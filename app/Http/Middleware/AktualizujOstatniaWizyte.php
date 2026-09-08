<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Analytics\ZanotujOstatniaWizyte;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
    public function __construct(private readonly ZanotujOstatniaWizyte $zanotuj) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->zanotuj->handle($user);
        }

        return $next($request);
    }
}
