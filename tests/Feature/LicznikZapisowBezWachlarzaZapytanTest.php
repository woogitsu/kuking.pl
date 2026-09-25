<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SavePostToCollection;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Licznik zapisów pod wpisem NIE dokłada zapytania na kartę (issue #275, D-081).
 *
 * PO CO OSOBNY PLIK, SKORO JEST `MiniaturyBezWachlarzaZapytanTest`
 * Tamten mierzy to samo dla ZDJĘĆ i nie ma w danych ani jednego zapisu
 * w zeszycie, więc przeszedłby także wtedy, gdyby liczba zapisów chodziła po
 * bazie per wpis: podzapytanie policzyłoby zero i nie zostawiło śladu.
 * Ten plik zapisuje po kilka osób NA KAŻDY wpis, czyli robi dokładnie te dane,
 * w których N+1 byłby widoczny.
 *
 * METODA TA SAMA CO W AUDYCIE N03 (AGENTS.md §3 — pomiar, nie założenie):
 * ta sama strona przy MAŁYM i przy DUŻYM zestawie, wymagana ta sama liczba
 * zapytań. Nie „ile zapytań jest OK" na sztywno, bo taki próg psuje się przy
 * pierwszej uzasadnionej zmianie i uczy ludzi go podnosić.
 *
 * Karta wpisu dostaje TU dwie kolumny naraz — liczbę zapisów i stan „mam to
 * w zeszycie" (`ZapisyWpisu::dolicz()`), więc obie są mierzone jednym
 * przebiegiem. Widz jest zalogowany właśnie dlatego: dla gościa kolumna
 * `czy_zapisany` w ogóle nie powstaje i test nie dotykałby połowy zmiany.
 */
class LicznikZapisowBezWachlarzaZapytanTest extends TestCase
{
    use RefreshDatabase;

    private const ZAPISUJACYCH_NA_WPIS = 3;

    /** Wpisy różnych autorów, każdy odłożony do zeszytu przez kilka osób. */
    private function wpisyZZapisami(int $ileWpisow, string $prefiks): void
    {
        $akcja = app(SavePostToCollection::class);

        for ($i = 0; $i < $ileWpisow; $i++) {
            $autor = $this->user($prefiks.'_autor_'.$i);

            $post = Post::factory()->create([
                'author_id' => $autor->getKey(),
                'visibility' => 'public',
                'published_at' => now()->subMinutes($i),
            ]);

            for ($z = 0; $z < self::ZAPISUJACYCH_NA_WPIS; $z++) {
                $akcja->handle($this->user($prefiks.'_zapis_'.$i.'_'.$z), $post);
            }
        }
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

    public function test_feed_odkrywania_nie_ma_wachlarza_zapytan_na_licznik_zapisow(): void
    {
        $widz = $this->user('widz_wachlarza');

        // MAŁO: dwa wpisy, każdy zapisany przez trzy osoby.
        $this->wpisyZZapisami(2, 'malo');

        $maloZapytan = $this->policzZapytania(
            fn () => $this->actingAs($widz)->get(route('discover'))->assertOk(),
        );

        $this->assertSame(2, Post::query()->count());

        // Sprzątamy, żeby druga próba nie liczyła wpisów z pierwszej.
        DB::table('collection_items')->delete();
        Post::query()->forceDelete();

        // DUŻO: dwadzieścia wpisów, też po trzy zapisy każdy. Gdyby liczba
        // zapisów albo stan „mam to w zeszycie" szły osobnym zapytaniem na
        // wpis, byłoby tu około +40.
        $this->wpisyZZapisami(20, 'duzo');

        $duzoZapytan = $this->policzZapytania(
            fn () => $this->actingAs($widz)->get(route('discover'))->assertOk(),
        );

        $this->assertSame(
            20,
            Post::query()->count(),
            'asercja kontrolna: w bazie musi naprawdę być 20 wpisów, inaczej test nie mierzy niczego',
        );

        $this->assertSame(
            self::ZAPISUJACYCH_NA_WPIS * 20,
            DB::table('collection_items')->whereNotNull('post_id')->count(),
            'asercja kontrolna: każdy wpis musi mieć zapisy, inaczej podzapytanie liczy zero i nie zostawia śladu',
        );

        fwrite(STDERR, sprintf(
            "\n[#275 /odkryj] malo (2 wpisy x 3 zapisy): %d zapytan, duzo (20 wpisow x 3 zapisy): %d zapytan\n",
            $maloZapytan,
            $duzoZapytan,
        ));

        $this->assertSame(
            $maloZapytan,
            $duzoZapytan,
            "Liczba zapytań rośnie z liczbą wpisów (N+1): {$maloZapytan} przy 2 wpisach, {$duzoZapytan} przy 20.",
        );
    }

    public function test_liczba_zapisow_jest_naprawde_widoczna_w_tym_zestawie_danych(): void
    {
        // KONTROLA DODATNIA do testu wyżej. Bez niej „tyle samo zapytań"
        // przechodziłoby także wtedy, gdyby licznik nie doliczał się wcale —
        // zero zapytań ekstra i zero informacji na ekranie.
        $widz = $this->user('widz_kontrolny');
        $this->wpisyZZapisami(1, 'kontrola');

        $html = (string) $this->actingAs($widz)->get(route('discover'))->assertOk()->getContent();

        $this->assertSame(
            1,
            preg_match(
                '/<p[^>]*data-rola="liczba-zapisow"[^>]*>\s*3 osoby zapisały to u siebie w zeszycie\s*<\/p>/su',
                $html,
            ),
            'W tych samych danych, na których mierzymy zapytania, liczba musi być widoczna na karcie.',
        );
    }
}
