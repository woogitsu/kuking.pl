<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Tabela `cache` ma nocne sprzątanie wygasłych wierszy (#2292, audyt
 * docs/audyt/2026-09-30-wydajnosc-baza.md, F6).
 *
 * Sterownik `database` usuwa wygasły wiersz tylko przy odczycie tego samego
 * klucza, więc klucze limiterów per adres gości leżały na zawsze. Pilnujemy:
 * wygasłe znikają; żywe, „na zawsze” i blokady (`cache_locks`) zostają;
 * partia i budżet mają sufit; zadanie stoi w harmonogramie.
 */
final class SprzatanieWygaslegoCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_kasuje_wygasle_a_zostawia_zywe_wieczne_i_blokady(): void
    {
        $sklep = Cache::store('database');
        $sklep->put('limiter:gosc-a', 1, 60);
        $sklep->put('limiter:gosc-a:timer', time() + 60, 60);
        $sklep->put('zywy-dluzej', 'tak', 3600);
        $sklep->forever('na-zawsze', 'tak');
        $blokada = $sklep->lock('blokada-harmonogramu', 60);
        $this->assertTrue($blokada->get());

        $this->travel(5)->minutes();
        $sklep->put('limiter:gosc-b', 1, 60);

        $this->artisan('kuking:sprzataj-cache')
            ->expectsOutput('Skasowano 2 wygasłych wierszy cache.')
            ->assertSuccessful();

        $klucze = DB::table('cache')->pluck('key')->map(fn ($k): string => (string) $k)->all();
        $prefiks = (string) config('cache.prefix');
        $this->assertNotContains($prefiks.'limiter:gosc-a', $klucze);
        $this->assertNotContains($prefiks.'limiter:gosc-a:timer', $klucze);
        $this->assertContains($prefiks.'zywy-dluzej', $klucze);
        $this->assertContains($prefiks.'na-zawsze', $klucze);
        $this->assertContains($prefiks.'limiter:gosc-b', $klucze);
        $this->assertSame(1, DB::table('cache_locks')->count(), 'Sprzątanie cache nie może ruszać tabeli blokad.');
        $this->assertSame('tak', $sklep->get('zywy-dluzej'));
    }

    public function test_na_sucho_liczy_i_niczego_nie_kasuje(): void
    {
        $this->wstawWygasle(3);

        $this->artisan('kuking:sprzataj-cache', ['--na-sucho' => true])
            ->expectsOutput('Do skasowania: 3 wygasłych wierszy cache.')
            ->assertSuccessful();

        $this->assertSame(3, DB::table('cache')->count());
    }

    public function test_wiersz_z_terminem_rownym_teraz_jest_juz_wygasly(): void
    {
        // Ten sam warunek co `DatabaseStore::forgetManyIfExpired()`:
        // `expiration <= teraz` to dla sterownika brak wpisu.
        $this->freezeTime();
        DB::table('cache')->insert(['key' => 'na-granicy', 'value' => 's:1:"x";', 'expiration' => now()->getTimestamp()]);
        DB::table('cache')->insert(['key' => 'sekunde-dalej', 'value' => 's:1:"x";', 'expiration' => now()->getTimestamp() + 1]);

        $this->artisan('kuking:sprzataj-cache')->assertSuccessful();

        $this->assertSame(['sekunde-dalej'], DB::table('cache')->pluck('key')->all());
    }

    public function test_budzet_przebiegu_ma_sufit_a_reszta_czeka_na_nastepny(): void
    {
        config(['kuking.retencja.partia' => 2, 'kuking.retencja.budzet' => 3]);
        $this->wstawWygasle(5);
        Log::spy();

        $this->artisan('kuking:sprzataj-cache')
            ->expectsOutput('Skasowano 3 wygasłych wierszy cache.')
            ->assertSuccessful();
        $this->assertSame(2, DB::table('cache')->count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $tresc, array $kontekst): bool => $kontekst['tabela'] === 'cache' && $kontekst['pozostalo'] === 2)
            ->once();

        $this->artisan('kuking:sprzataj-cache')->assertSuccessful();
        $this->assertSame(0, DB::table('cache')->count());
    }

    public function test_zadanie_stoi_w_harmonogramie_codziennie_na_jednym_serwerze(): void
    {
        $zadanie = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => $event->description === 'kuking:sprzataj-cache');

        $this->assertNotNull($zadanie, 'Sprzątanie wygasłego cache nie jest zaplanowane.');
        $this->assertSame('45 2 * * *', $zadanie->expression);
        $this->assertTrue($zadanie->onOneServer);
        $this->assertTrue($zadanie->withoutOverlapping);
    }

    private function wstawWygasle(int $ile): void
    {
        $wczoraj = now()->subDay()->getTimestamp();
        foreach (range(1, $ile) as $i) {
            DB::table('cache')->insert(['key' => "stary-{$i}", 'value' => 's:1:"x";', 'expiration' => $wczoraj]);
        }
    }
}
