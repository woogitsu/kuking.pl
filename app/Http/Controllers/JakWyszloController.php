<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Recipes\Gotowanie\JakWyszlo;
use App\Models\Recipe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Dwa przyciski pod „Jak wyszło?” na Starcie (F1, D-333). Oba to zwykłe
 * formularze POST — działają bez JavaScriptu. Stan żyje w sesji osoby
 * (`JakWyszlo`), więc cudzego pytania nie da się tu zamknąć: wpis wybiera
 * para „zalogowana osoba + przepis”, a adres wskazuje tylko przepis, który
 * i tak przechodzi przez `RecipePolicy::view` (AGENTS.md §7).
 */
class JakWyszloController extends Controller
{
    public function __construct(private readonly JakWyszlo $jakWyszlo) {}

    /** „Pokaż zdjęcie” — pytanie znika, dalej zwykły formularz „Ugotowałem”. */
    public function pokaz(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('view', $model);

        $this->jakWyszlo->pokaz($request->session(), $request->user(), $model);

        return redirect()->route('cooked.create', $model->slug);
    }

    /** „Nie teraz” — pytanie znika i dla tego gotowania nie wraca. */
    public function zamknij(Request $request, string $recipe): RedirectResponse
    {
        $model = Recipe::where('slug', $recipe)->firstOrFail();
        $this->authorize('view', $model);

        $this->jakWyszlo->zamknij($request->session(), $request->user(), $model);

        return redirect()->route('home');
    }
}
