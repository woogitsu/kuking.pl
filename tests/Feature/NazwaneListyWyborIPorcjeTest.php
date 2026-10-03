<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Zakupy\ListaZakupow;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Nazwane listy zakupów (#2528) na dwóch bocznych drogach dodawania:
 * „Wybierz składniki do zakupów” (#2462) i podglądzie przeliczonych porcji
 * (#2489). Bloker z PR #2793: obie drogi cicho dopisywały na listę domyślną.
 *
 * Każdy pomiar idzie przez HTTP i końcowy HTML; zapis sprawdzany w bazie
 * z podziałem na listy. Czas zamrożony.
 */
final class NazwaneListyWyborIPorcjeTest extends TestCase
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
        parent::tearDown();
    }

    private function lista(User $kto, string $nazwa): ShoppingList
    {
        $lista = new ShoppingList(['name' => $nazwa]);
        $lista->user_id = $kto->getKey();
        $lista->save();

        return $lista;
    }

    private function przepis(): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $this->user('kucharka')->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'servings' => 4,
        ]);

        foreach (['400 g mąki', '2 łyżki masła', 'sól do smaku'] as $i => $tekst) {
            RecipeIngredient::create(['recipe_id' => $przepis->getKey(), 'ingredient_text' => $tekst, 'position' => $i]);
        }

        return $przepis;
    }

    /** @return list<string> */
    private function idy(Recipe $przepis): array
    {
        return $przepis->ingredients()->orderBy('position')->pluck('id')->map(fn ($id): string => (string) $id)->values()->all();
    }

    private function odciskWyboru(Recipe $przepis): string
    {
        return app(ListaZakupow::class)->doWyboru($przepis)['odcisk'];
    }

    private function odciskPorcji(User $kto, Recipe $przepis): string
    {
        $html = (string) $this->actingAs($kto)
            ->get(route('shopping.recipe.scaled', ['recipe' => $przepis->slug, 'porcje' => '2']))
            ->assertOk()->getContent();
        preg_match('/name="odcisk" value="([0-9a-f]{64})"/', $html, $m);

        return $m[1] ?? '';
    }

    /** @return list<string> */
    private function teksty(User $kto, ?ShoppingList $lista): array
    {
        return ShoppingListItem::query()
            ->where('user_id', $kto->getKey())
            ->when($lista === null, fn ($q) => $q->whereNull('list_id'), fn ($q) => $q->where('list_id', $lista?->getKey()))
            ->orderBy('position')->orderBy('id')->pluck('text')->all();
    }

    public function test_wybor_skladnikow_pokazuje_pole_listy_i_dopisuje_na_wybrana_a_nie_na_domyslna(): void
    {
        $ja = $this->user('kupujaca');
        $swieta = $this->lista($ja, 'Święta');
        $grill = $this->lista($ja, 'Grill');
        $przepis = $this->przepis();

        $html = (string) $this->actingAs($ja)->get(route('shopping.recipe.pick', $przepis))->assertOk()->getContent();
        $this->assertStringContainsString('<label for="f-lista-zakupow-wybor">Na którą listę zakupów?</label>', $html, 'LISTY_2528_WYBOR_POLE: ekran wyboru składników nie pyta o listę.');
        $this->assertStringContainsString('<option value="'.$grill->getKey().'"', $html);

        $this->actingAs($ja)->post(route('shopping.recipe.pick.store', $przepis), [
            'skladniki' => array_slice($this->idy($przepis), 0, 2),
            'odcisk' => $this->odciskWyboru($przepis),
            'lista' => $grill->getKey(),
        ])->assertRedirect(route('shopping.index', ['lista' => $grill->getKey()]))
            ->assertSessionHas('status', fn ($t): bool => str_contains((string) $t, 'Dodane do listy „Grill”: 2 wybrane składniki'));

        $this->assertSame(['400 g mąki', '2 łyżki masła'], $this->teksty($ja, $grill), 'LISTY_2528_WYBOR_ZAPIS: wybrane składniki nie trafiły na wybraną listę.');
        $this->assertSame([], $this->teksty($ja, null));
        $this->assertSame([], $this->teksty($ja, $swieta));
    }

    public function test_wybor_skladnikow_ostrzega_o_duplikacie_tylko_na_wybranej_liscie_i_niesie_ja_przez_ostrzezenie(): void
    {
        $ja = $this->user('kupujaca');
        $swieta = $this->lista($ja, 'Święta');
        $this->lista($ja, 'Grill');
        $przepis = $this->przepis();
        $wyslij = fn (array $dodatkowe) => $this->actingAs($ja)->post(route('shopping.recipe.pick.store', $przepis), [
            'skladniki' => [$this->idy($przepis)[0]],
            'odcisk' => $this->odciskWyboru($przepis),
            ...$dodatkowe,
        ]);

        // Przepis na liście domyślnej nie jest duplikatem na „Święta”.
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug))->assertRedirect(route('shopping.index'));
        $wyslij(['lista' => $swieta->getKey()])->assertRedirect(route('shopping.index', ['lista' => $swieta->getKey()]));
        $this->assertSame(['400 g mąki'], $this->teksty($ja, $swieta), 'LISTY_2528_WYBOR_DUPLIKAT: przepis z innej listy zablokował dopisanie.');

        // Na TEJ SAMEJ liście: ostrzeżenie, nic nie dopisane, lista wraca w adresie i w polu.
        $adresWyboru = route('shopping.recipe.pick', [$przepis, 'lista' => $swieta->getKey()]);
        $wyslij(['lista' => $swieta->getKey()])->assertRedirect($adresWyboru);
        $this->assertSame(['400 g mąki'], $this->teksty($ja, $swieta));

        $html = (string) $this->followRedirects($wyslij(['lista' => $swieta->getKey()]))->assertOk()->getContent();
        $this->assertStringContainsString('listę zakupów „Święta” już', $html, 'LISTY_2528_WYBOR_OSTRZEZENIE: ostrzeżenie nie mówi, której listy dotyczy.');
        $this->assertStringContainsString('<option value="'.$swieta->getKey().'" selected', $html, 'LISTY_2528_WYBOR_OSTRZEZENIE: lista zgubiona na ekranie ostrzeżenia.');
        $this->assertStringContainsString('name="potwierdzona_lista" value="'.$swieta->getKey().'"', $html);

        // Potwierdzenie z ostrzeżenia, ale po zmianie na listę domyślną (tam też jest
        // duplikat): nowe pytanie, nic nie dopisane.
        $wyslij(['potwierdzam' => 1, 'potwierdzona_lista' => $swieta->getKey(), 'lista' => ''])
            ->assertRedirect(route('shopping.recipe.pick', $przepis));
        $this->assertCount(3, $this->teksty($ja, null));

        // Potwierdzenie na tej samej liście: dopisuje na „Święta”, nie na domyślną.
        $wyslij(['potwierdzam' => 1, 'potwierdzona_lista' => $swieta->getKey(), 'lista' => $swieta->getKey()])
            ->assertRedirect(route('shopping.index', ['lista' => $swieta->getKey()]));
        $this->assertSame(['400 g mąki', '400 g mąki'], $this->teksty($ja, $swieta));
        $this->assertCount(3, $this->teksty($ja, null));
    }

    public function test_ostrzezenie_na_ekranie_wyboru_liczy_date_z_wybranej_listy_gdy_domyslna_jest_pusta(): void
    {
        $ja = $this->user('kupujaca');
        $swieta = $this->lista($ja, 'Święta');
        $przepis = $this->przepis();
        $wyslij = fn () => $this->actingAs($ja)->post(route('shopping.recipe.pick.store', $przepis), [
            'skladniki' => [$this->idy($przepis)[0]],
            'odcisk' => $this->odciskWyboru($przepis),
            'lista' => $swieta->getKey(),
        ]);

        $wyslij()->assertRedirect(route('shopping.index', ['lista' => $swieta->getKey()]));
        $html = (string) $this->followRedirects($wyslij())->assertOk()->getContent();

        $this->assertStringContainsString('Składniki tego przepisu już są na liście', $html, 'LISTY_2528_WYBOR_OSTRZEZENIE_LISTA: ostrzeżenie liczone na innej liście niż wybrana.');
        $this->assertStringContainsString('Dodaj wybrane jeszcze raz', $html);
        $this->assertSame([], $this->teksty($ja, null));
        $this->assertSame(['400 g mąki'], $this->teksty($ja, $swieta));
    }

    public function test_podglad_porcji_ma_jedno_pole_listy_a_oba_przyciski_dopisuja_na_wybrana_liste(): void
    {
        $ja = $this->user('kupujaca');
        $swieta = $this->lista($ja, 'Święta');
        $grill = $this->lista($ja, 'Grill');
        $przepis = $this->przepis();

        $html = (string) $this->actingAs($ja)
            ->get(route('shopping.recipe.scaled', ['recipe' => $przepis->slug, 'porcje' => '2']))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'Na którą listę zakupów?'), 'LISTY_2528_PORCJE_POLE: podgląd porcji nie pyta o listę albo pyta dwa razy.');
        $this->assertStringContainsString('name="ilosci" value="autora"', $html);

        $odcisk = $this->odciskPorcji($ja, $przepis);
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug), [
            'porcje' => '2', 'odcisk' => $odcisk, 'ilosci' => 'przeliczone', 'lista' => $grill->getKey(),
        ])->assertRedirect(route('shopping.index', ['lista' => $grill->getKey()]));
        $this->assertSame(['200 g mąki', '1 łyżka masła', 'sól do smaku'], $this->teksty($ja, $grill), 'LISTY_2528_PORCJE_ZAPIS: przeliczone składniki nie trafiły na wybraną listę.');

        // „Dodaj ilości autora” z tego samego formularza: ilości autora, na „Święta”.
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug), [
            'porcje' => '2', 'odcisk' => $odcisk, 'ilosci' => 'autora', 'lista' => $swieta->getKey(),
        ])->assertRedirect(route('shopping.index', ['lista' => $swieta->getKey()]));
        $this->assertSame(['400 g mąki', '2 łyżki masła', 'sól do smaku'], $this->teksty($ja, $swieta), 'LISTY_2528_PORCJE_AUTOR: ilości autora nie trafiły na wybraną listę.');
        $this->assertSame([], $this->teksty($ja, null));

        // Duplikat na tej samej liście: ostrzeżenie niesie listę, porcje i odcisk.
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug), [
            'porcje' => '2', 'odcisk' => $odcisk, 'ilosci' => 'przeliczone', 'lista' => $grill->getKey(),
        ])->assertRedirect(route('shopping.recipe.confirm', ['recipe' => $przepis->slug, 'porcje' => '2', 'odcisk' => $odcisk, 'lista' => $grill->getKey()]));
        $this->assertCount(3, $this->teksty($ja, $grill));

        // Zmiana przepisu po podglądzie: świeży podgląd z tą samą listą zaznaczoną.
        RecipeIngredient::query()->where('recipe_id', $przepis->getKey())->where('position', 0)->update(['ingredient_text' => '600 g mąki']);
        $zmieniony = $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug), [
            'porcje' => '2', 'odcisk' => $odcisk, 'ilosci' => 'przeliczone', 'lista' => $swieta->getKey(), 'potwierdzam' => 1,
        ]);
        $zmieniony->assertRedirect(route('shopping.recipe.scaled', ['recipe' => $przepis->slug, 'porcje' => '2', 'lista' => $swieta->getKey()]));
        $swiezy = (string) $this->followRedirects($zmieniony)->assertOk()->getContent();
        $this->assertStringContainsString('<option value="'.$swieta->getKey().'" selected', $swiezy, 'LISTY_2528_PORCJE_ODCISK: lista zgubiona po zmianie przepisu.');
        $this->assertCount(3, $this->teksty($ja, $swieta));
    }

    public function test_cudza_lista_jest_odrzucana_na_obu_ekranach_i_nic_nie_zapisuje(): void
    {
        $ja = $this->user('kupujaca');
        $this->lista($ja, 'Święta');
        $cudza = $this->lista($this->user('obca'), 'Cudze święta');
        $przepis = $this->przepis();

        $this->actingAs($ja)->post(route('shopping.recipe.pick.store', $przepis), [
            'skladniki' => $this->idy($przepis), 'odcisk' => $this->odciskWyboru($przepis), 'lista' => $cudza->getKey(),
        ])->assertForbidden();
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug), [
            'porcje' => '2', 'odcisk' => $this->odciskPorcji($ja, $przepis), 'ilosci' => 'przeliczone', 'lista' => $cudza->getKey(),
        ])->assertForbidden();
        $this->actingAs($ja)->get(route('shopping.recipe.pick', [$przepis, 'lista' => $cudza->getKey()]))->assertForbidden();

        $this->assertSame(0, ShoppingListItem::query()->count(), 'LISTY_2528_CUDZA_LISTA_ODMOWA: cudza lista przyjęła zapis.');
    }

    public function test_lista_ktorej_nie_ma_daje_blad_przy_polu_i_zachowuje_wybor_na_obu_ekranach(): void
    {
        $ja = $this->user('kupujaca');
        $this->lista($ja, 'Święta');
        $przepis = $this->przepis();
        $nieznana = (string) Str::uuid();
        $komunikat = 'Tej listy zakupów już nie ma. Wybierz listę jeszcze raz.';
        $wybrane = array_slice($this->idy($przepis), 1, 1);

        $wybor = fn () => $this->actingAs($ja)->post(route('shopping.recipe.pick.store', $przepis), [
            'skladniki' => $wybrane, 'odcisk' => $this->odciskWyboru($przepis), 'lista' => $nieznana,
        ]);
        $wybor()->assertRedirect(route('shopping.recipe.pick', $przepis))
            ->assertSessionHasErrors(['lista' => $komunikat])
            ->assertSessionHasInput('skladniki', $wybrane);
        $html = (string) $this->followRedirects($wybor())->assertOk()->getContent();
        $this->assertStringContainsString('id="f-lista-zakupow-wybor-error">'.$komunikat, $html, 'LISTY_2528_WYBOR_BLAD_PRZY_POLU: błąd listy nie stoi przy polu.');
        $this->assertStringContainsString('value="'.$wybrane[0].'" checked', $html);

        $odcisk = $this->odciskPorcji($ja, $przepis);
        $porcje = fn () => $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug), [
            'porcje' => '2', 'odcisk' => $odcisk, 'ilosci' => 'przeliczone', 'lista' => $nieznana,
        ]);
        $porcje()->assertRedirect(route('shopping.recipe.scaled', ['recipe' => $przepis->slug, 'porcje' => '2']))
            ->assertSessionHasErrors(['lista' => $komunikat]);
        $podglad = (string) $this->followRedirects($porcje())->assertOk()->getContent();
        $this->assertStringContainsString('id="f-lista-zakupow-porcje-error">'.$komunikat, $podglad, 'LISTY_2528_PORCJE_BLAD_PRZY_POLU: błąd listy nie wraca na podgląd przy polu.');
        $this->assertStringContainsString('Składniki na 2 porcje', $podglad);

        $this->assertSame(0, ShoppingListItem::query()->count());
    }

    public function test_stary_formularz_bez_pola_lista_dopisuje_na_liste_domyslna(): void
    {
        $ja = $this->user('kupujaca');
        $swieta = $this->lista($ja, 'Święta');
        $przepis = $this->przepis();

        $this->actingAs($ja)->post(route('shopping.recipe.pick.store', $przepis), [
            'skladniki' => [$this->idy($przepis)[0]], 'odcisk' => $this->odciskWyboru($przepis),
        ])->assertRedirect(route('shopping.index'));
        $this->assertSame(['400 g mąki'], $this->teksty($ja, null));

        // Stary podgląd: bez `lista` i bez `ilosci` — przeliczone, na domyślną.
        $this->actingAs($ja)->post(route('shopping.recipe.store', $przepis->slug), [
            'porcje' => '2', 'odcisk' => $this->odciskPorcji($ja, $przepis), 'potwierdzam' => 1,
        ])->assertRedirect(route('shopping.index'));
        $this->assertSame(['400 g mąki', '200 g mąki', '1 łyżka masła', 'sól do smaku'], $this->teksty($ja, null));
        $this->assertSame([], $this->teksty($ja, $swieta));
    }

    public function test_kto_ma_tylko_liste_domyslna_nie_widzi_zbednego_pola_na_obu_ekranach(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis();

        $this->actingAs($ja)->get(route('shopping.recipe.pick', $przepis))->assertOk()->assertDontSee('Na którą listę zakupów?');
        $this->actingAs($ja)->get(route('shopping.recipe.scaled', ['recipe' => $przepis->slug, 'porcje' => '2']))->assertOk()->assertDontSee('Na którą listę zakupów?');
    }
}
