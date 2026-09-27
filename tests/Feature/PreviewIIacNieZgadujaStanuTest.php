<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DWA MIEJSCA, W KTÓRYCH WORKFLOW ZGADYWAŁ STAN ZAMIAST GO ODCZYTAĆ
 *
 *  #1389 — `preview.yml` uznawał sam adres deploymentu za gotowość
 *          środowiska i kończył czekanie na pierwszym `web_url`, zanim
 *          Railway skończył budować. Brak preview dawał `found=false`
 *          i zielony job z pominiętym testem.
 *  #1390 — `railway-iac.yml` nie przekazywał `KUKING_WAIT_FOR_CI`, a
 *          `railway.ts` czytał brak zmiennej jako `checkSuites: false`,
 *          więc automatyczny apply zdejmował bramkę „Wait for CI”.
 *
 * Zachowanie czekania sprawdza na atrapie `gh`
 * `tests/skrypty/kontrola-czekania-preview.sh` (uruchamia go `check.sh`).
 * Ten plik pilnuje drugiej połowy: że workflow tej logiki UŻYWA i że stara
 * nie wróciła obok. GitHub Actions nie da się uruchomić z testu, więc
 * czytamy pliki. Kontrole dodatnie: scripts/kontrole-negatywne-alfa08.py.
 */
class PreviewIIacNieZgadujaStanuTest extends TestCase
{
    private function plik(string $sciezka): string
    {
        $pelna = base_path($sciezka);

        $this->assertFileExists($pelna, "Nie ma {$sciezka}.");

        return (string) file_get_contents($pelna);
    }

    /** Kod bez komentarzy — komentarze mogą cytować starą logikę. */
    private function bezKomentarzy(string $tekst): string
    {
        return (string) preg_replace('/^\s*(#|\/\/).*$/m', '', $tekst);
    }

    /** Treść joba — od `  <nazwa>:` do następnego joba na tym samym wcięciu. */
    private function job(string $workflow, string $nazwa): string
    {
        $this->assertSame(
            1,
            preg_match('/^  '.preg_quote($nazwa, '/').':\n(?:(?:    |\s*$|  #).*\n?)+/m', $workflow, $dopasowanie),
            "Nie znalazłem joba „{$nazwa}” — test czyta zły kształt pliku.",
        );

        return $dopasowanie[0];
    }

    #[Test]
    public function preview_ma_prawo_odczytu_deploymentow(): void
    {
        // Bez `deployments: read` token w prywatnym repo nie odczyta statusów
        // deploymentu, a czekanie na `success` zawsze kończy się limitem.
        $this->assertMatchesRegularExpression(
            '/^permissions:\n(?:(?:  .*)?\n)*?  deployments: read$/m',
            $this->bezKomentarzy($this->plik('.github/workflows/preview.yml')),
            'preview.yml nie ma `deployments: read` — czekanie na status deploymentu nie zadziała w prywatnym repo.',
        );
    }

    #[Test]
    public function preview_czeka_na_status_success_a_nie_na_sam_adres(): void
    {
        $smoke = $this->bezKomentarzy($this->job($this->plik('.github/workflows/preview.yml'), 'smoke'));

        $checkout = strpos($smoke, 'actions/checkout@');
        $zrodlo = strpos($smoke, 'source scripts/czekaj-na-preview.sh');
        $czekanie = strpos($smoke, 'if ! czekaj_na_preview "$REPO" "$SHA"; then');

        $this->assertNotFalse($zrodlo, 'Krok czekania nie używa scripts/czekaj-na-preview.sh (#1389).');
        $this->assertNotFalse($czekanie, 'Krok czekania nie woła czekaj_na_preview dla SHA z PR-a.');
        $this->assertNotFalse($checkout, 'Bez checkoutu biblioteki czekania nie ma na runnerze.');
        $this->assertLessThan($zrodlo, $checkout, 'Checkout ma iść przed krokiem, który wczytuje bibliotekę.');
        $this->assertMatchesRegularExpression(
            '/if ! czekaj_na_preview[^\n]*\n(?:[^\n]*\n){0,2}?\s*exit 1/',
            $smoke,
            'Niegotowe preview ma kończyć job porażką, a nie pomijać test na zielono.',
        );
        $this->assertStringNotContainsString(
            'found=false',
            $smoke,
            'Wrócił wynik `found=false` — brak preview znów daje zielony job bez testu (#1389).',
        );
        $this->assertStringNotContainsString(
            'payload.web_url',
            $smoke,
            'Workflow znowu wyciąga adres z deploymentu sam — adres to nie gotowość (#1389).',
        );
    }

    #[Test]
    public function preview_przed_sprawdzeniami_potwierdza_commit_pod_adresem(): void
    {
        $smoke = $this->bezKomentarzy($this->job($this->plik('.github/workflows/preview.yml'), 'smoke'));

        $this->assertMatchesRegularExpression(
            '/OCZEKIWANY_SHA:\s*\$\{\{\s*github\.event\.pull_request\.head\.sha\s*\}\}/',
            $smoke,
        );
        $sonda = strpos($smoke, 'if ! sonda_wydanie "$BASE_URL" "$OCZEKIWANY_SHA"; then');
        $pierwszyCheck = strpos($smoke, 'check /health');

        $this->assertNotFalse($pierwszyCheck, 'Test dymny preview nie ma już `check /health` — test czyta zły kształt.');
        $this->assertNotFalse($sonda, 'Test dymny preview nie pyta /wydanie o commit PR-a (#1389).');
        $this->assertLessThan($pierwszyCheck, $sonda, 'Sonda wydania ma iść PRZED sprawdzeniami.');
    }

    #[Test]
    public function oba_joby_iac_dostaja_bramke_ci_z_jednego_zrodla_i_ja_waliduja(): void
    {
        $workflow = $this->plik('.github/workflows/railway-iac.yml');

        foreach (['plan' => 'command: plan', 'apply' => 'command: apply'] as $nazwa => $komenda) {
            $job = $this->bezKomentarzy($this->job($workflow, $nazwa));

            $this->assertMatchesRegularExpression(
                '/^    env:\n      KUKING_WAIT_FOR_CI: \$\{\{ vars\.KUKING_WAIT_FOR_CI \}\}$/m',
                $job,
                "Job „{$nazwa}” nie przekazuje KUKING_WAIT_FOR_CI — railway.ts policzy bramkę bez niej (#1390).",
            );

            $bramka = strpos($job, '- name: Bramka Wait for CI');
            $railway = strpos($job, $komenda);

            $this->assertNotFalse($railway, "Job „{$nazwa}” nie ma już `{$komenda}` — test czyta zły kształt.");
            $this->assertNotFalse($bramka, "Job „{$nazwa}” nie waliduje KUKING_WAIT_FOR_CI przed Railway.");
            $this->assertLessThan($railway, $bramka, "Walidacja bramki ma iść PRZED `{$komenda}`.");
            $this->assertStringContainsString('true|false) ;;', $job);
        }
    }

    #[Test]
    public function railway_ts_nie_czyta_braku_zmiennej_jako_wylaczenia(): void
    {
        $kod = $this->bezKomentarzy($this->plik('.railway/railway.ts'));

        $this->assertStringContainsString('const bramkaCI = process.env.KUKING_WAIT_FOR_CI;', $kod);
        $this->assertMatchesRegularExpression(
            '/if \(bramkaCI !== "true" && bramkaCI !== "false"\) \{\s*throw new Error\(/',
            $kod,
            'railway.ts ma odmówić przy braku albo złej wartości KUKING_WAIT_FOR_CI (#1390).',
        );
        $this->assertStringNotContainsString(
            'process.env.KUKING_WAIT_FOR_CI === "true"',
            $kod,
            'Wróciło `=== "true"` — brak zmiennej znowu po cichu wyłącza „Wait for CI” (#1390).',
        );
        $this->assertStringContainsString('checkSuites: czekajNaCI,', $kod);
    }
}
