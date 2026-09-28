<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * DANE ZDARZENIA WDROŻENIOWEGO TO DANE, NIE KOD (issue #1851).
 *
 * Krok „Ustal adres środowiska" w `.github/workflows/deploy.yml` miał:
 *
 *     env_name='${{ github.event.deployment.environment }}'
 *
 * GitHub podstawia wyrażenie do treści skryptu, ZANIM uruchomi Basha. Nazwa
 * środowiska `production' ; touch /tmp/x ; #` zamykała literał i dopisywała
 * polecenie wykonywane na runnerze (także na własnych maszynach z
 * CI_RUNS_ON).
 *
 * Ten plik pilnuje trzech rzeczy:
 *  1. w żadnym `run:` tego workflow nie ma wyrażenia `${{ … }}` — dane idą
 *     przez `env:`;
 *  2. krok, URUCHOMIONY na złośliwej wartości, niczego nie wykonuje i nie
 *     przepuszcza jej do `$GITHUB_OUTPUT` (nowa linia dopisałaby `url=…`);
 *  3. dozwolone są wyłącznie production, staging i preview-*.
 */
class DeployNieWklejaDanychZdarzeniaDoPowlokiTest extends TestCase
{
    private function workflow(): string
    {
        $sciezka = base_path('.github/workflows/deploy.yml');

        $this->assertFileExists($sciezka, 'Nie ma .github/workflows/deploy.yml.');

        return (string) file_get_contents($sciezka);
    }

    /**
     * Treści wszystkich bloków `run: |` — linie wcięte głębiej niż sam klucz.
     *
     * @return list<string>
     */
    private function blokiRun(): array
    {
        $linie = explode("\n", $this->workflow());
        $bloki = [];

        for ($i = 0, $n = count($linie); $i < $n; $i++) {
            if (! preg_match('/^(\s*)(?:- )?run:\s*(.*)$/', $linie[$i], $m)) {
                continue;
            }

            $wciecie = strlen($m[1]);
            $reszta = trim($m[2]);

            if ($reszta !== '|' && $reszta !== '>' && $reszta !== '|-' && $reszta !== '>-') {
                $bloki[] = $reszta;

                continue;
            }

            $tresc = [];
            for ($j = $i + 1; $j < $n; $j++) {
                $linia = $linie[$j];
                if (trim($linia) !== '' && strlen($linia) - strlen(ltrim($linia)) <= $wciecie) {
                    break;
                }
                $tresc[] = $linia;
            }
            $bloki[] = implode("\n", $tresc);
            $i = $j - 1;
        }

        return $bloki;
    }

    /** Skrypt kroku `id: target`. */
    private function skryptKrokuTarget(): string
    {
        $workflow = $this->workflow();
        $poczatek = strpos($workflow, "- name: Ustal adres środowiska\n");
        $this->assertNotFalse($poczatek, 'W deploy.yml nie ma kroku „Ustal adres środowiska".');

        $koniec = strpos($workflow, '- name: ', $poczatek + 10);
        $krok = substr($workflow, $poczatek, $koniec === false ? null : $koniec - $poczatek);

        $this->assertMatchesRegularExpression('/^\s*id:\s*target$/m', $krok);
        $this->assertSame(1, preg_match('/^( *)run: \|\n((?:\1 .*\n|\n)+)/m', $krok, $m), 'Krok target nie ma bloku `run: |`.');

        $wciecie = strlen($m[1]) + 2;

        return (string) preg_replace('/^ {'.$wciecie.'}/m', '', $m[2]);
    }

    /** @return array{0: int, 1: string, 2: string} kod wyjścia, $GITHUB_OUTPUT, stdout */
    private function uruchomTarget(string $srodowisko, string $stan = 'success'): array
    {
        $wyjscie = tempnam(sys_get_temp_dir(), 'gh-out-');
        $this->assertNotFalse($wyjscie);

        $proces = new Process(['bash', '-c', $this->skryptKrokuTarget()], null, [
            'ZDARZENIE_SRODOWISKO' => $srodowisko,
            'ZDARZENIE_STAN' => $stan,
            'GITHUB_OUTPUT' => $wyjscie,
        ]);
        $proces->run();

        $zawartosc = (string) file_get_contents($wyjscie);
        @unlink($wyjscie);

        return [(int) $proces->getExitCode(), $zawartosc, $proces->getOutput()];
    }

    #[Test]
    public function test_zaden_run_nie_zawiera_wyrazen_github(): void
    {
        $bloki = $this->blokiRun();

        // Kotwica: parser naprawdę znalazł skrypty, a nie pustkę.
        $this->assertGreaterThan(5, count($bloki), 'Parser nie znalazł bloków `run:` w deploy.yml.');

        foreach ($bloki as $blok) {
            $this->assertStringNotContainsString(
                '${{',
                $blok,
                "Blok `run:` w deploy.yml zawiera wyrażenie GitHuba — podstawiane do kodu przed uruchomieniem powłoki (#1851). Przekaż wartość przez `env:`.\n".mb_substr($blok, 0, 400),
            );
        }
    }

    #[Test]
    public function test_krok_target_czyta_zdarzenie_przez_env(): void
    {
        $this->assertMatchesRegularExpression(
            '/ZDARZENIE_SRODOWISKO:\s*\$\{\{\s*github\.event\.deployment\.environment\s*\}\}/',
            $this->workflow(),
        );
        $this->assertMatchesRegularExpression(
            '/ZDARZENIE_STAN:\s*\$\{\{\s*github\.event\.deployment_status\.state\s*\}\}/',
            $this->workflow(),
        );
    }

    #[Test]
    public function test_zlosliwa_nazwa_srodowiska_niczego_nie_wykonuje(): void
    {
        $znacznik = sys_get_temp_dir().'/kuking-workflow-injection-'.bin2hex(random_bytes(4));

        $zlosliwe = [
            "production' ; touch {$znacznik} ; #",
            "\$(touch {$znacznik})",
            "`touch {$znacznik}`",
            "production\nurl=https://zly.example",
            "ideal-exploration / production\nurl=https://zly.example",
            '::add-mask::cokolwiek',
            'x / preview-a$b',
            str_repeat('a', 200),
        ];

        foreach ($zlosliwe as $wartosc) {
            [$kod, $wyjscie] = $this->uruchomTarget($wartosc);

            $this->assertSame(0, $kod, 'Krok target padł na wartości: '.json_encode($wartosc));
            $this->assertFileDoesNotExist($znacznik, 'Wartość ze zdarzenia została WYKONANA: '.json_encode($wartosc));
            $this->assertStringNotContainsString('url=', $wyjscie, 'Złośliwa wartość ustawiła adres: '.json_encode($wartosc));
            $this->assertStringContainsString("pomin=1\n", $wyjscie);
            $this->assertStringContainsString("env=nieprawidlowe\n", $wyjscie);
        }

        // To samo dla stanu wdrożenia.
        [$kod, $wyjscie] = $this->uruchomTarget('production', "success' ; touch {$znacznik} ; #");
        $this->assertSame(0, $kod);
        $this->assertFileDoesNotExist($znacznik);
        $this->assertStringNotContainsString('url=', $wyjscie);
    }

    #[Test]
    public function test_dozwolone_srodowiska_dzialaja_jak_przedtem(): void
    {
        $przypadki = [
            'ideal-exploration / production' => ["url=https://kuking.pl\n", "env=production\n", false],
            'ideal-exploration / staging' => ["url=https://staging.kuking.pl\n", "env=staging\n", false],
            'production' => ["url=https://kuking.pl\n", "env=production\n", false],
            'ideal-exploration / preview-123' => [null, "env=preview-123\n", true],
            'ideal-exploration / cos-innego' => [null, "env=nieprawidlowe\n", true],
        ];

        foreach ($przypadki as $wejscie => [$url, $env, $pomin]) {
            [$kod, $wyjscie] = $this->uruchomTarget($wejscie);

            $this->assertSame(0, $kod, "Krok target padł na '{$wejscie}'.");
            $this->assertStringContainsString($env, $wyjscie, "Zła nazwa środowiska dla '{$wejscie}'.");
            if ($url !== null) {
                $this->assertStringContainsString($url, $wyjscie, "Zły adres dla '{$wejscie}'.");
            } else {
                $this->assertStringNotContainsString('url=', $wyjscie);
            }
            $this->assertSame($pomin, str_contains($wyjscie, "pomin=1\n"), "Złe pomin dla '{$wejscie}'.");
        }
    }

    #[Test]
    public function test_token_ma_tylko_odczyt_zawartosci(): void
    {
        $this->assertSame(
            1,
            preg_match('/^permissions:\n((?:  .*\n)+)/m', $this->workflow(), $m),
            'deploy.yml nie ustawia `permissions:` na poziomie workflow.',
        );
        $this->assertSame("  contents: read\n", $m[1], 'deploy.yml ma szersze uprawnienia tokenu niż `contents: read` (#1851).');
    }
}
