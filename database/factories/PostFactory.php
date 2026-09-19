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
            'kind' => Post::KIND_DISH,
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

    public function question(): static
    {
        return $this->state(fn () => [
            'kind' => Post::KIND_QUESTION,
            'title' => 'Jak upiec chrupiący chleb?',
        ]);
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
