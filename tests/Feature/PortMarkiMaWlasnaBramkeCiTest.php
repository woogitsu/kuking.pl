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

    public function test_port_i_zalezny_kreator_maja_jedna_obowiazkowa_bramke(): void
    {
        $job = $this->job('port_marki');
        $port = 'run: node scripts/port-projektu.mjs';
        $kroki = 'run: node scripts/kroki-kreatora.mjs';
        $this->assertSame(1, substr_count($this->workflow(), $port));
        $this->assertSame(1, substr_count($this->workflow(), $kroki));
        $this->assertStringContainsString($port, $job);
        $this->assertStringContainsString($kroki, $job);
        $this->assertLessThan(strpos($job, $kroki), strpos($job, $port), 'Kreator wymaga bazy przygotowanej przez port.');
        $this->assertStringNotContainsString('continue-on-error:', $job);
        $this->assertStringContainsString('job.services.postgres.ports[5432]', $job);
        $this->assertStringContainsString('storage/port-projektu', $job);
        $this->assertStringContainsString('storage/kroki-kreatora', $job);
        $other = $this->job('dostepnosc');
        foreach (['dostepnosc', 'wydajnosc', 'fokus-karty-dania', 'kafel-dodawania', 'service-worker-aktualizacja'] as $script) {
            $this->assertStringContainsString('run: node scripts/'.$script.'.mjs', $other);
        }
    }

    public function test_zmiana_samego_przyrzadu_nie_pomija_pomiarow(): void
    {
        foreach (['port_marki', 'dostepnosc'] as $name) {
            $this->assertSame(1, preg_match("/grep -qE '([^']+)'/", $this->job($name), $matches));
            $pattern = '~'.str_replace('~', '\\~', $matches[1]).'~';
            foreach (['scripts/zainteresowania-powiadomienia-marki.mjs', 'scripts/fixtures/kompozycje-513.php', 'resources/css/marka-onboarding.css'] as $path) {
                $this->assertSame(1, preg_match($pattern, $path), $name.': pominięto '.$path);
            }
            $this->assertSame(0, preg_match($pattern, 'docs/PRODUCT.md'));
        }
    }
}
