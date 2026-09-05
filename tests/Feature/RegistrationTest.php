<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_osoba_moze_zalozyc_konto_i_dostaje_profil(): void
    {
        $response = $this->post('/register', [
            'display_name' => 'Basia',
            'username' => 'basia_z_podkarpacia',
            'email' => 'basia@example.test',
            'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ]);

        $response->assertRedirect(route('onboarding.interests'));

        $user = User::where('email', 'basia@example.test')->first();

        $this->assertNotNull($user);
        $this->assertSame('basia_z_podkarpacia', $user->profile->username);
        $this->assertSame('Basia', $user->profile->display_name);
        $this->assertNotNull($user->age_confirmed_at, 'Oświadczenie o wieku musi zostać zapisane.');
        $this->assertAuthenticatedAs($user);
    }

    public function test_nazwa_uzytkownika_musi_byc_unikalna(): void
    {
        $this->user('basia');

        $response = $this->post('/register', [
            'display_name' => 'Inna Basia',
            'username' => 'basia',
            'email' => 'inna@example.test',
            'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_komunikaty_bledow_sa_po_polsku_i_mowia_co_zrobic(): void
    {
        $response = $this->post('/register', [
            'display_name' => 'Basia',
            'username' => 'ma spacje',
            'email' => 'to-nie-email',
            'password' => 'krotkie',
        ]);

        $response->assertSessionHasErrors(['username', 'email', 'password', 'age_confirmed', 'terms_accepted']);

        $messages = session('errors')->getMessages();

        // Nie „The username field format is invalid.” — użytkownik ma wiedzieć,
        // co konkretnie poprawić (docs/UX_50_PLUS.md).
        $this->assertStringContainsString('podkreślnik', $messages['username'][0]);
        $this->assertStringContainsString('10 znaków', $messages['password'][0]);
    }

    public function test_bez_potwierdzenia_wieku_nie_da_sie_zalozyc_konta(): void
    {
        $response = $this->post('/register', [
            'display_name' => 'Ktoś',
            'username' => 'ktos123',
            'email' => 'ktos@example.test',
            'password' => 'zielonapietruszkarano',
            'terms_accepted' => '1',
        ]);

        $response->assertSessionHasErrors('age_confirmed');
        $this->assertDatabaseCount('users', 0);
    }
}
