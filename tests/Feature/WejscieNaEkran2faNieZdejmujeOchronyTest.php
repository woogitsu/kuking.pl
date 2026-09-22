<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Wejście na ekran włączenia 2FA nie zdejmuje działającej ochrony (audyt W4-02).
 *
 * CO BYŁO NIE TAK
 * `create()` miał warunek `$user->two_factor_secret === null ||
 * $user->hasTwoFactorConfirmed()`, więc SAMO WEJŚCIE pod ten adres przy
 * włączonej weryfikacji wołało `beginTwoFactorSetup()`: podmieniało sekret
 * i zerowało `two_factor_confirmed_at`, kody zapasowe oraz znacznik ostatniego
 * użycia.
 *
 * Drugi składnik przestawał więc działać, ZANIM ktokolwiek potwierdził nowy —
 * bez hasła, bez POST-a, bez tokenu CSRF. Wystarczyło kliknąć link. Dla konta
 * moderatora znaczyło to natychmiastową utratę dostępu do `/admin`, bo
 * `moderator.2fa` widzi konto jako niepotwierdzone. Do tego żądania GET są
 * przepuszczane kontom zawieszonym, więc ta jedna ścieżka omijała także tryb
 * „tylko do odczytu".
 *
 * Żądanie GET nie ma prawa zmieniać stanu bezpieczeństwa. To nie jest
 * subtelność — to jest cała reguła.
 */
class WejscieNaEkran2faNieZdejmujeOchronyTest extends TestCase
{
    use RefreshDatabase;

    public function test_wejscie_na_ekran_wlaczenia_nie_rusza_dzialajacej_2fa(): void
    {
        $basia = $this->user('basia');

        $basia->beginTwoFactorSetup('SEKRETSEKRETSEKR');
        $basia->confirmTwoFactor([Hash::make('kod-zapasowy-1')]);
        $basia->refresh();

        $sekret = $basia->two_factor_secret;
        $potwierdzone = $basia->two_factor_confirmed_at;
        $kody = $basia->two_factor_backup_codes;

        $this->assertNotNull($sekret);
        $this->assertNotNull($potwierdzone);

        $this->actingAs($basia)
            ->get(route('settings.two_factor.enable'))
            ->assertRedirect(route('settings.two_factor.edit'));

        $basia->refresh();

        // NIC nie mogło się zmienić. Każda z tych czterech wartości osobno
        // wystarczy, żeby wyłączyć drugi składnik.
        $this->assertSame($sekret, $basia->two_factor_secret, 'Podmieniony sekret.');
        $this->assertEquals($potwierdzone, $basia->two_factor_confirmed_at, 'Zerowane potwierdzenie.');
        $this->assertEquals($kody, $basia->two_factor_backup_codes, 'Skasowane kody zapasowe.');
        $this->assertTrue($basia->hasTwoFactorConfirmed(), '2FA przestało być włączone po samym GET.');
    }

    public function test_czlowiek_dowiaduje_sie_co_zrobic_zamiast_zobaczyc_pusty_ekran(): void
    {
        // Komunikat ma powiedzieć CO ZROBIĆ (docs/UX_50_PLUS.md). Osoba, która
        // zmienia telefon, ma się dowiedzieć, że drogą jest wyłączenie —
        // a nie zobaczyć przekierowanie bez słowa wyjaśnienia.
        $basia = $this->user('basia');
        $basia->beginTwoFactorSetup('SEKRETSEKRETSEKR');
        $basia->confirmTwoFactor([Hash::make('kod-zapasowy-1')]);

        $this->actingAs($basia)
            ->get(route('settings.two_factor.enable'))
            ->assertRedirect(route('settings.two_factor.edit'))
            ->assertSessionHas('status', fn (string $tekst): bool => str_contains($tekst, 'już włączona')
                && str_contains($tekst, 'wyłącz'));
    }

    public function test_wlaczanie_od_zera_dalej_dziala(): void
    {
        // Kontrola w drugą stronę: naprawa nie może zablokować pierwszego
        // włączenia ani powrotu na ten ekran w trakcie ustawiania.
        $basia = $this->user('basia');

        $this->actingAs($basia)->get(route('settings.two_factor.enable'))->assertOk();

        $basia->refresh();
        $pierwszy = $basia->two_factor_secret;

        $this->assertNotNull($pierwszy);
        $this->assertFalse($basia->hasTwoFactorConfirmed());

        // Powrót na ten sam ekran (odświeżenie, cofnięcie w przeglądarce)
        // pokazuje TEN SAM sekret — inaczej kod QR zeskanowany chwilę wcześniej
        // przestałby pasować.
        $this->actingAs($basia)->get(route('settings.two_factor.enable'))->assertOk();

        $basia->refresh();
        $this->assertSame($pierwszy, $basia->two_factor_secret);
    }

    public function test_zmiana_drugiego_skladnika_idzie_przez_wylaczenie_z_haslem(): void
    {
        // Droga zastąpienia istnieje i prowadzi przez POST z hasłem — czyli
        // dokładnie to, czego brakowało w ścieżce GET.
        // Hasło z fabryki — `user()` nie przyjmuje go osobno, a `casts`
        // ma `'password' => 'hashed'`, więc podanie gotowego skrótu
        // zahaszowałoby go drugi raz.
        $basia = $this->user('basia');
        $basia->beginTwoFactorSetup('SEKRETSEKRETSEKR');
        $basia->confirmTwoFactor([Hash::make('kod-zapasowy-1')]);

        // Bez hasła — nic się nie dzieje.
        $this->actingAs($basia)
            ->from(route('settings.two_factor.edit'))
            ->post(route('settings.two_factor.disable'), ['password' => 'zle-haslo'])
            ->assertSessionHasErrorsIn('disable', ['password']);

        $this->assertTrue($basia->refresh()->hasTwoFactorConfirmed());

        // Z hasłem — wyłączone, i dopiero teraz ekran włączenia znowu działa.
        $this->actingAs($basia)
            ->post(route('settings.two_factor.disable'), ['password' => 'haslo-testowe-123'])
            ->assertRedirect(route('settings.two_factor.edit'));

        $this->assertFalse($basia->refresh()->hasTwoFactorConfirmed());
        $this->actingAs($basia)->get(route('settings.two_factor.enable'))->assertOk();
    }
}
