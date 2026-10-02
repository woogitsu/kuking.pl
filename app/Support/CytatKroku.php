<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Recipe;

/**
 * Krótki cytat kroku przepisu na start pytania do autora (#2556, V2).
 *
 * „Zapytaj o ten krok” to zwykły link do strony przepisu z numerem kroku
 * w adresie. Strona wstawia do TEGO SAMEGO formularza komentarza jedno zdanie
 * z początkiem kroku i zostawia decyzję człowiekowi: nic nie wysyła się samo,
 * cytat jest zwykłym tekstem w polu i można go poprawić albo usunąć.
 *
 * Cytat to treść komentarza z chwili wysłania — nie ma powiązania z wierszem
 * kroku, więc późniejsza zmiana albo przestawienie kroków przez autora niczego
 * w już zadanym pytaniu nie zmienia. Dlatego w tekście stoi numer kroku, a nie
 * odnośnik do niego.
 *
 * Tekst bierzemy z bazy po numerze (same cyfry, w zakresie kroków), nigdy
 * z adresu: z adresu nie da się wstawić do pola nic poza liczbą.
 */
final class CytatKroku
{
    /** Najwyżej tyle znaków samego fragmentu kroku (bez oprawy). */
    public const LIMIT_ZNAKOW = 160;

    public const PARAMETR = 'krok';

    /**
     * Tekst startowy pola komentarza albo `null`, gdy numer nie wskazuje
     * kroku tego przepisu (wtedy pole zostaje puste jak zawsze).
     */
    public static function tekstStartowy(Recipe $recipe, mixed $numer): ?string
    {
        if (! is_string($numer) || preg_match('/^[1-9][0-9]{0,2}$/', $numer) !== 1) {
            return null;
        }

        $krok = $recipe->steps->first(fn ($s): bool => $s->position + 1 === (int) $numer);
        if ($krok === null) {
            return null;
        }

        $fragment = self::skroc((string) $krok->instruction);
        if ($fragment === '') {
            return null;
        }

        return 'Pytanie o krok '.$numer.': „'.$fragment.'”'."\n\n";
    }

    /** Początek kroku do limitu, cięty na granicy słowa, w jednym wierszu. */
    public static function skroc(string $instrukcja): string
    {
        $tekst = trim((string) preg_replace('/\s+/u', ' ', $instrukcja));
        // Cudzysłowy w środku zepsułyby czytelność oprawy „…”.
        $tekst = str_replace(['„', '”', '"'], '', $tekst);

        if (mb_strlen($tekst) <= self::LIMIT_ZNAKOW) {
            return $tekst;
        }

        $ciety = mb_substr($tekst, 0, self::LIMIT_ZNAKOW);
        $spacja = mb_strrpos($ciety, ' ');
        if ($spacja !== false && $spacja > (int) (self::LIMIT_ZNAKOW / 2)) {
            $ciety = mb_substr($ciety, 0, $spacja);
        }

        return rtrim($ciety, ' ,;:.-').'…';
    }
}
