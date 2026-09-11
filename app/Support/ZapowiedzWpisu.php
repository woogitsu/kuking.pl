<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Zapowiedź treści wpisu na karcie w feedzie (issue #354).
 *
 * PO CO TO ISTNIEJE
 * Jeden wpis z przepisem (lista składników + kroki) wypełniał na telefonie
 * cały ekran i wypychał wszystko poniżej — pod nim nie było widać ani dna
 * karty, ani następnego wpisu. Feed przestawał być feedem. Karta pokazuje
 * więc początek, a całość jest jedno kliknięcie dalej, na stronie wpisu.
 *
 * PRÓG PATRZY NA WIERSZE **I** NA ZNAKI, BO SAME ZNAKI NIE WYSTARCZĄ
 * `.post-card-body` ma `white-space: pre-line`, więc KAŻDE przełamanie
 * wiersza zrobione przez autora zostaje osobnym wierszem na ekranie.
 * „500 g mąki / 350 ml wody / 7 g drożdży / …" to kilkanaście wierszy przy
 * stu kilkudziesięciu znakach: próg liczony wyłącznie w znakach przepuściłby
 * dokładnie ten wpis, od którego zaczęło się zgłoszenie. Skracamy więc, gdy
 * przekroczony jest KTÓRYKOLWIEK z dwóch progów.
 *
 * DLACZEGO `Str::words()`, A NIE `Str::limit()`
 * `Str::limit()` tnie po znakach, czyli w środku wyrazu („ziemniacza…").
 * `Str::words()` dopasowuje całe wyrazy razem z odstępami, które po nich
 * stoją — a że `\s` obejmuje też znak nowego wiersza, układ wierszy autora
 * zostaje w zapowiedzi nietknięty. To jest istotne przy liście składników.
 *
 * DLACZEGO NIE `-webkit-line-clamp`
 * Klamra CSS liczy wiersze w przeglądarce, ale nie ma jak powiedzieć
 * szablonowi, CZY w ogóle przyciąć — odnośnik „Czytaj dalej" stałby wtedy
 * pod każdym wpisem, także dwuzdaniowym, a martwy przycisk jest zakazany
 * (D-053, AGENTS.md §5). Do tego wysokość klamry podaje się w `rem`, a `rem`
 * rośnie razem z ustawieniem rozmiaru tekstu (D-082/D-107) — próg znaczyłby
 * co innego u każdego czytelnika.
 *
 * TO NIE JEST `App\Support\Skrot`
 * Tamta klasa liczy HMAC z adresu IP i nie ma z tą nic wspólnego poza
 * słowem „skrót". Stąd osobna, jednoznaczna nazwa.
 */
final class ZapowiedzWpisu
{
    /** Ponad tyle znaków wpis zajmuje na telefonie więcej niż ekran. */
    public const LIMIT_ZNAKOW = 400;

    /** Ponad tyle wierszy — to samo, nawet gdy znaków jest niewiele. */
    public const LIMIT_WIERSZY = 8;

    /** Znak, po którym widać, że to jeszcze nie koniec wpisu. */
    private const WIELOKROPEK = '…';

    /**
     * Czy ta treść jest na tyle długa, że karta pokazuje tylko początek?
     */
    public static function czyZaDluga(?string $tresc): bool
    {
        $tekst = self::przygotuj($tresc);

        if ($tekst === '') {
            return false;
        }

        return mb_strlen($tekst) > self::LIMIT_ZNAKOW
            || self::liczbaWierszy($tekst) > self::LIMIT_WIERSZY;
    }

    /**
     * Początek treści zakończony wielokropkiem.
     *
     * Treść, która w progi się mieści, wraca bez zmian — dzięki temu widok
     * może wołać tę metodę bez drugiego warunku obok.
     */
    public static function skroc(?string $tresc): string
    {
        $tekst = self::przygotuj($tresc);

        if (! self::czyZaDluga($tekst)) {
            return $tekst;
        }

        // Najpierw wiersze, i zawsze po PEŁNYM wierszu: ucięcie w połowie
        // wiersza listy składników („350 ml wo…") czyta się gorzej niż
        // krótsza, ale kompletna lista.
        $wiersze = explode("\n", $tekst);

        if (count($wiersze) > self::LIMIT_WIERSZY) {
            $tekst = implode("\n", array_slice($wiersze, 0, self::LIMIT_WIERSZY));
        }

        if (mb_strlen($tekst) > self::LIMIT_ZNAKOW) {
            $tekst = self::utnijPoSlowach($tekst, self::LIMIT_ZNAKOW);
        }

        return rtrim($tekst).self::WIELOKROPEK;
    }

    /**
     * Ujednolicone przełamania wierszy i obcięte puste brzegi.
     *
     * Bez tego wpis wysłany z przeglądarki (CRLF) liczyłby się inaczej niż
     * ten sam wpis z aplikacji, a puste wiersze na końcu podbijałyby licznik.
     */
    private static function przygotuj(?string $tresc): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $tresc));
    }

    private static function liczbaWierszy(string $tekst): int
    {
        return substr_count($tekst, "\n") + 1;
    }

    /**
     * Najdłuższy początek tekstu złożony z CAŁYCH wyrazów, mieszczący się
     * w limicie znaków.
     *
     * Liczbę wyrazów zgadujemy z pierwszych `$limit` znaków (więcej ich tam
     * nie ma) i schodzimy w dół, aż wynik zmieści się w limicie — zwykle
     * o jeden krok. Pętla po wszystkich wyrazach byłaby tu marnotrawstwem:
     * treść wpisu ma do 4000 znaków, a ta metoda pracuje na każdej karcie.
     */
    private static function utnijPoSlowach(string $tekst, int $limit): string
    {
        $wyrazy = preg_split('/\s+/u', trim(mb_substr($tekst, 0, $limit)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        for ($ile = max(1, count($wyrazy)); $ile >= 1; $ile--) {
            $kandydat = rtrim(Str::words($tekst, $ile, ''));

            if (mb_strlen($kandydat) <= $limit) {
                return $kandydat;
            }
        }

        // Tu schodzi tylko treść bez ani jednej spacji dłuższa niż limit —
        // czyli nie wyraz, tylko ciąg znaków. Nie ma granicy wyrazu, po
        // której dałoby się uciąć, więc tniemy po znakach. Karta tego nie
        // rozepnie: `.post-card-body` ma `overflow-wrap: anywhere`.
        return rtrim(mb_substr($tekst, 0, $limit));
    }
}
