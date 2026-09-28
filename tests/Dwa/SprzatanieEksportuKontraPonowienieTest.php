<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Users\Exports\ExportFileNames;
use App\Models\DataExport;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\Filesystem as LocalFiles;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\Group;

/**
 * ISSUE #2073 NA DWÓCH POŁĄCZENIACH: nocne sprzątanie osieroconych paczek
 * (`kuking:sprzataj-eksporty`, połączenie A — proces testu) kontra
 * ponowienie tego samego eksportu przez worker (`GenerateUserExport`,
 * połączenie B — osobny proces, `bin/eksportSprzatanie.php`).
 *
 * Sprzątanie wybiera `failed` bez `object_key` i kasuje obiekt pod kluczem
 * wyliczonym z id (`ExportFileNames::objectKey()`). Ponowienie zapisuje paczkę
 * POD TYM SAMYM kluczem i ustawia `ready`. Bez wspólnego przejęcia wiersza
 * kolejność „sprzątanie wybrało → worker zapisał i zakończył → sprzątanie
 * skasowało” zostawiała `ready` z linkiem do pliku, którego nie ma.
 *
 * Niezmiennik obu testów: `ready` ZAWSZE wskazuje istniejący obiekt.
 *
 *  1. Sprzątanie jest w `delete()` (czyli rekord już przejęło), a worker
 *     rusza z ponowieniem. Worker nie może w tym czasie zapisać paczki ani
 *     skończyć próby jako `ready` — czeka na koniec sprzątania i dopiero
 *     potem buduje paczkę od zera.
 *  2. Sprzątanie wybrało kandydata, ale zanim sięgnęło po plik, worker
 *     zdążył przejąć rekord, zapisać paczkę i zakończyć `ready`. Sprzątanie
 *     musi to zobaczyć (rewalidacja pod blokadą) i pliku nie ruszyć.
 *
 * KONTROLA UJEMNA (wykonana, opis w raporcie PR): bez blokady wiersza
 * i rewalidacji w `PrzejecieEksportu` oba testy oblewają na „`ready` bez
 * pliku”.
 */
#[Group('dwa-polaczenia')]
final class SprzatanieEksportuKontraPonowienieTest extends TestDwochPolaczen
{
    private string $nazwaDysku;

    private string $root;

    private FilesystemAdapter $dysk;

    /** Wołane raz, tuż przed `delete()` na dysku eksportów. */
    private ?Closure $przedUsunieciem = null;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->nazwaDysku = 'eksporty-2073-'.bin2hex(random_bytes(4));
        $this->root = storage_path('framework/testing/disks/'.$this->nazwaDysku);
        (new LocalFiles)->ensureDirectoryExists($this->root);

        $adapter = new LocalFilesystemAdapter($this->root);
        $hak = function (): void {
            if ($this->przedUsunieciem !== null) {
                $wywolaj = $this->przedUsunieciem;
                $this->przedUsunieciem = null;
                $wywolaj();
            }
        };

        $this->dysk = new class(new Filesystem($adapter), $adapter, ['root' => $this->root], $hak) extends FilesystemAdapter
        {
            /** @param array<string, mixed> $config */
            public function __construct(Filesystem $driver, LocalFilesystemAdapter $adapter, array $config, private readonly Closure $hak)
            {
                parent::__construct($driver, $adapter, $config);
            }

            public function delete($paths)
            {
                ($this->hak)();

                return parent::delete($paths);
            }
        };

        Storage::set($this->nazwaDysku, $this->dysk);
        config([
            "filesystems.disks.{$this->nazwaDysku}" => ['driver' => 'local', 'root' => $this->root],
            'kuking.exports.disk' => $this->nazwaDysku,
            'kuking.exports.ttl_days' => 7,
        ]);
    }

    protected function tearDown(): void
    {
        (new LocalFiles)->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_worker_nie_konczy_proby_jako_gotowej_gdy_sprzatanie_trzyma_rekord(): void
    {
        [$export, $klucz] = $this->nieudanyEksportZOsieroconymPlikiem();

        $worker = null;
        $stanWorkera = null;

        // Sprzątanie jest w `delete()` — czyli wybrało i (po naprawie)
        // przejęło rekord. Teraz rusza ponowienie z kolejki.
        $this->przedUsunieciem = function () use ($export, &$worker, &$stanWorkera): void {
            $worker = $this->ponowienie($export);
            $stanWorkera = $this->czekajAzSkonczyAlboStanie($worker, 'ponowienie-2073');

            if ($stanWorkera === 'czeka') {
                // Worker stoi na blokadzie wiersza: nie zdążył ani zapisać
                // paczki, ani zmienić stanu rekordu.
                $this->assertSame(
                    DataExport::STATUS_FAILED,
                    DB::table('data_exports')->where('id', $export->getKey())->value('status'),
                    'Worker zmienił stan rekordu, który przejęło sprzątanie.',
                );
            }
        };

        Artisan::call('kuking:sprzataj-eksporty');

        $this->assertNotNull($worker, 'Sprzątanie nie doszło do kasowania pliku — przeplot się nie ustawił.');

        $wynik = $worker->wynik();
        $this->assertTrue($wynik['ok'], 'Ponowienie padło: '.$wynik['komunikat']);

        // KONTROLA DODATNIA: ponowienie naprawdę zbudowało paczkę.
        $this->assertSame(DataExport::STATUS_READY, $wynik['wartosc']);

        $this->assertGotowaMaPlik($export, $klucz);
        $this->assertSame('czeka', $stanWorkera,
            'Ponowienie zakończyło próbę, gdy sprzątanie trzymało rekord w trakcie kasowania pliku.');
    }

    public function test_sprzatanie_pomija_plik_gdy_worker_przejal_rekord_po_wyborze_kandydata(): void
    {
        [$export, $klucz] = $this->nieudanyEksportZOsieroconymPlikiem();

        $wynikWorkera = null;
        $uzbrojone = true;

        // Pierwsze zapytanie o kandydatów (`failed` bez `object_key`) —
        // już wykonane, więc sprzątanie ma ten rekord na liście. Zanim
        // sięgnie po plik, worker kończy całe ponowienie.
        DB::listen(function (QueryExecuted $query) use ($export, &$wynikWorkera, &$uzbrojone): void {
            $sql = mb_strtolower($query->sql);

            if (! $uzbrojone
                || ! str_starts_with(trim($sql), 'select')
                || ! str_contains($sql, 'from "data_exports"')
                || ! str_contains($sql, '"object_key" is null')
                || str_contains($sql, 'for update')) {
                return;
            }

            $uzbrojone = false;
            $wynikWorkera = $this->ponowienie($export)->wynik();
        });

        Artisan::call('kuking:sprzataj-eksporty');

        $this->assertIsArray($wynikWorkera, 'Sprzątanie nie wybrało kandydatów — przeplot się nie ustawił.');
        $this->assertTrue($wynikWorkera['ok'], 'Ponowienie padło: '.$wynikWorkera['komunikat']);
        $this->assertSame(DataExport::STATUS_READY, $wynikWorkera['wartosc']);

        $this->assertGotowaMaPlik($export, $klucz);
    }

    /** @return array{0: DataExport, 1: string} */
    private function nieudanyEksportZOsieroconymPlikiem(): array
    {
        $konto = $this->konto();
        $export = DataExport::create(['user_id' => $konto->getKey(), 'status' => DataExport::STATUS_QUEUED]);
        $klucz = ExportFileNames::objectKey($export);

        // Osierocony obiekt po wcześniejszej, zabitej próbie.
        $this->dysk->put($klucz, 'stara paczka');

        DB::table('data_exports')->where('id', $export->getKey())->update([
            'status' => DataExport::STATUS_FAILED,
            'failure_reason' => DataExport::REASON_UNKNOWN,
            'updated_at' => now()->subHours(2),
        ]);

        return [$export, $klucz];
    }

    private function ponowienie(DataExport $export): ProcesRownolegly
    {
        return ProcesRownolegly::start(__DIR__.'/bin/eksportSprzatanie.php', 'ponowienie', [
            'name' => 'ponowienie-2073',
            'export' => (string) $export->getKey(),
            'disk' => $this->nazwaDysku,
            'root' => $this->root,
        ], [
            'DB_DATABASE' => $this->baza, 'APP_ENV' => 'testing', 'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
        ]);
    }

    /**
     * Czeka, aż proces skończy albo stanie w kolejce po blokadę — bez `sleep`
     * zgadującego czas. Zwraca 'koniec' albo 'czeka'.
     */
    private function czekajAzSkonczyAlboStanie(ProcesRownolegly $proces, string $nazwa): string
    {
        $zapytanie = $this->obserwator->prepare(
            "SELECT count(*) FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'",
        );
        $koniec = microtime(true) + self::SEKUNDY_NA_KOLEJKE;

        while (microtime(true) < $koniec) {
            if (! $proces->trwa()) {
                return 'koniec';
            }

            $zapytanie->execute([$nazwa]);

            if ((int) $zapytanie->fetchColumn() > 0) {
                return 'czeka';
            }

            usleep(20_000);
        }

        $this->fail('Ponowienie ani nie skończyło, ani nie stanęło na blokadzie w '.self::SEKUNDY_NA_KOLEJKE.' s.');
    }

    private function assertGotowaMaPlik(DataExport $export, string $klucz): void
    {
        $export->refresh();

        $this->assertSame(DataExport::STATUS_READY, $export->status);
        $this->assertSame($klucz, $export->object_key);
        $this->assertTrue(
            $this->dysk->exists((string) $export->object_key),
            'Eksport jest `ready`, ale pliku pod `object_key` nie ma — sprzątanie skasowało paczkę ponowienia.',
        );
        $this->assertNotSame('stara paczka', $this->dysk->get($klucz), 'Pod kluczem leży stara paczka, nie ta z ponowienia.');
    }
}
