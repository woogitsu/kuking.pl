<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
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

    private function siatka(string $html): string
    {
        $this->assertSame(1, substr_count($html, '<div class="photo-grid">'));
        $start = strpos($html, '<div class="photo-grid">');
        $koniec = strpos($html, '<div class="post-card-recipe">', $start);

        return $koniec === false ? substr($html, $start) : substr($html, $start, $koniec - $start);
    }
}
