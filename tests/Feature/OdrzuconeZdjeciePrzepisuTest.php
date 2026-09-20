<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OdrzuconeZdjeciePrzepisuTest extends TestCase
{
    use RefreshDatabase;

    public function test_autor_dostaje_wymiane_zdjecia_w_przepisie_i_trybie_gotowania(): void
    {
        $owner = $this->user();
        $media = Media::factory()->pending()->create(['owner_id' => $owner->id, 'status' => Media::STATUS_REJECTED]);
        $recipe = Recipe::factory()->family()->create(['author_id' => $owner->id, 'hero_media_id' => $media->id, 'source_scan_media_id' => $media->id]);
        $recipe->steps()->create(['position' => 1, 'instruction' => 'Zagotuj wodę.', 'media_id' => $media->id]);
        $this->actingAs($owner);
        foreach ([route('recipes.show', $recipe->slug), route('cooking.show', $recipe->slug)] as $url) {
            $response = $this->get($url)->assertOk();
            $response->assertDontSee('Wpis możesz usunąć');
            $response->assertSee('Zmień zdjęcie w przepisie');
            $response->assertSee('href="'.route('recipes.edit', $recipe->slug).'"', false);
        }
        $this->actingAs($this->user())->get(route('recipes.show', $recipe->slug))->assertOk()->assertDontSee('Zmień zdjęcie w przepisie');
        $recipe->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();
        $this->actingAs($owner)->get(route('recipes.show', $recipe->slug))->assertOk()->assertDontSee('Zmień zdjęcie w przepisie')->assertDontSee('Wpis możesz usunąć');
        $recipe->forceFill(['status' => Recipe::STATUS_PUBLISHED])->save();
        $owner->suspend(now()->addDay());
        $this->actingAs($owner->fresh())->get(route('recipes.show', $recipe->slug))->assertOk()->assertDontSee('Zmień zdjęcie w przepisie')->assertDontSee('Wpis możesz usunąć');
    }
}
