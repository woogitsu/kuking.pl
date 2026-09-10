<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Digest\ZbierzTresciDigestu;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Wysyłka podsumowań nie robi wachlarza zapytań (N+1) na odbiorcach.
 *
 * METODA — TA SAMA CO W `MiniaturyBezWachlarzaZapytanTest` (audyt N03)
 * Nie sprawdzamy „ile zapytań jest OK" na sztywno, bo taka liczba psuje się
 * przy pierwszej uzasadnionej zmianie i uczy ludzi podbijania progu zamiast
 * czytania kodu. Sprawdzamy KSZTAŁT: ten sam kod przy MAŁYM zestawie
 * (5 odbiorców) i przy REALISTYCZNYM (50 odbiorców) musi wykonać TĘ SAMĄ
 * liczbę zapytań. Gdyby zbieracz chodził po bazę per odbiorca, druga liczba
 * byłaby wyraźnie większa — rzędu +135 przy trzech zapytaniach na osobę.
 *
 * DLACZEGO AKURAT 50, A NIE 120 (czyli tyle, ile wynosi dzienny limit)
 * Bo test ma pokazać KIERUNEK, a nie zmierzyć produkcję: różnica między
 * 5 a 50 wykrywa wachlarz tak samo pewnie, a kosztuje kilka sekund zamiast
 * kilkunastu. Liczba jest wprost z zadania.
 *
 * DLACZEGO TO W OGÓLE MA ZNACZENIE PRZY ZADANIU W TLE
 * Bo w tym repozytorium harmonogram NIE chodzi w osobnym procesie:
 * `routes/console.php` używa `Schedule::call()`, bo `proc_open` jest
 * wyłączone w `docker/php.ini` (hardening, AGENTS.md §7 zabrania go
 * osłabiać). Zadanie wykonuje się więc w TYM SAMYM procesie PHP co serwer
 * WWW i przy jednej replice każde nadmiarowe zapytanie opóźnia obsługę
 * zwykłych żądań.
 */
class PodsumowanieBezWachlarzaZapytanTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Odbiorca z pełnym kompletem treści: cudze wykonanie jego przepisu,
     * nowy obserwujący i obserwowany autor, który coś pokazał. Chodzi o to,
     * żeby zbieracz musiał ruszyć KAŻDĄ ze swoich trzech sekcji — test na
     * pustych kontach nie zmierzyłby niczego.
     *
     * @return list<User>
     */
    private function odbiorcy(int $ile, string $prefiks): array
    {
        $odbiorcy = [];

        for ($i = 0; $i < $ile; $i++) {
            $odbiorca = $this->user($prefiks.'_odb_'.$i);

            $przepis = Recipe::factory()->for($odbiorca, 'author')->create();
            $kucharz = $this->user($prefiks.'_kuch_'.$i);
            CookedEvent::factory()->for($kucharz, 'user')->for($przepis, 'recipe')->create([
                'cooked_at' => now()->subDay(),
            ]);

            $nowy = $this->user($prefiks.'_obs_'.$i);
            $nowy->following()->attach($odbiorca->getKey(), ['created_at' => now()->subDay()]);

            $gotujacy = $this->user($prefiks.'_gotuje_'.$i);
            $odbiorca->following()->attach($gotujacy->getKey(), ['created_at' => now()->subMonth()]);
            Post::factory()->for($gotujacy, 'author')->create(['published_at' => now()->subDay()]);

            $odbiorcy[] = $odbiorca;
        }

        return $odbiorcy;
    }

    private function policzZapytania(callable $akcja): int
    {
        $ile = 0;
        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        $akcja();

        return $ile;
    }

    public function test_liczba_zapytan_nie_rosnie_z_liczba_odbiorcow(): void
    {
        $zbieracz = app(ZbierzTresciDigestu::class);

        $malo = collect($this->odbiorcy(5, 'malo'));
        $maloZapytan = $this->policzZapytania(function () use ($zbieracz, $malo): void {
            $tresci = $zbieracz->dla($malo);
            $this->assertCount(5, $tresci);
        });

        $duzo = collect($this->odbiorcy(50, 'duzo'));
        $duzoZapytan = $this->policzZapytania(function () use ($zbieracz, $duzo): void {
            $tresci = $zbieracz->dla($duzo);

            // Asercja kontrolna: test musi naprawdę zbudować pięćdziesiąt
            // niepustych listów, a nie pięćdziesiąt pustych obiektów.
            $this->assertCount(50, $tresci);

            foreach ($tresci as $tresc) {
                $this->assertFalse($tresc->jestPusty());
            }
        });

        fwrite(STDERR, sprintf(
            "\n[digest] malo (5 odbiorcow): %d zapytan, duzo (50 odbiorcow): %d zapytan\n",
            $maloZapytan,
            $duzoZapytan,
        ));

        $this->assertSame(
            $maloZapytan,
            $duzoZapytan,
            "Liczba zapytań rośnie z liczbą odbiorców (N+1): {$maloZapytan} przy 5 osobach, "
            ."{$duzoZapytan} przy 50. Zbieracz musi pytać bazę RAZ na paczkę, nie raz na osobę.",
        );
    }
}
