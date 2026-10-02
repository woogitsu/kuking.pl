<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Collections\Actions\PrzesunPrzepisWZeszycie;
use App\Domain\Collections\Actions\PrzywrocKolejnoscZapisu;
use App\Domain\Collections\KierunekPrzesuniecia;
use App\Domain\Collections\KonfliktKolejnosci;
use App\Models\Collection;
use App\Support\Komunikat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ręczna kolejność przepisów w zeszycie (#2544): zwykłe POST-y z przycisków
 * „Wyżej", „Niżej", „Na początek", „Na koniec" i „Wróć do kolejności zapisu".
 * Bez JavaScriptu, bez przeciągania.
 *
 * Kontroler jest cienki: Policy `reorder` (tylko właściciel własnego,
 * prywatnego zeszytu bez zaproszonych osób), walidacja kształtu żądania
 * i tłumaczenie wyniku na zdanie. Zamek, ponowne sprawdzenie właściciela
 * i ochrona przed starą kartą — w akcjach domenowych.
 *
 * Po każdej odpowiedzi człowiek wraca do trybu układania na stronie, na której
 * przepis stoi TERAZ (po ruchu przez granicę strony przepis „jedzie" razem
 * z nim), z kotwicą na jego karcie.
 */
class CollectionRecipeOrderController extends Controller
{
    public const POLE_ODCISKU = 'uklad';

    public function przesun(Request $request, Collection $collection, string $pozycja, PrzesunPrzepisWZeszycie $akcja): RedirectResponse
    {
        $this->authorize('reorder', $collection);
        abort_unless(Str::isUuid($pozycja), 404);

        $dane = $request->validate([
            'kierunek' => ['required', 'string', 'in:'.implode(',', array_column(KierunekPrzesuniecia::cases(), 'value'))],
            self::POLE_ODCISKU => ['nullable', 'string', 'max:128'],
            'strona' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ], [
            'kierunek.required' => 'Wybierz, dokąd przesunąć przepis: wyżej, niżej, na początek albo na koniec.',
            'kierunek.in' => 'Wybierz, dokąd przesunąć przepis: wyżej, niżej, na początek albo na koniec.',
        ]);

        $kierunek = KierunekPrzesuniecia::from($dane['kierunek']);

        try {
            $wynik = $akcja->handle($request->user(), $collection, $pozycja, $kierunek, $dane[self::POLE_ODCISKU] ?? null);
        } catch (KonfliktKolejnosci) {
            return $this->wroc($collection, isset($dane['strona']) ? (int) $dane['strona'] : null, null)
                ->with(Komunikat::blad(
                    'Kolejność w tym zeszycie zmieniła się, zanim kliknięto przycisk — w innym oknie albo przy poprzednim kliknięciu. '
                    .'Nic nie zostało przesunięte. Sprawdź układ poniżej i kliknij jeszcze raz.',
                ));
        }

        $komunikat = $wynik->zmieniono
            ? 'Przepis „'.$wynik->tytul.'” jest teraz na miejscu '.$wynik->pozycja.' z '.$wynik->ile.'.'
            : 'Przepis „'.$wynik->tytul.'” już tam stoi — miejsce '.$wynik->pozycja.' z '.$wynik->ile.'. Nic nie zmieniono.';

        return $this->wroc($collection, $wynik->strona(), $pozycja)
            ->with($wynik->zmieniono ? Komunikat::sukces($komunikat) : Komunikat::informacja($komunikat));
    }

    public function przywroc(Request $request, Collection $collection, PrzywrocKolejnoscZapisu $akcja): RedirectResponse
    {
        $this->authorize('reorder', $collection);

        $dane = $request->validate([
            self::POLE_ODCISKU => ['nullable', 'string', 'max:128'],
        ]);

        try {
            $akcja->handle($request->user(), $collection, $dane[self::POLE_ODCISKU] ?? null);
        } catch (KonfliktKolejnosci) {
            return $this->wroc($collection, null, null)
                ->with(Komunikat::blad(
                    'Kolejność w tym zeszycie zmieniła się, zanim potwierdzono powrót. Nic nie zostało zmienione. '
                    .'Sprawdź układ poniżej i, jeśli nadal chcesz wrócić do kolejności zapisu, kliknij jeszcze raz.',
                ));
        }

        return redirect()->route('collections.show', $collection)
            ->with(Komunikat::sukces('Przepisy w tym zeszycie są znów w kolejności zapisu, od najnowszego. Same przepisy i notatki zostały bez zmian.'));
    }

    private function wroc(Collection $collection, ?int $strona, ?string $kotwica): RedirectResponse
    {
        $parametry = ['collection' => $collection, 'uloz' => 1];
        if ($strona !== null && $strona > 1) {
            $parametry['page'] = $strona;
        }

        $adres = route('collections.show', $parametry);

        return redirect()->to($kotwica === null ? $adres : $adres.'#przepis-'.$kotwica);
    }
}
