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
     * BRZEGI PRZYCINAMY PO TRANSLITERACJI (#2331). Kontroler przycina frazę
     * przed tą metodą, ale emoji na brzegu znika dopiero tutaj i zostawia
     * spację: „Basia 🍲" dawało „basia ", a `LIKE '%basia %'` nie znajdowało
     * konta „Basia". Środka frazy to nie dotyka — „Basia 🍲 Nowak" zostaje
     * z dwiema spacjami, tak jak dotąd.
     */
    public static function normalizuj(string $fraza): string
    {
        return trim(mb_strtolower(Str::ascii($fraza)));
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
