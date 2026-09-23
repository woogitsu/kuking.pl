<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Pilnuje faktów historycznych raportów, nie zamraża wyboru SQL ani progów.
 *
 * @bez-kontroli-dodatniej Pilnuje treści historycznych raportów w docs/infra, nie źródeł aplikacji — żadna mutacja kodu nie uczyni go fałszywie zielonym, a zniknięcie raportu czerwieni na readReport.
 */
class RaportyPomiarowRozdzielajaStanIDowodTest extends TestCase
{
    public function test_raport_feedu_odnotowuje_dostarczenie_pakietu(): void
    {
        $report = $this->readReport('POMIAR_FEEDU_585.md');

        $this->assertStringContainsString('e951554cfd3f86147b95fb60606912c1650493f8', $report);
        $this->assertStringNotContainsString('PR i CI pozostają do wykonania', $report);
        $this->assertStringNotContainsString('Pełny hook i CI nadal nie są wykonane', $report);
        $this->assertStringNotContainsString('Issues #585 i #609 pozostają otwarte', $report);
    }

    public function test_raport_budzetu_nie_zamienia_obliczenia_w_pomiar(): void
    {
        $report = $this->readReport('WERYFIKACJA_BUDZETU_POLACZEN_598.md');

        $this->assertStringNotContainsString('zmierzonego szczytu (16)', $report);
        $this->assertStringNotContainsString('Liczby w §3 poza „16”', $report);
        $this->assertStringNotContainsString('Liczby w §3 poza „16"', $report);
        $this->assertStringContainsString('obliczonego budżetu szczytowego (16)', $report);
    }

    private function readReport(string $name): string
    {
        $path = base_path('docs/infra/'.$name);
        $this->assertFileExists($path);
        $report = file_get_contents($path);
        $this->assertIsString($report);
        $this->assertNotSame('', trim($report));

        return $report;
    }
}
