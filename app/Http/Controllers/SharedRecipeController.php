<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Recipes\Udostepnienia\OdbierzDostepDoPrzepisu;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Recipe;
use App\Models\RecipeShare;
use App\Support\Komunikat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Przepis udostępniony MNIE — ekran ODBIORCY (#2650, D-333).
 *
 * Jedna strona do czytania (`show`), lista „Udostępnione mi" (`index`)
 * i rezygnacja z dostępu (`leave`). Brama: `RecipePolicy::readShared()`
 * przy każdym żądaniu — bez pamięci podręcznej, więc odebranie dostępu,
 * blokada, kara i ukrycie przepisu działają od następnego wejścia.
 *
 * STRONA CZYTANIA NIE JEST STRONĄ PRZEPISU. Nie ma na niej komentarzy,
 * „Ugotowałem", „Zrób swoją wersję", zapisu do zeszytu, historii wersji,
 * trybu gotowania, karty QR, skanu kartki ani JSON-LD — każda z tych dróg
 * pyta `view()`, którego odbiorca nie ma. Przycisk prowadzący w 403 byłby
 * martwym przyciskiem, więc ich tu po prostu nie ma.
 */
class SharedRecipeController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        // Prawo do czytania nie warunkuje prawa do usunięcia WŁASNEGO grantu.
        // Niedostępny przepis pozostaje anonimową pozycją z samą rezygnacją.
        $udostepnienia = RecipeShare::query()
            ->where('recipient_id', $user->getKey())
            ->with(['recipe.author.profile'])
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (RecipeShare $u): array => [
                'grant' => $u,
                'czytelny' => $u->recipe !== null
                    && Gate::forUser($user)->allows('readShared', $u->recipe),
            ])
            ->values();

        return view('pages.recipes.udostepnione-mi', [
            'udostepnienia' => $udostepnienia,
        ]);
    }

    public function show(Request $request, Recipe $recipe): View|RedirectResponse
    {
        // Autor ma swoją zwykłą stronę przepisu — tu nie ma czego czytać inaczej.
        if ($request->user()->getKey() === $recipe->author_id) {
            return redirect()->route('recipes.show', $recipe);
        }

        $this->authorize('readShared', $recipe);

        $recipe->load([
            'author.profile',
            'heroMedia',
            'ingredients.unit',
            'steps.media',
        ]);

        return view('pages.recipes.udostepniony', [
            'recipe' => $recipe,
            'udostepnienie' => $recipe->shares()->where('recipient_id', $request->user()->getKey())->firstOrFail(),
        ]);
    }

    public function leave(Request $request, RecipeShare $share, OdbierzDostepDoPrzepisu $akcja): RedirectResponse
    {
        $this->authorize('leave', $share);

        try {
            $akcja->zrezygnuj($request->user(), $share);
        } catch (BladDlaCzlowieka) {
            abort(403);
        }

        return redirect()->route('recipes.shared.index')
            ->with(Komunikat::sukces('Ten przepis zniknął z Twojej listy i nie otworzysz go już. Autor może udostępnić go ponownie.'));
    }
}
