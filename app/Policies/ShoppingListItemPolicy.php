<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ShoppingListItem;
use App\Models\User;

/**
 * Lista zakupów jest PRYWATNA (#27, etap 2, D-333): pozycję zmienia
 * i usuwa wyłącznie jej właściciel, bez wyjątku dla moderatora — w cudzej
 * liście zakupów nie ma czego moderować. Identyfikator pozycji w adresie
 * niczego nie otwiera, decyduje właściciel wiersza.
 */
class ShoppingListItemPolicy
{
    public function update(User $user, ShoppingListItem $item): bool
    {
        return $user->getKey() === $item->user_id;
    }

    public function delete(User $user, ShoppingListItem $item): bool
    {
        return $user->getKey() === $item->user_id;
    }
}
