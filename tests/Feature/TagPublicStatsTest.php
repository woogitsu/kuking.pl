<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tags\TagPublicStats;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TagPublicStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_liczy_unikalne_zdjecia_i_autorow_a_nie_wpisy(): void
    {
        $tag = Tag::factory()->create();
        $post = $this->photoPost($tag);
        $post->media()->attach(Media::factory()->create(['owner_id' => $post->author_id]), ['position' => 1]);
        $this->assertCounts($tag, 2, 1);
        $copy = Post::factory()->create(['author_id' => $post->author_id]);
        $copy->tags()->attach($tag);
        $copy->media()->attach($post->media()->firstOrFail());
        $this->assertCounts($tag, 2, 1);
        $this->photoPost($tag);
        $this->assertCounts($tag, 3, 2);
        Post::factory()->create()->tags()->attach($tag);
        $this->assertCounts($tag, 3, 2);
    }

    public function test_nie_liczy_prywatnych_szkicow_ukrytych_i_usunietych_wpisow(): void
    {
        $tag = Tag::factory()->create();
        foreach ([['visibility' => 'private'], ['visibility' => 'followers'], ['status' => 'draft', 'published_at' => null], ['status' => 'hidden'], ['status' => 'removed']] as $attributes) {
            $post = $this->photoPost($tag, $attributes);
            $this->actingAs($post->author);
            $this->assertCounts($tag, 0, 0);
        }
        $this->photoPost($tag)->delete();
        $this->assertCounts($tag, 0, 0);
        $this->photoPost($tag);
        $this->assertCounts($tag, 1, 1);
    }

    public function test_wymaga_aktywnego_autora_i_gotowego_medium(): void
    {
        $tag = Tag::factory()->create();
        foreach ([User::STATUS_SUSPENDED, User::STATUS_BANNED] as $status) {
            $author = User::factory()->create(['status' => $status]);
            $this->photoPost($tag, ['author_id' => $author->id]);
        }
        $post = $this->photoPost($tag);
        $post->media()->firstOrFail()->update(['status' => Media::STATUS_PENDING]);
        $this->assertCounts($tag, 0, 0);
        $post->media()->firstOrFail()->update(['status' => Media::STATUS_READY]);
        $this->assertCounts($tag, 1, 1);
    }

    public function test_przepis_musi_byc_dostepny_gosciowi(): void
    {
        $tag = Tag::factory()->create();
        foreach ([['visibility' => 'private'], ['visibility' => 'followers'], ['status' => 'draft', 'published_at' => null], ['status' => 'hidden']] as $attributes) {
            $recipe = Recipe::factory()->create($attributes);
            $this->photoPost($tag, ['recipe_id' => $recipe->id, 'author_id' => $recipe->author_id]);
        }
        $recipe = Recipe::factory()->create();
        $this->photoPost($tag, ['recipe_id' => $recipe->id, 'author_id' => $recipe->author_id]);
        $recipe->delete();
        $this->assertCounts($tag, 0, 0);
        $recipe->restore();
        $this->assertCounts($tag, 1, 1);
    }

    public function test_usuniety_autor_i_niegotowe_media_nie_podbijaja_statystyk(): void
    {
        $tag = Tag::factory()->create();
        $post = $this->photoPost($tag);
        $post->author->delete();
        $this->assertCounts($tag, 0, 0);
        foreach ([Media::STATUS_PENDING, Media::STATUS_PROCESSING, Media::STATUS_REJECTED, Media::STATUS_DELETED] as $status) {
            $post = $this->photoPost($tag);
            $post->media()->firstOrFail()->update(['status' => $status]);
            $this->assertCounts($tag, 0, 0);
        }
    }

    public function test_batch_ma_jedno_zapytanie_dla_dwoch_i_trzydziestu_tagow(): void
    {
        $tags = Tag::factory()->count(30)->create();
        foreach ($tags as $tag) {
            $this->photoPost($tag);
        }
        foreach ([2, 30] as $size) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $result = (new TagPublicStats)->forTags($tags->take($size)->modelKeys());
                $this->assertCount(1, DB::getQueryLog());
                $this->assertCount($size, $result);
                foreach ($result as $stats) {
                    $this->assertSame(['photosCount' => 1, 'contributorsCount' => 1], $stats);
                }
            } finally {
                DB::disableQueryLog();
            }
        }
    }

    public function test_puste_wejscie_nie_pyta_bazy_a_duplikaty_i_ukryte_tagi_daja_zera(): void
    {
        $tag = Tag::factory()->hidden()->create();
        $this->photoPost($tag);
        $this->assertCounts($tag, 0, 0);
        $this->assertCount(1, (new TagPublicStats)->forTags([$tag->id, $tag->id]));
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->assertSame([], (new TagPublicStats)->forTags([]));
            $this->assertCount(0, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    /** @param array<string, mixed> $attributes */
    private function photoPost(Tag $tag, array $attributes = []): Post
    {
        $post = Post::factory()->create($attributes);
        $post->tags()->attach($tag);
        $post->media()->attach(Media::factory()->create(['owner_id' => $post->author_id]));

        return $post;
    }

    private function assertCounts(Tag $tag, int $photos, int $authors): void
    {
        $this->assertSame(['photosCount' => $photos, 'contributorsCount' => $authors], (new TagPublicStats)->forTags([$tag->id])[$tag->id]);
    }
}
