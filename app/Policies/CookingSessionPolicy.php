<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CookingSession;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Wspólne gotowanie (#2385). Projekt: `docs/product/PROJEKT_WSPOLNE_GOTOWANIE_2385.md`.
 *
 * ZASADY
 * - UUID sesji w adresie NIE jest autoryzacją. Sesję widzi wyłącznie jej
 *   gospodarz i pomocnik; każdy inny — także zalogowany, także moderator —
 *   dostaje 404 (`denyAsNotFound`), jakby sesji nie było. Sesja wygasła jest
 *   dla serwisu nieistniejąca tak samo.
 * - To jest pytanie o CZŁONKOSTWO. Czy osoba widzi sam PRZEPIS, rozstrzyga
 *   osobno `RecipePolicy::view` i kontroler zadaje je przy każdym żądaniu:
 *   członkostwo nie otwiera przepisu, do którego konto straciło dostęp.
 * - Zawieszone konto czyta, nie zapisuje (jak przy „Ugotowałem”).
 * - Moderator nie ma wyjątku: sesja nie jest treścią do moderacji.
 */
class CookingSessionPolicy
{
    /** Założenie sesji: aktywne konto i przepis, który ta osoba widzi. */
    public function create(User $user, Recipe $recipe): bool
    {
        return $user->isActive() && Gate::forUser($user)->allows('view', $recipe);
    }

    /** Widok sesji: gospodarz albo pomocnik, dopóki sesja trwa. */
    public function view(User $user, CookingSession $sesja): Response
    {
        if (! $sesja->trwa()) {
            return Response::denyAsNotFound();
        }

        if (! $sesja->maGospodarza($user) && ! $sesja->maPomocnika($user)) {
            return Response::denyAsNotFound();
        }

        // Gospodarz z zamkniętym kontem (zbanowany, do usunięcia, wymazany) nie
        // prowadzi już sesji: pomocnik widzi tyle, co przy sesji, której nie ma.
        // Zawieszenie tu nie wchodzi — zawieszony czyta (`mozeCzytac`).
        if (! $sesja->maGospodarza($user) && ! ($sesja->host?->mozeCzytac() ?? false)) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    /** Odhaczanie kroków: członek sesji z aktywnym kontem. */
    public function update(User $user, CookingSession $sesja): Response
    {
        $widok = $this->view($user, $sesja);

        if ($widok->denied()) {
            return $widok;
        }

        return $user->isActive()
            ? Response::allow()
            : Response::deny('Konto jest zawieszone, więc możesz tylko czytać.');
    }

    /** Zarządzanie (link, usuwanie pomocnika, czyszczenie postępu): tylko gospodarz. */
    public function manage(User $user, CookingSession $sesja): Response
    {
        $widok = $this->view($user, $sesja);

        if ($widok->denied()) {
            return $widok;
        }

        return $sesja->maGospodarza($user) && $user->isActive()
            ? Response::allow()
            : Response::deny('Tylko gospodarz sesji może to zrobić.');
    }

    /** Zakończenie (kasuje dane sesji): gospodarz, także z zawieszonym kontem. */
    public function end(User $user, CookingSession $sesja): Response
    {
        return $sesja->maGospodarza($user) ? Response::allow() : Response::denyAsNotFound();
    }

    /** Wyjście z sesji: pomocnik. */
    public function leave(User $user, CookingSession $sesja): Response
    {
        return $sesja->maPomocnika($user) ? Response::allow() : Response::denyAsNotFound();
    }
}
