<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\ExportTempDirectory;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ReflectionProperty;
use Tests\TestCase;

/**
 * ISSUE #993: osierocona kopia konta znika po określonym czasie, a nie
 * „przy następnym eksporcie na tym workerze”.
 *
 * Do 24 września 2026 katalog po twardo przerwanym eksporcie sprzątał
 * wyłącznie start KOLEJNEGO eksportu w tym samym kontenerze. Bez kolejnego
 * zamówienia pełna kopia konta leżała na dysku workera do restartu kontenera
 * — bez żadnej górnej granicy. Teraz robi to pętla workera (`Looping`),
 * najwyżej raz na `ExportTempDirectory::SWEEP_EVERY_SECONDS`.
 *
 * Test NIE uruchamia żadnego eksportu — o to właśnie chodzi.
 *
 * KONTROLA UJEMNA (wykonana): bez `Event::listen(Looping::class, ...)`
 * w `AppServiceProvider` pierwszy test oblewa — katalog zostaje.
 */
class PetlaWorkeraSprzataOsieroconeEksportyTest extends TestCase
{
    /** @var list<string> */
    private array $doSprzatniecia = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Znacznik dławika żyje w procesie — inny test mógł go już ustawić.
        (new ReflectionProperty(ExportTempDirectory::class, 'lastLoopSweep'))->setValue(null, null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach ($this->doSprzatniecia as $katalog) {
            foreach (glob($katalog.'/*') ?: [] as $plik) {
                @unlink($plik);
            }
            @rmdir($katalog);
        }

        (new ReflectionProperty(ExportTempDirectory::class, 'lastLoopSweep'))->setValue(null, null);

        parent::tearDown();
    }

    public function test_petla_workera_usuwa_stary_osierocony_katalog_ale_nie_swiezy(): void
    {
        $osierocony = $this->katalogEksportu(time() - 2 * 3600);
        $aktywny = $this->katalogEksportu(time() - 60);

        event(new Looping('database', 'low'));

        $this->assertDirectoryDoesNotExist($osierocony, 'Pętla workera nie usunęła osieroconej kopii konta.');

        // KONTROLA UJEMNA: plik żywego eksportu zostaje nietknięty.
        $this->assertDirectoryExists($aktywny, 'Sprzątanie zabrało pliki żywego eksportu.');
        $this->assertFileExists($aktywny.'/dane.json');
    }

    public function test_dlawik_przeglada_katalog_najwyzej_raz_na_odstep(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(time()));

        event(new Looping('database', 'low'));

        // Katalog osierocił się TUŻ PO przeglądzie — w ciszy pętla kręci się
        // co kilka sekund, ale nie zagląda do dysku przy każdym obrocie.
        $osierocony = $this->katalogEksportu(time() - 2 * 3600);

        Carbon::setTestNow(now()->addSeconds(ExportTempDirectory::SWEEP_EVERY_SECONDS - 1));
        event(new Looping('database', 'low'));
        $this->assertDirectoryExists($osierocony);

        // Po odstępie — przegląd i usunięcie.
        Carbon::setTestNow(now()->addSeconds(1));
        event(new Looping('database', 'low'));
        $this->assertDirectoryDoesNotExist($osierocony);
    }

    private function katalogEksportu(int $mtime): string
    {
        $katalog = ExportTempDirectory::forExport((string) Str::uuid());
        @mkdir($katalog, 0700, true);
        file_put_contents($katalog.'/dane.json', '{"konto":"kopia"}');
        touch($katalog.'/dane.json', $mtime);
        touch($katalog, $mtime);

        $this->doSprzatniecia[] = $katalog;

        return $katalog;
    }
}
