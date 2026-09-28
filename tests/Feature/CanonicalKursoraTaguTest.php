<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CanonicalKursoraTaguTest extends TestCase
{
    use RefreshDatabase;

    public function test_dalsza_strona_tagu_wskazuje_wlasny_kursor_w_canonical_i_og_url(): void
    {
        config(['kuking.feed.page_size' => 2]);

        $tag = Tag::factory()->create();
        $autor = $this->user('autor_tagu_canonical');
        $wpisy = [];
        foreach (range(1, 3) as $numer) {
            $wpis = Post::factory()->create([
                'author_id' => $autor->getKey(),
                'body' => "Wpis tagu {$numer}",
                'published_at' => now()->subMinutes($numer),
            ]);
            $wpis->tags()->attach($tag->getKey(), ['position' => 0]);
            $wpisy[] = $wpis;
        }

        $adresTagu = route('tags.show', $tag);
        $pierwsza = $this->get($adresTagu)->assertOk();
        $paginator = $pierwsza->viewData('posts');
        $nastepna = $paginator->nextPageUrl();

        $this->assertNotNull($nastepna);
        $this->assertStringContainsString('cursor=', $nastepna);
        $this->assertSame(
            [$wpisy[0]->getKey(), $wpisy[1]->getKey()],
            array_map(fn (Post $post) => $post->getKey(), $paginator->items()),
        );

        $druga = $this->get($nastepna.'&utm_source=test&page=2&smiec=1')->assertOk();
        $this->assertSame(
            [$wpisy[2]->getKey()],
            array_map(fn (Post $post) => $post->getKey(), $druga->viewData('posts')->items()),
        );
        $this->assertCanonical($druga, $nastepna);
        $this->assertCanonical($pierwsza, $adresTagu);

        // `page` wybiera treść spisu tagów, ale nie tej listy kursorowej.
        $this->assertCanonical($this->get($adresTagu.'?page=2&utm_source=test')->assertOk(), $adresTagu);
        $this->assertCanonical($this->get($adresTagu.'?cursor=nieczytelny&smiec=1')->assertOk(), $adresTagu);
        $this->assertCanonical(
            $this->get(route('tags.index').'?page=2&utm_source=test')->assertOk(),
            route('tags.index').'?page=2',
        );
    }

    private function assertCanonical(TestResponse $response, string $adres): void
    {
        $html = $response->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="'.e($adres).'">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="'.e($adres).'">', $html);
    }
}
