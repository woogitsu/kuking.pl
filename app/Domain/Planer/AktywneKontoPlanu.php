<?php

declare(strict_types=1);

namespace App\Domain\Planer;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/** Świeży stan konta odczytany po założeniu blokady wiersza users. */
final class AktywneKontoPlanu
{
    public static function podBlokada(User $user): User
    {
        $swiezy = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

        if ($swiezy === null || $swiezy->status !== User::STATUS_ACTIVE || $swiezy->data_erased_at !== null) {
            throw new AuthorizationException('Stan Twojego konta zmienił się. Odśwież stronę i spróbuj ponownie.');
        }

        return $swiezy;
    }
}
