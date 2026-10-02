<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Domain\Zakupy\ListaZakupow;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Poprawianie pozycji listy zakupów bez usuwania i ponownego dopisywania
 * (#2443, V2).
 *
 * Czas zamrożony na czwartek 1 października 2026. Pomiary idą przez HTTP
 * i końcowy HTML; akcja domenowa i migracja są wołane wprost tylko tam, gdzie
 * kryterium wymaga kontroli niezależnej od trasy.
 */
final class ListaZakupowPoprawkaTest extends TestCase
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

    /** @param  list<string>  $skladniki */
    private function przepis(User $autor, array $skladniki = ['2 jajka', '500 g mąki pszennej'], array $atrybuty = []): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            ...$atrybuty,
        ]);

        foreach ($skladniki as $i => $tekst) {
            RecipeIngredient::create(['recipe_id' => $przepis->getKey(), 'ingredient_text' => $tekst, 'position' => $i]);
        }

        return $przepis;
    }

    private function pozycja(User $kto, string $tekst, string $zrodlo = ShoppingListItem::SOURCE_MANUAL, ?Recipe $przepis = null, int $miejsce = 0): ShoppingListItem
    {
        $p = new ShoppingListItem(['text' => $tekst]);
        $p->user_id = $kto->getKey();
        $p->source = $zrodlo;
        $p->recipe_id = $przepis?->getKey();
        $p->position = $miejsce;
        $p->save();

        return $p;
    }

    private function bladTekstu(): string
    {
        $bledy = session('errors');
        $bag = $bledy instanceof ViewErrorBag
            ? $bledy->getBag('default')
            : new MessageBag((array) ($bledy['default']['messages'] ?? []));

        return (string) $bag->first('text');
    }

    private function popraw(User $kto, ShoppingListItem $pozycja, string $tekst, ?string $stan = null)
    {
        return $this->actingAs($kto)->patch(route('shopping.update', $pozycja), [
            'text' => $tekst,
            'stan' => $stan ?? ListaZakupow::znacznikTekstu($pozycja->refresh()),
        ]);
    }

    public function test_poprawka_recznej_pozycji_zachowuje_miejsce_odhaczenie_i_date_a_zmienia_tekst(): void
    {
        $ja = $this->user('kupujaca');
        $this->pozycja($ja, 'chleb', miejsce: 0);
        $mleko = $this->pozycja($ja, 'mleko', miejsce: 1);
        $this->pozycja($ja, 'masło', miejsce: 2);
        $mleko->forceFill(['checked_at' => '2026-09-30 12:00:00', 'created_at' => '2026-09-20 10:00:00', 'updated_at' => '2026-09-20 10:00:00'])->saveQuietly();
        $przed = $mleko->refresh()->getAttributes();

        $this->popraw($ja, $mleko, '  2   mleka ')
            ->assertRedirect(route('shopping.index').'#pozycja-'.$mleko->getKey())
            ->assertSessionHas('status', 'Pozycja poprawiona. Zostaje na swoim miejscu, z tym samym odhaczeniem.');

        $po = $mleko->refresh()->getAttributes();
        $this->assertSame('2 mleka', $po['text']);
        foreach (['id', 'user_id', 'source', 'recipe_id', 'position', 'checked_at', 'created_at'] as $kolumna) {
            $this->assertSame($przed[$kolumna], $po[$kolumna], "Kolumna {$kolumna} ma zostać bez zmian.");
        }
        $this->assertNotNull($po['edited_at']);
        $this->assertSame(
            ['chleb', '2 mleka', 'masło'],
            ShoppingListItem::query()->where('user_id', $ja->getKey())->orderBy('position')->pluck('text')->all(),
        );
        $this->assertSame(3, ShoppingListItem::query()->count(), 'Poprawka nie tworzy kopii.');
    }

    public function test_poprawiona_kopia_skladnika_zostaje_przy_przepisie_ale_mowi_ze_nie_jest_doslowna(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'), ['2 jajka', '500 g mąki'], ['title' => 'Zupa ogorkowa']);
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug));
        $jajka = ShoppingListItem::query()->where('text', '2 jajka')->sole();
        $maka = ShoppingListItem::query()->where('text', '500 g mąki')->sole();

        $this->popraw($ja, $jajka, '3 jajka')->assertSessionHas('status_rodzaj', 'sukces');

        $jajka->refresh();
        $this->assertSame(ShoppingListItem::SOURCE_RECIPE, $jajka->source);
        $this->assertSame($przepis->getKey(), $jajka->recipe_id);
        // Przepis źródłowy nietknięty — autor dalej ma „2 jajka”.
        $this->assertSame(['2 jajka', '500 g mąki'], $przepis->ingredients()->orderBy('position')->pluck('ingredient_text')->all());

        $html = (string) $this->actingAs($ja)->get(route('shopping.index'))->assertOk()->getContent();
        preg_match('~<li class="planer-pozycja" id="pozycja-'.$jajka->getKey().'">(.*?)</li>~s', $html, $poprawiona);
        preg_match('~<li class="planer-pozycja" id="pozycja-'.$maka->getKey().'">(.*?)</li>~s', $html, $doslowna);
        $this->assertStringContainsString('Tekst poprawiony przez Ciebie na tej liście', $poprawiona[1]);
        $this->assertStringContainsString('nie jest już dosłowna linia z przepisu', $poprawiona[1]);
        $this->assertStringContainsString('Z przepisu: <a', $poprawiona[1], 'Pochodzenie zostaje.');
        $this->assertStringNotContainsString('poprawiony', $doslowna[1], 'Niepoprawiona linia nie dostaje dopisku.');

        // Rozpoznanie nie polega na porównaniu z przepisem: autor zmienia składnik na tekst identyczny z poprawką.
        $przepis->ingredients()->where('ingredient_text', '2 jajka')->update(['ingredient_text' => '3 jajka']);
        $html = (string) $this->actingAs($ja)->get(route('shopping.index'))->getContent();
        $this->assertStringContainsString('Tekst poprawiony przez Ciebie', $html);
        $this->assertSame(2, ShoppingListItem::query()->where('recipe_id', $przepis->getKey())->count());

        // „Dodaj składniki” dalej ostrzega przed ponownym dodaniem tego przepisu.
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug))
            ->assertRedirect(route('shopping.recipe.confirm', ['recipe' => $przepis->slug]));
        $this->assertSame(2, ShoppingListItem::query()->count());
    }

    public function test_ekran_ma_etykiete_novalidate_obecny_tekst_i_przycisk_przy_kazdej_pozycji(): void
    {
        $ja = $this->user('kupujaca');
        $mleko = $this->pozycja($ja, 'mleko', miejsce: 0);
        $this->pozycja($ja, 'chleb', miejsce: 1)->forceFill(['checked_at' => now()])->save();

        $lista = (string) $this->actingAs($ja)->get(route('shopping.index'))->assertOk()->getContent();
        $this->assertSame(2, substr_count($lista, '>Popraw<span'), 'Przycisk przy pozycji do kupienia i odhaczonej.');
        $this->assertStringContainsString('href="'.route('shopping.edit', $mleko).'"', $lista);

        $html = (string) $this->actingAs($ja)->get(route('shopping.edit', $mleko))->assertOk()->getContent();
        $this->assertStringContainsString('value="mleko"', $html);
        $this->assertMatchesRegularExpression('~<form[^>]*novalidate[^>]*>~', $html);
        $this->assertStringContainsString('<label for="f-text">', $html);
        $this->assertStringContainsString('Najwyżej '.ListaZakupow::maksZnakow().' znaków.', $html);
        $this->assertStringContainsString('Anuluj, zostaw jak jest', $html);
        $this->assertStringContainsString('name="stan" value="'.ListaZakupow::znacznikTekstu($mleko).'"', $html);
    }

    public function test_cudza_pozycja_gosc_i_moderator_nie_zmieniaja_cudzej_listy_a_domena_traktuje_ja_jak_nieistniejaca(): void
    {
        $ja = $this->user('kupujaca');
        $moja = $this->pozycja($ja, 'tajna lista');
        $znacznik = ListaZakupow::znacznikTekstu($moja);

        foreach ([$this->user('obca'), $this->moderator()] as $obcy) {
            $this->popraw($obcy, $moja, 'podmienione', $znacznik)->assertForbidden();
            $this->actingAs($obcy)->get(route('shopping.edit', $moja))->assertForbidden();
        }
        auth()->logout();
        $this->patch(route('shopping.update', $moja), ['text' => 'podmienione', 'stan' => $znacznik])->assertRedirect(route('login'));
        $this->get(route('shopping.edit', $moja))->assertRedirect(route('login'));
        $this->assertSame('tajna lista', $moja->refresh()->text);

        $wynik = app(ListaZakupow::class)->popraw($this->user('obca2'), (string) $moja->getKey(), 'podmienione', $znacznik);
        $this->assertSame(ListaZakupow::POPRAWKA_BRAK, $wynik);
        $this->assertSame('tajna lista', $moja->refresh()->text);
        $this->assertNull($moja->edited_at);
    }

    public function test_pusty_za_dlugi_i_zly_typ_wracaja_przy_polu_z_wpisanym_tekstem_bez_cichego_obcinania(): void
    {
        $ja = $this->user('kupujaca');
        $mleko = $this->pozycja($ja, 'mleko');
        $dlugi = str_repeat('a', ListaZakupow::maksZnakow() + 1);

        $this->popraw($ja, $mleko, "  \n ")->assertRedirect(route('shopping.edit', $mleko))->assertSessionHasErrors('text');
        $this->popraw($ja, $mleko, $dlugi)->assertSessionHasErrors('text');
        $this->assertSame('Skróć wpis do '.ListaZakupow::maksZnakow().' znaków i zapisz jeszcze raz.', $this->bladTekstu());
        $this->actingAs($ja)->patch(route('shopping.update', $mleko), ['text' => ['x'], 'stan' => ListaZakupow::znacznikTekstu($mleko)])
            ->assertSessionHasErrors('text');
        $this->actingAs($ja)->patch(route('shopping.update', $mleko), ['text' => 'x'])->assertSessionHasErrors('stan');
        $this->assertSame('mleko', $mleko->refresh()->text);
        $this->assertNull($mleko->edited_at);

        $html = (string) $this->followRedirects($this->actingAs($ja)->patch(route('shopping.update', $mleko), [
            'text' => $dlugi, 'stan' => ListaZakupow::znacznikTekstu($mleko),
        ]))->getContent();
        $this->assertStringContainsString('error-summary', $html);
        $this->assertStringContainsString('field-error', $html);
        $this->assertStringContainsString('value="'.$dlugi.'"', $html, 'Wpisana poprawka nie ginie.');

        // Dokładnie na limicie jest dozwolone.
        $this->popraw($ja, $mleko, str_repeat('b', ListaZakupow::maksZnakow()))->assertSessionHas('status_rodzaj', 'sukces');
    }

    public function test_ten_sam_tekst_jest_bezpieczny_i_nie_oznacza_pozycji_jako_poprawionej(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'), ['2 jajka']);
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug));
        $jajka = ShoppingListItem::query()->sole();
        $updated = $jajka->updated_at;

        $this->popraw($ja, $jajka, '  2   jajka ')
            ->assertSessionHas('status', 'Ta pozycja ma już taki tekst. Nic nie zostało zmienione.');

        $this->assertNull($jajka->refresh()->edited_at, 'Niezmieniona linia z przepisu zostaje dosłowna.');
        $this->assertEquals($updated, $jajka->updated_at);
    }

    public function test_dwie_karty_nie_nadpisuja_nowszej_korekty_a_wpisany_tekst_zostaje_w_polu(): void
    {
        $ja = $this->user('kupujaca');
        $mleko = $this->pozycja($ja, 'mleko');
        $stary = ListaZakupow::znacznikTekstu($mleko);

        $this->popraw($ja, $mleko, '2 mleka', $stary)->assertSessionHas('status_rodzaj', 'sukces');

        $odpowiedz = $this->popraw($ja, $mleko, 'mleko 3,2%', $stary);
        // Najpierw dane: to one są istotą kontroli (kontrola ujemna #2443
        // oczekuje tego komunikatu), dopiero potem przekierowanie.
        $this->assertSame('2 mleka', $mleko->refresh()->text, 'Stara karta nie może nadpisać nowszej korekty.');
        $odpowiedz->assertRedirect(route('shopping.edit', $mleko))
            ->assertSessionHas('status_rodzaj', 'blad');
        $this->assertStringContainsString('innym oknie', (string) session('status'));

        $html = (string) $this->followRedirects($this->actingAs($ja)->patch(route('shopping.update', $mleko), [
            'text' => 'mleko 3,2%', 'stan' => $stary,
        ]))->getContent();
        $this->assertStringContainsString('<strong>2 mleka</strong>', $html);
        $this->assertStringContainsString('value="mleko 3,2%"', $html);
        $this->assertStringContainsString('name="stan" value="'.ListaZakupow::znacznikTekstu($mleko).'"', $html);

        $this->popraw($ja, $mleko, 'mleko 3,2%')->assertSessionHas('status_rodzaj', 'sukces');
        $this->assertSame('mleko 3,2%', $mleko->refresh()->text);
    }

    public function test_rownolegle_odhaczenie_nie_ginie_po_zapisie_tekstu_a_usunieta_pozycja_nie_powstaje_ponownie(): void
    {
        $ja = $this->user('kupujaca');
        $mleko = $this->pozycja($ja, 'mleko');
        $stale = ShoppingListItem::query()->findOrFail($mleko->getKey());

        // W innej karcie pozycja zostaje odhaczona, zanim ta zapisze tekst.
        $this->actingAs($ja)->patch(route('shopping.toggle', $mleko), ['odhaczona' => '1']);
        $this->assertNotNull($mleko->refresh()->checked_at);
        $this->assertNull($stale->checked_at, 'To jest przestarzały model z innej karty.');

        app(ListaZakupow::class)->popraw($ja, (string) $stale->getKey(), '2 mleka', ListaZakupow::znacznikTekstu($stale));
        $this->assertNotNull($mleko->refresh()->checked_at, 'Odhaczenie nie może zginąć po zapisie tekstu.');
        $this->assertSame('2 mleka', $mleko->text);

        // Pozycja usunięta w innej karcie nie powstaje ponownie.
        $this->actingAs($ja)->delete(route('shopping.destroy', $mleko));
        $wynik = app(ListaZakupow::class)->popraw($ja, (string) $mleko->getKey(), 'mleko znowu', ListaZakupow::znacznikTekstu($mleko));
        $this->assertSame(ListaZakupow::POPRAWKA_BRAK, $wynik);
        $this->assertSame(0, ShoppingListItem::query()->count());

        $this->actingAs($ja)->patch(route('shopping.update', $mleko), ['text' => 'mleko znowu', 'stan' => 'x'])
            ->assertNotFound();
    }

    public function test_niedostepne_zrodlo_nie_blokuje_poprawki_ani_nie_ujawnia_tytulu(): void
    {
        $ja = $this->user('kupujaca');
        $autorka = $this->user('kucharka');
        $przepis = $this->przepis($autorka, ['2 jajka'], ['title' => 'Sekretny bigos']);
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug));
        $jajka = ShoppingListItem::query()->sole();
        $przepis->forceFill(['visibility' => 'private'])->save();
        // Komunikat po dopisaniu składników (z tytułem, który osoba sama widziała) znika po jednym wyświetleniu.
        $this->actingAs($ja)->get(route('shopping.index'));

        $edycja = (string) $this->actingAs($ja)->get(route('shopping.edit', $jajka))->assertOk()->getContent();
        $this->assertStringNotContainsString('Sekretny bigos', $edycja);

        $this->popraw($ja, $jajka, '3 jajka')->assertSessionHas('status_rodzaj', 'sukces');
        $lista = (string) $this->actingAs($ja)->get(route('shopping.index'))->getContent();
        $this->assertStringContainsString('3 jajka', $lista);
        $this->assertStringContainsString('Przepis jest już niedostępny.', $lista);
        $this->assertStringContainsString('Tekst poprawiony przez Ciebie', $lista);
        $this->assertStringNotContainsString('Sekretny bigos', $lista);
        $this->assertSame($przepis->getKey(), $jajka->refresh()->recipe_id);
    }

    public function test_cofniecie_usuniecia_zachowuje_znacznik_poprawki(): void
    {
        $ja = $this->user('kupujaca');
        $mleko = $this->pozycja($ja, 'mleko');
        $this->popraw($ja, $mleko, '2 mleka');
        $edytowano = $mleko->refresh()->edited_at;
        $this->assertNotNull($edytowano);

        $this->actingAs($ja)->delete(route('shopping.destroy', $mleko));
        $this->actingAs($ja)->post(route('shopping.undo'))->assertSessionHas('status_rodzaj', 'sukces');

        $wrocila = ShoppingListItem::query()->sole();
        $this->assertSame('2 mleka', $wrocila->text);
        $this->assertNotNull($wrocila->edited_at, 'Cofnięte usunięcie nie może odwrócić zaakceptowanej korekty.');
        $this->assertTrue($edytowano->equalTo($wrocila->edited_at));
    }

    public function test_eksport_wydaje_aktualny_tekst_pochodzenie_i_znacznik_poprawki(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'), ['2 jajka', 'sól'], ['title' => 'Zupa ogorkowa']);
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug));
        $this->popraw($ja, ShoppingListItem::query()->where('text', '2 jajka')->sole(), '3 jajka');

        $lista = app(CollectUserExportData::class)->handle($ja, new ExportPhotoPlan($ja), now())['lista_zakupow'];

        $this->assertSame(['3 jajka', 'z_przepisu', true], [$lista[0]['pozycja'], $lista[0]['pochodzenie'], $lista[0]['tekst_poprawiony_przez_wlasciciela']]);
        $this->assertNotNull($lista[0]['poprawiono']);
        $this->assertSame(['sól', 'z_przepisu', false, null], [$lista[1]['pozycja'], $lista[1]['pochodzenie'], $lista[1]['tekst_poprawiony_przez_wlasciciela'], $lista[1]['poprawiono']]);
    }

    public function test_znacznik_poprawki_jest_poza_masowym_przypisaniem(): void
    {
        $this->assertNotContains('edited_at', (new ShoppingListItem)->getFillable());

        $this->expectException(MassAssignmentException::class);
        new ShoppingListItem(['text' => 'mleko', 'edited_at' => now()]);
    }

    public function test_cofniecie_migracji_odmawia_przy_poprawionych_pozycjach_i_przechodzi_bez_nich(): void
    {
        $migracja = require base_path('database/migrations/2026_10_05_143127_add_edited_at_to_shopping_list_items.php');
        $ja = $this->user('kupujaca');
        $mleko = $this->pozycja($ja, 'mleko');
        $this->popraw($ja, $mleko, '2 mleka');

        try {
            $migracja->down();
            $this->fail('Rollback skasował informację o poprawkach bez pytania.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SET edited_at = NULL', $e->getMessage());
        }
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('shopping_list_items', 'edited_at'));
        $this->assertNotNull($mleko->refresh()->edited_at);

        // Kontrola dodatnia: bez poprawek rollback przechodzi, a up() wraca.
        DB::table('shopping_list_items')->update(['edited_at' => null]);
        $migracja->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('shopping_list_items', 'edited_at'));
        $migracja->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('shopping_list_items', 'edited_at'));
    }

    public function test_zapis_bierze_blokade_konta_przed_odczytem_pozycji(): void
    {
        $ja = $this->user('kupujaca');
        $mleko = $this->pozycja($ja, 'mleko');
        $znacznik = ListaZakupow::znacznikTekstu($mleko);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->popraw($ja, $mleko, '2 mleka', $znacznik);
        $zapytania = array_map(fn (array $q): string => strtolower($q['query']), DB::getQueryLog());
        DB::disableQueryLog();

        $blokadaKonta = null;
        $odczytPozycji = null;
        foreach ($zapytania as $i => $sql) {
            if ($blokadaKonta === null && str_contains($sql, 'from "users"') && str_contains($sql, 'for update')) {
                $blokadaKonta = $i;
            }
            if ($odczytPozycji === null && str_contains($sql, 'from "shopping_list_items"') && str_contains($sql, 'for update')) {
                $odczytPozycji = $i;
            }
        }

        $this->assertNotNull($blokadaKonta, 'Brak blokady wiersza konta.');
        $this->assertNotNull($odczytPozycji, 'Pozycja musi być czytana pod blokadą.');
        $this->assertLessThan($odczytPozycji, $blokadaKonta);
    }
}
