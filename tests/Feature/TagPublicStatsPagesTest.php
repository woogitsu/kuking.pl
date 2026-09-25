<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use App\Models\TagPromotion;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TagPublicStatsPagesTest extends TestCase
{
    use RefreshDatabase;

    public static function thresholds(): array
    {
        return ['both reached' => [5, 3, true], 'too few photos' => [4, 3, false], 'too few people' => [5, 2, false]];
    }

    #[DataProvider('thresholds')]
    public function test_pages_publish_only_stats_above_both_thresholds(int $photos, int $people, bool $published): void
    {
        config(['kuking.tag_public_stats.min_photos' => 5, 'kuking.tag_public_stats.min_contributors' => 3]);
        $tag = $this->tagWithPhotos('Obiad', $photos, $people);
        $index = $this->xpath($this->get(route('tags.index'))->assertOk()->getContent());
        $chip = $index->query('//nav[@aria-label="Wszystkie tagi, alfabetycznie"]/a[@href="'.route('tags.show', $tag).'"]');
        $this->assertSame(1, $chip->length);
        $chipText = $this->text($chip->item(0)->textContent);
        // Dotychczasowy licznik wpisów pozostaje także poniżej progu zdjęć.
        $this->assertStringContainsString('('.$photos.' '.($photos === 4 ? 'wpisy' : 'wpisów').')', $chipText);
        $indexStats = $index->query('.//*[@data-tag-public-stats]', $chip->item(0));
        $show = $this->xpath($this->get(route('tags.show', $tag))->assertOk()->getContent());
        $showStats = $show->query('//main//*[@data-tag-public-stats]');
        $this->assertSame(1, $showStats->length);

        if ($published) {
            $this->assertSame(1, $indexStats->length);
            foreach ([$indexStats->item(0), $showStats->item(0)] as $node) {
                $this->assertSame('Publicznie: 5 zdjęć od 3 osób.', $this->text($node->textContent));
            }
        } else {
            $this->assertSame(0, $indexStats->length);
            $this->assertStringNotContainsString('Publicznie:', $chipText);
            $this->assertStringNotContainsString('zdję', $chipText);
            $this->assertStringNotContainsString('osób', $chipText);
            $this->assertSame('Pokaż, co gotujesz — dodaj swój wpis.', $this->text($showStats->item(0)->textContent));
        }
    }

    public function test_thresholds_are_read_from_configuration_on_both_pages(): void
    {
        $tag = $this->tagWithPhotos('Kolacja', 5, 3);
        config(['kuking.tag_public_stats.min_photos' => 6, 'kuking.tag_public_stats.min_contributors' => 4]);
        foreach ([route('tags.index'), route('tags.show', $tag)] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('Publicznie:', $html);
        }
    }

    public function test_alphabet_pagination_and_promoted_union_keep_their_meaning(): void
    {
        config(['kuking.tags.index_page_size' => 2, 'kuking.tag_public_stats.min_photos' => 5, 'kuking.tag_public_stats.min_contributors' => 3]);
        // Największa liczba zdjęć nie może przesunąć Zupy przed alfabet.
        $z = $this->tagWithPhotos('Zupy', 6, 3);
        $a = $this->tagWithPhotos('Agrest', 5, 3);
        $b = Tag::factory()->create(['name' => 'Buraki']);
        TagPromotion::create(['tag_id' => $z->getKey(), 'position' => 0]);
        TagPromotion::create(['tag_id' => $a->getKey(), 'position' => 1]);

        [$html, $queries] = $this->measuredIndex();
        $xpath = $this->xpath($html);
        $this->assertSame([route('tags.show', $a), route('tags.show', $b)], $this->hrefs($xpath, '//nav[@aria-label="Wszystkie tagi, alfabetycznie"]/a'));
        $this->assertSame([route('tags.show', $z), route('tags.show', $a)], $this->hrefs($xpath, '//nav[@aria-label="Polecane tagi"]/a'));
        $stats = $xpath->query('//nav[@aria-label="Polecane tagi"]//*[@data-tag-public-stats]');
        $this->assertSame(2, $stats->length);
        $this->assertSame('Publicznie: 6 zdjęć od 3 osób.', $this->text($stats->item(0)->textContent));
        $this->assertSame('Publicznie: 5 zdjęć od 3 osób.', $this->text($stats->item(1)->textContent));
        $this->assertOneStatsBatch($queries, [$a->getKey(), $b->getKey(), $z->getKey()]);

        $more = $xpath->query('//main//a[contains(normalize-space(.), "Pokaż więcej")]');
        $this->assertSame(1, $more->length);
        $next = $this->xpath($this->get(self::elementDom($more->item(0))->getAttribute('href'))->assertOk()->getContent());
        $this->assertSame([route('tags.show', $z)], $this->hrefs($next, '//nav[@aria-label="Wszystkie tagi, alfabetycznie"]/a'));
        $this->assertSame([route('tags.show', $z), route('tags.show', $a)], $this->hrefs($next, '//nav[@aria-label="Polecane tagi"]/a'));
    }

    public function test_index_query_cost_does_not_grow_per_tag(): void
    {
        config(['kuking.tags.index_page_size' => 40]);
        $tags = Tag::factory()->count(2)->create();
        TagPromotion::create(['tag_id' => $tags[0]->getKey(), 'position' => 0]);
        $this->get(route('tags.index'))->assertOk();
        [, $small] = $this->measuredIndex();
        $this->assertOneStatsBatch($small, $tags->modelKeys());
        $tags = $tags->concat(Tag::factory()->count(28)->create());
        $this->get(route('tags.index'))->assertOk();
        [$html, $large] = $this->measuredIndex();
        $this->assertSame(30, $this->xpath($html)->query('//nav[@aria-label="Wszystkie tagi, alfabetycznie"]/a')->length);
        $this->assertOneStatsBatch($large, $tags->pluck('id')->all());
        $this->assertLessThanOrEqual(count($small) + 2, count($large), 'Koszt GET /tagi rośnie wraz z liczbą tagów.');
    }

    private function tagWithPhotos(string $name, int $photos, int $people): Tag
    {
        $tag = Tag::factory()->create(['name' => $name]);
        $authors = [];
        for ($i = 0; $i < $people; $i++) {
            $authors[] = $this->user();
        }
        for ($i = 0; $i < $photos; $i++) {
            $author = $authors[$i % $people];
            $post = Post::factory()->create(['author_id' => $author->getKey()]);
            $post->tags()->attach($tag);
            $post->media()->attach(Media::factory()->create(['owner_id' => $author->getKey()]), ['position' => 0]);
        }

        return $tag;
    }

    private function measuredIndex(): array
    {
        // Pomiar na zimno: liczby tagów leżą w cache (audyt B4 W2), a ten
        // test mierzy koszt ich policzenia, nie odczytu z cache.
        Cache::flush();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $html = $this->get(route('tags.index'))->assertOk()->getContent();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return [$html, $queries];
    }

    private function assertOneStatsBatch(array $queries, array $ids): void
    {
        $stats = array_values(array_filter($queries, fn ($query) => str_contains(strtolower($query['query']), 'photos_count') && str_contains(strtolower($query['query']), 'contributors_count')));
        $this->assertCount(1, $stats, 'Statystyki muszą pochodzić z jednej zbiorczej agregacji, także przy promowanych tagach.');
        foreach ($ids as $id) {
            $this->assertSame(1, count(array_filter($stats[0]['bindings'], fn ($value) => $value === $id)), 'Identyfikator tagu ma wystąpić raz w unii promowanych i bieżącej strony.');
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new DOMXPath($dom);
    }

    private function hrefs(DOMXPath $xpath, string $selector): array
    {
        $result = [];
        foreach (self::elementyDom($xpath->query($selector)) as $node) {
            $result[] = $node->getAttribute('href');
        }

        return $result;
    }

    private function text(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
