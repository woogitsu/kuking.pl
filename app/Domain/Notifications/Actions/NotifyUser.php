<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Models\Notification;
use App\Models\User;

/**
 * Jedno miejsce, przez które powstają wszystkie powiadomienia w aplikacji.
 *
 * Reguły wpisane tutaj, żeby nie trzeba było o nich pamiętać w 12 miejscach:
 *  - nie powiadamiamy nikogo o jego własnej akcji,
 *  - nie powiadamiamy, jeśli między osobami jest blokada,
 *  - nie powiadamiamy kont nieaktywnych.
 */
final class NotifyUser
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $recipient, string $type, ?User $actor = null, array $data = []): ?Notification
    {
        if ($actor !== null && $actor->getKey() === $recipient->getKey()) {
            return null;
        }

        if (! $recipient->isActive()) {
            return null;
        }

        if ($actor !== null && $recipient->hasBlockRelationWith($actor)) {
            return null;
        }

        return Notification::create([
            'user_id' => $recipient->getKey(),
            'actor_id' => $actor?->getKey(),
            'type' => $type,
            'data' => $data,
        ]);
    }
}
