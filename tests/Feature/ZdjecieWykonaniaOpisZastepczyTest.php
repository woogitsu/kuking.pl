<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Opis zastępczy (alt) dla zdjęć wykonania („Ugotowałem") (issue #769).
 *
 * Zdjęcia wykonania nie są ozdobnikami, lecz sednem wykonania.
 * Zdjęcie bez autorskiego alt_text musi dostać sensowny fallback:
 * - kontekst wykonania,
 * - odróżnienie zdjęć (np. „Zdjęcie 1 z 2 wykonania”),
 * - fallback musi docierać także do kontrolki powiększenia (data-alt i aria-label),
 * - jeśli autor podał własny alt_text, jest on zachowywany,
 * - fallback nie ujawnia tytułu niedostępnego przepisu.
 */
class ZdjecieWykonaniaOpisZastepczyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_dwa_zdjecia_wykonania_dostaja_rozroznialny_alt_oraz_opis_w_powiekszeniu(): void
    {
        $kucharz = $this->user('kucharka');
        $recipe = Recipe::factory()->create([
            'title' => 'Sekretny barszcz',
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $storeImage = app(StoreUploadedImage::class);
        $media1 = $storeImage->handle($kucharz, UploadedFile::fake()->image('danie1.jpg', 800, 600));
        $media2 = $storeImage->handle($kucharz, UploadedFile::fake()->image('danie2.jpg', 800, 600));

        $event = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $recipe,
            mediaIds: [$media1->getKey(), $media2->getKey()],
        );

        $response = $this->actingAs($kucharz)
            ->get(route('cooked.show', $event))
            ->assertOk();

        // Sprawdź atrybuty alt obrazów
        $response->assertSee('alt="Zdjęcie 1 z 2 wykonania"', false);
        $response->assertSee('alt="Zdjęcie 2 z 2 wykonania"', false);

        // Sprawdź atrybuty w linku powiększenia (photo-zoom-link)
        $response->assertSee('data-alt="Zdjęcie 1 z 2 wykonania"', false);
        $response->assertSee('aria-label="Powiększ zdjęcie: Zdjęcie 1 z 2 wykonania"', false);
        $response->assertSee('data-alt="Zdjęcie 2 z 2 wykonania"', false);
        $response->assertSee('aria-label="Powiększ zdjęcie: Zdjęcie 2 z 2 wykonania"', false);
    }

    public function test_jedno_zdjecie_wykonania_dostaje_kontekstowy_alt_i_opis_w_powiekszeniu(): void
    {
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $storeImage = app(StoreUploadedImage::class);
        $media = $storeImage->handle($kucharz, UploadedFile::fake()->image('danie.jpg', 800, 600));

        $event = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $recipe,
            mediaIds: [$media->getKey()],
        );

        $response = $this->actingAs($kucharz)
            ->get(route('cooked.show', $event))
            ->assertOk();

        $response->assertSee('alt="Zdjęcie wykonania"', false);
        $response->assertSee('data-alt="Zdjęcie wykonania"', false);
        $response->assertSee('aria-label="Powiększ zdjęcie: Zdjęcie wykonania"', false);
    }

    public function test_autorski_alt_text_jest_zachowywany(): void
    {
        $kucharz = $this->user('kucharz2');
        $recipe = Recipe::factory()->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $storeImage = app(StoreUploadedImage::class);
        $media = $storeImage->handle($kucharz, UploadedFile::fake()->image('danie.jpg', 800, 600), 'Moja złocista tarta z jabłkami');

        $event = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $recipe,
            mediaIds: [$media->getKey()],
        );

        $response = $this->actingAs($kucharz)
            ->get(route('cooked.show', $event))
            ->assertOk();

        $response->assertSee('alt="Moja złocista tarta z jabłkami"', false);
        $response->assertSee('data-alt="Moja złocista tarta z jabłkami"', false);
        $response->assertSee('aria-label="Powiększ zdjęcie: Moja złocista tarta z jabłkami"', false);
        $response->assertDontSee('alt="Zdjęcie wykonania"', false);
    }

    public function test_opis_zastepczy_nie_ujawnia_tytulu_niedostepnego_przepisu(): void
    {
        $kucharz = $this->user('kucharz3');
        $autor = $this->user('autorka3');

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => 'Ściśle tajna szarlotka babci',
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $storeImage = app(StoreUploadedImage::class);
        $media = $storeImage->handle($kucharz, UploadedFile::fake()->image('danie.jpg', 800, 600));

        $event = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $recipe,
            mediaIds: [$media->getKey()],
        );

        // Przepis staje się prywatny
        $recipe->update(['visibility' => 'private']);

        $response = $this->actingAs($kucharz)
            ->get(route('cooked.show', $event))
            ->assertOk();

        // Tytuł przepisu nie może trafić do alt zdjęcia
        $response->assertDontSee('alt="Zdjęcie wykonania: Ściśle tajna szarlotka babci"', false);
        $response->assertSee('alt="Zdjęcie wykonania"', false);
    }

    /**
     * Ekran „Komuś wyszło" pokazuje TO SAMO zdjęcie wykonania co karta, ale
     * własnym wywołaniem `x-photo` — bez opisu zastępczego dostawał `alt=""`
     * na miniaturze i w powiększeniu.
     */
    public function test_ekran_komus_wyszlo_daje_zdjeciu_wykonania_opis_zastepczy(): void
    {
        $autor = $this->user('autorka4');
        $kucharz = $this->user('kucharz4');
        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $media = app(StoreUploadedImage::class)
            ->handle($kucharz, UploadedFile::fake()->image('danie.jpg', 800, 600));

        $event = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $recipe,
            mediaIds: [$media->getKey()],
        );

        $html = $this->actingAs($autor)
            ->get(route('cooked.celebrate', $event))
            ->assertOk()
            ->getContent();

        // Tylko obraz wykonania. Pusty `<img class="lightbox-obraz" alt="">`
        // w układzie strony to zaślepka nakładki, nie treść.
        $this->assertSame(1, preg_match('/<img class="post-photo"[^>]*>/', $html, $obraz), 'Zdjęcie wykonania musi być na stronie jako obraz.');
        $this->assertStringNotContainsString('alt=""', $obraz[0]);
        $this->assertStringNotContainsString('data-alt=""', $html);
        $this->assertStringContainsString('alt="Zdjęcie wykonania"', $html);
        $this->assertStringContainsString('aria-label="Powiększ zdjęcie: Zdjęcie wykonania"', $html);
    }

    public function test_ekran_komus_wyszlo_zachowuje_autorski_alt_text(): void
    {
        $autor = $this->user('autorka5');
        $kucharz = $this->user('kucharz5');
        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $media = app(StoreUploadedImage::class)
            ->handle($kucharz, UploadedFile::fake()->image('danie.jpg', 800, 600), 'Pierogi na desce');

        $event = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $recipe,
            mediaIds: [$media->getKey()],
        );

        $this->actingAs($autor)
            ->get(route('cooked.celebrate', $event))
            ->assertOk()
            ->assertSee('alt="Pierogi na desce"', false)
            ->assertDontSee('alt="Zdjęcie wykonania"', false);
    }
}
