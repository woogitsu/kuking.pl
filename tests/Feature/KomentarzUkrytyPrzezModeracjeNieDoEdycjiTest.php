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
 * Issue #937: autor mógł poprawić komentarz ukryty albo zdjęty przez moderację.
 *
 * `CommentPolicy::update()` sprawdzała autora, `body_removed_at` i okno
 * 15 minut, ale nie status komentarza. Moderator ukrywał komentarz, a autor
 * w tym samym oknie podmieniał treść — przy odwołaniu moderator oglądałby
 * inny tekst niż ten, o którym zdecydował.
 *
 * Każdy test stoi na komentarzu świeżym (2 minuty), więc okno 15 minut
 * NIE jest powodem odmowy — jedyną różnicą między kontrolą dodatnią a ujemną
 * jest status.
 */
class KomentarzUkrytyPrzezModeracjeNieDoEdycjiTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string}> */
    public static function statusyModeracji(): iterable
    {
        yield 'ukryty' => [Comment::STATUS_HIDDEN];
        yield 'zdjęty' => [Comment::STATUS_REMOVED];
    }

    #[DataProvider('statusyModeracji')]
    public function test_autor_nie_poprawi_komentarza_po_decyzji_moderatora(string $status): void
    {
        [$autor, $komentarz] = $this->komentarz($status);

        $this->assertFalse($autor->can('update', $komentarz));

        $this->actingAs($autor)
            ->from(route('posts.show', $komentarz->post_id))
            ->put(route('comments.update', $komentarz), ['body' => 'Podmieniona treść'])
            ->assertRedirect(route('posts.show', $komentarz->post_id))
            ->assertSessionHasErrors(['body' => 'Moderacja ukryła ten komentarz, więc nie da się go już poprawić. '
                .'Jeśli uważasz, że to pomyłka, odwołaj się od decyzji — znajdziesz ją w powiadomieniach.'])
            ->assertSessionHasInput('body', 'Podmieniona treść');

        $this->assertSame('Treść oceniona przez moderatora', $komentarz->fresh()->body);
    }

    #[DataProvider('statusyModeracji')]
    public function test_obca_osoba_dostaje_zwykle_403_bez_wyjasnienia(string $status): void
    {
        [, $komentarz] = $this->komentarz($status);
        $obcy = $this->user('obcy937');

        $this->actingAs($obcy)
            ->put(route('comments.update', $komentarz), ['body' => 'Cudza podmiana'])
            ->assertForbidden();

        $this->assertSame('Treść oceniona przez moderatora', $komentarz->fresh()->body);
    }

    public function test_kontrola_dodatnia_opublikowany_komentarz_w_oknie_da_sie_poprawic(): void
    {
        [$autor, $komentarz] = $this->komentarz(Comment::STATUS_PUBLISHED);

        $this->assertTrue($autor->can('update', $komentarz));

        $this->actingAs($autor)
            ->put(route('comments.update', $komentarz), ['body' => 'Poprawiona literówka'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Poprawiona literówka', $komentarz->fresh()->body);
    }

    /** @return array{User, Comment} */
    private function komentarz(string $status): array
    {
        $autor = $this->user('autor937');
        $post = Post::factory()->create(['author_id' => $this->user('wlasciciel937')->getKey()]);

        $komentarz = Comment::factory()->create([
            'author_id' => $autor->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Treść oceniona przez moderatora',
            'created_at' => now()->subMinutes(2),
        ]);
        // `status` nie jest ustawiany z żądania — tu udajemy decyzję moderatora.
        $komentarz->forceFill(['status' => $status])->save();

        return [$autor, $komentarz->fresh()];
    }
}
