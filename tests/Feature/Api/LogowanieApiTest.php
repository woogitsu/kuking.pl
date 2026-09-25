<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Http\Middleware\EnsureApiAccountIsActive;
use App\Models\AuditLogEntry;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Logowanie aplikacji mobilnej (D-270): `POST /api/v1/tokeny`,
 * `POST /api/v1/tokeny/kod`, `DELETE /api/v1/tokeny/biezacy`, `GET /api/v1/ja`.
 */
class LogowanieApiTest extends TestCase
{
    use RefreshDatabase;

    private const HASLO = 'haslo-testowe-123';

    private const ZLE_HASLO = 'Nie udało się zalogować. Sprawdź, czy nazwa i hasło są wpisane poprawnie. Jeśli nie pamiętasz hasła, kliknij „Nie pamiętam hasła”.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.api.wlaczone' => true]);
    }

    // --------------------------------------------------------------
    //  Pierwszy krok
    // --------------------------------------------------------------

    public function test_poprawne_haslo_wydaje_token_ktory_otwiera_ja(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        $odpowiedz = $this->zaloguj('basia@example.com');

        $odpowiedz->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('device.name', 'Telefon Basi')
            ->assertJsonPath('user.id', $basia->getKey())
            ->assertJsonPath('user.email', 'basia@example.com')
            ->assertHeader('Cache-Control', 'no-store, private');

        $token = (string) $odpowiedz->json('token');

        $this->getJson('/api/v1/ja', $this->naglowek($token))
            ->assertOk()
            ->assertJsonPath('data.id', $basia->getKey())
            ->assertJsonPath('data.username', 'basia')
            ->assertJsonPath('data.two_factor_enabled', false);

        $this->assertSame(1, AuditLogEntry::query()->where('action', 'account.api_token_created')->count());
    }

    public function test_logowanie_nazwa_uzytkownika_tez_dziala(): void
    {
        $this->user('basia');

        $this->zaloguj('Basia')->assertCreated();
    }

    public function test_ja_nie_zdradza_poswiadczen(): void
    {
        $basia = $this->user('basia');
        $token = $basia->createToken('Telefon')->plainTextToken;

        $tresc = (string) $this->getJson('/api/v1/ja', $this->naglowek($token))->getContent();

        foreach (['password', 'remember_token', 'two_factor_secret', 'backup', 'role', (string) $basia->password] as $zakazane) {
            $this->assertStringNotContainsString($zakazane, $tresc);
        }
    }

    public function test_zle_haslo_to_422_z_tym_samym_zdaniem_co_na_www_i_bez_tokenu(): void
    {
        $this->user('basia', ['email' => 'basia@example.com']);

        $this->zaloguj('basia@example.com', 'zle-haslo')
            ->assertUnprocessable()
            ->assertJsonPath('errors.login.0', self::ZLE_HASLO);

        $this->zaloguj('nikt@example.com')
            ->assertUnprocessable()
            ->assertJsonPath('errors.login.0', self::ZLE_HASLO);

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_brak_nazwy_urzadzenia_to_422_po_polsku(): void
    {
        $this->user('basia');

        $this->postJson('/api/v1/tokeny', ['login' => 'basia', 'password' => self::HASLO])
            ->assertUnprocessable()
            ->assertJsonPath('errors.device_name.0', 'Aplikacja nie podała nazwy urządzenia. Zaktualizuj aplikację i spróbuj jeszcze raz.');
    }

    /**
     * Koszyk KONTA z `LimitProbHasla` jest wspólny dla WWW i API. Zgadujący,
     * który wyczerpał go w aplikacji, nie wchodzi przez formularz — nawet
     * z poprawnym hasłem i z innego adresu.
     */
    public function test_proby_hasla_w_api_licza_sie_do_tego_samego_koszyka_co_na_www(): void
    {
        config(['kuking.login_limits.konto.proby' => 3]);
        $this->user('basia', ['email' => 'basia@example.com']);

        for ($i = 0; $i < 3; $i++) {
            $this->zaloguj('basia@example.com', 'zle-haslo')->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post('/login', ['login' => 'basia@example.com', 'password' => self::HASLO])
            ->assertSessionHasErrors('login');

        $this->assertGuest();

        // I odwrotnie — kontrola dodatnia: przy czystym koszyku to samo
        // hasło z tego samego adresu wpuszcza.
        config(['kuking.login_limits.konto.proby' => 100]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])
            ->zaloguj('basia@example.com')
            ->assertCreated();
    }

    public function test_limit_prob_hasla_w_api_mowi_to_samo_co_www(): void
    {
        config(['kuking.login_limits.para.proby' => 2]);
        $this->user('basia', ['email' => 'basia@example.com']);

        $this->zaloguj('basia@example.com', 'zle-haslo');
        $this->zaloguj('basia@example.com', 'zle-haslo');

        $odpowiedz = $this->zaloguj('basia@example.com');

        $odpowiedz->assertUnprocessable();
        $this->assertStringContainsString('Za dużo', (string) $odpowiedz->json('errors.login.0'));
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function zamknieteStatusy(): array
    {
        return [
            'zablokowane' => [User::STATUS_BANNED],
            'do usunięcia' => [User::STATUS_PENDING_DELETE],
            'usunięte' => [User::STATUS_ERASED],
        ];
    }

    #[DataProvider('zamknieteStatusy')]
    public function test_konto_zamkniete_nie_dostaje_tokenu(string $status): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        DB::table('users')->where('id', $basia->getKey())->update([
            'status' => $status,
            'data_erased_at' => $status === User::STATUS_ERASED ? now() : null,
        ]);

        $odpowiedz = $this->zaloguj('basia@example.com');

        $odpowiedz->assertUnprocessable();
        $this->assertNotSame(self::ZLE_HASLO, $odpowiedz->json('errors.login.0'),
            'Konto zamknięte dostało zdanie o złym haśle zamiast wyjaśnienia, co się stało z kontem.');
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_konto_zawieszone_dostaje_token_i_czyta_ale_nie_zapisuje(): void
    {
        Route::middleware(['api', 'auth:sanctum', EnsureApiAccountIsActive::class])
            ->post('/api/v1/_proba/zapis', fn () => ['ok' => true]);

        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $basia->suspend(now()->addDays(3));

        $token = (string) $this->zaloguj('basia@example.com')->assertCreated()->json('token');

        $this->getJson('/api/v1/ja', $this->naglowek($token))
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->postJson('/api/v1/_proba/zapis', [], $this->naglowek($token))
            ->assertForbidden()
            ->assertJsonPath('code', 'konto_zawieszone');

        // Wylogowanie działa mimo zawieszenia — inaczej telefonu nie da się odłączyć.
        $this->deleteJson('/api/v1/tokeny/biezacy', [], $this->naglowek($token))->assertNoContent();
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_kontrola_dodatnia_aktywne_konto_zapisuje(): void
    {
        Route::middleware(['api', 'auth:sanctum', EnsureApiAccountIsActive::class])
            ->post('/api/v1/_proba/zapis', fn () => ['ok' => true]);

        $token = $this->user('basia')->createToken('Telefon')->plainTextToken;

        $this->postJson('/api/v1/_proba/zapis', [], $this->naglowek($token))->assertOk();
    }

    public function test_kara_z_minionym_terminem_wraca_przy_pierwszym_zadaniu(): void
    {
        $basia = $this->user('basia');
        $token = $basia->createToken('Telefon')->plainTextToken;
        DB::table('users')->where('id', $basia->getKey())->update([
            'status' => User::STATUS_SUSPENDED,
            'status_expires_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v1/ja', $this->naglowek($token))
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(User::STATUS_ACTIVE, $basia->fresh()->status);
    }

    /**
     * Status zmieniony z pominięciem `ban()` (ręcznie w psql podczas
     * incydentu) — token przeżył, bo nikt nie zawołał `invalidateSessions()`.
     * Bramka ma go dobić przy pierwszym żądaniu.
     */
    public function test_token_konta_zablokowanego_recznie_ginie_przy_pierwszym_zadaniu(): void
    {
        $basia = $this->user('basia');
        $token = $basia->createToken('Telefon')->plainTextToken;
        DB::table('users')->where('id', $basia->getKey())->update(['status' => User::STATUS_BANNED]);

        $this->getJson('/api/v1/ja', $this->naglowek($token))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'konto_zamkniete');

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_ban_przez_metode_kasuje_tokeny_od_razu(): void
    {
        $basia = $this->user('basia');
        $basia->createToken('Telefon');
        $basia->createToken('Tablet');

        $basia->ban();

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    // --------------------------------------------------------------
    //  2FA
    // --------------------------------------------------------------

    public function test_konto_z_2fa_nie_dostaje_tokenu_przed_kodem(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $this->wlacz2fa($basia);

        $odpowiedz = $this->zaloguj('basia@example.com');

        $odpowiedz->assertStatus(202)
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonMissingPath('token');

        $this->assertIsString($odpowiedz->json('challenge'));
        $this->assertSame(0, PersonalAccessToken::query()->count(), 'Token powstał przed podaniem kodu.');

        // Wyzwanie nie jest tokenem.
        $this->getJson('/api/v1/ja', $this->naglowek((string) $odpowiedz->json('challenge')))->assertUnauthorized();
    }

    public function test_poprawny_kod_po_wyzwaniu_wydaje_token_z_nazwa_urzadzenia_z_pierwszego_kroku(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $sekret = $this->wlacz2fa($basia);

        $wyzwanie = (string) $this->zaloguj('basia@example.com')->json('challenge');

        $odpowiedz = $this->postJson('/api/v1/tokeny/kod', [
            'challenge' => $wyzwanie,
            'code' => (new Google2FA)->getCurrentOtp($sekret),
        ]);

        $odpowiedz->assertCreated()->assertJsonPath('device.name', 'Telefon Basi');

        $this->getJson('/api/v1/ja', $this->naglowek((string) $odpowiedz->json('token')))
            ->assertOk()
            ->assertJsonPath('data.two_factor_enabled', true);
    }

    public function test_kod_zapasowy_dziala_raz(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $this->wlacz2fa($basia);

        $wyzwanie = (string) $this->zaloguj('basia@example.com')->json('challenge');

        $this->postJson('/api/v1/tokeny/kod', ['challenge' => $wyzwanie, 'backup_code' => 'ABCD-1234'])->assertCreated();

        $this->postJson('/api/v1/tokeny/kod', ['challenge' => $wyzwanie, 'backup_code' => 'ABCD-1234'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('backup_code');
    }

    public function test_zly_kod_liczy_sie_do_tego_samego_koszyka_co_na_www(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $sekret = $this->wlacz2fa($basia);
        [$maxProb] = TwoFactorAuthenticator::limitProb();

        $wyzwanie = (string) $this->zaloguj('basia@example.com')->json('challenge');

        for ($i = 0; $i < $maxProb - 1; $i++) {
            $this->postJson('/api/v1/tokeny/kod', ['challenge' => $wyzwanie, 'code' => '000000'])
                ->assertUnprocessable()
                ->assertJsonPath('errors.code.0', 'Kod jest nieprawidłowy albo już wykorzystany. Sprawdź godzinę w telefonie i spróbuj ponownie.');
        }

        // Ostatnią próbę z budżetu zużywa WWW — ten sam koszyk po koncie.
        $this->withSession(TwoFactorAuthenticator::oczekujaceLogowanie($basia->fresh()))
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->post(route('login.two_factor.store'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        // Budżet wyczerpany: nawet poprawny kod nie przechodzi.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->postJson('/api/v1/tokeny/kod', ['challenge' => $wyzwanie, 'code' => (new Google2FA)->getCurrentOtp($sekret)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', fn (string $zdanie) => str_starts_with($zdanie, 'Za dużo prób.'));

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_podrobione_albo_przeterminowane_wyzwanie_odsyla_do_pierwszego_kroku(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $sekret = $this->wlacz2fa($basia);

        $wyzwanie = (string) $this->zaloguj('basia@example.com')->json('challenge');

        foreach (['abc', Str::random(200), substr($wyzwanie, 0, -4).'AAAA'] as $zle) {
            $this->postJson('/api/v1/tokeny/kod', ['challenge' => $zle, 'code' => (new Google2FA)->getCurrentOtp($sekret)])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('challenge');
        }

        $this->travel((int) config('kuking.api.wyzwanie_minut') + 1)->minutes();

        $this->postJson('/api/v1/tokeny/kod', ['challenge' => $wyzwanie, 'code' => (new Google2FA)->getCurrentOtp($sekret)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('challenge');

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_zmiana_hasla_po_pierwszym_kroku_uniewaznia_wyzwanie(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $sekret = $this->wlacz2fa($basia);

        $wyzwanie = (string) $this->zaloguj('basia@example.com')->json('challenge');

        $basia->forceFill(['password' => Hash::make('zupelnie-nowe-haslo-9')])->save();

        $this->postJson('/api/v1/tokeny/kod', ['challenge' => $wyzwanie, 'code' => (new Google2FA)->getCurrentOtp($sekret)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('challenge');
    }

    // --------------------------------------------------------------
    //  Wylogowanie i limit urządzeń
    // --------------------------------------------------------------

    public function test_wylogowanie_odwoluje_tylko_biezacy_token(): void
    {
        $basia = $this->user('basia');
        $telefon = $basia->createToken('Telefon')->plainTextToken;
        $tablet = $basia->createToken('Tablet')->plainTextToken;

        $this->deleteJson('/api/v1/tokeny/biezacy', [], $this->naglowek($telefon))->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/ja', $this->naglowek($telefon))->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/ja', $this->naglowek($tablet))->assertOk();

        $this->assertSame(1, AuditLogEntry::query()->where('action', 'account.api_token_revoked')->count());
    }

    public function test_bez_tokenu_wylogowanie_i_ja_to_401(): void
    {
        $this->deleteJson('/api/v1/tokeny/biezacy')->assertUnauthorized();
        $this->getJson('/api/v1/ja')->assertUnauthorized();
    }

    public function test_logowanie_ponad_limit_urzadzen_odwoluje_najdawniej_uzywane(): void
    {
        config(['kuking.api.max_urzadzen' => 3]);
        $basia = $this->user('basia');

        $najstarszy = $basia->createToken('Stary telefon');
        $najstarszy->accessToken->forceFill(['last_used_at' => now()->subYear()])->save();
        $basia->createToken('Tablet')->accessToken->forceFill(['last_used_at' => now()->subDay()])->save();
        $basia->createToken('Laptop')->accessToken->forceFill(['last_used_at' => now()->subHour()])->save();

        $this->zaloguj('basia')->assertCreated();

        $nazwy = $basia->tokens()->pluck('name')->sort()->values()->all();

        $this->assertSame(['Laptop', 'Tablet', 'Telefon Basi'], $nazwy);
    }

    // --------------------------------------------------------------
    //  Pomocnicze
    // --------------------------------------------------------------

    private function zaloguj(string $login, string $haslo = self::HASLO): TestResponse
    {
        return $this->postJson('/api/v1/tokeny', [
            'login' => $login,
            'password' => $haslo,
            'device_name' => 'Telefon Basi',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function naglowek(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function wlacz2fa(User $user): string
    {
        $totp = app(TwoFactorAuthenticator::class);
        $sekret = $totp->generateSecret();
        $user->beginTwoFactorSetup($sekret);
        $user->confirmTwoFactor($totp->hashBackupCodes(['ABCD-1234']));
        $user->refresh();

        return $sekret;
    }
}
