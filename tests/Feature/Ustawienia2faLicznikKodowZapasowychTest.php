<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Liczba pozostałych kodów zapasowych w ustawieniach 2FA (#2575). */
class Ustawienia2faLicznikKodowZapasowychTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $jawne = [];

    private function totp(): TwoFactorAuthenticator
    {
        return app(TwoFactorAuthenticator::class);
    }

    private function zDwuetapowa(int $ile): User
    {
        $user = $this->user('basia', ['email' => 'basia@example.com']);
        $user->beginTwoFactorSetup($this->totp()->generateSecret());
        $this->jawne = [];
        for ($i = 1; $i <= $ile; $i++) {
            $this->jawne[] = sprintf('ABCDE-%05d', $i);
        }
        $user->confirmTwoFactor($this->totp()->hashBackupCodes($this->jawne));

        return $user->refresh();
    }

    private function strona(User $user): string
    {
        return $this->actingAs($user)->get(route('settings.two_factor.edit'))->assertOk()->getContent();
    }

    public function test_pokazuje_liczbe_po_zuzyciu_kodow_i_poprawna_odmiane(): void
    {
        $user = $this->zDwuetapowa(6);
        $this->assertStringContainsString('Zostało 6 kodów zapasowych.', $this->strona($user));

        $this->assertTrue($this->totp()->consumeBackupCode($user, $this->jawne[0]));
        $this->assertStringContainsString('Zostało 5 kodów zapasowych.', $this->strona($user->fresh()));

        foreach ([1, 2, 3] as $i) {
            $this->assertTrue($this->totp()->consumeBackupCode($user, $this->jawne[$i]));
        }
        $this->assertStringContainsString('Zostały 2 kody zapasowe.', $this->strona($user->fresh()));

        $this->assertTrue($this->totp()->consumeBackupCode($user, $this->jawne[4]));
        $this->assertStringContainsString('Został 1 kod zapasowy.', $this->strona($user->fresh()));
    }

    public function test_zly_kod_nie_zmniejsza_licznika(): void
    {
        $user = $this->zDwuetapowa(3);
        $this->assertFalse($this->totp()->consumeBackupCode($user, 'ZZZZZ-ZZZZZ'));
        $this->assertStringContainsString('Zostały 3 kody zapasowe.', $this->strona($user->fresh()));
    }

    public function test_zero_kodow_mowi_to_slowami_i_prowadzi_do_regeneracji_bez_wylaczania_2fa(): void
    {
        $user = $this->zDwuetapowa(1);
        $this->assertTrue($this->totp()->consumeBackupCode($user, $this->jawne[0]));

        $html = $this->strona($user->fresh());

        $this->assertStringContainsString('Nie zostały Ci żadne kody zapasowe.', $html);
        $this->assertStringContainsString('Wygeneruj nowe kody zapasowe', $html);
        $this->assertStringNotContainsString('Został 0', $html);
        $this->assertStringNotContainsString('Zostało 0', $html);
        $this->assertStringContainsString('Włączona', $html);
        $this->assertTrue($user->fresh()->hasTwoFactorConfirmed());
    }

    public function test_regeneracja_ustawia_liczbe_z_konfiguracji_a_samo_wejscie_niczego_nie_zmienia(): void
    {
        config(['kuking.two_factor.recovery_codes' => 5]);
        $user = $this->zDwuetapowa(1);
        $przed = $user->fresh()->getRawOriginal('two_factor_backup_codes');

        $this->strona($user);
        $this->strona($user);
        $this->assertSame($przed, $user->fresh()->getRawOriginal('two_factor_backup_codes'));

        $this->actingAs($user)
            ->post(route('settings.two_factor.regenerate'), ['password' => 'haslo-testowe-123'])
            ->assertRedirect(route('settings.two_factor.codes'));

        $this->assertStringContainsString('Zostało 5 kodów zapasowych.', $this->strona($user->fresh()));
    }

    public function test_wylaczona_i_niepotwierdzona_2fa_nie_pokazuje_licznika(): void
    {
        $user = $this->user('ola', ['email' => 'ola@example.com']);
        $html = $this->strona($user);
        $this->assertStringNotContainsString('kodów zapasowych', $html);
        $this->assertStringNotContainsString('Nie zostały Ci żadne kody', $html);

        $user->beginTwoFactorSetup($this->totp()->generateSecret());
        $html = $this->strona($user->fresh());
        $this->assertStringNotContainsString('kodów zapasowych', $html);
        $this->assertStringNotContainsString('Nie zostały Ci żadne kody', $html);
    }

    public function test_html_nie_ujawnia_skrotow_ani_jawnych_kodow_ani_sekretu(): void
    {
        $user = $this->zDwuetapowa(3);
        $html = $this->strona($user);

        foreach ($user->two_factor_backup_codes as $skrot) {
            $this->assertStringNotContainsString($skrot, $html);
        }
        foreach ($this->jawne as $kod) {
            $this->assertStringNotContainsString($kod, $html);
        }
        $this->assertStringNotContainsString($user->two_factor_secret, $html);
        $this->assertStringNotContainsString('$2y$', $html);
    }

    public function test_licznik_dotyczy_tylko_zalogowanego_wlasciciela(): void
    {
        $this->zDwuetapowa(4);
        $this->get(route('settings.two_factor.edit'))->assertRedirect();

        $inna = $this->user('inna', ['email' => 'inna@example.com']);
        $this->assertStringNotContainsString('kodów zapasowych', $this->strona($inna));
    }
}
