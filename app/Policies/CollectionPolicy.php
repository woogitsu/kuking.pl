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

        // WSPÓŁPRACOWNIK WIDZI TAK JAK WŁAŚCICIEL — ALE TYLKO Z WAŻNYM
        // DOSTĘPEM (#1743, D-302). To jest drugie i ostatnie wpuszczenie
        // „z nadania" przed flagą widoczności niżej. Nie jest przywilejem
        // z urzędu, który #1092 zakazuje stawiać nad flagą: właściciel sam
        // wpisał tę osobę do zeszytu, a `dostepWspolpracownika()` zawęża to
        // jeszcze stanem obu kont i blokadą. Widoczność zeszytu nie zmienia
        // się przez to ani trochę — obcy dalej odpada niżej.
        if ($user !== null && $this->dostepWspolpracownika($user, $collection)) {
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
        return $user->getKey() === $collection->owner_id
            && ($user->isActive() || ($user->isSuspended() && ! $collection->isPublic()));
    }

    /**
     * Dopisanie pozycji do zeszytu („Zapisuję", notatka przy pozycji).
     *
     * Właściciel — dokładnie jak `update()` (zawieszony tylko do prywatnego,
     * D-253). Współpracownik — z ważnym dostępem i na tych samych zasadach
     * zawieszenia: kara za pisanie nie pozwala dopisywać do zeszytu, który
     * widzą wszyscy.
     */
    public function addItem(User $user, Collection $collection): bool
    {
        if ($user->getKey() === $collection->owner_id) {
            return $this->update($user, $collection);
        }

        return $this->dostepWspolpracownika($user, $collection)
            && ($user->isActive() || ($user->isSuspended() && ! $collection->isPublic()));
    }

    /**
     * Wyjęcie pozycji z zeszytu. Właściciel — zawsze (tak było od #775:
     * wyjęcie z własnego zeszytu nie pyta o nic). Współpracownik — z ważnym
     * dostępem; wyjmować może także to, co dodał właściciel (decyzja
     * właściciela 26.09.2026: „prawo dopisywania i usuwania pozycji").
     */
    public function removeItem(User $user, Collection $collection): bool
    {
        return $user->getKey() === $collection->owner_id
            || $this->dostepWspolpracownika($user, $collection);
    }

    /**
     * Zapraszanie, odwoływanie zaproszeń i odbieranie dostępu.
     *
     * Tylko właściciel i tylko aktywny: zaproszenie powiadamia drugiego
     * człowieka, czyli jest pisaniem, którego zawieszenie zabrania.
     * Domyślnego „Zapisane" nie udostępniamy (#1743, pierwsza wersja).
     */
    public function share(User $user, Collection $collection): bool
    {
        return $user->getKey() === $collection->owner_id
            && $user->isActive()
            && ! $collection->is_default;
    }

    /**
     * Odejście z cudzego zeszytu. Wystarczy być wpisanym — odejść wolno
     * zawsze, także przy zawieszeniu i przy zamkniętym koncie właściciela.
     */
    public function leave(User $user, Collection $collection): bool
    {
        return $user->getKey() !== $collection->owner_id
            && $collection->maCzlonka($user);
    }

    /**
     * Czy ta osoba ma teraz WAŻNY dostęp współpracownika (#1743, D-302).
     *
     * Wszystkie cztery warunki naraz:
     *  1. zeszyt nie jest domyślnym „Zapisane" (wyzwalacz w bazie i tak
     *     nie wpuści tam członka — to jest druga linia);
     *  2. właściciel wpisał tę osobę do `collection_members`;
     *  3. OBA konta mogą czytać serwis (`mozeCzytac()`): ban, oczekujące
     *     usunięcie i konto usunięte zamykają dostęp w obie strony od razu.
     *     Zawieszenie nie — odcina od pisania, nie od czytania. Członkostwo
     *     zostaje w bazie, więc cofnięcie usunięcia konta albo zdjęcie bana
     *     przywraca dostęp bez nowego zaproszenia;
     *  4. między nimi nie ma blokady w żadną stronę. `BlockUser` i tak
     *     kasuje członkostwo — ten warunek pilnuje okna i starych danych.
     */
    private function dostepWspolpracownika(User $user, Collection $collection): bool
    {
        if ($collection->is_default || $user->getKey() === $collection->owner_id) {
            return false;
        }

        if (! $user->mozeCzytac()) {
            return false;
        }

        $owner = $collection->owner;

        if ($owner === null || ! $owner->mozeCzytac()) {
            return false;
        }

        if (! $collection->maCzlonka($user)) {
            return false;
        }

        return ! $user->hasBlockRelationWith($owner);
    }

    public function create(User $user, string $visibility = 'private'): bool
    {
        return $user->isActive() || ($user->isSuspended() && $visibility === 'private');
    }

    public function delete(User $user, Collection $collection): bool
    {
        // Domyślnego zeszytu "Zapisane" nie da się usunąć — inaczej przycisk
        // "Zapisuję" przestałby mieć gdzie zapisywać.
        return $user->getKey() === $collection->owner_id && ! $collection->is_default;
    }
}
