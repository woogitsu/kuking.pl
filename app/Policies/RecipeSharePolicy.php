<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RecipeShare;
use App\Models\User;

/**
 * Udostępnienie przepisu (#2650) widziane od strony ODBIORCY.
 *
 * UUID udostępnienia w adresie nie jest autoryzacją: zrezygnować z dostępu
 * może wyłącznie osoba, której przepis udostępniono. Autor odbiera dostęp
 * inną trasą, przez `RecipePolicy::manageShares()`.
 */
class RecipeSharePolicy
{
    public function leave(User $user, RecipeShare $share): bool
    {
        return $share->recipient_id === $user->getKey();
    }
}
