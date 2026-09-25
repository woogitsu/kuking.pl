<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Audyt zewnętrzny N03 — podejrzenie wachlarza zapytań (N+1) przy
 * miniaturach: czy wyświetlenie listy z wieloma zdjęciami wykonuje osobne
 * zapytanie na KAŻDE zdjęcie.
 *
 * METODA (AGENTS.md §3 — pomiar, nie założenie)
 * Klasyczny objaw N+1 to liczba zapytań ROSNĄCA z liczbą wierszy. Test więc
 * nie sprawdza "ile zapytań jest OK" na sztywno (co i tak by się zepsuło
 * przy pierwszej uzasadnionej zmianie), tylko porównuje TĘ SAMĄ stronę przy
 * MAŁYM zestawie (2 wpisy) i przy REALISTYCZNYM zestawie z zadania audytu
 * (30 wpisów po 3 zdjęcia) — i wymaga TEJ SAMEJ liczby zapytań. Gdyby
 * `Media` albo `x-photo` chodziły po bazę per zdjęcie, druga liczba byłaby
 * wyraźnie większa (rzędu +90 przy 30×3 zdjęciach).
 *
 * Sprawdzone ekrany, tak jak wskazuje zadanie: feed ("Świeżo z Kuking" —
 * dostępny bez logowania i przez to najbardziej obciążony), strona tagu,
 * profil i zeszyt.
 */
class MiniaturyBezWachlarzaZapytanTest extends TestCase
{
    use RefreshDatabase;

    private const ZDJEC_NA_WPIS = 3;

    private function autorZWpisami(int $ileWpisow, string $username): User
    {
        $autor = $this->user($username);

        for ($i = 0; $i < $ileWpisow; $i++) {
            $post = Post::factory()->for($autor, 'author')->create([
                'published_at' => now()->subMinutes($i),
            ]);

            for ($z = 0; $z < self::ZDJEC_NA_WPIS; $z++) {
                $media = Media::factory()->for($autor, 'owner')->create();
                $post->media()->attach($media->getKey(), ['position' => $z]);
            }
        }

        return $autor;
    }

    private function policzZapytania(callable $akcja): int
    {
        // Każdy pomiar na zimno: kandydaci tablicy „Kuking na dziś” leżą
        // w cache (audyt B4 W1). Bez tego drugi pomiar byłby tańszy o samo
        // liczenie kandydatów, a nie o brak wachlarza zapytań.
        Cache::flush();

        $ile = 0;
        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        $akcja();

        return $ile;
    }

    public function test_feed_swiezo_z_kuking_nie_ma_wachlarza_zapytan_na_zdjecia(): void
    {
        // MAŁO: jeden autor, dwa wpisy po trzy zdjęcia.
        $maloAutor = $this->autorZWpisami(2, 'malo_dyskretnie');
        $maloZapytan = $this->policzZapytania(fn () => $this->get(route('discover'))->assertOk());

        $this->assertSame(2, Post::query()->count());

        // Sprzątamy, żeby druga próba zaczynała z czystego feedu, a nie
        // z dwoma wpisami z pierwszej próby dorzuconymi do trzydziestu.
        Post::query()->delete();
        Media::query()->delete();

        // DUŻO: realistyczne dane z zadania audytu — 30 wpisów po 3 zdjęcia,
        // rozłożone na kilku autorów, tak jak wygląda prawdziwy feed.
        for ($autorId = 0; $autorId < 3; $autorId++) {
            $this->autorZWpisami(10, 'duzo_autor_'.$autorId);
        }

        $duzoZapytan = $this->policzZapytania(fn () => $this->get(route('discover'))->assertOk());

        $this->assertSame(
            30,
            Post::query()->count(),
            'asercja kontrolna: w bazie musi naprawdę być 30 wpisów, inaczej test nie mierzy niczego',
        );

        fwrite(STDERR, sprintf("\n[N03 /odkryj] malo (2 wpisy): %d zapytan, duzo (30 wpisow x 3 zdjecia): %d zapytan\n", $maloZapytan, $duzoZapytan));

        $this->assertSame(
            $maloZapytan,
            $duzoZapytan,
            "Liczba zapytań rośnie z liczbą wpisów/zdjęć (N+1): {$maloZapytan} przy 2 wpisach, {$duzoZapytan} przy 30.",
        );
    }

    public function test_strona_tagu_nie_ma_wachlarza_zapytan_na_zdjecia(): void
    {
        $tag = Tag::factory()->create();

        $maloAutor = $this->autorZWpisami(2, 'tag_malo');
        foreach ($maloAutor->posts as $post) {
            $post->tags()->attach($tag->getKey(), ['position' => 0]);
        }
        $maloZapytan = $this->policzZapytania(fn () => $this->get(route('tags.show', $tag))->assertOk());

        Post::query()->delete();
        Media::query()->delete();

        $duzoAutor = $this->autorZWpisami(30, 'tag_duzo');
        foreach ($duzoAutor->posts as $post) {
            $post->tags()->attach($tag->getKey(), ['position' => 0]);
        }

        $duzoZapytan = $this->policzZapytania(fn () => $this->get(route('tags.show', $tag))->assertOk());

        $this->assertSame(30, Post::query()->count());

        fwrite(STDERR, sprintf("\n[N03 /tag] malo (2 wpisy): %d zapytan, duzo (30 wpisow x 3 zdjecia): %d zapytan\n", $maloZapytan, $duzoZapytan));

        $this->assertSame(
            $maloZapytan,
            $duzoZapytan,
            "Strona tagu: {$maloZapytan} zapytań przy 2 wpisach, {$duzoZapytan} przy 30 — wachlarz na zdjęcia.",
        );
    }

    public function test_profil_nie_ma_wachlarza_zapytan_na_zdjecia(): void
    {
        $maloAutor = $this->autorZWpisami(2, 'profil_malo');
        $maloZapytan = $this->policzZapytania(
            fn () => $this->get(route('profile.show', 'profil_malo'))->assertOk(),
        );

        Post::query()->delete();
        Media::query()->delete();

        $duzoAutor = $this->autorZWpisami(30, 'profil_duzo');
        $duzoZapytan = $this->policzZapytania(
            fn () => $this->get(route('profile.show', 'profil_duzo'))->assertOk(),
        );

        $this->assertSame(30, Post::query()->count());

        fwrite(STDERR, sprintf("\n[N03 /@profil] malo (2 wpisy): %d zapytan, duzo (30 wpisow x 3 zdjecia): %d zapytan\n", $maloZapytan, $duzoZapytan));

        $this->assertSame(
            $maloZapytan,
            $duzoZapytan,
            "Profil: {$maloZapytan} zapytań przy 2 wpisach, {$duzoZapytan} przy 30 — wachlarz na zdjęcia.",
        );
    }

    public function test_zeszyt_nie_ma_wachlarza_zapytan_na_zdjecia(): void
    {
        $widz = $this->user('zeszyt_widz');
        $autor = $this->user('zeszyt_autor');

        $collectionMalo = Collection::create([
            'owner_id' => $widz->getKey(),
            'name' => 'Mało',
            'visibility' => 'private',
        ]);

        for ($i = 0; $i < 2; $i++) {
            $post = Post::factory()->for($autor, 'author')->create(['published_at' => now()->subMinutes($i)]);
            for ($z = 0; $z < self::ZDJEC_NA_WPIS; $z++) {
                $media = Media::factory()->for($autor, 'owner')->create();
                $post->media()->attach($media->getKey(), ['position' => $z]);
            }
            $collectionMalo->posts()->attach($post->getKey(), ['created_at' => now()->subMinutes($i)]);
        }

        $maloZapytan = $this->policzZapytania(
            fn () => $this->actingAs($widz)->get(route('collections.show', $collectionMalo))->assertOk(),
        );

        $collectionDuzo = Collection::create([
            'owner_id' => $widz->getKey(),
            'name' => 'Dużo',
            'visibility' => 'private',
        ]);

        for ($i = 0; $i < 12; $i++) {
            $post = Post::factory()->for($autor, 'author')->create(['published_at' => now()->subMinutes($i)]);
            for ($z = 0; $z < self::ZDJEC_NA_WPIS; $z++) {
                $media = Media::factory()->for($autor, 'owner')->create();
                $post->media()->attach($media->getKey(), ['position' => $z]);
            }
            $collectionDuzo->posts()->attach($post->getKey(), ['created_at' => now()->subMinutes($i)]);
        }

        // 12 to pełna strona po poprawce T20 (config('kuking.collections.saved_posts_page_size')) —
        // to jest realistyczny "duży" ekran PO naprawie wzrostu z T20, nie
        // przypadkowa liczba.
        $duzoZapytan = $this->policzZapytania(
            fn () => $this->actingAs($widz)->get(route('collections.show', $collectionDuzo))->assertOk(),
        );

        fwrite(STDERR, sprintf("\n[N03 /zeszyt] malo (2 wpisy): %d zapytan, duzo (12 wpisow x 3 zdjecia): %d zapytan\n", $maloZapytan, $duzoZapytan));

        $this->assertSame(
            $maloZapytan,
            $duzoZapytan,
            "Zeszyt: {$maloZapytan} zapytań przy 2 wpisach, {$duzoZapytan} przy 12 (pełna strona) — wachlarz na zdjęcia.",
        );
    }
}
