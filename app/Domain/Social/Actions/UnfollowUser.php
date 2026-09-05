<?php

declare(strict_types=1);

namespace App\Domain\Social\Actions;

use App\Models\User;

final class UnfollowUser
{
    public function handle(User $follower, User $target): void
    {
        $follower->following()->detach($target->getKey());
    }
}
