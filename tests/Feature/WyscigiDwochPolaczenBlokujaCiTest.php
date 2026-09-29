<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Job `dwa-polaczenia` (D-105) BLOKUJE CI — #611, etap 9.
 *
 * Do 29.09.2026 miał `continue-on-error: true` z warunkiem zdjęcia „dwadzieścia
 * kolejnych przebiegów bez czerwieni". Warunek spełniono (38 zielonych
 * przebiegów, zero czerwonych kroków — szczegóły w komentarzu przy jobie).
 * Flaga wracała jednak po cichu przy każdym „tymczasowym" uspokajaniu CI, a jej
 * skutek jest niewidoczny: przy `continue-on-error` pole `conclusion` joba
 * jest `success` także po padniętym kroku, więc czerwona grupa wyścigów
 * wyglądałaby w podsumowaniu jak zielona.
 *
 * Ten test pilnuje trzech rzeczy: flaga nie wraca, job nadal uruchamia
 * prawdziwą grupę (bez tego „nie ma flagi" nic nie znaczy — kontrola dodatnia)
 * i nazwa nie obiecuje już, że job „nie blokuje".
 */
class WyscigiDwochPolaczenBlokujaCiTest extends TestCase
{
    public function test_job_wyscigow_nie_ma_continue_on_error_i_uruchamia_grupe(): void
    {
        $job = $this->job('dwa-polaczenia');

        $this->assertStringNotContainsString(
            'continue-on-error',
            $job,
            'Job `dwa-polaczenia` znów ma continue-on-error: czerwone wyścigi wyglądałyby jak zielone. '
            .'Gdy grupa migocze, napraw test (D-105, „Gdy grupa zacznie migać"), nie przywracaj flagi.',
        );

        // Kontrola dodatnia: job realnie uruchamia grupę na własnej bazie.
        $this->assertStringContainsString('run: ./scripts/testy-dwa-polaczenia.sh', $job);
        $this->assertStringContainsString('image: postgres:18', $job);
        $this->assertMatchesRegularExpression("/^    if: .*needs\\.zakres\\.outputs\\.wyscigi == 'true'/m", $job);
    }

    public function test_nazwa_joba_nie_obiecuje_ze_nie_blokuje(): void
    {
        $this->assertSame(1, preg_match('/^    name: (.+)$/m', $this->job('dwa-polaczenia'), $nazwa));
        $this->assertStringNotContainsStringIgnoringCase('nie blokuje', $nazwa[1]);
    }

    private function job(string $name): string
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
        $matched = preg_match('/^  '.preg_quote($name, '/').':(?:\r\n|\n|\r)(.*?)(?=^  [a-z_-]+:|\z)/ms', $workflow, $matches);
        $this->assertSame(1, $matched, 'Brak sprawdzanego joba CI: '.$name);

        // Komentarze lecą: `ci.yml` jest w połowie dokumentacją i opisuje flagę słowami.
        return (string) preg_replace('/^\s*#.*$/m', '', $matches[1]);
    }
}
