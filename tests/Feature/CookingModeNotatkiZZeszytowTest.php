<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Podgląd własnych dopisków z zeszytów w trybie gotowania (#2433).
 */
class CookingModeNotatkiZZeszytowTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(User $autor): Recipe
    {
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        RecipeStep::create(['recipe_id' => $recipe->getKey(), 'position' => 0, 'instruction' => 'Krok pierwszy.']);

        return $recipe;
    }

    private function zapisz(User $wlasciciel, Recipe $recipe, string $nazwa, ?string $notatka, string $widocznosc = 'private'): Collection
    {
        $zeszyt = Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => $nazwa, 'visibility' => $widocznosc]);
        DB::table('collection_items')->insert([
            'collection_id' => $zeszyt->getKey(),
            'recipe_id' => $recipe->getKey(),
            'note' => $notatka,
            'created_at' => now(),
            'added_by_id' => $wlasciciel->getKey(),
        ]);

        return $zeszyt;
    }

    public function test_wlasciciel_widzi_wlasna_notatke_z_nazwa_zeszytu(): void
    {
        $osoba = $this->user('halina');
        $recipe = $this->przepis($this->user('autor'));
        $this->zapisz($osoba, $recipe, 'Niedzielne', 'Następnym razem mniej soli <b>x</b>');

        $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug))
            ->assertOk()
            ->assertSee('Moje notatki z zeszytów', false)
            ->assertSee('Zeszyt: Niedzielne', false)
            ->assertSee('Następnym razem mniej soli &lt;b&gt;x&lt;/b&gt;', false)
            ->assertDontSee('<b>x</b>', false)
            ->assertSee('<details class="cook-notatki-zeszytow">', false);
    }

    public function test_kilka_zeszytow_daje_osobne_pozycje(): void
    {
        $osoba = $this->user('halina2');
        $recipe = $this->przepis($this->user('autor2'));
        $this->zapisz($osoba, $recipe, 'Zeszyt A', 'Notatka alfa');
        $this->zapisz($osoba, $recipe, 'Zeszyt B', 'Notatka beta');

        $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug))
            ->assertSee('Zeszyt: Zeszyt A', false)->assertSee('Notatka alfa', false)
            ->assertSee('Zeszyt: Zeszyt B', false)->assertSee('Notatka beta', false)
            ->assertSee('Moje notatki z zeszytów (2)', false);
    }

    public function test_puste_notatki_nie_tworza_bloku(): void
    {
        $osoba = $this->user('halina3');
        $recipe = $this->przepis($this->user('autor3'));
        $this->zapisz($osoba, $recipe, 'Pusty', null);
        $this->zapisz($osoba, $recipe, 'Spacje', "   \n ");

        $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug))
            ->assertOk()
            ->assertDontSee('Moje notatki z zeszytów', false);
    }

    public function test_cudza_notatka_nie_jest_pokazana_innej_osobie_ani_gosciowi(): void
    {
        $wlasciciel = $this->user('halina4');
        $obca = $this->user('obca4');
        $recipe = $this->przepis($this->user('autor4'));
        $this->zapisz($wlasciciel, $recipe, 'Tajny zeszyt', 'Sekret Haliny');

        $this->actingAs($obca)->get(route('cooking.show', $recipe->slug))
            ->assertOk()
            ->assertDontSee('Sekret Haliny', false)
            ->assertDontSee('Tajny zeszyt', false)
            ->assertDontSee('Moje notatki z zeszytów', false);

        $this->app['auth']->forgetGuards();
        $this->get(route('cooking.show', $recipe->slug))
            ->assertOk()
            ->assertDontSee('Sekret Haliny', false)
            ->assertDontSee('Tajny zeszyt', false);
    }

    public function test_notatka_ze_wspolnego_albo_publicznego_zeszytu_nie_jest_pokazana(): void
    {
        $osoba = $this->user('halina5');
        $inna = $this->user('inna5');
        $recipe = $this->przepis($this->user('autor5'));
        $wspolny = $this->zapisz($osoba, $recipe, 'Wspólny', 'Notatka wspólna');
        DB::table('collection_members')->insert([
            'collection_id' => $wspolny->getKey(),
            'user_id' => $inna->getKey(),
            'created_at' => now(),
        ]);
        $this->zapisz($osoba, $recipe, 'Publiczny', 'Notatka publiczna', 'public');

        $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug))
            ->assertOk()
            ->assertDontSee('Notatka wspólna', false)
            ->assertDontSee('Notatka publiczna', false);
    }

    public function test_notatka_nie_dotyczy_innego_przepisu_i_znika_po_usunieciu_zapisu(): void
    {
        $osoba = $this->user('halina6');
        $recipe = $this->przepis($this->user('autor6'));
        $inny = $this->przepis($this->user('autor6b'));
        $zeszyt = $this->zapisz($osoba, $recipe, 'Obiady', 'Dopisek do pierwszego');

        $this->actingAs($osoba)->get(route('cooking.show', $inny->slug))
            ->assertOk()->assertDontSee('Dopisek do pierwszego', false);

        DB::table('collection_items')->where('collection_id', $zeszyt->getKey())->delete();

        $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug))
            ->assertOk()->assertDontSee('Dopisek do pierwszego', false);
    }
}
