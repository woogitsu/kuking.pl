<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Cache między jobami CI przyspiesza, ale nie może dać starego wyniku — #2299.
 *
 * Decyzja właściciela z 30.09.2026: `vendor/` i przeglądarki Playwrighta
 * z `actions/cache`, „poprawność ma pierwszeństwo przed szybkością". Stąd
 * reguły, każda sprawdzana na każdym użyciu `actions/cache` w `ci.yml`:
 *   1. wolno cache'ować tylko znane ścieżki: `vendor`, `~/.cache/ms-playwright`
 *      i `.phpstan-cache` (PHPStan sam unieważnia wpisy po zmianie plików) —
 *      NIE `node_modules`, `public/build` ani nic innego, co mogłoby podać
 *      arkusz albo zależności z innego commita;
 *   2. `vendor`: klucz po `composer.lock` i wersji PHP, a w TYM SAMYM jobie
 *      po odtworzeniu zawsze biegnie `composer install` (doprowadza katalog
 *      do locka także po trafieniu w `restore-keys`);
 *   3. przeglądarka: klucz po dokładnej wersji `playwright-core`, bez
 *      `restore-keys`, tylko na runnerach GitHuba (na własnych katalog jest
 *      wspólny dla jobów jednej maszyny), a po odtworzeniu nadal biegnie
 *      `npx playwright install chromium`;
 *   4. każdy job instalujący przeglądarkę albo `vendor/` ma swój cache —
 *      reguła szybkości, żeby nowy job nie wypadł z niej po cichu.
 */
class CacheMiedzyJobamiNieDajeStarychWynikowTest extends TestCase
{
    private const DOZWOLONE_SCIEZKI = ['vendor', '~/.cache/ms-playwright', '.phpstan-cache'];

    public function test_cache_tylko_dla_znanych_sciezek(): void
    {
        $uzycia = $this->uzyciaCache();
        $this->assertGreaterThanOrEqual(10, count($uzycia), 'Odczyt kroków `actions/cache` zepsuty — za mało użyć.');

        foreach ($uzycia as [$job, $krok]) {
            $this->assertSame(1, preg_match('/^          path: (.+)$/m', $krok, $m), "Job `{$job}`: cache bez jednej ścieżki `path:`.");
            $this->assertContains(
                trim($m[1]),
                self::DOZWOLONE_SCIEZKI,
                "Job `{$job}` cache'uje `".trim($m[1]).'` — spoza listy bezpiecznych ścieżek (#2299). '
                .'Arkusz, `node_modules` albo wynik z innego commita dałby stary wynik.',
            );
        }
    }

    public function test_vendor_ma_klucz_po_locku_i_zawsze_composer_install_po_odtworzeniu(): void
    {
        $ile = 0;
        foreach ($this->uzyciaCache() as [$job, $krok, $blok]) {
            if (! preg_match('/^          path: vendor\s*$/m', $krok)) {
                continue;
            }
            $ile++;
            $this->assertTrue(str_contains($krok, "key: composer-\${{ runner.os }}-php\${{ env.PHP_VERSION }}-\${{ hashFiles('composer.lock') }}"), "Job `{$job}`: klucz `vendor` nie zależy od composer.lock i PHP.");
            $po = substr($blok, (int) strpos($blok, $krok) + strlen($krok));
            $this->assertMatchesRegularExpression(
                '/^\s+(run: )?composer install --no-interaction/m',
                $po,
                "Job `{$job}`: po odtworzeniu `vendor` nie biegnie `composer install` — job szedłby na zależnościach z innego locka (#2299).",
            );
        }
        $this->assertGreaterThanOrEqual(9, $ile);
    }

    public function test_przegladarka_ma_klucz_po_wersji_bez_restore_keys_tylko_na_runnerach_githuba(): void
    {
        $ile = 0;
        foreach ($this->uzyciaCache() as [$job, $krok, $blok]) {
            if (! str_contains($krok, 'ms-playwright')) {
                continue;
            }
            $ile++;
            $this->assertTrue(str_contains($krok, 'key: playwright-${{ runner.os }}-${{ runner.arch }}-${{ steps.playwright.outputs.wersja }}'), "Job `{$job}`: klucz przeglądarki nie jest wersją Playwrighta.");
            $this->assertFalse(str_contains($krok, 'restore-keys'), "Job `{$job}`: przeglądarka innej wersji nie może być odtwarzana (#2299).");
            $this->assertMatchesRegularExpression(
                "/^        if: (runner\\.environment == 'github-hosted'|\\$\\{\\{ !cancelled\\(\\) && \\(runner\\.environment == 'github-hosted'\\) \\}\\})\\s*$/m",
                $krok,
                "Job `{$job}`: cache przeglądarki na własnym runnerze podmieniałby wspólny katalog innym jobom (#2299).",
            );
            $this->assertTrue(str_contains($blok, "require('./package-lock.json').packages['node_modules/playwright-core'].version"), "Job `{$job}`: brak kroku z wersją Playwrighta.");
            $po = substr($blok, (int) strpos($blok, $krok) + strlen($krok));
            $this->assertTrue(str_contains($po, 'npx playwright install chromium'), "Job `{$job}`: po odtworzeniu przeglądarki nie biegnie `npx playwright install`.");
        }
        $this->assertGreaterThanOrEqual(6, $ile);
    }

    public function test_kazdy_job_instalujacy_przegladarke_albo_vendor_ma_cache(): void
    {
        foreach ($this->joby() as $job => $blok) {
            if (str_contains($blok, 'npx playwright install chromium')) {
                $this->assertTrue(str_contains($blok, 'path: ~/.cache/ms-playwright'), "Job `{$job}` instaluje przeglądarkę bez cache (#2299).");
            }
            if (preg_match('/^\s+(run: )?composer install --no-interaction/m', $blok)) {
                $this->assertTrue(str_contains($blok, 'path: vendor'), "Job `{$job}` instaluje `vendor/` bez cache (#2299).");
            }
        }
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function uzyciaCache(): array
    {
        $uzycia = [];
        foreach ($this->joby() as $job => $blok) {
            preg_match_all('/^      - name: [^\n]*\n(?:        (?!uses:)[^\n]*\n)*        uses: actions\/cache@[^\n]*\n(?:        [^\n]*\n)*/m', $blok, $m);
            foreach ($m[0] as $krok) {
                $uzycia[] = [$job, $krok, $blok];
            }
        }

        return $uzycia;
    }

    /** @return array<string, string> */
    private function joby(): array
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
        $workflow = (string) preg_replace('/^\s*#.*\n/m', '', $workflow);
        preg_match_all('/^  ([a-z_0-9-]+):\n(.*?)(?=^  [a-z_0-9-]+:|\z)/ms', substr($workflow, (int) strpos($workflow, "\njobs:\n")), $m, PREG_SET_ORDER);
        $joby = [];
        foreach ($m as $dopasowanie) {
            $joby[$dopasowanie[1]] = $dopasowanie[2];
        }

        return $joby;
    }
}
