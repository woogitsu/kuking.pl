<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LoginLinkToken;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * #584: każdy HTTP ma własny proces, realny kernel, zaszyfrowane cookies i CSRF.
 * Zatwierdzone fixture są potrzebne procesowi potomnemu — bez RefreshDatabase.
 * To pomiar kolejnych żądań, nie współbieżnego wyścigu logowania z wylogowaniem.
 */
class ZapamietaneLogowanieUniewaznienieTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        // DatabaseMigrations cofa migracje, a cofnięcie migracji 2FA odmawia,
        // dopóki jakieś konto ma ją potwierdzoną (D-238). Testy #930 ją włączają.
        User::query()->whereNotNull('two_factor_confirmed_at')->get()->each->disableTwoFactor();

        parent::tearDown();
    }

    private function request(array &$jar, string $method, string $path, array $data = []): array
    {
        $process = new Process([PHP_BINARY, base_path('tests/Support/remember-request.php')], base_path());
        $process->setInput(json_encode([
            'cookies' => $jar, 'method' => $method, 'path' => $path, 'data' => $data,
            'config' => [
                'database.default' => 'pgsql', 'database.connections.pgsql' => config('database.connections.pgsql'),
                'app.key' => config('app.key'), 'app.debug' => false,
                'session.driver' => 'database', 'session.secure' => false,
                'cache.default' => 'array', 'mail.default' => 'array', 'queue.default' => 'sync',
                'kuking.turnstile.klucz_publiczny' => '', 'kuking.turnstile.sekret' => '',
            ],
        ], JSON_THROW_ON_ERROR));
        $process->run();
        // Nie wypisujemy odpowiedzi: zawiera cookies i token formularza.
        $this->assertSame(0, $process->getExitCode(), 'Proces HTTP zakończył się błędem.');

        $response = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $jar = array_filter(array_replace($jar, $response['cookies']), fn ($value) => $value !== null);

        return $response;
    }

    private function token(array $response): string
    {
        $this->assertSame(200, $response['status']);
        $this->assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $response['body'], $matches));

        return $matches[1];
    }

    private function login(User $user, string $password = 'haslo-testowe-123'): array
    {
        $jar = [];
        $token = $this->token($this->request($jar, 'GET', '/login'));
        $response = $this->request($jar, 'POST', '/login', ['_token' => $token, 'login' => $user->email, 'password' => $password]);
        $this->assertSame(302, $response['status']);
        $this->assertSame(200, $this->request($jar, 'GET', '/ustawienia/bezpieczenstwo')['status']);

        return $jar;
    }

    private function remembered(array $jar): array
    {
        $remembered = array_filter($jar, fn ($name) => str_starts_with($name, 'remember_'), ARRAY_FILTER_USE_KEY);
        $this->assertCount(1, $remembered);

        return $remembered;
    }

    private function denied(array $jar): void
    {
        $response = $this->request($jar, 'GET', '/ustawienia/bezpieczenstwo');
        $this->assertSame(302, $response['status'], 'Stare poświadczenie odtworzyło dostęp.');
        $this->assertSame('http://localhost/login', $response['location']);
    }

    public function test_wylogowanie_odcina_stary_recaller_i_zachowuje_biezaca_sesje(): void
    {
        $user = $this->user();
        $a = $this->login($user);
        $b = $this->login($user);
        $oldA = $this->remembered($a);
        $oldB = $this->remembered($b);
        $probe = $oldB;
        $this->assertSame(200, $this->request($probe, 'GET', '/ustawienia/bezpieczenstwo')['status']);
        $other = $this->user();
        $c = $this->login($other);
        $otherToken = $other->fresh()->getRememberToken();
        $token = $this->token($this->request($a, 'GET', '/ustawienia/bezpieczenstwo'));
        $this->assertSame(419, $this->request($a, 'POST', '/ustawienia/bezpieczenstwo/wyloguj-inne', ['password' => 'haslo-testowe-123'])['status']);
        $this->assertSame(302, $this->request($a, 'POST', '/ustawienia/bezpieczenstwo/wyloguj-inne', ['_token' => $token, 'password' => 'haslo-testowe-123'])['status']);
        $this->denied($oldB);
        $this->denied($b);
        $this->denied($oldA); // Wyjątek dotyczy sesji, nie starego wspólnego tokena.
        $this->assertSame(200, $this->request($a, 'GET', '/ustawienia/bezpieczenstwo')['status']);
        $this->assertSame(200, $this->request($c, 'GET', '/ustawienia/bezpieczenstwo')['status']);
        $this->assertTrue(hash_equals($otherToken, $other->fresh()->getRememberToken()));
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->getKey())->count());
    }

    public function test_bledne_haslo_nie_odwoluje_poswiadczenia(): void
    {
        $user = $this->user();
        $a = $this->login($user);
        $b = $this->remembered($this->login($user));
        $old = $user->fresh()->getRememberToken();
        $token = $this->token($this->request($a, 'GET', '/ustawienia/bezpieczenstwo'));
        $this->assertSame(302, $this->request($a, 'POST', '/ustawienia/bezpieczenstwo/wyloguj-inne', ['_token' => $token, 'password' => 'wrong'])['status']);
        $this->assertTrue(hash_equals($old, $user->fresh()->getRememberToken()));
        $this->assertSame(200, $this->request($b, 'GET', '/ustawienia/bezpieczenstwo')['status']);
    }

    public function test_zmiana_hasla_odcina_stary_recaller_a_sesja_zostaje(): void
    {
        $user = $this->user();
        $a = $this->login($user);
        $b = $this->remembered($this->login($user));
        $token = $this->token($this->request($a, 'GET', '/ustawienia/bezpieczenstwo'));
        $this->assertSame(302, $this->request($a, 'PUT', '/ustawienia/bezpieczenstwo/haslo', ['_token' => $token, 'current_password' => 'haslo-testowe-123', 'password' => 'nowe-bezpieczne-haslo-584', 'password_confirmation' => 'nowe-bezpieczne-haslo-584'])['status']);
        $this->denied($b);
        $this->assertTrue(Hash::check('nowe-bezpieczne-haslo-584', $user->fresh()->password));
        $this->assertSame(200, $this->request($a, 'GET', '/ustawienia/bezpieczenstwo')['status']);
        $this->login($user, 'nowe-bezpieczne-haslo-584');
    }

    public function test_reset_hasla_odcina_wszystkie_stare_cookies(): void
    {
        $user = $this->user();
        $a = $this->login($user);
        $b = $this->remembered($this->login($user));
        $reset = Password::createToken($user);
        $guest = [];
        $token = $this->token($this->request($guest, 'GET', '/login'));
        $response = $this->request($guest, 'POST', '/nowe-haslo', ['_token' => $token, 'token' => $reset, 'email' => $user->email, 'password' => 'nowe-bezpieczne-haslo-584', 'password_confirmation' => 'nowe-bezpieczne-haslo-584']);
        $this->assertSame(302, $response['status']);
        $this->assertSame('http://localhost/login', $response['location']);
        $this->assertTrue(Hash::check('nowe-bezpieczne-haslo-584', $user->fresh()->password));
        $this->denied($a);
        $this->denied($b);
    }

    public function test_decyzje_o_koncie_odcinaja_recaller_takze_po_przywroceniu(): void
    {
        $moderator = $this->user();
        $moderatorJar = $this->login($moderator);
        $moderatorToken = $moderator->fresh()->getRememberToken();
        foreach (['ban', 'suspend', 'markForDeletion'] as $action) {
            $user = $this->user();
            $jar = $this->remembered($this->login($user));
            $user->{$action}();
            $action === 'markForDeletion' ? $user->cancelDeletion() : $user->reinstate();
            $this->denied($jar);
        }
        $this->assertSame(200, $this->request($moderatorJar, 'GET', '/ustawienia/bezpieczenstwo')['status']);
        $this->assertTrue(hash_equals($moderatorToken, $moderator->fresh()->getRememberToken()));
    }

    public function test_ograniczony_model_i_niedatabase_odwoluja_token_oraz_linki(): void
    {
        config(['session.driver' => 'array']);
        $user = $this->user();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        $old = $user->getRememberToken();
        $link = new LoginLinkToken;
        $link->forceFill(['user_id' => $user->getKey(), 'token_hash' => hash('sha256', Str::random(60)), 'expires_at' => now()->addMinutes(30)])->save();
        User::query()->select('id')->findOrFail($user->getKey())->invalidateSessions();
        $this->assertFalse(hash_equals($old, $user->fresh()->getRememberToken()));
        $this->assertDatabaseMissing('login_link_tokens', ['id' => $link->getKey()]);
        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
        $this->assertTrue(Hash::check('haslo-testowe-123', $user->fresh()->password));
    }

    /**
     * #930: włączenie 2FA gasi poświadczenia sprzed niego. Zwraca kod
     * odpowiedzi POST-a potwierdzenia wysłanego z klienta `$jar`.
     */
    private function enableTwoFactor(array &$jar, User $user, ?string $code = null): int
    {
        $token = $this->token($this->request($jar, 'GET', '/ustawienia/2fa/wlacz'));
        $code ??= (new Google2FA)->getCurrentOtp($user->fresh()->two_factor_secret);

        return $this->request($jar, 'POST', '/ustawienia/2fa/wlacz', ['_token' => $token, 'code' => $code, 'password' => 'haslo-testowe-123'])['status'];
    }

    public function test_wlaczenie_2fa_odcina_stare_sesje_i_recaller_a_biezaca_zostaje(): void
    {
        $user = $this->user();
        $a = $this->login($user);
        $b = $this->login($user);
        $oldB = $this->remembered($b);
        $probe = $oldB;
        $this->assertSame(200, $this->request($probe, 'GET', '/ustawienia/bezpieczenstwo')['status']);

        $this->assertSame(302, $this->enableTwoFactor($a, $user));
        $this->assertTrue($user->fresh()->hasTwoFactorConfirmed());

        $this->denied($oldB);
        $this->denied($b); // Pełna sesja sprzed włączenia też gaśnie.
        $this->assertSame(200, $this->request($a, 'GET', '/ustawienia/bezpieczenstwo')['status']);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->getKey())->count());
    }

    public function test_zly_kod_przy_wlaczaniu_2fa_niczego_nie_odwoluje(): void
    {
        $user = $this->user();
        $a = $this->login($user);
        $b = $this->remembered($this->login($user));
        $old = $user->fresh()->getRememberToken();

        $this->assertSame(302, $this->enableTwoFactor($a, $user, '000000'));

        $this->assertFalse($user->fresh()->hasTwoFactorConfirmed());
        $this->assertTrue(hash_equals($old, $user->fresh()->getRememberToken()));
        $this->assertSame(200, $this->request($b, 'GET', '/ustawienia/bezpieczenstwo')['status']);
    }

    public function test_wlaczenie_2fa_nie_otwiera_panelu_staremu_recallerowi_moderatora(): void
    {
        $moderator = $this->user(null, ['role' => User::ROLE_MODERATOR]);
        $a = $this->login($moderator);
        $b = $this->remembered($this->login($moderator));
        $probe = $b;
        $this->assertSame(403, $this->request($probe, 'GET', '/admin/zgloszenia')['status']);

        $this->assertSame(302, $this->enableTwoFactor($a, $moderator));

        $response = $this->request($b, 'GET', '/admin/zgloszenia');
        $this->assertSame(302, $response['status'], 'Stary recaller moderatora wszedł do panelu bez kodu.');
        $this->assertSame('http://localhost/login', $response['location']);
        $this->assertSame(200, $this->request($a, 'GET', '/admin/zgloszenia')['status']);
    }

    /**
     * #930: wyłączenie 2FA też zamyka inne urządzenia. Klient B loguje się
     * hasłem i kodem już przy włączonej 2FA; A wyłącza ją hasłem.
     */
    public function test_wylaczenie_2fa_odcina_inne_sesje_a_biezaca_zostaje(): void
    {
        $user = $this->user();
        $a = $this->login($user);
        $this->assertSame(302, $this->enableTwoFactor($a, $user));

        // Proces potomny ma prawdziwy zegar, a kod z włączenia jest zużyty w tym
        // oknie 30 s. Zamiast czekać, zdejmujemy znacznik ostatniego użycia.
        $user->fresh()->forceFill(['two_factor_last_used_at' => null])->save();
        $b = [];
        $token = $this->token($this->request($b, 'GET', '/login'));
        $this->assertSame(302, $this->request($b, 'POST', '/login', ['_token' => $token, 'login' => $user->email, 'password' => 'haslo-testowe-123'])['status']);
        $token = $this->token($this->request($b, 'GET', '/logowanie/kod'));
        $code = (new Google2FA)->getCurrentOtp($user->fresh()->two_factor_secret);
        $this->assertSame(302, $this->request($b, 'POST', '/logowanie/kod', ['_token' => $token, 'code' => $code])['status']);
        $probe = $b;
        $this->assertSame(200, $this->request($probe, 'GET', '/ustawienia/bezpieczenstwo')['status']);

        $token = $this->token($this->request($a, 'GET', '/ustawienia/2fa'));
        $this->assertSame(302, $this->request($a, 'POST', '/ustawienia/2fa/wylacz', ['_token' => $token, 'password' => 'haslo-testowe-123'])['status']);

        $this->assertFalse($user->fresh()->hasTwoFactorConfirmed());
        $this->denied($b);
        $this->assertSame(200, $this->request($a, 'GET', '/ustawienia/bezpieczenstwo')['status']);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->getKey())->count());
    }

    public function test_wylaczenie_2fa_ze_zlym_haslem_niczego_nie_odwoluje(): void
    {
        $user = $this->user();
        $a = $this->login($user);
        $this->assertSame(302, $this->enableTwoFactor($a, $user));
        $old = $user->fresh()->getRememberToken();

        $token = $this->token($this->request($a, 'GET', '/ustawienia/2fa'));
        $this->assertSame(302, $this->request($a, 'POST', '/ustawienia/2fa/wylacz', ['_token' => $token, 'password' => 'wrong'])['status']);

        $this->assertTrue($user->fresh()->hasTwoFactorConfirmed());
        $this->assertTrue(hash_equals($old, $user->fresh()->getRememberToken()));
    }
}
