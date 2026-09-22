<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Recipe;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaginacjaKomentarzyIWykonanTest extends TestCase
{
    use RefreshDatabase;

    private int $commentsTotal;

    private int $cookedTotal;

    private string $recipeUrl;

    public function test_invalid_and_foreign_parameters_are_not_propagated(): void
    {
        $this->fixture();
        foreach (['komentarze' => 'wykonań', 'wykonania' => 'komentarzy'] as $fixed => $label) {
            $moving = $fixed === 'komentarze' ? 'wykonania' : 'komentarze';
            foreach ([null, '', '0', '-2', 'abc', '1.5', ['2'], '999999999999999999999999999', '01', '1', '2'] as $value) {
                $query = ['token' => 'nie-przenos', 'redirect' => 'https://example.test/obcy'];
                if ($value !== null) {
                    $query[$fixed] = $value;
                }
                $response = $this->get($this->recipeUrl.'?'.http_build_query($query))->assertOk();
                $this->privacy($response);
                $href = $this->next($response, $label);
                $expected = [$moving => '2'];
                if ($value === '2') {
                    $expected[$fixed] = '2';
                }
                $this->assertQuery($href, $expected);
                $next = $this->get($href)->assertOk();
                $this->check($next, $moving === 'komentarze' || $value === '2' ? 2 : 1, $moving === 'wykonania' || $value === '2' ? 2 : 1);
            }
        }
    }

    public function test_out_of_range_pages_are_clamped_to_their_own_unequal_lists(): void
    {
        $this->fixture();
        foreach (['komentarze' => ['komentarze', 'wykonań', 3], 'wykonania' => ['cookedEvents', 'komentarzy', 4]] as $fixed => [$data, $label, $last]) {
            $response = $this->get($this->recipeUrl.'?'.$fixed.'=999')->assertOk();
            $this->privacy($response);
            $this->assertSame(999, $response->viewData($data)->currentPage());
            $this->assertCount(0, $response->viewData($data));
            $href = $this->next($response, $label);
            $this->assertQuery($href, $fixed === 'komentarze' ? ['komentarze' => '3', 'wykonania' => '2'] : ['komentarze' => '2', 'wykonania' => '4']);
            $next = $this->get($href)->assertOk();
            $this->check($next, $fixed === 'komentarze' ? $last : 2, $fixed === 'wykonania' ? $last : 2);
        }
    }

    public function test_empty_comments_are_not_propagated_as_a_page(): void
    {
        $this->fixture(0, 41);
        $response = $this->get($this->recipeUrl.'?komentarze=999')->assertOk();
        $this->privacy($response);
        $href = $this->next($response, 'wykonań');
        $this->assertQuery($href, ['wykonania' => '2']);
        $this->check($this->get($href)->assertOk(), 1, 2);
    }

    public function test_single_page_cooked_events_are_not_propagated(): void
    {
        $this->fixture(null, 1);
        $response = $this->get($this->recipeUrl.'?wykonania=999')->assertOk();
        $this->privacy($response);
        $href = $this->next($response, 'komentarzy');
        $this->assertQuery($href, ['komentarze' => '2']);
        $this->check($this->get($href)->assertOk(), 2, 1);
    }

    /** @param array<string, string> $expected */
    private function assertQuery(string $href, array $expected): void
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $actual);
        ksort($actual);
        ksort($expected);
        $this->assertSame($expected, $actual);
        $this->assertSame(parse_url($this->recipeUrl, PHP_URL_PATH), parse_url($href, PHP_URL_PATH));
    }

    public function test_kolejne_wykonania_zachowuja_druga_strone_komentarzy(): void
    {
        $first = $this->fixture();
        $comments = $this->get($this->next($first, 'komentarzy'))->assertOk();
        $this->check($comments, 2, 1);
        $ids = $comments->viewData('komentarze')->pluck('id')->all();
        $both = $this->get($this->next($comments, 'wykonań'))->assertOk();
        $this->check($both, 2, 2);
        $this->assertSame($ids, $both->viewData('komentarze')->pluck('id')->all());
        $third = $this->get($this->next($both, 'wykonań'))->assertOk();
        $this->check($third, 2, 3);
        $this->assertSame($ids, $third->viewData('komentarze')->pluck('id')->all());
    }

    public function test_kolejne_komentarze_zachowuja_druga_strone_wykonan(): void
    {
        $first = $this->fixture();
        $cooked = $this->get($this->next($first, 'wykonań'))->assertOk();
        $this->check($cooked, 1, 2);
        $ids = $cooked->viewData('cookedEvents')->pluck('id')->all();
        $href = $this->next($cooked, 'komentarzy');
        $both = $this->get($href)->assertOk();
        // Najpierw prywatność, także na odpowiedzi odtwarzającej błąd.
        $this->privacy($both);
        $this->assertSame(2, $both->viewData('cookedEvents')->currentPage(), 'Odnośnik komentarzy cofa wykonania: '.$href);
        $this->check($both, 2, 2);
        $this->assertSame($ids, $both->viewData('cookedEvents')->pluck('id')->all());
        $third = $this->get($this->next($both, 'komentarzy'))->assertOk();
        $this->check($third, 3, 2);
        $this->assertSame($ids, $third->viewData('cookedEvents')->pluck('id')->all());
    }

    private function fixture(?int $commentsTotal = null, int $cookedTotal = 41): TestResponse
    {
        $viewer = $this->user('widz652');
        $author = $this->user('autor652');
        $blocked = $this->user('blokowany652');
        $viewer->blocking()->attach($blocked->getKey(), ['created_at' => now()]);
        $recipe = Recipe::factory()->create(['author_id' => $author->getKey(), 'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public']);
        $limit = (int) config('kuking.comments.page_size');
        $this->assertGreaterThan(0, $limit);
        $this->commentsTotal = $commentsTotal ?? 2 * $limit + 5;
        $this->cookedTotal = $cookedTotal;
        $this->recipeUrl = route('recipes.show', $recipe->slug);
        for ($i = 0; $i < $this->commentsTotal; $i++) {
            Comment::create(['recipe_id' => $recipe->getKey(), 'author_id' => $author->getKey(), 'body' => 'Komentarz652-'.$i, 'status' => Comment::STATUS_PUBLISHED, 'created_at' => now()->subMinutes(100 - $i)]);
        }
        for ($i = 0; $i < $this->cookedTotal; $i++) {
            CookedEvent::factory()->create(['recipe_id' => $recipe->getKey(), 'user_id' => $author->getKey(), 'note' => 'Wykonanie652-'.$i, 'cooked_at' => now()->subMinutes($i)]);
        }
        Comment::create(['recipe_id' => $recipe->getKey(), 'author_id' => $blocked->getKey(), 'body' => 'TAJNY-KOMENTARZ-652', 'status' => Comment::STATUS_PUBLISHED]);
        CookedEvent::factory()->create(['recipe_id' => $recipe->getKey(), 'user_id' => $blocked->getKey(), 'note' => 'TAJNE-WYKONANIE-652']);
        $response = $this->actingAs($viewer)->get(route('recipes.show', $recipe->slug))->assertOk();
        $this->check($response, 1, 1);
        if ($commentsTotal === null && $cookedTotal === 41) {
            $this->assertSame(3, $response->viewData('komentarze')->lastPage());
            $this->assertSame(4, $response->viewData('cookedEvents')->lastPage());
        }

        return $response;
    }

    private function privacy(TestResponse $response): void
    {
        $response->assertDontSee('TAJNY-KOMENTARZ-652')->assertDontSee('TAJNE-WYKONANIE-652');
        $this->assertSame($this->commentsTotal, $response->viewData('komentarze')->total());
        $this->assertSame($this->cookedTotal, $response->viewData('cookedEvents')->total());
    }

    private function check(TestResponse $response, int $commentsPage, int $cookedPage): void
    {
        $this->privacy($response);
        foreach (['komentarze' => $commentsPage, 'cookedEvents' => $cookedPage] as $key => $page) {
            $paginator = $response->viewData($key);
            $this->assertSame($page, $paginator->currentPage());
            $this->assertSame(max(1, (int) ceil($paginator->total() / $paginator->perPage())), $paginator->lastPage());
            $this->assertCount(min($paginator->perPage(), $paginator->total() - ($page - 1) * $paginator->perPage()), $paginator->items());
            foreach ($paginator as $item) {
                $response->assertSee($key === 'komentarze' ? $item->body : $item->note);
            }
        }
    }

    private function next(TestResponse $response, string $kind): string
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.(string) $response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $links = (new DOMXPath($dom))->query('//main//a[normalize-space(.)="Pokaż więcej '.$kind.'"]/@href');
        $this->assertNotFalse($links);
        $this->assertSame(1, $links->length, 'Oczekiwany jeden rzeczywisty odnośnik paginacji '.$kind);

        return $links->item(0)->nodeValue;
    }
}
