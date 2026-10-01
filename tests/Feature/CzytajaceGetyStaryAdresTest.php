<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Czytające GET-y pod starym adresem przepisu (`recipe_slug_redirects`):
 * „Ugotowałem", „Szczegóły", „Edycja" i „Dodaj składniki na listę zakupów".
 *
 * Wzorzec jak na stronie przepisu: najpierw Policy, przy odmowie 404 bez
 * `Location` (slug powstaje z tytułu — nagłówek nie może zdradzić przepisu),
 * przy zgodzie 301 z zachowanym query. Zapisy (POST/PUT/DELETE) nie
 * przekierowują — 301 zamieniłby POST w GET.
 */
class CzytajaceGetyStaryAdresTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string, 2: string}> sufiks adresu, trasa, kto ma dostęp */
    public static function ekrany(): array
    {
        return [
            'ugotowalem' => ['ugotowalem', 'cooked.create', 'obcy'],
            'szczegoly' => ['szczegoly', 'recipes.details', 'autor'],
            'edycja' => ['edycja', 'recipes.edit', 'autor'],
            'lista zakupow' => ['lista-zakupow', 'shopping.recipe.confirm', 'obcy'],
        ];
    }

    private function przepis(User $autor, string $slug, array $atrybuty = []): Recipe
    {
        return Recipe::factory()->for($autor, 'author')->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
            'slug' => $slug,
            ...$atrybuty,
        ]);
    }

    private function stary(string $slug, Recipe $cel): void
    {
        DB::table('recipe_slug_redirects')->insert(['slug' => $slug, 'recipe_id' => $cel->getKey(), 'created_at' => now()]);
    }

    private function osoba(string $rola, User $autor): User
    {
        return $rola === 'autor' ? $autor : $this->user('obcy'.$rola.random_int(1, 99999));
    }

    #[DataProvider('ekrany')]
    public function test_stary_adres_przekierowuje_301_z_zachowanym_query(string $sufiks, string $trasa, string $kto): void
    {
        $autor = $this->user('autora'.substr(md5($sufiks), 0, 8));
        $przepis = $this->przepis($autor, 'zurek-nowy');
        $this->stary('zurek-stary', $przepis);

        $this->actingAs($this->osoba($kto, $autor))
            ->get('/przepisy/zurek-stary/'.$sufiks.'?z_planera=1&porcje=4')
            ->assertStatus(301)
            ->assertRedirect(route($trasa, ['recipe' => 'zurek-nowy', 'z_planera' => 1, 'porcje' => 4]));
    }

    #[DataProvider('ekrany')]
    public function test_odmowa_policy_daje_404_bez_location(string $sufiks, string $trasa, string $kto): void
    {
        $autor = $this->user('autorb'.substr(md5($sufiks), 0, 8));
        // Prywatny przepis: obcy nie przejdzie ani `view`, ani `cook`;
        // autor-ekrany (`update`) odmawiają każdemu poza autorem.
        $przepis = $this->przepis($autor, 'nalewka-wandy', ['visibility' => 'private']);
        $this->stary('stara-nalewka', $przepis);

        $odpowiedz = $this->actingAs($this->user('podgladacz'.substr(md5($sufiks), 0, 8)))
            ->get('/przepisy/stara-nalewka/'.$sufiks);

        $odpowiedz->assertNotFound();
        $this->assertNull($odpowiedz->headers->get('Location'));
    }

    #[DataProvider('ekrany')]
    public function test_nieznany_adres_daje_404(string $sufiks, string $trasa, string $kto): void
    {
        $this->actingAs($this->user('ktos'.substr(md5($sufiks), 0, 8)))
            ->get('/przepisy/nigdy-takiego-nie-bylo/'.$sufiks)
            ->assertNotFound();
    }

    #[DataProvider('ekrany')]
    public function test_aktualny_adres_dziala_bez_przekierowania(string $sufiks, string $trasa, string $kto): void
    {
        $autor = $this->user('autorc'.substr(md5($sufiks), 0, 8));
        $przepis = $this->przepis($autor, 'barszcz-biały');

        $odpowiedz = $this->actingAs($this->osoba($kto, $autor))->get('/przepisy/'.$przepis->slug.'/'.$sufiks);

        $this->assertNotSame(301, $odpowiedz->getStatusCode());
    }

    public function test_zapisu_pod_starym_adresem_nie_przekierowujemy(): void
    {
        $autor = $this->user('autorzapis');
        $przepis = $this->przepis($autor, 'sernik-nowy');
        $this->stary('sernik-stary', $przepis);

        $odpowiedz = $this->actingAs($autor)->post('/przepisy/sernik-stary/lista-zakupow');

        $this->assertNotSame(301, $odpowiedz->getStatusCode());
    }
}
