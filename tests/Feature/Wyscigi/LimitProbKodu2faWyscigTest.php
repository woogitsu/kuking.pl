<?php

declare(strict_types=1);

namespace Tests\Feature\Wyscigi;

use App\Domain\Security\Actions\SprawdzKodDrugiegoSkladnika;
use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\User;
use Closure;
use Illuminate\Cache\RateLimiter as LimiterLaravela;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Regresja issue #2043: równoległe próby kodu 2FA przekraczały limit NA KONTO.
 *
 * Stary porządek był „sprawdź limit → sprawdź kod → policz błędną próbę"
 * (`tooManyAttempts()` przed weryfikacją, `hit()` dopiero po złym kodzie).
 * Żądania, które wszystkie przeczytały licznik, ZANIM pierwsze zdążyło go
 * zwiększyć, szły do weryfikacji wszystkie — z różnych sesji i adresów IP
 * (throttle trasy liczy po IP, więc tego nie łapie).
 *
 * PRZEPLOT WYMUSZAMY BARIERĄ W LIMITERZE: pierwsze żądanie, zaraz po swoim
 * pierwszym pytaniu do limitera (odczyt albo zwiększenie licznika), wpuszcza
 * „w środek" `max` innych prób tego samego konta i dopiero potem idzie dalej.
 * To jest dokładnie okno z issue. Liczymy WYWOŁANIA WERYFIKACJI kodu
 * (`verifyCode` / `consumeBackupCode` / `backupCodeMatches`), nie odpowiedzi:
 * o budżet zgadywania chodzi właśnie w liczbie sprawdzonych kodów.
 *
 * Próby „w środku" to akcja `SprawdzKodDrugiegoSkladnika` — ta sama, którą
 * wołają ekran `/logowanie/kod` i API v1 (D-270, D-271), więc odpowiadają
 * żądaniom z innych sesji i innych adresów IP (akcja adresu nie zna — limit
 * jest po koncie, `TwoFactorAuthenticator::kluczLimituProb`).
 *
 * CZEGO TEN TEST NIE DOWODZI (docs/PULAPKI_TESTOW.md §6): to jeden proces
 * i jedno połączenie. Pokazuje, że decyzja o dopuszczeniu wynika z WYNIKU
 * zwiększenia licznika (rezerwacja przed weryfikacją), więc żaden przeplot
 * między odczytem a zapisem licznika nie daje dodatkowej próby. Blokada
 * wiersza konta w `zarezerwujProbe()` (serializacja między procesami przy
 * magazynie cache bez atomowego `increment`, np. `file`) nie jest tu
 * mierzona — to by wymagało grupy `dwa-polaczenia`.
 */
class LimitProbKodu2faWyscigTest extends TestCase
{
    use RefreshDatabase;

    private const HASLO = 'haslo-testowe-123';

    private LicznikWeryfikacji2fa $licznik;

    private LimiterZBariera $limiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->licznik = new LicznikWeryfikacji2fa;
        $this->app->instance(TwoFactorAuthenticator::class, $this->licznik);

        $this->limiter = new LimiterZBariera($this->app->make('cache')->driver(config('cache.limiter')));
        RateLimiter::swap($this->limiter);
    }

    public function test_rownolegle_bledne_kody_nie_przekraczaja_limitu_konta(): void
    {
        $basia = $this->kontoZ2fa('basia');
        [$max] = TwoFactorAuthenticator::limitProb();
        $wyniki = [];

        $this->limiter->przyPierwszymPytaniu(TwoFactorAuthenticator::kluczLimituProb($basia), function () use ($basia, $max, &$wyniki): void {
            for ($i = 0; $i < $max; $i++) {
                $wyniki[] = $this->akcja()->handle($basia->fresh(), '000000', '')[0];
            }
        });

        $wyniki[] = $this->akcja()->handle($basia, '000000', '')[0];

        $this->assertTrue($this->limiter->barieraZadzialala, 'Bariera nie zadziałała — przeplot się nie ustawił.');
        $this->assertSame($max, $this->licznik->sprawdzen, 'Sprawdzono więcej kodów niż pozwala limit konta.');
        $this->assertSame($max, count(array_keys($wyniki, SprawdzKodDrugiegoSkladnika::BLEDNY, true)));
        $this->assertSame(1, count(array_keys($wyniki, SprawdzKodDrugiegoSkladnika::ZA_DUZO_PROB, true)));
    }

    /**
     * Ta sama bariera, żądanie przez ekran `/logowanie/kod` z innego adresu
     * IP niż wszystkie wcześniejsze, i kod zapasowy zamiast kodu z aplikacji.
     */
    public function test_ekran_logowania_nie_sprawdza_kodu_ponad_limit_przy_przeplocie(): void
    {
        $basia = $this->kontoZ2fa('basia');
        [$max] = TwoFactorAuthenticator::limitProb();

        $this->limiter->przyPierwszymPytaniu(TwoFactorAuthenticator::kluczLimituProb($basia), function () use ($basia, $max): void {
            for ($i = 0; $i < $max; $i++) {
                $this->akcja()->handle($basia->fresh(), '000000', '');
            }
        });

        $this->withSession(TwoFactorAuthenticator::oczekujaceLogowanie($basia))
            ->withServerVariables(['REMOTE_ADDR' => '10.20.43.1'])
            ->post(route('login.two_factor.store'), ['backup_code' => 'ZZZZZ-99999'])
            ->assertSessionHasErrors(['backup_code' => 'Za dużo prób. Spróbuj ponownie za 1 min.']);

        $this->assertTrue($this->limiter->barieraZadzialala);
        $this->assertSame($max, $this->licznik->sprawdzen);
        $this->assertGuest();
    }

    /** Cofnięcie usunięcia konta (#1314) liczy w tym samym koszyku konta. */
    public function test_cofniecie_usuniecia_nie_sprawdza_kodu_ponad_limit_przy_przeplocie(): void
    {
        $basia = $this->kontoZ2fa('basia', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(5),
        ]);
        [$max] = TwoFactorAuthenticator::limitProb();

        $this->limiter->przyPierwszymPytaniu(TwoFactorAuthenticator::kluczLimituProb($basia), function () use ($basia, $max): void {
            for ($i = 0; $i < $max; $i++) {
                $this->akcja()->handle($basia->fresh(), '000000', '');
            }
        });

        $this->post(route('account.delete.cancel.store'), [
            'login' => 'basia',
            'password' => self::HASLO,
            'code' => '000000',
        ])->assertSessionHasErrors(['code' => 'Za dużo prób kodu. Spróbuj ponownie za 1 min.']);

        $this->assertTrue($this->limiter->barieraZadzialala);
        $this->assertSame($max, $this->licznik->sprawdzen);
        $this->assertSame(User::STATUS_PENDING_DELETE, $basia->fresh()->status);
    }

    /**
     * KONTROLA DODATNIA: przy tym samym przeplocie poprawny kod mieszczący się
     * w limicie loguje, a licznik konta zostaje wyczyszczony — jak przed
     * poprawką. Bez tego zielone testy wyżej mogłyby znaczyć „limiter
     * odrzuca wszystko".
     */
    public function test_poprawny_kod_w_limicie_loguje_mimo_przeplotu(): void
    {
        $basia = $this->kontoZ2fa('basia');
        [$max] = TwoFactorAuthenticator::limitProb();

        $this->limiter->przyPierwszymPytaniu(TwoFactorAuthenticator::kluczLimituProb($basia), function () use ($basia, $max): void {
            for ($i = 0; $i < $max - 1; $i++) {
                $this->akcja()->handle($basia->fresh(), '000000', '');
            }
        });

        $this->withSession(TwoFactorAuthenticator::oczekujaceLogowanie($basia))
            ->withServerVariables(['REMOTE_ADDR' => '10.20.43.2'])
            ->post(route('login.two_factor.store'), ['code' => (new Google2FA)->getCurrentOtp($basia->two_factor_secret)])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertTrue($this->limiter->barieraZadzialala);
        $this->assertAuthenticatedAs($basia);
        $this->assertSame($max, $this->licznik->sprawdzen);
        $this->assertSame(0, RateLimiter::attempts(TwoFactorAuthenticator::kluczLimituProb($basia)));
    }

    /**
     * KONTROLA PRZYRZĄDU: stary porządek „sprawdź → zweryfikuj → policz"
     * odegrany na TYM SAMYM limiterze z barierą sprawdza o jeden kod za
     * dużo. Gdyby bariera przestała ustawiać przeplot, ten test by oblał,
     * zamiast zostawić testy wyżej zielone bez znaczenia.
     */
    public function test_przyrzad_wykrywa_stary_porzadek_sprawdz_zweryfikuj_policz(): void
    {
        $basia = $this->kontoZ2fa('basia');
        [$max, $minuty] = TwoFactorAuthenticator::limitProb();
        $klucz = TwoFactorAuthenticator::kluczLimituProb($basia);

        $staraProba = function () use ($basia, $max, $minuty, $klucz): void {
            if (RateLimiter::tooManyAttempts($klucz, $max)) {
                return;
            }

            if (! $this->licznik->verifyCode($basia, (string) $basia->two_factor_secret, '000000')) {
                RateLimiter::hit($klucz, $minuty * 60);
            }
        };

        $this->limiter->przyPierwszymPytaniu(TwoFactorAuthenticator::kluczLimituProb($basia), function () use ($staraProba, $max): void {
            for ($i = 0; $i < $max; $i++) {
                $staraProba();
            }
        });

        $staraProba();

        $this->assertTrue($this->limiter->barieraZadzialala);
        $this->assertSame($max + 1, $this->licznik->sprawdzen);
    }

    private function akcja(): SprawdzKodDrugiegoSkladnika
    {
        return $this->app->make(SprawdzKodDrugiegoSkladnika::class);
    }

    /** @param  array<string, mixed>  $atrybuty */
    private function kontoZ2fa(string $nazwa, array $atrybuty = []): User
    {
        $osoba = $this->user($nazwa, $atrybuty);
        $osoba->beginTwoFactorSetup($this->licznik->generateSecret());
        $osoba->confirmTwoFactor($this->licznik->hashBackupCodes(['ABCDE-23456']));

        return $osoba->refresh();
    }
}

/** Liczy każde sprawdzenie kodu drugiego składnika. */
final class LicznikWeryfikacji2fa extends TwoFactorAuthenticator
{
    public int $sprawdzen = 0;

    public function verifyCode(User $user, string $secret, string $code): bool
    {
        $this->sprawdzen++;

        return parent::verifyCode($user, $secret, $code);
    }

    public function consumeBackupCode(User $user, string $podanyKod): bool
    {
        $this->sprawdzen++;

        return parent::consumeBackupCode($user, $podanyKod);
    }

    public function backupCodeMatches(User $user, string $podanyKod): bool
    {
        $this->sprawdzen++;

        return parent::backupCodeMatches($user, $podanyKod);
    }
}

/**
 * Limiter z barierą: po PIERWSZYM pytaniu o licznik konta (odczyt albo
 * zwiększenie) — już po wykonaniu go naprawdę — jednorazowo uruchamia
 * wstawkę, czyli „równoległe" żądania, i dopiero potem oddaje wynik.
 */
final class LimiterZBariera extends LimiterLaravela
{
    public bool $barieraZadzialala = false;

    private ?Closure $wstawka = null;

    private ?string $klucz = null;

    public function __construct(Cache $cache)
    {
        parent::__construct($cache);
    }

    /** Bariera reaguje tylko na klucz konta — nie na throttle trasy po IP. */
    public function przyPierwszymPytaniu(string $klucz, Closure $wstawka): void
    {
        $this->klucz = $klucz;
        $this->wstawka = $wstawka;
    }

    public function tooManyAttempts($key, $maxAttempts)
    {
        $wynik = parent::tooManyAttempts($key, $maxAttempts);
        $this->bariera((string) $key);

        return $wynik;
    }

    public function increment($key, $decaySeconds = 60, $amount = 1)
    {
        $wynik = parent::increment($key, $decaySeconds, $amount);
        $this->bariera((string) $key);

        return $wynik;
    }

    private function bariera(string $klucz): void
    {
        if ($this->wstawka === null || $klucz !== $this->klucz) {
            return;
        }

        $wstawka = $this->wstawka;
        $this->wstawka = null;
        $this->barieraZadzialala = true;
        $wstawka();
    }
}
