<?php

declare(strict_types=1);

namespace App\Support;

/**
 * JEDNO MIEJSCE, KTÓRE WIE, KTÓRY WIERSZ NALEŻY DO ODRZUCONEGO FORMULARZA
 * (issue #243).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PROBLEM: JEDNA STRONA, WIELE FORMULARZY, JEDNA SESJA
 * ────────────────────────────────────────────────────────────────────────
 *
 * `/admin/sygnaly`, `/admin/zgloszenia` i `/admin/odwolania` stawiają na
 * jednej stronie do dwudziestu pięciu OSOBNYCH formularzy — po jednym na
 * sprawę — a każdy z nich ma pole o TEJ SAMEJ nazwie (`note`, `user_message`,
 * `decision_note`...), bo to jest ten sam formularz decyzji, powtórzony.
 *
 * `old()` i `$errors` nie wiedzą nic o „wierszu": Laravel po nieudanej
 * walidacji odkłada do sesji WSZYSTKIE pola z ostatniego żądania pod ich
 * nazwami. Gdy walidacja formularza sprawy nr 7 się nie powiedzie, `old('note')`
 * zwraca treść z tamtego formularza — i zwróci ją też przy sprawie nr 1, 2
 * i każdej innej na tej stronie, bo to jest DOKŁADNIE TA SAMA nazwa pola.
 * Moderator widzi cudzą notatkę przy swojej sprawie i może tego nie
 * zauważyć.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ROZWIĄZANIE: UKRYTE POLE `_wiersz` + PORÓWNANIE PRZED UŻYCIEM `old()`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Każdy formularz w pętli wysyła dodatkowe ukryte pole {@see self::POLE}
 * z identyfikatorem SWOJEGO wiersza (id sprawy, klucz grupy, id odwołania —
 * cokolwiek jednoznacznie odróżnia tę sprawę od pozostałych na stronie).
 * Laravel odkłada je do sesji razem z resztą pól, więc po nieudanej
 * walidacji `old('_wiersz')` mówi, KTÓRY formularz naprawdę został wysłany.
 *
 * Ta klasa nie zmienia więc ŻADNEGO kontrolera — cały mechanizm żyje
 * w widokach: hasłowe pole `_wiersz` w formularzu i porównanie w miejscach,
 * które i tak już czytają `old()` (`x-field`, `x-error-summary`, oraz ręcznie
 * budowane pola wyboru w `reports.blade.php` i `appeals.blade.php`).
 *
 * Strona bez pola `_wiersz` (zwykły, pojedynczy formularz) zachowuje się
 * dokładnie tak jak dawniej: `jestAktywny()` dostaje `null` i nic nie
 * blokuje.
 */
final class WierszFormularza
{
    /** Nazwa ukrytego pola, które każdy formularz w pętli musi wysłać. */
    public const POLE = '_wiersz';

    /**
     * Czy TEN wiersz jest tym, którego formularz właśnie wrócił z błędem
     * walidacji — czyli czy wolno mu pokazać `old()` i błąd z sesji.
     */
    public static function jestAktywny(int|string $wiersz): bool
    {
        $aktywny = self::aktywnyWiersz();

        return $aktywny !== null && $aktywny === (string) $wiersz;
    }

    /**
     * Identyfikator wiersza, który naprawdę wrócił z błędem — albo `null`,
     * gdy żaden formularz w pętli nie został wysłany.
     *
     * `_wiersz` to zwykłe pole HTML: nic nie stoi na przeszkodzie, żeby
     * żądanie przesłało je jako tablicę (`_wiersz[]=coś`), a kontroler,
     * który waliduje `collection_id` czy `note`, nie ma powodu walidować
     * ukrytego pola pomocniczego. `old(self::POLE)` wraca wtedy z sesji
     * jako `array`, a `(string) $tablica` rzuca `TypeError` w PHP 8 — co
     * wywalało render CAŁEJ strony po nieudanej walidacji (issue #745)
     * zamiast pokazać błąd przy polu. Wartość nieskalarna nie pasuje do
     * ŻADNEGO wiersza — traktujemy ją jak brak `_wiersz`.
     */
    public static function aktywnyWiersz(): ?string
    {
        $aktywny = old(self::POLE);

        return is_scalar($aktywny) ? (string) $aktywny : null;
    }

    /**
     * `old($klucz, $domyslna)`, ale TYLKO dla wiersza, którego formularz
     * naprawdę wrócił z błędem. Każdy inny wiersz dostaje `$domyslna`
     * (zwykle bieżącą wartość z bazy), a nie cudzą treść z sesji.
     */
    public static function stareLubDomyslne(string $klucz, int|string $wiersz, mixed $domyslna = null): mixed
    {
        return self::jestAktywny($wiersz) ? old($klucz, $domyslna) : $domyslna;
    }
}
