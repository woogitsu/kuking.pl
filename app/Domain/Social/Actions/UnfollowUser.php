<?php

declare(strict_types=1);

namespace App\Domain\Social\Actions;

use App\Domain\Social\ListyWidza;
use App\Domain\Social\ZamekPary;
use App\Models\User;

final class UnfollowUser
{
    public function handle(User $follower, User $target): void
    {
        ZamekPary::zablokuj($follower, $target, static function (?User $obserwujacy, ?User $obserwowany): void {
            // Oba konta mogły zniknąć, zanim przyszła kolej na blokadę.
            // Cofnięcie nieistniejącego obserwowania jest idempotentne.
            if ($obserwujacy === null || $obserwowany === null) {
                return;
            }

            $obserwujacy->following()->detach($obserwowany->getKey());
        });

        ListyWidza::uniewaznij();
    }
}
