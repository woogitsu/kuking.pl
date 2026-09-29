<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Media;
use App\Models\Post;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `GET /` — czy liczba zapytań ROŚNIE z liczbą wpisów, osób i komentarzy (#599).
 *
 * DLACZEGO TEN TEST ISTNIEJE
 * Alarm „Łączny czas zapytań SQL żądania przekroczył próg" (1330 i 1116 ms
 * przy progu 1000 ms) padł na `GET /` po wdrożeniu Alfa 0.77.001. Strona
 * główna jest pierwszym ekranem gościa i zalogowanego, a monitor sumuje czas
 * WSZYSTKICH zapytań żądania — N+1 na tym ekranie byłby najprostszym
 * wyjaśnieniem takiego alarmu. Pomiar (docs/infra/ODKRYJ_KOSZT_1952.md,
 * scripts/dane-obciazenia-605.php): na `/` liczba zapytań jest STAŁA
 * (gość 31, zalogowany 52 od 88 do 6000 wpisów). Ten test zamraża tę
 * własność, żeby nikt jej nie zepsuł nieświadomie — koszt zapytań może
 * rosnąć z danymi (to osobny temat, #1952), liczba zapytań nie.
 *
 * KAŻDY POMIAR PO ROZGRZEWCE. `DailyBoard` trzyma kandydatów w cache (300 s),
 * więc pierwsze żądanie po czyszczeniu cache ma inną liczbę zapytań niż
 * kolejne. Porównujemy ciepłe z ciepłym, z czystym cache przed każdą rozgrzewką.
 */
class StronaGlownaBezWachlarzaZapytanTest extends TestCase
{
    use RefreshDatabase;

    private const MALO = 2;

    private const DUZO = 24;

    private function policzZapytania(callable $akcja): int
    {
        $ile = 0;
        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        $akcja();

        return $ile;
    }

    /** Ciepłe żądanie: cache czyszczony, jedno żądanie rozgrzewające, potem pomiar. */
    private function zmierzCieple(?User $widz): int
    {
        Cache::flush();
        $this->zadanie($widz)->assertOk();

        return $this->policzZapytania(fn () => $this->zadanie($widz)->assertOk());
    }

    private function zadanie(?User $widz): TestResponse
    {
        return $widz === null ? $this->get(route('landing')) : $this->actingAs($widz)->get(route('landing'));
    }

    /**
     * @return list<User> autorzy dodanych wpisów
     */
    private function dosyp(int $ile, int $od = 0): array
    {
        $autorzy = [];
        for ($i = $od; $i < $od + $ile; $i++) {
            $autor = $this->user(null, ['display_name' => 'Autorka numer '.$i]);
            Profile::query()->where('user_id', $autor->getKey())->update([
                'avatar_media_id' => Media::factory()->create(['owner_id' => $autor->getKey()])->getKey(),
            ]);
            $wpis = Post::factory()->for($autor, 'author')->create(['body' => 'Wpis numer '.$i]);
            $wpis->media()->attach(
                Media::factory()->create(['owner_id' => $autor->getKey()])->getKey(),
                ['position' => 0],
            );
            Comment::factory()->create([
                'post_id' => $wpis->getKey(),
                'author_id' => $this->user()->getKey(),
                'body' => 'Komentarz numer '.$i,
            ]);
            $autorzy[] = $autor;
        }

        return $autorzy;
    }

    public function test_strona_glowna_goscia_nie_ma_wachlarza_zapytan_na_wpisy(): void
    {
        $this->dosyp(self::MALO);
        $malo = $this->zmierzCieple(null);

        $this->dosyp(self::DUZO - self::MALO, od: self::MALO);

        // KONTROLA DODATNIA (docs/PULAPKI_TESTOW.md §4): płaska liczba zapytań
        // przechodzi także wtedy, gdy strona nie pokazuje wpisów — wtedy
        // mierzymy pustą stronę.
        Cache::flush();
        $html = $this->zadanie(null)->assertOk()->getContent();
        $this->assertStringContainsString('Wpis numer '.(self::DUZO - 1), $html, 'Strona główna nie pokazuje wpisów — pomiar nie mierzy feedu.');

        $duzo = $this->zmierzCieple(null);

        $this->assertSame($malo, $duzo, $this->komunikat('/ (gość)', $malo, $duzo));
    }

    public function test_strona_glowna_zalogowanego_nie_ma_wachlarza_zapytan_na_wpisy_i_obserwowanych(): void
    {
        $widz = $this->user(null, ['display_name' => 'Widz']);

        $widz->following()->attach(
            collect($this->dosyp(self::MALO))->pluck('id')->all(),
            ['created_at' => now()],
        );
        $malo = $this->zmierzCieple($widz);

        $widz->following()->attach(
            collect($this->dosyp(self::DUZO - self::MALO, od: self::MALO))->pluck('id')->all(),
            ['created_at' => now()],
        );

        Cache::flush();
        $html = $this->zadanie($widz)->assertOk()->getContent();
        $this->assertStringContainsString('Wpis numer '.(self::DUZO - 1), $html, 'Strona główna zalogowanego nie pokazuje wpisów obserwowanych — pomiar nie mierzy feedu.');

        $duzo = $this->zmierzCieple($widz);

        $this->assertSame($malo, $duzo, $this->komunikat('/ (zalogowany)', $malo, $duzo));
    }

    private function komunikat(string $ekran, int $malo, int $duzo): string
    {
        return "Liczba zapytań rośnie z liczbą wpisów (N+1) na {$ekran}: {$malo} przy "
            .self::MALO." wpisach, {$duzo} przy ".self::DUZO.'.';
    }
}
