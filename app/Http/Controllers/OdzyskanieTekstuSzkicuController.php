<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Recipes\Odzyskiwanie\PunktOdzyskaniaSzkicu;
use App\Models\Recipe;
use App\Support\Komunikat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Odzyskanie wcześniejszego tekstu własnego szkicu (#2512, V2).
 *
 * Dwa kroki bez JavaScriptu: podgląd różnicy (GET, niczego nie zmienia) i
 * przywrócenie po osobnym potwierdzeniu (POST). UUID w adresie to nie
 * autoryzacja: wejście idzie przez `RecipePolicy::restoreDraftText`, a akcja
 * domenowa jeszcze raz sprawdza konto, autorstwo, rewizję treści i znacznik
 * kopii.
 */
class OdzyskanieTekstuSzkicuController extends Controller
{
    public function pokaz(Request $request, string $szkic, PunktOdzyskaniaSzkicu $punkty): View|RedirectResponse
    {
        $przepis = Recipe::query()->findOrFail($szkic);
        $this->authorize('restoreDraftText', $przepis);

        $podglad = $punkty->podglad($przepis);

        if ($podglad === null) {
            return redirect()
                ->route('recipes.create', ['szkic' => $przepis->getKey()])
                ->with(Komunikat::informacja('Nie mamy już wcześniejszej kopii tekstu tego szkicu. Kopia jest przechowywana krótko i powstaje, gdy otwierasz szkic do pisania.'));
        }

        return view('pages.recipes.odzyskaj-tekst', ['szkic' => $przepis] + $podglad);
    }

    public function przywroc(Request $request, string $szkic, PunktOdzyskaniaSzkicu $punkty): RedirectResponse
    {
        $przepis = Recipe::query()->findOrFail($szkic);
        $this->authorize('restoreDraftText', $przepis);

        $komunikatBledu = 'Nie wiemy, którą kopię przywrócić. Otwórz podgląd kopii jeszcze raz.';
        $dane = $request->validate([
            'rewizja' => ['required', 'integer', 'min:0'],
            'znacznik' => ['required', 'string', 'max:40'],
        ], [
            'rewizja.*' => $komunikatBledu,
            'znacznik.*' => $komunikatBledu,
        ]);

        $wynik = $punkty->przywroc($request->user(), (string) $przepis->getKey(), (int) $dane['rewizja'], (string) $dane['znacznik']);

        $doPodgladu = redirect()->route('recipes.drafts.restore.show', $przepis->getKey());
        $doKreatora = redirect()->route('recipes.create', ['szkic' => $przepis->getKey()]);

        return match ($wynik->status) {
            PunktOdzyskaniaSzkicu::ODZYSKANO => $doKreatora->with(Komunikat::sukces(
                'Przywróciliśmy wcześniejszy tekst szkicu. Nic nie zostało opublikowane. Tekst, który został zastąpiony, zostawiliśmy jako kopię — jeśli to nie to, wróć tu i przywróć go z powrotem.',
            )),
            PunktOdzyskaniaSzkicu::BEZ_ZMIAN => $doKreatora->with(Komunikat::informacja('Kopia jest taka sama jak bieżący tekst, więc nic nie zmieniliśmy.')),
            PunktOdzyskaniaSzkicu::KONFLIKT => $doPodgladu->with(Komunikat::blad(
                'Szkic albo kopia zmieniły się od otwarcia podglądu — na przykład w innej karcie. Niczego nie przywróciliśmy ani nie nadpisaliśmy. Sprawdź różnice poniżej i kliknij jeszcze raz.',
            )),
            PunktOdzyskaniaSzkicu::ZDJECIA => $doPodgladu->with(Komunikat::blad(
                'W szkicu jest zdjęcie kroku, którego nie ma w kopii, a przywrócenie musiałoby je odpiąć. Niczego nie zmieniliśmy. Usuń to zdjęcie w kreatorze, jeśli chcesz wrócić do kopii, albo zostań przy bieżącym tekście.',
            )),
            PunktOdzyskaniaSzkicu::BLAD => $doPodgladu->with(Komunikat::blad(
                ($wynik->komunikat ?? 'Nie udało się przywrócić tekstu.').' Bieżący tekst szkicu został bez zmian.',
            )),
            PunktOdzyskaniaSzkicu::NIE_SZKIC => redirect()->route('recipes.drafts')->with(Komunikat::blad('Ten przepis nie jest już szkicem, więc nie ma czego przywracać.')),
            default => $doKreatora->with(Komunikat::informacja('Nie mamy już wcześniejszej kopii tekstu tego szkicu.')),
        };
    }
}
