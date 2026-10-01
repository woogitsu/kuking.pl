<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie\Wspolne;

use App\Domain\Users\ZamekKonta;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookingSession;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Cykl życia sesji wspólnego gotowania (#2385): założenie, wyjście pomocnika,
 * usunięcie pomocnika, zakończenie. Projekt:
 * `docs/product/PROJEKT_WSPOLNE_GOTOWANIE_2385.md`.
 *
 * Uprawnienia sprawdza TA klasa (Policy przez `Gate::forUser`), a kontroler
 * robi to jeszcze raz — żeby dodanie drugiego endpointu niczego nie omijało.
 * Każda zmiana odbywa się pod blokadą wiersza sesji i na jego świeżym stanie.
 */
final class SesjaWspolnegoGotowania
{
    public const NIE_MA_SESJI = 'Tej sesji już nie ma — mogła się skończyć albo wygasnąć. Możesz założyć nową.';

    /**
     * Zakłada sesję dla przepisu albo oddaje istniejącą, trwającą (idempotentnie).
     * Pod zamkiem konta gospodarza, więc dwa szybkie kliknięcia nie przeskoczą
     * limitu sesji ani nie założą dwóch.
     */
    public function zaloz(User $gospodarz, Recipe $przepis): CookingSession
    {
        Gate::forUser($gospodarz)->authorize('create', [CookingSession::class, $przepis]);

        if (! $przepis->steps()->exists()) {
            throw new BladDlaCzlowieka('Ten przepis nie ma jeszcze opisanych kroków, więc nie da się go gotować razem.');
        }

        return ZamekKonta::zablokuj($gospodarz, function (?User $swiezy) use ($przepis): CookingSession {
            if ($swiezy === null || ! $swiezy->isActive()) {
                throw new BladDlaCzlowieka('Tego konta nie można teraz użyć do założenia sesji.');
            }

            // Wygasła sesja tej pary czekałaby na nocne sprzątanie i zajmowała
            // unikalny indeks — zwalniamy go tutaj.
            DB::table('cooking_sessions')
                ->where('host_id', $swiezy->getKey())
                ->where('expires_at', '<=', now())
                ->delete();

            $istniejaca = CookingSession::query()
                ->where('host_id', $swiezy->getKey())
                ->where('recipe_id', $przepis->getKey())
                ->first();

            if ($istniejaca !== null) {
                return $istniejaca;
            }

            $ile = CookingSession::query()->where('host_id', $swiezy->getKey())->count();

            if ($ile >= (int) config('kuking.wspolne_gotowanie.max_sesji_gospodarza')) {
                throw new BladDlaCzlowieka('Masz już najwięcej wspólnych gotowań naraz, ile się da. Zakończ jedno z nich albo poczekaj, aż wygaśnie.');
            }

            $teraz = now();
            $sesja = new CookingSession;
            $sesja->forceFill([
                'id' => (string) Str::uuid(),
                'recipe_id' => $przepis->getKey(),
                'host_id' => $swiezy->getKey(),
                'status' => CookingSession::STATUS_ACTIVE,
                'revision' => 1,
                'expires_at' => $teraz->copy()->addHours(self::godziny()),
            ])->save();

            return $sesja;
        });
    }

    /** Zakończenie przez gospodarza: kasuje sesję, odhaczenia, pomocników i linki. Idempotentne. */
    public function zakoncz(User $gospodarz, CookingSession $sesja): void
    {
        Gate::forUser($gospodarz)->authorize('end', $sesja);

        DB::transaction(function () use ($gospodarz, $sesja): void {
            CookingSession::query()
                ->whereKey($sesja->getKey())
                ->where('host_id', $gospodarz->getKey())
                ->lockForUpdate()
                ->first()
                ?->delete();
        });
    }

    /** Pomocnik odchodzi. Odhaczenia zostają do końca sesji. Idempotentne. */
    public function wyjdz(User $pomocnik, CookingSession $sesja): void
    {
        Gate::forUser($pomocnik)->authorize('leave', $sesja);

        $this->usunUdzial($sesja, (string) $pomocnik->getKey());
    }

    /** Gospodarz odbiera dostęp pomocnikowi. Idempotentne. */
    public function usunPomocnika(User $gospodarz, CookingSession $sesja, string $idPomocnika): void
    {
        Gate::forUser($gospodarz)->authorize('manage', $sesja);

        $this->usunUdzial($sesja, $idPomocnika);
    }

    private function usunUdzial(CookingSession $sesja, string $idOsoby): void
    {
        DB::transaction(function () use ($sesja, $idOsoby): void {
            $swieza = CookingSession::query()->whereKey($sesja->getKey())->lockForUpdate()->first();

            if ($swieza === null) {
                return;
            }

            $usuniete = DB::table('cooking_session_participants')
                ->where('session_id', $swieza->getKey())
                ->where('user_id', $idOsoby)
                ->delete();

            if ($usuniete > 0) {
                $swieza->forceFill(['revision' => $swieza->revision + 1])->save();
            }
        });
    }

    public static function godziny(): int
    {
        return max(1, (int) config('kuking.wspolne_gotowanie.retencja_godziny', 24));
    }
}
