<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\PurgeExpiredAccountDeletions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Nocne wymazywanie kont przy backlogu większym niż partia i budżet
 * (issue #1028).
 *
 * Wcześniej `kuking:usun-wygasle-konta` wczytywał całą kolejkę jednym
 * `get()` i nie miał limitu na jedno uruchomienie. Harmonogram woła komendę
 * przez `Artisan::call()`, więc zator nie zostawiał śladu nigdzie poza
 * zegarem. Teraz: partie, budżet, najstarsze najpierw i ostrzeżenie
 * w dzienniku z liczbą kont, które czekają na następną noc.
 */
class WymazywanieKontPartiamiIBudzetemTest extends TestCase
{
    use RefreshDatabase;

    private function kontoPoTerminie(int $dniTemu): User
    {
        return $this->user(null, [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays($dniTemu),
        ]);
    }

    public function test_backlog_wiekszy_niz_partia_i_budzet_znika_w_dwoch_przebiegach_najstarsze_najpierw(): void
    {
        // Partia mniejsza niż kolejka — konta wchodzą do pamięci po dwa.
        $this->app->when(PurgeExpiredAccountDeletions::class)->needs('$rozmiarPartii')->give(2);

        $konta = collect([40, 35, 50, 33, 45])->map(fn (int $dni): User => $this->kontoPoTerminie($dni));
        $najstarsze = $konta->sortByDesc(fn (User $u): int => (int) $u->delete_requested_at->diffInDays(now(), true))
            ->take(3)->map(fn (User $u): string => (string) $u->getKey())->values()->all();

        $log = Log::spy();

        $this->artisan('kuking:usun-wygasle-konta', ['--limit' => 3])
            ->expectsOutputToContain('Usunięto dane kont: 3.')
            ->expectsOutputToContain('Zostaje na następny przebieg: kont do wymazania 2')
            ->assertSuccessful();

        $wymazane = User::query()->whereNotNull('data_erased_at')->pluck('id')->map(fn ($id): string => (string) $id)->sort()->values()->all();
        $this->assertSame(collect($najstarsze)->sort()->values()->all(), $wymazane,
            'Przy budżecie najpierw idą najstarsze zgłoszenia — to im najdłużej biegnie obietnica usunięcia.');

        $log->shouldHaveReceived('warning')
            ->withArgs(static fn (string $komunikat, array $kontekst): bool => ($kontekst['zostalo_do_wymazania'] ?? null) === 2)
            ->once();

        $this->artisan('kuking:usun-wygasle-konta', ['--limit' => 3])
            ->expectsOutputToContain('Usunięto dane kont: 2.')
            ->doesntExpectOutputToContain('Zostaje na następny przebieg')
            ->assertSuccessful();

        $this->assertSame(5, User::query()->whereNotNull('data_erased_at')->count());
    }

    public function test_bez_opcji_limitu_jeden_przebieg_bierze_cala_zwykla_kolejke(): void
    {
        $this->app->when(PurgeExpiredAccountDeletions::class)->needs('$rozmiarPartii')->give(2);

        foreach (range(31, 35) as $dni) {
            $this->kontoPoTerminie($dni);
        }

        $this->artisan('kuking:usun-wygasle-konta')
            ->expectsOutputToContain('Usunięto dane kont: 5.')
            ->assertSuccessful();

        $this->assertSame(5, User::query()->whereNotNull('data_erased_at')->count());
    }

    /**
     * Awaria jednego konta nie zatrzymuje pozostałych (issue #1028).
     *
     * Wyzwalacz PostgreSQL odrzuca wymazanie jednego, najstarszego konta —
     * tak jak odrzuciłaby je awaria bazy albo zerwane połączenie w połowie
     * transakcji. Wcześniej wyjątek wychodził z pętli i konta za nim
     * czekały do następnej nocy, a następnej nocy znowu stało przed nimi
     * to samo konto.
     */
    public function test_awaria_jednego_konta_nie_zatrzymuje_nastepnych_ani_nie_wycieka_do_logu(): void
    {
        $this->app->when(PurgeExpiredAccountDeletions::class)->needs('$rozmiarPartii')->give(2);

        $zepsute = $this->kontoPoTerminie(60);
        $pozostale = collect([40, 35, 33])->map(fn (int $dni): User => $this->kontoPoTerminie($dni));

        DB::unprepared(<<<SQL
            CREATE FUNCTION test_odrzuc_wymazanie() RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION 'awaria testowa dla %', OLD.email;
            END;
            \$\$ LANGUAGE plpgsql;
            CREATE TRIGGER test_odrzuc_wymazanie BEFORE UPDATE ON users
                FOR EACH ROW WHEN (OLD.id = '{$zepsute->getKey()}')
                EXECUTE FUNCTION test_odrzuc_wymazanie();
            SQL);

        $log = Log::spy();

        $this->artisan('kuking:usun-wygasle-konta')
            ->expectsOutputToContain('Nie udało się obsłużyć konta '.$zepsute->getKey())
            ->expectsOutputToContain('Usunięto dane kont: 3. Dokończono kasowanie zdjęć: 0. Nieudane: 1.')
            ->assertSuccessful();

        foreach ($pozostale as $konto) {
            $this->assertNotNull($konto->fresh()->data_erased_at, 'Konta za zepsutym też mają być wymazane w tym samym przebiegu.');
        }

        $this->assertNull($zepsute->fresh()->data_erased_at, 'Nieudane konto zostaje nietknięte (transakcja per konto).');
        $this->assertSame(User::STATUS_PENDING_DELETE, $zepsute->fresh()->status);

        // Komunikat wyjątku z bazy niesie e-mail — do dziennika nie trafia.
        $log->shouldHaveReceived('error')
            ->withArgs(static fn (string $komunikat, array $kontekst): bool => $kontekst === [
                'konto_id' => (string) $zepsute->getKey(),
                'wyjatek' => $kontekst['wyjatek'] ?? null,
            ] && ! str_contains(json_encode($kontekst), '@'))
            ->once();

        // Podsumowanie: same liczby, łącznie z wiekiem najstarszej zaległości
        // (60 dni od zgłoszenia − 30 dni karencji = 30 dni po terminie).
        $log->shouldHaveReceived('info')
            ->withArgs(static fn (string $komunikat, array $kontekst): bool => $komunikat === 'Wymazywanie kont po karencji: podsumowanie przebiegu'
                && $kontekst['wymazane'] === 3
                && $kontekst['nieudane'] === 1
                && $kontekst['zostalo_do_wymazania'] === 1
                && $kontekst['zostalo_do_ponowienia_zdjec'] === 0
                && $kontekst['najstarsza_zaleglosc_dni'] === 30)
            ->once();

        // Po usunięciu awarii następny przebieg domyka zaległość.
        DB::unprepared('DROP TRIGGER test_odrzuc_wymazanie ON users; DROP FUNCTION test_odrzuc_wymazanie();');

        $this->artisan('kuking:usun-wygasle-konta')
            ->expectsOutputToContain('Usunięto dane kont: 1.')
            ->doesntExpectOutputToContain('Nieudane')
            ->assertSuccessful();

        $this->assertNotNull($zepsute->fresh()->data_erased_at);
    }

    public function test_limit_niebedacy_dodatnia_liczba_konczy_sie_bledem_bez_zadnej_zmiany(): void
    {
        $konto = $this->kontoPoTerminie(40);

        foreach (['abc', '0', '-5', '2x'] as $zly) {
            $this->artisan('kuking:usun-wygasle-konta', ['--limit' => $zly])
                ->expectsOutputToContain('Opcja --limit musi być dodatnią liczbą całkowitą')
                ->assertFailed();
        }

        $this->assertNull($konto->fresh()->data_erased_at, 'Literówka w opcji nie może uruchomić wymazywania.');
    }
}
