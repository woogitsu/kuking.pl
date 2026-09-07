<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

use App\Exceptions\BladDlaCzlowieka;

/**
 * Minutnik jednego kroku przepisu — JEDNO miejsce, w którym minuty człowieka
 * zamieniają się na sekundy bazy.
 *
 * DLACZEGO MINUTY WCHODZĄ, A SEKUNDY WYCHODZĄ
 * Człowiek myśli o gotowaniu w minutach („piecz 45 minut"), więc formularz
 * pyta o minuty. Baza i tryb gotowania liczą w sekundach: kolumna
 * `recipe_steps.timer_seconds`, `RecipeStep::timerLabel()` oraz atrybut
 * `data-timer-sekundy`, z którego odliczanie w przeglądarce bierze wartość
 * startową. Przelicznik musi więc istnieć — pytanie tylko, ile razy.
 *
 * DLACZEGO TO NIE MIESZKA W KONTROLERZE
 * Drogi zapisu przepisu są DWIE i obie są prawdziwe (AGENTS.md §5): formularz
 * jednostronicowy bez JavaScriptu i kreator Livewire. Przelicznik wpisany
 * w każdą z nich osobno to dwie kopie tej samej arytmetyki i dwie okazje,
 * żeby jedna droga zapisała minuty tam, gdzie druga zapisuje sekundy — a
 * wtedy „45" w jednym przepisie znaczy 45 minut, a w drugim 45 sekund
 * i nikt tego nie zauważy, dopóki ktoś nie spali obiadu.
 *
 * GRANICE SĄ TU, NIE TYLKO W REGUŁACH WALIDACJI FORMULARZA
 * Baza ma CHECK `timer_seconds IS NULL OR timer_seconds >= 0`, czyli łapie
 * wyłącznie wartość ujemną — i łapie ją wyjątkiem SQL-a, czyli błędem 500
 * i utratą całej pracy autora. Wartość absurdalnie duża albo tekstowa
 * przeszłaby przez CHECK bez słowa. Dlatego prawdziwa bramka stoi tutaj:
 * w warstwie domenowej, przez którą przechodzą OBIE drogi zapisu, a także
 * konsola i przyszły import.
 */
final class StepTimer
{
    /**
     * Górna granica minutnika kroku, w minutach: 10080 = tydzień.
     *
     * TA SAMA LICZBA CO `prep_minutes` I `cook_minutes` w przepisie —
     * świadomie, bo to ta sama kategoria pytania („ile to trwa") i dwa różne
     * sufity znaczyłyby, że formularz przyjmuje w jednym polu to, co odrzuca
     * w drugim. Tydzień jest hojny dla minutnika, który ma odliczać
     * w przeglądarce, ale krok „zakwas dojrzewa trzy dni" i „mięso marynuje
     * się dobę" są prawdziwe i przepis ma prawo je zapisać.
     */
    public const MAX_MINUTES = 10080;

    public const KOMUNIKAT_NIE_LICZBA = 'Czas kroku podaj w pełnych minutach, na przykład 45. '
        .'Zostaw to pole puste, jeśli ten krok nie potrzebuje minutnika.';

    public const KOMUNIKAT_UJEMNY = 'Czas kroku nie może być ujemny. Wpisz, ile minut ma trwać ten krok — '
        .'na przykład 45 — albo zostaw pole puste.';

    public const KOMUNIKAT_ZA_DUZO = 'Ten czas jest nierealnie długi. Wpisz najwyżej '
        .self::MAX_MINUTES.' minut, czyli tydzień.';

    /**
     * Minuty od człowieka → sekundy do bazy.
     *
     * Puste pole to „ten krok nie potrzebuje minutnika", nie zero — pole jest
     * nieobowiązkowe i większość kroków go nie ma.
     *
     * ZERO TEŻ ZNACZY „BEZ MINUTNIKA", a nie „minutnik na zero sekund".
     * `RecipeStep::timerLabel()` zwraca dla zera `null`, więc tryb gotowania
     * i tak nic by nie pokazał — a wiersz z zapisanym zerem byłby wartością,
     * która wygląda jak ustawiona i nie działa. Zapisujemy `NULL`, czyli to,
     * co ten krok naprawdę znaczy.
     *
     * @throws BladDlaCzlowieka gdy wartość nie jest liczbą minut albo wykracza
     *                          poza granice — komunikat mówi, CO ZROBIĆ
     */
    public static function secondsFromMinutes(mixed $minutes): ?int
    {
        if ($minutes === null) {
            return null;
        }

        // Tablica ani obiekt nie zamienią się na tekst bez awarii PHP-a,
        // a `true` zamieniłoby się po cichu na „1 minutę". Formularz umie
        // przysłać jedno i drugie (`steps[0][timer_minutes][]`).
        if (! is_scalar($minutes) || is_bool($minutes)) {
            throw new BladDlaCzlowieka(self::KOMUNIKAT_NIE_LICZBA);
        }

        $text = trim((string) $minutes);

        if ($text === '') {
            return null;
        }

        // Wartość ujemna ma własny komunikat: „to nie jest liczba" byłoby
        // nieprawdą i nie mówiłoby, co poprawić.
        if (preg_match('/^-\d+$/', $text) === 1) {
            throw new BladDlaCzlowieka(self::KOMUNIKAT_UJEMNY);
        }

        // Minutnik chodzi w PEŁNYCH minutach, więc „45,5" nie jest tu
        // zaokrąglane po cichu — człowiek dostaje pytanie z powrotem.
        if (preg_match('/^\d+$/', $text) !== 1) {
            throw new BladDlaCzlowieka(self::KOMUNIKAT_NIE_LICZBA);
        }

        // Długość TEKSTU sprawdzamy przed rzutowaniem na int.
        // „99999999999999999999" po `(int)` zostaje przycięte do PHP_INT_MAX,
        // więc porównanie po rzutowaniu przepuściłoby liczbę, której nikt nie
        // wpisał — a mnożenie razy 60 przekręciłoby licznik na ujemny i trafiło
        // w CHECK bazy, czyli w błąd 500.
        if (mb_strlen(ltrim($text, '0')) > mb_strlen((string) self::MAX_MINUTES)) {
            throw new BladDlaCzlowieka(self::KOMUNIKAT_ZA_DUZO);
        }

        $wartosc = (int) $text;

        if ($wartosc > self::MAX_MINUTES) {
            throw new BladDlaCzlowieka(self::KOMUNIKAT_ZA_DUZO);
        }

        return $wartosc === 0 ? null : $wartosc * 60;
    }

    /**
     * Sekundy z bazy → minuty do formularza.
     *
     * Zaokrąglenie W GÓRĘ, nie w dół: krok z minutnikiem 90 sekund pokazuje
     * w formularzu „2", a nie „1". Przy zaokrągleniu w dół krok z minutnikiem
     * krótszym niż minuta pokazywałby „0", czyli PUSTE pole — i pierwszy zapis
     * z formularza skasowałby minutnik, którego nikt nie chciał ruszać.
     * Minutniki krótsze niż minuta mogą dziś powstać tylko poza tym formularzem
     * (fabryka, konsola), ale „poza formularzem" nie znaczy „nieprawdziwe".
     */
    public static function minutesFromSeconds(?int $seconds): ?int
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        return (int) ceil($seconds / 60);
    }
}
