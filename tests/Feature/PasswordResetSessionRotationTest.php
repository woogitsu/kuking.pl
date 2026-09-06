<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Rotacja sesji po resecie hasła przez „nie pamiętam hasła" (issue #12).
 *
 * To druga z dwóch dróg zmiany hasła (pierwsza — ustawienia — jest w
 * SecuritySettingsTest). Ktoś prosi o reset hasła najczęściej właśnie
 * DLATEGO, że podejrzewa, że jego hasło zna ktoś inny — więc stare sesje
 * (w tym ta cudza) muszą tu paść, tak samo jak przy zmianie w ustawieniach.
 * Różnica: tu nikt nie jest zalogowany, więc nie ma „bieżącej sesji do
 * zachowania" — kasujemy WSZYSTKIE.
 */
class PasswordResetSessionRotationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_hasla_kasuje_wszystkie_istniejace_sesje_uzytkownika(): void
    {
        config(['session.driver' => 'database']);

        $basia = $this->user('basia');

        DB::table('sessions')->insert([
            'id' => 'stara-sesja-basi',
            'user_id' => $basia->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'stare-urzadzenie',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $token = Password::createToken($basia);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $basia->email,
            'password' => 'zupelnienowehaslo789',
            'password_confirmation' => 'zupelnienowehaslo789',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseMissing('sessions', ['user_id' => $basia->getKey()]);
        $this->assertTrue(Hash::check('zupelnienowehaslo789', $basia->fresh()->password));
    }

    public function test_reset_hasla_bez_wplywu_na_sesje_innego_uzytkownika(): void
    {
        config(['session.driver' => 'database']);

        $basia = $this->user('basia');
        $inna = $this->user('inna_osoba');

        DB::table('sessions')->insert([
            'id' => 'sesja-innej-osoby',
            'user_id' => $inna->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'urzadzenie-innej-osoby',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $token = Password::createToken($basia);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $basia->email,
            'password' => 'zupelnienowehaslo789',
            'password_confirmation' => 'zupelnienowehaslo789',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseHas('sessions', ['id' => 'sesja-innej-osoby']);
    }
}
