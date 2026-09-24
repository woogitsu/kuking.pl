<?php

declare(strict_types=1);

namespace App\Domain\Social\Actions;

use App\Domain\Users\ObserwowanieGospodarza;
use App\Models\Profile;
use App\Models\User;

/**
 * Implementacja kontraktu z `Users` (issue #971) — patrz
 * `App\Domain\Users\ObserwowanieGospodarza`, tam jest uzasadnienie kierunku.
 *
 * Gospodarz, którego nie ma, i konto będące samym gospodarzem przechodzą
 * po cichu — dokładnie jak przed przeniesieniem z `ZalozKonto`. Odmowy
 * `FollowUser` (`BladDlaCzlowieka`) i każdy inny wyjątek wychodzą na
 * zewnątrz: politykę awarii rozstrzyga rejestracja.
 */
final class ObserwujGospodarza implements ObserwowanieGospodarza
{
    public function __construct(private readonly FollowUser $followUser) {}

    public function zacznij(User $konto, string $nazwaGospodarza): void
    {
        $gospodarz = Profile::where('username', $nazwaGospodarza)->first()?->user;

        if ($gospodarz === null || $gospodarz->getKey() === $konto->getKey()) {
            return;
        }

        $this->followUser->handle($konto, $gospodarz);
    }
}
