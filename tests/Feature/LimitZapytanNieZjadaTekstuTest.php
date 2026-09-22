<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Odbicie przez limit zapytań oddaje człowiekowi to, co napisał (audyt G06).
 *
 * CO BYŁO ZMIERZONE PRZED NAPRAWĄ
 * Jedenaste wysłanie komentarza pod rząd kończyło się tak: HTTP 429,
 * `old('body')` puste, tekstu sondy NIGDZIE w odpowiedzi — a na ekranie
 * zdanie „Nic się nie zepsuło i nic nie przepadło". Serwer nie zachowywał
 * niczego i mówił, że zachował.
 *
 * Powrót „wstecz" bywa ratunkiem, ale to zachowanie przeglądarki, nie
 * obietnica aplikacji; pliku nie odzyskuje w ogóle. Obietnica stała
 * wypisana na ekranie, więc musi ją pokrywać kod, nie przeglądarka.
 *
 * AGENTS.md §5: poprawnie wpisane dane nigdy nie znikają. Limit zapytań
 * nie jest wyjątkiem od tej reguły — jest jej najtrudniejszym przypadkiem,
 * bo trafia w człowieka DOKŁADNIE w chwili, w której skończył pisać.
 *
 * CZEGO TEN TEST PILNUJE OSOBNO
 * Że odzyskiwanie NIE rozlewa się poza trasy treści. `OdzyskiwalneDane`
 * zgadza się po nazwie trasy, więc limit logowania — ten, który odbija
 * najczęściej — nie ma prawa oddać hasła w HTML-u. To jest ta sama granica
 * co przy ekranie 419 (`SekretyNieWracajaNaEkranTest`), tylko wejście inne.
 */
class LimitZapytanNieZjadaTekstuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Limit komentarzy z `config/kuking.php` to 10 na minutę, liczony po
     * koncie. Bijemy w niego jedenastym wysłaniem — dokładnie tak samo jak
     * `LimitZapytanZostawiaSladTest`.
     */
    public function test_po_429_tekst_komentarza_wraca_w_formularzu(): void
    {
        $basia = $this->user('basia');

        $post = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $tekst = 'Robiłam to wczoraj z połową porcji rozmarynu i wyszło łagodniej.';

        $odpowiedz = null;

        for ($i = 0; $i <= 10; $i++) {
            $odpowiedz = $this->actingAs($basia)
                ->post(route('posts.comment', $post), ['body' => $tekst]);
        }

        $odpowiedz->assertStatus(429);

        // 1. Tekst jest NA STRONIE, nie w sesji i nie w cudzej pamięci.
        $odpowiedz->assertSee($tekst, escape: false);

        // 2. Da się go wysłać jeszcze raz jednym kliknięciem, bez JavaScriptu.
        $odpowiedz->assertSee('Wyślij jeszcze raz');
        $odpowiedz->assertSee('action="'.route('posts.comment', $post).'"', escape: false);

        // 3. Nagłówek `Retry-After` przeżył ręczne rysowanie odpowiedzi —
        //    bez niego ekran nie wie, na ile jest ta przerwa, i mówi
        //    „za kilka minut" zamiast „za 1 min".
        $this->assertNotNull(
            $odpowiedz->headers->get('Retry-After'),
            'Ekran 429 rysujemy sami, więc nagłówki z wyjątku trzeba przepisać ręcznie. '
            .'Bez `Retry-After` człowiek nie dowie się, ile ma czekać.',
        );
    }

    public function test_po_429_przepis_wraca_w_calosci_a_o_zdjeciu_mowimy_wprost(): void
    {
        $basia = $this->user('basia');

        // `recipes.store` ma własny limiter; bijemy w niego tą samą metodą,
        // ale z pełnym formularzem — tekst DŁUGI (wraca jako `textarea`),
        // tekst krótki (wraca jako pole ukryte) i plik (nie wraca wcale).
        $tytul = 'Zupa z pieczonej dyni na niedzielę';
        $opis = 'Dynię piekę w całości, potem obieram łyżką — tak jest najprościej, '
            .'a skórka schodzi sama. Śmietanę dodaję na końcu, już poza ogniem.';

        $odpowiedz = null;

        for ($i = 0; $i <= 30; $i++) {
            $odpowiedz = $this->actingAs($basia)->post(route('recipes.store'), [
                'title' => $tytul,
                'body' => $opis,
                'hero_photo' => UploadedFile::fake()->image('dynia.jpg'),
            ]);

            if ($odpowiedz->getStatusCode() === 429) {
                break;
            }
        }

        $odpowiedz->assertStatus(429);
        $odpowiedz->assertSee($opis, escape: false);
        $odpowiedz->assertSee($tytul, escape: false);

        // O pliku mówimy PRAWDĘ zamiast obiecywać, że jest. Przeglądarka nie
        // pozwala wpisać wartości do `<input type="file">` i dobrze — inaczej
        // strona mogłaby podkraść plik z dysku.
        $odpowiedz->assertSee('Wybierz zdjęcie jeszcze raz');

        // Żaden przepis nie powstał — 429 odbija PRZED kontrolerem.
        $this->assertSame(
            0,
            Recipe::query()->where('title', $tytul)->count(),
            'Ekran odzyskiwania ma tylko narysować formularz. Gdyby przy okazji '
            .'zapisywał przepis, limit zapytań przestałby cokolwiek ograniczać.',
        );
    }

    /**
     * Granica: 429 na logowaniu nie oddaje NICZEGO.
     *
     * To nie jest ostrożność na wyrost — limit logowania jest tym limitem,
     * który odbija najczęściej, a formularz, który go wywołał, niesie hasło.
     * Gdyby odzyskiwanie działało po nazwie pola zamiast po nazwie trasy,
     * hasło wróciłoby tu w `value=`.
     */
    public function test_429_na_logowaniu_nie_oddaje_hasla_ani_nie_obiecuje_ze_nic_nie_przepadlo(): void
    {
        $basia = $this->user('basia');

        $haslo = 'Zle-Haslo-Ktore-Nie-Ma-Prawa-Wrocic-1';

        $odpowiedz = null;

        for ($i = 0; $i <= 30; $i++) {
            $odpowiedz = $this->post('/login', [
                'email' => $basia->email,
                'password' => $haslo,
            ]);

            if ($odpowiedz->getStatusCode() === 429) {
                break;
            }
        }

        $odpowiedz->assertStatus(429);
        $odpowiedz->assertDontSee($haslo, escape: false);
        $odpowiedz->assertDontSee('Wyślij jeszcze raz');

        // I — druga połowa naprawy G06 — bez odzyskanego formularza ekran
        // NIE twierdzi, że nic nie przepadło. Nie ma czym tego pokryć.
        $odpowiedz->assertDontSee('nic nie przepadło');
    }

    /**
     * Kontrola metody pomiaru: bez tego wszystkie trzy testy wyżej mogłyby
     * przejść dlatego, że limitów nie da się już wywołać.
     *
     * Pierwsze wysłanie komentarza ma się UDAĆ. Gdyby limiter odbijał od
     * pierwszego żądania, „429 zachowuje tekst" nie mówiłoby nic o życiu.
     */
    public function test_kontrola_pierwsze_wyslanie_przechodzi_bez_limitu(): void
    {
        $basia = $this->user('basia');

        $post = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $this->actingAs($basia)
            ->post(route('posts.comment', $post), ['body' => 'Pierwszy komentarz, bez limitu.'])
            ->assertStatus(302);
    }
}
