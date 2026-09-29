<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Zgody\WersjaDokumentu;
use App\Models\User;
use App\Support\Czas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Pasek o zmianie polityki prywatności (D-327, D-332).
 *
 * Decyzja właściciela z 29.09.2026: zmiana polityki z pytaniem „Jak mamy do
 * Ciebie pisać?” jest ISTOTNA — nowa wersja obowiązuje 14 dni po publikacji,
 * a zalogowani widzą do tego czasu zamykany pasek (bez maili).
 */
class ZmianaPolitykiTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACJA = 'database/migrations/2026_09_29_180000_add_policy_notice_dismissed_version_to_users.php';

    public function test_biezaca_zmiana_polityki_jest_istotna_i_obowiazuje_po_czternastu_dniach(): void
    {
        $wersja = WersjaDokumentu::polityka();

        $this->assertTrue($wersja->istotna);
        $this->assertSame('2026-09-25', $wersja->poprzednia);
        $this->assertSame('2026-10-13', $wersja->obowiazujeOd()->toDateString());
    }

    /** Nagłówek, konfiguracja i sekcja „Co się zmieniło” mówią o tym samym dniu i terminie. */
    public function test_polityka_ma_sekcje_zmian_z_data_wersji_i_terminem(): void
    {
        $tresc = (string) file_get_contents(resource_path('legal/polityka-prywatnosci.md'));

        $this->assertMatchesRegularExpression('/## Co się zmieniło\s+\*\*29 września 2026\.\*\*/u', $tresc);
        $this->assertStringContainsString('nowa wersja obowiązuje od 13 października 2026', $tresc);
        $this->assertStringContainsString('obowiązuje poprzednia wersja z 25 września 2026', $tresc);
        $this->assertStringContainsString('Jak mamy do Ciebie pisać?', $tresc);

        $this->get(route('privacy'))->assertOk()->assertSee('<h2 id="co-sie-zmienilo">Co się zmieniło</h2>', false);
    }

    public function test_konto_sprzed_wersji_widzi_pasek_z_terminem_do_zamkniecia_i_potem_juz_nie(): void
    {
        Mail::fake();
        $osoba = $this->kontoSprzedWersji();

        $this->travelTo(CarbonImmutable::parse('2026-09-30', Czas::strefa()));
        $this->actingAs($osoba)->get(route('home'))->assertOk()
            ->assertSee('data-pasek-zmiany-polityki', false)
            ->assertSee('Zmieniliśmy politykę prywatności.')
            ->assertSee('Nowa wersja obowiązuje od 13 października 2026. Do tego dnia obowiązuje poprzednia.')
            ->assertSee(route('privacy').'#co-sie-zmienilo', false)
            ->assertSee(route('privacy.notice.dismiss'), false);
        $this->actingAs($osoba)->get(route('discover'))->assertSee('data-pasek-zmiany-polityki', false);

        $this->actingAs($osoba)->from(route('discover'))->post(route('privacy.notice.dismiss'))
            ->assertRedirect(route('discover'));

        $this->assertSame(
            (string) config('kuking.zgody.wersja_polityki'),
            (string) DB::table('users')->where('id', $osoba->getKey())->value('policy_notice_dismissed_version'),
        );
        $this->actingAs($osoba->fresh())->get(route('home'))->assertDontSee('data-pasek-zmiany-polityki', false);

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_po_dacie_wejscia_pasek_nie_mowi_juz_o_poprzedniej(): void
    {
        $osoba = $this->kontoSprzedWersji();
        $this->travelTo(CarbonImmutable::parse('2026-10-13', Czas::strefa()));

        $this->actingAs($osoba)->get(route('home'))->assertOk()
            ->assertSee('data-pasek-zmiany-polityki', false)
            ->assertSee('Nowa wersja obowiązuje od 13 października 2026.')
            ->assertDontSee('Do tego dnia obowiązuje poprzednia.');
    }

    public function test_konto_zalozone_w_dniu_wersji_i_gosc_paska_nie_widza(): void
    {
        $nowe = $this->user('nowe_konto');
        $nowe->forceFill(['created_at' => CarbonImmutable::parse((string) config('kuking.zgody.wersja_polityki'), Czas::strefa())->startOfDay()])->save();

        $this->actingAs($nowe)->get(route('home'))->assertOk()->assertDontSee('data-pasek-zmiany-polityki', false);

        auth()->logout();
        $this->get(route('discover'))->assertOk()->assertDontSee('data-pasek-zmiany-polityki', false);
    }

    public function test_gosc_nie_zamknie_paska_ani_get_niczego_nie_zapisuje(): void
    {
        $this->post(route('privacy.notice.dismiss'))->assertRedirect(route('login'));

        $osoba = $this->kontoSprzedWersji();
        $this->actingAs($osoba)->get('/prywatnosc/zmiana/zamknij')->assertStatus(405);
        $this->assertNull(DB::table('users')->where('id', $osoba->getKey())->value('policy_notice_dismissed_version'));
    }

    public function test_rollback_odmawia_gdy_ktos_zamknal_pasek(): void
    {
        $osoba = $this->kontoSprzedWersji();
        $this->actingAs($osoba)->post(route('privacy.notice.dismiss'));

        try {
            Artisan::call('migrate:rollback', ['--path' => self::MIGRACJA]);
            $this->fail('Rollback przeszedł, choć ktoś zamknął pasek.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 kont(a) zamknęło już pasek', $e->getMessage());
            $this->assertStringContainsString('wycofaj sam kod paska', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('users', 'policy_notice_dismissed_version'));
    }

    /** Kontrola dodatnia: bez zamkniętych pasków rollback przechodzi i wraca. */
    public function test_rollback_przechodzi_gdy_nikt_nie_zamknal_paska(): void
    {
        $this->kontoSprzedWersji();

        Artisan::call('migrate:rollback', ['--path' => self::MIGRACJA]);
        $this->assertFalse(Schema::hasColumn('users', 'policy_notice_dismissed_version'));

        Artisan::call('migrate', ['--path' => self::MIGRACJA]);
        $this->assertTrue(Schema::hasColumn('users', 'policy_notice_dismissed_version'));
    }

    private function kontoSprzedWersji(): User
    {
        $osoba = $this->user('stara_osoba');
        $osoba->forceFill(['created_at' => CarbonImmutable::parse((string) config('kuking.zgody.wersja_polityki'), Czas::strefa())->subDays(30)])->save();

        return $osoba->fresh();
    }
}
