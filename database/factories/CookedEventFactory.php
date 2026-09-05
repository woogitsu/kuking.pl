<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CookedEvent>
 */
class CookedEventFactory extends Factory
{
    protected $model = CookedEvent::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'recipe_id' => Recipe::factory(),
            'note' => fake()->sentence(),
            'would_make_again' => true,
            'perceived_difficulty' => 'easy',
            'actual_minutes' => fake()->numberBetween(20, 90),
            'cooked_at' => now(),
        ];
    }
}
