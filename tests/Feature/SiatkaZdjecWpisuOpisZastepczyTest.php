<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** Siatka zwykłego wpisu musi opisywać zdjęcia tak samo jak kolaż i karuzela (#2065). */
class SiatkaZdjecWpisuOpisZastepczyTest extends TestCase
{
    use RefreshDatabase;

    public function test_puste_opisy_dostaja_kontekst_i_odrebne_numery_takze_w_powiekszeniu(): void
    {
        $post = Post::factory()->create(['display_mode' => Post::DISPLAY_NORMAL]);
        $pierwsze = Media::factory()->create(['owner_id' => $post->author_id, 'alt_text' => null]);
        $drugie = Media::factory()->create(['owner_id' => $post->author_id, 'alt_text' => '']);
        $post->media()->attach($pierwsze->getKey(), ['position' => 0]);
        $post->media()->attach($drugie->getKey(), ['position' => 1]);

        $html = Blade::render('<x-post-card :post="$post" />', ['post' => $post->fresh()]);
        $siatka = $this->siatka($html);

        foreach ([1, 2] as $numer) {
            $opis = 'Zdjęcie '.$numer.' z 2 w tym wpisie';
            $this->assertStringContainsString('alt="'.$opis.'"', $siatka);
            $this->assertStringContainsString('data-alt="'.$opis.'"', $siatka);
            $this->assertStringContainsString('aria-label="Powiększ zdjęcie: '.$opis.'"', $siatka);
        }
        $this->assertSame(2, substr_count($siatka, '<img '));
    }

    public function test_autorski_opis_ma_pierwszenstwo_przed_opisem_zastepczym(): void
    {
        $post = Post::factory()->create(['display_mode' => Post::DISPLAY_NORMAL]);
        $media = Media::factory()->create([
            'owner_id' => $post->author_id,
            'alt_text' => 'Domowy rosół w misce',
        ]);
        $post->media()->attach($media->getKey(), ['position' => 0]);

        $siatka = $this->siatka(Blade::render('<x-post-card :post="$post" />', ['post' => $post->fresh()]));

        $this->assertStringContainsString('alt="Domowy rosół w misce"', $siatka);
        $this->assertStringContainsString('data-alt="Domowy rosół w misce"', $siatka);
        $this->assertStringContainsString('aria-label="Powiększ zdjęcie: Domowy rosół w misce"', $siatka);
        $this->assertStringNotContainsString('Zdjęcie 1 z 1 w tym wpisie', $siatka);
    }

    public function test_dwa_i_trzy_zdjecia_o_roznych_proporcjach_zachowuja_kolejnosc_i_powiekszanie(): void
    {
        foreach ([2, 3] as $ile) {
            $post = Post::factory()->create(['display_mode' => Post::DISPLAY_NORMAL]);
            foreach (range(1, $ile) as $numer) {
                $media = Media::factory()->create([
                    'owner_id' => $post->author_id,
                    'alt_text' => 'Potrawa, ujęcie '.$numer,
                ]);
                $metadata = $media->metadata;
                $metadata['variants']['feed']['width'] = $numer === 1 ? 720 : 960;
                $metadata['variants']['feed']['height'] = $numer === 1 ? 960 : 540;
                $media->update(['metadata' => $metadata]);
                $post->media()->attach($media->getKey(), ['position' => $numer - 1]);
            }

            $html = Blade::render('<x-post-card :post="$post" />', ['post' => $post->fresh()]);
            $dom = new DOMDocument;
            @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
            $xpath = new DOMXPath($dom);
            $siatka = $xpath->query('//div[@class="photo-grid"]')->item(0);

            $this->assertNotNull($siatka);
            $this->assertCount($ile, $xpath->query('./div[@class="photo-zoom"]', $siatka));
            $zdjecia = $xpath->query('./div[@class="photo-zoom"]//img', $siatka);
            $linki = $xpath->query('./div[@class="photo-zoom"]//a[@data-powieksz]', $siatka);
            $this->assertCount($ile, $zdjecia);
            $this->assertCount($ile, $linki);

            foreach (range(1, $ile) as $numer) {
                $zdjecie = $zdjecia->item($numer - 1);
                $opis = 'Potrawa, ujęcie '.$numer;
                $this->assertSame($opis, $zdjecie?->getAttribute('alt'));
                $this->assertSame($numer === 1 ? '720' : '960', $zdjecie?->getAttribute('width'));
                $this->assertSame($numer === 1 ? '960' : '540', $zdjecie?->getAttribute('height'));
                $this->assertSame($opis, $linki->item($numer - 1)?->getAttribute('data-alt'));
            }
        }
    }

    private function siatka(string $html): string
    {
        $this->assertSame(1, substr_count($html, '<div class="photo-grid">'));
        $start = strpos($html, '<div class="photo-grid">');
        $koniec = strpos($html, '<div class="post-card-recipe">', $start);

        return $koniec === false ? substr($html, $start) : substr($html, $start, $koniec - $start);
    }
}
