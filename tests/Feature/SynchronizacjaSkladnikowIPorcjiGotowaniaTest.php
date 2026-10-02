<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Porcje\WyborPorcji;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\CookingProgress;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Synchronizacja gotowania, etap 2 (#2016): składniki „przygotowane” (#2069)
 * i wybrana liczba porcji na koncie — TYLKO gdy osoba włączyła zapamiętywanie
 * dla tego przepisu. „Drugie urządzenie” to `flushSession()`.
 */
class SynchronizacjaSkladnikowIPorcjiGotowaniaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Recipe, 1: list<RecipeIngredient>} */
    private function przepis(?User $autor = null, string $widocznosc = 'public', ?int $porcje = 4): array
    {
        $recipe = Recipe::factory()->create([
            'author_id' => ($autor ?? $this->user())->getKey(),
            'visibility' => $widocznosc,
            'servings' => $porcje,
        ]);
        RecipeStep::create(['recipe_id' => $recipe->getKey(), 'position' => 0, 'instruction' => 'Zagnieć ciasto.']);

        $skladniki = [];
        foreach (['500 g mąki', '2 jajka', 'szczypta soli'] as $i => $tekst) {
            $skladniki[] = RecipeIngredient::create(['recipe_id' => $recipe->getKey(), 'ingredient_text' => $tekst, 'position' => $i]);
        }

        return [$recipe, $skladniki];
    }

    private function wlacz(User $osoba, Recipe $recipe, array $dodatkowe = [])
    {
        return $this->actingAs($osoba)->post(route('cooking.sync.wlacz', $recipe->slug), ['krok' => 1] + $dodatkowe);
    }

    /** @param  list<RecipeIngredient>  $zaznaczone @param  list<RecipeIngredient>  $bylo */
    private function zapiszSkladniki(User $osoba, Recipe $recipe, array $zaznaczone, array $bylo = [], array $dodatkowe = [])
    {
        $postep = $this->postep($osoba, $recipe);
        $zKonta = $postep?->servings === null ? null : (float) $postep->servings;
        $wybor = WyborPorcji::dla($recipe, $dodatkowe['porcje'] ?? $zKonta);

        return $this->actingAs($osoba)->post(route('cooking.sync.skladniki', $recipe->slug), [
            'zaznaczone' => array_map(fn (RecipeIngredient $s): string => (string) $s->getKey(), $zaznaczone),
            'bylo' => array_map(fn (RecipeIngredient $s): string => (string) $s->getKey(), $bylo),
            'kontekst_porcji' => $wybor->przeliczone() ? $wybor->doAdresu((float) $wybor->wybrane) : 'przepis',
            'porcje_z_konta' => $zKonta === null ? 'przepis' : (string) $zKonta,
            'rewizja_porcji' => $postep?->servings_revision,
            'id_postepu' => $postep?->getKey(),
        ] + $dodatkowe);
    }

    private function postep(User $osoba, Recipe $recipe): ?CookingProgress
    {
        return CookingProgress::query()->where('user_id', $osoba->getKey())->where('recipe_id', $recipe->getKey())->first();
    }

    /** @param  list<RecipeIngredient>  $skladniki @return list<string> */
    private function ids(array $skladniki): array
    {
        return array_map(fn (RecipeIngredient $s): string => (string) $s->getKey(), $skladniki);
    }

    // --- Domyślnie nic nie trafia na konto ---------------------------------

    public function test_bez_wlaczonej_synchronizacji_skladniki_i_porcje_nie_trafiaja_do_bazy(): void
    {
        $osoba = $this->user();
        [$recipe, $s] = $this->przepis();

        $this->zapiszSkladniki($osoba, $recipe, [$s[0]])->assertRedirect()->assertSessionHas('status_rodzaj', 'blad');
        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => '6'])
            ->assertRedirect()->assertSessionHas('status_rodzaj', 'blad');

        $this->assertSame(0, CookingProgress::query()->count());

        // Ekran bez synchronizacji zostaje przy pamięci karty (#2069).
        $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug))
            ->assertOk()
            ->assertSee('data-przygotowanie="'.$recipe->getKey().'"', false)
            ->assertDontSee('name="zaznaczone[]"', false);
    }

    public function test_gosc_nie_zapisze_skladnikow_ani_porcji(): void
    {
        [$recipe, $s] = $this->przepis();

        $this->post(route('cooking.sync.skladniki', $recipe->slug), ['zaznaczone' => [$s[0]->getKey()]])->assertRedirect(route('login'));
        $this->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => '6'])->assertRedirect(route('login'));

        $this->assertSame(0, CookingProgress::query()->count());
    }

    // --- Składniki ----------------------------------------------------------

    public function test_zapisane_skladniki_widzi_drugie_urzadzenie_bez_javascriptu(): void
    {
        $osoba = $this->user();
        [$recipe, $s] = $this->przepis();
        $this->wlacz($osoba, $recipe);

        $this->zapiszSkladniki($osoba, $recipe, [$s[0], $s[2]], [])->assertRedirect()->assertSessionHas('status_rodzaj', 'sukces');

        $this->assertEqualsCanonicalizing($this->ids([$s[0], $s[2]]), $this->postep($osoba, $recipe)->prepared_ingredient_ids);

        $this->flushSession();
        $html = $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug))->assertOk()->getContent();

        // Formularz z zaznaczeniem z serwera; skrypt z sessionStorage go nie rusza.
        $this->assertStringNotContainsString('data-przygotowanie="', $html);
        $this->assertStringContainsString('Zapisz zaznaczenie składników', $html);
        $this->assertStringContainsString('name="rewizja_porcji" value="1"', $html);
        $this->assertStringContainsString('name="id_postepu" value="'.$this->postep($osoba, $recipe)->getKey().'"', $html);
        $this->assertMatchesRegularExpression('/name="zaznaczone\[\]" value="'.$s[0]->getKey().'" checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="zaznaczone\[\]" value="'.$s[1]->getKey().'" checked/', $html);
        $this->assertMatchesRegularExpression('/name="bylo\[\]" value="'.$s[2]->getKey().'"/', $html);
        $this->assertStringContainsString('Zapisane na koncie: 2 z 3', $html);
    }

    public function test_dwa_urzadzenia_zaznaczajace_rozne_skladniki_nic_sobie_nie_gubia(): void
    {
        $osoba = $this->user();
        [$recipe, $s] = $this->przepis();
        $this->wlacz($osoba, $recipe);

        // Urządzenie A i B widziały pustą listę (`bylo` puste); A zapisuje pierwsze.
        $krok = $recipe->steps()->firstOrFail();
        $this->actingAs($osoba)->post(route('cooking.zaznacz', $recipe->slug), [
            'krok_id' => $krok->getKey(), 'zrobiono' => '1', 'krok' => 1,
        ])->assertRedirect();
        $this->assertGreaterThan(1, $this->postep($osoba, $recipe)->revision);
        $this->zapiszSkladniki($osoba, $recipe, [$s[0]], [], ['rewizja' => 1]);
        $this->assertSame(1, $this->postep($osoba, $recipe)->servings_revision);
        $this->zapiszSkladniki($osoba, $recipe, [$s[1]], [], ['rewizja' => 1])
            ->assertSessionHas('status_rodzaj', 'informacja');

        $this->assertEqualsCanonicalizing($this->ids([$s[0], $s[1]]), $this->postep($osoba, $recipe)->prepared_ingredient_ids);
        $this->assertSame(1, $this->postep($osoba, $recipe)->servings_revision, 'Zwykłe odhaczenie nie zmienia znacznika porcji.');
    }

    public function test_odznaczenie_zdejmuje_tylko_ten_skladnik_a_powtorzenie_nie_zmienia_rewizji(): void
    {
        $osoba = $this->user();
        [$recipe, $s] = $this->przepis();
        $this->wlacz($osoba, $recipe);
        $this->zapiszSkladniki($osoba, $recipe, [$s[0], $s[1]], []);

        // Strona pokazywała [0, 1]; osoba zostawia tylko [1].
        $this->zapiszSkladniki($osoba, $recipe, [$s[1]], [$s[0], $s[1]]);
        $this->assertSame($this->ids([$s[1]]), $this->postep($osoba, $recipe)->prepared_ingredient_ids);

        $rewizja = $this->postep($osoba, $recipe)->revision;
        $this->zapiszSkladniki($osoba, $recipe, [$s[1]], [$s[0], $s[1]]);
        $this->assertSame($rewizja, $this->postep($osoba, $recipe)->revision, 'Idempotentne powtórzenie nie podbija rewizji.');
    }

    public function test_obcy_skladnik_z_innego_przepisu_i_zmyslone_id_sa_ignorowane(): void
    {
        $osoba = $this->user();
        [$recipe, $s] = $this->przepis();
        [, $obce] = $this->przepis();
        $this->wlacz($osoba, $recipe);

        $this->zapiszSkladniki($osoba, $recipe, [$s[0], $obce[0]]);
        $this->actingAs($osoba)->post(route('cooking.sync.skladniki', $recipe->slug), [
            'zaznaczone' => ['zmyslone'], 'kontekst_porcji' => 'przepis', 'porcje_z_konta' => 'przepis',
        ]);

        $this->assertSame($this->ids([$s[0]]), $this->postep($osoba, $recipe)->prepared_ingredient_ids);
    }

    public function test_wygasla_synchronizacja_nie_przyjmuje_zapisu_skladnikow(): void
    {
        $osoba = $this->user();
        [$recipe, $s] = $this->przepis();
        $this->wlacz($osoba, $recipe);
        $this->postep($osoba, $recipe)->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->zapiszSkladniki($osoba, $recipe, [$s[0]])->assertSessionHas('status_rodzaj', 'blad');

        $this->assertSame([], $this->postep($osoba, $recipe)->prepared_ingredient_ids);
    }

    public function test_wylaczenie_kasuje_zapisane_skladniki_i_porcje(): void
    {
        $osoba = $this->user();
        [$recipe, $s] = $this->przepis();
        $this->wlacz($osoba, $recipe);
        $this->zapiszSkladniki($osoba, $recipe, [$s[0]]);
        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => '6']);

        $this->actingAs($osoba)->post(route('cooking.sync.wylacz', $recipe->slug))->assertRedirect();

        $this->assertNull($this->postep($osoba, $recipe));
    }

    // --- Porcje -------------------------------------------------------------

    public function test_wybrane_porcje_zapisane_na_koncie_obowiazuja_na_drugim_urzadzeniu(): void
    {
        $osoba = $this->user();
        [$recipe] = $this->przepis(null, 'public', 4);
        $this->wlacz($osoba, $recipe);

        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => '6', 'krok' => 1])
            ->assertRedirect(route('cooking.show', ['recipe' => $recipe->slug, 'krok' => 1, 'porcje' => '6']));
        $this->assertEquals(6.0, (float) $this->postep($osoba, $recipe)->servings);

        // Drugie urządzenie otwiera przepis BEZ ?porcje= — obowiązuje zapis z konta.
        $this->flushSession();
        $html = $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug))->assertOk()->getContent();
        $this->assertStringContainsString('Przeliczone na 6 porcji', $html);
        $this->assertStringContainsString('porcje=6', $html);

        // Jawny adres ma pierwszeństwo przed zapisem.
        $this->actingAs($osoba)->get(route('cooking.show', ['recipe' => $recipe->slug, 'porcje' => 8]))
            ->assertOk()->assertSee('Przeliczone na 8 porcji');
    }

    public function test_zmiana_porcji_na_koncie_wymaga_ponownego_odmierzenia_a_krok_i_ta_sama_ilosc_nie(): void
    {
        $osoba = $this->user();
        [$recipe, $s] = $this->przepis(null, 'public', 4);
        $krok = $recipe->steps()->firstOrFail();
        $this->wlacz($osoba, $recipe);
        $this->zapiszSkladniki($osoba, $recipe, [$s[0], $s[1]]);

        $this->actingAs($osoba)->post(route('cooking.zaznacz', $recipe->slug), [
            'krok_id' => $krok->getKey(), 'zrobiono' => '1', 'krok' => 1,
        ])->assertRedirect();
        $this->assertEqualsCanonicalizing($this->ids([$s[0], $s[1]]), $this->postep($osoba, $recipe)->prepared_ingredient_ids);

        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => '4']);
        $this->assertEqualsCanonicalizing($this->ids([$s[0], $s[1]]), $this->postep($osoba, $recipe)->prepared_ingredient_ids);

        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => '8'])
            ->assertSessionHas('status_rodzaj', 'informacja');
        $this->assertSame([], $this->postep($osoba, $recipe)->prepared_ingredient_ids, 'PORCJE_2502_NOWA_ILOSC_NIE_PRZYGOTOWANA');
        $this->assertContains((string) $krok->getKey(), $this->postep($osoba, $recipe)->done_step_ids);
        $this->flushSession();
        $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug))
            ->assertOk()->assertSee('Przeliczone na 8 porcji')
            ->assertDontSee('name="zaznaczone[]" value="'.$s[0]->getKey().'" checked', false);

        $this->zapiszSkladniki($osoba, $recipe, [$s[0]]);
        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => '2']);
        $this->assertSame([], $this->postep($osoba, $recipe)->prepared_ingredient_ids);
        $this->zapiszSkladniki($osoba, $recipe, [$s[0]]);
        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => 'przepis']);
        $this->assertSame([], $this->postep($osoba, $recipe)->prepared_ingredient_ids);
    }

    public function test_jawny_adres_z_inna_iloscia_nie_pokazuje_starych_odmierzen_i_daje_ponowne_potwierdzenie(): void
    {
        $osoba = $this->user();
        [$recipe, $s] = $this->przepis(null, 'public', 4);
        $this->wlacz($osoba, $recipe);
        $this->zapiszSkladniki($osoba, $recipe, [$s[0]]);

        $this->actingAs($osoba)->get(route('cooking.show', ['recipe' => $recipe->slug, 'porcje' => 8]))
            ->assertOk()->assertSee('Te ilości różnią się od zapisanych na koncie')
            ->assertSee('name="kontekst_porcji" value="8"', false)
            ->assertDontSee('name="zaznaczone[]" value="'.$s[0]->getKey().'" checked', false);

        $this->zapiszSkladniki($osoba, $recipe, [$s[1]], [], ['porcje' => '8'])
            ->assertSessionHas('status_rodzaj', 'sukces');
        $this->assertSame($this->ids([$s[1]]), $this->postep($osoba, $recipe)->prepared_ingredient_ids, 'PORCJE_2502_JAWNY_ADRES_NIE_PRZENOSI_STARYCH');
        $this->assertEquals(8.0, (float) $this->postep($osoba, $recipe)->servings);
    }

    public function test_stary_formularz_po_zmianie_porcji_na_drugim_urzadzeniu_nie_przywraca_odmierzenia(): void
    {
        $osoba = $this->user();
        [$recipe, $s] = $this->przepis(null, 'public', 4);
        $this->wlacz($osoba, $recipe);
        $this->zapiszSkladniki($osoba, $recipe, [$s[0]]);
        $widzianyPostep = $this->postep($osoba, $recipe);
        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => '8']);

        $odpowiedz = $this->actingAs($osoba)->post(route('cooking.sync.skladniki', $recipe->slug), [
            'zaznaczone' => [$s[0]->getKey(), $s[1]->getKey()], 'bylo' => [$s[0]->getKey()],
            'kontekst_porcji' => 'przepis', 'porcje_z_konta' => 'przepis',
            'rewizja_porcji' => $widzianyPostep->servings_revision,
            'id_postepu' => $widzianyPostep->getKey(),
        ]);
        $this->assertSame([], $this->postep($osoba, $recipe)->prepared_ingredient_ids, 'PORCJE_2502_STARY_FORMULARZ_NIE_PRZYWRACA');
        $odpowiedz->assertSessionHas('status_rodzaj', 'blad');
        $this->assertEquals(8.0, (float) $this->postep($osoba, $recipe)->servings, 'PORCJE_2502_STARY_FORMULARZ_NIE_PRZYWRACA');

        $this->actingAs($osoba)->post(route('cooking.sync.skladniki', $recipe->slug), [
            'zaznaczone' => [$s[0]->getKey()],
        ])->assertSessionHas('status_rodzaj', 'blad');
        $this->assertSame([], $this->postep($osoba, $recipe)->prepared_ingredient_ids);
    }

    public function test_stary_formularz_po_powrocie_do_tej_samej_liczby_porcji_nie_przywraca_odmierzenia(): void
    {
        $osoba = $this->user();
        [$recipe, $s] = $this->przepis(null, 'public', 4);
        $this->wlacz($osoba, $recipe);
        $staraRewizja = $this->postep($osoba, $recipe)->servings_revision;

        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => '8']);
        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => 'przepis']);
        $this->assertNull($this->postep($osoba, $recipe)->servings);
        $this->assertSame($staraRewizja + 2, $this->postep($osoba, $recipe)->servings_revision);

        $odpowiedz = $this->actingAs($osoba)->post(route('cooking.sync.skladniki', $recipe->slug), [
            'zaznaczone' => [$s[0]->getKey()], 'bylo' => [],
            'kontekst_porcji' => 'przepis', 'porcje_z_konta' => 'przepis',
            'rewizja_porcji' => $staraRewizja,
            'id_postepu' => $this->postep($osoba, $recipe)->getKey(),
        ]);

        $this->assertSame([], $this->postep($osoba, $recipe)->prepared_ingredient_ids, 'PORCJE_2502_ABA_NIE_PRZYWRACA');
        $odpowiedz->assertSessionHas('status_rodzaj', 'blad');
        $this->zapiszSkladniki($osoba, $recipe, [$s[1]])->assertSessionHas('status_rodzaj', 'sukces');
        $this->assertSame($this->ids([$s[1]]), $this->postep($osoba, $recipe)->prepared_ingredient_ids);
    }

    public function test_formularz_sprzed_wylaczenia_i_ponownego_wlaczenia_nie_potwierdza_dawnych_skladnikow(): void
    {
        $osoba = $this->user();
        [$recipe, $s] = $this->przepis(null, 'public', 4);
        $this->wlacz($osoba, $recipe);
        $dawnyPostep = $this->postep($osoba, $recipe);
        $this->actingAs($osoba)->post(route('cooking.sync.wylacz', $recipe->slug))->assertRedirect();
        $this->wlacz($osoba, $recipe);
        $this->assertNotSame($dawnyPostep->getKey(), $this->postep($osoba, $recipe)->getKey());

        $odpowiedz = $this->actingAs($osoba)->post(route('cooking.sync.skladniki', $recipe->slug), [
            'zaznaczone' => [$s[0]->getKey()], 'bylo' => [],
            'kontekst_porcji' => 'przepis', 'porcje_z_konta' => 'przepis',
            'rewizja_porcji' => $dawnyPostep->servings_revision,
            'id_postepu' => $dawnyPostep->getKey(),
        ]);

        $this->assertSame([], $this->postep($osoba, $recipe)->prepared_ingredient_ids, 'PORCJE_2502_NOWY_POSTEP_NIE_PRZYWRACA');
        $odpowiedz->assertSessionHas('status_rodzaj', 'blad');
    }

    public function test_rollback_znacznika_odmawia_po_zmianie_porcji_a_przy_wartosci_domyslnej_przechodzi(): void
    {
        $migracja = require base_path('database/migrations/2026_10_02_180000_add_servings_revision_to_cooking_progress.php');
        $osoba = $this->user();
        [$recipe] = $this->przepis(null, 'public', 4);
        $this->wlacz($osoba, $recipe);

        $migracja->down();
        $this->assertFalse(Schema::hasColumn('cooking_progress', 'servings_revision'));
        $migracja->up();
        $this->assertTrue(Schema::hasColumn('cooking_progress', 'servings_revision'));

        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => '8']);
        $this->assertGreaterThan(1, $this->postep($osoba, $recipe)->servings_revision);
        try {
            $migracja->down();
            $this->fail('Rollback usunąłby znacznik aktywnej historii porcji.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('historię zmian porcji', $e->getMessage());
            $this->assertTrue(Schema::hasColumn('cooking_progress', 'servings_revision'));
        }
    }

    public function test_odhaczenie_kroku_z_jawnym_adresem_innych_porcji_nie_zostawia_starych_skladnikow(): void
    {
        $osoba = $this->user();
        [$recipe, $s] = $this->przepis(null, 'public', 4);
        $krok = $recipe->steps()->firstOrFail();
        $this->wlacz($osoba, $recipe);
        $this->zapiszSkladniki($osoba, $recipe, [$s[0]]);
        $widzianaRewizjaPorcji = $this->postep($osoba, $recipe)->servings_revision;

        $this->actingAs($osoba)->post(route('cooking.zaznacz', $recipe->slug), [
            'krok_id' => $krok->getKey(), 'zrobiono' => '1', 'krok' => 1, 'porcje' => '8',
        ])->assertRedirect();

        $postep = $this->postep($osoba, $recipe);
        $this->assertEquals(8.0, (float) $postep->servings);
        $this->assertSame($widzianaRewizjaPorcji + 1, $postep->servings_revision);
        $this->assertSame([], $postep->prepared_ingredient_ids, 'PORCJE_2502_KROK_NIE_ZOSTAWIA_STARYCH');
        $this->assertContains((string) $krok->getKey(), $postep->done_step_ids);
    }

    public function test_powrot_do_porcji_z_przepisu_czysci_zapis(): void
    {
        $osoba = $this->user();
        [$recipe] = $this->przepis(null, 'public', 4);
        $this->wlacz($osoba, $recipe);
        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => '6']);

        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => 'przepis', 'krok' => 1])
            ->assertRedirect(route('cooking.show', ['recipe' => $recipe->slug, 'krok' => 1]));

        $this->assertNull($this->postep($osoba, $recipe)->servings);
        $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug))->assertOk()->assertDontSee('Przeliczone na');
    }

    public function test_nieprawidlowa_liczba_porcji_nie_zmienia_zapisu(): void
    {
        $osoba = $this->user();
        [$recipe] = $this->przepis(null, 'public', 4);
        $this->wlacz($osoba, $recipe);
        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => '6']);

        foreach (['0', '101', '-3', 'abc', '', '6; DROP TABLE'] as $zla) {
            $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => $zla])
                ->assertSessionHas('status_rodzaj', 'blad');
        }

        $this->assertEquals(6.0, (float) $this->postep($osoba, $recipe)->servings);
    }

    public function test_przepis_bez_podanych_porcji_nie_zapisuje_porcji(): void
    {
        $osoba = $this->user();
        [$recipe] = $this->przepis(null, 'public', null);
        $this->wlacz($osoba, $recipe);

        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => '6'])
            ->assertSessionHas('status_rodzaj', 'blad');

        $this->assertNull($this->postep($osoba, $recipe)->servings);
    }

    public function test_wlaczenie_bierze_porcje_z_adresu_a_odhaczenie_kroku_z_jawna_liczba_je_zapamietuje(): void
    {
        $osoba = $this->user();
        [$recipe] = $this->przepis(null, 'public', 4);
        $krok = $recipe->steps()->firstOrFail();

        $this->wlacz($osoba, $recipe, ['porcje' => '6']);
        $this->assertEquals(6.0, (float) $this->postep($osoba, $recipe)->servings);

        $this->actingAs($osoba)->post(route('cooking.zaznacz', $recipe->slug), [
            'krok_id' => $krok->getKey(), 'zrobiono' => '1', 'porcje' => '8',
        ])->assertRedirect();
        $this->assertEquals(8.0, (float) $this->postep($osoba, $recipe)->servings);
    }

    public function test_odhaczenie_kroku_ze_stara_strona_nie_cofa_porcji_zmienionych_na_innym_urzadzeniu(): void
    {
        // Regresja: formularz kroku niesie `porcje` z chwili wyświetlenia
        // strony. Gdy drugie urządzenie zmieniło od tamtej pory liczbę porcji,
        // odhaczenie kroku na pierwszym nie może jej po cichu cofnąć.
        $osoba = $this->user();
        [$recipe] = $this->przepis(null, 'public', 4);
        $krok = $recipe->steps()->firstOrFail();

        $this->wlacz($osoba, $recipe, ['porcje' => '6']);
        $widzianaRewizja = $this->postep($osoba, $recipe)->revision;

        // Drugie urządzenie: 8 porcji.
        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => '8', 'krok' => 1]);
        $this->assertEquals(8.0, (float) $this->postep($osoba, $recipe)->servings);

        // Pierwsze urządzenie odhacza krok ze strony, która pokazywała 6 porcji.
        $this->actingAs($osoba)->post(route('cooking.zaznacz', $recipe->slug), [
            'krok_id' => $krok->getKey(), 'zrobiono' => '1', 'porcje' => '6', 'krok' => 1, 'rewizja' => $widzianaRewizja,
        ])->assertRedirect(route('cooking.show', ['recipe' => $recipe->slug, 'krok' => 1]))
            ->assertSessionHas('status_rodzaj', 'informacja');

        $this->assertEquals(8.0, (float) $this->postep($osoba, $recipe)->servings);
        $this->assertContains((string) $krok->getKey(), $this->postep($osoba, $recipe)->done_step_ids);
    }

    // --- Prywatność i Policy --------------------------------------------------

    public function test_obca_osoba_nie_zmieni_ani_nie_zobaczy_cudzego_wiersza(): void
    {
        $osoba = $this->user();
        $obca = $this->user();
        [$recipe, $s] = $this->przepis();
        $this->wlacz($osoba, $recipe);
        $this->zapiszSkladniki($osoba, $recipe, [$s[0]]);
        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => '6']);

        // Obca osoba nie ma własnego wiersza — nie tworzy go ani nie dotyka cudzego.
        $this->zapiszSkladniki($obca, $recipe, [$s[1], $s[2]])->assertSessionHas('status_rodzaj', 'blad');
        $this->actingAs($obca)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => '2'])->assertSessionHas('status_rodzaj', 'blad');

        $this->assertSame(1, CookingProgress::query()->count());
        $wiersz = $this->postep($osoba, $recipe);
        $this->assertSame([], $wiersz->prepared_ingredient_ids, 'Zmiana porcji nie zostawia starych odmierzeń.');
        $this->assertEquals(6.0, (float) $wiersz->servings);

        $html = $this->actingAs($obca)->get(route('cooking.show', $recipe->slug))->assertOk()->getContent();
        $this->assertStringNotContainsString('Przeliczone na 6 porcji', $html);
        $this->assertStringNotContainsString('name="zaznaczone[]"', $html);
    }

    public function test_konto_bez_dostepu_do_przepisu_dostaje_odmowe(): void
    {
        $osoba = $this->user();
        [$recipe, $s] = $this->przepis(null, 'public');
        $this->wlacz($osoba, $recipe);
        $recipe->forceFill(['visibility' => 'private'])->save();

        $this->zapiszSkladniki($osoba, $recipe, [$s[0]])->assertForbidden();
        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => '6'])->assertForbidden();

        $this->assertSame([], $this->postep($osoba, $recipe)->prepared_ingredient_ids);
        $this->assertNull($this->postep($osoba, $recipe)->servings);
    }

    // --- Eksport i wymazanie ----------------------------------------------------

    public function test_paczka_danych_zawiera_porcje_i_skladniki_a_wymazanie_je_kasuje(): void
    {
        $osoba = $this->user();
        [$recipe, $s] = $this->przepis();
        $this->wlacz($osoba, $recipe);
        $this->zapiszSkladniki($osoba, $recipe, [$s[1]]);
        $this->actingAs($osoba)->post(route('cooking.sync.porcje', $recipe->slug), ['wybor' => '6']);
        $this->zapiszSkladniki($osoba, $recipe, [$s[1]]);

        $dane = app(CollectUserExportData::class)->handle($osoba, new ExportPhotoPlan($osoba), Carbon::now());

        $this->assertSame(6.0, $dane['postep_gotowania'][0]['wybrana_liczba_porcji']);
        $this->assertSame(1, $dane['postep_gotowania'][0]['przygotowane_skladniki_liczba']);
        $this->assertSame(['2 jajka'], $dane['postep_gotowania'][0]['przygotowane_skladniki']);

        // Przepis niewidoczny dla osoby: liczba tak, treść składników nie.
        $recipe->forceFill(['visibility' => 'private'])->save();
        $dane = app(CollectUserExportData::class)->handle($osoba, new ExportPhotoPlan($osoba), Carbon::now());
        $this->assertSame([], $dane['postep_gotowania'][0]['przygotowane_skladniki']);
        $this->assertSame(1, $dane['postep_gotowania'][0]['przygotowane_skladniki_liczba']);

        $osoba->markForDeletion();
        app(EraseAccountData::class)->handle($osoba->fresh());
        $this->assertNull($this->postep($osoba, $recipe));
    }
}
