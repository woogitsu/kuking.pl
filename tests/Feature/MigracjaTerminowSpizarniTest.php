<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Schemat terminów przy produktach z „Co mam w domu” (#1903, D-333): CHECK-i
 * w bazie odrzucają to, czego PHP nie zdążyłoby sprawdzić (AGENTS.md §6).
 * Każdy CHECK ma tu kontrolę ujemną (zły wiersz odpada) i dodatnią (dobry
 * przechodzi).
 *
 * @bez-kontroli-dodatniej Test mierzy zachowanie bazy na prawdziwych wierszach (odrzucenie i przyjęcie), nie czyta źródła migracji.
 */
class MigracjaTerminowSpizarniTest extends TestCase
{
    use RefreshDatabase;

    public function test_kolumny_istnieja_a_frozen_ma_stala_domyslna_bez_przepisywania(): void
    {
        foreach (['expires_on', 'expiry_kind', 'quantity_note', 'frozen'] as $kolumna) {
            $this->assertTrue(Schema::hasColumn('pantry_items', $kolumna), $kolumna);
        }

        $produkt = $this->user()->pantryItems()->create(['name' => 'mleko']);
        $wiersz = DB::table('pantry_items')->where('id', $produkt->getKey())->first();

        $this->assertNull($wiersz->expires_on);
        $this->assertNull($wiersz->expiry_kind);
        $this->assertNull($wiersz->quantity_note);
        $this->assertFalse((bool) $wiersz->frozen);
    }

    public function test_baza_przyjmuje_poprawny_termin_ilosc_i_mrozone(): void
    {
        $id = $this->produktId();

        DB::table('pantry_items')->where('id', $id)->update([
            'expires_on' => '2026-10-13', 'expiry_kind' => 'use_by', 'quantity_note' => 'pół kostki', 'frozen' => true,
        ]);
        DB::table('pantry_items')->where('id', $id)->update(['expires_on' => '2020-01-01', 'expiry_kind' => 'best_before']);
        DB::table('pantry_items')->where('id', $id)->update(['expires_on' => '2100-12-31']);

        $this->assertSame('2100-12-31', (string) DB::table('pantry_items')->where('id', $id)->value('expires_on'));
    }

    public function test_termin_bez_rodzaju_i_rodzaj_bez_terminu_odpadaja(): void
    {
        $id = $this->produktId();

        $this->assertOdrzucone($id, ['expires_on' => '2026-10-13'], 'pantry_items_expiry_pair_check');
        $this->assertOdrzucone($id, ['expiry_kind' => 'use_by'], 'pantry_items_expiry_pair_check');
    }

    public function test_zly_rodzaj_terminu_odpada(): void
    {
        $this->assertOdrzucone($this->produktId(), ['expires_on' => '2026-10-13', 'expiry_kind' => 'fresh'], 'pantry_items_expiry_kind_check');
    }

    public function test_data_spoza_zakresu_odpada_z_obu_stron(): void
    {
        $id = $this->produktId();

        $this->assertOdrzucone($id, ['expires_on' => '2019-12-31', 'expiry_kind' => 'use_by'], 'pantry_items_expires_on_range_check');
        $this->assertOdrzucone($id, ['expires_on' => '2101-01-01', 'expiry_kind' => 'use_by'], 'pantry_items_expires_on_range_check');
    }

    public function test_pusta_i_za_dluga_ilosc_odpadaja_a_40_znakow_przechodzi(): void
    {
        $id = $this->produktId();

        $this->assertOdrzucone($id, ['quantity_note' => ''], 'pantry_items_quantity_note_check');
        $this->assertOdrzucone($id, ['quantity_note' => '   '], 'pantry_items_quantity_note_check');

        DB::table('pantry_items')->where('id', $id)->update(['quantity_note' => str_repeat('a', 40)]);
        $this->assertSame(40, mb_strlen((string) DB::table('pantry_items')->where('id', $id)->value('quantity_note')));

        // 41 znaków zatrzymuje już sam typ varchar(40).
        $this->expectException(QueryException::class);
        DB::table('pantry_items')->where('id', $id)->update(['quantity_note' => str_repeat('a', 41)]);
    }

    public function test_wszystkie_cztery_check_i_sa_zwalidowane(): void
    {
        $nazwy = array_map(fn (object $w): string => $w->conname, DB::select(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'pantry_items'::regclass AND contype = 'c' AND convalidated ORDER BY conname",
        ));

        foreach (['pantry_items_expires_on_range_check', 'pantry_items_expiry_kind_check', 'pantry_items_expiry_pair_check', 'pantry_items_quantity_note_check'] as $check) {
            $this->assertContains($check, $nazwy);
        }
    }

    public function test_zgoda_na_przypomnienie_jest_domyslnie_wylaczona_a_dziennik_zna_nowy_cel(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'wants_pantry_reminder'));
        $this->assertFalse((bool) $this->user()->fresh()->wants_pantry_reminder);

        $osoba = $this->user('osoba');
        DB::table('dziennik_zgod')->insert([
            'user_id' => $osoba->getKey(), 'cel' => 'przypomnienie_spizarni', 'czynnosc' => 'udzielona',
            'zrodlo' => 'ustawienia', 'wersja_polityki' => '2026-09-30', 'wystapilo_at' => now(),
        ]);
        $this->assertSame(1, DB::table('dziennik_zgod')->where('cel', 'przypomnienie_spizarni')->count());

        $this->expectException(QueryException::class);
        DB::table('dziennik_zgod')->insert([
            'user_id' => $osoba->getKey(), 'cel' => 'cos_innego', 'czynnosc' => 'udzielona',
            'zrodlo' => 'ustawienia', 'wersja_polityki' => '2026-09-30', 'wystapilo_at' => now(),
        ]);
    }

    public function test_sygnaly_produktowe_spizarni_sa_w_slowniku(): void
    {
        foreach (['pantry_expiry_set', 'pantry_priority_viewed', 'pantry_cook_priority_viewed'] as $nazwa) {
            DB::table('product_signals')->insert(['signal_name' => $nazwa, 'properties' => '{}', 'occurred_at' => now()]);
        }

        $this->assertSame(3, DB::table('product_signals')->where('signal_name', 'like', 'pantry_%')->count());
    }

    private function produktId(): string
    {
        return (string) $this->user()->pantryItems()->create(['name' => 'mleko'])->getKey();
    }

    /** @param  array<string, mixed>  $zmiany */
    private function assertOdrzucone(string $id, array $zmiany, string $check): void
    {
        try {
            // SAVEPOINT: nieudany UPDATE nie psuje transakcji testu.
            DB::transaction(fn () => DB::table('pantry_items')->where('id', $id)->update($zmiany));
            $this->fail("Baza przyjęła wiersz łamiący {$check}.");
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0], $e->getMessage());
            $this->assertStringContainsString($check, $e->getMessage());
        }
    }
}
