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
        'tests/skrypty/healthcheck-role.sh',
        'tests/skrypty/bramka-migracji.sh',
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

        foreach (['docker/entrypoint.sh', 'docker/healthcheck.sh', 'docker/kopia/*.sh', 'scripts/*.sh', 'tests/skrypty/*.sh'] as $wzorzec) {
            $this->assertStringContainsString($wzorzec, $wspolny, "Składnia {$wzorzec} nie jest już sprawdzana.");
        }
    }

    /** Nazwy plików z jednego z bloków `NAZWA=$(cat <<'KONIEC' … KONIEC)` w skrypcie wspólnym. */
    private function wpisyBloku(string $nazwa): array
    {
        $this->assertSame(
            1,
            preg_match('/^'.$nazwa.'=\$\(cat <<\'KONIEC\'\n(.*?)^KONIEC$/ms', $this->plik(self::WSPOLNY), $m),
            "Nie ma bloku {$nazwa} w ".self::WSPOLNY.'.',
        );

        return array_map(
            fn (string $wiersz): string => explode('|', $wiersz)[0],
            array_values(array_filter(explode("\n", $m[1]))),
        );
    }

    public function test_zaden_plik_z_tests_skrypty_nie_jest_pominiety(): void
    {
        // Strażnik przeciw cichemu pominięciu: nowy tests/skrypty/*.sh, którego
        // nikt nie dopisał do listy, nie chodzi ani w check.sh, ani w CI.
        $lista = $this->wpisyBloku('LISTA');
        $poza = $this->wpisyBloku('POZA_LISTA');
        $naDysku = array_map(
            fn (string $sciezka): string => 'tests/skrypty/'.basename($sciezka),
            glob(base_path('tests/skrypty/*.sh')) ?: [],
        );

        $this->assertNotEmpty($naDysku, 'Brak plików w tests/skrypty/ — zła ścieżka?');
        $this->assertSame([], array_values(array_intersect($lista, $poza)), 'Plik jest naraz na LISTA i POZA_LISTA.');
        $this->assertSame(
            [],
            array_values(array_diff($naDysku, $lista, $poza)),
            'Test powłoki poza listą w '.self::WSPOLNY.' — dopisz go do LISTA (albo do POZA_LISTA z powodem).',
        );
        $this->assertSame(
            [],
            array_values(array_diff(array_merge($lista, $poza), $naDysku)),
            'Lista w '.self::WSPOLNY.' wskazuje plik, którego nie ma na dysku.',
        );
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
