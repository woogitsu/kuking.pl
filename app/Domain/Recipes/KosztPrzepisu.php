<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

/**
 * Szacunkowy koszt CAŁEGO przepisu podany przez autora (V2, D-286).
 *
 * Jedno nazwane źródło dla czterech rzeczy, które inaczej rozjechałyby się
 * między kreatorem (Livewire), formularzem szczegółów (zwykły POST), stroną
 * przepisu i wyszukiwarką:
 *
 *  1. normalizacja tego, co człowiek wpisał („24,50", „24 zł", „ 1 200 ")
 *     do postaci, którą rozumie walidator i kolumna `numeric(6,2)`;
 *  2. reguły walidacji i polskie komunikaty mówiące, CO ZROBIĆ;
 *  3. zdanie dla czytelnika — zawsze z „ok." i z „wg autora", bo to jest
 *     deklaracja jednej osoby, a nie cennik;
 *  4. przeliczenie na inną liczbę porcji (proporcjonalne), gotowe dla
 *     skalowania porcji, kiedy ono wejdzie na `main`.
 *
 * Czego ta klasa świadomie NIE robi: nie liczy kosztu ze składników i nie
 * zna cen sklepowych. Liczba jest wyłącznie tym, co wpisał autor — zgadywanie
 * ceny za niego byłoby udawaniem wiedzy, której serwis nie ma.
 */
final class KosztPrzepisu
{
    /** Kolumna `recipes.estimated_cost_pln` to `numeric(6,2)`. */
    public const MAKS = 9999.99;

    /** Granica zakresu „Do 20 zł” w wyszukiwarce. */
    public const TANIE_DO = 20;

    /**
     * `bail` — jedno zdanie na raz: „dużo" dostaje „wpisz liczbą", a nie
     * dodatkowo „najwyżej dwa miejsca po przecinku".
     *
     * @var list<string>
     */
    public const REGULY = ['bail', 'nullable', 'numeric', 'min:0', 'max:9999.99', 'decimal:0,2'];

    /** Komunikaty dla klucza pola `estimated_cost_pln`. */
    public const KOMUNIKATY = [
        'estimated_cost_pln.numeric' => 'Koszt wpisz samą liczbą złotych, na przykład 24 albo 24,50.',
        'estimated_cost_pln.min' => 'Koszt nie może być mniejszy od zera. Wpisz na przykład 24 albo zostaw pole puste.',
        'estimated_cost_pln.max' => 'Ten koszt jest nierealnie wysoki. Wpisz najwyżej 9999,99 zł albo zostaw pole puste.',
        'estimated_cost_pln.decimal' => 'Koszt może mieć najwyżej dwa miejsca po przecinku (grosze). Zamiast 24,555 wpisz 24,55 albo 24,56.',
    ];

    /**
     * To, co człowiek wpisał, w postaci dla walidatora — albo `null`,
     * gdy pole jest puste.
     *
     * Przecinek zamieniamy na kropkę (po polsku pisze się „24,50"), spacje
     * znikają („1 200"), a dopisek „zł"/„zl" na końcu też — etykieta pola
     * mówi „w złotych", ale ktoś i tak go dopisze i nie ma powodu go za to
     * karać. Wszystko inne idzie do walidatora bez zmian, żeby „abc" dostało
     * komunikat, a nie ciche wyczyszczenie pola.
     */
    public static function normalizuj(mixed $wartosc): ?string
    {
        if ($wartosc === null || is_array($wartosc)) {
            return null;
        }

        $tekst = trim((string) $wartosc);
        $tekst = (string) preg_replace('/\s*(zł|zl|pln)\.?$/iu', '', $tekst);
        $tekst = (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $tekst);
        $tekst = str_replace(',', '.', $tekst);

        return $tekst === '' ? null : $tekst;
    }

    /** Liczba do zapisu po udanej walidacji; `null`, gdy pole puste. */
    public static function naLiczbe(mixed $wartosc): ?float
    {
        $tekst = self::normalizuj($wartosc);

        return $tekst === null || ! is_numeric($tekst) ? null : round((float) $tekst, 2);
    }

    /**
     * Wartość z bazy z powrotem do pola formularza: „24", „24,50" — tak, jak
     * się to pisze po polsku, żeby ponowny zapis nie zmieniał liczby
     * i nie straszył kropką. `null` → puste pole.
     */
    public static function doPola(mixed $koszt): string
    {
        if ($koszt === null || $koszt === '') {
            return '';
        }

        $koszt = round((float) $koszt, 2);
        $calkowita = abs($koszt - round($koszt)) < 0.005;

        return number_format($koszt, $calkowita ? 0 : 2, ',', '');
    }

    /**
     * „24 zł", „24,50 zł", „1 200 zł" — kwota po polsku, bez zbędnych zer.
     */
    public static function kwota(float $koszt): string
    {
        $koszt = round($koszt, 2);
        $calkowita = abs($koszt - round($koszt)) < 0.005;

        return number_format($koszt, $calkowita ? 0 : 2, ',', ' ').' zł';
    }

    /** Zdanie na stronę przepisu: „Szacunkowy koszt: ok. 24 zł (wg autora)". */
    public static function zdanie(float $koszt): string
    {
        return 'Szacunkowy koszt: ok. '.self::kwota($koszt).' (wg autora)';
    }

    /**
     * Koszt przeliczony proporcjonalnie na inną liczbę porcji.
     *
     * `null`, gdy przeliczyć się nie da: przepis bez podanej liczby porcji
     * nie ma podstawy proporcji, a zgadywanie „pewnie 4" byłoby nieprawdą
     * o cudzym przepisie. Wynik zaokrąglamy do pełnych złotych — przy
     * szacunku autora grosze po przeliczeniu udawałyby dokładność, której
     * nigdy nie było. Widok ma wtedy dopisać, że to przeliczenie
     * (`zdaniePrzeliczone()`), a nie liczba autora.
     */
    public static function naPorcje(float $koszt, ?float $porcjeAutora, float $porcje): ?float
    {
        if ($porcjeAutora === null || $porcjeAutora <= 0 || $porcje <= 0) {
            return null;
        }

        return round($koszt * $porcje / $porcjeAutora);
    }

    /**
     * „Szacunkowy koszt: ok. 36 zł (przeliczone z kosztu podanego przez autora)".
     *
     * Bez liczby porcji w nawiasie: „na 1 porcję / na 2 porcje / na 5 porcji"
     * wymaga biernika, a `Recipe::servingsLabel()` daje mianownik. Liczbę
     * porcji i tak widać obok, w przełączniku skalowania.
     */
    public static function zdaniePrzeliczone(float $kosztPrzeliczony): string
    {
        return 'Szacunkowy koszt: ok. '.self::kwota($kosztPrzeliczony).' (przeliczone z kosztu podanego przez autora)';
    }
}
