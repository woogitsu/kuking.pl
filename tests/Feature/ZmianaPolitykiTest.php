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
 * Decyzja właściciela z 29.09.2026 (wieczór): zmiana polityki z pytaniem „Jak
 * mamy do Ciebie pisać?” jest DROBNA — serwis nie ma jeszcze prawdziwych kont,
 * więc obowiązuje od dnia publikacji, bez okresu przejściowego i bez paska.
 * Mechanizm paska zostaje na przyszłe zmiany ISTOTNE; testy niżej sprawdzają
 * oba przypadki: bieżącą konfigurację (drobna) i zmianę istotną ustawioną
 * w teście (`istotnaZmianaPolityki()`).
 */
class ZmianaPolitykiTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACJA = 'database/migrations/2026_09_29_180000_add_policy_notice_dismissed_version_to_users.php';

    /** Hipotetyczna zmiana istotna tej samej wersji — wejście 14 dni po publikacji. */
    private function istotnaZmianaPolityki(): void
    {
        config(['kuking.zgody.zmiana_polityki' => ['istotna' => true, 'poprzednia' => '2026-09-29', 'obowiazuje_od' => null]]);
    }

    public function test_biezaca_zmiana_polityki_jest_drobna_i_obowiazuje_od_publikacji(): void
    {
        $wersja = WersjaDokumentu::polityka();

        $this->assertFalse($wersja->istotna);
        // 2026-09-29 jest już na produkcji (Alfa 0.76) — forma dostaje NOWĄ
        // wersję, a nie przeklasyfikowanie obowiązującej (D-332).
        $this->assertSame('2026-09-30', $wersja->opublikowana);
        $this->assertSame('2026-09-30', $wersja->obowiazujeOd()->toDateString());

        $publikacja = CarbonImmutable::parse('2026-09-30', Czas::strefa());
        $this->assertFalse($wersja->wOkresiePrzejsciowym($publikacja));
        $this->assertSame('2026-09-30', $wersja->obowiazujaca($publikacja));
    }

    /** Kontrola dodatnia mechanizmu: ta sama wersja oznaczona jako istotna ma 14 dni i poprzednią. */
    public function test_ta_sama_wersja_oznaczona_jako_istotna_obowiazuje_po_czternastu_dniach(): void
    {
        $this->istotnaZmianaPolityki();
        $wersja = WersjaDokumentu::polityka();

        $this->assertTrue($wersja->istotna);
        $this->assertSame('2026-10-14', $wersja->obowiazujeOd()->toDateString());
        $this->assertSame('2026-09-29', $wersja->obowiazujaca(CarbonImmutable::parse('2026-10-13 23:59', Czas::strefa())));
        $this->assertSame('2026-09-30', $wersja->obowiazujaca(CarbonImmutable::parse('2026-10-14 00:00', Czas::strefa())));
    }

    /**
     * Wpis „Co się zmieniło” dla bieżącej wersji mówi o wejściu w życie to
     * samo co konfiguracja: drobna — „od dnia publikacji”, bez poprzedniej;
     * istotna — dzień wejścia w życie i że do niego obowiązuje poprzednia.
     */
    public function test_polityka_ma_sekcje_zmian_z_data_wersji_i_zgodnym_terminem(): void
    {
        $tresc = (string) file_get_contents(resource_path('legal/polityka-prywatnosci.md'));
        $wersja = WersjaDokumentu::polityka();
        $dzien = Czas::data(CarbonImmutable::parse($wersja->opublikowana, Czas::strefa()));

        $this->assertMatchesRegularExpression('/## Co się zmieniło\s+\*\*'.preg_quote($dzien, '/').'\.\*\*/u', $tresc);
        $this->assertSame(1, preg_match('/^\*\*'.preg_quote($dzien, '/').'\.\*\*(.*)$/mu', $tresc, $wpis), 'Brak wpisu „Co się zmieniło” dla bieżącej wersji.');
        $akapit = $wpis[1];

        $this->assertStringContainsString('Jak mamy do Ciebie pisać?', $akapit);

        if ($wersja->istotna) {
            $this->assertStringContainsString('nowa wersja obowiązuje od '.Czas::data($wersja->obowiazujeOd()), $akapit);
            $this->assertStringContainsString('obowiązuje poprzednia wersja', $akapit);
        } else {
            $this->assertStringContainsString('obowiązuje od dnia publikacji', $akapit);
            $this->assertStringNotContainsString('obowiązuje poprzednia', $akapit);
            $this->assertStringNotContainsString('zmiana istotna', mb_strtolower($akapit));
        }

        $this->get(route('privacy'))->assertOk()->assertSee('<h2 id="co-sie-zmienilo">Co się zmieniło</h2>', false);
    }

    /** Decyzja 29.09 wieczór: drobna zmiana nie zaczepia nikogo paskiem, nawet konta sprzed wersji. */
    public function test_przy_drobnej_zmianie_nikt_nie_widzi_paska(): void
    {
        $osoba = $this->kontoSprzedWersji();

        foreach (['2026-09-30 00:00', '2026-10-01 12:00', '2026-10-20 12:00'] as $chwila) {
            $this->travelTo(CarbonImmutable::parse($chwila, Czas::strefa()));
            $this->actingAs($osoba)->get(route('home'))->assertOk()
                ->assertDontSee('data-pasek-zmiany-polityki', false)
                ->assertDontSee('Zmieniliśmy politykę prywatności.');
        }
    }

    public function test_konto_sprzed_wersji_widzi_pasek_z_terminem_do_zamkniecia_i_potem_juz_nie(): void
    {
        $this->istotnaZmianaPolityki();
        Mail::fake();
        $osoba = $this->kontoSprzedWersji();

        $this->travelTo(CarbonImmutable::parse('2026-10-01', Czas::strefa()));
        $this->actingAs($osoba)->get(route('home'))->assertOk()
            ->assertSee('data-pasek-zmiany-polityki', false)
            ->assertSee('Zmieniliśmy politykę prywatności.')
            ->assertSee('Nowa wersja obowiązuje od 14 października 2026. Do tego dnia obowiązuje poprzednia.')
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

    /**
     * Regresja paczki H (przy zmianie istotnej): wersja polityki z datą publikacji w przyszłości
     * (kod wdrożony dzień wcześniej) dawała pasek „Zmieniliśmy” KAŻDEMU
     * zalogowanemu, także kontu założonemu przed chwilą — pasek liczy się
     * od dnia publikacji, nie wcześniej.
     */
    public function test_przed_dniem_publikacji_wersji_nikt_nie_widzi_paska(): void
    {
        $this->istotnaZmianaPolityki();
        $osoba = $this->kontoSprzedWersji();
        $publikacja = CarbonImmutable::parse((string) config('kuking.zgody.wersja_polityki'), Czas::strefa())->startOfDay();

        $this->travelTo($publikacja->subMinute());
        $this->actingAs($osoba)->get(route('home'))->assertOk()->assertDontSee('data-pasek-zmiany-polityki', false);
        $swieze = $this->user('konto_z_wczoraj');
        $this->actingAs($swieze)->get(route('home'))->assertOk()->assertDontSee('data-pasek-zmiany-polityki', false);

        $this->travelTo($publikacja);
        $this->actingAs($osoba)->get(route('home'))->assertOk()->assertSee('data-pasek-zmiany-polityki', false);
        $this->actingAs($swieze)->get(route('home'))->assertOk()->assertSee('data-pasek-zmiany-polityki', false);
    }

    public function test_po_dacie_wejscia_pasek_nie_mowi_juz_o_poprzedniej(): void
    {
        $this->istotnaZmianaPolityki();
        $osoba = $this->kontoSprzedWersji();
        $this->travelTo(CarbonImmutable::parse('2026-10-14', Czas::strefa()));

        $this->actingAs($osoba)->get(route('home'))->assertOk()
            ->assertSee('data-pasek-zmiany-polityki', false)
            ->assertSee('Nowa wersja obowiązuje od 14 października 2026.')
            ->assertDontSee('Do tego dnia obowiązuje poprzednia.');
    }

    public function test_konto_zalozone_w_dniu_wersji_i_gosc_paska_nie_widza(): void
    {
        $this->istotnaZmianaPolityki();
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
