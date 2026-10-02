<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CookingNote;
use App\Models\User;

/**
 * Roboczy dopisek z gotowania jest PRYWATNY (#2587): widzi go i zmienia
 * wyłącznie właściciel, bez wyjątku dla moderatora. Dostęp do samego przepisu
 * to osobne pytanie (`RecipePolicy::view`), które kontroler zadaje zawsze
 * dodatkowo.
 */
class CookingNotePolicy
{
    /** Zapis nowego dopisku: tylko aktywne konto (zawieszone nie zapisuje). */
    public function create(User $user): bool
    {
        return $user->isActive();
    }

    public function view(User $user, CookingNote $dopisek): bool
    {
        return $user->getKey() === $dopisek->user_id;
    }

    public function update(User $user, CookingNote $dopisek): bool
    {
        return $user->isActive() && $this->view($user, $dopisek);
    }

    public function delete(User $user, CookingNote $dopisek): bool
    {
        return $this->view($user, $dopisek);
    }
}
