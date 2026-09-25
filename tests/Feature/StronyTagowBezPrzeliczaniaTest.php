<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tags\LiczbyTagowWCache;
use App\Domain\Tags\TagCollage;
use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `/tagi` i `/tag/{slug}` nie przeliczają wszystkich wpisów tagów przy
 * każdym żądaniu (audyt B4 W2).
 *
 * Liczby publiczne i kolaż gościa są w cache per tag; strona tagu idzie
 * kursorem, bez pełnego COUNT i OFFSET.
 */
class StronyTagowBezPrzeliczaniaTest extends TestCase
{
    use RefreshDatabase;

    public function test_druga_odslona_spisu_nie_liczy_agregatow_od_nowa(): void
    {
        $tag = Tag::factory()->create();
        $this->zdjecie($tag);

        $this->get(route('tags.index'))->assertOk()->assertSee('(1 wpis)', false);

        DB::enableQueryLog();
        $this->get(route('tags.index'))->assertOk()->assertSee('(1 wpis)', false);
        $zapytania = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();

        $this->assertStringNotContainsString('COUNT(DISTINCT media.id)', $zapytania, 'Statystyki tagów liczone przy każdej odsłonie.');
        $this->assertStringNotContainsString('ROW_NUMBER()', $zapytania, 'Kolaż gościa dobierany przy każdej odsłonie.');
        $this->assertStringNotContainsString('posts_count', $zapytania, 'Liczba wpisów liczona przy każdej odsłonie.');
    }

    public function test_kolaz_goscia_z_cache_nie_pokazuje_wpisu_schowanego_pozniej(): void
    {
        $tag = Tag::factory()->create();
        $schowany = $this->zdjecie($tag);
        $zostaje = $this->zdjecie($tag);

        $this->assertCount(2, (new TagCollage)->forTagsWCache([$tag->id])[$tag->id]);

        $schowany->forceFill(['status' => Post::STATUS_HIDDEN])->save();

        $kafle = (new TagCollage)->forTagsWCache([$tag->id])[$tag->id];
        $this->assertSame([$zostaje->id], $kafle->map(fn (Media $m) => $m->posts->first()->id)->all());
    }

    public function test_statystyki_z_cache_po_czasie_licza_sie_od_nowa(): void
    {
        $tag = Tag::factory()->create();
        $this->zdjecie($tag);
        $this->assertSame(1, (new LiczbyTagowWCache)->forTags([$tag->id])[$tag->id]['postsCount']);

        $this->zdjecie($tag);
        $this->assertSame(1, (new LiczbyTagowWCache)->forTags([$tag->id])[$tag->id]['postsCount'], 'Liczba nie jest z cache.');

        $this->travel(LiczbyTagowWCache::CACHE_SEKUND + 1)->seconds();
        $this->assertSame(2, (new LiczbyTagowWCache)->forTags([$tag->id])[$tag->id]['postsCount']);
    }

    /**
     * Regresja: przyrząd przeglądarkowy (`kompozycje-515.php fotografia`)
     * dopisuje zdjęcie do bazy po tym, jak gość już odwiedził `/tagi`.
     * Bez unieważnienia cache katalog pokazywał pusty kolaż
     * (K681_BRAK_FOTOGRAFII w jobie „Port marki — rodziny ekranów").
     */
    public function test_zapomnienie_cache_goscia_pokazuje_nowe_zdjecie_od_razu(): void
    {
        $tag = Tag::factory()->create();
        $this->assertCount(0, (new TagCollage)->forTagsWCache([$tag->id])[$tag->id]);
        $this->assertSame(0, (new LiczbyTagowWCache)->forTags([$tag->id])[$tag->id]['postsCount']);

        $wpis = $this->zdjecie($tag);
        $this->assertCount(0, (new TagCollage)->forTagsWCache([$tag->id])[$tag->id], 'Kolaż gościa nie jest z cache.');

        TagCollage::zapomnijGoscia([$tag->id]);
        LiczbyTagowWCache::zapomnij([$tag->id]);

        $kafle = (new TagCollage)->forTagsWCache([$tag->id])[$tag->id];
        $this->assertSame([$wpis->id], $kafle->map(fn (Media $m) => $m->posts->first()->id)->all());
        $this->assertSame(1, (new LiczbyTagowWCache)->forTags([$tag->id])[$tag->id]['postsCount']);
    }

    public function test_strona_tagu_idzie_kursorem_bez_pelnego_count(): void
    {
        $tag = Tag::factory()->create();
        foreach (range(1, (int) config('kuking.feed.page_size') + 1) as $i) {
            $this->zdjecie($tag, now()->subMinutes($i));
        }

        DB::enableQueryLog();
        $odpowiedz = $this->get(route('tags.show', $tag))->assertOk();
        $zapytania = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $odpowiedz->assertSee('cursor=', false);
        $liczace = array_filter($zapytania, fn (string $q): bool => str_contains($q, 'count(*) as aggregate from "posts"'));
        $this->assertSame([], array_values($liczace), 'Strona tagu liczy pełny COUNT wpisów.');
    }

    private function zdjecie(Tag $tag, $kiedy = null): Post
    {
        $wpis = Post::factory()->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => $kiedy ?? now()->subHour(),
        ]);
        $wpis->tags()->attach($tag);
        $wpis->media()->attach(Media::factory()->create(['owner_id' => $wpis->author_id]));

        return $wpis;
    }
}
