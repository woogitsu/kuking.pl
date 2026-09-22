<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Trzy wachlarze zapytań (N+1) zmierzone `scripts/pomiar-n1.php` na zbiorze
 * o wielkości z #369 (10 000 wpisów, 30 000 zdjęć, 1000 autorów) — i strażnik,
 * żeby nie wróciły niezauważone.
 *
 * DLACZEGO TEN TEST NIE SPRAWDZA „ILE ZAPYTAŃ JEST OK"
 * Liczba bezwzględna zepsuje się przy pierwszej uzasadnionej zmianie widoku
 * i wtedy ktoś ją po prostu podniesie, nie patrząc na przyczynę. Objawem N+1
 * jest WZROST liczby zapytań z liczbą WIERSZY, więc test renderuje tę samą
 * stronę dwa razy — z kilkoma wierszami i z pełną stroną — i wymaga TEJ SAMEJ
 * liczby zapytań. Ten sam wzorzec co `MiniaturyBezWachlarzaZapytanTest`.
 *
 * DLACZEGO OSIĄ JEST LICZBA WIERSZY NA STRONIE, A NIE WIELKOŚĆ BAZY
 * Na ekranie STRONICOWANYM powiększenie bazy z 1 000 do 10 000 wpisów niczego
 * nie wykryje: paginacja i tak odda jedną stronę. Zmierzone — liczby zapytań
 * na wszystkich sześciu ścieżkach były identyczne przy obu wielkościach zbioru.
 * Rosły dopiero z liczbą wierszy WYRENDEROWANYCH i to jest ta oś.
 *
 * CO BYŁO ZMIERZONE PRZED POPRAWKĄ (zbiór 10 000 wpisów, po `ANALYZE`):
 *   - `/tag/{slug}`                 17 / 23 / 28 zapytań przy 5 / 15 / 25 wpisach
 *   - `/przepisy/{slug}`            40 / 60 / 80 zapytań przy 5 / 15 / 25 komentarzach
 *   - `/@{username}?zakladka=ugotowane`  52 zapytania na 12 kartach (24 z nich to wachlarz)
 */
class StronyBezWachlarzaZapytanTest extends TestCase
{
    use RefreshDatabase;

    private function policzZapytania(callable $akcja): int
    {
        $ile = 0;
        $liczy = true;

        DB::listen(function () use (&$ile, &$liczy): void {
            if ($liczy) {
                $ile++;
            }
        });

        $akcja();
        $liczy = false;

        return $ile;
    }

    /**
     * Karta wpisu (`components/post-card.blade.php`) czyta z przepisu
     * widoczność, tytuł i zdjęcie główne. `TagController::show()` przepisu
     * nie dociągał, więc każdy wpis WSKAZUJĄCY PRZEPIS szedł po niego osobno.
     */
    public function test_strona_tagu_nie_doklada_zapytania_na_kazdy_wpis_z_przepisem(): void
    {
        $tag = Tag::factory()->create();
        $autor = $this->user('tag_autor');

        $wpisyZPrzepisem = function (int $ile) use ($tag, $autor): void {
            for ($i = 0; $i < $ile; $i++) {
                $przepis = Recipe::factory()->for($autor, 'author')->create([
                    'hero_media_id' => Media::factory()->for($autor, 'owner')->create()->getKey(),
                ]);
                $post = Post::factory()->for($autor, 'author')->create([
                    'recipe_id' => $przepis->getKey(),
                    'published_at' => now()->subMinutes($i),
                ]);
                $post->tags()->attach($tag->getKey(), ['position' => 0]);
            }
        };

        $wpisyZPrzepisem(2);
        $malo = $this->policzZapytania(fn () => $this->get(route('tags.show', $tag))->assertOk());

        // Sprzątamy, żeby druga próba renderowała PIERWSZĄ stronę złożoną
        // wyłącznie z nowych wpisów, a nie mieszankę obu prób.
        Post::query()->forceDelete();
        Recipe::query()->forceDelete();

        $wpisyZPrzepisem(12);
        $duzo = $this->policzZapytania(fn () => $this->get(route('tags.show', $tag))->assertOk());

        $this->assertSame(12, Post::query()->count(), 'asercja kontrolna: druga próba musi mieć 12 wpisów');

        $this->assertSame(
            $malo,
            $duzo,
            "Strona tagu dokłada zapytanie na każdy wpis wskazujący przepis (N+1): {$malo} przy 2 wpisach, {$duzo} przy 12.",
        );
    }

    /**
     * Pod każdym komentarzem widok pyta `@can('delete', $comment)`,
     * a `CommentPolicy::delete()` schodzi przez `Comment::notifiableUserId()`
     * do `Comment::subject()`, czyli do relacji `recipe`. Niedoładowana
     * relacja to jedno zapytanie na KAŻDY komentarz — po ten sam przepis,
     * który stoi już wczytany obok.
     */
    public function test_strona_przepisu_nie_doklada_zapytania_na_kazdy_komentarz(): void
    {
        $autor = $this->user('przepis_autor');
        $przepis = Recipe::factory()->for($autor, 'author')->create();
        $czytelnik = $this->user('przepis_czytelnik');

        $komentarze = function (int $ile) use ($przepis, $autor): void {
            for ($i = 0; $i < $ile; $i++) {
                Comment::factory()->for($autor, 'author')->create([
                    'post_id' => null,
                    'recipe_id' => $przepis->getKey(),
                ]);
            }
        };

        $komentarze(2);
        $malo = $this->policzZapytania(
            fn () => $this->actingAs($czytelnik)->get(route('recipes.show', $przepis))->assertOk(),
        );

        $komentarze(10);
        $duzo = $this->policzZapytania(
            fn () => $this->actingAs($czytelnik)->get(route('recipes.show', $przepis))->assertOk(),
        );

        $this->assertSame(12, Comment::query()->count(), 'asercja kontrolna: druga próba musi mieć 12 komentarzy');

        $this->assertSame(
            $malo,
            $duzo,
            "Strona przepisu dokłada zapytanie na każdy komentarz (N+1): {$malo} przy 2, {$duzo} przy 12.",
        );
    }

    /**
     * Karta wykonania (`components/cooked-card.blade.php`) czyta OSOBĘ, KTÓRA
     * GOTOWAŁA — awatar, nazwę i `profile->username` na odnośnik. Zakładka
     * „ugotowane" dociągała tylko autora przepisu, więc na każdą kartę szła
     * para zapytań `users` + `profiles`.
     */
    public function test_zakladka_ugotowane_nie_doklada_zapytan_na_kazde_wykonanie(): void
    {
        $gospodarz = $this->user('kucharz_gospodarz');
        $widz = $this->user('kucharz_widz');

        $wykonania = function (int $ile) use ($gospodarz): void {
            for ($i = 0; $i < $ile; $i++) {
                $autorPrzepisu = User::factory()->create();
                $przepis = Recipe::factory()->for($autorPrzepisu, 'author')->create();
                CookedEvent::factory()->create([
                    'user_id' => $gospodarz->getKey(),
                    'recipe_id' => $przepis->getKey(),
                    'cooked_at' => now()->subMinutes($i),
                ]);
            }
        };

        $adres = route('profile.show', $gospodarz->profile->username).'?zakladka=ugotowane';

        $wykonania(2);
        $malo = $this->policzZapytania(fn () => $this->actingAs($widz)->get($adres)->assertOk());

        $wykonania(10);
        $duzo = $this->policzZapytania(fn () => $this->actingAs($widz)->get($adres)->assertOk());

        $this->assertSame(12, CookedEvent::query()->count(), 'asercja kontrolna: druga próba musi mieć 12 wykonań');

        $this->assertSame(
            $malo,
            $duzo,
            "Zakładka ugotowane dokłada zapytania na każde wykonanie (N+1): {$malo} przy 2, {$duzo} przy 12.",
        );
    }
}
