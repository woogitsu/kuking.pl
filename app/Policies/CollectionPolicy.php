<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Collection;
use App\Models\User;

class CollectionPolicy
{
    public function view(?User $user, Collection $collection): bool
    {
        if ($collection->isPublic()) {
            return true;
        }

        return $user !== null && $user->getKey() === $collection->owner_id;
    }

    public function update(User $user, Collection $collection): bool
    {
        return $user->getKey() === $collection->owner_id;
    }

    public function delete(User $user, Collection $collection): bool
    {
        // Domyślnego zeszytu "Zapisane" nie da się usunąć — inaczej przycisk
        // "Zapisuję" przestałby mieć gdzie zapisywać.
        return $user->getKey() === $collection->owner_id && ! $collection->is_default;
    }
}
