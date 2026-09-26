<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SitemapChunkCompletenessTest extends TestCase
{
    use RefreshDatabase;

    public static function contentOrders(): array
    {
        $cases = [];
        foreach (['posts', 'recipes'] as $type) {
            foreach (['ascending', 'descending', 'equal'] as $order) {
                $cases[$type.'-'.$order] = [$type, $order];
            }
        }

        return $cases;
    }

    #[DataProvider('contentOrders')]
    public function test_all_urls_survive_chunk_boundaries_and_cache(string $type, string $order): void
    {
        $this->travelTo(now()->startOfDay());
        $author = User::factory()->create()->refresh();
        // Profil ma publiczny wpis w obu wariantach; reguła profili pozostaje osobną sprawą.
        $anchor = Post::factory()->create(['author_id' => $author->id]);
        // Huby i strony stałe (#1032); pytania są tu wyłączone flagą.
        $expected = array_map(fn ($name) => route($name), ['landing', 'discover', 'tags.index', 'about', 'help', 'rules', 'kontakt', 'terms', 'privacy']);
        $expected[] = route('profile.show', $author->profile->username);
        $expected[] = route('posts.show', $anchor->id);
        $model = $type === 'posts' ? Post::class : Recipe::class;
        $rows = [];
        for ($index = 1; $index <= 1001; $index++) {
            $id = sprintf('00000000-0000-4000-8000-%012d', $index);
            $offset = match ($order) {
                'ascending' => $index,
                'descending' => 1002 - $index,
                'equal' => 1,
            };
            $attributes = [
                'id' => $id,
                'author_id' => $author->id,
                'published_at' => now()->subDays(2)->addSeconds($offset),
                'created_at' => now()->subDays(3),
                'updated_at' => now()->subDay(),
            ];
            if ($type === 'recipes') {
                $attributes['slug'] = 'sitemap-fixture-'.$index;
            }
            $rows[] = $model::factory()->raw($attributes);
            $expected[] = $type === 'posts'
                ? route('posts.show', $id)
                : route('recipes.show', $attributes['slug']);
        }
        // Fixture zbiorcza nadal przechodzi przez prawdziwy PostgreSQL i jego ograniczenia.
        foreach (array_chunk($rows, 200) as $batch) {
            DB::table($type)->insert($batch);
        }

        foreach (['private', 'followers'] as $visibility) {
            $model::factory()->create(['author_id' => $author->id, 'visibility' => $visibility]);
        }
        $model::factory()->draft()->create(['author_id' => $author->id]);
        $model::factory()->create(['author_id' => $author->id, 'status' => 'hidden']);
        $model::factory()->create(['author_id' => $author->id, 'deleted_at' => now()]);
        foreach (['banned', 'pending_delete'] as $status) {
            $unavailable = User::factory()->create(['status' => $status]);
            $model::factory()->create(['author_id' => $unavailable->id]);
        }

        Cache::forget('sitemap.urls');
        sort($expected);
        $first = $this->readLocations();
        $this->assertSame(count($expected), count($first), 'Niepełna liczba adresów sitemapy po przejściu granicy partii.');
        $this->assertSame($expected, $first, 'Sitemapa musi zawierać dokładnie wszystkie publiczne adresy, bez duplikatów.');
        $this->assertCount(count($first), array_unique($first), 'Sitemapa powtarza adresy między partiami.');
        $this->assertTrue(Cache::has('sitemap.urls'));

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $cached = $this->readLocations();
            $contentQueries = array_filter(DB::getQueryLog(), fn ($query) => preg_match('/from "(?:posts|recipes|profiles)"/i', $query['query']));
            $this->assertSame([], array_values($contentQueries), 'Drugi odczyt ma korzystać z cache, nie pobierać treść ponownie.');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame($expected, $cached, 'Cache musi zachować pełny zbiór i liczność adresów.');
    }

    private function readLocations(): array
    {
        $response = $this->get(route('sitemap'))->assertOk();
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml, 'Sitemapa musi być poprawnym XML-em.');
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $nodes = $xml->xpath('/s:urlset/s:url/s:loc');
        $this->assertNotFalse($nodes);
        $locations = array_map(fn ($node) => (string) $node, $nodes);
        sort($locations);

        return $locations;
    }
}
