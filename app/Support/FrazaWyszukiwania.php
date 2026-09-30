<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Fraza wpisana przez człowieka, przygotowana do porównania z kolumną
 * znormalizowaną w bazie (`kuking_normalize`, kolumny `*_search`).
 *
 * JEDNA KOPIA ZAMIAST TRZECH. Ta sama reguła stała osobno w
 * `SearchQuery::normalize()`, `TagSuggester::normalize()` („skopiowane
 * z `SearchQuery::normalize()`") i `TagFollowWindow::normalizuj()`, a razem
 * z nią rozjechały się poprawki, które powinny były dotyczyć wszystkich:
 * naprawa pustej po transliteracji frazy (#1050) i cytowanie metaznaków
 * `LIKE` (#753) trafiły tylko do wyszukiwarki. Podpowiedzi tagów dla frazy
 * z samych emoji budowały więc `LIKE '%'` i podpowiadały pierwsze lepsze
 * tagi, a „%%" dopasowywało wszystkie.
 */
final class FrazaWyszukiwania
{
    /**
     * `Str::ascii()` odpowiada temu, co `unaccent` w bazie robi z polskimi
     * znakami diakrytycznymi — „Żurek" ma znaleźć „żurek". Znaki, których
     * `Str::ascii()` nie umie zapisać (np. emoji), ZNIKAJĄ: wynik bywa
     * krótszy od wejścia, także pusty. Długość sprawdza się więc PO tej
     * metodzie, nie przed nią (#1050).
     *
     * ZNIKAJĄCE SŁOWO ZABIERA ZE SOBĄ SWÓJ ODSTĘP (#2331). Kontrolery
     * przycinają frazę PRZED tą metodą, ale emoji znika dopiero tutaj
     * i zostawiało po sobie spację: „Basia 🍲" dawało „basia ", a wzorzec
     * `LIKE '%basia %'` nie znajdował konta „Basia". Tak samo „🍲 Basia"
     * (spacja z przodu) i „Basia 🍲 Nowak" (dwie spacje w środku, kiedy
     * w nazwie jest jedna). Dlatego fraza jest dzielona na słowa po białych
     * znakach: słowo, z którego transliteracja nic nie zostawia, wypada
     * razem z odstępem, który je poprzedzał, a na końcu brzegi są
     * przycinane (także z odstępu, który `Str::ascii()` robi np. ze spacji
     * zerowej szerokości).
     *
     * ODSTĘPÓW, KTÓRE CZŁOWIEK WPISAŁ MIĘDZY SŁOWAMI, NIE ZWIJAMY. Kolumny
     * `*_search` w bazie ich nie zwijają (`kuking_normalize` to tylko
     * `lower` + `unaccent`), więc nazwa „Anna  Nowak" skopiowana dosłownie
     * ma nadal znaleźć samą siebie. To także nie jest obcinanie frazy —
     * reguła #885 (bez ucinania długiej frazy) zostaje nietknięta.
     */
    public static function normalizuj(string $fraza): string
    {
        $czesci = preg_split('/(\s+)/u', $fraza, -1, PREG_SPLIT_DELIM_CAPTURE);

        // Niepoprawny UTF-8 — `preg_split` z `/u` odmawia. Zachowanie sprzed
        // #2331 plus przycięte brzegi, zamiast wyjątku na ekranie szukania.
        if ($czesci === false) {
            return trim(mb_strtolower(Str::ascii($fraza)));
        }

        $wynik = '';
        $odstep = '';

        foreach ($czesci as $i => $czesc) {
            // Nieparzyste pozycje to przechwycone odstępy, parzyste — słowa.
            if ($i % 2 === 1) {
                $odstep = Str::ascii($czesc);

                continue;
            }

            $slowo = mb_strtolower(Str::ascii($czesc));

            if ($slowo === '') {
                continue;
            }

            $wynik .= ($wynik === '' ? '' : $odstep).$slowo;
        }

        return trim($wynik);
    }

    /**
     * Fraza jako DOSŁOWNY tekst we wzorcu `LIKE` (#753).
     *
     * PostgreSQL bierze `\` jako domyślny znak ucieczki `LIKE`, więc najpierw
     * podwajamy sam znak ucieczki, dopiero potem cytujemy `%` i `_` —
     * inaczej `\` z frazy uciekałby znak wstawiony tutaj. Tylko dla `LIKE`:
     * operator trigramowy `<%` i `word_similarity()` dostają frazę bez tej
     * ucieczki, bo to nie jest wzorzec.
     */
    public static function doLike(string $fraza): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $fraza);
    }
}
