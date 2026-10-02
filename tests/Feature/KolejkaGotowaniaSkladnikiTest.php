<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Podgląd składników aktywnej potrawy w kolejce gotowania (#2469).
 */
class KolejkaGotowaniaSkladnikiTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<array<string, mixed>>  $skladniki */
    private function przepis(User $autor, string $tytul, array $skladniki, string $widocznosc = 'public'): Recipe
    {
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => $tytul, 'visibility' => $widocznosc, 'servings' => 4]);
        $przepis->forceFill(['status' => Recipe::STATUS_PUBLISHED, 'published_at' => now()])->save();

        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'instruction' => $tytul.': krok 1.', 'timer_seconds' => 600]);

        foreach ($skladniki as $i => $skladnik) {
            RecipeIngredient::create(['recipe_id' => $przepis->getKey(), 'position' => $i] + $skladnik);
        }

        return $przepis->refresh();
    }

    public function test_podglad_pokazuje_skladniki_tylko_aktywnej_potrawy_i_przelacza_sie(): void
    {
        $autor = $this->user('autorskl');
        $zupa = $this->przepis($autor, 'Zupa', [['ingredient_text' => '2 litry bulionu']]);
        $sos = $this->przepis($autor, 'Sos', [['ingredient_text' => '200 ml mleka']]);
        $p = $zupa->slug.':1,'.$sos->slug.':1';

        $this->get(route('kolejka-gotowania', ['p' => $p, 'a' => $sos->slug]))
            ->assertOk()
            ->assertSee('Składniki: Sos (1)', false)
            ->assertSee('200 ml mleka')
            ->assertDontSee('2 litry bulionu')
            ->assertSee('<details class="cook-ingredients" data-kolejka-skladniki>', false)
            ->assertSee('Ilości podał autor na 4 porcje', false);

        $this->get(route('kolejka-gotowania', ['p' => $p, 'a' => $zupa->slug]))
            ->assertSee('2 litry bulionu')
            ->assertDontSee('200 ml mleka');
    }

    public function test_grupy_uwagi_zamienniki_i_do_smaku_bez_mnozenia(): void
    {
        $przepis = $this->przepis($this->user('autorgr'), 'Ciasto drożdżowe', [
            ['group_name' => 'Ciasto', 'ingredient_text' => '500 g mąki', 'note' => 'przesiana', 'substitutes' => 'mąka orkiszowa'],
            ['group_name' => 'Posypka', 'ingredient_text' => 'cukier puder', 'no_amount' => true],
        ]);

        $this->get(route('kolejka-gotowania', ['p' => $przepis->slug.':1']))
            ->assertOk()
            ->assertSeeInOrder(['Ciasto', '500 g mąki', 'przesiana', 'Zamiast tego: mąka orkiszowa', 'Posypka', 'cukier puder', 'do smaku']);
    }

    public function test_skladniki_nie_trafiaja_do_danych_dla_skryptu(): void
    {
        $przepis = $this->przepis($this->user('autorjs'), 'Zupa', [['ingredient_text' => 'sekretny składnik 77']]);

        $html = $this->get(route('kolejka-gotowania', ['p' => $przepis->slug.':1']))->getContent();

        preg_match('/data-kolejka-dane="([^"]*)"/', $html, $m);
        $this->assertNotEmpty($m);
        $this->assertStringNotContainsString('sekretny', html_entity_decode($m[1]));
        $this->assertStringContainsString('sekretny składnik 77', $html);
    }

    public function test_brak_skladnikow_ma_komunikat(): void
    {
        $przepis = $this->przepis($this->user('autorpusty'), 'Pusty', []);

        $this->get(route('kolejka-gotowania', ['p' => $przepis->slug.':1']))
            ->assertSee('Składniki: Pusty (0)', false)
            ->assertSee('Autor jeszcze nie dodał składników.');
    }

    public function test_przepis_niedostepny_nie_zdradza_skladnikow_a_stary_slug_dziala(): void
    {
        $autor = $this->user('autorpriv');
        $prywatny = $this->przepis($autor, 'Nalewka', [['ingredient_text' => 'wisnie tajne']], 'private');
        $zupa = $this->przepis($autor, 'Zupa', [['ingredient_text' => 'marchew jawna']]);
        DB::table('recipe_slug_redirects')->insert(['slug' => 'stara-zupa', 'recipe_id' => $zupa->getKey(), 'created_at' => now()]);

        $this->get(route('kolejka-gotowania', ['p' => $prywatny->slug.':1,stara-zupa:1', 'a' => $prywatny->slug]))
            ->assertOk()
            ->assertDontSee('wisnie tajne')
            ->assertSee('marchew jawna');
    }

    public function test_skladniki_ladowane_jednym_zapytaniem_niezaleznie_od_liczby_przepisow(): void
    {
        $autor = $this->user('autorzap');
        $slugi = [];
        for ($i = 1; $i <= 4; $i++) {
            $slugi[] = $this->przepis($autor, 'Potrawa '.$i, [['ingredient_text' => 'a'.$i], ['ingredient_text' => 'b'.$i]])->slug.':1';
        }

        DB::enableQueryLog();
        $this->get(route('kolejka-gotowania', ['p' => implode(',', $slugi)]))->assertOk();
        $zapytania = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'recipe_ingredients'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $zapytania);
    }
}
