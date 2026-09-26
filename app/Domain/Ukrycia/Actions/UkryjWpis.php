<?php

declare(strict_types=1);

namespace App\Domain\Ukrycia\Actions;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\Hide;
use App\Models\Post;
use App\Models\User;

/**
 * „Ukryj ten wpis" — tylko dla widza, domyślnie na `kuking.ukrycia.dni` dni
 * (issue #1810, D-278).
 *
 * Nikogo nie powiadamia, nic nie liczy po stronie autora, nie dotyka
 * moderacji. Ponowne ukrycie tego samego wpisu przedłuża termin w tym samym
 * wierszu (indeks unikalny `hides_user_post_unique`), a ukrycie „na stałe"
 * zostaje na stałe — drugi klik w menu nie może skrócić decyzji z listy.
 * Czy widz w ogóle widzi wpis, sprawdza Policy w kontrolerze.
 */
final class UkryjWpis
{
    public function handle(User $widz, Post $post): Hide
    {
        if ($post->author_id === $widz->getKey()) {
            throw new BladDlaCzlowieka('Własnego wpisu nie ukrywasz — możesz go edytować albo usunąć.');
        }

        return Hide::ukryjDla($widz, 'post_id', (string) $post->getKey());
    }
}
