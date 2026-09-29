<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\LicznikiBazy;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Tinker jest narzędziem deweloperskim, nie produkcyjnym (issue #2223, D-333).
 *
 * `laravel/tinker` stał w `require`, więc obraz budowany z `--no-dev` niósł
 * PsySH — pełną powłokę PHP z sekretami w zasięgu — a cztery runbooki kazały
 * go używać przez `railway ssh`. Właściciel zdecydował 29.09.2026: od razu
 * do `require-dev`, a w runbookach komendy `kuking:*`.
 *
 * Ten plik pilnuje trzech rzeczy, które da się sprawdzić bez Dockera:
 * klasyfikacji w `composer.json` i `composer.lock`, runbooków oraz tego,
 * że kod aplikacji nie odwołuje się do Tinkera ani PsySH. Sam obraz (brak
 * `vendor/laravel/tinker`, brak `Psy\` w autoloadzie, działające
 * `php artisan list`) sprawdza krok „Test dymny obrazu” w `ci.yml`.
 *
 * Kontrola dodatnia: dwa wpisy w `scripts/kontrole-negatywne-alfa08.py`
 * (tinker wraca do `require`; runbook wraca do `artisan tinker`).
 */
class TinkerPozaObrazemProdukcyjnymTest extends TestCase
{
    private const PAKIETY_DEV = ['laravel/tinker', 'psy/psysh'];

    public function test_tinker_jest_tylko_w_require_dev(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('laravel/framework', $composer['require'], 'Zły plik albo zły klucz — `require` nie ma nawet frameworka.');
        $this->assertArrayNotHasKey('laravel/tinker', $composer['require'], 'laravel/tinker wrócił do `require` — obraz --no-dev znów niesie PsySH (D-333).');
        $this->assertArrayHasKey('laravel/tinker', $composer['require-dev'], 'laravel/tinker zniknął z `require-dev` — skrypty scripts/*.mjs wołają `php artisan tinker` lokalnie i w CI.');
    }

    public function test_lock_trzyma_tinkera_i_psysh_w_pakietach_dev(): void
    {
        $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true, flags: JSON_THROW_ON_ERROR);

        $produkcyjne = array_column($lock['packages'], 'name');
        $deweloperskie = array_column($lock['packages-dev'], 'name');

        $this->assertContains('laravel/framework', $produkcyjne, 'Zły plik — `packages` nie ma nawet frameworka.');

        foreach (self::PAKIETY_DEV as $pakiet) {
            $this->assertNotContains($pakiet, $produkcyjne, "{$pakiet} jest w `packages` composer.lock — `composer install --no-dev` go zainstaluje.");
            $this->assertContains($pakiet, $deweloperskie, "{$pakiet} zniknął z `packages-dev`.");
        }
    }

    public function test_runbooki_produkcyjne_nie_kaza_uzywac_tinkera(): void
    {
        $runbooki = glob(base_path('docs/infra/*.md')) ?: [];

        // Pułapka 2 z docs/PULAPKI_TESTOW.md: skan bez plików przechodzi.
        $this->assertGreaterThan(20, count($runbooki), 'Skan docs/infra/*.md nie znalazł runbooków.');

        foreach ($runbooki as $plik) {
            $this->assertStringNotContainsString(
                'artisan tinker',
                (string) file_get_contents($plik),
                basename($plik).' każe użyć `php artisan tinker` — w obrazie produkcyjnym go nie ma (D-333). Użyj komendy kuking:*.',
            );
        }

        // Kontrola dodatnia: zastępcze komendy naprawdę stoją w runbookach.
        $kopie = (string) file_get_contents(base_path('docs/infra/KOPIE_I_ODTWORZENIE.md'));
        $this->assertStringContainsString('php artisan kuking:przetworz-zdjecia-ponownie --media=<uuid> --wykonaj', $kopie);
        $this->assertStringContainsString('php artisan kuking:liczniki-bazy', $kopie);
        $this->assertStringContainsString(
            'php artisan kuking:sprawdz-alarm --przez-wyjatek',
            (string) file_get_contents(base_path('docs/infra/MONITORING_BLEDOW.md')),
        );
    }

    public function test_liczniki_bazy_licza_te_same_tabele_co_krok_piaty_cwiczenia(): void
    {
        $kopie = (string) file_get_contents(base_path('docs/infra/KOPIE_I_ODTWORZENIE.md'));

        foreach (LicznikiBazy::TABELE as $tabela) {
            $this->assertStringContainsString("SELECT count(*) AS {$tabela} FROM {$tabela};", $kopie);
        }
    }

    public function test_kod_aplikacji_nie_odwoluje_sie_do_tinkera_ani_psysh(): void
    {
        $pliki = [base_path('bootstrap/app.php'), base_path('bootstrap/providers.php')];

        foreach (['app', 'config', 'routes'] as $katalog) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($katalog), FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $plik) {
                if ($plik->getExtension() === 'php') {
                    $pliki[] = $plik->getPathname();
                }
            }
        }

        $this->assertGreaterThan(200, count($pliki), 'Skan kodu aplikacji znalazł podejrzanie mało plików.');

        foreach ($pliki as $plik) {
            $tresc = (string) file_get_contents($plik);

            $this->assertDoesNotMatchRegularExpression(
                '/\bPsy\\\\|Laravel\\\\Tinker/',
                $tresc,
                $plik.' odwołuje się do PsySH albo Tinkera — w obrazie produkcyjnym tych klas nie ma (D-333).',
            );
        }
    }
}
