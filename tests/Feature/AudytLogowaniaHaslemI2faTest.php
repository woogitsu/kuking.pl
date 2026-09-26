<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\AuditLogEntry;
use App\Models\User;
use App\Support\Skrot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AudytLogowaniaHaslemI2faTest extends TestCase
{
    use RefreshDatabase;

    private const HASLO = 'haslo-testowe-123';

    private const ADRES = '203.0.113.27';

    public function test_udane_i_nieudane_logowanie_haslem_zostawiaja_tylko_minimalny_slad(): void
    {
        $user = $this->user('basia');
        $this->withServerVariables(['REMOTE_ADDR' => self::ADRES]);

        $this->post('/login', ['login' => $user->email, 'password' => 'zle-haslo'])
            ->assertSessionHasErrors('login');
        $this->assertGuest();

        $this->post('/login', ['login' => 'nie-ma-takiego@example.test', 'password' => 'zle-haslo'])
            ->assertSessionHasErrors('login');
        $this->assertGuest();

        $this->post('/login', ['login' => $user->email, 'password' => self::HASLO])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user);

        $wpisy = AuditLogEntry::query()->orderBy('id')->get();
        $this->assertCount(3, $wpisy);
        $this->assertSame('account.password_login_failed', $wpisy[0]->action);
        $this->assertNull($wpisy[0]->actor_id);
        $this->assertSame('User', $wpisy[0]->subject_type);
        $this->assertSame($user->id, $wpisy[0]->subject_id);
        $this->assertSame('account.password_login_failed', $wpisy[1]->action);
        $this->assertNull($wpisy[1]->actor_id);
        $this->assertNull($wpisy[1]->subject_id);
        $this->assertSame('account.password_login_succeeded', $wpisy[2]->action);
        $this->assertSame($user->id, $wpisy[2]->actor_id);
        $this->assertSame($user->id, $wpisy[2]->subject_id);

        foreach ($wpisy as $wpis) {
            $this->assertSame(Skrot::hmac(self::ADRES), $wpis->ip_hash);
            $this->assertSame([], $wpis->metadata);
            $zapis = json_encode($wpis->getAttributes(), JSON_THROW_ON_ERROR);
            foreach ([$user->email, 'nie-ma-takiego@example.test', self::HASLO, 'zle-haslo', self::ADRES] as $sekret) {
                $this->assertStringNotContainsString($sekret, $zapis);
            }
        }
    }

    public function test_haslo_konta_z_2fa_nie_jest_jeszcze_udanym_logowaniem(): void
    {
        $user = $this->user('basia');
        $totp = app(TwoFactorAuthenticator::class);
        $user->beginTwoFactorSetup($totp->generateSecret());
        $user->confirmTwoFactor($totp->hashBackupCodes(['ABCD-1234']));

        $this->post('/login', ['login' => $user->email, 'password' => self::HASLO])
            ->assertRedirect(route('login.two_factor'));
        $this->assertGuest();
        $this->assertSame(0, AuditLogEntry::query()->count());

        $this->post(route('login.two_factor.store'), ['code' => 'niepoprawny'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertSame(0, AuditLogEntry::query()->count());

        $this->post(route('login.two_factor.store'), ['code' => (new Google2FA)->getCurrentOtp($user->two_factor_secret)])
            ->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'account.password_login_succeeded')->count());
    }

    public function test_poprawne_haslo_zamknietego_konta_nie_jest_sukcesem(): void
    {
        $user = $this->user('basia', ['status' => User::STATUS_BANNED]);

        $this->post('/login', ['login' => $user->email, 'password' => self::HASLO])
            ->assertSessionHasErrors('login');
        $this->assertGuest();

        $this->assertSame(1, AuditLogEntry::query()->where('action', 'account.password_login_failed')->count());
        $this->assertSame(0, AuditLogEntry::query()->where('action', 'account.password_login_succeeded')->count());
    }

    public function test_2fa_jest_audytowane_dopiero_po_zatwierdzonej_zmianie_bez_sekretow(): void
    {
        $user = $this->user('basia');
        $this->actingAs($user)->get(route('settings.two_factor.enable'))->assertOk();
        $sekret = $user->refresh()->two_factor_secret;
        $kod = (new Google2FA)->getCurrentOtp($sekret);

        $this->post(route('settings.two_factor.confirm'), ['code' => $kod, 'password' => 'zle-haslo'])
            ->assertSessionHasErrors('password');
        $this->assertSame(0, AuditLogEntry::query()->count());

        $this->post(route('settings.two_factor.confirm'), ['code' => $kod, 'password' => self::HASLO])
            ->assertRedirect(route('settings.two_factor.codes'));
        $this->assertTrue($user->refresh()->hasTwoFactorConfirmed());
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'account.two_factor_enabled')->count());

        $this->post(route('settings.two_factor.disable'), ['password' => 'zle-haslo'])
            ->assertSessionHasErrorsIn('disable', ['password']);
        $this->assertSame(0, AuditLogEntry::query()->where('action', 'account.two_factor_disabled')->count());

        $this->post(route('settings.two_factor.disable'), ['password' => self::HASLO])
            ->assertRedirect(route('settings.two_factor.edit'));
        $this->assertFalse($user->refresh()->hasTwoFactorConfirmed());

        $wpisy = AuditLogEntry::query()->orderBy('id')->get();
        $this->assertSame(['account.two_factor_enabled', 'account.two_factor_disabled'], $wpisy->pluck('action')->all());
        foreach ($wpisy as $wpis) {
            $this->assertSame($user->id, $wpis->actor_id);
            $this->assertSame($user->id, $wpis->subject_id);
            $this->assertSame([], $wpis->metadata);
            $zapis = json_encode($wpis->getAttributes(), JSON_THROW_ON_ERROR);
            foreach ([$sekret, $kod, self::HASLO, 'zle-haslo'] as $sekretDoUkrycia) {
                $this->assertStringNotContainsString($sekretDoUkrycia, $zapis);
            }
        }
    }
}
