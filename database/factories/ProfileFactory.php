<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    protected $model = Profile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'username' => Str::lower(Str::random(12)),
            'display_name' => fake()->firstName(),
            'bio' => fake()->optional()->sentence(),
        ];
    }
}
