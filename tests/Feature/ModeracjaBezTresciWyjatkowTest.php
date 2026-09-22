<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PrzeanalizujAwatar;
use App\Jobs\PrzeanalizujTresc;
use App\Models\Media;
use App\Models\Post;
use App\Models\Profile;
use App\Moderacja\KlientOpenAI;
use App\Moderacja\OcenaModelem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Kontrolowane wyjątki i przechwycony logger, bez sieci i prawdziwych danych.
 * Nie dowodzi wycieku z produkcji ani treści dzisiejszego błędu dostawcy.
 */
class ModeracjaBezTresciWyjatkowTest extends TestCase
{
    use RefreshDatabase;

    private const FOREIGN_MESSAGE = 'CONTROLLED_FOREIGN_EXCEPTION user@example.invalid https://example.invalid/?token=SYNTHETIC_SECRET PRIVATE_REQUEST_TEXT data:image/jpeg;base64,PRIVATE_IMAGE';

    private array $records = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.moderation.model.klucz' => 'syntetyczny-klucz',
            'kuking.moderation.model.ocenia_zdjecia' => true,
            'kuking.moderation.sygnaly.wlaczone' => true,
        ]);
        Http::preventStrayRequests();
        Notification::fake();
        Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []): void {
            $this->records[] = [$message, $context];
        });

        // `Log::shouldReceive()` podmienia cały LogManager na ścisłą atrapę.
        // Hook payloadu kolejki (`QueueCorrelation::payload()`, #1040) czyta
        // przy każdym `dispatch()` `sharedContext()`, a słuchacze zadania
        // ustawiają i sprzątają kontekst. Bez tych zgód test mierzyłby własną
        // atrapę (`BadMethodCallException`), a nie treść logu. `byDefault()`,
        // bo liczby wywołań tu nie sprawdzamy — ten sam wzór, co
        // `KontaktPotwierdzeniaTest::pozwolNaKontekstKorelacji()`.
        Log::shouldReceive('shareContext')->andReturnSelf()->byDefault();
        Log::shouldReceive('sharedContext')->andReturn([])->byDefault();
        Log::shouldReceive('withoutContext')->andReturnSelf()->byDefault();
        Log::shouldReceive('flushSharedContext')->andReturnSelf()->byDefault();
    }

    public function test_transport_openai_nie_loguje_tresci_wyjatku(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new RuntimeException(self::FOREIGN_MESSAGE, 123456);
        });

        $this->assertNull(app(KlientOpenAI::class)->ocenObraz('data:image/jpeg;base64,cHJvYmE='));
        $this->assertSame(1, $calls);
        $this->assertSafeRecord('Ocena treści modelem nie doszła do skutku.', 'openai_transport');
        $this->assertSame('zdjęcie', $this->records[0][1]['czego']);
    }

    public function test_przygotowanie_zdjecia_nie_loguje_tresci_wyjatku(): void
    {
        $media = $this->media();
        Storage::shouldReceive('disk')->once()->with('public')->andReturnSelf();
        Storage::shouldReceive('get')->once()->with('media/proba_thumb.webp')
            ->andThrow(new RuntimeException(self::FOREIGN_MESSAGE, 123456));

        $this->assertSame([], app(OcenaModelem::class)->dlaZdjecia($media));
        Http::assertNothingSent();
        $this->assertSafeRecord('Nie udało się przygotować zdjęcia do oceny modelem.', 'image_preparation');
    }

    public function test_zewnetrzny_catch_analizy_tresci_nie_loguje_wyjatku(): void
    {
        $post = Post::create([
            'author_id' => $this->user()->getKey(),
            'body' => '',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->failInsideModel(fn () => dispatch_sync(new PrzeanalizujTresc('post', (string) $post->getKey())));

        $this->assertSame(Post::STATUS_PUBLISHED, $post->refresh()->status);
        $this->assertSafeRecord('Analiza treści pod kątem sygnałów nie powiodła się.', 'content_analysis');
        $this->assertNoSanctions();
    }

    public function test_zewnetrzny_catch_analizy_awatara_nie_loguje_wyjatku(): void
    {
        $media = $this->media();
        Profile::query()->where('user_id', $media->owner_id)->update(['avatar_media_id' => $media->getKey()]);

        $this->failInsideModel(fn () => dispatch_sync(new PrzeanalizujAwatar((string) $media->getKey())));

        $this->assertSame(Media::STATUS_READY, $media->refresh()->status);
        $this->assertDatabaseHas('profiles', ['user_id' => $media->owner_id, 'avatar_media_id' => $media->getKey()]);
        $this->assertSafeRecord('Ocena zdjęcia profilowego modelem nie powiodła się.', 'avatar_analysis');
        $this->assertNoSanctions();
    }

    public function test_odpowiedz_http_zachowuje_tylko_status_bez_ciala(): void
    {
        Http::fake(['*' => Http::response(self::FOREIGN_MESSAGE, 503)]);

        $this->assertNull(app(KlientOpenAI::class)->ocenTekst('Próba'));
        Http::assertSentCount(1);
        $this->assertSame([
            ['Model moderacji odpowiedział błędem.', ['czego' => 'tekst', 'status' => 503]],
        ], $this->records);
    }

    private function media(): Media
    {
        return Media::factory()->create([
            'owner_id' => $this->user()->getKey(),
            'disk' => 'public',
            'variants_disk' => 'public',
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => ['thumb' => ['key' => 'media/proba_thumb.webp', 'width' => 320, 'height' => 320]]],
        ]);
    }

    private function failInsideModel(callable $run): void
    {
        // Obie klasy modelu są final. Wstrzykujemy awarię przy odczycie
        // ustawienia WEWNĄTRZ OcenaModelem, poza jego wewnętrznym catch.
        // Dzięki temu mierzymy osobno catch każdego joba, nie ponownie klienta.
        // To sztuczny punkt awarii, nie symulacja konkretnego błędu dostawcy.
        $original = app('config');
        $config = Mockery::mock($original);
        $config->shouldReceive('get')->withAnyArgs()->andReturnUsing(fn (...$args) => $original->get(...$args))->byDefault();
        $config->shouldReceive('get')->once()->with('kuking.moderation.model.ocenia_zdjecia', null)
            ->andThrow(new RuntimeException(self::FOREIGN_MESSAGE, 123456));
        app()->instance('config', $config);

        try {
            $run();
        } finally {
            app()->instance('config', $original);
        }
    }

    private function assertSafeRecord(string $message, string $stage): void
    {
        $this->assertCount(1, $this->records, 'Brak ostrzeżenia nie dowodzi ochrony dziennika.');
        $this->assertSame($message, $this->records[0][0]);
        $serialized = json_encode($this->records, JSON_THROW_ON_ERROR);
        foreach (['CONTROLLED_FOREIGN_EXCEPTION', 'user@example.invalid', 'SYNTHETIC_SECRET', 'PRIVATE_REQUEST_TEXT', 'PRIVATE_IMAGE', '123456'] as $marker) {
            $this->assertStringNotContainsString($marker, $serialized);
        }
        $this->assertSame(RuntimeException::class, $this->records[0][1]['exception_class'] ?? null);
        $this->assertSame($stage, $this->records[0][1]['stage'] ?? null);
    }

    private function assertNoSanctions(): void
    {
        $this->assertDatabaseCount('reports', 0);
        $this->assertDatabaseCount('moderation_actions', 0);
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }
}
