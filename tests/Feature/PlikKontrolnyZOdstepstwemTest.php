<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * PLIK KONTROLNY — druga strona kontroli dodatniej dla
 * `StraznikTekstuMaKontroleDodatniaTest`.
 *
 * Ten plik JEST strażnikiem tekstu: czyta źródło i asertuje na jego treści.
 * Nie ma pliku w `scripts/kontrole_negatywne/` i mieć go nie będzie —
 * przechodzi furtką odstępstwa. Usunięcie poniższego znacznika ma zapalić
 * strażnika; to jest jedna z trzech jego kontroli dodatnich.
 *
 * @bez-kontroli-dodatniej Przyrząd kontrolny furtki odstępstwa: mutacja tego znacznika jest kontrolą dodatnią strażnika, więc własna kontrola w `scripts/kontrole_negatywne/` byłaby kółkiem.
 */
class PlikKontrolnyZOdstepstwemTest extends TestCase
{
    public function test_przyrzad_kontrolny_czyta_zrodlo_i_asertuje_na_tresci(): void
    {
        $composer = (string) file_get_contents(base_path('composer.json'));

        $this->assertMatchesRegularExpression(
            '/"name"\s*:\s*"[^"]+"/',
            $composer,
            'composer.json bez nazwy pakietu — to nie jest to repozytorium.',
        );
    }
}
