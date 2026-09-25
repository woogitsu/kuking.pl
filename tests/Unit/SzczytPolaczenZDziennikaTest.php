<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * `scripts/szczyt-polaczen-z-dziennika.php` — szereg z dziennika Railway
 * zamieniony na liczbę do #598.
 *
 * @bez-kontroli-dodatniej Uruchamia skrypt na zbudowanym w teście dzienniku i asertuje na jego wyjściu i kodzie wyjścia; nie czyta treści źródeł aplikacji.
 */
class SzczytPolaczenZDziennikaTest extends TestCase
{
    /**
     * @return array{0: int, 1: string}
     */
    private function uruchom(string $dziennik): array
    {
        $plik = tempnam(sys_get_temp_dir(), 'dziennik-598-');
        file_put_contents($plik, $dziennik);

        try {
            $proces = new Process([PHP_BINARY, dirname(__DIR__, 2).'/scripts/szczyt-polaczen-z-dziennika.php', $plik]);
            $proces->run();
        } finally {
            unlink($plik);
        }

        return [(int) $proces->getExitCode(), $proces->getOutput().$proces->getErrorOutput()];
    }

    private function kontekst(int $zajete, string $stan = 'spokojny'): string
    {
        return json_encode([
            'stan' => $stan, 'zajete_serwer' => $zajete, 'zajete_baza' => $zajete, 'aktywne' => 1,
            'bezczynne' => $zajete - 1, 'w_transakcji' => 0, 'dostepne' => 497, 'max_connections' => 500,
            'budzet_szczytowy' => 16, 'prog_ostrzegawczy' => 50,
        ], JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function podaje_maksimum_i_chwile_maksimum_ze_wszystkich_trzech_ksztaltow_linii(): void
    {
        $linie = [
            // kształt 1: LineFormatter
            '[2026-09-26 10:25:02] production.INFO: kuking:budzet-polaczen '.$this->kontekst(2).' ',
            // szum z tej samej sekundy — ma zostać pominięty
            '[2026-09-26 10:25:02] production.INFO: Running [kuking:budzet-polaczen] ... DONE',
            // kształt 2: JsonFormatter Monologa
            json_encode(['message' => 'kuking:budzet-polaczen', 'context' => json_decode($this->kontekst(9), true), 'datetime' => '2026-09-26T11:25:03+00:00']),
            // kształt 3: eksport Railway, LineFormatter w polu "message"
            json_encode(['timestamp' => '2026-09-26T12:25:01Z', 'message' => '[2026-09-26 12:25:01] production.INFO: kuking:budzet-polaczen '.$this->kontekst(4)]),
            // okno --probki liczone osobno
            '[2026-09-26 13:02:00] production.INFO: kuking:budzet-polaczen:szczyt {"stan":"spokojny","probki":120,"nieudane":0,"odstep_sekundy":1,"szczyt_zajete_serwer":12,"szczyt_zajete_baza":12,"szczyt_o":"2026-09-26T13:01:17+00:00","dostepne":497,"budzet_szczytowy":16,"prog_ostrzegawczy":50}',
        ];

        [$kod, $wyjscie] = $this->uruchom(implode("\n", $linie)."\n");

        $this->assertSame(0, $kod, $wyjscie);
        $this->assertStringContainsString('czujka godzinna (:25): 3 pomiarów', $wyjscie);
        $this->assertStringContainsString('min 2 · mediana 4 · MAX 9 (o 2026-09-26T11:25:03+00:00)', $wyjscie);
        $this->assertStringContainsString('okna --probki: 1 pomiarów', $wyjscie);
        $this->assertStringContainsString('MAX 12 (o 2026-09-26T13:01:17+00:00)', $wyjscie);
        $this->assertStringContainsString('stany czujki godzinnej: spokojny=3', $wyjscie);
    }

    #[Test]
    public function brak_linii_pomiaru_nie_jest_zielonym_wynikiem(): void
    {
        [$kod, $wyjscie] = $this->uruchom("[2026-09-26 10:25:02] production.INFO: Running [kuking:budzet-polaczen] ... DONE\n");

        $this->assertSame(2, $kod, $wyjscie);
        $this->assertStringContainsString('szeregu NIE MA', $wyjscie);
    }
}
