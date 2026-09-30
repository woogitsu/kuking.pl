<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Http\Controllers\AppealController;
use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * `POST /odwolanie` nie jest wyrocznią hasła i nie omija 2FA
 * (#2272, audyt 30.09.2026 S-04).
 *
 * Przed poprawką złe hasło dawało „Nie rozpoznajemy tych danych…”, a dobre
 * — „Nie mamy decyzji, od której można się teraz odwołać…”. Formularz bez
 * Turnstile odpowiadał więc na pytanie „czy to hasło pasuje do tego konta”
 * dla każdego aktywnego konta. Konto z 2FA składało tu odwołanie samym
 * hasłem, choć samym hasłem się nie loguje. Brak Turnstile sprawdza
 * `TurnstileWymagaPotwierdzeniaTest` (miejsce `odwolanie`).
 */
class OdwolanieGosciaNieJestWyroczniaHaslaTest extends TestCase
{
    use RefreshDatabase;

    private const HASLO = 'haslo-testowe-123';

    private const KOD_ZAPASOWY = 'ABCDE-23456';

    public function test_dobre_haslo_bez_decyzji_i_zle_haslo_daja_to_samo_zdanie(): void
    {
        $this->user('aktywna');

        // Błędy czytane od razu po każdym żądaniu: sesja testu jest wspólna.
        $zle = $this->bledy($this->zloz('aktywna', 'zle-haslo-sasiadki'), 'login');
        $dobre = $this->bledy($this->zloz('aktywna', self::HASLO), 'login');
        $brakKonta = $this->bledy($this->zloz('nie-ma-takiego-konta', 'cokolwiek'), 'login');

        $this->assertSame([AppealController::nieMozemyPrzyjac()], $zle);
        $this->assertSame($zle, $dobre,
            'Dobre hasło na koncie bez decyzji daje inne zdanie niż złe — formularz zdradza hasło.');
        $this->assertSame($zle, $brakKonta);
        $this->assertDatabaseCount('appeals', 0);
    }

    /** KONTROLA DODATNIA: konto z decyzją i dobrym hasłem składa odwołanie. */
    public function test_dobre_haslo_i_decyzja_skladaja_odwolanie(): void
    {
        $osoba = $this->user('zablokowana');
        $this->decyzja($osoba);

        $this->zloz('zablokowana', self::HASLO)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('appeals.guest'));

        $this->assertSame((string) $osoba->getKey(), (string) Appeal::query()->sole()->user_id);
    }

    public function test_konto_z_2fa_nie_sklada_odwolania_samym_haslem(): void
    {
        $osoba = $this->kontoZ2fa('zablokowana2fa');
        $this->decyzja($osoba);

        $odpowiedz = $this->zloz('zablokowana2fa', self::HASLO)->assertSessionHasErrors('code');

        $this->assertStringContainsString('weryfikację dwuetapową', $this->bledy($odpowiedz, 'code')[0]);
        $this->assertDatabaseCount('appeals', 0);
    }

    public function test_konto_z_2fa_ze_zlym_kodem_nie_sklada_odwolania_i_kod_liczy_sie_do_limitu(): void
    {
        $osoba = $this->kontoZ2fa('zlykod2fa');
        $this->decyzja($osoba);

        $this->zloz('zlykod2fa', self::HASLO, ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertDatabaseCount('appeals', 0);
        $this->assertSame(1, RateLimiter::attempts(TwoFactorAuthenticator::kluczLimituProb($osoba)));
    }

    public function test_konto_z_2fa_i_poprawnym_kodem_sklada_odwolanie(): void
    {
        $osoba = $this->kontoZ2fa('dobrykod2fa');
        $this->decyzja($osoba);

        $this->zloz('dobrykod2fa', self::HASLO, ['code' => (new Google2FA)->getCurrentOtp($osoba->two_factor_secret)])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('appeals.guest'));

        $this->assertSame((string) $osoba->getKey(), (string) Appeal::query()->sole()->user_id);
    }

    public function test_kod_zapasowy_na_koncie_bez_decyzji_nie_jest_zuzyty(): void
    {
        $osoba = $this->kontoZ2fa('zapasowy2fa');

        $odpowiedz = $this->zloz('zapasowy2fa', self::HASLO, ['code' => self::KOD_ZAPASOWY]);

        $this->assertSame([AppealController::nieMozemyPrzyjac()], $this->bledy($odpowiedz, 'login'));
        $this->assertCount(1, $osoba->fresh()->two_factor_backup_codes, 'Pomyłka co do stanu konta zjadła kod ratunkowy.');
    }

    public function test_kod_i_haslo_nie_wracaja_w_formularzu_a_tresc_odwolania_wraca(): void
    {
        $osoba = $this->kontoZ2fa('sekrety2fa');
        $this->decyzja($osoba);

        $this->zloz('sekrety2fa', self::HASLO, ['code' => '000000']);

        $stare = (array) session('_old_input');
        $this->assertArrayNotHasKey('code', $stare);
        $this->assertArrayNotHasKey('password', $stare);
        $this->assertSame('To była pomyłka, proszę sprawdzić jeszcze raz.', $stare['body'] ?? null);
    }

    /** @param  array<string, string>  $dodatkowe */
    private function zloz(string $login, string $haslo, array $dodatkowe = []): TestResponse
    {
        return $this->from(route('appeals.guest'))->post(route('appeals.guest.store'), [
            'login' => $login,
            'password' => $haslo,
            'body' => 'To była pomyłka, proszę sprawdzić jeszcze raz.',
            ...$dodatkowe,
        ]);
    }

    /** @return list<string> */
    private function bledy(TestResponse $odpowiedz, string $pole): array
    {
        // Przekierowanie po walidacji niesie ten sam magazyn sesji, który
        // trzyma aplikacja testu (`$odpowiedz` zostaje dla czytelności wywołań) —
        // czytamy go wprost, bez __call na odpowiedzi.
        $bledy = app('session.store')->get('errors');

        // Sesja testu trzyma worek błędów już zserializowany (tablica) albo jako obiekt.
        return $bledy instanceof ViewErrorBag
            ? $bledy->get($pole)
            : (array) ($bledy['default']['messages'][$pole] ?? []);
    }

    private function decyzja(User $osoba): ModerationAction
    {
        $moderator = $this->user(null, ['role' => User::ROLE_MODERATOR]);

        return ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'target_type' => 'user',
            'target_id' => $osoba->getKey(),
            'subject_user_id' => $osoba->getKey(),
            'action' => ModerationAction::ACTION_WARN,
            'reason_code' => 'spam',
            'user_message' => 'Linki reklamowe pod cudzymi przepisami.',
        ]);
    }

    private function kontoZ2fa(string $nazwa): User
    {
        $osoba = $this->user($nazwa);

        $totp = app(TwoFactorAuthenticator::class);
        $osoba->beginTwoFactorSetup($totp->generateSecret());
        $osoba->confirmTwoFactor($totp->hashBackupCodes([self::KOD_ZAPASOWY]));

        return $osoba->refresh();
    }
}
