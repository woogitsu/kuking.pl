<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Recipes\Gotowanie\ProbyPrzepisu;
use App\Models\Recipe;
use App\Models\User;
use App\Support\StaryAdresPrzepisu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Moje próby tego przepisu" (V2, #2412): prywatna historia i porównanie
 * własnych wykonań jednego przepisu. Tylko odczyt; dane liczy
 * `ProbyPrzepisu` i zawsze wiąże je z osobą zalogowaną.
 */
class MojeProbyPrzepisuController extends Controller
{
    public function index(Request $request, string $recipe): View|RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->first();

        if ($model === null) {
            return StaryAdresPrzepisu::przekieruj($request, $recipe, 'cooked.proby', 'view');
        }

        // Historia własnych prób nie otwiera przepisu, którego dana osoba
        // nie może zobaczyć (prywatny, ukryty, za blokadą): odmowa jest
        // ta sama co na stronie przepisu i nie niesie tytułu.
        $this->authorize('view', $model);

        /** @var User $user */
        $user = $request->user();

        return view('pages.cooked.proby', [
            'recipe' => $model,
            ...ProbyPrzepisu::dla($user, $model),
        ]);
    }
}
