<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Domain\Collections\KolejnoscPrzepisow;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Http\Controllers\CollectionRecipeOrderController;
use App\Models\Collection;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Ręczna kolejność przepisów w własnym, prywatnym zeszycie (#2544, V2, D-333).
 *
 * Pilnowane: zwykły widok bez zmian, przyciski z podpisami jako zwykłe POST-y,
 * trwałość układu, stabilne reguły (dopisanie na koniec, wyjęcie bez dziur
 * psujących przesuwanie), ochrona przed starą kartą, Policy (tylko właściciel
 * prywatnego zeszytu bez zaproszonych), paginacja bez duplikatów, wydruk
 * i paczka danych w tej samej kolejności oraz brak N+1.
 */
final class KolejnoscPrzepisowWZeszycieTest extends TestCase
{
    use RefreshDatabase;

    private User $halina;

    private User $autor;

    private Collection $zeszyt;

    /** @var array<string, Recipe> tytuł => przepis, zapisane od najstarszego do najnowszego */
    private array $przepisy = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->halina = $this->user('halina', ['display_name' => 'Halina']);
        $this->autor = $this->user('autorka', ['display_name' => 'Autorka']);
        $this->zeszyt = $this->zeszyt($this->halina, 'Niedzielny obiad');
    }

    private function zeszyt(User $wlasciciel, string $nazwa, string $widocznosc = 'private'): Collection
    {
        return Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => $nazwa, 'visibility' => $widocznosc]);
    }

    /**
     * Przepisy zapisane w tej kolejności czasu, więc DOMYŚLNY porządek zeszytu
     * (od najnowszego) to odwrotność listy.
     *
     * @param  list<string>  $tytuly  od najstarszego zapisu do najnowszego
     */
    private function zapisz(array $tytuly, ?Collection $zeszyt = null): void
    {
        $zeszyt ??= $this->zeszyt;
        $start = Carbon::parse('2026-09-01 10:00:00');

        foreach ($tytuly as $i => $tytul) {
            $przepis = $this->przepisy[$tytul] ??= Recipe::factory()->create(['author_id' => $this->autor->getKey(), 'title' => $tytul]);
            DB::table('collection_items')->insert([
                'collection_id' => $zeszyt->getKey(),
                'recipe_id' => $przepis->getKey(),
                'created_at' => $start->copy()->addMinutes($i),
                'added_by_id' => $zeszyt->owner_id,
                'note' => null,
            ]);
        }
    }

    /**
     * Czyta bazę przy każdym wywołaniu — Larastan nie może zapamiętać wyniku.
     *
     * @return list<string> tytuły w kolejności pełnej listy zeszytu
     *
     * @phpstan-impure
     */
    private function kolejnosc(?Collection $zeszyt = null): array
    {
        $tytulPoId = [];
        foreach ($this->przepisy as $tytul => $przepis) {
            $tytulPoId[(string) $przepis->getKey()] = $tytul;
        }

        return array_map(
            fn (string $id): string => $tytulPoId[$id],
            KolejnoscPrzepisow::uklad($zeszyt ?? $this->zeszyt),
        );
    }

    /** Odcisk układu, który widziała karta w tej chwili. */
    private function odcisk(?Collection $zeszyt = null): string
    {
        return KolejnoscPrzepisow::odcisk(KolejnoscPrzepisow::uklad($zeszyt ?? $this->zeszyt));
    }

    private function przesun(string $tytul, string $kierunek, ?string $odcisk = null, ?User $kto = null, ?Collection $zeszyt = null): TestResponse
    {
        $zeszyt ??= $this->zeszyt;

        return $this->actingAs($kto ?? $this->halina)->post(
            route('collections.recipes.move', ['collection' => $zeszyt, 'pozycja' => $this->przepisy[$tytul]->getKey()]),
            ['kierunek' => $kierunek, CollectionRecipeOrderController::POLE_ODCISKU => $odcisk ?? $this->odcisk($zeszyt)],
        );
    }

    public function test_zwykly_widok_zeszytu_jest_od_najnowszego_bez_przyciskow_ukladania(): void
    {
        $this->zapisz(['Zupa', 'Danie', 'Deser']);

        $this->actingAs($this->halina)->get(route('collections.show', $this->zeszyt))
            ->assertOk()
            ->assertSeeInOrder(['Deser', 'Danie', 'Zupa'])
            ->assertSee('Ułóż kolejność przepisów')
            ->assertDontSee('data-kolejnosc-przepisu', false)
            ->assertDontSee('Na początek');
    }

    public function test_tryb_ukladania_pokazuje_przyciski_z_podpisami_a_skrajne_pozycje_nie_maja_zbednych(): void
    {
        $this->zapisz(['Zupa', 'Danie', 'Deser']);

        $html = (string) $this->actingAs($this->halina)
            ->get(route('collections.show', ['collection' => $this->zeszyt, 'uloz' => 1]))
            ->assertOk()
            ->assertSee('Układasz kolejność przepisów')
            ->assertSee('Miejsce 1 z 3')
            ->assertSee('Miejsce 3 z 3')
            ->getContent();

        // 3 formularze; Wyżej mają dwa (miejsca 2 i 3), Niżej dwa (1 i 2).
        $this->assertSame(3, substr_count($html, 'data-kolejnosc-przepisu'));
        $this->assertSame(2, substr_count($html, 'value="wyzej"'));
        $this->assertSame(2, substr_count($html, 'value="nizej"'));
        $this->assertStringContainsString('>Wyżej</button>', $html);
        $this->assertStringContainsString('>Niżej</button>', $html);
    }

    public function test_zupa_danie_deser_zostaje_po_ponownym_wejsciu_i_nie_rusza_dat_ani_powiadomien(): void
    {
        $this->zapisz(['Deser', 'Danie', 'Zupa']); // domyślnie, od najnowszego: Zupa, Danie, Deser
        DB::table('collection_items')->where('recipe_id', $this->przepisy['Danie']->getKey())->update(['note' => 'Bez soli']);
        $daty = DB::table('collection_items')->orderBy('recipe_id')->pluck('created_at', 'recipe_id')->all();
        $powiadomienia = DB::table('notifications')->count();

        // Deser dwa razy wyżej: Deser, Zupa, Danie.
        $this->przesun('Deser', 'wyzej')->assertRedirect();
        $this->przesun('Deser', 'wyzej')->assertRedirect();

        $this->assertSame(['Deser', 'Zupa', 'Danie'], $this->kolejnosc());

        // Ponowne wejście: ta sama kolejność.
        $this->actingAs($this->halina)->get(route('collections.show', $this->zeszyt))
            ->assertSeeInOrder(['Deser', 'Zupa', 'Danie']);

        $this->assertSame($daty, DB::table('collection_items')->orderBy('recipe_id')->pluck('created_at', 'recipe_id')->all());
        $this->assertSame('Bez soli', DB::table('collection_items')->where('recipe_id', $this->przepisy['Danie']->getKey())->value('note'));
        $this->assertSame($powiadomienia, DB::table('notifications')->count());
    }

    public function test_cztery_ruchy_dzialaja_na_widocznej_liscie(): void
    {
        $this->zapisz(['D', 'C', 'B', 'A']); // domyślnie A, B, C, D

        $this->przesun('A', 'nizej')->assertRedirect();
        $this->assertSame(['B', 'A', 'C', 'D'], $this->kolejnosc());

        $this->przesun('D', 'poczatek')->assertRedirect();
        $this->assertSame(['D', 'B', 'A', 'C'], $this->kolejnosc());

        $this->przesun('D', 'koniec')->assertRedirect();
        $this->assertSame(['B', 'A', 'C', 'D'], $this->kolejnosc());

        $this->przesun('C', 'wyzej')->assertRedirect();
        $this->assertSame(['B', 'C', 'A', 'D'], $this->kolejnosc());
    }

    public function test_przepis_juz_na_miejscu_nic_nie_zmienia_i_mowi_o_tym(): void
    {
        $this->zapisz(['B', 'A']); // A, B

        $this->przesun('A', 'wyzej')
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $tekst): bool => str_contains($tekst, 'już tam stoi'));

        $this->assertSame(['A', 'B'], $this->kolejnosc());
        $this->assertFalse(KolejnoscPrzepisow::jestUlozony($this->zeszyt), 'Ruch bez skutku nie powinien nadawać pozycji.');
    }

    public function test_stara_karta_nie_psuje_kolejnosci_a_brak_odcisku_tez_jest_konfliktem(): void
    {
        $this->zapisz(['C', 'B', 'A']); // A, B, C
        $staryOdcisk = $this->odcisk();

        // Druga karta: C na początek.
        $this->przesun('C', 'poczatek')->assertRedirect();
        $poDrugiejKarcie = $this->kolejnosc();
        $this->assertSame(['C', 'A', 'B'], $poDrugiejKarcie);

        // Pierwsza karta (stara) klika "B wyżej" — nie wolno przesunąć niczego.
        $this->przesun('B', 'wyzej', $staryOdcisk)
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $tekst): bool => str_contains($tekst, 'Nic nie zostało przesunięte'))
            ->assertSessionHas('status_rodzaj', 'blad');
        $poStarejKarcie = $this->kolejnosc();
        $this->assertSame(['C', 'A', 'B'], $poStarejKarcie);

        // Bez odcisku (własny klient, stary formularz) — też konflikt.
        $this->actingAs($this->halina)->post(
            route('collections.recipes.move', ['collection' => $this->zeszyt, 'pozycja' => $this->przepisy['B']->getKey()]),
            ['kierunek' => 'wyzej'],
        )->assertSessionHas('status_rodzaj', 'blad');
        $bezOdcisku = $this->kolejnosc();
        $this->assertSame(['C', 'A', 'B'], $bezOdcisku);
    }

    public function test_podwojne_klikniecie_tego_samego_przycisku_przesuwa_tylko_raz(): void
    {
        $this->zapisz(['C', 'B', 'A']); // A, B, C
        $odcisk = $this->odcisk();

        $this->przesun('C', 'wyzej', $odcisk);
        $this->przesun('C', 'wyzej', $odcisk)->assertSessionHas('status_rodzaj', 'blad');

        $this->assertSame(['A', 'C', 'B'], $this->kolejnosc());
    }

    public function test_nowy_przepis_w_ulozonym_zeszycie_trafia_na_koniec_a_w_nieulozonym_na_poczatek(): void
    {
        $this->zapisz(['B', 'A']);
        $nowy1 = Recipe::factory()->create(['author_id' => $this->autor->getKey(), 'title' => 'Nowy1']);
        $nowy2 = Recipe::factory()->create(['author_id' => $this->autor->getKey(), 'title' => 'Nowy2']);
        $this->przepisy['Nowy1'] = $nowy1;
        $this->przepisy['Nowy2'] = $nowy2;
        $zapis = app(SaveRecipeToCollection::class);

        // Nieułożony: jak dotąd — najnowszy na górze.
        $zapis->handle($this->halina, $nowy1, $this->zeszyt);
        $this->assertSame(['Nowy1', 'A', 'B'], $this->kolejnosc());
        $this->assertNull(DB::table('collection_items')->where('recipe_id', $nowy1->getKey())->value('position'));

        // Układamy (A na koniec), potem dopisujemy dwa — oba na końcu, po kolei.
        $this->przesun('A', 'koniec')->assertRedirect();
        $this->assertSame(['Nowy1', 'B', 'A'], $this->kolejnosc());

        $zapis->handle($this->halina, $nowy2, $this->zeszyt);
        $this->assertSame(['Nowy1', 'B', 'A', 'Nowy2'], $this->kolejnosc());
        $this->assertSame(4, (int) DB::table('collection_items')->where('recipe_id', $nowy2->getKey())->value('position'));

        // Wyjęcie i powtórny zapis: wraca na koniec, bez kolizji pozycji.
        $zapis->remove($this->halina, $nowy1, $this->zeszyt);
        $zapis->handle($this->halina, $nowy1, $this->zeszyt);
        $this->assertSame(['B', 'A', 'Nowy2', 'Nowy1'], $this->kolejnosc());
    }

    public function test_wyjecie_przepisu_nie_zostawia_dziur_psujacych_przesuwanie(): void
    {
        $this->zapisz(['D', 'C', 'B', 'A']); // A, B, C, D
        $this->przesun('A', 'nizej'); // B, A, C, D  (pozycje 1..4)

        app(SaveRecipeToCollection::class)->remove($this->halina, $this->przepisy['A'], $this->zeszyt);
        // Pozycje 1, 3, 4 (dziura po A).
        $this->assertSame(['B', 'C', 'D'], $this->kolejnosc());

        $this->przesun('C', 'wyzej')->assertRedirect();
        $this->assertSame(['C', 'B', 'D'], $this->kolejnosc());

        $this->przesun('B', 'nizej')->assertRedirect();
        $this->assertSame(['C', 'D', 'B'], $this->kolejnosc());
    }

    public function test_ukryty_przepis_jest_pomijany_przy_wyborze_sasiada_i_nie_da_sie_go_ruszyc(): void
    {
        $this->zapisz(['D', 'C', 'B', 'A']); // A, B, C, D
        $this->przesun('A', 'nizej'); // B, A, C, D
        $this->przesun('A', 'nizej'); // B, C, A, D

        // C staje się prywatny u autorki — dla Haliny niewidoczny.
        $this->przepisy['C']->forceFill(['visibility' => 'private'])->save();

        // "A wyżej" przeskakuje niewidoczne C i zamienia się z B.
        $this->przesun('A', 'wyzej')->assertRedirect();
        $this->assertSame(['A', 'C', 'B', 'D'], $this->kolejnosc());

        // Niewidocznego przepisu ruszyć nie można (404), nic się nie zmienia.
        $przed = $this->kolejnosc();
        $this->przesun('C', 'wyzej')->assertNotFound();
        $this->assertSame($przed, $this->kolejnosc());
    }

    public function test_cudzy_zeszyt_publiczny_wspolny_i_gosc_nie_przesuna_niczego(): void
    {
        $this->zapisz(['B', 'A']);
        $przed = $this->kolejnosc();
        $obca = $this->user('obca');

        $this->przesun('A', 'nizej', null, $obca)->assertForbidden();

        $this->zeszyt->forceFill(['visibility' => 'public'])->save();
        $this->przesun('A', 'nizej')->assertForbidden();
        $this->zeszyt->forceFill(['visibility' => 'private'])->save();

        DB::table('collection_members')->insert(['collection_id' => $this->zeszyt->getKey(), 'user_id' => $obca->getKey(), 'created_at' => now()]);
        $this->przesun('A', 'nizej', null, $obca)->assertForbidden(); // członek
        $this->przesun('A', 'nizej')->assertForbidden(); // nawet właściciel — wspólny jest poza pilotem

        auth()->logout();
        $this->post(route('collections.recipes.move', ['collection' => $this->zeszyt, 'pozycja' => $this->przepisy['A']->getKey()]), ['kierunek' => 'nizej'])
            ->assertRedirect(route('login'));

        $this->assertSame($przed, $this->kolejnosc());
        $this->assertFalse(KolejnoscPrzepisow::jestUlozony($this->zeszyt));
    }

    public function test_przepis_spoza_zeszytu_i_zly_kierunek_nie_ruszaja_danych(): void
    {
        $this->zapisz(['B', 'A']);
        $inny = Recipe::factory()->create(['author_id' => $this->autor->getKey()]);
        $this->przepisy['Inny'] = $inny;

        $this->przesun('Inny', 'wyzej')->assertNotFound();

        $this->actingAs($this->halina)->post(
            route('collections.recipes.move', ['collection' => $this->zeszyt, 'pozycja' => $this->przepisy['A']->getKey()]),
            ['kierunek' => 'w-bok', CollectionRecipeOrderController::POLE_ODCISKU => $this->odcisk()],
        )->assertSessionHasErrors('kierunek');

        $this->assertFalse(KolejnoscPrzepisow::jestUlozony($this->zeszyt));
        $this->assertSame(['A', 'B'], $this->kolejnosc());
    }

    public function test_ten_sam_przepis_ma_rozne_pozycje_w_roznych_zeszytach(): void
    {
        $drugi = $this->zeszyt($this->halina, 'Święta');
        $this->zapisz(['B', 'A']);
        $this->zapisz(['A', 'B'], $drugi); // w drugim: B, A (od najnowszego)

        $this->przesun('A', 'nizej'); // pierwszy: B, A

        $this->assertSame(['B', 'A'], $this->kolejnosc());
        $this->assertSame(['B', 'A'], $this->kolejnosc($drugi));
        $this->assertFalse(KolejnoscPrzepisow::jestUlozony($drugi), 'Ułożenie jednego zeszytu nie może ruszyć drugiego.');
    }

    public function test_paginacja_przepis_z_drugiej_strony_wedruje_na_pierwsza_bez_duplikatow(): void
    {
        $tytuly = array_map(fn (int $i): string => sprintf('Przepis %02d', $i), range(1, 15));
        $this->zapisz(array_reverse($tytuly)); // domyślnie: 01, 02, ... 15
        $odwiedzone = [];

        // 13. przepis (druga strona, miejsce 13) "Na początek": ląduje na stronie 1.
        $odpowiedz = $this->przesun('Przepis 13', 'poczatek');
        $odpowiedz->assertRedirect();
        $adres = (string) $odpowiedz->headers->get('Location');
        $this->assertStringContainsString('uloz=1', $adres);
        $this->assertStringNotContainsString('page=', $adres, 'Na stronie 1 nie dopisujemy page.');
        $this->assertStringContainsString('#przepis-'.$this->przepisy['Przepis 13']->getKey(), $adres);

        foreach ([1, 2] as $strona) {
            $html = (string) $this->actingAs($this->halina)
                ->get(route('collections.show', ['collection' => $this->zeszyt, 'uloz' => 1, 'page' => $strona]))
                ->assertOk()->getContent();
            preg_match_all('/Przepis \d\d/u', (string) preg_replace('/Miejsce \d+ z \d+/u', '', $html), $trafienia);
            $odwiedzone = array_merge($odwiedzone, array_unique($trafienia[0]));
        }

        $this->assertCount(15, $odwiedzone);
        $this->assertCount(15, array_unique($odwiedzone), 'Przepisy powtarzają się między stronami.');
        $this->assertSame('Przepis 13', $this->kolejnosc()[0]);

        // Przesunięcie pierwszego z drugiej strony "Wyżej" przenosi go na stronę 1 (miejsce 12).
        $odpowiedz = $this->przesun($this->kolejnosc()[12], 'wyzej');
        $this->assertStringNotContainsString('page=2', (string) $odpowiedz->headers->get('Location'));
    }

    public function test_przywracanie_kolejnosci_zapisu_zeruje_pozycje_i_wymaga_swiezego_odcisku(): void
    {
        $this->zapisz(['C', 'B', 'A']); // A, B, C
        $this->przesun('C', 'poczatek'); // C, A, B
        $this->assertTrue(KolejnoscPrzepisow::jestUlozony($this->zeszyt));

        $stary = $this->odcisk();
        $this->przesun('B', 'poczatek'); // B, C, A

        $this->actingAs($this->halina)->post(route('collections.recipes.order-reset', $this->zeszyt), [CollectionRecipeOrderController::POLE_ODCISKU => $stary])
            ->assertSessionHas('status_rodzaj', 'blad');
        $this->assertSame(['B', 'C', 'A'], $this->kolejnosc());

        $this->actingAs($this->halina)->post(route('collections.recipes.order-reset', $this->zeszyt), [CollectionRecipeOrderController::POLE_ODCISKU => $this->odcisk()])
            ->assertRedirect(route('collections.show', $this->zeszyt))
            ->assertSessionHas('status_rodzaj', 'sukces');

        $this->assertFalse(KolejnoscPrzepisow::jestUlozony($this->zeszyt));
        $this->assertSame(['A', 'B', 'C'], $this->kolejnosc());
        $this->assertSame(3, DB::table('collection_items')->where('collection_id', $this->zeszyt->getKey())->count(), 'Przepisy zostają.');

        $this->actingAs($this->obcaOsoba())->post(route('collections.recipes.order-reset', $this->zeszyt))->assertForbidden();
    }

    private function obcaOsoba(): User
    {
        return $this->user('obcaosoba');
    }

    public function test_wydruk_idzie_kolejnoscia_wlasciciela_a_bez_ulozenia_alfabetycznie(): void
    {
        $this->zapisz(['Zupa', 'Pierogi', 'Babka']);

        $this->actingAs($this->halina)->get(route('collections.print', $this->zeszyt))
            ->assertOk()->assertSeeInOrder(['Spis treści', 'Babka', 'Pierogi', 'Zupa']);

        $this->przesun('Zupa', 'poczatek'); // domyślnie: Babka, Pierogi, Zupa → Zupa, Babka, Pierogi
        $this->przesun('Pierogi', 'wyzej');

        $this->assertSame(['Zupa', 'Pierogi', 'Babka'], $this->kolejnosc());
        $html = (string) $this->actingAs($this->halina)->get(route('collections.print', $this->zeszyt))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<a href="#przepis-1">Zupa<\/a>.*<a href="#przepis-2">Pierogi<\/a>.*<a href="#przepis-3">Babka<\/a>/su', $html);
    }

    public function test_paczka_danych_niesie_kolejnosc_i_jej_rodzaj(): void
    {
        $this->zapisz(['C', 'B', 'A']); // A, B, C
        $paczka = fn (): array => app(CollectUserExportData::class)
            ->handle($this->halina->fresh(), new ExportPhotoPlan($this->halina->fresh()), Carbon::now())['kolekcje'];

        $this->assertSame('od_najnowszego', $paczka()[0]['kolejnosc_przepisow']);
        $this->assertSame(['A', 'B', 'C'], array_column($paczka()[0]['przepisy'], 'tytul'));

        $this->przesun('C', 'poczatek');

        $this->assertSame('reczna', $paczka()[0]['kolejnosc_przepisow']);
        $this->assertSame(['C', 'A', 'B'], array_column($paczka()[0]['przepisy'], 'tytul'));
    }

    public function test_pozycja_to_osobne_pole_od_created_at_a_pozycja_nie_wchodzi_do_fillable(): void
    {
        $this->assertNotContains('position', (new Collection)->getFillable());
        $this->zapisz(['B', 'A']);
        $this->przesun('A', 'nizej');

        $wiersz = DB::table('collection_items')->where('recipe_id', $this->przepisy['A']->getKey())->first();
        $this->assertSame(2, (int) $wiersz->position);
        $this->assertSame('2026-09-01 10:01:00', Carbon::parse($wiersz->created_at)->utc()->format('Y-m-d H:i:s'));
    }

    public function test_baza_odrzuca_powtorzona_pozycje_i_pozycje_niedodatnia(): void
    {
        $this->zapisz(['B', 'A']);
        $this->przesun('A', 'nizej'); // pozycje 1, 2

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('collection_items')->where('recipe_id', $this->przepisy['B']->getKey())->update(['position' => 2]);
    }

    public function test_baza_odrzuca_pozycje_zero(): void
    {
        $this->zapisz(['B']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/collection_items_position_check/');
        DB::table('collection_items')->update(['position' => 0]);
    }

    public function test_strona_zeszytu_w_trybie_ukladania_nie_ma_n_plus_1(): void
    {
        $zapytan = function (int $ile): int {
            $zeszyt = $this->zeszyt($this->halina, 'Duży '.$ile);
            $tytuly = array_map(fn (int $i): string => "Duży{$ile}-{$i}", range(1, $ile));
            $this->zapisz($tytuly, $zeszyt);
            $this->przesun($tytuly[0], 'koniec', $this->odcisk($zeszyt), null, $zeszyt);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->halina)->get(route('collections.show', ['collection' => $zeszyt, 'uloz' => 1]))->assertOk();
            $liczba = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $liczba;
        };

        $malo = $zapytan(13);
        $duzo = $zapytan(120);

        $this->assertSame($malo, $duzo, "Liczba zapytań rośnie z rozmiarem zeszytu ({$malo} → {$duzo}).");
    }
}
