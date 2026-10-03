<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Recipes\Actions\ZrobKopieSzkicu;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Recipe;
use App\Support\Czas;
use App\Support\Komunikat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * „Zrób kopię” własnego szkicu do pracy nad drugim wariantem (#2507, V2, D-333).
 *
 * Dwa kroki bez JavaScriptu: ekran potwierdzenia zakresu (GET, nic nie zapisuje —
 * mówi, co się kopiuje, czego nie i z jakiego zapisanego stanu powstanie kopia)
 * i zwykły POST. UUID w adresie to nie autoryzacja: oba wejścia idą przez
 * `RecipePolicy::copyDraft`, a akcja domenowa sprawdza prawo jeszcze raz pod blokadą.
 */
class KopiaSzkicuController extends Controller
{
    public function potwierdz(Request $request, string $szkic): View
    {
        $przepis = Recipe::query()->findOrFail($szkic);
        $this->authorize('copyDraft', $przepis);

        return view('pages.recipes.kopia-szkicu', [
            'szkic' => $przepis,
            'zapisanoO' => Czas::data($przepis->updated_at, 'j F Y, H:i'),
            'skladnikow' => $przepis->ingredients()->count(),
            'krokow' => $przepis->steps()->count(),
            'maZdjecia' => $przepis->hero_media_id !== null
                || $przepis->source_scan_media_id !== null
                || $przepis->steps()->whereNotNull('media_id')->exists(),
            'jestAdaptacja' => $przepis->forked_at !== null,
            'pochodziZImportu' => ZrobKopieSzkicu::jestPowiazanyZImportem($przepis),
            // Nowy formularz = nowy klucz = nowy wariant; odświeżenie ekranu klucza nie zmienia dopiero po POST.
            'klucz' => old('klucz_kopii') && Str::isUuid((string) old('klucz_kopii')) ? (string) old('klucz_kopii') : (string) Str::uuid7(),
        ]);
    }

    public function zrob(Request $request, string $szkic, ZrobKopieSzkicu $akcja): RedirectResponse
    {
        $przepis = Recipe::query()->findOrFail($szkic);
        $this->authorize('copyDraft', $przepis);

        $dane = $request->validate([
            'klucz_kopii' => ['required', 'uuid'],
        ], [
            'klucz_kopii.*' => 'Otwórz ekran kopiowania jeszcze raz i kliknij „Zrób kopię”. Nic nie zostało zapisane.',
        ]);

        try {
            $kopia = $akcja->handle($request->user(), $przepis, (string) $dane['klucz_kopii'], $request->ip());
        } catch (BladDlaCzlowieka $blad) {
            return redirect()->route('recipes.drafts')->with(Komunikat::blad($blad->getMessage()));
        }

        return redirect()->route('recipes.create', ['szkic' => $kopia->getKey()])->with($kopia->wasRecentlyCreated
            ? Komunikat::sukces('Kopia gotowa: to osobny szkic, widzisz go tylko Ty. Pierwowzór został bez zmian. Zmień w kopii to, co ma być inne.')
            : Komunikat::informacja('Ta kopia już istnieje — to jest ona. Nic nie zostało zdublowane.'));
    }
}
