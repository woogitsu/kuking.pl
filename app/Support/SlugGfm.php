<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Algorytm slugów GitHuba (GFM) dla kotwic w dokumentach Markdown.
 *
 * WYDZIELONY Z `App\Support\Wersja::kotwicaWydania()` (issue #1909) przy
 * #1932, bo potrzebuje go teraz DRUGIE miejsce: dopasowanie nagłówków `###`
 * z `resources/nowosci/tresc.md` do wierszy `wdrozenia_funkcje`
 * (`App\Domain\Wydania\Actions\ZarejestrujWdrozenie` i
 * `App\Http\Controllers\NowosciController`). Oba muszą liczyć DOKŁADNIE ten
 * sam slug dla tego samego tekstu — inaczej „od Alfa 0.68.NNN" nigdy by się
 * nie dopasowało do właściwego akapitu, mimo poprawnych danych w bazie.
 *
 * Algorytm (sprawdzony też przez
 * `tests/Feature/DokumentyMdNieMajaMartwychOdnosnikowTest.php` na WSZYSTKICH
 * plikach `.md` repozytorium): małe litery, spacja → myślnik, potem zostają
 * tylko litery (Unicode), cyfry, myślniki i podkreślenia — reszta (kropka,
 * myślnik długi, przecinek…) znika BEZ ZASTĘPCZEGO ZNAKU.
 * „Alfa 0.68 — kuchnia" → „alfa-068-—-kuchnia" → po usunięciu reszty →
 * „alfa-068-kuchnia" (myślnik długi „—" nie jest literą ani cyfrą, więc
 * znika razem z otaczającymi go spacjami zamienionymi na myślniki, ZANIM
 * ich usunięcie skleiłoby dwa sąsiadujące myślniki w jeden — GitHub też
 * zostawia podwójny myślnik, więc i my go zostawiamy, nie normalizujemy).
 *
 * Nie używamy `Str::slug()` z Laravela — ma inne zachowanie separatorów
 * i dałby PODOBNY, nie IDENTYCZNY wynik.
 */
final class SlugGfm
{
    public static function z(string $tekst): string
    {
        $tekst = mb_strtolower(trim($tekst));
        $tekst = str_replace(' ', '-', $tekst);

        $wynik = '';

        foreach (mb_str_split($tekst) as $znak) {
            if ($znak === '-' || $znak === '_' || preg_match('/\p{L}|\p{N}/u', $znak) === 1) {
                $wynik .= $znak;
            }
        }

        return $wynik;
    }
}
