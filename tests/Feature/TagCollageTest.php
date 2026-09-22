<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tags\TagCollage;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TagCollageTest extends TestCase
{
    use RefreshDatabase;

    public function test_wybiera_piec_osob_bez_zapasu_ktory_zaslanialby_starszych_autorow(): void
    {
        $tag = Tag::factory()->create();
        $expected = [];
        for ($i = 0; $i < 6; $i++) {
            $post = $this->photoPost($tag, ['published_at' => now()->subDays($i + 1)]);
            $expected[] = $post->media->first()->id;
        }
        $author = User::factory()->create();
        for ($i = 0; $i < 45; $i++) {
            $post = $this->photoPost($tag, ['author_id' => $author->id, 'published_at' => now()->subMinutes($i)]);
            if ($i === 0) {
                $newest = $post->media->first()->id;
            }
        }
        $photos = $this->photos($tag);
        $this->assertSame([$newest, ...array_slice($expected, 0, 4)], $photos->modelKeys());
        $this->assertCount(5, $photos->map(fn (Media $m) => $m->posts->first()->author_id)->unique());
    }

    public function test_remisy_dat_rozstrzyga_id_wpisu_a_potem_deterministyczny_wybor_zdjecia(): void
    {
        $tag = Tag::factory()->create();
        $date = '2026-09-01 12:00:00+00';
        $first = $this->photoPost($tag, ['id' => $this->uuid(1), 'published_at' => $date]);
        $second = $this->photoPost($tag, ['id' => $this->uuid(2), 'published_at' => $date]);
        $second->media()->detach();
        foreach ([10, 20] as $id) {
            $second->media()->attach(Media::factory()->create(['id' => $this->uuid($id), 'owner_id' => $second->author_id, 'created_at' => $date]), ['position' => $id]);
        }
        // Po identycznych datach zdjęć kolejność ustawiona we wpisie wygrywa.
        $expected = [$this->uuid(10), $first->media->first()->id];
        $this->assertSame($expected, $this->photos($tag)->modelKeys());
        $this->assertSame($expected, $this->photos($tag)->modelKeys());
        $second->media()->whereKey($this->uuid(20))->firstOrFail()->forceFill(['created_at' => '2026-09-02 12:00:00+00'])->save();
        $this->assertSame([$this->uuid(20), $expected[1]], $this->photos($tag)->modelKeys());
    }

    public function test_deduplikuje_medium_i_nie_miesza_rodzicow_miedzy_tagami(): void
    {
        $one = Tag::factory()->create();
        $two = Tag::factory()->create();
        $older = $this->photoPost($one, ['published_at' => now()->subDay()]);
        $photo = $older->media->first();
        $newer = Post::factory()->create();
        $newer->tags()->attach([$one->id => ['position' => 0], $two->id => ['position' => 1]]);
        $newer->media()->attach($photo);
        $onlyOne = Post::factory()->create(['published_at' => now()->addMinute()]);
        $onlyOne->tags()->attach($one);
        $onlyOne->media()->attach($photo);
        $private = Post::factory()->private()->create();
        $private->media()->attach($photo);
        $result = (new TagCollage)->forTags([$one->id, $two->id, $one->id]);
        $this->assertCount(2, $result);
        foreach ([$one, $two] as $tag) {
            $this->assertSame([$photo->id], $result[$tag->id]->modelKeys());
            $this->assertCount(1, $result[$tag->id]->first()->posts);
        }
        $this->assertSame($onlyOne->id, $result[$one->id]->first()->posts->first()->id);
        $this->assertSame($newer->id, $result[$two->id]->first()->posts->first()->id);
        $this->assertNotSame($result[$one->id]->first(), $result[$two->id]->first());
    }

    public function test_wymaga_publicznego_opublikowanego_wpisu_takze_dla_wlasciciela(): void
    {
        $tag = Tag::factory()->create();
        $good = $this->photoPost($tag);
        foreach ([['visibility' => 'private'], ['visibility' => 'followers'], ['status' => 'draft', 'published_at' => null], ['status' => 'hidden'], ['status' => 'removed']] as $state) {
            $bad = $this->photoPost($tag, $state);
            $this->assertSame($good->media->modelKeys(), $this->photos($tag, $bad->author)->modelKeys());
        }
        $this->photoPost($tag)->delete();
        $this->assertSame($good->media->modelKeys(), $this->photos($tag)->modelKeys());
    }

    public function test_odrzuca_nieaktywnych_autorow_i_niegotowe_media(): void
    {
        $tag = Tag::factory()->create();
        $good = $this->photoPost($tag);
        foreach (['suspended', 'banned', 'pending_delete', 'erased'] as $status) {
            $author = User::factory()->create(['status' => $status, 'data_erased_at' => $status === 'erased' ? now() : null]);
            $this->photoPost($tag, ['author_id' => $author->id]);
        }
        $deleted = $this->photoPost($tag);
        $deleted->author->delete();
        foreach (['pending', 'processing', 'rejected', 'deleted'] as $status) {
            $post = $this->photoPost($tag);
            $post->media->first()->update(['status' => $status]);
        }
        $this->assertSame($good->media->modelKeys(), $this->photos($tag)->modelKeys());
    }

    public function test_niedostepny_przepis_jest_odfiltrowany_przed_wyborem_autora(): void
    {
        $tag = Tag::factory()->create();
        $good = $this->photoPost($tag, ['published_at' => now()->subDay()]);
        foreach ([['visibility' => 'private'], ['visibility' => 'followers'], ['status' => 'draft', 'published_at' => null], ['status' => 'hidden']] as $state) {
            $recipe = Recipe::factory()->create($state);
            $this->photoPost($tag, ['author_id' => $good->author_id, 'recipe_id' => $recipe->id]);
            $this->assertSame($good->media->modelKeys(), $this->photos($tag, $recipe->author)->modelKeys());
        }
        $recipe = Recipe::factory()->create();
        $this->photoPost($tag, ['author_id' => $good->author_id, 'recipe_id' => $recipe->id]);
        $recipe->delete();
        $this->assertSame($good->media->modelKeys(), $this->photos($tag)->modelKeys());
    }

    public function test_blokady_wpisu_i_przepisu_w_obie_strony_poprzedzaja_wybor(): void
    {
        $tag = Tag::factory()->create();
        $viewer = User::factory()->create();
        $good = $this->photoPost($tag, ['published_at' => now()->subDay()]);
        foreach ([true, false] as $outgoing) {
            $blocked = $this->photoPost($tag);
            $this->block($outgoing ? $viewer : $blocked->author, $outgoing ? $blocked->author : $viewer);
            $recipe = Recipe::factory()->create();
            $this->block($outgoing ? $viewer : $recipe->author, $outgoing ? $recipe->author : $viewer);
            $this->photoPost($tag, ['author_id' => $good->author_id, 'recipe_id' => $recipe->id]);
        }
        $this->assertSame($good->media->modelKeys(), $this->photos($tag, $viewer)->modelKeys());
        $this->assertCount(3, $this->photos($tag));
    }

    public function test_puste_wejscie_i_ukryte_lub_scalone_tagi_nie_daja_zdjec(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame([], (new TagCollage)->forTags([]));
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
        $active = Tag::factory()->create();
        foreach (['hidden', 'merged'] as $status) {
            $tag = Tag::factory()->create(['status' => $status, 'merged_into_tag_id' => $status === 'merged' ? $active->id : null]);
            $this->photoPost($tag);
            $this->assertTrue($this->photos($tag)->isEmpty());
        }
        $this->assertTrue($this->photos($active)->isEmpty());
        $post = $this->photoPost($active);
        $this->assertSame($post->media->modelKeys(), $this->photos($active)->modelKeys());
    }

    public function test_batch_dwa_i_trzydziesci_tagow_ma_stala_liczbe_zapytan_z_relacjami(): void
    {
        $tags = Tag::factory()->count(30)->create();
        foreach ($tags as $tag) {
            $this->photoPost($tag);
        }
        $counts = [];
        foreach ([2, 30] as $size) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $result = (new TagCollage)->forTags($tags->take($size)->modelKeys());
                $this->assertCount($size, $result);
                foreach ($result as $photos) {
                    $this->assertInstanceOf(Collection::class, $photos);
                    $this->assertCount(1, $photos);
                    $photo = $photos->first();
                    $this->assertTrue($photo->relationLoaded('posts'));
                    $parent = $photo->posts->first();
                    $this->assertTrue($parent->relationLoaded('author'));
                    $this->assertTrue($parent->author->relationLoaded('profile'));
                    $this->assertNotEmpty($parent->author->displayName());
                    $this->assertStringContainsString('/zdjecia/'.$photo->id.'/', $photo->url());
                }
                $counts[] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        }
        $this->assertSame($counts[0], $counts[1]);
        $this->assertLessThanOrEqual(5, $counts[1]);
    }

    /** @param array<string, mixed> $attributes */
    private function photoPost(Tag $tag, array $attributes = []): Post
    {
        $post = Post::factory()->create($attributes);
        $post->tags()->attach($tag);
        $post->media()->attach(Media::factory()->create(['owner_id' => $post->author_id]));

        return $post->load('media');
    }

    /** @return Collection<int, Media> */
    private function photos(Tag $tag, ?User $viewer = null): Collection
    {
        return (new TagCollage)->forTags([$tag->id], $viewer)[$tag->id];
    }

    private function block(User $from, User $to): void
    {
        DB::table('blocks')->insert(['blocker_id' => $from->id, 'blocked_id' => $to->id, 'created_at' => now()]);
    }

    private function uuid(int $number): string
    {
        return '00000000-0000-4000-8000-'.str_pad((string) $number, 12, '0', STR_PAD_LEFT);
    }
}
