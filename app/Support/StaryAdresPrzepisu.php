<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Recipe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Stary adres przepisu (`recipe_slug_redirects`) dla czytających GET-ów.
 *
 * Wzorzec jak na stronie przepisu: NAJPIERW Policy, potem 301 z zachowanym
 * query. Przy odmowie, nieznanym adresie i przepisie usuniętym odpowiedź jest
 * ta sama — 404 bez nagłówka `Location`, bo slug powstaje z tytułu i sam
 * nagłówek zdradziłby przepis niedostępny dla oglądającego.
 *
 * Tylko dla GET-ów: 301 zamienia POST w GET i gubi akcję, więc zapisy idą
 * wyłącznie pod aktualnym adresem.
 */
final class StaryAdresPrzepisu
{
    /**
     * @param  string  $trasa  nazwa trasy z parametrem `recipe`
     * @param  string  $zdolnosc  Policy, którą musi przejść oglądający (`view`, `update`, `cook`)
     */
    public static function przekieruj(Request $request, string $slug, string $trasa, string $zdolnosc): RedirectResponse
    {
        $przekierowanie = DB::table('recipe_slug_redirects')->where('slug', $slug)->first();
        abort_if($przekierowanie === null, 404);

        $cel = Recipe::find($przekierowanie->recipe_id);
        abort_if($cel === null, 404);
        abort_unless(Gate::forUser($request->user())->allows($zdolnosc, $cel), 404);

        return redirect()->route($trasa, ['recipe' => $cel->slug] + $request->query(), 301);
    }
}
