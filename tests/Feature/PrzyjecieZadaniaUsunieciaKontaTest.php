<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\CancelAccountDeletion;
use App\Models\AuditLogEntry;
use App\Models\PotwierdzenieZadaniaRodo;
use App\Models\User;
use App\Support\NumerZadaniaRodo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Przyjęcie żądania usunięcia konta — trzy usterki jednej ścieżki.
 *
 *  - #1364: konto zawieszone widziało formularz, ale POST odbijał się
 *    w `EnsureAccountIsActive` przed kontrolerem (brak `settings.data.delete`
 *    na liście tras dozwolonych mimo zawieszenia).
 *  - #1347: `account.delete_requested` stał za transakcją; jego awaria
 *    dawała 500 przy koncie już w `pending_delete`.
 *  - #1346: baza nie pilnowała jednej sprawy `w_toku` na konto. Wyścig
 *    dwóch formularzy na dwóch połączeniach:
 *    `tests/Dwa/DwaZadaniaUsunieciaKontaRownolegleTest.php`.
 *
 * ── KONTROLE UJEMNE (wykonane) ──
 *  - bez `settings.data.delete` w `DOZWOLONE_MIMO_ZAWIESZENIA` oba testy
 *    zawieszenia padają (status zostaje `suspended`, błąd „konto”),
 *  - `AuditLogEntry::record()` przeniesione z powrotem za transakcję:
 *    test awarii dziennika pada (`pending_delete` przy odpowiedzi z błędem),
 *  - bez migracji indeksu test drugiej sprawy `w_toku` pada (INSERT przechodzi).
 */
class PrzyjecieZadaniaUsunieciaKontaTest extends TestCase
{
    use RefreshDatabase;

    private const HASLO = 'haslo-testowe-123';

    private bool $awaria = true;

    private function usun(User $kto, bool $wszystko = false)
    {
        return $this->actingAs($kto)
            ->from(route('settings.data'))
            ->post(route('settings.data.delete'), array_filter([
                'password' => self::HASLO,
                'confirm' => '1',
                'usun_tresci' => $wszystko ? '1' : null,
            ]));
    }

    private function wToku(User $kto): int
    {
        return PotwierdzenieZadaniaRodo::query()
            ->where('konto_id', $kto->getKey())
            ->where('wynik', PotwierdzenieZadaniaRodo::WYNIK_W_TOKU)
            ->count();
    }

    private function wpisy(User $kto): int
    {
        return AuditLogEntry::query()
            ->where('action', 'account.delete_requested')
            ->where('subject_id', $kto->getKey())
            ->count();
    }

    // ------------------------------------------------------------------
    // #1364 — zawieszone konto
    // ------------------------------------------------------------------

    public function test_zawieszenie_bez_terminu_nie_zamyka_drogi_do_usuniecia_konta(): void
    {
        $basia = $this->user('basia');
        $this->user('halina');
        $basia->suspend();

        $this->actingAs($basia)->get(route('settings.data'))
            ->assertOk()
            ->assertSee('action="'.route('settings.data.delete').'"', false);

        $this->usun($basia)->assertRedirect(route('landing'))->assertSessionHasNoErrors();

        $stan = $basia->fresh();
        $this->assertSame(User::STATUS_PENDING_DELETE, $stan->status);
        $this->assertSame(User::DELETE_SCOPE_MINIMUM, $stan->delete_scope);
        $this->assertSame(User::STATUS_SUSPENDED, $stan->punishment_status, 'Kara ma czekać, nie zniknąć (#980).');
        $this->assertNull($stan->punishment_expires_at);
        $this->assertSame(1, $this->wToku($basia));
        $this->assertSame(['zakres' => User::DELETE_SCOPE_MINIMUM], AuditLogEntry::query()
            ->where('action', 'account.delete_requested')->sole()->metadata);
        $this->assertGuest();
    }

    public function test_zawieszenie_czasowe_przechodzi_przez_check_i_zeruje_termin(): void
    {
        $basia = $this->user('basia');
        $termin = Carbon::now()->addDays(7)->startOfSecond();
        $basia->suspend($termin);

        $this->usun($basia, wszystko: true)->assertRedirect(route('landing'))->assertSessionHasNoErrors();

        $stan = $basia->fresh();
        $this->assertSame(User::STATUS_PENDING_DELETE, $stan->status);
        $this->assertNull($stan->status_expires_at, 'CHECK status_expires_at wymaga NULL poza zawieszeniem.');
        $this->assertSame(User::STATUS_SUSPENDED, $stan->punishment_status);
        $this->assertTrue($termin->equalTo($stan->punishment_expires_at));
        $this->assertSame(User::DELETE_SCOPE_EVERYTHING, $stan->delete_scope);
        $this->assertSame(['zakres' => User::DELETE_SCOPE_EVERYTHING], AuditLogEntry::query()
            ->where('action', 'account.delete_requested')->sole()->metadata);
    }

    public function test_zawieszone_konto_z_blednym_haslem_nie_zmienia_ani_statusu_ani_terminu(): void
    {
        $basia = $this->user('basia');
        $termin = Carbon::now()->addDays(7)->startOfSecond();
        $basia->suspend($termin);

        $this->actingAs($basia)
            ->from(route('settings.data'))
            ->post(route('settings.data.delete'), ['password' => 'zle-haslo', 'confirm' => '1'])
            ->assertRedirect(route('settings.data'))
            ->assertSessionHasErrors('password');

        $stan = $basia->fresh();
        $this->assertSame(User::STATUS_SUSPENDED, $stan->status);
        $this->assertTrue($termin->equalTo($stan->status_expires_at));
        $this->assertNull($stan->delete_requested_at);
        $this->assertSame(0, $this->wToku($basia));
        $this->assertSame(0, $this->wpisy($basia));
    }

    /** Kontrola dodatnia: wyjątek dotyczy tylko tej trasy, zapis dalej jest zablokowany. */
    public function test_zawieszone_konto_nadal_nie_obserwuje_nikogo(): void
    {
        $basia = $this->user('basia');
        $this->user('halina');
        $basia->suspend();

        $this->actingAs($basia)
            ->from(route('profile.show', 'halina'))
            ->post(route('social.follow', 'halina'))
            ->assertSessionHasErrors('konto');

        $this->assertSame(0, DB::table('follows')->where('follower_id', $basia->getKey())->count());
    }

    // ------------------------------------------------------------------
    // #1347 — awaria dziennika audytu
    // ------------------------------------------------------------------

    public function test_awaria_dziennika_cofa_zadanie_a_ponowienie_daje_jeden_komplet(): void
    {
        Exceptions::fake();
        $basia = $this->user('basia');

        // Awaria PO wykonaniu INSERT-u (`DB::listen` woła się po zapytaniu).
        DB::listen(function ($zapytanie): void {
            if ($this->awaria
                && str_contains($zapytanie->sql, 'insert into "audit_log"')
                && in_array('account.delete_requested', $zapytanie->bindings, true)) {
                throw new RuntimeException('Wstrzyknięta awaria dziennika: account.delete_requested');
            }
        });

        $this->usun($basia, wszystko: true)
            ->assertRedirect(route('settings.data'))
            ->assertSessionHasErrors(['confirm'])
            ->assertSessionHasInput('usun_tresci', '1');

        $this->assertStringContainsString('nic się nie zmieniło', session('errors')->first('confirm'));
        $stan = $basia->fresh();
        $this->assertSame(User::STATUS_ACTIVE, $stan->status);
        $this->assertNull($stan->delete_requested_at);
        $this->assertNull($stan->delete_scope);
        $this->assertSame(0, $this->wToku($basia));
        $this->assertSame(0, $this->wpisy($basia));
        $this->assertAuthenticatedAs($basia);
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'Wstrzyknięta awaria dziennika'));

        // Model z żądania wrócił do stanu z bazy razem z wycofaniem.
        $this->assertSame(User::STATUS_ACTIVE, $basia->status);

        // Awaria minęła — człowiek klika jeszcze raz.
        $this->awaria = false;

        $this->usun($basia, wszystko: true)->assertRedirect(route('landing'))->assertSessionHasNoErrors();

        $this->assertSame(User::STATUS_PENDING_DELETE, $basia->fresh()->status);
        $this->assertSame(1, $this->wToku($basia));
        $this->assertSame(1, $this->wpisy($basia));
        $this->assertGuest();
    }

    public function test_kontrola_dodatnia_bez_awarii_konto_sprawa_i_wpis_powstaja_razem(): void
    {
        $basia = $this->user('basia');

        $this->usun($basia)->assertRedirect(route('landing'));

        $this->assertSame(User::STATUS_PENDING_DELETE, $basia->fresh()->status);
        $this->assertSame(1, $this->wToku($basia));
        $this->assertSame(1, $this->wpisy($basia));
    }

    // ------------------------------------------------------------------
    // #1346 — jedna sprawa w toku na konto
    // ------------------------------------------------------------------

    public function test_baza_nie_przyjmie_drugiej_sprawy_w_toku_dla_tego_samego_konta(): void
    {
        $basia = $this->user('basia');
        $this->usun($basia)->assertRedirect(route('landing'));

        $wiersz = (array) DB::table('potwierdzenia_zadan_rodo')->where('konto_id', $basia->getKey())->sole();
        unset($wiersz['id'], $wiersz['numer']);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(fn () => DB::table('potwierdzenia_zadan_rodo')->insert(
            [...$wiersz, 'id' => (string) Str::uuid(), 'numer' => $this->drugiNumer()],
        ));
    }

    /** Kontrola dodatnia: indeks nie blokuje innego konta ani zamkniętej sprawy tego samego. */
    public function test_indeks_przepuszcza_inne_konto_i_zamknieta_sprawe(): void
    {
        $basia = $this->user('basia');
        $halina = $this->user('halina');

        $this->usun($basia)->assertRedirect(route('landing'));
        $this->usun($halina)->assertRedirect(route('landing'));

        app(CancelAccountDeletion::class)->handle($basia->fresh());
        $this->usun($basia->fresh())->assertRedirect(route('landing'));

        $this->assertSame(1, $this->wToku($basia));
        $this->assertSame(1, $this->wToku($halina));
        $this->assertSame(1, PotwierdzenieZadaniaRodo::query()->where('konto_id', $basia->getKey())
            ->where('wynik', PotwierdzenieZadaniaRodo::WYNIK_COFNIETE)->count());
    }

    private function drugiNumer(): string
    {
        return NumerZadaniaRodo::wygeneruj();
    }
}
