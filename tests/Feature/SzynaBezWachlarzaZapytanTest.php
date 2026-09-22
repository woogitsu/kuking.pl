<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prawa szyna nie kosztuje wachlarza zapytań (issue #205).
 *
 * PO CO TO JEST
 * Szyna stoi od tej zmiany na siedmiu ekranach naraz. Blok, który wykonuje
 * jedno zapytanie na każdy wypisany element, jest na jednym ekranie
 * niezauważalny, a na siedmiu staje się kosztem stałym całego serwisu.
 *
 * METODA — ta sama, co w `MiniaturyBezWachlarzaZapytanTest` (audyt N03):
 * nie sprawdzamy „ile zapytań jest OK" (taka liczba psuje się przy pierwszej
 * uzasadnionej zmianie), tylko porównujemy TĘ SAMĄ stronę przy małym
 * i przy dużym zestawie danych. Objawem N+1 jest liczba ROSNĄCA z liczbą
 * wierszy, więc wymagamy liczby IDENTYCZNEJ.
 */
class SzynaBezWachlarzaZapytanTest extends TestCase
{
    use RefreshDatabase;

    private function policzZapytania(callable $akcja): int
    {
        $ile = 0;
        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        $akcja();

        return $ile;
    }

    private function zeszyt(User $wlasciciel, string $nazwa, string $widocznosc = 'private'): Collection
    {
        return Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => $nazwa,
            'visibility' => $widocznosc,
        ]);
    }

    /**
     * Cudzy profil: szyna liczy tagi z wpisów i publiczne zeszyty.
     *
     * Rośnie WSZYSTKO, co szyna czyta: liczba tagów, liczba wpisów pod nimi
     * i liczba publicznych zeszytów. Gdyby którakolwiek z tych list szła po
     * bazę per element, druga liczba byłaby o kilkadziesiąt większa.
     */
    public function test_szyna_profilu_nie_rosnie_z_liczba_tagow_i_zeszytow(): void
    {
        $widz = $this->user('widz_wydajnosc');

        $malo = $this->user('profil_szyna_malo');
        $this->wyposazProfil($malo, tagow: 2, zeszytow: 2);

        $maloZapytan = $this->policzZapytania(
            fn () => $this->actingAs($widz)->get(route('profile.show', 'profil_szyna_malo'))->assertOk(),
        );

        $duzo = $this->user('profil_szyna_duzo');
        $this->wyposazProfil($duzo, tagow: 30, zeszytow: 20);

        $duzoZapytan = $this->policzZapytania(
            fn () => $this->actingAs($widz)->get(route('profile.show', 'profil_szyna_duzo'))->assertOk(),
        );

        fwrite(STDERR, sprintf(
            "\n[#205 /@profil] malo (2 tagi, 2 zeszyty): %d zapytan, duzo (30 tagow, 20 zeszytow): %d zapytan\n",
            $maloZapytan,
            $duzoZapytan,
        ));

        $this->assertSame(
            $maloZapytan,
            $duzoZapytan,
            "Szyna profilu: {$maloZapytan} zapytań przy 2 tagach i 2 zeszytach, {$duzoZapytan} przy 30 i 20.",
        );
    }

    private function wyposazProfil(User $autor, int $tagow, int $zeszytow): void
    {
        for ($i = 0; $i < $tagow; $i++) {
            $tag = Tag::factory()->create();
            $wpis = Post::factory()->for($autor, 'author')->create([
                'published_at' => now()->subMinutes($i),
            ]);
            $media = Media::factory()->for($autor, 'owner')->create();
            $wpis->media()->attach($media->getKey(), ['position' => 0]);
            $wpis->tags()->attach($tag->getKey(), ['position' => 0]);
        }

        for ($i = 0; $i < $zeszytow; $i++) {
            $this->zeszyt($autor, 'Zeszyt numer '.$i, 'public');
        }
    }

    /**
     * „Moje" (`/zeszyt`): szyna wypisuje pięć rzeczy odłożonych ostatnio.
     *
     * Rośnie liczba zeszytów I liczba rzeczy w nich — czyli dokładnie to,
     * co rośnie u człowieka korzystającego z serwisu przez rok.
     */
    public function test_ostatnie_zapisy_nie_rosna_z_zawartoscia_zeszytow(): void
    {
        $malo = $this->user('moje_szyna_malo');
        $this->wyposazZeszyty($malo, zeszytow: 1, rzeczyNaZeszyt: 2);

        $maloZapytan = $this->policzZapytania(
            fn () => $this->actingAs($malo)->get(route('collections.index'))->assertOk(),
        );

        $duzo = $this->user('moje_szyna_duzo');
        $this->wyposazZeszyty($duzo, zeszytow: 6, rzeczyNaZeszyt: 5);

        $duzoZapytan = $this->policzZapytania(
            fn () => $this->actingAs($duzo)->get(route('collections.index'))->assertOk(),
        );

        fwrite(STDERR, sprintf(
            "\n[#205 /zeszyt] malo (1 zeszyt x 2 rzeczy): %d zapytan, duzo (6 zeszytow x 5 rzeczy): %d zapytan\n",
            $maloZapytan,
            $duzoZapytan,
        ));

        $this->assertSame(
            $maloZapytan,
            $duzoZapytan,
            "Szyna „Moje”: {$maloZapytan} zapytań przy 2 odłożonych rzeczach, {$duzoZapytan} przy 30.",
        );
    }

    /** Pojedynczy zeszyt: szyna wypisuje pozostałe zeszyty tej osoby. */
    public function test_szyna_pojedynczego_zeszytu_nie_rosnie_z_liczba_zeszytow(): void
    {
        $malo = $this->user('jeden_zeszyt_malo');
        $this->wyposazZeszyty($malo, zeszytow: 2, rzeczyNaZeszyt: 2);
        $otwartyMalo = $malo->collections()->orderBy('name')->first();

        $maloZapytan = $this->policzZapytania(
            fn () => $this->actingAs($malo)->get(route('collections.show', $otwartyMalo))->assertOk(),
        );

        $duzo = $this->user('jeden_zeszyt_duzo');
        $this->wyposazZeszyty($duzo, zeszytow: 20, rzeczyNaZeszyt: 2);
        $otwartyDuzo = $duzo->collections()->orderBy('name')->first();

        $duzoZapytan = $this->policzZapytania(
            fn () => $this->actingAs($duzo)->get(route('collections.show', $otwartyDuzo))->assertOk(),
        );

        fwrite(STDERR, sprintf(
            "\n[#205 /zeszyt/{id}] malo (2 zeszyty): %d zapytan, duzo (20 zeszytow): %d zapytan\n",
            $maloZapytan,
            $duzoZapytan,
        ));

        $this->assertSame(
            $maloZapytan,
            $duzoZapytan,
            "Szyna zeszytu: {$maloZapytan} zapytań przy 2 zeszytach, {$duzoZapytan} przy 20.",
        );
    }

    private function wyposazZeszyty(User $wlasciciel, int $zeszytow, int $rzeczyNaZeszyt): void
    {
        $autor = $this->user(null);

        for ($z = 0; $z < $zeszytow; $z++) {
            $zeszyt = $this->zeszyt($wlasciciel, 'Zeszyt '.$z);

            for ($i = 0; $i < $rzeczyNaZeszyt; $i++) {
                $przepis = Recipe::factory()->for($autor, 'author')->create();
                $zeszyt->recipes()->attach($przepis->getKey(), ['created_at' => now()->subMinutes($i)]);

                $wpis = Post::factory()->for($autor, 'author')->create([
                    'published_at' => now()->subMinutes($i),
                ]);
                $media = Media::factory()->for($autor, 'owner')->create();
                $wpis->media()->attach($media->getKey(), ['position' => 0]);
                $zeszyt->posts()->attach($wpis->getKey(), ['created_at' => now()->subMinutes($i)]);
            }
        }
    }
}
