<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Blokada działa też przy ODPOWIADANIU na komentarz (audyt W7-06, SEC-06).
 *
 * Blokada obowiązywała dotąd na trzech powierzchniach: renderowanie
 * (`Comment::scopeWidoczneDla`), powiadomienia (`NotifyUser`) i tworzenie
 * komentarza — ale to ostatnie tylko wobec WŁAŚCICIELA treści. Wobec autora
 * komentarza, pod którym się odpowiada, nie działało nigdzie.
 *
 * SCENARIUSZ
 * A i B są w relacji blokady, ale oboje mogą komentować u C. B pisze
 * komentarz X. A go nie widzi, bo filtr go ukrywa — ale znając UUID
 * komentarza X (od wspólnego znajomego, ze zrzutu ekranu, sprzed blokady)
 * A mógł wysłać `parent_id = X` i utworzyć odpowiedź strukturalnie podpiętą
 * pod wątek B.
 *
 * Powiadomienie do B i tak nie szło, więc nękania z tego nie było — ale zapis
 * przekraczał granicę, o której interfejs mówi, że jej nie da się przekroczyć,
 * a wątek B rósł o cudzą odpowiedź. To jest ten sam wzorzec, który wraca
 * w audytach: reguła żyje w jednej warstwie, a druga ją omija.
 */
class BlokadaObowiazujeTakzePrzyOdpowiadaniuTest extends TestCase
{
    use RefreshDatabase;

    public function test_zablokowany_nie_podepnie_sie_pod_watek_osoby_ktora_go_blokuje(): void
    {
        $c = $this->user('gospodarz');
        $b = $this->user('zablokowanyb');
        $a = $this->user('blokujacya');

        $a->blocking()->attach($b->getKey());

        $wpis = Post::factory()->for($c, 'author')->create([
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subHour(),
        ]);

        $komentarzB = Comment::create([
            'author_id' => $b->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Robiłam podobnie, tylko z majerankiem.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        // A zna UUID komentarza B, choć go nie widzi.
        $this->actingAs($a)
            ->post(route('posts.comment', $wpis), [
                'body' => 'A ja uważam inaczej.',
                'parent_id' => $komentarzB->getKey(),
            ]);

        $this->assertSame(
            0,
            Comment::where('parent_id', $komentarzB->getKey())->count(),
            'Powstała odpowiedź pod komentarzem osoby w relacji blokady.',
        );
    }

    public function test_komentarz_bez_pola_parent_id_nie_wywala_serwisu(): void
    {
        // Nie audyt, tylko awaria znaleziona przy okazji: `validate()` NIE
        // zwraca klucza, którego w żądaniu nie było, a `parent_id` jest
        // `nullable`. Nasz formularz zawsze wysyła puste pole, więc z ekranu
        // to nie wychodziło — ale każde żądanie spoza formularza kończyło się
        // „Undefined array key \"parent_id\"", czyli błędem 500 zamiast
        // komentarza.
        $autor = $this->user('autor');
        $wpis = Post::factory()->for($autor, 'author')->create([
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subHour(),
        ]);

        $this->actingAs($this->user('czytelnik'))
            ->post(route('posts.comment', $wpis), ['body' => 'Bez pola parent_id.'])
            ->assertRedirect();

        $this->assertDatabaseHas('comments', ['body' => 'Bez pola parent_id.']);
    }

    public function test_odpowiedz_pod_komentarzem_z_innej_strony_nie_powstaje(): void
    {
        // Rodzic musi stać pod TĄ treścią. Odpowiedź podpięta pod komentarz
        // z innego wpisu rozjeżdża wątek w obie strony: u siebie jej nie
        // widać, a w cudzym wątku wisi.
        $autor = $this->user('autor');

        $pierwszy = Post::factory()->for($autor, 'author')->create([
            'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subHour(),
        ]);
        $drugi = Post::factory()->for($autor, 'author')->create([
            'status' => 'published', 'visibility' => 'public', 'published_at' => now()->subHour(),
        ]);

        $obcyKomentarz = Comment::create([
            'author_id' => $autor->getKey(),
            'post_id' => $pierwszy->getKey(),
            'body' => 'Komentarz spod pierwszego wpisu.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $this->actingAs($this->user('czytelnik'))
            ->post(route('posts.comment', $drugi), [
                'body' => 'Podpinam się nie tam, gdzie trzeba.',
                'parent_id' => $obcyKomentarz->getKey(),
            ]);

        $this->assertSame(0, Comment::where('parent_id', $obcyKomentarz->getKey())->count());
    }
}
