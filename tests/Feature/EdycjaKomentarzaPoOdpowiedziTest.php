<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #1337: pierwsza widoczna odpowiedź zamyka poprawkę komentarza.
 *
 * Przed zmianą `CommentPolicy::update()` patrzyła tylko na autora, status,
 * znacznik usunięcia i okno 15 minut — pytanie „Czy dodać sól?" dało się
 * zmienić na „Czy pominąć sól?" pod cudzym „Tak", bez śladu.
 *
 * Semantyka „widocznej" odpowiedzi to `Comment::replies()`: opublikowana
 * i nieusunięta. Odpowiedź ukryta przez moderację albo usunięta poprawki
 * nie blokuje — w rozmowie jej nie ma, więc nie ma sensu, który można zmienić.
 *
 * Wyścig odpowiedzi z poprawką na dwóch połączeniach mierzy
 * `Tests\Dwa\PoprawkaKomentarzaPoOdpowiedziNaDwochPolaczeniachTest`.
 */
class EdycjaKomentarzaPoOdpowiedziTest extends TestCase
{
    use RefreshDatabase;

    public function test_komentarz_bez_odpowiedzi_da_sie_poprawic_w_oknie(): void
    {
        [$autorka, $pytanie] = $this->pytanie();

        $this->actingAs($autorka)->get(route('posts.show', $pytanie->post_id))
            ->assertSee('Popraw swój komentarz');

        $this->actingAs($autorka)->put(route('comments.update', $pytanie), ['body' => 'Czy dodać sól do ciasta?'])
            ->assertRedirect();

        $this->assertSame('Czy dodać sól do ciasta?', $pytanie->fresh()->body);
    }

    public function test_po_pierwszej_odpowiedzi_tresci_nie_da_sie_podmienic(): void
    {
        [$autorka, $pytanie] = $this->pytanie();
        $this->odpowiedz($pytanie);

        $odpowiedz = $this->actingAs($autorka)->put(route('comments.update', $pytanie), [
            'body' => 'Czy pominąć sól?',
        ]);

        $odpowiedz->assertStatus(409);
        $odpowiedz->assertSee('Ktoś już odpowiedział na ten komentarz.');
        $odpowiedz->assertSee('dopisz odpowiedź pod swoim komentarzem', false);
        // Wpisany tekst nie znika — wraca do skopiowania.
        $odpowiedz->assertSee('Czy pominąć sól?');
        $odpowiedz->assertSee('#komentarz-'.$pytanie->getKey(), false);
        $this->assertSame('Czy dodać sól?', $pytanie->fresh()->body);

        // Formularz mówi to samo co endpoint: „Popraw" znika.
        $this->actingAs($autorka)->get(route('posts.show', $pytanie->post_id))
            ->assertOk()
            ->assertDontSee('Popraw swój komentarz');
    }

    public function test_odpowiedz_bez_dzieci_da_sie_poprawic(): void
    {
        [, $pytanie] = $this->pytanie();
        $odpowiedz = $this->odpowiedz($pytanie);

        $this->actingAs($odpowiedz->author)->put(route('comments.update', $odpowiedz), ['body' => 'Tak, szczyptę.'])
            ->assertRedirect();

        $this->assertSame('Tak, szczyptę.', $odpowiedz->fresh()->body);
    }

    public function test_odpowiedz_ukryta_przez_moderacje_nie_blokuje_poprawki(): void
    {
        [$autorka, $pytanie] = $this->pytanie();
        $this->odpowiedz($pytanie)->forceFill(['status' => Comment::STATUS_HIDDEN])->save();

        $this->actingAs($autorka)->put(route('comments.update', $pytanie), ['body' => 'Czy dodać sól do ciasta?'])
            ->assertRedirect();

        $this->assertSame('Czy dodać sól do ciasta?', $pytanie->fresh()->body);
    }

    public function test_usunieta_odpowiedz_nie_blokuje_poprawki(): void
    {
        [$autorka, $pytanie] = $this->pytanie();
        $this->odpowiedz($pytanie)->delete();

        $this->actingAs($autorka)->put(route('comments.update', $pytanie), ['body' => 'Czy dodać sól do ciasta?'])
            ->assertRedirect();

        $this->assertSame('Czy dodać sól do ciasta?', $pytanie->fresh()->body);
    }

    public function test_po_15_minutach_zostaje_komunikat_o_czasie_nawet_z_odpowiedzia(): void
    {
        [$autorka, $pytanie] = $this->pytanie(minutTemu: 16);
        $this->odpowiedz($pytanie);

        $this->actingAs($autorka)->put(route('comments.update', $pytanie), ['body' => 'Czy pominąć sól?'])
            ->assertForbidden()
            ->assertSee('Czas na poprawienie komentarza minął.');

        $this->assertSame('Czy dodać sól?', $pytanie->fresh()->body);
    }

    public function test_cudzy_komentarz_z_odpowiedzia_nie_dostaje_strony_odzyskiwania(): void
    {
        [, $pytanie] = $this->pytanie();
        $this->odpowiedz($pytanie);
        $obcy = $this->user();

        $this->actingAs($obcy)->put(route('comments.update', $pytanie), ['body' => 'Cudza treść'])
            ->assertForbidden()
            ->assertDontSee('Cudza treść');
    }

    /** @return array{User, Comment} */
    private function pytanie(int $minutTemu = 5): array
    {
        $autorka = $this->user();
        $post = Post::factory()->create(['author_id' => $this->user()->getKey()]);

        return [$autorka, Comment::factory()->create([
            'author_id' => $autorka->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Czy dodać sól?',
            'created_at' => now()->subMinutes($minutTemu),
        ])];
    }

    private function odpowiedz(Comment $pytanie): Comment
    {
        return Comment::factory()->create([
            'author_id' => $this->user()->getKey(),
            'post_id' => $pytanie->post_id,
            'parent_id' => $pytanie->getKey(),
            'body' => 'Tak.',
        ]);
    }
}
