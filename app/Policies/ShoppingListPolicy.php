<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ShoppingList;
use App\Models\User;

/**
 * Nazwana lista zakupów jest PRYWATNA (#2528, D-333): ogląda ją, zmienia jej
 * nazwę, dopisuje do niej i usuwa wyłącznie właściciel, bez wyjątku dla
 * moderatora. Identyfikator listy w adresie albo w formularzu niczego nie
 * otwiera — decyduje właściciel wiersza.
 */
class ShoppingListPolicy
{
    public function view(User $user, ShoppingList $list): bool
    {
        return $user->getKey() === $list->user_id;
    }

    public function update(User $user, ShoppingList $list): bool
    {
        return $user->getKey() === $list->user_id;
    }

    public function delete(User $user, ShoppingList $list): bool
    {
        return $user->getKey() === $list->user_id;
    }
}
