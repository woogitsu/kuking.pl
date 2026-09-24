<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * „Zdejmij z urzędu” — poprawki z przeglądu kodu (G31, D-251).
 *
 * Kontrola ujemna (zepsuć → test oblewa → przywrócić): usunąć
 * `isModerator()` z komponentu i eager load `post` w `PostController`
 * — oblewa test liczby zapytań.
 */
class ZdejmijZUrzeduPoPrzegladzieTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    // ── Przycisk „Zdejmij z urzędu” bez N+1 ───────────────────────────

    public function test_strona_wpisu_dla_zwyklego_uzytkownika_nie_ma_zapytan_na_komentarz(): void
    {
        $wpis = Post::factory()->create();
        $widz = $this->user('czytelnik');

        $this->komentarze($wpis, 2, od: 0);
        $malo = $this->policzZapytania(fn () => $this->actingAs($widz)->get(route('posts.show', $wpis))->assertOk());

        $this->komentarze($wpis, 8, od: 2);
        $html = $this->actingAs($widz)->get(route('posts.show', $wpis))->assertOk()->getContent();
        // Kontrola dodatnia: pomiar mierzy rozmowę, nie pustą stronę.
        $this->assertStringContainsString('Komentarz numer 9', $html);
        $this->assertStringContainsString('Odpowiedź numer 9', $html);

        $duzo = $this->policzZapytania(fn () => $this->actingAs($widz)->get(route('posts.show', $wpis))->assertOk());

        $this->assertSame($malo, $duzo, "Strona wpisu dla zalogowanego: {$malo} zapytań przy 2 komentarzach, {$duzo} przy 10.");
    }

    public function test_strona_wpisu_dla_moderatora_tez_nie_ma_zapytan_na_komentarz(): void
    {
        $wpis = Post::factory()->create();
        $moderator = $this->moderator();

        $this->komentarze($wpis, 2, od: 0);
        $malo = $this->policzZapytania(fn () => $this->actingAs($moderator)->get(route('posts.show', $wpis))->assertOk());

        $this->komentarze($wpis, 8, od: 2);
        $html = $this->actingAs($moderator)->get(route('posts.show', $wpis))->assertOk()->getContent();
        $this->assertStringContainsString('Zdejmij z urzędu', $html, 'Moderator nie widzi przycisku — pomiar nie mierzy komponentu.');

        $duzo = $this->policzZapytania(fn () => $this->actingAs($moderator)->get(route('posts.show', $wpis))->assertOk());

        $this->assertSame($malo, $duzo, "Strona wpisu dla moderatora: {$malo} zapytań przy 2 komentarzach, {$duzo} przy 10.");
    }

    public function test_strona_wykonania_dla_moderatora_nie_ma_zapytan_na_komentarz(): void
    {
        $wykonanie = CookedEvent::factory()->create([
            'recipe_id' => Recipe::factory()->create()->getKey(),
        ]);
        $moderator = $this->moderator();

        $this->komentarze($wykonanie, 2, od: 0);
        $malo = $this->policzZapytania(fn () => $this->actingAs($moderator)->get(route('cooked.show', $wykonanie))->assertOk());

        $this->komentarze($wykonanie, 8, od: 2);
        $duzo = $this->policzZapytania(fn () => $this->actingAs($moderator)->get(route('cooked.show', $wykonanie))->assertOk());

        $this->assertSame($malo, $duzo, "Strona wykonania dla moderatora: {$malo} zapytań przy 2 komentarzach, {$duzo} przy 10.");
    }

    /** Komentarze różnych osób, każdy z jedną odpowiedzią kolejnej osoby. */
    private function komentarze(Post|CookedEvent $gdzie, int $ile, int $od): void
    {
        $kolumna = $gdzie instanceof Post ? 'post_id' : 'cooked_event_id';

        for ($i = $od; $i < $od + $ile; $i++) {
            $komentarz = Comment::factory()->create([
                'post_id' => null,
                $kolumna => $gdzie->getKey(),
                'author_id' => $this->user()->getKey(),
                'body' => 'Komentarz numer '.$i,
            ]);
            Comment::factory()->create([
                'post_id' => null,
                $kolumna => $gdzie->getKey(),
                'parent_id' => $komentarz->getKey(),
                'author_id' => $this->user()->getKey(),
                'body' => 'Odpowiedź numer '.$i,
            ]);
        }
    }

    private function policzZapytania(callable $akcja): int
    {
        $ile = 0;
        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        $akcja();

        return $ile;
    }
}
