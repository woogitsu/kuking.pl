<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Migawka wersji przepisu zachowuje adres źródła i wybór „Bez ilości"
 * (issue #896).
 *
 * Przed poprawką `SnapshotRecipeVersion` kopiował `source_type`,
 * `source_person`, `source_note` i `family_since_year`, ale pomijał
 * `source_url`, a przy składniku pomijał `no_amount`. Dwie wersje różniące
 * się tylko „Bez ilości" dawały IDENTYCZNĄ migawkę, a zmiana adresu źródła
 * nie zostawiała w historii żadnego śladu.
 *
 * Testy mierzą RÓŻNICĘ między dwiema zapisanymi migawkami, a nie obecność
 * słowa w kodzie — tak żąda issue.
 */
class MigawkaPrzepisuZachowujeZrodloIBezIlosciTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $atrybuty
     * @param  list<array<string, mixed>>  $skladniki
     */
    private function publikuj(User $autorka, array $atrybuty, array $skladniki, ?Recipe $istniejacy = null): Recipe
    {
        return app(PublishRecipe::class)->handle(
            author: $autorka,
            attributes: ['title' => 'Rosół babci Zofii', 'visibility' => 'public', ...$atrybuty],
            ingredients: $skladniki,
            steps: [['instruction' => 'Zalej wodą i gotuj trzy godziny.']],
            publish: true,
            existing: $istniejacy,
        );
    }

    /** @return array<string, mixed> */
    private function migawka(Recipe $przepis, int $numer): array
    {
        return RecipeVersion::query()
            ->where('recipe_id', $przepis->getKey())
            ->where('version_number', $numer)
            ->firstOrFail()
            ->snapshot;
    }

    public function test_wybor_bez_ilosci_rozroznia_dwie_migawki(): void
    {
        $autorka = $this->user('autorka');
        $zrodlo = ['source_type' => 'own'];

        $przepis = $this->publikuj($autorka, $zrodlo, [['text' => 'sól', 'no_amount' => false]]);
        $przepis = $this->publikuj($autorka, $zrodlo, [['text' => 'sól', 'no_amount' => true]], $przepis);

        $pierwsza = $this->migawka($przepis, 1);
        $druga = $this->migawka($przepis, 2);

        $this->assertNull($pierwsza['ingredients'][0]['quantity']);
        $this->assertNull($druga['ingredients'][0]['quantity']);

        $this->assertFalse($pierwsza['ingredients'][0]['no_amount']);
        $this->assertTrue($druga['ingredients'][0]['no_amount']);
        $this->assertNotSame($pierwsza, $druga, 'Dwie wersje różniące się „Bez ilości" dały identyczną migawkę.');
    }

    public function test_adres_zrodla_trafia_do_migawki_i_jego_usuniecie_tez(): void
    {
        $autorka = $this->user('autorka');
        $skladniki = [['text' => '1 kurczak']];

        $przepis = $this->publikuj($autorka, [
            'source_type' => 'external',
            'source_url' => 'https://przyklad.test/rosol',
        ], $skladniki);

        // Kontrola pustego źródła: ten sam przepis przepisany na własny,
        // bez adresu. Migawka ma mieć jawne `null`, nie brak klucza.
        $przepis = $this->publikuj($autorka, ['source_type' => 'own', 'source_url' => ''], $skladniki, $przepis);

        $pierwsza = $this->migawka($przepis, 1);
        $druga = $this->migawka($przepis, 2);

        $this->assertSame('https://przyklad.test/rosol', $pierwsza['source_url']);
        $this->assertArrayHasKey('source_url', $druga);
        $this->assertNull($druga['source_url']);
    }
}
