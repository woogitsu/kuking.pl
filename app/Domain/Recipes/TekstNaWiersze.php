<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

/**
 * Jedno pole tekstowe → wiersze w bazie (issue #364).
 *
 * BAZA SIĘ NIE ZMIENIA. `recipe_ingredients` i `recipe_steps` zostają
 * dokładnie takie, jakie były — znika WPISYWANIE składnika po jednym
 * w siedmiu polach, a nie struktura danych. Dzięki temu przeliczanie porcji
 * i szukanie po składnikach działają dalej, bo czytają te same wiersze.
 *
 * DLACZEGO WOLNO TAK PARSOWAĆ
 * Kreator od początku obiecuje „Pisz tak, jak mówisz: «szklanka mąki»,
 * «2 duże cebule»" — i D-017 rozstrzygnął, że składnik jest JEDNYM polem
 * wolnego tekstu, bo „tyle, żeby ciasto było miękkie" nie ma pola na ilość.
 * Wiersz tekstu idzie więc do `ingredient_text` w całości, bez zgadywania,
 * co w nim jest ilością. Ta podpowiedź zaczyna być prawdą, zamiast być
 * obietnicą złożoną obok siedmiu pól.
 *
 * DWIE RÓŻNE GRANICE, BO TO DWIE RÓŻNE RZECZY
 *  - składnik to JEDEN WIERSZ. Nowa linia = nowy składnik;
 *  - krok to AKAPIT. Pusta linia = nowy krok, bo krok bywa dwuzdaniowy
 *    i łamanie go na każdym enterze rozbiłoby jedną czynność na trzy.
 *
 * Puste wiersze są pomijane PO CICHU — tak jak dziś w obu formularzach.
 * Człowiek, który zostawił enter na końcu, nie zrobił nic złego i nie ma
 * o czym być powiadamiany.
 */
final class TekstNaWiersze
{
    /**
     * Składniki: jeden na wiersz.
     *
     * @return list<array{text: string}>
     */
    public static function skladniki(?string $tekst): array
    {
        $wiersze = [];

        foreach (explode("\n", self::znormalizuj($tekst)) as $wiersz) {
            $wiersz = trim($wiersz);

            if ($wiersz === '') {
                continue;
            }

            $wiersze[] = ['text' => $wiersz];
        }

        return $wiersze;
    }

    /**
     * Przygotowanie: pusta linia rozdziela kroki.
     *
     * Wewnątrz jednego kroku pojedyncze entery ZOSTAJĄ — to dalej jedna
     * czynność, tylko zapisana w dwóch linijkach.
     *
     * @return list<array{instruction: string}>
     */
    public static function kroki(?string $tekst): array
    {
        $tekst = self::znormalizuj($tekst);

        // Linia „pusta" to także linia z samymi spacjami albo tabulatorami —
        // tego człowiek na ekranie nie odróżni, więc nie może to zmieniać
        // wyniku. `preg_split` z `+` skleja też kilka pustych linii pod rząd
        // w jedną granicę, zamiast robić z nich puste kroki.
        $bloki = preg_split("/\n[ \t]*\n(?:[ \t]*\n)*/", $tekst) ?: [];

        $wiersze = [];

        foreach ($bloki as $blok) {
            $blok = trim($blok);

            if ($blok === '') {
                continue;
            }

            $wiersze[] = ['instruction' => $blok];
        }

        return $wiersze;
    }

    /**
     * Końce linii z każdego systemu na jeden.
     *
     * Windows przysyła `\r\n`, stare Maki `\r`. Bez tego `\r` zostawałby na
     * końcu każdego składnika i szedł do bazy razem z nazwą — niewidoczny na
     * ekranie, a psujący porównania i wyszukiwanie.
     */
    private static function znormalizuj(?string $tekst): string
    {
        return str_replace(["\r\n", "\r"], "\n", (string) $tekst);
    }
}
