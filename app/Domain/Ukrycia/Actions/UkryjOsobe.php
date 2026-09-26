<?php

declare(strict_types=1);

namespace App\Domain\Ukrycia\Actions;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\Hide;
use App\Models\User;

/**
 * „Ukryj tę osobę" — tylko dla widza, domyślnie na `kuking.ukrycia.dni` dni
 * (issue #1810, D-278).
 *
 * Działa tam, gdzie serwis sam podsuwa ludzi (Odkrywanie, automatyczna część
 * tablicy, propozycje osób). Kogoś, kogo się obserwuje, się nie ukrywa —
 * wtedy właściwą drogą jest „Przestań obserwować", więc akcja odmawia
 * zamiast tworzyć stan, w którym Obserwowani i ukrycie mówią co innego.
 * Nie powiadamiamy tej osoby i nie liczymy niczego po jej stronie.
 */
final class UkryjOsobe
{
    public function handle(User $widz, User $osoba): Hide
    {
        if ($osoba->getKey() === $widz->getKey()) {
            throw new BladDlaCzlowieka('Siebie nie da się ukryć.');
        }

        if ($widz->isFollowing($osoba)) {
            throw new BladDlaCzlowieka('Obserwujesz tę osobę. Jeśli nie chcesz widzieć jej wpisów, najpierw przestań ją obserwować.');
        }

        return Hide::ukryjDla($widz, 'hidden_user_id', (string) $osoba->getKey());
    }
}
