<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\WeeklyActiveCooks;
use App\Models\Post;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tydzień w metrykach zaczyna się w polski poniedziałek — i nie zależy od
 * ustawienia serwera bazy.
 *
 * DWA PROBLEMY, JEDNA PRZYCZYNA
 * `date_trunc('week', activity_at)` na kolumnie `timestamptz` obcina tydzień
 * w strefie SESJI POSTGRESA. Z tego wynikały dwie osobne rzeczy:
 *
 * 1. Aktywność z poniedziałku 00:30 czasu polskiego (niedziela 23:30 UTC)
 *    wpadała do tygodnia POPRZEDNIEGO. Ta sama pomyłka co we `Wspomnieniach`,
 *    w archiwum profilu i na tablicy dnia — tylko że tu przesuwa liczbę,
 *    którą zespół czyta jako trend, a nie ekran.
 *
 * 2. GORSZE, BO NIEWIDOCZNE: to repozytorium NIGDZIE nie ustawia strefy sesji
 *    Postgresa. `config/database.php` nie ma klucza `timezone` dla `pgsql`,
 *    więc sesja bierze domyślną strefę SERWERA bazy. W tym kontenerze to
 *    `Etc/UTC`; na innym serwerze może być cokolwiek — a wtedy TE SAME dane
 *    dają INNY WAC, bez jednej zmiany w kodzie i bez żadnego sygnału.
 *    Drugi test niżej sprawdza właśnie to: wynik ma być niewrażliwy na
 *    `SET TIME ZONE`.
 *
 * (Komentarz w nagłówku `WeeklyActiveCooksTest` twierdził, że strefę sesji
 * ustawia `config/database.php`. Nie ustawia — i to jest poprawione razem
 * z tą zmianą.)
 */
class WacLiczyTydzienWStrefieCzlowiekaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Poniedziałek 9 marca 2026, 00:30 czasu polskiego.
     *
     * Marzec przed ostatnią niedzielą miesiąca, więc Polska jest na CET
     * (UTC+1) — ten sam moment to niedziela 8 marca, 23:30 UTC. Po polsku
     * jest już nowy tydzień, po UTC jeszcze stary.
     */
    private const PONIEDZIALEK_LOKALNY = '2026-03-09';

    private const TEN_MOMENT_W_UTC = '2026-03-08 23:30:00';

    /** Poniedziałek tydzień wcześniej — tam wpadało to zdarzenie przed poprawką. */
    private const POPRZEDNI_PONIEDZIALEK = '2026-03-02';

    public function test_publikacja_z_poniedzialkowej_nocy_liczy_sie_do_biezacego_tygodnia(): void
    {
        $basia = $this->user('basia');

        Post::factory()->create([
            'author_id' => $basia->getKey(),
            'published_at' => CarbonImmutable::parse(self::TEN_MOMENT_W_UTC, 'UTC'),
        ]);

        $tygodnie = app(WeeklyActiveCooks::class)->weekly()
            ->mapWithKeys(fn ($t) => [(string) $t->week_start => (int) $t->weekly_active_cooks])
            ->all();

        $this->assertSame(
            [self::PONIEDZIALEK_LOKALNY => 1],
            $tygodnie,
            'Wpis opublikowany w poniedziałek o 00:30 czasu polskiego trafił do '
            .'innego tygodnia niż ten, w którym powstał dla człowieka. '
            .'`date_trunc(\'week\', …)` na `timestamptz` obcina tydzień w strefie '
            .'sesji bazy, nie w strefie czytelnika.',
        );

        $this->assertArrayNotHasKey(self::POPRZEDNI_PONIEDZIALEK, $tygodnie);
    }

    public function test_wynik_nie_zalezy_od_strefy_sesji_postgresa(): void
    {
        $basia = $this->user('basia');

        Post::factory()->create([
            'author_id' => $basia->getKey(),
            'published_at' => CarbonImmutable::parse(self::TEN_MOMENT_W_UTC, 'UTC'),
        ]);

        $wUtc = $this->tygodnie();

        // Ta sama sesja, inna strefa. Nic w kodzie się nie zmienia — zmienia
        // się wyłącznie ustawienie, którego to repozytorium nie kontroluje.
        DB::statement("SET TIME ZONE 'Pacific/Kiritimati'");

        try {
            $wInnejStrefie = $this->tygodnie();
        } finally {
            DB::statement("SET TIME ZONE 'Etc/UTC'");
        }

        $this->assertSame(
            $wUtc,
            $wInnejStrefie,
            'WAC zmienił się po samym przestawieniu strefy sesji Postgresa. '
            .'To repozytorium nigdzie tej strefy nie ustawia (`config/database.php` '
            .'nie ma klucza `timezone` dla `pgsql`), więc metryka zależałaby od '
            .'konfiguracji serwera bazy — bez śladu w kodzie i bez ostrzeżenia.',
        );
    }

    /** @return array<string, int> */
    private function tygodnie(): array
    {
        return app(WeeklyActiveCooks::class)->weekly()
            ->mapWithKeys(fn ($t) => [(string) $t->week_start => (int) $t->weekly_active_cooks])
            ->all();
    }
}
