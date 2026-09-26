<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

use App\Models\Recipe;
use App\Models\User;

/** Domyślna straż: przepis bez szczególnego pochodzenia, nic do pilnowania. */
final class BezStrazyPochodzenia implements StrazPochodzeniaPrzepisu
{
    public function przedZapisem(User $author, Recipe $existing, array $attributes, bool $publish): array
    {
        return $attributes;
    }

    public function poPublikacji(Recipe $recipe): void {}
}
