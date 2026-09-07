<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Limity tagów w JEDNYM miejscu — pochodne `config('kuking.tags.*')`.
 *
 * WZOREM `App\Support\LimityZdjec`. Ten plik ma w komentarzu opisaną
 * historię błędu, przez który powstał: TA SAMA liczba (limit zdjęć na
 * wysyłkę) była przepisana ręcznie w pięciu miejscach — `PostController`,
 * `CookedEventController`, `ProfileSettingsController` i dwa razy
 * w `RecipeController` — i rozjechała się osobno w każdym z nich (audyt
 * A31). Limity tagów (5 na wpis, 2–30 znaków) mają dokładnie ten sam
 * kształt ryzyka: kontroler, akcja domenowa (`PublishPost`, `EditPost`)
 * i widok muszą się zgadzać co do TEJ SAMEJ liczby, więc żadna z nich nie ma
 * prawa być wpisana wprost.
 *
 * AGENTS.md §7: „Limity zapytań są w `config/kuking.php`, nie rozsiane po
 * trasach" — ta sama zasada dotyczy limitów PRODUKTOWYCH, nie tylko limitów
 * zapytań (`throttle`).
 */
final class LimityTagow
{
    /**
     * Minimalna długość nazwy tagu PO normalizacji (przycięcie, redukcja
     * białych znaków). To jest TEN SAM próg, którego używa wyszukiwarka
     * (`SearchQuery::recipes()`/`people()`: `mb_strlen($phrase) < 2`) —
     * spójność z istniejącym kodem, nie osobna, przypadkowa liczba.
     */
    public static function minZnakow(): int
    {
        return (int) config('kuking.tags.min_length');
    }

    public static function maksZnakow(): int
    {
        return (int) config('kuking.tags.max_length');
    }

    /**
     * Ile RÓŻNYCH tagów wolno przypiąć do JEDNEGO wpisu.
     *
     * Liczony na UNIKALNYCH `tag_id` po rozwiązaniu nazw, nie na wpisanych
     * frazach — dokładnie ta sama zasada „bramka liczona na sumie, nie na
     * każdej drodze osobno", którą `PostController::zebranZdjecia()` już
     * stosuje dla zdjęć (nowe pliki + odzyskane po błędzie walidacji razem).
     * Bez tego dałoby się obejść limit, wpisując ten sam tag pod dwiema
     * różnymi pisowniami, które i tak rozwiążą się do jednego wiersza.
     */
    public static function maksTagowNaWpis(): int
    {
        return (int) config('kuking.tags.max_per_post');
    }

    /** Ile podpowiedzi zwraca wyszukiwarka tagów (SPEC §1.5). */
    public static function maksPodpowiedzi(): int
    {
        return (int) config('kuking.tags.suggestions_limit');
    }

    /**
     * Dozwolone znaki w nazwie tagu (SPEC §1.2): litery Unicode, cyfry,
     * spacja i myślnik. Bez HTML, bez znaków sterujących, bez separatorów
     * mających udawać spację (np. niełamliwej spacji U+00A0, którą oko nie
     * odróżni od zwykłej, a `trim()` jej nie usuwa).
     *
     * `\p{L}` i `\p{N}` to klasy Unicode (litera/cyfra w KAŻDYM alfabecie,
     * nie tylko ASCII) — modyfikator `u` włącza tryb Unicode w PCRE.
     *
     * MODYFIKATOR `D` JEST TU KONIECZNY, NIE KOSMETYCZNY.
     * Bez niego `$` w PCRE domyślnie dopasowuje się też TUŻ PRZED końcowym
     * znakiem nowej linii — więc `"sernik\n"` przeszłoby ten wzorzec, mimo
     * że zawiera znak sterujący spoza dozwolonego zbioru. Zmierzone: pierwsza
     * wersja tej metody (bez `D`) przepuszczała dokładnie taki ciąg, a test
     * `LimityTagow::pasujeDoWzorca("sernik\n")` łapał to od razu.
     */
    public static function pasujeDoWzorca(string $znormalizowanaNazwa): bool
    {
        return (bool) preg_match('/^[\p{L}\p{N} -]+$/uD', $znormalizowanaNazwa);
    }

    /**
     * Czy znormalizowana nazwa mieści się w limicie długości.
     *
     * Długość liczona PO normalizacji — inaczej „  sernik  " (12 znaków
     * surowych) i "sernik" (6 po normalizacji) dawałyby różną odpowiedź
     * na pytanie „czy to jest za krótkie", co jest mylące dla człowieka,
     * który widzi na ekranie tylko wynik.
     */
    public static function dlugoscOk(string $znormalizowanaNazwa): bool
    {
        $dlugosc = mb_strlen($znormalizowanaNazwa);

        return $dlugosc >= self::minZnakow() && $dlugosc <= self::maksZnakow();
    }

    public static function komunikatZaDuzoTagow(): string
    {
        $limit = self::maksTagowNaWpis();

        return 'Do jednego wpisu można dodać maksymalnie '.$limit.' '
            .Odmiana::rzeczownik($limit, 'tag', 'tagi', 'tagów').'.';
    }

    public static function komunikatNiepoprawnaNazwa(): string
    {
        return 'Nazwa tagu musi mieć od '.self::minZnakow().' do '.self::maksZnakow()
            .' znaków i może zawierać tylko litery, cyfry, spacje i myślniki.';
    }

    public static function komunikatTagJuzDodany(): string
    {
        return 'Ten tag jest już dodany do wpisu.';
    }

    public static function komunikatNiedozwolonaNazwa(): string
    {
        return 'Ta nazwa tagu jest niedozwolona. Wybierz inną.';
    }
}
