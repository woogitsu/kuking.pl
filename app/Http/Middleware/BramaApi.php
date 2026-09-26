<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Providers\ApiServiceProvider;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pierwsza bramka każdego żądania pod `/api/*` (D-270).
 *
 * TRZY RZECZY, WSZYSTKIE PRZED CZYMKOLWIEK INNYM W GRUPIE `api` — dlatego ta
 * klasa stoi na samym początku listy priorytetów middleware'u
 * (`bootstrap/app.php`), przed `AuthenticatesRequests` i `ThrottleRequests`.
 *
 * 1. WYŁĄCZNIK `KUKING_API_ENABLED` (domyślnie zamknięty). Przy wyłączonym
 *    API odpowiedź jest TYM SAMYM 404 co na adres, którego nie ma — nie 401
 *    z `auth:sanctum` i nie 429 z limitera. Inaczej zamknięte API
 *    zdradzałoby, które trasy za nim stoją.
 *
 * 2. LIMIT NA ADRES IP, liczony TUTAJ, a nie w limiterze `api`. Limiter
 *    frameworka (`throttle:`) framework sortuje ZA `auth:sanctum`, więc
 *    żądanie z fałszywym tokenem kończy się na 401, zanim licznik cokolwiek
 *    policzy — a to jest właśnie ruch, który limit po adresie ma łapać
 *    (ktoś próbujący tokenów seriami, zapętlony klient z odwołanym tokenem).
 *    Każde odbicie od `auth:sanctum` to zapytanie do bazy; tutaj liczy się
 *    ono do limitu, zanim do bazy dojdzie. Limit NA TOKEN zostaje
 *    w limiterze `api` — tam token jest już sprawdzony.
 *
 * 3. `Accept: application/json` DLA KAŻDEGO ŻĄDANIA. Klient, który zapomni
 *    nagłówka, dostałby od części frameworka przekierowanie na ekran
 *    logowania zamiast 401 — a aplikacja mobilna nie ma przeglądarki, która
 *    by to przekierowanie pokazała.
 */
class BramaApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('kuking.api.wlaczone')) {
            throw new NotFoundHttpException;
        }

        $this->policzAdres($request);

        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }

    private function policzAdres(Request $request): void
    {
        [$proby, $minuty] = ApiServiceProvider::limit('na_adres');
        $klucz = ApiServiceProvider::PREFIKS_ADRESU.(string) $request->ip();

        if (RateLimiter::tooManyAttempts($klucz, $proby)) {
            $sekundy = max(1, RateLimiter::availableIn($klucz));

            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => $sekundy,
                'X-RateLimit-Limit' => $proby,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($klucz, $minuty * 60);
    }
}
