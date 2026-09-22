<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Analytics\ZanotujOstatniaWizyte;
use App\Domain\Pwa\InstallPrompt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Ustawia `users.ostatnio_widziany_at` dla każdego uwierzytelnionego żądania —
 * cienko: cała reguła „czy i jak zapisać" mieszka w
 * `App\Domain\Analytics\ZanotujOstatniaWizyte` (patrz tamten komentarz po
 * throttl i wybór `DB::table()->update()` zamiast `$user->save()`).
 *
 * GLOBALNIE W GRUPIE `web`, PO `EnsureAccountIsActive` (`bootstrap/app.php`)
 * Kolejność jest celowa, nie przypadkowa. `EnsureAccountIsActive` wylogowuje
 * konta zbanowane/`pending_delete`/`erased` PRZED tym middleware'em — więc
 * `$request->user()` jest tu już `null` dla takiego żądania i nic się nie
 * zapisuje. Bez tej kolejności ostatnia sekunda przed wylogowaniem
 * zbanowanego konta liczyłaby się do WAC jako „ktoś tu był", mimo że
 * `CookEligibility` (używane przez raport, `App\Domain\Analytics
 * \CookEligibility`) i tak wyklucza takie konta z metryk — dwie niezgodne
 * ze sobą reguły „kto się liczy" byłyby gorsze niż jedna.
 *
 * DLACZEGO GLOBALNIE, A NIE NA WYBRANYCH TRASACH
 * Ten sam argument co przy `EnsureAccountIsActive`: wybiórcze dołożenie na
 * kontrolerach znaczy, że następny nowy kontroler o tym zapomni, a brak tego
 * middleware'u niczego nie wywala — po prostu WAC/D7/D30 cichutko nie
 * zauważą, że ktoś tu był. Middleware sam nic nie robi na trasach gościa
 * (`$request->user()` jest `null`), więc koszt na niezalogowanym ruchu to
 * jedno sprawdzenie `instanceof`.
 */
class AktualizujOstatniaWizyte
{
    private const PWA_RETURN_CANDIDATE = 'pwa_return_candidate';

    public function __construct(
        private readonly ZanotujOstatniaWizyte $zanotuj,
        private readonly InstallPrompt $installPrompt,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            // Zachowujemy poprzednią aktywność przed jej aktualizacją.
            // Prefetch i żądania w tle nie są powrotem do czytania strony.
            $navigation = $request->isMethod('GET') && $request->acceptsHtml()
                && ! $request->expectsJson() && ! $request->ajax()
                && (in_array($request->header('Sec-Fetch-Dest'), [null, 'document'], true)
                    || ($request->header('Sec-Fetch-Dest') === 'empty' && $request->header('Sec-Fetch-Mode') === 'navigate'))
                && ! str_contains(strtolower($request->header('Purpose', '').' '.$request->header('Sec-Purpose', '')), 'prefetch');
            $previous = $user->ostatnio_widziany_at?->copy();

            if ($request->hasSession()) {
                $session = $request->session();
                $candidate = $session->get(self::PWA_RETURN_CANDIDATE);
                if (! is_array($candidate)
                    || ($candidate['user'] ?? null) !== (string) $user->getKey()
                    || ! is_int($candidate['previous'] ?? null)
                    || ! is_int($candidate['expires'] ?? null)
                    || $candidate['expires'] <= now()->getTimestamp()
                    || $user->pwa_prompt_state !== null) {
                    $session->forget(self::PWA_RETURN_CANDIDATE);
                    $candidate = null;
                }

                if ($navigation) {
                    // Jednorazowy most między prefetch/AJAX a nawigacją.
                    // Nie zmienia globalnego trackera i nie przechodzi na inne konto.
                    if ($candidate !== null) {
                        $previous = CarbonImmutable::createFromTimestampUTC($candidate['previous']);
                    }
                    $session->forget(self::PWA_RETURN_CANDIDATE);
                } elseif ($candidate === null && $user->pwa_prompt_state === null
                    && $previous !== null && $previous->lessThanOrEqualTo(now()->utc()->subHours(24))) {
                    $session->put(self::PWA_RETURN_CANDIDATE, [
                        'user' => (string) $user->getKey(),
                        'previous' => $previous->getTimestamp(),
                        // Ruch w tle nie przedłuża okna w nieskończoność.
                        'expires' => now()->addHour()->getTimestamp(),
                    ]);
                }
            }

            if ($navigation) {
                try {
                    if ($this->installPrompt->qualify($user, $previous)) {
                        $user->setAttribute('pwa_prompt_state', InstallPrompt::ELIGIBLE);
                    }
                } catch (Throwable $e) {
                    Log::warning('Nie udało się zapisać kwalifikacji zachęty instalacji.', ['wyjatek' => $e::class]);
                }
            }

            $this->zanotuj->handle($user);
        } elseif ($request->hasSession()) {
            $request->session()->forget(self::PWA_RETURN_CANDIDATE);
        }

        return $next($request);
    }
}
