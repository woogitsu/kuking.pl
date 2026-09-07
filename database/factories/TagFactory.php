<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    public function definition(): array
    {
        // `unique()` na poziomie Fakera, nie na `normalized_name` w bazie —
        // dwa tagi o tej samej znormalizowanej nazwie w jednym przebiegu
        // testów i tak wybuchłyby na UNIQUE, więc lepiej dostać czytelny
        // błąd Fakera niż SQLSTATE.
        $name = ucfirst($this->faker->unique()->words(2, true));

        return [
            'name' => $name,
            'normalized_name' => Tag::znormalizujNazwe($name),
            'slug' => Tag::slugDlaNazwy($name).'-'.$this->faker->unique()->numberBetween(1, 999999),
        ];
    }

    public function seeded(): static
    {
        return $this->state(fn () => ['is_seeded' => true]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['status' => Tag::STATUS_HIDDEN]);
    }
}
