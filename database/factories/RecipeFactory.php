<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Recipe>
 */
class RecipeFactory extends Factory
{
    protected $model = Recipe::class;

    public function definition(): array
    {
        $title = ucfirst(fake()->words(3, true));

        return [
            'author_id' => User::factory(),
            'title' => $title,
            'slug' => Str::slug(Str::ascii($title)).'-'.Str::lower(Str::random(6)),
            'summary' => fake()->sentence(),
            'servings' => fake()->numberBetween(2, 8),
            'prep_minutes' => fake()->numberBetween(5, 40),
            'cook_minutes' => fake()->numberBetween(10, 120),
            'difficulty' => fake()->randomElement(['easy', 'medium', 'hard']),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'source_type' => Recipe::SOURCE_OWN,
            'published_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => Recipe::STATUS_DRAFT,
            'published_at' => null,
        ]);
    }

    public function family(string $person = 'mamie, Halinie'): static
    {
        return $this->state(fn () => [
            'source_type' => Recipe::SOURCE_FAMILY,
            'source_person' => $person,
            'source_note' => 'Robiła to zawsze w niedzielę.',
            'family_since_year' => 1974,
        ]);
    }
}
