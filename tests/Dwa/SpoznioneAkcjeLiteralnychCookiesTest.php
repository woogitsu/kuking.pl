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

/** #2851/#2854/#2862: A/B odczytywane z dosłownych cookies, każdy GET w nowym procesie. */
#[Group('dwa-polaczenia')]
final class SpoznioneAkcjeLiteralnychCookiesTest extends TestDwochPolaczen
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
        $this->wlasneProcesy = [];
        if ($this->adresyZTokenem !== []) {
            DB::table('password_reset_tokens')->whereIn('email', $this->adresyZTokenem)->delete();
        }
        parent::tearDown();
    }

    /** @return array<string, array{string, string}> */
    public static function drogi(): array
    {
        $wynik = [];
        foreach (['zmiana', 'wyloguj', 'wylacz2fa'] as $a) {
            foreach (['reset', 'zmiana', 'reset-identyczny'] as $b) {
                $wynik[$a.'-'.$b] = [$a, $b];
            }
        }

        return $wynik;
    }

    #[DataProvider('drogi')]
    public function test_spozniona_akcja_odmawia_cookie_a_i_zachowuje_literalne_cookie_b(string $drogaA, string $drogaB): void
    {
        $konto = $this->konto();
        $sekret = '';
        if ($drogaA === 'wylacz2fa') {
            $sekret = (new Google2FA)->generateSecretKey();
            $konto->beginTwoFactorSetup($sekret);
            $konto->confirmTwoFactor(['skrot-kodu-fixture']);
        }
        $cookiePrzedA = $this->loginOtwierajacyUstawienia($konto, 'haslo-testowe-123', $sekret);
        $this->assertOdczyt('', 302, 'COOKIE_S4_BRAK_ODMOWA');
        $cookieZmianyB = $drogaB === 'zmiana'
            ? $this->loginOtwierajacyUstawienia($konto, 'haslo-testowe-123', $sekret)
            : '';
        if ($sekret !== '' && $drogaB === 'zmiana') {
            // Trzeci login będzie po zmianie B. Miejsce na kolejny prawdziwy
            // TOTP zapewniamy PRZED barierą, bez trzymania transakcji.
            $this->czekajNaKolejnyDozwolonyTotp($konto);
        }
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(28512862, 1)', []);
        $daneA = $drogaA === 'zmiana'
            ? ['current_password' => 'haslo-testowe-123', 'password' => 'haslo-spoznione-S4', 'password_confirmation' => 'haslo-spoznione-S4']
            : ['password' => 'haslo-testowe-123'];
        $trasaA = match ($drogaA) {
            'zmiana' => 'settings.security.password',
            'wyloguj' => 'settings.security.logout-others',
            default => 'settings.two_factor.disable',
        };
        $a = $this->zadanie('akcja-a', $trasaA, $drogaA === 'zmiana' ? 'PUT' : 'POST', $daneA, $cookiePrzedA);

        try {
            $this->czekajNaPrecheckA($a);
            $hasloB = $drogaB === 'reset-identyczny' ? 'haslo-testowe-123' : 'haslo-wlasciciela-S4';
            if ($drogaB === 'zmiana') {
                $b = $this->zadanie('zmiana-b', 'settings.security.password', 'PUT', [
                    'current_password' => 'haslo-testowe-123', 'password' => $hasloB, 'password_confirmation' => $hasloB,
                ], $cookieZmianyB)->wynik();
            } else {
                $token = Password::createToken($konto);
                $this->adresyZTokenem[] = (string) $konto->email;
                $b = $this->zadanie('reset-b', 'password.update', 'POST', [
                    'email' => (string) $konto->email, 'token' => $token, 'password' => $hasloB, 'password_confirmation' => $hasloB,
                ])->wynik();
            }
            $this->assertHttp($b, 302);
            $this->assertSame([], $b['wartosc']['bledy'], 'COOKIE_S4_ZMIANA_B_BEZ_BLEDU');
            $this->assertTrue(Hash::check($hasloB, (string) $konto->fresh()?->password));
            $cookieB = $this->loginOtwierajacyUstawienia($konto, $hasloB, $sekret);
            $stanPoB = $this->stanKonta($konto);
            $this->assertTrue($a->trwa());
        } finally {
            $this->zwolnijBariere($bariera);
        }

        $wynikA = $a->wynik();
        $this->assertBezZakleszczenia($wynikA, 'literalne cookie S4 A');
        $this->assertHttp($wynikA, 302);
        $this->assertSame(route('login'), $wynikA['wartosc']['dokad'], 'COOKIE_S4_A_AKCJA_ODMOWA');
        $this->assertSame([], $wynikA['wartosc']['old']);
        $this->assertSame(0, $wynikA['wartosc']['listy']);
        $this->assertSame($stanPoB, $this->stanKonta($konto), 'COOKIE_S4_A_BEZ_ZAPISU');
        $this->assertNotSame($b['wartosc']['pid'], $wynikA['wartosc']['pid'], 'COOKIE_S4_OSOBNE_PROCESY');
        $cookieA = $wynikA['wartosc']['cookie'];
        $this->assertIsString($cookieA);
        $this->assertNotSame('', $cookieA);

        // Cookie B jest identycznym ciągiem z GET przed końcem A. Nie logujemy
        // ponownie B ani nie podmieniamy jego generacji w sesji.
        $this->assertOdczyt($cookieB, 200, 'COOKIE_S4_B_ZOSTAJE');
        $this->assertOdczyt($cookieA, 302, 'COOKIE_S4_A_ODMOWA');
        $this->assertOdczyt($cookiePrzedA, 302, 'COOKIE_S4_PRZED_A_ODMOWA');
    }

    private function loginOtwierajacyUstawienia(User $konto, string $haslo, string $sekret): string
    {
        $login = $this->zadanie('login', 'login', 'POST', ['login' => (string) $konto->email, 'password' => $haslo])->wynik();
        $this->assertHttp($login, 302);
        $this->assertSame(route($sekret === '' ? 'home' : 'login.two_factor'), $login['wartosc']['dokad']);
        $cookie = $login['wartosc']['cookie'];
        $this->assertIsString($cookie);
        $this->assertNotSame('', $cookie);
        if ($sekret !== '') {
            $engine = new Google2FA;
            $ostatni = (int) ($konto->fresh()->two_factor_last_used_at ?? 0);
            // Kolejne rzeczywiste TOTP w standardowym oknie ±1. Nie zerujemy
            // ochrony przed powtórzeniem ani nie podmieniamy zegara aplikacji.
            $czas = max($engine->getTimestamp(), $ostatni + 1);
            $this->assertLessThanOrEqual($engine->getTimestamp() + 1, $czas, 'COOKIE_S4_TOTP_W_DOZWOLONYM_OKNIE');
            $kod = $engine->oathTotp($sekret, $czas);
            $wyzwanie = $this->zadanie('kod', 'login.two_factor.store', 'POST', ['code' => $kod], $cookie)->wynik();
            $this->assertHttp($wyzwanie, 302);
            $this->assertSame(route('home'), $wyzwanie['wartosc']['dokad']);
            $this->assertSame([], $wyzwanie['wartosc']['bledy']);
            $cookie = $wyzwanie['wartosc']['cookie'];
        }
        $get = $this->assertOdczyt($cookie, 200, 'COOKIE_S4_DODATNI_LOGIN');
        $otrzymane = $get['wartosc']['cookie'];
        $this->assertIsString($otrzymane);
        $this->assertNotSame('', $otrzymane);

        return $otrzymane;
    }

    private function czekajNaKolejnyDozwolonyTotp(User $konto): void
    {
        $engine = new Google2FA;
        $ostatni = (int) $konto->fresh()->two_factor_last_used_at;
        $koniec = microtime(true) + 35;
        while ($engine->getTimestamp() + 1 <= $ostatni) {
            if (microtime(true) >= $koniec) {
                $this->fail('COOKIE_S4_TOTP_W_DOZWOLONYM_OKNIE: rzeczywisty zegar nie udostępnił kolejnego kodu.');
            }
            usleep(20_000);
        }
    }

    /** @return array<string, mixed> */
    private function stanKonta(User $konto): array
    {
        $swiezy = $konto->fresh();
        $this->assertInstanceOf(User::class, $swiezy);

        return [
            'haslo' => $swiezy->password, 'generacja' => $swiezy->session_generation, 'remember' => $swiezy->remember_token,
            'sekret' => $swiezy->two_factor_secret, 'potwierdzenie' => $swiezy->getRawOriginal('two_factor_confirmed_at'),
            'kody' => $swiezy->two_factor_backup_codes,
            'audyt' => AuditLogEntry::query()->where('subject_id', $konto->getKey())->count(),
            'tokeny' => DB::table('password_reset_tokens')->where('email', $konto->email)->count(),
        ];
    }

    /** @param array{ok: bool, sqlstate: ?string, komunikat: string, wartosc: mixed, wyjatek: ?string} $wynik */
    private function assertHttp(array $wynik, int $status, string $marker = 'COOKIE_S4_HTTP'): void
    {
        $this->assertTrue($wynik['ok'], $wynik['komunikat']);
        $this->assertSame($status, $wynik['wartosc']['status'], $marker);
        $this->assertTrue($wynik['wartosc']['wspolny_store'], 'COOKIE_S4_GUARD_I_REQUEST_MAJA_TEN_SAM_STORE');
    }

    /** @return array{ok: bool, sqlstate: ?string, komunikat: string, wartosc: mixed, wyjatek: ?string} */
    private function assertOdczyt(string $cookie, int $status, string $marker): array
    {
        $get = $this->zadanie('odczyt', 'settings.security', cookie: $cookie)->wynik();
        $this->assertHttp($get, $status, $marker);
        if ($status === 302) {
            $this->assertSame(route('login'), $get['wartosc']['dokad'], $marker);
        }

        return $get;
    }

    private function czekajNaPrecheckA(ProcesRownolegly $a): void
    {
        $zapytanie = $this->obserwator->prepare("SELECT count(*) FROM pg_stat_activity WHERE datname = ? AND application_name = 'cookiesS4-akcja-a' AND wait_event_type = 'Lock' AND wait_event = 'advisory'");
        $koniec = microtime(true) + self::SEKUNDY_NA_KOLEJKE;
        do {
            $zapytanie->execute([$this->baza]);
            if ((int) $zapytanie->fetchColumn() === 1) {
                return;
            }
            if (! $a->trwa()) {
                $wynik = $a->wynik();
                $this->fail('COOKIE_S4_PRECHECK: A nie dotarło do bariery: '.$wynik['komunikat']);
            }
            usleep(20_000);
        } while (microtime(true) < $koniec);
        $this->fail('COOKIE_S4_PRECHECK: brak rzeczywistej kolejki na barierze A.');
    }

    /** @param array<string, string> $dane */
    private function zadanie(string $etap, string $trasa, string $metoda = 'GET', array $dane = [], string $cookie = ''): ProcesRownolegly
    {
        $proces = ProcesRownolegly::start(__DIR__.'/bin/literalneCookiesS4.php', $etap, [
            'trasa' => $trasa, 'metoda' => $metoda, 'dane' => (string) json_encode($dane), 'cookie' => $cookie,
        ], [
            'APP_BASE_PATH' => dirname(__DIR__, 2), 'APP_ENV' => 'testing', 'DB_DATABASE' => $this->baza,
            'SESSION_DRIVER' => 'database', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array', 'BCRYPT_ROUNDS' => '4',
        ]);
        $this->wlasneProcesy[] = $proces;

        return $proces;
    }
}
