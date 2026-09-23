<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Rozpoczęte logowanie 2FA nie przeżywa odwołania poświadczeń (issue #931).
 *
 * Po poprawnym haśle sesja trzyma tylko oczekujące logowanie gościa. Reset
 * hasła i „wyloguj inne urządzenia” kasują sesje po `user_id`, więc takiej
 * sesji nie dotykały — a kod zapasowy kończył logowanie bez nowego hasła.
 * Każdy test niżej sprawdza też, że odmowa NIE zużyła kodu zapasowego.
 */
class OczekujaceLogowanie2faOdwolaneTest extends TestCase
{
    use RefreshDatabase;

    private const KOD_ZAPASOWY = 'ABCD-1234';

    private function kontoZ2fa(): User
    {
        $totp = app(TwoFactorAuthenticator::class);
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $basia->beginTwoFactorSetup($totp->generateSecret());
        $basia->confirmTwoFactor($totp->hashBackupCodes([self::KOD_ZAPASOWY]));

        return $basia->refresh();
    }

    private function pierwszyKrok(): void
    {
        $this->post('/login', ['login' => 'basia@example.com', 'password' => 'haslo-testowe-123'])
            ->assertRedirect(route('login.two_factor'));
        $this->assertGuest();
    }

    private function assertOdmowaBezZuzyciaKodu(User $basia): void
    {
        $zapasowePrzed = $basia->fresh()->two_factor_backup_codes;

        $this->post(route('login.two_factor.store'), ['backup_code' => self::KOD_ZAPASOWY])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['login' => 'Zaloguj się jeszcze raz: od rozpoczęcia logowania zmieniło się coś na koncie (na przykład hasło). Wpisz adres e-mail i aktualne hasło.']);

        $this->assertGuest();
        $this->assertSame($zapasowePrzed, $basia->fresh()->two_factor_backup_codes);
        $this->assertFalse(session()->has('logowanie.2fa.user_id'));
        $this->get(route('login.two_factor'))->assertRedirect(route('login'));
    }

    public function test_kontrola_dodatnia_bez_odwolania_kod_zapasowy_loguje(): void
    {
        $basia = $this->kontoZ2fa();
        $this->pierwszyKrok();

        $this->get(route('login.two_factor'))->assertOk();
        $this->post(route('login.two_factor.store'), ['backup_code' => self::KOD_ZAPASOWY])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($basia->fresh());
    }

    public function test_reset_hasla_miedzy_krokami_odrzuca_kod(): void
    {
        $basia = $this->kontoZ2fa();
        $this->pierwszyKrok();

        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        $this->post(route('password.update'), [
            'token' => Password::createToken($basia),
            'email' => 'basia@example.com',
            'password' => 'zupelnie-nowe-haslo-2026',
            'password_confirmation' => 'zupelnie-nowe-haslo-2026',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(auth()->validate(['email' => 'basia@example.com', 'password' => 'zupelnie-nowe-haslo-2026']));

        $this->assertOdmowaBezZuzyciaKodu($basia);

        // Nowe logowanie aktualnym hasłem działa tym samym, niezużytym kodem.
        $this->post('/login', ['login' => 'basia@example.com', 'password' => 'zupelnie-nowe-haslo-2026'])
            ->assertRedirect(route('login.two_factor'));
        $this->post(route('login.two_factor.store'), ['backup_code' => self::KOD_ZAPASOWY])
            ->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($basia->fresh());
    }

    public function test_wylogowanie_innych_urzadzen_odrzuca_kod(): void
    {
        $basia = $this->kontoZ2fa();
        $this->pierwszyKrok();

        // To samo wywołanie, które robi POST /ustawienia/bezpieczenstwo/wyloguj-inne
        // w DRUGIEJ, zalogowanej przeglądarce właściciela — z wyjątkiem jej sesji.
        $basia->fresh()->invalidateSessions('sesja-drugiej-przegladarki');

        $this->assertOdmowaBezZuzyciaKodu($basia);
    }

    public function test_ban_i_przywrocenie_nie_wskrzeszaja_starego_kroku(): void
    {
        $basia = $this->kontoZ2fa();
        $this->pierwszyKrok();

        $basia->fresh()->ban();
        $basia->fresh()->forceFill(['status' => User::STATUS_ACTIVE])->save();

        $this->assertOdmowaBezZuzyciaKodu($basia);
    }

    public function test_wylaczenie_i_ponowne_wlaczenie_2fa_odrzuca_kod(): void
    {
        $basia = $this->kontoZ2fa();
        $this->pierwszyKrok();

        $totp = app(TwoFactorAuthenticator::class);
        $basia->fresh()->disableTwoFactor();
        $this->travel(1)->seconds();
        $ponownie = $basia->fresh();
        $ponownie->beginTwoFactorSetup($totp->generateSecret());
        $ponownie->confirmTwoFactor($totp->hashBackupCodes([self::KOD_ZAPASOWY]));

        $this->assertOdmowaBezZuzyciaKodu($basia);
    }

    public function test_sesja_bez_odcisku_sprzed_poprawki_nie_wystarcza(): void
    {
        $basia = $this->kontoZ2fa();
        $this->withSession(['logowanie.2fa.user_id' => $basia->getKey()]);

        $this->assertOdmowaBezZuzyciaKodu($basia);
    }
}
