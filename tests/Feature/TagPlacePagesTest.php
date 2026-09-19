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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TagPlacePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_tag_header_links_to_preselected_form_and_public_photo_post(): void
    {
        $tag = Tag::factory()->create();
        $post = $this->photoPost($tag);
        TagPromotion::create(['tag_id' => $tag->id, 'position' => 0, 'note' => 'Ciasta z naszych kuchni.']);
        $html = $this->get(route('tags.show', $tag))->assertOk()->assertSee('Ciasta z naszych kuchni.')->getContent();
        $xpath = $this->xpath($html);
        $this->assertSame(1, $xpath->query('//h1')->length);
        $this->assertSame(1, $xpath->query('//section[@aria-labelledby="tag-title"]//a[@href="'.route('posts.create', ['tag' => $tag->slug]).'"]')->length);
        $tiles = $xpath->query('//*[@data-tag-collage]/a');
        $this->assertSame(1, $tiles->length);
        $this->assertSame($post->url(), $tiles->item(0)->getAttribute('href'));
        $this->assertSame($post->media->first()->url('feed'), $xpath->query('.//img', $tiles->item(0))->item(0)->getAttribute('src'));
        $this->get($tiles->item(0)->getAttribute('href'))->assertOk()->assertSee($post->body);
    }

    public function test_empty_tag_offers_a_selected_tag_without_fake_photo(): void
    {
        $tag = Tag::factory()->create();
        $html = $this->get(route('tags.show', $tag))->assertOk()->getContent();
        $xpath = $this->xpath($html);
        $this->assertSame(0, $xpath->query('//*[@data-tag-collage]')->length);
        $this->assertSame(2, $xpath->query('//main//a[@href="'.route('posts.create', ['tag' => $tag->slug]).'"]')->length);
    }

    public function test_directory_cards_have_public_photos_and_editorial_notes_are_escaped(): void
    {
        $tag = Tag::factory()->create();
        $this->photoPost($tag);
        $plain = Tag::factory()->create();
        $plainPost = $this->photoPost($plain);
        TagPromotion::create(['tag_id' => $tag->id, 'position' => 0, 'note' => '<script>bad()</script>']);
        $html = $this->get(route('tags.index'))->assertOk()->assertDontSee('<script>bad()</script>', false)->getContent();
        $xpath = $this->xpath($html);
        $this->assertSame(1, $xpath->query('//nav[@aria-label="Polecane tagi"]/a//*[@data-tag-collage]')->length);
        $this->assertSame(0, $xpath->query('//nav[@aria-label="Polecane tagi"]/a//a')->length);
        $this->assertSame(2, $xpath->query('//nav[@aria-label="Wszystkie tagi, alfabetycznie"]//img')->length);
        $plainCard = $xpath->query('//nav[@aria-label="Wszystkie tagi, alfabetycznie"]/a[@href="'.route('tags.show', $plain).'"]')->item(0);
        $this->assertNotNull($plainCard);
        $this->assertSame($plainPost->media->first()->url('feed'), $xpath->query('.//img', $plainCard)->item(0)->getAttribute('src'));
        $this->assertStringContainsString('Zdjęcie: '.$plainPost->author->displayName(), $plainCard->textContent);
        $this->get(route('tags.show', $plain))->assertOk()->assertSee($plainPost->body);
        $this->assertSame(1, $xpath->query('//nav[@aria-label="Polecane tagi"]/a[@href="'.route('tags.show', $tag).'"]')->length);
    }

    public function test_directory_uses_no_photo_when_only_private_post_has_one(): void
    {
        $tag = Tag::factory()->create();
        $post = $this->photoPost($tag);
        $post->update(['visibility' => Post::VISIBILITY_PRIVATE]);
        $html = $this->actingAs($post->author)->get(route('tags.index'))->assertOk()->getContent();
        $xpath = $this->xpath($html);
        $cards = $xpath->query('//nav[@aria-label="Wszystkie tagi, alfabetycznie"]/a');
        $this->assertSame(1, $cards->length);
        $this->assertSame(0, $xpath->query('.//img', $cards->item(0))->length);
        $this->assertStringContainsString($tag->name, $cards->item(0)->textContent);
        $this->assertStringContainsString('0 wpisów', $cards->item(0)->textContent);
        $this->assertStringNotContainsString('Zdjęcie:', $cards->item(0)->textContent);
    }

    public function test_rendering_thirty_promoted_collages_does_not_add_queries_per_card(): void
    {
        $counts = [];
        foreach (range(1, 30) as $position) {
            $tag = Tag::factory()->create();
            $this->photoPost($tag);
            TagPromotion::create(['tag_id' => $tag->id, 'position' => $position]);
            if (! in_array($position, [2, 30], true)) {
                continue;
            }
            $this->get(route('tags.index'))->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $html = $this->get(route('tags.index'))->assertOk()->getContent();
                $counts[] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
            $this->assertSame($position, $this->xpath($html)->query('//nav[@aria-label="Polecane tagi"]//*[@data-tag-collage]')->length);
        }
        $this->assertSame($counts[0], $counts[1]);
    }

    private function photoPost(Tag $tag): Post
    {
        $post = Post::factory()->create();
        $post->tags()->attach($tag);
        $post->media()->attach(Media::factory()->create(['owner_id' => $post->author_id]));

        return $post->load('media');
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }
}
