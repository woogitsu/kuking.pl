<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CookedEvent;
use App\Models\User;

class CookedEventPolicy
{
    public function view(?User $user, CookedEvent $event): bool
    {
        if ($user !== null && $user->hasBlockRelationWith($event->user)) {
            return false;
        }

        // Przepis usunięty (soft delete) — relacja zwraca null (audyt A23).
        //
        // Widoczność wykonania idzie za widocznością przepisu, więc bez
        // przepisu nie ma na czym oprzeć pokazania go obcym. Zostaje sam
        // właściciel wykonania (żeby mógł je skasować) i moderator.
        // withTrashed() rozwiązałoby TypeError i jednocześnie przywróciło
        // widoczność treści, którą autor świadomie usunął — czyli naprawiło
        // wyjątek kosztem prywatności.
        if ($event->recipe === null) {
            return $user !== null && ($user->getKey() === $event->user_id || $user->isModerator());
        }

        // Widoczność wykonania idzie za widocznością przepisu.
        return app(RecipePolicy::class)->view($user, $event->recipe);
    }

    public function delete(User $user, CookedEvent $event): bool
    {
        return $user->getKey() === $event->user_id || $user->isModerator();
    }

    /**
     * Ekran „Komuś wyszło" (issue #17) — świętowanie cudzego wykonania.
     *
     * Świadomie WĘŻSZE niż `view()` wyżej, z dwóch powodów:
     *
     * 1. `view()` odpowiada „kto ma prawo zobaczyć ten wpis" (autor wykonania,
     *    moderator, każdy kogo widoczność przepisu wpuszcza). Ten ekran to
     *    inne pytanie: „komu ten produkt ma AKTYWNIE wypychać na wierzch
     *    cudze zdjęcie z gratulacją" — a to ma sens wyłącznie dla osoby, dla
     *    której to wykonanie faktycznie coś znaczy, czyli autora przepisu.
     *    Zwykły widz albo moderator dostają tu 403, nie pustą stronę — mają
     *    zwykły `cooked.show`, tak jak każdy inny wpis.
     *
     * 2. Blokada sprawdzana PIERWSZA i w OBIE strony, dokładnie jak
     *    w `scopeWidoczneDla` (ten sam wzorzec, patrz `CookedEvent::scopeWidoczneDla`).
     *
     * 3. Wykonanie osoby zbanowanej albo czekającej na usunięcie konta NIE
     *    dostaje tego wyróżnienia. To NIE jest to samo co ukrycie treści —
     *    „poprawne dane nigdy nie znikają" (AGENTS.md) dalej obowiązuje i
     *    `cooked.show` pod zwykłym adresem działa bez zmian. Różnica jest
     *    w tym, że serwis przestaje SAM z siebie podsuwać ten moment jako
     *    powód do radości, tym samym wzorcem co `Post::scopeTylkoOdDostepnychAutorow`
     *    dla treści polecanych nieznajomym — tu odbiorcą „polecenia" jest
     *    autor przepisu, nie przypadkowy gość, ale mechanika jest ta sama.
     */
    public function celebrate(User $user, CookedEvent $event): bool
    {
        if ($event->recipe === null || $user->getKey() !== $event->recipe->author_id) {
            return false;
        }

        if ($user->hasBlockRelationWith($event->user)) {
            return false;
        }

        return $event->user->status !== User::STATUS_BANNED
            && $event->user->status !== User::STATUS_PENDING_DELETE;
    }
}
