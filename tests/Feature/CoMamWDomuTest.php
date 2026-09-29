<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pantry\CoMamWDomu;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Ingredient;
use App\Models\PantryItem;
use App\Models\Recipe;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „Co mam w domu” — prywatna lista produktów (V2, D-285).
 */
class CoMamWDomuTest extends TestCase
{
    use RefreshDatabase;

    public function test_gosc_nie_wchodzi_na_liste(): void
    {
        $this->get(route('pantry.index'))->assertRedirect(route('login'));
        $this->post(route('pantry.store'), ['nazwa' => 'mąka'])->assertRedirect(route('login'));
        $this->get(route('pantry.suggestions', ['q' => 'ma']))->assertRedirect(route('login'));
    }

    public function test_dodaje_produkt_i_pokazuje_go_na_liscie(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->post(route('pantry.store'), ['nazwa' => '  Mąka   pszenna '])
            ->assertRedirect(route('pantry.index'))
            ->assertSessionHas('status', 'Dodano „Mąka pszenna” do listy.');

        $this->assertSame(['Mąka pszenna'], $user->pantryItems()->pluck('name')->all());

        $this->actingAs($user)->get(route('pantry.index'))
            ->assertOk()
            ->assertSee('Mąka pszenna')
            ->assertSee('Co masz w domu?')
            ->assertSee('Usuń z listy: Mąka pszenna', false);
    }

    public function test_ta_sama_rzecz_w_innej_pisowni_nie_dubluje_sie(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post(route('pantry.store'), ['nazwa' => 'Jajka']);

        $this->actingAs($user)
            ->post(route('pantry.store'), ['nazwa' => 'jajko'])
            ->assertSessionHas('status', '„Jajka” już jest na Twojej liście.');

        $this->assertSame(1, $user->pantryItems()->count());
    }

    public function test_klucz_porownania_ignoruje_polskie_znaki_wielkosc_liter_i_prosta_liczbe_mnoga(): void
    {
        $user = $this->user();
        $lista = app(CoMamWDomu::class);

        foreach (['Pomidory', 'Ziemniaki', 'Cebula', 'Masło'] as $nazwa) {
            $lista->dodaj($user, $nazwa);
        }

        foreach (['pomidorów', 'ziemniak', 'CEBULE', 'masla'] as $inaczej) {
            $this->assertFalse($lista->dodaj($user, $inaczej)['nowy'], "„{$inaczej}” powinno trafić na produkt już z listy.");
        }

        $this->assertSame(4, $user->pantryItems()->count());
        // Kontrola dodatnia: inny produkt jednak się dopisuje.
        $this->assertTrue($lista->dodaj($user, 'ser żółty')['nowy']);
    }

    public function test_pusta_i_bezslowna_nazwa_dostaje_blad_mowiacy_co_zrobic_i_wpis_zostaje_w_polu(): void
    {
        $user = $this->user();

        $this->actingAs($user)->from(route('pantry.index'))
            ->post(route('pantry.store'), ['nazwa' => ''])
            ->assertRedirect(route('pantry.index'))
            ->assertSessionHasErrors(['nazwa' => 'Wpisz nazwę produktu, na przykład „mąka” albo „jajka”.']);

        $this->actingAs($user)->from(route('pantry.index'))
            ->post(route('pantry.store'), ['nazwa' => '500 g'])
            ->assertSessionHasErrors('nazwa')
            ->assertSessionHasInput('nazwa', '500 g');

        $this->assertSame(0, $user->pantryItems()->count());
    }

    public function test_baza_sama_odrzuca_nazwe_bez_slow(): void
    {
        $user = $this->user();

        $this->expectException(QueryException::class);
        DB::table('pantry_items')->insert(['user_id' => $user->getKey(), 'name' => '123']);
    }

    public function test_lista_ma_gorny_limit(): void
    {
        $user = $this->user();
        $wiersze = [];
        for ($i = 0; $i < CoMamWDomu::MAKS_PRODUKTOW; $i++) {
            $wiersze[] = ['user_id' => $user->getKey(), 'name' => 'produkt '.$this->slowo($i)];
        }
        DB::table('pantry_items')->insert($wiersze);

        $this->actingAs($user)->from(route('pantry.index'))
            ->post(route('pantry.store'), ['nazwa' => 'szczypiorek'])
            ->assertSessionHasErrors('nazwa');

        $this->assertSame(CoMamWDomu::MAKS_PRODUKTOW, $user->pantryItems()->count());
    }

    public function test_usuwa_tylko_wlasny_produkt(): void
    {
        $wlasciciel = $this->user();
        $obcy = $this->user();
        $moderator = $this->moderator();
        $produkt = $wlasciciel->pantryItems()->create(['name' => 'mleko']);

        $this->actingAs($obcy)->delete(route('pantry.destroy', $produkt))->assertForbidden();
        $this->actingAs($moderator)->delete(route('pantry.destroy', $produkt))->assertForbidden();
        $this->assertModelExists($produkt);

        $this->actingAs($wlasciciel)->delete(route('pantry.destroy', $produkt))
            ->assertRedirect(route('pantry.index'))
            ->assertSessionHas('status', 'Usunięto „mleko” z listy.');
        $this->assertModelMissing($produkt);
    }

    public function test_lista_jest_prywatna(): void
    {
        $wlasciciel = $this->user();
        $obcy = $this->user();
        $wlasciciel->pantryItems()->create(['name' => 'szafran z Hiszpanii']);

        $this->actingAs($obcy)->get(route('pantry.index'))
            ->assertOk()
            ->assertDontSee('szafran z Hiszpanii');
    }

    public function test_podpowiedzi_pochodza_tylko_z_publicznych_opublikowanych_przepisow(): void
    {
        $user = $this->user();
        $this->przepisZeSkladnikami(['mąka pszenna', 'mąka ziemniaczana']);
        $this->przepisZeSkladnikami(['mąka z sekretnego zeszytu'], ['visibility' => 'private']);
        $this->przepisZeSkladnikami(['mąka ze szkicu'], [], szkic: true);

        $odpowiedz = $this->actingAs($user)->getJson(route('pantry.suggestions', ['q' => 'maka']))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(['mąka pszenna', 'mąka ziemniaczana'], $odpowiedz->json('podpowiedzi'));
    }

    public function test_podpowiedzi_pomijaja_to_co_juz_jest_na_liscie(): void
    {
        $user = $this->user();
        $this->przepisZeSkladnikami(['cebula', 'cebula czerwona']);
        $user->pantryItems()->create(['name' => 'Cebule']);

        $this->actingAs($user)->getJson(route('pantry.suggestions', ['q' => 'ceb']))
            ->assertOk()
            ->assertExactJson(['podpowiedzi' => ['cebula czerwona']]);
    }

    public function test_lista_jest_w_paczce_danych(): void
    {
        $user = $this->user();
        $user->pantryItems()->create(['name' => 'kasza gryczana']);

        $dane = app(CollectUserExportData::class)->handle($user, new ExportPhotoPlan($user), Carbon::now());

        $this->assertSame('kasza gryczana', $dane['co_mam_w_domu'][0]['produkt']);
        $this->assertArrayNotHasKey('klucz', $dane['co_mam_w_domu'][0]);
    }

    public function test_lista_znika_przy_wymazaniu_konta_a_cudza_zostaje(): void
    {
        $user = $this->user();
        $inny = $this->user();
        $user->pantryItems()->create(['name' => 'kasza gryczana']);
        $inny->pantryItems()->create(['name' => 'kasza jaglana']);

        $user->markForDeletion();
        app(EraseAccountData::class)->handle($user->fresh());

        $this->assertSame(0, PantryItem::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(1, PantryItem::query()->where('user_id', $inny->getKey())->count());
    }

    /**
     * @param  list<string>  $skladniki
     * @param  array<string, mixed>  $atrybuty
     */
    private function przepisZeSkladnikami(array $skladniki, array $atrybuty = [], bool $szkic = false): Recipe
    {
        $fabryka = Recipe::factory();
        $przepis = ($szkic ? $fabryka->draft() : $fabryka)->create($atrybuty);

        foreach ($skladniki as $i => $tekst) {
            $przepis->ingredients()->create([
                'position' => $i,
                'ingredient_text' => $tekst,
                'ingredient_id' => Ingredient::findOrCreateByName($tekst)->getKey(),
            ]);
        }

        return $przepis;
    }

    /** Różne słowa bez cyfr — cyfry odpadają z klucza porównania. */
    private function slowo(int $n): string
    {
        $litery = '';
        do {
            $litery .= chr(ord('a') + $n % 26);
            $n = intdiv($n, 26);
        } while ($n > 0);

        return 'x'.$litery.'x';
    }
}
