<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Znacznik kolejności bajtów (BOM) na początku szablonu Blade.
 *
 * CO BYŁO ZMIERZONE. `resources/views/components/szybki-wyglad.blade.php`
 * był zapisany z BOM-em (EF BB BF). Blade nie traktuje go jak nagłówka pliku,
 * tylko jak zwykły tekst szablonu — więc do `<body>` trafiał węzeł tekstowy
 * ze znakiem U+FEFF, a przeglądarka robiła z niego PEŁNY WIERSZ TEKSTU:
 * 28 px przy skali 100% i 39,6 px przy 140%, na samym końcu dokumentu, pod
 * stopką. Niewidoczny znak trzymał widoczny pas pustki, którą właściciel
 * zgłosił razem z podwójnie liczoną rezerwą pod belką.
 *
 * DLACZEGO TEST NA CAŁYM KATALOGU, A NIE NA TYM JEDNYM PLIKU. Przyczyną jest
 * edytor albo narzędzie zapisujące UTF-8 z BOM-em, nie ten konkretny szablon.
 * Następny plik zapisany tak samo da dokładnie ten sam pas i nikt nie połączy
 * jednego z drugim.
 *
 * DRUGA STRONA TEGO SAMEGO POMIARU stoi w `scripts/stopka-pusty-pas.mjs`,
 * który mierzy w przeglądarce wysokość pustego obszaru pod stopką. Ten plik
 * chodzi w jobie `test`, czyli ZAWSZE, i jest tani.
 */
class WidokiBezZnacznikaBomTest extends TestCase
{
    public function test_zaden_szablon_blade_nie_zaczyna_sie_od_bom(): void
    {
        $katalog = resource_path('views');

        $this->assertDirectoryExists($katalog);

        $pliki = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($katalog)),
            '~\.blade\.php$~',
        );

        $zBomem = [];
        $sprawdzone = 0;

        foreach ($pliki as $plik) {
            $sprawdzone++;

            $uchwyt = fopen($plik->getPathname(), 'rb');
            $poczatek = (string) fread($uchwyt, 3);
            fclose($uchwyt);

            if ($poczatek === "\xEF\xBB\xBF") {
                $zBomem[] = str_replace($katalog.DIRECTORY_SEPARATOR, '', $plik->getPathname());
            }
        }

        /*
         * KONTROLA DODATNIA. Skan, który nic nie znalazł, przechodzi także
         * wtedy, gdy nic nie przeskanował (`docs/PULAPKI_TESTOW.md`). Widoków
         * jest dziś grubo ponad sto; próg stoi nisko, żeby nie padał przy
         * zwykłym sprzątaniu.
         */
        $this->assertGreaterThan(
            50,
            $sprawdzone,
            'Przeskanowano podejrzanie mało szablonów — test sprawdzałby pustkę.',
        );

        $this->assertSame(
            [],
            $zBomem,
            'Szablon zaczyna się od BOM-a (EF BB BF): '.implode(', ', $zBomem).'. '
            .'Blade wypisze ten znak jako treść strony, a przeglądarka zrobi z niego '
            .'pełny wiersz tekstu — 28 px pustki przy skali 100%, 39,6 px przy 140%. '
            .'Zapisz plik jako UTF-8 bez BOM-a.',
        );
    }
}
