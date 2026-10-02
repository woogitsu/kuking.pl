<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MealPlanEntry;
use App\Models\User;

/**
 * Planer jest prywatny (#27, D-310): pozycję zmienia wyłącznie jej właściciel.
 * Bez wyjątku dla moderatora — w cudzym planie nie ma czego moderować.
 */
class MealPlanEntryPolicy
{
    /** Prywatne „Zrobione” / „Cofnij oznaczenie” (#2593). */
    public function markDone(User $user, MealPlanEntry $entry): bool
    {
        return $user->getKey() === $entry->user_id;
    }

    /** Poprawienie własnego tekstu pozycji (#2454): tylko właściciel. */
    public function update(User $user, MealPlanEntry $entry): bool
    {
        return $user->getKey() === $entry->user_id;
    }

    public function delete(User $user, MealPlanEntry $entry): bool
    {
        return $user->getKey() === $entry->user_id;
    }
}
