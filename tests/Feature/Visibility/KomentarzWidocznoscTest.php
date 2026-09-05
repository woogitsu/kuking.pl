<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Komentarze (issue #41).
 *
 * Komentarz nie ma własnej widoczności — renderuje się wewnątrz strony rodzica.
 * To znaczy, że dziedziczy ochronę rodzica NIEJAWNIE, a rzeczy niejawne psują
 * się po cichu. Stąd te testy.
 *
 * Dwa różne pytania, które łatwo pomylić:
 *   1. czy komentarz pod NIEWIDOCZNYM wpisem wycieka (ochrona rodzica),
 *   2. czy komentarz osoby ZABLOKOWANEJ wycieka pod wpisem, który widzę
 *      (ochrona per-komentarz — rodzic jest tu w porządku).
 */
class KomentarzWidocznoscTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autor = $this->user('autorka');
    }

    private function wpis(string $widocznosc): Post
    {
        return Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => $widocznosc,
            'body' => 'Wpis '.$widocznosc,
        ]);
    }

    public function test_komentarz_pod_prywatnym_wpisem_nie_wycieka(): void
    {
        $wpis = $this->wpis(Post::VISIBILITY_PRIVATE);

        Comment::factory()->create([
            'post_id' => $wpis->getKey(),
            'author_id' => $this->autor->getKey(),
            'body' => 'Tajna uwaga o rosole',
        ]);

        $obcy = $this->user('obca');

        $this->actingAs($obcy)->get(route('posts.show', $wpis))->assertStatus(403);

        Auth::logout();
        $this->get(route('posts.show', $wpis))->assertStatus(403);
    }

    public function test_komentarz_pod_wpisem_dla_obserwujacych_widzi_tylko_obserwujacy(): void
    {
        $wpis = $this->wpis(Post::VISIBILITY_FOLLOWERS);

        Comment::factory()->create([
            'post_id' => $wpis->getKey(),
            'author_id' => $this->autor->getKey(),
            'body' => 'Uwaga dla swoich',
        ]);

        $obserwujacy = $this->user('obserwujaca');
        app(FollowUser::class)->handle($obserwujacy, $this->autor);

        $this->actingAs($obserwujacy)
            ->get(route('posts.show', $wpis))
            ->assertOk()
            ->assertSee('Uwaga dla swoich');

        $this->actingAs($this->user('obca'))
            ->get(route('posts.show', $wpis))
            ->assertStatus(403);
    }

    public function test_komentarz_osoby_zablokowanej_nie_pokazuje_sie_pod_widocznym_wpisem(): void
    {
        $wpis = $this->wpis(Post::VISIBILITY_PUBLIC);

        $nieprzyjemny = $this->user('nieprzyjemna');
        $czytelnik = $this->user('czytelniczka');

        Comment::factory()->create([
            'post_id' => $wpis->getKey(),
            'author_id' => $nieprzyjemny->getKey(),
            'body' => 'Zaczepka pod wpisem',
        ]);

        app(BlockUser::class)->handle($czytelnik, $nieprzyjemny);

        // Wpis jest publiczny i czytelnik ma prawo go widzieć. Ale komentarz
        // osoby zablokowanej to dokładnie to, przed czym blokada ma chronić —
        // inaczej „zablokowałam" znaczy „zablokowałam wszędzie poza komentarzami".
        $this->actingAs($czytelnik)
            ->get(route('posts.show', $wpis))
            ->assertOk()
            ->assertDontSee('Zaczepka pod wpisem');
    }
}
