<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Import\BudzetAi;
use App\Domain\Import\KlientLuna;
use App\Domain\Import\LimitImportowOsoby;
use App\Domain\Import\ZlecImportPrzepisu;
use App\Domain\Zgody\PrzestawZgodeNaOdczytAi;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\ImportPrzepisu;
use App\Rules\ObslugiwaneZdjecie;
use App\Support\LimityZdjec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Import przepisu — ekran wyboru źródła, odczyt zdjęcia kartki, postęp (V2, D-298).
 *
 * Cienki: walidacja → akcja domenowa → widok. Reguły (zgoda, limit, budżet,
 * „zdjęcie zapisane przed wszystkim”) mieszkają w `App\Domain\Import`.
 */
class ImportPrzepisuController extends Controller
{
    /**
     * Cztery duże przyciski: kartka, adres strony, PDF, „Wpiszę sam”.
     * Wyłączone źródło = brak przycisku (D-053). „Wpiszę sam” jest zawsze.
     */
    public function wybor(): View
    {
        return view('pages.import.wybor', [
            'zdjecie' => ZlecImportPrzepisu::dostepnyOdczytZdjecia(),
            'url' => (bool) config('kuking.import.zrodla.url') && Route::has('import.url.create'),
            'pdf' => (bool) config('kuking.import.zrodla.pdf') && Route::has('import.pdf.create'),
        ]);
    }

    public function zdjecie(Request $request, PrzestawZgodeNaOdczytAi $zgoda, LimitImportowOsoby $limit, BudzetAi $budzet): View|RedirectResponse
    {
        if (! ZlecImportPrzepisu::dostepnyOdczytZdjecia()) {
            return redirect()->route('recipes.create')
                ->with('status', 'Odczytywanie przepisów ze zdjęć jest teraz wyłączone. Możesz wpisać przepis ręcznie i dodać do niego zdjęcie kartki.');
        }

        $osoba = $request->user();

        if (! $zgoda->udzielona($osoba)) {
            return view('pages.import.zgoda');
        }

        $brakBudzetu = $budzet->brakMiejscaNa(BudzetAi::szacunek(KlientLuna::ZADANIE_OCR) ?? 0);

        return view('pages.import.zdjecie', [
            'kluczWyslania' => $this->kluczDlaFormularza(),
            'limitOsoby' => $limit->przekroczony($osoba),
            'brakBudzetu' => $brakBudzetu,
            'naDzien' => (int) config('kuking.import.limity.na_osobe_dzien'),
        ]);
    }

    public function zlec(Request $request, ZlecImportPrzepisu $zlec): RedirectResponse
    {
        $request->validate([
            'zdjecie' => ['required', 'file', new ObslugiwaneZdjecie, 'max:'.LimityZdjec::maksKilobajtowDoWalidacji()],
        ], [
            'zdjecie.required' => 'Wybierz zdjęcie kartki albo strony zeszytu. Połóż kartkę na stole przy oknie i zrób zdjęcie z góry.',
            'zdjecie.file' => 'Nie udało się odczytać pliku. Wybierz zdjęcie jeszcze raz.',
            'zdjecie.max' => 'To zdjęcie waży za dużo. Maksymalny rozmiar to '.LimityZdjec::maksMegabajtowDoKomunikatu().' MB — wybierz mniejsze zdjęcie.',
        ]);

        $klucz = $request->input('klucz_wyslania');

        try {
            $zlecenie = $zlec->handle(
                $request->user(),
                $request->file('zdjecie'),
                is_string($klucz) && Str::isUuid($klucz) ? $klucz : null,
            );
        } catch (BladDlaCzlowieka $e) {
            return back()->withErrors(['zdjecie' => $e->getMessage()]);
        }

        return redirect()->route('import.show', $zlecenie);
    }

    /**
     * Postęp słowami. `?fragment=1` oddaje sam blok postępu — czyta go mały
     * skrypt co 5 s. Bez skryptu działa odnośnik „Sprawdź, czy już gotowe”.
     */
    public function show(Request $request, ImportPrzepisu $import): View|Response
    {
        $this->authorize('view', $import);

        $dane = ['import' => $import, 'szkic' => $import->recipe];

        if ($request->boolean('fragment')) {
            return response()->view('pages.import.partials.postep', $dane)
                ->header('Cache-Control', 'no-store');
        }

        return view('pages.import.show', $dane);
    }

    public function ponow(Request $request, ImportPrzepisu $import, ZlecImportPrzepisu $zlec): RedirectResponse
    {
        $this->authorize('update', $import);

        try {
            $nowe = $zlec->ponow($request->user(), $import);
        } catch (BladDlaCzlowieka $e) {
            return redirect()->route('import.show', $import)->withErrors(['ponow' => $e->getMessage()]);
        }

        return redirect()->route('import.show', $nowe);
    }

    /** Ta sama zasada co w `RecipeController::kluczDlaFormularza()`. */
    private function kluczDlaFormularza(): ?string
    {
        if (! (bool) config('kuking.formularze.klucz_wyslania_wlaczony')) {
            return null;
        }

        $stary = old('klucz_wyslania');

        return is_string($stary) && Str::isUuid($stary) ? $stary : (string) Str::uuid7();
    }
}
