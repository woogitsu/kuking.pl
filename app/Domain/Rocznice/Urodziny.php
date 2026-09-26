<?php

declare(strict_types=1);

namespace App\Domain\Rocznice;

use App\Models\User;
use App\Support\Czas;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Urodziny: dzień i miesiąc, bez roku (issue #1755).
 *
 * Decyzje właściciela z 25.09.2026 (research
 * `docs/research/PROFIL_FORMA_I_URODZINY.md`):
 *   - pole opcjonalne, sam dzień i miesiąc — roku nie zbieramy;
 *   - data jest prywatna: nie ma jej na profilu publicznym ani w żadnym
 *     widoku dla innych, poza przypomnieniem obserwującym, które osoba
 *     musi sama włączyć;
 *   - 29 lutego obchodzimy 28 lutego w latach nieprzestępnych.
 *
 * Ta klasa trzyma REGUŁY DATY w jednym miejscu, żeby blok na `/home`, mail
 * i przypomnienie liczyły „czy dziś" tak samo.
 */
final class Urodziny
{
    /** Najwięcej dni w miesiącu, z 29 lutego włącznie (jak CHECK w bazie). */
    public static function dniWMiesiacu(int $miesiac): int
    {
        return match ($miesiac) {
            2 => 29,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }

    /** Czy para dzień–miesiąc istnieje w kalendarzu (29.02 tak, 31.04 nie). */
    public static function poprawna(int $dzien, int $miesiac): bool
    {
        return $miesiac >= 1 && $miesiac <= 12
            && $dzien >= 1 && $dzien <= self::dniWMiesiacu($miesiac);
    }

    public static function maDate(User $user): bool
    {
        return $user->birthday_day !== null && $user->birthday_month !== null;
    }

    /**
     * Czy dziś — w strefie człowieka, nie w UTC — są urodziny tej osoby.
     */
    public static function czyDzis(User $user, ?CarbonInterface $teraz = null): bool
    {
        if (! self::maDate($user)) {
            return false;
        }

        return self::wypadaDnia((int) $user->birthday_day, (int) $user->birthday_month, $teraz ?? Carbon::now());
    }

    /**
     * Rdzeń reguły, bez modelu — żeby zapytanie SQL w przypomnieniach
     * i test mogły sprawdzić dokładnie to samo.
     */
    public static function wypadaDnia(int $dzien, int $miesiac, CarbonInterface $teraz): bool
    {
        $dzis = Czas::lokalnie($teraz);

        // 29 lutego w roku nieprzestępnym obchodzimy 28 lutego.
        if ($miesiac === 2 && $dzien === 29 && ! $dzis->isLeapYear()) {
            $dzien = 28;
        }

        return $dzis->month === $miesiac && $dzis->day === $dzien;
    }

    /**
     * Pary (dzień, miesiąc), które DZIŚ obchodzą urodziny — do zapytań po
     * wielu kontach. W zwykły dzień jedna para; 28 lutego roku
     * nieprzestępnego dwie (28.02 i 29.02).
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function dzisiejszePary(?CarbonInterface $teraz = null): array
    {
        $dzis = Czas::lokalnie($teraz ?? Carbon::now());
        $pary = [[$dzis->day, $dzis->month]];

        if ($dzis->month === 2 && $dzis->day === 28 && ! $dzis->isLeapYear()) {
            $pary[] = [29, 2];
        }

        return $pary;
    }

    /**
     * Życzenia od gospodarza na `/home` (etap b) — albo `null`, gdy dziś nie
     * ma urodzin, daty nie podano albo człowiek wyłączył życzenia.
     *
     * Blok u samego zainteresowanego, jak Wspomnienia: bez powiadomienia,
     * bez wstawki do feedu, bez pustego stanu.
     */
    public static function zyczeniaNaDzis(User $user, ?CarbonInterface $teraz = null): ?string
    {
        if (! $user->birthday_wishes_enabled || ! self::czyDzis($user, $teraz)) {
            return null;
        }

        return self::tekstZyczen($user);
    }

    /**
     * JEDYNE MIEJSCE, W KTÓRYM POWSTAJE TEKST ŻYCZEŃ — na stronie i w mailu.
     *
     * Dziś bez rodzaju. Forma gramatyczna z ustawienia „Jak mamy do Ciebie
     * pisać?" (#1752/#1753) wejdzie TUTAJ, przez wspólny helper formy.
     * Imienia nie odmieniamy (D-153) — stoi po przecinku, w mianowniku,
     * tak jak w powitaniu „Dzień dobry, {imię}".
     */
    public static function tekstZyczen(User $user): string
    {
        return 'Wszystkiego dobrego z okazji urodzin, '.$user->displayName().'. Dużo zdrowia i smacznego gotowania.';
    }

    /** Podpis: imię gospodarza z jednego miejsca w konfiguracji. */
    public static function podpis(): string
    {
        return (string) config('kuking.community.host_name');
    }

    /** Zapis do paczki RODO: „DD-MM", bez roku — albo `null`. */
    public static function doEksportu(User $user): ?string
    {
        if (! self::maDate($user)) {
            return null;
        }

        return sprintf('%02d-%02d', (int) $user->birthday_day, (int) $user->birthday_month);
    }

    /** „12 marca" — do ekranu ustawień. */
    public static function slownie(User $user): ?string
    {
        if (! self::maDate($user)) {
            return null;
        }

        return $user->birthday_day.' '.self::MIESIACE_DOPELNIACZ[(int) $user->birthday_month];
    }

    /** Nazwy miesięcy w dopełniaczu, do daty „12 marca". */
    public const MIESIACE_DOPELNIACZ = [
        1 => 'stycznia', 2 => 'lutego', 3 => 'marca', 4 => 'kwietnia',
        5 => 'maja', 6 => 'czerwca', 7 => 'lipca', 8 => 'sierpnia',
        9 => 'września', 10 => 'października', 11 => 'listopada', 12 => 'grudnia',
    ];

    /** Nazwy miesięcy w mianowniku, do listy wyboru. */
    public const MIESIACE = [
        1 => 'styczeń', 2 => 'luty', 3 => 'marzec', 4 => 'kwiecień',
        5 => 'maj', 6 => 'czerwiec', 7 => 'lipiec', 8 => 'sierpień',
        9 => 'wrzesień', 10 => 'październik', 11 => 'listopad', 12 => 'grudzień',
    ];
}
