<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Hide;
use App\Models\User;

/**
 * Ukrycie należy do jednej osoby i tylko ona może je zmienić (issue #1810).
 * UUID wiersza w adresie to nie autoryzacja (AGENTS.md §7).
 */
class HidePolicy
{
    public function update(User $user, Hide $hide): bool
    {
        return $hide->user_id === $user->getKey();
    }

    public function delete(User $user, Hide $hide): bool
    {
        return $hide->user_id === $user->getKey();
    }
}
