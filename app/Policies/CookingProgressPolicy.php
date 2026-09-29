<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CookingProgress;
use App\Models\User;

/**
 * Zapamiętany postęp gotowania jest PRYWATNY (#2016): widzi go i zmienia
 * wyłącznie właściciel, bez wyjątku dla moderatora. Dostęp do samego przepisu
 * to osobne pytanie (`RecipePolicy::view`) i kontroler zadaje je zawsze
 * dodatkowo — postęp nie otwiera przepisu, do którego konto straciło dostęp.
 */
class CookingProgressPolicy
{
    /** Włączenie synchronizacji: tylko aktywne konto (zawieszone może czytać, nie zapisuje). */
    public function create(User $user): bool
    {
        return $user->isActive();
    }

    public function view(User $user, CookingProgress $postep): bool
    {
        return $user->getKey() === $postep->user_id;
    }

    public function update(User $user, CookingProgress $postep): bool
    {
        return $user->isActive() && $this->view($user, $postep);
    }

    public function delete(User $user, CookingProgress $postep): bool
    {
        return $this->view($user, $postep);
    }
}
