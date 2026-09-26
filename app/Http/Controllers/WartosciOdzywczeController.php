<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Recipes\Odzywcze\UstawWidocznoscWartosci;
use App\Models\Recipe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * „Ukryj tę sekcję w moim przepisie” / „Pokaż wartości odżywcze” (D-299).
 *
 * auth (grupa tras) → Policy `update` (tylko autor, tylko przepis, który
 * wolno mu edytować; UUID/slug w adresie nie jest autoryzacją) → walidacja
 * → limit `wartosci_odzywcze` → bez wpisu w audycie, bo to ustawienie
 * widoku własnej treści, a nie decyzja wobec kogoś innego.
 */
class WartosciOdzywczeController extends Controller
{
    public function __invoke(Request $request, Recipe $recipe, UstawWidocznoscWartosci $ustaw): RedirectResponse
    {
        $this->authorize('update', $recipe);

        $dane = $request->validate(
            ['pokazuj' => ['required', 'boolean']],
            ['pokazuj.*' => 'Nie udało się zapisać wyboru. Odśwież stronę i naciśnij przycisk jeszcze raz.'],
        );

        $pokazuj = (bool) $dane['pokazuj'];
        $ustaw->handle($recipe, $pokazuj);

        return redirect()
            ->to(route('recipes.show', $recipe).'#wartosci-odzywcze')
            ->with('status', $pokazuj
                ? 'Wartości odżywcze znów są widoczne przy tym przepisie.'
                : 'Ukryliśmy wartości odżywcze. Inni ich przy tym przepisie nie zobaczą.');
    }
}
