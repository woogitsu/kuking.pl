<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Zakupy\ListaZakupow;
use App\Models\CookedEvent;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ręczny wybór składników przepisu do listy zakupów (#2462, V2).
 *
 * Czas zamrożony na czwartek 1 października 2026. Pomiary idą przez HTTP i
 * końcowy HTML; akcja domenowa jest wołana wprost tylko tam, gdzie kryterium
 * wymaga kontroli niezależnej od trasy.
 */
final class ListaZakupowWyborSkladnikowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-01 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  list<string|array{0: string, 1: ?string}>  $skladniki  tekst albo [tekst, grupa] */
    private function przepis(User $autor, array $skladniki, array $atrybuty = []): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            ...$atrybuty,
        ]);

        foreach ($skladniki as $i => $linia) {
            [$tekst, $grupa] = is_array($linia) ? $linia : [$linia, null];
            RecipeIngredient::create(['recipe_id' => $przepis->getKey(), 'ingredient_text' => $tekst, 'group_name' => $grupa, 'position' => $i]);
        }

        return $przepis;
    }

    /** Identyfikatory linii przepisu po tekście (w kolejności przepisu). */
    private function idy(Recipe $przepis, string ...$teksty): array
    {
        $wszystkie = $przepis->ingredients()->get();
        $wynik = [];
        foreach ($teksty as $tekst) {
            $wynik[] = (string) $wszystkie->first(fn (RecipeIngredient $s) => $s->ingredient_text === $tekst && ! in_array((string) $s->getKey(), $wynik, true))->getKey();
        }

        return $wynik;
    }

    private function odcisk(Recipe $przepis): string
    {
        return app(ListaZakupow::class)->doWyboru($przepis)['odcisk'];
    }

    private function dopisz(User $kto, Recipe $przepis, array $idy, ?string $odcisk = null, array $dodatkowe = [])
    {
        return $this->actingAs($kto)->post(route('shopping.recipe.pick.store', $przepis), [
            'skladniki' => $idy,
            'odcisk' => $odcisk ?? $this->odcisk($przepis),
            ...$dodatkowe,
        ]);
    }

    /** @return list<string> */
    private function teksty(User $kto): array
    {
        return ShoppingListItem::query()->where('user_id', $kto->getKey())->orderBy('position')->pluck('text')->all();
    }

    public function test_wybrane_linie_trafiaja_na_liste_dokladnie_tak_jak_w_przepisie_w_kolejnosci_przepisu_z_pochodzeniem(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'), ['500 g mąki', ['2 jajka', 'Ciasto'], ['szczypta soli', 'Ciasto'], ['300 g twarogu', 'Farsz'], 'drożdże'], ['title' => 'Pierogi']);

        // Prośba w odwrotnej kolejności: liczy się kolejność ze strony przepisu (składniki bez grupy, potem grupy), nie żądania.
        $this->dopisz($ja, $przepis, $this->idy($przepis, 'drożdże', '2 jajka'))
            ->assertRedirect(route('shopping.index'))
            ->assertSessionHas('status', 'Dodane do listy zakupów: 2 wybrane składniki z przepisu „Pierogi”.');

        $this->assertSame(['drożdże', '2 jajka'], $this->teksty($ja));
        foreach (ShoppingListItem::query()->where('user_id', $ja->getKey())->get() as $pozycja) {
            $this->assertSame(ShoppingListItem::SOURCE_RECIPE, $pozycja->source);
            $this->assertSame($przepis->getKey(), $pozycja->recipe_id);
            $this->assertNull($pozycja->checked_at);
        }
        // Przepis źródłowy nietknięty.
        $this->assertSame(5, $przepis->ingredients()->count());
    }

    public function test_ekran_pokazuje_pola_wyboru_z_etykietami_w_grupach_novalidate_i_niczego_nie_dopisuje(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'), [['500 g mąki', 'Ciasto'], ['2 jajka', 'Ciasto'], ['300 g twarogu', 'Farsz']], ['title' => 'Pierogi']);

        $html = (string) $this->actingAs($ja)->get(route('shopping.recipe.pick', $przepis))->assertOk()->getContent();

        $this->assertStringContainsString('noindex', $html);
        $this->assertMatchesRegularExpression('~<form[^>]*novalidate[^>]*>~', $html);
        $this->assertSame(3, substr_count($html, 'name="skladniki[]"'));
        foreach (['500 g mąki', '2 jajka', '300 g twarogu'] as $tekst) {
            $this->assertStringContainsString('<span class="choice-label">'.$tekst.'</span>', $html);
        }
        $this->assertLessThan(strpos($html, '300 g twarogu'), strpos($html, '500 g mąki'));
        $this->assertStringContainsString('>Ciasto</h2>', $html);
        $this->assertStringContainsString('>Farsz</h2>', $html);
        $this->assertStringContainsString('name="odcisk" value="'.$this->odcisk($przepis).'"', $html);
        $this->assertStringContainsString('Dodaj wybrane', $html);
        $this->assertStringNotContainsString('name="potwierdzam"', $html);
        // Sam GET (i odświeżenie) niczego nie dopisuje.
        $this->actingAs($ja)->get(route('shopping.recipe.pick', $przepis));
        $this->assertSame(0, ShoppingListItem::query()->count());
    }

    public function test_przyciski_wyboru_stoja_na_stronie_przepisu_i_w_planerze_a_pelne_dodanie_dziala_jak_dotad(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'), ['2 jajka', 'sól'], ['title' => 'Zupa']);
        $wpis = new MealPlanEntry(['day' => '2026-10-01', 'recipe_id' => $przepis->getKey()]);
        $wpis->user_id = $ja->getKey();
        $wpis->save();

        $strona = (string) $this->actingAs($ja)->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();
        $this->assertStringContainsString('Dodaj składniki do listy zakupów', $strona);
        $this->assertStringContainsString('href="'.route('shopping.recipe.pick', $przepis).'"', $strona);

        $planer = (string) $this->actingAs($ja)->get(route('planer.show'))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('shopping.recipe.pick', ['recipe' => $przepis, 'z_planera' => 1]).'"', $planer);

        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug))->assertRedirect(route('shopping.index'));
        $this->assertSame(['2 jajka', 'sól'], $this->teksty($ja));
    }

    public function test_identyczne_teksty_roznych_linii_pozostaja_odrebnymi_liniami(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'), [['sól', 'Ciasto'], ['mąka', 'Ciasto'], ['sól', 'Posypka']]);

        $this->dopisz($ja, $przepis, $this->idy($przepis, 'sól', 'sól'))->assertSessionHas('status_rodzaj', 'sukces');

        $this->assertSame(['sól', 'sól'], $this->teksty($ja));
    }

    public function test_pusty_wybor_daje_instrukcje_przy_polu_i_w_podsumowaniu_i_niczego_nie_dopisuje(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'), ['2 jajka', 'sól']);
        $wybor = route('shopping.recipe.pick', $przepis);

        $this->actingAs($ja)->post(route('shopping.recipe.pick.store', $przepis), ['odcisk' => $this->odcisk($przepis)])
            ->assertRedirect($wybor)
            ->assertSessionHasErrors(['skladniki' => 'Zaznacz co najmniej jeden składnik i naciśnij „Dodaj wybrane”. Nic nie zostało dodane.']);

        $html = (string) $this->followRedirects($this->actingAs($ja)->post(route('shopping.recipe.pick.store', $przepis), ['skladniki' => [], 'odcisk' => $this->odcisk($przepis)]))->getContent();
        $this->assertStringContainsString('error-summary', $html);
        $this->assertStringContainsString('id="f-skladniki-error"', $html);
        $this->assertSame(0, ShoppingListItem::query()->count());
    }

    public function test_podmienione_id_skladnik_innego_przepisu_i_zly_format_nie_zapisuja_nic_ani_nie_ujawniaja_tresci(): void
    {
        $ja = $this->user('kupujaca');
        $autorka = $this->user('kucharka');
        $moj = $this->przepis($autorka, ['2 jajka', 'sól']);
        $inny = $this->przepis($autorka, ['tajny składnik innego przepisu']);
        $cudzeId = $this->idy($inny, 'tajny składnik innego przepisu');
        $wlasneId = $this->idy($moj, '2 jajka');

        // Składnik innego przepisu (poprawny UUID, zgodny odcisk).
        $this->dopisz($ja, $moj, [...$wlasneId, ...$cudzeId])->assertSessionHasErrors('skladniki');
        $this->assertSame(0, ShoppingListItem::query()->count(), 'Wszystko albo nic.');
        // Wymyślony UUID.
        $this->dopisz($ja, $moj, [Str::uuid()->toString()])->assertSessionHasErrors('skladniki');
        // Nie-UUID i tablica zamiast tekstu.
        $this->dopisz($ja, $moj, ['x'])->assertSessionHasErrors('skladniki.0');
        $this->dopisz($ja, $moj, [['a']])->assertSessionHasErrors();
        $this->assertSame(0, ShoppingListItem::query()->count());

        $html = (string) $this->followRedirects($this->dopisz($ja, $moj, $cudzeId))->getContent();
        $this->assertStringNotContainsString('tajny składnik innego przepisu', $html);
    }

    public function test_przepis_ktorego_osoba_nie_widzi_nie_daje_ekranu_ani_zapisu_i_nie_ujawnia_skladnikow(): void
    {
        $ja = $this->user('kupujaca');
        $prywatny = $this->przepis($this->user('kucharka'), ['tajny składnik'], ['visibility' => 'private']);
        $idy = $this->idy($prywatny, 'tajny składnik');

        $this->actingAs($ja)->get(route('shopping.recipe.pick', $prywatny))->assertForbidden();
        $this->dopisz($ja, $prywatny, $idy)->assertForbidden();
        $this->assertSame(0, ShoppingListItem::query()->count());

        auth()->logout();
        $this->get(route('shopping.recipe.pick', $prywatny))->assertRedirect(route('login'));
        $this->post(route('shopping.recipe.pick.store', $prywatny), ['skladniki' => $idy, 'odcisk' => str_repeat('a', 64)])->assertRedirect(route('login'));
    }

    public function test_utrata_dostepu_miedzy_podgladem_a_zapisem_nic_nie_dopisuje(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'), ['2 jajka', 'sól']);
        $idy = $this->idy($przepis, '2 jajka');
        $odcisk = $this->odcisk($przepis);

        $przepis->forceFill(['visibility' => 'private'])->save();

        $this->dopisz($ja, $przepis, $idy, $odcisk)->assertForbidden();
        $this->assertSame(0, ShoppingListItem::query()->count());
    }

    public function test_zmiana_przepisu_po_podgladzie_nie_podmienia_po_cichu_wybranej_tresci(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'), ['2 jajka', 'sól', 'mąka']);
        $idy = $this->idy($przepis, '2 jajka', 'sól');
        $odcisk = $this->odcisk($przepis);

        // Autor zmienia tekst wybranej linii, a potem dopisuje nową.
        $przepis->ingredients()->where('ingredient_text', '2 jajka')->update(['ingredient_text' => '6 jajek']);
        $this->dopisz($ja, $przepis, $idy, $odcisk)
            ->assertRedirect(route('shopping.recipe.pick', $przepis))
            ->assertSessionHas('status_rodzaj', 'blad');
        $this->assertStringContainsString('zmieniły się', (string) session('status'));
        $this->assertSame(0, ShoppingListItem::query()->count());

        // Ekran po zmianie pokazuje aktualną treść i zachowuje zaznaczenie linii, które nadal istnieją.
        $html = (string) $this->followRedirects($this->dopisz($ja, $przepis, $idy, $odcisk))->getContent();
        $this->assertStringContainsString('<span class="choice-label">6 jajek</span>', $html);
        $this->assertStringNotContainsString('2 jajka', $html);
        $this->assertSame(2, substr_count($html, ' checked'), 'Zaznaczenie wraca do ponownego sprawdzenia.');
        $this->assertStringContainsString('name="odcisk" value="'.$this->odcisk($przepis).'"', $html);

        // Usunięta linia i dołożona linia też unieważniają podgląd.
        $odcisk = $this->odcisk($przepis);
        $przepis->ingredients()->where('ingredient_text', 'sól')->delete();
        $this->dopisz($ja, $przepis, $idy, $odcisk)->assertSessionHas('status_rodzaj', 'blad');
        $odcisk = $this->odcisk($przepis);
        RecipeIngredient::create(['recipe_id' => $przepis->getKey(), 'ingredient_text' => 'pieprz', 'position' => 9]);
        $this->dopisz($ja, $przepis, $this->idy($przepis, 'mąka'), $odcisk)->assertSessionHas('status_rodzaj', 'blad');
        $this->assertSame(0, ShoppingListItem::query()->count());

        // Z nowym podglądem zapis przechodzi i kopiuje aktualny tekst.
        $this->dopisz($ja, $przepis, $this->idy($przepis, '6 jajek', 'pieprz'))->assertSessionHas('status_rodzaj', 'sukces');
        $this->assertSame(['6 jajek', 'pieprz'], $this->teksty($ja));
    }

    public function test_ponowne_dodanie_ostrzega_zachowuje_dokladnie_wybrany_podzbior_a_potwierdzenie_go_dopisuje(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'), ['2 jajka', 'sól', 'mąka'], ['title' => 'Zupa']);
        $this->dopisz($ja, $przepis, $this->idy($przepis, '2 jajka'))->assertSessionHas('status_rodzaj', 'sukces');
        $idy = $this->idy($przepis, 'sól', 'mąka');

        // Bez potwierdzenia: nic się nie dopisuje, wracamy na ekran z ostrzeżeniem i tym samym zaznaczeniem.
        $this->dopisz($ja, $przepis, $idy)->assertRedirect(route('shopping.recipe.pick', $przepis))->assertSessionHas('zakupy_wybor_juz_jest');
        $this->assertSame(['2 jajka'], $this->teksty($ja));

        $html = (string) $this->followRedirects($this->dopisz($ja, $przepis, $idy))->getContent();
        $this->assertStringContainsString('Składniki tego przepisu już są na liście', $html);
        $this->assertStringContainsString('Dodaj wybrane jeszcze raz', $html);
        $this->assertStringContainsString('name="potwierdzam" value="1"', $html);
        $this->assertSame(2, substr_count($html, ' checked'));
        $this->assertSame(['2 jajka'], $this->teksty($ja), 'Anulowanie i odświeżenie niczego nie dodają.');

        // Odświeżenie samego GET-a (bez flasha) pokazuje zwykły ekran bez ostrzeżenia i bez dopisywania.
        $zwykly = (string) $this->actingAs($ja)->get(route('shopping.recipe.pick', $przepis))->getContent();
        $this->assertStringNotContainsString('Składniki tego przepisu już są na liście', $zwykly);

        // Potwierdzenie dopisuje dokładnie wybrane linie, nie cały przepis.
        $this->dopisz($ja, $przepis, $idy, null, ['potwierdzam' => '1'])->assertSessionHas('status_rodzaj', 'sukces');
        $this->assertSame(['2 jajka', 'sól', 'mąka'], $this->teksty($ja));
        $this->assertSame(3, ShoppingListItem::query()->count());
    }

    public function test_limit_liczy_wybrany_podzbior_a_zapis_jest_wszystko_albo_nic(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'), ['2 jajka', 'sól', 'mąka', 'masło']);
        $maks = ListaZakupow::maksPozycji();

        $teraz = now();
        DB::table('shopping_list_items')->insert(array_map(fn (int $i): array => [
            'id' => (string) Str::uuid(), 'user_id' => $ja->getKey(), 'text' => 'pozycja '.$i, 'source' => 'manual',
            'position' => $i, 'created_at' => $teraz, 'updated_at' => $teraz,
        ], range(1, $maks - 1)));

        // Zostaje miejsce na jedną pozycję: dwie wybrane nie wchodzą — żadna.
        $this->dopisz($ja, $przepis, $this->idy($przepis, '2 jajka', 'sól'))
            ->assertRedirect(route('shopping.recipe.pick', $przepis))
            ->assertSessionHasErrors('skladniki');
        $this->assertSame($maks - 1, ShoppingListItem::query()->count(), 'Brak częściowego dopisania.');

        $html = (string) $this->followRedirects($this->dopisz($ja, $przepis, $this->idy($przepis, '2 jajka', 'sól')))->getContent();
        $this->assertStringContainsString('error-summary', $html);
        $this->assertStringContainsString('Lista zakupów mieści najwyżej '.$maks.' pozycji', $html);
        $this->assertSame(2, substr_count($html, ' checked'), 'Wybór nie ginie przy błędzie limitu.');

        // Jedna wybrana mieści się, mimo że cały przepis (4 linie) by nie wszedł.
        $this->dopisz($ja, $przepis, $this->idy($przepis, 'masło'))->assertSessionHas('status_rodzaj', 'sukces');
        $this->assertSame($maks, ShoppingListItem::query()->count());
    }

    public function test_wybor_dotyczy_wylacznie_zakupow_a_inne_dane_zostaja(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'), ['2 jajka', 'sól']);
        $ja->pantryItems()->create(['name' => 'mąka']);
        $wpis = new MealPlanEntry(['day' => '2026-10-01', 'recipe_id' => $przepis->getKey()]);
        $wpis->user_id = $ja->getKey();
        $wpis->save();
        $spizarniaPrzed = DB::table('pantry_items')->get()->all();
        $planPrzed = $wpis->refresh()->getAttributes();

        $this->dopisz($ja, $przepis, $this->idy($przepis, 'sól'));

        $this->assertEquals($spizarniaPrzed, DB::table('pantry_items')->get()->all());
        $this->assertSame($planPrzed, $wpis->refresh()->getAttributes());
        $this->assertSame(0, CookedEvent::query()->count());
        $this->assertSame(1, ShoppingListItem::query()->count());
    }

    public function test_z_planera_wracamy_do_planera_przy_rezygnacji_i_zachowujemy_parametr_przy_bledzie(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'), ['2 jajka', 'sól']);

        $html = (string) $this->actingAs($ja)->get(route('shopping.recipe.pick', ['recipe' => $przepis, 'z_planera' => 1]))->getContent();
        $this->assertStringContainsString('name="z_planera" value="1"', $html);
        $this->assertStringContainsString('href="'.route('planer.show').'"', $html);

        $this->dopisz($ja, $przepis, [], null, ['z_planera' => '1'])
            ->assertRedirect(route('shopping.recipe.pick', ['recipe' => $przepis, 'z_planera' => 1]));
    }

    public function test_przepis_bez_skladnikow_mowi_co_zrobic_a_zapis_niczego_nie_dopisuje(): void
    {
        $ja = $this->user('kupujaca');
        $pusty = $this->przepis($this->user('kucharka'), []);

        $html = (string) $this->actingAs($ja)->get(route('shopping.recipe.pick', $pusty))->assertOk()->getContent();
        $this->assertStringContainsString('Ten przepis nie ma jeszcze składników', $html);
        $this->assertStringNotContainsString('name="skladniki[]"', $html);

        $this->dopisz($ja, $pusty, [Str::uuid()->toString()], str_repeat('a', 64))
            ->assertSessionHas('status_rodzaj', 'informacja');
        $this->assertSame(0, ShoppingListItem::query()->count());
    }

    public function test_zapis_bierze_blokade_listy_przed_odczytem_skladnikow_do_kopiowania(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'), ['2 jajka', 'sól']);
        $idy = $this->idy($przepis, 'sól');
        $odcisk = $this->odcisk($przepis);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->dopisz($ja, $przepis, $idy, $odcisk);
        $zapytania = array_map(fn (array $q): string => strtolower($q['query']), DB::getQueryLog());
        DB::disableQueryLog();

        $blokada = null;
        $odczytPoBlokadzie = null;
        foreach ($zapytania as $i => $sql) {
            if ($blokada === null && str_contains($sql, 'from "users"') && str_contains($sql, 'for update')) {
                $blokada = $i;
            }
            if ($blokada !== null && $odczytPoBlokadzie === null && str_contains($sql, 'from "recipe_ingredients"')) {
                $odczytPoBlokadzie = $i;
            }
        }

        $this->assertNotNull($blokada, 'Brak blokady wiersza konta.');
        $this->assertNotNull($odczytPoBlokadzie, 'Składniki muszą być czytane na nowo po blokadzie, nie tylko przy podglądzie.');
    }
}
