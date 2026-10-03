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
use RuntimeException;
use Throwable;

/** #2861: literalny panel moderatora i rzeczywiste okno confirmTwoFactor → invalidateSessions. */
#[Group('dwa-polaczenia')]
final class Potwierdzenie2faPodWspolnaBlokadaTest extends TestDwochPolaczen
{
    /** @var list<ProcesRownolegly> */
    private array $wlasneProcesy = [];

    /** @var list<string> */
    private array $adresyZTokenem = [];

    protected function tearDown(): void
    {
        foreach ($this->wlasneProcesy as $proces) {
            $proces->zabij();
        }
        if ($this->adresyZTokenem !== []) {
            DB::table('password_reset_tokens')->whereIn('email', $this->adresyZTokenem)->delete();
        }
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function zmianyHasla(): array
    {
        return ['reset' => ['reset'], 'zmiana' => ['zmiana'], 'reset identycznego hasła' => ['reset-to-same']];
    }

    /** @return array<string, array{0: string}> */
    public static function resety(): array
    {
        return ['nowe hasło' => ['reset'], 'identyczne hasło' => ['reset-to-same']];
    }

    #[DataProvider('zmianyHasla')]
    public function test_spoznione_potwierdzenie_nie_otwiera_panelu_literalnym_cookie(string $drogaB): void
    {
        [$konto, $cookie, $kod] = $this->przygotujModeratora();
        $argumentyB = $this->argumentyHasla($konto, $drogaB);
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2861, 17)', []);
        $a = $this->wlasnyProces('wlacz', [
            'konto' => (string) $konto->getKey(), 'haslo' => 'haslo-testowe-123',
            'kod' => $kod, 'cookie' => $cookie, 'bariera' => 'przed',
        ]);
        try {
            $this->czekajNaBariere($a);
            $b = $this->wTle('ustaw-haslo', $argumentyB)->wynik();
            $this->assertTrue($b['ok'], $b['komunikat']);
            $this->assertSame([], $b['wartosc']['bledy'] ?? null);
            $poB = $konto->fresh();
            $this->assertInstanceOf(User::class, $poB);
            $audytPoB = AuditLogEntry::query()->where('subject_id', $konto->getKey())->count();
        } finally {
            $this->zwolnijBariere($bariera);
        }
        $wynik = $a->wynik();
        $this->assertTrue($wynik['ok'], $wynik['komunikat']);
        $cookieA = $wynik['wartosc']['cookie'] ?? null;
        $this->assertIsString($cookieA);
        $this->assertNotSame('', $cookieA);
        // To jest dosłowny cookie odpowiedzi A, nie nowo zalogowany użytkownik.
        $this->assertOdmowaLiteralnegoCookie($cookieA, '2FA_2861_COOKIE_A_PANEL_ODMOWA');
        $this->assertOdmowaLiteralnegoCookie($cookie, '2FA_2861_COOKIE_PRZED_PANEL_ODMOWA');
        $this->assertSame(route('login'), $wynik['wartosc']['dokad'] ?? null);
        $this->assertSame([], $wynik['wartosc']['old'] ?? null);
        $this->assertSame(0, $wynik['wartosc']['listy'] ?? null);
        $poA = $konto->fresh();
        $this->assertInstanceOf(User::class, $poA);
        $this->assertSame($poB->password, $poA->password);
        $this->assertSame($poB->session_generation, $poA->session_generation);
        $this->assertSame($poB->remember_token, $poA->remember_token);
        $this->assertSame($poB->two_factor_secret, $poA->two_factor_secret);
        $this->assertNull($poA->two_factor_confirmed_at);
        $this->assertNull($poA->two_factor_backup_codes);
        $this->assertSame($audytPoB, AuditLogEntry::query()->where('subject_id', $konto->getKey())->count());
    }

    #[DataProvider('resety')]
    public function test_potwierdzenie_i_odwolanie_sesji_serializuja_reset(string $drogaB): void
    {
        [$konto, $cookie, $kod] = $this->przygotujModeratora();
        $generacjaPrzed = (int) $konto->fresh()?->session_generation;
        $argumentyB = $this->argumentyHasla($konto, $drogaB);
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2861, 17)', []);
        $a = $this->wlasnyProces('wlacz', [
            'konto' => (string) $konto->getKey(), 'haslo' => 'haslo-testowe-123',
            'kod' => $kod, 'cookie' => $cookie, 'bariera' => 'okno',
        ]);
        try {
            $this->czekajNaBariere($a);
            $b = $this->wTle('ustaw-haslo', $argumentyB, ['PGAPPNAME' => 'uzupelnienie2861-reset-b']);
            $this->assertResetCzekaNaWlaczenie($b, $konto, $argumentyB['haslo'], $generacjaPrzed);
            // Drugie połączenie nie widzi jeszcze potwierdzenia: wspólna
            // transakcja nie zatwierdziła połowy czynności.
            $this->assertNull($konto->fresh()?->two_factor_confirmed_at, '2FA_2861_BARIERA_POTWIERDZENIA');
        } finally {
            $this->zwolnijBariere($bariera);
        }
        $wynikA = $a->wynik();
        $wynikB = $this->wynikPoprawnegoResetu($b);
        $this->assertTrue($wynikA['ok'], $wynikA['komunikat']);
        $this->assertTrue($wynikB['ok'], $wynikB['komunikat']);
        $this->assertSame([], $wynikB['wartosc']['bledy'] ?? null);
        $this->assertSame(route('settings.two_factor.codes'), $wynikA['wartosc']['dokad'] ?? null);
        $this->assertTrue($wynikA['wartosc']['potwierdzone'] ?? false, 'Bariera nie stanęła PO confirmTwoFactor.');
        $this->assertSame(1, $wynikA['wartosc']['poziom'] ?? null, '2FA_2861_BARIERA_POTWIERDZENIA');
        $po = $konto->fresh();
        $this->assertInstanceOf(User::class, $po);
        $this->assertTrue($po->hasTwoFactorConfirmed());
        $this->assertTrue(Hash::check($argumentyB['haslo'], (string) $po->password));
        $this->assertSame($generacjaPrzed + 2, (int) $po->session_generation);
        $cookieA = $wynikA['wartosc']['cookie'] ?? null;
        $this->assertIsString($cookieA);
        $this->assertNotSame('', $cookieA);
        $this->assertOdmowaLiteralnegoCookie($cookieA, '2FA_2861_OKNO_RESET_ODCINA_COOKIE_A');
        $this->assertOdmowaLiteralnegoCookie($cookie, '2FA_2861_OKNO_RESET_ODCINA_COOKIE_PRZED');
    }

    /** @return array{0: User, 1: string, 2: string} */
    private function przygotujModeratora(): array
    {
        $konto = $this->konto(['role' => User::ROLE_MODERATOR]);
        $totp = new Google2FA;
        $sekret = $totp->generateSecretKey();
        $konto->beginTwoFactorSetup($sekret);
        $konto->confirmTwoFactor(['skrot-kodu-fixture']);
        $przygotowany = $this->wlasnyProces('przygotuj', [
            'konto' => (string) $konto->getKey(), 'haslo' => 'haslo-testowe-123', 'kod' => $totp->getCurrentOtp($sekret),
        ])->wynik();
        $this->assertTrue($przygotowany['ok'], $przygotowany['komunikat']);
        foreach (['login' => 302, 'wyzwanie' => 302, 'panel' => 200, 'wylaczenie' => 302, 'przygotowanie' => 200, 'ustawienia' => 200] as $etap => $status) {
            $this->assertSame($status, $przygotowany['wartosc'][$etap] ?? null, '2FA_2861_DODATNI_MODERATOR_'.$etap);
        }
        $cookie = $przygotowany['wartosc']['cookie'] ?? null;
        $this->assertIsString($cookie);
        $this->assertNotSame('', $cookie);
        $konto->refresh();
        $this->assertNull($konto->two_factor_confirmed_at);
        $this->assertIsString($konto->two_factor_secret);

        return [$konto, $cookie, $totp->getCurrentOtp($konto->two_factor_secret)];
    }

    /** @return array<string, string> */
    private function argumentyHasla(User $konto, string $droga): array
    {
        $dane = [
            'konto' => (string) $konto->getKey(), 'droga' => $droga === 'zmiana' ? 'zmiana' : 'reset',
            'haslo' => $droga === 'reset-to-same' ? 'haslo-testowe-123' : 'haslo-wlasciciela-2861',
        ];
        if ($droga === 'zmiana') {
            $dane['obecne'] = 'haslo-testowe-123';
        } else {
            $dane['token'] = Password::createToken($konto);
            $dane['email'] = (string) $konto->email;
            $this->adresyZTokenem[] = $dane['email'];
        }

        return $dane;
    }

    private function assertOdmowaLiteralnegoCookie(string $cookie, string $marker): void
    {
        $odczyt = $this->wlasnyProces('odczyt', ['cookie' => $cookie])->wynik();
        $this->assertTrue($odczyt['ok'], $odczyt['komunikat']);
        $this->assertSame(302, $odczyt['wartosc']['panel'] ?? null, $marker);
        $this->assertSame(route('login'), $odczyt['wartosc']['panel_dokad'] ?? null, $marker);
        $this->assertSame(302, $odczyt['wartosc']['ustawienia'] ?? null, $marker);
        $this->assertSame(route('login'), $odczyt['wartosc']['ustawienia_dokad'] ?? null, $marker);
    }

    private function assertResetCzekaNaWlaczenie(ProcesRownolegly $b, User $konto, string $hasloB, int $generacjaPrzed): void
    {
        $zapytanie = $this->obserwator->prepare("SELECT count(*) FROM pg_stat_activity a JOIN pg_stat_activity b ON a.pid = ANY(pg_blocking_pids(b.pid)) WHERE a.datname = ? AND a.application_name = 'uzupelnienie2861-wlacz' AND b.datname = a.datname AND b.application_name = 'uzupelnienie2861-reset-b' AND b.wait_event_type = 'Lock' AND b.query ILIKE '%from \"users\"%for update%'");
        $koniec = microtime(true) + self::SEKUNDY_NA_KOLEJKE;
        do {
            $zapytanie->execute([$this->baza]);
            if ((int) $zapytanie->fetchColumn() === 1 && $b->trwa()) {
                return;
            }
            if (! $b->trwa()) {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $koniec);
        if ($b->trwa()) {
            throw new RuntimeException('2FA_2861_PROCES_B_TIMEOUT: nie potwierdzono kolejki ani zakończenia resetu B.');
        }
        $this->wynikPoprawnegoResetu($b);
        $poB = $konto->fresh();
        if (! $poB instanceof User || ! Hash::check($hasloB, (string) $poB->password)
            || (int) $poB->session_generation !== $generacjaPrzed + 1) {
            throw new RuntimeException('2FA_2861_PROCES_B_BLAD: wynik JSON nie odpowiada zakończonemu resetowi w bazie.');
        }
        // Wyłącznie udany, zatwierdzony reset może dowieść ominięcia blokady.
        $this->fail('2FA_2861_OKNO_WSPOLNA_BLOKADA: poprawny reset B zakończył się przed zwolnieniem A.');
    }

    /** @return array{ok: bool, sqlstate: ?string, komunikat: string, wartosc: mixed, wyjatek: ?string} */
    private function wynikPoprawnegoResetu(ProcesRownolegly $b): array
    {
        try {
            $wynik = $b->wynik();
        } catch (Throwable $e) {
            throw new RuntimeException('2FA_2861_PROCES_B_BLAD: brak poprawnego wyniku procesu B ('.$e::class.').');
        }
        if (! $wynik['ok'] || $wynik['sqlstate'] !== null || $wynik['wyjatek'] !== null
            || ! is_array($wynik['wartosc']) || ($wynik['wartosc']['bledy'] ?? null) !== []) {
            throw new RuntimeException('2FA_2861_PROCES_B_BLAD: reset B odmówił lub zawiódł; SQLSTATE='
                .($wynik['sqlstate'] ?? 'brak').'; wyjątek='.($wynik['wyjatek'] ?? 'brak').'.');
        }

        return $wynik;
    }

    private function czekajNaBariere(ProcesRownolegly $a): void
    {
        $zapytanie = $this->obserwator->prepare("SELECT count(*) FROM pg_stat_activity WHERE datname = ? AND application_name = 'uzupelnienie2861-wlacz' AND wait_event_type = 'Lock' AND wait_event = 'advisory'");
        $koniec = microtime(true) + self::SEKUNDY_NA_KOLEJKE;
        do {
            $zapytanie->execute([$this->baza]);
            if ((int) $zapytanie->fetchColumn() === 1) {
                return;
            }
            if (! $a->trwa()) {
                $wynik = $a->wynik();
                $this->fail('Żądanie A nie dotarło do bariery #2861: status '.($wynik['wartosc']['status'] ?? '?')
                    .', kierunek '.($wynik['wartosc']['dokad'] ?? '?').', '.$wynik['komunikat']);
            }
            usleep(20_000);
        } while (microtime(true) < $koniec);
        $this->fail('Żądanie A nie ustawiło się na rzeczywistej barierze #2861.');
    }

    /** @param array<string, string> $argumenty */
    private function wlasnyProces(string $etap, array $argumenty): ProcesRownolegly
    {
        $proces = ProcesRownolegly::start(__DIR__.'/bin/uzupelnienie2861.php', $etap, $argumenty, [
            'APP_BASE_PATH' => dirname(__DIR__, 2), 'APP_ENV' => 'testing', 'DB_DATABASE' => $this->baza,
            'SESSION_DRIVER' => 'database', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array', 'BCRYPT_ROUNDS' => '4',
        ]);
        $this->wlasneProcesy[] = $proces;

        return $proces;
    }
}
