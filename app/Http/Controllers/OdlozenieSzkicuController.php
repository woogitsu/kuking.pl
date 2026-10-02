<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Recipes\Actions\OdlozSzkicPrzepisu;
use App\Models\Recipe;
use App\Support\Komunikat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * „Odłóż na później” i „Wróć do pracy” przy własnym szkicu (#2550, V2).
 *
 * Zwykłe formularze POST/DELETE, bez JavaScriptu. UUID w adresie to nie
 * autoryzacja: wejście idzie przez `RecipePolicy::postpone`, a akcja domenowa
 * jeszcze raz sprawdza konto i autorstwo pod blokadą.
 */
class OdlozenieSzkicuController extends Controller
{
    public function odloz(Request $request, string $szkic, OdlozSzkicPrzepisu $akcja): RedirectResponse
    {
        return $this->zmien($request, $szkic, true, $akcja);
    }

    public function przywroc(Request $request, string $szkic, OdlozSzkicPrzepisu $akcja): RedirectResponse
    {
        return $this->zmien($request, $szkic, false, $akcja);
    }

    private function zmien(Request $request, string $szkic, bool $odloz, OdlozSzkicPrzepisu $akcja): RedirectResponse
    {
        $przepis = Recipe::query()->findOrFail($szkic);
        $this->authorize('postpone', $przepis);

        $dane = $request->validate([
            'stan' => ['nullable', 'string', 'max:40'],
            'dokad' => ['nullable', 'in:lista,kreator'],
        ], [
            'stan.*' => 'Nie wiemy, co zrobić z tym szkicem. Odśwież stronę i spróbuj jeszcze raz.',
            'dokad.in' => 'Nie wiemy, co zrobić z tym szkicem. Odśwież stronę i spróbuj jeszcze raz.',
        ]);

        $wynik = $akcja->handle($request->user(), (string) $przepis->getKey(), $odloz, $dane['stan'] ?? null);

        $komunikat = match ($wynik) {
            OdlozSzkicPrzepisu::ZASTOSOWANO => Komunikat::sukces($odloz
                ? 'Szkic odłożony na później. Nic z niego nie zniknęło — znajdziesz go w „Odłożone na później”.'
                : 'Szkic wrócił do bieżących. Możesz go dokończyć.'),
            OdlozSzkicPrzepisu::JUZ_TAK_BYLO => Komunikat::informacja($odloz
                ? 'Ten szkic już jest odłożony na później.'
                : 'Ten szkic już jest wśród bieżących.'),
            OdlozSzkicPrzepisu::KONFLIKT => Komunikat::blad('Stan tego szkicu zmienił się w innym oknie. Sprawdź go poniżej i w razie potrzeby kliknij jeszcze raz.'),
            OdlozSzkicPrzepisu::NIE_SZKIC => Komunikat::blad('Ten przepis nie jest już szkicem do dokończenia, więc nie ma czego odkładać.'),
            default => Komunikat::blad('Tego szkicu już nie ma. Odśwież stronę.'),
        };

        if (($dane['dokad'] ?? 'lista') === 'kreator' && $wynik !== OdlozSzkicPrzepisu::BRAK && $wynik !== OdlozSzkicPrzepisu::NIE_SZKIC) {
            return redirect()->route('recipes.create', ['szkic' => $przepis->getKey()])->with($komunikat);
        }

        // Nieudane „Wróć do pracy” zostaje na liście odłożonych, gdzie człowiek kliknął.
        $odlozoneNaLiscie = ! $odloz && $wynik !== OdlozSzkicPrzepisu::ZASTOSOWANO;

        return redirect()
            ->route('recipes.drafts', $odlozoneNaLiscie ? ['odlozone' => 1] : [])
            ->with($komunikat);
    }
}
