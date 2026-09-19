<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class PortMarkiMaWlasnaBramkeCiTest extends TestCase
{
    private function workflow(): string
    {
        return (string) file_get_contents(base_path('.github/workflows/ci.yml'));
    }

    private function job(string $name): string
    {
        $matched = preg_match('/^  '.preg_quote($name, '/').':\R(.*?)(?=^  [a-z_]+:|\z)/ms', $this->workflow(), $matches);
        $this->assertSame(1, $matched, 'Brak sprawdzanego joba CI: '.$name);

        return (string) preg_replace('/^\s*#.*$/m', '', $matches[1]);
    }

    public function test_obie_grupy_sa_obowiazkowe_a_kreator_ma_przygotowana_baze(): void
    {
        $port = 'run: node scripts/port-projektu.mjs';
        $kroki = 'run: node scripts/kroki-kreatora.mjs';
        $this->assertSame(2, substr_count($this->workflow(), $port));
        $this->assertSame(1, substr_count($this->workflow(), $kroki));
        foreach (['port_marki' => 'baza', 'port_funkcje' => 'rozszerzenia'] as $name => $group) {
            $job = $this->job($name);
            $this->assertStringContainsString($port, $job);
            $this->assertStringContainsString('run: node --test scripts/port-grupy.test.mjs', $job);
            $this->assertStringContainsString('PORT_GRUPA: '.$group, $job);
            $this->assertStringContainsString('timeout-minutes: '.($name === 'port_funkcje' ? 35 : 25), $job);
            $this->assertStringContainsString("if: needs.zakres.outputs.kod == 'true'", $job);
            $this->assertStringNotContainsString('continue-on-error:', $job);
            $this->assertStringContainsString('job.services.postgres.ports[5432]', $job);
            $this->assertStringContainsString('storage/port-projektu', $job);
            $this->assertStringContainsString('uses: actions/checkout@v7', $job);
        }
        $job = $this->job('port_funkcje');
        $this->assertStringContainsString($kroki, $job);
        $this->assertLessThan(strpos($job, $kroki), strpos($job, $port), 'Kreator wymaga bazy przygotowanej przez port.');
        $this->assertStringContainsString('storage/kroki-kreatora', $job);
        $this->assertStringNotContainsString($kroki, $this->job('port_marki'));
        $other = $this->job('dostepnosc');
        foreach (['dostepnosc', 'wydajnosc', 'fokus-karty-dania', 'kafel-dodawania', 'service-worker-aktualizacja'] as $script) {
            $this->assertStringContainsString('run: node scripts/'.$script.'.mjs', $other);
        }
    }

    public function test_zmiana_samego_przyrzadu_nie_pomija_pomiarow(): void
    {
        foreach (['port_marki', 'port_funkcje', 'dostepnosc'] as $name) {
            $this->assertSame(1, preg_match("/grep -qE '([^']+)'/", $this->job($name), $matches));
            $pattern = '~'.str_replace('~', '\\~', $matches[1]).'~';
            foreach (['scripts/port-grupy.mjs', 'scripts/port-grupy.test.mjs', 'scripts/nawigacja-etykiety.mjs', 'scripts/nawigacja-zoom.mjs', 'scripts/nawigacja-negatywy.mjs', 'scripts/szybki-wyglad.mjs', 'scripts/pasek-przewijany.mjs', 'scripts/zwarte-kolumny.mjs', 'scripts/katalog-tagow.mjs', 'scripts/zainteresowania-powiadomienia-marki.mjs', 'scripts/fixtures/kompozycje-513.php', 'resources/css/marka-onboarding.css'] as $path) {
                $this->assertSame(1, preg_match($pattern, $path), $name.': pominięto '.$path);
            }
            $this->assertSame(0, preg_match($pattern, 'docs/PRODUCT.md'));
        }
    }
}
