<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #937, druga droga: usunięcie komentarza po decyzji moderatora.
 *
 * Edycję zamknął `CommentPolicy::update()`, ale `delete()` nie patrzyła na
 * status. Komentarz ukryty z odpowiedziami `DeleteComment` zastępował
 * placeholderem „Komentarz usunięty." — autor (albo autor wpisu) kasował
 * w ten sposób treść, o której zdecydował moderator. Przy odwołaniu
 * moderator oglądałby placeholder, a po uwzględnieniu odwołania komentarz
 * wracałby opublikowany z cudzym tekstem zamiast własnego.
 *
 * Komentarz ma odpowiedź, bo to ona wybiera ścieżkę nadpisania `body`.
 * Jedyną różnicą między kontrolą dodatnią a ujemną jest status.
 */
class KomentarzUkrytyPrzezModeracjeNieDoUsunieciaTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string}> */
    public static function statusyModeracji(): iterable
    {
        yield 'ukryty' => [Comment::STATUS_HIDDEN];
        yield 'zdjęty' => [Comment::STATUS_REMOVED];
    }

    #[DataProvider('statusyModeracji')]
    public function test_autor_nie_usunie_komentarza_po_decyzji_moderatora(string $status): void
    {
        [$autor, , $komentarz] = $this->komentarz($status);

        $this->assertFalse($autor->can('delete', $komentarz));
        // Furtka odczytu dla odwołania zostaje (`CommentPolicy::view()`).
        $this->assertTrue($autor->can('view', $komentarz));

        $this->actingAs($autor)
            ->from(route('posts.show', $komentarz->post_id))
            ->delete(route('comments.destroy', $komentarz))
            ->assertRedirect(route('posts.show', $komentarz->post_id))
            ->assertSessionHasErrors(['comment' => 'Moderacja ukryła ten komentarz, więc nie da się go już usunąć. '
                .'Jeśli uważasz, że to pomyłka, odwołaj się od decyzji — znajdziesz ją w powiadomieniach.']);

        $this->assertNietkniety($komentarz, $status);
    }

    #[DataProvider('statusyModeracji')]
    public function test_autor_wpisu_nie_usunie_komentarza_po_decyzji_moderatora(string $status): void
    {
        [, $wlasciciel, $komentarz] = $this->komentarz($status);

        $this->assertFalse($wlasciciel->can('delete', $komentarz));

        $this->actingAs($wlasciciel)
            ->delete(route('comments.destroy', $komentarz), ['reason' => 'Nie podoba mi się'])
            ->assertForbidden();

        $this->assertNietkniety($komentarz, $status);
    }

    public function test_kontrola_dodatnia_opublikowany_komentarz_autor_usuwa(): void
    {
        [$autor, , $komentarz] = $this->komentarz(Comment::STATUS_PUBLISHED);

        $this->assertTrue($autor->can('delete', $komentarz));

        $this->actingAs($autor)
            ->delete(route('comments.destroy', $komentarz))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Komentarz usunięty.', $komentarz->fresh()->body);
        $this->assertNotNull($komentarz->fresh()->body_removed_at);
    }

    private function assertNietkniety(Comment $komentarz, string $status): void
    {
        $swiezy = Comment::withTrashed()->findOrFail($komentarz->getKey());

        $this->assertSame('Treść oceniona przez moderatora', $swiezy->body);
        $this->assertNull($swiezy->body_removed_at);
        $this->assertFalse($swiezy->trashed());
        $this->assertSame($status, $swiezy->status);
    }

    /** @return array{User, User, Comment} */
    private function komentarz(string $status): array
    {
        $autor = $this->user('autor937u');
        $wlasciciel = $this->user('wlasciciel937u');
        $post = Post::factory()->create(['author_id' => $wlasciciel->getKey()]);

        $komentarz = Comment::factory()->create([
            'author_id' => $autor->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Treść oceniona przez moderatora',
        ]);
        Comment::factory()->create([
            'author_id' => $this->user('odpowiadajacy937u')->getKey(),
            'post_id' => $post->getKey(),
            'parent_id' => $komentarz->getKey(),
            'body' => 'Odpowiedź',
        ]);
        // `status` nie jest ustawiany z żądania — tu udajemy decyzję moderatora.
        $komentarz->forceFill(['status' => $status])->save();

        return [$autor, $wlasciciel, $komentarz->fresh()];
    }
}
