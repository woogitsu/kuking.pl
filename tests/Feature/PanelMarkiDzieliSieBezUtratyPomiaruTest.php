<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Panel marki w dwóch częściach macierzy bez utraty pomiaru i bez zmiany
 * wymaganego checku — #2299.
 *
 * Job `port_panelu` szedł 17,5–20 min i był ścieżką krytyczną CI, więc dzieli
 * się na części (`scripts/panel-czesci.mjs`). Pilnujemy czterech rzeczy:
 *   1. macierz `port_panelu` ma dokładnie te części, które zna
 *      `CZESCI_PANELU`, a każda część dostaje swój numer w `PANEL_CZESC`
 *      (bez zmiennej skrypt mierzy całość — część bez niej robiłaby wszystko
 *      dwa razy, część ze złym numerem padłaby dopiero w przeglądarce);
 *   2. każda część uruchamia pomiar i regresję podziału (kompletność sumy
 *      części sprawdza `scripts/panel-czesci.test.mjs`);
 *   3. wymagany check „Panel marki — puste i pełne widoki" nadal POWSTAJE:
 *      wystawia go job zbiorczy `panel_marki`, który czeka na obie części,
 *      rusza także po ich czerwieni i jest zielony tylko po sukcesie albo po
 *      świadomym pominięciu przez bramkę `zakres`;
 *   4. żaden inny job nie nosi tej nazwy (dwa checki o jednej nazwie to
 *      wyścig o to, który z nich przeczyta ochrona gałęzi).
 */
class PanelMarkiDzieliSieBezUtratyPomiaruTest extends TestCase
{
    private const NAZWA_WYMAGANA = 'Panel marki — puste i pełne widoki';

    public function test_macierz_panelu_ma_czesci_ze_skryptu_i_podaje_numer_czesci(): void
    {
        $job = $this->job('port_panelu');

        $this->assertSame(1, preg_match('/^        czesc: \[([0-9, ]+)\]\s*$/m', $job, $m), 'Job `port_panelu` nie ma macierzy `czesc`.');
        $wCi = array_map('intval', array_map('trim', explode(',', $m[1])));

        $skrypt = (string) file_get_contents(base_path('scripts/panel-czesci.mjs'));
        $this->assertSame(1, preg_match('/export const CZESCI_PANELU = \{(.*?)\n\};/s', $skrypt, $blok), 'Brak `CZESCI_PANELU` w scripts/panel-czesci.mjs.');
        preg_match_all('/^  (\d+): \{/m', $blok[1], $czesci);
        $wSkrypcie = array_map('intval', $czesci[1]);

        $this->assertNotEmpty($wSkrypcie);
        $this->assertSame(
            $wSkrypcie,
            $wCi,
            'Macierz `port_panelu` w ci.yml ma inne części niż `CZESCI_PANELU` w scripts/panel-czesci.mjs — '
            .'część bez elementu macierzy nigdy się nie uruchomi (#2299).',
        );
        $this->assertMatchesRegularExpression(
            '/^      PANEL_CZESC: \$\{\{ matrix\.czesc \}\}\s*$/m',
            $job,
            'Job `port_panelu` nie podaje `PANEL_CZESC` z macierzy — każda część mierzyłaby cały panel (#2299).',
        );
        $this->assertTrue(str_contains($job, 'fail-fast: false'), 'Czerwona część panelu nie może anulować drugiej.');
        $this->assertStringContainsString('(część ${{ matrix.czesc }}/2)', $job);
        $this->assertSame(count($wCi), 2, 'Nazwa części mówi „/2" — zmień ją razem z liczbą części.');
    }

    public function test_kazda_czesc_uruchamia_pomiar_i_regresje_podzialu(): void
    {
        $job = $this->job('port_panelu');

        $this->assertMatchesRegularExpression('/^        run: node scripts\/panel-marki-run\.mjs\s*$/m', $job);
        $this->assertMatchesRegularExpression(
            '/^        run: node --test scripts\/panel-czesci\.test\.mjs\s*$/m',
            $job,
            'Części panelu nie uruchamiają `scripts/panel-czesci.test.mjs` — podział mógłby zgubić szerokość albo dodatek (#2299).',
        );
        // Dodatkowa regresja formularza biegnie raz w części 2. Sam pomiar
        // panelu i wszystkie pozostałe kroki nadal muszą biec w obu częściach.
        $dodatkowaRegresja = "      - name: Regresja błędów walidacji obok hostów dyktowania\n"
            ."        if: matrix.czesc == 2\n"
            ."        run: node --test scripts/przegladarka/panel-validation-errors.test.mjs\n";
        $this->assertSame(1, substr_count($job, $dodatkowaRegresja), 'Jedyna regresja tylko w części 2 musi rzeczywiście biec.');
        $this->assertDoesNotMatchRegularExpression(
            '/^\s+if: matrix\.czesc/m',
            str_replace($dodatkowaRegresja, '', $job),
            'PANEL_2446_POMIAR_KAZDA_CZESC: pomiar panelu i pozostałe kroki nie zależą od części.',
        );
    }

    public function test_wymagany_check_wystawia_job_zbiorczy_czekajacy_na_obie_czesci(): void
    {
        $job = $this->job('panel_marki');

        $this->assertMatchesRegularExpression('/^    name: '.preg_quote(self::NAZWA_WYMAGANA, '/').'\s*$/m', $job);
        $this->assertMatchesRegularExpression('/^    needs: \[zakres, port_panelu\]\s*$/m', $job);
        $this->assertMatchesRegularExpression(
            '/^    if: \$\{\{ !cancelled\(\) \}\}\s*$/m',
            $job,
            'Job zbiorczy panelu bez `!cancelled()` jest `skipped` po czerwonej części, a pominięty wymagany check liczy się jak zielony (#2299).',
        );
        $this->assertStringContainsString('WYNIK_PANELU: ${{ needs.port_panelu.result }}', $job);
        $this->assertStringContainsString('if [ "${WYNIK_PANELU}" = "success" ]; then', $job);
        $this->assertStringContainsString('[ "${WYNIK_PANELU}" = "skipped" ] && [ "${WYNIK_ZAKRESU}" = "success" ]', $job);
        $this->assertMatchesRegularExpression('/exit 1\s*$/', rtrim($job), 'Job zbiorczy panelu musi kończyć się porażką poza dwoma zielonymi stanami.');
        $this->assertStringNotContainsString('continue-on-error', $job);
    }

    public function test_nazwa_wymagana_ma_dokladnie_jeden_job(): void
    {
        preg_match_all('/^    name: '.preg_quote(self::NAZWA_WYMAGANA, '/').'\s*$/m', $this->workflow(), $m);

        $this->assertCount(1, $m[0], 'Nazwa „'.self::NAZWA_WYMAGANA.'" musi należeć do jednego joba — jobu zbiorczego `panel_marki`.');
    }

    private function workflow(): string
    {
        return (string) file_get_contents(base_path('.github/workflows/ci.yml'));
    }

    private function job(string $name): string
    {
        $matched = preg_match('/^  '.preg_quote($name, '/').':(?:\r\n|\n|\r)(.*?)(?=^  [a-z_0-9-]+:|\z)/ms', $this->workflow(), $matches);
        $this->assertSame(1, $matched, 'Brak sprawdzanego joba CI: '.$name);

        return (string) preg_replace('/^\s*#.*$/m', '', $matches[1]);
    }
}
