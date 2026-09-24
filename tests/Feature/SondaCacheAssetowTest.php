<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class SondaCacheAssetowTest extends TestCase
{
    public function test_sonda_sprawdza_dostepnosc_css_i_js_a_nie_sam_naglowek(): void
    {
        $process = new Process(['bash', base_path('tests/skrypty/cache-assetow.sh')], base_path());
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('Sprawdzono: good', $process->getOutput());
        $this->assertStringContainsString('Sprawdzono: missing', $process->getOutput());
    }

    public function test_manifest_bez_hasha_nie_dostaje_rocznego_cache_assetow(): void
    {
        $config = file_get_contents(base_path('docker/Caddyfile'));
        $this->assertStringContainsString('@viteAssets path /build/assets/*', $config);
        $this->assertStringNotContainsString('@viteAssets path /build/*', $config);
        $this->assertStringContainsString('@viteManifest path /build/manifest.json', $config);
        $this->assertStringContainsString('header @viteManifest Cache-Control "no-cache"', $config);
    }
}
