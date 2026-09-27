<?php

declare(strict_types=1);

namespace App\Domain\Ukrycia\Actions;

use App\Models\Hide;
use App\Models\Post;
use App\Models\User;

/**
 * „Przywróć", „Zostaw ukryte" i „Cofnij" (issue #1810, D-278).
 *
 * Wszystkie trzy działają na ukryciach WŁASNYCH widza — kontroler pyta
 * `HidePolicy` o wiersz z listy, a „Cofnij" po ukryciu szuka wiersza po
 * parze (widz, obiekt), więc cudzego wiersza nie da się tu wskazać.
 */
final class ZmienUkrycie
{
    /** Obiekt wraca od razu. Wiersz znika — lista nie trzyma historii. */
    public function przywroc(Hide $ukrycie): void
    {
        $ukrycie->delete();
    }

    /** Termin znika: ukryte, dopóki widz sam nie przywróci. */
    public function zostawNaStale(Hide $ukrycie): void
    {
        $ukrycie->forceFill(['hidden_until' => null])->save();
    }

    public function cofnijWpis(User $widz, Post $post): bool
    {
        return Hide::query()->where('user_id', $widz->getKey())->where('post_id', $post->getKey())->delete() > 0;
    }

    public function cofnijOsobe(User $widz, User $osoba): bool
    {
        return Hide::query()->where('user_id', $widz->getKey())->where('hidden_user_id', $osoba->getKey())->delete() > 0;
    }
}
