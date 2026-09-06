<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RecipeStep;
use App\Models\User;

/**
 * Krok przepisu nie ma własnej widoczności — ma widoczność przepisu.
 *
 * Ta klasa istnieje po to, żeby `App\Domain\Media\DostepDoZdjecia` mogło
 * zapytać o zdjęcie przypięte do kroku (`recipe_steps.media_id`) tak samo,
 * jak pyta o każdego innego rodzica: przez `Gate`. Alternatywą było wpisanie
 * w tamtej klasie warunku „krok widać wtedy, kiedy przepis" — czyli kolejnej,
 * siódmej kopii reguły widoczności w tym repozytorium, w miejscu, w którym
 * nikt by jej nie szukał przy zmianie `RecipePolicy` (audyt W7-02).
 *
 * Delegacja, nie powtórzenie: ten sam wzorzec co `CookedEventPolicy::view`.
 */
class RecipeStepPolicy
{
    public function view(?User $user, RecipeStep $step): bool
    {
        // Przepis skasowany miękko — relacja zwraca `null` (ten sam przypadek
        // co w `CookedEventPolicy`, audyt A23). Bez przepisu nie ma na czym
        // oprzeć pokazania kroku komukolwiek.
        if ($step->recipe === null) {
            return false;
        }

        return app(RecipePolicy::class)->view($user, $step->recipe);
    }
}
