<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tags\PodpowiedziTagow;
use App\Domain\Tags\TagSuggester;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `TagSuggester` nie liczy wpisów (#647, komentarz z 23.09).
 *
 * Stał tam `withCount(['posts' => published()])`: nikt tej liczby nie czytał,
 * a `published()` obejmuje wpisy prywatne — gdyby ktoś podpiął `posts_count`
 * pod podpowiedź, pokazałby liczbę z treści, których oglądający nie widzi.
 * Licznik dla człowieka to wyłącznie `public_posts_count` z `PodpowiedziTagow`.
 */
final class TagSuggesterBezLicznikaWpisowTest extends TestCase
{
    use RefreshDatabase;

    public function test_podpowiedzi_nie_niosa_licznika_z_prywatnych_wpisow(): void
    {
        $autor = $this->user('autorka');
        $tag = $this->tag('sernik');
        foreach (['public', 'private', 'private'] as $widocznosc) {
            $wpis = Post::create(['author_id' => $autor->id, 'body' => 'Wpis', 'visibility' => $widocznosc,
                'status' => Post::STATUS_PUBLISHED, 'published_at' => now()]);
            $wpis->tags()->attach($tag->id, ['position' => 0]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $sugestie = app(TagSuggester::class)->sugeruj('sernik');
            $zapytania = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame(['sernik'], $sugestie->pluck('name')->all());
        $this->assertArrayNotHasKey('posts_count', $sugestie->first()->getAttributes());
        $this->assertStringNotContainsString('post_tag', $zapytania, 'Podpowiedzi nie mają liczyć wpisów.');

        // Kontrola dodatnia: licznik dla człowieka nadal jest i liczy tylko publiczne.
        $wynik = app(PodpowiedziTagow::class)->dla('sernik', $this->user('szukajaca'));
        $this->assertSame(1, $wynik['tags'][0]['public_posts_count']);
    }

    public function test_ta_sama_fraza_daje_te_sama_kolejnosc(): void
    {
        foreach (['sernik z rodzynkami', 'sernik baskijski', 'sernik', 'sernik na zimno'] as $nazwa) {
            $this->tag($nazwa);
        }

        $pierwsza = app(TagSuggester::class)->sugeruj('sernik')->pluck('name')->all();
        $druga = app(TagSuggester::class)->sugeruj('sernik')->pluck('name')->all();

        $this->assertSame('sernik', $pierwsza[0]);
        $this->assertSame($pierwsza, $druga);
    }

    private function tag(string $nazwa): Tag
    {
        return Tag::create(['name' => $nazwa, 'normalized_name' => $nazwa, 'slug' => str_replace(' ', '-', $nazwa)]);
    }
}
