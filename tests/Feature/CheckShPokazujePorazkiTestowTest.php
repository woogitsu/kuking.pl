<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Po nieudanym kroku „Testy" `scripts/check.sh` pokazuje same porażki z logu
 * (#611, etap 4), a nie tylko ogon. Test uruchamia pomocnika na sztucznym
 * logu; kontrola ujemna: log bez porażek nie może udawać, że jakaś jest.
 *
 * @bez-kontroli-dodatniej Uruchamia skrypt na logu zbudowanym w teście i asertuje na jego wyjściu; jedyna asercja na tekście check.sh sprawdza wywołanie pomocnika, a nie logikę.
 */
class CheckShPokazujePorazkiTestowTest extends TestCase
{
    private function uruchom(string $log): Process
    {
        $plik = tempnam(sys_get_temp_dir(), 'kuking-porazki-');
        file_put_contents($plik, $log);

        try {
            $proces = new Process(['bash', 'scripts/porazki-z-logu.sh', $plik, '60'], base_path());
            $proces->run();
        } finally {
            @unlink($plik);
        }

        return $proces;
    }

    public function test_porazki_sa_wyciagniete_z_dlugiego_logu(): void
    {
        $log = str_repeat("  PASS  Tests\\Feature\\Dobry\n", 300)
            ."  FAIL  Tests\\Feature\\Zly > psuje sie\n"
            ."Failed asserting that 1 is identical to 0.\n"
            .str_repeat("  PASS  Tests\\Feature\\Inny\n", 300)
            ."  Tests:    1 failed, 600 passed\n";

        $proces = $this->uruchom($log);

        $this->assertSame(0, $proces->getExitCode());
        $this->assertStringContainsString('FAIL  Tests\Feature\Zly > psuje sie', $proces->getOutput());
        $this->assertStringContainsString('Failed asserting that 1 is identical to 0.', $proces->getOutput());
        $this->assertStringNotContainsString('PASS', $proces->getOutput());
    }

    public function test_log_bez_porazek_nie_udaje_porazki(): void
    {
        $proces = $this->uruchom("  PASS  Tests\\Feature\\Dobry\n");

        $this->assertSame(0, $proces->getExitCode());
        $this->assertStringContainsString('Nie znaleziono w logu wiersza z porażką', $proces->getOutput());
        $this->assertStringNotContainsString('FAIL', $proces->getOutput());
    }

    public function test_check_sh_wola_pomocnika_po_nieudanych_testach(): void
    {
        $this->assertStringContainsString(
            'bash scripts/porazki-z-logu.sh "$_test_log"',
            (string) file_get_contents(base_path('scripts/check.sh')),
        );
    }
}
