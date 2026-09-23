<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\ExportTempDirectory;
use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ISSUE #993: przerwany eksport nie zostawia bezterminowo pełnej kopii konta
 * w katalogu tymczasowym.
 *
 * Do 23 września 2026 pliki pośrednie (ZIP w budowie, `dane.json`, kopie
 * zdjęć) leżały luzem jako `<tmp>/kuking-eksport-<losowe>.*`, a ich lista
 * żyła tylko w pamięci jednego uruchomienia joba. `failed()` po timeoucie
 * działa na NOWEJ instancji i tej listy nie znał; nic innego tych plików
 * nie szukało.
 *
 * KONTROLA UJEMNA (wykonana): usunięcie `ExportTempDirectory::sweepStale()`
 * ze startu `handle()` oblewa test o osieroconych plikach; usunięcie
 * `ExportTempDirectory::remove()` z `failed()` oblewa test o timeoucie.
 */
class EksportNieZostawiaKopiiKontaWKataloguTymczasowymTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $doSprzatniecia = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        Storage::fake('local');

        config([
            'kuking.exports.disk' => 'local',
            'kuking.exports.ttl_days' => 7,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->doSprzatniecia as $sciezka) {
            if (is_dir($sciezka)) {
                foreach (glob($sciezka.'/{,.}*', GLOB_BRACE) ?: [] as $plik) {
                    if (is_file($plik)) {
                        @unlink($plik);
                    } elseif (is_dir($plik) && ! in_array(basename($plik), ['.', '..'], true)) {
                        @rmdir($plik);
                    }
                }
                @rmdir($sciezka);
            } elseif (is_file($sciezka)) {
                @unlink($sciezka);
            }
        }

        parent::tearDown();
    }

    public function test_start_eksportu_usuwa_osierocone_pliki_innych_eksportow_ale_nie_swieze(): void
    {
        $dwieGodzinyTemu = time() - 2 * 3600;

        $osierocony = $this->katalogEksportu((string) Str::uuid(), mtime: $dwieGodzinyTemu);
        $staryLuzny = sys_get_temp_dir().'/kuking-eksport-'.Str::uuid().'.zip';
        file_put_contents($staryLuzny, 'kopia konta w starym układzie');
        touch($staryLuzny, $dwieGodzinyTemu);
        $this->doSprzatniecia[] = $staryLuzny;

        // Aktywny eksport na innym workerze: dotknięty przed chwilą.
        $aktywny = $this->katalogEksportu((string) Str::uuid(), mtime: time() - 60);

        $export = $this->zamowienie();
        (new GenerateUserExport((string) $export->getKey()))->handle();

        // KONTROLA DODATNIA: eksport naprawdę się wykonał.
        $this->assertSame(DataExport::STATUS_READY, $export->refresh()->status);

        $this->assertDirectoryDoesNotExist($osierocony);
        $this->assertFileDoesNotExist($staryLuzny);
        $this->assertDirectoryExists($aktywny, 'Sprzątanie zabrało pliki żywego eksportu.');
        $this->assertFileExists($aktywny.'/dane.json');
    }

    public function test_udany_i_nieudany_eksport_nie_zostawiaja_swojego_katalogu(): void
    {
        $udany = $this->zamowienie();
        (new GenerateUserExport((string) $udany->getKey()))->handle();

        $this->assertSame(DataExport::STATUS_READY, $udany->refresh()->status);
        $this->assertDirectoryDoesNotExist(ExportTempDirectory::forExport((string) $udany->getKey()));

        $nieudany = DataExport::create(['user_id' => $this->user('marektmp')->getKey(), 'status' => DataExport::STATUS_QUEUED]);
        config(['kuking.exports.disk' => 'dysk-ktorego-nie-ma']);

        try {
            (new GenerateUserExport((string) $nieudany->getKey()))->handle();
            $this->fail('Job powinien rzucić.');
        } catch (\Throwable) {
            // oczekiwane
        }

        $this->assertSame(DataExport::STATUS_FAILED, $nieudany->refresh()->status);
        $this->assertDirectoryDoesNotExist(ExportTempDirectory::forExport((string) $nieudany->getKey()));
    }

    public function test_failed_po_timeoucie_na_nowej_instancji_usuwa_pliki_przerwanej_proby(): void
    {
        $export = $this->zamowienie(DataExport::STATUS_PROCESSING);

        // Tak zostaje po procesie zabitym w połowie: pliki są, `finally` nie było.
        $katalog = $this->katalogEksportu((string) $export->getKey(), mtime: time());

        (new GenerateUserExport((string) $export->getKey()))->failed(null);

        $this->assertSame(DataExport::REASON_TIMEOUT, $export->refresh()->failure_reason);
        $this->assertDirectoryDoesNotExist($katalog);
    }

    public function test_nieudane_usuniecie_jest_widoczne_w_dzienniku_bez_sciezki(): void
    {
        $id = (string) Str::uuid();
        $katalog = $this->katalogEksportu($id, mtime: time());

        // Podkatalogu nie zdejmie `unlink()` — także uruchomionemu jako root.
        mkdir($katalog.'/nie-do-usuniecia');

        Log::spy();

        $this->assertFalse(ExportTempDirectory::remove($id));

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $wiadomosc, array $kontekst) use ($id): bool {
                $caly = $wiadomosc.' '.json_encode($kontekst, JSON_UNESCAPED_SLASHES);

                return ($kontekst['data_export_id'] ?? null) === $id
                    && ! str_contains($caly, sys_get_temp_dir());
            })
            ->once();
    }

    private function zamowienie(string $status = DataExport::STATUS_QUEUED): DataExport
    {
        return DataExport::create([
            'user_id' => $this->user('basiatmp'.Str::random(6))->getKey(),
            'status' => $status,
        ]);
    }

    private function katalogEksportu(string $id, int $mtime): string
    {
        $katalog = ExportTempDirectory::forExport($id);
        @mkdir($katalog, 0700, true);
        file_put_contents($katalog.'/dane.json', '{"konto":"kopia"}');
        touch($katalog.'/dane.json', $mtime);
        touch($katalog, $mtime);

        $this->doSprzatniecia[] = $katalog;

        return $katalog;
    }
}
