<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * SYGNAŁ DLA WDROŻENIA, KTÓRE KOŃCZY SIĘ BEZ SUKCESU (#611, etap 6).
 *
 * `verify` w `deploy.yml` chodzi tylko na `deployment_status: success`.
 * Wdrożenie, które padło (`failure`, `error`) albo zniknęło (`inactive` bez
 * wcześniejszego `success`), nie zostawiało śladu. Job `alarm_bez_sukcesu`
 * robi z tego czerwony job z polskim komunikatem; `success` -> `inactive`
 * (zastąpienie nowszym wdrożeniem) nie alarmuje.
 *
 * Logika decyzji siedzi w `scripts/ci/stan-wdrozenia.sh`; tabelę przypadków
 * i kontrolę ujemną trzyma `tests/skrypty/stan-wdrozenia.sh` (tu uruchamiana
 * w zestawie PHPUnit, obok `scripts/kontrole-powloki.sh`). Ten plik pilnuje
 * połączenia: workflow parsowany prawdziwym YAML-em (PyYAML, `safe_load`),
 * składnia skryptów i to, że job nie łączy się z Railway.
 *
 * @bez-kontroli-dodatniej Struktura workflow jest asertowana na sparsowanym YAML-u (dokładnie jeden job o tej nazwie, liczby kroków), a asercje tekstowe dotyczą wyjścia skryptów; kontrole ujemne skryptu robi tests/skrypty/stan-wdrozenia.sh.
 */
#[Group('ci')]
class DeployAlarmujeGdyWdrozenieKonczySieBezSukcesuTest extends TestCase
{
    private const SKRYPT = 'scripts/ci/stan-wdrozenia.sh';

    /** @return array<string, mixed> */
    private function workflow(): array
    {
        $proces = new Process([
            'python3', '-c',
            'import sys, json, yaml; print(json.dumps(yaml.safe_load(open(sys.argv[1], encoding="utf-8"))))',
            base_path('.github/workflows/deploy.yml'),
        ]);
        $proces->run();
        $this->assertSame(0, $proces->getExitCode(), 'yaml.safe_load nie przeczytał deploy.yml: '.$proces->getErrorOutput());

        $dane = json_decode($proces->getOutput(), true);
        $this->assertIsArray($dane);

        return $dane;
    }

    /** @return array<string, mixed> */
    private function job(): array
    {
        $joby = $this->workflow()['jobs'] ?? [];
        $this->assertArrayHasKey('alarm_bez_sukcesu', $joby, 'deploy.yml nie ma joba alarm_bez_sukcesu.');

        return $joby['alarm_bez_sukcesu'];
    }

    public function test_tabela_przypadkow_i_kontrola_ujemna_przechodza(): void
    {
        $proces = new Process(['bash', 'tests/skrypty/stan-wdrozenia.sh'], base_path());
        $proces->setTimeout(120);
        $proces->run();

        $this->assertSame(0, $proces->getExitCode(), $proces->getOutput().$proces->getErrorOutput());
        $this->assertStringContainsString('Stan wdrożenia: OK', $proces->getOutput());
        // Kontrola dodatnia przyrządu: kontrola ujemna naprawdę poszła po regułach.
        $this->assertSame(7, substr_count($proces->getOutput(), ': mutacja zapaliła tabelę'));
    }

    public function test_job_chodzi_na_trzech_terminalnych_stanach_bez_sukcesu_i_tylko_na_deployment_status(): void
    {
        $warunek = (string) $this->job()['if'];

        $this->assertStringContainsString("github.event_name == 'deployment_status'", $warunek);
        foreach (['inactive', 'failure', 'error'] as $stan) {
            $this->assertStringContainsString("github.event.deployment_status.state == '{$stan}'", $warunek);
        }
        // `success` obsługuje `verify`; ten job nie może go zaalarmować.
        $this->assertStringNotContainsString("== 'success'", $warunek);
    }

    public function test_job_ma_odczyt_wdrozen_i_zadnych_uprawnien_zapisu(): void
    {
        $uprawnienia = $this->job()['permissions'];

        $this->assertSame(['contents' => 'read', 'deployments' => 'read'], $uprawnienia);
    }

    public function test_job_uruchamia_skrypt_decyzji_i_nie_liczy_niczego_w_yamlu(): void
    {
        $kroki = $this->job()['steps'];
        $uruchomienia = array_values(array_filter(array_map(fn ($k) => $k['run'] ?? null, $kroki)));

        $skrypty = array_values(array_filter($uruchomienia, fn ($r) => str_contains($r, self::SKRYPT)));
        $this->assertCount(1, $skrypty, 'Dokładnie jeden krok woła skrypt decyzji.');
        $this->assertSame('bash '.self::SKRYPT, trim($skrypty[0]));

        $ocena = array_values(array_filter($kroki, fn ($k) => ($k['name'] ?? '') === 'Oceń stan wdrożenia'));
        $this->assertCount(1, $ocena);
        $this->assertSame(
            ['STAN', 'STAN_CZAS', 'SRODOWISKO', 'SHA', 'HISTORIA_PLIK'],
            array_keys($ocena[0]['env']),
        );

        // Dane zdarzenia idą przez env:, nigdy do treści `run:` (#1851).
        foreach ($uruchomienia as $run) {
            $this->assertStringNotContainsString('${{', $run);
        }
        $this->assertFileExists(base_path(self::SKRYPT));
    }

    public function test_job_nie_laczy_sie_z_railway(): void
    {
        $tresc = json_encode($this->job());
        $skrypt = (string) file_get_contents(base_path(self::SKRYPT));

        foreach ([$tresc, $skrypt] as $zrodlo) {
            $this->assertDoesNotMatchRegularExpression('/RAILWAY_|railway\.(app|com)|\brailway (up|run|redeploy|logs|login)\b|secrets\./i', $zrodlo);
            $this->assertStringNotContainsString('curl', $zrodlo);
        }
        // Historia statusów idzie z GitHuba, tokenem zadania.
        $this->assertStringContainsString('repos/${REPO}/deployments/${DEPLOYMENT_ID}/statuses', $this->tekstKrokuHistorii());
    }

    public function test_skrypty_maja_poprawna_skladnie_i_sa_w_kontrolach_powloki(): void
    {
        foreach ([self::SKRYPT, 'tests/skrypty/stan-wdrozenia.sh'] as $plik) {
            $proces = new Process(['bash', '-n', $plik], base_path());
            $proces->run();
            $this->assertSame(0, $proces->getExitCode(), "bash -n {$plik}: ".$proces->getErrorOutput());
        }

        $powloka = (string) file_get_contents(base_path('scripts/kontrole-powloki.sh'));
        $this->assertMatchesRegularExpression('/^tests\/skrypty\/stan-wdrozenia\.sh\|/m', $powloka, 'Test decyzji o stanie wdrożenia wypadł z listy testów powłoki.');
    }

    public function test_inactive_po_sukcesie_nie_alarmuje_a_bez_sukcesu_alarmuje_na_prawdziwym_skrypcie(): void
    {
        $historia = tempnam(sys_get_temp_dir(), 'stan-wdr');
        $this->assertIsString($historia);

        try {
            file_put_contents($historia, "2026-09-29T10:00:01Z in_progress\n2026-09-29T10:00:02Z success\n2026-09-29T10:00:03Z inactive\n");
            $zastapione = $this->ocen('inactive', $historia);
            $this->assertSame(0, $zastapione->getExitCode(), $zastapione->getOutput());
            $this->assertStringNotContainsString('::error', $zastapione->getOutput());

            file_put_contents($historia, "2026-09-29T10:00:01Z in_progress\n2026-09-29T10:00:03Z inactive\n");
            $porzucone = $this->ocen('inactive', $historia);
            $this->assertSame(1, $porzucone->getExitCode());
            $this->assertStringContainsString('::error title=Wdrożenie zniknęło bez sukcesu::', $porzucone->getOutput());
        } finally {
            @unlink($historia);
        }
    }

    private function ocen(string $stan, string $historia): Process
    {
        $proces = new Process(['bash', self::SKRYPT], base_path(), [
            'STAN' => $stan,
            'HISTORIA_PLIK' => $historia,
            'SRODOWISKO' => 'production',
            'SHA' => '0123456789abcdef0123456789abcdef01234567',
        ]);
        $proces->run();

        return $proces;
    }

    private function tekstKrokuHistorii(): string
    {
        foreach ($this->job()['steps'] as $krok) {
            if (($krok['id'] ?? '') === 'historia') {
                return (string) $krok['run'];
            }
        }

        $this->fail('Brak kroku z historią statusów.');
    }
}
