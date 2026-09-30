<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * #2301 (IN-09) — Composer ma się rozwiązywać bez względu na to, jaką wersją
 * PHP uruchomi go Dependabot.
 *
 * CO BYŁO NIE TAK
 * Dependabot otworzył osiem PR-ów (npm, actions, docker) i ZERO dla Composera,
 * choć `laravel/framework`, `livewire/livewire`, `larastan` i `pint` miały
 * nowsze wersje. `composer.json` wymaga `"php": "^8.4"` i nie ma
 * `config.platform.php`, więc wersję PHP do rozwiązywania zależności narzędzie
 * zewnętrzne musi zgadnąć. Najniższa wersja spełniająca `^8.4` to 8.4.0, a zamek
 * ma pakiety wymagające więcej. Odtworzone 30.09.2026 na kopii plików:
 * `composer config platform.php 8.4.0 && composer update --dry-run -W
 * laravel/framework …` → „symfony/filesystem v8.1.6 requires php >=8.4.1 ->
 * your php version (8.4.0; overridden via config.platform …) does not satisfy”.
 * Ta sama komenda z 8.4.1 rozwiązuje się. Który PHP ma updater Dependabota,
 * z repozytorium nie widać (log zadania czyta właściciel, krok W14) — jawne
 * `config.platform.php` usuwa zgadywanie niezależnie od odpowiedzi.
 *
 * CO PILNUJE TEN TEST
 *  1. `config.platform.php` jest ustawione, ma tę samą gałąź 8.4 co obraz
 *     produkcyjny (`Dockerfile`, `FROM dunglas/frankenphp:…-php8.4-…`)
 *     i spełnia wymaganie `php` z `require`.
 *  2. `composer.lock` jest zamknięty z tym nadpisaniem (`platform-overrides`).
 *  3. Żaden pakiet w zamku nie wymaga wyższego wydania 8.4.x niż nadpisanie —
 *     inaczej Dependabot i `composer update` znów trafią na nierozwiązywalny
 *     zestaw. Wtedy podnieś `config.platform.php` (nie wyżej niż PHP obrazu).
 *
 * CZEGO NIE DOWODZI: że Dependabot faktycznie otworzy PR — to wie tylko GitHub.
 */
final class ComposerRozwiazujeSieDlaDependabotaTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function json(string $plik): array
    {
        $dane = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/'.$plik), true);
        self::assertIsArray($dane, $plik.' nie jest poprawnym JSON-em.');

        return $dane;
    }

    private static function platforma(): string
    {
        $platforma = self::json('composer.json')['config']['platform']['php'] ?? null;
        self::assertIsString($platforma, 'composer.json nie ma config.platform.php — Dependabot musi zgadywać wersję PHP i nie rozwiązuje zależności Composera (#2301).');

        return $platforma;
    }

    public function test_platforma_php_jest_jawna_i_zgodna_z_obrazem(): void
    {
        $platforma = self::platforma();

        $this->assertMatchesRegularExpression('/^8\.4\.\d+$/', $platforma, 'config.platform.php ma być konkretnym wydaniem 8.4.x (#2301).');
        $this->assertNotSame('8.4.0', $platforma, 'config.platform.php = 8.4.0 odtwarza błąd #2301: pakiety w zamku wymagają co najmniej 8.4.1.');

        $this->assertSame('^8.4', self::json('composer.json')['require']['php'] ?? null, 'Zmieniło się wymaganie php w require — sprawdź zgodność z config.platform.php.');

        $dockerfile = (string) file_get_contents(dirname(__DIR__, 2).'/Dockerfile');
        $this->assertSame(1, preg_match('/^FROM dunglas\/frankenphp:[^\s@]*-php(\d+\.\d+)-\S+ AS runtime$/m', $dockerfile, $m), 'Nie znalazłem obrazu runtime w Dockerfile.');
        $this->assertStringStartsWith($m[1].'.', $platforma, 'config.platform.php ma inną gałąź PHP niż obraz produkcyjny.');

        $this->assertSame(
            $platforma,
            self::json('composer.lock')['platform-overrides']['php'] ?? null,
            'composer.lock nie jest zamknięty z config.platform.php — uruchom `composer update --lock` (#2301).',
        );
    }

    public function test_zaden_pakiet_w_zamku_nie_wymaga_nowszego_wydania_niz_platforma(): void
    {
        $platforma = self::platforma();
        $zamek = self::json('composer.lock');
        $pakiety = [...($zamek['packages'] ?? []), ...($zamek['packages-dev'] ?? [])];

        $this->assertGreaterThan(100, count($pakiety), 'Zamek ma podejrzanie mało pakietów — zła ścieżka albo zepsuty odczyt.');

        $zaWysokie = [];
        $zWymaganiem84 = 0;

        foreach ($pakiety as $pakiet) {
            $wymaganie = (string) ($pakiet['require']['php'] ?? '');

            if (preg_match_all('/(?:>=|\^|~)\s*(8\.4\.\d+)/', $wymaganie, $trafienia) < 1) {
                continue;
            }

            $zWymaganiem84++;

            foreach ($trafienia[1] as $minimum) {
                if (version_compare($minimum, $platforma, '>')) {
                    $zaWysokie[] = $pakiet['name'].' ('.$wymaganie.')';
                }
            }
        }

        $this->assertGreaterThan(0, $zWymaganiem84, 'Skan nie widzi ani jednego pakietu z wymaganiem 8.4.x — zepsuty wzorzec?');
        $this->assertSame([], $zaWysokie, 'Pakiety w zamku wymagają nowszego PHP niż config.platform.php '.$platforma.' — podnieś nadpisanie (#2301): '.implode(', ', $zaWysokie));
    }
}
