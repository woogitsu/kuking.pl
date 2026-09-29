<?php

declare(strict_types=1);

namespace App\Domain\Collections\Wspoldzielenie;

use App\Models\CollectionInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Zaproszenia, na które ta osoba może teraz odpowiedzieć (#1743).
 *
 * Tylko po nazwie konta (link nie ma adresata, dopóki nikt go nie otworzy),
 * tylko oczekujące i nieprzeterminowane, tylko od właściciela, który może
 * czytać serwis, i tylko bez blokady między stronami. Zaproszenie od osoby
 * zbanowanej albo kasującej konto nie wisi na ekranie „Moje" jako coś, co
 * da się przyjąć — przyjęcie i tak by odmówiło.
 */
final class ZaproszeniaDoZeszytow
{
    /** @return EloquentCollection<int, CollectionInvitation> */
    public function oczekujaceDla(User $user): EloquentCollection
    {
        return CollectionInvitation::query()
            ->where('invitee_id', $user->getKey())
            ->where('status', CollectionInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->whereHas('collection.owner', fn ($wlasciciel) => $wlasciciel->widocznyJakoOsoba())
            ->whereNotExists(fn ($blokada) => $blokada->selectRaw('1')
                ->from('blocks')
                ->where(fn ($para) => $para
                    ->where(fn ($a) => $a->whereColumn('blocks.blocker_id', 'collection_invitations.inviter_id')->where('blocks.blocked_id', $user->getKey()))
                    ->orWhere(fn ($b) => $b->where('blocks.blocker_id', $user->getKey())->whereColumn('blocks.blocked_id', 'collection_invitations.inviter_id'))))
            ->with(['collection', 'inviter.profile'])
            ->orderBy('created_at')
            ->get();
    }
}
