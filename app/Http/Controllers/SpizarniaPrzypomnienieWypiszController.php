<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Pantry\OdnosnikWypisaniaZPrzypomnienia;
use App\Domain\Zgody\PrzestawZgodeNaPrzypomnienieSpizarni;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Wypisanie z sobotniego przypomnienia o produktach do zużycia (#1903, D-333).
 *
 * Bez logowania — autoryzacją jest podpis (`middleware('signed')`), nie
 * identyfikator w adresie. GET NICZEGO NIE ZMIENIA (skanery odnośników
 * w poczcie otwierają adresy z listów same, wzorem #1403 i D-269): pokazuje
 * pytanie z przyciskiem, zgodę wycofuje dopiero POST z tokenem CSRF. Po
 * wypisaniu na tej samej stronie stoi „Jednak chcę" — link ważny 1 godzinę,
 * bo włącza zgodę bez logowania (D-333); wygasły pokazuje stronę z wyjaśnieniem.
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

    public function wracam(Request $request, User $user, PrzestawZgodeNaPrzypomnienieSpizarni $zgoda): View
    {
        // Trasa NIE ma `signed`: wygasły, ale poprawnie podpisany link dostaje
        // po polsku wyjaśnienie, nie gołe 403. Zły podpis to nadal 403.
        if (! URL::hasCorrectSignature($request)) {
            abort(403);
        }

        if (! URL::signatureHasNotExpired($request)) {
            return view('pages.spizarnia-wygaslo');
        }

        // Konto wymazane: nic nie zapisujemy i nie udajemy, że zgoda wróciła.
        abort_if($user->data_erased_at !== null, 404);

        $zgoda->handle($user, true, WpisZgody::ZRODLO_LINK_POWROTNY);

        return view('pages.spizarnia-wrocono', [
            'wypisz' => OdnosnikWypisaniaZPrzypomnienia::dla($user),
        ]);
    }
}
