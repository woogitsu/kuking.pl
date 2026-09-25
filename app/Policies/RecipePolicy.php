<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Recipes\RecipeStatusTransitions;
use App\Models\Recipe;
use App\Models\User;

class RecipePolicy
{
    public function view(?User $user, Recipe $recipe): bool
    {
        if (! $recipe->isPublished()) {
            if ($user === null) {
                return false;
            }

            if ($user->getKey() === $recipe->author_id) {
                return true;
            }

            // SZKIC JEST WYŁĄCZNIE AUTORA — także wobec moderatora (#1359,
            // audyt AUTHZ-01). Moderacja zagląda do treści, którą sama
            // ukryła albo zdjęła (`hidden`/`removed`), bo rozpatruje sprawę
            // albo odwołanie. Rodzinny przepis w warsztacie, którego autor
            // nikomu nie pokazał, nie jest żadną sprawą — ta sama granica co
            // `PostPolicy::view()` dla szkicu wpisu. Lista statusów jest
            // zamknięta: nowy stan nieopublikowany nie otworzy się moderacji
            // po cichu. Blokady ta gałąź celowo nie sprawdza — tak jak przed
            // #1359: czy blokada ma odcinać moderatora od sprawy, to otwarte
            // pytanie B-02 z `docs/AUDYT_BEZPIECZENSTWA_2026-09-15.md`,
            // a nie coś do rozstrzygnięcia przy okazji.
            return in_array($recipe->status, [Recipe::STATUS_HIDDEN, Recipe::STATUS_REMOVED], true)
                && $user->isModerator();
        }

        $isOwnerOrModerator = $user !== null
            && ($user->getKey() === $recipe->author_id || $user->isModerator());

        // Konto autora zbanowane albo oznaczone do usunięcia — ta sama granica
        // co `UserPolicy::viewProfile` (audyt A5). Bez tego przepis zostawał
        // dostępny pod bezpośrednim adresem, mimo że link „zobacz profil" pod
        // nim dawał 403 — obietnica bez pokrycia w drugą stronę. Zawieszenie
        // NIE wchodzi tutaj: to kara czasowa i tylko na publikowanie
        // („dostęp tylko do ODCZYTU" — `EnsureAccountIsActive`), więc treść
        // zawieszonej osoby zostaje widoczna tak jak jej profil.
        if (! $isOwnerOrModerator && ! $recipe->author->jestDostepnyJakoAutor()) {
            return false;
        }

        if ($user !== null && $user->hasBlockRelationWith($recipe->author)) {
            return false;
        }

        return match ($recipe->visibility) {
            'public' => true,
            'followers' => $user !== null
                && ($user->getKey() === $recipe->author_id || $user->isFollowing($recipe->author)),
            'private' => $user !== null && $user->getKey() === $recipe->author_id,
            default => false,
        };
    }

    /**
     * Autorstwo to warunek konieczny, nie wystarczający (audyt A08).
     *
     * Sama odpowiedź „to Twój przepis" pozwalała autorowi wejść w edycję
     * przepisu UKRYTEGO przez moderatora i opublikować go z powrotem.
     * O tym, czy przepis w danym stanie wolno jeszcze zmieniać, decyduje
     * jawna macierz przejść, a nie kolejny warunek dopisany w tym miejscu.
     */
    public function update(User $user, Recipe $recipe): bool
    {
        if ($user->getKey() !== $recipe->author_id) {
            return false;
        }

        return RecipeStatusTransitions::authorMayEdit($recipe->status);
    }

    /**
     * Zwykłe usunięcie (`DELETE` ze strony treści) — wyłącznie autor.
     *
     * Issue #932: moderator NIE usuwa tędy cudzej treści, nawet z 2FA.
     * Ta droga omija panel `/admin` (2FA — `moderator.2fa`), uzasadnienie,
     * wiersz w `moderation_actions`, powiadomienie i odwołanie (DSA art. 17
     * i 20). Cudzą treść zdejmuje się decyzją „Usuń" w `/admin/zgloszenia`.
     */
    public function delete(User $user, Recipe $recipe): bool
    {
        return $user->getKey() === $recipe->author_id;
    }

    /**
     * Zdjęcie przepisu Z URZĘDU, bez zgłoszenia, z panelu moderacji (G31, D-251).
     * Reguła: `UserPolicy::takeDownContentOf()` — 2FA i niższa rola autora.
     */
    public function removeExOfficio(User $user, Recipe $recipe): bool
    {
        return app(UserPolicy::class)->takeDownContentOf($user, $recipe->author);
    }

    /**
     * "Ugotowałem" można dodać do CUDZEGO przepisu.
     *
     * Do własnego też — bo ludzie realnie gotują swoje przepisy i chcą mieć
     * ślad, że robili to w tym roku. Nie odbieramy tego, ale w statystykach
     * jakości liczymy tylko wykonania cudzych przepisów.
     */
    public function cook(User $user, Recipe $recipe): bool
    {
        return $this->view($user, $recipe) && $user->isActive();
    }
}
