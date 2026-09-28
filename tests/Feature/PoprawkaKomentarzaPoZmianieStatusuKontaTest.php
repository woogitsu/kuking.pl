<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\EditComment;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/** #2090: model konta z początku żądania może być nieaktualny przy zapisie. */
final class PoprawkaKomentarzaPoZmianieStatusuKontaTest extends TestCase
{
    use RefreshDatabase;

    public function test_stary_aktywny_model_nie_pozwala_poprawic_po_zamknieciu_konta(): void
    {
        foreach ([User::STATUS_SUSPENDED, User::STATUS_BANNED, User::STATUS_PENDING_DELETE, User::STATUS_ERASED] as $status) {
            $author = $this->user();
            $post = Post::factory()->create(['author_id' => $this->user()->getKey()]);
            $comment = Comment::factory()->create([
                'author_id' => $author->getKey(),
                'post_id' => $post->getKey(),
                'body' => 'Tekst przed sankcją',
            ]);

            $staleAuthor = User::query()->findOrFail($author->getKey());
            $author->forceFill([
                'status' => $status,
                'data_erased_at' => $status === User::STATUS_ERASED ? now() : null,
            ])->save();

            $this->assertTrue($staleAuthor->isActive(), 'Model wejściowy przestał być nieaktualny.');
            $this->assertFalse($author->fresh()->isActive());
            $this->assertTrue(Gate::forUser($author->fresh())->denies('update', $comment), 'Policy przepuszcza stan '.$status);
            $this->assertNull(app(EditComment::class)->handle($staleAuthor, $comment, 'Niedozwolona poprawka'));
            $this->assertSame('Tekst przed sankcją', $comment->fresh()->body);
        }
    }

    public function test_aktywny_autor_nadal_moze_poprawic_komentarz(): void
    {
        $author = $this->user();
        $post = Post::factory()->create(['author_id' => $this->user()->getKey()]);
        $comment = Comment::factory()->create([
            'author_id' => $author->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Przed poprawką',
        ]);

        $this->assertSame('Po poprawce', app(EditComment::class)->handle($author, $comment, 'Po poprawce')?->body);
        $this->assertSame('Po poprawce', $comment->fresh()->body);
    }
}
