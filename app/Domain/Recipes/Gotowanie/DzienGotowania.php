<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie;

use App\Exceptions\BladDlaCzlowieka;
use App\Support\Czas;

/**
 * Prywatny dzień faktycznego gotowania (issue #2583).
 *
 * Dzień KALENDARZOWY (`Y-m-d`), który kucharz sam podaje przy „Ugotowałem",
 * gdy zgłasza wykonanie później niż gotował. Nigdy nie zastępuje
 * `cooked_events.cooked_at` (chwila zgłoszenia) i nie jest czytany przez
 * feed, digest, kohorty ani „Ugotujmy razem".
 *
 * „Dziś" liczymy w strefie człowieka (`Czas::dzisiajData()`, Europe/Warsaw),
 * nie w UTC: przez pierwsze godziny polskiej doby UTC jest jeszcze
 * wczoraj. Data bez godziny, więc zmiana strefy ani czasu letniego nie
 * przesuwa dnia.
 */
final class DzienGotowania
{
    /** Jawna dolna granica; ta sama wartość stoi w CHECK-u migracji. */
    public const NAJWCZESNIEJSZY = '2000-01-01';

    public const KOMUNIKAT_NIEZROZUMIALY = 'Nie rozumiemy tej daty. Wybierz dzień z kalendarza albo zostaw pole puste.';

    public const KOMUNIKAT_Z_PRZYSZLOSCI = 'Ten dzień jeszcze nie nastąpił. Wybierz dzisiejszy dzień albo wcześniejszy, albo zostaw pole puste.';

    public const KOMUNIKAT_ZA_WCZESNY = 'Ten dzień jest zbyt odległy. Wybierz dzień od 1 stycznia 2000 albo późniejszy, albo zostaw pole puste.';

    /**
     * Pusty napis i `null` znaczą „nie podano". Inaczej zwraca poprawną datę
     * `Y-m-d` albo rzuca zdanie mówiące, co zrobić.
     *
     * @throws BladDlaCzlowieka
     */
    public static function sprawdz(?string $dzien): ?string
    {
        $dzien = $dzien === null ? null : trim($dzien);

        if ($dzien === null || $dzien === '') {
            return null;
        }

        $blad = self::blad($dzien);

        if ($blad !== null) {
            throw new BladDlaCzlowieka($blad);
        }

        return $dzien;
    }

    /** Komunikat o błędzie dla niepustego napisu albo `null`, gdy dzień jest poprawny. */
    public static function blad(string $dzien): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dzien) !== 1) {
            return self::KOMUNIKAT_NIEZROZUMIALY;
        }

        $dane = date_parse_from_format('Y-m-d', $dzien);

        if ($dane['error_count'] > 0 || $dane['warning_count'] > 0) {
            return self::KOMUNIKAT_NIEZROZUMIALY;
        }

        // Daty `Y-m-d` porównują się napisowo tak samo jak kalendarzowo.
        if ($dzien > Czas::dzisiajData()) {
            return self::KOMUNIKAT_Z_PRZYSZLOSCI;
        }

        if ($dzien < self::NAJWCZESNIEJSZY) {
            return self::KOMUNIKAT_ZA_WCZESNY;
        }

        return null;
    }
}
