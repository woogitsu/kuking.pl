<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\HealthController;
use App\Support\AnalitykaCloudflare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Analityka odwiedzin DA SIĘ WDROŻYĆ — i widać, kiedy nie jest wdrożona.
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Do 12 września 2026 polityka prywatności mówiła czytelnikowi w czasie
 * teraźniejszym, że statystykę odwiedzin prowadzi **Cloudflare Web Analytics**
 * — nazwa usługi pada w tym dokumencie trzy razy, a w akapicie
 * o przekazywaniu danych poza EOG stoi przy niej data („od 10 września
 * 2026"). Analityka nie mierzyła wtedy NICZEGO, z dwóch niezależnych
 * powodów, z których każdy sam wystarczał:
 *
 *  1. `CLOUDFLARE_ANALYTICS_TOKEN` nie było — a bez tokenu beacona nie ma
 *     w HTML-u wcale (`AnalitykaCloudflare::wlaczona()`), więc nie ma czego
 *     liczyć. Co gorsza, zmiennej nie przepuszczał `.railway/railway.ts`:
 *     wartość wpisana w Shared Variables Railwaya stałaby tam i NIE
 *     DOCHODZIŁA do aplikacji, dokładnie jak wcześniej `FACEBOOK_*`
 *     (issue #259, D-167);
 *  2. w panelu Cloudflare wybrany był wariant zbierania danych wykluczający
 *     odwiedzających z Unii Europejskiej — czyli, przy naszym ruchu,
 *     praktycznie wszystkich.
 *
 * TO JEST TA SAMA KLASA BŁĘDU CO D-167, TYLKO CIĘŻSZA. Tam obietnica stała
 * w konfiguracji i jej niespełnienie było widać okiem: nie ma przycisku
 * „Wejdź kontem Facebooka" na `/login`. Tu obietnica stoi w DOKUMENCIE
 * PRAWNYM, a jej niespełnienia nie widać NIGDZIE: strona wygląda normalnie,
 * w dzienniku serwera nie ma nic, przeglądarka nie zgłasza usterki (skryptu
 * po prostu nie ma), a właściciel, który raz założył serwis w panelu
 * Cloudflare, ma wszelkie powody sądzić, że analityka działa.
 *
 * DECYZJA WŁAŚCICIELA Z 12 WRZEŚNIA 2026: analityka ma zacząć działać
 * naprawdę, a nie zniknąć z dokumentu. Ten plik pilnuje czterech twierdzeń,
 * które tę decyzję czynią wykonalną i sprawdzalną:
 *
 *  1. dokument prawny naprawdę obiecuje analitykę (inaczej cała reszta
 *     pilnowałaby nieistniejącego problemu);
 *  2. runbook mówi, co ustawić — w tym **wariant zbierania danych** — i jak
 *     sprawdzić, że dane realnie dochodzą;
 *  3. `railway.ts` przepuszcza zmienną do serwisu;
 *  4. `/health` mówi prawdę o jej braku.
 *
 * DLACZEGO TO JEST TEST, A NIE WPIS W CHECKLIŚCIE
 * Bo obie luki powstały PRZEZ POMINIĘCIE, nie przez błędną decyzję — nikt
 * nie postanowił, że analityki w runbooku nie będzie. Jedyną obroną przed
 * pominięciem jest coś, co oblewa. Wzorzec strażnika dokumentu bierzemy
 * z `WdrozenieWejsciaFacebookiemTest` i `TabelaStackuMowiPrawdeTest`
 * (D-104): skan pliku plus PRÓG, żeby test, który przestał cokolwiek
 * znajdować, oblewał zamiast świecić na zielono (`docs/PULAPKI_TESTOW.md`,
 * pułapka 2).
 *
 * CZEGO TEN PLIK ŚWIADOMIE NIE PILNUJE — TO GRANICA, NIE PRZEOCZENIE
 *  - **Tego, czy beacon liczy.** Z repozytorium nie da się sprawdzić, czy
 *    token jest prawdziwy ani jaki wariant zbierania danych wybrano w cudzym
 *    panelu — Cloudflare przyjmuje zdarzenie z nieznanym tokenem i po cichu
 *    je odrzuca. Da się sprawdzić, czy runbook o to prosi i czy mówi, jak to
 *    zweryfikować liczbami z panelu.
 *  - **Treści polityki prywatności zdanie po zdaniu.** Od tej strony pilnują
 *    jej `DokumentyPrawneNieKlamiaTest` i
 *    `PolitykaPrywatnosciWymieniaKazdaUslugeTest`. Tutaj pytamy o jedno:
 *    czy obietnica nadal stoi, bo od tego zależy, czy `/health` ma prawo
 *    krzyczeć.
 *  - **Znacznika w layoucie i nagłówków CSP.** Pilnuje ich
 *    `AnalitykaBezCiasteczekTest` (D-092).
 */
class WdrozenieAnalitykiOdwiedzinTest extends TestCase
{
    use RefreshDatabase;

    private const RUNBOOK = 'docs/infra/DEPLOYMENT_RUNBOOK.md';

    private const RAILWAY = '.railway/railway.ts';

    /** Nagłówek kroku analityki — kotwica całego skanu runbooka. */
    private const NAGLOWEK_8F = '## KROK 8F.';

    /**
     * Jedyna zmienna, bez której tej analityki nie ma. Nazwa jest czytana
     * z `config/kuking.php` w `test_nazwa_zmiennej_jest_ta_sama...`, więc
     * literówka tutaj nie przejdzie po cichu.
     */
    private const ZMIENNA = 'CLOUDFLARE_ANALYTICS_TOKEN';

    /** Kod, którym `/health` melduje rozjazd między dokumentem a rzeczywistością. */
    private const POWOD = 'analityka_bez_tokenu';

    /**
     * PRÓG — bez niego ten test jest zielony na zawsze.
     *
     * Skan, który nie znajdzie kroku 8F, zwróciłby krótki łańcuch, a asercje
     * na nim oblałyby dopiero wtedy, gdy ktoś przesunie numerację kroków
     * i test zacznie sprawdzać NIE TEN krok. Próg pilnuje, że kotwica trafia
     * w prawdziwą, niepustą sekcję. Zmierzone 12.09.2026: krok 8F ma
     * 167 wierszy, sąsiedni 8E ma ~190.
     */
    private const MIN_WIERSZY_KROKU = 60;

    // ------------------------------------------------------------------
    //  Twierdzenie 0: obietnica naprawdę stoi w dokumencie prawnym
    // ------------------------------------------------------------------

    /**
     * Polityka prywatności obiecuje tę analitykę — i to jest przesłanka
     * wszystkiego, co niżej.
     *
     * Gdyby obietnicy nie było, sygnał `/health` pilnowałby problemu, którego
     * nie ma, a runbook opisywałby funkcję, której nikomu nie obiecaliśmy.
     * Ten test stoi pierwszy, żeby oblewał jako pierwszy: jeśli ktoś kiedyś
     * wykreśli analitykę z polityki (to jest DOZWOLONA droga wycofania — patrz
     * 8F.5), ma zobaczyć tutaj, że razem z nią wypada cała reszta tego pliku.
     */
    public function test_polityka_prywatnosci_naprawde_obiecuje_te_analityke(): void
    {
        $dokument = $this->tresc('resources/'.AnalitykaCloudflare::dokumentObietnicy());

        $this->assertStringContainsString(
            AnalitykaCloudflare::frazaObietnicy(),
            $dokument,
            'Dokument prawny wskazany w `kuking.analytics.cloudflare.obietnica` nie zawiera '
            .'frazy „'.AnalitykaCloudflare::frazaObietnicy().'". Albo obietnicę wykreślono '
            .'(wtedy trzeba też wyczyścić CLOUDFLARE_ANALYTICS_TOKEN, usunąć krok 8F '
            .'i ten plik — patrz KROK 8F.5), albo ta konfiguracja wskazuje nie ten '
            .'dokument, a wtedy /health pyta o obietnicę, której nikt nie złożył.',
        );

        $this->assertTrue(
            AnalitykaCloudflare::obiecanaWDokumencie(),
            '`AnalitykaCloudflare::obiecanaWDokumencie()` mówi „nie", choć fraza stoi '
            .'w dokumencie. To jest usterka odczytu (ścieżka, prawa do pliku), a nie '
            .'zmiana treści — i uciszyłaby sygnał /health po cichu, bo brak obietnicy '
            .'znaczy tam „nie ma o czym krzyczeć".',
        );
    }

    // ------------------------------------------------------------------
    //  Twierdzenie 1: runbook opisuje krok analityki
    // ------------------------------------------------------------------

    /**
     * Runbook ma KROK 8F i mówi w nim trzy rzeczy: co kliknąć w panelu
     * Cloudflare, co wpisać w Railway i jak sprawdzić, że działa.
     *
     * Najważniejsza jest tu **druga czynność w panelu** — wariant zbierania
     * danych. Token bez niej daje stan najgorszy z możliwych: wszystko po
     * naszej stronie jest w porządku, `/health` milczy, polityka mówi prawdę,
     * a panel Cloudflare dalej świeci zerami.
     */
    public function test_runbook_ma_krok_analityki(): void
    {
        $krok = $this->sekcja(self::RUNBOOK, self::NAGLOWEK_8F);

        $this->assertGreaterThanOrEqual(
            self::MIN_WIERSZY_KROKU,
            substr_count($krok, "\n"),
            'Krok 8F w '.self::RUNBOOK.' ma tylko '.substr_count($krok, "\n").' wierszy, '
            .'a spodziewamy się co najmniej '.self::MIN_WIERSZY_KROKU.'. Albo kotwica '
            .'„'.self::NAGLOWEK_8F.'" trafia w niewłaściwe miejsce (usterka TEGO TESTU), '
            .'albo krok skurczył się do wzmianki — a wtedy nie jest instrukcją wdrożenia.',
        );

        foreach ([
            'Web Analytics' => 'nazwa usługi w panelu Cloudflare',
            'Add a site' => 'przycisk, którym zakłada się serwis',
            'data-cf-beacon' => 'atrybut, z którego bierze się wartość tokenu',
            'Railway' => 'miejsce, w którym wpisuje się zmienną',
            'restart' => 'konfiguracja jest na produkcji buforowana i czyta się przy starcie',
            'polityka prywatności' => 'dlaczego ten krok w ogóle jest obowiązkowy',
        ] as $szukane => $poco) {
            $this->assertStringContainsString(
                $szukane,
                $krok,
                'Krok 8F w '.self::RUNBOOK.' nie mówi o „'.$szukane.'" ('.$poco.'). '
                .'Właściciel czyta ten krok ZAMIAST panelu Cloudflare — czego tu nie ma, '
                .'tego nie zrobi.',
            );
        }

        $this->assertStringContainsString(
            self::ZMIENNA,
            $krok,
            'Krok 8F w '.self::RUNBOOK.' nie wymienia zmiennej „'.self::ZMIENNA.'". '
            .'Bez jej nazwy krok mówi „wpisz token do Railway" i nie mówi, POD JAKĄ '
            .'NAZWĄ — a `ctx.shared` w '.self::RAILWAY.' szuka dokładnie tej.',
        );
    }

    /**
     * Krok 8F ostrzega przed DRUGĄ, niezależną przyczyną pustego panelu:
     * wariantem zbierania danych wykluczającym Unię Europejską.
     *
     * Osobny test, nie kolejna asercja wyżej, bo to jest osobna usterka
     * o osobnej naprawie — i ta jedna jest niewykrywalna z naszej strony
     * ŻADNYM pomiarem. Runbook jest jedynym miejscem, w którym w ogóle może
     * paść. Drugie zdanie tego samego kalibru: automatyczne wstrzykiwanie
     * beacona (dwa beacony = każda odsłona liczona dwa razy).
     */
    public function test_krok_analityki_ostrzega_przed_wariantem_wykluczajacym_unie(): void
    {
        $krok = $this->sekcja(self::RUNBOOK, self::NAGLOWEK_8F);

        foreach ([
            'Unii Europejskiej' => 'wariant zbierania danych, przy którym panel zostaje pusty',
            'wstrzykiwanie' => 'automatyczne wstrzykiwanie beacona przez proxy — podwójne liczenie',
        ] as $szukane => $poco) {
            $this->assertStringContainsString(
                $szukane,
                $krok,
                'Krok 8F w '.self::RUNBOOK.' nie mówi o „'.$szukane.'" ('.$poco.'). '
                .'To jest czynność w CUDZYM panelu, której skutku nie da się u nas '
                .'zmierzyć: przy złym wariancie beacon działa, zdarzenia wychodzą, '
                .'/health milczy, a panel i tak pokazuje zero. Jeśli nie stoi to '
                .'w runbooku, nie stoi NIGDZIE.',
            );
        }
    }

    /**
     * Krok 8F ma własną sekcję „Sprawdzenie, że naprawdę działa" — i to
     * sprawdzenie sięga po LICZBY Z PANELU, nie tylko po nasz `/health`.
     *
     * Tu jest różnica wobec 8D i 8E, i ona jest sednem tego kroku: przy
     * Google i Facebooku `/health` na zielono plus przycisk na ekranie
     * naprawdę znaczą „działa". Tutaj nie znaczą — obie rzeczy mogą być
     * zielone przy analityce, która nie zapisuje ani jednej odsłony. Jedynym
     * dowodem jest odsłona zrobiona PRZEGLĄDARKĄ (curl nie wykonuje skryptów)
     * i policzona w panelu.
     */
    public function test_krok_analityki_mowi_jak_sprawdzic_ze_dane_realnie_dochodza(): void
    {
        $krok = $this->sekcja(self::RUNBOOK, self::NAGLOWEK_8F);

        $this->assertMatchesRegularExpression(
            '/###\s+8F\.\d+\s+Sprawdzenie, że naprawdę działa/u',
            $krok,
            'Krok 8F w '.self::RUNBOOK.' nie ma sekcji „Sprawdzenie, że naprawdę '
            .'działa". Każdy krok tego runbooka ją ma i to nie jest ozdoba: krok bez '
            .'niej kończy się słowem „gotowe" wypowiedzianym przez kogoś, kto niczego '
            .'nie sprawdził. Wzór stoi obok, w 8E.3.',
        );

        foreach ([
            '/health' => 'sprawdzenie automatem, które da się wpiąć w monitoring',
            self::POWOD => 'powód, którym /health melduje rozjazd',
            'beacon.min.js' => 'sprawdzenie, że znacznik jest w HTML-u — i tylko jeden',
            'przeglądarce' => 'beacon jest JavaScriptem, curl nie wyśle ani jednego zdarzenia',
            'Page views' => 'liczba z panelu Cloudflare — jedyny dowód, że dane dochodzą',
            'Top pages' => 'lista stron w panelu — dowód, że to NASZE odsłony, a nie cudze',
        ] as $szukane => $poco) {
            $this->assertStringContainsString(
                $szukane,
                $krok,
                'Sekcja sprawdzająca w kroku 8F nie mówi o „'.$szukane.'" ('.$poco.'). '
                .'Sprawdzenie po naszej stronie jest tu ZA SŁABE: /health i HTML mogą być '
                .'zielone przy analityce, która nie liczy ani jednej odsłony. „Powinno '
                .'działać" nie jest sprawdzeniem.',
            );
        }
    }

    /**
     * Dwie tabele, do których zagląda się BEZ czytania całego runbooka:
     * pełna lista zmiennych produkcyjnych (KROK 8) i zestawienie „potrzebne
     * od właściciela" (KROK 16).
     *
     * Krok 8F może być doskonały i nie pomoże, jeśli te dwie tabele o nim
     * milczą — pierwsza jest listą, po której zakłada się Shared Variables,
     * druga listą, po której właściciel odhacza, czego jeszcze brakuje.
     */
    public function test_tabele_zmiennych_i_zestawienie_wlasciciela_wymieniaja_analityke(): void
    {
        $tabelaZmiennych = $this->sekcja(self::RUNBOOK, '### Pełna lista — środowisko `production`');
        $zestawienie = $this->sekcja(self::RUNBOOK, '## KROK 16.');

        $this->assertStringContainsString(
            self::ZMIENNA,
            $tabelaZmiennych,
            'Tabela zmiennych produkcyjnych (KROK 8) nie wymienia „'.self::ZMIENNA.'". '
            .'Do tej tabeli zagląda się przy zakładaniu Shared Variables, bez czytania '
            .'całego runbooka — Turnstile, Google i Facebook stoją w niej od swoich kroków.',
        );

        $this->assertStringContainsString(
            self::ZMIENNA,
            $zestawienie,
            'Zestawienie „[POTRZEBNE OD WŁAŚCICIELA]" (KROK 16) nie wymienia '
            .'„'.self::ZMIENNA.'". To jest lista, po której właściciel odhacza, czego '
            .'jeszcze brakuje — czego na niej nie ma, o tym nie wie.',
        );
    }

    // ------------------------------------------------------------------
    //  Twierdzenie 2: railway.ts przepuszcza zmienną do serwisu
    // ------------------------------------------------------------------

    /**
     * `.railway/railway.ts` przekazuje `CLOUDFLARE_ANALYTICS_TOKEN` do
     * serwisu — dokładnie tak jak `TURNSTILE_*` i `FACEBOOK_*`.
     *
     * TO JEST NAJCICHSZA Z LUK TEGO ZLECENIA. Token wpisany w Shared
     * Variables Railwaya nie trafia do aplikacji sam z siebie: `railway.ts`
     * musi go wymienić przez `ctx.shared`. Bez tej linii właściciel zakłada
     * serwis w panelu Cloudflare, wpisuje token, robi `railway config apply`
     * — i dostaje stronę bez beacona, panel bez danych i zero śladu dlaczego.
     */
    public function test_railway_przepuszcza_token_analityki_do_serwisu(): void
    {
        $railway = $this->tresc(self::RAILWAY);

        $this->assertMatchesRegularExpression(
            '/^\s*'.preg_quote(self::ZMIENNA, '/').':\s*ctx\.shared\.'.preg_quote(self::ZMIENNA, '/').',/m',
            $railway,
            'W '.self::RAILWAY.' nie ma linii „'.self::ZMIENNA.': ctx.shared.'
            .self::ZMIENNA.',". Bez niej token wpisany w Shared Variables Railwaya NIE '
            .'DOCHODZI do serwisu — beacona nie ma w HTML-u, panel jest pusty i nikt nie '
            .'wie dlaczego. Ta sama luka kosztowała osobne issue przy FACEBOOK_* (#259).',
        );
    }

    /**
     * Symetria wobec sąsiadów jest tu twierdzeniem, nie estetyką: ta sama
     * rola, ten sam mechanizm i ten sam sposób pomyłki. Gdyby ktoś kiedyś
     * usunął `TURNSTILE_*` albo `FACEBOOK_*`, ten test powie, że wzorzec
     * zniknął — zamiast przepuścić „analityka jest, więc dobrze".
     */
    public function test_analityka_stoi_w_railway_na_rownych_prawach_z_sasiadami(): void
    {
        $railway = $this->tresc(self::RAILWAY);

        foreach (['TURNSTILE_SITE_KEY', 'FACEBOOK_CLIENT_ID'] as $zmienna) {
            $this->assertStringContainsString(
                $zmienna.': ctx.shared.'.$zmienna,
                $railway,
                'W '.self::RAILWAY.' zniknęła linia „'.$zmienna.'". To jest wzorzec, '
                .'wobec którego dopisano '.self::ZMIENNA.' — jeśli wzorzec zniknął, '
                .'przestała działać tamta funkcja i ten test mówi o tym pierwszy.',
            );
        }
    }

    /**
     * Nazwa zmiennej w runbooku i w `railway.ts` to DOKŁADNIE ta nazwa, którą
     * czyta `config/kuking.php`.
     *
     * Bez tego sprawdzenia wszystkie powyższe mogą być zielone przy
     * literówce, której nikt nie zobaczy: runbook każe wpisać
     * `CLOUDFLARE_WEB_ANALYTICS_TOKEN`, `railway.ts` przepuszcza tę samą
     * nazwę, a aplikacja czyta `CLOUDFLARE_ANALYTICS_TOKEN` i nie widzi nic.
     * Źródłem prawdy jest konfiguracja, bo to ona naprawdę decyduje.
     */
    public function test_nazwa_zmiennej_jest_ta_sama_co_czyta_konfiguracja(): void
    {
        $config = $this->tresc('config/kuking.php');

        $this->assertMatchesRegularExpression(
            "/env\\(\\s*'".preg_quote(self::ZMIENNA, '/')."'/",
            $config,
            'config/kuking.php nie czyta zmiennej „'.self::ZMIENNA.'" (klucz '
            .'kuking.analytics.cloudflare.token). Albo zmienną przemianowano i runbook '
            .'razem z '.self::RAILWAY.' mówią teraz o nieistniejącej nazwie, albo ten '
            .'test pilnuje nazwy, której już nie ma. Jedno i drugie wymaga poprawki '
            .'CZŁOWIEKA, nie wyciszenia testu.',
        );

        // Kropka nad i: ta sama nazwa naprawdę steruje tym, co robi aplikacja.
        config(['kuking.analytics.cloudflare.token' => '']);
        $this->assertFalse(AnalitykaCloudflare::wlaczona());

        config(['kuking.analytics.cloudflare.token' => 'udawany-token']);
        $this->assertTrue(AnalitykaCloudflare::wlaczona());
    }

    // ------------------------------------------------------------------
    //  Twierdzenie 3: /health mówi prawdę o braku tokenu
    // ------------------------------------------------------------------

    /**
     * Produkcja, na której dokument prawny obiecuje analitykę, a tokenu nie
     * ma, jest `degraded` — a nie „ok".
     *
     * TO JEST SEDNO CAŁEGO PLIKU. Dwie pozostałe luki (runbook, `railway.ts`)
     * da się odkryć, próbując wdrożyć. Tej nie odkrywa nikt: bez tokenu
     * beacona nie ma w HTML-u, więc wdrożenie, w którym obiecana analityka
     * NIE ISTNIEJE, wygląda identycznie jak wdrożenie, na którym wszystko
     * jest w porządku.
     *
     * Świadomie 200, nie 503: healthcheck oddający 503 już raz położył ten
     * serwis. Monitoring pilnuje TREŚCI odpowiedzi.
     */
    public function test_produkcja_z_obietnica_i_bez_tokenu_jest_degraded(): void
    {
        $this->produkcjaBezSzumu();
        $this->bezTokenu();

        $odpowiedz = $this->zdrowieZeSzczegolami();

        $odpowiedz->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.analityka.ok', false)
            ->assertJsonPath('checks.analityka.error', self::POWOD)
            // Baza i zdjęcia są całe — sygnał dotyczy WYŁĄCZNIE konfiguracji.
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.media.ok', true);

        $this->assertContains(
            $odpowiedz->json('checks.analityka.error'),
            HealthController::POWODY,
            'Powód „'.self::POWOD.'" nie stoi w zamkniętym zbiorze '
            .'HealthController::POWODY. Ta odpowiedź jest publiczna, a '
            .'HealthNieZdradzaSzczegolowTest pilnuje, że nie wychodzi z niej nic '
            .'spoza tego zbioru.',
        );
    }

    /**
     * Z tokenem na miejscu `/health` milczy — inaczej ten sygnał byłby stałym
     * `degraded`, czyli szumem, który uczy ignorować całe pole (ta sama lekcja
     * co przy Turnstile).
     */
    public function test_produkcja_z_tokenem_jest_zdrowa(): void
    {
        $this->produkcjaBezSzumu();

        config(['kuking.analytics.cloudflare.token' => 'udawany-token']);

        $this->zdrowieZeSzczegolami()
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.analityka.ok', true);
    }

    /**
     * Dokument, który NICZEGO nie obiecuje, nie jest awarią — nawet bez tokenu.
     *
     * To jest ta gałąź, która odróżnia ten sygnał od zwykłego „brakuje
     * zmiennej". Serwis bez analityki jest poprawnym serwisem; nieprawdą jest
     * dopiero polityka prywatności opisująca przetwarzanie, którego nie ma.
     * Bez tego rozróżnienia jedynym sposobem uciszenia sygnału byłoby wpisanie
     * byle czego w token — czyli nauczenie właściciela kłamania konfiguracji.
     *
     * Podstawiamy regulamin, bo on tej frazy nie zawiera (sprawdzone asercją
     * niżej, żeby test nie zzieleniał od tego, że ktoś dopisał analitykę także
     * tam).
     */
    public function test_dokument_bez_obietnicy_nie_jest_awaria(): void
    {
        $this->produkcjaBezSzumu();
        $this->bezTokenu();

        config(['kuking.analytics.cloudflare.obietnica.dokument' => 'legal/regulamin.md']);

        $this->assertFalse(
            AnalitykaCloudflare::obiecanaWDokumencie(),
            'Regulamin zawiera frazę „'.AnalitykaCloudflare::frazaObietnicy().'". '
            .'Ten test podstawia go jako dokument, który NICZEGO nie obiecuje — jeśli '
            .'obiecuje, sprawdza teraz coś innego, niż mówi jego nazwa.',
        );

        $this->zdrowieZeSzczegolami()
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.analityka.ok', true);
    }

    /**
     * Nieistniejący dokument też nie jest tą awarią.
     *
     * Brak dokumentu prawnego to awaria o własnej, znacznie głośniejszej
     * sygnalizacji — `/prywatnosc` oddaje wtedy 500. Meldowanie w tym miejscu
     * „brak tokenu" wskazywałoby operatorowi zupełnie nie tę naprawę.
     */
    public function test_brak_dokumentu_nie_udaje_braku_tokenu(): void
    {
        $this->produkcjaBezSzumu();
        $this->bezTokenu();

        config(['kuking.analytics.cloudflare.obietnica.dokument' => 'legal/nie-ma-takiego-pliku.md']);

        $this->zdrowieZeSzczegolami()
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.analityka.ok', true);
    }

    /**
     * Poza produkcją brak tokenu jest stanem NORMALNYM — tak stoi
     * w `.env.example`, tak chodzi CI, tak chodzą wszystkie środowiska
     * preview i staging (tam token świadomie nie wchodzi, żeby ruch testowy
     * nie mieszał się z ruchem ludzi w jednym panelu). Stały `degraded`
     * w tych środowiskach byłby szumem.
     */
    public function test_poza_produkcja_brak_tokenu_nie_jest_awaria(): void
    {
        Artisan::call('storage:link');
        $this->bezTokenu();

        $this->zdrowieZeSzczegolami()
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.analityka.ok', true);
    }

    /**
     * Publiczna odpowiedź nie niesie ANI nazwy zmiennej, ANI odnośnika do
     * runbooka — mimo że sam token sekretem nie jest.
     *
     * Trasa `/health` nie ma `auth` i mieć nie może: Railway odpytuje ją
     * z zewnątrz. Zdanie dla właściciela ma prawo pójść WYŁĄCZNIE do
     * serwerowego logu. Token nie jest sekretem, ale wykaz tego, czego nam
     * brakuje, jest wskazówką dla kogoś, kto właśnie szuka, po czym uderzyć.
     */
    public function test_odpowiedz_nie_zdradza_nazwy_zmiennej_ani_odnosnika(): void
    {
        $this->produkcjaBezSzumu();
        $this->bezTokenu();

        $tresc = (string) $this->zdrowieZeSzczegolami()->getContent();

        foreach ([self::ZMIENNA, 'DEPLOYMENT_RUNBOOK', 'Web Analytics'] as $tajne) {
            $this->assertStringNotContainsString(
                $tajne,
                $tresc,
                'Publiczna odpowiedź /health niesie „'.$tajne.'". Czyta ją dowolna osoba '
                .'w internecie. Na zewnątrz idzie sam kod z HealthController::POWODY; '
                .'zdanie dla właściciela zostaje w logu.',
            );
        }
    }

    /**
     * Zdanie, które idzie do LOGU, mówi właścicielowi obie rzeczy naraz:
     * nazwę zmiennej i to, że sam token nie wystarczy.
     *
     * Bez drugiej połowy ten komunikat prowadzi wprost w najgorszy stan tego
     * wdrożenia: token wpisany, `/health` zielony, wszystko po naszej stronie
     * w porządku — i panel nadal pusty, bo w cudzym panelu stoi wariant
     * wykluczający Unię Europejską. Tego z kontenera nie da się zmierzyć,
     * więc jedyne, co możemy zrobić, to powiedzieć o tym w tym samym zdaniu.
     */
    public function test_zdanie_do_logu_mowi_o_zmiennej_i_o_wariancie_zbierania_danych(): void
    {
        $komunikat = AnalitykaCloudflare::komunikatBrakuTokenu();

        foreach ([
            self::ZMIENNA => 'nazwa zmiennej, bez której nie ma czego szukać',
            'Unię Europejską' => 'druga, niezależna przyczyna pustego panelu',
            'wstrzykiwanie' => 'trzecia czynność w panelu — podwójne liczenie odsłon',
            'KROK 8F' => 'gdzie stoi instrukcja krok po kroku',
        ] as $szukane => $poco) {
            $this->assertStringContainsString(
                $szukane,
                $komunikat,
                'Komunikat dla właściciela nie mówi o „'.$szukane.'" ('.$poco.'). '
                .'To jedyne zdanie, które w tej sprawie zobaczy człowiek — na zewnątrz '
                .'idzie sam kod „'.self::POWOD.'", który nie mówi nic.',
            );
        }
    }

    // ------------------------------------------------------------------
    //  Pomocnicze
    // ------------------------------------------------------------------

    /**
     * Produkcja z uciszonymi sprawdzeniami, które NIE dotyczą tego pliku.
     *
     * Bez tego każdy test niżej mierzyłby cudzą awarię: na produkcji `/health`
     * sprawdza też Turnstile (bez kluczy w testach), pocztę (domyślnie
     * `MAIL_MAILER=array` w `phpunit.xml`) oraz dwie drogi wejścia — i `status`
     * byłby `degraded` niezależnie od analityki. Ten sam zabieg i ten sam powód
     * co w `WdrozenieWejsciaFacebookiemTest`.
     */
    private function produkcjaBezSzumu(): void
    {
        Artisan::call('storage:link');

        config([
            'kuking.turnstile.klucz_publiczny' => 'test-klucz-publiczny',
            'kuking.turnstile.sekret' => 'test-sekret',
            'mail.default' => 'smtp',
            'kuking.google.wlaczone' => false,
            'kuking.facebook.wlaczone' => false,
            // Czyszczenie cache CDN (audyt G-03) zapala na produkcji własny
            // sygnał `czyszczenie_cdn_wylaczone`, gdy nie ma `CLOUDFLARE_ZONE_ID`
            // i `CLOUDFLARE_PURGE_TOKEN` — a w testach ich nie ma i mieć nie
            // musi. Uciszamy go udawaną parą, żeby ten test mierzył swoje.
            'kuking.media.cdn_purge.zone_id' => 'udawana-strefa',
            'kuking.media.cdn_purge.token' => 'udawany-token-czyszczenia',
        ]);

        $this->app->detectEnvironment(static fn (): string => 'production');
    }

    private function bezTokenu(): void
    {
        config(['kuking.analytics.cloudflare.token' => '']);
    }

    /**
     * Fragment pliku od podanego nagłówka do następnego nagłówka tego samego
     * albo wyższego poziomu.
     *
     * Kotwicą jest DOKŁADNY początek linii, nie „gdzieś w pliku": runbook ma
     * kilkanaście kroków i wiele tabel, a skan „od pierwszego trafienia do
     * końca pliku" przepuściłby wszystko, co stoi w krokach dalszych.
     *
     * BLOKI KODU SĄ POMIJANE PRZY SZUKANIU KOŃCA SEKCJI — i to nie jest
     * drobiazg. Sekcje „Sprawdzenie, że naprawdę działa" są w tym runbooku
     * pisane jako `bash` z komentarzami (`# 1. Healthcheck...`), a taka linia
     * wygląda dla skanu jak nagłówek pierwszego poziomu. Bez tego warunku skan
     * urywał sekcję na pierwszym poleceniu do wklejenia, czyli dokładnie na
     * tym, czego ma pilnować.
     */
    private function sekcja(string $plik, string $naglowek): string
    {
        $tresc = $this->tresc($plik);
        $poziom = strlen($naglowek) - strlen(ltrim($naglowek, '#'));

        $linie = explode("\n", $tresc);
        $zebrane = [];
        $wSekcji = false;
        $wBlokuKodu = false;

        foreach ($linie as $linia) {
            if (! $wSekcji) {
                if (str_starts_with($linia, $naglowek)) {
                    $wSekcji = true;
                    $zebrane[] = $linia;
                }

                continue;
            }

            if (str_starts_with(ltrim($linia), '```')) {
                $wBlokuKodu = ! $wBlokuKodu;
            }

            if (! $wBlokuKodu && preg_match('/^#{1,'.$poziom.'}\s/', $linia) === 1) {
                break;
            }

            $zebrane[] = $linia;
        }

        $this->assertTrue(
            $wSekcji,
            'W '.$plik.' nie ma sekcji zaczynającej się od „'.$naglowek.'". '
            .'Jeśli sekcję przemianowano — popraw kotwicę w tym teście I sprawdź, '
            .'czy treść nadal tam jest. Jeśli sekcji nie ma wcale — to jest właśnie '
            .'ta luka, której ten plik pilnuje.',
        );

        return implode("\n", $zebrane);
    }

    private function tresc(string $plik): string
    {
        $sciezka = base_path($plik);

        $this->assertFileExists(
            $sciezka,
            'Nie ma '.$plik.' — a to jeden z plików, wobec których ten test sprawdza, '
            .'czy analitykę odwiedzin da się w ogóle wdrożyć.',
        );

        return (string) file_get_contents($sciezka);
    }
}
