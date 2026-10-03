<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Recipes\Udostepnienia\OdbierzDostepDoPrzepisu;
use App\Domain\Recipes\Udostepnienia\PotwierdzenieOdbiorcy;
use App\Domain\Recipes\Udostepnienia\UdostepnijPrzepis;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Recipe;
use App\Models\RecipeShare;
use App\Models\User;
use App\Support\Komunikat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Pokaż ten przepis wybranej osobie" — ekran AUTORA (#2650, D-333).
 *
 * Pięć pytań z AGENTS.md §7: `auth` (grupa tras), Policy
 * (`RecipePolicy::manageShares` i `share`), walidacja z polskim zdaniem,
 * limit `udostepnienia`, ślad — w samych wierszach `recipe_shares` (kto,
 * komu, od kiedy). Reguły żyją w `App\Domain\Recipes\Udostepnienia`.
 *
 * DWA KROKI, BEZ JAVASCRIPTU. Pierwszy POST (bez `potwierdzam`) tylko
 * sprawdza nazwę konta i wraca na ekran z odbiorcą i zakresem do
 * potwierdzenia — issue: „przed potwierdzeniem pokazać odbiorcę i zakres".
 * Drugi POST zapisuje i sprawdza wszystko od nowa pod zamkiem.
 */
class RecipeShareController extends Controller
{
    public function index(Request $request, Recipe $recipe): View
    {
        $this->authorize('manageShares', $recipe);

        $kandydat = null;
        $potwierdzenie = $request->session()->get('udostepnij_potwierdzenie');
        $danePotwierdzenia = is_string($potwierdzenie)
            ? PotwierdzenieOdbiorcy::odczytaj($potwierdzenie, $request->user(), $recipe)
            : null;

        if ($danePotwierdzenia !== null && $request->user()->can('share', $recipe)) {
            try {
                $kandydat = app(UdostepnijPrzepis::class)->odbiorca($request->user(), $recipe, $danePotwierdzenia['nazwa']);
                if ((string) $kandydat->getKey() !== $danePotwierdzenia['odbiorca']) {
                    $kandydat = null;
                }
            } catch (BladDlaCzlowieka) {
                // Stan zmienił się między krokami (blokada, kara) — formularz
                // zostaje zwykłym pierwszym krokiem, bez potwierdzenia.
                $kandydat = null;
            }
        }

        return view('pages.recipes.udostepnij', [
            'recipe' => $recipe,
            'mozeUdostepniac' => $request->user()->can('share', $recipe),
            'udostepnienia' => $recipe->shares()
                ->with('recipient.profile')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(),
            'kandydat' => $kandydat,
            'potwierdzenie' => $kandydat !== null ? $potwierdzenie : null,
            'limit' => (int) config('kuking.udostepnienia.max_osob'),
        ]);
    }

    public function store(Request $request, Recipe $recipe, UdostepnijPrzepis $akcja): RedirectResponse
    {
        $this->authorize('share', $recipe);

        $wroc = redirect()->route('recipes.shares.index', $recipe);

        if (! $request->boolean('potwierdzam')) {
            $dane = $request->validate([
                'nazwa' => ['required', 'string', 'max:60'],
            ], [
                'nazwa.required' => 'Wpisz nazwę konta osoby, której chcesz pokazać przepis — jest na jej profilu, po znaku @.',
                'nazwa.max' => 'Nazwa konta jest krótsza. Sprawdź ją na profilu tej osoby i wpisz jeszcze raz.',
            ]);
            try {
                $odbiorca = $akcja->odbiorca($request->user(), $recipe, $dane['nazwa']);
            } catch (BladDlaCzlowieka $e) {
                return $wroc->withInput()->withErrors(['nazwa' => $e->getMessage()]);
            }

            return $wroc->with('udostepnij_potwierdzenie', PotwierdzenieOdbiorcy::wystaw($request->user(), $recipe, $odbiorca));
        }

        $token = $request->input('potwierdzenie');
        $danePotwierdzenia = is_string($token) && strlen($token) <= 4096
            ? PotwierdzenieOdbiorcy::odczytaj($token, $request->user(), $recipe)
            : null;

        if ($danePotwierdzenia === null) {
            return $wroc->withErrors(['nazwa' => UdostepnijPrzepis::PONOW_POTWIERDZENIE]);
        }

        try {
            [$udostepnienie, $nowe] = $akcja->poPotwierdzeniu($request->user(), $recipe, $danePotwierdzenia['odbiorca'], $danePotwierdzenia['nazwa']);
        } catch (BladDlaCzlowieka $e) {
            return $wroc->withInput(['nazwa' => $danePotwierdzenia['nazwa']])->withErrors(['nazwa' => $e->getMessage()]);
        }

        /** @var User|null $odbiorca */
        $odbiorca = $udostepnienie->recipient;
        $kto = $odbiorca?->displayName() ?? 'Ta osoba';

        return $wroc->with($nowe
            ? Komunikat::sukces("{$kto} może już czytać ten przepis. Ta osoba zobaczy powiadomienie w Kuking, a przepis znajdzie w „Moje”, w części „Przepisy udostępnione mi”.")
            : Komunikat::informacja("{$kto} już ma dostęp do tego przepisu. Nic się nie zmieniło."));
    }

    public function destroy(Request $request, Recipe $recipe, RecipeShare $share, OdbierzDostepDoPrzepisu $akcja): RedirectResponse
    {
        $this->authorize('manageShares', $recipe);

        // Udostępnienie innego przepisu pod adresem tego — jak nieistniejące.
        abort_unless($share->recipe_id === $recipe->getKey(), 404);

        try {
            $akcja->odbierz($request->user(), $share);
        } catch (BladDlaCzlowieka) {
            abort(403);
        }

        $kto = $share->recipient?->displayName() ?? 'Ta osoba';

        return redirect()->route('recipes.shares.index', $recipe)
            ->with(Komunikat::sukces("Dostęp odebrany. {$kto} nie otworzy już tego przepisu. Tego, co ta osoba zdążyła wydrukować albo przepisać, nie da się cofnąć."));
    }
}
