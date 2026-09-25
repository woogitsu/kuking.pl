<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * #1315: awans z powłoki (`kuking:nadaj-role`) nie otwiera panelu sesji ani
 * ciasteczku „zapamiętaj mnie” sprzed awansu. Każde żądanie idzie w osobnym
 * procesie (tests/Support/remember-request.php): prawdziwy kernel, sterownik
 * sesji `database`, zaszyfrowane cookies, CSRF — nie `actingAs`. Zatwierdzone
 * fixture są potrzebne procesowi potomnemu, więc bez RefreshDatabase.
 *
 * Kontrola ujemna: wpis „Awans roli bez odwołania sesji” w
 * scripts/kontrole-negatywne-alfa08.py usuwa `invalidateSessions()`
 * z ChangeUserRole i ta klasa ma oblać.
 */
class AwansRoliWymagaNowejSesjiTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        // Komenda chodzi w procesie testu; na produkcji sesje są w bazie
        // (config/session.php, .railway/railway.ts), a phpunit.xml daje `array`.
        config(['session.driver' => 'database']);
    }

    protected function tearDown(): void
    {
        // Cofnięcie migracji 2FA odmawia, dopóki konto ma ją potwierdzoną (D-238).
        User::query()->whereNotNull('two_factor_confirmed_at')->get()->each->disableTwoFactor();

        parent::tearDown();
    }

    public function test_awans_konta_z_2fa_zamyka_sesje_sprzed_awansu_a_logowanie_z_kodem_otwiera_panel(): void
    {
        foreach ([User::ROLE_MODERATOR, User::ROLE_ADMIN] as $rola) {
            $ula = $this->user(null, ['email' => "ula-{$rola}@kuking.pl"]);
            $this->wlacz2fa($ula);

            $przed = $this->zaloguj($ula, 'ABCD-1234');
            // Kontrola dodatnia: sesja sprzed awansu żyje, a panelu nie ma.
            $this->assertSame(200, $this->request($przed, 'GET', '/ustawienia/bezpieczenstwo')['status']);
            $this->assertSame(404, $this->request($przed, 'GET', '/admin/zgloszenia')['status']);

            $this->nadajRole($ula, $rola);

            $this->odeslanaDoLogowania($przed, "Sesja sprzed awansu na {$rola} weszła do panelu.");

            $po = $this->zaloguj($ula, 'EFGH-5678');
            $this->assertSame(200, $this->request($po, 'GET', '/admin/zgloszenia')['status']);
        }
    }

    public function test_awans_konta_bez_2fa_zamyka_sesje_i_zapamietane_logowanie_a_nowa_sesja_widzi_wymog_2fa(): void
    {
        $ula = $this->user(null, ['email' => 'ula@kuking.pl']);
        $przed = $this->zaloguj($ula);
        $zapamietane = array_filter($przed, fn ($nazwa) => str_starts_with($nazwa, 'remember_'), ARRAY_FILTER_USE_KEY);
        $this->assertCount(1, $zapamietane);
        $sonda = $zapamietane;
        $this->assertSame(200, $this->request($sonda, 'GET', '/ustawienia/bezpieczenstwo')['status']);

        $this->nadajRole($ula, User::ROLE_MODERATOR);

        $this->odeslanaDoLogowania($przed, 'Sesja sprzed awansu przetrwała.');
        $this->odeslanaDoLogowania($zapamietane, 'Ciasteczko „zapamiętaj mnie” sprzed awansu odtworzyło sesję.');

        $po = $this->zaloguj($ula);
        $odpowiedz = $this->request($po, 'GET', '/admin/zgloszenia');
        $this->assertSame(403, $odpowiedz['status']);
        $this->assertStringContainsString('/ustawienia/2fa', $odpowiedz['body']);
    }

    private function nadajRole(User $user, string $rola): void
    {
        $this->artisan('kuking:nadaj-role', ['login' => $user->email, 'rola' => $rola, '--tak' => true])
            ->assertSuccessful();
        $this->assertSame($rola, $user->fresh()->role);
    }

    private function wlacz2fa(User $user): void
    {
        $totp = app(TwoFactorAuthenticator::class);
        $user->beginTwoFactorSetup($totp->generateSecret());
        $user->confirmTwoFactor($totp->hashBackupCodes(['ABCD-1234', 'EFGH-5678']));
    }

    /** Nowy klient: hasło, a przy 2FA jeszcze kod zapasowy (TOTP nie przejdzie dwa razy w oknie). */
    private function zaloguj(User $user, ?string $kodZapasowy = null): array
    {
        $jar = [];
        $token = $this->token($this->request($jar, 'GET', '/login'));
        $odpowiedz = $this->request($jar, 'POST', '/login', ['_token' => $token, 'login' => $user->email, 'password' => 'haslo-testowe-123']);
        $this->assertSame(302, $odpowiedz['status']);

        if ($kodZapasowy !== null) {
            $this->assertSame('http://localhost/logowanie/kod', $odpowiedz['location']);
            $token = $this->token($this->request($jar, 'GET', '/logowanie/kod'));
            $this->assertSame(302, $this->request($jar, 'POST', '/logowanie/kod', ['_token' => $token, 'backup_code' => $kodZapasowy])['status']);
        }

        $this->assertSame(200, $this->request($jar, 'GET', '/ustawienia/bezpieczenstwo')['status']);

        return $jar;
    }

    private function odeslanaDoLogowania(array $jar, string $komunikat): void
    {
        $odpowiedz = $this->request($jar, 'GET', '/admin/zgloszenia');
        $this->assertSame(302, $odpowiedz['status'], $komunikat);
        $this->assertSame('http://localhost/login', $odpowiedz['location'], $komunikat);
    }

    private function token(array $response): string
    {
        $this->assertSame(200, $response['status']);
        $this->assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $response['body'], $matches));

        return $matches[1];
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
}
