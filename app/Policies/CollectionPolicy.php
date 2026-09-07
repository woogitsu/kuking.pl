<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Collection;
use App\Models\User;

class CollectionPolicy
{
    public function view(?User $user, Collection $collection): bool
    {
        // Właściciel widzi zawsze — także wtedy, gdy kogoś zablokował.
        if ($user !== null && $user->getKey() === $collection->owner_id) {
            return true;
        }

        // Konto bez właściciela nie powinno istnieć (klucz obcy z `cascade`),
        // ale odczyt w połowie kasowania konta może zwrócić `null` — tak samo
        // jak w `ProfilePolicy::view()`. Odmowa jest jedyną bezpieczną
        // odpowiedzią.
        if ($collection->owner === null) {
            return false;
        }

        // TA SAMA REGUŁA CO `UserPolicy::viewProfile()` — i z tego samego
        // powodu: `/@konto-zbanowane` daje 403 (chyba że patrzy moderator),
        // ale bez tego warunku publiczny zeszyt tej samej osoby zostawał pod
        // swoim adresem dalej widoczny dla każdego, kto ten adres miał —
        // konto zbanowane albo kasujące się mniej dostępne przez profil niż
        // przez bezpośredni link do zeszytu. Ten sam rozjazd co wpis
        // zbanowanego autora w feedzie obserwowanych (commit 964b99c), tylko
        // na poziomie POJEMNIKA, nie pojedynczej treści w środku.
        if (! $collection->owner->jestDostepnyJakoAutor()) {
            return $user !== null && $user->isModerator();
        }

        // Blokada ma pierwszeństwo przed „publiczny" (issue #41).
        //
        // Bez tego warunku zeszyt był jedynym typem treści, który blokady nie
        // respektował: wpis, przepis i wykonanie znikały zablokowanemu z oczu,
        // a zeszyt — nie. Blokada, która działa „wszędzie poza jednym miejscem",
        // nie jest blokadą, tylko obietnicą bez pokrycia.
        if ($user !== null && $user->hasBlockRelationWith($collection->owner)) {
            return false;
        }

        return $collection->isPublic();
    }

    public function update(User $user, Collection $collection): bool
    {
        return $user->getKey() === $collection->owner_id;
    }

    public function delete(User $user, Collection $collection): bool
    {
        // Domyślnego zeszytu "Zapisane" nie da się usunąć — inaczej przycisk
        // "Zapisuję" przestałby mieć gdzie zapisywać.
        return $user->getKey() === $collection->owner_id && ! $collection->is_default;
    }
}
