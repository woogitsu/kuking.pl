<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WeeklyRecipePick;

/**
 * Kto może zobaczyć tydzień „Ugotujmy razem” (F3).
 *
 * Adres tygodnia (`/ugotujmy-razem/2026-W40`) to NIE jest autoryzacja
 * (AGENTS.md §7) — ten sam tydzień jednej osobie się pokazuje, innej nie.
 */
class WeeklyRecipePickPolicy
{
    /**
     * Trzy warunki, wszystkie konieczne:
     *
     * 1. Tydzień już się zaczął. Plan gospodarza na przyszłe tygodnie nie
     *    wychodzi przed czasem — ani na stronę, ani przez zgadnięty adres.
     * 2. Przepis jest dziś PUBLICZNY i OPUBLIKOWANY. Wspólne gotowanie jest
     *    zaproszeniem dla wszystkich, więc przepis, który autor potem
     *    przestawił na „dla obserwujących” albo prywatny, albo który
     *    moderacja ukryła, znika ze strony dla każdego — także dla autora
     *    i moderatora (oni mają zwykły adres przepisu).
     * 3. `RecipePolicy::view` dla tego widza: blokada z autorem w którąkolwiek
     *    stronę i konto autora zbanowane albo w trakcie usuwania.
     */
    public function view(?User $user, WeeklyRecipePick $pick): bool
    {
        if ($pick->tydzien()->jestPrzyszly()) {
            return false;
        }

        $recipe = $pick->recipe;

        if ($recipe === null || ! $recipe->isPublished() || $recipe->visibility !== 'public') {
            return false;
        }

        return app(RecipePolicy::class)->view($user, $recipe);
    }
}
