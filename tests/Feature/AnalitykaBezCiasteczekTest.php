<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\AnalitykaCloudflare;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Analityka odwiedzin bez ciasteczek — Cloudflare Web Analytics (D-092).
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
 * Stąd rzeczy sprawdzane tutaj i nigdzie indziej:
 *
 *  1. BEZ ZMIENNEJ ŚRODOWISKOWEJ NIE MA ANI ŚLADU ZNACZNIKA. Lokalnie,
 *     w testach i w CI analityka ma być niema — nie „wyłączona flagą",
 *     tylko nieobecna w HTML-u.
 *  2. Z TOKENEM ZNACZNIK JEST, Z WŁAŚCIWYM TOKENEM I ADRESEM. Literówka
 *     w tokenie nie wywołuje żadnego błędu: skrypt się ładuje, a panel
 *     zostaje pusty.
 *  3. CSP PRZEPUSZCZA DWA RÓŻNE HOSTY W DWÓCH RÓŻNYCH DYREKTYWACH. To jest
 *     najczęstsza cicha porażka takiego wpięcia — i przy tym dostawcy
 *     grubsza niż zwykle, bo plik pobiera się z
 *     `static.cloudflareinsights.com`, a zdarzenia lecą na
 *     `cloudflareinsights.com`, czyli na host BEZ `static.`. Zmierzone
 *     w `beacon.min.js`, nie przepisane z dokumentacji.
 *  4. ŻADNEGO CIASTECZKA WIĘCEJ. To jest sedno obietnicy i dlatego ma
 *     własny test, a nie przypis w innym.
 *
 * DWA HOSTY = DWA OSOBNE TESTY, KAŻDY ZE SWOIM SABOTAŻEM.
 * Jeden test na obie dyrektywy przechodziłby za drugą (`docs/PULAPKI_TESTOW.md`
 * §3b: każda gałąź potrzebuje własnego testu i własnej kontroli ujemnej).
 * Dlatego `script-src` i `connect-src` mają tu po jednym teście z osobna,
 * a trzeci pilnuje tego, czego żaden z nich nie widzi: że te dwa hosty są
 * RÓŻNE. Wpisanie tego samego hosta w oba miejsca zdałoby oba pierwsze
 * testy i dałoby dokładnie tę awarię, przed którą one mają chronić.
 *
 * JAK TO JEST MIERZONE — I DLACZEGO NIE `assertSee()`
 * `assertDontSee('cloudflare')` na całym dokumencie jest w tym repozytorium
 * pułapką, która złapała już sześć osób (`docs/PULAPKI_TESTOW.md` §1): to
 * samo słowo pada w stopce, w polityce prywatności, w skrypcie Turnstile
 * i w komentarzu Blade'a. Każda asercja niżej celuje więc w KONKRETNY
 * ELEMENT wyciągnięty przez DOMXPath — `<script>` z atrybutem
 * `data-cf-beacon` — a nie w tekst strony.
 *
 * Do tego każda asercja „czegoś nie ma" ma przy sobie KONTROLĘ DODATNIĄ
 * (`docs/PULAPKI_TESTOW.md` §4). Tutaj jest ona ZROBIONA PARĄ W JEDNYM
 * TEŚCIE: najpierw z ustawionym tokenem pokazujemy, że wyszukiwanie znajduje
 * znacznik, i dopiero potem — po jego wyczyszczeniu — że go nie ma. Bez tej
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

    private const TOKEN = '0123456789abcdef0123456789abcdef';

    private const HOST_SKRYPTU = 'https://static.cloudflareinsights.com';

    private const HOST_ZDARZEN = 'https://cloudflareinsights.com';

    /** Analityka wyłączona — stan domyślny lokalnie, w testach i w CI. */
    private function bezAnalityki(): void
    {
        config(['kuking.analytics.cloudflare.token' => '']);
    }

    private function zAnalityka(): void
    {
        config(['kuking.analytics.cloudflare.token' => self::TOKEN]);
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
            static fn (DOMElement $skrypt): bool => $skrypt->hasAttribute('data-cf-beacon'),
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
            'Przy ustawionym tokenie nie widzę znacznika analityki — to psuje metodę pomiaru, '
            .'więc asercja „bez tokenu znacznika nie ma" nic by nie dowodziła.',
        );

        $this->bezAnalityki();

        $skrypty = $this->skryptyLandingu();

        $this->assertSame(
            [],
            $this->znacznikiAnalityki($skrypty),
            'Bez CLOUDFLARE_ANALYTICS_TOKEN na stronie stoi znacznik z atrybutem `data-cf-beacon`. '
            .'Analityka ma być NIEOBECNA w HTML-u lokalnie, w testach i w CI, '
            .'a nie obecna i wyłączona.',
        );

        // Druga strona tego samego: żaden skrypt nie może wskazywać na host
        // analityki — także taki, któremu ktoś zapomniałby dać
        // `data-cf-beacon`.
        foreach ($skrypty as $skrypt) {
            $this->assertStringNotContainsStringIgnoringCase(
                'cloudflareinsights',
                $skrypt->getAttribute('src'),
                'Strona pobiera skrypt z hosta analityki mimo pustego CLOUDFLARE_ANALYTICS_TOKEN.',
            );
        }
    }

    public function test_z_tokenem_skrypt_jest_z_wlasciwym_tokenem_i_adresem(): void
    {
        $this->zAnalityka();

        $znaczniki = $this->znacznikiAnalityki($this->skryptyLandingu());

        $this->assertCount(
            1,
            $znaczniki,
            'Znacznik analityki ma być dokładnie jeden — zero znaczy, że wpięcie nie działa, '
            .'a więcej niż jeden liczyłby każdą odsłonę podwójnie.',
        );

        $this->assertSame(
            self::HOST_SKRYPTU.'/beacon.min.js',
            $znaczniki[0]->getAttribute('src'),
        );

        // `data-cf-beacon` to JSON, nie goły token: beacon czyta z niego pole
        // `token`. Porównujemy po ROZKODOWANIU, a nie łańcuchem znaków —
        // inaczej test oblewałby przy nieistotnej zmianie odstępów, a nie
        // przy zmianie znaczenia.
        $konfiguracja = json_decode($znaczniki[0]->getAttribute('data-cf-beacon'), true);

        $this->assertIsArray(
            $konfiguracja,
            'Atrybut `data-cf-beacon` nie jest poprawnym JSON-em — beacon nie odczyta z niego tokenu '
            .'i po cichu nie policzy niczego.',
        );
        $this->assertSame(self::TOKEN, $konfiguracja['token'] ?? null);

        // Świadomie BEZ pola `version`: zmierzone w `beacon.min.js`, jego
        // obecność przełącza adres zdarzeń na ścieżkę względną na naszej
        // domenie (tak działa automatyczne wstrzyknięcie przez proxy) —
        // a wtedy host dopuszczony w `connect-src` opisywałby nieprawdę.
        $this->assertArrayNotHasKey('version', $konfiguracja);

        // `defer` — analityka doładowuje się PO treści. To jest rzecz
        // najmniej ważna na tej stronie i nie ma konkurować o łącze
        // z tym, po co człowiek przyszedł.
        $this->assertTrue($znaczniki[0]->hasAttribute('defer'));
    }

    /**
     * Widok liczy adres z konfiguracji, a nie powtarza literału.
     *
     * Gdyby adres stał w Blade'ie wpisany na sztywno, zmiana w
     * `config/kuking.php` przestałaby cokolwiek znaczyć — a reguła CSP liczy
     * się właśnie z konfiguracji. Rozjazd tych dwóch miejsc to skrypt po
     * cichu zablokowany przez politykę bezpieczeństwa.
     */
    public function test_adres_skryptu_pochodzi_z_konfiguracji(): void
    {
        $this->zAnalityka();
        config(['kuking.analytics.cloudflare.host_skryptu' => 'https://przyklad.test']);

        $znaczniki = $this->znacznikiAnalityki($this->skryptyLandingu());

        $this->assertCount(1, $znaczniki);
        $this->assertSame(
            'https://przyklad.test/beacon.min.js',
            $znaczniki[0]->getAttribute('src'),
            'Widok nie liczy adresu skryptu z konfiguracji — ma go wpisany literałem, '
            .'więc CSP i HTML mogą się rozjechać.',
        );
    }

    private function csp(): string
    {
        return (string) $this->get(route('landing'))
            ->assertOk()
            ->headers->get('Content-Security-Policy');
    }

    /**
     * PIERWSZA POŁOWA WPIĘCIA: przeglądarka musi móc POBRAĆ plik.
     *
     * Osobny test od `connect-src` niżej — i to jest celowe. Jeden test na
     * obie dyrektywy zdawałby za drugą i sabotaż jednej linijki nie oblewałby
     * niczego (`docs/PULAPKI_TESTOW.md` §3b).
     */
    public function test_csp_dopuszcza_host_skryptu_w_script_src(): void
    {
        $this->zAnalityka();

        $this->assertContains(
            self::HOST_SKRYPTU,
            $this->dyrektywa($this->csp(), 'script-src'),
            'Host, z którego pobiera się beacon, nie jest dopuszczony w `script-src` — '
            .'przeglądarka nie pobierze pliku i analityki nie ma.',
        );
    }

    /**
     * DRUGA POŁOWA WPIĘCIA I NAJCZĘSTSZA CICHA PORAŻKA.
     *
     * `script-src` pozwala POBRAĆ plik i na tym koniec. Zdarzenia beacon
     * wysyła przez `navigator.sendBeacon` na `cloudflareinsights.com/cdn-cgi/rum`
     * (zmierzone w `beacon.min.js`), a to podlega `connect-src` — która
     * w polityce tego serwisu jest wypisana osobno, więc nie dziedziczy
     * niczego z `default-src 'self'`. Bez tej dyrektywy strona wygląda bez
     * zarzutu, w dzienniku nie ma nic, a panel Cloudflare jest pusty i nie
     * ma jak zgadnąć dlaczego.
     */
    public function test_csp_dopuszcza_host_zdarzen_w_connect_src(): void
    {
        $this->zAnalityka();

        $this->assertContains(
            self::HOST_ZDARZEN,
            $this->dyrektywa($this->csp(), 'connect-src'),
            'Host, na który beacon WYSYŁA zdarzenia, nie jest dopuszczony w `connect-src` — '
            .'skrypt się pobierze, ale KAŻDE zdarzenie zginie na barierze CSP.',
        );
    }

    /**
     * TEGO NIE WIDZI ŻADEN Z DWÓCH TESTÓW WYŻEJ: że to są DWA RÓŻNE HOSTY.
     *
     * Przy Plausible, które stało tu przed tą decyzją, plik i zdarzenia
     * szły na ten sam host, więc jedna wartość obsługiwała obie dyrektywy.
     * U Cloudflare nie: plik z `static.cloudflareinsights.com`, zdarzenia
     * na `cloudflareinsights.com`. Wpisanie jednego hosta w oba miejsca
     * zdałoby oba testy wyżej i dałoby dokładnie tę awarię, przed którą
     * one mają chronić — dlatego różnica ma własną asercję.
     */
    public function test_host_skryptu_i_host_zdarzen_to_dwa_rozne_hosty(): void
    {
        $this->assertNotSame(
            AnalitykaCloudflare::hostSkryptu(),
            AnalitykaCloudflare::hostZdarzen(),
            'Host skryptu i host zdarzeń są tą samą wartością. U Cloudflare są różne '
            .'(zmierzone w `beacon.min.js`), więc jedna z dwóch dyrektyw CSP jest teraz błędna.',
        );

        $this->zAnalityka();

        $csp = $this->csp();

        // I ta różnica ma dojechać aż do nagłówka, w obu kierunkach: host
        // zdarzeń nie ma czego robić w `script-src`, a host skryptu
        // w `connect-src`. Bez tego „dopuściliśmy oba wszędzie" przechodziłoby
        // jako poprawne, poszerzając powierzchnię ataku bez powodu (issue #12).
        $this->assertNotContains(self::HOST_ZDARZEN, $this->dyrektywa($csp, 'script-src'));
        $this->assertNotContains(self::HOST_SKRYPTU, $this->dyrektywa($csp, 'connect-src'));
    }

    /**
     * Bez analityki hosty nie mają czego obsłużyć — a każdy obcy host
     * w `script-src` to poszerzenie powierzchni ataku dla XSS-a (issue #12).
     * Polityka opisuje to, co strona NAPRAWDĘ ładuje.
     */
    public function test_bez_analityki_csp_nie_dopuszcza_zadnego_z_obu_hostow(): void
    {
        $this->bezAnalityki();

        $csp = $this->csp();

        // KONTROLA DODATNIA: nagłówek naprawdę jest i naprawdę ma te
        // dyrektywy — inaczej „nie ma tam hosta" byłoby prawdą o pustym
        // łańcuchu.
        $this->assertNotSame('', $csp, 'Nie ma wymuszanego nagłówka CSP.');
        $this->assertContains("'self'", $this->dyrektywa($csp, 'script-src'));
        $this->assertContains("'self'", $this->dyrektywa($csp, 'connect-src'));

        $this->assertNotContains(self::HOST_SKRYPTU, $this->dyrektywa($csp, 'script-src'));
        $this->assertNotContains(self::HOST_ZDARZEN, $this->dyrektywa($csp, 'connect-src'));
    }

    /**
     * Źródła jednej dyrektywy CSP.
     *
     * Rozbijamy nagłówek na dyrektywy, zamiast szukać podłańcucha w całym
     * nagłówku — inaczej „host jest w `connect-src`" byłoby prawdą również
     * wtedy, gdy stoi wyłącznie w `script-src`, bo obie nazwy siedzą w tym
     * samym łańcuchu znaków. Przy tym dostawcy jest to podwójnie ważne:
     * `https://cloudflareinsights.com` jest PODŁAŃCUCHEM
     * `https://static.cloudflareinsights.com`, więc `str_contains` na
     * nagłówku dawałby wynik dodatni dla hosta, którego tam nie ma.
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
     * `AGENTS.md` mówi, że nigdzie nie wolno zostawić martwego przycisku.
     * Analityka ważna nie jest i osoba z wyłączonym skryptem po prostu się
     * nie policzy; to jest w porządku. Nie jest w porządku, gdyby jej
     * włączenie cokolwiek zmieniło w tym, co ta osoba widzi i może zrobić.
     * Dlatego porównujemy treść strony przy analityce włączonej i wyłączonej —
     * ma być ta sama.
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
     * `cloudflare`" byłoby słabsze — przeszłoby dla ciasteczka nazwanego
     * inaczej.
     *
     * CZEGO TEN TEST NIE DOWODZI, i trzeba to napisać wprost: mierzy
     * ciasteczka stawiane przez NASZĄ odpowiedź. O tym, czy sam beacon
     * niczego nie zapisuje w przeglądarce, ten test nie mówi nic — tego nie
     * da się sprawdzić w PHPUnicie i zostało zmierzone inaczej: przez
     * pobranie `beacon.min.js` i odczytanie, że nie ma w nim ani jednego
     * odwołania do `document.cookie`, `localStorage`, `sessionStorage` ani
     * `indexedDB` (D-092, `docs/legal/COMPLIANCE.md` §5.5).
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
