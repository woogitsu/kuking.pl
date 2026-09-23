<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Regresja issue #1314: cofnięcie usunięcia konta chronionego 2FA
 * przyjmowało SAMO hasło.
 *
 * `/cofnij-usuniecie-konta` jest publiczne, a `AccountDeletionController`
 * po `Hash::check()` od razu wołał `CancelAccountDeletion` — konto, którego
 * właściciel włączył kod z aplikacji, dało się przywrócić i potem logować
 * znajomością samego hasła. Konto bez 2FA zostaje bez zmian (pilnuje tego
 * `AccountDeletionCancellationTest`).
 */
class CofniecieUsunieciaKontaZ2faTest extends TestCase
{
    use RefreshDatabase;

    private const HASLO = 'haslo-testowe-123';

    public function test_z_2fa_samo_haslo_nie_cofa_usuniecia(): void
    {
        $basia = $this->kontoDoUsunieciaZ2fa();

        $this->from(route('account.delete.cancel'))
            ->post(route('account.delete.cancel.store'), ['login' => 'basia', 'password' => self::HASLO])
            ->assertRedirect(route('account.delete.cancel'))
            ->assertSessionHasErrors('code');

        $this->assertSame(User::STATUS_PENDING_DELETE, $basia->fresh()->status);
        $this->assertNotNull($basia->fresh()->delete_requested_at);
    }

    public function test_haslo_i_poprawny_kod_z_aplikacji_cofaja_usuniecie(): void
    {
        $basia = $this->kontoDoUsunieciaZ2fa();

        $this->post(route('account.delete.cancel.store'), [
            'login' => 'basia',
            'password' => self::HASLO,
            'code' => (new Google2FA)->getCurrentOtp($basia->two_factor_secret),
        ])->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $this->assertSame(User::STATUS_ACTIVE, $basia->fresh()->status);
    }

    public function test_haslo_i_kod_zapasowy_cofaja_usuniecie_a_kod_znika(): void
    {
        $basia = $this->kontoDoUsunieciaZ2fa();

        $this->post(route('account.delete.cancel.store'), [
            'login' => 'basia',
            'password' => self::HASLO,
            'code' => 'abcde-23456',
        ])->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $basia = $basia->fresh();
        $this->assertSame(User::STATUS_ACTIVE, $basia->status);
        $this->assertSame([], $basia->two_factor_backup_codes);
    }

    public function test_zly_kod_nie_cofa_i_liczy_sie_do_limitu_prob_konta(): void
    {
        $basia = $this->kontoDoUsunieciaZ2fa();
        $klucz = TwoFactorAuthenticator::kluczLimituProb($basia);

        $this->post(route('account.delete.cancel.store'), [
            'login' => 'basia',
            'password' => self::HASLO,
            'code' => '000000',
        ])->assertSessionHasErrors('code');

        $this->assertSame(User::STATUS_PENDING_DELETE, $basia->fresh()->status);
        $this->assertSame(1, RateLimiter::attempts($klucz));
    }

    /**
     * Koszyk jest TEN SAM co na ekranie kodu przy logowaniu — próby
     * wyczerpane tam blokują też tutaj, nawet przy poprawnym kodzie.
     */
    public function test_po_wyczerpaniu_limitu_nawet_poprawny_kod_nie_cofa(): void
    {
        $basia = $this->kontoDoUsunieciaZ2fa();
        [$maxProb, $minuty] = TwoFactorAuthenticator::limitProb();

        for ($i = 0; $i < $maxProb; $i++) {
            RateLimiter::hit(TwoFactorAuthenticator::kluczLimituProb($basia), $minuty * 60);
        }

        $this->post(route('account.delete.cancel.store'), [
            'login' => 'basia',
            'password' => self::HASLO,
            'code' => (new Google2FA)->getCurrentOtp($basia->two_factor_secret),
        ])->assertSessionHasErrors(['code' => 'Za dużo prób kodu. Spróbuj ponownie za 1 min.']);

        $this->assertSame(User::STATUS_PENDING_DELETE, $basia->fresh()->status);
    }

    public function test_zle_haslo_z_poprawnym_kodem_zapasowym_nie_zuzywa_kodu(): void
    {
        $basia = $this->kontoDoUsunieciaZ2fa();

        $this->post(route('account.delete.cancel.store'), [
            'login' => 'basia',
            'password' => 'nie-to-haslo',
            'code' => 'ABCDE-23456',
        ])->assertSessionHasErrors('login');

        $basia = $basia->fresh();
        $this->assertSame(User::STATUS_PENDING_DELETE, $basia->status);
        $this->assertCount(1, $basia->two_factor_backup_codes);
    }

    public function test_kod_i_haslo_nie_wracaja_w_formularzu_po_bledzie(): void
    {
        $this->kontoDoUsunieciaZ2fa();

        $this->post(route('account.delete.cancel.store'), [
            'login' => 'basia',
            'password' => self::HASLO,
            'code' => 'ZLYKO-D0000',
        ])->assertSessionHasErrors('code');

        $this->assertSame('basia', session()->getOldInput('login'));
        $this->assertNull(session()->getOldInput('code'));
        $this->assertNull(session()->getOldInput('password'));
    }

    public function test_formularz_ma_pole_na_kod_z_widoczna_etykieta(): void
    {
        $this->get(route('account.delete.cancel'))
            ->assertOk()
            ->assertSee('Kod z aplikacji albo kod zapasowy')
            ->assertSee('name="code"', false);
    }

    private function kontoDoUsunieciaZ2fa(): User
    {
        $basia = $this->user('basia', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(5),
        ]);

        $totp = app(TwoFactorAuthenticator::class);
        $basia->beginTwoFactorSetup($totp->generateSecret());
        $basia->confirmTwoFactor($totp->hashBackupCodes(['ABCDE-23456']));

        return $basia->refresh();
    }
}
