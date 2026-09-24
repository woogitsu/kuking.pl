<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\PurgeExpiredAccountDeletions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
