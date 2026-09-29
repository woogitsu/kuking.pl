<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Recipes\Historia\HistoriaWersji;
use App\Domain\Recipes\Historia\MigawkaWersji;
use App\Domain\Recipes\Historia\PorownanieWersji;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Historia zapisanych wersji przepisu — tylko do odczytu (issue #2024).
 *
 * Dostęp: `RecipePolicy::view` (403 jak na stronie przepisu) i dodatkowo
 * przepis musi być opublikowany (404 — nic nie zdradzamy o przepisie ukrytym
 * albo zdjętym). Reguły w `HistoriaWersji`.
 */
class HistoriaPrzepisuController extends Controller
{
    public function index(Request $request, string $recipe): View
    {
        $model = $this->przepis($request, $recipe);

        $wersje = HistoriaWersji::zapytanie($model)
            ->select(['id', 'recipe_id', 'version_number', 'change_note', 'created_at'])
            ->orderByDesc('version_number')
            ->simplePaginate(HistoriaWersji::NA_STRONE);

        return view('pages.recipes.historia', [
            'recipe' => $model,
            'wersje' => $wersje,
            'najnowsza' => HistoriaWersji::numery($model)[0] ?? null,
        ]);
    }

    public function show(Request $request, string $recipe, int $numer): View
    {
        $model = $this->przepis($request, $recipe);
        $wersja = $this->wersja($model, $numer);
        $numery = HistoriaWersji::numery($model);

        return view('pages.recipes.historia-wersja', [
            'recipe' => $model,
            'wersja' => $wersja,
            'migawka' => new MigawkaWersji($wersja->snapshot ?? []),
            'starszy' => $this->sasiad($numery, $numer, -1),
            'nowszy' => $this->sasiad($numery, $numer, 1),
            'czyNajnowsza' => ($numery[0] ?? null) === $numer,
        ]);
    }

    public function zmiany(Request $request, string $recipe, int $numer): View
    {
        $model = $this->przepis($request, $recipe);
        $wersja = $this->wersja($model, $numer);
        $numery = HistoriaWersji::numery($model);
        $poprzedniNumer = $this->sasiad($numery, $numer, -1);

        $poprzednia = $poprzedniNumer === null ? null : $this->wersja($model, $poprzedniNumer);

        return view('pages.recipes.historia-zmiany', [
            'recipe' => $model,
            'wersja' => $wersja,
            'poprzednia' => $poprzednia,
            'porownanie' => $poprzednia === null
                ? null
                : PorownanieWersji::porownaj($poprzednia->snapshot ?? [], $wersja->snapshot ?? []),
            'nowszy' => $this->sasiad($numery, $numer, 1),
        ]);
    }

    private function przepis(Request $request, string $slug): Recipe
    {
        $model = Recipe::where('slug', $slug)->first();
        abort_if($model === null, 404);

        // 403 jak na stronie przepisu (`RecipeController::show`) ...
        $this->authorize('view', $model);
        // ... a przepis nieopublikowany (ukryty, zdjęty) nie ma historii.
        abort_unless(HistoriaWersji::wolnoOgladac($request->user(), $model), 404);

        return $model;
    }

    private function wersja(Recipe $recipe, int $numer): RecipeVersion
    {
        return HistoriaWersji::zapytanie($recipe)
            ->where('version_number', $numer)
            ->firstOrFail();
    }

    /**
     * Numer sąsiedniej wersji: -1 = starsza, 1 = nowsza. `$numery` malejąco.
     *
     * @param  list<int>  $numery
     */
    private function sasiad(array $numery, int $numer, int $kierunek): ?int
    {
        $pozycja = array_search($numer, $numery, true);
        if ($pozycja === false) {
            return null;
        }

        // Malejąco: starsza to następny indeks, nowsza — poprzedni.
        return $numery[$pozycja - $kierunek] ?? null;
    }
}
