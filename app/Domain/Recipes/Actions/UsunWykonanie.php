<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;

/**
 * Usunięcie własnego wykonania — wyjęte z `CookedEventController::destroy()`
 * bez zmiany zachowania (issue #970). Autoryzacja (`delete`) zostaje
 * w kontrolerze, przed wywołaniem.
 */
final class UsunWykonanie
{
    /**
     * Kasuje wykonanie i zwraca przepis, na którego stronę wolno wrócić —
     * albo `null`, gdy nie ma dokąd (przepis usunięty albo niewidoczny dla
     * aktora).
     */
    public function handle(CookedEvent $wykonanie, User $aktor): ?Recipe
    {
        // Przepis mógł zostać usunięty (soft delete) po zapisaniu wykonania —
        // wtedy relacja zwraca null (audyt A23). Wcześniej ta linia rzucała
        // wyjątek PRZED skasowaniem, więc człowiek nie mógł usunąć własnego
        // wykonania i zostawał z trwale zepsutą zakładką „Ugotowane".
        $recipe = $wykonanie->recipe;
        $wykonanie->delete();

        // Przepis może nie istnieć (soft delete) ALBO aktor może nie mieć
        // już do niego uprawnień do odczytu (np. autor zmienił widoczność
        // na prywatną, moderacja ukryła przepis, relacja blokady — issue #766).
        // Bez sprawdzenia uprawnień powrót do recipes.show kończył się 403 Forbidden.
        if ($recipe === null || $aktor->cannot('view', $recipe)) {
            return null;
        }

        return $recipe;
    }
}
