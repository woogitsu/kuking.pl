<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Pantry\OdnosnikWypisaniaZPrzypomnienia;
use App\Domain\Zgody\PrzestawZgodeNaPrzypomnienieSpizarni;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Wypisanie z sobotniego przypomnienia o produktach do zużycia (#1903, D-333).
 *
 * Bez logowania — autoryzacją jest podpis (`middleware('signed')`), nie
 * identyfikator w adresie. GET NICZEGO NIE ZMIENIA (skanery odnośników
 * w poczcie otwierają adresy z listów same, wzorem #1403 i D-269): pokazuje
 * pytanie z przyciskiem, zgodę wycofuje dopiero POST z tokenem CSRF. Po
 * wypisaniu na tej samej stronie stoi „Jednak chcę".
 */
class SpizarniaPrzypomnienieWypiszController extends Controller
{
    public function wypisz(Request $request, User $user, PrzestawZgodeNaPrzypomnienieSpizarni $zgoda): View
    {
        if (! $request->isMethod('POST')) {
            return view('pages.spizarnia-wypisz', [
                'wypisz' => OdnosnikWypisaniaZPrzypomnienia::dla($user),
            ]);
        }

        $zgoda->handle($user, false, WpisZgody::ZRODLO_LINK_WYPISANIA);

        return view('pages.spizarnia-wypisano', [
            'powrot' => OdnosnikWypisaniaZPrzypomnienia::powrotDla($user),
        ]);
    }

    public function wracam(User $user, PrzestawZgodeNaPrzypomnienieSpizarni $zgoda): View
    {
        $zgoda->handle($user, true, WpisZgody::ZRODLO_LINK_POWROTNY);

        return view('pages.spizarnia-wrocono', [
            'wypisz' => OdnosnikWypisaniaZPrzypomnienia::dla($user),
        ]);
    }
}
