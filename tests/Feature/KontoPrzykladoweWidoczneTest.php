<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Oznaczenie „Konto przykładowe" MUSI być widoczne wszędzie tam, gdzie
 * serwis pokazuje autora — D-025, `docs/DECISIONS.md`.
 *
 * Cztery ekrany z zadania: profil, karta wpisu, karta przepisu, komentarz.
 * Każdy test ma parę: oznaczenie WIDAĆ u konta `is_seeded`, oznaczenie NIE
 * WIDAĆ u prawdziwego konta — a w testach karty wpisu i karty przepisu ta
 * druga połowa jest sprawdzana w TEJ SAMEJ odpowiedzi HTTP (post/przepis
 * konta przykładowego, obok komentarza od prawdziwego człowieka), więc to
 * jest kontrola, nie tylko dwa niezależne stwierdzenia.
 */
class KontoPrzykladoweWidoczneTest extends TestCase
{
    use RefreshDatabase;

    private const ETYKIETA = 'Konto przykładowe — nie prawdziwa osoba';

    // -----------------------------------------------------------------
    // Profil
    // -----------------------------------------------------------------

    public function test_profil_konta_przykladowego_pokazuje_oznaczenie(): void
    {
        $persona = $this->user('halina', ['is_seeded' => true, 'display_name' => 'Halina z Kaszub']);

        $response = $this->get(route('profile.show', 'halina'));

        $response->assertOk();
        $response->assertSee(self::ETYKIETA);
        $response->assertSee('To konto jest przykładowe', false);
    }

    public function test_profil_prawdziwego_konta_nie_pokazuje_oznaczenia(): void
    {
        $this->user('henryk', ['display_name' => 'Henryk ze Śląska']);

        $response = $this->get(route('profile.show', 'henryk'));

        $response->assertOk();
        $response->assertDontSee(self::ETYKIETA);
        $response->assertDontSee('To konto jest przykładowe', false);
    }

    // -----------------------------------------------------------------
    // Karta wpisu + komentarz, w JEDNEJ odpowiedzi (kontrola)
    // -----------------------------------------------------------------

    public function test_karta_wpisu_i_komentarz_oznaczaja_tylko_konta_przykladowe(): void
    {
        $autorPersona = $this->user('jurek', ['is_seeded' => true, 'display_name' => 'Jurek z Mazur']);
        $komentujacaPersona = $this->user('zosia', ['is_seeded' => true, 'display_name' => 'Zosia z Wielkopolski']);
        $prawdziwyCzlowiek = $this->user('marek_prawdziwy', ['display_name' => 'Marek']);

        $post = Post::factory()->create(['author_id' => $autorPersona->getKey()]);

        Comment::factory()->create([
            'author_id' => $komentujacaPersona->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Komentarz od persony.',
        ]);

        Comment::factory()->create([
            'author_id' => $prawdziwyCzlowiek->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Komentarz od prawdziwego człowieka.',
        ]);

        $response = $this->get($post->url());

        $response->assertOk();
        // Autor wpisu (persona) + autorka komentarza (persona) = dwa
        // wystąpienia. Gdyby oznaczenie wyciekło na prawdziwego człowieka,
        // byłyby trzy.
        $this->assertSame(2, substr_count($response->getContent(), self::ETYKIETA));
        $response->assertSee('Marek');
        $response->assertSee('Komentarz od prawdziwego człowieka.');
    }

    // -----------------------------------------------------------------
    // Karta przepisu + komentarz, w JEDNEJ odpowiedzi (kontrola)
    // -----------------------------------------------------------------

    public function test_karta_przepisu_i_komentarz_oznaczaja_tylko_konta_przykladowe(): void
    {
        $autorPersona = $this->user('teresa', ['is_seeded' => true, 'display_name' => 'Teresa z Kujaw']);
        $komentujacaPersona = $this->user('antoni', ['is_seeded' => true, 'display_name' => 'Antoni z Lubelszczyzny']);
        $prawdziwyCzlowiek = $this->user('ania_prawdziwa', ['display_name' => 'Ania']);

        $recipe = Recipe::factory()->create(['author_id' => $autorPersona->getKey()]);

        // `post_id` na `null` jawnie: `CommentFactory` domyślnie podpina
        // komentarz pod nowy Post, a `comments_single_target_check` w bazie
        // pozwala mieć wypełnione dokładnie JEDNO z `post_id`/`recipe_id`.
        Comment::factory()->create([
            'author_id' => $komentujacaPersona->getKey(),
            'post_id' => null,
            'recipe_id' => $recipe->getKey(),
            'body' => 'Komentarz od persony pod przepisem.',
        ]);

        Comment::factory()->create([
            'author_id' => $prawdziwyCzlowiek->getKey(),
            'post_id' => null,
            'recipe_id' => $recipe->getKey(),
            'body' => 'Komentarz od prawdziwej osoby pod przepisem.',
        ]);

        $response = $this->get($recipe->url());

        $response->assertOk();
        $this->assertSame(2, substr_count($response->getContent(), self::ETYKIETA));
        $response->assertSee('Ania');
        $response->assertSee('Komentarz od prawdziwej osoby pod przepisem.');
    }

    // -----------------------------------------------------------------
    // Karta przepisu w formie skróconej (`x-recipe-card`, zakładka „przepisy")
    // -----------------------------------------------------------------

    public function test_skrocona_karta_przepisu_na_liscie_tez_pokazuje_oznaczenie(): void
    {
        $persona = $this->user('grazyna', ['is_seeded' => true, 'display_name' => 'Grażyna z Łódzkiego']);
        Recipe::factory()->create(['author_id' => $persona->getKey(), 'title' => 'Szarlotka Grażyny']);

        $response = $this->get(route('profile.show', ['username' => 'grazyna', 'zakladka' => 'przepisy']));

        $response->assertOk();
        $response->assertSee(self::ETYKIETA);
        $response->assertSee('Szarlotka Grażyny');
    }
}
