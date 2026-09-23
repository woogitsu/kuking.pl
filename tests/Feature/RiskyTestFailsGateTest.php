<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class RiskyTestFailsGateTest extends TestCase
{
    public function test_risky_without_assertions_fails_artisan_test(): void
    {
        $process = $this->runFixture('WithoutAssertionsTest.php');
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertStringContainsString('This test did not perform any assertions', $output);
        $this->assertStringNotContainsString('SQLSTATE', $output);
        $plainOutput = preg_replace('/\x1b\[[0-9;]*m/', '', $output);
        $this->assertMatchesRegularExpression('/Tests:\s+1 risky\s+\(0 assertions\)/', $plainOutput);
        $this->assertSame(1, $process->getExitCode(), 'Risky musi oblać bramkę php artisan test. '.$output);
    }

    public function test_real_assertion_passes_artisan_test(): void
    {
        $process = $this->runFixture('WithAssertionTest.php');
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('1 passed', $output);
        $this->assertStringContainsString('1 assertions', $output);
    }

    private function runFixture(string $fixture): Process
    {
        $root = dirname(__DIR__, 2);
        // Ta sama bramka co CI, bez wymuszania fail-on-risky w argumentach.
        // Jawny plik spoza zestawów wyklucza uruchomienie strażnika rekurencyjnie.
        $process = new Process([
            PHP_BINARY, 'artisan', 'test', 'tests/Fixtures/PhpunitRisky/'.$fixture, '--no-ansi', '--do-not-cache-result',
        ], $root, ['APP_BASE_PATH' => $root], timeout: 60);
        $process->run();

        return $process;
    }
}
