<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\Actions\WlaczDwuetapowa;
use App\Domain\Security\TwoFactorAuthenticator;
use App\Domain\Security\WynikWlaczeniaDwuetapowej as Wynik;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * `WlaczDwuetapowa` — potwierdzenie 2FA wyjęte z `TwoFactorSettingsController::confirm()` (#970).
 *
 * Akcja wołana bez HTTP. Kolejność (brak sekretu, już włączona, hasło, kod,
 * blokada konta, audyt) zostaje jak przed wyjęciem; przebieg przez przeglądarkę
 * pilnują dalej `WlaczenieDwuetapowejWymagaHaslaTest` i `Konfiguracja2faJedenSekretTest`.
 */
class WlaczDwuetapowaTest extends TestCase
{
    use RefreshDatabase;

    private const HASLO = 'haslo-testowe-123';

    private function akcja(): WlaczDwuetapowa
    {
        return app(WlaczDwuetapowa::class);
    }

    private function wlacz(User $konto, string $haslo, string $kod, ?string $ip): Wynik
    {
        return $this->akcja()->handle($konto, $haslo, $kod, $ip, (int) $konto->session_generation, 'testowa-sesja');
    }

    /** @return array{0: User, 1: string} konto z niepotwierdzonym sekretem i aktualny kod */
    private function zaczete(): array
    {
        $konto = $this->user('basia');
        $sekret = app(TwoFactorAuthenticator::class)->generateSecret();
        $konto->beginTwoFactorSetup($sekret);

        return [$konto->refresh(), (new Google2FA)->getCurrentOtp($sekret)];
    }

    private function wpisow(): int
    {
        return AuditLogEntry::query()->where('action', 'account.two_factor_enabled')->count();
    }

    #[Test]
    public function poprawne_haslo_i_kod_wlaczaja_2fa_zapisuja_kody_i_jeden_wpis_audytu(): void
    {
        [$konto, $kod] = $this->zaczete();

        $wynik = $this->wlacz($konto, self::HASLO, $kod, '203.0.113.7');

        $this->assertSame(Wynik::WLACZONO, $wynik->status);
        $this->assertCount(8, $wynik->kodyJawne);
        $this->assertTrue($konto->hasTwoFactorConfirmed(), 'Konto po akcji ma być odświeżone.');
        $this->assertCount(8, $konto->two_factor_backup_codes);
        $this->assertSame(1, $this->wpisow());
    }

    #[Test]
    public function brak_sekretu_odsyla_na_ekran_wlaczenia_bez_zapisu(): void
    {
        $konto = $this->user();

        $wynik = $this->wlacz($konto, self::HASLO, '123456', null);

        $this->assertSame(Wynik::BRAK_SEKRETU, $wynik->status);
        $this->assertSame([], $wynik->kodyJawne);
        $this->assertSame(0, $this->wpisow());
    }

    #[Test]
    public function juz_wlaczona_2fa_nie_sprawdza_kodu_i_nie_wymienia_kompletu(): void
    {
        [$konto, $kod] = $this->zaczete();
        $this->wlacz($konto, self::HASLO, $kod, null);
        $komplet = $konto->refresh()->getRawOriginal('two_factor_backup_codes');

        $wynik = $this->wlacz($konto, self::HASLO, '000000', null);

        $this->assertSame(Wynik::JUZ_WLACZONE, $wynik->status);
        $this->assertSame($komplet, $konto->refresh()->getRawOriginal('two_factor_backup_codes'));
        $this->assertSame(1, $this->wpisow());
    }

    #[Test]
    public function zle_haslo_nie_sprawdza_ani_nie_zuzywa_kodu(): void
    {
        [$konto, $kod] = $this->zaczete();

        $wynik = $this->wlacz($konto, 'nie-to-haslo', $kod, null);

        $this->assertSame(Wynik::ZLE_HASLO, $wynik->status);
        $this->assertNull($konto->refresh()->two_factor_last_used_at, 'Kod nie mógł zostać zużyty.');
        $this->assertFalse($konto->hasTwoFactorConfirmed());

        // Ten sam kod z dobrym hasłem przechodzi — zła próba go nie spaliła.
        $this->assertSame(Wynik::WLACZONO, $this->wlacz($konto, self::HASLO, $kod, null)->status);
    }

    #[Test]
    public function zly_kod_przy_dobrym_hasle_nie_wlacza_2fa(): void
    {
        [$konto] = $this->zaczete();

        $wynik = $this->wlacz($konto, self::HASLO, '000000', null);

        $this->assertSame(Wynik::ZLY_KOD, $wynik->status);
        $this->assertFalse($konto->refresh()->hasTwoFactorConfirmed());
        $this->assertSame(0, $this->wpisow());
    }

    #[Test]
    public function sekret_podmieniony_przez_druga_karte_nie_wlacza_2fa(): void
    {
        [$konto, $kod] = $this->zaczete();

        // Druga karta zaczyna ustawianie od nowa w oknie między sprawdzeniem
        // kodu (`verifyCode`, zapis znacznika ostatniego użycia) a blokadą
        // zapisu: w bazie ląduje inny sekret.
        $inny = app(TwoFactorAuthenticator::class)->generateSecret();
        $podmieniono = false;
        DB::listen(function (QueryExecuted $zapytanie) use (&$podmieniono, $konto, $inny): void {
            if (! $podmieniono && str_starts_with($zapytanie->sql, 'update "users"')) {
                $podmieniono = true;
                DB::table('users')->where('id', $konto->getKey())->update(['two_factor_secret' => Crypt::encryptString($inny)]);
            }
        });

        $wynik = $this->wlacz($konto, self::HASLO, $kod, null);

        $this->assertSame(Wynik::PRZEGRANA_Z_DRUGA_KARTA, $wynik->status);
        $this->assertSame([], $wynik->kodyJawne);
        $this->assertFalse($konto->hasTwoFactorConfirmed());
        $this->assertTrue($podmieniono);
        $this->assertSame($inny, $konto->two_factor_secret, 'Konto ma być odświeżone ze stanu w bazie.');
        $this->assertSame(0, $this->wpisow());
    }

    #[Test]
    public function druga_karta_potwierdzila_2fa_w_trakcie_zada_juz_wlaczone_i_bez_nowego_kompletu(): void
    {
        [$konto, $kod] = $this->zaczete();

        $druga = User::query()->whereKey($konto->getKey())->firstOrFail();
        $druga->confirmTwoFactor(['skrot-z-drugiej-karty']);
        $komplet = $druga->refresh()->getRawOriginal('two_factor_backup_codes');

        $wynik = $this->wlacz($konto, self::HASLO, $kod, null);

        $this->assertSame(Wynik::JUZ_WLACZONE, $wynik->status);
        $this->assertSame($komplet, $konto->refresh()->getRawOriginal('two_factor_backup_codes'));
        $this->assertSame(0, $this->wpisow());
    }

    #[Test]
    public function blokada_konta_wchodzi_przed_zapisem_i_audytem(): void
    {
        [$konto, $kod] = $this->zaczete();

        $kolejnosc = [];
        DB::listen(function (QueryExecuted $zapytanie) use (&$kolejnosc): void {
            if (str_contains($zapytanie->sql, 'for update') && str_contains($zapytanie->sql, '"users"')) {
                $kolejnosc[] = 'blokada_konta';
            } elseif (str_starts_with($zapytanie->sql, 'update "users"')) {
                $kolejnosc[] = 'zapis_konta';
            } elseif (str_contains($zapytanie->sql, 'insert into "audit_log"')) {
                $kolejnosc[] = 'audyt';
            }
        });

        $this->wlacz($konto, self::HASLO, $kod, null);

        $this->assertSame('blokada_konta', $kolejnosc[0] ?? null);
        $this->assertLessThan(
            array_search('audyt', $kolejnosc, true),
            array_search('blokada_konta', $kolejnosc, true),
        );
        $this->assertContains('zapis_konta', $kolejnosc);
    }
}
