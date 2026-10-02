<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pantry\CoMamWDomu;
use App\Domain\Pantry\CoUgotuje;
use App\Domain\Pantry\DrugieOpakowanieProduktu;
use App\Domain\Pantry\Opakowanie;
use App\Domain\Pantry\PriorytetZuzycia;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Mail\PrzypomnienieOProduktach;
use App\Models\PantryItem;
use App\Models\PantrySecondPackage;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * Dwa opakowania tego samego produktu z osobnymi terminami (#2568, V2).
 *
 * „Dziś” to sobota 10 października 2026, południe w Warszawie. Każdy test
 * mierzy zachowanie na prawdziwej bazie (HTTP, akcje domenowe, SQL doboru
 * przepisów, komenda i list), a nie treść źródeł.
 *
 * @bez-kontroli-dodatniej Test mierzy zachowanie aplikacji i bazy na prawdziwych wierszach (migrację ładuje i wykonuje down()), nie asertuje na treści źródeł.
 */
class DwaOpakowaniaProduktuTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACJA = 'database/migrations/2026_10_03_200000_create_pantry_second_packages_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();
        config([
            'kuking.pantry.przypomnienie.wlaczone' => true,
            'kuking.pantry.przypomnienie.dzienny_sufit' => 20,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-10 09:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        putenv('KUKING_ROLLBACK_KASUJE_DRUGIE_OPAKOWANIA');

        parent::tearDown();
    }

    /**
     * Produkt z pierwszym opakowaniem (kolumny `pantry_items`) i — gdy podano
     * `$drugie` — drugim (wiersz `pantry_second_packages`).
     *
     * @param  array{0: ?string, 1: ?string}  $pierwsze  [termin, rodzaj]
     * @param  array{0: ?string, 1: ?string}|null  $drugie  [termin, rodzaj]
     */
    private function produkt(User $osoba, string $nazwa, array $pierwsze, ?array $drugie = null, bool $mrozonePierwsze = false, bool $mrozoneDrugie = false, ?string $iloscPierwsze = null, ?string $iloscDrugie = null): PantryItem
    {
        $produkt = $osoba->pantryItems()->create(['name' => $nazwa]);
        DB::table('pantry_items')->where('id', $produkt->getKey())->update([
            'expires_on' => $pierwsze[0],
            'expiry_kind' => $pierwsze[0] === null ? null : $pierwsze[1],
            'frozen' => $mrozonePierwsze,
            'quantity_note' => $iloscPierwsze,
        ]);

        if ($drugie !== null) {
            DB::table('pantry_second_packages')->insert([
                'pantry_item_id' => $produkt->getKey(),
                'expires_on' => $drugie[0],
                'expiry_kind' => $drugie[0] === null ? null : $drugie[1],
                'frozen' => $mrozoneDrugie,
                'quantity_note' => $iloscDrugie,
            ]);
        }

        return $produkt;
    }

    private function przepis(string $tytul, string ...$skladniki): Recipe
    {
        $przepis = Recipe::factory()->create(['title' => $tytul]);

        foreach ($skladniki as $pozycja => $tekst) {
            $przepis->ingredients()->create(['position' => $pozycja, 'ingredient_text' => $tekst]);
        }

        return $przepis;
    }

    // ------------------------------------------------------------------
    // Dodanie, edycja i niezależność opakowań
    // ------------------------------------------------------------------

    public function test_drugie_opakowanie_ma_wlasny_termin_rodzaj_ilosc_i_mrozenie_po_ponownym_otwarciu(): void
    {
        $ja = $this->user();
        $mleko = $this->produkt($ja, 'mleko', ['2026-10-14', 'best_before'], iloscPierwsze: '1 litr');

        $this->actingAs($ja)->get(route('pantry.edit', ['pantryItem' => $mleko, 'opakowanie' => 'drugie']))
            ->assertOk()
            ->assertSee('Dodajesz drugie opakowanie')
            ->assertSee('Dodaj drugie opakowanie')
            ->assertSee('name="opakowanie" value="drugie"', false);

        $this->actingAs($ja)->put(route('pantry.update', $mleko), [
            '_formularz' => 'termin', 'opakowanie' => 'drugie',
            'rodzaj' => 'use_by', 'termin_dzien' => '20', 'termin_miesiac' => '10', 'termin_rok' => '2026',
            'ilosc' => '2 litry', 'mrozone' => '1',
        ])->assertRedirect(route('pantry.index'));

        $pierwsze = DB::table('pantry_items')->where('id', $mleko->getKey())->first();
        $this->assertSame('2026-10-14', (string) $pierwsze->expires_on, 'Pierwsze opakowanie zostaje bez zmian.');
        $this->assertSame('best_before', $pierwsze->expiry_kind);
        $this->assertSame('1 litr', $pierwsze->quantity_note);
        $this->assertFalse((bool) $pierwsze->frozen);

        $drugie = DB::table('pantry_second_packages')->where('pantry_item_id', $mleko->getKey())->first();
        $this->assertSame('2026-10-20', (string) $drugie->expires_on);
        $this->assertSame('use_by', $drugie->expiry_kind);
        $this->assertSame('2 litry', $drugie->quantity_note);
        $this->assertTrue((bool) $drugie->frozen);

        // Ponowne otwarcie: każde opakowanie pokazuje własne dane i mówi, które edytujesz.
        $this->actingAs($ja)->get(route('pantry.edit', ['pantryItem' => $mleko, 'opakowanie' => 'drugie']))
            ->assertOk()
            ->assertSee('Edytujesz: drugie opakowanie')
            ->assertSee('value="2 litry"', false)
            ->assertSee('name="opakowanie_id" value="'.$drugie->id.'"', false);
        $this->actingAs($ja)->get(route('pantry.edit', $mleko))
            ->assertOk()
            ->assertSee('Edytujesz: pierwsze opakowanie')
            ->assertSee('value="1 litr"', false);
    }

    public function test_edycja_jednego_opakowania_nie_nadpisuje_drugiego(): void
    {
        $ja = $this->user();
        $mleko = $this->produkt($ja, 'mleko', ['2026-10-14', 'best_before'], ['2026-10-20', 'use_by'], iloscPierwsze: '1 litr', iloscDrugie: '2 litry');

        $this->actingAs($ja)->put(route('pantry.update', $mleko), [
            '_formularz' => 'termin', 'opakowanie' => 'drugie',
            'opakowanie_id' => DB::table('pantry_second_packages')->value('id'),
            'rodzaj' => 'best_before', 'termin_dzien' => '25', 'termin_miesiac' => '10', 'termin_rok' => '2026', 'ilosc' => 'pół litra',
        ])->assertRedirect(route('pantry.index'));

        $pierwsze = DB::table('pantry_items')->where('id', $mleko->getKey())->first();
        $this->assertSame(['2026-10-14', 'best_before', '1 litr'], [(string) $pierwsze->expires_on, $pierwsze->expiry_kind, $pierwsze->quantity_note]);
        $drugie = DB::table('pantry_second_packages')->first();
        $this->assertSame(['2026-10-25', 'best_before', 'pół litra'], [(string) $drugie->expires_on, $drugie->expiry_kind, $drugie->quantity_note]);

        // I w drugą stronę: edycja pierwszego (z odciskiem z otwarcia strony) nie rusza drugiego.
        $odcisk = Opakowanie::zProduktu($mleko->fresh(['secondPackage']))[0]->odcisk();
        $this->actingAs($ja)->put(route('pantry.update', $mleko), [
            '_formularz' => 'termin', 'opakowanie' => 'pierwsze', 'odcisk' => $odcisk,
            'rodzaj' => 'use_by', 'termin_dzien' => '12', 'termin_miesiac' => '10', 'termin_rok' => '2026', 'ilosc' => 'karton',
        ])->assertRedirect(route('pantry.index'));

        $this->assertSame('2026-10-12', (string) DB::table('pantry_items')->value('expires_on'));
        $this->assertSame(['2026-10-25', 'best_before', 'pół litra'], [(string) DB::table('pantry_second_packages')->value('expires_on'), DB::table('pantry_second_packages')->value('expiry_kind'), DB::table('pantry_second_packages')->value('quantity_note')]);
    }

    public function test_zwykle_dodanie_tej_samej_nazwy_nie_tworzy_drugiego_opakowania(): void
    {
        $ja = $this->user();

        $this->actingAs($ja)->post(route('pantry.store'), ['nazwa' => 'jajka'])->assertRedirect(route('pantry.index'));
        $this->actingAs($ja)->post(route('pantry.store'), ['nazwa' => 'jajka'])->assertRedirect(route('pantry.index'));
        $this->actingAs($ja)->post(route('pantry.store'), ['nazwa' => 'Jajko'])->assertRedirect(route('pantry.index'));

        $this->assertSame(1, $ja->pantryItems()->count());
        $this->assertSame(0, DB::table('pantry_second_packages')->count(), 'Drugie opakowanie powstaje tylko z jawnej akcji.');
    }

    public function test_ponowienie_jawnego_dodania_jest_idempotentne_a_inna_tresc_nie_nadpisuje(): void
    {
        $ja = $this->user();
        $mleko = $this->produkt($ja, 'mleko', ['2026-10-14', 'best_before']);
        $dane = ['rodzaj' => 'use_by', 'termin_dzien' => '20', 'termin_miesiac' => '10', 'termin_rok' => '2026', 'ilosc' => '2 litry'];
        $akcja = app(DrugieOpakowanieProduktu::class);

        $this->assertTrue($akcja->zapisz($mleko, $dane));
        // Podwójne kliknięcie albo ponowne wysłanie tego samego formularza.
        $this->assertTrue($akcja->zapisz($mleko, $dane));
        $this->assertSame(1, DB::table('pantry_second_packages')->count());

        // Formularz „Dodaj” otwarty w drugim oknie z inną treścią: odmowa, nic nie nadpisane.
        try {
            $akcja->zapisz($mleko, [...$dane, 'ilosc' => '5 litrów']);
            $this->fail('Formularz „Dodaj” nadpisał istniejące drugie opakowanie.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Drugie opakowanie tego produktu jest już zapisane', $e->errors()['opakowanie'][0]);
        }
        $this->assertSame('2 litry', DB::table('pantry_second_packages')->value('quantity_note'));
    }

    public function test_trzeciego_opakowania_nie_da_sie_zapisac_nawet_prosto_do_bazy(): void
    {
        $ja = $this->user();
        $mleko = $this->produkt($ja, 'mleko', [null, null], [null, null]);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('pantry_second_packages')->insert(['pantry_item_id' => $mleko->getKey()]);
    }

    public function test_baza_odrzuca_zly_termin_rodzaj_i_ilosc_drugiego_opakowania(): void
    {
        $ja = $this->user();
        $id = $this->produkt($ja, 'mleko', [null, null])->getKey();
        $zle = [
            'pantry_second_packages_expiry_pair_check' => ['expires_on' => '2026-10-13'],
            'pantry_second_packages_expiry_kind_check' => ['expires_on' => '2026-10-13', 'expiry_kind' => 'fresh'],
            'pantry_second_packages_expires_on_range_check' => ['expires_on' => '2101-01-01', 'expiry_kind' => 'use_by'],
            'pantry_second_packages_quantity_note_check' => ['quantity_note' => '   '],
        ];

        foreach ($zle as $ograniczenie => $dane) {
            try {
                DB::transaction(fn () => DB::table('pantry_second_packages')->insert(['pantry_item_id' => $id, ...$dane]));
                $this->fail("Baza przyjęła wiersz łamiący {$ograniczenie}.");
            } catch (QueryException $e) {
                $this->assertStringContainsString($ograniczenie, $e->getMessage());
            }
        }

        DB::table('pantry_second_packages')->insert(['pantry_item_id' => $id, 'expires_on' => '2026-10-13', 'expiry_kind' => 'use_by', 'quantity_note' => str_repeat('a', 40)]);
        $this->assertSame(1, DB::table('pantry_second_packages')->count());
    }

    public function test_blad_walidacji_drugiego_opakowania_wraca_po_polsku_a_dane_nie_znikaja(): void
    {
        $ja = $this->user();
        $mleko = $this->produkt($ja, 'mleko', [null, null]);

        $dane = [
            '_formularz' => 'termin', 'opakowanie' => 'drugie',
            'rodzaj' => 'use_by', 'termin_dzien' => '31', 'termin_miesiac' => '2', 'termin_rok' => '2026', 'ilosc' => '2 litry',
        ];

        $this->actingAs($ja)->from(route('pantry.edit', $mleko))->put(route('pantry.update', $mleko), $dane)
            ->assertRedirect(route('pantry.edit', ['pantryItem' => $mleko, 'opakowanie' => 'drugie']))
            ->assertSessionHasErrors('termin_dzien');
        $this->assertSame(0, DB::table('pantry_second_packages')->count());

        $this->actingAs($ja)->followingRedirects()->from(route('pantry.edit', $mleko))->put(route('pantry.update', $mleko), $dane)
            ->assertOk()
            ->assertSee('Sprawdź formularz')
            ->assertSee('W tym miesiącu nie ma takiego dnia')
            ->assertSee('value="2 litry"', false)
            ->assertSee('Dodajesz drugie opakowanie')
            ->assertSee('novalidate', false);
    }

    // ------------------------------------------------------------------
    // Usuwanie
    // ------------------------------------------------------------------

    public function test_usuniecie_drugiego_opakowania_zostawia_pierwsze(): void
    {
        $ja = $this->user();
        $mleko = $this->produkt($ja, 'mleko', ['2026-10-14', 'best_before'], ['2026-10-20', 'use_by'], iloscPierwsze: '1 litr', iloscDrugie: '2 litry');
        $idDrugiego = (string) DB::table('pantry_second_packages')->value('id');

        $this->actingAs($ja)->delete(route('pantry.destroyOpakowanie', $mleko), ['opakowanie' => $idDrugiego])
            ->assertRedirect(route('pantry.index'));

        $this->assertSame(0, DB::table('pantry_second_packages')->count());
        $pierwsze = DB::table('pantry_items')->where('id', $mleko->getKey())->first();
        $this->assertSame(['2026-10-14', 'best_before', '1 litr'], [(string) $pierwsze->expires_on, $pierwsze->expiry_kind, $pierwsze->quantity_note]);
    }

    public function test_usuniecie_pierwszego_opakowania_zostawia_drugie_z_jego_danymi(): void
    {
        $ja = $this->user();
        $mleko = $this->produkt($ja, 'mleko', ['2026-10-14', 'best_before'], ['2026-10-20', 'use_by'], mrozoneDrugie: true, iloscPierwsze: '1 litr', iloscDrugie: '2 litry');
        $odcisk = Opakowanie::zProduktu($mleko->fresh(['secondPackage']))[0]->odcisk();

        $this->actingAs($ja)->delete(route('pantry.destroyOpakowanie', $mleko), ['opakowanie' => 'pierwsze', 'odcisk' => $odcisk])
            ->assertRedirect(route('pantry.index'));

        $this->assertSame(0, DB::table('pantry_second_packages')->count());
        $zostalo = DB::table('pantry_items')->where('id', $mleko->getKey())->first();
        $this->assertSame(['2026-10-20', 'use_by', '2 litry', true], [(string) $zostalo->expires_on, $zostalo->expiry_kind, $zostalo->quantity_note, (bool) $zostalo->frozen], 'Drugie opakowanie nie ginie ani nie scala się z domyślną wartością.');
        $this->assertSame(1, $ja->pantryItems()->count());
    }

    public function test_stary_formularz_usuniecia_pierwszego_nie_usunie_opakowania_ktore_awansowalo(): void
    {
        $ja = $this->user();
        $mleko = $this->produkt($ja, 'mleko', ['2026-10-14', 'best_before'], ['2026-10-20', 'use_by'], iloscPierwsze: '1 litr', iloscDrugie: '2 litry');
        $odciskA = Opakowanie::zProduktu($mleko->fresh(['secondPackage']))[0]->odcisk();
        $akcja = app(DrugieOpakowanieProduktu::class);

        // Okno 1 usuwa pierwsze; drugie awansuje na jego miejsce.
        $this->assertSame(DrugieOpakowanieProduktu::USUNIETO, $akcja->usun($mleko, 'pierwsze', $odciskA));
        // Okno 2 ma stary formularz „usuń pierwsze” (z odciskiem opakowania A) — i tej samej treści już nie ma.
        $this->assertSame(DrugieOpakowanieProduktu::JEDYNE, $akcja->usun($mleko, 'pierwsze', $odciskA), 'Jedyne opakowanie wymaga usunięcia całego produktu.');
        $this->assertSame(1, $ja->pantryItems()->count());

        // To samo z dwoma opakowaniami: odcisk z innej treści odmawia.
        DB::table('pantry_second_packages')->insert(['pantry_item_id' => $mleko->getKey(), 'quantity_note' => 'trzecie']);
        $this->assertSame(DrugieOpakowanieProduktu::ZMIENILO_SIE, $akcja->usun($mleko, 'pierwsze', $odciskA));
        $this->assertSame(1, DB::table('pantry_second_packages')->count());
        $this->assertSame('2 litry', DB::table('pantry_items')->value('quantity_note'));
    }

    public function test_usuniecie_nieistniejacego_opakowania_niczego_nie_rusza(): void
    {
        $ja = $this->user();
        $mleko = $this->produkt($ja, 'mleko', ['2026-10-14', 'best_before'], ['2026-10-20', 'use_by']);

        foreach (['00000000-0000-4000-8000-000000000000', 'nie-uuid', ''] as $cel) {
            $this->actingAs($ja)->delete(route('pantry.destroyOpakowanie', $mleko), ['opakowanie' => $cel])
                ->assertRedirect(route('pantry.index'));
        }

        $this->assertSame(1, DB::table('pantry_second_packages')->count());
        $this->assertSame(1, $ja->pantryItems()->count());
    }

    public function test_usuniecie_calego_produktu_kasuje_oba_opakowania_a_karta_nazywa_zakres(): void
    {
        $ja = $this->user();
        $mleko = $this->produkt($ja, 'mleko', ['2026-10-14', 'best_before'], ['2026-10-20', 'use_by']);

        $this->actingAs($ja)->get(route('pantry.index'))->assertOk()
            ->assertSee('Pierwsze opakowanie')
            ->assertSee('Drugie opakowanie')
            ->assertSee('Tak, usuń tylko to opakowanie')
            ->assertSee('Usuń cały produkt (oba opakowania)');

        $this->actingAs($ja)->delete(route('pantry.destroy', $mleko))->assertRedirect(route('pantry.index'));

        $this->assertSame(0, $ja->pantryItems()->count());
        $this->assertSame(0, DB::table('pantry_second_packages')->count());
    }

    public function test_limit_150_produktow_liczy_produkty_a_nie_opakowania(): void
    {
        $ja = $this->user();
        $wiersze = [];
        for ($i = 0; $i < CoMamWDomu::MAKS_PRODUKTOW - 1; $i++) {
            $litery = '';
            for ($n = $i; ; $n = intdiv($n, 26)) {
                $litery .= chr(ord('a') + $n % 26);
                if ($n < 26) {
                    break;
                }
            }
            $wiersze[] = ['user_id' => $ja->getKey(), 'name' => 'produkt x'.$litery.'x'];
        }
        DB::table('pantry_items')->insert($wiersze);
        // Każdy produkt ma drugie opakowanie — 298 opakowań, 149 produktów.
        foreach (DB::table('pantry_items')->pluck('id') as $id) {
            DB::table('pantry_second_packages')->insert(['pantry_item_id' => $id]);
        }

        $this->actingAs($ja)->post(route('pantry.store'), ['nazwa' => 'szczypiorek'])->assertSessionHasNoErrors();
        $this->assertSame(CoMamWDomu::MAKS_PRODUKTOW, $ja->pantryItems()->count());
        $this->actingAs($ja)->from(route('pantry.index'))->post(route('pantry.store'), ['nazwa' => 'pietruszka'])->assertSessionHasErrors('nazwa');
    }

    // ------------------------------------------------------------------
    // „Co ugotuję”
    // ------------------------------------------------------------------

    public function test_produkt_z_opakowaniem_po_terminie_i_dobrym_jest_dostepny_a_ostrzezenie_zostaje(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'mleko', ['2026-10-01', 'use_by'], ['2026-12-01', 'best_before']);
        $naleśniki = $this->przepis('Naleśniki 2568', 'mleko', 'mąka');

        $wynik = app(CoUgotuje::class)->dla($ja);

        $this->assertSame([$naleśniki->getKey()], $wynik['przepisy']->modelKeys(), 'Dobre opakowanie otwiera produkt do „Co ugotuję”.');
        $this->assertSame(1, $wynik['produktow'], 'Produkt liczy się raz, nie raz na opakowanie.');
        $this->assertSame(['mąka'], $wynik['brakujace'][$naleśniki->getKey()]);
        $this->assertSame(1, (int) $wynik['przepisy'][0]->getAttribute('skladnikow_brakuje'));

        $this->actingAs($ja)->get(route('pantry.index'))->assertOk()
            ->assertSee('Po terminie „Należy zużyć do”')
            ->assertSee('Termin „Należy zużyć do” minął. Nie używaj tego produktu do gotowania.')
            ->assertSee('Masz na liście 1 produkt');
    }

    public function test_gdy_oba_opakowania_sa_po_terminie_use_by_ekran_mowi_ze_wszystko_po_terminie(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'mleko', ['2026-10-01', 'use_by'], ['2026-10-05', 'use_by']);
        $this->przepis('Naleśniki 2568', 'mleko');

        $wynik = app(CoUgotuje::class)->dla($ja);

        $this->assertTrue($wynik['przepisy']->isEmpty());
        $this->assertTrue($wynik['wszystkie_po_terminie']);
        $this->assertSame(1, $wynik['produktow']);
    }

    public function test_mrozone_pierwsze_opakowanie_nie_chowa_pilnego_drugiego_w_trybie_najpierw_termin(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'mleko', ['2026-10-11', 'best_before'], ['2026-10-12', 'best_before'], mrozonePierwsze: true);
        $przepis = $this->przepis('Naleśniki 2568', 'mleko');

        $wynik = app(CoUgotuje::class)->dla($ja, 0, 20, true);

        $this->assertSame([$przepis->getKey()], $wynik['przepisy']->modelKeys());
        $this->assertSame(1, (int) $wynik['przepisy'][0]->getAttribute('pilnych_pasuje'));
        $this->assertSame([['nazwa' => 'mleko', 'termin' => '2026-10-12']], $wynik['do_zuzycia'][$przepis->getKey()]);
    }

    public function test_dwa_pilne_opakowania_to_jeden_produkt_w_doborze_i_w_zuzyciu(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'mleko', ['2026-10-12', 'best_before'], ['2026-10-11', 'best_before']);
        $this->produkt($ja, 'jajka', ['2026-10-30', 'best_before']);
        $przepis = $this->przepis('Naleśniki 2568', 'mleko', 'jajka');

        $wynik = app(CoUgotuje::class)->dla($ja, 0, 20, true);

        $this->assertSame(1, (int) $wynik['przepisy'][0]->getAttribute('pilnych_pasuje'), 'Produkt z dwoma pilnymi opakowaniami to jeden pilny produkt.');
        $this->assertSame([['nazwa' => 'mleko', 'termin' => '2026-10-11']], $wynik['do_zuzycia'][$przepis->getKey()], 'Raz, z najwcześniejszym terminem.');
        $this->assertSame([], $wynik['brakujace'][$przepis->getKey()]);
    }

    // ------------------------------------------------------------------
    // Pilność, Start, przypomnienie
    // ------------------------------------------------------------------

    public function test_grupy_stawiaja_kazde_opakowanie_osobno(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'mleko', ['2026-10-01', 'use_by'], ['2026-10-11', 'best_before']);
        $mleko = $ja->pantryItems()->with('secondPackage')->get();

        $grupy = PriorytetZuzycia::pogrupuj($mleko, '2026-10-10');

        $this->assertSame(['pierwsze'], $grupy['po_terminie']->pluck('numer')->all());
        $this->assertSame(['drugie'], $grupy['pilne']->pluck('numer')->all());
        $this->assertTrue($grupy['pozniej']->isEmpty() && $grupy['mrozone']->isEmpty() && $grupy['bez_terminu']->isEmpty());
    }

    public function test_pilne_drugie_opakowanie_jest_na_starcie_a_mrozone_pierwsze_go_nie_chowa(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'mleko', ['2026-10-10', 'best_before'], ['2026-10-12', 'best_before'], mrozonePierwsze: true);
        $this->produkt($ja, 'ser', ['2026-10-30', 'best_before']);

        $pilne = PriorytetZuzycia::pilneDla($ja);

        $this->assertSame(['mleko'], $pilne->pluck('name')->all());
        $this->assertSame('drugie', $pilne[0]->numer);
        $this->actingAs($ja)->get(route('home'))->assertOk()
            ->assertSee('Do zużycia w ciągu 3 dni: mleko.');
    }

    public function test_pilny_start_nie_powtarza_nazwy_gdy_oba_opakowania_sa_pilne(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'mleko', ['2026-10-12', 'best_before'], ['2026-10-11', 'best_before']);
        $this->produkt($ja, 'szynka', ['2026-10-13', 'best_before']);

        $this->actingAs($ja)->get(route('home'))->assertOk()
            ->assertSee('Do zużycia w ciągu 3 dni: mleko i szynka.');
        $this->assertSame(['mleko', 'szynka'], PriorytetZuzycia::pilneDla($ja)->pluck('name')->all());
    }

    public function test_sobotni_list_ma_jedna_pozycje_na_produkt_i_mowi_ktore_opakowanie(): void
    {
        $ja = $this->user();
        DB::table('users')->where('id', $ja->getKey())->update(['wants_pantry_reminder' => true]);
        $this->produkt($ja, 'mleko', ['2026-10-10', 'best_before'], ['2026-10-12', 'best_before'], mrozonePierwsze: true, iloscDrugie: '2 litry');

        Artisan::call('kuking:wyslij-przypomnienia-spizarni');
        Mail::assertQueued(PrzypomnienieOProduktach::class, 1);

        $tresc = (new PrzypomnienieOProduktach($ja->fresh()))->content();
        $this->assertCount(1, $tresc->with['pozycje']);
        $pozycja = $tresc->with['pozycje'][0];
        $this->assertSame('mleko', $pozycja['nazwa']);
        $this->assertSame('2 litry', $pozycja['ilosc']);
        $this->assertStringStartsWith('Drugie opakowanie: Najlepiej spożyć przed 12 października', $pozycja['termin']);
    }

    public function test_sobotni_list_nie_wychodzi_gdy_pilne_jest_tylko_mrozone_i_po_terminie(): void
    {
        $ja = $this->user();
        DB::table('users')->where('id', $ja->getKey())->update(['wants_pantry_reminder' => true]);
        // Pierwsze mrożone (pilna data, ale w zamrażarce), drugie po „Należy zużyć do”.
        $this->produkt($ja, 'mleko', ['2026-10-11', 'best_before'], ['2026-10-01', 'use_by'], mrozonePierwsze: true);

        Artisan::call('kuking:wyslij-przypomnienia-spizarni');

        Mail::assertNothingQueued();
    }

    // ------------------------------------------------------------------
    // Strefa Europe/Warsaw: granica północy
    // ------------------------------------------------------------------

    public function test_granica_polnocy_w_polsce_rozstrzyga_osobno_dla_kazdego_opakowania(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'mleko', ['2026-10-09', 'use_by'], ['2026-10-09', 'use_by']);
        $this->przepis('Naleśniki 2568', 'mleko');

        // 23:30 w Warszawie (21:30 UTC) 9 października: termin „dziś” jeszcze obowiązuje.
        Carbon::setTestNow(Carbon::parse('2026-10-09 21:30:00', 'UTC'));
        $this->assertSame('2026-10-09', PriorytetZuzycia::dzis());
        $this->assertCount(1, app(CoUgotuje::class)->dla($ja)['przepisy']);
        $this->actingAs($ja)->get(route('pantry.index'))->assertOk()->assertSee('Termin dziś.');
        $this->assertSame(['mleko'], PriorytetZuzycia::pilneDla($ja)->pluck('name')->all());

        // 00:30 w Warszawie (22:30 UTC) 10 października: oba opakowania są już po terminie.
        Carbon::setTestNow(Carbon::parse('2026-10-09 22:30:00', 'UTC'));
        $this->assertSame('2026-10-10', PriorytetZuzycia::dzis());
        $wynik = app(CoUgotuje::class)->dla($ja);
        $this->assertTrue($wynik['przepisy']->isEmpty());
        $this->assertTrue($wynik['wszystkie_po_terminie']);
        $this->assertTrue(PriorytetZuzycia::pilneDla($ja)->isEmpty());
        $this->actingAs($ja)->get(route('pantry.index'))->assertOk()->assertSee('Termin „Należy zużyć do” minął.');
    }

    public function test_szybki_przycisk_drugiego_opakowania_liczy_date_od_polskiego_dnia(): void
    {
        $ja = $this->user();
        $mleko = $this->produkt($ja, 'mleko', [null, null]);
        // 00:30 w Warszawie, 10 października; w UTC jeszcze 9 października.
        Carbon::setTestNow(Carbon::parse('2026-10-09 22:30:00', 'UTC'));

        $this->actingAs($ja)->put(route('pantry.update', $mleko), [
            '_formularz' => 'termin', 'opakowanie' => 'drugie', 'rodzaj' => 'best_before', 'za' => '3',
        ])->assertRedirect(route('pantry.index'));

        $this->assertSame('2026-10-13', (string) DB::table('pantry_second_packages')->value('expires_on'));
    }

    // ------------------------------------------------------------------
    // Prywatność, eksport, wymazanie, rollback
    // ------------------------------------------------------------------

    public function test_cudze_opakowania_sa_niedostepne_dla_innej_osoby(): void
    {
        $ja = $this->user();
        $obca = $this->user('obca');
        $mleko = $this->produkt($ja, 'mleko', ['2026-10-14', 'best_before'], ['2026-10-20', 'use_by'], iloscDrugie: '2 litry');
        $idDrugiego = (string) DB::table('pantry_second_packages')->value('id');

        $this->actingAs($obca)->get(route('pantry.edit', ['pantryItem' => $mleko, 'opakowanie' => 'drugie']))->assertForbidden();
        $this->actingAs($obca)->put(route('pantry.update', $mleko), ['opakowanie' => 'drugie', 'opakowanie_id' => $idDrugiego, 'ilosc' => 'moje'])->assertForbidden();
        $this->actingAs($obca)->delete(route('pantry.destroyOpakowanie', $mleko), ['opakowanie' => $idDrugiego])->assertForbidden();
        $this->actingAs($obca)->get(route('pantry.index'))->assertOk()->assertDontSee('mleko');

        $this->assertSame('2 litry', DB::table('pantry_second_packages')->value('quantity_note'));
        $this->assertSame(1, DB::table('pantry_second_packages')->count());
    }

    public function test_paczka_danych_ma_drugie_opakowanie_z_wlasnymi_danymi(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'mleko', ['2026-10-14', 'best_before'], ['2026-10-20', 'use_by'], mrozoneDrugie: true, iloscPierwsze: '1 litr', iloscDrugie: '2 litry');
        $this->produkt($ja, 'mąka', [null, null]);

        $dane = app(CollectUserExportData::class)->handle($ja, new ExportPhotoPlan($ja), Carbon::now());
        $wiersze = collect($dane['co_mam_w_domu'])->keyBy('produkt');

        $this->assertSame('1 litr', $wiersze['mleko']['ilosc']);
        $this->assertFalse($wiersze['mleko']['mrozone']);
        $this->assertSame('2026-10-20', (string) $wiersze['mleko']['drugie_opakowanie']['termin']);
        $this->assertSame('use_by', $wiersze['mleko']['drugie_opakowanie']['rodzaj_terminu']);
        $this->assertSame('2 litry', $wiersze['mleko']['drugie_opakowanie']['ilosc']);
        $this->assertTrue($wiersze['mleko']['drugie_opakowanie']['mrozone']);
        $this->assertNull($wiersze['mąka']['drugie_opakowanie']);
    }

    public function test_paczka_danych_nie_zawiera_cudzych_drugich_opakowan(): void
    {
        $ja = $this->user();
        $inna = $this->user('inna');
        $this->produkt($ja, 'mleko', [null, null]);
        $this->produkt($inna, 'ser', [null, null], ['2026-10-20', 'use_by'], iloscDrugie: 'sekret');

        $dane = app(CollectUserExportData::class)->handle($ja, new ExportPhotoPlan($ja), Carbon::now());

        $this->assertStringNotContainsString('sekret', json_encode($dane, JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_wymazanie_konta_kasuje_drugie_opakowania_a_cudze_zostaja(): void
    {
        $ja = $this->user();
        $inna = $this->user('inna');
        $this->produkt($ja, 'mleko', ['2026-10-14', 'best_before'], ['2026-10-20', 'use_by']);
        $cudze = $this->produkt($inna, 'ser', [null, null], ['2026-10-20', 'use_by']);

        $ja->markForDeletion();
        app(EraseAccountData::class)->handle($ja->fresh());

        $this->assertSame(0, DB::table('pantry_items')->where('user_id', $ja->getKey())->count());
        $this->assertSame([$cudze->getKey()], DB::table('pantry_second_packages')->pluck('pantry_item_id')->all(), 'Zostaje tylko drugie opakowanie innej osoby.');
    }

    public function test_cofniecie_migracji_odmawia_gdy_jest_drugie_opakowanie_i_przechodzi_na_pustej_bazie(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'mleko', ['2026-10-14', 'best_before'], ['2026-10-20', 'use_by']);

        try {
            (require base_path(self::MIGRACJA))->down();
            $this->fail('Cofnięcie przeszło, choć skasowałoby osobną decyzję człowieka.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba drugich opakowań, które znikną: 1.', $e->getMessage());
            $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_DRUGIE_OPAKOWANIA=1', $e->getMessage());
            $this->assertStringContainsString('CREATE TABLE pantry_second_packages_kopia', $e->getMessage());
        }

        $this->assertSame(1, DB::table('pantry_second_packages')->count(), 'Odmowa nie ruszyła danych.');

        // Kontrola dodatnia: świeża baza (bez drugich opakowań) cofa się bez pytania.
        DB::table('pantry_second_packages')->delete();
        (require base_path(self::MIGRACJA))->down();
        $this->assertFalse(Schema::hasTable('pantry_second_packages'));
        $this->assertSame(1, $ja->pantryItems()->count(), 'Produkt i jego pierwsze opakowanie zostają.');
    }

    public function test_cofniecie_migracji_przechodzi_ze_swiadomym_wymuszeniem(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'mleko', [null, null], ['2026-10-20', 'use_by']);
        putenv('KUKING_ROLLBACK_KASUJE_DRUGIE_OPAKOWANIA=1');

        (require base_path(self::MIGRACJA))->down();

        $this->assertFalse(Schema::hasTable('pantry_second_packages'));
    }

    public function test_pola_sterujace_drugiego_opakowania_nie_sa_w_fillable(): void
    {
        $model = new PantrySecondPackage;

        $this->assertSame([], $model->getFillable());
        $this->assertFalse($model->isFillable('expires_on'));
        $this->assertFalse($model->isFillable('expiry_kind'));
        $this->assertFalse($model->isFillable('frozen'));
        $this->assertFalse($model->isFillable('pantry_item_id'));
    }
}
