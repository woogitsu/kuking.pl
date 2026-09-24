<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Włączenie 2FA wymaga obecnego hasła, nie tylko otwartej sesji (#1376, D-245).
 *
 * PRZED POPRAWKĄ `POST /ustawienia/2fa/wlacz` sprawdzał wyłącznie kod
 * z NOWO wygenerowanego sekretu. Kod dowodzi, że nowy telefon jest dobrze
 * ustawiony — nie tego, że przy sesji siedzi właściciel. Kto przejął ważną
 * sesję, podpinał własny telefon i zabierał kody zapasowe; właściciel przy
 * następnym logowaniu stawał przed kodem, którego nie ma.
 *
 * Sesja w tych testach powstaje z PRAWDZIWEGO logowania hasłem (`POST
 * /login`), nie z `actingAs()`: chodzi dokładnie o przypadek, w którym
 * sesja jest ważna i pełna, a mimo to nie wystarcza.
 */
class WlaczenieDwuetapowejWymagaHaslaTest extends TestCase
{
    use RefreshDatabase;

    private const HASLO = 'haslo-testowe-123';

    private function zalogowanaZSekretem(): array
    {
        $basia = $this->user('basia');

        $this->post('/login', ['login' => $basia->email, 'password' => self::HASLO])->assertRedirect();
        $this->assertAuthenticatedAs($basia);

        $this->get(route('settings.two_factor.enable'))->assertOk();
        $sekret = $basia->refresh()->two_factor_secret;
        $this->assertNotNull($sekret);

        return [$basia, (new Google2FA)->getCurrentOtp($sekret)];
    }

    private function assertNieWlaczone(User $basia): void
    {
        $basia->refresh();
        $this->assertFalse($basia->hasTwoFactorConfirmed(), 'Samo posiadanie sesji włączyło 2FA.');
        $this->assertNull($basia->two_factor_confirmed_at);
        $this->assertEmpty($basia->two_factor_backup_codes, 'Kody zapasowe powstały mimo odmowy.');
    }

    public function test_sama_sesja_i_kod_z_nowego_telefonu_nie_wlaczaja_2fa(): void
    {
        [$basia, $kod] = $this->zalogowanaZSekretem();

        $this->from(route('settings.two_factor.enable'))
            ->post(route('settings.two_factor.confirm'), ['code' => $kod])
            ->assertRedirect(route('settings.two_factor.enable'))
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('kody_zapasowe');

        $this->assertNieWlaczone($basia);
    }

    public function test_zle_haslo_nie_wlacza_2fa_i_nie_zuzywa_kodu(): void
    {
        [$basia, $kod] = $this->zalogowanaZSekretem();

        $this->from(route('settings.two_factor.enable'))
            ->post(route('settings.two_factor.confirm'), ['code' => $kod, 'password' => 'nie-to-haslo'])
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('kody_zapasowe');

        $this->assertNieWlaczone($basia);

        // Ten sam kod z poprawnym hasłem przechodzi — zła próba go nie spaliła.
        $this->post(route('settings.two_factor.confirm'), ['code' => $kod, 'password' => self::HASLO])
            ->assertRedirect(route('settings.two_factor.codes'));

        $this->assertTrue($basia->refresh()->hasTwoFactorConfirmed());
    }

    public function test_kontrola_dodatnia_z_haslem_i_kodem_2fa_sie_wlacza(): void
    {
        [$basia, $kod] = $this->zalogowanaZSekretem();

        $this->post(route('settings.two_factor.confirm'), ['code' => $kod, 'password' => self::HASLO])
            ->assertRedirect(route('settings.two_factor.codes'))
            ->assertSessionHas('kody_zapasowe');

        $basia->refresh();
        $this->assertTrue($basia->hasTwoFactorConfirmed());
        $this->assertNotEmpty($basia->two_factor_backup_codes);
    }

    public function test_dobre_haslo_nie_ratuje_zlego_kodu(): void
    {
        [$basia] = $this->zalogowanaZSekretem();

        $this->post(route('settings.two_factor.confirm'), ['code' => '000000', 'password' => self::HASLO])
            ->assertSessionHasErrors('code');

        $this->assertNieWlaczone($basia);
    }

    public function test_ekran_wlaczenia_pyta_o_haslo_i_mowi_co_zrobic_bez_hasla(): void
    {
        $this->zalogowanaZSekretem();

        $ekran = $this->get(route('settings.two_factor.enable'))->assertOk();
        $tresc = (string) $ekran->getContent();

        $formularz = substr($tresc, (int) strpos($tresc, 'action="'.route('settings.two_factor.confirm').'"'));
        $this->assertStringContainsString('name="password"', $formularz, 'Formularz włączenia nie ma pola hasła.');
        $this->assertStringContainsString('autocomplete="current-password"', $formularz);
        $this->assertStringContainsString('Jeśli logujesz się przez Google', $tresc);
    }
}
