<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    protected $model = Post::class;

    public function definition(): array
    {
        return [
            'title' => null,
            'author_id' => User::factory(),
            'body' => fake()->sentence(8),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => Post::STATUS_DRAFT,
            'published_at' => null,
        ]);
    }

    /**
     * Pytanie do działu „Poradźcie".
     *
     * `kind` idzie przez `afterMaking()` i `Post::oznaczJakoPytanie()`, a nie
     * przez `state()`. NIE dlatego, że `state()` by nie zadziałał — fabryki
     * Laravela budują model w `Model::unguarded()`, więc zadziałałby — tylko
     * dlatego, że pytanie ma w tym repozytorium jedną drogę powstawania.
     * Fabryka chodząca obok niej dowodziłaby czegoś, czego produkcja nie robi.
     */
    public function question(): static
    {
        return $this->state(fn () => ['title' => 'Jak upiec chrupiący chleb?'])
            ->afterMaking(fn (Post $post) => $post->oznaczJakoPytanie((string) $post->title));
    }

    public function followersOnly(): static
    {
        return $this->state(fn () => ['visibility' => Post::VISIBILITY_FOLLOWERS]);
    }

    public function private(): static
    {
        return $this->state(fn () => ['visibility' => Post::VISIBILITY_PRIVATE]);
    }
}
