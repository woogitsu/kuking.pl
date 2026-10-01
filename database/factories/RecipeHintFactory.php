<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\RecipeHint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabryka dla testów. Ustawia pola sterujące jawnie przez `state()` —
 * `$fillable` modelu jest puste, więc `create()` z tablicą nie przypisuje
 * niczego, a `afterMaking` robi to ręcznie (jak akcje domenowe).
 *
 * @extends Factory<RecipeHint>
 */
class RecipeHintFactory extends Factory
{
    protected $model = RecipeHint::class;

    public function definition(): array
    {
        return [
            'status' => RecipeHint::STATUS_PROPOSED,
        ];
    }

    /** Wskazówka ukryta przez moderację (stan zgody kucharza zostaje, jaki był). */
    public function ukrytaPrzezModeracje(): static
    {
        return $this->afterMaking(function (RecipeHint $h): void {
            $h->moderation_hidden_at = now();
        });
    }

    /**
     * Wskazówka dla gotowego wykonania: przepis, autor i kucharz wynikają z niego.
     */
    public function dlaWykonania(CookedEvent $wykonanie, string $status = RecipeHint::STATUS_PROPOSED): static
    {
        return $this->afterMaking(function (RecipeHint $h) use ($wykonanie, $status): void {
            $przepis = $wykonanie->recipe ?? Recipe::query()->findOrFail($wykonanie->recipe_id);
            $h->recipe_id = $przepis->getKey();
            $h->cooked_event_id = $wykonanie->getKey();
            $h->author_id = $przepis->author_id;
            $h->cook_id = $wykonanie->user_id;
            $h->status = $status;
            $h->decided_at = in_array($status, [RecipeHint::STATUS_ACCEPTED, RecipeHint::STATUS_DECLINED, RecipeHint::STATUS_WITHDRAWN], true) ? now() : null;
            $h->withdrawn_at = $status === RecipeHint::STATUS_WITHDRAWN ? now() : null;
        });
    }
}
