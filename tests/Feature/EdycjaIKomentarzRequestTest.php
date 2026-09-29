<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\Posts\EdycjaWpisuRequest;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Walidacja edycji wpisu i komentarza wyjęta z `PostController::update()`
 * i `comment()` (issue #970, krok 4). Zachowanie nie miało się zmienić —
 * ten test pilnuje tego, co przy przenosinach łatwo zgubić: kolejności
 * (Policy przed walidacją komentarza; akcje tagów przed walidacją edycji).
 */
class EdycjaIKomentarzRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_edycja_z_zla_widocznoscia_wraca_z_komunikatem_i_tekstem(): void
    {
        $autor = $this->user('edytor970');
        $post = Post::factory()->create(['author_id' => $autor->getKey(), 'body' => 'Stary tekst']);

        $this->actingAs($autor)->from('/wpisy/edycja')
            ->put(route('posts.update', $post), ['body' => 'Nowy tekst', 'visibility' => 'wszyscy'])
            ->assertRedirect('/wpisy/edycja')
            ->assertSessionHasErrors(['visibility' => 'Zaznacz, kto ma widzieć ten wpis: wszyscy, obserwujący czy tylko Ty.'])
            ->assertSessionHasInput('body', 'Nowy tekst');

        $this->assertSame('Stary tekst', $post->fresh()->body);
    }

    public function test_przycisk_tagu_nie_wymaga_poprawnej_widocznosci(): void
    {
        $autor = $this->user('edytor971');
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);

        // Bez `visibility` — gdyby walidacja szła przed akcją tagów, byłby
        // tu błąd pola zamiast dodanego tagu.
        $this->actingAs($autor)->from('/wpisy/edycja')
            ->put(route('posts.update', $post), ['body' => 'Tekst', 'dodaj_tag' => 'sernik'])
            ->assertSessionHasNoErrors()
            ->assertSessionHasInput('tag_names', ['sernik']);
    }

    public function test_edycja_pytania_wymaga_tytulu_a_wpisu_nie(): void
    {
        $pytanie = Post::factory()->make(['kind' => Post::KIND_QUESTION]);
        $wpis = Post::factory()->make();

        $dane = ['title' => 'Za krótko', 'visibility' => 'public'];
        $bladPytania = $this->zadanieEdycji($dane)->walidatorTresci($pytanie);
        $this->assertSame('Rozwiń pytanie do co najmniej 10 znaków.', $bladPytania->errors()->first('title'));

        // Kontrola ujemna: ten sam tytuł nie przeszkadza zwykłemu wpisowi
        // i nie trafia do zwalidowanych danych.
        $this->assertFalse($this->zadanieEdycji($dane)->walidatorTresci($wpis)->fails());
        $this->assertArrayNotHasKey('title', $this->zadanieEdycji($dane)->walidatorTresci($wpis)->validated());
    }

    public function test_komentarz_bez_prawa_dostaje_403_zanim_ktokolwiek_sprawdzi_pola(): void
    {
        $post = Post::factory()->create(['visibility' => Post::VISIBILITY_PRIVATE]);
        $obcy = $this->user('obcy970');

        // Pusty komentarz złamałby walidację; Policy musi przyjść pierwsza.
        $this->actingAs($obcy)->post(route('posts.comment', $post), ['body' => ''])
            ->assertForbidden();
    }

    public function test_komentarz_mowi_po_polsku_co_poprawic_i_nie_gubi_tekstu(): void
    {
        $post = Post::factory()->create();
        $czytelnik = $this->user('czytelnik970');

        $this->actingAs($czytelnik)->from('/wpisy/x')
            ->post(route('posts.comment', $post), ['body' => ''])
            ->assertSessionHasErrors(['body' => 'Napisz coś, zanim wyślesz komentarz.']);

        $this->from('/wpisy/x')
            ->post(route('posts.comment', $post), ['body' => str_repeat('a', 4001)])
            ->assertSessionHasErrors(['body' => 'Ten komentarz jest za długi. Zmieść się w 4000 znakach.'])
            ->assertSessionHasInput('body');

        $this->from('/wpisy/x')
            ->post(route('posts.comment', $post), ['body' => 'Ok', 'parent_id' => 'nie-uuid'])
            ->assertSessionHasErrors('parent_id');

        // Kontrola dodatnia: bez pola `parent_id` (klient spoza formularza)
        // komentarz się publikuje, nie kończy 500.
        $this->post(route('posts.comment', $post), ['body' => 'Smacznego!'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');
        $this->assertSame(1, $post->allComments()->count());
    }

    /**
     * @param  array<string, mixed>  $dane
     */
    private function zadanieEdycji(array $dane): EdycjaWpisuRequest
    {
        return EdycjaWpisuRequest::create('/wpisy/x', 'PUT', $dane);
    }
}
