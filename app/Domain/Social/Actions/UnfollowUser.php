<?php

declare(strict_types=1);

namespace App\Domain\Social\Actions;

use App\Domain\Social\ListyWidza;
use App\Domain\Social\ZamekPary;
use App\Models\User;

/**
 * Przestań obserwować.
 *
 * Wchodzi pod ten sam `ZamekPary` co `FollowUser` i `BlockUser` (issue #2404,
 * audyt A-001). Gołe `detach()` bez blokady nie ustawiało się w kolejce razem
 * z „Obserwuj": późniejsze kliknięcie mogło wykonać się PRZED wcześniejszym,
 * które już czekało na blokadę, i stan końcowy był odwrotny do ostatniej
 * decyzji człowieka (np. „Przestań obserwować" kliknięte po „Obserwuj", a
 * osoba dalej obserwowana). Blokada szereguje obie operacje po tej samej
 * parze wierszy `users`, w kolejności rosnąco po id (uzasadnienie w
 * `ZamekPary`, razem z zakazem wołania jej z wnętrza `ZamekKonta`).
 *
 * Idempotencja: wielokrotne „Przestań obserwować" jest bezpieczne — `detach()`
 * po wierszu, którego nie ma, nic nie robi. Konto, którego już nie ma
 * (`null` pod blokadą), nie jest błędem: `detach()` idzie po identyfikatorach.
 */
final class UnfollowUser
{
    public function handle(User $follower, User $target): void
    {
        ZamekPary::zablokuj($follower, $target, static function () use ($follower, $target): void {
            $follower->following()->detach($target->getKey());
            ListyWidza::uniewaznij();
        });
    }
}
