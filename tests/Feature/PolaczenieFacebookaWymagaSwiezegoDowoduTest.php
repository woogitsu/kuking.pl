<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\FacebookConnectionProof;
use App\Models\TozsamoscZewnetrzna;
use App\Models\User;
use App\Notifications\PotwierdzeniePolaczeniaFacebooka;
use App\Support\Skrot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class PolaczenieFacebookaWymagaSwiezegoDowoduTest extends TestCase
{
    use RefreshDatabase;

    private const FB_ID = 'facebook-2085';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config([
            'kuking.facebook.wlaczone' => true,
            'kuking.facebook.identyfikator_klienta' => 'test-client',
            'kuking.facebook.sekret_klienta' => 'test-secret',
            'mail.default' => 'smtp',
        ]);
    }

    private function begin(User $user): void
    {
        $this->actingAs($user)->withSession(['wejscie_facebook.tozsamosc' => [
            'identyfikator' => self::FB_ID,
            'email' => 'untrusted@facebook.test',
            'imie' => 'Test',
            'od' => now()->getTimestamp(),
        ]]);
    }

    private function emailedToken(User $user): string
    {
        Notification::assertSentTo($user, PotwierdzeniePolaczeniaFacebooka::class);
        $notification = Notification::sent($user, PotwierdzeniePolaczeniaFacebooka::class)->last();
        $path = parse_url($notification->toMail($user)->actionUrl, PHP_URL_PATH);

        return basename((string) $path);
    }

    #[Test]
    public function test_stara_sesja_i_nowe_oauth_nie_wystarczaja_a_haslo_musi_byc_poprawne(): void
    {
        $user = $this->user('basia');
        $this->begin($user);

        $this->post(route('facebook.link.store'))->assertRedirect(route('facebook.link'));
        $this->post(route('facebook.link.store'), ['password' => 'zle-haslo'])
            ->assertRedirect(route('facebook.link'));
        $this->assertFalse($user->fresh()->hasFacebookConnected());
        $this->assertDatabaseMissing('audit_log', ['action' => 'account.facebook_connected']);

        $this->post(route('facebook.link.store'), ['password' => 'haslo-testowe-123'])
            ->assertRedirect(route('settings.security'));
        $this->assertTrue($user->fresh()->hasFacebookConnected());
        $this->assertDatabaseHas('audit_log', ['action' => 'account.facebook_connected']);
        $this->assertDatabaseCount('tozsamosci_zewnetrzne', 1);
    }

    #[Test]
    public function test_konto_bez_znanego_hasla_potwierdza_tylko_obecny_adres_w_tej_samej_sesji(): void
    {
        $user = $this->user('basia', ['email' => 'obecny@example.test']);
        $user->forceFill(['password' => Hash::make(bin2hex(random_bytes(40)))])->save();
        $this->begin($user);

        $this->post(route('facebook.link.email'))->assertRedirect(route('facebook.link'));
        $token = $this->emailedToken($user);
        $this->assertSame('obecny@example.test', $user->fresh()->email);
        $this->assertTrue(FacebookConnectionProof::validShape($token), 'Niepoprawny token z listu: '.$token);
        $proof = FacebookConnectionProof::query()->firstOrFail();
        $this->assertSame(FacebookConnectionProof::hashToken($token), $proof->token_hash);
        $this->assertSame(Skrot::hmac($this->app['session']->getId()), $proof->session_hash);
        $this->get(route('facebook.link'))->assertOk();
        $confirmation = $this->get(route('facebook.link.confirm', ['token' => $token]));
        $this->assertSame(200, $confirmation->status(),
            'Link potwierdzający skierował na: '.(string) $confirmation->headers->get('Location')
            .' / powód: '.(string) session('status'));
        $this->assertFalse($user->fresh()->hasFacebookConnected());

        $this->post(route('facebook.link.store'), ['proof_token' => $token])
            ->assertRedirect(route('settings.security'));
        $this->assertTrue($user->fresh()->hasFacebookConnected());
        $this->assertDatabaseCount('facebook_connection_proofs', 0);
        $this->post(route('facebook.link.store'), ['proof_token' => $token]);
        $this->assertDatabaseCount('tozsamosci_zewnetrzne', 1);
    }

    #[Test]
    public function test_link_wygasa_i_zmiana_konta_odrzuca_stary_dowod(): void
    {
        $user = $this->user('basia');
        $this->begin($user);
        $this->post(route('facebook.link.email'));
        $token = $this->emailedToken($user);

        $user->assignPassword('nowe-haslo')->save();
        $pending = Notification::sent($user, PotwierdzeniePolaczeniaFacebooka::class)->last();
        $this->assertFalse($pending->shouldSend($user->fresh(), 'mail'),
            'Spóźniony list po zmianie konta nie może trafić na nowy adres.');
        $this->post(route('facebook.link.store'), ['proof_token' => $token]);
        $this->assertFalse($user->fresh()->hasFacebookConnected());

        $this->post(route('facebook.link.email'));
        $newToken = $this->emailedToken($user);
        $this->travel(11)->minutes();
        $this->post(route('facebook.link.store'), ['proof_token' => $newToken]);
        $this->assertFalse($user->fresh()->hasFacebookConnected());
    }

    #[Test]
    public function test_link_jest_zwiazany_z_kontem_facebookiem_i_sesja(): void
    {
        $user = $this->user('basia');
        $this->begin($user);
        $this->post(route('facebook.link.email'));
        $token = $this->emailedToken($user);

        $this->withSession(['wejscie_facebook.tozsamosc' => [
            'identyfikator' => 'inny-facebook', 'email' => null,
            'imie' => 'Inna', 'od' => now()->getTimestamp(),
        ]]);
        $this->post(route('facebook.link.store'), ['proof_token' => $token]);
        $this->assertFalse($user->fresh()->hasFacebookConnected());

        $this->begin($user);
        $this->app['session']->migrate(true);
        $this->post(route('facebook.link.store'), ['proof_token' => $token]);
        $this->assertFalse($user->fresh()->hasFacebookConnected());

        $other = $this->user('inna');
        $this->begin($other);
        $this->post(route('facebook.link.store'), ['proof_token' => $token]);
        $this->assertFalse($other->fresh()->hasFacebookConnected());
    }

    #[Test]
    public function test_zmiana_adresu_po_prosbie_uniewaznia_link_i_wstrzymuje_spozniony_list(): void
    {
        $user = $this->user('basia', ['email' => 'stary@example.test']);
        $this->begin($user);
        $this->post(route('facebook.link.email'));
        $token = $this->emailedToken($user);
        $mail = Notification::sent($user, PotwierdzeniePolaczeniaFacebooka::class)->last();

        $user->assignEmail('nowy@example.test', true)->save();
        $this->assertFalse($mail->shouldSend($user->fresh(), 'mail'));
        $this->post(route('facebook.link.store'), ['proof_token' => $token]);
        $this->assertFalse($user->fresh()->hasFacebookConnected());
    }

    #[Test]
    public function test_niepotwierdzony_adres_nie_dostaje_linku_i_2fa_wymaga_starego_kodu(): void
    {
        $user = $this->user('basia', ['email_verified_at' => null]);
        $this->begin($user);
        $this->post(route('facebook.link.email'));
        Notification::assertNothingSent();
        $this->assertDatabaseCount('facebook_connection_proofs', 0);

        $user->forceFill(['email_verified_at' => now()])->save();
        $secret = app(TwoFactorAuthenticator::class)->generateSecret();
        $user->beginTwoFactorSetup($secret);
        $user->confirmTwoFactor([]);
        $this->begin($user->refresh());

        $this->post(route('facebook.link.store'), ['password' => 'haslo-testowe-123'])
            ->assertRedirect(route('facebook.link'));
        $this->assertFalse($user->fresh()->hasFacebookConnected());

        $this->post(route('facebook.link.store'), [
            'password' => 'haslo-testowe-123',
            'two_factor_code' => (new Google2FA)->getCurrentOtp($secret),
        ])->assertRedirect(route('settings.security'));
        $this->assertTrue($user->fresh()->hasFacebookConnected());
    }

    #[Test]
    public function test_znowu_wlaczenie_uspionego_powiazania_wymaga_dowodu(): void
    {
        $user = $this->user('basia');
        $user->connectFacebook(self::FB_ID);
        $user->tozsamosciZewnetrzne()->where('dostawca', TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK)
            ->update(['dostep_odebrany_at' => now()]);
        $this->begin($user);

        $this->post(route('facebook.link.store'));
        $this->assertTrue($user->dostepOdebranyU(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK));
        $this->post(route('facebook.link.store'), ['password' => 'haslo-testowe-123'])
            ->assertRedirect(route('settings.security'));
        $this->assertFalse($user->dostepOdebranyU(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK));
        $this->assertDatabaseCount('tozsamosci_zewnetrzne', 1);
    }
}
