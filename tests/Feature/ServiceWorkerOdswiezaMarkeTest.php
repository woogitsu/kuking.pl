<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ServiceWorkerOdswiezaMarkeTest extends TestCase
{
    public function test_prawdziwy_worker_odswieza_stale_adresy_i_nie_zapisuje_prywatnych_stron(): void
    {
        $proces = new Process(['node', base_path('scripts/service-worker-marka.test.mjs')], base_path());
        $proces->run();

        $this->assertSame(0, $proces->getExitCode(), $proces->getOutput().$proces->getErrorOutput());
        $this->assertStringContainsString('offline i prywatność — OK', $proces->getOutput());
    }
}
