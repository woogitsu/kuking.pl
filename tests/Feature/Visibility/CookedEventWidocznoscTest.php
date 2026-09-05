<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Models\CookedEvent;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Wykonanie („Ugotowałem") nie ma własnej widoczności — idzie za przepisem.
 *
 * To jest dokładnie ten przypadek, w którym łatwo o wyciek: treść widoczna
 * jest sterowana przez CUDZY rekord, więc sama zmiana Policy przepisu nie
 * wystarcza, jeśli ktoś kiedyś doda listę wykonań pomijającą ten warunek.
 */
class CookedEventWidocznoscTest extends WidocznoscTestCase
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
            'title' => 'Przepis '.$widocznosc,
            'slug' => 'przepis-'.$widocznosc.'-'.Str::lower(Str::random(6)),
        ]);

        return CookedEvent::factory()->create([
            'recipe_id' => $recipe->getKey(),
            'user_id' => $this->autor->getKey(),
        ]);
    }

    protected function adres(Model $tresc): string
    {
        return route('cooked.show', $tresc);
    }
}
