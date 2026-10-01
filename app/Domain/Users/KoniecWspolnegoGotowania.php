<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Models\User;

/**
 * Koniec wspólnego gotowania przy blokadzie i przy usunięciu konta (#2385).
 *
 * Ten sam wzór co `KoniecWspolnychZeszytow` (D-302, #971): blokadę zakłada
 * moduł `Social`, konto wymazuje `Users`, a sesje należą do `Recipes`.
 * Kontrakt mieszka po stronie wołających, implementacja w
 * `App\Domain\Recipes\Gotowanie\Wspolne\KoniecWspolnegoGotowaniaImpl`, a łączy
 * je `AppServiceProvider`. Kierunek zależności to wtedy wyłącznie
 * `Recipes → Users`; pilnuje tego `GrafModulowDomenyBezCykliTest`.
 */
interface KoniecWspolnegoGotowania
{
    /**
     * Blokada w którąkolwiek stronę: udział pomocnika w sesji drugiej osoby
     * kończy się natychmiast (w obie strony). Wołać pod zamkiem pary kont.
     */
    public function miedzy(User $a, User $b): void;

    /**
     * Usunięcie konta (każdy zakres): sesje tej osoby jako gospodarza znikają,
     * jej udziały jako pomocnika znikają, a podpis przy odhaczonych krokach
     * w cudzych sesjach zostaje pusty.
     */
    public function przyWymazaniu(User $user): void;
}
