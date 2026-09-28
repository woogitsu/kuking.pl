<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Zgody\PrzestawZgodeNaOdczytAi;
use App\Models\WpisZgody;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Udzielenie i wycofanie zgody „odczyt AI” (D-296). Reguła i dowód:
 * `App\Domain\Zgody\PrzestawZgodeNaOdczytAi`.
 *
 * WYCOFANIE MA OSOBNĄ TRASĘ i jest dozwolone także podczas zawieszenia
 * konta (`EnsureAccountIsActive`): RODO art. 7 ust. 3 — wycofanie ma być
 * tak łatwe jak udzielenie, a zawieszenie jest karą za pisanie, nie za
 * korzystanie z prawa.
 */
class ZgodaOdczytuAiController extends Controller
{
    public function udziel(Request $request, PrzestawZgodeNaOdczytAi $zgoda): RedirectResponse
    {
        $zEkranuImportu = $request->input('skad') === 'import';

        $zgoda->handle(
            $request->user(),
            true,
            $zEkranuImportu ? WpisZgody::ZRODLO_EKRAN_IMPORTU : WpisZgody::ZRODLO_USTAWIENIA,
        );

        return $zEkranuImportu
            ? redirect()->route('import.zdjecie')
            : redirect()->route('settings.privacy')->with('status', 'Zgoda zapisana. Zdjęcia kartek, które dodasz do odczytu, przeczyta komputer firmy OpenAI.');
    }

    public function wycofaj(Request $request, PrzestawZgodeNaOdczytAi $zgoda): RedirectResponse
    {
        $zgoda->handle($request->user(), false, WpisZgody::ZRODLO_USTAWIENIA);

        return redirect()->route('settings.privacy')
            ->with('status', 'Zgoda wycofana. Zdjęcia kartek dalej możesz dodawać do przepisów — tekst wpiszesz wtedy ręcznie.');
    }
}
