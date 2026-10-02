<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Porcje\WyborPorcji;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Domain\Zakupy\ListaZakupow;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\PolecenieArtisanaZOdmowa;
use Tests\TestCase;

/**
 * Dodawanie do zakupów ilości przeliczonych na wybraną liczbę porcji
 * (V2, #2489): podgląd -> zatwierdzenie, obok dotychczasowego kopiowania
 * ilości autora.
 */
final class ZakupyPrzeliczonePorcjeTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA_MIGRACJI = 'database/migrations/2026_10_06_200200_add_scaled_servings_to_shopping_list_items.php';

    private User $ja;

    private User $autor;

    private Recipe $przepis;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-01 08:00:00', 'UTC'));

        $this->ja = $this->user('kupujaca');
        $this->autor = $this->user('autor_przepisu');
        $this->przepis = $this->przepis(['400 g mąki', '2 łyżki masła', 'sól do smaku', 'mleko — ile weźmie']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  list<string>  $skladniki */
    private function przepis(array $skladniki, array $atrybuty = []): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'servings' => 4,
            'slug' => 'ciasto-na-porcje-'.bin2hex(random_bytes(3)),
            ...$atrybuty,
        ]);

        foreach ($skladniki as $i => $tekst) {
            RecipeIngredient::create(['recipe_id' => $przepis->getKey(), 'ingredient_text' => $tekst, 'position' => $i]);
        }

        return $przepis;
    }

    private function odcisk(string $porcje = '2', ?Recipe $przepis = null): string
    {
        $html = (string) $this->actingAs($this->ja)
            ->get(route('shopping.recipe.scaled', ['recipe' => ($przepis ?? $this->przepis)->slug, 'porcje' => $porcje]))
            ->assertOk()->getContent();
        preg_match('/name="odcisk" value="([0-9a-f]{64})"/', $html, $m);

        return $m[1] ?? '';
    }

    private function dodaj(array $dane = [], ?Recipe $przepis = null)
    {
        return $this->actingAs($this->ja)->post(route('shopping.recipe.store', ($przepis ?? $this->przepis)->slug), $dane);
    }

    /** @return list<string> */
    private function teksty(): array
    {
        return ShoppingListItem::query()->where('user_id', $this->ja->getKey())->orderBy('position')->pluck('text')->all();
    }

    public function test_podglad_pokazuje_linie_autora_obok_wyniku_i_niczego_nie_zapisuje(): void
    {
        $this->actingAs($this->ja)->get(route('shopping.recipe.scaled', ['recipe' => $this->przepis->slug, 'porcje' => '2']))
            ->assertOk()
            ->assertSee('Składniki na 2 porcje')
            ->assertSee('200 g mąki')
            ->assertSee('U autora: 400 g mąki')
            ->assertSee('1 łyżka masła')
            ->assertSee('Bez przeliczenia, jak u autora.')
            ->assertSee('sól do smaku')
            ->assertSee('mleko — ile weźmie')
            ->assertSee('Jeszcze niczego nie dodaliśmy')
            ->assertSee('Przeliczone ilości: 2 z 4')
            ->assertSee('Dodaj ilości autora (4 porcje)');

        $this->assertSame(0, ShoppingListItem::query()->count());
    }

    public function test_zatwierdzenie_zapisuje_przeliczone_z_oznaczeniem_a_nieprzeliczone_jako_oryginal(): void
    {
        $odcisk = $this->odcisk();

        $this->dodaj(['porcje' => '2', 'odcisk' => $odcisk])
            ->assertRedirect(route('shopping.index'))
            ->assertSessionHas('status', fn ($t) => str_contains((string) $t, 'Ilości przeliczone na 2 porcje: 2.')
                && str_contains((string) $t, 'Bez przeliczenia, takie jak napisał autor: 2 — sprawdź je samodzielnie.'));

        $this->assertSame(['200 g mąki', '1 łyżka masła', 'sól do smaku', 'mleko — ile weźmie'], $this->teksty());
        $pozycje = ShoppingListItem::query()->orderBy('position')->get();
        $this->assertSame([2.0, 2.0, null, null], $pozycje->pluck('scaled_servings')->all());

        $html = (string) $this->actingAs($this->ja)->get(route('shopping.index'))->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'Ilość przeliczona z przepisu na 2 porcje.'));
        $this->assertSame(0, $this->przepis->ingredients()->where('ingredient_text', '200 g mąki')->count(), 'Receptura autora bez zmian.');
    }

    public function test_dotychczasowe_kopiowanie_ilosci_autora_dziala_bez_zmian(): void
    {
        $this->dodaj()->assertRedirect(route('shopping.index'));

        $this->assertSame(['400 g mąki', '2 łyżki masła', 'sól do smaku', 'mleko — ile weźmie'], $this->teksty());
        $this->assertSame([null, null, null, null], ShoppingListItem::query()->pluck('scaled_servings')->all());
    }

    public function test_zmiana_przepisu_miedzy_podgladem_a_zapisem_wymaga_nowego_podgladu(): void
    {
        $odcisk = $this->odcisk();
        $this->przepis->ingredients()->where('ingredient_text', '400 g mąki')->update(['ingredient_text' => '600 g mąki']);

        $this->dodaj(['porcje' => '2', 'odcisk' => $odcisk])
            ->assertRedirect(route('shopping.recipe.scaled', ['recipe' => $this->przepis->slug, 'porcje' => '2']))
            ->assertSessionHas('status', fn ($t) => str_contains((string) $t, 'Składniki przepisu zmieniły się od chwili podglądu'));

        $this->assertSame(0, ShoppingListItem::query()->count());

        $this->actingAs($this->ja)->get(route('shopping.recipe.scaled', ['recipe' => $this->przepis->slug, 'porcje' => '2']))->assertSee('300 g mąki');
        $this->dodaj(['porcje' => '2', 'odcisk' => $this->odcisk()])->assertRedirect(route('shopping.index'));
        $this->assertSame('300 g mąki', $this->teksty()[0]);
    }

    public function test_brak_odcisku_albo_zly_odcisk_nic_nie_dopisuje(): void
    {
        $this->dodaj(['porcje' => '2'])->assertRedirect(route('shopping.recipe.scaled', ['recipe' => $this->przepis->slug, 'porcje' => '2']));
        $this->dodaj(['porcje' => '2', 'odcisk' => str_repeat('a', 64)])->assertRedirect(route('shopping.recipe.scaled', ['recipe' => $this->przepis->slug, 'porcje' => '2']));
        $this->dodaj(['porcje' => '2', 'odcisk' => 'krotki'])->assertSessionHasErrors('odcisk');

        $this->assertSame(0, ShoppingListItem::query()->count());
    }

    public function test_zle_porcje_nie_zapisuja_po_cichu_innych_ilosci(): void
    {
        foreach (['abc', '0', '101', '4', '2,555'] as $zle) {
            $this->dodaj(['porcje' => $zle, 'odcisk' => str_repeat('a', 64)])
                ->assertRedirect(route('recipes.show', $this->przepis->slug))
                ->assertSessionHas('status', fn ($t) => str_contains((string) $t, 'Nie rozpoznajemy tej liczby porcji'));
        }

        $this->assertSame(0, ShoppingListItem::query()->count());

        // Podgląd z niemożliwą liczbą odsyła do przepisu, nie pokazuje fałszywego przeliczenia.
        $this->actingAs($this->ja)->get(route('shopping.recipe.scaled', ['recipe' => $this->przepis->slug, 'porcje' => '4']))
            ->assertRedirect(route('recipes.show', $this->przepis->slug));
        $this->actingAs($this->ja)->get(route('shopping.recipe.scaled', ['recipe' => $this->przepis->slug]))
            ->assertRedirect(route('recipes.show', $this->przepis->slug));
    }

    public function test_przepis_bez_liczby_porcji_nie_ma_przeliczenia(): void
    {
        $bez = $this->przepis(['400 g mąki'], ['servings' => null]);

        $this->actingAs($this->ja)->get(route('shopping.recipe.scaled', ['recipe' => $bez->slug, 'porcje' => '2']))
            ->assertRedirect(route('recipes.show', $bez->slug));
        $this->dodaj(['porcje' => '2', 'odcisk' => str_repeat('a', 64)], $bez)->assertRedirect(route('recipes.show', $bez->slug));
        $this->assertSame(0, ShoppingListItem::query()->count());
    }

    public function test_ponowne_dodanie_ostrzega_i_potwierdzenie_zachowuje_porcje_i_linie(): void
    {
        $odcisk = $this->odcisk();
        $this->dodaj(['porcje' => '2', 'odcisk' => $odcisk]);

        $this->dodaj(['porcje' => '2', 'odcisk' => $odcisk])
            ->assertRedirect(route('shopping.recipe.confirm', ['recipe' => $this->przepis->slug, 'porcje' => '2', 'odcisk' => $odcisk]));
        $odczyt1 = ShoppingListItem::query()->count();
        $this->assertSame(4, $odczyt1, 'Ostrzeżenie niczego nie dopisuje.');

        $html = (string) $this->actingAs($this->ja)
            ->get(route('shopping.recipe.confirm', ['recipe' => $this->przepis->slug, 'porcje' => '2', 'odcisk' => $odcisk]))
            ->assertOk()->assertSee('przeliczone na 2 porcje')->getContent();
        $this->assertStringContainsString('name="porcje" value="2"', $html);
        $this->assertStringContainsString('name="odcisk" value="'.$odcisk.'"', $html);

        $this->dodaj(['potwierdzam' => 1, 'porcje' => '2', 'odcisk' => $odcisk])->assertRedirect(route('shopping.index'));
        $odczyt2 = ShoppingListItem::query()->count();
        $this->assertSame(8, $odczyt2);
        $this->assertSame(4, ShoppingListItem::query()->whereNotNull('scaled_servings')->count());
    }

    public function test_przepis_niedostepny_nie_otwiera_podgladu_ani_zapisu(): void
    {
        $prywatny = $this->przepis(['400 g mąki'], ['visibility' => 'private']);

        $this->actingAs($this->ja)->get(route('shopping.recipe.scaled', ['recipe' => $prywatny->slug, 'porcje' => '2']))->assertForbidden();
        $this->dodaj(['porcje' => '2', 'odcisk' => str_repeat('a', 64)], $prywatny)->assertForbidden();
        $this->assertSame(0, ShoppingListItem::query()->count());
    }

    public function test_oznaczenie_przeliczenia_zostaje_po_utracie_dostepu_do_przepisu(): void
    {
        $this->dodaj(['porcje' => '2', 'odcisk' => $this->odcisk()]);
        $this->przepis->forceFill(['visibility' => 'private'])->save();
        // Komunikat po dodaniu (z tytułem) wyświetla się raz; zużywamy go.
        $this->actingAs($this->ja)->get(route('shopping.index'))->assertOk();

        $this->actingAs($this->ja)->get(route('shopping.index'))
            ->assertOk()
            ->assertSee('Z przepisu. Przepis jest już niedostępny.')
            ->assertSee('Ilość przeliczona z przepisu na 2 porcje.')
            ->assertDontSee($this->przepis->title);
    }

    public function test_limit_listy_jest_atomowy_i_nic_nie_dopisuje(): void
    {
        config(['kuking.zakupy.pozycji_max' => 5]);
        foreach (range(1, 3) as $i) {
            $p = new ShoppingListItem(['text' => 'pozycja '.$i]);
            $p->user_id = $this->ja->getKey();
            $p->source = ShoppingListItem::SOURCE_MANUAL;
            $p->position = $i;
            $p->save();
        }

        $this->dodaj(['porcje' => '2', 'odcisk' => $this->odcisk()])
            ->assertRedirect(route('shopping.index'))
            ->assertSessionHas('status', fn ($t) => str_contains((string) $t, 'Lista zakupów mieści najwyżej 5 pozycji'));

        $this->assertSame(3, ShoppingListItem::query()->count());
    }

    public function test_zmiana_porcji_na_stronie_nie_zmienia_istniejacych_pozycji(): void
    {
        $this->dodaj();
        $przed = $this->teksty();

        $this->actingAs($this->ja)->get(route('recipes.show', ['recipe' => $this->przepis->slug, 'porcje' => '8']))->assertOk();

        $this->assertSame($przed, $this->teksty());
    }

    public function test_strona_przepisu_pokazuje_przeliczone_dodawanie_tylko_przy_zmienionych_porcjach(): void
    {
        $adres = route('shopping.recipe.scaled', ['recipe' => $this->przepis->slug, 'porcje' => '2']);

        $this->actingAs($this->ja)->get(route('recipes.show', $this->przepis->slug))->assertOk()
            ->assertDontSee($adres, false)
            ->assertSee('Dodaj składniki do listy zakupów');

        $this->actingAs($this->ja)->get(route('recipes.show', ['recipe' => $this->przepis->slug, 'porcje' => '2']))->assertOk()
            ->assertSee($adres, false)
            ->assertSee('Dodaj składniki na 2 porcje do listy zakupów')
            ->assertSee('Przycisk wyżej dodaje ilości autora (4 porcje).');
    }

    public function test_cofniecie_usuniecia_przywraca_oznaczenie_przeliczenia(): void
    {
        $this->dodaj(['porcje' => '2', 'odcisk' => $this->odcisk()]);
        $pozycja = ShoppingListItem::query()->orderBy('position')->first();

        $this->actingAs($this->ja)->delete(route('shopping.destroy', $pozycja))->assertRedirect();
        $this->assertSame(3, ShoppingListItem::query()->count());

        $this->actingAs($this->ja)->post(route('shopping.undo'))->assertRedirect();

        $this->assertSame(2.0, ShoppingListItem::query()->whereKey($pozycja->getKey())->sole()->scaled_servings);
    }

    public function test_eksport_niesie_oznaczenie_przeliczenia(): void
    {
        $this->dodaj(['porcje' => '2', 'odcisk' => $this->odcisk()]);

        $lista = app(CollectUserExportData::class)->handle($this->ja->refresh(), new ExportPhotoPlan($this->ja), now())['lista_zakupow'];

        $this->assertSame([2.0, 2.0, null, null], array_column($lista, 'przeliczona_na_porcje'));
    }

    public function test_pole_nie_jest_w_fillable_a_baza_pilnuje_zakresu_i_zrodla(): void
    {
        $this->dodaj(['porcje' => '2', 'odcisk' => $this->odcisk()]);
        $z = ShoppingListItem::query()->whereNotNull('scaled_servings')->first();
        $this->assertNotNull($z);

        try {
            $z->fill(['scaled_servings' => 3]);
            $this->fail('scaled_servings nie może być masowo przypisywane.');
        } catch (MassAssignmentException) {
            $this->addToAssertionCount(1);
        }

        foreach ([0.5, 100.5] as $zla) {
            try {
                DB::transaction(fn () => DB::table('shopping_list_items')->where('id', $z->getKey())->update(['scaled_servings' => $zla]));
                $this->fail("CHECK przepuścił {$zla}.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('shopping_list_items_scaled_servings_check', $e->getMessage());
            }
        }

        $reczna = new ShoppingListItem(['text' => 'mleko']);
        $reczna->user_id = $this->ja->getKey();
        $reczna->source = ShoppingListItem::SOURCE_MANUAL;
        $reczna->position = 99;
        $reczna->save();

        $this->expectException(QueryException::class);
        DB::table('shopping_list_items')->where('id', $reczna->getKey())->update(['scaled_servings' => 2]);
    }

    public function test_cofniecie_migracji_odmawia_gdy_sa_przeliczone_pozycje_a_kontrola_dodatnia_przechodzi(): void
    {
        $this->dodaj(['porcje' => '2', 'odcisk' => $this->odcisk()]);

        try {
            PolecenieArtisanaZOdmowa::wywolaj('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
            $this->fail('Cofnięcie przeszło, mimo że pozycje mają zapisane przeliczenie.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(shopping_list_items.scaled_servings IS NOT NULL): 2.', $e->getMessage());
            $this->assertStringContainsString('CO ZROBIĆ', $e->getMessage());
        }
        $this->assertSame(2, ShoppingListItem::query()->whereNotNull('scaled_servings')->count());

        DB::table('shopping_list_items')->update(['scaled_servings' => null]);
        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $odczyt3 = $this->kolumny();
        $this->assertSame(0, $odczyt3);
        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $odczyt4 = $this->kolumny();
        $this->assertSame(1, $odczyt4);
    }

    public function test_ten_sam_mechanizm_co_strona_przepisu(): void
    {
        // Wynik w podglądzie musi być równy temu, co pokazuje strona przepisu.
        $strona = (string) $this->actingAs($this->ja)->get(route('recipes.show', ['recipe' => $this->przepis->slug, 'porcje' => '2']))->getContent();
        $this->assertStringContainsString('200', $strona);

        $podglad = app(ListaZakupow::class)->podgladPorcji(
            $this->przepis->fresh(),
            WyborPorcji::dla($this->przepis->fresh(), '2'),
        );
        $this->assertSame('200 g mąki', $podglad['linie'][0]['tekst']);
    }

    private function kolumny(): int
    {
        return count(DB::select("SELECT 1 FROM information_schema.columns WHERE table_name = 'shopping_list_items' AND column_name = 'scaled_servings'"));
    }
}
