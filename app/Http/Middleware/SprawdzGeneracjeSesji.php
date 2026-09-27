<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Sesja\GeneracjaSesji;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sesja z generacją starszą niż konto = wylogowanie (#1046).
 *
 * Pełne uzasadnienie w `App\Support\Sesja\GeneracjaSesji`. W grupie `web`,
 * PO `EnsureAccountIsActive`: konto zbanowane albo zgłoszone do usunięcia ma
 * dostać tamten komunikat (z treścią od moderatora), nie ogólny.
 *
 * `logoutCurrentDevice()`, a nie `logout()`: `logout()` rotuje
 * `remember_token` konta, więc odrzucenie starej sesji napastnika wylogowałoby
 * przy okazji zapamiętane logowanie właściciela, wystawione już po
 * unieważnieniu.
 */
class SprawdzGeneracjeSesji
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User || ! $request->hasSession() || GeneracjaSesji::zgodna($request->session(), $user)) {
            return $next($request);
        }

        Auth::guard('web')->logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors([
            'login' => 'Ze względów bezpieczeństwa wylogowaliśmy Cię na tym urządzeniu. Zaloguj się ponownie.',
        ]);
    }
}
