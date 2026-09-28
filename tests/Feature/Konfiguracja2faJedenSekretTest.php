<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Jeden sekret i jeden komplet kodów na jedno włączenie 2FA (issue #2061).
 *
 * Wyścig dwóch kart mierzy `tests/Dwa/OpoznionyEkran2faNieNadpisujeSekretuTest`.
 * Tu są KONTROLE DODATNIE na jednym połączeniu: zwykłe pierwsze włączenie
 * dalej działa, ponowne wejście przed potwierdzeniem pokazuje TEN SAM sekret
 * co w kodzie QR, a nowa metoda nie zapisuje sekretu, gdy konfiguracja już
 * się zaczęła. Do tego stary formularz potwierdzenia przy włączonej 2FA nie
 * wymienia kodów zapasowych — nowy komplet daje tylko osobny przycisk.
 */
class Konfiguracja2faJedenSekretTest extends TestCase
{
    use RefreshDatabase;

    public function test_pierwsze_wejscie_zapisuje_sekret_a_ponowne_pokazuje_ten_sam(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->get(route('settings.two_factor.enable'))->assertOk();
        $sekret = $basia->refresh()->two_factor_secret;
        $this->assertNotNull($sekret);
        $this->assertFalse($basia->hasTwoFactorConfirmed());

        $this->get(route('settings.two_factor.enable'))->assertOk()->assertSee($sekret);
        $this->assertSame($sekret, $basia->refresh()->two_factor_secret);

        $this->post(route('settings.two_factor.confirm'), [
            'code' => (new Google2FA)->getCurrentOtp($sekret),
            'password' => 'haslo-testowe-123',
        ])->assertRedirect(route('settings.two_factor.codes'));

        $basia->refresh();
        $this->assertTrue($basia->hasTwoFactorConfirmed());
        $this->assertSame($sekret, $basia->two_factor_secret);
        $this->assertNotEmpty($basia->two_factor_backup_codes);
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'account.two_factor_enabled')->count());
    }

    public function test_warunkowy_poczatek_nie_zapisuje_sekretu_gdy_konfiguracja_sie_zaczela(): void
    {
        $basia = $this->user('basia');
        $staryModel = User::query()->findOrFail($basia->getKey());

        $this->assertTrue($basia->beginTwoFactorSetupIfNotStarted('AAAAAAAAAAAAAAAA'));

        // Model z początku „drugiego żądania" wciąż widzi NULL — decyduje baza.
        $this->assertNull($staryModel->two_factor_secret);
        $this->assertFalse($staryModel->beginTwoFactorSetupIfNotStarted('BBBBBBBBBBBBBBBB'));
        $this->assertSame('AAAAAAAAAAAAAAAA', $staryModel->two_factor_secret, 'Model wywołującego ma przyjąć stan z bazy.');
        $this->assertSame('AAAAAAAAAAAAAAAA', $basia->refresh()->two_factor_secret);
    }

    public function test_stary_formularz_potwierdzenia_przy_wlaczonej_2fa_nie_wymienia_kodow(): void
    {
        $basia = $this->user('basia');
        $basia->beginTwoFactorSetup('SEKRETSEKRETSEKR');
        $basia->confirmTwoFactor([Hash::make('kod-zapasowy-1')]);
        $kody = $basia->refresh()->two_factor_backup_codes;

        $this->actingAs($basia)->post(route('settings.two_factor.confirm'), [
            'code' => (new Google2FA)->getCurrentOtp('SEKRETSEKRETSEKR'),
            'password' => 'haslo-testowe-123',
        ])->assertRedirect(route('settings.two_factor.edit'))
            ->assertSessionHas('status')
            ->assertSessionMissing('kody_zapasowe');

        $basia->refresh();
        $this->assertTrue($basia->hasTwoFactorConfirmed());
        $this->assertSame($kody, $basia->two_factor_backup_codes);
        $this->assertNull($basia->two_factor_last_used_at, 'Kod nie powinien był zostać sprawdzony ani zużyty.');
    }
}
