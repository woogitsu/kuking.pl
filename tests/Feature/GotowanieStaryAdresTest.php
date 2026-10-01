<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tryb gotowania pod starym adresem przepisu (`recipe_slug_redirects`).
 *
 * Strona przepisu, historia i karta QR przekierowują 301 na aktualny slug;
 * `/przepisy/{stary}/gotuj` dawał 404. Tu: 301 z zachowaniem `?krok=`
 * i `?porcje=`, za tą samą bramką `view`, a odmowa to 404 bez `Location`
 * (slug powstaje z tytułu — nagłówek nie może zdradzić przepisu).
 */
class GotowanieStaryAdresTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(User $autor, string $slug, array $atrybuty = []): Recipe
    {
        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
            'slug' => $slug,
            ...$atrybuty,
        ]);

        foreach ([0, 1, 2] as $i) {
            RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => $i, 'instruction' => 'Krok '.($i + 1)]);
        }

        return $przepis;
    }

    private function stary(string $slug, Recipe $cel): void
    {
        DB::table('recipe_slug_redirects')->insert(['slug' => $slug, 'recipe_id' => $cel->getKey(), 'created_at' => now()]);
    }

    public function test_stary_adres_trybu_gotowania_przekierowuje_301_z_krokiem(): void
    {
        $przepis = $this->przepis($this->user('kucharz1'), 'rosol-niedzielny');
        $this->stary('stary-rosol', $przepis);

        $this->get('/przepisy/stary-rosol/gotuj?krok=2')
            ->assertStatus(301)
            ->assertRedirect(route('cooking.show', ['recipe' => 'rosol-niedzielny', 'krok' => 2]));
    }

    public function test_stary_adres_przepisu_prywatnego_daje_404_bez_location(): void
    {
        $przepis = $this->przepis($this->user('kucharz2'), 'nalewka-babci-wandy', ['visibility' => 'private']);
        $this->stary('stara-nalewka', $przepis);

        $odpowiedz = $this->actingAs($this->user('obcy2'))->get('/przepisy/stara-nalewka/gotuj');

        $odpowiedz->assertNotFound();
        $this->assertNull($odpowiedz->headers->get('Location'));
    }

    public function test_autor_trafia_starym_adresem_na_swoj_prywatny_przepis(): void
    {
        $autor = $this->user('kucharz3');
        $przepis = $this->przepis($autor, 'moje-zapiski', ['visibility' => 'private']);
        $this->stary('stare-zapiski', $przepis);

        $this->actingAs($autor)->get('/przepisy/stare-zapiski/gotuj')
            ->assertStatus(301)
            ->assertRedirect(route('cooking.show', 'moje-zapiski'));
    }

    public function test_nieznany_adres_trybu_gotowania_dalej_daje_404(): void
    {
        $this->get('/przepisy/nigdy-takiego-nie-bylo/gotuj')->assertNotFound();
    }
}
