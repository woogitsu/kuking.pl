<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\RequestEmailChange;
use App\Models\AuditLogEntry;
use App\Models\PendingEmailChange;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Tests\TestCase;

/**
 * Zapis hasła i anulowanie zamówionej zmiany adresu to JEDNO zdarzenie
 * (issue #1358), a zmiana hasła w ustawieniach zamyka też link resetu
 * i odnawia identyfikator bieżącej sesji.
 *
 * Kolejność blokad na dwóch połączeniach PostgreSQL mierzy osobno
 * `Tests\Dwa\HasloPodBlokadaKontaTest`. Tu, na jednym połączeniu, sprawdzamy
 * to, czego tamten nie widzi: że BŁĄD w środku operacji wycofuje nowe hasło
 * razem z resztą, a dziennik audytu nie opisuje zmiany, której nie ma.
 *
 * Błąd wstrzykujemy po skasowaniu wiersza `pending_email_changes` — to
 * ostatni moment, w którym stary kod miał już zatwierdzone nowe hasło.
 */
class HasloIZmianaAdresuWJednejTransakcjiTest extends TestCase
{
    use RefreshDatabase;

    private const NOWE_HASLO = 'zupelnienowehaslo789';

    protected function setUp(): void
    {
        parent::setUp();

        // Zamówienie zmiany adresu wysyła listy; tu nie o nie chodzi.
        Notification::fake();
    }

    private function basiaZZamowionaZmiana(): User
    {
        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        app(RequestEmailChange::class)->handle($basia, 'napastnik@example.test');

        // Kontrola dodatnia: jest co anulować.
        $this->assertSame(1, PendingEmailChange::query()->where('user_id', $basia->getKey())->count());

        return $basia->fresh();
    }

    private function wywrocPoSkasowaniuZmianyAdresu(): void
    {
        DB::listen(static function (QueryExecuted $zapytanie): void {
            if (str_starts_with($zapytanie->sql, 'delete from "pending_email_changes"')) {
                throw new RuntimeException('Awaria wstrzyknięta przez test #1358.');
            }
        });
    }

    private function assertNicSieNieZmienilo(User $basia, string $staryHash): void
    {
        $this->assertSame($staryHash, $basia->fresh()->password,
            'Nowe hasło zostało, choć operacja padła — obok nadal ważnej zmiany adresu.');
        $this->assertSame(1, PendingEmailChange::query()->where('user_id', $basia->getKey())->count(),
            'Zamówiona zmiana adresu zniknęła, choć hasło się nie zmieniło.');
        $this->assertSame(0, AuditLogEntry::query()
            ->whereIn('action', ['account.password_changed', 'account.password_reset', 'account.email_change_cancelled'])
            ->where('subject_id', $basia->getKey())
            ->count(), 'Dziennik audytu opisuje zmianę, której nie było.');
    }

    public function test_blad_przy_zmianie_hasla_w_ustawieniach_wycofuje_nowe_haslo(): void
    {
        $basia = $this->basiaZZamowionaZmiana();
        $staryHash = $basia->password;
        $this->wywrocPoSkasowaniuZmianyAdresu();

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($basia)->put(route('settings.security.password'), [
                'current_password' => 'haslo-testowe-123',
                'password' => self::NOWE_HASLO,
                'password_confirmation' => self::NOWE_HASLO,
            ]);
            $this->fail('Wstrzyknięta awaria nie dotarła do żądania — test niczego by nie mierzył.');
        } catch (RuntimeException $e) {
            $this->assertSame('Awaria wstrzyknięta przez test #1358.', $e->getMessage());
        }

        $this->assertNicSieNieZmienilo($basia, $staryHash);
    }

    public function test_blad_przy_resecie_hasla_wycofuje_nowe_haslo(): void
    {
        $basia = $this->basiaZZamowionaZmiana();
        $staryHash = $basia->password;
        $token = Password::createToken($basia);
        $this->wywrocPoSkasowaniuZmianyAdresu();

        $this->withoutExceptionHandling();

        try {
            $this->post(route('password.update'), [
                'token' => $token,
                'email' => $basia->email,
                'password' => self::NOWE_HASLO,
                'password_confirmation' => self::NOWE_HASLO,
            ]);
            $this->fail('Wstrzyknięta awaria nie dotarła do żądania — test niczego by nie mierzył.');
        } catch (RuntimeException $e) {
            $this->assertSame('Awaria wstrzyknięta przez test #1358.', $e->getMessage());
        }

        $this->assertNicSieNieZmienilo($basia, $staryHash);
        $this->assertTrue(Password::broker()->tokenExists($basia->fresh(), $token),
            'Link resetu przepadł, choć hasła nie ustawiliśmy — człowiek nie ma jak spróbować ponownie.');
    }

    public function test_zwykla_zmiana_hasla_anuluje_zmiane_adresu_i_zapisuje_oba_zdarzenia(): void
    {
        // KONTROLA DODATNIA dla dwóch testów wyżej: bez awarii to samo
        // żądanie naprawdę zmienia hasło, kasuje żądanie i pisze dziennik.
        $basia = $this->basiaZZamowionaZmiana();

        $this->actingAs($basia)->put(route('settings.security.password'), [
            'current_password' => 'haslo-testowe-123',
            'password' => self::NOWE_HASLO,
            'password_confirmation' => self::NOWE_HASLO,
        ])->assertRedirect()->assertSessionHas('status', fn (string $s): bool => str_contains($s, 'Anulowaliśmy też zamówioną zmianę adresu'));

        $this->assertTrue(Hash::check(self::NOWE_HASLO, $basia->fresh()->password));
        $this->assertSame(0, PendingEmailChange::query()->where('user_id', $basia->getKey())->count());
        $this->assertSame('basia@example.test', $basia->fresh()->email);
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'account.password_changed')->where('subject_id', $basia->getKey())->count());
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'account.email_change_cancelled')->where('subject_id', $basia->getKey())->count());
    }

    public function test_zwykly_reset_hasla_anuluje_zmiane_adresu_i_zapisuje_oba_zdarzenia(): void
    {
        $basia = $this->basiaZZamowionaZmiana();
        $token = Password::createToken($basia);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $basia->email,
            'password' => self::NOWE_HASLO,
            'password_confirmation' => self::NOWE_HASLO,
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check(self::NOWE_HASLO, $basia->fresh()->password));
        $this->assertSame(0, PendingEmailChange::query()->where('user_id', $basia->getKey())->count());
        $this->assertFalse(Password::broker()->tokenExists($basia->fresh(), $token));
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'account.password_reset')->where('subject_id', $basia->getKey())->count());
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'account.email_change_cancelled')->where('subject_id', $basia->getKey())->count());
    }

    public function test_zmiana_hasla_w_ustawieniach_uniewaznia_link_resetu_tego_konta(): void
    {
        // Link „Nie pamiętam hasła" czekający w skrzynce jest drogą na konto
        // niezależną od sesji. Po zmianie hasła w ustawieniach dalej
        // ustawiłby nowe — czyli komuś, kto ma dostęp do tej skrzynki,
        // zmiana hasła niczego by nie odebrała.
        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $inna = $this->user('inna', ['email' => 'inna@example.test']);
        $tokenBasi = Password::createToken($basia);
        $tokenInnej = Password::createToken($inna);
        $this->assertTrue(Password::broker()->tokenExists($basia, $tokenBasi), 'Kontrola dodatnia: link resetu istnieje.');

        $this->actingAs($basia)->put(route('settings.security.password'), [
            'current_password' => 'haslo-testowe-123',
            'password' => self::NOWE_HASLO,
            'password_confirmation' => self::NOWE_HASLO,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse(Password::broker()->tokenExists($basia->fresh(), $tokenBasi),
            'Link resetu wysłany przed zmianą hasła nadal działa.');
        $this->assertTrue(Password::broker()->tokenExists($inna, $tokenInnej),
            'Zmiana hasła jednego konta skasowała link resetu innego konta.');

        // I naprawdę nie da się nim już ustawić hasła.
        auth()->logout();
        $this->post(route('password.update'), [
            'token' => $tokenBasi,
            'email' => 'basia@example.test',
            'password' => 'jeszczeinnehaslo321',
            'password_confirmation' => 'jeszczeinnehaslo321',
        ])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check(self::NOWE_HASLO, $basia->fresh()->password));
    }
}
