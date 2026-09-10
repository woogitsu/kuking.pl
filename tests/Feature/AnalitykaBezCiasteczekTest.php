<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Analityka odwiedzin bez ciasteczek — Plausible (D-092).
 *
 * CZEGO PILNUJE TEN PLIK I DLACZEGO AKURAT TEGO
 * Właściciel poprosił o Google Analytics. Dostał narzędzie odpowiadające na
 * to samo pytanie („skąd ludzie przychodzą, które strony oglądają") bez
 * jednej rzeczy, którą GA przynosi w pakiecie: ciasteczka. To nie jest
 * detal techniczny — na tym stoi zdanie z opublikowanej polityki
 * prywatności, że serwis NIE pyta o zgodę na cookies i NIE zasłania się
 * banerem. Jeśli analityka kiedykolwiek zacznie cokolwiek zapisywać
 * na urządzeniu człowieka, ten dokument staje się nieprawdziwy.
 *
 * Stąd cztery rzeczy sprawdzane tutaj i nigdzie indziej:
 *
 *  1. BEZ ZMIENNEJ ŚRODOWISKOWEJ NIE MA ANI ŚLADU ZNACZNIKA. Lokalnie,
 *     w testach i w CI analityka ma być niema — nie „wyłączona flagą",
 *     tylko nieobecna w HTML-u.
 *  2. ZE ZMIENNĄ ZNACZNIK JEST, Z WŁAŚCIWĄ DOMENĄ. Literówka w domenie nie
 *     wywołuje żadnego błędu: skrypt się ładuje, a panel zostaje pusty.
 *  3. CSP PRZEPUSZCZA HOST W OBU DYREKTYWACH. To jest najczęstsza cicha
 *     porażka takiego wpięcia: `script-src` pozwala pobrać plik, ale
 *     zdarzenia idą POST-em i podlegają `connect-src`. Brak drugiej
 *     dyrektywy = strona bez usterki i panel bez danych.
 *  4. ŻADNEGO CIASTECZKA WIĘCEJ. To jest sedno obietnicy i dlatego ma
 *     własny test, a nie przypis w innym.
 *
 * JAK TO JEST MIERZONE — I DLACZEGO NIE `assertSee()`
 * `assertDontSee('plausible')` na całym dokumencie jest w tym repozytorium
 * pułapką, która złapała już sześć osób (`docs/PULAPKI_TESTOW.md` §1): to
 * samo słowo pada w stopce, w polityce prywatności i w komentarzu Blade'a.
 * Każda asercja niżej celuje więc w KONKRETNY ELEMENT wyciągnięty przez
 * DOMXPath — `<script>` z atrybutem `data-domain` — a nie w tekst strony.
 *
 * Do tego każda asercja „czegoś nie ma" ma przy sobie KONTROLĘ DODATNIĄ
 * (`docs/PULAPKI_TESTOW.md` §4). Tutaj jest ona ZROBIONA PARĄ W JEDNYM
 * TEŚCIE: najpierw z ustawioną domeną pokazujemy, że wyszukiwanie znajduje
 * znacznik, i dopiero potem — po jej wyczyszczeniu — że go nie ma. Bez tej
 * pierwszej połowy „nie ma znacznika" byłoby prawdą także wtedy, gdyby
 * literówka w wyrażeniu XPath nie znajdowała NIGDY niczego.
 *
 * Naiwna kontrola „strona ma jakieś znaczniki `<script>`" NIE ZADZIAŁA
 * w tym repozytorium i nie warto do niej wracać: `Tests\TestCase` woła
 * `withoutVite()`, więc na stronie renderowanej w teście nie ma ani
 * jednego skryptu z pakietu — jedynym `<script>` na landingu bywa właśnie
 * ten, którego ten plik szuka.
 */
class AnalitykaBezCiasteczekTest extends TestCase
{
    use RefreshDatabase;

    private const DOMENA = 'kuking.pl';

    /** Analityka wyłączona — stan domyślny lokalnie, w testach i w CI. */
    private function bezAnalityki(): void
    {
        config(['kuking.analytics.plausible.domena' => '']);
        config(['kuking.analytics.plausible.host' => 'https://plausible.io']);
    }

    private function zAnalityka(string $host = 'https://plausible.io'): void
    {
        config(['kuking.analytics.plausible.domena' => self::DOMENA]);
        config(['kuking.analytics.plausible.host' => $host]);
    }

    /**
     * Wszystkie znaczniki `<script>` ze strony powitalnej gościa.
     *
     * Zwracamy ELEMENTY, nie HTML, żeby asercje niżej mogły pytać
     * o konkretne atrybuty zamiast szukać podłańcuchów w tekście strony.
     *
     * @return list<DOMElement>
     */
    private function skryptyLandingu(): array
    {
        $html = (string) $this->get(route('landing'))->assertOk()->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $znalezione = [];

        foreach ((new DOMXPath($dom))->query('//script') ?: [] as $wezel) {
            if ($wezel instanceof DOMElement) {
                $znalezione[] = $wezel;
            }
        }

        // KONTROLA, ŻE CZYTAM ŻYWĄ STRONĘ, A NIE PUSTEJ ODPOWIEDZI.
        // Liczba skryptów do tego się nie nadaje (`withoutVite()` w
        // `Tests\TestCase` zdejmuje pakiet), więc pytam o tytuł: jest
        // w każdym renderze layoutu i znika tylko wtedy, gdy parser dostał
        // pustą albo niewyrenderowaną treść.
        $this->assertNotSame(
            '',
            trim((string) ((new DOMXPath($dom))->query('//head/title')?->item(0)?->textContent ?? '')),
            'Czytana strona nie ma tytułu — to dowód, że pomiar nie działa, '
            .'a nie wynik pomiaru. Popraw sposób czytania strony, zanim uwierzysz w resztę testu.',
        );

        return $znalezione;
    }

    /**
     * Znacznik analityki wyciągnięty po atrybucie, który ma TYLKO on.
     *
     * @param  list<DOMElement>  $skrypty
     * @return list<DOMElement>
     */
    private function znacznikiAnalityki(array $skrypty): array
    {
        return array_values(array_filter(
            $skrypty,
            static fn (DOMElement $skrypt): bool => $skrypt->hasAttribute('data-domain'),
        ));
    }

    public function test_bez_zmiennej_srodowiskowej_nie_ma_ani_sladu_skryptu(): void
    {
        // KONTROLA DODATNIA, W PARZE, W TYM SAMYM TEŚCIE. Najpierw dowód,
        // że szukanie w ogóle coś znajduje — inaczej „nie ma znacznika"
        // niżej byłoby prawdą również przy literówce w wyszukiwaniu.
        $this->zAnalityka();
        $this->assertCount(
            1,
            $this->znacznikiAnalityki($this->skryptyLandingu()),
            'Przy ustawionej domenie nie widzę znacznika analityki — to psuje metodę pomiaru, '
            .'więc asercja „bez domeny znacznika nie ma" nic by nie dowodziła.',
        );

        $this->bezAnalityki();

        $skrypty = $this->skryptyLandingu();

        $this->assertSame(
            [],
            $this->znacznikiAnalityki($skrypty),
            'Bez PLAUSIBLE_DOMENA na stronie stoi znacznik z atrybutem `data-domain`. '
            .'Analityka ma być NIEOBECNA w HTML-u lokalnie, w testach i w CI, '
            .'a nie obecna i wyłączona.',
        );

        // Druga strona tego samego: żaden skrypt nie może wskazywać na host
        // analityki — także taki, któremu ktoś zapomniałby dać `data-domain`.
        foreach ($skrypty as $skrypt) {
            $this->assertStringNotContainsStringIgnoringCase(
                'plausible',
                $skrypt->getAttribute('src'),
                'Strona pobiera skrypt z hosta analityki mimo pustej PLAUSIBLE_DOMENA.',
            );
        }
    }

    public function test_ze_zmienna_skrypt_jest_z_wlasciwa_domena(): void
    {
        $this->zAnalityka();

        $znaczniki = $this->znacznikiAnalityki($this->skryptyLandingu());

        $this->assertCount(
            1,
            $znaczniki,
            'Znacznik analityki ma być dokładnie jeden — zero znaczy, że wpięcie nie działa, '
            .'a więcej niż jeden liczyłby każdą odsłonę podwójnie.',
        );

        $this->assertSame(self::DOMENA, $znaczniki[0]->getAttribute('data-domain'));
        $this->assertSame('https://plausible.io/js/script.js', $znaczniki[0]->getAttribute('src'));

        // `defer` — analityka doładowuje się PO treści. To jest rzecz
        // najmniej ważna na tej stronie i nie ma konkurować o łącze
        // z tym, po co człowiek przyszedł.
        $this->assertTrue($znaczniki[0]->hasAttribute('defer'));
    }

    /**
     * Własna instancja Plausible ma zmieniać JEDNO miejsce w konfiguracji —
     * i adres skryptu ma się z niej policzyć sam.
     */
    public function test_wlasny_host_zmienia_adres_skryptu(): void
    {
        $this->zAnalityka('https://statystyki.kuking.pl');

        $znaczniki = $this->znacznikiAnalityki($this->skryptyLandingu());

        $this->assertCount(1, $znaczniki);
        $this->assertSame(
            'https://statystyki.kuking.pl/js/script.js',
            $znaczniki[0]->getAttribute('src'),
        );
    }

    private function csp(): string
    {
        return (string) $this->get(route('landing'))
            ->assertOk()
            ->headers->get('Content-Security-Policy');
    }

    /**
     * NAJCZĘSTSZA CICHA PORAŻKA TAKIEGO WPIĘCIA.
     *
     * `script-src` pozwala POBRAĆ plik i na tym koniec. Zdarzenia skrypt
     * wysyła POST-em na `<host>/api/event`, a to podlega `connect-src` —
     * która w polityce tego serwisu jest wypisana osobno, więc nie
     * dziedziczy niczego z `default-src 'self'`. Bez drugiej dyrektywy
     * strona wygląda bez zarzutu, w dzienniku nie ma nic, a panel Plausible
     * jest pusty i nie ma jak zgadnąć dlaczego.
     */
    public function test_csp_dopuszcza_host_analityki_w_obu_dyrektywach(): void
    {
        $this->zAnalityka();

        $csp = $this->csp();

        $skrypty = $this->dyrektywa($csp, 'script-src');
        $polaczenia = $this->dyrektywa($csp, 'connect-src');

        $this->assertContains('https://plausible.io', $skrypty, 'Host analityki nie jest dopuszczony w `script-src` — przeglądarka nie pobierze skryptu.');
        $this->assertContains('https://plausible.io', $polaczenia, 'Host analityki nie jest dopuszczony w `connect-src` — skrypt się pobierze, ale KAŻDE zdarzenie zginie na barierze CSP.');
    }

    /**
     * Bez analityki host nie ma czego obsłużyć — a każdy obcy host
     * w `script-src` to poszerzenie powierzchni ataku dla XSS-a (issue #12).
     * Polityka opisuje to, co strona NAPRAWDĘ ładuje.
     */
    public function test_bez_analityki_csp_nie_dopuszcza_obcego_hosta(): void
    {
        $this->bezAnalityki();

        $csp = $this->csp();

        // KONTROLA DODATNIA: nagłówek naprawdę jest i naprawdę ma te
        // dyrektywy — inaczej „nie ma tam hosta" byłoby prawdą o pustym
        // łańcuchu.
        $this->assertNotSame('', $csp, 'Nie ma wymuszanego nagłówka CSP.');
        $this->assertContains("'self'", $this->dyrektywa($csp, 'script-src'));
        $this->assertContains("'self'", $this->dyrektywa($csp, 'connect-src'));

        $this->assertNotContains('https://plausible.io', $this->dyrektywa($csp, 'script-src'));
        $this->assertNotContains('https://plausible.io', $this->dyrektywa($csp, 'connect-src'));
    }

    /**
     * Źródła jednej dyrektywy CSP.
     *
     * Rozbijamy nagłówek na dyrektywy, zamiast szukać podłańcucha w całym
     * nagłówku — inaczej „host jest w `connect-src`" byłoby prawdą również
     * wtedy, gdy stoi wyłącznie w `script-src`, bo obie nazwy siedzą w tym
     * samym łańcuchu znaków.
     *
     * @return list<string>
     */
    private function dyrektywa(string $csp, string $nazwa): array
    {
        foreach (explode(';', $csp) as $fragment) {
            $czesci = preg_split('/\s+/', trim($fragment)) ?: [];

            if (($czesci[0] ?? '') === $nazwa) {
                return array_values(array_slice($czesci, 1));
            }
        }

        $this->fail("W nagłówku CSP nie ma dyrektywy `{$nazwa}`.");
    }

    /**
     * Treść znacznika `<main>` — czyli to, co człowiek naprawdę czyta.
     *
     * Bierzemy TEKST, nie HTML: znacznik CSRF i podpis `nonce` zmieniają się
     * przy każdym żądaniu, więc porównanie surowego HTML-u oblewałoby zawsze
     * i z niewłaściwego powodu.
     */
    private function trescGlowna(): string
    {
        $html = (string) $this->get(route('landing'))->assertOk()->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $glowna = (new DOMXPath($dom))->query('//main')?->item(0);

        $this->assertInstanceOf(
            DOMElement::class,
            $glowna,
            'Na stronie powitalnej nie ma znacznika <main> — czytam nie tę stronę albo nie tę treść.',
        );

        return trim((string) preg_replace('/\s+/u', ' ', $glowna->textContent));
    }

    /**
     * ANALITYKA NIE JEST FUNKCJĄ WAŻNĄ — ALE NIE MOŻE NICZEGO ZEPSUĆ.
     *
     * `AGENTS.md` mówi, że ważne funkcje działają bez JavaScriptu. Analityka
     * ważna nie jest i osoba z wyłączonym skryptem po prostu się nie policzy;
     * to jest w porządku. Nie jest w porządku, gdyby jej włączenie cokolwiek
     * zmieniło w tym, co ta osoba widzi i może zrobić. Dlatego porównujemy
     * treść strony przy analityce włączonej i wyłączonej — ma być ta sama.
     *
     * To jest jednocześnie dowód, że znacznik siedzi w `<head>`, a nie
     * w środku treści.
     */
    public function test_wlaczenie_analityki_nie_zmienia_tresci_strony(): void
    {
        $this->bezAnalityki();
        $bez = $this->trescGlowna();

        // KONTROLA DODATNIA: naprawdę czytamy treść, a nie pusty element.
        $this->assertNotSame('', $bez, 'Znacznik <main> jest pusty — porównanie niżej nie miałoby czego porównywać.');

        $this->zAnalityka();

        $this->assertSame(
            $bez,
            $this->trescGlowna(),
            'Włączenie analityki zmieniło treść strony. Analityka ma być niewidoczna dla człowieka — '
            .'i dla kogoś z wyłączonym JavaScriptem tak samo.',
        );
    }

    /** @return list<string> */
    private function nazwyCiasteczek(): array
    {
        $nazwy = [];

        foreach ($this->get(route('landing'))->assertOk()->headers->getCookies() as $ciasteczko) {
            $nazwy[] = $ciasteczko->getName();
        }

        sort($nazwy);

        return $nazwy;
    }

    /**
     * SEDNO CAŁEJ OBIETNICY.
     *
     * Polityka prywatności mówi wprost, że nie pytamy o zgodę na cookies,
     * bo analityka niczego na urządzeniu nie zapisuje. Ten test porównuje
     * ZBIÓR NAZW ciasteczek przy analityce włączonej i wyłączonej: mają być
     * identyczne. Sprawdzanie „czy nie ma ciasteczka o nazwie zawierającej
     * `plausible`" byłoby słabsze — przeszłoby dla ciasteczka nazwanego
     * inaczej.
     */
    public function test_wlaczenie_analityki_nie_doklada_zadnego_ciasteczka(): void
    {
        $this->bezAnalityki();
        $bez = $this->nazwyCiasteczek();

        // KONTROLA DODATNIA: serwis w ogóle stawia jakieś ciasteczka (sesja,
        // CSRF), więc porównanie niżej ma na czym pracować. Bez tego dwa
        // puste zbiory byłyby „równe" także wtedy, gdyby nagłówki ciasteczek
        // w ogóle nie docierały do testu.
        $this->assertNotEmpty(
            $bez,
            'Odpowiedź nie ustawia ŻADNEGO ciasteczka — nawet sesji. To dowód, że ten test '
            .'nie patrzy tam, gdzie trzeba, a nie dowód, że analityka jest czysta.',
        );

        $this->zAnalityka();
        $z = $this->nazwyCiasteczek();

        $this->assertSame(
            $bez,
            $z,
            'Włączenie analityki dołożyło ciasteczko. To łamie zdanie z polityki prywatności '
            .'(sekcja 5) o tym, że nie pytamy o zgodę na cookies, bo nie ma na co jej udzielać.',
        );
    }
}
