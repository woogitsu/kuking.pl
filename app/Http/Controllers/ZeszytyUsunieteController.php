<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\Odzyskiwanie\OdzyskajUsunietyZeszyt;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\DeletedCollection;
use App\Support\Komunikat;
use App\Support\Odmiana;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Usunięte zeszyty" — własny, omyłkowo usunięty PRYWATNY zeszyt można
 * odzyskać do końca retencji (#2567, D-333). Reguły (kto, kiedy, do jakiego
 * stanu, wyścigi) żyją w `OdzyskajUsunietyZeszyt`; kontroler pyta Policy
 * i opowiada wynik.
 *
 * Lista nie ma identyfikatora w adresie: należy do zalogowanej osoby.
 * Odzyskanie bierze dawny UUID zeszytu z adresu, ale NIE jest to autoryzacja —
 * wejście idzie przez `DeletedCollectionPolicy::odzyskaj()`, a akcja sprawdza
 * własność jeszcze raz pod blokadą.
 */
class ZeszytyUsunieteController extends Controller
{
    public function index(Request $request, OdzyskajUsunietyZeszyt $odzyskaj): View
    {
        $this->authorize('lista', DeletedCollection::class);

        return view('pages.collections.usuniete-zeszyty', [
            'zeszyty' => $odzyskaj->dlaEkranu($request->user()),
            'dni' => OdzyskajUsunietyZeszyt::dniOkna(),
        ]);
    }

    public function odzyskaj(Request $request, string $zeszytUsuniety, OdzyskajUsunietyZeszyt $odzyskaj): RedirectResponse
    {
        $kopia = DeletedCollection::query()->where('collection_id', $zeszytUsuniety)->first();

        if ($kopia !== null) {
            $this->authorize('odzyskaj', $kopia);
        } else {
            // Kopii już nie ma: albo to drugie wysłanie formularza (zeszyt jest
            // już odzyskany), albo termin minął. Czyje to było, rozstrzyga
            // akcja pod blokadą (po własności), więc cudzego nie zdradzamy.
            $this->authorize('lista', DeletedCollection::class);
        }

        try {
            $wynik = $odzyskaj->handle($request->user(), $zeszytUsuniety);
        } catch (BladDlaCzlowieka $e) {
            return redirect()->route('collections.deleted')->with(Komunikat::blad($e->getMessage()));
        }

        if ($wynik->juzOdzyskany) {
            return redirect()->route('collections.show', $wynik->zeszyt)->with(Komunikat::informacja(
                'Ten zeszyt jest już odzyskany — to on. Nic nie zginęło i nic się nie zdublowało.',
            ));
        }

        $tresc = 'Zeszyt „'.$wynik->zeszyt->name.'” wrócił jako prywatny: widzisz go tylko Ty. '
            .'Nikt nie dostał powiadomienia i nic nie zostało opublikowane.';

        if ($wynik->zapisyNieWrocily > 0) {
            $n = $wynik->zapisyNieWrocily;
            $tresc .= ' Nie wróciło '.$n.' '.Odmiana::rzeczownik($n, 'zapis', 'zapisy', 'zapisów')
                .' — przepis albo wpis, do którego należały, został w międzyczasie usunięty.';
        } else {
            $tresc .= ' Wszystkie zapisy z dopiskami i datami są na miejscu.';
        }

        return redirect()->route('collections.show', $wynik->zeszyt)->with(Komunikat::sukces($tresc));
    }
}
