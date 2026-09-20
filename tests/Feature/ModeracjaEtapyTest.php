<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\OznaczDoPrzegladu;
use App\Domain\Moderation\Sygnaly\Sygnal;
use App\Domain\Posts\Actions\PublishPost;
use App\Jobs\ProcessUploadedImage;
use App\Jobs\PrzeanalizujTresc;
use App\Jobs\PrzeanalizujZdjecieWpisu;
use App\Models\Media;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ModeracjaEtapyTest extends TestCase
{
    use RefreshDatabase;

    private QueueFake $queueFake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queueFake = Queue::fake();
        Notification::fake();
        Storage::fake('public');
        config(['kuking.moderation.model.klucz' => 'test', 'kuking.moderation.model.ocenia_zdjecia' => true]);
    }

    public function test_lokalny_sygnal_jest_trwaly_zanim_zacznie_sie_http(): void
    {
        $post = Post::factory()->create(['body' => 'Zarabiaj z domu, tel. +48 600 100 200']);
        $savedBeforeHttp = false;
        Http::fake(function () use ($post, &$savedBeforeHttp) {
            $savedBeforeHttp = Report::where('target_id', $post->id)->exists();

            return Http::response(['results' => [['category_scores' => ['hate' => 0.9]]]]);
        });
        app()->call([new PrzeanalizujTresc('post', $post->id), 'handle']);
        $this->assertTrue($savedBeforeHttp, 'Lokalny sygnał przepadłby przy zabiciu workera podczas HTTP.');
        $report = Report::where('target_id', $post->id)->sole();
        $this->assertSame('automat_model', $report->reason);
        $this->assertStringContainsString('nienawiści', $report->details);
        $this->assertStringContainsString('zarobek', mb_strtolower($report->details));
    }

    public function test_zdjecie_przed_tekstem_nie_zabiera_lokalnego_sygnalu(): void
    {
        $post = Post::factory()->create(['body' => 'Zarabiaj z domu']);
        app(OznaczDoPrzegladu::class)->handle($post, [new Sygnal('automat_model', 'Ocena zdjęcia.')], uzupelnij: true);
        Http::fake(['*' => Http::response(['results' => [['category_scores' => ['hate' => 0.1]]]])]);
        app()->call([new PrzeanalizujTresc('post', $post->id), 'handle']);
        $report = Report::where('target_id', $post->id)->sole();
        $this->assertStringContainsString('zarobek', $report->details);
        $this->assertStringContainsString('Ocena zdjęcia.', $report->details);
        $this->assertSame('automat_model', $report->reason);
    }

    public function test_gotowe_zdjecie_wznawia_analize_po_publikacji_processing(): void
    {
        $author = $this->user();
        $media = Media::factory()->pending()->create(['owner_id' => $author->id, 'status' => Media::STATUS_PROCESSING]);
        Storage::disk('public')->put($media->object_key, (string) ImageManager::gd()->create(32, 32)->toJpeg());
        $post = app(PublishPost::class)->handle($author, 'Zarabiaj z domu', [$media->id]);
        Http::fake(fn ($request) => Http::response(['results' => [['category_scores' => $request['input'][0]['type'] === 'text' ? ['hate' => 0.1] : ['sexual' => 0.9],
        ]]]));
        app()->call([new PrzeanalizujTresc('post', $post->id), 'handle']);
        Http::assertSentCount(1);
        app()->call([new ProcessUploadedImage($media->id), 'handle']);
        $this->assertSame(Media::STATUS_READY, $media->fresh()->status);
        Queue::assertPushed(PrzeanalizujZdjecieWpisu::class);
        foreach ($this->queueFake->pushed(PrzeanalizujZdjecieWpisu::class) as $entry) {
            app()->call([$entry, 'handle']);
            app()->call([$entry, 'handle']);
        }
        $report = Report::where('target_id', $post->id)->sole();
        $this->assertSame('automat_model', $report->reason);
        $this->assertSame(1, substr_count($report->details, 'treść seksualna'));
        Http::assertSent(fn ($request) => $request['input'][0]['type'] === 'image_url');
    }

    public static function blockedImages(): array
    {
        return [['private'], ['hidden'], ['detached'], ['deleted'], ['rejected'], ['processing'], ['disabled'], ['no-key'], ['zero-limit']];
    }

    #[DataProvider('blockedImages')]
    public function test_spoznione_zdjecie_sprawdza_aktualny_stan(string $state): void
    {
        $post = Post::factory()->create();
        $media = Media::factory()->create();
        $post->media()->attach($media, ['position' => 0]);
        $job = new PrzeanalizujZdjecieWpisu($post->id, $media->id);
        match ($state) {
            'private' => $post->update(['visibility' => 'private']),
            'hidden' => $post->update(['status' => 'hidden']),
            'detached' => $post->media()->detach(),
            'deleted' => $media->delete(),
            'rejected', 'processing' => $media->update(['status' => $state]),
            'disabled' => config(['kuking.moderation.model.ocenia_zdjecia' => false]),
            'no-key' => config(['kuking.moderation.model.klucz' => null]),
            'zero-limit' => config(['kuking.moderation.model.zdjec_na_wpis' => 0]),
        };
        Http::fake();
        app()->call([$job, 'handle']);
        Http::assertNothingSent();
        $this->assertSame(0, Report::count());
    }

    public static function reportStates(): array
    {
        return [['open', true], ['triage', true], ['reviewing', true], ['rejected', false], ['resolved', false]];
    }

    #[DataProvider('reportStates')]
    public function test_uzupelnianie_szanuje_decyzje_moderatora(string $state, bool $changes): void
    {
        $post = Post::factory()->create();
        $mark = app(OznaczDoPrzegladu::class);
        $report = $mark->handle($post, [new Sygnal('automat_wzorzec', 'Lokalny sygnał.')]);
        $report->update(['status' => $state]);
        $before = $report->details;
        $signals = [new Sygnal('automat_model', 'Ocena zdjęcia.')];
        $updated = $mark->handle($post, $signals, uzupelnij: true);
        $this->assertSame($changes, $updated !== null);
        $this->assertSame($state, $report->fresh()->status);
        $this->assertStringContainsString('Lokalny sygnał.', $report->fresh()->details);
        if (! $changes) {
            $this->assertSame($before, $report->fresh()->details);
        }
        $this->assertNull($mark->handle($post, $signals, uzupelnij: true));
        $this->assertSame(1, Report::count());
    }

    public static function photoCounts(): array
    {
        return [[2], [6]];
    }

    /** Pomiar opóźnienia atrapy, nie dostawcy ani timeoutu systemowego workera. */
    #[DataProvider('photoCounts')]
    #[Group('wolny-model')]
    public function test_wolne_oceny_nie_sumują_sie_w_jednym_zadaniu(int $count): void
    {
        config(['kuking.moderation.model.zdjec_na_wpis' => $count]);
        $post = Post::factory()->create(['body' => 'Zarabiaj z domu']);
        for ($i = 0; $i < $count; $i++) {
            $media = Media::factory()->create();
            Storage::disk('public')->put($media->wariantDoSerwowania('thumb')['klucz'], (string) ImageManager::gd()->create(32, 32)->toJpeg());
            $post->media()->attach($media, ['position' => $i]);
        }
        Http::fake(function () use ($post) {
            $this->assertSame(1, Report::where('target_id', $post->id)->count());
            usleep(5_000_000);

            return Http::response(['results' => [['category_scores' => ['hate' => 0.9]]]]);
        });
        $start = microtime(true);
        app()->call([new PrzeanalizujTresc('post', $post->id), 'handle']);
        $this->assertLessThan(15, microtime(true) - $start);
        Queue::assertPushed(PrzeanalizujZdjecieWpisu::class, $count);
        foreach ($this->queueFake->pushed(PrzeanalizujZdjecieWpisu::class) as $job) {
            $jobStart = microtime(true);
            app()->call([$job, 'handle']);
            $this->assertLessThan(15, microtime(true) - $jobStart);
        }
        $elapsed = microtime(true) - $start;
        $this->assertGreaterThan($count === 6 ? 30 : 14, $elapsed);
        Http::assertSentCount($count + 1);
        $report = Report::where('target_id', $post->id)->sole();
        $this->assertSame('automat_model', $report->reason);
        $this->assertStringContainsString('zarobek', $report->details);
        $this->assertStringContainsString('niepełna', $report->details);
        fwrite(STDERR, sprintf("\nPOMIAR %d zdjęć: %.2f s łącznie, każde zadanie <15 s.\n", $count, $elapsed));
    }
}
