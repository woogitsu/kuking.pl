<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * README i SECURITY.md nie rozjeżdżają się ze stanem repozytorium.
 *
 * SKĄD TO SIĘ WZIĘŁO
 * README podawało liczbę testów w DWÓCH miejscach: „3631 testów” w „Stan
 * repozytorium” i „72 testy” przy `php artisan test`. Druga liczba została
 * z blueprintu i przez tygodnie przeczyła pierwszej (audyt A13 w
 * `docs/research/audyt-2026-09-11/TRIAZ.md` zgłaszał to dwa razy). Obok stało
 * zdanie „Dopóki ich nie ma [Actions]”, choć CI jest włączone od D-010.
 * Każda liczba przepisana w drugie miejsce kiedyś się rozjedzie, więc test
 * pilnuje, żeby w README była jedna.
 *
 * SECURITY.md podaje adres do zgłoszeń. Adres, którego nikt nie czyta, jest
 * gorszy niż brak adresu: zgłaszający myśli, że zgłosił. Test wymaga więc
 * adresu, który podaje też polityka prywatności — ten ktoś czyta.
 */
class ReadmeISecurityMowiaPrawdeTest extends TestCase
{
    private function plik(string $sciezka): string
    {
        $tresc = file_get_contents(base_path($sciezka));
        $this->assertIsString($tresc, "Brak pliku {$sciezka}.");

        return $tresc;
    }

    public function test_readme_podaje_liczbe_testow_najwyzej_raz(): void
    {
        preg_match_all('/\b\d[\d\s]*\s+test(?:y|ów)\b/u', $this->plik('README.md'), $trafienia);

        $this->assertLessThanOrEqual(
            1,
            count($trafienia[0]),
            'README podaje liczbę testów w kilku miejscach: '.implode(' | ', $trafienia[0])
                .'. Zostaw jedną, w „Stan repozytorium”, a w pozostałych miejscach odeślij do niej.',
        );
    }

    public function test_readme_nie_twierdzi_ze_ci_jest_wylaczone(): void
    {
        $ci = $this->plik('.github/workflows/ci.yml');
        $this->assertStringContainsString('CI JEST WŁĄCZONE', $ci, 'Kontrola dodatnia: nagłówek ci.yml się zmienił — sprawdź, czy ten test nadal pyta o właściwą rzecz.');

        $this->assertStringNotContainsString(
            'Dopóki ich nie ma',
            $this->plik('README.md'),
            'README mówi, że GitHub Actions nie działają, a ci.yml mówi, że CI jest włączone (D-010).',
        );
    }

    public function test_security_md_podaje_adres_ktory_zna_polityka_prywatnosci(): void
    {
        $security = $this->plik('SECURITY.md');
        $polityka = $this->plik('resources/legal/polityka-prywatnosci.md');

        preg_match_all('/[a-z0-9._-]+@kuking\.pl/i', $security, $adresy);
        $this->assertNotEmpty($adresy[0], 'SECURITY.md nie podaje adresu do zgłoszeń.');

        foreach (array_unique($adresy[0]) as $adres) {
            $this->assertStringContainsString(
                $adres,
                $polityka,
                "SECURITY.md każe pisać na {$adres}, a polityka prywatności tego adresu nie zna — czy ktoś go czyta?",
            );
        }
    }

    public function test_readme_odsyla_do_security_md(): void
    {
        $this->assertStringContainsString('](./SECURITY.md)', $this->plik('README.md'));
    }
}
