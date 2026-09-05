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

        // Przepis usunięty (soft delete) — relacja zwraca null (audyt A23).
        //
        // Widoczność wykonania idzie za widocznością przepisu, więc bez
        // przepisu nie ma na czym oprzeć pokazania go obcym. Zostaje sam
        // właściciel wykonania (żeby mógł je skasować) i moderator.
        // withTrashed() rozwiązałoby TypeError i jednocześnie przywróciło
        // widoczność treści, którą autor świadomie usunął — czyli naprawiło
        // wyjątek kosztem prywatności.
        if ($event->recipe === null) {
            return $user !== null && ($user->getKey() === $event->user_id || $user->isModerator());
        }

        // Widoczność wykonania idzie za widocznością przepisu.
        return app(RecipePolicy::class)->view($user, $event->recipe);
    }

    public function delete(User $user, CookedEvent $event): bool
    {
        return $user->getKey() === $event->user_id || $user->isModerator();
    }
}
