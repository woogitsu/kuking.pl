<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Pantry\CoMamWDomu;
use App\Domain\Pantry\CoUgotuje;
use App\Domain\Pantry\PodpowiedziSkladnikow;
use App\Models\PantryItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Co mam w domu” i „Co ugotuję z tego, co mam” (V2, D-285).
 *
 * Kontroler jest cienki: reguły listy żyją w `CoMamWDomu`, dobór przepisów
 * w `CoUgotuje`. Lista jest prywatna — każda trasa pracuje na liście
 * zalogowanej osoby, a usunięcie produktu po identyfikatorze przechodzi
 * przez `PantryItemPolicy`.
 */
class PantryController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('pages.pantry.index', [
            'produkty' => $user->pantryItems()->get(),
            'maksProduktow' => CoMamWDomu::MAKS_PRODUKTOW,
        ]);
    }

    public function store(Request $request, CoMamWDomu $lista): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $dane = $request->validate(
            ['nazwa' => ['required', 'string', 'max:'.CoMamWDomu::MAKS_ZNAKOW]],
            [
                'nazwa.required' => 'Wpisz nazwę produktu, na przykład „mąka” albo „jajka”.',
                'nazwa.string' => 'Wpisz nazwę produktu, na przykład „mąka” albo „jajka”.',
                'nazwa.max' => 'Skróć nazwę produktu do :max znaków. Wystarczy samo „mąka” albo „ser żółty”.',
            ],
        );

        $wynik = $lista->dodaj($user, (string) $dane['nazwa']);
        $nazwa = $wynik['produkt']->name;

        return redirect()->route('pantry.index')->with(
            'status',
            $wynik['nowy']
                ? "Dodano „{$nazwa}” do listy."
                : "„{$nazwa}” już jest na Twojej liście.",
        );
    }

    public function destroy(Request $request, PantryItem $pantryItem): RedirectResponse
    {
        $this->authorize('delete', $pantryItem);

        $nazwa = $pantryItem->name;
        $pantryItem->delete();

        return redirect()->route('pantry.index')->with('status', "Usunięto „{$nazwa}” z listy.");
    }

    public function podpowiedzi(Request $request, PodpowiedziSkladnikow $podpowiedzi): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $dane = $request->validate([
            'q' => ['required', 'string', 'min:'.PodpowiedziSkladnikow::MIN_ZNAKOW, 'max:'.PodpowiedziSkladnikow::MAKS_ZNAKOW],
        ]);

        return response()->json(['podpowiedzi' => $podpowiedzi->dla((string) $dane['q'], $user)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function coUgotuje(Request $request, CoUgotuje $dobor): View
    {
        /** @var User $user */
        $user = $request->user();

        $od = max(0, min(10_000, (int) $request->query('od', '0')));
        $wynik = $dobor->dla($user, $od);

        return view('pages.pantry.co-ugotuje', [
            ...$wynik,
            'od' => $od,
            'nastepne' => $od + CoUgotuje::NA_STRONE,
            'regula' => CoUgotuje::REGULA,
        ]);
    }
}
