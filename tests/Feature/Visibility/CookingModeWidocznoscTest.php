<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Models\Recipe;
use App\Models\RecipeStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Tryb gotowania (issue #24) — sama macierz widoczności co
 * `RecipeWidocznoscTest`, bo to jest DOKŁADNIE ta sama Policy.
 *
 * Ten test istnieje po to, żeby to zdanie było czymś więcej niż
 * deklaracją w komentarzu kontrolera: gdyby ktoś kiedyś dopisał do
 * `CookingModeController::show()` własny warunek widoczności obok
 * `$this->authorize('view', ...)` — zamiast zamiast niego — któraś
 * z piętnastu kombinacji w tej macierzy by się rozjechała.
 */
class CookingModeWidocznoscTest extends WidocznoscTestCase
{
    protected function widocznosci(): array
    {
        return ['public', 'followers', 'private'];
    }

    protected function utworz(string $widocznosc): Model
    {
        $recipe = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => $widocznosc,
            'title' => 'Tryb gotowania '.$widocznosc,
            'slug' => 'tryb-gotowania-'.$widocznosc.'-'.Str::lower(Str::random(6)),
        ]);

        // Bez kroku kontroler przekierowuje z powrotem na stronę przepisu
        // (komunikat „ten przepis nie ma jeszcze kroków”) zamiast w ogóle
        // pytać Policy o zgodę — a to jest inna droga niż ta, którą bada
        // ten test.
        RecipeStep::create(['recipe_id' => $recipe->getKey(), 'position' => 0, 'instruction' => 'Krok.']);

        return $recipe;
    }

    protected function adres(Model $tresc): string
    {
        return route('cooking.show', $tresc->slug);
    }
}
