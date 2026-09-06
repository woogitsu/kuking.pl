<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Edycja i usunięcie wpisu z poziomu menu „…" na karcie (luka MVP:
 * `docs/FEATURES.md` sekcja „Wpis" i `docs/ROADMAP.md` §3 wymieniają
 * edycję i usunięcie, a menu autora pokazywało wyłącznie „Otwórz wpis").
 *
 * Zdjęcia ZOSTAJĄ POZA tym ekranem — mają już swój, „Zdjęcia w tym wpisie"
 * (`PostMediaController`, patrz `EdycjaZdjecWpisuTest` gdzieś indziej, jeśli
 * istnieje). Ten plik sprawdza tylko tekst, widoczność i temat.
 */
class EdycjaWpisuTest extends TestCase
{
    use RefreshDatabase;

    private function temat(string $slug = 'zupy', array $nadpisz = []): Topic
    {
        // `is_active` celowo nie jest w `$fillable` na modelu Topic — patrz
        // komentarz przy `TematyWpisowTest::temat()`, ten sam powód tutaj.
        return Topic::create(array_merge([
            'slug' => $slug,
            'name' => 'Zupy',
            'description' => 'Od rosołu po krem.',
            'position' => 1,
            'is_active' => true,
        ], $nadpisz));
    }

    public function test_autor_widzi_swoj_tekst_na_ekranie_edycji(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => 'Rosół na niedzielę. Wyszedł złoty.',
        ]);

        $response = $this->actingAs($basia)->get(route('posts.edit', $post));

        $response->assertOk();
        $response->assertSee('Rosół na niedzielę. Wyszedł złoty.', false);
    }

    public function test_autor_zapisuje_zmiane_tekstu_widocznosci_i_tematu(): void
    {
        $basia = $this->user('basia');
        $temat = $this->temat();
        $post = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => 'Wersja pierwsza.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $response = $this->actingAs($basia)->put(route('posts.update', $post), [
            'body' => 'Wersja poprawiona, po korekcie.',
            'visibility' => 'private',
            'topic_id' => $temat->getKey(),
        ]);

        $response->assertRedirect(route('posts.show', $post));
        $response->assertSessionHas('status');

        $post->refresh();
        $this->assertSame('Wersja poprawiona, po korekcie.', $post->body);
        $this->assertSame('private', $post->visibility);
        $this->assertSame($temat->getKey(), $post->topic_id);
        // Publikacja zostaje na miejscu — zmiana widoczności to nie to samo
        // co wycofanie wpisu (Post::isPublished() patrzy na status i datę,
        // nie na widoczność).
        $this->assertTrue($post->isPublished());
    }

    public function test_obcy_dostaje_odmowe_przy_otwieraniu_edycji(): void
    {
        $basia = $this->user('basia');
        $obcy = $this->user('obcy');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);

        $this->actingAs($obcy)->get(route('posts.edit', $post))->assertForbidden();
    }

    public function test_obcy_dostaje_odmowe_przy_zapisie_edycji(): void
    {
        $basia = $this->user('basia');
        $obcy = $this->user('obcy');
        $post = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => 'Nietknięte przez obcego.',
        ]);

        $response = $this->actingAs($obcy)->put(route('posts.update', $post), [
            'body' => 'Podmienione przez kogoś innego',
            'visibility' => 'public',
        ]);

        $response->assertForbidden();
        $this->assertSame('Nietknięte przez obcego.', $post->fresh()->body);
    }

    public function test_niezalogowany_trafia_na_logowanie_przy_edycji(): void
    {
        $post = Post::factory()->create();

        $this->get(route('posts.edit', $post))->assertRedirect(route('login'));
        $this->put(route('posts.update', $post), [
            'body' => 'Próba bez zalogowania',
            'visibility' => 'public',
        ])->assertRedirect(route('login'));
    }

    public function test_pusta_tresc_bez_zdjecia_jest_odrzucana_a_wpisany_tekst_nie_znika(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => 'Ten tekst zniknąłby bez tej poprawki.',
        ]);

        $response = $this->actingAs($basia)->put(route('posts.update', $post), [
            'body' => '   ',
            'visibility' => 'public',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('body');
        // Poprawnie wpisane dane nie znikają po nieudanej walidacji —
        // tu wpisana (pusta) treść wraca, ale wpis w bazie zostaje bez zmian.
        $this->assertSame('Ten tekst zniknąłby bez tej poprawki.', $post->fresh()->body);
    }

    public function test_za_dluga_tresc_jest_odrzucana_a_wpisany_tekst_nie_znika(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);
        $zaDlugi = str_repeat('a', 4001);

        $response = $this->actingAs($basia)->put(route('posts.update', $post), [
            'body' => $zaDlugi,
            'visibility' => 'public',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('body');
        $response->assertSessionHasInput('body', $zaDlugi);
    }

    public function test_usun_wpis_jest_w_menu_karty_dla_autora_i_nie_ma_go_dla_obcego(): void
    {
        $basia = $this->user('basia');
        $obcy = $this->user('obcy');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);

        $jakoAutor = $this->actingAs($basia)->get(route('posts.show', $post));
        $jakoAutor->assertOk();
        $jakoAutor->assertSee('Edytuj wpis', false);
        $jakoAutor->assertSee('Usuń wpis', false);

        $jakoObcy = $this->actingAs($obcy)->get(route('posts.show', $post));
        $jakoObcy->assertOk();
        $jakoObcy->assertDontSee('Edytuj wpis', false);
        $jakoObcy->assertDontSee('Usuń wpis', false);
    }

    // Obcy nie usunie cudzego wpisu — pokryte już w
    // SecurityTest::test_obcy_nie_usunie_cudzego_wpisu (nazwa przybliżona),
    // patrz `posts.destroy` w tamtym pliku. Nie duplikujemy tego tutaj.
}
