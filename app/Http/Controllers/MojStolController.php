<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Feed\MojStol;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Mój stół" (issue #1749, D-304) — strona półki i jej wyłącznik.
 *
 * Adres nie niesie identyfikatora — półka jest zawsze widza, więc nie ma
 * cudzej treści do autoryzowania na wejściu. Każdy wpis na półce przechodzi
 * bramki widoczności w `MojStol::bramki()`.
 */
final class MojStolController extends Controller
{
    public function __construct(private readonly MojStol $mojStol) {}

    public function pokaz(Request $request): View
    {
        $widz = $request->user();

        return view('pages.moj-stol', [
            'wlaczony' => (bool) $widz->moj_stol_enabled,
            // Wyłączona półka nie liczy NICZEGO — nawet w tle.
            'polka' => $widz->moj_stol_enabled ? $this->mojStol->dlaWidza($widz) : null,
            'dlaczego' => MojStol::DLACZEGO,
        ]);
    }

    public function ustaw(Request $request): RedirectResponse
    {
        $dane = $request->validate([
            'wlaczony' => ['required', 'boolean'],
        ], [
            'wlaczony.required' => 'Wybierz, czy chcesz włączyć Mój stół, i kliknij przycisk jeszcze raz.',
            'wlaczony.boolean' => 'Wybierz, czy chcesz włączyć Mój stół, i kliknij przycisk jeszcze raz.',
        ]);

        $wlacz = filter_var($dane['wlaczony'], FILTER_VALIDATE_BOOLEAN);
        $request->user()->update(['moj_stol_enabled' => $wlacz]);

        return redirect()->route('moj-stol')->with('status', $wlacz
            ? 'Mój stół jest włączony. Widzisz go tutaj i na Starcie.'
            : 'Mój stół jest wyłączony. Nie pokazujemy Ci żadnych propozycji.');
    }
}
