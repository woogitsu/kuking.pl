<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie\Wspolne;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookingSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Wspólny postęp kroków sesji wspólnego gotowania (#2385).
 *
 * ZASADY (projekt, sekcja 6)
 * - Odhaczenie jest USTAWIENIEM („ten krok: zrobiony/nie”), nie przełączeniem.
 *   Dwa równoczesne odhaczenia tego samego kroku dają jeden wiersz
 *   (`INSERT … ON CONFLICT DO NOTHING` na kluczu `(session_id, step_id)`),
 *   dwie osoby klikające różne kroki nic sobie nie gubią.
 * - Wszystko pod blokadą wiersza sesji i na jego świeżym stanie: sesja musi
 *   jeszcze trwać, a osoba być jej gospodarzem lub pomocnikiem TERAZ (nie
 *   w chwili wyświetlenia strony).
 * - `revision` rośnie o 1 tylko przy REALNEJ zmianie. Powtórzone żądanie jej
 *   nie podbija, więc druga osoba nie widzi „zmiany”, której nie było.
 * - Audyt: kto i kiedy odhaczył (`done_by_id`, `done_at`). Przepis jest tylko
 *   czytany — sesja go nie zmienia.
 *
 * Czy osoba widzi sam PRZEPIS (`RecipePolicy::view`), rozstrzyga kontroler
 * przy każdym żądaniu; tu sprawdzamy tylko członkostwo i przynależność kroku.
 */
final class PostepWspolnegoGotowania
{
    /**
     * Ustawia jeden krok. Zwraca `true`, gdy stan się zmienił.
     *
     * @throws BladDlaCzlowieka
     */
    public function ustaw(User $osoba, CookingSession $sesja, string $krokId, bool $zrobiono): bool
    {
        Gate::forUser($osoba)->authorize('update', $sesja);

        return DB::transaction(function () use ($osoba, $sesja, $krokId, $zrobiono): bool {
            $swieza = $this->zablokuj($osoba, $sesja);

            // Krok musi należeć do przepisu tej sesji (identyfikator innego
            // przepisu albo usuniętego kroku tu nie pasuje).
            $nalezy = DB::table('recipe_steps')
                ->where('id', $krokId)
                ->where('recipe_id', $swieza->recipe_id)
                ->exists();

            if (! $nalezy) {
                throw new BladDlaCzlowieka('Przepis zmienił się, odkąd otworzono ten krok, więc nic nie zostało oznaczone. Odśwież stronę, przeczytaj krok widoczny teraz na ekranie i oznacz go jeszcze raz, jeśli jest zrobiony.');
            }

            $zmieniono = $zrobiono
                ? DB::table('cooking_session_steps')->insertOrIgnore([
                    'session_id' => $swieza->getKey(),
                    'step_id' => $krokId,
                    'done_by_id' => $osoba->getKey(),
                    'done_at' => now(),
                ]) === 1
                : DB::table('cooking_session_steps')
                    ->where('session_id', $swieza->getKey())
                    ->where('step_id', $krokId)
                    ->delete() > 0;

            if ($zmieniono) {
                $swieza->forceFill(['revision' => $swieza->revision + 1])->save();
            }

            return $zmieniono;
        });
    }

    /** „Zacznij od początku” — czyści wspólne odhaczenia. Tylko gospodarz. */
    public function wyczysc(User $gospodarz, CookingSession $sesja): void
    {
        Gate::forUser($gospodarz)->authorize('manage', $sesja);

        DB::transaction(function () use ($gospodarz, $sesja): void {
            $swieza = $this->zablokuj($gospodarz, $sesja);

            if (! $swieza->maGospodarza($gospodarz)) {
                throw new BladDlaCzlowieka('Tylko gospodarz sesji może zacząć od początku.');
            }

            $usuniete = DB::table('cooking_session_steps')->where('session_id', $swieza->getKey())->delete();

            if ($usuniete > 0) {
                $swieza->forceFill(['revision' => $swieza->revision + 1])->save();
            }
        });
    }

    /**
     * Odhaczone kroki sesji: ID kroku → [kto (nazwa albo null), kiedy].
     * Kroków, których przepis już nie ma, nie ma (klucz obcy je kasuje).
     *
     * @return array<string, array{kto: ?string, kiedy: CarbonImmutable}>
     */
    public function zrobione(CookingSession $sesja): array
    {
        $wiersze = DB::table('cooking_session_steps')
            ->where('session_id', $sesja->getKey())
            ->get(['step_id', 'done_by_id', 'done_at']);

        $nazwy = User::query()
            ->with('profile')
            ->whereIn('id', $wiersze->pluck('done_by_id')->filter()->unique()->all())
            ->get()
            ->mapWithKeys(fn (User $u): array => [(string) $u->getKey() => $u->displayName()]);

        $wynik = [];
        foreach ($wiersze as $w) {
            $wynik[(string) $w->step_id] = [
                'kto' => $w->done_by_id !== null ? ($nazwy[(string) $w->done_by_id] ?? null) : null,
                'kiedy' => CarbonImmutable::parse($w->done_at),
            ];
        }

        return $wynik;
    }

    /** Blokada wiersza sesji + sprawdzenie, że sesja trwa i osoba jest jej uczestnikiem TERAZ. */
    private function zablokuj(User $osoba, CookingSession $sesja): CookingSession
    {
        $swieza = CookingSession::query()->whereKey($sesja->getKey())->lockForUpdate()->first();

        if ($swieza === null || ! $swieza->trwa()) {
            throw new BladDlaCzlowieka(SesjaWspolnegoGotowania::NIE_MA_SESJI);
        }

        if (! $swieza->maGospodarza($osoba) && ! $swieza->maPomocnika($osoba)) {
            throw new BladDlaCzlowieka('Nie jesteś już uczestnikiem tej sesji, więc nic nie zostało zapisane.');
        }

        if (! $osoba->isActive()) {
            throw new BladDlaCzlowieka('Konto jest zawieszone, więc możesz tylko czytać.');
        }

        return $swieza;
    }
}
