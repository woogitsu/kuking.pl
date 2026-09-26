<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * #930: `/admin` wymaga kodu przebytego W TEJ SESJI, nie tylko 2FA na koncie.
 *
 * Gołe `be()` daje sesję zalogowaną bez dowodu kodu — tak wygląda sesja
 * z samego hasła sprzed włączenia 2FA albo odtworzona z „zapamiętaj mnie"
 * przy sterowniku, którego `invalidateSessions()` nie czyści. `actingAs()`
 * w TestCase dokłada dowód i jest kontrolą dodatnią.
 */
class PanelWymagaKoduWSesjiTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    public function test_sesja_bez_kodu_nie_wchodzi_do_panelu_mimo_2fa_na_koncie(): void
    {
        foreach ([$this->moderator(), $this->admin()] as $konto) {
            $response = $this->be($konto)->get(route('admin.reports'));

            $response->assertForbidden();
            $ekran = $this->trescEkranu((string) $response->getContent());
            $this->assertStringContainsString('Zaloguj się ponownie, podając kod', $ekran);
            $this->assertStringContainsString('Wyloguj się i zaloguj z kodem', $ekran);

            $this->flushSession();
        }
    }

    public function test_kontrola_dodatnia_sesja_z_dowodem_wchodzi(): void
    {
        $this->actingAs($this->moderator())->get(route('admin.reports'))->assertOk();
    }

    public function test_logowanie_haslem_i_kodem_otwiera_panel(): void
    {
        $moderator = $this->moderator();
        $moderator->forceFill(['email' => 'mod@example.com'])->save();

        $this->post('/login', ['login' => 'mod@example.com', 'password' => 'haslo-testowe-123'])
            ->assertRedirect(route('login.two_factor'));
        $this->post(route('login.two_factor.store'), [
            'code' => (new Google2FA)->getCurrentOtp($moderator->fresh()->two_factor_secret),
        ])->assertRedirect();

        $this->assertAuthenticatedAs($moderator->fresh());
        $this->get(route('admin.reports'))->assertOk();
    }

    public function test_wlaczenie_2fa_w_tej_sesji_otwiera_panel_bez_ponownego_logowania(): void
    {
        $moderator = $this->user(null, ['role' => User::ROLE_MODERATOR]);

        $this->be($moderator)->get(route('settings.two_factor.enable'))->assertOk();
        $this->post(route('settings.two_factor.confirm'), [
            'code' => (new Google2FA)->getCurrentOtp($moderator->fresh()->two_factor_secret),
            'password' => 'haslo-testowe-123',
        ])->assertRedirect(route('settings.two_factor.codes'));

        $this->get(route('admin.reports'))->assertOk();
    }

    public function test_dowod_sprzed_wylaczenia_i_ponownego_wlaczenia_przestaje_dzialac(): void
    {
        $moderator = $this->moderator();
        $this->actingAs($moderator)->get(route('admin.reports'))->assertOk();

        $this->travel(5)->minutes();
        $totp = app(TwoFactorAuthenticator::class);
        $moderator->disableTwoFactor();
        $moderator->beginTwoFactorSetup($totp->generateSecret());
        $moderator->confirmTwoFactor($totp->hashBackupCodes($totp->generateBackupCodes()));

        $this->get(route('admin.reports'))->assertForbidden();
    }

    public function test_wylaczenie_2fa_zdejmuje_dowod_z_biezacej_sesji(): void
    {
        $moderator = $this->moderator();
        $this->actingAs($moderator)->get(route('admin.reports'))->assertOk();

        $this->post(route('settings.two_factor.disable'), ['password' => 'haslo-testowe-123'])
            ->assertRedirect(route('settings.two_factor.edit'));

        $this->assertFalse(session()->has(TwoFactorAuthenticator::KLUCZ_DOWODU_SESJI));
        $this->get(route('admin.reports'))->assertForbidden();
    }
}
