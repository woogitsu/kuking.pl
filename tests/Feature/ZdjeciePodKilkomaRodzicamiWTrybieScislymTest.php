<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TEST REGRESYJNY (#976): zdjęcie pod KILKOMA rodzicami tego samego typu.
 *
 * Port marki (`scripts/fixtures/zeszyty-marki.php`) złapał to na zeszycie:
 * dwa przepisy z tym samym zdjęciem w nagłówku, a miniatura odpowiadała 500.
 * `DostepDoZdjecia` wczytywał rodziców jednym `get()`, a Policy każdego
 * z nich doładowywała autora osobno — tryb ścisły Eloquent (lokalnie
 * i w testach) kończy takie leniwe ładowanie wyjątkiem. Naprawą jest
 * `with()` w resolverze, nie wyłączenie trybu ścisłego.
 */
class ZdjeciePodKilkomaRodzicamiWTrybieScislymTest extends TestCase
{
    use RefreshDatabase;

    public function test_zdjecie_dwoch_przepisow_otwiera_sie_bez_leniwego_ladowania(): void
    {
        Storage::fake('public');

        // Kontrola: test ma sens tylko wtedy, gdy tryb ścisły naprawdę działa.
        $this->assertTrue(Model::preventsLazyLoading(), 'Tryb ścisły w testach jest wyłączony — ten test nic nie mierzy.');

        $autor = $this->user('autorka_wspolne_zdjecie');
        $zdjecie = $this->zdjecie($autor);

        foreach ([1, 2] as $numer) {
            Recipe::factory()->create([
                'author_id' => $autor->getKey(),
                'visibility' => 'public',
                'status' => 'published',
                'published_at' => now()->subDay(),
                'hero_media_id' => $zdjecie->getKey(),
                'slug' => 'wspolne-zdjecie-'.$numer.'-'.Str::lower(Str::random(6)),
            ]);
        }

        $this->get($zdjecie->url('feed'))->assertStatus(302);
        $this->actingAs($this->user('obcy_wspolne_zdjecie'))->get($zdjecie->url('feed'))->assertStatus(302);
    }

    public function test_zdjecie_dwoch_wpisow_otwiera_sie_bez_leniwego_ladowania(): void
    {
        Storage::fake('public');

        $autor = $this->user('autor_wspolne_zdjecie_wpisu');
        $zdjecie = $this->zdjecie($autor);

        foreach (['Pierwszy wpis', 'Drugi wpis'] as $tresc) {
            $wpis = Post::factory()->create([
                'author_id' => $autor->getKey(),
                'body' => $tresc,
                'visibility' => Post::VISIBILITY_PUBLIC,
                'status' => Post::STATUS_PUBLISHED,
                'published_at' => now()->subDay(),
            ]);
            $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);
        }

        $this->get($zdjecie->url('feed'))->assertStatus(302);
        $this->actingAs($this->user('obca_wspolne_zdjecie_wpisu'))->get($zdjecie->url('feed'))->assertStatus(302);
    }

    private function zdjecie(User $wlasciciel): Media
    {
        $identyfikator = Str::uuid()->toString();
        $warianty = [];

        foreach (config('kuking.media.variants') as $nazwa => $krawedz) {
            $klucz = 'media/'.$identyfikator.'_'.$nazwa.'.webp';
            $warianty[$nazwa] = ['key' => $klucz, 'width' => $krawedz, 'height' => $krawedz];
            Storage::disk('public')->put($klucz, 'udawane-bajty-'.$nazwa);
        }

        return Media::factory()->create([
            'owner_id' => $wlasciciel->getKey(),
            'disk' => 'public',
            'variants_disk' => 'public',
            'object_key' => 'incoming/'.$identyfikator.'.jpg',
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => $warianty],
        ]);
    }
}
