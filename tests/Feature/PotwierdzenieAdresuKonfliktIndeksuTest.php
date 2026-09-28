<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PendingEmailChange;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use PDOException;
use Tests\TestCase;

/**
 * Regresja #1435: konflikt indeksu adresu przy ZAPISIE, czyli już po
 * aplikacyjnym „czy adres wolny", ma dać komunikat, a nie 500 — i tylko
 * ten konflikt. Prawdziwy wyścig dwóch połączeń mierzy
 * `tests/Dwa/RownoleglePotwierdzeniaAdresuTest.php`; tu sprawdzamy
 * rozpoznanie indeksu, którego tamten przeplot nie ustawi.
 *
 * Okno między sprawdzeniem a zapisem otwieramy słuchaczem zapytań: zaraz
 * po `exists(... lower(email) = ?)` ktoś „inny" zajmuje adres.
 */
final class PotwierdzenieAdresuKonfliktIndeksuTest extends TestCase
{
    use RefreshDatabase;

    public function test_adres_zajety_wielkimi_literami_tuz_przed_zapisem_daje_komunikat(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $zmiana = $this->oczekujaca($basia, 'wspolny@example.test');
        $tokenPrzed = $basia->fresh()->remember_token;

        // Prawdziwy konflikt `users_email_lower_unique`: ciąg różni się
        // wielkością liter, więc zwykły `users_email_unique` go przepuści.
        $this->poSprawdzeniu(function (): void {
            $inny = $this->user('inny');
            DB::table('users')->where('id', $inny->getKey())->update(['email' => 'WSPOLNY@example.test']);
        });

        $this->actingAs($basia)
            ->get($this->link($zmiana))
            ->assertRedirect(route('settings.email'))
            ->assertSessionHasErrors(['email' => 'Na ten adres jest już założone inne konto w Kuking, a jeden adres to jedno konto. '
                .'Zaloguj się na tamto konto albo zamów zmianę na inny adres. Twoje obecne konto zostaje bez zmian.']);

        $swiezy = $basia->fresh();
        $this->assertSame('basia@example.test', $swiezy->email);
        $this->assertSame($tokenPrzed, $swiezy->remember_token);
        $this->assertTrue(PendingEmailChange::query()->whereKey($zmiana->getKey())->exists());
        $this->assertDatabaseMissing('audit_log', ['action' => 'account.email_changed', 'subject_id' => $basia->getKey()]);
    }

    public function test_inny_konflikt_unikalnosci_nie_udaje_zajetego_adresu(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $zmiana = $this->oczekujaca($basia, 'wolny@example.test');

        // Konflikt, którego ten zapis dziś nie wywoła, ale może jutro —
        // i wtedy ma być widoczny jako błąd techniczny.
        DB::listen(static function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'update "users" set "email"')) {
                throw new UniqueConstraintViolationException('pgsql', $query->sql, [], new PDOException(
                    'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "users_username_lower_unique"',
                ));
            }
        });

        $this->withoutExceptionHandling();
        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('users_username_lower_unique');

        try {
            $this->actingAs($basia)->get($this->link($zmiana));
        } finally {
            $this->assertSame('basia@example.test', $basia->fresh()->email);
        }
    }

    private function oczekujaca(User $user, string $adres): PendingEmailChange
    {
        $zmiana = new PendingEmailChange;
        $zmiana->user_id = $user->getKey();
        $zmiana->new_email = $adres;
        $zmiana->created_at = now();
        $zmiana->expires_at = now()->addHour();
        $zmiana->save();

        return $zmiana;
    }

    /** Wykonuje `$co` raz, zaraz po aplikacyjnym sprawdzeniu zajętości adresu. */
    private function poSprawdzeniu(\Closure $co): void
    {
        $zrobione = false;

        DB::listen(static function (QueryExecuted $query) use (&$zrobione, $co): void {
            if (! $zrobione && str_contains($query->sql, 'exists(') && str_contains($query->sql, 'lower(email) = ?')) {
                $zrobione = true;
                $co();
            }
        });
    }

    private function link(PendingEmailChange $zmiana): string
    {
        return URL::signedRoute('settings.email.confirm', ['zmiana' => $zmiana->getKey()]);
    }
}
