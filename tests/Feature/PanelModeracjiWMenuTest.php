<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Wydzielona sekcja „Panel moderacji" w menu bocznym i jednolite oznaczenie
 * ekranów `/admin/**` (zgłoszenie właściciela: „panel admina i moderatora
 * jest zlany z normalnym menu — nie wiadomo, co jest zwykłą podstroną,
 * a co adminową").
 *
 * NA CZYM POLEGAŁ BŁĄD
 * Sześć pozycji panelu stało w tym samym `<ul>` co „Start"/„Szukaj"/…, tą
 * samą czcionką, bez nagłówka i bez przerwy PRZED nimi — kreska
 * (`.side-nav-dol`) stała tylko PO panelu. Same ekrany `/admin/**` nie
 * miały żadnego wspólnego paska — sześć osobnych `<x-layout>` bez śladu
 * tego, że to panel, poza samym adresem.
 *
 * Test HTTP nie widzi kolorów, tła ani odstępów — to ocenia się okiem.
 * Pilnuje tego, co regresja cofa najciszej: czy sekcja ma w ogóle własny
 * nagłówek, czy widzi ją WYŁĄCZNIE moderator (nie zwykły użytkownik, nie
 * gość), i czy same ekrany panelu dalej mówią, że są panelem.
 */
class PanelModeracjiWMenuTest extends TestCase
{
    use RefreshDatabase;

    private const POZYCJE_PANELU = [
        'Bez odpowiedzi',
        'Zgłoszenia',
        'Odwołania',
        'Tablica na dziś',
        'Tagi promowane',
        'Wiadomości do nas',
    ];

    /** Ten sam sposób wycinania fragmentu co `NawigacjaAktywnaPozycjaTest`. */
    private function wytnijBoczna(string $html): string
    {
        $start = strpos($html, '<nav class="side-nav"');
        $this->assertNotFalse($start, 'Brak nawigacji bocznej na stronie.');

        $koniec = strpos($html, '</nav>', $start);
        $this->assertNotFalse($koniec, 'Nawigacja boczna nie jest domknięta.');

        return substr($html, $start, $koniec - $start);
    }

    /**
     * Moderator widzi WYDZIELONĄ sekcję — prawdziwy nagłówek, grupę
     * dostępną dla czytnika ekranu i wszystkie sześć odnośników panelu.
     */
    public function test_moderator_widzi_sekcje_panelu_i_wszystkie_szesc_odnosnikow(): void
    {
        $moderator = $this->moderator();

        $html = $this->actingAs($moderator)->get('/home')->assertOk()->getContent();
        $boczna = $this->wytnijBoczna($html);

        $this->assertStringContainsString(
            '<h2 class="side-nav-moderacja-naglowek" id="side-nav-moderacja-naglowek">Panel moderacji</h2>',
            $boczna,
            'Brak prawdziwego nagłówka sekcji panelu w menu bocznym.',
        );
        $this->assertStringContainsString(
            'role="group" aria-labelledby="side-nav-moderacja-naglowek"',
            $boczna,
            'Grupa panelu nie jest powiązana z nagłówkiem dla czytnika ekranu.',
        );

        foreach (self::POZYCJE_PANELU as $pozycja) {
            $this->assertStringContainsString(
                $pozycja,
                $boczna,
                "Moderator nie widzi pozycji „{$pozycja}” w menu.",
            );
        }
    }

    /**
     * Zwykły użytkownik NIE widzi ani nagłówka sekcji, ani żadnego z sześciu
     * odnośników — asercja na treść HTML, nie na oko.
     */
    public function test_zwykly_uzytkownik_nie_widzi_ani_sekcji_ani_odnosnikow_panelu(): void
    {
        $uzytkownik = $this->user('basia');

        $html = $this->actingAs($uzytkownik)->get('/home')->assertOk()->getContent();
        $boczna = $this->wytnijBoczna($html);

        $this->assertStringNotContainsString(
            'Panel moderacji',
            $boczna,
            'Zwykły użytkownik widzi nagłówek sekcji panelu — powinien dostać dokładnie te same '
            .'pięć pozycji co dotąd.',
        );
        $this->assertStringNotContainsString('side-nav-moderacja', $boczna);

        foreach (self::POZYCJE_PANELU as $pozycja) {
            $this->assertStringNotContainsString(
                $pozycja,
                $boczna,
                "Zwykły użytkownik widzi pozycję panelu „{$pozycja}”.",
            );
        }
    }

    /**
     * Gość niezalogowany — to samo co zwykły użytkownik. Sprawdzamy CAŁĄ
     * stronę, nie tylko `side-nav` (gość i tak go nie ma, patrz
     * `NawigacjaAktywnaPozycjaTest::test_gosc_nie_ma_menu_zalogowanego…`),
     * żeby złapać też ewentualny wyciek gdzie indziej na stronie.
     */
    public function test_gosc_niezalogowany_nie_widzi_ani_sekcji_ani_odnosnikow_panelu(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Panel moderacji', $html);
        $this->assertStringNotContainsString('side-nav-moderacja', $html);

        foreach (self::POZYCJE_PANELU as $pozycja) {
            $this->assertStringNotContainsString($pozycja, $html);
        }
    }

    /**
     * Strona panelu ma widoczne oznaczenie, że to panel — nie tylko po
     * adresie: pasek na ekranie ORAZ `<title>` karty przeglądarki.
     */
    public function test_strona_panelu_ma_widoczne_oznaczenie_ze_to_panel(): void
    {
        $moderator = $this->moderator();

        $html = $this->actingAs($moderator)->get(route('admin.reports'))->assertOk()->getContent();

        $this->assertStringContainsString('class="panel-pasek"', $html, 'Brak paska panelu na ekranie.');
        $this->assertStringContainsString('Panel moderacji', $html);
        $this->assertStringContainsString(
            '<title>Zgłoszenia — Panel moderacji — Kuking</title>',
            $html,
            'Tytuł karty przeglądarki nie mówi, że to panel.',
        );
    }

    /**
     * KAŻDY ekran panelu, nie jeden wybrany.
     *
     * Cały sens `<x-panel-moderacji>` polega na tym, że oznaczenie jest
     * w JEDNYM miejscu, a nie przepisane sześć razy z ręki. Test na jednym
     * ekranie tego nie pilnuje: siódmy ekran, dodany kiedyś bez paska,
     * przeszedłby niezauważony i wróciłoby dokładnie to, na co skarżył się
     * właściciel („nie wiadomo, co jest podstroną, a co panelem").
     *
     * Trasy bierzemy z routera, nie z listy przepisanej tutaj — lista w teście
     * zdążyłaby się rozjechać z rzeczywistością. Pomijamy trasy z parametrem
     * (np. `admin.contact.show`), bo wymagają istniejącego rekordu; ich układ
     * i tak jest ten sam.
     *
     * NAZWA MUSI ZACZYNAĆ SIĘ OD `test_`, I TO NIE JEST DROBIAZG.
     * Pierwsza wersja miała nazwę bez tego przedrostka i atrybut `#[Test]`
     * bez importu — czyli atrybut wskazywał na nieistniejącą klasę
     * `Tests\Feature\Test`. PHPUnit takiego atrybutu nie rozpoznaje i po
     * cichu POMIJA metodę: przebieg świecił na zielono, a ten test nie
     * uruchomił się ani razu. Wyłapał to dopiero Larastan. Reszta pliku
     * używa przedrostka `test_`, więc trzymamy się jednej konwencji zamiast
     * mieszać dwie.
     */
    public function test_kazdy_ekran_panelu_ma_pasek_i_dopisek_w_tytule(): void
    {
        $moderator = $this->moderator();

        $trasy = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($trasa): bool => in_array('GET', $trasa->methods(), true))
            ->filter(fn ($trasa): bool => str_starts_with((string) $trasa->uri(), 'admin/'))
            ->filter(fn ($trasa): bool => ! str_contains((string) $trasa->uri(), '{'))
            ->map(fn ($trasa): string => (string) $trasa->uri())
            ->values();

        $this->assertGreaterThanOrEqual(
            6,
            $trasy->count(),
            'Router oddał mniej niż sześć bezparametrowych ekranów panelu — czytam złe trasy.',
        );

        foreach ($trasy as $uri) {
            $html = $this->actingAs($moderator)->get('/'.$uri)->assertOk()->getContent();

            $this->assertStringContainsString(
                'class="panel-pasek"',
                (string) $html,
                'Ekran /'.$uri.' nie ma paska panelu. Dodaj `<x-panel-moderacji ekran="…" />` '
                .'zaraz po otwarciu `<x-layout>` — po to ten komponent istnieje.',
            );

            $this->assertStringContainsString(
                '— Panel moderacji — Kuking</title>',
                (string) $html,
                'Tytuł karty na /'.$uri.' nie mówi, że to panel. Przekaż '
                .'`title="… — Panel moderacji"` do `<x-layout>`.',
            );
        }
    }

    /**
     * `aria-current="page"` zostaje na bieżącej pozycji panelu — dokładnie
     * tak samo, jak na zwykłych pozycjach (`NawigacjaAktywnaPozycjaTest`).
     */
    public function test_aria_current_dziala_na_biezacej_pozycji_panelu(): void
    {
        $moderator = $this->moderator();

        $html = $this->actingAs($moderator)->get(route('admin.reports'))->assertOk()->getContent();
        $boczna = $this->wytnijBoczna($html);

        $this->assertMatchesRegularExpression(
            '~<a class="side-nav-item" href="[^"]*"\s+aria-current="page"\s*>.*?Zgłoszenia</a>~s',
            $boczna,
            'Pozycja „Zgłoszenia” nie ma `aria-current="page"`, mimo że to bieżący ekran.',
        );

        // I tylko jedna pozycja panelu jest bieżąca naraz.
        $this->assertSame(1, substr_count($boczna, 'aria-current="page"'));
    }
}
