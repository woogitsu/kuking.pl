<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CookedEvent;
use App\Models\User;

class CookedEventPolicy
{
    public function view(?User $user, CookedEvent $event): bool
    {
        if ($user !== null && $user->hasBlockRelationWith($event->user)) {
            return false;
        }

        // Widoczność wykonania idzie za widocznością przepisu.
        return app(RecipePolicy::class)->view($user, $event->recipe);
    }

    public function delete(User $user, CookedEvent $event): bool
    {
        return $user->getKey() === $event->user_id || $user->isModerator();
    }
}
