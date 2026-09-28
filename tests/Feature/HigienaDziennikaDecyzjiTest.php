<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\HigienaDziennikaDecyzji;
use Tests\TestCase;

final class HigienaDziennikaDecyzjiTest extends TestCase
{
    public function test_aktywny_dziennik_nie_ma_roboczych_numerow_ani_martwych_odnosnikow(): void
    {
        $usterki = (new HigienaDziennikaDecyzji(base_path()))->usterki();

        $this->assertSame([], $usterki, implode("\n", $usterki));
    }

    public function test_cztery_cyfry_robocza_i_martwy_odnosnik_zapalaja_osobne_kontrole(): void
    {
        $korzen = sys_get_temp_dir().'/kuking-decyzje-'.bin2hex(random_bytes(6));
        mkdir($korzen.'/docs/decyzje', 0777, true);

        try {
            file_put_contents($korzen.'/docs/decyzje/D-001-pierwsza.md',
                "## D-001 · Pierwsza\n\nObowiązuje reguła D-999.\n");
            file_put_contents($korzen.'/docs/decyzje/D-002-druga.md',
                "## D-002-ROBOCZA — Druga\n");
            file_put_contents($korzen.'/docs/decyzje/D-1000-czwarta.md',
                "## D-1000 — Czwarta\n");
            file_put_contents($korzen.'/docs/decyzje/D-1001-robocza.md',
                "## D-1001-ROBOCZA — Piąta\n");

            $usterki = (new HigienaDziennikaDecyzji($korzen))->usterki();

            $this->assertCount(4, $usterki);
            $this->assertStringContainsString('D-002-druga.md', implode("\n", $usterki));
            $this->assertStringContainsString('D-1000-czwarta.md', implode("\n", $usterki));
            $this->assertStringContainsString('D-1001-robocza.md', implode("\n", $usterki));
            $this->assertStringContainsString('D-999 nie ma wpisu', implode("\n", $usterki));
        } finally {
            unlink($korzen.'/docs/decyzje/D-001-pierwsza.md');
            unlink($korzen.'/docs/decyzje/D-002-druga.md');
            unlink($korzen.'/docs/decyzje/D-1000-czwarta.md');
            unlink($korzen.'/docs/decyzje/D-1001-robocza.md');
            rmdir($korzen.'/docs/decyzje');
            rmdir($korzen.'/docs');
            rmdir($korzen);
        }
    }
}
