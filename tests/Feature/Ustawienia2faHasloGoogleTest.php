<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\User;
use App\Notifications\UstawienieNowegoHasla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class Ustawienia2faHasloGoogleTest extends TestCase
{
    use RefreshDatabase;

    public function test_nowe_konto_google_ma_instrukcje_i_przechodzi_cala_droge_ustawienia_hasla(): void
    {
        Notification::fake();
        config(['kuking.google.wlaczone' => true, 'kuking.google.identyfikator_klienta' => 'test.apps.googleusercontent.com', 'kuking.google.sekret_klienta' => 'test', 'mail.default' => 'smtp']);
        $this->get(route('google.start'))->assertRedirect();
        $encode = fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
        $token = $encode(['alg' => 'RS256']).'.'.$encode([
            'iss' => 'https://accounts.google.com', 'aud' => 'test.apps.googleusercontent.com',
            'sub' => '876-test', 'email' => 'google876@example.test', 'email_verified' => true,
            'given_name' => 'Basia', 'exp' => time() + 3600, 'nonce' => session('wejscie_google.nonce'),
        ]).'.test';
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'test', 'id_token' => $token]), 'api.pwnedpasswords.com/*' => Http::response('', 200)]);
        $this->get(route('google.callback', ['code' => 'test', 'state' => session('wejscie_google.state')]))->assertRedirect(route('google.finish'));
        $this->post(route('google.finish.store'), ['display_name' => 'Basia', 'username' => 'basia876', 'age_confirmed' => '1', 'terms_accepted' => '1'])->assertRedirect();
        $user = User::where('email', 'google876@example.test')->firstOrFail();
        $this->get(route('settings.two_factor.enable'))->assertOk();
        $secret = $user->fresh()->two_factor_secret;
        $this->post(route('settings.two_factor.confirm'), ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertRedirect(route('settings.two_factor.codes'));
        $this->get(route('settings.two_factor.codes'))->assertOk();
        $this->get(route('settings.two_factor.codes'))->assertRedirect(route('settings.two_factor.edit'));
        $page = $this->get(route('settings.two_factor.edit'))->assertOk();
        $page->assertSee('Hasło do Kuking', false)->assertSee('Nie pamiętam hasła', false)->assertSee('Google', false);
        $this->assertSame(2, substr_count($page->getContent(), 'Jeśli logujesz się przez Google'));
        $this->post(route('logout'))->assertRedirect(route('landing'));
        $this->get(route('landing'))->assertOk()->assertSee(route('login'), false);
        $this->get(route('login'))->assertOk()->assertSee(route('password.request'), false);
        $this->get(route('password.request'))->assertOk();
        $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email])->assertRedirect();
        $resetToken = null;
        Notification::assertSentTo($user, UstawienieNowegoHasla::class, function ($notification) use (&$resetToken) {
            $resetToken = $notification->token;

            return true;
        });
        $this->get(route('password.reset', ['token' => $resetToken, 'email' => $user->email]))->assertOk();
        $password = 'kuchnia-wrzesien-test-876';
        $this->post(route('password.update'), ['token' => $resetToken, 'email' => $user->email, 'password' => $password, 'password_confirmation' => $password])->assertRedirect(route('login'));
        $this->post('/login', ['login' => $user->email, 'password' => $password])->assertRedirect(route('login.two_factor'));
        $this->assertGuest();
        // Następny krok TOTP: nie osłabiamy ochrony przed ponownym użyciem kodu włączenia.
        $engine = new Google2FA;
        $nextCode = $engine->oathTotp($secret, (int) floor(time() / 30) + 1);
        $this->post(route('login.two_factor.store'), ['code' => $nextCode])->assertRedirect(route('home'));
        $this->get(route('settings.two_factor.edit'))->assertOk();
        $oldCodes = $user->fresh()->two_factor_backup_codes;
        $this->post(route('settings.two_factor.regenerate'), ['password' => $password])->assertRedirect(route('settings.two_factor.codes'));
        $this->assertTrue($oldCodes !== $user->fresh()->two_factor_backup_codes);
        $this->assertTrue($secret === $user->fresh()->two_factor_secret);
        $this->assertTrue($user->fresh()->hasTwoFactorConfirmed());
        $this->post(route('settings.two_factor.disable'), ['password' => $password])->assertRedirect(route('settings.two_factor.edit'));
        $this->assertFalse($user->fresh()->hasTwoFactorConfirmed());
    }

    public function test_tradycyjne_konto_i_konto_polaczone_z_google_nadal_wymagaja_wlasnego_hasla(): void
    {
        foreach ([false, true] as $connected) {
            $user = $this->user();
            if ($connected) {
                $user->connectGoogle('876-linked-test');
            }
            $totp = app(TwoFactorAuthenticator::class);
            $secret = $totp->generateSecret();
            $user->beginTwoFactorSetup($secret);
            $user->confirmTwoFactor($totp->hashBackupCodes(['OLD0-1IOO']));
            $old = $user->fresh()->two_factor_backup_codes;
            foreach (['regenerate', 'disable'] as $operation) {
                $this->actingAs($user)->post(route('settings.two_factor.'.$operation), ['password' => 'obce-haslo'])
                    ->assertSessionHasErrorsIn($operation, ['password']);
                $this->assertTrue($old === $user->fresh()->two_factor_backup_codes);
                $this->assertTrue($user->fresh()->hasTwoFactorConfirmed());
            }
            $this->post(route('settings.two_factor.regenerate'), ['password' => 'haslo-testowe-123'])->assertRedirect(route('settings.two_factor.codes'));
            $this->assertFalse($totp->consumeBackupCode($user, 'OLD0-1IOO'));
            $this->assertTrue($secret === $user->fresh()->two_factor_secret);
            $this->post(route('settings.two_factor.disable'), ['password' => 'haslo-testowe-123'])->assertRedirect(route('settings.two_factor.edit'));
            $this->assertFalse($user->fresh()->hasTwoFactorConfirmed());
        }
    }

    public function test_bez_poczty_instrukcja_nie_kaze_wylogowac_sie_w_ciemno(): void
    {
        config(['mail.default' => 'array']);
        $user = $this->user();
        $totp = app(TwoFactorAuthenticator::class);
        $user->beginTwoFactorSetup($totp->generateSecret());
        $user->confirmTwoFactor($totp->hashBackupCodes(['TEST-TEST']));
        $page = $this->actingAs($user)->get(route('settings.two_factor.edit'))->assertOk();
        $page->assertSee('Pozostań na razie na swoim koncie.', false);
        $page->assertDontSee('wybierz „Wyloguj się”', false);
        $page->assertSee('Nie wysyłamy jeszcze wiadomości e-mail', false);
    }

    public function test_sama_sesja_i_falszywy_link_nie_ustawiaja_hasla(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        $user = $this->user();
        $hash = $user->password;
        $this->actingAs($user)->post(route('password.update'), ['token' => 'falszywy', 'email' => $user->email, 'password' => 'nowe-haslo-testowe', 'password_confirmation' => 'nowe-haslo-testowe'])->assertRedirect(route('home'));
        $this->assertTrue($hash === $user->fresh()->password);
        $this->post(route('logout'));
        $this->post(route('password.update'), ['token' => 'falszywy', 'email' => $user->email, 'password' => 'nowe-haslo-testowe', 'password_confirmation' => 'nowe-haslo-testowe'])->assertSessionHasErrors('email');
        $this->assertTrue($hash === $user->fresh()->password);
    }
}
