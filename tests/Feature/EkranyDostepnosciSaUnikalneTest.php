<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Lista ekranów `scripts/dostepnosc.mjs` nie ma powtórzeń (#611, etap 4).
 * Do 29.09.2026 `/otworz-link` stał w `EKRANY` i drugi raz nad `...EKRANY`
 * w `EKRANY_UKLADU`. Test uruchamia `scripts/ekrany-unikalne.test.mjs`
 * (z kontrolą ujemną wewnątrz), bo ci.yml nie ma osobnego kroku dla tego testu.
 *
 * @bez-kontroli-dodatniej Uruchamia test w Node i asertuje na jego kodzie wyjścia; treść źródła czyta sam skrypt Node, a nie ten plik PHP.
 */
class EkranyDostepnosciSaUnikalneTest extends TestCase
{
    public function test_ekrany_automatu_dostepnosci_nie_maja_duplikatow(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Brak node — to jest brak kontroli, nie sukces.');
        }

        $proces = new Process([$node, 'scripts/ekrany-unikalne.test.mjs'], base_path(), null, null, 60);
        $proces->run();

        $this->assertSame(0, $proces->getExitCode(), $proces->getOutput().$proces->getErrorOutput());
        $this->assertStringContainsString('źródło dostepnosc.mjs): OK', $proces->getOutput());
    }
}
