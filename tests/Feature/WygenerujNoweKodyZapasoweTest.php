<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\Actions\WygenerujNoweKodyZapasowe;
use App\Domain\Security\TwoFactorAuthenticator;
use App\Domain\Security\WynikNowychKodowZapasowych as Wynik;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `WygenerujNoweKodyZapasowe` — nowy komplet kodów wyjęty z
 * `TwoFactorSettingsController::regenerateCodes()` (#970).
 *
 * Akcja wołana bez HTTP. Kolejność (2FA włączona, hasło, migawka, skróty przed
 * blokadą, porównanie migawki pod blokadą) zostaje jak przed wyjęciem; przebieg
 * przez przeglądarkę i dwa połączenia pilnują dalej `Ustawienia2faBledyTest`
 * i `tests/Dwa/RegeneracjaKodowZapasowychNaDwochPolaczeniachTest`.
 */
class WygenerujNoweKodyZapasoweTest extends TestCase
{
    use RefreshDatabase;

    private const HASLO = 'haslo-testowe-123';

    private function akcja(): WygenerujNoweKodyZapasowe
    {
        return app(WygenerujNoweKodyZapasowe::class);
    }

    private function zWlaczona2fa(): User
    {
        $totp = app(TwoFactorAuthenticator::class);
        $konto = $this->user('basia');
        $konto->beginTwoFactorSetup($totp->generateSecret());
        $konto->confirmTwoFactor($totp->hashBackupCodes($totp->generateBackupCodes()));

        return $konto->refresh();
    }

    #[Test]
    public function dobre_haslo_wymienia_komplet_i_zwraca_jawne_kody(): void
    {
        $konto = $this->zWlaczona2fa();
        $stary = $konto->getRawOriginal('two_factor_backup_codes');

        $wynik = $this->akcja()->handle($konto, self::HASLO);

        $this->assertSame(Wynik::ZAPISANE, $wynik->status);
        $this->assertCount(8, $wynik->kodyJawne);
        $this->assertNotSame($stary, $konto->getRawOriginal('two_factor_backup_codes'));
        $this->assertTrue($konto->hasTwoFactorConfirmed(), 'Sekret i potwierdzenie zostają ruszone tylko przez kody.');
        $this->assertSame(0, AuditLogEntry::query()->where('action', 'like', 'account.two_factor%')->count());
    }

    #[Test]
    public function wylaczona_2fa_konczy_przed_sprawdzeniem_hasla(): void
    {
        $konto = $this->user();

        $wynik = $this->akcja()->handle($konto, 'nie-to-haslo');

        $this->assertSame(Wynik::WYLACZONE, $wynik->status);
        $this->assertSame([], $wynik->kodyJawne);
    }

    #[Test]
    public function zle_haslo_nie_zmienia_kompletu(): void
    {
        $konto = $this->zWlaczona2fa();
        $stary = $konto->getRawOriginal('two_factor_backup_codes');

        $wynik = $this->akcja()->handle($konto, 'nie-to-haslo');

        $this->assertSame(Wynik::ZLE_HASLO, $wynik->status);
        $this->assertSame([], $wynik->kodyJawne);
        $this->assertSame($stary, $konto->refresh()->getRawOriginal('two_factor_backup_codes'));
    }

    #[Test]
    public function komplet_zapisany_przez_druga_karte_zostaje_wazny(): void
    {
        $konto = $this->zWlaczona2fa();

        // Druga karta zapisała własny komplet po tym, jak to żądanie wczytało konto.
        $druga = User::query()->whereKey($konto->getKey())->firstOrFail();
        $druga->replaceTwoFactorBackupCodes(['skrot-z-drugiej-karty']);
        $komplet = $druga->refresh()->getRawOriginal('two_factor_backup_codes');

        $wynik = $this->akcja()->handle($konto, self::HASLO);

        $this->assertSame(Wynik::ZMIENIONE, $wynik->status);
        $this->assertSame([], $wynik->kodyJawne, 'Nic nie może być pokazane, gdy komplet z drugiej karty wygrał.');
        $this->assertSame($komplet, $konto->getRawOriginal('two_factor_backup_codes'));
    }

    #[Test]
    public function wylaczenie_2fa_w_trakcie_zadania_konczy_sie_wylaczone_bez_zapisu(): void
    {
        $konto = $this->zWlaczona2fa();

        User::query()->whereKey($konto->getKey())->firstOrFail()->disableTwoFactor();

        $wynik = $this->akcja()->handle($konto, self::HASLO);

        $this->assertSame(Wynik::WYLACZONE, $wynik->status);
        $this->assertNull($konto->refresh()->two_factor_secret);
    }

    #[Test]
    public function blokada_konta_wchodzi_przed_zapisem_kompletu(): void
    {
        $konto = $this->zWlaczona2fa();

        $kolejnosc = [];
        DB::listen(function (QueryExecuted $zapytanie) use (&$kolejnosc): void {
            if (str_contains($zapytanie->sql, 'for update') && str_contains($zapytanie->sql, '"users"')) {
                $kolejnosc[] = 'blokada_konta';
            } elseif (str_starts_with($zapytanie->sql, 'update "users"')) {
                $kolejnosc[] = 'zapis_konta';
            }
        });

        $this->akcja()->handle($konto, self::HASLO);

        $this->assertSame('blokada_konta', $kolejnosc[0] ?? null);
        $this->assertContains('zapis_konta', $kolejnosc);
    }
}
