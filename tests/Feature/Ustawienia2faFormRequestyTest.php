<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\AuditLogEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Wejście HTTP 2FA i zaproszenia po wyjęciu walidacji do Form Requestów (#970).
 *
 * Komunikaty, worki błędów i odmowy dla gościa zostają jak przed wyjęciem.
 * Przebieg z hasłem i kodem pilnuje `WlaczenieDwuetapowejWymagaHaslaTest`.
 */
class Ustawienia2faFormRequestyTest extends TestCase
{
    use RefreshDatabase;

    private const HASLO = 'haslo-testowe-123';

    #[Test]
    public function wlaczenie_bez_pol_zwraca_polskie_komunikaty_w_domyslnym_worku(): void
    {
        $konto = $this->user();

        $this->actingAs($konto)->from(route('settings.two_factor.enable'))
            ->post(route('settings.two_factor.confirm'), [])
            ->assertRedirect(route('settings.two_factor.enable'))
            ->assertSessionHasErrors([
                'code' => 'Wpisz sześciocyfrowy kod z aplikacji.',
                'password' => 'Wpisz hasło do Kuking, żeby włączyć weryfikację dwuetapową.',
            ]);
    }

    #[Test]
    public function nowe_kody_bez_hasla_wracaja_w_worku_regenerate(): void
    {
        $konto = $this->user();

        $this->actingAs($konto)->from(route('settings.two_factor.edit'))
            ->post(route('settings.two_factor.regenerate'), [])
            ->assertRedirect(route('settings.two_factor.edit'))
            ->assertSessionHasErrorsIn('regenerate', ['password' => 'Wpisz hasło do Kuking, żeby dostać nowe kody zapasowe.'])
            ->assertSessionDoesntHaveErrors(['password']);
    }

    #[Test]
    public function wylaczenie_bez_hasla_wraca_w_worku_disable(): void
    {
        $konto = $this->user();

        $this->actingAs($konto)->from(route('settings.two_factor.edit'))
            ->post(route('settings.two_factor.disable'), [])
            ->assertRedirect(route('settings.two_factor.edit'))
            ->assertSessionHasErrorsIn('disable', ['password' => 'Wpisz hasło do Kuking, żeby wyłączyć weryfikację dwuetapową.']);
    }

    #[Test]
    public function zle_haslo_przy_nowych_kodach_wraca_w_worku_regenerate_i_nie_rusza_kompletu(): void
    {
        $konto = $this->user();
        $totp = app(TwoFactorAuthenticator::class);
        $konto->beginTwoFactorSetup($totp->generateSecret());
        $konto->confirmTwoFactor($totp->hashBackupCodes(['TEST-TEST']));
        $komplet = $konto->refresh()->getRawOriginal('two_factor_backup_codes');

        $this->actingAs($konto)->from(route('settings.two_factor.edit'))
            ->post(route('settings.two_factor.regenerate'), ['password' => 'nie-to-haslo'])
            ->assertSessionHasErrorsIn('regenerate', [
                'password' => 'Wpisz ponownie hasło do Kuking. Jeśli go nie pamiętasz, skorzystaj z instrukcji przy formularzu.',
            ])
            ->assertSessionMissing('kody_zapasowe');

        $this->assertSame($komplet, $konto->refresh()->getRawOriginal('two_factor_backup_codes'));
    }

    #[Test]
    public function wlaczenie_przez_http_zostawia_jeden_wpis_audytu_i_kody_w_flashu(): void
    {
        $konto = $this->user();
        $konto->beginTwoFactorSetup($sekret = app(TwoFactorAuthenticator::class)->generateSecret());

        $this->actingAs($konto)
            ->post(route('settings.two_factor.confirm'), [
                'code' => (new Google2FA)->getCurrentOtp($sekret),
                'password' => self::HASLO,
            ])
            ->assertRedirect(route('settings.two_factor.codes'))
            ->assertSessionHas('kody_zapasowe', fn (array $kody): bool => count($kody) === 8);

        $this->assertSame(1, AuditLogEntry::query()->where('action', 'account.two_factor_enabled')->count());
    }

    #[Test]
    public function gosc_nie_wchodzi_na_zadna_z_tras_2fa(): void
    {
        foreach (['confirm', 'regenerate', 'disable'] as $trasa) {
            $this->post(route('settings.two_factor.'.$trasa), ['password' => self::HASLO, 'code' => '123456'])
                ->assertRedirect(route('login'));
        }
    }

    #[Test]
    public function przyjecie_zaproszenia_bez_tokenu_wyglada_jak_przy_tokenie_nieznanym(): void
    {
        config([
            'kuking.login_link.zaproszenia.wlaczone' => true,
            'kuking.account.registration_open' => true,
        ]);

        $bez = $this->post(route('zaproszenie.przyjmij'), []);
        $nieznany = $this->post(route('zaproszenie.przyjmij'), ['token' => 'nie-ma-takiego-tokenu']);

        $bez->assertRedirect(route('register'))->assertSessionDoesntHaveErrors();
        $nieznany->assertRedirect(route('register'));
        $this->assertSame($bez->getStatusCode(), $nieznany->getStatusCode());
    }
}
