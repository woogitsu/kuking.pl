<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie;

use App\Domain\Recipes\Porcje\WyborPorcji;
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
 *
 * ETAP 2 (#2016): ten sam wiersz niesie też wybraną liczbę porcji (`servings`,
 * NULL = z przepisu) i składniki „przygotowane” (`prepared_ingredient_ids`).
 * Składniki zapisuje się RÓŻNICĄ („było na ekranie” → „jest”), nie całą
 * listą, więc dwa urządzenia zaznaczające różne składniki nic sobie nie
 * gubią; porcje to jedna wartość, wygrywa ostatni zapis. Minutników tu nie
 * ma — patrz `docs/DATABASE.md`.
 */
class PostepGotowania
{
    /** Ten sam sufit co CHECK w bazie (`cooking_progress_done_check`). */
    private const MAKS_KROKOW = 200;

    /** Ten sam sufit co CHECK w bazie (`cooking_progress_prepared_check`). */
    private const MAKS_SKLADNIKOW = 300;

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
     * @param  float|null  $porcje  porcje wybrane w adresie (już sprawdzone przez `WyborPorcji`); null = z przepisu
     */
    public function wlacz(User $user, Recipe $recipe, array $idKrokowPrzepisu, array $zSesji, ?float $porcje = null): CookingProgress
    {
        $seed = $this->przytnij($zSesji, $idKrokowPrzepisu);
        $porcje = $this->porcjeDoZapisu($porcje);

        return DB::transaction(function () use ($user, $recipe, $seed, $porcje): CookingProgress {
            $teraz = now();

            // ON CONFLICT DO NOTHING: dwa równoległe „włącz” nie kończą się
            // wyjątkiem (który w PostgreSQL psuje całą transakcję).
            DB::table('cooking_progress')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'user_id' => $user->getKey(),
                'recipe_id' => $recipe->getKey(),
                'done_step_ids' => json_encode($seed, JSON_THROW_ON_ERROR),
                'servings' => $porcje,
                'prepared_ingredient_ids' => '[]',
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
                $this->zapisz($wiersz, [
                    'done_step_ids' => $seed,
                    'servings' => $porcje,
                    'prepared_ingredient_ids' => [],
                ]);
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

            $this->zapisz($wiersz, ['done_step_ids' => $this->przytnij(array_values($ids), $idKrokowPrzepisu)]);

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

            $this->zapisz($wiersz, ['done_step_ids' => []]);

            return $wiersz;
        });
    }

    /**
     * Zaznacza i odznacza składniki „przygotowane”. `$dodaj` to składniki
     * zaznaczone od chwili, gdy strona się wyświetliła, `$usun` — odznaczone;
     * reszta listy z konta zostaje, więc zmiana z drugiego urządzenia nie
     * ginie. Powtórzenie tego samego żądania nic nie zmienia i nie podnosi
     * rewizji. Null, gdy synchronizacja wygasła lub jest wyłączona.
     *
     * @param  list<string>  $dodaj
     * @param  list<string>  $usun
     * @param  list<string>  $idSkladnikowPrzepisu
     */
    public function ustawSkladniki(CookingProgress $postep, array $dodaj, array $usun, array $idSkladnikowPrzepisu): ?CookingProgress
    {
        return DB::transaction(function () use ($postep, $dodaj, $usun, $idSkladnikowPrzepisu): ?CookingProgress {
            $wiersz = $this->zablokuj($postep);

            if ($wiersz === null) {
                return null;
            }

            $obecne = $this->przygotowane($wiersz, $idSkladnikowPrzepisu);
            $usun = array_values(array_diff($usun, $dodaj));
            $nowe = $this->przytnij(
                [...array_values(array_diff($obecne, $usun)), ...$dodaj],
                $idSkladnikowPrzepisu,
                self::MAKS_SKLADNIKOW,
            );

            $zapisane = $this->przytnij($wiersz->prepared_ingredient_ids, $idSkladnikowPrzepisu, self::MAKS_SKLADNIKOW);

            if ($nowe === $zapisane) {
                return $wiersz;
            }

            $this->zapisz($wiersz, ['prepared_ingredient_ids' => $nowe]);

            return $wiersz;
        });
    }

    /**
     * Ustawia wybraną liczbę porcji; null wraca do „z przepisu”. Wartość
     * spoza zakresu 1–100 jest traktowana jak null (kontroler i tak podaje
     * tylko to, co przeszło przez `WyborPorcji`).
     */
    public function ustawPorcje(CookingProgress $postep, ?float $porcje): ?CookingProgress
    {
        $porcje = $this->porcjeDoZapisu($porcje);

        return DB::transaction(function () use ($postep, $porcje): ?CookingProgress {
            $wiersz = $this->zablokuj($postep);

            if ($wiersz === null) {
                return null;
            }

            if ($this->porcje($wiersz) === $porcje) {
                return $wiersz;
            }

            $this->zapisz($wiersz, ['servings' => $porcje]);

            return $wiersz;
        });
    }

    /** Wybrana liczba porcji zapisana na koncie albo null („z przepisu”). */
    public function porcje(CookingProgress $postep): ?float
    {
        return $postep->servings === null ? null : round((float) $postep->servings, 2);
    }

    /**
     * Składniki „przygotowane”, które przepis nadal ma.
     *
     * @param  list<string>  $idSkladnikowPrzepisu
     * @return list<string>
     */
    public function przygotowane(CookingProgress $postep, array $idSkladnikowPrzepisu): array
    {
        return $this->przytnij($postep->prepared_ingredient_ids, $idSkladnikowPrzepisu, self::MAKS_SKLADNIKOW);
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

    /** @param  array<string, mixed>  $zmiany  kolumny do ustawienia (wszystkie poza rewizją i ważnością) */
    private function zapisz(CookingProgress $wiersz, array $zmiany): void
    {
        $wiersz->forceFill($zmiany + [
            'revision' => $wiersz->revision + 1,
            'expires_at' => now()->addHours($this->godziny()),
        ])->save();
    }

    private function porcjeDoZapisu(?float $porcje): ?float
    {
        if ($porcje === null) {
            return null;
        }

        $porcje = round($porcje, 2);

        return $porcje >= WyborPorcji::NAJMNIEJ && $porcje <= WyborPorcji::NAJWIECEJ ? $porcje : null;
    }

    /**
     * Tylko ID, które przepis ma, bez powtórzeń i nie więcej niż sufit (domyślnie kroki).
     *
     * @param  array<int, mixed>  $ids
     * @param  list<string>  $idKrokowPrzepisu
     * @return list<string>
     */
    private function przytnij(array $ids, array $idKrokowPrzepisu, int $sufit = self::MAKS_KROKOW): array
    {
        $wynik = array_filter(
            array_unique($ids, SORT_REGULAR),
            fn (mixed $id): bool => is_string($id) && in_array($id, $idKrokowPrzepisu, true),
        );

        return array_slice(array_values($wynik), 0, $sufit);
    }

    private function godziny(): int
    {
        return max(1, (int) config('kuking.cooking_progress.retention_hours', 24));
    }
}
