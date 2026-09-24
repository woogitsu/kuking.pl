<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Tests\TestCase;

/**
 * Klasa bazowa grupy `dwa-polaczenia` (D-105).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  PO CO TA GRUPA ISTNIEJE
 * ══════════════════════════════════════════════════════════════════════
 *
 * Do 11.09.2026 żaden z 2854 testów tego repozytorium nie chodził na dwóch
 * połączeniach do PostgreSQL. `RefreshDatabase` trzyma każdy test
 * w transakcji, której nigdy nie zatwierdza, więc drugie połączenie nie
 * widzi ani jednego wiersza pierwszego — a to znaczy, że **zakleszczenia
 * i odwrócone kolejności blokad są dla całego zestawu niewidzialne**
 * (`docs/PULAPKI_TESTOW.md` §6). Dwa zakleszczenia z tej rodziny (Z-2/D-093
 * i Z-3) zostały naprawione 10.09.2026, a obie naprawy zmierzono RĘCZNIE,
 * poza zestawem testów, i obaj agenci napisali wprost, że ich testy tego
 * nie dowodzą. Ta grupa zamienia to rozumowanie w pomiar.
 *
 * Pełne uzasadnienie, koszt i granice: `docs/DECISIONS.md` D-105 oraz
 * rozdział 7 `docs/research/2026-09-10-kolejnosc-blokad.md`.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  SZEŚĆ ZASAD, BEZ KTÓRYCH TO NIE DZIAŁA
 * ══════════════════════════════════════════════════════════════════════
 *
 * 1. **Bez `RefreshDatabase`.** Dane są zatwierdzane naprawdę — inaczej
 *    drugi uczestnik wyścigu nie zobaczy kont, na których ma się ścigać.
 *
 * 2. **Własna baza na przebieg** — `kuking_race_<worktree>`, liczona tą samą
 *    metodą co nazwa bazy testowej (`kuking_nazwa_bazy_wyscigow()`
 *    w `tests/bootstrap.php`). Puszczenie tego na `kuking_test_*` byłoby
 *    powtórzeniem issue #66: równoległy zwykły przebieg zrzuca schemat
 *    w trakcie. `setUp()` niżej ODMAWIA startu, gdy nazwa bazy nie zaczyna
 *    się od `kuking_race` — to jedyny bezpiecznik między tą grupą a cudzą
 *    pracą, więc jest twardy, a nie ostrzegawczy.
 *
 * 3. **Sprzątanie po identyfikatorach**, nie `truncate`. Przy błędzie
 *    w izolacji `truncate` zabiera dane innego przebiegu — a te testy
 *    zatwierdzają naprawdę, więc nie ma transakcji, która by je cofnęła.
 *
 * 4. **Twardy `lock_timeout` i `statement_timeout` na KAŻDYM połączeniu**,
 *    łącznie z połączeniami procesów potomnych. Bez tego pierwszy błąd
 *    w teście wiesza cały przebieg CI, bo blokady trzymanej przez porzuconą
 *    transakcję nie ma kto zdjąć (zmierzone przy audycie, pomiar E4c).
 *    Do tego `idle_in_transaction_session_timeout` na barierach: bariera
 *    JEST porzuconą transakcją, jeśli test padnie między jej założeniem
 *    a zwolnieniem.
 *
 * 5. **Naprawdę osobne połączenia.** `pg_connect()` bez
 *    `PGSQL_CONNECT_FORCE_NEW` zwraca dla tego samego ciągu TO SAMO
 *    połączenie — na tym przewrócił się pierwszy pomiar audytu z 10.09,
 *    w którym jawny `FOR UPDATE` „nikogo nie blokował". Tutaj każde
 *    połączenie to osobna instancja `PDO` (PDO nie współdzieli połączeń,
 *    o ile nie prosi się o `ATTR_PERSISTENT` — i dlatego `ATTR_PERSISTENT`
 *    jest tu jawnie wyłączone), a uczestnicy wyścigów o kolejność blokad to
 *    osobne PROCESY, więc mają własne połączenia z definicji. Że to
 *    naprawdę są różne backendy, sprawdza `sprawdzMechanizmWykrywania()` —
 *    zasada 5 bez tego pomiaru jest deklaracją, a nie faktem.
 *
 * 6. **Kontrola POZYTYWNA w każdym teście.** `sprawdzMechanizmWykrywania()`
 *    niżej jest wołana z `setUp()` i sprawdza, czy blokada założona na
 *    jednym połączeniu JEST widziana z drugiego. Bez niej cała grupa
 *    przechodzi także wtedy, gdy nie mierzy niczego — ten sam błąd, co
 *    „test skanujący pliki, który nie znalazł żadnego pliku"
 *    (`docs/PULAPKI_TESTOW.md` §2). Każdy test dokłada do tego własną
 *    kontrolę dodatnią na to, że mierzona operacja NAPRAWDĘ się wykonała.
 *
 * ── CZEGO TA GRUPA NIE DOWODZI ──
 *
 * Że kod jest wolny od zakleszczeń w OGÓLE. Każdy test odtwarza JEDEN
 * przeplot, wymuszony barierą — ten, który był zmierzoną usterką. Inny
 * przeplot na tych samych tabelach wymaga innego testu. Grupa jest
 * narzędziem do zamiany konkretnego rozumowania w konkretny pomiar, a nie
 * dowodem ogólnym.
 */
abstract class TestDwochPolaczen extends TestCase
{
    /**
     * Ile czekamy na blokadę, zanim uznamy przebieg za zawieszony.
     *
     * Musi być DŁUŻSZE niż czas trzymania bariery (ułamki sekundy) i
     * KRÓTSZE niż cierpliwość CI. Zakleszczenie wykrywa się szybciej —
     * `deadlock_timeout` PostgreSQL to domyślnie 1 s.
     */
    protected const LOCK_TIMEOUT = '10s';

    protected const STATEMENT_TIMEOUT = '30s';

    /** Ile sekund czekamy, aż uczestnik wyścigu ustawi się w kolejce po blokadę. */
    protected const SEKUNDY_NA_KOLEJKE = 15;

    protected string $baza;

    /** Połączenie do podglądania, kto na co czeka — nigdy nie bierze blokad. */
    protected PDO $obserwator;

    /** @var list<PDO> */
    private array $polaczenia = [];

    /** @var list<ProcesRownolegly> */
    private array $procesy = [];

    /** @var list<string> Identyfikatory kont utworzonych w teście — do sprzątania. */
    protected array $konta = [];

    protected function setUp(): void
    {
        $this->baza = kuking_nazwa_bazy_wyscigow(__DIR__.'/../..');

        // Ustawiane PRZED `parent::setUp()`, bo to tam powstaje aplikacja
        // i tam `config/database.php` czyta `env('DB_DATABASE')`. Dotenv
        // Laravela jest niemutowalny, więc `.env` tego nie nadpisze.
        putenv('DB_DATABASE='.$this->baza);
        $_ENV['DB_DATABASE'] = $this->baza;
        $_SERVER['DB_DATABASE'] = $this->baza;

        parent::setUp();

        $this->odmowJesliPozaGrupa();

        $nazwa = DB::connection()->getDatabaseName();

        // ZASADA 2, TWARDO. Ta grupa zatwierdza dane naprawdę i zakłada
        // blokady — puszczona na cudzą bazę potrafi zawiesić czyjś przebieg.
        if (! is_string($nazwa) || ! str_starts_with($nazwa, 'kuking_race')) {
            $this->fail(
                'Grupa `dwa-polaczenia` odmawia startu: połączenie wskazuje na bazę "'
                .(is_string($nazwa) ? $nazwa : '?').'", a wolno jej wyłącznie na `kuking_race_*`. '
                .'Uruchom ją przez `./scripts/testy-dwa-polaczenia.sh`.',
            );
        }

        DB::statement("SET lock_timeout = '".self::LOCK_TIMEOUT."'");
        DB::statement("SET statement_timeout = '".self::STATEMENT_TIMEOUT."'");

        $this->obserwator = $this->nowePolaczenie();

        $this->sprawdzMechanizmWykrywania();
    }

    protected function tearDown(): void
    {
        foreach ($this->procesy as $proces) {
            $proces->zabij();
        }
        $this->procesy = [];

        foreach ($this->polaczenia as $polaczenie) {
            try {
                if ($polaczenie->inTransaction()) {
                    $polaczenie->rollBack();
                }
            } catch (PDOException) {
                // Połączenie mogło paść razem z testem — sprzątanie nie ma
                // prawa zasłonić prawdziwego błędu własnym wyjątkiem.
            }
        }
        $this->polaczenia = [];

        $this->posprzatajKonta();

        parent::tearDown();
    }

    /**
     * ZASADA 2 NA POZIOMIE ZESTAWU: test bez `#[Group('dwa-polaczenia')]`
     * wjechałby do zwykłego `php artisan test`.
     *
     * A tam nie ma bazy `kuking_race_*`, więc oblałby się — tylko że
     * oblałby CUDZY przebieg, cudzą zmianą i komunikatem o nazwie bazy.
     * Lepiej powiedzieć wprost, czego brakuje, i powiedzieć to autorowi
     * nowego testu przy pierwszym uruchomieniu.
     */
    private function odmowJesliPozaGrupa(): void
    {
        $grupy = [];

        foreach ((new ReflectionClass($this))->getAttributes(Group::class) as $atrybut) {
            $grupy[] = (string) ($atrybut->getArguments()[0] ?? '');
        }

        if (! in_array('dwa-polaczenia', $grupy, true)) {
            $this->fail(
                'Klasa '.static::class.' dziedziczy po TestDwochPolaczen, ale nie ma atrybutu '
                ."#[Group('dwa-polaczenia')] — bez niego wjedzie do zwykłego `php artisan test`, "
                .'który nie ma bazy wyścigów. Dopisz atrybut nad klasą.',
            );
        }
    }

    /**
     * Kasuje WYŁĄCZNIE wiersze kont utworzonych w tym teście (zasada 3).
     *
     * Kolejność jest wymuszona przez klucze obce: `dziennik_zgod` ma
     * `ON DELETE RESTRICT` (dowód zgody nie znika razem z kontem, D-072),
     * a `audit_log.actor_id` ma `ON DELETE SET NULL` — czyli wiersz zostałby
     * po teście jako sierota. Reszta idzie kaskadą z `users`.
     *
     * `dziennik_zgod` jest przy tym APPEND-ONLY, pilnowanym wyzwalaczem
     * (D-072), i to zabezpieczenie ma zostać nietknięte — więc sprzątaczka
     * wyłącza wyzwalacze WYŁĄCZNIE NA SWOIM POŁĄCZENIU
     * (`session_replication_role`), wyłącznie na czas tego jednego `DELETE`
     * i wyłącznie w bazie `kuking_race_*`. Zaraz potem wraca do `origin`, bo
     * dalsze kasowanie MUSI odpalić kaskady kluczy obcych — inaczej
     * zostawiłoby po sobie sieroty w kilkunastu tabelach.
     */
    private function posprzatajKonta(): void
    {
        if ($this->konta === []) {
            return;
        }

        $identyfikatory = '{'.implode(',', $this->konta).'}';
        $konta = $this->konta;
        $this->konta = [];

        try {
            $sprzataczka = $this->nowePolaczenie();

            try {
                $sprzataczka->exec("SET session_replication_role = 'replica'");
                $sprzataczka->prepare('DELETE FROM dziennik_zgod WHERE user_id = ANY(?::uuid[])')
                    ->execute([$identyfikatory]);
            } finally {
                $sprzataczka->exec("SET session_replication_role = 'origin'");
            }

            $sprzataczka->prepare('DELETE FROM audit_log WHERE actor_id = ANY(?::uuid[]) OR subject_id = ANY(?::uuid[])')
                ->execute([$identyfikatory, $identyfikatory]);
            $sprzataczka->prepare('DELETE FROM potwierdzenia_zadan_rodo WHERE konto_id = ANY(?::uuid[])')
                ->execute([$identyfikatory]);
            $sprzataczka->prepare('DELETE FROM sessions WHERE user_id = ANY(?::uuid[])')->execute([$identyfikatory]);
            $sprzataczka->prepare('DELETE FROM users WHERE id = ANY(?::uuid[])')->execute([$identyfikatory]);

            $zostalo = $sprzataczka->prepare('SELECT count(*) FROM users WHERE id = ANY(?::uuid[])');
            $zostalo->execute([$identyfikatory]);

            if ((int) $zostalo->fetchColumn() !== 0) {
                fwrite(STDERR, "\nPo teście zostały w bazie ".$this->baza.' konta: '.implode(', ', $konta)."\n");
            }
        } catch (PDOException $e) {
            // Świadomie NIE rzucamy dalej: nieposprzątany wiersz w bazie
            // wyścigów jest kłopotem następnego przebiegu, a przesłonięcie
            // wyniku testu wyjątkiem ze sprzątania jest kłopotem od razu.
            fwrite(STDERR, "\nSprzątanie po teście nie powiodło się: ".$e->getMessage()."\n");
        }
    }

    /**
     * Konto zapisane do posprzątania — każdy test tworzy konta TĄ metodą.
     *
     * Nazwa konta jest losowa z tego samego powodu, dla którego baza jest
     * osobna: dane z poprzedniego przebiegu ZOSTAJĄ (nie ma transakcji, która
     * by je cofnęła), a stała nazwa zderzyłaby się z nimi na unikalności
     * i oblała test z powodu, który nie ma nic wspólnego z blokadami.
     *
     * @param  array<string, mixed>  $atrybuty
     */
    protected function konto(array $atrybuty = []): User
    {
        $user = $this->user('w'.bin2hex(random_bytes(6)), $atrybuty);
        $this->konta[] = (string) $user->getKey();

        return $user;
    }

    /**
     * Para kont uporządkowana rosnąco po identyfikatorze — tak jak
     * porządkuje `ZamekPary` i `EraseAccountData::usunRelacjeWKolejnosciDanych()`.
     *
     * Zwraca `[mniejsze, większe]`. Kolejność jest w tych testach istotna:
     * wyznacza, który wiersz jest brany PIERWSZY, a który DRUGI, czyli gdzie
     * ma stanąć bariera.
     *
     * @return array{0: User, 1: User}
     */
    protected function paraPosortowana(): array
    {
        $a = $this->konto();
        $b = $this->konto();

        return strcmp((string) $a->getKey(), (string) $b->getKey()) < 0 ? [$a, $b] : [$b, $a];
    }

    /**
     * Nowe, NAPRAWDĘ osobne połączenie do bazy wyścigów (zasada 5).
     *
     * `new PDO` z tym samym ciągiem daje osobne połączenie — inaczej niż
     * `pg_connect()`, które bez `PGSQL_CONNECT_FORCE_NEW` zwraca to samo
     * i o mało nie unieważniło całego audytu z 10.09.2026. `ATTR_PERSISTENT`
     * jest tu zakazane z tego samego powodu.
     */
    protected function nowePolaczenie(): PDO
    {
        /** @var array{host: string, port: string|int, username: string, password: string} $config */
        $config = config('database.connections.pgsql');

        $polaczenie = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $this->baza),
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_PERSISTENT => false,
            ],
        );

        // ZASADA 4 na każdym połączeniu bez wyjątku.
        $polaczenie->exec("SET lock_timeout = '".self::LOCK_TIMEOUT."'");
        $polaczenie->exec("SET statement_timeout = '".self::STATEMENT_TIMEOUT."'");
        $polaczenie->exec("SET idle_in_transaction_session_timeout = '".self::STATEMENT_TIMEOUT."'");

        $this->polaczenia[] = $polaczenie;

        return $polaczenie;
    }

    /**
     * KONTROLA POZYTYWNA CAŁEJ METODY (zasada 6).
     *
     * Sprawdza dwie rzeczy, obie w tym samym miejscu, bo obie są warunkiem
     * sensowności każdego testu w tej grupie:
     *
     *  1. dwa połączenia to dwa RÓŻNE backendy PostgreSQL (różne `pid`),
     *  2. blokada założona na pierwszym JEST WIDZIANA jako konflikt
     *     z drugiego.
     *
     * Punkt 2 nie wynika z punktu 1 i odwrotnie: można mieć dwa backendy
     * i mierzyć konflikt, którego nie ma (zły klucz), albo jeden backend
     * i „zmierzyć" brak konfliktu, którego nie da się zobaczyć. Blokada
     * doradcza (`pg_advisory_xact_lock`) nadaje się tu lepiej niż wiersz
     * `users`, bo nie wymaga żadnych danych i jest w tej samej mechanice
     * kolejkowania co blokady wierszy — a przy JEDNYM połączeniu jest
     * wznawialna, więc `pg_try_advisory_xact_lock` zwróciłoby `true`
     * i kontrola oblałaby się głośno, zamiast przepuścić fałszywą zieleń.
     *
     * To jest dokładnie ta kontrola, którą zmierzono przy zakładaniu tej
     * grupy (`docs/PULAPKI_TESTOW.md` §7): po zepięciu obu „połączeń"
     * w jedno wszystkie trzy testy przechodzą MIMO zepsutego kodu.
     */
    private function sprawdzMechanizmWykrywania(): void
    {
        $klucz = random_int(1, 2_000_000_000);

        $pierwsze = $this->nowePolaczenie();
        $drugie = $this->nowePolaczenie();

        $pidPierwszego = $pierwsze->query('SELECT pg_backend_pid()')?->fetchColumn();
        $pidDrugiego = $drugie->query('SELECT pg_backend_pid()')?->fetchColumn();

        $this->assertNotSame(
            $pidPierwszego,
            $pidDrugiego,
            'Oba „połączenia" siedzą na tym samym backendzie PostgreSQL — cała grupa dawałaby '
            .'fałszywe zielone (zasada 5, `docs/PULAPKI_TESTOW.md` §7).',
        );

        $pierwsze->beginTransaction();
        $zdobyte = $pierwsze->query('SELECT pg_advisory_xact_lock('.$klucz.')');
        $this->assertNotFalse($zdobyte, 'Pierwsze połączenie nie zdołało założyć blokady doradczej.');

        $wynik = $drugie->query('SELECT pg_try_advisory_xact_lock('.$klucz.')')?->fetchColumn();

        $pierwsze->rollBack();

        $this->assertFalse(
            $wynik === true || $wynik === 't' || $wynik === '1',
            'Blokada założona na pierwszym połączeniu NIE JEST widziana z drugiego — mechanizm '
            .'wykrywania nie działa, więc każdy zielony wynik w tej grupie byłby bez wartości '
            .'(zasada 6).',
        );
    }

    /**
     * Zakłada barierę: otwiera transakcję na osobnym połączeniu i trzyma
     * wskazany wiersz pod `FOR UPDATE`, aż do `zwolnijBariere()`.
     *
     * Bariera zastępuje to, czego w PHPUnicie nie ma — możliwość zatrzymania
     * cudzego procesu w wybranym miejscu. Uczestnik wyścigu, który sięgnie
     * po ten wiersz, staje w kolejce; my w tym czasie ustawiamy w kolejce
     * drugiego, w znanej kolejności. Dopiero zwolnienie bariery puszcza obu
     * — a to, że kolejka po blokadę w PostgreSQL jest obsługiwana W KOLEJNOŚCI
     * ZGŁOSZEŃ, jest jedynym powodem, dla którego ten przeplot jest
     * powtarzalny, a nie wylosowany.
     *
     * @param  list<string>  $parametry
     */
    protected function bariera(string $sql, array $parametry): PDO
    {
        $polaczenie = $this->nowePolaczenie();
        $polaczenie->beginTransaction();

        $zapytanie = $polaczenie->prepare($sql);
        $zapytanie->execute($parametry);

        $this->assertNotSame(
            0,
            $zapytanie->rowCount(),
            'Bariera nie trafiła w żaden wiersz — test mierzyłby wtedy przeplot, którego nie ma. '
            .'To jest ta sama pułapka co „skan, który nie znalazł żadnego pliku".',
        );

        return $polaczenie;
    }

    protected function zwolnijBariere(PDO $bariera): void
    {
        $bariera->rollBack();
    }

    /**
     * Czeka, aż CO NAJMNIEJ tylu uczestników stoi w kolejce po blokadę.
     *
     * To jest jedyna synchronizacja w tych testach: nie zgadujemy czasu
     * (`sleep`), tylko pytamy bazę, czy przeplot naprawdę się ustawił.
     * Test z `sleep` byłby dokładnie tym migającym alarmem, przed którym
     * ostrzega rozdział 7 audytu.
     */
    protected function czekajNaZablokowane(int $ilu): void
    {
        $koniec = microtime(true) + self::SEKUNDY_NA_KOLEJKE;
        $ostatnio = -1;

        while (microtime(true) < $koniec) {
            $ostatnio = $this->ilu();

            if ($ostatnio >= $ilu) {
                return;
            }

            usleep(20_000);
        }

        $this->fail(
            'Po '.self::SEKUNDY_NA_KOLEJKE." s w kolejce po blokadę stoi {$ostatnio} uczestników, "
            ."a miało stać {$ilu}. Przeplot się nie ustawił — wynik testu nic by nie znaczył.",
        );
    }

    /** Ilu uczestników w bazie wyścigów czeka w tej chwili na blokadę. */
    private function ilu(): int
    {
        $zapytanie = $this->obserwator->query(
            "SELECT count(*) FROM pg_stat_activity
             WHERE datname = current_database()
               AND pid <> pg_backend_pid()
               AND wait_event_type = 'Lock'",
        );

        return $zapytanie === false ? 0 : (int) $zapytanie->fetchColumn();
    }

    /**
     * Uruchamia PRAWDZIWY kod domenowy w osobnym procesie.
     *
     * Osobny proces, a nie drugie połączenie w tym samym — bo test ma
     * mierzyć `EraseAccountData`, `FollowUser` i `BlockUser`, a nie SQL
     * przepisany z tych klas do testu. Przepisany SQL zostaje zielony
     * także wtedy, gdy ktoś zmieni kod: mierzyłby wtedy sam siebie.
     *
     * @param  array<string, string>  $argumenty
     */
    protected function wTle(string $scenariusz, array $argumenty): ProcesRownolegly
    {
        $proces = ProcesRownolegly::start(
            __DIR__.'/bin/scenariusz.php',
            $scenariusz,
            $argumenty,
            [
                'DB_DATABASE' => $this->baza,
                'APP_ENV' => 'testing',
                'BCRYPT_ROUNDS' => '4',
                'MAIL_MAILER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'KUKING_LOCK_TIMEOUT' => self::LOCK_TIMEOUT,
                'KUKING_STATEMENT_TIMEOUT' => self::STATEMENT_TIMEOUT,
            ],
        );

        $this->procesy[] = $proces;

        return $proces;
    }

    /**
     * Asercja właściwa całej grupie: PostgreSQL nie zabił tej operacji jako
     * ofiary zakleszczenia.
     *
     * `40P01` to `deadlock_detected`. Sprawdzamy KOD, a nie tekst
     * komunikatu — tekst zależy od lokalizacji serwera, a kod nie.
     *
     * @param  array{ok: bool, sqlstate: ?string, komunikat: string, wartosc: mixed, wyjatek: ?string}  $wynik
     */
    protected function assertBezZakleszczenia(array $wynik, string $ktoTo): void
    {
        $this->assertNotSame(
            '40P01',
            $wynik['sqlstate'] ?? null,
            "Zakleszczenie (40P01) przy: {$ktoTo}.\n".($wynik['komunikat'] ?? ''),
        );
    }
}
