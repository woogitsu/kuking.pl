<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class FixturePortuSygnalizujeBladTest extends TestCase
{
    public function test_odmowa_niewlasciwej_bazy_konczy_rzeczywisty_proces_bledem(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['513', '515'] as $number) {
            // Niedozwolona nazwa zatrzymuje fixture przed pierwszym zapytaniem.
            $process = new Process([PHP_BINARY, "scripts/fixtures/kompozycje-{$number}.php", 'stan'], $root, [
                'APP_ENV' => 'testing',
                'APP_CONFIG_CACHE' => sys_get_temp_dir().'/kuking-fixture-config-nie-istnieje-'.getmypid(),
                'DB_CONNECTION' => 'pgsql',
                'DB_DATABASE' => 'odmowa_fixture',
                'DB_HOST' => '127.0.0.1',
                'DB_PORT' => '1',
            ]);
            $process->run();
            $this->assertSame(1, $process->getExitCode(), 'Fixture '.$number.' musi zatrzymać proces wywołujący.');
            $this->assertStringContainsString('kuking_port*', $process->getErrorOutput());
            $this->assertStringNotContainsString('SQLSTATE', $process->getOutput().$process->getErrorOutput());
        }
    }
}
