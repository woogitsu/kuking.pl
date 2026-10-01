<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Formularz z własnego wpisu pokazuje prywatne dane autora (#1334). */
final class DopiszPrzepisCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_formularz_autora_i_przekierowanie_goscia_nie_trafiaja_do_wspolnego_cache(): void
    {
        config(['kuking.html_cache.edge_seconds' => 120]);
        Storage::fake('public');

        $autor = $this->user('autorka');
        $zdjecie = Media::factory()->create(['owner_id' => $autor->getKey()]);
        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);
        $adres = route('recipes.create.from-post', $wpis);

        // Kontrola dodatnia: cache brzegu jest włączony dla dozwolonej strony.
        $publiczny = $this->get('/')->assertOk();
        $this->assertTrue($publiczny->headers->hasCacheControlDirective('public'));

        $zalogowany = $this->actingAs($autor)->get($adres)->assertOk();
        $this->assertStringContainsString('private', (string) $zalogowany->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $zalogowany->headers->get('Cache-Control'));
        $zalogowany->assertHeaderMissing('CDN-Cache-Control')
            ->assertHeaderMissing('Cloudflare-CDN-Cache-Control');

        $this->app['auth']->logout();
        $gosc = $this->get($adres)->assertRedirect(route('login'));
        $this->assertStringContainsString('private', (string) $gosc->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $gosc->headers->get('Cache-Control'));
        $gosc->assertHeaderMissing('CDN-Cache-Control')
            ->assertHeaderMissing('Cloudflare-CDN-Cache-Control');
    }
}
