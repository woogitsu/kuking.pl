<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie;

use App\Models\CookingProgress;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Zapamiętany na koncie postęp trybu gotowania (#2016).
 *
 * TA KLASA NIE PYTA O UPRAWNIENIA. Kto może w ogóle patrzeć na przepis
 * (`RecipePolicy::view`) i kto może włączyć synchronizację
 * (`CookingProgressPolicy::create`), rozstrzyga kontroler PRZED każdym
 * wywołaniem — tak jak w całym trybie gotowania. Tu jest wyłącznie
 * arytmetyka stanu: co jest zrobione, która rewizja, kiedy wygasa.
 *
 * ZASADY, KTÓRE TU STOJĄ
 * - Wiersz wygasły (`expires_at` w przeszłości) jest niewidoczny: `aktywny()`
 *   zwraca null, jakby go nie było. Kasuje go dopiero nocne sprzątanie, więc
 *   odczyt nigdy nie zależy od tego, czy harmonogram żyje.
 * - Zapis jest idempotentnym USTAWIENIEM jednego kroku („zrobiony”/„nie”),
 *   nie przełączeniem: dwa urządzenia klikające różne kroki nic sobie nie
 *   gubią, a na ten sam krok wygrywa ostatni zapis. Zapis odbywa się pod
 *   blokadą wiersza, więc równoległe żądania nie nadpisują sobie listy.
 * - `revision` rośnie o 1 przy KAŻDEJ zmianie i jest tym, co widzi drugie
 *   urządzenie, żeby zauważyć, że stan zmienił się bez niego.
 * - ID kroków, których przepis już nie ma (autor je usunął), nie wracają
 *   w odczycie (`zrobione()`) i wypadają przy następnym zapisie.
 * - Każda zmiana przedłuża ważność o `retention_hours` od TEJ zmiany.
 */
class PostepGotowania
{
    /** Ten sam sufit co CHECK w bazie (`cooking_progress_done_check`). */
    private const MAKS_KROKOW = 200;

    /** Aktywny (niewygasły) postęp tej osoby dla tego przepisu albo null. */
    public function aktywny(User $user, Recipe $recipe): ?CookingProgress
    {
        return CookingProgress::query()
            ->where('user_id', $user->getKey())
            ->where('recipe_id', $recipe->getKey())
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Włącza synchronizację; przy pierwszym włączeniu bierze odhaczenia
     * z sesji tego urządzenia, żeby to, co już zrobiono, nie znikło.
     * Powtórne włączenie (drugie urządzenie, podwójne kliknięcie) niczego
     * nie kasuje — zostaje stan, który już jest na koncie.
     *
     * @param  list<string>  $idKrokowPrzepisu  ID kroków przepisu (kolejność bez znaczenia)
     * @param  list<string>  $zSesji  ID odhaczone dotąd w sesji tego urządzenia
     */
    public function wlacz(User $user, Recipe $recipe, array $idKrokowPrzepisu, array $zSesji): CookingProgress
    {
        $seed = $this->przytnij($zSesji, $idKrokowPrzepisu);

        return DB::transaction(function () use ($user, $recipe, $seed): CookingProgress {
            $teraz = now();

            // ON CONFLICT DO NOTHING: dwa równoległe „włącz” nie kończą się
            // wyjątkiem (który w PostgreSQL psuje całą transakcję).
            DB::table('cooking_progress')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'user_id' => $user->getKey(),
                'recipe_id' => $recipe->getKey(),
                'done_step_ids' => json_encode($seed, JSON_THROW_ON_ERROR),
                'revision' => 1,
                'expires_at' => $teraz->copy()->addHours($this->godziny()),
                'created_at' => $teraz,
                'updated_at' => $teraz,
            ]);

            $wiersz = CookingProgress::query()
                ->where('user_id', $user->getKey())
                ->where('recipe_id', $recipe->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($wiersz->expires_at->lessThanOrEqualTo($teraz)) {
                // Stary, wygasły wiersz czekał na sprzątanie — zaczynamy
                // od czystej karty z bieżącej sesji, nie od dawnych odhaczeń.
                $this->zapisz($wiersz, $seed);
            }

            return $wiersz;
        });
    }

    /**
     * Ustawia jeden krok. Zwraca zaktualizowany wiersz albo null, gdy
     * synchronizacja w międzyczasie wygasła lub została wyłączona.
     *
     * @param  list<string>  $idKrokowPrzepisu
     */
    public function ustaw(CookingProgress $postep, string $krokId, bool $zrobiono, array $idKrokowPrzepisu): ?CookingProgress
    {
        return DB::transaction(function () use ($postep, $krokId, $zrobiono, $idKrokowPrzepisu): ?CookingProgress {
            $wiersz = $this->zablokuj($postep);

            if ($wiersz === null) {
                return null;
            }

            $ids = $this->zrobione($wiersz, $idKrokowPrzepisu);

            if ($zrobiono && in_array($krokId, $idKrokowPrzepisu, true)) {
                $ids[] = $krokId;
            } elseif (! $zrobiono) {
                $ids = array_filter($ids, fn (string $id): bool => $id !== $krokId);
            }

            $this->zapisz($wiersz, $this->przytnij(array_values($ids), $idKrokowPrzepisu));

            return $wiersz;
        });
    }

    /** „Zacznij od początku”: puste odhaczenia, synchronizacja zostaje włączona. */
    public function wyczysc(CookingProgress $postep): ?CookingProgress
    {
        return DB::transaction(function () use ($postep): ?CookingProgress {
            $wiersz = $this->zablokuj($postep);

            if ($wiersz === null) {
                return null;
            }

            $this->zapisz($wiersz, []);

            return $wiersz;
        });
    }

    /**
     * Wyłącza synchronizację i KASUJE wiersz z konta. Zwraca odhaczone
     * dotąd ID (żeby kontroler mógł je zostawić w sesji tego urządzenia),
     * albo pustą listę, gdy nic nie było włączone.
     *
     * @param  list<string>  $idKrokowPrzepisu
     * @return list<string>
     */
    public function wylacz(User $user, Recipe $recipe, array $idKrokowPrzepisu): array
    {
        return DB::transaction(function () use ($user, $recipe, $idKrokowPrzepisu): array {
            $wiersz = CookingProgress::query()
                ->where('user_id', $user->getKey())
                ->where('recipe_id', $recipe->getKey())
                ->lockForUpdate()
                ->first();

            if ($wiersz === null) {
                return [];
            }

            $ids = $wiersz->expires_at->greaterThan(now())
                ? $this->zrobione($wiersz, $idKrokowPrzepisu)
                : [];
            $wiersz->delete();

            return $ids;
        });
    }

    /**
     * Odhaczone kroki, które ten przepis nadal ma (w kolejności zapisu).
     *
     * @param  list<string>  $idKrokowPrzepisu
     * @return list<string>
     */
    public function zrobione(CookingProgress $postep, array $idKrokowPrzepisu): array
    {
        return $this->przytnij($postep->done_step_ids, $idKrokowPrzepisu);
    }

    /**
     * Nocne sprzątanie. `$wszystkie` kasuje też niewygasłe (tylko do
     * wycofania migracji — patrz jej `down()`).
     */
    public function posprzataj(bool $naSucho = false, bool $wszystkie = false): int
    {
        $zapytanie = CookingProgress::query();

        if (! $wszystkie) {
            $zapytanie->where('expires_at', '<=', now());
        }

        return $naSucho ? $zapytanie->count() : $zapytanie->delete();
    }

    private function zablokuj(CookingProgress $postep): ?CookingProgress
    {
        return CookingProgress::query()
            ->whereKey($postep->getKey())
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->first();
    }

    /** @param  list<string>  $ids */
    private function zapisz(CookingProgress $wiersz, array $ids): void
    {
        $wiersz->forceFill([
            'done_step_ids' => $ids,
            'revision' => $wiersz->revision + 1,
            'expires_at' => now()->addHours($this->godziny()),
        ])->save();
    }

    /**
     * Tylko ID, które przepis ma, bez powtórzeń i nie więcej niż sufit.
     *
     * @param  array<int, mixed>  $ids
     * @param  list<string>  $idKrokowPrzepisu
     * @return list<string>
     */
    private function przytnij(array $ids, array $idKrokowPrzepisu): array
    {
        $wynik = array_filter(
            array_unique($ids, SORT_REGULAR),
            fn (mixed $id): bool => is_string($id) && in_array($id, $idKrokowPrzepisu, true),
        );

        return array_slice(array_values($wynik), 0, self::MAKS_KROKOW);
    }

    private function godziny(): int
    {
        return max(1, (int) config('kuking.cooking_progress.retention_hours', 24));
    }
}
