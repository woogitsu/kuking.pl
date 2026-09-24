<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #957: pierwszy kafel kolażu w hero to prawdopodobny obraz LCP na ekranie
 * od 64rem. Nie może mieć `loading="lazy"` (lazy opóźnia samo ODKRYCIE
 * zasobu, więc `fetchpriority` by go nie uratowało) i ma mieć
 * `fetchpriority="high"`. Pozostałe kafle zostają `lazy` i nie konkurują
 * z nim wysokim priorytetem.
 *
 * Test czyta RENDEROWANY HTML strony powitalnej, nie tekst szablonu.
 *
 * KONTROLA UJEMNA: przywrócenie bezwarunkowego `loading="lazy"` na każdym
 * kaflu w `landing.blade.php` oblewa
 * `test_pierwszy_kafel_ladowany_od_razu_z_wysokim_priorytetem` — wpis
 * w `scripts/kontrole-negatywne-alfa08.py`.
 */
class KolazPowitalnyPriorytetLcpTest extends TestCase
{
    use RefreshDatabase;

    private function dodajWpisZeZdjeciem(int $numer): void
    {
        $autor = $this->user('kolaz_lcp_'.$numer);
        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subMinutes($numer),
        ]);
        $zdjecie = Media::factory()->for($autor, 'owner')->create();
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);
    }

    /** @return list<string> Znaczniki `<img>` kafli kolażu w kolejności z HTML. */
    private function kafleZeStrony(): array
    {
        $html = $this->get('/')->assertOk()->getContent();
        preg_match_all('/<img class="hero-kolaz-kafel"[^>]*>/s', $html, $trafienia);

        return $trafienia[0];
    }

    /** @return list<string> */
    private function kafleKolazu(int $liczba): array
    {
        for ($i = 1; $i <= $liczba; $i++) {
            $this->dodajWpisZeZdjeciem($i);
        }

        return $this->kafleZeStrony();
    }

    /** Kontrola dodatnia: bez kafli w HTML każdy test niżej byłby pusty. */
    public function test_strona_powitalna_renderuje_cztery_kafle(): void
    {
        $this->assertCount(4, $this->kafleKolazu(4));
    }

    public function test_pierwszy_kafel_ladowany_od_razu_z_wysokim_priorytetem(): void
    {
        $pierwszy = $this->kafleKolazu(4)[0];

        $this->assertStringContainsString('fetchpriority="high"', $pierwszy);
        $this->assertStringNotContainsString('loading=', $pierwszy);
        $this->assertStringContainsString('decoding="async"', $pierwszy);
        $this->assertMatchesRegularExpression('/width="\d+"/', $pierwszy);
        $this->assertMatchesRegularExpression('/height="\d+"/', $pierwszy);
    }

    public function test_pozostale_kafle_zostaja_lazy_bez_wysokiego_priorytetu(): void
    {
        $kafle = $this->kafleKolazu(4);

        foreach (array_slice($kafle, 1) as $kafel) {
            $this->assertStringContainsString('loading="lazy"', $kafel);
            $this->assertStringNotContainsString('fetchpriority', $kafel);
        }
    }

    public function test_wysoki_priorytet_ma_dokladnie_jeden_kafel_przy_kazdej_liczbie(): void
    {
        for ($liczba = 1; $liczba <= 4; $liczba++) {
            $this->dodajWpisZeZdjeciem($liczba);
            $kafle = $this->kafleZeStrony();

            $this->assertCount($liczba, $kafle);
            $this->assertSame(1, substr_count(implode('', $kafle), 'fetchpriority="high"'), "kafli: {$liczba}");
        }
    }
}
