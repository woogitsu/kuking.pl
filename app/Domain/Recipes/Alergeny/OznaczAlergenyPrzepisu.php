<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Alergeny;

use App\Domain\Recipes\RecipeStatusTransitions;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Oznaczenie alergenów przepisu przez jego autora (#1902, D-333).
 *
 * TO JEDYNE MIEJSCE, GDZIE POWSTAJE STAN `declared`. Kolumny
 * `allergen_status`, `allergens` i `allergens_declared_at` są POZA
 * `$fillable` (AGENTS.md §7, D-006) — to pola sterujące tej samej rodziny
 * co `status` i `kind`: rozstrzygają, co czytelnik zobaczy jako „według
 * autora” i co przepuści filtr w wyszukiwarce. Zmienia je `forceFill()`
 * w tej akcji, z wartościami ustalonymi tutaj, nie z żądania.
 *
 * Trzy wejścia:
 *  - `handle()` — samodzielne oznaczenie (Policy `update`, blokada wiersza);
 *  - `zastosuj()` — dla `PublishRecipe`, które autoryzowało i zablokowało
 *    przepis samo, w tej samej transakcji co zapis składników;
 *  - `potwierdzPonownie()` — przycisk „Składniki nadal się zgadzają” po
 *    stanie `needs_review`.
 *
 * Reguły stanu:
 *  - potwierdzone (lista może być pusta) → `declared` z datą;
 *  - niepotwierdzone i pusta lista → z powrotem `unchecked` (autor cofa);
 *  - niepotwierdzone z zaznaczeniami → odmowa po polsku, niczego nie zapisujemy.
 */
final class OznaczAlergenyPrzepisu
{
    public const KOMUNIKAT_POTWIERDZ = 'Zaznaczone alergeny trzeba potwierdzić. Zaznacz pole „Składniki sprawdzone”, '
        .'albo odznacz wszystkie alergeny, jeśli nie chcesz ich oznaczać.';

    private const KOMUNIKAT_NIE_TERAZ = 'Tego oznaczenia nie da się teraz zmienić, bo przepis jest ukryty przez moderację.';

    private const KOMUNIKAT_NIE_MA_CO_POTWIERDZAC = 'Ten przepis nie czeka na ponowne potwierdzenie alergenów. Odśwież stronę.';

    /**
     * @throws BladDlaCzlowieka
     */
    public function handle(User $autor, Recipe $przepis, DeklaracjaAlergenow $deklaracja, ?string $ip = null): Recipe
    {
        Gate::forUser($autor)->authorize('update', $przepis);

        return DB::transaction(function () use ($autor, $przepis, $deklaracja, $ip): Recipe {
            $this->zablokujAutora($autor);
            $swiezy = $this->zablokowany($przepis);

            if ($this->zastosuj($swiezy, $deklaracja)) {
                $this->zapiszSlad($autor, $swiezy, $ip);
            }

            return $swiezy->refresh();
        });
    }

    /**
     * „Składniki nadal się zgadzają” — potwierdza ponownie dokładnie tę
     * listę, która stoi zapisana. Tylko ze stanu `needs_review`: z innych
     * stanów przycisk nie ma sensu i nie wolno go użyć, żeby obejść
     * potwierdzenie (z `unchecked` nie ma czego potwierdzać).
     *
     * @throws BladDlaCzlowieka
     */
    public function potwierdzPonownie(User $autor, Recipe $przepis, ?string $ip = null): Recipe
    {
        Gate::forUser($autor)->authorize('update', $przepis);

        return DB::transaction(function () use ($autor, $przepis, $ip): Recipe {
            $this->zablokujAutora($autor);
            $swiezy = $this->zablokowany($przepis);

            if ($swiezy->allergen_status !== Recipe::ALERGENY_DO_PRZEGLADU) {
                throw new BladDlaCzlowieka(self::KOMUNIKAT_NIE_MA_CO_POTWIERDZAC);
            }

            if ($this->zastosuj($swiezy, new DeklaracjaAlergenow($swiezy->allergens, true))) {
                $this->zapiszSlad($autor, $swiezy, $ip);
            }

            return $swiezy->refresh();
        });
    }

    /**
     * Zapis stanu na przepisie, który wywołujący JUŻ autoryzował i zablokował.
     *
     * @return bool czy stan się zmienił (zapis bez różnicy niczego nie rusza,
     *              także daty potwierdzenia)
     *
     * @throws BladDlaCzlowieka
     */
    public function zastosuj(Recipe $przepis, DeklaracjaAlergenow $deklaracja): bool
    {
        if ($deklaracja->wymagaPotwierdzenia()) {
            throw new BladDlaCzlowieka(self::KOMUNIKAT_POTWIERDZ);
        }

        if (! $deklaracja->rozniSieOd($przepis)) {
            return false;
        }

        if ($deklaracja->potwierdzone) {
            $przepis->forceFill([
                'allergen_status' => Recipe::ALERGENY_ZDEKLAROWANE,
                'allergens' => $deklaracja->kody,
                'allergens_declared_at' => now(),
            ])->save();

            return true;
        }

        // Niepotwierdzone i bez zaznaczeń: autor wycofuje oznaczenie.
        $przepis->forceFill([
            'allergen_status' => Recipe::ALERGENY_NIESPRAWDZONE,
            'allergens' => [],
            'allergens_declared_at' => null,
        ])->save();

        return true;
    }

    /**
     * Składniki zmieniły się po potwierdzeniu: lista przestaje być pokazywana,
     * dopóki autor jej nie potwierdzi ponownie. Nic nie zmienia z `unchecked`
     * i `needs_review`.
     *
     * @return bool czy przestawiono na `needs_review`
     */
    public function uniewaznPoZmianieSkladnikow(Recipe $przepis): bool
    {
        if (! $przepis->alergenyZdeklarowane()) {
            return false;
        }

        $przepis->forceFill(['allergen_status' => Recipe::ALERGENY_DO_PRZEGLADU])->save();

        return true;
    }

    /**
     * WIERSZ AUTORA ZANIM WIERSZ PRZEPISU — ta sama kolejność co w
     * `PublishRecipe` (`users` → `recipes`).
     *
     * `zapiszSlad()` wstawia wpis do `audit_log`, a jego klucz obcy `actor_id`
     * zakłada `FOR KEY SHARE` na wierszu `users`. Gdyby przepis był już
     * zablokowany, a konto — nie, powstałaby odwrotna kolejność (`recipes` →
     * `users`) niż w `EraseAccountData`, które trzyma `users FOR UPDATE`
     * i dopiero potem kasuje przepisy: zakleszczenie 40P01 (D-079 §1, D-093;
     * to samo zmierzone dla zapisu przepisu, patrz
     * `tests/Dwa/EdycjaPrzepisuNieZakleszczaSieZKasowaniemKontaTest.php`).
     * `FOR NO KEY UPDATE` jak w `PublishRecipe`: ustawia się w jednej kolejce
     * z karą i egzekucją, a nie blokuje cudzych sprawdzeń klucza obcego.
     */
    private function zablokujAutora(User $autor): User
    {
        return User::query()->whereKey($autor->getKey())->lock('FOR NO KEY UPDATE')->firstOrFail();
    }

    private function zablokowany(Recipe $przepis): Recipe
    {
        $swiezy = Recipe::query()->whereKey($przepis->getKey())->lockForUpdate()->first();

        if ($swiezy === null) {
            throw new BladDlaCzlowieka('Tego przepisu już nie ma. Odśwież stronę.');
        }

        if (! RecipeStatusTransitions::authorMayEdit($swiezy->status)) {
            throw new BladDlaCzlowieka(self::KOMUNIKAT_NIE_TERAZ);
        }

        return $swiezy;
    }

    private function zapiszSlad(User $autor, Recipe $przepis, ?string $ip): void
    {
        AuditLogEntry::record(
            action: 'recipe.allergens_marked',
            actor: $autor,
            subject: $przepis,
            metadata: [
                'status' => $przepis->allergen_status,
                'liczba_alergenow' => count($przepis->allergens),
            ],
            ip: $ip,
        );
    }
}
