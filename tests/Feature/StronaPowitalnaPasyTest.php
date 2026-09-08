<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Strona powitalna stoi na pasach i nic w niej nie wisi przy krawędzi okna.
 *
 * SKĄD SIĘ WZIĄŁ TEN UKŁAD
 * System projektowy v3.1 (`docs/design/system-v3.1/site.css`, §1) buduje
 * strony publiczne z PASÓW: sekcja na całą szerokość okna, własne tło,
 * a szerokość treści pilnuje wnętrze pasa. Do 8 września 2026 strona
 * powitalna była jedną kolumną w ramce ekranu zalogowanego — czyli dokładnie
 * tą różnicą, którą `STAN_WDROZENIA_KITU.md` od początku nazywał jedyną
 * niezgodnością z kitem: „nie zgadza się układ".
 *
 * CO TAKI UKŁAD PSUJE, GDY SIĘ GO ŹLE ZŁOŻY
 * Pas bez wnętrza to tekst dotykający krawędzi okna — na telefonie pierwsza
 * i ostatnia litera każdego wiersza stoją pod palcem. Wnętrze w wnętrzu to
 * podwójne wcięcie: treść nagle węższa o 48 px w jednej sekcji z sześciu.
 * Jedno i drugie widać dopiero w przeglądarce i tylko na tej jednej stronie,
 * więc nie ma czego złapać przy przeglądaniu kodu.
 *
 * DLACZEGO TU JEST TAKŻE ASERCJA NA ARKUSZ
 * Ramka wokół treści (`max-width`, wcięcia boczne) siedzi w CSS, nie
 * w widoku. Można napisać sześć poprawnych pasów i nie zobaczyć ani jednego
 * na całą szerokość, bo kontener wyżej dalej je przycina — a HTML wygląda
 * wtedy bez zarzutu.
 */
class StronaPowitalnaPasyTest extends TestCase
{
    use RefreshDatabase;

    private function dokument(): DOMXPath
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($dom);
    }

    /**
     * @return list<DOMElement>
     */
    private function zKlasa(DOMXPath $xpath, string $klasa): array
    {
        $wynik = [];

        /** @var DOMElement $element */
        foreach ($xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$klasa} ')]") as $element) {
            $wynik[] = $element;
        }

        return $wynik;
    }

    private function arkusz(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'))
            .(string) file_get_contents(resource_path('css/strony-publiczne.css'));
    }

    public function test_kazdy_pas_ma_wnetrze_i_zadne_nie_jest_w_drugim(): void
    {
        $xpath = $this->dokument();

        $pasy = $this->zKlasa($xpath, 'pas');

        // ASERCJA KONTROLNA. Bez niej strona bez ani jednego pasa — czyli
        // cofnięta do poprzedniego układu — przeszłaby ten test pusto.
        $this->assertGreaterThanOrEqual(
            5,
            count($pasy),
            'Strona powitalna ma mniej niż pięć pasów. Albo wróciła do układu jednokolumnowego, '
            .'albo argument, który te pasy prowadzą, został skrócony bez zmiany tego testu.',
        );

        foreach ($pasy as $pas) {
            $wnetrza = $xpath->query(
                ".//*[contains(concat(' ', normalize-space(@class), ' '), ' pas-wnetrze ')]",
                $pas,
            );

            $this->assertSame(
                1,
                $wnetrza->length,
                'Pas „'.trim((string) $pas->getAttribute('class')).'" ma '.$wnetrza->length
                .' wnętrz zamiast jednego. Bez wnętrza tekst dotyka krawędzi okna; '
                .'dwa wnętrza to podwójne wcięcie i sekcja węższa od pozostałych.',
            );
        }
    }

    public function test_ciemny_pas_ma_wlasna_palete_i_sam_sie_maluje(): void
    {
        $xpath = $this->dokument();

        $ciemne = $this->zKlasa($xpath, 'blok-ciemny');

        $this->assertCount(
            1,
            $ciemne,
            'Ciemny pas („Ugotowałem") zniknął albo zrobiło się ich kilka — a to jedyne miejsce '
            .'na stronie, które ma odwróconą paletę.',
        );

        $tokeny = (string) file_get_contents(resource_path('css/tokens.css'));

        // Sama paleta nie wystarczy: <html> maluje tło regułą na `body`,
        // a zwykły <section> takiej reguły nie ma i dostałby jasne litery
        // na jasnym tle.
        $this->assertMatchesRegularExpression(
            '/\.blok-ciemny\s*\{[^}]*--color-ink:/',
            $tokeny,
            '`.blok-ciemny` nie przestawia palety — ciemny pas wziąłby kolory strony wokół.',
        );

        $this->assertMatchesRegularExpression(
            '/\.blok-ciemny\s*\{[^}]*background-color:\s*var\(--color-surface\)/',
            $tokeny,
            '`.blok-ciemny` nie maluje własnego tła. Litery zrobią się jasne, tło zostanie jasne '
            .'i cały pas będzie nieczytelny.',
        );
    }

    /**
     * Ramka wokół treści musi tu zniknąć CAŁKOWICIE — i to POZA zasięgiem
     * obu progów szerokości.
     *
     * `.app-body` ma sufit szerokości i wcięcia boczne — słusznie, bo trzyma
     * nawigację, treść i szynę w jednej siatce. Ustawia je dwa razy: raz od
     * `64rem`, drugi raz od `80rem`, gdy dochodzi szyna. Zdjęcie ich w układzie
     * pasów musi więc stać w pliku ZA obiema tymi regułami, bo zapytanie
     * o szerokość nie podnosi wagi selektora i przy równej wadze rozstrzyga
     * kolejność.
     *
     * TO NIE JEST HIPOTEZA. Pierwsza wersja tej poprawki miała blok „układ
     * pasów" przed progiem `80rem`. Skutek: pasy szły do krawędzi okna między
     * 1024 a 1280 px, a od 1280 px wracały do sufitu 1424 px — czyli
     * przestawały działać dokładnie na tych ekranach, dla których powstały.
     * Wygląda to jak celowy układ, więc nikt by tego nie zgłosił jako usterki.
     */
    public function test_uklad_powitalny_nie_przycina_pasow(): void
    {
        $arkusz = (string) file_get_contents(resource_path('css/app.css'));

        $bezSufitu = $this->ostatniaRegula($arkusz, '.app-body-powitalny', '/max-width:\s*none/');

        $this->assertNotNull(
            $bezSufitu,
            'Żadna reguła `.app-body-powitalny` nie zdejmuje sufitu szerokości — '
            .'pasy nie dojdą do krawędzi okna.',
        );

        $this->assertMatchesRegularExpression(
            '/padding:\s*0/',
            $bezSufitu['tresc'],
            'Układ pasów zdejmuje sufit szerokości, ale zostawia wcięcia boczne — '
            .'każdy pas dostanie wtedy pasek tła po obu stronach.',
        );

        $zSufitem = $this->ostatniaRegula($arkusz, '.app-body', '/max-width:\s*var\(/');

        $this->assertNotNull($zSufitem, 'W arkuszu nie ma ani jednej reguły `.app-body` z sufitem szerokości.');

        $this->assertGreaterThan(
            $zSufitem['pozycja'],
            $bezSufitu['pozycja'],
            'Reguła zdejmująca sufit stoi w pliku PRZED ostatnią regułą `.app-body`, '
            .'która sufit nakłada (pozycja '.$zSufitem['pozycja'].'). Zapytanie o szerokość nie '
            .'podnosi wagi selektora, więc od tamtego progu wygra sufit i pasy zostaną przycięte.',
        );

        preg_match('/\.app-body-powitalny \.app-main\s*\{([^}]*)\}/', $arkusz, $main);

        $this->assertNotEmpty($main, 'W arkuszu nie ma reguły `.app-body-powitalny .app-main`.');

        $this->assertMatchesRegularExpression(
            '/padding:\s*0/',
            $main[1],
            '`<main>` w układzie pasów dalej ma własne wcięcie. Doda się ono do wcięcia '
            .'`.pas-wnetrze` i treść będzie węższa dwa razy — najbardziej widoczne na telefonie.',
        );
    }

    /**
     * Ostatnia w pliku reguła o danym selektorze, której treść pasuje do wzorca.
     *
     * Selektor jest dopasowywany DOKŁADNIE (`.app-body` nie łapie
     * `.app-body-solo`), bo cały sens tego sprawdzenia to porównanie dwóch
     * konkretnych reguł, nie rodziny nazw.
     *
     * @return array{pozycja: int, tresc: string}|null
     */
    private function ostatniaRegula(string $arkusz, string $selektor, string $wzorzec): ?array
    {
        preg_match_all(
            '/(?<![a-z-])'.preg_quote($selektor, '/').'\s*\{([^}]*)\}/',
            $arkusz,
            $trafienia,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        $wynik = null;

        foreach ($trafienia as $trafienie) {
            if (preg_match($wzorzec, $trafienie[1][0]) === 1) {
                $wynik = ['pozycja' => (int) $trafienie[0][1], 'tresc' => $trafienie[1][0]];
            }
        }

        return $wynik;
    }

    /**
     * Logotyp ma stać nad pierwszym słowem hasła, a nie obok niego.
     *
     * To jest ta sama zasada, którą pilnuje blok „BELKA I STOPKA STOJĄ W TEJ
     * SAMEJ SIATCE CO TREŚĆ" w `app.css`: jedna liczba na jedną krawędź.
     * Wcześniej strona powitalna miała nadpisanie belki na
     * `--container-strona-z-szyna`, bo jej treść była tak szeroka. Pas jest
     * węższy, więc nadpisanie musiało zniknąć — i ten test jest jedyną
     * rzeczą, która zauważy, gdyby ktoś je przywrócił.
     */
    public function test_belka_i_stopka_maja_szerokosc_pasa(): void
    {
        $pasy = (string) file_get_contents(resource_path('css/strony-publiczne.css'));

        preg_match('/\.pas-wnetrze\s*\{([^}]*)\}/', $pasy, $wnetrze);
        $this->assertNotEmpty($wnetrze, 'W arkuszu stron publicznych nie ma reguły `.pas-wnetrze`.');

        preg_match('/max-width:\s*var\((--[a-z0-9-]+)\)/', $wnetrze[1], $token);
        $this->assertNotEmpty($token, '`.pas-wnetrze` nie bierze szerokości z tokenu.');

        $arkusz = (string) file_get_contents(resource_path('css/app.css'));

        preg_match_all(
            '/\.uklad-powitalny \.topbar-inner,\s*\.uklad-powitalny \.site-footer-inner\s*\{([^}]*)\}/',
            $arkusz,
            $nadpisania,
            PREG_SET_ORDER,
        );

        foreach ($nadpisania as $nadpisanie) {
            $this->assertStringContainsString(
                'var('.$token[1].')',
                $nadpisanie[1],
                'Belka strony powitalnej ma inną szerokość niż pas ('.$token[1].'). '
                .'Logotyp stanie w innym miejscu niż pierwsze słowo hasła — dokładnie ten '
                .'rozjazd, który naprawiał blok o wspólnej siatce w `app.css`.',
            );
        }
    }

    /**
     * Przebudowa układu nie ma prawa zgubić drogi dalej.
     *
     * Trzy wyjścia z tej strony — rejestracja, logowanie i „Świeżo z Kuking"
     * — to jedyne, po co ta strona istnieje. Przy przenoszeniu treści między
     * sześcioma sekcjami najłatwiej zgubić właśnie je, bo każde jest
     * pojedynczym odnośnikiem w innym pasie.
     */
    public function test_strona_dalej_prowadzi_dokads(): void
    {
        $odpowiedz = $this->get(route('landing'))->assertOk();

        foreach (['register', 'login', 'discover'] as $trasa) {
            $odpowiedz->assertSee(route($trasa), false);
        }
    }
}
