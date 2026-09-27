<?php

declare(strict_types=1);

namespace App\Domain\Rocznice;

use App\Models\User;
use App\Support\Czas;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Rocznica dołączenia: jedno zdanie od gospodarza na `/home` (issue #1754).
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Research `docs/research/PROFIL_FORMA_I_URODZINY.md` §5 i decyzja
 * właściciela z 25.09.2026: w rocznicę założenia konta człowiek widzi na
 * stronie głównej jedno ciche zdanie od gospodarza. Zero nowych danych —
 * liczymy wyłącznie z `users.created_at`.
 *
 * CZEGO TA KLASA NIE ROBI I ROBIĆ NIE MA
 *   - nie wysyła powiadomień, maili ani pushy (kryterium issue);
 *   - nie wstawia niczego do feedu — to blok u samego zainteresowanego,
 *     tak samo jak Wspomnienia (AGENTS.md §8: feed chronologiczny);
 *   - nie liczy „serii", nie nagradza i nie porównuje z innymi (§12).
 *
 * WYŁĄCZNIK
 * Wspólny ze Wspomnieniami (`users.memories_enabled`). To ten sam rodzaj
 * treści — „ten dzień w poprzednich latach" — i ta sama wada: rocznica
 * potrafi zaboleć, gdy konto prowadzi rodzina po śmierci osoby, która je
 * zakładała. Jeden przełącznik zamiast dwóch: człowiek w żałobie nie ma
 * szukać drugiego.
 *
 * STREFA
 * Dzień liczony w strefie człowieka (`Czas::lokalnie`), nie w UTC — konto
 * założone 15 marca o 00:30 czasu polskiego ma rocznicę 15 marca, choć
 * w bazie stoi 14 marca 23:30 UTC. Ten sam błąd przeżył długo we
 * Wspomnieniach (`WspomnieniaTest::test_wpis_z_pierwszej_w_nocy_wraca_w_swoja_rocznice`).
 *
 * 29 LUTEGO
 * Konto założone 29 lutego ma rocznicę 28 lutego w latach nieprzestępnych.
 * Inaczej przez trzy lata z czterech nie miałoby jej wcale.
 */
final class RocznicaDolaczenia
{
    /**
     * Zdanie na dziś albo `null`, gdy dziś nie ma rocznicy lub człowiek
     * wyłączył tę mechanikę.
     */
    public function dlaOsoby(User $user): ?string
    {
        if (! $user->memories_enabled || $user->created_at === null) {
            return null;
        }

        $lat = $this->ileLatDzis($user->created_at, Carbon::now());

        return $lat === null ? null : $this->tekst($user, $lat);
    }

    /**
     * Ile pełnych lat mija DZIŚ od założenia konta — albo `null`, gdy dziś
     * nie jest dzień rocznicy (także w dniu założenia konta: to jeszcze nie
     * rocznica).
     */
    public function ileLatDzis(CarbonInterface $zalozone, CarbonInterface $teraz): ?int
    {
        $od = Czas::lokalnie($zalozone);
        $dzis = Czas::lokalnie($teraz);

        $lat = $dzis->year - $od->year;
        if ($lat < 1) {
            return null;
        }

        $miesiac = $od->month;
        $dzien = $od->day;

        // 29 lutego w roku nieprzestępnym obchodzimy 28 lutego.
        if ($miesiac === 2 && $dzien === 29 && ! $dzis->isLeapYear()) {
            $dzien = 28;
        }

        return ($dzis->month === $miesiac && $dzis->day === $dzien) ? $lat : null;
    }

    /**
     * JEDYNE MIEJSCE, W KTÓRYM POWSTAJE TEKST ROCZNICY.
     *
     * Dziś bez rodzaju („Gotujesz z nami…" — wzór z `docs/brand/COPY_STYLE.md`
     * §2, sprawdzony przez `TekstyNiePrzypisujaPlciTest`). Forma gramatyczna
     * z ustawienia „Jak mamy do Ciebie pisać?" (issue #1752/#1753) wejdzie
     * TUTAJ, przez wspólny helper formy — stąd `$user` w sygnaturze, choć
     * dziś nie jest czytany. Widok dostaje gotowe zdanie i nie składa go sam.
     */
    public function tekst(User $user, int $lat): string
    {
        // „od roku", „od 2 lat", „od 5 lat", „od 22 lat" — dla każdej liczby
        // od dwóch w górę dopełniacz liczby mnogiej brzmi „lat".
        $odKiedy = $lat === 1 ? 'od roku' : 'od '.$lat.' lat';

        return 'Gotujesz z nami '.$odKiedy.' — dziękuję, że jesteś.';
    }

    /** Podpis pod zdaniem: imię gospodarza z jednego miejsca w konfiguracji. */
    public function podpis(): string
    {
        return (string) config('kuking.community.host_name');
    }
}
