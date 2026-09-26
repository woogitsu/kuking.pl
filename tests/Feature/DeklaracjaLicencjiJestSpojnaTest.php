<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class DeklaracjaLicencjiJestSpojnaTest extends TestCase
{
    public function test_manifest_obraz_i_licencja_zastrzegaja_prawa(): void
    {
        $katalog = dirname(__DIR__, 2);
        $manifest = json_decode((string) file_get_contents($katalog.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $licencja = (string) file_get_contents($katalog.'/LICENSE');
        $dockerfile = (string) file_get_contents($katalog.'/Dockerfile');

        $this->assertSame('proprietary', $manifest['license'] ?? null);
        $this->assertStringContainsString('Wszelkie prawa zastrzeżone', $licencja);
        $this->assertStringContainsString('nie jest objęty żadną', $licencja);
        $this->assertMatchesRegularExpression('/^LABEL org\.opencontainers\.image\.licenses="proprietary"$/m', $dockerfile);
    }
}
