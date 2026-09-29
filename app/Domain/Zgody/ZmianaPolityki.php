<?php

declare(strict_types=1);

namespace App\Domain\Zgody;

use App\Models\User;
use App\Support\Czas;
use Carbon\CarbonImmutable;

/**
 * Pasek „Zmieniliśmy politykę prywatności — co się zmieniło” (D-327, D-332).
 *
 * Bliźniak `ZmianaRegulaminu`: polityka §9 obiecuje przy zmianie istotnej
 * „powiadomienie w serwisie”, a `WersjaDokumentuTest` nie pozwala oznaczyć
 * zmiany jako istotnej bez tego paska. Zalogowana osoba widzi go na każdym
 * ekranie, dopóki go nie zamknie; zamknięcie zapisuje wersję
 * (`users.policy_notice_dismissed_version`) i przy niej pasek nie wraca.
 * Następne podbicie `kuking.zgody.wersja_polityki` pokazuje go znowu.
 *
 * Konto założone W DNIU wersji albo później paska nie dostaje: przy rejestracji
 * miało przed sobą już nowy tekst.
 *
 * Zamknięcie paska NIE jest zgodą ani akceptacją — to ślad, że komunikat
 * dotarł. Termin wejścia w życie liczy `WersjaDokumentu::polityka()`.
 */
final class ZmianaPolityki
{
    /** Data PUBLIKACJI bieżącej wersji — od niej liczy się pasek. */
    public static function wersja(): string
    {
        return (string) config('kuking.zgody.wersja_polityki');
    }

    public function dokument(): WersjaDokumentu
    {
        return WersjaDokumentu::polityka();
    }

    public function pokazac(?User $user): bool
    {
        if ($user === null || $user->created_at === null) {
            return false;
        }

        $wersja = self::wersja();
        $poczatekWersji = CarbonImmutable::parse($wersja, Czas::strefa())->startOfDay();

        if ($user->created_at->greaterThanOrEqualTo($poczatekWersji)) {
            return false;
        }

        $zamknieta = $user->policy_notice_dismissed_version;

        return $zamknieta === null || (string) $zamknieta < $wersja;
    }

    /** Zapis wersji, przy której osoba zamknęła pasek. Tylko tędy. */
    public function zamknij(User $user): void
    {
        $user->forceFill(['policy_notice_dismissed_version' => self::wersja()])->save();
    }
}
