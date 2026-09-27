<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PrzeanalizujTresc;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Issue #982: dwie karty poprawki tego samego komentarza. Druga, otwarta na
 * starszej treści, nie może po cichu nadpisać poprawki zapisanej w pierwszej.
 * Zapis jest odrzucany, a tekst z drugiej karty zostaje w polu (AGENTS.md §5:
 * poprawne dane nigdy nie znikają).
 */
class PoprawkaKomentarzaDwieKartyTest extends TestCase
{
    use RefreshDatabase;

    public function test_druga_karta_ze_stara_wersja_nie_nadpisuje_poprawki_z_pierwszej(): void
    {
        [$basia, $post, $comment] = $this->komentarz('Sól do smaku');
        $wersjaStartowa = $this->wersjaZFormularza($basia, $post, $comment);

        // Kontrola dodatnia: karta A, z aktualną wersją, zapisuje normalnie.
        $this->actingAs($basia)->from(route('posts.show', $post))
            ->put(route('comments.update', $comment), [
                'body' => 'Pół łyżeczki soli',
                'wersja' => $wersjaStartowa,
                '_wiersz' => 'popraw-'.$comment->id,
            ])
            ->assertRedirect(route('posts.show', $post))
            ->assertSessionHas('status', 'Komentarz poprawiony.')
            ->assertSessionHasNoErrors();
        $this->assertSame('Pół łyżeczki soli', $comment->fresh()->body);

        // Karta B nadal pokazuje „Sól do smaku”.
        $response = $this->actingAs($basia)->from(route('posts.show', $post))
            ->put(route('comments.update', $comment), [
                'body' => 'Sól i pieprz do smaku',
                'wersja' => $wersjaStartowa,
                '_wiersz' => 'popraw-'.$comment->id,
            ]);

        $response->assertRedirect(route('posts.show', $post));
        $response->assertSessionHasErrors('wersja');
        $response->assertSessionDoesntHaveErrors('body');
        $response->assertSessionMissing('status');
        $response->assertSessionHasInput('body', 'Sól i pieprz do smaku');
        $this->assertSame('Pół łyżeczki soli', $comment->fresh()->body);

        // Po przekierowaniu: zapisana treść nad polem, tekst z karty B w polu,
        // otwarte „Popraw” i podsumowanie błędów prowadzące do konfliktu.
        $strona = $this->actingAs($basia)->from(route('posts.show', $post))->followingRedirects()
            ->put(route('comments.update', $comment), [
                'body' => 'Sól i pieprz do smaku',
                'wersja' => $wersjaStartowa,
                '_wiersz' => 'popraw-'.$comment->id,
            ]);
        $strona->assertOk();
        $strona->assertSee('Tak ten komentarz jest zapisany teraz');
        $strona->assertSee('Ten komentarz zmienił się w innej karcie');
        $strona->assertSee('href="#f-wersja-popraw-'.$comment->id.'"', false);
        $strona->assertSee('id="f-wersja-popraw-'.$comment->id.'"', false);
        $strona->assertSee('>Sól i pieprz do smaku</textarea>', false);
        $this->assertMatchesRegularExpression('/<details\s+open\s*>/', $strona->getContent());
        $this->assertSame('Pół łyżeczki soli', $comment->fresh()->body);
        // Formularz niesie teraz AKTUALNĄ wersję: świadomy drugi zapis przejdzie.
        $this->assertSame($comment->fresh()->wersjaTresci(), $this->wersjaZOdpowiedzi($strona->getContent(), $comment));
    }

    public function test_swiadomy_ponowny_zapis_po_konflikcie_przechodzi(): void
    {
        [$basia, $post, $comment] = $this->komentarz('Sól do smaku');
        $wersjaStartowa = $comment->wersjaTresci();
        $comment->update(['body' => 'Pół łyżeczki soli']);

        $this->actingAs($basia)->put(route('comments.update', $comment), [
            'body' => 'Sól i pieprz do smaku', 'wersja' => $wersjaStartowa,
        ])->assertSessionHasErrors('wersja');

        $this->actingAs($basia)->put(route('comments.update', $comment), [
            'body' => 'Sól i pieprz do smaku', 'wersja' => $this->wersjaZFormularza($basia, $post, $comment),
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Komentarz poprawiony.');

        $this->assertSame('Sól i pieprz do smaku', $comment->fresh()->body);
    }

    public function test_odpowiedz_tez_niesie_wersje_i_konflikt(): void
    {
        [$basia, $post, $root] = $this->komentarz('Pytanie o sól');
        $reply = Comment::factory()->create([
            'author_id' => $basia->getKey(), 'post_id' => $post->getKey(),
            'parent_id' => $root->getKey(), 'body' => 'Sól do smaku', 'created_at' => now()->subMinutes(2),
        ]);
        $wersjaStartowa = $this->wersjaZFormularza($basia, $post, $reply);
        $reply->update(['body' => 'Pół łyżeczki soli']);

        $this->actingAs($basia)->from(route('posts.show', $post))
            ->put(route('comments.update', $reply), [
                'body' => 'Sól i pieprz do smaku', 'wersja' => $wersjaStartowa, '_wiersz' => 'popraw-'.$reply->id,
            ])
            ->assertSessionHasErrors('wersja')
            ->assertSessionHasInput('body', 'Sól i pieprz do smaku');

        $this->assertSame('Pół łyżeczki soli', $reply->fresh()->body);
        $this->actingAs($basia)->from(route('posts.show', $post))->followingRedirects()
            ->put(route('comments.update', $reply), [
                'body' => 'Sól i pieprz do smaku', 'wersja' => $wersjaStartowa, '_wiersz' => 'popraw-'.$reply->id,
            ])
            ->assertOk()
            ->assertSee('id="f-wersja-popraw-'.$reply->id.'"', false)
            ->assertSee('>Sól i pieprz do smaku</textarea>', false);
        $this->assertSame('Pół łyżeczki soli', $reply->fresh()->body);
    }

    /**
     * Ponowienie już zapisanej, identycznej zmiany (podwójne kliknięcie,
     * odświeżone żądanie) to sukces bez zapisu — nie fałszywy konflikt.
     */
    public function test_ponowienie_identycznej_zmiany_to_sukces_bez_zapisu(): void
    {
        [$basia, , $comment] = $this->komentarz('Sól do smaku');
        $wersjaStartowa = $comment->wersjaTresci();
        $zadanie = ['body' => 'Pół łyżeczki soli', 'wersja' => $wersjaStartowa];

        $this->actingAs($basia)->put(route('comments.update', $comment), $zadanie)->assertSessionHasNoErrors();
        $poPierwszym = $comment->fresh()->updated_at;
        $this->travel(5)->seconds();

        $this->actingAs($basia)->put(route('comments.update', $comment), $zadanie)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Komentarz poprawiony.');

        $this->assertSame('Pół łyżeczki soli', $comment->fresh()->body);
        $this->assertTrue($poPierwszym->equalTo($comment->fresh()->updated_at), 'Ponowienie nie może zapisywać wiersza drugi raz.');
    }

    /**
     * Odrzucona poprawka z drugiej karty nie zleca skutków ubocznych edycji
     * (kryterium #982): ponownej analizy treści (#909, D-256) ani zapisu
     * wiersza. Kontrola dodatnia: poprawka z aktualną wersją zleca analizę.
     */
    public function test_konflikt_nie_zleca_ponownej_analizy(): void
    {
        Queue::fake([PrzeanalizujTresc::class]);
        [$basia, , $comment] = $this->komentarz('Sól do smaku');
        $wersjaStartowa = $comment->wersjaTresci();

        $this->actingAs($basia)->put(route('comments.update', $comment), [
            'body' => 'Pół łyżeczki soli', 'wersja' => $wersjaStartowa,
        ])->assertSessionHasNoErrors();
        Queue::assertPushed(PrzeanalizujTresc::class, 1);
        $poPierwszym = $comment->fresh()->updated_at;
        $this->travel(5)->seconds();

        $this->actingAs($basia)->put(route('comments.update', $comment), [
            'body' => 'Sól i pieprz do smaku', 'wersja' => $wersjaStartowa,
        ])->assertSessionHasErrors('wersja');

        Queue::assertPushed(PrzeanalizujTresc::class, 1);
        $this->assertSame('Pół łyżeczki soli', $comment->fresh()->body);
        $this->assertTrue($poPierwszym->equalTo($comment->fresh()->updated_at), 'Odrzucona poprawka zapisała wiersz.');
    }

    /** @return array{0: User, 1: Post, 2: Comment} */
    private function komentarz(string $body): array
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);
        $comment = Comment::factory()->create([
            'author_id' => $basia->getKey(), 'post_id' => $post->getKey(),
            'body' => $body, 'created_at' => now()->subMinutes(3),
        ]);

        return [$basia, $post, $comment];
    }

    /** Wersja odczytana z prawdziwego formularza, nie policzona w teście. */
    private function wersjaZFormularza(User $user, Post $post, Comment $comment): string
    {
        return $this->wersjaZOdpowiedzi($this->actingAs($user)->get(route('posts.show', $post))->assertOk()->getContent(), $comment);
    }

    private function wersjaZOdpowiedzi(string $html, Comment $comment): string
    {
        $akcja = preg_quote(e(route('comments.update', $comment)), '/');
        $this->assertSame(1, preg_match('/<form[^>]*action="'.$akcja.'"[^>]*>(.*?)<\/form>/s', $html, $formularz), 'Brak formularza poprawki.');
        $this->assertSame(1, preg_match('/name="wersja" value="([0-9a-f]{64})"/', $formularz[1], $wersja), 'Formularz poprawki nie niesie wersji.');

        return $wersja[1];
    }
}
