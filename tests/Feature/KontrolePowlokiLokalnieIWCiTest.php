<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Testy powłoki chodzą w CI dokładnie te same, co w `scripts/check.sh`
 * (audyt A4 5.1).
 *
 * Do 25 września 2026 `check.sh` uruchamiał `bash -n` na skryptach i pięć
 * zestawów z `tests/skrypty/`, a job `lint` w CI — tylko `kopia-bazy.sh`.
 * Regresja w entrypoincie kontenera albo w sondach testu dymnego wdrożenia
 * przechodziła przez zielone CI. Teraz lista żyje w jednym pliku,
 * `scripts/kontrole-powloki.sh`, a ten test pilnuje, żeby oba miejsca go
 * wołały i żeby żadne z nich nie dokładało testów powłoki obok niego.
 *
 * Samego skryptu tu nie uruchamiamy: `kopia-bazy.sh` wymaga klienta
 * `pg_restore` 18, a cały zestaw trwa kilkadziesiąt sekund. Uruchamia go
 * job `lint`.
 */
class KontrolePowlokiLokalnieIWCiTest extends TestCase
{
    private const WSPOLNY = 'scripts/kontrole-powloki.sh';

    /** Zestawy, które `check.sh` uruchamiał przed wydzieleniem — nie może ubyć żadnego. */
    private const ZESTAWY = [
        'tests/skrypty/entrypoint-nadzor.sh',
        'tests/skrypty/kopia-bazy.sh',
        'tests/skrypty/cache-assetow.sh',
        'tests/skrypty/kontrola-ujemna.sh',
        'tests/skrypty/kontrola-sondy-wdrozenia.sh',
    ];

    private function plik(string $sciezka): string
    {
        return (string) file_get_contents(base_path($sciezka));
    }

    /** Treść joba `lint` z ci.yml — od nagłówka do następnego joba. */
    private function jobLint(): string
    {
        $ci = $this->plik('.github/workflows/ci.yml');

        $this->assertSame(1, preg_match('/^  lint:\n(.*?)(?=^  [a-z0-9_]+:\n)/ms', $ci, $m), 'Nie ma joba `lint` w ci.yml.');

        return $m[1];
    }

    public function test_job_lint_w_ci_woła_wspolny_skrypt(): void
    {
        $this->assertStringContainsString('run: bash '.self::WSPOLNY, $this->jobLint());
    }

    public function test_check_sh_woła_wspolny_skrypt(): void
    {
        $this->assertStringContainsString('bash '.self::WSPOLNY, $this->plik('scripts/check.sh'));
    }

    public function test_wspolny_skrypt_ma_wszystkie_zestawy_i_skladnie(): void
    {
        $wspolny = $this->plik(self::WSPOLNY);

        foreach (self::ZESTAWY as $zestaw) {
            $this->assertMatchesRegularExpression('/^'.preg_quote($zestaw, '/').'\|/m', $wspolny, "{$zestaw} wypadł z listy.");
            $this->assertFileExists(base_path($zestaw));
        }

        $this->assertStringContainsString('bash -n "$skrypt"', $wspolny);

        foreach (['docker/entrypoint.sh', 'docker/kopia/*.sh', 'scripts/*.sh', 'tests/skrypty/*.sh'] as $wzorzec) {
            $this->assertStringContainsString($wzorzec, $wspolny, "Składnia {$wzorzec} nie jest już sprawdzana.");
        }
    }

    public function test_nikt_nie_uruchamia_testow_powloki_obok_wspolnego_skryptu(): void
    {
        // Drugi, osobny wpis w jednym z dwóch miejsc to dokładnie ten rozjazd,
        // który audyt znalazł: działa w jednym, nie działa w drugim.
        foreach (['scripts/check.sh' => $this->plik('scripts/check.sh'), 'ci.yml (job lint)' => $this->jobLint()] as $gdzie => $tresc) {
            $this->assertDoesNotMatchRegularExpression(
                '/bash tests\/skrypty\//',
                $tresc,
                "{$gdzie} uruchamia test z tests/skrypty/ bezpośrednio. Dopisz go do ".self::WSPOLNY.'.',
            );
        }
    }
}
