<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Posts\Actions\PublishPost;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Zdjęcie wpisu tuż po publikacji (audyt A2).
 *
 * CO SIĘ DZIAŁO
 * `StoreUploadedImage` wrzuca przetwarzanie zdjęcia do kolejki i wraca
 * natychmiast. `PostController::store()` od razu przekierowuje na stronę
 * wpisu. Między publikacją a końcem zadania w tle jest okno — realne, kilka
 * sekund na produkcji — w którym `Media` ma status `pending`, a
 * `<x-photo>` (docs/UX_50_PLUS.md, AGENTS.md §7: „widoki nie pokazują
 * zdjęcia w stanie innym niż ready”) renderowało w tym miejscu KOMPLETNIE
 * NIC. Autor, który właśnie kliknął „Opublikuj”, widział swoje imię,
 * dzisiejszą datę i pustkę zamiast jedzenia — dokładnie w momencie, w którym
 * miał poczuć, że mu się udało.
 *
 * DLACZEGO TEN TEST NIE UFA KOLEJCE
 * `phpunit.xml` ustawia `QUEUE_CONNECTION=sync` (patrz komentarz przy tym
 * ustawieniu) — zadanie kończy się, zanim kontroler zdąży odpowiedzieć,
 * więc okno „zdjęcie jeszcze nie gotowe” w ogóle nie istnieje w domyślnym
 * przebiegu testów. Każdy test tutaj używa `Queue::fake()`, żeby zamrozić
 * `Media` w stanie `pending` DOKŁADNIE tak, jak wygląda to na produkcji
 * w pierwszych sekundach po publikacji.
 */
class PrzygotowywanieZdjeciaWpisuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_autor_widzi_ze_zdjecie_sie_przygotowuje_zamiast_pustego_miejsca(): void
    {
        Queue::fake();

        $basia = $this->user('basia');

        $response = $this->actingAs($basia)->post(route('posts.store'), [
            'photos' => [UploadedFile::fake()->image('obiad.jpg', 1200, 900)],
            'visibility' => 'public',
        ]);

        $post = Post::firstOrFail();
        $this->assertSame(Media::STATUS_PENDING, $post->media->first()->status, 'Test nie odtwarza stanu, który miał sprawdzić: zdjęcie jest już gotowe.');

        $html = $this->actingAs($basia)->get(route('posts.show', $post))->assertOk()->getContent();

        // Komunikat, nie pusta ramka i nie sam spinner: autor ma wiedzieć
        // CO się dzieje i że nic nie zginęło.
        $this->assertStringContainsString('przygotowuje', $html);
        $this->assertStringContainsString('Nic nie zginęło', $html);
    }

    public function test_odswiezenie_strony_pokazuje_gotowe_zdjecie_bez_javascriptu(): void
    {
        Storage::fake('public');
        $basia = $this->user('basia');

        // Kolejka udaje OD RAZU, przed wgraniem — inaczej `QUEUE_CONNECTION=sync`
        // z phpunit.xml przetworzyłby zdjęcie natychmiast, w środku `handle()`,
        // i test nie odtworzyłby stanu, który ma sprawdzić.
        Queue::fake();

        $media = app(StoreUploadedImage::class)->handle(
            $basia,
            UploadedFile::fake()->image('obiad.jpg', 1200, 900),
        );

        $post = app(PublishPost::class)->handle(author: $basia, body: null, mediaIds: [$media->getKey()], visibility: 'public');

        // Uwaga: strona ZAWSZE zawiera jeden `<img>` — pusty podgląd w modalu
        // powiększenia (layout.blade.php, `#powiekszenie`) — a placeholder
        // niżej też dostaje klasę `post-photo` (żeby zajmować to samo
        // miejsce w siatce). Sprawdzamy więc konkretnie ZNACZNIK `<img
        // class="post-photo"`, jedyny sposób odróżnienia „prawdziwe zdjęcie”
        // od „miejsce na zdjęcie”.
        $znacznikGotowegoZdjecia = '<img class="post-photo"';

        $htmlPrzed = $this->actingAs($basia)->get(route('posts.show', $post))->assertOk()->getContent();
        $this->assertStringContainsString('przygotowuje', $htmlPrzed);
        $this->assertStringNotContainsString($znacznikGotowegoZdjecia, $htmlPrzed, 'Zdjęcie nie powinno renderować się, zanim jest ready.');

        // To, co na produkcji robi zadanie w tle, wołamy tu wprost — bez
        // żadnego JavaScriptu po stronie klienta, samo odświeżenie strony
        // (kolejny GET) ma pokazać gotowe zdjęcie.
        (new ProcessUploadedImage($media->getKey()))->handle();

        $htmlPo = $this->actingAs($basia)->get(route('posts.show', $post))->assertOk()->getContent();
        $this->assertStringContainsString($znacznikGotowegoZdjecia, $htmlPo, 'Po przetworzeniu zdjęcie ma się pokazać po zwykłym odświeżeniu strony.');
        $this->assertStringNotContainsString('przygotowuje', $htmlPo);
    }

    public function test_inni_widzowie_widza_ogolny_komunikat_bez_zwracania_sie_do_nich_jak_do_autora(): void
    {
        Queue::fake();

        $basia = $this->user('basia');
        $ktos = $this->user('ktos');

        $this->actingAs($basia)->post(route('posts.store'), [
            'photos' => [UploadedFile::fake()->image('obiad.jpg', 1200, 900)],
            'visibility' => 'public',
        ]);
        $post = Post::firstOrFail();

        $html = $this->actingAs($ktos)->get(route('posts.show', $post))->assertOk()->getContent();

        // Osoba, która nie jest autorem, i tak dostaje wyjaśnienie zamiast
        // pustki — ale bez zwrotu „Twoje zdjęcie”, bo to nie jej zdjęcie.
        $this->assertStringContainsString('przygotowuje', $html);
        $this->assertStringNotContainsString('Twoje zdjęcie', $html);
    }

    public function test_trwale_nieudane_przetworzenie_mowi_o_tym_zamiast_wiecznej_pustki(): void
    {
        $basia = $this->user('basia');

        $media = Media::factory()->create([
            'owner_id' => $basia->getKey(),
            'status' => Media::STATUS_REJECTED,
            'metadata' => ['failure_reason' => 'processing_failed'],
        ]);

        $post = app(PublishPost::class)->handle(author: $basia, body: null, mediaIds: [$media->getKey()], visibility: 'public');

        $html = $this->actingAs($basia)->get(route('posts.show', $post))->assertOk()->getContent();

        $this->assertStringContainsString('Nie udało się przygotować', $html);
        $this->assertStringNotContainsString('przygotowuje', $html, 'Zdjęcie, które padło na dobre, nie powinno dalej udawać, że "się przygotowuje".');
    }
}
