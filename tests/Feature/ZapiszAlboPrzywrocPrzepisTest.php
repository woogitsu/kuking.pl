<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Domain\Collections\PowrotPoWyjeciu;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Collection;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Orkiestracja „Zapisuję” przy przepisie żyje w akcji, nie w kontrolerze
 * (issue #970, krok 6): droga powrotu po wyjęciu ALBO zwykły zapis.
 *
 * Akcja nie zna sesji ani żądania — dostaje zwykłą wartość zapisu wyjęcia
 * i domknięcie „zużyj”. Zachowanie przez HTTP pilnują testy Feature zeszytów
 * (m.in. `PowrotDoZeszytuSwiezyStanTest`); tu pilnujemy granicy akcji.
 */
class ZapiszAlboPrzywrocPrzepisTest extends TestCase
{
    use RefreshDatabase;

    private function akcja(): SaveRecipeToCollection
    {
        return app(SaveRecipeToCollection::class);
    }

    /** @return array{0: User, 1: Recipe, 2: Collection, 3: Collection, 4: array<string, mixed>} */
    private function poWyjeciuZDwochZeszytow(): array
    {
        $osoba = $this->user('wracajaca');
        $przepis = Recipe::factory()->create();
        $a = Collection::create(['owner_id' => $osoba->id, 'name' => 'Aaa', 'visibility' => 'private']);
        $b = Collection::create(['owner_id' => $osoba->id, 'name' => 'Bbb', 'visibility' => 'private']);

        $this->akcja()->handle($osoba, $przepis, $a, 'mniej soli');
        $this->akcja()->handle($osoba, $przepis, $b);

        $zdjete = $this->akcja()->remove($osoba, $przepis);
        $this->assertCount(2, $zdjete);

        return [$osoba, $przepis, $a, $b, [
            'typ' => PowrotPoWyjeciu::TYP_PRZEPIS,
            'id' => (string) $przepis->getKey(),
            'pozycje' => $zdjete,
        ]];
    }

    public function test_bez_wyjecia_to_zwykly_zapis_i_wyjecie_nie_jest_zuzywane(): void
    {
        $osoba = $this->user('zapisujaca');
        $przepis = Recipe::factory()->create();
        $zuzyte = 0;

        $wynik = $this->akcja()->zapiszAlboPrzywroc($osoba, $przepis, null, false, null, function () use (&$zuzyte): void {
            $zuzyte++;
        });

        $this->assertNull($wynik->zdaniePowrotu);
        $this->assertNotNull($wynik->zeszyt);
        $this->assertSame(0, $zuzyte);
        $this->assertSame(1, DB::table('collection_items')->where('recipe_id', $przepis->getKey())->count());
    }

    public function test_powrot_przywraca_oba_zeszyty_z_notatka_i_zuzywa_wyjecie_raz(): void
    {
        [$osoba, $przepis, $a, $b, $wyjecie] = $this->poWyjeciuZDwochZeszytow();
        $zuzyte = 0;

        $wynik = $this->akcja()->zapiszAlboPrzywroc($osoba, $przepis, null, false, $wyjecie, function () use (&$zuzyte): void {
            $zuzyte++;
        });

        $this->assertNull($wynik->zeszyt);
        $this->assertSame('Przepis wrócił do wszystkich 2 zeszytów razem z notatkami.', $wynik->zdaniePowrotu);
        $this->assertSame(1, $zuzyte);
        $this->assertSame('mniej soli', $a->recipes()->first()->pivot->note);
        $this->assertSame(1, $b->recipes()->count());
    }

    public function test_powrot_z_jednego_zeszytu_mowi_w_liczbie_pojedynczej(): void
    {
        $osoba = $this->user('jedna');
        $przepis = Recipe::factory()->create();
        $a = Collection::create(['owner_id' => $osoba->id, 'name' => 'Jedyny', 'visibility' => 'private']);
        $this->akcja()->handle($osoba, $przepis, $a);
        $wyjecie = [
            'typ' => PowrotPoWyjeciu::TYP_PRZEPIS,
            'id' => (string) $przepis->getKey(),
            'pozycje' => $this->akcja()->remove($osoba, $przepis),
        ];

        $wynik = $this->akcja()->zapiszAlboPrzywroc($osoba, $przepis, null, false, $wyjecie, static function (): void {});

        $this->assertSame('Przepis wrócił do zeszytu razem z notatką.', $wynik->zdaniePowrotu);
    }

    public function test_podana_notatka_albo_wybrany_zeszyt_to_zwykly_zapis_bez_powrotu(): void
    {
        [$osoba, $przepis, $a, , $wyjecie] = $this->poWyjeciuZDwochZeszytow();
        $zuzyte = 0;
        $zuzyj = function () use (&$zuzyte): void {
            $zuzyte++;
        };

        $zNotatka = $this->akcja()->zapiszAlboPrzywroc($osoba, $przepis, null, true, $wyjecie, $zuzyj);
        $this->assertNull($zNotatka->zdaniePowrotu);
        $this->assertNotNull($zNotatka->zeszyt);

        $wybrany = $this->akcja()->zapiszAlboPrzywroc($osoba, $przepis, $a, false, $wyjecie, $zuzyj);
        $this->assertNull($wybrany->zdaniePowrotu);
        $this->assertTrue($wybrany->zeszyt->is($a));

        $this->assertSame(0, $zuzyte);
    }

    public function test_wyjecie_innego_przepisu_nie_jest_droga_powrotu(): void
    {
        [$osoba, , , , $wyjecie] = $this->poWyjeciuZDwochZeszytow();
        $inny = Recipe::factory()->create();
        $zuzyte = 0;

        $wynik = $this->akcja()->zapiszAlboPrzywroc($osoba, $inny, null, false, $wyjecie, function () use (&$zuzyte): void {
            $zuzyte++;
        });

        $this->assertNull($wynik->zdaniePowrotu);
        $this->assertSame(0, $zuzyte);
        $this->assertSame(1, DB::table('collection_items')->where('recipe_id', $inny->getKey())->count());
        $this->assertSame(0, DB::table('collection_items')->where('recipe_id', $wyjecie['id'])->count());
    }

    public function test_gdy_zaden_zeszyt_nie_wrocil_wyjecie_jest_zuzyte_i_idzie_zwykly_zapis(): void
    {
        [$osoba, $przepis, $a, $b, $wyjecie] = $this->poWyjeciuZDwochZeszytow();
        $a->delete();
        $b->delete();
        $zuzyte = 0;

        $wynik = $this->akcja()->zapiszAlboPrzywroc($osoba, $przepis, null, false, $wyjecie, function () use (&$zuzyte): void {
            $zuzyte++;
        });

        $this->assertNull($wynik->zdaniePowrotu);
        $this->assertNotNull($wynik->zeszyt);
        $this->assertSame(1, $zuzyte);
    }

    public function test_blad_dla_czlowieka_wychodzi_do_wywolujacego_bez_zapisu(): void
    {
        $osoba = $this->user('bez_dostepu');
        $wlasciciel = $this->user('wlasciciel');
        $prywatny = Recipe::factory()->create(['author_id' => $wlasciciel->getKey(), 'visibility' => 'private']);

        try {
            $this->akcja()->zapiszAlboPrzywroc($osoba, $prywatny, null, false, null, static function (): void {});
            $this->fail('Zapis cudzego przepisu prywatnego powinien zostać odrzucony.');
        } catch (BladDlaCzlowieka) {
            $this->assertSame(0, DB::table('collection_items')->count());
        }
    }

    public function test_zapis_wyjecia_daje_pozycje_tylko_dla_wlasciwego_typu_i_id(): void
    {
        $wyjecie = ['typ' => 'przepis', 'id' => 'X', 'pozycje' => [['collection_id' => 'c']]];

        $this->assertSame([['collection_id' => 'c']], PowrotPoWyjeciu::pozycje($wyjecie, 'przepis', 'X'));
        $this->assertNull(PowrotPoWyjeciu::pozycje($wyjecie, 'wpis', 'X'));
        $this->assertNull(PowrotPoWyjeciu::pozycje($wyjecie, 'przepis', 'Y'));
        $this->assertNull(PowrotPoWyjeciu::pozycje(['typ' => 'przepis', 'id' => 'X', 'pozycje' => []], 'przepis', 'X'));
        $this->assertNull(PowrotPoWyjeciu::pozycje('śmieci', 'przepis', 'X'));
        $this->assertNull(PowrotPoWyjeciu::pozycje(null, 'przepis', 'X'));
    }
}
