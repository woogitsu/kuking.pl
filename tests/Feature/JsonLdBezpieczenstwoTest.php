<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Support\JsonLd;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stored XSS przez JSON-LD (audyt A01).
 *
 * W zwykłym tekście JSON ciąg `</script>` jest poprawną wartością. W dokumencie
 * HTML kończy element skryptu — także wtedy, gdy jego typem jest
 * `application/ld+json`. Tytuł przepisu albo bio mogły więc wyjść z kontekstu
 * danych i wstrzyknąć HTML na stronę, którą otwiera również moderator.
 */
class JsonLdBezpieczenstwoTest extends TestCase
{
    use RefreshDatabase;

    private const ZLOSLIWY = 'Rosol</script><img src=x onerror=alert(1)>';

    // -----------------------------------------------------------------
    // Sam koder — bez HTTP, żeby błąd wskazywał przyczynę, nie objaw
    // -----------------------------------------------------------------

    public function test_koder_nie_wypuszcza_zamkniecia_skryptu(): void
    {
        $wynik = JsonLd::encode(['name' => self::ZLOSLIWY]);

        $this->assertStringNotContainsString('</script>', $wynik);
        $this->assertStringNotContainsString('<img', $wynik);
    }

    public function test_koder_zachowuje_wartosc_bez_zmian(): void
    {
        $wynik = JsonLd::encode(['name' => self::ZLOSLIWY]);

        // Kluczowe: zmieniamy ZAPIS, nie treść. Wyszukiwarki mają dostać
        // dokładnie to, co wpisał człowiek.
        $this->assertSame(self::ZLOSLIWY, json_decode($wynik, true)['name']);
    }

    public function test_koder_nie_psuje_polskich_znakow(): void
    {
        $tekst = 'Żurek śląski „u babci” — 100% smaku';

        $wynik = JsonLd::encode(['name' => $tekst]);

        $this->assertSame($tekst, json_decode($wynik, true)['name']);
        // Polskie znaki zostają czytelne w źródle strony, nie jako \uXXXX.
        $this->assertStringContainsString('Żurek', $wynik);
    }

    public function test_koder_zwraca_poprawny_json(): void
    {
        $wynik = JsonLd::encode(['@context' => 'https://schema.org', 'name' => self::ZLOSLIWY]);

        $this->assertIsArray(json_decode($wynik, true));
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    // -----------------------------------------------------------------
    // Cała strona — to jest miejsce, w którym błąd był realny
    // -----------------------------------------------------------------

    public function test_strona_przepisu_nie_wypuszcza_html_z_tytulu(): void
    {
        $autor = $this->user('autorka');

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'title' => self::ZLOSLIWY,
            'slug' => 'rosol-'.Str::lower(Str::random(8)),
        ]);

        $html = $this->get(route('recipes.show', $recipe->slug))->assertOk()->getContent();

        $this->assertBrakWstrzyknietegoElementu($html);
    }

    public function test_strona_profilu_nie_wypuszcza_html_z_bio(): void
    {
        $autor = $this->user('autorka');
        $autor->profile->update(['bio' => self::ZLOSLIWY]);

        // Profil emituje JSON-LD dopiero, gdy jest jakaś publiczna treść.
        Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'title' => 'Zwykly rosol',
            'slug' => 'zwykly-rosol-'.Str::lower(Str::random(8)),
        ]);

        $html = $this->get(route('profile.show', 'autorka'))->assertOk()->getContent();

        $this->assertBrakWstrzyknietegoElementu($html);
    }

    /**
     * Czy w dokumencie powstał PRAWDZIWY element z atrybutem zdarzeniowym.
     *
     * Świadomie parsujemy DOM, zamiast szukać podciągu „onerror". Bezpiecznie
     * zescapowany tekst `\u003Cimg src=x onerror=alert(1)\u003E` NADAL zawiera
     * litery „onerror" — jest jednak wartością tekstową w JSON-ie, a nie
     * znacznikiem. Asercja na podciągu oblewałaby więc poprawny kod i kusiła,
     * żeby ją rozluźnić. Pytanie brzmi „czy powstał element", więc pytamy o to
     * wprost.
     */
    private function assertBrakWstrzyknietegoElementu(string $html): void
    {
        $dom = new \DOMDocument;

        // Strona ma polskie znaki, a loadHTML zakłada ISO-8859-1.
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $obrazki = $dom->getElementsByTagName('img');

        foreach ($obrazki as $obrazek) {
            $this->assertFalse(
                $obrazek->hasAttribute('onerror'),
                'W dokumencie powstał element <img> z atrybutem onerror — '
                .'JSON-LD wyszło z kontekstu danych.',
            );
        }

        // Drugie, niezależne pytanie: czy treść użytkownika zamknęła element
        // skryptu. Sprawdzamy zawartość KAŻDEJ sekcji JSON-LD.
        foreach ($dom->getElementsByTagName('script') as $skrypt) {
            if ($skrypt->getAttribute('type') !== 'application/ld+json') {
                continue;
            }

            $this->assertStringNotContainsString(
                '</script>',
                $skrypt->textContent,
                'Sekcja JSON-LD zawiera dosłowne </script>.',
            );
        }
    }

    public function test_dane_strukturalne_nadal_da_sie_odczytac(): void
    {
        $autor = $this->user('autorka');

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'title' => 'Żurek na zakwasie',
            'slug' => 'zurek-'.Str::lower(Str::random(8)),
        ]);

        $html = $this->get(route('recipes.show', $recipe->slug))->assertOk()->getContent();

        // Bezpieczeństwo nie może kosztować poprawności: SEO ma nadal działać.
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $trafienia);

        $this->assertNotEmpty($trafienia, 'Brak sekcji JSON-LD na stronie przepisu.');

        $dane = json_decode($trafienia[1], true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'JSON-LD nie jest poprawnym JSON-em.');
        $this->assertSame('Żurek na zakwasie', $dane['name'] ?? null);
    }
}
