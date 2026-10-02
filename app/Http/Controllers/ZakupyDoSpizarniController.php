<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Pantry\CoMamWDomu;
use App\Domain\Pantry\DodajKupioneDoSpizarni;
use App\Domain\Zakupy\ListaZakupow;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Support\Komunikat;
use App\Support\Odmiana;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * „Dodaj kupione do »Co mam w domu«" (V2, #2481): wybrane odhaczone pozycje
 * własnej listy zakupów, z nazwą produktu poprawianą przez człowieka.
 * Zwykły formularz, bez JavaScriptu; samo odhaczenie niczego w spiżarni
 * nie zmienia. Reguły: `DodajKupioneDoSpizarni`.
 */
class ZakupyDoSpizarniController extends Controller
{
    public function form(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $odhaczone = ShoppingListItem::query()
            ->where('user_id', $user->getKey())
            ->whereNotNull('checked_at')
            ->orderBy('position')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'text']);

        if ($odhaczone->isEmpty()) {
            return redirect()->route('shopping.index')->with(Komunikat::informacja(
                'Nie ma odhaczonych pozycji. Odhacz to, co kupiono, a potem dodaj wybrane do „Co mam w domu”.',
            ));
        }

        return view('pages.zakupy.do-spizarni', [
            'pozycje' => $odhaczone,
            'wSpizarni' => $user->pantryItems()->count(),
            'maksProduktow' => CoMamWDomu::MAKS_PRODUKTOW,
            'maksZnakow' => CoMamWDomu::MAKS_ZNAKOW,
        ]);
    }

    public function store(Request $request, DodajKupioneDoSpizarni $dodaj): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $dane = $request->validate([
            'pozycje' => ['required', 'array', 'min:1', 'max:'.ListaZakupow::maksPozycji()],
            'pozycje.*' => ['uuid'],
            'nazwy' => ['nullable', 'array'],
            'nazwy.*' => ['nullable', 'string', 'max:500'],
        ], [
            'pozycje.required' => 'Zaznacz przynajmniej jedną pozycję, którą chcesz dodać do „Co mam w domu”.',
            'pozycje.min' => 'Zaznacz przynajmniej jedną pozycję, którą chcesz dodać do „Co mam w domu”.',
            'pozycje.*.uuid' => 'Lista zmieniła się od otwarcia tego ekranu. Otwórz go jeszcze raz.',
            'nazwy.*.string' => 'Wpisz nazwę produktu słowami, na przykład „mąka” albo „jajka”.',
            'nazwy.*.max' => 'Skróć nazwę produktu do '.CoMamWDomu::MAKS_ZNAKOW.' znaków. Wystarczy samo „mąka” albo „ser żółty”.',
        ]);

        $nazwy = [];
        foreach (array_values(array_unique($dane['pozycje'])) as $id) {
            $nazwy[(string) $id] = (string) ($dane['nazwy'][$id] ?? '');
        }

        try {
            $wynik = $dodaj->handle($user, $nazwy);
        } catch (ValidationException $e) {
            return redirect()->route('shopping.pantry.form')
                ->withErrors($e->errors())
                ->withInput($request->only('pozycje', 'nazwy'));
        }

        $zdania = [];
        if ($wynik['dodane'] !== []) {
            $zdania[] = 'Dodane do „Co mam w domu”: '.$this->lista($wynik['dodane']).'.';
        } else {
            $zdania[] = 'Nic nowego nie zostało dodane do „Co mam w domu”.';
        }
        if ($wynik['juz_byly'] !== []) {
            $zdania[] = 'Już były na liście i zostały bez zmian: '.$this->lista($wynik['juz_byly']).'.';
        }

        return redirect()->route('pantry.index')
            ->with($wynik['dodane'] !== [] ? Komunikat::sukces(implode(' ', $zdania)) : Komunikat::informacja(implode(' ', $zdania)));
    }

    /** @param  list<string>  $nazwy */
    private function lista(array $nazwy): string
    {
        $pokaz = array_slice($nazwy, 0, 8);
        $reszta = count($nazwy) - count($pokaz);

        return implode(', ', $pokaz).($reszta > 0 ? ' i jeszcze '.$reszta.' '.Odmiana::rzeczownik($reszta, 'produkt', 'produkty', 'produktów') : '');
    }
}
