<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\AktywniWTygodniu;
use App\Domain\Analytics\CookRetentionCohorts;
use App\Domain\Analytics\LiczbaKukingow;
use App\Domain\Analytics\PowrotPoDniach;
use App\Domain\Analytics\WeeklyActiveCooks;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Raporty analityczne nie przenoszą historii zamkniętych kont (#1309).
 *
 * Dawne `CookEligibility::excludedUserIds()` pobierało do PHP UUID WSZYSTKICH
 * kont `banned`/`pending_delete`/`erased` i zalążkowych, a potem wstawiało je
 * jako parametry `NOT IN` — w UNION aktywności trzy razy. Liczba parametrów
 * i wielkość SQL rosły z całą historią serwisu.
 *
 * Test liczy parametry WSZYSTKICH zapytań jednego pełnego przebiegu metryk
 * (WAC, kohorty i ich rozmiary, aktywni w tygodniu, D7/D30, licznik), dokłada
 * kolejne zamknięte konta Z AKTYWNOŚCIĄ i liczy jeszcze raz. Liczba ma być
 * ta sama — i wyniki też, bo żadne zamknięte konto nie może wrócić do metryk.
 *
 * KONTROLA UJEMNA (wykonana ręcznie przy #1309): po przywróceniu
 * `whereNotIn('id', <lista UUID>)` w `CookEligibility` ten test oblewa na
 * liczbie parametrów (rośnie o liczbę dołożonych kont razy liczbę miejsc
 * użycia). Kontrola dodatnia jest niżej: ten sam pomiar widzi wzrost, gdy
 * dokładamy konta LICZONE — bez niej stała liczba parametrów mogłaby znaczyć
 * „nasłuch nic nie złapał".
 */
class RaportyNieNiosaHistoriiZamknietychKontTest extends TestCase
{
    use RefreshDatabase;

    private function wTygodniu(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-08-31 12:00:00', 'UTC');
    }

    /** Konto z pełną aktywnością: wpis, przepis i „Ugotowałem". */
    private function aktywneKonto(array $atrybuty = []): User
    {
        $konto = $this->user('k'.bin2hex(random_bytes(5)), $atrybuty + [
            'created_at' => $this->wTygodniu()->subDays(40),
            'ostatnio_widziany_at' => $this->wTygodniu()->addDays(1),
        ]);

        Post::factory()->create(['author_id' => $konto->getKey(), 'published_at' => $this->wTygodniu()]);
        $przepis = Recipe::factory()->create(['author_id' => $konto->getKey(), 'published_at' => $this->wTygodniu()]);
        CookedEvent::query()->create([
            'user_id' => $konto->getKey(),
            'recipe_id' => $przepis->getKey(),
            'cooked_at' => $this->wTygodniu(),
        ]);

        return $konto;
    }

    private function zamknieteKonta(int $ile): void
    {
        $statusy = User::STATUSY_ZAMKNIETEGO_KONTA;

        for ($i = 0; $i < $ile; $i++) {
            $status = $statusy[$i % count($statusy)];

            $this->aktywneKonto(['status' => $status] + match ($status) {
                User::STATUS_ERASED => ['data_erased_at' => $this->wTygodniu(), 'delete_scope' => 'minimum'],
                User::STATUS_PENDING_DELETE => ['delete_scope' => 'minimum'],
                default => [],
            });
        }

        // Zalążkowe też są wykluczane i też rosłyby w dawnej liście UUID.
        $this->aktywneKonto(['is_seeded' => true]);
    }

    /**
     * @return array{parametry: int, wyniki: array<string, mixed>}
     */
    private function przebiegMetryk(): array
    {
        $parametry = 0;
        $nasluch = true;

        DB::listen(function (QueryExecuted $zapytanie) use (&$parametry, &$nasluch): void {
            if ($nasluch) {
                $parametry += count($zapytanie->bindings);
            }
        });

        $teraz = $this->wTygodniu()->addDays(2);

        $wyniki = [
            'wac' => app(WeeklyActiveCooks::class)->weekly()->map(fn ($w) => (array) $w)->all(),
            'kohorty' => app(CookRetentionCohorts::class)->weekly()->map(fn ($w) => (array) $w)->all(),
            'rozmiary' => app(CookRetentionCohorts::class)->rozmiaryKohort()->all(),
            'aktywni' => app(AktywniWTygodniu::class)->liczba($teraz),
            'd7' => app(PowrotPoDniach::class)->policz(7, $teraz),
            'd30' => app(PowrotPoDniach::class)->policz(30, $teraz),
            'licznik' => app(LiczbaKukingow::class)->przelicz(),
        ];

        $nasluch = false;

        return ['parametry' => $parametry, 'wyniki' => $wyniki];
    }

    public function test_liczba_parametrow_i_wyniki_nie_zaleza_od_liczby_zamknietych_kont(): void
    {
        config(['kuking.community.host_username' => 'gospodarz', 'kuking.account.test_usernames' => ['testowe']]);
        $this->aktywneKonto();
        $this->aktywneKonto();
        $this->zamknieteKonta(3);

        $przed = $this->przebiegMetryk();

        $this->zamknieteKonta(30);

        $po = $this->przebiegMetryk();

        $this->assertGreaterThan(0, $przed['parametry'], 'Nasłuch nie złapał żadnego parametru — pomiar byłby pusty.');
        $this->assertSame(
            $przed['parametry'],
            $po['parametry'],
            'Liczba parametrów SQL rośnie z liczbą zamkniętych kont — raport znowu przenosi ich UUID (#1309).',
        );
        $this->assertSame($przed['wyniki'], $po['wyniki'], 'Zamknięte albo zalążkowe konto wróciło do metryk.');
        $this->assertSame(2, $po['wyniki']['licznik']);
        $this->assertSame(2, $po['wyniki']['aktywni']);
    }

    /** Kontrola dodatnia: ten sam pomiar widzi zmianę, gdy przybywa LICZONYCH kont. */
    public function test_pomiar_widzi_konta_liczone(): void
    {
        $this->aktywneKonto();
        $przed = $this->przebiegMetryk();

        $this->aktywneKonto();
        $po = $this->przebiegMetryk();

        $this->assertSame(1, $przed['wyniki']['licznik']);
        $this->assertSame(2, $po['wyniki']['licznik']);
        $this->assertNotSame($przed['wyniki'], $po['wyniki']);
    }
}
