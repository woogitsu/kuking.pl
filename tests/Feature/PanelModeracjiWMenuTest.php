<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
     * W PANELU moderator widzi WYDZIELONĄ sekcję — prawdziwy nagłówek, grupę
     * dostępną dla czytnika ekranu i wszystkie sześć odnośników.
     *
     * Do 11 września ta lista stała też POZA panelem, w zwykłym menu obok
     * „Profil", a pod nią przycisk prowadzący dokładnie tam, gdzie prowadziła
     * jej pierwsza pozycja. Zgłoszenie właściciela: „po co w menu cały panel
     * moderacji i pod spodem przycisk »Otwórz panel moderacji«?". Spis ekranów
     * pracy został więc tam, gdzie się tę pracę wykonuje.
     */
    public function test_w_panelu_moderator_widzi_sekcje_i_wszystkie_szesc_odnosnikow(): void
    {
        $moderator = $this->moderator();

        $html = $this->actingAs($moderator)->get('/admin/zgloszenia')->assertOk()->getContent();
        $boczna = $this->wytnijBoczna($html);

        $this->assertStringContainsString(
            '<h2 class="side-nav-moderacja-naglowek" id="side-nav-moderacja-naglowek">Panel moderacji</h2>',
            $boczna,
            'Brak prawdziwego nagłówka sekcji panelu w menu bocznym panelu.',
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
                "Moderator nie widzi pozycji „{$pozycja}” w menu panelu.",
            );
        }
    }

    /**
     * POZA PANELEM menu ma jedno wejście i ani jednej pozycji panelu.
     *
     * Bez tej połowy poprzedni test przechodziłby także wtedy, gdyby lista
     * wróciła na każdy ekran serwisu — sprawdzałby bowiem tylko, że w panelu
     * jest, a nie że gdzie indziej jej nie ma.
     */
    public function test_poza_panelem_menu_ma_tylko_wejscie_do_panelu(): void
    {
        $moderator = $this->moderator();

        $html = $this->actingAs($moderator)->get('/home')->assertOk()->getContent();
        $boczna = $this->wytnijBoczna($html);

        $this->assertStringContainsString(
            'Otwórz panel moderacji',
            $boczna,
            'Moderator stracił wejście do panelu ze zwykłego menu.',
        );

        $this->assertStringNotContainsString(
            'side-nav-moderacja-naglowek',
            $boczna,
            'Nagłówek „Panel moderacji" wrócił do zwykłego menu — razem z przyciskiem '.
            'poniżej mówi tę samą rzecz dwa razy.',
        );

        foreach (self::POZYCJE_PANELU as $pozycja) {
            // „Zgłoszenia" wypada z tej pętli: samo słowo pada też w innych
            // miejscach menu i asercja negatywna na nie łapałaby cudzy tekst.
            if ($pozycja === 'Zgłoszenia') {
                continue;
            }

            $this->assertStringNotContainsString(
                $pozycja,
                $boczna,
                "Pozycja panelu „{$pozycja}” wróciła do zwykłego menu.",
            );
        }
    }

    /**
     * Wejście do panelu niesie SUMĘ wszystkich pięciu kolejek.
     *
     * Bez tej plakietki moderator straciłby poza panelem jedyny sygnał „jest
     * robota", jaki dotąd miał przy pozycji „Bez odpowiedzi" — a cicha strata
     * poprawnej informacji jest zakazana wprost (AGENTS.md).
     *
     * DLACZEGO TEST WSTAWIA LICZBY DO CACHE, ZAMIAST TWORZYĆ ZGŁOSZENIA:
     * `KolejkiPanelu::liczby()` czyta wyłącznie cache, a przeliczaniem zajmuje
     * się harmonogram. Test ma sprawdzić SUMOWANIE w widoku, a nie pięć
     * zapytań, które mają własny test (`LicznikiKolejekBezZapytanTest`).
     *
     * I najważniejsze: pusta kolejka NIE renderuje plakietki, więc test
     * wykonany tylko na czystej bazie przechodziłby także dla kodu, który tej
     * plakietki nie ma wcale (D-099/D-106). Dlatego są tu OBA stany.
     */
    public function test_wejscie_do_panelu_pokazuje_sume_kolejek(): void
    {
        $moderator = $this->moderator();

        Cache::forever('panel:kolejki', [
            'bez_odpowiedzi' => 6,
            'zgloszenia' => 2,
            'sygnaly' => 1,
            'odwolania' => 0,
            'wiadomosci' => 3,
        ]);

        $boczna = $this->wytnijBoczna(
            (string) $this->actingAs($moderator)->get('/home')->assertOk()->getContent(),
        );

        // Liczba widoczna i ta sama liczba słowami dla czytnika ekranu.
        // Forma czasownika idzie z `Odmiana::rzeczownik` — dla dwunastu jest to
        // „czeka”, nie „czekają”, i test bierze ją z komponentu, zamiast
        // zgadywać polską odmianę po swojemu.
        $this->assertStringContainsString('<span aria-hidden="true">12</span>', $boczna,
            'Wejście do panelu nie pokazuje sumy wszystkich kolejek.');
        $this->assertStringContainsString('12 czeka', $boczna,
            'Suma kolejek nie jest czytana przez czytnik ekranu.');

        // Stan przeciwny: puste kolejki nie pokazują „0”. Zero to sam hałas,
        // a plakietka ma znaczyć „tu jest praca”.
        Cache::forever('panel:kolejki', [
            'bez_odpowiedzi' => 0,
            'zgloszenia' => 0,
            'sygnaly' => 0,
            'odwolania' => 0,
            'wiadomosci' => 0,
        ]);

        $pusta = $this->wytnijBoczna(
            (string) $this->actingAs($moderator)->get('/home')->assertOk()->getContent(),
        );

        $this->assertStringContainsString('Otwórz panel moderacji', $pusta);
        $this->assertStringNotContainsString('licznik-kolejki', $pusta,
            'Puste kolejki pokazują plakietkę — „0” na ekranie jest samym hałasem.');
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

        // `.*?</a>` po nazwie, nie `Zgłoszenia</a>` wprost: od 10 września
        // pozycja kolejki może mieć w środku odnośnika licznik tego, co czeka
        // (`<x-licznik-kolejki>`), więc nazwa nie jest już ostatnią rzeczą
        // przed zamknięciem znacznika. Sprawdzana rzecz — `aria-current` na
        // pozycji „Zgłoszenia" — zostaje bez zmian.
        $this->assertMatchesRegularExpression(
            '~<a class="side-nav-item" href="[^"]*"\s+aria-current="page"\s*>.*?Zgłoszenia.*?</a>~s',
            $boczna,
            'Pozycja „Zgłoszenia” nie ma `aria-current="page"`, mimo że to bieżący ekran.',
        );

        // I tylko jedna pozycja panelu jest bieżąca naraz.
        $this->assertSame(1, substr_count($boczna, 'aria-current="page"'));
    }
}
