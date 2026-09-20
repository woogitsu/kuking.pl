<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OdzyskaniePoprawkiKomentarzaTest extends TestCase
{
    use RefreshDatabase;

    public static function variants(): array
    {
        return [[false, false], [true, false], [false, true], [true, true]];
    }

    #[DataProvider('variants')]
    public function test_tekst_wraca_po_terminie_takze_po_walidacji(bool $reply, bool $validation): void
    {
        $this->freezeTime();
        $author = $this->user();
        $post = Post::factory()->create(['author_id' => $author->id]);
        $parent = Comment::factory()->create(['author_id' => $author->id, 'post_id' => $post->id]);
        $comment = $reply ? Comment::factory()->create(['author_id' => $author->id, 'post_id' => $post->id, 'parent_id' => $parent->id]) : $parent;
        $original = $comment->body;
        $this->actingAs($author)->get($post->url())->assertOk()->assertSee(route('comments.update', $comment), false);
        $text = 'Moja poprawka </textarea><script>alert(1)</script>'.($validation ? str_repeat('a', 4001) : '');
        $this->travel(899)->seconds();
        if ($validation) {
            $this->from($post->url())->put(route('comments.update', $comment), ['body' => $text, '_wiersz' => 'popraw-'.$comment->id])->assertSessionHasErrors('body');
            $this->travel(1)->seconds();
            $response = $this->get($post->url());
        } else {
            $this->travel(1)->seconds();
            $response = $this->put(route('comments.update', $comment), ['body' => $text]);
            $response->assertForbidden();
        }
        $response->assertSee('Czas na poprawienie komentarza minął.')->assertSee('Skopiuj tekst')->assertSee(e($text), false)->assertDontSee('<script>alert(1)</script>', false);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $field = (new \DOMXPath($dom))->query('//textarea[@id="odzyskana-poprawka" and @readonly]')->item(0);
        $this->assertNotNull($field);
        $this->assertSame($text, $field->textContent);
        if ($validation) {
            $response->assertDontSee('href="#f-body-popraw-'.$comment->id.'"', false);
        }
        $this->assertSame($original, $comment->fresh()->body);
    }

    public function test_cudzy_i_usuniety_komentarz_nie_dostaja_odzyskiwania(): void
    {
        $author = $this->user();
        $post = Post::factory()->create(['author_id' => $author->id]);
        $comment = Comment::factory()->create(['author_id' => $author->id, 'post_id' => $post->id, 'created_at' => now()->subMinutes(16)]);
        $this->actingAs($this->user())->put(route('comments.update', $comment), ['body' => 'tajna poprawka'])->assertForbidden()->assertDontSee('tajna poprawka');
        $comment->forceFill(['body_removed_at' => now()])->save();
        $this->actingAs($author)->put(route('comments.update', $comment), ['body' => 'tajna poprawka'])->assertForbidden()->assertDontSee('tajna poprawka');
    }

    public function test_poprawka_na_899_sekundzie_nadal_sie_zapisuje(): void
    {
        $this->freezeTime();
        $author = $this->user();
        $post = Post::factory()->create(['author_id' => $author->id]);
        $comment = Comment::factory()->create(['author_id' => $author->id, 'post_id' => $post->id]);
        $this->travel(899)->seconds();
        $this->actingAs($author)->put(route('comments.update', $comment), ['body' => 'Poprawiony tekst'])->assertRedirect()->assertSessionMissing('comment_edit_recovery');
        $this->assertSame('Poprawiony tekst', $comment->fresh()->body);
    }

    public function test_ukryta_tresc_nie_jest_przedstawiana_jako_uplyw_czasu(): void
    {
        $author = $this->user();
        $owner = $this->user();
        $post = Post::factory()->create(['author_id' => $owner->id, 'visibility' => 'private']);
        $comment = Comment::factory()->create(['author_id' => $author->id, 'post_id' => $post->id, 'created_at' => now()->subMinutes(16)]);
        $this->actingAs($author)->put(route('comments.update', $comment), ['body' => 'nie pokazuj tego'])->assertForbidden()->assertDontSee('nie pokazuj tego')->assertDontSee('Czas na poprawienie');
        $post->update(['visibility' => 'public']);
        $comment->forceFill(['status' => 'hidden'])->save();
        $this->put(route('comments.update', $comment), ['body' => 'nie pokazuj tego'])->assertForbidden()->assertDontSee('nie pokazuj tego');
    }
}
