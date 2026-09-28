<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PantryItem;
use App\Models\User;

/**
 * „Co mam w domu” jest listą PRYWATNĄ (D-285): nie ma drugiego widza,
 * nie ma wyjątku dla moderatora. Identyfikator produktu w adresie usuwania
 * niczego nie otwiera — decyduje właściciel wiersza.
 */
class PantryItemPolicy
{
    public function delete(User $user, PantryItem $item): bool
    {
        return $user->getKey() === $item->user_id;
    }
}
