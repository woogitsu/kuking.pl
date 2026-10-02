<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie;

use App\Models\CookingNote;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prywatny roboczy dopisek z trybu gotowania (#2587).
 *
 * TA KLASA NIE PYTA O UPRAWNIENIA — `RecipePolicy::view` i `CookingNotePolicy`
 * rozstrzyga kontroler przed każdym wywołaniem. Tu jest wyłącznie stan:
 *
 * - jeden dopisek na osobę i przepis, więc nie przechodzi na inny przepis
 *   ani na inne konto (właściciel i przepis zawsze z argumentów, nigdy z żądania);
 * - wiersz wygasły jest dla serwisu nieistniejący (`aktywny()` zwraca null),
 *   a kasuje go dopiero nocne sprzątanie — odczyt nie zależy od harmonogramu;
 * - każdy zapis przedłuża ważność o `retention_hours` od TEJ zmiany;
 * - zapis niesie rewizję, którą widziała strona. Gdy w bazie jest już nowsza
 *   (dopisek zmieniono na innym urządzeniu), zapis jest ODRZUCANY, a nie
 *   po cichu nadpisywany;
 * - nic tu nie tworzy `CookedEvent`, powiadomienia ani wpisu. Dopisek trafia do
 *   formularza „Ugotowałem” tylko przez widok, na wyraźną prośbę osoby.
 */
class RoboczyDopisek
{
    public const MAKS_ZNAKOW = 500;

    public function aktywny(User $osoba, Recipe $recipe): ?CookingNote
    {
        return CookingNote::query()
            ->where('user_id', $osoba->getKey())
            ->where('recipe_id', $recipe->getKey())
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Zapisuje (albo zakłada) dopisek. `$widzianaRewizja` to rewizja z formularza:
     * 0 = „strona nie widziała żadnego dopisku”.
     *
     * @throws KonfliktDopisku gdy dopisek zmieniono po tym, jak strona go widziała
     */
    public function zapisz(User $osoba, Recipe $recipe, string $tresc, int $widzianaRewizja): CookingNote
    {
        try {
            return DB::transaction(fn (): CookingNote => $this->zapiszPodBlokada($osoba, $recipe, $tresc, $widzianaRewizja));
        } catch (UniqueConstraintViolationException) {
            // Dwa pierwsze zapisy naraz: wygrał drugi, ten widział „brak”.
            throw new KonfliktDopisku;
        }
    }

    private function zapiszPodBlokada(User $osoba, Recipe $recipe, string $tresc, int $widzianaRewizja): CookingNote
    {
        $wiersz = CookingNote::query()
            ->where('user_id', $osoba->getKey())
            ->where('recipe_id', $recipe->getKey())
            ->lockForUpdate()
            ->first();

        // Wygasły dopisek to brak dopisku: nowy tekst osoby nie ma z czym
        // się kłócić, więc nie ma konfliktu, a rewizja zaczyna od nowa.
        if ($wiersz !== null && $wiersz->expires_at->lessThanOrEqualTo(now())) {
            $wiersz->delete();
            $wiersz = null;
        }

        $teraz = now();

        if ($wiersz === null) {
            if ($widzianaRewizja > 0) {
                // Strona widziała dopisek, którego już nie ma (usunięty na
                // innym urządzeniu): tekst z formularza jest nowszy od niczego.
                $widzianaRewizja = 0;
            }

            $nowy = new CookingNote;
            $nowy->forceFill([
                'id' => (string) Str::uuid7(),
                'user_id' => $osoba->getKey(),
                'recipe_id' => $recipe->getKey(),
                'body' => $tresc,
                'revision' => 1,
                'expires_at' => $teraz->copy()->addHours($this->godziny()),
            ])->save();

            return $nowy;
        }

        if ($wiersz->revision !== $widzianaRewizja) {
            throw new KonfliktDopisku;
        }

        $wiersz->forceFill([
            'body' => $tresc,
            'revision' => $wiersz->revision + 1,
            'expires_at' => $teraz->copy()->addHours($this->godziny()),
        ])->save();

        return $wiersz;
    }

    public function usun(User $osoba, Recipe $recipe): void
    {
        CookingNote::query()
            ->where('user_id', $osoba->getKey())
            ->where('recipe_id', $recipe->getKey())
            ->delete();
    }

    /** Nocne sprzątanie. `$wszystkie` kasuje też niewygasłe (tylko do wycofania migracji). */
    public function posprzataj(bool $naSucho = false, bool $wszystkie = false): int
    {
        $zapytanie = CookingNote::query();

        if (! $wszystkie) {
            $zapytanie->where('expires_at', '<=', now());
        }

        return $naSucho ? $zapytanie->count() : $zapytanie->delete();
    }

    private function godziny(): int
    {
        return max(1, (int) config('kuking.cooking_note.retention_hours', 24));
    }
}
