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

        // FLAGA WIDOCZNOŚCI JEST PIERWSZA — I MUSI BYĆ (issue #1092).
        //
        // Zeszyt prywatny nie ma poza właścicielem żadnego widza. Żaden
        // warunek NIŻEJ nie może tego odwrócić, więc żaden nie ma prawa
        // stać wyżej. Wcześniej stan konta właściciela był sprawdzany PRZED
        // tą flagą i cała bramka zwracała wtedy `$user->isModerator()` —
        // bez pytania, czy zeszyt jest w ogóle publiczny. Skutek był
        // dokładnie odwrotny do zamierzonego: zbanowanie właściciela albo
        // ustawienie mu `pending_delete` OTWIERAŁO moderatorowi jego
        // PRYWATNY zeszyt, którego przy koncie aktywnym nie widział.
        // Zmiana statusu ROZSZERZAŁA dostęp zamiast go zawężać — ta sama
        // rodzina co P0 #941.
        //
        // To nie jest przeniesienie „dla porządku": dopóki widoczność stoi
        // niżej, każdy przyszły warunek wpuszczający kogoś „z urzędu"
        // dziedziczy tę samą dziurę.
        if (! $collection->isPublic()) {
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
        //
        // Ten warunek tylko ZAWĘŻA: publiczny zeszyt zbanowanego znika
        // wszystkim poza moderatorem. Niczego nie odblokowuje, bo zeszyt
        // niepubliczny odpadł wyżej.
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

        return true;
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
