<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prawa szyna na kolejnych ekranach (issue #205, po #209).
 *
 * CO TEN TEST PILNUJE — I DLACZEGO AKURAT TEGO
 * Nie tego, że `<aside class="app-rail">` istnieje. Pusty kontener przechodzi
 * taką asercję i nie znaczy nic; zgłoszenie właściciela dotyczyło TREŚCI
 * („prawa kolumna też jest pusta"). Każda asercja niżej sprawdza więc, że
 * w szynie stoi konkretny tekst albo konkretny odnośnik.
 *
 * Trzy rodzaje asercji:
 *  1. szyna zawiera to, co ma zawierać,
 *  2. szyna NIE zawiera cudzych danych prywatnych (zeszyt prywatny, tag
 *     wyłącznie z prywatnego wpisu),
 *  3. kolejność w dokumencie się nie zmienia — `<main>` przed `<aside>`,
 *     żeby `Tab` i czytnik ekranu szły tak samo jak przed tą zmianą.
 */
class SzynaKolejneEkranyTest extends TestCase
{
    use RefreshDatabase;

    /** Zawartość `<aside class="app-rail">` jako HTML — bez reszty strony. */
    private function szyna(string $url, ?User $jako = null): string
    {
        $żądanie = $jako === null ? $this : $this->actingAs($jako);

        $html = (string) $żądanie->get($url)->assertOk()->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $szyna = $xpath->query("//aside[contains(concat(' ', normalize-space(@class), ' '), ' app-rail ')]")->item(0);

        $this->assertNotNull($szyna, "Ekran {$url}: brak <aside class=\"app-rail\"> — szyna nie ma gdzie stanąć.");

        return (string) $dom->saveHTML($szyna);
    }

    /** Cała strona jako tekst — do sprawdzania, czego na niej NIE MA nigdzie. */
    private function strona(string $url, ?User $jako = null): string
    {
        $żądanie = $jako === null ? $this : $this->actingAs($jako);

        return (string) $żądanie->get($url)->assertOk()->getContent();
    }

    private function zeszyt(User $wlasciciel, string $nazwa, string $widocznosc = 'private'): Collection
    {
        return Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => $nazwa,
            'visibility' => $widocznosc,
        ]);
    }

    // --- PROFIL ----------------------------------------------------------

    public function test_wlasny_profil_ma_w_szynie_skroty_i_liste_zeszytow(): void
    {
        $ja = $this->user('gospodyni');
        $this->zeszyt($ja, 'Na święta');

        $szyna = $this->szyna(route('profile.show', 'gospodyni'), $ja);

        $this->assertStringContainsString('Twoje skróty', $szyna);
        $this->assertStringContainsString('Dodaj zdjęcie i kilka słów', $szyna);
        $this->assertStringContainsString('Napisz przepis', $szyna);
        $this->assertStringContainsString(route('settings.avatar'), $szyna);

        $this->assertStringContainsString('Twoje zeszyty', $szyna);
        $this->assertStringContainsString('Na święta', $szyna);
    }

    public function test_cudzy_profil_ma_w_szynie_tagi_i_publiczne_zeszyty(): void
    {
        $autorka = $this->user('halina');
        $widz = $this->user('sasiad');

        $this->zeszyt($autorka, 'Zupy na zimę', 'public');

        $tag = Tag::factory()->create(['name' => 'Rosół']);
        $wpis = Post::factory()->for($autorka, 'author')->create();
        $wpis->tags()->attach($tag->getKey(), ['position' => 0]);

        $szyna = $this->szyna(route('profile.show', 'halina'), $widz);

        $this->assertStringContainsString('Co gotuje', $szyna);
        $this->assertStringContainsString('Rosół', $szyna);
        $this->assertStringContainsString('Zeszyty tej osoby', $szyna);
        $this->assertStringContainsString('Zupy na zimę', $szyna);

        // Cudzy profil NIE dostaje skrótów do dodawania własnych treści —
        // to są czynności, które robi się u siebie.
        $this->assertStringNotContainsString('Twoje skróty', $szyna);
    }

    public function test_cudzy_profil_nie_pokazuje_w_szynie_prywatnego_zeszytu_ani_tagu_z_prywatnego_wpisu(): void
    {
        $autorka = $this->user('basia');
        $widz = $this->user('obcy');

        $this->zeszyt($autorka, 'Tajny zeszyt');

        $tagPrywatny = Tag::factory()->create(['name' => 'Tajnyskladnik']);
        $prywatny = Post::factory()->private()->for($autorka, 'author')->create();
        $prywatny->tags()->attach($tagPrywatny->getKey(), ['position' => 0]);

        $tagDlaObserwujacych = Tag::factory()->create(['name' => 'Tylkodlaswoich']);
        $dlaObserwujacych = Post::factory()->followersOnly()->for($autorka, 'author')->create();
        $dlaObserwujacych->tags()->attach($tagDlaObserwujacych->getKey(), ['position' => 0]);

        // Sprawdzamy CAŁĄ stronę, nie tylko szynę: prywatna nazwa nie ma prawa
        // wyciec żadnym kanałem, także przez podpowiedź obok.
        $strona = $this->strona(route('profile.show', 'basia'), $widz);

        $this->assertStringNotContainsString('Tajny zeszyt', $strona);
        $this->assertStringNotContainsString('Tajnyskladnik', $strona);
        $this->assertStringNotContainsString('Tylkodlaswoich', $strona);
    }

    public function test_tag_z_wpisu_dla_obserwujacych_widzi_obserwujacy(): void
    {
        $autorka = $this->user('kucharka');
        $widz = $this->user('obserwujacy');
        $widz->following()->attach($autorka->getKey());

        $tag = Tag::factory()->create(['name' => 'Pierogitest']);
        $wpis = Post::factory()->followersOnly()->for($autorka, 'author')->create();
        $wpis->tags()->attach($tag->getKey(), ['position' => 0]);

        $this->assertStringContainsString(
            'Pierogitest',
            $this->szyna(route('profile.show', 'kucharka'), $widz),
        );
    }

    // --- ZESZYT (/zeszyt i /zeszyt/{id}) ---------------------------------

    public function test_moje_ma_w_szynie_ostatnio_odlozone(): void
    {
        $ja = $this->user('zbieracz');
        $autor = $this->user('autor_przepisu');

        $zeszyt = $this->zeszyt($ja, 'Zapisane');

        $przepis = Recipe::factory()->for($autor, 'author')->create(['title' => 'Barszcz z uszkami']);
        $zeszyt->recipes()->attach($przepis->getKey(), ['created_at' => now()->subDay()]);

        $wpis = Post::factory()->for($autor, 'author')->create(['body' => 'Placki na niedzielę']);
        $zeszyt->posts()->attach($wpis->getKey(), ['created_at' => now()]);

        $szyna = $this->szyna(route('collections.index'), $ja);

        $this->assertStringContainsString('Ostatnio odłożone', $szyna);
        $this->assertStringContainsString('Barszcz z uszkami', $szyna);
        $this->assertStringContainsString('Placki na niedzielę', $szyna);

        // Kolejność liczy czas ODŁOŻENIA: wpis poszedł do zeszytu dziś,
        // przepis wczoraj — więc wpis stoi wyżej.
        $this->assertLessThan(
            (int) strpos($szyna, 'Barszcz z uszkami'),
            (int) strpos($szyna, 'Placki na niedzielę'),
            'Szyna „Ostatnio odłożone” nie idzie od najświeższego odłożenia.',
        );
    }

    public function test_szyna_moje_nie_pokazuje_tresci_ktorej_juz_nie_wolno_ogladac(): void
    {
        $ja = $this->user('zbieracz2');
        $autor = $this->user('autor2');

        $zeszyt = $this->zeszyt($ja, 'Zapisane');

        // Odłożone, gdy było publiczne — potem autor schował je przed światem.
        $wpis = Post::factory()->private()->for($autor, 'author')->create(['body' => 'Schowany placek']);
        $zeszyt->posts()->attach($wpis->getKey(), ['created_at' => now()]);

        $this->assertStringNotContainsString(
            'Schowany placek',
            $this->strona(route('collections.index'), $ja),
        );
    }

    public function test_pojedynczy_zeszyt_ma_w_szynie_inne_zeszyty_tej_osoby(): void
    {
        $ja = $this->user('wlasciciel_zeszytow');

        $otwarty = $this->zeszyt($ja, 'Na co dzień');
        $this->zeszyt($ja, 'Na święta');

        $szyna = $this->szyna(route('collections.show', $otwarty), $ja);

        $this->assertStringContainsString('Twoje inne zeszyty', $szyna);
        $this->assertStringContainsString('Na święta', $szyna);
        // Zeszyt, w którym stoimy, nie jest odnośnikiem do samego siebie.
        $this->assertStringNotContainsString('Na co dzień', $szyna);
    }

    public function test_w_cudzym_zeszycie_szyna_pokazuje_wylacznie_publiczne(): void
    {
        $autorka = $this->user('autorka_zeszytow');
        $widz = $this->user('widz_zeszytow');

        $otwarty = $this->zeszyt($autorka, 'Otwarty zeszyt', 'public');
        $this->zeszyt($autorka, 'Drugi otwarty', 'public');
        $this->zeszyt($autorka, 'Zamkniety zeszyt');

        $strona = $this->strona(route('collections.show', $otwarty), $widz);

        $this->assertStringContainsString('Inne zeszyty tej osoby', $strona);
        $this->assertStringContainsString('Drugi otwarty', $strona);
        $this->assertStringNotContainsString('Zamkniety zeszyt', $strona);
    }

    // --- EKRANY BEZ ZAPYTAŃ ---------------------------------------------

    public function test_dodaj_ma_w_szynie_podpowiedz_o_zdjeciu(): void
    {
        $szyna = $this->szyna(route('add'), $this->user('dodajacy'));

        $this->assertStringContainsString('Zdjęcie, które dobrze wychodzi', $szyna);
        $this->assertStringContainsString('Nie musi być ładnie. Ma być Twoje.', $szyna);
    }

    public function test_napisz_do_nas_ma_w_szynie_pomoc_i_zasady(): void
    {
        $szyna = $this->szyna(route('kontakt'));

        $this->assertStringContainsString('Może odpowiedź już tu jest', $szyna);
        $this->assertStringContainsString(route('help'), $szyna);
        $this->assertStringContainsString(route('rules'), $szyna);
        // Osoba niezalogowana dostaje najczęstszy powód pisania do nas.
        $this->assertStringContainsString(route('password.request'), $szyna);
    }

    public function test_zalogowany_nie_dostaje_w_szynie_odzyskiwania_hasla(): void
    {
        $szyna = $this->szyna(route('kontakt'), $this->user('zalogowany_kontakt'));

        $this->assertStringNotContainsString('Nie możesz się zalogować', $szyna);
    }

    // --- KOLEJNOŚĆ W DOM -------------------------------------------------

    /**
     * `<main>` PRZED `<aside class="app-rail">` na każdym ruszonym ekranie.
     *
     * To jest kolejność `Tab` i kolejność czytania przez czytnik ekranu.
     * CSS-owy `order` zmieniłby to, co widać, ale nie to — dlatego test
     * patrzy na surową kolejność znaczników w źródle, nie na wygląd.
     */
    public function test_tresc_glowna_stoi_w_kodzie_przed_szyna(): void
    {
        $ja = $this->user('kolejnosc');
        $zeszyt = $this->zeszyt($ja, 'Zeszyt do kolejności');
        // Drugi zeszyt, żeby `/zeszyt/{id}` miało co pokazać w szynie —
        // przy jednym zeszycie szyna zostaje świadomie pusta.
        $this->zeszyt($ja, 'Drugi zeszyt do kolejności');

        $ekrany = [
            route('profile.show', 'kolejnosc'),
            route('collections.index'),
            route('collections.show', $zeszyt),
            route('add'),
            route('kontakt'),
        ];

        foreach ($ekrany as $url) {
            $html = $this->strona($url, $ja);

            $tresc = strpos($html, 'id="tresc"');
            $szyna = strpos($html, 'class="app-rail"');

            $this->assertIsInt($tresc, "Ekran {$url}: brak <main id=\"tresc\">.");
            $this->assertIsInt($szyna, "Ekran {$url}: brak <aside class=\"app-rail\">.");

            $this->assertLessThan(
                $szyna,
                $tresc,
                "Ekran {$url}: szyna wyprzedza treść główną w kodzie — kolejność Tab i czytnika ekranu byłaby odwrócona.",
            );
        }
    }
}
