<?php

declare(strict_types=1);

namespace App\Domain\Social\Actions;

use App\Domain\Community\HostUserResolver;
use App\Domain\Users\ObserwowanieGospodarza;
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
    public function __construct(
        private readonly FollowUser $followUser,
        private readonly HostUserResolver $hostUser,
    ) {}

    public function zacznij(User $konto): void
    {
        // Gospodarz rozpoznawany po UUID konta, nie po edytowalnej nazwie
        // profilu (#1089) — jedno źródło: HostUserResolver.
        $gospodarz = $this->hostUser->resolve();

        if ($gospodarz === null || $gospodarz->getKey() === $konto->getKey()) {
            return;
        }

        $this->followUser->handle($konto, $gospodarz);
    }
}
