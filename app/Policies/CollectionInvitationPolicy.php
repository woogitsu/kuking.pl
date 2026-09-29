<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CollectionInvitation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Zaproszenie do wspólnego zeszytu po nazwie konta (#1743, D-302).
 *
 * UUID zaproszenia w adresie nie jest autoryzacją (AGENTS.md §7): otwiera je,
 * przyjmuje i odrzuca wyłącznie adresat. Każdy inny — także zalogowany,
 * także właściciel zeszytu (ten zarządza zaproszeniami z ekranu zeszytu,
 * przez `CollectionPolicy::manageAccess()`) — dostaje 404, jakby zaproszenia
 * nie było. Zaproszenie linkiem nie przechodzi tędy: nie ma adresata, a jego
 * poświadczeniem jest token w adresie.
 */
class CollectionInvitationPolicy
{
    public function respond(User $user, CollectionInvitation $invitation): Response
    {
        return $invitation->invitee_id === $user->getKey()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
