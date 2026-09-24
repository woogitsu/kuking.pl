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
use Illuminate\Support\Facades\Event;
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

    public function test_rosnaca_historia_wykonan_nie_zwieksza_liczby_hydratowanych_zdarzen(): void
    {
        config()->set('kuking.digest.max_pozycji', 3);

        $odbiorca = $this->user('limit_wykonan');
        $przepis = Recipe::factory()->for($odbiorca, 'author')->create();

        $najnowsze = [];
        for ($i = 0; $i < 20; $i++) {
            $zdarzenie = CookedEvent::factory()
                ->for($this->user('limit_kucharz_'.$i), 'user')
                ->for($przepis, 'recipe')
                ->create(['cooked_at' => now()->subMinutes($i)]);
            if ($i < 3) {
                $najnowsze[] = (string) $zdarzenie->getKey();
            }
        }

        $zhydratowane = 0;
        Event::listen('eloquent.retrieved: '.CookedEvent::class, function () use (&$zhydratowane): void {
            $zhydratowane++;
        });

        $tresc = app(ZbierzTresciDigestu::class)->dlaJednej($odbiorca);

        $this->assertCount(3, $tresc->wykonania);
        $this->assertSame(3, $zhydratowane, 'Zbieracz zhydratował wykonania, których list nie pokaże.');
        $this->assertSame(
            $najnowsze,
            array_map(fn (CookedEvent $zdarzenie): string => (string) $zdarzenie->getKey(), $tresc->wykonania),
            'List ma pokazać trzy najnowsze wykonania.',
        );
    }

    public function test_pelna_liczba_obserwujacych_nie_wymaga_hydratacji_pelnej_listy(): void
    {
        config()->set('kuking.digest.max_pozycji', 3);

        $odbiorca = $this->user('limit_obserwujacych');

        $najnowsi = [];
        for ($i = 0; $i < 20; $i++) {
            $nowy = $this->user('limit_obserwujacy_'.$i);
            $nowy->following()->attach($odbiorca->getKey(), ['created_at' => now()->subMinutes($i)]);
            if ($i < 3) {
                $najnowsi[] = (string) $nowy->getKey();
            }
        }

        $zhydratowane = 0;
        Event::listen('eloquent.retrieved: '.User::class, function () use (&$zhydratowane): void {
            $zhydratowane++;
        });

        $tresc = app(ZbierzTresciDigestu::class)->dlaJednej($odbiorca);

        $this->assertCount(3, $tresc->nowiObserwujacy);
        $this->assertSame(20, $tresc->ileNowychObserwujacych);
        $this->assertSame(3, $zhydratowane, 'Pełny licznik obserwujących nie wymaga hydratacji wszystkich osób.');
        $this->assertSame(
            $najnowsi,
            array_map(fn (User $osoba): string => (string) $osoba->getKey(), $tresc->nowiObserwujacy),
        );
    }

    public function test_wspolny_autor_ma_osobny_limit_dla_kazdego_odbiorcy(): void
    {
        config()->set('kuking.digest.max_pozycji', 3);

        $pierwszy = $this->user('limit_wpisow_a');
        $drugi = $this->user('limit_wpisow_b');
        $autor = $this->user('limit_wspolny_autor');
        $pierwszy->following()->attach($autor->getKey(), ['created_at' => now()->subMonth()]);
        $drugi->following()->attach($autor->getKey(), ['created_at' => now()->subMonth()]);

        $najnowsze = [];
        for ($i = 0; $i < 20; $i++) {
            $wpis = Post::factory()->for($autor, 'author')->create(['published_at' => now()->subMinutes($i)]);
            if ($i < 3) {
                $najnowsze[] = (string) $wpis->getKey();
            }
        }

        $zhydratowane = 0;
        Event::listen('eloquent.retrieved: '.Post::class, function () use (&$zhydratowane): void {
            $zhydratowane++;
        });

        $tresci = app(ZbierzTresciDigestu::class)->dla(collect([$pierwszy, $drugi]));

        foreach ([$pierwszy, $drugi] as $odbiorca) {
            $wpisy = $tresci[(string) $odbiorca->getKey()]->wpisyObserwowanych;
            $this->assertSame($najnowsze, array_map(fn (Post $wpis): string => (string) $wpis->getKey(), $wpisy));
        }

        $this->assertSame(6, $zhydratowane, 'Wspólny autor nie może wciągać pełnej historii do pamięci.');
    }

    /**
     * Styk z #1328: `odswiez()` czyta świeżo WYŁĄCZNIE pozycje wybrane przy
     * kolejkowaniu. Limit w SQL nie może ich przyciąć, nawet gdy
     * `max_pozycji` zmalało, zanim zadanie doszło do wysyłki.
     */
    public function test_odswiezenie_przy_wysylce_nie_tnie_wybranych_pozycji_nowym_limitem(): void
    {
        config()->set('kuking.digest.max_pozycji', 3);

        $odbiorca = $this->user('limit_odswiez');
        $przepis = Recipe::factory()->for($odbiorca, 'author')->create();
        $autor = $this->user('limit_odswiez_autor');
        $odbiorca->following()->attach($autor->getKey(), ['created_at' => now()->subMonth()]);

        for ($i = 0; $i < 5; $i++) {
            CookedEvent::factory()
                ->for($this->user('limit_odswiez_kucharz_'.$i), 'user')
                ->for($przepis, 'recipe')
                ->create(['cooked_at' => now()->subMinutes($i)]);
            $this->user('limit_odswiez_obs_'.$i)->following()
                ->attach($odbiorca->getKey(), ['created_at' => now()->subMinutes($i)]);
            Post::factory()->for($autor, 'author')->create(['published_at' => now()->subMinutes($i)]);
        }

        $zbieracz = app(ZbierzTresciDigestu::class);
        $zapis = $zbieracz->dlaJednej($odbiorca);
        $this->assertCount(3, $zapis->wykonania);
        $this->assertCount(3, $zapis->nowiObserwujacy);
        $this->assertCount(3, $zapis->wpisyObserwowanych);

        config()->set('kuking.digest.max_pozycji', 1);
        $swieza = $zbieracz->odswiez($zapis);

        $klucze = fn (array $modele): array => array_map(fn ($m): string => (string) $m->getKey(), $modele);
        $this->assertNotNull($swieza);
        $this->assertSame($klucze($zapis->wykonania), $klucze($swieza->wykonania));
        $this->assertSame($klucze($zapis->nowiObserwujacy), $klucze($swieza->nowiObserwujacy));
        $this->assertSame($klucze($zapis->wpisyObserwowanych), $klucze($swieza->wpisyObserwowanych));
        $this->assertSame(5, $swieza->ileNowychObserwujacych);
    }
}
