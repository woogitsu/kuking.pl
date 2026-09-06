<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Karta Open Graph — jak wygląda link wklejony w Messengera (issue #14).
 *
 * CO SIĘ DZIAŁO
 * Strona podawała `og:title` i `og:description`, ale **nie podawała
 * `og:image`**. Link do przepisu wklejony w Messengera, WhatsAppa albo
 * na Facebooka pokazywał sam tytuł, bez zdjęcia.
 *
 * W serwisie o gotowaniu to jest strata najważniejszej rzeczy. Nikt nie
 * klika w link do jedzenia, którego nie widać — a „wyślij rodzinie link
 * do przepisu po babci" jest jedną z głównych dróg, którymi Kuking ma
 * do kogokolwiek trafić.
 *
 * TESTUJEMY TO, CZEGO NIE WIDAĆ W PRZEGLĄDARCE
 * Znaczniki `og:` są niewidoczne na stronie. Jedyny sposób, żeby zauważyć
 * usterkę bez testu, to wkleić link do Messengera i spojrzeć — czyli
 * czynność, której nikt nie wykona po każdej zmianie w widoku.
 */
class KartaDoUdostepnianiaTest extends TestCase
{
    use RefreshDatabase;

    private function przepisZeZdjeciem(): Recipe
    {
        $autor = $this->user('kucharka');

        $zdjecie = Media::factory()->create([
            'owner_id' => $autor->getKey(),
            'status' => Media::STATUS_READY,
        ]);

        return Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
            'hero_media_id' => $zdjecie->getKey(),
        ]);
    }

    public function test_przepis_publiczny_ma_zdjecie_w_karcie(): void
    {
        $przepis = $this->przepisZeZdjeciem();

        $html = $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '~<meta property="og:image" content="[^"]+">~',
            $html,
            'Przepis publiczny nie podaje zdjęcia do karty — link w Messengerze będzie bez obrazka.',
        );

        // Duża karta ma sens tylko wtedy, gdy jest prawdziwe zdjęcie.
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $html);
    }

    public function test_adres_zdjecia_jest_bezwzgledny(): void
    {
        $przepis = $this->przepisZeZdjeciem();

        $html = $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        preg_match('~<meta property="og:image" content="([^"]+)">~', $html, $m);

        // TO JEST SEDNO CAŁEJ NAPRAWY.
        //
        // Scraper Facebooka nie ma kontekstu strony: „/storage/media/x.jpg"
        // jest dla niego niczym i taki obrazek zostaje CICHO POMINIĘTY —
        // karta wraca do postaci bez zdjęcia, a w kodzie strony wszystko
        // wygląda poprawnie. Dysk lokalny zwraca ścieżkę względną, R2 pełny
        // adres, więc bez tej asercji usterka wróciłaby przy zmianie dysku.
        $this->assertStringStartsWith('http', $m[1] ?? '', 'og:image nie jest adresem bezwzględnym.');
    }

    public function test_strona_bez_zdjecia_dostaje_karte_zapasowa(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        preg_match('~<meta property="og:image" content="([^"]+)">~', $html, $m);

        // PNG, nie SVG. Facebook, WhatsApp i Signal nie renderują SVG
        // i pokazują pustą ramkę zamiast karty.
        $this->assertStringEndsWith('.png', $m[1] ?? '', 'Karta zapasowa nie jest PNG-iem.');
        $this->assertFileExists(public_path('icons/kuking-udostepnianie.png'));

        // Przy samym logo duży format to wielka plama koloru z małym znaczkiem.
        $this->assertStringContainsString('name="twitter:card" content="summary"', $html);
    }

    public function test_szkic_nie_podaje_zdjecia_do_karty(): void
    {
        $autor = $this->user('kucharka');

        $zdjecie = Media::factory()->create([
            'owner_id' => $autor->getKey(),
            'status' => Media::STATUS_READY,
        ]);

        $szkic = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_DRAFT,
            'visibility' => 'public',
            'hero_media_id' => $zdjecie->getKey(),
        ]);

        $html = $this->actingAs($autor)
            ->get(route('recipes.show', $szkic->slug))
            ->assertOk()
            ->getContent();

        preg_match('~<meta property="og:image" content="([^"]+)">~', $html, $m);

        // Przy szkicu nie ma czego udostępniać, a adres zdjęcia nie ma po co
        // trafiać do znacznika, który zbierają scrapery. Wchodzi karta zapasowa.
        $this->assertStringEndsWith('.png', $m[1] ?? '');
        $this->assertStringContainsString('name="robots" content="noindex, nofollow"', $html);
    }

    public function test_wpis_ze_zdjeciem_ma_je_w_karcie(): void
    {
        $autor = $this->user('kucharka');
        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);

        $zdjecie = Media::factory()->create([
            'owner_id' => $autor->getKey(),
            'status' => Media::STATUS_READY,
        ]);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $html = $this->get(route('posts.show', $wpis))->assertOk()->getContent();

        // Wpis to najczęściej samo zdjęcie z podpisem — bez tego link
        // wklejony w Messengera nie pokazuje NICZEGO poza imieniem autora.
        //
        // Najpierw pytamy, czy znacznik W OGÓLE JEST. Bez tego pierwsza wersja
        // testu przechodziła na kodzie sprzed naprawy: brak znacznika daje
        // pusty ciąg, a pusty ciąg „nie zawiera" nazwy karty zapasowej.
        // Test przechodził, nie sprawdzając niczego.
        $this->assertSame(
            1,
            preg_match('~<meta property="og:image" content="([^"]+)">~', $html, $m),
            'Wpis nie podaje w ogóle znacznika og:image.',
        );

        $this->assertStringNotContainsString('kuking-udostepnianie.png', $m[1]);
    }

    public function test_kazda_strona_ma_adres_kanoniczny(): void
    {
        $przepis = $this->przepisZeZdjeciem();

        $html = $this->get(route('recipes.show', $przepis->slug).'?zakladka=cos')
            ->assertOk()
            ->getContent();

        // Ten sam przepis wysłany z parametrem w adresie nie może liczyć się
        // jako osobna strona — inaczej zbiera własne polubienia zamiast
        // dołożyć do wspólnej puli, a wyszukiwarka widzi duplikat treści.
        $kanoniczny = route('recipes.show', $przepis->slug);

        $this->assertStringContainsString('<link rel="canonical" href="'.$kanoniczny.'">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="'.$kanoniczny.'">', $html);
    }

    public function test_karta_jest_po_polsku_i_ma_typ(): void
    {
        $przepis = $this->przepisZeZdjeciem();

        $html = $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:locale" content="pl_PL">', $html);
        $this->assertStringContainsString('<meta property="og:type" content="article">', $html);
    }
}
