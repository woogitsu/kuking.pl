<?php

declare(strict_types=1);

namespace App\Support\KreatorPrzepisu;

use App\Domain\Recipes\KosztPrzepisu;
use App\Domain\Recipes\StepTimer;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Recipe;
use App\Models\RecipeStep;

/**
 * Czyste przeliczenia kreatora przepisu: zamiana tego, co człowiek wpisał
 * w pola, na liczby do zapisu i na zdania do podglądu (issue #1387, krok 4).
 *
 * Wydzielone z `resources/views/components/recipe-wizard.blade.php` bez
 * zmiany zachowania. Klasa nie zna Livewire'a, requestu ani widoku i nie
 * zapisuje niczego — modele `Recipe` i `RecipeStep` powstają tu tylko po to,
 * żeby policzyć zdanie tym samym kodem, którego używa strona przepisu
 * (podgląd, który liczy po swojemu, pokazuje przepis, którego po
 * opublikowaniu nikt nie zobaczy). Reguły domenowe zostają w `PublishRecipe`.
 */
final class PodgladPrzepisu
{
    /**
     * Nagłówek podglądu — ZALEŻNY OD WYBRANEJ WIDOCZNOŚCI. Przy przepisie
     * „Tylko ja” zdanie „tak zobaczą to inni” byłoby nieprawdą.
     */
    public static function naglowek(string $widocznosc): string
    {
        return match ($widocznosc) {
            'private' => 'Podgląd: tak będziesz widzieć ten przepis',
            'followers' => 'Podgląd: tak zobaczą to osoby, które Cię obserwują',
            default => 'Podgląd: tak zobaczą to inni',
        };
    }

    /**
     * Liczba porcji do podglądu — liczona TYM SAMYM kodem, co znaczek na
     * stronie przepisu (`Recipe::servingsLabel()`): „1 porcja”, „2 porcje”,
     * „0,5 porcji”, a nie „1 porcji” ani „0 porcji” (issue #38).
     */
    public static function etykietaPorcji(string $porcje): ?string
    {
        $liczba = self::liczbaLubNull($porcje);

        return $liczba === null ? null : (new Recipe(['servings' => $liczba]))->servingsLabel();
    }

    /**
     * Etykieta minutnika do podglądu — ten sam kod co w trybie gotowania
     * (`RecipeStep::timerLabel()`). Wartość niemożliwą zwracamy jako `null`,
     * nie jako wyjątek: na podgląd da się wejść przyciskiem „Dalej”, który
     * sprawdza tylko krok pierwszy, więc render nie może się wywalić na tym,
     * o czym i tak powie dopiero „Opublikuj przepis”.
     */
    public static function etykietaMinutnika(mixed $minuty): ?string
    {
        try {
            $sekundy = StepTimer::secondsFromMinutes($minuty);
        } catch (BladDlaCzlowieka) {
            return null;
        }

        return (new RecipeStep(['timer_seconds' => $sekundy]))->timerLabel(afterNa: true);
    }

    /** Zdanie o koszcie do podglądu — to samo co na stronie przepisu (D-286). */
    public static function etykietaKosztu(string $koszt): ?string
    {
        $liczba = KosztPrzepisu::naLiczbe($koszt);

        return $liczba === null ? null : KosztPrzepisu::zdanie($liczba);
    }

    /** Ta sama reguła co na stronie przepisu i w filtrze „Do 30 minut” (#1090). */
    public static function czasRazem(string $przygotowanie, string $gotowanie): ?int
    {
        return (new Recipe([
            'prep_minutes' => self::calkowitaLubNull($przygotowanie),
            'cook_minutes' => self::calkowitaLubNull($gotowanie),
        ]))->totalMinutes();
    }

    /** Przycięty tekst albo `null` dla pustego. */
    public static function tekstLubNull(mixed $wartosc): ?string
    {
        $przyciety = trim((string) $wartosc);

        return $przyciety === '' ? null : $przyciety;
    }

    /** Liczba z przecinkiem albo kropką („0,5”); `null` dla pustego i nieliczbowego. */
    public static function liczbaLubNull(string $wartosc): ?float
    {
        $przyciety = str_replace(',', '.', trim($wartosc));

        return $przyciety === '' || ! is_numeric($przyciety) ? null : (float) $przyciety;
    }

    public static function calkowitaLubNull(string $wartosc): ?int
    {
        $przyciety = trim($wartosc);

        return $przyciety === '' || ! is_numeric($przyciety) ? null : (int) $przyciety;
    }

    /** Wartość z bazy z powrotem do pola tekstowego: `2.50` → „2.5”, `null` → „”. */
    public static function liczbaNaTekst(mixed $wartosc): string
    {
        if ($wartosc === null) {
            return '';
        }

        if (is_float($wartosc)) {
            return rtrim(rtrim(number_format($wartosc, 2, '.', ''), '0'), '.');
        }

        return (string) $wartosc;
    }
}
