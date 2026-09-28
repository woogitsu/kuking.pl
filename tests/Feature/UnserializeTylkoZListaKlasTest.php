<?php

declare(strict_types=1);

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Strażnik: każde `unserialize(` w `app/` ma jawne `allowed_classes`,
 * i nie jest to `true` (issue #1841).
 *
 * `ZapiszNieudanyList` odtwarzało ładunek `failed_jobs` bez listy klas,
 * choć dwa inne miejsca czytające ten sam ładunek listę miały. Rozjazd
 * powstał, bo reguła żyła tylko w komentarzach. Ten test czyta TOKENY PHP
 * (nie tekst), więc komentarz ze słowem `unserialize()` go nie myli,
 * a metoda o tej nazwie (`->unserialize(`, `::unserialize(`) nie jest
 * wywołaniem funkcji wbudowanej.
 *
 * Kontrola dodatnia: `scripts/kontrole-negatywne-alfa08.py` zdejmuje listę
 * klas w `PolecenieZadania::powiadomienie()` i ten test ma oblać. Druga
 * strona — że skaner rozpoznaje oba przypadki na próbkach — jest niżej.
 */
class UnserializeTylkoZListaKlasTest extends TestCase
{
    public function test_kazde_unserialize_w_app_ma_liste_dozwolonych_klas(): void
    {
        $naruszenia = [];
        $wywolan = 0;
        $baza = rtrim(base_path(), '/').'/';

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $plik) {
            if (! $plik->isFile() || $plik->getExtension() !== 'php') {
                continue;
            }

            $sciezka = (string) $plik->getPathname();

            foreach ($this->wywolania((string) file_get_contents($sciezka)) as [$wiersz, $argumenty]) {
                $wywolan++;

                if (! $this->maListeKlas($argumenty)) {
                    $naruszenia[] = str_replace($baza, '', $sciezka).':'.$wiersz;
                }
            }
        }

        $this->assertGreaterThan(0, $wywolan, 'Skaner nie widzi w app/ żadnego unserialize( — patrzy w złe miejsce.');
        $this->assertSame(
            [],
            $naruszenia,
            'unserialize( bez `allowed_classes` (albo z `true`) odtwarza KAŻDĄ klasę z ładunku, '
            .'razem z jej __wakeup/__destruct (#1841). Podaj jawną, minimalną listę klas — '
            .'dla ładunku kolejki użyj `PolecenieZadania::powiadomienie()`.',
        );
    }

    public function test_skaner_rozroznia_wywolanie_z_lista_i_bez(): void
    {
        $zle = <<<'PHP'
            <?php
            // unserialize($x) w komentarzu nie jest wywołaniem
            $a = unserialize($x);
            $b = \unserialize($y, ['allowed_classes' => true]);
            $c = @unserialize(f($z));
            PHP;
        $dobre = <<<'PHP'
            <?php
            $a = unserialize($x, ['allowed_classes' => [Foo::class]]);
            $b = @\unserialize(f($y), ['allowed_classes' => false]);
            $c = $obiekt->unserialize($z);
            $d = Klasa::unserialize($z);
            function unserialize_cos($q) {}
            PHP;

        $zleWynik = array_map(fn (array $w): bool => $this->maListeKlas($w[1]), $this->wywolania($zle));
        $dobreWynik = array_map(fn (array $w): bool => $this->maListeKlas($w[1]), $this->wywolania($dobre));

        $this->assertSame([false, false, false], $zleWynik);
        $this->assertSame([true, true], $dobreWynik);
    }

    /**
     * Wywołania funkcji wbudowanej `unserialize` z numerem wiersza
     * i tekstem argumentów (bez komentarzy).
     *
     * @return list<array{0: int, 1: string}>
     */
    private function wywolania(string $kod): array
    {
        $tokeny = array_values(array_filter(
            token_get_all($kod),
            fn ($t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $wynik = [];

        foreach ($tokeny as $i => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                || strtolower(ltrim($token[1], '\\')) !== 'unserialize') {
                continue;
            }

            $przed = $tokeny[$i - 1] ?? null;

            if (is_array($przed) && in_array($przed[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                continue;
            }

            if (($tokeny[$i + 1] ?? null) !== '(') {
                continue;
            }

            $glebokosc = 0;
            $argumenty = '';

            for ($j = $i + 1, $n = count($tokeny); $j < $n; $j++) {
                $tekst = is_array($tokeny[$j]) ? $tokeny[$j][1] : $tokeny[$j];
                $glebokosc += match ($tekst) {
                    '(', '[' => 1,
                    ')', ']' => -1,
                    default => 0,
                };
                $argumenty .= $tekst.' ';

                if ($glebokosc === 0) {
                    break;
                }
            }

            $wynik[] = [$token[2], $argumenty];
        }

        return $wynik;
    }

    private function maListeKlas(string $argumenty): bool
    {
        return preg_match('/[\'"]allowed_classes[\'"]\s*=>\s*(?!true\b)\S/i', $argumenty) === 1;
    }
}
