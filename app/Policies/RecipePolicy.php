<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Recipe;
use App\Models\User;

class RecipePolicy
{
    public function view(?User $user, Recipe $recipe): bool
    {
        if (! $recipe->isPublished()) {
            return $user !== null && ($user->getKey() === $recipe->author_id || $user->isModerator());
        }

        if ($user !== null && $user->hasBlockRelationWith($recipe->author)) {
            return false;
        }

        return match ($recipe->visibility) {
            'public' => true,
            'followers' => $user !== null
                && ($user->getKey() === $recipe->author_id || $user->isFollowing($recipe->author)),
            'private' => $user !== null && $user->getKey() === $recipe->author_id,
            default => false,
        };
    }

    public function update(User $user, Recipe $recipe): bool
    {
        return $user->getKey() === $recipe->author_id;
    }

    public function delete(User $user, Recipe $recipe): bool
    {
        return $user->getKey() === $recipe->author_id || $user->isModerator();
    }

    /**
     * "Ugotowałem" można dodać do CUDZEGO przepisu.
     *
     * Do własnego też — bo ludzie realnie gotują swoje przepisy i chcą mieć
     * ślad, że robili to w tym roku. Nie odbieramy tego, ale w statystykach
     * jakości liczymy tylko wykonania cudzych przepisów.
     */
    public function cook(User $user, Recipe $recipe): bool
    {
        return $this->view($user, $recipe) && $user->isActive();
    }
}
