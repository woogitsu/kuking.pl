<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Recipes\Historia\HistoriaWersji;
use App\Domain\Recipes\Historia\MigawkaWersji;
use App\Domain\Recipes\Historia\PorownanieWersji;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
        $model = $this->przepis($request, $recipe, 'recipes.history');

        $wersje = HistoriaWersji::zapytanie($model)
            ->select(['id', 'recipe_id', 'version_number', 'change_note', 'created_at'])
            ->orderByDesc('version_number')
            ->simplePaginate(HistoriaWersji::NA_STRONE);

        $numery = HistoriaWersji::numery($model);

        return view('pages.recipes.historia', [
            'recipe' => $model,
            'wersje' => $wersje,
            'najnowsza' => $numery[0] ?? null,
            // Faktyczny poprzednik każdej wersji z listy (numeracja może mieć luki).
            'poprzednicy' => $wersje->getCollection()
                ->mapWithKeys(fn (RecipeVersion $w): array => [$w->version_number => $this->sasiad($numery, $w->version_number, -1)])
                ->all(),
        ]);
    }

    public function show(Request $request, string $recipe, int $numer): View
    {
        $model = $this->przepis($request, $recipe, 'recipes.history.version', $numer);
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
        $model = $this->przepis($request, $recipe, 'recipes.history.changes', $numer);
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

    /**
     * @param  string  $trasa  nazwa trasy, na którą wraca 301 ze starego sluga
     */
    private function przepis(Request $request, string $slug, string $trasa, ?int $numer = null): Recipe
    {
        $model = Recipe::where('slug', $slug)->first();

        // Stary adres przepisu działa też na ekranach historii (jak w
        // `RecipeController::show`): 301 na aktualny slug, ta sama bramka
        // `view` i ten sam 404 zamiast 403 (nie zdradzamy, że przepis istnieje).
        if ($model === null) {
            $przekierowanie = DB::table('recipe_slug_redirects')->where('slug', $slug)->first();
            abort_if($przekierowanie === null, 404);

            $cel = Recipe::findOrFail($przekierowanie->recipe_id);
            abort_unless(Gate::forUser($request->user())->allows('view', $cel), 404);
            // Przepis nieopublikowany nie ma historii — także pod starym adresem.
            abort_unless(HistoriaWersji::opublikowanyPoAutoryzacji($cel), 404);

            throw new HttpResponseException(
                redirect()->route($trasa, $numer === null ? [$cel->slug] : [$cel->slug, $numer], 301),
            );
        }

        // 403 jak na stronie przepisu (`RecipeController::show`) ...
        $this->authorize('view', $model);
        // ... a przepis nieopublikowany (ukryty, zdjęty) nie ma historii.
        // `view` policzone wyżej — nie liczymy go drugi raz.
        abort_unless(HistoriaWersji::opublikowanyPoAutoryzacji($model), 404);

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
