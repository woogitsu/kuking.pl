<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wyszukiwarka MVP: PostgreSQL + pg_trgm + unaccent.
 *
 * Te testy MUSZĄ chodzić na PostgreSQL — SQLite nie ma ani unaccent,
 * ani similarity(), więc przechodziłyby na zielono nic nie sprawdzając.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_znajduje_przepis_mimo_braku_polskich_znakow_w_zapytaniu(): void
    {
        $basia = $this->user('basia');
        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Żurek z jajkiem',
            'slug' => 'zurek-z-jajkiem',
        ]);

        // Osoba 60+ na telefonie często nie przełącza się na polską klawiaturę.
        $wyniki = app(SearchQuery::class)->recipes('zurek');

        $this->assertCount(1, $wyniki);
        $this->assertSame('Żurek z jajkiem', $wyniki->first()->title);
    }

    public function test_znajduje_przepis_po_skladniku(): void
    {
        $basia = $this->user('basia');
        $recipe = Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Coś zupełnie inaczej nazwane',
            'slug' => 'cos-zupelnie-inaczej-nazwane',
        ]);

        RecipeIngredient::create([
            'recipe_id' => $recipe->getKey(),
            'ingredient_text' => 'kiszona kapusta',
            'position' => 0,
        ]);

        $wyniki = app(SearchQuery::class)->recipes('kapusta');

        $this->assertCount(1, $wyniki);
    }

    public function test_nie_pokazuje_szkicow_ani_tresci_prywatnych(): void
    {
        $basia = $this->user('basia');

        Recipe::factory()->draft()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Sekretny żurek',
            'slug' => 'sekretny-zurek',
        ]);

        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Prywatny żurek',
            'slug' => 'prywatny-zurek',
            'visibility' => 'private',
        ]);

        $this->assertCount(0, app(SearchQuery::class)->recipes('zurek'));
    }

    public function test_znajduje_osobe_po_imieniu_i_po_specjalnosci(): void
    {
        $basia = $this->user('basia_z_podkarpacia', ['display_name' => 'Basia']);
        $basia->profile->update(['speciality' => 'zupy i kiszonki']);

        $this->assertCount(1, app(SearchQuery::class)->people('Basia'));
        $this->assertCount(1, app(SearchQuery::class)->people('kiszonki'));
    }

    public function test_bardzo_krotkie_zapytanie_nie_obciaza_bazy(): void
    {
        $this->assertCount(0, app(SearchQuery::class)->recipes('a'));
        $this->assertCount(0, app(SearchQuery::class)->people(''));
    }

    public function test_strona_wyszukiwania_nie_jest_indeksowana(): void
    {
        $this->get(route('search', ['q' => 'rosol']))
            ->assertOk()
            ->assertSee('noindex', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
