<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ZeszytDrogaTest extends TestCase
{
    use RefreshDatabase;

    public function test_wyjecie_ostatniego_wpisu_strony_wraca_do_pozostalych_i_zachowuje_komunikat(): void
    {
        $owner = $this->user();
        $book = $owner->defaultCollection();
        $posts = Post::factory()->count(13)->create(['author_id' => $owner->id]);
        $book->posts()->attach($posts->modelKeys());
        $first = $this->actingAs($owner)->get(route('collections.show', $book))->assertOk();
        $second = $this->get($first->viewData('posts')->nextPageUrl())->assertOk();
        $this->assertCount(1, $second->viewData('posts'));
        $post = $second->viewData('posts')->first();
        $url = route('collections.show', ['collection' => $book, 'wpisy' => 2]);
        $this->from($url)->delete(route('collections.unsave-post', $post))->assertRedirect($url);
        $response = $this->get($url);
        if ($response->isRedirect()) {
            $response = $this->get($response->headers->get('Location'));
        }
        // Komunikat NAZYWA TERAZ ZESZYT PO IMIENIU (issue #775): po wyjęciu
        // z jednego zeszytu zdanie brzmi „Wpis wyjęty z zeszytu «Zapisane».",
        // a nie „Wpis wyjęty z zeszytu." z kropką zaraz po słowie. Scena
        // pilnuje dalej tego samego: że komunikat przeżywa przekierowanie na
        // poprzednią stronę listy.
        $response->assertOk()->assertDontSee('W tym zeszycie nic jeszcze nie ma')->assertSee('Wpis wyjęty z zeszytu');
        $this->assertCount(12, $response->viewData('posts'));
        $this->assertSame(12, $book->posts()->count());
    }

    public function test_obie_listy_wraca_na_wlasne_strony_a_prawdziwie_pusty_zeszyt_zostaje_pusty(): void
    {
        $owner = $this->user();
        $book = $owner->defaultCollection();
        $book->recipes()->attach(Recipe::factory()->count(25)->create(['author_id' => $owner->id])->modelKeys());
        $book->posts()->attach(Post::factory()->count(13)->create(['author_id' => $owner->id])->modelKeys());
        $response = $this->actingAs($owner)->get(route('collections.show', ['collection' => $book, 'page' => 2, 'wpisy' => 999]));
        $response->assertRedirect(route('collections.show', ['collection' => $book, 'page' => 2, 'wpisy' => 2]));
        $page = $this->get($response->headers->get('Location'))->assertOk();
        $this->assertSame(2, $page->viewData('recipes')->currentPage());
        $this->assertCount(12, $page->viewData('recipes'));
        $this->assertCount(1, $page->viewData('posts'));
        $book->recipes()->detach();
        $book->posts()->detach();
        $this->get(route('collections.show', $book))->assertOk()->assertSee('W tym zeszycie nic jeszcze nie ma');
    }

    public function test_potwierdzenie_nazywa_wlasciwy_obiekt_i_escapuje_nazwe(): void
    {
        $owner = $this->user();
        foreach (['Zupa "A" & <b>domowa</b>', 'Zupa "B" & <i>inna</i>'] as $title) {
            $recipe = Recipe::factory()->create(['author_id' => $owner->id, 'title' => $title]);
            $book = Collection::create(['owner_id' => $owner->id, 'name' => $title, 'visibility' => 'private']);
            foreach ([[$recipe->url(), route('recipes.destroy', $recipe->slug)], [route('collections.show', $book), route('collections.destroy', $book)]] as [$url, $action]) {
                $html = $this->actingAs($owner)->get($url)->assertOk()->getContent();
                $dom = new \DOMDocument;
                @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
                $xpath = new \DOMXPath($dom);
                $details = $xpath->query('//details[.//form[@action="'.$action.'"]]');
                $this->assertSame(1, $details->length);
                $question = $xpath->query('.//p[@class="confirm-question"]', $details->item(0))->item(0);
                $this->assertNotNull($question);
                $this->assertStringContainsString($title, $question->textContent);
                $this->assertSame(0, $xpath->query('.//b|.//i', $question)->length);
                $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $details->item(0))->length);
            }
        }
    }
}
