<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\PantryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Terminy, ilości i „mrożone” (#1903) są w paczce danych i znikają razem
 * z kontem. Wymazanie gasi też zgodę na sobotnie przypomnienie.
 */
class TerminySpizarniEksportIWymazanieTest extends TestCase
{
    use RefreshDatabase;

    public function test_paczka_danych_ma_termin_rodzaj_ilosc_i_mrozone(): void
    {
        $user = $this->user();
        $mleko = $user->pantryItems()->create(['name' => 'mleko', 'quantity_note' => '1 litr']);
        $kurczak = $user->pantryItems()->create(['name' => 'kurczak']);
        $mąka = $user->pantryItems()->create(['name' => 'mąka']);
        DB::table('pantry_items')->where('id', $mleko->getKey())->update(['expires_on' => '2026-10-13', 'expiry_kind' => 'use_by']);
        DB::table('pantry_items')->where('id', $kurczak->getKey())->update(['expires_on' => '2026-09-01', 'expiry_kind' => 'best_before', 'frozen' => true]);

        $dane = app(CollectUserExportData::class)->handle($user, new ExportPhotoPlan($user), Carbon::now());
        $wiersze = collect($dane['co_mam_w_domu'])->keyBy('produkt');

        $this->assertSame('2026-10-13', (string) $wiersze['mleko']['termin']);
        $this->assertSame('use_by', $wiersze['mleko']['rodzaj_terminu']);
        $this->assertSame('1 litr', $wiersze['mleko']['ilosc']);
        $this->assertFalse($wiersze['mleko']['mrozone']);
        $this->assertSame('best_before', $wiersze['kurczak']['rodzaj_terminu']);
        $this->assertTrue($wiersze['kurczak']['mrozone']);
        $this->assertNull($wiersze['mąka']['termin']);
        $this->assertNull($wiersze['mąka']['ilosc']);
        $this->assertArrayNotHasKey('klucz', $wiersze['mleko']);
        $this->assertArrayNotHasKey('rdzenie', $wiersze['mleko']);
        $this->assertInstanceOf(PantryItem::class, $mąka);
    }

    public function test_paczka_danych_mowi_o_zgodzie_na_sobotnie_przypomnienie(): void
    {
        $user = $this->user();

        $this->assertFalse(app(CollectUserExportData::class)->handle($user, new ExportPhotoPlan($user), Carbon::now())['konto']['chce_sobotniego_przypomnienia_o_produktach']);

        DB::table('users')->where('id', $user->getKey())->update(['wants_pantry_reminder' => true]);
        $this->assertTrue(app(CollectUserExportData::class)->handle($user->fresh(), new ExportPhotoPlan($user), Carbon::now())['konto']['chce_sobotniego_przypomnienia_o_produktach']);
    }

    public function test_wymazanie_konta_kasuje_produkty_z_terminami_i_gasi_zgode_a_cudze_zostaja(): void
    {
        $user = $this->user();
        $inny = $this->user('inny');
        $produkt = $user->pantryItems()->create(['name' => 'mleko', 'quantity_note' => '1 litr']);
        DB::table('pantry_items')->where('id', $produkt->getKey())->update(['expires_on' => '2026-10-13', 'expiry_kind' => 'use_by', 'frozen' => true]);
        $obcy = $inny->pantryItems()->create(['name' => 'ser']);
        DB::table('pantry_items')->where('id', $obcy->getKey())->update(['expires_on' => '2026-10-13', 'expiry_kind' => 'use_by']);
        DB::table('users')->whereIn('id', [$user->getKey(), $inny->getKey()])->update(['wants_pantry_reminder' => true]);

        $user->markForDeletion();
        app(EraseAccountData::class)->handle($user->fresh());

        $this->assertSame(0, DB::table('pantry_items')->where('user_id', $user->getKey())->count());
        $this->assertFalse((bool) DB::table('users')->where('id', $user->getKey())->value('wants_pantry_reminder'));
        $this->assertSame(1, DB::table('pantry_items')->where('user_id', $inny->getKey())->whereNotNull('expires_on')->count());
        $this->assertTrue((bool) DB::table('users')->where('id', $inny->getKey())->value('wants_pantry_reminder'));
    }
}
