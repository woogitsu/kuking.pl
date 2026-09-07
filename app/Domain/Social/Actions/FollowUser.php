<?php

declare(strict_types=1);

namespace App\Domain\Social\Actions;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Notification;
use App\Models\User;

/**
 * Obserwowanie kogoś.
 *
 * Blokada ma pierwszeństwo: jeśli którakolwiek strona zablokowała drugą,
 * obserwowanie jest niemożliwe. Test regresyjny na to jest obowiązkowy.
 */
final class FollowUser
{
    public function __construct(private readonly NotifyUser $notify) {}

    public function handle(User $follower, User $target): bool
    {
        if ($follower->getKey() === $target->getKey()) {
            throw new BladDlaCzlowieka('Nie można obserwować samego siebie.');
        }

        if ($follower->hasBlockRelationWith($target)) {
            throw new BladDlaCzlowieka('Nie można obserwować tej osoby.');
        }

        if (! $target->isActive()) {
            throw new BladDlaCzlowieka('To konto jest niedostępne.');
        }

        if ($follower->isFollowing($target)) {
            return false;
        }

        $follower->following()->attach($target->getKey(), ['created_at' => now()]);

        $this->notify->handle(
            recipient: $target,
            type: Notification::TYPE_FOLLOW,
            actor: $follower,
            data: ['username' => $follower->profile?->username],
        );

        return true;
    }
}
