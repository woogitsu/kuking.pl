<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\DeleteComment;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #2190: `DeleteComment` decyduje na ŚWIEŻYM koncie wykonawcy, nie na modelu
 * przekazanym z kontrolera. Wyścig na dwóch połączeniach:
 * `tests/Dwa/UsuniecieKomentarzaPoSankcjiKontaTest.php`; tu ten sam błąd
 * bez współbieżności — model w pamięci jest aktywny, w bazie już nie.
 */
class UsuniecieKomentarzaPrzezKontoZSankcjaTest extends TestCase
{
    use RefreshDatabase;

    public function test_stary_model_wlasciciela_nie_pozwala_usunac_cudzego_komentarza_po_sankcji(): void
    {
        $basia = $this->user('basia');
        $zenek = $this->user('zenek');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);
        $korzen = Comment::factory()->create(['author_id' => $zenek->getKey(), 'post_id' => $post->getKey(), 'body' => 'Do zachowania']);
        Comment::factory()->create(['author_id' => $this->user('ola')->getKey(), 'post_id' => $post->getKey(), 'parent_id' => $korzen->getKey()]);

        $staryModel = User::query()->findOrFail($basia->getKey());
        $this->assertTrue($staryModel->isActive());
        $basia->suspend();

        try {
            app(DeleteComment::class)->handle($staryModel, $korzen, 'Nie na temat.');
            $this->fail('Zawieszona osoba nie może usunąć cudzego komentarza.');
        } catch (AuthorizationException) {
            // oczekiwane
        }

        $po = Comment::withTrashed()->findOrFail($korzen->getKey());
        $this->assertNull($po->deleted_at);
        $this->assertNull($po->body_removed_at);
        $this->assertSame('Do zachowania', $po->body);
        $this->assertSame(0, Notification::query()->where('user_id', $zenek->getKey())->count());
    }

    public function test_stary_model_autora_nie_pozwala_usunac_wlasnego_komentarza_po_sankcji(): void
    {
        $basia = $this->user('basia');
        $zenek = $this->user('zenek');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);
        $komentarz = Comment::factory()->create(['author_id' => $zenek->getKey(), 'post_id' => $post->getKey()]);

        $staryModel = User::query()->findOrFail($zenek->getKey());
        $zenek->ban();

        $this->expectException(AuthorizationException::class);

        try {
            app(DeleteComment::class)->handle($staryModel, $komentarz);
        } finally {
            $this->assertNull(Comment::withTrashed()->findOrFail($komentarz->getKey())->deleted_at);
        }
    }

    public function test_aktywny_wlasciciel_nadal_usuwa_cudzy_komentarz_i_autor_dostaje_powiadomienie(): void
    {
        $basia = $this->user('basia');
        $zenek = $this->user('zenek');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);
        $komentarz = Comment::factory()->create(['author_id' => $zenek->getKey(), 'post_id' => $post->getKey()]);

        $this->assertTrue(app(DeleteComment::class)->handle($basia, $komentarz, 'Nie na temat.'));

        $this->assertNotNull(Comment::withTrashed()->findOrFail($komentarz->getKey())->deleted_at);
        $this->assertSame(1, Notification::query()->where('user_id', $zenek->getKey())->where('type', Notification::TYPE_MODERATION)->count());
        // #911: ponowione usunięcie jest idempotentne.
        $this->assertFalse(app(DeleteComment::class)->handle($basia, $komentarz, 'Nie na temat.'));
        $this->assertSame(1, Notification::query()->where('user_id', $zenek->getKey())->count());
    }

    public function test_aktywny_autor_nadal_usuwa_wlasny_komentarz(): void
    {
        $basia = $this->user('basia');
        $zenek = $this->user('zenek');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);
        $komentarz = Comment::factory()->create(['author_id' => $zenek->getKey(), 'post_id' => $post->getKey()]);

        $this->assertTrue(app(DeleteComment::class)->handle($zenek, $komentarz));
        $this->assertNotNull(Comment::withTrashed()->findOrFail($komentarz->getKey())->deleted_at);
        $this->assertSame(0, Notification::query()->count());
    }
}
