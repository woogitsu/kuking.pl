<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lista zakupów — etap 2 z #27 (decyzja właściciela z 29.09.2026, D-333).
 *
 * Prywatna lista konta: ręczne dopisanie, odhaczanie, usuwanie, „Dodaj
 * składniki” (kopia ORYGINALNYCH linii, bez sumowania) z przepisu i z planera,
 * ostrzeżenie przy ponownym dodaniu, „Wyczyść odhaczone”.
 *
 * Każdy pomiar idzie przez HTTP i końcowy HTML (albo przez prawdziwą paczkę
 * danych i prawdziwą akcję wymazania), nie przez wołanie akcji obok ekranu.
 * Czas zamrożony na czwartek 1 października 2026.
 */
final class ListaZakupowTest extends TestCase
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
        putenv('KUKING_ROLLBACK_KASUJE_LISTE_ZAKUPOW');
        parent::tearDown();
    }

    /** @param  list<string>  $skladniki */
    private function przepis(User $autor, array $skladniki = ['2 jajka', '500 g mąki pszennej', 'szczypta soli'], array $atrybuty = []): Recipe
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

    /** @return list<string> */
    private function teksty(User $kto): array
    {
        return ShoppingListItem::query()->where('user_id', $kto->getKey())->orderBy('position')->pluck('text')->all();
    }

    public function test_gosc_nie_wchodzi_na_liste(): void
    {
        $this->get(route('shopping.index'))->assertRedirect(route('login'));
        $this->post(route('shopping.store'), ['text' => 'mleko'])->assertRedirect(route('login'));
        $przepis = $this->przepis($this->user('kucharka'));
        $this->post(route('shopping.recipe.store', $przepis->slug))->assertRedirect(route('login'));
        $this->assertSame(0, ShoppingListItem::query()->count());
    }

    public function test_pusta_lista_mowi_co_zrobic_i_jest_poza_indeksem(): void
    {
        $html = (string) $this->actingAs($this->user('kupujaca'))->get(route('shopping.index'))->assertOk()->getContent();

        $this->assertStringContainsString('noindex', $html);
        $this->assertStringContainsString('Lista zakupów jest jeszcze pusta', $html);
        $this->assertStringContainsString('Co trzeba kupić?', $html);
    }

    public function test_reczne_dopisanie_stoi_na_liscie_z_oznaczeniem_recznej(): void
    {
        $ja = $this->user('kupujaca');

        $this->actingAs($ja)->post(route('shopping.store'), ['text' => '  papier   toaletowy '])
            ->assertRedirect(route('shopping.index').'#dopisz')
            ->assertSessionHas('status');

        $this->assertSame(['papier toaletowy'], $this->teksty($ja));
        $pozycja = ShoppingListItem::query()->sole();
        $this->assertSame([ShoppingListItem::SOURCE_MANUAL, null], [$pozycja->source, $pozycja->recipe_id]);

        $html = (string) $this->actingAs($ja)->get(route('shopping.index'))->getContent();
        $this->assertStringContainsString('papier toaletowy', $html);
        $this->assertStringContainsString('Dopisane ręcznie', $html);
    }

    public function test_puste_i_za_dlugie_dopisanie_wraca_z_bledem_po_polsku_przy_polu_i_w_podsumowaniu(): void
    {
        $ja = $this->user('kupujaca');

        $this->actingAs($ja)->from(route('shopping.index'))->post(route('shopping.store'), ['text' => '   '])
            ->assertRedirect(route('shopping.index'))
            ->assertSessionHasErrors(['text' => 'Wpisz, co trzeba kupić, np. „mleko” albo „2 cebule”.']);

        $this->actingAs($ja)->from(route('shopping.index'))->post(route('shopping.store'), ['text' => str_repeat('a', 241)])
            ->assertSessionHasErrors(['text' => 'Skróć wpis do 240 znaków i dopisz jeszcze raz.']);

        $this->assertSame(0, ShoppingListItem::query()->count());

        // Wpisany tekst nie znika po błędzie (old()).
        $html = (string) $this->actingAs($ja)->withSession(['_old_input' => ['text' => str_repeat('b', 300)]])
            ->get(route('shopping.index'))->getContent();
        $this->assertStringContainsString(str_repeat('b', 300), $html);
    }

    public function test_lista_ma_limit_pozycji_i_mowi_co_zrobic(): void
    {
        config(['kuking.zakupy.pozycji_max' => 3]);
        $ja = $this->user('kupujaca');
        foreach (['a', 'b', 'c'] as $i => $t) {
            $this->pozycja($ja, $t, miejsce: $i);
        }

        $this->actingAs($ja)->from(route('shopping.index'))->post(route('shopping.store'), ['text' => 'd'])
            ->assertSessionHasErrors('text');
        $blad = session('errors')->first('text');
        $this->assertStringContainsString('najwyżej 3 pozycji', $blad);
        $this->assertStringContainsString('Wyczyść odhaczone', $blad);

        $przepis = $this->przepis($this->user('kucharka'));
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug))->assertSessionHasErrors('text');

        $this->assertSame(3, ShoppingListItem::query()->count(), 'Przekroczenie limitu nie może dopisać nawet części pozycji.');
    }

    public function test_dodaj_skladniki_kopiuje_oryginalne_linie_bez_sumowania_z_oznaczeniem_przepisu(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'), ['2 jajka', '3 jajka', '500 g mąki pszennej', 'szczypta soli'], ['title' => 'Naleśniki babci']);
        $this->pozycja($ja, 'mleko', miejsce: 0);

        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug))
            ->assertRedirect(route('shopping.index'))
            ->assertSessionHas('status', 'Dodane do listy zakupów: 4 składniki z przepisu „Naleśniki babci”.');

        // Dokładnie cztery linie, w kolejności przepisu, „2 jajka” i „3 jajka” NIE są zsumowane.
        $this->assertSame(['mleko', '2 jajka', '3 jajka', '500 g mąki pszennej', 'szczypta soli'], $this->teksty($ja));
        $skopiowane = ShoppingListItem::query()->where('source', ShoppingListItem::SOURCE_RECIPE)->get();
        $this->assertCount(4, $skopiowane);
        $this->assertSame([$przepis->getKey()], $skopiowane->pluck('recipe_id')->unique()->values()->all());

        $html = (string) $this->actingAs($ja)->get(route('shopping.index'))->getContent();
        $this->assertStringContainsString('Z przepisu: <a href="'.route('recipes.show', $przepis->slug).'">Naleśniki babci</a>', $html);
        $this->assertStringContainsString('Dopisane ręcznie', $html);
    }

    public function test_strona_przepisu_ma_przycisk_dla_zalogowanego_a_gosc_go_nie_widzi(): void
    {
        $przepis = $this->przepis($this->user('kucharka'));

        $this->get(route('recipes.show', $przepis->slug))->assertOk()->assertDontSee('Dodaj składniki do listy zakupów');
        $this->actingAs($this->user('kupujaca'))->get(route('recipes.show', $przepis->slug))->assertOk()
            ->assertSee('Dodaj składniki do listy zakupów')
            ->assertSee(route('shopping.recipe.store', $przepis->slug), escape: false);
    }

    public function test_ponowne_dodanie_tego_samego_przepisu_najpierw_ostrzega_i_dopisuje_po_potwierdzeniu(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'), ['2 jajka', 'sól'], ['title' => 'Jajecznica']);

        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug));
        $this->assertCount(2, $this->teksty($ja));

        // Drugi raz bez potwierdzenia: nic nie przybywa, człowiek dostaje pytanie.
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug))
            ->assertRedirect(route('shopping.recipe.confirm', $przepis->slug));
        $this->assertCount(2, $this->teksty($ja));

        $html = (string) $this->actingAs($ja)->get(route('shopping.recipe.confirm', $przepis->slug))->assertOk()->getContent();
        $this->assertStringContainsString('Te składniki już są na liście', $html);
        $this->assertStringContainsString('Jajecznica', $html);
        $this->assertStringContainsString('1 października', $html);
        $this->assertStringContainsString('name="potwierdzam" value="1"', $html);
        $this->assertStringContainsString('noindex', $html);

        // Odświeżenie ekranu pytania niczego nie dopisuje (GET).
        $this->assertCount(2, $this->teksty($ja));

        // Po potwierdzeniu — dopisane drugi raz, bez łączenia.
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug), ['potwierdzam' => '1'])
            ->assertRedirect(route('shopping.index'));
        $this->assertSame(['2 jajka', 'sól', '2 jajka', 'sól'], $this->teksty($ja));
    }

    public function test_ostrzezenie_dotyczy_tego_samego_przepisu_nie_innego_ani_cudzej_listy(): void
    {
        $ja = $this->user('kupujaca');
        $inna = $this->user('inna');
        $autorka = $this->user('kucharka');
        $pierwszy = $this->przepis($autorka, ['mąka']);
        $drugi = $this->przepis($autorka, ['cukier']);

        $this->actingAs($inna)->post(route('shopping.recipe.store', $pierwszy->slug));
        $this->actingAs($ja)->post(route('shopping.recipe.store', $pierwszy->slug))->assertRedirect(route('shopping.index'));
        $this->actingAs($ja)->post(route('shopping.recipe.store', $drugi->slug))->assertRedirect(route('shopping.index'));

        $this->assertSame(['mąka', 'cukier'], $this->teksty($ja));
        $this->assertSame(['mąka'], $this->teksty($inna));
    }

    public function test_confirm_bez_wczesniejszego_dodania_wraca_do_przepisu(): void
    {
        $przepis = $this->przepis($this->user('kucharka'));

        $this->actingAs($this->user('kupujaca'))->get(route('shopping.recipe.confirm', $przepis->slug))
            ->assertRedirect(route('recipes.show', $przepis->slug));
    }

    public function test_przepis_bez_skladnikow_mowi_ze_nie_ma_czego_dodac(): void
    {
        $ja = $this->user('kupujaca');
        $pusty = $this->przepis($this->user('kucharka'), []);

        $this->actingAs($ja)->from(route('recipes.show', $pusty->slug))->post(route('shopping.recipe.store', $pusty->slug))
            ->assertRedirect(route('recipes.show', $pusty->slug))
            ->assertSessionHas('status', 'Ten przepis nie ma jeszcze składników, więc nie ma czego dodać do listy zakupów.');
        $this->assertSame(0, ShoppingListItem::query()->count());
    }

    public function test_nie_da_sie_dodac_skladnikow_przepisu_ktorego_sie_nie_widzi(): void
    {
        $ja = $this->user('kupujaca');
        $prywatny = $this->przepis($this->user('kucharka'), ['tajny składnik'], ['visibility' => 'private']);

        $this->actingAs($ja)->post(route('shopping.recipe.store', $prywatny->slug))->assertForbidden();
        $this->actingAs($ja)->get(route('shopping.recipe.confirm', $prywatny->slug))->assertForbidden();
        $this->assertSame(0, ShoppingListItem::query()->count());

        // Kontrola dodatnia: autor swój prywatny przepis dodaje.
        $this->actingAs($prywatny->author)->post(route('shopping.recipe.store', $prywatny->slug))->assertRedirect(route('shopping.index'));
        $this->assertSame(['tajny składnik'], $this->teksty($prywatny->author));
    }

    public function test_dodaj_skladniki_z_planera_kopiuje_linie_i_wraca_z_ostrzezeniem_do_planera(): void
    {
        $ja = $this->user('planujaca');
        $przepis = $this->przepis($this->user('kucharka'), ['ziemniaki', 'twaróg'], ['title' => 'Pierogi ruskie']);
        $wpis = new MealPlanEntry(['day' => '2026-10-02', 'recipe_id' => $przepis->getKey()]);
        $wpis->user_id = $ja->getKey();
        $wpis->save();

        $planer = (string) $this->actingAs($ja)->get(route('planer.show'))->assertOk()->getContent();
        $this->assertStringContainsString('Dodaj składniki', $planer);
        $this->assertStringContainsString('action="'.route('shopping.recipe.store', $przepis->slug).'"', $planer);
        $this->assertStringContainsString('name="z_planera" value="1"', $planer);

        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug), ['z_planera' => '1'])
            ->assertRedirect(route('shopping.index'));
        $this->assertSame(['ziemniaki', 'twaróg'], $this->teksty($ja));

        // Drugi raz z planera: pytanie zna drogę powrotną do planera.
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug), ['z_planera' => '1'])
            ->assertRedirect(route('shopping.recipe.confirm', ['recipe' => $przepis->slug, 'z_planera' => 1]));
        $pytanie = (string) $this->actingAs($ja)->get(route('shopping.recipe.confirm', ['recipe' => $przepis->slug, 'z_planera' => 1]))->getContent();
        $this->assertStringContainsString('name="z_planera" value="1"', $pytanie);
        $this->assertStringContainsString('href="'.route('planer.show').'"', $pytanie);
    }

    public function test_plan_bez_przycisku_dla_przepisu_niedostepnego_i_wlasnego_wpisu(): void
    {
        $ja = $this->user('planujaca');
        $przepis = $this->przepis($this->user('kucharka'), ['mąka'], ['title' => 'Schowany sernik']);
        foreach ([['recipe_id' => $przepis->getKey()], ['label' => 'obiad u mamy']] as $atrybuty) {
            $wpis = new MealPlanEntry(['day' => '2026-10-02', ...$atrybuty]);
            $wpis->user_id = $ja->getKey();
            $wpis->save();
        }
        $przepis->forceFill(['visibility' => 'private'])->save();

        $planer = (string) $this->actingAs($ja)->get(route('planer.show'))->getContent();

        $this->assertStringNotContainsString(route('shopping.recipe.store', $przepis->slug), $planer);
        $this->assertStringNotContainsString('name="z_planera"', $planer);
        $this->assertStringNotContainsString('Schowany sernik', $planer);
    }

    public function test_odhaczanie_przenosi_pozycje_do_odhaczonych_i_da_sie_cofnac(): void
    {
        $ja = $this->user('kupujaca');
        $mleko = $this->pozycja($ja, 'mleko', miejsce: 0);
        $this->pozycja($ja, 'chleb', miejsce: 1);

        $this->actingAs($ja)->patch(route('shopping.toggle', $mleko), ['odhaczona' => '1'])
            ->assertRedirect(route('shopping.index').'#pozycja-'.$mleko->getKey());
        $this->assertNotNull($mleko->fresh()->checked_at);

        $html = (string) $this->actingAs($ja)->get(route('shopping.index'))->getContent();
        $this->assertMatchesRegularExpression('~Do kupienia \(1\).*chleb.*Odhaczone \(1\).*mleko~s', $html);
        $this->assertStringContainsString('Cofnij odhaczenie', $html);

        // Ponowne odhaczenie niczego nie zmienia (ustawienie, nie przełącznik).
        $kiedy = $mleko->fresh()->checked_at;
        $this->actingAs($ja)->patch(route('shopping.toggle', $mleko), ['odhaczona' => '1']);
        $this->assertEquals($kiedy, $mleko->fresh()->checked_at);

        $this->actingAs($ja)->patch(route('shopping.toggle', $mleko), ['odhaczona' => '0']);
        $this->assertNull($mleko->fresh()->checked_at);
    }

    public function test_odhaczenie_bez_wartosci_mowi_co_zrobic(): void
    {
        $ja = $this->user('kupujaca');
        $mleko = $this->pozycja($ja, 'mleko');

        $this->actingAs($ja)->from(route('shopping.index'))->patch(route('shopping.toggle', $mleko), [])
            ->assertSessionHasErrors(['odhaczona' => 'Nie wiemy, co zrobić z tą pozycją. Wróć do listy zakupów i spróbuj jeszcze raz.']);
        $this->assertNull($mleko->fresh()->checked_at);
    }

    public function test_usuniecie_pozycji_i_cudza_pozycja_nietykalna(): void
    {
        $ja = $this->user('kupujaca');
        $obca = $this->user('obca');
        $moja = $this->pozycja($ja, 'tajna lista');

        $this->actingAs($obca)->get(route('shopping.index'))->assertOk()->assertDontSee('tajna lista');
        $this->actingAs($obca)->patch(route('shopping.toggle', $moja), ['odhaczona' => '1'])->assertForbidden();
        $this->actingAs($obca)->delete(route('shopping.destroy', $moja))->assertForbidden();
        $this->assertModelExists($moja);
        $this->assertNull($moja->fresh()->checked_at);

        $this->actingAs($ja)->delete(route('shopping.destroy', $moja))
            ->assertRedirect(route('shopping.index'))
            ->assertSessionHas('status', 'Usunięte z listy zakupów: tajna lista.');
        $this->assertModelMissing($moja);
    }

    public function test_wyczysc_odhaczone_kasuje_tylko_odhaczone_tylko_swoje(): void
    {
        $ja = $this->user('kupujaca');
        $obca = $this->user('obca');
        $kupione = $this->pozycja($ja, 'kupione', miejsce: 0);
        $this->pozycja($ja, 'jeszcze nie', miejsce: 1);
        $cudzeKupione = $this->pozycja($obca, 'cudze kupione');
        $kupione->forceFill(['checked_at' => now()])->save();
        $cudzeKupione->forceFill(['checked_at' => now()])->save();

        $html = (string) $this->actingAs($ja)->get(route('shopping.index'))->getContent();
        $this->assertStringContainsString('Wyczyść odhaczone', $html);
        $this->assertStringContainsString('Tak, usuń odhaczone', $html);

        $this->actingAs($ja)->delete(route('shopping.clear'))
            ->assertRedirect(route('shopping.index'))
            ->assertSessionHas('status', 'Usunięte odhaczone pozycje: 1.');

        $this->assertSame(['jeszcze nie'], $this->teksty($ja));
        $this->assertSame(['cudze kupione'], $this->teksty($obca));

        // Drugi raz: nic do usunięcia — informacja, nie błąd.
        $this->actingAs($ja)->delete(route('shopping.clear'))
            ->assertSessionHas('status', 'Nie ma odhaczonych pozycji do usunięcia.');
    }

    public function test_przepis_ktory_przestal_byc_widoczny_zostawia_pozycje_jako_sam_tekst(): void
    {
        $ja = $this->user('kupujaca');
        $autorka = $this->user('kucharka');
        $zawezony = $this->przepis($autorka, ['mąka z sekretnego'], ['title' => 'Sekretny bigos']);
        $usuniety = $this->przepis($autorka, ['mąka z usuniętego'], ['title' => 'Kasza skasowana miekko']);
        $zniszczony = $this->przepis($autorka, ['mąka ze zniszczonego'], ['title' => 'Kasza skasowana twardo']);
        $widoczny = $this->przepis($autorka, ['mąka z widocznego'], ['title' => 'Zupa ogorkowa']);
        $blokujaca = $this->user('blokujaca');
        $zablokowany = $this->przepis($blokujaca, ['mąka od blokującej'], ['title' => 'Placki od blokujacej']);

        foreach ([$zawezony, $usuniety, $zniszczony, $widoczny, $zablokowany] as $przepis) {
            $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug));
        }

        $zawezony->forceFill(['visibility' => 'private'])->save();
        $blokujaca->blocking()->attach($ja->getKey(), ['created_at' => now()]);
        $usuniety->delete();
        $zniszczony->forceDelete();

        // Pierwsze wejście zużywa komunikat po ostatnim dodaniu (nosi tytuł
        // przepisu z chwili, gdy jeszcze był widoczny).
        $this->actingAs($ja)->get(route('shopping.index'));
        $html = (string) $this->actingAs($ja)->get(route('shopping.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Zupa ogorkowa', $html);
        foreach (['Sekretny bigos', 'Kasza skasowana miekko', 'Kasza skasowana twardo', 'Placki od blokujacej'] as $tytul) {
            $this->assertStringNotContainsString($tytul, $html);
        }
        // Tekst pozycji zostaje we wszystkich pięciu przypadkach.
        foreach (['mąka z sekretnego', 'mąka z usuniętego', 'mąka ze zniszczonego', 'mąka z widocznego', 'mąka od blokującej'] as $tekst) {
            $this->assertStringContainsString($tekst, $html);
        }
        $this->assertSame(3, substr_count($html, 'Przepis jest już niedostępny.'));
        $this->assertSame(1, substr_count($html, 'Przepis został usunięty.'));
        $this->assertSame(5, ShoppingListItem::query()->count(), 'Lista nie może tracić pozycji, gdy przepis znika.');
        $this->assertSame('recipe', ShoppingListItem::query()->where('text', 'mąka ze zniszczonego')->sole()->source, 'Pozycja po twardym usunięciu przepisu wciąż wie, że pochodziła z przepisu.');

        // Ponowne dodanie zniszczonego przepisu jest niemożliwe (404), ukrytego — 403.
        $this->actingAs($ja)->post(route('shopping.recipe.store', $zawezony->slug))->assertForbidden();
    }

    public function test_lista_jest_w_paczce_danych_bez_tytulow_niedostepnych_przepisow(): void
    {
        $ja = $this->user('kupujaca');
        $autorka = $this->user('kucharka');
        $widoczny = $this->przepis($autorka, ['jajka'], ['title' => 'Zupa ogorkowa']);
        $schowany = $this->przepis($autorka, ['śmietana'], ['title' => 'Schowany sernik']);
        $this->actingAs($ja)->post(route('shopping.recipe.store', $widoczny->slug));
        $this->actingAs($ja)->post(route('shopping.recipe.store', $schowany->slug));
        $this->actingAs($ja)->post(route('shopping.store'), ['text' => 'papier']);
        $schowany->forceFill(['visibility' => 'private'])->save();
        $odhaczona = ShoppingListItem::query()->where('text', 'jajka')->sole();
        $odhaczona->forceFill(['checked_at' => now()])->save();

        $paczka = app(CollectUserExportData::class)->handle($ja, new ExportPhotoPlan($ja), now());
        $lista = $paczka['lista_zakupow'];

        $this->assertCount(3, $lista);
        $this->assertSame(['jajka', 'z_przepisu', 'Zupa ogorkowa', false, true], [
            $lista[0]['pozycja'], $lista[0]['pochodzenie'], $lista[0]['przepis'], $lista[0]['przepis_niedostepny'], $lista[0]['odhaczona'],
        ]);
        $this->assertSame(['śmietana', null, true], [$lista[1]['pozycja'], $lista[1]['przepis'], $lista[1]['przepis_niedostepny']]);
        $this->assertSame(['papier', 'reczna', false], [$lista[2]['pozycja'], $lista[2]['pochodzenie'], $lista[2]['przepis_niedostepny']]);
        $this->assertStringNotContainsString('Schowany sernik', json_encode($paczka, JSON_UNESCAPED_UNICODE));
    }

    public function test_wymazanie_konta_kasuje_liste_tylko_tej_osoby(): void
    {
        $odchodzi = $this->user('odchodzi', ['status' => User::STATUS_PENDING_DELETE, 'delete_requested_at' => now()->subDays(40)]);
        $zostaje = $this->user('zostaje');
        $this->pozycja($odchodzi, 'lista do skasowania');
        $this->pozycja($zostaje, 'lista zostaje');

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $this->assertSame(['lista zostaje'], ShoppingListItem::query()->pluck('text')->all());
    }

    public function test_cofniecie_migracji_odmawia_przy_listach_ludzi_i_przechodzi_na_pustej(): void
    {
        $migracja = require base_path('database/migrations/2026_09_30_090000_create_shopping_list_items_table.php');
        $this->pozycja($this->user('kupujaca'), 'mleko');

        try {
            $migracja->down();
            $this->fail('Rollback skasował listy zakupów ludzi bez pytania.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Liczba pozycji, które znikną: 1.', $e->getMessage());
            $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_LISTE_ZAKUPOW=1', $e->getMessage());
        }
        $this->assertSame(1, DB::table('shopping_list_items')->count());

        // Kontrola dodatnia: świadome wymuszenie przechodzi, a pusta tabela — bez pytania.
        putenv('KUKING_ROLLBACK_KASUJE_LISTE_ZAKUPOW=1');
        $migracja->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('shopping_list_items'));
        putenv('KUKING_ROLLBACK_KASUJE_LISTE_ZAKUPOW');

        $migracja->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('shopping_list_items'));
        $migracja->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('shopping_list_items'), 'Na pustej tabeli rollback ma przechodzić bez pytania.');
        $migracja->up();
    }

    public function test_baza_pilnuje_zrodla_pustego_tekstu_i_przepisu_przy_recznej(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'));

        foreach ([
            ['text' => 'mleko', 'source' => 'inne', 'recipe_id' => null],
            ['text' => '   ', 'source' => 'manual', 'recipe_id' => null],
            ['text' => 'mleko', 'source' => 'manual', 'recipe_id' => $przepis->getKey()],
        ] as $wiersz) {
            try {
                DB::transaction(fn () => DB::table('shopping_list_items')->insert([
                    'user_id' => $ja->getKey(), 'position' => 0, ...$wiersz,
                    'created_at' => now(), 'updated_at' => now(),
                ]));
                $this->fail('Baza przyjęła wiersz, który łamie CHECK: '.json_encode($wiersz));
            } catch (QueryException $e) {
                $this->assertStringContainsString('shopping_list_items_', $e->getMessage());
            }
        }
    }

    public function test_klucz_obcy_przepisu_zostawia_pozycje_po_twardym_usunieciu_przepisu(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'));
        $pozycja = $this->pozycja($ja, '2 jajka', ShoppingListItem::SOURCE_RECIPE, $przepis);

        $przepis->forceDelete();

        $this->assertNull($pozycja->fresh()->recipe_id);
        $this->assertSame('2 jajka', $pozycja->fresh()->text);
    }

    public function test_masowe_przypisanie_nie_ustawi_wlasciciela_zrodla_przepisu_ani_odhaczenia(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'));
        $pozycja = new ShoppingListItem;

        try {
            $pozycja->fill([
                'text' => 'mleko',
                'user_id' => $ja->getKey(),
                'source' => ShoppingListItem::SOURCE_RECIPE,
                'recipe_id' => $przepis->getKey(),
                'position' => 5,
                'checked_at' => now(),
            ]);
        } catch (MassAssignmentException) {
            // Tryb ścisły (testy, lokalnie) odmawia głośno; poza nim pola giną po cichu.
        }

        $this->assertSame([null, null, null, null, null], [
            $pozycja->user_id, $pozycja->source, $pozycja->recipe_id, $pozycja->position, $pozycja->checked_at,
        ]);
    }
}
