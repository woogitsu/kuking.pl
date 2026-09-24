<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\DailyBoard;
use App\Models\DailyPick;
use App\Models\Post;
use App\Models\Recipe;
use App\Support\Czas;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PytanieNaTablicyDniaTest extends TestCase
{
    use RefreshDatabase;

    public static function questions(): array
    {
        return ['sam tytuł automatycznie' => [null, false], 'opis automatycznie' => ['Dodatkowe szczegóły', false],
            'sam tytuł wybrany' => [null, true], 'opis wybrany' => ['Dodatkowe szczegóły', true]];
    }

    #[DataProvider('questions')]
    public function test_pytanie_ma_tytul_na_karcie_w_nazwie_linku_i_w_panelu(?string $body, bool $curated): void
    {
        config(['kuking.questions.enabled' => true]);
        $moderator = $this->moderator();
        $author = $this->user('pytajacy');
        $post = Post::factory()->question()->create(['author_id' => $author->id, 'body' => $body, 'title' => 'Dlaczego chleb opada po wyjęciu z pieca?']);
        if ($curated) {
            DailyPick::create(['shown_on' => Czas::dzisiajData(), 'subject_type' => 'post', 'subject_id' => $post->id, 'position' => 0, 'curator_id' => $moderator->id]);
        }
        $this->assertTrue(app(DailyBoard::class)->forViewer(null)['posts']->contains('id', $post->id));
        $html = $this->get('/')->assertOk()->getContent();
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($document);
        $cards = $xpath->query('//section[@aria-labelledby="kuking-na-dzis"]//li[@class="kuking-board-post"]');
        $this->assertSame(1, $cards->length);
        $this->assertStringContainsString($post->title, $cards->item(0)->textContent, 'Karta pytania pomija tytuł.');
        $link = $xpath->query('.//a', $cards->item(0))->item(0);
        $this->assertSame($post->url(), $link->getAttribute('href'));
        $this->assertStringContainsString($post->title, $link->getAttribute('aria-label'));
        $headings = $xpath->query('//section[@aria-labelledby="kuking-na-dzis"]//h3');
        $this->assertStringContainsString('Dania i pytania', $headings->item(0)->textContent);
        $this->actingAs($moderator)->get(route('admin.daily-board'))->assertOk()->assertSee($post->title)->assertSee('Wpisy z ostatnich 7 dni');
    }

    public function test_flaga_prywatnosc_i_ukrycie_nie_przepuszczaja_tytulu_pytania(): void
    {
        config(['kuking.questions.enabled' => true]);
        $moderator = $this->moderator();
        $question = Post::factory()->question()->create(['author_id' => $this->user()->id, 'body' => null]);
        DailyPick::create(['shown_on' => Czas::dzisiajData(), 'subject_type' => 'post', 'subject_id' => $question->id, 'position' => 0, 'curator_id' => $moderator->id]);
        foreach (['flaga', 'prywatny', 'ukryty'] as $state) {
            config(['kuking.questions.enabled' => $state !== 'flaga']);
            $question->forceFill(['visibility' => $state === 'prywatny' ? Post::VISIBILITY_PRIVATE : Post::VISIBILITY_PUBLIC,
                'status' => $state === 'ukryty' ? Post::STATUS_HIDDEN : Post::STATUS_PUBLISHED])->save();
            $this->assertFalse(app(DailyBoard::class)->forViewer(null)['posts']->contains('id', $question->id));
            $this->get('/')->assertOk()->assertDontSee($question->title);
        }
    }

    public function test_opisy_zwyklego_wpisu_i_przepisu_zostaja(): void
    {
        $dish = Post::factory()->create(['author_id' => $this->user()->id, 'body' => 'Pierogi z kaszą i serem']);
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->id, 'title' => 'Chleb na zakwasie']);
        $post = Post::factory()->create(['author_id' => $recipe->author_id, 'recipe_id' => $recipe->id, 'body' => null]);
        $response = $this->get('/')->assertOk();
        $response->assertSee($dish->body)->assertSee($recipe->title)->assertSee($post->url());
    }
}
