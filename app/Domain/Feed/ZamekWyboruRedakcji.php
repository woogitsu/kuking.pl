<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use Illuminate\Support\Facades\DB;

/**
 * Jeden pełny wybór redakcyjny naraz — tablica dnia albo kolaż (#1027).
 *
 * Oba narzędzia zastępują CAŁY zestaw przez `DELETE` + serię `INSERT`-ów.
 * Sama transakcja nie serializuje dwóch równoległych zapisów: w domyślnym
 * `READ COMMITTED` oba `DELETE` widzą ten sam (np. pusty) stan, a potem oba
 * zestawy się wstawiają — w bazie zostaje suma A ∪ B, z przekroczonym
 * limitem pozycji i dwoma gospodarzami naraz.
 *
 * Blokada doradcza, a nie `FOR UPDATE` na wierszach wyboru: przy pustym
 * zestawie nie ma czego zablokować, a po `DELETE` blokada na usuniętych
 * wierszach niczego już nie pilnuje. Blokada doradcza istnieje zawsze,
 * także dla dnia, na który nikt jeszcze niczego nie wybrał.
 *
 * `_xact_` — zwalnia się sama przy `COMMIT`/`ROLLBACK`, więc nie ma jak jej
 * zgubić na ścieżce błędu. Wołać WYŁĄCZNIE wewnątrz `DB::transaction()`.
 */
final class ZamekWyboruRedakcji
{
    /**
     * Nasza część globalnej przestrzeni blokad doradczych (por. 647, 1016,
     * 8301 w innych akcjach). Drugi argument rozróżnia zasób: data tablicy
     * jako liczba `RRRRMMDD` albo zero dla kolażu — daty nigdy nie są zerem.
     */
    private const PRZESTRZEN_BLOKAD = 1027;

    private const KOLAZ = 0;

    /** Tablica dnia — osobno dla każdej daty (`RRRR-MM-DD`, jak `Czas::dzisiajData()`). */
    public static function tablicy(string $dzien): void
    {
        self::zablokuj((int) str_replace('-', '', $dzien));
    }

    /** Kolaż strony powitalnej — jeden, globalny zestaw. */
    public static function kolazu(): void
    {
        self::zablokuj(self::KOLAZ);
    }

    /**
     * Liczby wprost w zapytaniu, nie jako parametry: placeholder bez typu nie
     * mówi PostgreSQL, którą wersję `pg_advisory_xact_lock` wołamy (ten sam
     * powód co w `PublishComment`). Obie wartości to `int`, nie dane z żądania.
     */
    private static function zablokuj(int $zasob): void
    {
        DB::select(sprintf('SELECT pg_advisory_xact_lock(%d, %d)', self::PRZESTRZEN_BLOKAD, $zasob));
    }
}
