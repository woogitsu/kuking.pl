<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Zawieszone konto da się zabezpieczyć: hasło, inne urządzenia, 2FA
 * (audyt B2-04, D-259).
 *
 * CO SIĘ DZIAŁO
 * Konto przejęte przez spamera zostaje zawieszone za to, co robił napastnik.
 * Właściciel odzyskuje dostęp resetem, ale `EnsureAccountIsActive` odbijał
 * zmianę hasła, „Wyloguj mnie z innych urządzeń” i każdą zmianę 2FA — aż do
 * końca kary. Napastnik zostawał w swojej sesji.
 *
 * Kontrola dodatnia: publikacja dalej jest odbijana.
 */
class ZawieszonyZabezpieczaKontoTest extends TestCase
{
    use RefreshDatabase;

    private function zawieszona(): User
    {
        $osoba = $this->user('zawieszona');
        $osoba->suspend();

        return $osoba->refresh();
    }

    private function cudzaSesja(string $userId): void
    {
        DB::table('sessions')->insert([
            'id' => 'sesja-napastnika',
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'urzadzenie-napastnika',
            'payload' => '',
            'last_activity' => time(),
        ]);
    }

    private function biezacaSesja(string $userId): void
    {
        $id = Str::random(40);

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'biezace-urzadzenie',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->withCookie((string) config('session.cookie'), $id);
    }

    public function test_zawieszona_osoba_zmienia_haslo(): void
    {
        $osoba = $this->zawieszona();
        $this->assertTrue($osoba->isSuspended());

        $this->actingAs($osoba)
            ->from(route('settings.security'))
            ->put(route('settings.security.password'), [
                'current_password' => 'haslo-testowe-123',
                'password' => 'zupelnieinnehaslo456',
                'password_confirmation' => 'zupelnieinnehaslo456',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('zupelnieinnehaslo456', $osoba->fresh()->password), 'Zawieszona osoba nie mogła zmienić hasła.');
    }

    public function test_zawieszona_osoba_wylogowuje_inne_urzadzenia(): void
    {
        config(['session.driver' => 'database']);
        $osoba = $this->zawieszona();
        $this->cudzaSesja($osoba->getKey());
        $this->biezacaSesja($osoba->getKey());

        $this->actingAs($osoba)
            ->from(route('settings.security'))
            ->post(route('settings.security.logout-others'), ['password' => 'haslo-testowe-123'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'sesja-napastnika']);
    }

    public function test_zawieszona_osoba_wylacza_i_wlacza_2fa(): void
    {
        $osoba = $this->zawieszona();
        $totp = app(TwoFactorAuthenticator::class);
        $osoba->beginTwoFactorSetup($totp->generateSecret());
        $osoba->confirmTwoFactor($totp->hashBackupCodes(['ABCD-1234']));
        $osoba->refresh();

        $this->actingAs($osoba)
            ->post(route('settings.two_factor.regenerate'), ['password' => 'haslo-testowe-123'])
            ->assertSessionHasNoErrors();

        $this->actingAs($osoba)
            ->post(route('settings.two_factor.disable'), ['password' => 'haslo-testowe-123'])
            ->assertRedirect(route('settings.two_factor.edit'));
        $this->assertFalse($osoba->refresh()->hasTwoFactorConfirmed(), 'Zawieszona osoba nie mogła wyłączyć 2FA napastnika.');

        $this->actingAs($osoba)->get(route('settings.two_factor.enable'))->assertOk();
        $kod = (new Google2FA)->getCurrentOtp($osoba->refresh()->two_factor_secret);

        $this->actingAs($osoba)
            ->post(route('settings.two_factor.confirm'), ['code' => $kod, 'password' => 'haslo-testowe-123'])
            ->assertRedirect(route('settings.two_factor.codes'));
        $this->assertTrue($osoba->refresh()->hasTwoFactorConfirmed());
    }

    public function test_publikacja_dalej_zablokowana(): void
    {
        $wpis = Post::factory()->create(['author_id' => $this->user()->getKey()]);
        $osoba = $this->zawieszona();

        $this->actingAs($osoba)
            ->post(route('posts.comment', $wpis), ['body' => 'Nie powinno wyjść.'])
            ->assertSessionHasErrors('konto');
        $this->assertDatabaseMissing('comments', ['body' => 'Nie powinno wyjść.']);
    }
}
