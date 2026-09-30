<?php

declare(strict_types=1);

namespace App\Domain\Zgody;

use App\Models\User;
use App\Models\WpisZgody;
use InvalidArgumentException;

/**
 * Trwały dowód akceptacji regulaminu przy rejestracji (#2217).
 *
 * Woła to `ZalozKonto` WEWNĄTRZ transakcji zakładającej konto, dla KAŻDEJ
 * drogi rejestracji (hasło, Google, Facebook) — konto i dowód powstają razem
 * albo wcale. Wiersz idzie do `dziennik_zgod` (D-072, append-only): nowa
 * wersja regulaminu przy nowej akceptacji dopisuje NOWY wiersz, a nigdy nie
 * przepisuje starego.
 *
 * Zapisujemy wersję OBOWIĄZUJĄCĄ w chwili akceptacji
 * (`WersjaDokumentu::regulamin()->obowiazujaca()`, D-327), obok wersji
 * polityki prywatności, którą człowiek miał wtedy przed sobą. Zamknięcie
 * paska o zmianie regulaminu tędy nie przechodzi i akceptacją nie jest.
 */
final class ZapiszAkceptacjeRegulaminu
{
    private const ZRODLA = [
        WpisZgody::ZRODLO_REJESTRACJA_HASLO,
        WpisZgody::ZRODLO_REJESTRACJA_GOOGLE,
        WpisZgody::ZRODLO_REJESTRACJA_FACEBOOK,
    ];

    /**
     * @param  string  $zrodlo  jedna ze stałych `WpisZgody::ZRODLO_REJESTRACJA_*`
     */
    public function handle(User $osoba, string $zrodlo): WpisZgody
    {
        if (! in_array($zrodlo, self::ZRODLA, true)) {
            throw new InvalidArgumentException("Nieznane źródło akceptacji regulaminu: {$zrodlo}.");
        }

        return WpisZgody::create([
            'user_id' => $osoba->getKey(),
            'cel' => WpisZgody::CEL_REGULAMIN,
            'czynnosc' => WpisZgody::UDZIELONA,
            'zrodlo' => $zrodlo,
            'wersja_polityki' => WersjaDokumentu::polityka()->obowiazujaca(),
            'wersja_regulaminu' => WersjaDokumentu::regulamin()->obowiazujaca(),
            'wystapilo_at' => now(),
        ]);
    }
}
