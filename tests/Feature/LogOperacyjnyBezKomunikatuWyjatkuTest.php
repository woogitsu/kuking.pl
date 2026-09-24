<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\KasujZdjecie;
use App\Jobs\NotifyUserExportReady;
use App\Logging\BezpiecznyBlad;
use App\Models\DataExport;
use App\Models\MailFailure;
use App\Models\Media;
use App\Models\User;
use App\Poczta\ZapiszNieudanyList;
use App\Support\PhpIniRozmiar;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;
use Throwable;

/**
 * Logi OPERACYJNE (sprzątanie, media, eksport, retencja, /health) nie niosą
 * komunikatu obcego wyjątku — #973, rozszerzenie #828.
 *
 * Komunikat buduje sterownik bazy, klient storage, dekoder albo klient HTTP.
 * Niesie SQL z wartościami, adres żądania z kluczem obiektu, token w adresie,
 * CR/LF. Stderr czyta Railway. Zamiast komunikatu idzie
 * `BezpiecznyBlad::kontekst()`: klasa, kod o zamkniętym kształcie, klasy
 * przyczyn, odcisk. Identyfikator encji dokłada każde wywołanie.
 */
final class LogOperacyjnyBezKomunikatuWyjatkuTest extends TestCase
{
    use RefreshDatabase;

    /** Wszystko, czego w logu być nie może — naraz, w jednym komunikacie. */
    private const ZLY_KOMUNIKAT = "Error executing DeleteObject on https://konto.r2.example/media/basia.jpg?X-Amz-Signature=deadbeef123&token=sekret-abc\r\n"
        .'[2026-09-23] production.CRITICAL: wstrzyknięty wpis basia@example.com';

    /** Hash hasła w kształcie bcrypt — tak wygląda w SQL-u z audytu A6-01. */
    private const HASH = '$2y$12$Qw3rTyUi0pAsDfGhJkLzXeVbNm1234567890abcdefghijklmnopq';

    /** @return list<string> */
    private function zakazane(): array
    {
        return [
            'basia@example.com', 'X-Amz-Signature', 'deadbeef123', 'sekret-abc', 'r2.example', "\r", 'wstrzyknięty',
            self::HASH, '$2y$', 'insert into', 'Failing row', 'already exists',
        ];
    }

    /**
     * Dwa rodzaje obcego wyjątku z kryteriów #973, każdy przez każdą
     * sprawdzaną ścieżkę: prawdziwy `QueryException` z PostgreSQL (SQL
     * z adresem i hashem, DETAIL z wartościami) i wyjątek storage/HTTP
     * (podpisany URL, token, CR/LF, wstrzyknięty rekord, adres).
     *
     * Wszystkie przepięte miejsca w `app/` idą przez `BezpiecznyBlad::kontekst()`
     * — tu są cztery reprezentatywne (media, sprzątanie, poczta paczki,
     * ślad nieudanego listu) plus sama funkcja; że NIKT nie omija funkcji,
     * pilnuje skaner niżej.
     *
     * @return array<string, array{Closure(): Throwable}>
     */
    public static function obceWyjatki(): array
    {
        return [
            'QueryException z PostgreSQL: SQL, adres, hash' => [static fn (): Throwable => self::zapytanieZWartosciami()],
            'storage/HTTP: podpisany URL, token, CR/LF, adres' => [static fn (): Throwable => new RuntimeException(self::ZLY_KOMUNIKAT)],
        ];
    }

    /**
     * Prawdziwy `QueryException` z bazy testowej (PostgreSQL), nie atrapa:
     * zdublowany adres w `users`. Savepoint (`DB::transaction` wewnątrz
     * transakcji `RefreshDatabase`) cofa przerwaną transakcję, więc test
     * idzie dalej na tej samej bazie.
     */
    private static function zapytanieZWartosciami(): QueryException
    {
        if (! User::query()->where('email', 'basia@example.com')->exists()) {
            User::factory()->create(['email' => 'basia@example.com']);
        }

        try {
            DB::transaction(static fn () => DB::insert(
                'insert into users (email, password) values (?, ?)',
                ['basia@example.com', self::HASH],
            ));
        } catch (QueryException $e) {
            // KONTROLA DODATNIA atrapy: komunikat naprawdę niesie wartości
            // — inaczej „nie ma ich w logu" nic by nie dowodziło.
            if (! str_contains($e->getMessage(), 'basia@example.com') || ! str_contains($e->getMessage(), self::HASH)) {
                throw new RuntimeException('QueryException bez wartości w komunikacie — atrapa nic nie sprawdza.');
            }

            return $e;
        }

        throw new RuntimeException('Oczekiwano QueryException z PostgreSQL.');
    }

    private function assertBezZakazanych(array $kontekst): void
    {
        $caly = json_encode($kontekst, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ($this->zakazane() as $fraza) {
            $this->assertStringNotContainsString($fraza, (string) $caly);
        }
    }

    public function test_kontekst_ma_klase_kod_przyczyny_i_odcisk_a_nie_komunikat(): void
    {
        $sterownik = new PDOException('SQLSTATE[23505]: Unique violation: Key (email)=(basia@example.com) already exists.');
        $sterownik->errorInfo = ['23505', 7, 'x'];
        $baza = new QueryException('pgsql', 'insert into users (email) values (?)', ['basia@example.com'], $sterownik);
        $opakowanie = new RuntimeException(self::ZLY_KOMUNIKAT, 0, $baza);

        $kontekst = BezpiecznyBlad::kontekst($opakowanie);

        $this->assertBezZakazanych($kontekst);
        $this->assertSame(RuntimeException::class, $kontekst['wyjatek']);
        $this->assertSame([QueryException::class.' (23505)', PDOException::class.' (23505)'], $kontekst['przyczyny']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $kontekst['odcisk']);
        $this->assertArrayNotHasKey('kod', $kontekst);

        // Ten sam rodzaj awarii → ten sam odcisk (zestawienie z webhookiem).
        $this->assertSame(
            $kontekst['odcisk'],
            substr(sha1(RuntimeException::class.'|'.$opakowanie->getFile().'|'.$opakowanie->getLine()), 0, 8),
        );
    }

    /**
     * Wywołania `BezpiecznyBlad` połykają wyjątek — webhook nie dzwoni, więc
     * z samego odcisku nie da się znaleźć miejsca awarii. Miejsce idzie jako
     * ścieżka względem projektu (to nie dana osobowa), a dla przyczyn —
     * pierwsza ramka z `app/`.
     */
    public function test_kontekst_mowi_gdzie_padl_wyjatek_i_jego_przyczyna(): void
    {
        try {
            PhpIniRozmiar::naBajty('basia@example.com');
            $this->fail('Oczekiwano wyjątku z PhpIniRozmiar.');
        } catch (InvalidArgumentException $zApp) {
        }

        $linia = __LINE__ + 1;
        $opakowanie = new RuntimeException(self::ZLY_KOMUNIKAT, 0, $zApp);

        $kontekst = BezpiecznyBlad::kontekst($opakowanie);

        $this->assertBezZakazanych($kontekst);
        $this->assertSame('tests/Feature/LogOperacyjnyBezKomunikatuWyjatkuTest.php:'.$linia, $kontekst['miejsce']);
        // Rzut spoza `app/`, a stos testu nie przechodzi przez `app/`.
        $this->assertArrayNotHasKey('miejsce_w_app', $kontekst);
        $this->assertSame(['app/Support/PhpIniRozmiar.php:'.$zApp->getLine()], $kontekst['miejsca_przyczyn']);
        $this->assertStringNotContainsString(base_path(), (string) json_encode($kontekst, JSON_UNESCAPED_SLASHES));

        // Odcisk dalej liczy się z pełnej ścieżki — tak jak w webhooku.
        $this->assertSame(
            substr(sha1(RuntimeException::class.'|'.$opakowanie->getFile().'|'.$opakowanie->getLine()), 0, 8),
            $kontekst['odcisk'],
        );
    }

    public function test_kod_o_niebezpiecznym_ksztalcie_jest_pomijany(): void
    {
        $e = new class('x') extends RuntimeException
        {
            public function __construct(string $m)
            {
                parent::__construct($m);
                $this->code = 'basia@example.com';
            }
        };

        $this->assertArrayNotHasKey('kod', BezpiecznyBlad::kontekst($e));
        $this->assertSame(42, BezpiecznyBlad::kontekst(new RuntimeException('x', 42))['kod']);
    }

    #[DataProvider('obceWyjatki')]
    public function test_kontekst_obcego_wyjatku_nie_niesie_jego_wartosci(Closure $wyjatek): void
    {
        $blad = $wyjatek();

        foreach ([$blad, new RuntimeException('opakowanie', 0, $blad)] as $e) {
            $kontekst = BezpiecznyBlad::kontekst($e);

            $this->assertBezZakazanych($kontekst);
            $this->assertSame($e::class, $kontekst['wyjatek']);
        }

        if ($blad instanceof QueryException) {
            // SQLSTATE zostaje — zamknięty kształt, mówi „unikalność", nie „kto".
            $this->assertSame('23505', BezpiecznyBlad::kontekst($blad)['kod']);
        }
    }

    private function dyskKtoryRzuca(string $nazwa, ?Throwable $blad = null): void
    {
        $dysk = Mockery::mock(Filesystem::class);
        $dysk->shouldReceive('exists', 'delete')->andThrow($blad ?? new RuntimeException(self::ZLY_KOMUNIKAT));
        Storage::set($nazwa, $dysk);
    }

    #[DataProvider('obceWyjatki')]
    public function test_kasowanie_zdjecia_loguje_klucz_i_klase_bez_komunikatu(Closure $wyjatek): void
    {
        $blad = $wyjatek();

        config(['kuking.media.disk' => 'public', 'kuking.media.public_disk' => 'public']);

        $zdjecie = Media::factory()->create([
            'disk' => 'public',
            'variants_disk' => 'public',
            'object_key' => 'media/abc/oryginal.jpg',
            'metadata' => ['variants' => []],
        ]);

        $this->dyskKtoryRzuca('public', $blad);
        $dziennik = Log::spy();

        $this->assertFalse(app(KasujZdjecie::class)->skasujPliki($zdjecie));

        $dziennik->shouldHaveReceived('error')
            ->withArgs(function (string $wiadomosc, array $kontekst) use ($zdjecie, $blad): bool {
                if ($wiadomosc !== 'Nie udało się usunąć pliku zdjęcia') {
                    return false;
                }

                $this->assertBezZakazanych($kontekst);
                $this->assertSame($zdjecie->getKey(), $kontekst['media_id']);
                $this->assertSame('media/abc/oryginal.jpg', $kontekst['klucz']);
                $this->assertSame($blad::class, $kontekst['error']['wyjatek']);

                return true;
            })
            ->atLeast()->once();
    }

    #[DataProvider('obceWyjatki')]
    public function test_sprzatanie_wygaslej_paczki_loguje_klucz_i_klase_bez_komunikatu(Closure $wyjatek): void
    {
        $blad = $wyjatek();

        $export = DataExport::create([
            'user_id' => User::factory()->create()->getKey(),
            'status' => DataExport::STATUS_READY,
            'disk' => 'local',
            'object_key' => 'exports/abc.zip',
            'expires_at' => now()->subDay(),
        ]);

        $this->dyskKtoryRzuca('local', $blad);
        $dziennik = Log::spy();

        $this->artisan('kuking:sprzataj-eksporty');

        $dziennik->shouldHaveReceived('error')
            ->withArgs(function (string $wiadomosc, array $kontekst) use ($export, $blad): bool {
                if ($wiadomosc !== 'Nie udało się usunąć wygasłej paczki z danymi') {
                    return false;
                }

                $this->assertBezZakazanych($kontekst);
                $this->assertSame($export->getKey(), $kontekst['data_export_id']);
                $this->assertSame('exports/abc.zip', $kontekst['object_key']);
                $this->assertSame($blad::class, $kontekst['error']['wyjatek']);

                return true;
            })
            ->once();
    }

    /**
     * List „paczka gotowa" nie wyszedł. Dawniej: `BezpiecznyKomunikat::z()`
     * — maskował adres, ale zostawiał SQL, hash i token (#973).
     */
    #[DataProvider('obceWyjatki')]
    public function test_nieudany_list_o_paczce_loguje_eksport_i_klase_bez_komunikatu(Closure $wyjatek): void
    {
        $blad = $wyjatek();

        $export = DataExport::create([
            'user_id' => User::factory()->create(['email' => 'odbiorca@kuking.test'])->getKey(),
            'status' => DataExport::STATUS_READY,
            'disk' => 'local',
            'object_key' => 'exports/abc.zip',
            'completed_at' => now()->subMinute(),
            'expires_at' => now()->addDay(),
        ]);

        Mail::shouldReceive('to')->andThrow($blad);
        $dziennik = Log::spy();

        try {
            (new NotifyUserExportReady((string) $export->getKey()))->handle();
            $this->fail('Nieudany list ma dalej przewrócić zadanie — kolejka ponawia.');
        } catch (RuntimeException $e) {
            // Reakcja domenowa bez zmian: zadanie pada, żeby kolejka ponowiła.
            $this->assertSame('Nie udało się wysłać listu o gotowej paczce z danymi.', $e->getMessage());
        }

        $dziennik->shouldHaveReceived('warning')
            ->withArgs(function (string $wiadomosc, array $kontekst) use ($export, $blad): bool {
                if ($wiadomosc !== 'Paczka z danymi gotowa, ale e-mail nie wyszedł') {
                    return false;
                }

                $this->assertBezZakazanych($kontekst);
                $this->assertStringNotContainsString('odbiorca@kuking.test', (string) json_encode($kontekst));
                $this->assertSame((string) $export->getKey(), (string) $kontekst['data_export_id']);
                $this->assertSame($blad::class, $kontekst['error']['wyjatek']);

                return true;
            })
            ->once();
    }

    /**
     * Zapis śladu nieudanego listu sam się wywraca (zwykle na bazie).
     * Dawniej: `BezpiecznyKomunikat::z()` na komunikacie `QueryException`.
     */
    #[DataProvider('obceWyjatki')]
    public function test_awaria_zapisu_sladu_listu_loguje_zadanie_i_klase_bez_komunikatu(Closure $wyjatek): void
    {
        $blad = $wyjatek();

        MailFailure::saving(static function () use ($blad): never {
            throw $blad;
        });

        $zadanie = $this->createMock(Job::class);
        $zadanie->method('uuid')->willReturn('11111111-2222-3333-4444-555555555555');
        $zadanie->method('attempts')->willReturn(1);
        $zadanie->method('getQueue')->willReturn('default');
        $zadanie->method('resolveName')->willReturn(NotifyUserExportReady::class);
        $zadanie->method('payload')->willReturn(['displayName' => NotifyUserExportReady::class]);

        $dziennik = Log::spy();

        // Brak wyjątku to część kontraktu: `failed_jobs` ma się zapisać.
        app(ZapiszNieudanyList::class)(new JobFailed('database', $zadanie, new TransportException('550 5.1.1 <basia@example.com>')));

        $dziennik->shouldHaveReceived('error')
            ->withArgs(function (string $wiadomosc, array $kontekst) use ($blad): bool {
                if ($wiadomosc !== 'Nie udało się zapisać śladu nieudanego listu.') {
                    return false;
                }

                $this->assertBezZakazanych($kontekst);
                $this->assertSame('11111111-2222-3333-4444-555555555555', $kontekst['failed_job_uuid']);
                $this->assertSame($blad::class, $kontekst['error']['wyjatek']);

                return true;
            })
            ->once();
    }

    /**
     * Każda droga do loggera, jaką zna skaner. W `app/` dziś żyją `Log::…`,
     * `Log::channel(…)->…` i `report(…)`; `logger()`, `app('log')`,
     * `app(LoggerInterface::class)` i wstrzyknięty `$this->logger` są tu
     * zawczasu — nowe miejsce ma oblać test w dniu, w którym się pojawi.
     * `report(new RuntimeException($e->getMessage()))` to też log: komunikat
     * przenosi się do nowego wyjątku i idzie do handlera.
     */
    private const WYWOLANIE_LOGGERA = '/(?:'
        .'(?:Log::(?:channel|stack)\([^)]*\)->|Log::|logger\(\)->'
        .'|app\((?:[\'"]log[\'"]|\\\\?(?:Psr\\\\Log\\\\)?LoggerInterface::class)\)->'
        .'|\$(?:this->)?\w*(?:[lL]ogger|dziennik)\w*->)'
        .'(?:debug|info|notice|warning|error|critical|alert|emergency|log)'
        .'|(?<![\w>:$\\\\])(?<!function )(?:logger|report)'
        .')\((?:[^;]|;(?!\s*$))*?\);/ms';

    /**
     * Skan całego `app/`: żadne wywołanie loggera (wszystkie formy z
     * `WYWOLANIE_LOGGERA`) nie przekazuje `->getMessage()` — także przez
     * metodę pomocniczą, która oddaje loggerowi swój parametr jako kontekst
     * (`KlientTurnstile::nieWiemy()`). Bez wyjątku dla
     * `BezpiecznyKomunikat::z()`: maskuje adres, ale zostawia SQL, token
     * i hash (#973). Nowe miejsce z surowym komunikatem ma oblać ten test,
     * nie czekać na kolejny audyt.
     */
    public function test_zadne_wywolanie_loggera_w_app_nie_przekazuje_surowego_komunikatu(): void
    {
        ['naruszenia' => $naruszenia, 'sprawdzone' => $sprawdzone, 'pomocnicy' => $pomocnicy] = $this->skanuj(app_path());

        // KONTROLA DODATNIA: skan naprawdę widzi wywołania loggera i metody,
        // które budują mu kontekst na zewnątrz.
        $this->assertGreaterThan(50, $sprawdzone);
        $this->assertContains('app/Turnstile/KlientTurnstile.php::nieWiemy', $pomocnicy);

        $this->assertSame([], $naruszenia, "Surowe getMessage() w logu (użyj BezpiecznyBlad::kontekst()):\n".implode("\n", $naruszenia));
    }

    /**
     * KONTROLA DODATNIA SKANERA na sztucznym pliku: każda forma loggera
     * z surowym komunikatem ma zostać złapana, a poprawne wywołania — nie.
     * Bez tego pusta lista naruszeń w `app/` mogłaby znaczyć „skaner ślepy".
     */
    public function test_skaner_lapie_kazda_forme_loggera_w_sztucznym_pliku(): void
    {
        $katalog = sys_get_temp_dir().'/kuking-skaner-'.bin2hex(random_bytes(4));
        mkdir($katalog);

        $kod = <<<'PHP'
<?php
final class Naruszenia
{
    public function wszystko(\Throwable $e): void
    {
        Log::error('fasada', ['error' => $e->getMessage()]);
        Log::channel('blad_webhook')->warning('kanał', ['error' => $e->getMessage()]);
        Log::stack(['single'])->info('stos', ['error' => $e?->getMessage()]);
        logger()->error('helper', ['error' => $e->getMessage()]);
        logger('helper z argumentem', ['error' => $e->getPrevious()->getMessage()]);
        app('log')->critical('kontener', ['error' => $e->getMessage()]);
        app(LoggerInterface::class)->alert('psr', ['error' => $e->getMessage()]);
        $this->logger->notice('wstrzyknięty', ['error' => $e->getMessage()]);
        report(new RuntimeException('opakowany: '.$e->getMessage(), previous: $e));
        Log::error('dawny wyjątek dla poczty', ['error' => BezpiecznyKomunikat::z($e->getMessage())]);
        $this->pomocnik('przez pomocnika', ['error' => $e->getMessage()]);
    }

    public function czysto(\Throwable $e): void
    {
        // Log::error('w komentarzu', ['error' => $e->getMessage()]);
        Log::error('poprawnie', ['error' => BezpiecznyBlad::kontekst($e)]);
        report($e);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, $e->getMessage());
    }

    private function pomocnik(string $powod, array $kontekst): void
    {
        Log::warning($powod, $kontekst);
    }
}
PHP;
        file_put_contents($katalog.'/Naruszenia.php', $kod);

        try {
            ['naruszenia' => $naruszenia, 'pomocnicy' => $pomocnicy] = $this->skanuj($katalog);
        } finally {
            unlink($katalog.'/Naruszenia.php');
            rmdir($katalog);
        }

        $linie = array_map(static fn (string $n): int => (int) substr($n, strrpos($n, ':') + 1), $naruszenia);
        sort($linie);

        // Linie 6–16: jedenaście naruszeń. `czysto()` (z komentarzem, który
        // tylko OPISUJE surowy log) i relacja `report()` (definicja metody,
        // nie wywołanie) — ani jednego.
        $this->assertSame(range(6, 16), $linie, "Skaner nie złapał wszystkich form:\n".implode("\n", $naruszenia));
        $this->assertContains('Naruszenia.php::pomocnik', $pomocnicy);
    }

    /**
     * Komentarze zamienione na same znaki nowej linii: opis w docbloku
     * („`report()` loguje `$e->getMessage()`") to nie wywołanie, a numery
     * linii naruszeń mają zostać prawdziwe.
     */
    private function bezKomentarzy(string $kod): string
    {
        $wynik = '';

        foreach (token_get_all($kod) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $wynik .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $wynik .= is_array($token) ? $token[1] : $token;
        }

        return $wynik;
    }

    /**
     * @return array{naruszenia: list<string>, sprawdzone: int, pomocnicy: list<string>}
     */
    private function skanuj(string $katalog): array
    {
        $surowy = '/\$\w+\??->(?:getPrevious\(\)\??->)?getMessage\(\)/';

        // Metoda, która przekazuje SWÓJ parametr jako kontekst loggera
        // (`nieWiemy($powod, $kontekst)` → `Log::warning(…, $kontekst)`):
        // wtedy kontekst powstaje przy wywołaniu metody, nie przy `Log::`.
        $metoda = '/function\s+(\w+)\s*\(([^)]*)\)[^{;]*\{(.*?)\n    \}/s';

        $naruszenia = [];
        $sprawdzone = 0;
        $pomocnicy = [];
        $baza = rtrim(base_path(), '/').'/';

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($katalog)) as $plik) {
            if (! str_ends_with((string) $plik, '.php')) {
                continue;
            }

            $kod = $this->bezKomentarzy((string) file_get_contents((string) $plik));
            $sciezka = str_starts_with((string) $plik, $baza) ? substr((string) $plik, strlen($baza)) : basename((string) $plik);
            preg_match_all(self::WYWOLANIE_LOGGERA, $kod, $trafienia, PREG_OFFSET_CAPTURE);
            $wywolania = $trafienia[0];

            preg_match_all($metoda, $kod, $metody, PREG_SET_ORDER);

            foreach ($metody as [, $nazwa, $parametry, $cialo]) {
                preg_match_all('/\$(\w+)/', $parametry, $nazwyParametrow);
                preg_match_all(self::WYWOLANIE_LOGGERA, $cialo, $wCiele);

                foreach ($wCiele[0] as $wywolanieLoggera) {
                    foreach ($nazwyParametrow[1] as $parametr) {
                        if (preg_match('/,\s*\$'.$parametr.'\s*\);$/', $wywolanieLoggera) !== 1) {
                            continue;
                        }

                        $pomocnicy[] = $sciezka.'::'.$nazwa;
                        preg_match_all('/(?:\$this->|self::|static::)'.$nazwa.'\((?:[^;]|;(?!\s*$))*?\);/ms', $kod, $przezPomocnika, PREG_OFFSET_CAPTURE);
                        array_push($wywolania, ...$przezPomocnika[0]);
                    }
                }
            }

            foreach ($wywolania as [$tekst, $pozycja]) {
                $sprawdzone++;

                if (preg_match($surowy, $tekst) === 1) {
                    $naruszenia[] = $sciezka.':'.(substr_count(substr($kod, 0, $pozycja), "\n") + 1);
                }
            }
        }

        return ['naruszenia' => array_values(array_unique($naruszenia)), 'sprawdzone' => $sprawdzone, 'pomocnicy' => $pomocnicy];
    }
}
