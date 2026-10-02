<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Recipes\Porcje\WyborPorcji;
use App\Domain\Recipes\Porcje\ZapamietanePorcje;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Recipe;
use App\Support\Komunikat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * „Zapamiętaj dla mnie N porcji” i „Zapomnij moje ustawienie” (#2602).
 *
 * auth (grupa tras) → Policy `view` na przepisie (UUID/slug w adresie nie jest
 * autoryzacją; preferencja nie otwiera ukrytej treści) → walidacja liczby
 * przez `WyborPorcji` → limit `ustawienia`. Właściciel wiersza to zawsze
 * `$request->user()`, nigdy pole z żądania, więc cudzej preferencji nie da
 * się ani zapisać, ani usunąć.
 */
class PorcjeZapamietaneController extends Controller
{
    public function zapisz(Request $request, Recipe $recipe, ZapamietanePorcje $porcje): RedirectResponse
    {
        $this->authorize('view', $recipe);
        abort_unless($recipe->isPublished(), 404);

        $liczba = $request->input('porcje');

        try {
            $zapisane = $porcje->zapamietaj($request->user(), $recipe, is_string($liczba) || is_int($liczba) || is_float($liczba) ? $liczba : null);
        } catch (BladDlaCzlowieka $e) {
            return redirect()
                ->to(route('recipes.show', $recipe).'#skladniki')
                ->with(Komunikat::blad($e->getMessage()));
        }

        // Adres bez parametru: od teraz to właśnie zapamiętane ustawienie.
        return redirect()
            ->to(route('recipes.show', $recipe).'#skladniki')
            ->with(Komunikat::sukces('Zapamiętaliśmy dla Ciebie '.WyborPorcji::etykieta($zapisane).' przy tym przepisie. Widzisz to tylko Ty, a ustawienie zapomnisz jednym przyciskiem.'));
    }

    public function zapomnij(Request $request, Recipe $recipe, ZapamietanePorcje $porcje): RedirectResponse
    {
        $this->authorize('view', $recipe);

        $bylo = $porcje->zapomnij($request->user(), $recipe);

        return redirect()
            ->to(route('recipes.show', $recipe).'#skladniki')
            ->with($bylo
                ? Komunikat::sukces('Zapomnieliśmy Twoje ustawienie porcji. Ten przepis pokazuje znów ilości autora.')
                : Komunikat::informacja('Przy tym przepisie nie było zapamiętanego ustawienia, więc nic nie zmieniliśmy.'));
    }
}
