<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class DokumentacjaSkaliMarkiTest extends TestCase
{
    public function test_system_designu_opisuje_skale_dostepne_w_aplikacji(): void
    {
        $document = file_get_contents(base_path('docs/design/DESIGN_SYSTEM.md'));
        $this->assertSame(1, preg_match('/### 2\.3 Skala tekstu użytkownika \(`text_scale`: ([0-9\/]+)%\)/u', $document, $matches));
        $documented = array_map('intval', explode('/', $matches[1]));
        $this->assertSame(config('kuking.text.scales'), $documented, 'Instrukcja modeli nie może nakazywać skali spoza konfiguracji aplikacji.');
    }
}
