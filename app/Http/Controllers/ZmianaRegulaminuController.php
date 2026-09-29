<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Zgody\ZmianaRegulaminu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Zamknięcie paska „Zmieniliśmy regulamin" (#1811, D-306).
 *
 * Bez identyfikatora w adresie — zapis dotyczy wyłącznie zalogowanej osoby,
 * więc nie ma cudzego zasobu do sprawdzenia w Policy. Powrót na ten sam ekran,
 * bez komunikatu: pasek po prostu znika.
 */
final class ZmianaRegulaminuController extends Controller
{
    public function __invoke(Request $request, ZmianaRegulaminu $zmiana): RedirectResponse
    {
        $zmiana->zamknij($request->user());

        return back();
    }
}
