<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PragmaRX\Google2FA\Google2FA;

/** #2861/#2862: po resecie stare żądanie nie może zmienić 2FA ani odnowić sesji. */
#[Group('dwa-polaczenia')]
final class SpoznioneWlaczenieIWylaczenie2faTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $adresyZTokenem = [];

    protected function tearDown(): void
    {
        if ($this->adresyZTokenem !== []) {
            DB::table('password_reset_tokens')->whereIn('email', $this->adresyZTokenem)->delete();
        }

        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function drogi(): array
    {
        return [
            'włączenie po resecie' => ['wlacz', 'reset'],
            'włączenie po zmianie' => ['wlacz', 'zmiana'],
            'włączenie po resecie do tego samego hasła' => ['wlacz', 'reset-to-same'],
            'wyłączenie po resecie' => ['wylacz', 'reset'],
            'wyłączenie po zmianie' => ['wylacz', 'zmiana'],
            'wyłączenie po resecie do tego samego hasła' => ['wylacz', 'reset-to-same'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function aktualneDrogi(): array
    {
        return ['włączenie w aktualnej sesji' => ['wlacz'], 'wyłączenie w aktualnej sesji' => ['wylacz']];
    }

    #[DataProvider('aktualneDrogi')]
    public function test_aktualna_sesja_moze_zmienic_2fa_i_pozostaje_zalogowana(string $droga): void
    {
        $konto = $this->konto();
        $sekret = (new Google2FA)->generateSecretKey();
        $konto->beginTwoFactorSetup($sekret);
        if ($droga === 'wylacz') {
            $konto->confirmTwoFactor(['testowy-skrot-kodu']);
        }

        $wynik = $this->wTle('spoznione-2fa', [
            'konto' => (string) $konto->getKey(),
            'droga' => $droga,
            'obecne' => 'haslo-testowe-123',
            'kod' => (new Google2FA)->getCurrentOtp($sekret),
        ], ['SESSION_DRIVER' => 'database'])->wynik();

        $this->assertTrue($wynik['ok'], $wynik['komunikat']);
        $this->assertSame(200, $wynik['wartosc']['dodatni_status'] ?? null);
        $this->assertSame(302, $wynik['wartosc']['status'] ?? null);
        $this->assertSame(200, $wynik['wartosc']['kolejne_status'] ?? null, 'AKTUALNA_SESJA_2861_2862_ZOSTAJE');
        $this->assertSame($droga === 'wlacz', $konto->fresh()?->hasTwoFactorConfirmed());
        $this->assertSame(1, AuditLogEntry::query()->where('subject_id', $konto->getKey())
            ->where('action', $droga === 'wlacz' ? 'account.two_factor_enabled' : 'account.two_factor_disabled')->count());
    }

    #[DataProvider('drogi')]
    public function test_stare_zadanie_nie_zmienia_2fa_ani_nie_odnawia_sesji(string $drogaA, string $drogaB): void
    {
        $konto = $this->konto();
        $sekret = (new Google2FA)->generateSecretKey();
        $konto->beginTwoFactorSetup($sekret);
        if ($drogaA === 'wylacz') {
            $konto->confirmTwoFactor(['testowy-skrot-kodu']);
        }
        $kod = (new Google2FA)->getCurrentOtp($sekret);
        $adres = (string) $konto->email;
        $token = str_starts_with($drogaB, 'reset') ? Password::createToken($konto) : null;
        if ($token !== null) {
            $this->adresyZTokenem[] = $adres;
        }

        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2861, 1)', []);
        $spoznione = $this->wTle('spoznione-2fa', [
            'konto' => (string) $konto->getKey(),
            'droga' => $drogaA,
            'obecne' => 'haslo-testowe-123',
            'kod' => $kod,
        ], ['SESSION_DRIVER' => 'database']);

        try {
            $this->czekajNaZablokowane(1);
            $argumentyB = [
                'konto' => (string) $konto->getKey(),
                'droga' => str_starts_with($drogaB, 'reset') ? 'reset' : 'zmiana',
                'haslo' => $drogaB === 'reset-to-same' ? 'haslo-testowe-123' : 'haslo-wlasciciela-2861',
            ];
            if (str_starts_with($drogaB, 'reset')) {
                $argumentyB['token'] = (string) $token;
                $argumentyB['email'] = $adres;
            } else {
                $argumentyB['obecne'] = 'haslo-testowe-123';
            }

            $wynikB = $this->wTle('ustaw-haslo', $argumentyB)->wynik();
            $this->assertTrue($wynikB['ok'], $wynikB['komunikat']);
            $this->assertSame([], $wynikB['wartosc']['bledy'] ?? null);
            $poB = $konto->fresh();
            $this->assertInstanceOf(User::class, $poB);
            $this->assertTrue(Hash::check($argumentyB['haslo'], (string) $poB->password));
            $audytPoB = AuditLogEntry::query()->where('subject_id', $konto->getKey())->count();
            $tokenyPoB = DB::table('password_reset_tokens')->where('email', $adres)->count();
        } finally {
            $this->zwolnijBariere($bariera);
        }

        $wynikA = $spoznione->wynik();
        $this->assertBezZakleszczenia($wynikA, 'spóźnione '.$drogaA.' 2FA');
        $this->assertTrue($wynikA['ok'], $wynikA['komunikat']);
        $poA = $konto->fresh();
        $this->assertInstanceOf(User::class, $poA);
        $this->assertSame($poB->password, $poA->password);
        $this->assertSame($poB->session_generation, $poA->session_generation, 'SESJA_2861_2862_BEZ_AWANSU');
        $this->assertSame($poB->two_factor_secret, $poA->two_factor_secret, 'DWA_2861_2862_SEKRET_BEZ_ZMIANY');
        $this->assertSame($poB->two_factor_confirmed_at?->toIso8601String(), $poA->two_factor_confirmed_at?->toIso8601String(), 'DWA_2861_2862_POTWIERDZENIE_BEZ_ZMIANY');
        $this->assertSame($poB->two_factor_backup_codes, $poA->two_factor_backup_codes, 'DWA_2861_2862_KODY_BEZ_ZMIANY');
        $this->assertSame($audytPoB, AuditLogEntry::query()->where('subject_id', $konto->getKey())->count());
        $this->assertSame($tokenyPoB, DB::table('password_reset_tokens')->where('email', $adres)->count());
        $this->assertSame(0, $wynikA['wartosc']['listy'] ?? null);
        $this->assertSame(route('login'), $wynikA['wartosc']['redirect'] ?? null);
        $this->assertSame([], $wynikA['wartosc']['old'] ?? null);
        $this->assertSame(200, $wynikA['wartosc']['pierwszy_status'] ?? null);
        $this->assertSame(200, $wynikA['wartosc']['dodatni_status'] ?? null);
        $this->assertTrue($wynikA['wartosc']['cookie_przed'] ?? false);
        $this->assertTrue($wynikA['wartosc']['cookie_a'] ?? false);
        $this->assertSame(302, $wynikA['wartosc']['kolejne_status'] ?? null);
        $this->assertSame(route('login'), $wynikA['wartosc']['kolejne_dokad'] ?? null, 'HTTP_2861_2862_STARE_CIASTKO_ODMOWA');
    }
}
