<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Zgody\InformacjaOdczytuAi;
use App\Domain\Zgody\PrzestawZgodeNaOdczytAi;
use App\Models\WpisZgody;
use App\Support\Komunikat;
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
 *
 * UDZIELENIE TYLKO Z AKTUALNĄ INFORMACJĄ (issue #2033). Formularz zgody jest
 * wyłącznie w komponencie `x-zgoda-odczyt-ai`, który przed przyciskiem
 * pokazuje odbiorcę, zakres i skutek odczytu, i niesie wersję tej
 * informacji. Strona otwarta przed zmianą treści nie zapisuje zgody — wraca
 * do aktualnej informacji z wyjaśnieniem.
 */
class ZgodaOdczytuAiController extends Controller
{
    public function udziel(Request $request, PrzestawZgodeNaOdczytAi $zgoda): RedirectResponse
    {
        $zEkranuImportu = $request->input('skad') === 'import';

        if (! InformacjaOdczytuAi::aktualna($request->input(InformacjaOdczytuAi::POLE))) {
            $powrot = $zEkranuImportu
                ? redirect()->route('import.zdjecie')
                : redirect()->to(route('settings.privacy').'#odczyt-ai');

            return $powrot->withErrors([
                InformacjaOdczytuAi::POLE => 'Informacja o odczycie zmieniła się od chwili, gdy otworzono tę stronę, więc zgody nie zapisaliśmy. Przeczytaj aktualną informację i zdecyduj jeszcze raz.',
            ], InformacjaOdczytuAi::WOREK_BLEDOW);
        }

        $zgoda->handle(
            $request->user(),
            true,
            $zEkranuImportu ? WpisZgody::ZRODLO_EKRAN_IMPORTU : WpisZgody::ZRODLO_USTAWIENIA,
        );

        return $zEkranuImportu
            ? redirect()->route('import.zdjecie')
            : redirect()->route('settings.privacy')->with(Komunikat::sukces('Zgoda zapisana. Zdjęcia kartek, które dodasz do odczytu, przeczyta komputer firmy OpenAI.'));
    }

    public function wycofaj(Request $request, PrzestawZgodeNaOdczytAi $zgoda): RedirectResponse
    {
        $zgoda->handle($request->user(), false, WpisZgody::ZRODLO_USTAWIENIA);

        return redirect()->route('settings.privacy')
            ->with(Komunikat::sukces('Zgoda wycofana. Zdjęcia kartek dalej możesz dodawać do przepisów — tekst wpiszesz wtedy ręcznie.'));
    }
}
