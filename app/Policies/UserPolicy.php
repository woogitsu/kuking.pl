<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewProfile(?User $viewer, User $target): bool
    {
        if (! in_array($target->status, [User::STATUS_ACTIVE, User::STATUS_SUSPENDED], true)) {
            return $viewer !== null && $viewer->isModerator();
        }

        return ! ($viewer !== null && $viewer->hasBlockRelationWith($target));
    }

    public function follow(User $viewer, User $target): bool
    {
        return $viewer->getKey() !== $target->getKey()
            && $viewer->isActive()
            && $target->isActive()
            && ! $viewer->hasBlockRelationWith($target);
    }

    public function moderate(User $viewer): bool
    {
        return $viewer->isModerator();
    }
}
