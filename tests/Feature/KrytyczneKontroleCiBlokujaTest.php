<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Krytyczne kontrole CI blokują merge — #2215.
 *
 * `continue-on-error` na jobie albo kroku sprawia, że pole `conclusion` jest
 * `success` także po padniętym kroku, więc czerwona kontrola wygląda jak zielona.
 * Test robi trzy rzeczy: (1) job audytu zależności nie ma tej flagi i naprawdę
 * uruchamia bramkę (kontrola dodatnia), (2) `continue-on-error` w `ci.yml` wolno
 * mieć tylko dwóm znanym, opisanym krokom pomocniczym (wysyłka artefaktów),
 * (3) nazwy jobów bezpieczeństwa nie obiecują, że „nie blokują".
 * Job `dwa-polaczenia` ma własny strażnik: `WyscigiDwochPolaczenBlokujaCiTest`.
 */
class KrytyczneKontroleCiBlokujaTest extends TestCase
{
    /** Jedyne joby, w których `continue-on-error` jest świadomym wyjątkiem: krok wysyłki artefaktu, nie kontrola. */
    /*
     * `kaskada` (2): flaga na jobie strażnika martwych reguł CSS — nieblokujący
     * z decyzji właściciela do 06.10.2026 (#960, D-333; termin i warunek zdjęcia
     * przy jobie w `ci.yml`) — oraz krok zapisu artefaktu. Przy zdjęciu flagi
     * z joba zmień tu 2 na 1 w tym samym PR-ze.
     */
    private const DOZWOLONE = ['kontrole_krotkie' => 1, 'kaskada' => 2, 'port_panelu' => 1];

    public function test_job_audytu_nie_ma_continue_on_error_i_uruchamia_bramke(): void
    {
        // #2299: kroki audytu są w jobie `kontrole_krotkie`; `audit` to lustro
        // dawnej nazwy (pilnuje go `KrotkieKontroleWJednymJobieTest`).
        $job = $this->job('kontrole_krotkie');

        // Jedyna flaga w tym jobie stoi na wysyłce artefaktu assetów (DOZWOLONE
        // niżej) — żadna na jobie ani na krokach audytu.
        preg_match_all('/^      - name: ([^\n]+)\n(?:(?:        [^\n]*)?\n)*?        continue-on-error/m', $job, $zFlaga);
        $this->assertTrue(
            preg_match('/^    continue-on-error/m', $job) === 0 && $zFlaga[1] === ['Zapis artefaktu'],
            'Job `kontrole_krotkie` (audyt) znów ma continue-on-error: wysoka albo krytyczna podatność wyglądałaby jak zielone CI. '
            .'Pilny hotfix odblokowuje jawny, datowany wyjątek w .github/wyjatki-audytu.json, nie flaga.',
        );

        // Kontrola dodatnia: oba audyty i skrypt bramki naprawdę biegną.
        $this->assertStringContainsString('composer audit --locked', $job);
        $this->assertStringContainsString('npm audit --json', $job);
        $this->assertStringContainsString('python3 scripts/audyt-zaleznosci.py', $job);
        $this->assertStringContainsString('.github/wyjatki-audytu.json', $job);
        $this->assertStringContainsString('exit "${PIPESTATUS[0]}"', $job);
        $this->assertMatchesRegularExpression("/^    if: needs\\.zakres\\.outputs\\.kod == 'true'/m", $job);
    }

    public function test_continue_on_error_w_ci_tylko_w_znanych_krokach_pomocniczych(): void
    {
        $znalezione = [];
        $job = null;
        foreach (preg_split('/\r\n|\n|\r/', $this->workflow()) ?: [] as $linia) {
            if (preg_match('/^  ([a-z_0-9-]+):\s*$/', $linia, $m) === 1) {
                $job = $m[1];
            }
            if (preg_match('/^\s*#/', $linia) === 1) {
                continue;
            }
            if (str_contains($linia, 'continue-on-error')) {
                $znalezione[(string) $job] = ($znalezione[(string) $job] ?? 0) + 1;
            }
        }
        ksort($znalezione);
        $oczekiwane = self::DOZWOLONE;
        ksort($oczekiwane);

        $this->assertSame(
            $oczekiwane,
            $znalezione,
            'Nowe continue-on-error w ci.yml. Kontrola bezpieczeństwa albo wyścigów nie może go mieć; '
            .'krok pomocniczy dopisz do DOZWOLONE razem z uzasadnieniem w komentarzu przy kroku.',
        );
    }

    public function test_nazwa_joba_audytu_nie_obiecuje_ze_nie_blokuje(): void
    {
        $this->assertSame(1, preg_match('/^    name: (.+)$/m', $this->job('audit'), $nazwa));
        $this->assertStringNotContainsStringIgnoringCase('nie blokuje', $nazwa[1]);
        $this->assertStringContainsString('blokuje', $nazwa[1]);
    }

    private function workflow(): string
    {
        return (string) file_get_contents(base_path('.github/workflows/ci.yml'));
    }

    private function job(string $name): string
    {
        $matched = preg_match('/^  '.preg_quote($name, '/').':(?:\r\n|\n|\r)(.*?)(?=^  [a-z_-]+:|\z)/ms', $this->workflow(), $matches);
        $this->assertSame(1, $matched, 'Brak sprawdzanego joba CI: '.$name);

        // Komentarze lecą: `ci.yml` jest w połowie dokumentacją i opisuje flagę słowami.
        return (string) preg_replace('/^\s*#.*$/m', '', $matches[1]);
    }
}
