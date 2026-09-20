<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WydrukPrzepisuFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_pelne_przepisy_do_pomiaru_a4(): void
    {
        $owner = $this->user('autor765', ['display_name' => 'Autorka przepisu A4']);
        $photo = Media::factory()->create(['owner_id' => $owner->id]);
        foreach (['krotki' => 3, 'dlugi' => 14] as $name => $count) {
            $recipe = Recipe::factory()->create([
                'author_id' => $owner->id, 'slug' => 'wydruk-'.$name,
                'title' => 'Pierogi z kapustą — '.$name,
                'summary' => 'Przepis kontrolny z grupami składników i zdjęciami.',
                'servings' => 4, 'prep_minutes' => 30, 'cook_minutes' => 20,
                'source_type' => 'external', 'source_url' => 'https://example.org/przepis-pierogi',
                'hero_media_id' => $photo->id,
            ]);
            foreach (['Ciasto', 'Farsz'] as $groupIndex => $group) {
                for ($i = 0; $i < ($name === 'dlugi' ? 9 : 3); $i++) {
                    $recipe->ingredients()->create([
                        'position' => $groupIndex * 10 + $i, 'group_name' => $group,
                        'ingredient_text' => $group.' składnik '.($i + 1),
                        'note' => 'drobno posiekany', 'no_amount' => $i === 0,
                    ]);
                }
            }
            for ($i = 0; $i < $count; $i++) {
                $recipe->steps()->create([
                    'position' => $i,
                    'instruction' => 'Początek kroku '.($i + 1).'. '.str_repeat('Wymieszaj składniki, zagnieć ciasto i odstaw pod przykryciem. ', $name === 'dlugi' && $i === 4 ? 55 : 2).'Koniec kroku '.($i + 1).'.',
                    'media_id' => $i === 0 ? $photo->id : null,
                ]);
            }
            foreach (['light', 'dark'] as $theme) {
                $owner->forceFill(['theme' => $theme])->save();
                $response = $this->actingAs($owner->fresh())->get(route('recipes.show', $recipe->slug))->assertOk();
                $response->assertSee('Koniec kroku '.$count.'.');
                if (getenv('PRINT_FIXTURES')) {
                    $directory = base_path('output/playwright/druk765');
                    if (! is_dir($directory)) {
                        mkdir($directory, 0755, true);
                    }
                    file_put_contents($directory.'/'.$name.'-'.$theme.'.html', $response->getContent());
                }
            }
        }
    }
}
