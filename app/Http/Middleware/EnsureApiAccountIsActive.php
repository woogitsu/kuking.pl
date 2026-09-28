<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Security\KomunikatZamknietegoKonta;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stan konta przy KAŻDYM żądaniu z tokenem — odpowiednik
 * `EnsureAccountIsActive` z WWW (issue #39, D-270).
 *
 * TEN SAM PODZIAŁ, INNA ODPOWIEDŹ. WWW wylogowuje i przekierowuje z komunikatem
 * nad formularzem; aplikacja nie ma formularza, więc dostaje JSON z tym samym
 * zdaniem i kodem, po którym wie, co pokazać:
 *
 * - kara z minionym terminem → konto wraca do `active` od razu (issue #40);
 * - `banned`, `pending_delete`, `erased` → token, którym przyszło żądanie,
 *   GINIE, a odpowiedź to 401 `konto_zamkniete` ze zdaniem z
 *   `KomunikatZamknietegoKonta` (tym samym, które widzi ekran logowania).
 *   Zwykle tokenów już nie ma — `ban()` i `markForDeletion()` kasują je przez
 *   `invalidateSessions()` — ale ta bramka nie może zakładać, że każde inne
 *   miejsce zadziałało (status zmieniony ręcznie w psql podczas incydentu);
 * - `suspended` → odczyt przechodzi, zapis poza listą niżej to 403
 *   `konto_zawieszone` ze zdaniem z `EnsureAccountIsActive`. Wylogowanie
 *   musi działać, inaczej zawieszona osoba nie ma jak odłączyć telefonu.
 *
 * STOI W TRASIE ZA `auth:sanctum` (`routes/api.php`), nie w grupie `api`:
 * potrzebuje rozpoznanej osoby, a grupa działa także na trasach bez tokenu.
 */
class EnsureApiAccountIsActive
{
    /** Trasy API dostępne mimo zawieszenia — ten sam powód co na WWW. */
    private const DOZWOLONE_MIMO_ZAWIESZENIA = [
        'api.tokeny.biezacy.destroy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->punishmentHasExpired()) {
            $user->reinstate();
        }

        if (in_array($user->status, User::STATUSY_ZAMKNIETEGO_KONTA, true)) {
            $token = $user->currentAccessToken();

            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            return $this->odmowa(401, 'konto_zamkniete', KomunikatZamknietegoKonta::dla($user));
        }

        if ($user->isSuspended()
            && ! $request->isMethodSafe()
            && ! in_array($request->route()?->getName(), self::DOZWOLONE_MIMO_ZAWIESZENIA, true)) {
            return $this->odmowa(403, 'konto_zawieszone', EnsureAccountIsActive::komunikatZawieszenia($user));
        }

        return $next($request);
    }

    private function odmowa(int $status, string $kod, string $zdanie): JsonResponse
    {
        return new JsonResponse(['message' => $zdanie, 'code' => $kod], $status, [], JSON_UNESCAPED_UNICODE);
    }
}
