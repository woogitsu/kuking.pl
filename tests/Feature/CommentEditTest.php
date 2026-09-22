<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Edycja i usunięcie komentarza (issue #16, część 2).
 *
 * Reguły KTO MOŻE CO żyją w CommentPolicy — te testy sprawdzają, że
 * kontroler faktycznie ich pilnuje (a nie tylko UUID w adresie).
 */
class CommentEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_edycja_po_16_minutach_od_publikacji_jest_zabroniona(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);

        $comment = Comment::factory()->create([
            'author_id' => $basia->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Oryginalny tekst',
            'created_at' => now()->subMinutes(16),
        ]);

        $response = $this->actingAs($basia)->put(route('comments.update', $comment), [
            'body' => 'Próba poprawki po czasie',
        ]);

        $response->assertForbidden();
        $this->assertSame('Oryginalny tekst', $comment->fresh()->body);
    }

    public function test_edycja_w_oknie_15_minut_dziala_i_zapisuje_zmiane(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);

        $comment = Comment::factory()->create([
            'author_id' => $basia->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Tekst z literówką',
            'created_at' => now()->subMinutes(10),
        ]);

        $response = $this->actingAs($basia)->put(route('comments.update', $comment), [
            'body' => 'Tekst bez literówki',
        ]);

        $response->assertRedirect();
        $this->assertSame('Tekst bez literówki', $comment->fresh()->body);
    }

    public function test_pusty_tekst_poprawki_jest_odrzucany(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);

        $comment = Comment::factory()->create([
            'author_id' => $basia->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Coś tam',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($basia)->put(route('comments.update', $comment), [
            'body' => '',
        ]);

        $response->assertSessionHasErrors('body');
        $this->assertSame('Coś tam', $comment->fresh()->body);
    }

    public function test_autor_wpisu_moze_usunac_cudzy_komentarz_pod_swoim_wpisem(): void
    {
        $autorWpisu = $this->user('kucharka');
        $ktos = $this->user('ktos');
        $post = Post::factory()->create(['author_id' => $autorWpisu->getKey()]);

        $comment = Comment::factory()->create([
            'author_id' => $ktos->getKey(),
            'post_id' => $post->getKey(),
        ]);

        $response = $this->actingAs($autorWpisu)->delete(route('comments.destroy', $comment), [
            'reason' => 'Komentarz nie na temat.',
        ]);

        $response->assertRedirect();
        $this->assertSoftDeleted($comment);
    }

    public function test_obca_osoba_nie_moze_usunac_cudzego_komentarza(): void
    {
        $autorWpisu = $this->user('kucharka');
        $autorKomentarza = $this->user('autor_komentarza');
        $obcy = $this->user('obcy');
        $post = Post::factory()->create(['author_id' => $autorWpisu->getKey()]);

        $comment = Comment::factory()->create([
            'author_id' => $autorKomentarza->getKey(),
            'post_id' => $post->getKey(),
        ]);

        $response = $this->actingAs($obcy)->delete(route('comments.destroy', $comment));

        $response->assertForbidden();
        $this->assertDatabaseHas('comments', ['id' => $comment->getKey(), 'deleted_at' => null]);
    }

    public function test_usuniecie_cudzego_komentarza_przez_autora_tresci_tworzy_powiadomienie(): void
    {
        $autorWpisu = $this->user('kucharka');
        $autorKomentarza = $this->user('gadatliwy');
        $post = Post::factory()->create(['author_id' => $autorWpisu->getKey()]);

        $comment = Comment::factory()->create([
            'author_id' => $autorKomentarza->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Coś kontrowersyjnego',
        ]);

        $this->actingAs($autorWpisu)->delete(route('comments.destroy', $comment), [
            'reason' => 'To nie jest miejsce na taką dyskusję.',
        ])->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $autorKomentarza->getKey(),
            'actor_id' => $autorWpisu->getKey(),
            'type' => Notification::TYPE_MODERATION,
        ]);

        $notification = Notification::where('user_id', $autorKomentarza->getKey())->first();
        $this->assertStringContainsString('To nie jest miejsce na taką dyskusję.', $notification->data['message']);
    }

    public function test_usuniecie_wlasnego_komentarza_nie_wysyla_powiadomienia(): void
    {
        $autorWpisu = $this->user('kucharka');
        $post = Post::factory()->create(['author_id' => $autorWpisu->getKey()]);

        $comment = Comment::factory()->create([
            'author_id' => $autorWpisu->getKey(),
            'post_id' => $post->getKey(),
        ]);

        $this->actingAs($autorWpisu)->delete(route('comments.destroy', $comment))->assertRedirect();

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_usuniecie_cudzego_komentarza_przez_autora_tresci_bez_powodu_jest_odrzucane(): void
    {
        $autorWpisu = $this->user('kucharka');
        $autorKomentarza = $this->user('gadatliwy');
        $post = Post::factory()->create(['author_id' => $autorWpisu->getKey()]);

        $comment = Comment::factory()->create([
            'author_id' => $autorKomentarza->getKey(),
            'post_id' => $post->getKey(),
        ]);

        $response = $this->actingAs($autorWpisu)->delete(route('comments.destroy', $comment));

        $response->assertSessionHasErrors('reason');
        $this->assertDatabaseHas('comments', ['id' => $comment->getKey(), 'deleted_at' => null]);
    }

    public function test_usuniety_komentarz_z_odpowiedziami_zostawia_slad_i_nie_rozsypuje_watku(): void
    {
        $autorWpisu = $this->user('kucharka');
        $post = Post::factory()->create(['author_id' => $autorWpisu->getKey()]);

        $rodzic = Comment::factory()->create([
            'author_id' => $autorWpisu->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Pytanie o przepis',
        ]);

        $odpowiedz = Comment::factory()->create([
            'author_id' => $this->user('odpowiadajacy')->getKey(),
            'post_id' => $post->getKey(),
            'parent_id' => $rodzic->getKey(),
            'body' => 'Odpowiedź na pytanie',
        ]);

        $this->actingAs($autorWpisu)->delete(route('comments.destroy', $rodzic))->assertRedirect();

        $rodzic->refresh();
        $this->assertNull($rodzic->deleted_at, 'Wiersz nie może zniknąć — odpowiedź by osierociała.');
        $this->assertSame('Komentarz usunięty.', $rodzic->body);

        // Odpowiedź nadal wisi pod tym samym komentarzem głównym.
        $this->assertSame($rodzic->getKey(), $odpowiedz->fresh()->parent_id);
        $this->assertNull($odpowiedz->fresh()->deleted_at);
    }

    public function test_strona_wpisu_z_komentarzem_renderuje_sie_dla_roznych_widzow(): void
    {
        $autorWpisu = $this->user('kucharka');
        $autorKomentarza = $this->user('gadatliwy');
        $obcy = $this->user('obcy_widz');
        $post = Post::factory()->create(['author_id' => $autorWpisu->getKey()]);

        $rodzic = Comment::factory()->create([
            'author_id' => $autorKomentarza->getKey(),
            'post_id' => $post->getKey(),
            'created_at' => now()->subMinutes(5),
        ]);

        Comment::factory()->create([
            'author_id' => $autorWpisu->getKey(),
            'post_id' => $post->getKey(),
            'parent_id' => $rodzic->getKey(),
        ]);

        // Autor treści: widzi "Popraw" (swój wpis rodzica nie jest jego, ale
        // ma prawo usunąć — z powodem) oraz odpowiedź, którą sam napisał.
        $this->actingAs($autorWpisu)->get(route('posts.show', $post))->assertOk();

        // Autor komentarza: widzi swoje "Popraw" i "Usuń" bez pola powodu.
        $this->actingAs($autorKomentarza)->get(route('posts.show', $post))->assertOk();

        // Obcy widz i gość: bez żadnych przycisków edycji/usunięcia.
        $this->actingAs($obcy)->get(route('posts.show', $post))->assertOk();
        $this->get(route('posts.show', $post))->assertOk();
    }

    public function test_moderator_moze_usunac_dowolny_komentarz(): void
    {
        $autorWpisu = $this->user('kucharka');
        $autorKomentarza = $this->user('gadatliwy');
        $moderator = $this->moderator();
        $post = Post::factory()->create(['author_id' => $autorWpisu->getKey()]);

        $comment = Comment::factory()->create([
            'author_id' => $autorKomentarza->getKey(),
            'post_id' => $post->getKey(),
        ]);

        $this->actingAs($moderator)->delete(route('comments.destroy', $comment))->assertRedirect();

        $this->assertSoftDeleted($comment);
    }

    /**
     * Issue #760: widok rozpoznawał usunięcie PO TREŚCI (porównanie z
     * dosłownym „Komentarz usunięty."), nie po `body_removed_at` — dokładnie
     * to pole, którego już pilnuje `CommentPolicy::update()`. Człowiek, który
     * naprawdę napisał to zdanie jako swój komentarz, tracił w widoku
     * przyciski Popraw/Usuń, choć Policy mu ich nie odbierała.
     *
     * Kontrola dodatnia: `test_usuniety_komentarz_z_odpowiedziami_zostawia_slad_i_nie_rozsypuje_watku`
     * wyżej pokazuje, że naprawdę usunięty komentarz nadal dostaje ten sam
     * placeholder w treści — ten test dowodzi tylko, że sam tekst już o tym
     * nie decyduje.
     */
    public function test_zwykly_komentarz_o_tresci_placeholdera_nie_jest_traktowany_jak_usuniety(): void
    {
        $autorWpisu = $this->user('kucharka760');
        $autorKomentarza = $this->user('gadatliwy760');
        $post = Post::factory()->create(['author_id' => $autorWpisu->getKey()]);

        $rodzic = Comment::create([
            'author_id' => $autorKomentarza->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Komentarz usunięty.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $this->assertNull($rodzic->body_removed_at, 'Test zakłada, że ten wiersz NIE ma znacznika usunięcia.');

        // Autor w oknie 15 minut nadal widzi Popraw i Usuń.
        $jegoWidok = $this->actingAs($autorKomentarza)->get(route('posts.show', $post));
        $jegoWidok->assertOk();
        $jegoWidok->assertSee(route('comments.update', $rodzic), false);
        $jegoWidok->assertSee(route('comments.destroy', $rodzic), false);

        // Odpowiedź jest tą samą kolizją — ta sama poprawka, ta sama asercja.
        $odpowiadajacy = $this->user('odpowiadajacy760');
        $odpowiedz = Comment::create([
            'author_id' => $odpowiadajacy->getKey(),
            'post_id' => $post->getKey(),
            'parent_id' => $rodzic->getKey(),
            'body' => 'Komentarz usunięty.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $this->assertNull($odpowiedz->body_removed_at);

        $jegoWidokOdpowiedzi = $this->actingAs($odpowiadajacy)->get(route('posts.show', $post));
        $jegoWidokOdpowiedzi->assertSee(route('comments.update', $odpowiedz), false);
        $jegoWidokOdpowiedzi->assertSee(route('comments.destroy', $odpowiedz), false);
    }
}
