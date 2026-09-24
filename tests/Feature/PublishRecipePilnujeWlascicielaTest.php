<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishRecipePilnujeWlascicielaTest extends TestCase
{
    use RefreshDatabase;

    public function test_wlasciciel_nadal_tworzy_i_edytuje_przepis_bez_zmiany_autora(): void
    {
        $autor = $this->user('wlasciciel_przepisu');

        $przepis = app(PublishRecipe::class)->handle(
            author: $autor,
            attributes: ['title' => 'Zupa przed zmianą'],
        );

        $poEdycji = app(PublishRecipe::class)->handle(
            author: $autor,
            attributes: ['title' => 'Zupa po zmianie'],
            existing: $przepis,
        );

        $this->assertSame('Zupa po zmianie', $poEdycji->title);
        $this->assertSame($autor->getKey(), $poEdycji->author_id);
    }

    public function test_obcy_i_moderator_nie_moga_zmienic_tresci_relacji_stanu_mediow_ani_autora(): void
    {
        $autor = $this->user('autorka_chronionego_przepisu');
        $obcy = $this->user('obcy_edytor');
        $moderator = $this->moderator();
        $zdjecie = Media::factory()->for($autor, 'owner')->create(['status' => 'ready']);
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => 'Nienaruszony rosół',
            'hero_media_id' => $zdjecie->getKey(),
        ]);
        $przepis->ingredients()->create([
            'position' => 0,
            'ingredient_text' => 'kura',
            'no_amount' => true,
        ]);
        $przepis->steps()->create([
            'position' => 0,
            'instruction' => 'Gotować powoli.',
        ]);
        $stanPrzed = $this->stan($przepis);

        foreach ([$obcy, $moderator] as $aktor) {
            try {
                app(PublishRecipe::class)->handle(
                    author: $aktor,
                    attributes: ['title' => 'Przejęty przepis', 'visibility' => 'private'],
                    ingredients: [['text' => 'obcy składnik']],
                    steps: [['instruction' => 'Obcy krok.']],
                    publish: false,
                    existing: $przepis,
                );

                $this->fail('Akcja pozwoliła zmienić cudzy przepis bezpośrednio poza kontrolerem.');
            } catch (AuthorizationException) {
                $this->assertSame($stanPrzed, $this->stan($przepis), 'Odmowa zostawiła częściową zmianę przepisu.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function stan(Recipe $przepis): array
    {
        $przepis->refresh();

        return [
            'recipe' => [
                ...$przepis->only([
                    'author_id', 'title', 'slug', 'status', 'visibility', 'hero_media_id',
                ]),
                'published_at' => $przepis->published_at?->toISOString(),
                'updated_at' => $przepis->updated_at?->toISOString(),
            ],
            'ingredients' => $przepis->ingredients()->orderBy('position')->get()
                ->map->only(['id', 'position', 'ingredient_text', 'no_amount'])->all(),
            'steps' => $przepis->steps()->orderBy('position')->get()
                ->map->only(['id', 'position', 'instruction', 'media_id'])->all(),
        ];
    }
}
