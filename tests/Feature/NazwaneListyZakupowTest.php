<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Domain\Zakupy\ListaZakupow;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\ShoppingListUndo;
use App\Models\User;
use App\Support\Komunikat;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Osobne, nazwane listy zakupów (#2528, V2, D-333 — paczka E).
 *
 * Lista domyślna („Na co dzień”) to pozycje bez `list_id`; nazwane listy to
 * wiersze `shopping_lists`. Każdy pomiar idzie przez HTTP i końcowy HTML (albo
 * prawdziwą paczkę danych i akcję wymazania). Czas zamrożony.
 */
final class NazwaneListyZakupowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        putenv('KUKING_ROLLBACK_KASUJE_LISTY_ZAKUPOW');
        putenv('KUKING_ROLLBACK_SCALA_LISTY_ZAKUPOW');
        parent::tearDown();
    }

    private function lista(User $kto, string $nazwa): ShoppingList
    {
        $lista = new ShoppingList(['name' => $nazwa]);
        $lista->user_id = $kto->getKey();
        $lista->save();

        return $lista;
    }

    private function pozycja(User $kto, string $tekst, ?ShoppingList $lista = null, int $miejsce = 0): ShoppingListItem
    {
        $p = new ShoppingListItem(['text' => $tekst]);
        $p->user_id = $kto->getKey();
        $p->source = ShoppingListItem::SOURCE_MANUAL;
        $p->position = $miejsce;
        $p->list_id = $lista?->getKey();
        $p->save();

        return $p;
    }

    /** @param  list<string>  $skladniki */
    private function przepis(User $autor, array $skladniki = ['2 jajka', 'szczypta soli']): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);

        foreach ($skladniki as $i => $tekst) {
            RecipeIngredient::create(['recipe_id' => $przepis->getKey(), 'ingredient_text' => $tekst, 'position' => $i]);
        }

        return $przepis;
    }

    /** @return list<string> */
    private function teksty(User $kto, ?ShoppingList $lista): array
    {
        return ShoppingListItem::query()
            ->where('user_id', $kto->getKey())
            ->when($lista === null, fn ($q) => $q->whereNull('list_id'), fn ($q) => $q->where('list_id', $lista?->getKey()))
            ->orderBy('position')->orderBy('id')->pluck('text')->all();
    }

    public function test_dotychczasowe_pozycje_sa_na_liscie_domyslnej_a_nazwana_ich_nie_miesza(): void
    {
        $ja = $this->user('kupujaca');
        $swieta = $this->lista($ja, 'Święta');
        $this->pozycja($ja, 'twaróg');
        $this->pozycja($ja, 'karp', $swieta, 1);

        $domyslna = (string) $this->actingAs($ja)->get(route('shopping.index'))->assertOk()->getContent();
        $this->assertStringContainsString('twaróg', $domyslna);
        $this->assertStringNotContainsString('karp', $domyslna, 'LISTY_2528_POZYCJE_NIE_MIESZAJA_SIE: lista domyślna pokazuje pozycję nazwanej listy.');
        $this->assertStringContainsString('Wybrana lista: Na co dzień', $domyslna);
        $this->assertStringContainsString('Dopisujesz do listy: <strong>Na co dzień</strong>', $domyslna);

        $nazwana = (string) $this->actingAs($ja)->get(route('shopping.index', ['lista' => $swieta->getKey()]))->assertOk()->getContent();
        $this->assertStringContainsString('karp', $nazwana);
        $this->assertStringNotContainsString('twaróg', $nazwana, 'LISTY_2528_POZYCJE_NIE_MIESZAJA_SIE: nazwana lista pokazuje pozycję listy domyślnej.');
        $this->assertStringContainsString('Wybrana lista: Święta', $nazwana);
        $this->assertStringContainsString('Dopisujesz do listy: <strong>Święta</strong>', $nazwana);
        $this->assertStringContainsString('aria-current="page"', $nazwana);
    }

    public function test_kto_ma_tylko_liste_domyslna_widzi_ekran_jak_dotad(): void
    {
        $ja = $this->user('kupujaca');
        $this->pozycja($ja, 'mleko');

        $html = (string) $this->actingAs($ja)->get(route('shopping.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Wybrana lista', $html);
        $this->assertStringNotContainsString('Dopisujesz do listy', $html);
        $this->assertStringContainsString('Na liście: 1 z 300 możliwych pozycji.', $html);
        $this->assertStringContainsString('Nowa lista', $html);
    }

    public function test_reczne_dopisanie_trafia_na_wybrana_liste_i_wraca_na_nia(): void
    {
        $ja = $this->user('kupujaca');
        $swieta = $this->lista($ja, 'Święta');

        $this->actingAs($ja)->post(route('shopping.store'), ['text' => 'orzechy', 'lista' => $swieta->getKey()])
            ->assertRedirect(route('shopping.index', ['lista' => $swieta->getKey()]).'#dopisz')
            ->assertSessionHas('status');

        $this->assertSame(['orzechy'], $this->teksty($ja, $swieta));
        $this->assertSame([], $this->teksty($ja, null));

        // Brak wyboru = lista domyślna, nie „ostatnio używana”.
        $this->actingAs($ja)->post(route('shopping.store'), ['text' => 'chleb']);
        $this->assertSame(['chleb'], $this->teksty($ja, null));
        $this->assertSame(['orzechy'], $this->teksty($ja, $swieta));
    }

    public function test_skladniki_z_przepisu_ida_na_wybrana_liste(): void
    {
        $ja = $this->user('kupujaca');
        $swieta = $this->lista($ja, 'Święta');
        $przepis = $this->przepis($this->user('kucharka'));

        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug), ['lista' => $swieta->getKey()])
            ->assertRedirect(route('shopping.index', ['lista' => $swieta->getKey()]));

        $this->assertSame(['2 jajka', 'szczypta soli'], $this->teksty($ja, $swieta));
        $this->assertSame([], $this->teksty($ja, null));
    }

    public function test_ostrzezenie_o_duplikacie_dotyczy_jednej_listy_a_inna_lista_nie_jest_duplikatem(): void
    {
        $ja = $this->user('kupujaca');
        $swieta = $this->lista($ja, 'Święta');
        $przepis = $this->przepis($this->user('kucharka'));

        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug));
        $this->assertSame(2, ShoppingListItem::query()->count());

        // Ten sam przepis na INNEJ liście: bez pytania.
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug), ['lista' => $swieta->getKey()])
            ->assertRedirect(route('shopping.index', ['lista' => $swieta->getKey()]));
        $this->assertSame(4, ShoppingListItem::query()->count());

        // Ten sam przepis na TEJ SAMEJ liście nazwanej: pytanie, nic nie dopisane, lista niesiona dalej.
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug), ['lista' => $swieta->getKey()])
            ->assertRedirect(route('shopping.recipe.confirm', ['recipe' => $przepis->slug, 'lista' => $swieta->getKey()]));
        $this->assertSame(4, ShoppingListItem::query()->count());

        $ekran = (string) $this->actingAs($ja)
            ->get(route('shopping.recipe.confirm', ['recipe' => $przepis->slug, 'lista' => $swieta->getKey()]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('listę zakupów „Święta” już', $ekran);
        $this->assertStringContainsString('name="lista" value="'.$swieta->getKey().'"', $ekran);

        // Po potwierdzeniu dopisuje na tę listę.
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug), ['lista' => $swieta->getKey(), 'potwierdzam' => 1]);
        $this->assertCount(4, $this->teksty($ja, $swieta));
        $this->assertCount(2, $this->teksty($ja, null));
    }

    public function test_strona_przepisu_pyta_o_liste_dopiero_gdy_jest_nazwana_lista(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'));

        $bez = (string) $this->actingAs($ja)->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();
        $this->assertStringContainsString('Dodaj składniki do listy zakupów', $bez);
        $this->assertStringNotContainsString('Na którą listę zakupów?', $bez);

        $swieta = $this->lista($ja, 'Święta');
        $z = (string) $this->actingAs($ja)->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();
        $this->assertStringContainsString('Na którą listę zakupów?', $z);
        $this->assertMatchesRegularExpression('/<option value="">Na co dzień<\/option>\s*<option value="'.$swieta->getKey().'">Święta<\/option>/', $z);
    }

    public function test_odhaczenie_i_usuniecie_dzialaja_na_pozycji_i_wracaja_na_jej_liste(): void
    {
        $ja = $this->user('kupujaca');
        $swieta = $this->lista($ja, 'Święta');
        $pozycja = $this->pozycja($ja, 'karp', $swieta);

        $this->actingAs($ja)->patch(route('shopping.toggle', $pozycja), ['odhaczona' => 1])
            ->assertRedirect(route('shopping.index', ['lista' => $swieta->getKey()]).'#pozycja-'.$pozycja->getKey());
        $this->assertNotNull($pozycja->fresh()?->checked_at);

        $this->actingAs($ja)->delete(route('shopping.destroy', $pozycja))
            ->assertRedirect(route('shopping.index', ['lista' => $swieta->getKey()]));
        $this->assertSame([], $this->teksty($ja, $swieta));
    }

    public function test_wyczysc_odhaczone_dotyczy_tylko_wybranej_listy(): void
    {
        $ja = $this->user('kupujaca');
        $swieta = $this->lista($ja, 'Święta');
        $this->pozycja($ja, 'mleko', null, 0)->forceFill(['checked_at' => now()])->save();
        $this->pozycja($ja, 'karp', $swieta, 1)->forceFill(['checked_at' => now()])->save();
        $this->pozycja($ja, 'orzechy', $swieta, 2);

        $this->actingAs($ja)->delete(route('shopping.clear'), ['lista' => $swieta->getKey()])
            ->assertRedirect(route('shopping.index', ['lista' => $swieta->getKey()]));

        $this->assertSame(['orzechy'], $this->teksty($ja, $swieta));
        $this->assertSame(['mleko'], $this->teksty($ja, null), 'Lista domyślna nie jest ruszana.');

        $this->actingAs($ja)->delete(route('shopping.clear'));
        $this->assertSame([], $this->teksty($ja, null));
        $this->assertSame(['orzechy'], $this->teksty($ja, $swieta));
    }

    public function test_limit_pozycji_liczy_sie_dla_calego_konta_a_nie_dla_jednej_listy(): void
    {
        $ja = $this->user('kupujaca');
        $swieta = $this->lista($ja, 'Święta');
        $wstaw = [];
        for ($i = 0; $i < ListaZakupow::maksPozycji(); $i++) {
            $wstaw[] = [
                'id' => (string) Str::uuid(), 'user_id' => $ja->getKey(), 'text' => 'p'.$i, 'source' => 'manual',
                'position' => $i, 'list_id' => $i % 2 === 0 ? null : $swieta->getKey(), 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('shopping_list_items')->insert($wstaw);

        $this->actingAs($ja)->from(route('shopping.index'))->post(route('shopping.store'), ['text' => 'jeszcze', 'lista' => $swieta->getKey()])
            ->assertSessionHasErrors('text');

        $this->assertSame(ListaZakupow::maksPozycji(), ShoppingListItem::query()->count());
    }

    public function test_cudza_lista_nie_otwiera_sie_i_nie_przyjmuje_zapisow(): void
    {
        $ja = $this->user('kupujaca');
        $obca = $this->user('obca');
        $cudza = $this->lista($obca, 'Cudze święta');
        $this->pozycja($obca, 'cudzy karp', $cudza);
        $przepis = $this->przepis($this->user('kucharka'));

        $odpowiedz = $this->actingAs($ja)->get(route('shopping.index', ['lista' => $cudza->getKey()]));
        $this->assertSame(403, $odpowiedz->getStatusCode(), 'LISTY_2528_CUDZA_LISTA_ODMOWA: identyfikator cudzej listy otworzył ekran.');
        $odpowiedz = $this->actingAs($ja)->post(route('shopping.store'), ['text' => 'x', 'lista' => $cudza->getKey()]);
        $this->assertSame(403, $odpowiedz->getStatusCode(), 'LISTY_2528_CUDZA_LISTA_ODMOWA: identyfikator cudzej listy przyjął zapis.');
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug), ['lista' => $cudza->getKey()])->assertForbidden();
        $this->actingAs($ja)->delete(route('shopping.clear'), ['lista' => $cudza->getKey()])->assertForbidden();
        $this->actingAs($ja)->patch(route('shopping.lists.rename', $cudza), ['nowa_nazwa' => 'Moje'])->assertForbidden();
        $this->actingAs($ja)->delete(route('shopping.lists.destroy', $cudza), ['potwierdzam' => 1, 'widziana_liczba' => 1])->assertForbidden();

        $this->assertSame(['cudzy karp'], $this->teksty($obca, $cudza));
        $this->assertSame('Cudze święta', $cudza->fresh()?->name);
        $this->assertSame(0, ShoppingListItem::query()->where('user_id', $ja->getKey())->count());
    }

    public function test_lista_usunieta_w_innej_karcie_daje_komunikat_i_nie_gubi_wpisanego_tekstu(): void
    {
        $ja = $this->user('kupujaca');
        $swieta = $this->lista($ja, 'Święta');
        $id = $swieta->getKey();
        $swieta->delete();

        $this->actingAs($ja)->from(route('shopping.index'))->post(route('shopping.store'), ['text' => 'orzechy', 'lista' => $id])
            ->assertRedirect(route('shopping.index'))
            ->assertSessionHasErrors(['lista' => 'Tej listy zakupów już nie ma. Wybierz listę jeszcze raz.'])
            ->assertSessionHasInput('text', 'orzechy');
        $this->assertSame(0, ShoppingListItem::query()->count());

        // Stary adres listy: ekran listy domyślnej z komunikatem, nie błąd.
        $this->actingAs($ja)->get(route('shopping.index', ['lista' => $id]))
            ->assertRedirect(route('shopping.index'))
            ->assertSessionHas('status_rodzaj', Komunikat::BLAD);
    }

    public function test_zalozenie_listy_nazwa_limit_i_duplikaty(): void
    {
        $ja = $this->user('kupujaca');

        $this->actingAs($ja)->post(route('shopping.lists.store'), ['nazwa' => '  Święta   Bożego Narodzenia '])
            ->assertSessionHas('status');
        $swieta = ShoppingList::query()->sole();
        $this->assertSame('Święta Bożego Narodzenia', $swieta->name);
        $this->assertSame($ja->getKey(), $swieta->user_id);

        foreach ([
            ['nazwa' => '   ', 'blad' => 'Wpisz nazwę listy, np. „Święta” albo „Przyjęcie u Kasi”.'],
            ['nazwa' => str_repeat('a', 61), 'blad' => 'Skróć nazwę listy do 60 znaków i spróbuj jeszcze raz.'],
            ['nazwa' => 'na co dzień', 'blad' => '„Na co dzień” to Twoja podstawowa lista, która już jest. Wybierz inną nazwę.'],
            ['nazwa' => 'ŚWIĘTA bożego narodzenia', 'blad' => 'Masz już listę o nazwie „ŚWIĘTA bożego narodzenia”. Wybierz inną nazwę.'],
        ] as $przypadek) {
            $this->actingAs($ja)->from(route('shopping.index'))->post(route('shopping.lists.store'), ['nazwa' => $przypadek['nazwa']])
                ->assertRedirect(route('shopping.index'))
                ->assertSessionHasErrors(['nazwa' => $przypadek['blad']]);
        }
        $this->assertSame(1, ShoppingList::query()->count());

        // Limit: razem z domyślną najwyżej list_max (5) => cztery nazwane.
        foreach (['B', 'C', 'D'] as $n) {
            $this->actingAs($ja)->post(route('shopping.lists.store'), ['nazwa' => $n])->assertSessionHas('status');
        }
        $this->assertSame(4, ShoppingList::query()->count());
        $this->actingAs($ja)->from(route('shopping.index'))->post(route('shopping.lists.store'), ['nazwa' => 'E'])
            ->assertSessionHasErrors('nazwa');
        $this->assertSame(4, ShoppingList::query()->count());

        // Inna osoba może nazwać listę tak samo.
        $inna = $this->user('inna');
        $this->actingAs($inna)->post(route('shopping.lists.store'), ['nazwa' => 'Święta Bożego Narodzenia'])->assertSessionHas('status');
        $this->assertSame(5, ShoppingList::query()->count());
    }

    public function test_wpisana_nazwa_listy_nie_znika_po_bledzie(): void
    {
        $ja = $this->user('kupujaca');
        $html = (string) $this->actingAs($ja)->withSession(['_old_input' => ['nazwa' => 'Przyjęcie u Kasi'], 'errors' => new ViewErrorBag])
            ->get(route('shopping.index'))->getContent();

        $this->assertStringContainsString('Przyjęcie u Kasi', $html);
        $this->assertStringContainsString('Nazwa nowej listy', $html);
    }

    public function test_zmiana_nazwy_nie_rusza_pozycji(): void
    {
        $ja = $this->user('kupujaca');
        $swieta = $this->lista($ja, 'Swieta');
        $this->lista($ja, 'Wigilia');
        $this->pozycja($ja, 'karp', $swieta)->forceFill(['checked_at' => now()])->save();

        $this->actingAs($ja)->from(route('shopping.index'))->patch(route('shopping.lists.rename', $swieta), ['nowa_nazwa' => 'wigilia'])
            ->assertSessionHasErrors(['nowa_nazwa' => 'Masz już listę o nazwie „wigilia”. Wybierz inną nazwę.']);

        $this->actingAs($ja)->patch(route('shopping.lists.rename', $swieta), ['nowa_nazwa' => 'Święta'])
            ->assertRedirect(route('shopping.index', ['lista' => $swieta->getKey()]));

        $this->assertSame('Święta', $swieta->fresh()?->name);
        $pozycja = ShoppingListItem::query()->sole();
        $this->assertSame([$swieta->getKey(), 'karp'], [$pozycja->list_id, $pozycja->text]);
        $this->assertNotNull($pozycja->checked_at);
    }

    public function test_usuniecie_listy_z_pozycjami_wymaga_potwierdzenia_z_liczba_i_nie_rusza_innych_list(): void
    {
        $ja = $this->user('kupujaca');
        $swieta = $this->lista($ja, 'Święta');
        $this->pozycja($ja, 'mleko');
        $this->pozycja($ja, 'karp', $swieta, 1);
        $this->pozycja($ja, 'orzechy', $swieta, 2);

        // Ekran mówi, co zniknie.
        $html = (string) $this->actingAs($ja)->get(route('shopping.index', ['lista' => $swieta->getKey()]))->getContent();
        $this->assertStringContainsString('Razem z listą zniknie 2 pozycje', $html);
        $this->assertStringContainsString('name="widziana_liczba" value="2"', $html);

        // Bez potwierdzenia i z nieaktualną liczbą: nic nie znika.
        $bez = $this->actingAs($ja)->from(route('shopping.index'))->delete(route('shopping.lists.destroy', $swieta));
        $nieaktualna = $this->actingAs($ja)->from(route('shopping.index'))->delete(route('shopping.lists.destroy', $swieta), ['potwierdzam' => 1, 'widziana_liczba' => 1]);
        $this->assertSame(1, ShoppingList::query()->count(), 'LISTY_2528_USUNIECIE_NIEAKTUALNA_LICZBA: lista zniknęła bez aktualnego potwierdzenia.');
        $this->assertSame(3, ShoppingListItem::query()->count(), 'LISTY_2528_USUNIECIE_NIEAKTUALNA_LICZBA: pozycje zniknęły bez aktualnego potwierdzenia.');
        $bez->assertSessionHasErrors('potwierdzam');
        $nieaktualna->assertSessionHasErrors('potwierdzam');

        $this->actingAs($ja)->delete(route('shopping.lists.destroy', $swieta), ['potwierdzam' => 1, 'widziana_liczba' => 2])
            ->assertRedirect(route('shopping.index'))
            ->assertSessionHas('status');

        $this->assertSame(0, ShoppingList::query()->count());
        $this->assertSame(['mleko'], ShoppingListItem::query()->pluck('text')->all(), 'Lista domyślna zostaje nietknięta.');

        // Powtórzone żądanie nie jest błędem.
        $this->actingAs($ja)->delete(route('shopping.lists.destroy', $swieta->getKey()), ['potwierdzam' => 1, 'widziana_liczba' => 2])
            ->assertRedirect(route('shopping.index'));
    }

    public function test_pusta_lista_usuwa_sie_bez_liczby_pozycji(): void
    {
        $ja = $this->user('kupujaca');
        $pusta = $this->lista($ja, 'Pusta');

        $this->actingAs($ja)->delete(route('shopping.lists.destroy', $pusta), ['potwierdzam' => 1, 'widziana_liczba' => 0])
            ->assertSessionHas('status');

        $this->assertSame(0, ShoppingList::query()->count());
    }

    public function test_cofniecie_usuniecia_wraca_na_swoja_liste_a_gdy_listy_nie_ma_na_domyslna(): void
    {
        $ja = $this->user('kupujaca');
        $swieta = $this->lista($ja, 'Święta');
        $karp = $this->pozycja($ja, 'karp', $swieta);

        $this->actingAs($ja)->delete(route('shopping.destroy', $karp));
        $migawka = ShoppingListUndo::query()->sole();
        $this->assertSame($swieta->getKey(), $migawka->items[0]['list_id']);

        $ekran = (string) $this->actingAs($ja)->get(route('shopping.index', ['lista' => $swieta->getKey()]))->getContent();
        $this->assertStringContainsString('Była to lista „Święta”.', $ekran);

        $this->actingAs($ja)->post(route('shopping.undo'))
            ->assertRedirect(route('shopping.index', ['lista' => $swieta->getKey()]));
        $this->assertSame(['karp'], $this->teksty($ja, $swieta));

        // Lista usunięta po usunięciu pozycji: pozycja nie ginie, wraca na domyślną.
        $this->actingAs($ja)->delete(route('shopping.destroy', ShoppingListItem::query()->sole()));
        $swieta->delete();
        $this->actingAs($ja)->post(route('shopping.undo'))->assertRedirect(route('shopping.index'));
        $this->assertSame(['karp'], $this->teksty($ja, null));
    }

    public function test_paczka_danych_ma_nazwy_list_i_lista_przy_pozycji(): void
    {
        $ja = $this->user('kupujaca');
        $swieta = $this->lista($ja, 'Święta');
        $this->lista($ja, 'Pusta lista');
        $this->pozycja($ja, 'mleko');
        $this->pozycja($ja, 'karp', $swieta, 1);

        $paczka = app(CollectUserExportData::class)->handle($ja, new ExportPhotoPlan($ja), now());

        $this->assertSame(['Święta', 'Pusta lista'], array_column($paczka['listy_zakupow'], 'nazwa'));
        $this->assertSame(['Na co dzień', 'Święta'], array_column($paczka['lista_zakupow'], 'lista'));
    }

    public function test_wymazanie_konta_kasuje_nazwy_list_tylko_tej_osoby(): void
    {
        $odchodzi = $this->user('odchodzi', ['status' => User::STATUS_PENDING_DELETE, 'delete_requested_at' => now()->subDays(40)]);
        $zostaje = $this->user('zostaje');
        $this->pozycja($odchodzi, 'karp', $this->lista($odchodzi, 'Święta u mamy Zosi'));
        $this->lista($odchodzi, 'Pusta');
        $this->lista($zostaje, 'Zostaje');

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $this->assertSame(['Zostaje'], ShoppingList::query()->pluck('name')->all());
        $this->assertSame(0, ShoppingListItem::query()->count());
    }

    public function test_baza_pilnuje_dlugosci_nazwy_i_unikalnosci_bez_wzgledu_na_wielkosc_liter(): void
    {
        $ja = $this->user('kupujaca');
        $this->lista($ja, 'Swieta');

        foreach (['   ', str_repeat('a', 61), 'SWIETA'] as $zla) {
            try {
                DB::transaction(fn () => DB::table('shopping_lists')->insert([
                    'user_id' => $ja->getKey(), 'name' => $zla, 'created_at' => now(), 'updated_at' => now(),
                ]));
                $this->fail('Baza przyjęła niepoprawną nazwę listy: '.$zla);
            } catch (QueryException) {
                $this->assertSame(1, DB::table('shopping_lists')->count());
            }
        }
    }

    public function test_usuniecie_listy_w_bazie_kasuje_jej_pozycje_kluczem_obcym(): void
    {
        $ja = $this->user('kupujaca');
        $lista = $this->lista($ja, 'Święta');
        $this->pozycja($ja, 'karp', $lista);
        $this->pozycja($ja, 'mleko', null, 1);

        $lista->delete();

        $this->assertSame(['mleko'], ShoppingListItem::query()->pluck('text')->all());
    }

    public function test_masowe_przypisanie_nie_ustawi_wlasciciela_ani_listy(): void
    {
        $ja = $this->user('kupujaca');
        $cudza = $this->lista($this->user('obca'), 'Cudza');

        foreach ([
            fn () => new ShoppingList(['name' => 'Moja', 'user_id' => $cudza->user_id]),
            fn () => new ShoppingListItem(['text' => 'mleko', 'list_id' => $cudza->getKey()]),
            fn () => new ShoppingListItem(['text' => 'mleko', 'user_id' => $ja->getKey()]),
        ] as $proba) {
            try {
                $proba();
                $this->fail('Pole sterujące (właściciel albo lista) weszło masowym przypisaniem.');
            } catch (MassAssignmentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_cofniecie_migracji_list_odmawia_przy_nazwanych_listach_i_przechodzi_na_pustej_bazie(): void
    {
        $kolumna = require base_path('database/migrations/2026_10_07_110100_add_list_id_to_shopping_list_items.php');
        $tabela = require base_path('database/migrations/2026_10_07_110000_create_shopping_lists_table.php');
        $ja = $this->user('kupujaca');
        $lista = $this->lista($ja, 'Święta');
        $this->pozycja($ja, 'karp', $lista);

        // Kolumna: odmowa, gdy pozycja jest na nazwanej liście.
        try {
            $kolumna->down();
            $this->fail('Rollback wrzucił pozycje z nazwanej listy na domyślną bez pytania.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Liczba takich pozycji: 1.', $e->getMessage());
            $this->assertStringContainsString('KUKING_ROLLBACK_SCALA_LISTY_ZAKUPOW=1', $e->getMessage());
        }
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('shopping_list_items', 'list_id'));

        // Tabela: odmowa, gdy istnieje lista.
        try {
            $tabela->down();
            $this->fail('Rollback skasował nazwane listy bez pytania.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Liczba list, które znikną: 1.', $e->getMessage());
            $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_LISTY_ZAKUPOW=1', $e->getMessage());
        }
        $this->assertSame(1, DB::table('shopping_lists')->count());
    }

    public function test_cofniecie_migracji_list_przechodzi_bez_nazwanych_list_i_z_jawnym_wymuszeniem(): void
    {
        $kolumna = require base_path('database/migrations/2026_10_07_110100_add_list_id_to_shopping_list_items.php');
        $tabela = require base_path('database/migrations/2026_10_07_110000_create_shopping_lists_table.php');
        $ja = $this->user('kupujaca');
        $this->pozycja($ja, 'mleko');

        // Kontrola dodatnia: pozycje tylko na liście domyślnej — kolumna schodzi bez pytania.
        $kolumna->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('shopping_list_items', 'list_id'));
        $this->assertSame(['mleko'], DB::table('shopping_list_items')->pluck('text')->all(), 'Pozycje listy domyślnej nie giną.');

        $tabela->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('shopping_lists'));

        // I wraca: up() po down() odtwarza oba kawałki.
        $tabela->up();
        $kolumna->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('shopping_lists'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('shopping_list_items', 'list_id'));

        // Jawne wymuszenie po kopii przechodzi mimo list.
        $this->lista($ja, 'Święta');
        putenv('KUKING_ROLLBACK_SCALA_LISTY_ZAKUPOW=1');
        putenv('KUKING_ROLLBACK_KASUJE_LISTY_ZAKUPOW=1');
        $kolumna->down();
        $tabela->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('shopping_lists'));
        $tabela->up();
        $kolumna->up();
    }
}
