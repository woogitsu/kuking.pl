<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Strażnik reguły R60 (`AGENTS.md` §10): testy chodzą na PostgreSQL,
 * nie na SQLite.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  DLACZEGO AKURAT TA REGUŁA POTRZEBUJE STRAŻNIKA NAJPILNIEJ
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Złamanie R60 nie robi żadnej czerwieni. Robi coś gorszego: **unieważnia
 * znaczenie wszystkich pozostałych zielonych przebiegów**. Kiedy suita po
 * cichu zjedzie na SQLite, każdy inny strażnik nadal będzie zielony — tylko
 * przestanie mierzyć to, co obiecuje jego nazwa. Zielone nie staje się
 * fałszywe, staje się BEZWARTOŚCIOWE, a tego nie widać w żadnym raporcie.
 *
 * Schemat Kuking stoi na rzeczach, których SQLite nie ma: indeksy częściowe,
 * `num_nonnulls()`, `gen_random_uuid()`, `pg_trgm`, `unaccent`, JSONB, GIN,
 * ograniczenia unikalności z NULL-ami. Część testów ma dziś jawne
 * `if (DB::connection()->getDriverName() !== 'pgsql') { markTestSkipped }`
 * (`KolumnySzukaniaTest`, `NumerSprawyTest`, `RegressionTest`,
 * `TrafnoscWyszukiwarkiTest`) — czyli na SQLite **same się wyłączą i nadal
 * będą zielone**. To jest dokładnie ten mechanizm, który zamienia zjazd na
 * SQLite w cichą stratę pokrycia zamiast w porażkę.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  DWIE DROGI, KTÓRYMI SUITA MOŻE ZJECHAĆ NA SQLITE — I DLACZEGO OBIE
 *  TRZEBA MIERZYĆ OSOBNO
 * ═══════════════════════════════════════════════════════════════════════
 *
 * ── Droga 1: PLIK KONFIGURACYJNY PRZESTAJE MÓWIĆ „pgsql".
 *    `config/database.php` ma `'default' => env('DB_CONNECTION', 'sqlite')`.
 *    Wartość domyślna to SQLITE — Laravel tak to wysyła i nikt tego nie
 *    zmienił. Jedyne, co trzyma przebieg na Postgresie przy zwykłym
 *    `php artisan test` bez wyeksportowanych zmiennych, to wiersz
 *    `<env name="DB_CONNECTION" value="pgsql"/>` w `phpunit.xml`. Usunięcie
 *    tego jednego wiersza nie wywala niczego głośno: suita startuje, tylko
 *    idzie na plik SQLite.
 *
 * ── Droga 2: PLIK JEST POPRAWNY, A POŁĄCZENIE IDZIE GDZIE INDZIEJ.
 *    To jest ta trudniejsza. `<env>` w `phpunit.xml` BEZ `force="true"` nie
 *    nadpisuje prawdziwej zmiennej środowiskowej (nadpisuje tylko `.env`),
 *    więc otoczenie zawsze bije plik. W drugą stronę działa to tak samo:
 *    `config/database.php` przestawione na sztywno na `'sqlite'` bije
 *    JEDNO I DRUGIE — i wtedy `phpunit.xml` dalej ładnie mówi „pgsql",
 *    a testy chodzą na SQLite.
 *
 * Dlatego strażnik, który czytałby tylko `phpunit.xml`, przeszedłby po
 * mutacji z drogi 2 i byłby dokładnie tym wpisem w sekcji A mapy, przed
 * którym mapa ostrzega we własnym wstępie. **Pytamy więc o STAN FAKTYCZNY
 * ŻYWEGO POŁĄCZENIA** — sterownik z PDO, wersję z serwera, odpowiedź na
 * składnię, której SQLite nie zna — a dopiero OBOK o treść plików, bo pliki
 * pilnują przypadku „nikt nic nie wyeksportował".
 *
 * Ta różnica jest tą samą różnicą, co między pytaniem o wynik kaskady
 * a o tekst arkusza stylów.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  PUŁAPKA 2 Z `docs/PULAPKI_TESTOW.md` — SKAN BEZ TRAFIEŃ PRZECHODZI
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Dwa z tych testów czytają pliki (`phpunit.xml`, `.github/workflows/ci.yml`)
 * i orzekają na podstawie tego, czego w nich NIE ma. Taki skan przechodzi
 * także wtedy, gdy nie przeczyta nic: przeniesiony plik, zmieniona ścieżka
 * `base_path()`, pusty `file_get_contents()` — i strażnik jest zielony,
 * nie zmierzywszy niczego.
 *
 * Stąd `test_skan_naprawde_czyta_konfiguracje_i_oblewa_na_podstawionej`:
 * te same funkcje orzekające dostają podstawione, zepsute treści i MUSZĄ
 * zgłosić naruszenie. Kontrola dodatnia jest tu wbudowana w strażnika,
 * a nie zrobiona raz ręcznie obok — bo ręczna nie wraca przy następnej
 * zmianie.
 *
 * @bez-kontroli-dodatniej Nosi własną kontrolę dodatnią w środku — test_skan_naprawde_czyta_konfiguracje_i_oblewa_na_podstawionej przepuszcza PODSTAWIONE, zepsute phpunit.xml i ci.yml przez te same funkcje orzekające i wymaga naruszenia, więc dowód „umie powiedzieć nie" chodzi w każdej baterii, nie tylko w kroku kontroli negatywnych.
 */
class TestyChodzaNaPostgresieTest extends TestCase
{
    /**
     * Najniższy major PostgreSQL, na którym wolno puścić suitę.
     *
     * `AGENTS.md` (tabela stacku): produkcja i CI to 18, „lokalnie i w CI
     * wystarczy 16+". Próg stoi więc na 16, a NIE na 18 — inaczej strażnik
     * oblewałby na poprawnie skonfigurowanej maszynie deweloperskiej
     * i nauczyłby wszystkich, że jego czerwień nic nie znaczy. To, że CI
     * stoi na 18, sprawdza osobno `test_ci_stawia_postgresa_18_pod_testami`
     * — z pliku, bo w CI mierzy to sam ten przebieg.
     */
    private const MINIMALNY_MAJOR = 16;

    // ═══════════════════════════════════════════════════════════════════
    //  1. STAN FAKTYCZNY POŁĄCZENIA
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Połączenie, na którym chodzi TEN przebieg, jest żywym PostgreSQL-em.
     *
     * Nie pytamy o `config(...)`, tylko o obiekt, który dostają wszystkie
     * pozostałe testy, i o PDO pod nim. `getDriverName()` bierze się
     * z konfiguracji, więc sam by nie wystarczył — `PDO::ATTR_DRIVER_NAME`
     * bierze się ze sterownika, który NAPRAWDĘ otworzył gniazdo.
     */
    public function test_polaczenie_testowe_jest_zywym_postgresem(): void
    {
        $polaczenie = DB::connection();

        $this->assertInstanceOf(
            PostgresConnection::class,
            $polaczenie,
            'Domyślne połączenie testów nie jest połączeniem PostgreSQL. '
            .'Suita mierzy inny silnik niż produkcja — patrz AGENTS.md §10.',
        );

        $this->assertSame('pgsql', $polaczenie->getDriverName());

        $sterownik = $polaczenie->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->assertSame(
            'pgsql',
            $sterownik,
            'Sterownik ŻYWEGO połączenia to "'.$sterownik.'", nie "pgsql". '
            .'Konfiguracja może mówić co innego — liczy się to, z czym PDO się połączyło.',
        );

        // Nazwa bazy, a nie ścieżka pliku: `:memory:` i `*.sqlite` to dwa
        // kształty, które SQLite przyjmuje, a Postgres nigdy.
        $baza = (string) $polaczenie->getDatabaseName();
        $this->assertNotSame(':memory:', $baza);
        $this->assertStringEndsNotWith('.sqlite', $baza);
        $this->assertNotSame('', $baza, 'Połączenie testowe nie ma nazwy bazy.');
    }

    /**
     * Serwer po drugiej stronie melduje wersję PostgreSQL, i to dość świeżą.
     *
     * `SELECT version()` i `server_version_num` pochodzą z SERWERA, nie
     * z klienta i nie z pliku. SQLite nie zna ani jednego, ani drugiego —
     * a gdyby ktoś podstawił warstwę zgodności, i tak nie zwróci napisu
     * zaczynającego się od „PostgreSQL ".
     */
    public function test_serwer_melduje_wersje_postgresa_nie_starsza_niz_wymagana(): void
    {
        $wersja = (string) DB::selectOne('SELECT version() AS w')->w;

        $this->assertMatchesRegularExpression(
            '/^PostgreSQL \d+/',
            $wersja,
            'Serwer nie przedstawia się jako PostgreSQL. Zmierzone: '.$wersja,
        );

        $numer = (int) DB::selectOne('SHOW server_version_num')->server_version_num;
        $this->assertGreaterThan(0, $numer, 'Serwer nie podał server_version_num.');

        $major = intdiv($numer, 10000);
        $this->assertGreaterThanOrEqual(
            self::MINIMALNY_MAJOR,
            $major,
            'PostgreSQL '.$major.' jest starszy niż wymagane '.self::MINIMALNY_MAJOR
            .'+. Część schematu Kuking może się na nim nie założyć.',
        );
    }

    /**
     * Baza odpowiada na składnię, której SQLite nie ma — i odpowiada
     * POPRAWNIE, nie byle czym.
     *
     * To jest asercja o ZACHOWANIU, nie o nazwie sterownika: `num_nonnulls`,
     * `gen_random_uuid` i `to_regclass` to dokładnie ta rodzina rzeczy,
     * dla której R60 w ogóle istnieje. Gdyby suita zjechała na SQLite,
     * każde z tych zapytań wywróciłoby się na „no such function".
     *
     * Świadomie BEZ `RefreshDatabase`: ten test nie potrzebuje ani jednego
     * wiersza i nie ma prawa zrzucać schematu cudzemu przebiegowi. Dzięki
     * temu po mutacji oblewa na asercji, a nie na dwudziestu minutach
     * migracji, których SQLite i tak nie przyjmie.
     */
    public function test_baza_odpowiada_skladnia_ktorej_sqlite_nie_zna(): void
    {
        $wynik = DB::selectOne(
            'SELECT num_nonnulls(1, NULL, 3) AS ile,'
            ."\n       gen_random_uuid()::text AS uuid,"
            ."\n       to_regclass('pg_class')::text AS katalog,"
            ."\n       current_setting('server_version') AS wersja",
        );

        $this->assertSame(2, (int) $wynik->ile, 'num_nonnulls() policzył źle — to nie jest PostgreSQL.');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $wynik->uuid,
            'gen_random_uuid() nie zwrócił UUID-a.',
        );

        $this->assertSame('pg_class', (string) $wynik->katalog);
        $this->assertNotSame('', (string) $wynik->wersja);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. KONFIGURACJA, CZYLI CO SIĘ STANIE BEZ WYEKSPORTOWANYCH ZMIENNYCH
    // ═══════════════════════════════════════════════════════════════════

    /**
     * `phpunit.xml` sam z siebie trzyma suitę na Postgresie.
     *
     * Ten test NIE dubluje poprzednich. Tamte mierzą przebieg, który ma
     * wyeksportowane `DB_CONNECTION=pgsql` (robi to i CI, i skrypt floty).
     * Ten pilnuje przypadku, w którym nikt niczego nie wyeksportował — czyli
     * zwykłego `php artisan test` na czyjejś maszynie. Wtedy jedyną zaporą
     * przed `env('DB_CONNECTION', 'sqlite')` jest wiersz w `phpunit.xml`.
     */
    public function test_phpunit_trzyma_polaczenie_na_pgsql_bez_pomocy_otoczenia(): void
    {
        $naruszenia = $this->naruszeniaPhpunit($this->trescPhpunit());

        $this->assertSame([], $naruszenia, implode("\n", $naruszenia));

        // Domyślna wartość w `config/database.php` jest i pozostaje pułapką,
        // więc odnotowujemy ją tu wprost: gdyby ktoś ją kiedyś zmienił na
        // 'pgsql', ten test przestanie mieć sens i trzeba go przemyśleć,
        // a nie po cichu utrzymywać.
        $this->assertSame(
            'pgsql',
            config('database.default'),
            'Domyślne połączenie aplikacji w tym przebiegu nie jest pgsql.',
        );
    }

    /**
     * CI stawia PostgreSQL 18 pod KAŻDYM zadaniem, które rusza bazę,
     * a zadanie z testami nie jest w stanie przejść bez niego.
     *
     * Czytane z pliku, bo w CI mierzyłby to ten sam przebieg, który jest
     * mierzony — a „zadanie potwierdza samo siebie" nie jest pomiarem.
     */
    public function test_ci_stawia_postgresa_18_pod_testami(): void
    {
        $naruszenia = $this->naruszeniaWorkflow($this->trescWorkflow());

        $this->assertSame([], $naruszenia, implode("\n", $naruszenia));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  3. KONTROLA DODATNIA (PULAPKI_TESTOW.md §2)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Dowód, że dwa powyższe skany naprawdę czytają pliki i naprawdę
     * orzekają — a nie przechodzą na pustce.
     *
     * Trzy warstwy, każda zamyka inny sposób na ciche zero:
     *
     *  1. progi ilościowe na prawdziwych plikach (gdyby `file_get_contents`
     *     zwrócił pustkę albo ktoś przeniósł plik, progi padną),
     *  2. kotwice — napisy, które w tych plikach po prostu są,
     *  3. PODSTAWIONE, ZEPSUTE treści przepuszczone przez TE SAME funkcje
     *     orzekające; każda musi zgłosić naruszenie.
     *
     * Warstwa 3 jest tu najważniejsza: progi i kotwice dowodzą, że coś
     * przeczytaliśmy, ale dopiero ona dowodzi, że umiemy powiedzieć „nie".
     */
    public function test_skan_naprawde_czyta_konfiguracje_i_oblewa_na_podstawionej(): void
    {
        $phpunit = $this->trescPhpunit();
        $workflow = $this->trescWorkflow();

        // ── 1 i 2: naprawdę mamy w ręku te pliki, nie pustkę.
        $this->assertGreaterThan(2000, strlen($phpunit), 'phpunit.xml wygląda na pusty albo nie ten.');
        $this->assertGreaterThan(20000, strlen($workflow), 'ci.yml wygląda na pusty albo nie ten.');

        $this->assertGreaterThanOrEqual(
            10,
            substr_count($phpunit, '<env name='),
            'phpunit.xml ma podejrzanie mało wpisów <env> — skan czyta coś innego niż trzeba.',
        );
        $this->assertGreaterThanOrEqual(
            8,
            count($this->jobyWorkflow($workflow)),
            'W ci.yml widać za mało zadań — regexp rozbijający plik na zadania przestał działać.',
        );
        $this->assertGreaterThanOrEqual(
            4,
            substr_count($workflow, 'image: postgres:'),
            'W ci.yml widać za mało usług PostgreSQL — skan czyta coś innego niż trzeba.',
        );
        $this->assertArrayHasKey('test', $this->jobyWorkflow($workflow));

        // ── 3: kontrola dodatnia właściwa. Te same funkcje, zepsute wejście.
        $this->assertNotSame(
            [],
            $this->naruszeniaPhpunit(str_replace(
                '<env name="DB_CONNECTION" value="pgsql"/>',
                '<env name="DB_CONNECTION" value="sqlite"/>',
                $phpunit,
            )),
            'Skan phpunit.xml przepuścił jawne przestawienie połączenia na SQLite.',
        );

        $this->assertNotSame(
            [],
            $this->naruszeniaPhpunit(str_replace(
                '<env name="DB_CONNECTION" value="pgsql"/>',
                '',
                $phpunit,
            )),
            'Skan phpunit.xml przepuścił USUNIĘCIE wiersza DB_CONNECTION — '
            .'a to jest cichy zjazd na domyślne sqlite z config/database.php.',
        );

        $this->assertNotSame(
            [],
            $this->naruszeniaPhpunit('<phpunit><php></php></phpunit>'),
            'Skan phpunit.xml uznał plik bez żadnego <env> za poprawny.',
        );

        $this->assertNotSame(
            [],
            $this->naruszeniaWorkflow(str_replace('image: postgres:18-alpine', 'image: postgres:14-alpine', $workflow)),
            'Skan ci.yml przepuścił zjazd usługi bazy na starszego majora.',
        );

        $this->assertNotSame(
            [],
            $this->naruszeniaWorkflow(str_replace('DB_CONNECTION: pgsql', 'DB_CONNECTION: sqlite', $workflow)),
            'Skan ci.yml przepuścił przestawienie połączenia zadania testów na SQLite.',
        );

        $this->assertNotSame(
            [],
            $this->naruszeniaWorkflow("jobs:\n  lint:\n    runs-on: ubuntu-latest\n"),
            'Skan ci.yml uznał workflow BEZ zadania z testami za poprawny — '
            .'to jest dokładnie pułapka 2: brak trafień jako sukces.',
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  FUNKCJE ORZEKAJĄCE — osobno, żeby kontrola dodatnia mogła podstawić
    //  im zepsute wejście. Zwracają LISTĘ naruszeń, nie true/false: pusta
    //  lista znaczy „czysto", a niepusta sama mówi, co jest nie tak.
    // ═══════════════════════════════════════════════════════════════════

    /** @return list<string> */
    private function naruszeniaPhpunit(string $xml): array
    {
        $naruszenia = [];

        $trafienia = [];
        preg_match_all(
            '/<env\s+name="DB_CONNECTION"\s+value="([^"]*)"/',
            $xml,
            $trafienia,
        );

        $wartosci = $trafienia[1];

        if ($wartosci === []) {
            $naruszenia[] = 'phpunit.xml nie ustawia DB_CONNECTION. Bez tego wiersza '
                .'`php artisan test` bez wyeksportowanej zmiennej idzie na domyślne '
                ."'sqlite' z config/database.php — po cichu, bez jednej czerwieni.";

            return $naruszenia;
        }

        foreach ($wartosci as $wartosc) {
            if ($wartosc !== 'pgsql') {
                $naruszenia[] = 'phpunit.xml ustawia DB_CONNECTION na "'.$wartosc.'", nie "pgsql".';
            }
        }

        return $naruszenia;
    }

    /** @return list<string> */
    private function naruszeniaWorkflow(string $yaml): array
    {
        $naruszenia = [];
        $joby = $this->jobyWorkflow($yaml);

        // Każda postawiona usługa bazy musi być tym majorem, co produkcja.
        foreach ($joby as $nazwa => $tresc) {
            if (! str_contains($tresc, 'image: postgres:')) {
                continue;
            }

            if (preg_match('/image:\s*postgres:18(\D|$)/m', $tresc) !== 1) {
                $naruszenia[] = 'Zadanie CI "'.$nazwa.'" stawia inną wersję PostgreSQL niż 18.';
            }
        }

        // Zadanie, które uruchamia suitę, musi istnieć i mieć własną bazę.
        $zTestami = [];

        foreach ($joby as $nazwa => $tresc) {
            if (preg_match('/artisan test\b/', $tresc) === 1) {
                $zTestami[$nazwa] = $tresc;
            }
        }

        if ($zTestami === []) {
            $naruszenia[] = 'W ci.yml nie ma ANI JEDNEGO zadania uruchamiającego `artisan test`. '
                .'Albo testy wypadły z CI, albo skan przestał je rozpoznawać — '
                .'obie możliwości są porażką, żadna nie jest sukcesem.';

            return $naruszenia;
        }

        foreach ($zTestami as $nazwa => $tresc) {
            if (! str_contains($tresc, 'image: postgres:')) {
                $naruszenia[] = 'Zadanie CI "'.$nazwa.'" uruchamia testy, ale nie stawia usługi PostgreSQL.';
            }

            if (preg_match('/DB_CONNECTION:\s*pgsql/', $tresc) !== 1) {
                $naruszenia[] = 'Zadanie CI "'.$nazwa.'" uruchamia testy bez DB_CONNECTION: pgsql.';
            }

            if (preg_match('/DB_CONNECTION:\s*sqlite/', $tresc) === 1) {
                $naruszenia[] = 'Zadanie CI "'.$nazwa.'" ustawia DB_CONNECTION na sqlite.';
            }

            // `continue-on-error` zamienia czerwień w ostrzeżenie. Zadanie,
            // które może paść bez konsekwencji, nie jest strażnikiem niczego.
            if (str_contains($tresc, 'continue-on-error: true')) {
                $naruszenia[] = 'Zadanie CI "'.$nazwa.'" ma continue-on-error — jego porażka niczego nie blokuje.';
            }
        }

        return $naruszenia;
    }

    /**
     * Rozbija workflow na zadania: nazwa => treść, bez komentarzy.
     *
     * Komentarze lecą, bo `ci.yml` jest w tym repozytorium w połowie
     * dokumentacją: zdania w rodzaju „SQLite in-memory byłby szybszy"
     * trafiałyby w skan i orzekałyby o czymś, czego w konfiguracji nie ma.
     *
     * Ten sam wzorzec co `PortMarkiMaWlasnaBramkeCiTest::job()`, rozszerzony
     * o myślnik w nazwie (`static-analysis`, `dwa-polaczenia`, `docker-build`).
     *
     * @return array<string, string>
     */
    private function jobyWorkflow(string $yaml): array
    {
        $bezKomentarzy = (string) preg_replace('/^\s*#.*$/m', '', $yaml);

        $trafienia = [];
        preg_match_all(
            '/^  ([a-z0-9_-]+):\R(.*?)(?=^  [a-z0-9_-]+:|\z)/ms',
            $bezKomentarzy,
            $trafienia,
            PREG_SET_ORDER,
        );

        $joby = [];

        foreach ($trafienia as $trafienie) {
            $joby[$trafienie[1]] = $trafienie[2];
        }

        return $joby;
    }

    private function trescPhpunit(): string
    {
        return $this->plik(base_path('phpunit.xml'));
    }

    private function trescWorkflow(): string
    {
        return $this->plik(base_path('.github/workflows/ci.yml'));
    }

    /**
     * Czyta plik i ODMAWIA cicho zwrócenia pustki.
     *
     * Bez tego cała reszta strażnika przechodziłaby na nieistniejącym pliku
     * — pułapka 2 w najczystszej postaci.
     */
    private function plik(string $sciezka): string
    {
        $this->assertFileExists($sciezka, 'Strażnik R60 nie znalazł pliku, o który pyta: '.$sciezka);

        $tresc = file_get_contents($sciezka);

        $this->assertIsString($tresc);
        $this->assertNotSame('', $tresc, 'Plik jest pusty: '.$sciezka);

        return $tresc;
    }
}
