<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZapiszSygnal;
use App\Models\ProductSignal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Sprzeciw wobec statystyk da się złożyć i serwis go szanuje (#2277, audyt 30.09 Z1).
 *
 * Polityka prywatności opiera statystyki na uzasadnionym interesie i od
 * początku pisze „możesz się temu sprzeciwić”. Do tej poprawki serwis nie miał
 * pola, w którym sprzeciw dałoby się zapisać: skrypt Cloudflare ładował się
 * każdemu, `ZapiszSygnal` i `ZanotujOstatniaWizyte` zapisywały każde konto.
 *
 * Każda asercja „nic nie zapisano” ma obok kontrolę dodatnią na koncie bez
 * sprzeciwu (`docs/PULAPKI_TESTOW.md` §4).
 */
class SprzeciwWobecStatystykTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACJA = 'database/migrations/2026_09_30_163000_add_sprzeciw_statystyk_at_to_users.php';

    private const TOKEN = 'token-testowy-statystyk';

    public function test_przycisk_w_ustawieniach_zapisuje_sprzeciw_i_kasuje_to_co_zebrano(): void
    {
        $osoba = $this->user('nie_licz_mnie');
        $inna = $this->user('licz_mnie');
        $sygnaly = app(ZapiszSygnal::class);
        $sygnaly->handle($osoba, ZapiszSygnal::SEARCH_PERFORMED, ['query_length' => 4, 'has_results' => true]);
        $sygnaly->handle($inna, ZapiszSygnal::SEARCH_PERFORMED, ['query_length' => 4, 'has_results' => true]);
        $osoba->forceFill(['ostatnio_widziany_at' => now()->subDay()])->save();

        $this->actingAs($osoba)->get(route('settings.privacy'))->assertOk()
            ->assertSee('Nie licz mnie w statystykach');

        $this->actingAs($osoba)->post(route('settings.privacy.sprzeciw-statystyk'))
            ->assertRedirect(route('settings.privacy').'#statystyki');

        $osoba->refresh();
        $this->assertTrue($osoba->sprzeciwWobecStatystyk());
        $this->assertNull($osoba->ostatnio_widziany_at, 'Sprzeciw zostawił datę ostatniej wizyty.');
        $this->assertSame(0, ProductSignal::query()->where('user_id', $osoba->getKey())->count());
        // Zdarzenie zostaje bez powiązania — liczniki zbiorcze się nie zmieniają.
        $this->assertSame(2, ProductSignal::query()->count());
        $this->assertSame(1, ProductSignal::query()->where('user_id', $inna->getKey())->count());

        $this->actingAs($osoba)->get(route('settings.privacy'))->assertOk()
            ->assertSee('data-sprzeciw-statystyk', false)
            ->assertSee('Licz mnie znowu');
    }

    public function test_po_sprzeciwie_zadne_zdarzenie_tej_osoby_nie_trafia_do_bazy(): void
    {
        $osoba = $this->zeSprzeciwem('nie_licz_mnie');
        $inna = $this->user('licz_mnie');
        $sygnaly = app(ZapiszSygnal::class);

        $sygnaly->handle($osoba, ZapiszSygnal::SEARCH_PERFORMED, ['query_length' => 4, 'has_results' => true]);
        $sygnaly->handle($inna, ZapiszSygnal::SEARCH_PERFORMED, ['query_length' => 4, 'has_results' => true]);

        $this->assertSame(1, ProductSignal::query()->count(), 'Zdarzenie osoby ze sprzeciwem trafiło do bazy (z kontem albo bez).');
        $this->assertSame(1, ProductSignal::query()->where('user_id', $inna->getKey())->count());
    }

    public function test_po_sprzeciwie_wizyta_nie_zapisuje_daty(): void
    {
        $osoba = $this->zeSprzeciwem('nie_licz_mnie');
        $inna = $this->user('licz_mnie');

        $this->travelTo(Carbon::parse('2026-09-30 10:00:00', 'UTC'));
        $this->actingAs($osoba)->get(route('home'))->assertOk();
        $this->actingAs($inna)->get(route('home'))->assertOk();

        $this->assertNull($osoba->refresh()->ostatnio_widziany_at, 'Wizyta osoby ze sprzeciwem zapisała datę.');
        $this->assertNotNull($inna->refresh()->ostatnio_widziany_at, 'Kontrola: wizyta bez sprzeciwu ma zapisywać datę.');
    }

    public function test_po_sprzeciwie_strona_nie_ma_skryptu_statystyki_odwiedzin(): void
    {
        config(['kuking.analytics.cloudflare.token' => self::TOKEN]);

        $osoba = $this->zeSprzeciwem('nie_licz_mnie');
        $inna = $this->user('licz_mnie');

        $this->actingAs($inna)->get(route('home'))->assertOk()
            ->assertSee('data-cf-beacon', false);

        $this->actingAs($osoba)->get(route('home'))->assertOk()
            ->assertDontSee('data-cf-beacon', false)
            ->assertDontSee(self::TOKEN, false);
    }

    public function test_cofniecie_przywraca_liczenie(): void
    {
        $osoba = $this->zeSprzeciwem('wracam');

        $this->actingAs($osoba)->delete(route('settings.privacy.sprzeciw-statystyk.cofnij'))
            ->assertRedirect(route('settings.privacy').'#statystyki');

        $this->assertFalse($osoba->refresh()->sprzeciwWobecStatystyk());

        app(ZapiszSygnal::class)->handle($osoba, ZapiszSygnal::SEARCH_PERFORMED, ['query_length' => 4, 'has_results' => true]);
        $this->assertSame(1, ProductSignal::query()->where('user_id', $osoba->getKey())->count());
    }

    public function test_gosc_nie_zglosi_sprzeciwu_bez_logowania(): void
    {
        $this->post(route('settings.privacy.sprzeciw-statystyk'))->assertRedirect(route('login'));
    }

    public function test_rollback_odmawia_gdy_ktos_zglosil_sprzeciw(): void
    {
        $this->zeSprzeciwem('nie_licz_mnie');

        try {
            Artisan::call('migrate:rollback', ['--path' => self::MIGRACJA]);
            $this->fail('Rollback przeszedł, choć ktoś zgłosił sprzeciw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 kont(a) zgłosiło sprzeciw', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('users', 'sprzeciw_statystyk_at'));
    }

    /** Kontrola dodatnia: bez sprzeciwów rollback przechodzi i wraca. */
    public function test_rollback_przechodzi_gdy_nikt_nie_zglosil_sprzeciwu(): void
    {
        $this->user('licz_mnie');

        Artisan::call('migrate:rollback', ['--path' => self::MIGRACJA]);
        $this->assertFalse(Schema::hasColumn('users', 'sprzeciw_statystyk_at'));

        Artisan::call('migrate', ['--path' => self::MIGRACJA]);
        $this->assertTrue(Schema::hasColumn('users', 'sprzeciw_statystyk_at'));
    }

    private function zeSprzeciwem(string $nazwa): User
    {
        $osoba = $this->user($nazwa);
        $osoba->forceFill(['sprzeciw_statystyk_at' => now()->subHour()])->save();

        return $osoba->refresh();
    }
}
