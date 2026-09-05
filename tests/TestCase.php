<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Skrót do tworzenia użytkownika z profilem i czytelną nazwą.
     *
     * Testy Kuking prawie zawsze potrzebują profilu (adresy /@nazwa, widoki),
     * więc tworzenie samego User-a byłoby ciągłym źródłem fałszywych błędów.
     */
    protected function user(?string $username = null, array $attributes = []): User
    {
        // display_name należy do profilu, nie do konta — wyjmujemy je,
        // zanim resztę atrybutów przekażemy fabryce User-a.
        $displayName = $attributes['display_name'] ?? 'Testowa osoba';
        unset($attributes['display_name']);

        $user = User::factory()->create($attributes);

        Profile::query()->where('user_id', $user->getKey())->delete();

        Profile::create([
            'user_id' => $user->getKey(),
            'username' => $username ?? Str::lower(Str::random(12)),
            'display_name' => $displayName,
        ]);

        return $user->refresh();
    }

    protected function moderator(): User
    {
        return $this->user(null, ['role' => User::ROLE_MODERATOR]);
    }
}
