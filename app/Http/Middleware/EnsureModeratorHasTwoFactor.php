<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Security\TwoFactorAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blokada `/admin/**` bez potwierdzonego 2FA (issue #12).
 *
 * Zakładana KONIECZNIE po middleware `moderator` w tej samej trasie — dzięki
 * temu zwykły użytkownik nadal dostaje 404 od `EnsureUserIsModerator`
 * (panel moderacji nie potwierdza, że istnieje) i NIGDY nie trafia tutaj.
 * Ten middleware zakłada, że `$request->user()` jest już potwierdzonym
 * moderatorem albo adminem.
 *
 * Moderator BEZ 2FA nie widzi ściany — widzi jasny ekran tłumaczący dlaczego
 * i przycisk prowadzący prosto do włączenia. Status 403, bo strona istnieje
 * i ta osoba naprawdę ma do niej docelowo dostęp — tylko jeszcze nie teraz.
 */
class EnsureModeratorHasTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->hasTwoFactorConfirmed()) {
            return response()->view('pages.admin.wymagane_2fa', [], 403);
        }

        // KONTO MA 2FA, ALE TA SESJA KODU NIE WIDZIAŁA (#930).
        //
        // Stan konta nie mówi, jak powstała bieżąca sesja: mogła zostać
        // z logowania samym hasłem sprzed włączenia 2FA albo odtworzyć się
        // ze starego ciasteczka „zapamiętaj mnie". Do panelu wpuszcza więc
        // dopiero dowód, że w tej sesji padł poprawny kod.
        if ($user !== null && ! TwoFactorAuthenticator::sesjaMaDowod(
            $user,
            $request->session()->get(TwoFactorAuthenticator::KLUCZ_DOWODU_SESJI),
        )) {
            return response()->view('pages.admin.wymagane_2fa_w_sesji', [], 403);
        }

        return $next($request);
    }
}
