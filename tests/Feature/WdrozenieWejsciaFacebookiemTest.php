<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\HealthController;
use App\Support\Facebook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Wejście kontem Facebooka DA SIĘ WDROŻYĆ — trzy twierdzenia, trzy strażnicy.
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Do 12 września 2026 logowanie kontem Facebooka było w `main` w całości:
 * kontroler, trasy, ekrany, polityka prywatności, osobny runbook panelu Meta
 * i 43 testy funkcji (D-113, issue #259). **I nie dało się tego wdrożyć.**
 * Nie dlatego, że coś nie działało — dlatego, że nikt nie miał skąd wiedzieć,
 * co ustawić, a to, co by ustawił, i tak nie doszłoby do aplikacji:
 *
 *  1. `docs/infra/DEPLOYMENT_RUNBOOK.md` miał KROK 8D dla Google i **żadnego
 *     kroku dla Facebooka** — runbook nigdy nie kazał skonfigurować kluczy;
 *  2. `.railway/railway.ts` przepuszczał `GOOGLE_*` i **nie przepuszczał
 *     `FACEBOOK_*`** — klucze wpisane w Shared Variables Railwaya stały tam
 *     i nie docierały do serwisu;
 *  3. `/health` **nie wiedział o żadnym z dwóch dostawców** — więc wdrożenie
 *     bez kluczy wyglądało dokładnie tak samo jak zdrowe, a przycisk „Wejdź
 *     kontem Facebooka" po prostu nie istniał.
 *
 * Trzecia luka jest najgorsza z tej trójki i to ona nazywa całą klasę błędu:
 * **cicha, nieistniejąca droga wejścia jest gorsza niż wyłączona.** Dwie
 * pierwsze da się odkryć, próbując wdrożyć. Trzeciej nie odkrywa nikt.
 *
 * DLACZEGO TO JEST TEST, A NIE WPIS W CHECKLIŚCIE
 * Bo wszystkie trzy luki powstały **przez pominięcie**, nie przez błędną
 * decyzję. Nikt nie postanowił, że Facebooka w runbooku nie będzie — po
 * prostu Google dopisano wcześniej i nikt nie wrócił. Jedyną obroną przed
 * pominięciem jest coś, co oblewa. Wzorzec strażnika dokumentu bierzemy
 * z `TabelaStackuMowiPrawdeTest` (D-104): skan pliku plus PROGI, żeby test,
 * który przestał cokolwiek znajdować, oblewał zamiast świecić na zielono
 * (`docs/PULAPKI_TESTOW.md`, pułapka 2).
 *
 * CZEGO TEN PLIK ŚWIADOMIE NIE PILNUJE — TO GRANICA, NIE PRZEOCZENIE
 *  - **Logiki samego logowania.** Pilnują jej `LogowanieKontemFacebookiemTest`
 *    (43 testy) i `OdebranieDostepuFacebookaTest`. Ten plik stoi przy jednym
 *    pytaniu: czy tę zrobioną funkcję **da się uruchomić na produkcji**.
 *  - **Czy właściciel wykonał czynności w panelu Meta.** Z repozytorium nie
 *    da się sprawdzić, czy ktoś kliknął „App Mode → Live". Da się sprawdzić,
 *    czy runbook go o to prosi i czy mówi, jak to zweryfikować.
 *  - **Treści runbooka zdanie po zdaniu.** Test, który dopasowuje akapity,
 *    oblewa przy każdej poprawce stylistycznej i kończy jako wyciszony.
 *    Pilnujemy KOTWIC: numeru kroku, nazw zmiennych, obecności sekcji
 *    „sprawdź, że działa" i wierszy w dwóch tabelach.
 */
class WdrozenieWejsciaFacebookiemTest extends TestCase
{
    use RefreshDatabase;

    private const RUNBOOK = 'docs/infra/DEPLOYMENT_RUNBOOK.md';

    private const RAILWAY = '.railway/railway.ts';

    /** Nagłówek kroku Facebooka — kotwica całego skanu runbooka. */
    private const NAGLOWEK_8E = '## KROK 8E.';

    /** Nagłówek kroku Google — wzorzec, wobec którego 8E ma być symetryczny. */
    private const NAGLOWEK_8D = '## KROK 8D.';

    /**
     * Dwie zmienne, bez których ta droga wejścia nie istnieje. Nazwy są
     * czytane z `config/kuking.php` w `test_nazwy_zmiennych_sa_te_same...`,
     * więc literówka tutaj nie przejdzie po cichu.
     */
    private const ZMIENNE = ['FACEBOOK_CLIENT_ID', 'FACEBOOK_CLIENT_SECRET'];

    /**
     * PRÓG — bez niego ten test jest zielony na zawsze.
     *
     * Skan, który nie znajdzie kroku 8E, zwróci pusty łańcuch, a każde
     * `assertStringContainsString` na pustce obleje — ale dopiero po tym, jak
     * ktoś przesunie numerację kroków i test zacznie sprawdzać NIE TEN krok.
     * Ten próg pilnuje, że kotwica trafia w prawdziwą, niepustą sekcję.
     * Zmierzone 12.09.2026: krok 8E ma ~190 wierszy, 8D ma ~180.
     */
    private const MIN_WIERSZY_KROKU = 60;

    // ------------------------------------------------------------------
    //  Twierdzenie 1: runbook wdrożeniowy opisuje krok Facebooka
    // ------------------------------------------------------------------

    /**
     * Runbook ma KROK 8E i mówi w nim trzy rzeczy: co założyć w panelu Meta,
     * jakie zmienne wpisać i jak sprawdzić, że działa.
     *
     * Trzecia jest tu najważniejsza i najłatwiejsza do pominięcia. Runbook
     * ma po każdej sekcji „sprawdź, że działa" — bez niej krok kończy się
     * słowem „gotowe" wypowiedzianym przez kogoś, kto niczego nie sprawdził.
     */
    public function test_runbook_wdrozeniowy_ma_krok_facebooka(): void
    {
        $krok = $this->sekcja(self::RUNBOOK, self::NAGLOWEK_8E);

        $this->assertGreaterThanOrEqual(
            self::MIN_WIERSZY_KROKU,
            substr_count($krok, "\n"),
            'Krok 8E w '.self::RUNBOOK.' ma tylko '.substr_count($krok, "\n").' wierszy, '
            .'a spodziewamy się co najmniej '.self::MIN_WIERSZY_KROKU.'. Albo kotwica '
            .'„'.self::NAGLOWEK_8E.'" trafia w niewłaściwe miejsce (usterka TEGO TESTU), '
            .'albo krok skurczył się do wzmianki — a wtedy nie jest instrukcją wdrożenia.',
        );

        foreach ([
            'Meta' => 'nazwa panelu, w którym właściciel zakłada aplikację',
            'developers.facebook.com' => 'adres panelu Meta',
            'App ID' => 'nazwa, którą Meta nadaje FACEBOOK_CLIENT_ID',
            'App Secret' => 'nazwa, którą Meta nadaje FACEBOOK_CLIENT_SECRET',
            'Sealed' => 'zaznaczenie sekretu w panelu Railway',
            '/wejdz/facebook/wroc' => 'adres powrotu, który Meta dopasowuje znak w znak',
            'https://kuking.pl/prywatnosc' => 'Data Deletion Instructions URL',
            'Live' => 'przestawienie aplikacji w tryb publiczny',
            'KUKING_WEJSCIE_FACEBOOK' => 'wyłącznik funkcji bez migracji',
        ] as $szukane => $poco) {
            $this->assertStringContainsString(
                $szukane,
                $krok,
                'Krok 8E w '.self::RUNBOOK.' nie mówi o „'.$szukane.'" ('.$poco.'). '
                .'Właściciel czyta ten krok ZAMIAST panelu Meta — czego tu nie ma, '
                .'tego nie zrobi.',
            );
        }

        foreach (self::ZMIENNE as $zmienna) {
            $this->assertStringContainsString(
                $zmienna,
                $krok,
                'Krok 8E w '.self::RUNBOOK.' nie wymienia zmiennej „'.$zmienna.'". '
                .'Bez jej nazwy krok mówi „wpisz klucze do Railway" i nie mówi, POD JAKĄ '
                .'NAZWĄ — a `ctx.shared` w '.self::RAILWAY.' szuka dokładnie tej.',
            );
        }
    }

    /**
     * Krok 8E ma własną sekcję „sprawdź, że działa" — z `/health` i z okiem.
     *
     * Osobny test, nie kolejna asercja wyżej: „kroku nie ma" i „krok nie mówi,
     * jak się upewnić" to dwie różne usterki o dwóch różnych naprawach.
     */
    public function test_krok_facebooka_mowi_jak_sprawdzic_ze_dziala(): void
    {
        $krok = $this->sekcja(self::RUNBOOK, self::NAGLOWEK_8E);

        $this->assertMatchesRegularExpression(
            '/###\s+8E\.\d+\s+Sprawdzenie, że naprawdę działa/u',
            $krok,
            'Krok 8E w '.self::RUNBOOK.' nie ma sekcji „Sprawdzenie, że naprawdę '
            .'działa". Każdy krok tego runbooka ją ma i to nie jest ozdoba: krok bez '
            .'niej kończy się słowem „gotowe" wypowiedzianym przez kogoś, kto niczego '
            .'nie sprawdził. Wzór stoi obok, w 8D.3.',
        );

        foreach ([
            '/health' => 'sprawdzenie automatem, które da się wpiąć w monitoring',
            'facebook_bez_kluczy' => 'powód, którym `/health` melduje brak kluczy',
            '/login' => 'sprawdzenie okiem: czy przycisk jest na ekranie',
        ] as $szukane => $poco) {
            $this->assertStringContainsString(
                $szukane,
                $krok,
                'Sekcja sprawdzająca w kroku 8E nie mówi o „'.$szukane.'" ('.$poco.'). '
                .'Sprawdzenie tylko okiem jest za słabe — nikt nie otwiera /login co pięć '
                .'minut; sprawdzenie tylko automatem nie pokaże, że przycisk stoi tam, '
                .'gdzie ma stać.',
            );
        }
    }

    /**
     * Dwie tabele, w których Google już był, a Facebooka nie było: pełna lista
     * zmiennych produkcyjnych (KROK 8) i zestawienie „potrzebne od
     * właściciela" (KROK 16).
     *
     * To są miejsca, do których zagląda się BEZ czytania całego runbooka —
     * przy zakładaniu zmiennych w Railwayu i przy odhaczaniu, czego jeszcze
     * brakuje. Krok 8E może być doskonały i nie pomoże, jeśli te dwie tabele
     * o nim milczą.
     */
    public function test_tabele_zmiennych_i_zestawienie_wlasciciela_wymieniaja_facebooka(): void
    {
        $runbook = $this->tresc(self::RUNBOOK);

        $tabelaZmiennych = $this->sekcja(self::RUNBOOK, '### Pełna lista — środowisko `production`');
        $zestawienie = $this->sekcja(self::RUNBOOK, '## KROK 16.');

        foreach (self::ZMIENNE as $zmienna) {
            $this->assertStringContainsString(
                $zmienna,
                $tabelaZmiennych,
                'Tabela zmiennych produkcyjnych (KROK 8) nie wymienia „'.$zmienna.'". '
                .'Google stoi w niej od D-069 — do tej tabeli zagląda się przy zakładaniu '
                .'Shared Variables, bez czytania całego runbooka.',
            );

            $this->assertStringContainsString(
                $zmienna,
                $zestawienie,
                'Zestawienie „[POTRZEBNE OD WŁAŚCICIELA]" (KROK 16) nie wymienia '
                .'„'.$zmienna.'". To jest lista, po której właściciel odhacza, czego '
                .'jeszcze brakuje — czego na niej nie ma, o tym nie wie.',
            );
        }

        $this->assertStringContainsString(
            'FACEBOOK_LOGIN_URUCHOMIENIE.md',
            $runbook,
            'Runbook wdrożeniowy nigdzie nie odsyła do '
            .'docs/infra/FACEBOOK_LOGIN_URUCHOMIENIE.md — a to tam stoi cały panel Meta '
            .'krok po kroku (§12). Bez tego odnośnika krok 8E udaje, że kilkanaście '
            .'czynności właściciela mieści się w jednym akapicie.',
        );
    }

    /**
     * Wersja Graph API ma pozycję w przeglądzie kwartalnym.
     *
     * To jest obowiązek, którego droga Google nie ma wcale, i pilnujemy go
     * tutaj, bo AWARIA JEST CICHA: po wygaśnięciu wersji wywołania do Mety
     * nie padają, tylko spadają na starszą. Czyli działa do dnia, w którym
     * przestanie, i nikt nie wie którego. `.env.example` i `config/kuking.php`
     * obiecują wprost, że ta pozycja w runbooku stoi — ten test pilnuje, żeby
     * ta obietnica nie była pusta.
     */
    public function test_przeglad_kwartalny_pilnuje_wersji_graph_api(): void
    {
        $rutyna = $this->sekcja(self::RUNBOOK, '## KROK 15.');

        $this->assertStringContainsString(
            'Graph API',
            $rutyna,
            'Rutyna utrzymaniowa (KROK 15) nie ma pozycji o wersji Graph API. '
            .'`.env.example` obiecuje przy FACEBOOK_GRAPH_WERSJA, że „w przeglądzie '
            .'kwartalnym stoi pozycja »sprawdź, czy nasza wersja Graph API jeszcze '
            .'żyje«" — bez niej ta obietnica jest nieprawdziwa, a awaria z wygasłej '
            .'wersji jest CICHA: wywołania spadają na starszą zamiast paść.',
        );

        $this->assertStringContainsString(
            'FACEBOOK_GRAPH_WERSJA',
            $rutyna,
            'Pozycja o Graph API w KROKU 15 nie mówi, KTÓRĄ zmienną się podnosi. '
            .'Bez nazwy zmiennej ta pozycja mówi „sprawdź coś" i nie mówi, co zrobić '
            .'z odpowiedzią.',
        );
    }

    // ------------------------------------------------------------------
    //  Twierdzenie 2: railway.ts przepuszcza te zmienne do serwisu
    // ------------------------------------------------------------------

    /**
     * `.railway/railway.ts` przekazuje `FACEBOOK_*` do serwisu — dokładnie tak
     * jak `GOOGLE_*`.
     *
     * TO JEST NAJCICHSZA Z TRZECH LUK. Zmienne wpisane w Shared Variables
     * Railwaya nie trafiają do aplikacji same z siebie: `railway.ts` musi je
     * wymienić przez `ctx.shared`. Bez tych dwóch linii właściciel wykonuje
     * kilkanaście czynności w panelu Meta, robi `railway config apply` —
     * i dostaje ekran bez przycisku, bez błędu i bez wskazówki dlaczego.
     */
    public function test_railway_przepuszcza_zmienne_facebooka_do_serwisu(): void
    {
        $railway = $this->tresc(self::RAILWAY);

        foreach (self::ZMIENNE as $zmienna) {
            $this->assertMatchesRegularExpression(
                '/^\s*'.preg_quote($zmienna, '/').':\s*ctx\.shared\.'.preg_quote($zmienna, '/').',/m',
                $railway,
                'W '.self::RAILWAY.' nie ma linii „'.$zmienna.': ctx.shared.'.$zmienna.',". '
                .'Bez niej klucz wpisany w Shared Variables Railwaya NIE DOCHODZI do '
                .'serwisu — przycisku nie ma i nikt nie wie dlaczego. Wzór stoi kilka '
                .'linii wyżej, przy GOOGLE_CLIENT_ID (issue #259).',
            );
        }
    }

    /**
     * Symetria wobec Google jest tu twierdzeniem, nie estetyką: dokładnie ta
     * sama rola, ten sam mechanizm i ten sam sposób pomyłki. Gdyby ktoś kiedyś
     * usunął `GOOGLE_*` z `railway.ts`, ten test powie, że wzorzec zniknął —
     * zamiast przepuścić „Facebook jest, więc dobrze".
     */
    public function test_facebook_stoi_w_railway_na_rownych_prawach_z_google(): void
    {
        $railway = $this->tresc(self::RAILWAY);

        foreach (['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'] as $zmienna) {
            $this->assertStringContainsString(
                $zmienna.': ctx.shared.'.$zmienna,
                $railway,
                'W '.self::RAILWAY.' zniknęła linia „'.$zmienna.'". To jest wzorzec, '
                .'wobec którego dopisano FACEBOOK_* (issue #259) — jeśli wzorzec '
                .'zniknął, przestało działać wejście kontem Google i ten test mówi '
                .'o tym pierwszy.',
            );
        }
    }

    /**
     * Nazwy zmiennych w runbooku i w `railway.ts` to DOKŁADNIE te nazwy, które
     * czyta `config/kuking.php`.
     *
     * Bez tego sprawdzenia wszystkie powyższe mogą być zielone przy literówce,
     * której nikt nie zobaczy: runbook każe wpisać `FACEBOOK_APP_ID`,
     * `railway.ts` przepuszcza `FACEBOOK_APP_ID`, a aplikacja czyta
     * `FACEBOOK_CLIENT_ID` i nie widzi niczego. Źródłem prawdy jest tu
     * konfiguracja, bo to ona naprawdę decyduje.
     */
    public function test_nazwy_zmiennych_sa_te_same_co_czyta_konfiguracja(): void
    {
        $config = $this->tresc('config/kuking.php');

        foreach ([
            'FACEBOOK_CLIENT_ID' => 'kuking.facebook.identyfikator_klienta',
            'FACEBOOK_CLIENT_SECRET' => 'kuking.facebook.sekret_klienta',
        ] as $zmienna => $klucz) {
            $this->assertMatchesRegularExpression(
                "/env\\(\\s*'".preg_quote($zmienna, '/')."'/",
                $config,
                'config/kuking.php nie czyta zmiennej „'.$zmienna.'" (klucz '.$klucz.'). '
                .'Albo zmienna została przemianowana i runbook razem z '.self::RAILWAY.' '
                .'mówią teraz o nieistniejącej nazwie, albo ten test pilnuje nazwy, '
                .'której już nie ma. Jedno i drugie wymaga poprawki CZŁOWIEKA, nie '
                .'wyciszenia testu.',
            );
        }

        // Kropka nad i: te same nazwy naprawdę sterują tym, co robi aplikacja.
        config(['kuking.facebook.identyfikator_klienta' => '']);
        config(['kuking.facebook.sekret_klienta' => '']);
        $this->assertFalse(Facebook::skonfigurowany());

        config(['kuking.facebook.identyfikator_klienta' => 'udawany-app-id']);
        config(['kuking.facebook.sekret_klienta' => 'udawany-app-secret']);
        $this->assertTrue(Facebook::skonfigurowany());
    }

    // ------------------------------------------------------------------
    //  Twierdzenie 3: /health mówi prawdę o braku kluczy
    // ------------------------------------------------------------------

    /**
     * Produkcja z włączoną funkcją i bez kluczy jest `degraded`, a nie „ok".
     *
     * TO JEST SEDNO CAŁEGO PLIKU. Dwie poprzednie luki da się odkryć, próbując
     * wdrożyć. Tej nie odkrywa nikt: bez kluczy przycisku po prostu nie ma na
     * ekranie — czyli wdrożenie, w którym droga wejścia NIE ISTNIEJE, wygląda
     * identycznie jak wdrożenie, na którym właściciel świadomie jej nie chciał.
     * `AGENTS.md` D-053 zabrania martwych przycisków; to jest ta sama zasada
     * widziana z drugiej strony — zabrania OBIECANEJ drogi, której nie ma.
     *
     * Świadomie 200, nie 503: healthcheck oddający 503 już raz położył ten
     * serwis. Monitoring pilnuje TREŚCI odpowiedzi.
     */
    public function test_produkcja_z_wlaczona_funkcja_i_bez_kluczy_jest_degraded(): void
    {
        $this->produkcjaBezSzumu();
        $this->facebookWlaczonyBezKluczy();

        $odpowiedz = $this->get('/health');

        $odpowiedz->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.facebook.ok', false)
            ->assertJsonPath('checks.facebook.error', 'facebook_bez_kluczy')
            // Baza i zdjęcia są całe — sygnał dotyczy WYŁĄCZNIE konfiguracji.
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.media.ok', true);

        $this->assertContains(
            $odpowiedz->json('checks.facebook.error'),
            HealthController::POWODY,
            'Powód „facebook_bez_kluczy" nie stoi w zamkniętym zbiorze '
            .'HealthController::POWODY. Ta odpowiedź jest publiczna, a '
            .'HealthNieZdradzaSzczegolowTest pilnuje, że nie wychodzi z niej nic '
            .'spoza tego zbioru.',
        );
    }

    /**
     * Ta sama luka dla Google — bo issue #258 nazywało ją tym samym zdaniem
     * i dotyczyła obu dostawców naraz. Osobny powód, bo osobna naprawa: Google
     * Cloud Console to nie jest panel Meta.
     */
    public function test_produkcja_bez_kluczy_google_tez_jest_degraded(): void
    {
        $this->produkcjaBezSzumu();
        $this->facebookSwiadomieWylaczony();

        config(['kuking.google.wlaczone' => true]);
        config(['kuking.google.identyfikator_klienta' => '']);
        config(['kuking.google.sekret_klienta' => '']);

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.google.ok', false)
            ->assertJsonPath('checks.google.error', 'google_bez_kluczy')
            ->assertJsonPath('checks.facebook.ok', true);
    }

    /**
     * Z kluczami na miejscu `/health` milczy — inaczej ten sygnał byłby stałym
     * `degraded`, czyli szumem, który uczy ignorować całe pole (ta sama lekcja
     * co przy Turnstile).
     */
    public function test_produkcja_z_kluczami_jest_zdrowa(): void
    {
        $this->produkcjaBezSzumu();

        config(['kuking.facebook.wlaczone' => true]);
        config(['kuking.facebook.identyfikator_klienta' => 'udawany-app-id']);
        config(['kuking.facebook.sekret_klienta' => 'udawany-app-secret']);

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.facebook.ok', true);
    }

    /**
     * Świadome wyłączenie funkcji NIE JEST awarią.
     *
     * `KUKING_WEJSCIE_FACEBOOK=false` znaczy „nie chcę tej drogi" —
     * konfiguracja wtedy niczego nie obiecuje, więc nie ma o czym krzyczeć.
     * Bez tego rozróżnienia jedynym sposobem uciszenia sygnału byłoby wpisanie
     * byle czego w klucze, czyli nauczenie właściciela kłamania konfiguracji.
     */
    public function test_swiadome_wylaczenie_funkcji_nie_jest_awaria(): void
    {
        $this->produkcjaBezSzumu();
        $this->facebookSwiadomieWylaczony();

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.facebook.ok', true);
    }

    /**
     * Poza produkcją brak kluczy jest stanem NORMALNYM — tak stoi
     * w `.env.example`, tak chodzi CI, tak chodzą wszystkie środowiska
     * preview (Meta nie przyjmuje wieloznaczników w adresach powrotu).
     * Stały `degraded` w tych środowiskach byłby szumem.
     */
    public function test_poza_produkcja_brak_kluczy_nie_jest_awaria(): void
    {
        Artisan::call('storage:link');
        $this->facebookWlaczonyBezKluczy();

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.facebook.ok', true);
    }

    /**
     * Publiczna odpowiedź nie niesie ANI nazwy zmiennej, ANI fragmentu klucza.
     *
     * Trasa `/health` nie ma `auth` i mieć nie może — Railway odpytuje ją
     * z zewnątrz. Zdanie dla właściciela (z nazwami zmiennych i odnośnikiem do
     * runbooka) ma prawo pójść WYŁĄCZNIE do serwerowego logu.
     */
    public function test_odpowiedz_nie_zdradza_nazw_zmiennych_ani_kluczy(): void
    {
        $this->produkcjaBezSzumu();
        $this->facebookWlaczonyBezKluczy();

        $tresc = (string) $this->get('/health')->getContent();

        foreach ([...self::ZMIENNE, 'App Secret', 'developers.facebook.com'] as $tajne) {
            $this->assertStringNotContainsString(
                $tajne,
                $tresc,
                'Publiczna odpowiedź /health niesie „'.$tajne.'". Czyta ją dowolna osoba '
                .'w internecie, łącznie z tą, która właśnie szuka, po czym uderzyć. '
                .'Na zewnątrz idzie sam kod z HealthController::POWODY; zdanie dla '
                .'właściciela zostaje w logu.',
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
     * sprawdza też Turnstile (bez kluczy w testach) i pocztę (domyślnie
     * `MAIL_MAILER=array` w `phpunit.xml`) — i `status` byłby `degraded`
     * niezależnie od Facebooka. Dokładnie ten sam zabieg i ten sam powód, co
     * w `HealthPocztaKolejkaIWebhookTest` i `TurnstileWymagaPotwierdzeniaTest`.
     */
    private function produkcjaBezSzumu(): void
    {
        Artisan::call('storage:link');

        config([
            'kuking.turnstile.klucz_publiczny' => 'test-klucz-publiczny',
            'kuking.turnstile.sekret' => 'test-sekret',
            'mail.default' => 'smtp',
            // Google ma własny sygnał od tej samej zmiany — tam, gdzie test
            // pyta o Facebooka, Google ma milczeć.
            'kuking.google.wlaczone' => false,
            // Analityka odwiedzin (D-092) ma na produkcji własny sygnał
            // `analityka_bez_tokenu`: polityka prywatności ją obiecuje, a tokenu
            // w testach nie ma. Uciszamy ją udawanym tokenem — przełącznika
            // „wyłącz" tam świadomie nie ma, bo obietnica stoi w dokumencie
            // prawnym, nie w konfiguracji.
            'kuking.analytics.cloudflare.token' => 'udawany-token-analityki',
            // Czyszczenie cache CDN (audyt G-03) zapala na produkcji własny
            // sygnał `czyszczenie_cdn_wylaczone`, gdy nie ma `CLOUDFLARE_ZONE_ID`
            // i `CLOUDFLARE_PURGE_TOKEN` — a w testach ich nie ma i mieć nie
            // musi. Uciszamy go udawaną parą, żeby ten test mierzył swoje.
            'kuking.media.cdn_purge.zone_id' => 'udawana-strefa',
            'kuking.media.cdn_purge.token' => 'udawany-token-czyszczenia',
        ]);

        $this->app->detectEnvironment(static fn (): string => 'production');
        // Produkcja z poprawnym trybem debugowania i ciasteczkiem sesji —
        // inaczej `/health` zgłosi własną, niezwiązaną awarię (audyt B10-04).
        config(['app.debug' => false, 'session.secure' => true]);
    }

    private function facebookWlaczonyBezKluczy(): void
    {
        config([
            'kuking.facebook.wlaczone' => true,
            'kuking.facebook.identyfikator_klienta' => '',
            'kuking.facebook.sekret_klienta' => '',
        ]);
    }

    private function facebookSwiadomieWylaczony(): void
    {
        config([
            'kuking.facebook.wlaczone' => false,
            'kuking.facebook.identyfikator_klienta' => '',
            'kuking.facebook.sekret_klienta' => '',
        ]);
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
            .'ta luka, której ten plik pilnuje (issue #259).',
        );

        return implode("\n", $zebrane);
    }

    private function tresc(string $plik): string
    {
        $sciezka = base_path($plik);

        $this->assertFileExists(
            $sciezka,
            'Nie ma '.$plik.' — a to jeden z plików, wobec których ten test sprawdza, '
            .'czy wejście kontem Facebooka da się w ogóle wdrożyć.',
        );

        return (string) file_get_contents($sciezka);
    }
}
