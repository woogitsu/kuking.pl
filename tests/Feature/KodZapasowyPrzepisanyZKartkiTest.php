<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Kod zapasowy przepisany z kartki (issue #875).
 *
 * Człowiek bez telefonu wpisuje kod tak, jak go przeczytał: ze spacją
 * zamiast myślnika, bez myślnika, małymi literami albo z pauzą, którą
 * wstawiła autokorekta. Te różnice nie niosą żadnej tajemnicy, więc nie
 * mogą odbierać dostępu do konta. Znaków natomiast nie zgadujemy:
 * O w miejscu 0 nadal jest innym kodem.
 */
class KodZapasowyPrzepisanyZKartkiTest extends TestCase
{
    use RefreshDatabase;

    private function totp(): TwoFactorAuthenticator
    {
        return app(TwoFactorAuthenticator::class);
    }

    /** @param  array<int, string>  $kody */
    private function kontoZKodami(array $kody): User
    {
        $user = $this->user('basia', ['email' => 'basia@example.com']);
        $user->beginTwoFactorSetup($this->totp()->generateSecret());
        $user->confirmTwoFactor($this->totp()->hashBackupCodes($kody));

        return $user->refresh();
    }

    public function test_nowy_kod_dziala_ze_spacja_bez_myslnika_i_malymi_literami(): void
    {
        $user = $this->kontoZKodami(['ABCDE-FGHJK', 'LMNPQ-RSTUV', 'WXYZ2-34567', '89ABC-DEFGH']);

        $this->assertTrue($this->totp()->consumeBackupCode($user, 'abcde fghjk'));
        $this->assertTrue($this->totp()->consumeBackupCode($user, 'LMNPQRSTUV'));
        $this->assertTrue($this->totp()->consumeBackupCode($user, ' wx yz2 – 345 67 '));
        $this->assertTrue($this->totp()->consumeBackupCode($user, "89ABC\u{2014}DEFGH"));

        // Każdy nadal działa tylko RAZ, w dowolnym zapisie.
        $this->assertFalse($this->totp()->consumeBackupCode($user, 'ABCDE-FGHJK'));
        $this->assertSame([], $user->fresh()->two_factor_backup_codes);
    }

    public function test_wydany_wczesniej_kod_starego_formatu_dziala_bez_zamiany_znakow(): void
    {
        // Stary generator: cztery i cztery znaki, z 0/O i 1/I. Komplet
        // użytkownika NIE jest unieważniany wdrożeniem.
        $user = $this->kontoZKodami(['0O1I-AB12', 'WXYZ-9876']);

        $this->assertFalse($this->totp()->consumeBackupCode($user, 'OO1I AB12'));
        $this->assertFalse($this->totp()->consumeBackupCode($user, '0O11 AB12'));
        $this->assertCount(2, $user->fresh()->two_factor_backup_codes);

        $this->assertTrue($this->totp()->consumeBackupCode($user, '0o1i ab12'));
        $this->assertTrue($this->totp()->consumeBackupCode($user, 'WXYZ9876'));
        $this->assertSame([], $user->fresh()->two_factor_backup_codes);
    }

    public function test_sprawdzenie_bez_zuzycia_stosuje_te_sama_tolerancje(): void
    {
        $user = $this->kontoZKodami(['ABCDE-FGHJK']);

        $this->assertTrue($this->totp()->backupCodeMatches($user, 'abcde fghjk'));
        $this->assertFalse($this->totp()->backupCodeMatches($user, 'ABCDE FGHJ'));
        $this->assertCount(1, $user->fresh()->two_factor_backup_codes);
    }

    public function test_logowanie_przyjmuje_kod_przepisany_ze_spacja(): void
    {
        $user = $this->kontoZKodami(['ABCDE-FGHJK']);

        $this->post('/login', ['login' => 'basia@example.com', 'password' => 'haslo-testowe-123']);
        $this->post(route('login.two_factor.store'), ['backup_code' => 'abcde fghjk'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame([], $user->fresh()->two_factor_backup_codes);
    }

    public function test_kod_z_innym_znakiem_nadal_odmawia_i_liczy_sie_do_limitu(): void
    {
        $user = $this->kontoZKodami(['ABCDE-FGHJK']);
        $klucz = TwoFactorAuthenticator::kluczLimituProb($user);

        $this->post('/login', ['login' => 'basia@example.com', 'password' => 'haslo-testowe-123']);
        $this->post(route('login.two_factor.store'), ['backup_code' => 'abcde fghjx'])
            ->assertSessionHasErrors('backup_code');

        $this->assertGuest();
        $this->assertSame(1, RateLimiter::attempts($klucz));
        $this->assertCount(1, $user->fresh()->two_factor_backup_codes);
    }
}
