<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Rozmiar tekstu i wygoda czytania.
 *
 * Ustawienie leży na KONCIE, nie w ciasteczku. Osoba, która raz z trudem
 * powiększyła tekst, nie może go stracić po zmianie przeglądarki albo po
 * wyczyszczeniu danych — to jest dla niej powrót do stanu "nie da się czytać".
 */
class AccessibilitySettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('pages.settings.accessibility', [
            'scales' => config('kuking.text.scales'),
            'current' => $request->user()->text_scale,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'text_scale' => ['required', 'integer', Rule::in(config('kuking.text.scales'))],
        ], [
            'text_scale.required' => 'Wybierz rozmiar tekstu.',
            'text_scale.in' => 'Wybierz jeden z dostępnych rozmiarów tekstu.',
        ]);

        $request->user()->update(['text_scale' => $data['text_scale']]);

        return back()->with('status', 'Rozmiar tekstu zapisany. Będzie taki na każdym urządzeniu, na którym się zalogujesz.');
    }
}
