<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Recipes\OstatnioOgladane;
use App\Http\Controllers\Controller;
use App\Support\Komunikat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ustawienia → „Ostatnio oglądane” (#2553, V2): opcjonalna, prywatna lista
 * przepisów, do których można wrócić. Reguły (co zapamiętujemy, limit, czas
 * życia, widoczność przy pokazaniu) żyją w `App\Domain\Recipes\OstatnioOgladane`.
 *
 * ZWYKŁE FORMULARZE POST, BEZ JAVASCRIPTU. „Włącz”, „Wyłącz i usuń historię”
 * i „Wyczyść” to trzy osobne przyciski; żaden z nich nie wymaga skryptu.
 * Wyłączenie i czyszczenie działają od razu — historia znika w tej samej
 * transakcji, bez „może jutro”.
 *
 * AUTORYZACJA. Trasy siedzą w grupie `auth`, nie przyjmują żadnego
 * identyfikatora, a właściciel danych zawsze pochodzi z sesji (`$request->user()`):
 * nie ma czyjegoś zasobu do podmiany w adresie, więc nie ma czego przepuszczać
 * przez Policy. Ekran ma nagłówek `private, no-store` z middleware (zalogowany).
 * Lista pokazuje przepisy dopiero po ponownej kontroli widoczności (domena).
 */
class OstatnioOgladaneController extends Controller
{
    public function edit(Request $request, OstatnioOgladane $ostatnie): View
    {
        $osoba = $request->user();

        return view('pages.settings.ostatnio-ogladane', [
            'wlaczone' => $osoba->maWlaczoneOstatnioOgladane(),
            'pozycje' => $ostatnie->lista($osoba),
            'limit' => $ostatnie->limit(),
            'dni' => $ostatnie->dni(),
        ]);
    }

    public function wlacz(Request $request, OstatnioOgladane $ostatnie): RedirectResponse
    {
        $osoba = $request->user();

        if ($osoba->maWlaczoneOstatnioOgladane()) {
            return redirect()->route('settings.ogladane')
                ->with(Komunikat::informacja('Lista ostatnio oglądanych przepisów jest już włączona. Nic nie trzeba robić.'));
        }

        $ostatnie->wlacz($osoba);

        return redirect()->route('settings.ogladane')
            ->with(Komunikat::sukces('Lista włączona. Od tej chwili zapamiętamy przepisy, które otworzysz.'));
    }

    public function wylacz(Request $request, OstatnioOgladane $ostatnie): RedirectResponse
    {
        $osoba = $request->user();

        if (! $osoba->maWlaczoneOstatnioOgladane()) {
            // Mimo wszystko kasujemy to, co mogło zostać: wyłączenie ma zostawić pusto.
            $ostatnie->wyczysc($osoba);

            return redirect()->route('settings.ogladane')
                ->with(Komunikat::informacja('Lista była już wyłączona. Nic nie jest zapamiętywane.'));
        }

        $ostatnie->wylacz($osoba);

        return redirect()->route('settings.ogladane')
            ->with(Komunikat::sukces('Lista wyłączona, a zapamiętane przepisy usunięte.'));
    }

    public function wyczysc(Request $request, OstatnioOgladane $ostatnie): RedirectResponse
    {
        $ile = $ostatnie->wyczysc($request->user());

        return redirect()->route('settings.ogladane')->with($ile > 0
            ? Komunikat::sukces('Lista wyczyszczona. Zapamiętywanie działa dalej.')
            : Komunikat::informacja('Lista była już pusta. Nic nie trzeba robić.'));
    }
}
