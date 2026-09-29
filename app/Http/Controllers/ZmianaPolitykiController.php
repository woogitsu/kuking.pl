<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Zgody\ZmianaPolityki;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Zamknięcie paska „Zmieniliśmy politykę prywatności” (D-327).
 *
 * Bez identyfikatora w adresie — zapis dotyczy wyłącznie zalogowanej osoby.
 * Powrót na ten sam ekran, bez komunikatu: pasek po prostu znika.
 */
final class ZmianaPolitykiController extends Controller
{
    public function __invoke(Request $request, ZmianaPolityki $zmiana): RedirectResponse
    {
        $zmiana->zamknij($request->user());

        return back();
    }
}
