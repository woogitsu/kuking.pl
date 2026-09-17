<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PodpowiedziTagowEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_wiecej_podpowiedzi_nie_dodaje_zapytania_na_kazdy_licznik(): void
    {
        $viewer = $this->user();
        $author = $this->user();
        $measure = function (int $expected) use ($viewer): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $result = app(\App\Domain\Tags\PodpowiedziTagow::class)->dla('sernik', $viewer);
                $queries = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
            $this->assertCount($expected, $result['tags']);
            foreach ($result['tags'] as $tag) {
                $this->assertSame(1, $tag['public_posts_count']);
            }

            return $queries;
        };
        $small = null;
        for ($i = 1; $i <= 5; $i++) {
            $tag = Tag::create(['name' => 'sernik '.$i, 'normalized_name' => 'sernik '.$i, 'slug' => 'sernik-'.$i]);
            $post = Post::create(['author_id' => $author->id, 'body' => 'Pomiar',
                'visibility' => 'public', 'status' => Post::STATUS_PUBLISHED, 'published_at' => now()]);
            $post->tags()->attach($tag->id, ['position' => 0]);
            if ($i === 2) {
                $small = $measure(2);
            }
        }
        $large = $measure(5);
        $this->assertSame($small, $large, 'Liczba zapytań ma być stała dla dwóch i pięciu podpowiedzi.');
    }

    public function test_wymaga_konta_i_krotkiej_frazy_tekstowej(): void
    {
        $this->getJson(route('tags.suggestions', ['q' => 'sernik']))->assertUnauthorized();
        $this->actingAs($this->user());
        foreach (['', 's', str_repeat('s', 41), ['sernik']] as $query) {
            $this->getJson(route('tags.suggestions', ['q' => $query]))
                ->assertUnprocessable()->assertJsonValidationErrors('q');
        }
    }

    public function test_zwraca_tylko_jawny_kontrakt_i_publiczne_wpisy_bez_mutacji(): void
    {
        $viewer = $this->user('szukajaca');
        $author = $this->user('autorka');
        $tag = Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'sernik']);
        foreach (['public', 'private', 'followers'] as $visibility) {
            $post = Post::create([
                'author_id' => $author->id, 'body' => 'Kontrolny wpis',
                'visibility' => $visibility, 'status' => Post::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);
            $post->tags()->attach($tag->id, ['position' => 0]);
        }
        $before = [Tag::count(), Post::count()];
        $this->actingAs($viewer)->getJson(route('tags.suggestions', ['q' => 'sernik']))
            ->assertOk()->assertExactJson([
                'tags' => [['id' => $tag->id, 'name' => 'Sernik', 'slug' => 'sernik', 'public_posts_count' => 1]],
                'exact_match' => true, 'can_create' => false,
            ]);
        $this->assertSame($before, [Tag::count(), Post::count()]);
        $this->getJson(route('tags.suggestions', ['q' => 'unikalnynowytag']))
            ->assertOk()->assertJsonPath('can_create', true);
        $this->assertSame($before, [Tag::count(), Post::count()]);
    }

    public function test_ukryta_dokladna_nazwa_nie_wycieka_i_nie_proponuje_duplikatu(): void
    {
        $tag = Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'sernik']);
        $tag->forceFill(['status' => Tag::STATUS_HIDDEN])->save();
        $this->actingAs($this->user())->getJson(route('tags.suggestions', ['q' => 'sernik']))
            ->assertOk()->assertExactJson(['tags' => [], 'exact_match' => false, 'can_create' => false]);
    }

    public function test_dokladny_slug_jest_pierwszy_bez_duplikatu_takze_gdy_ma_40_znakow(): void
    {
        $this->actingAs($this->user());
        foreach (['zupa-pomidorowa', str_repeat('a', 29).'-1234567890'] as $index => $slug) {
            $tag = Tag::create([
                'name' => 'Zupa pomidorowa '.$index,
                'normalized_name' => 'zupa pomidorowa '.$index,
                'slug' => $slug,
            ]);
            $response = $this->getJson(route('tags.suggestions', ['q' => $slug]))
                ->assertOk()->assertJsonPath('tags.0.id', $tag->id)
                ->assertJsonPath('exact_match', true)->assertJsonPath('can_create', false);
            $ids = array_column($response->json('tags'), 'id');
            $this->assertSame($ids, array_values(array_unique($ids)));
            $this->assertLessThanOrEqual(config('kuking.tags.suggestions_limit'), count($ids));
            $tag->forceFill(['status' => Tag::STATUS_HIDDEN])->save();
            $response = $this->getJson(route('tags.suggestions', ['q' => $slug]))
                ->assertOk()->assertJsonPath('exact_match', false)->assertJsonPath('can_create', false);
            $this->assertNotContains($tag->id, array_column($response->json('tags'), 'id'));
        }
    }

    public function test_dluzsze_zapytanie_nie_pozwala_utworzyc_za_dlugiej_nazwy(): void
    {
        $this->actingAs($this->user());
        foreach ([31, 40] as $length) {
            $this->getJson(route('tags.suggestions', ['q' => str_repeat('z', $length)]))
                ->assertOk()->assertJsonPath('exact_match', false)->assertJsonPath('can_create', false);
        }
        $this->assertDatabaseCount('tags', 0);
    }

    public function test_licznik_respektuje_blokady_statusy_i_prywatny_przepis_wlasciciela(): void
    {
        $viewer = $this->user('widz');
        $tag = Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'sernik']);
        $this->actingAs($viewer);
        foreach (['public', 'blocked', 'blocked_reverse', 'suspended', 'hidden', 'deleted', 'private_recipe'] as $kind) {
            $author = $this->user();
            $post = Post::create([
                'author_id' => $author->id, 'body' => 'Próba', 'visibility' => 'public',
                'status' => 'published', 'published_at' => now(),
            ]);
            $post->tags()->attach($tag->id, ['position' => 0]);
            if ($kind === 'blocked') {
                $viewer->blocking()->attach($author->id);
            } elseif ($kind === 'blocked_reverse') {
                $author->blocking()->attach($viewer->id);
            } elseif ($kind === 'suspended') {
                $author->forceFill(['status' => 'suspended'])->save();
            } elseif ($kind === 'hidden') {
                $post->forceFill(['status' => 'hidden'])->save();
            } elseif ($kind === 'deleted') {
                $post->delete();
            } elseif ($kind === 'private_recipe') {
                $recipe = Recipe::factory()->create([
                    'author_id' => $viewer->id, 'visibility' => 'private',
                    'status' => 'published', 'published_at' => now(),
                ]);
                $post->forceFill(['recipe_id' => $recipe->id])->save();
            }
        }
        $this->getJson(route('tags.suggestions', ['q' => 'sernik']))
            ->assertOk()->assertJsonPath('tags.0.public_posts_count', 1);
    }
}
