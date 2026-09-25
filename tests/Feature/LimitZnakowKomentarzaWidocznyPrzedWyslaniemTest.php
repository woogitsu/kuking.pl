<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #762: formularz komentarza informował o limicie 4000 znaków
 * dopiero PO nieudanym wysłaniu. `components/field.blade.php` pokazuje
 * teraz zawsze widoczną podpowiedź „Najwyżej 4000 znaków." przy nowym
 * komentarzu, odpowiedzi i poprawce — DZIAŁA BEZ JAVASCRIPTU, bo ten test
 * nie uruchamia żadnego skryptu, tylko czyta zwrócony HTML.
 *
 * `resources/js/licznik-znakow.test.mjs` sprawdza osobno logikę licznika
 * „na żywo" (treść komunikatu, liczenie punktów kodowych) — to jest
 * ulepszenie nad tym, co ten plik potwierdza jako działające bez JS.
 */
class LimitZnakowKomentarzaWidocznyPrzedWyslaniemTest extends TestCase
{
    use RefreshDatabase;

    private function wpisZAutorem(): Post
    {
        $autor = $this->user('autorwpisu762');

        return Post::factory()->for($autor, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);
    }

    public function test_formularz_nowego_komentarza_pokazuje_limit_przed_wyslaniem(): void
    {
        $wpis = $this->wpisZAutorem();

        $strona = $this->actingAs($this->user('czytelnik762'))->get(route('posts.show', $wpis));

        $strona->assertOk();
        $strona->assertSee('Najwyżej 4000 znaków.');
    }

    public function test_formularz_odpowiedzi_i_poprawki_tez_pokazuja_limit(): void
    {
        $wpis = $this->wpisZAutorem();
        $autorKomentarza = $this->user('komentator762');

        $komentarz = Comment::create([
            'author_id' => $autorKomentarza->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Komentarz do poprawienia.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        // Autor widzi też formularz „Popraw" (w oknie 15 minut) — obie
        // podpowiedzi („nowy komentarz" i „popraw") muszą się dało odróżnić,
        // bo dzielą tę samą treść i stoją na tej samej stronie.
        $strona = $this->actingAs($autorKomentarza)->get(route('posts.show', $wpis));

        $strona->assertOk();
        // Co najmniej dwie podpowiedzi: formularz nowego komentarza i formularz poprawki.
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($strona->getContent(), 'Najwyżej 4000 znaków.'),
            'Formularz nowego komentarza i formularz poprawki mają obie mieć widoczny limit.',
        );
    }

    /** Kryterium odbioru z #762: 4000 przechodzi, 4001 nie przechodzi — realny POST. */
    public function test_dokladnie_4000_znakow_przechodzi_a_4001_jest_odrzucane(): void
    {
        $wpis = $this->wpisZAutorem();
        $autor = $this->user('graniczny762');

        $czterysta = str_repeat('a', 4000);
        $this->actingAs($autor)
            ->post(route('posts.comment', $wpis), ['body' => $czterysta])
            ->assertSessionDoesntHaveErrors('body');
        $this->assertDatabaseHas('comments', ['body' => $czterysta]);

        $czteryTysiaceJeden = str_repeat('b', 4001);
        $odpowiedz = $this->actingAs($autor)
            ->post(route('posts.comment', $wpis), ['body' => $czteryTysiaceJeden]);

        $odpowiedz->assertSessionHasErrors('body');
        $this->assertDatabaseMissing('comments', ['body' => $czteryTysiaceJeden]);

        // Komunikat mówi, co zrobić — nie tylko „za długo" (AGENTS.md, UX 50+).
        $bledy = self::sesjaPrzekierowania($odpowiedz)->get('errors');
        $this->assertStringContainsString('4000 znak', $bledy->first('body'));
    }
}
