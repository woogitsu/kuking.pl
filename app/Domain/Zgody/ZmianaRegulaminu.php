<?php

declare(strict_types=1);

namespace App\Domain\Zgody;

use App\Models\User;
use App\Support\Czas;
use Carbon\CarbonImmutable;

/**
 * Pasek „Zmieniliśmy regulamin — co się zmieniło" (#1811, D-306).
 *
 * Decyzja właściciela z 26 września 2026: o zmianie regulaminu mówimy
 * KOMUNIKATEM W SERWISIE, bez maili. Zalogowana osoba widzi pasek na każdym
 * ekranie, dopóki go nie zamknie; zamknięcie zapisuje wersję
 * (`users.terms_notice_dismissed_version`) i przy tej wersji pasek już nie
 * wraca. Następne podbicie `kuking.zgody.wersja_regulaminu` pokazuje go znowu.
 *
 * Konto założone W DNIU wersji albo później paska nie dostaje: przy rejestracji
 * miało przed sobą już nowy tekst, więc „zmieniliśmy" nie byłoby prawdą.
 *
 * Zamknięcie paska NIE jest akceptacją regulaminu i nigdzie tak nie jest
 * opisane — to ślad, że komunikat do tej osoby dotarł.
 */
final class ZmianaRegulaminu
{
    public static function wersja(): string
    {
        return (string) config('kuking.zgody.wersja_regulaminu');
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

        $zamknieta = $user->terms_notice_dismissed_version;

        return $zamknieta === null || (string) $zamknieta < $wersja;
    }

    /** Zapis wersji, przy której osoba zamknęła pasek. Tylko tędy. */
    public function zamknij(User $user): void
    {
        $user->forceFill(['terms_notice_dismissed_version' => self::wersja()])->save();
    }
}
