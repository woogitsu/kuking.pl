<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Recipes\OdzyskajUsunietyPrzepis;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Recipe;
use App\Support\Komunikat;
use App\Support\Odmiana;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Usunięte przepisy" — własny, omyłkowo usunięty przepis można odzyskać do
 * końca retencji (#2620, D-333). Reguły (kto, kiedy, do jakiego stanu, wyścigi)
 * żyją w `OdzyskajUsunietyPrzepis`; kontroler pyta Policy i opowiada wynik.
 *
 * Lista nie ma identyfikatora w adresie: należy do zalogowanej osoby.
 * Odzyskanie bierze UUID z adresu, ale NIE jest to autoryzacja — wejście
 * idzie przez `RecipePolicy::odzyskaj()`, a akcja sprawdza własność jeszcze
 * raz pod blokadą.
 */
class UsunietePrzepisyController extends Controller
{
    public function index(Request $request, OdzyskajUsunietyPrzepis $odzyskaj): View
    {
        $this->authorize('odzyskajListe', Recipe::class);

        return view('pages.collections.usuniete-przepisy', [
            'przepisy' => $odzyskaj->dlaEkranu($request->user()),
            'dni' => OdzyskajUsunietyPrzepis::dniOkna(),
        ]);
    }

    public function odzyskaj(Request $request, string $usuniety, OdzyskajUsunietyPrzepis $odzyskaj): RedirectResponse
    {
        $przepis = Recipe::withTrashed()->findOrFail($usuniety);
        $this->authorize('odzyskaj', $przepis);

        try {
            $wynik = $odzyskaj->handle($request->user(), (string) $przepis->getKey());
        } catch (BladDlaCzlowieka $e) {
            return redirect()->route('collections.deleted-recipes')->with(Komunikat::blad($e->getMessage()));
        }

        $adres = route('recipes.create', ['szkic' => $wynik->przepis->getKey()]);

        if ($wynik->juzOdzyskany) {
            return redirect($adres)->with(Komunikat::informacja(
                'Ten przepis jest już odzyskany — to on. Nic nie zginęło i nic się nie zdublowało.',
            ));
        }

        $tresc = 'Przepis wrócił jako szkic widoczny tylko dla Ciebie. '
            .'Nie trafił do żadnego feedu ani cudzego zeszytu — sprawdź go i opublikuj, kiedy zechcesz.';

        if ($wynik->zdjeciaNieWrocily > 0) {
            $n = $wynik->zdjeciaNieWrocily;
            $tresc .= ' Nie wróciło '.$n.' '.Odmiana::rzeczownik($n, 'zdjęcie', 'zdjęcia', 'zdjęć')
                .' — nie wolno już ich użyć, dodaj je ponownie.';
        } else {
            $tresc .= ' Zdjęcia, które były przy przepisie, są na miejscu.';
        }

        return redirect($adres)->with(Komunikat::sukces($tresc));
    }
}
