<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Rocznice\OdnosnikWypisaniaZUrodzin;
use App\Domain\Zgody\PrzestawZgodeNaZyczeniaMailem;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Wypisanie z listu z życzeniami urodzinowymi (issue #1755, D-269).
 *
 * Bez logowania — autoryzacją jest podpis (`middleware('signed')`), nie
 * identyfikator w adresie.
 *
 * GET NICZEGO NIE ZMIENIA (decyzja właściciela z 25.09.2026, wzorem #1403).
 * Skanery odnośników w poczcie firmowej i podglądy linków otwierają adresy
 * z listów same — wejście na adres nie jest decyzją człowieka. GET pokazuje
 * pytanie z przyciskiem, zgodę wycofuje dopiero POST z tokenem CSRF. Po
 * wypisaniu na tej samej stronie stoi „Jednak chcę" — naprawa pomyłki ma być
 * tak samo krótka jak pomyłka.
 */
class UrodzinyWypiszController extends Controller
{
    public function wypisz(Request $request, User $user, PrzestawZgodeNaZyczeniaMailem $zgoda): View
    {
        if (! $request->isMethod('POST')) {
            return view('pages.urodziny-wypisz', [
                'wypisz' => OdnosnikWypisaniaZUrodzin::dla($user),
            ]);
        }

        $zgoda->handle($user, false, WpisZgody::ZRODLO_LINK_WYPISANIA);

        return view('pages.urodziny-wypisano', [
            'powrot' => OdnosnikWypisaniaZUrodzin::powrotDla($user),
        ]);
    }

    public function wracam(User $user, PrzestawZgodeNaZyczeniaMailem $zgoda): View
    {
        $zgoda->handle($user, true, WpisZgody::ZRODLO_LINK_POWROTNY);

        return view('pages.urodziny-wrocono', [
            'wypisz' => OdnosnikWypisaniaZUrodzin::dla($user),
        ]);
    }
}
