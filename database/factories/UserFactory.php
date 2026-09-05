<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('haslo-testowe-123'),
            'status' => User::STATUS_ACTIVE,
            'role' => User::ROLE_USER,
            'locale' => 'pl',
            'text_scale' => 100,
            'wants_weekly_digest' => true,
            'age_confirmed_at' => now(),
            'email_verified_at' => now(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function moderator(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_MODERATOR]);
    }

    public function banned(): static
    {
        return $this->state(fn () => ['status' => User::STATUS_BANNED]);
    }

    /**
     * Każdy użytkownik dostaje profil — bez niego nie da się go pokazać
     * ani zalinkować, a wszystkie widoki tego oczekują.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if ($user->profile === null) {
                Profile::create([
                    'user_id' => $user->getKey(),
                    'username' => Str::lower(Str::random(10)),
                    'display_name' => fake()->firstName(),
                ]);
            }
        });
    }
}
