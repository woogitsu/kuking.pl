<?php

declare(strict_types=1);

namespace Tests\Feature;

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
 * Zmiana regulaminu ogłoszona paskiem w serwisie (#1811, D-306).
 *
 * Decyzja właściciela z 26.09.2026: nowa wersja z datą w konfiguracji
 * (`kuking.zgody.wersja_regulaminu`, wzorem `wersja_polityki`), zalogowani
 * przy wejściu widzą jednorazowy, zamykany pasek „Zmieniliśmy regulamin —
 * co się zmieniło" z odnośnikiem; bez maili.
 */
class ZmianaRegulaminuTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACJA = 'database/migrations/2026_09_26_120000_add_terms_notice_dismissed_version_to_users.php';

    private const MIESIACE = [
        1 => 'stycznia', 2 => 'lutego', 3 => 'marca', 4 => 'kwietnia', 5 => 'maja', 6 => 'czerwca',
        7 => 'lipca', 8 => 'sierpnia', 9 => 'września', 10 => 'października', 11 => 'listopada', 12 => 'grudnia',
    ];

    private function wersjaSlownie(): string
    {
        $data = CarbonImmutable::parse((string) config('kuking.zgody.wersja_regulaminu'));

        return $data->day.' '.self::MIESIACE[$data->month].' '.$data->year;
    }

    /**
     * Data w nagłówku regulaminu i wersja w konfiguracji to ten sam dzień,
     * a sekcja „Co się zmieniło" ma wpis z tą datą. Podbicie jednego bez
     * drugiego pokazałoby pasek o zmianie, której w dokumencie nie ma.
     */
    public function test_naglowek_regulaminu_i_sekcja_zmian_maja_date_wersji_z_konfiguracji(): void
    {
        $tresc = (string) file_get_contents(resource_path('legal/regulamin.md'));
        $data = $this->wersjaSlownie();

        $this->assertMatchesRegularExpression('/opisuje stan serwisu na '.preg_quote($data, '/').' /u', $tresc,
            "Nagłówek regulaminu nie mówi „{$data}”, a tyle jest w kuking.zgody.wersja_regulaminu.");
        $this->assertMatchesRegularExpression('/## Co się zmieniło\s+\*\*'.preg_quote($data, '/').'\.\*\*/u', $tresc,
            'Sekcja „Co się zmieniło” nie zaczyna się od wpisu z datą bieżącej wersji.');

        $this->get(route('terms'))->assertOk()->assertSee('<h2 id="co-sie-zmienilo">Co się zmieniło</h2>', false);
    }

    public function test_konto_sprzed_wersji_widzi_pasek_do_zamkniecia_i_potem_juz_nie(): void
    {
        Mail::fake();
        $osoba = $this->kontoSprzedWersji();

        $strona = $this->actingAs($osoba)->get(route('home'))->assertOk();
        $strona->assertSee('data-pasek-zmiany-regulaminu', false)
            ->assertSee('Zmieniliśmy regulamin.')
            ->assertSee(route('terms').'#co-sie-zmienilo', false)
            ->assertSee(route('terms.notice.dismiss'), false);
        // Na innym ekranie też — „przy wejściu" znaczy gdziekolwiek.
        $this->actingAs($osoba)->get(route('discover'))->assertSee('data-pasek-zmiany-regulaminu', false);

        $this->actingAs($osoba)->from(route('discover'))->post(route('terms.notice.dismiss'))
            ->assertRedirect(route('discover'));

        $this->assertSame((string) config('kuking.zgody.wersja_regulaminu'), (string) DB::table('users')->where('id', $osoba->getKey())->value('terms_notice_dismissed_version'));
        $this->actingAs($osoba->fresh())->get(route('home'))->assertDontSee('data-pasek-zmiany-regulaminu', false);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();

        // Następna wersja pokazuje pasek znowu.
        config(['kuking.zgody.wersja_regulaminu' => CarbonImmutable::parse((string) config('kuking.zgody.wersja_regulaminu'))->addMonth()->toDateString()]);
        $this->travel(2)->months();
        $this->actingAs($osoba->fresh())->get(route('home'))->assertSee('data-pasek-zmiany-regulaminu', false);
    }

    public function test_konto_zalozone_w_dniu_wersji_i_gosc_paska_nie_widza(): void
    {
        $nowe = $this->user('nowe_konto');
        $nowe->forceFill(['created_at' => CarbonImmutable::parse((string) config('kuking.zgody.wersja_regulaminu'), Czas::strefa())->startOfDay()])->save();

        $this->actingAs($nowe)->get(route('home'))->assertOk()->assertDontSee('data-pasek-zmiany-regulaminu', false);

        auth()->logout();
        $this->get(route('discover'))->assertOk()->assertDontSee('data-pasek-zmiany-regulaminu', false);
    }

    public function test_gosc_nie_zamknie_paska_ani_get_niczego_nie_zapisuje(): void
    {
        $this->post(route('terms.notice.dismiss'))->assertRedirect(route('login'));

        $osoba = $this->kontoSprzedWersji();
        $this->actingAs($osoba)->get('/regulamin/zmiana/zamknij')->assertStatus(405);
        $this->assertNull(DB::table('users')->where('id', $osoba->getKey())->value('terms_notice_dismissed_version'));
    }

    public function test_rollback_odmawia_gdy_ktos_zamknal_pasek(): void
    {
        $osoba = $this->kontoSprzedWersji();
        $this->actingAs($osoba)->post(route('terms.notice.dismiss'));

        try {
            Artisan::call('migrate:rollback', ['--path' => self::MIGRACJA]);
            $this->fail('Rollback przeszedł, choć ktoś zamknął pasek.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 kont(a) zamknęło już pasek', $e->getMessage());
            $this->assertStringContainsString('wycofaj sam kod paska', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('users', 'terms_notice_dismissed_version'));
    }

    /** Kontrola dodatnia: bez zamkniętych pasków rollback przechodzi i wraca. */
    public function test_rollback_przechodzi_gdy_nikt_nie_zamknal_paska(): void
    {
        $this->kontoSprzedWersji();

        Artisan::call('migrate:rollback', ['--path' => self::MIGRACJA]);
        $this->assertFalse(Schema::hasColumn('users', 'terms_notice_dismissed_version'));

        Artisan::call('migrate', ['--path' => self::MIGRACJA]);
        $this->assertTrue(Schema::hasColumn('users', 'terms_notice_dismissed_version'));
    }

    private function kontoSprzedWersji(): User
    {
        $osoba = $this->user('stara_osoba');
        $osoba->forceFill(['created_at' => CarbonImmutable::parse((string) config('kuking.zgody.wersja_regulaminu'), Czas::strefa())->subDays(30)])->save();

        return $osoba->fresh();
    }
}
