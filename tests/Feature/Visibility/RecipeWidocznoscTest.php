<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Models\Recipe;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Przepis — pełna macierz: public / followers / private.
 */
class RecipeWidocznoscTest extends WidocznoscTestCase
{
    protected function widocznosci(): array
    {
        return ['public', 'followers', 'private'];
    }

    protected function utworz(string $widocznosc): Model
    {
        return Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => $widocznosc,
            'title' => 'Tajny zurek '.$widocznosc,
            'slug' => 'tajny-zurek-'.$widocznosc.'-'.Str::lower(Str::random(6)),
        ]);
    }

    protected function adres(Model $tresc): string
    {
        return route('recipes.show', $tresc->slug);
    }
}
