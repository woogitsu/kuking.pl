<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PersonalAccessToken;
use App\Models\User;

/**
 * Token aplikacji mobilnej (D-270): zobaczyć i odwołać go może WYŁĄCZNIE
 * właściciel konta.
 *
 * Moderator też nie — token to poświadczenie, nie treść. Moderacja odcina
 * konto blokadą albo zawieszeniem (`User::ban()`/`suspend()`), a te kasują
 * tokeny same (`User::invalidateSessions()`); przeglądanie cudzych urządzeń
 * nie jest do tego potrzebne i nie ma go w żadnym zadaniu moderacji.
 */
class PersonalAccessTokenPolicy
{
    public function delete(User $user, PersonalAccessToken $token): bool
    {
        return $token->tokenable_type === User::class
            && $token->tokenable_id === $user->getKey();
    }
}
