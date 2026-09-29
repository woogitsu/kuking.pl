<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Collections\PowrotPoWyjeciu;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Collection;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Orkiestracja „Zapisuję” przy wpisie żyje w akcji, nie w kontrolerze
 * (issue #970, krok 7): droga powrotu po wyjęciu ALBO zwykły zapis.
 *
 * Akcja nie zna sesji ani żądania — dostaje zwykłą wartość zapisu wyjęcia
 * i domknięcie „zużyj”. Zachowanie przez HTTP pilnują testy Feature zeszytów
 * (m.in. `PowrotDoZeszytuSwiezyStanTest`); tu pilnujemy granicy akcji.
 */
class ZapiszAlboPrzywrocWpisTest extends TestCase
{
    use RefreshDatabase;

    private function akcja(): SavePostToCollection
    {
        return app(SavePostToCollection::class);
    }

    /** @return array{0: User, 1: Post, 2: Collection, 3: Collection, 4: array<string, mixed>} */
    private function poWyjeciuZDwochZeszytow(): array
    {
        $osoba = $this->user('wracajaca');
        $wpis = Post::factory()->create();
        $a = Collection::create(['owner_id' => $osoba->id, 'name' => 'Aaa', 'visibility' => 'private']);
        $b = Collection::create(['owner_id' => $osoba->id, 'name' => 'Bbb', 'visibility' => 'private']);

        $this->akcja()->handle($osoba, $wpis, $a, 'mniej soli');
        $this->akcja()->handle($osoba, $wpis, $b);

        $zdjete = $this->akcja()->remove($osoba, $wpis);
        $this->assertCount(2, $zdjete);

        return [$osoba, $wpis, $a, $b, [
            'typ' => PowrotPoWyjeciu::TYP_WPIS,
            'id' => (string) $wpis->getKey(),
            'pozycje' => $zdjete,
        ]];
    }

    public function test_bez_wyjecia_to_zwykly_zapis_i_wyjecie_nie_jest_zuzywane(): void
    {
        $osoba = $this->user('zapisujaca');
        $wpis = Post::factory()->create();
        $zuzyte = 0;

        $wynik = $this->akcja()->zapiszAlboPrzywroc($osoba, $wpis, null, false, null, function () use (&$zuzyte): void {
            $zuzyte++;
        });

        $this->assertNull($wynik->zdaniePowrotu);
        $this->assertNotNull($wynik->zeszyt);
        $this->assertSame(0, $zuzyte);
        $this->assertSame(1, DB::table('collection_items')->where('post_id', $wpis->getKey())->count());
    }

    public function test_powrot_przywraca_oba_zeszyty_z_notatka_i_zuzywa_wyjecie_raz(): void
    {
        [$osoba, $wpis, $a, $b, $wyjecie] = $this->poWyjeciuZDwochZeszytow();
        $zuzyte = 0;

        $wynik = $this->akcja()->zapiszAlboPrzywroc($osoba, $wpis, null, false, $wyjecie, function () use (&$zuzyte): void {
            $zuzyte++;
        });

        $this->assertNull($wynik->zeszyt);
        $this->assertSame('Wpis wrócił do wszystkich 2 zeszytów razem z notatkami.', $wynik->zdaniePowrotu);
        $this->assertSame(1, $zuzyte);
        $this->assertSame('mniej soli', $a->posts()->first()->pivot->note);
        $this->assertSame(1, $b->posts()->count());
    }

    public function test_powrot_z_jednego_zeszytu_mowi_w_liczbie_pojedynczej(): void
    {
        $osoba = $this->user('jedna');
        $wpis = Post::factory()->create();
        $a = Collection::create(['owner_id' => $osoba->id, 'name' => 'Jedyny', 'visibility' => 'private']);
        $this->akcja()->handle($osoba, $wpis, $a);
        $wyjecie = [
            'typ' => PowrotPoWyjeciu::TYP_WPIS,
            'id' => (string) $wpis->getKey(),
            'pozycje' => $this->akcja()->remove($osoba, $wpis),
        ];

        $wynik = $this->akcja()->zapiszAlboPrzywroc($osoba, $wpis, null, false, $wyjecie, static function (): void {});

        $this->assertSame('Wpis wrócił do zeszytu razem z notatką.', $wynik->zdaniePowrotu);
    }

    public function test_podana_notatka_albo_wybrany_zeszyt_to_zwykly_zapis_bez_powrotu(): void
    {
        [$osoba, $wpis, $a, , $wyjecie] = $this->poWyjeciuZDwochZeszytow();
        $zuzyte = 0;
        $zuzyj = function () use (&$zuzyte): void {
            $zuzyte++;
        };

        $zNotatka = $this->akcja()->zapiszAlboPrzywroc($osoba, $wpis, null, true, $wyjecie, $zuzyj);
        $this->assertNull($zNotatka->zdaniePowrotu);
        $this->assertNotNull($zNotatka->zeszyt);

        $wybrany = $this->akcja()->zapiszAlboPrzywroc($osoba, $wpis, $a, false, $wyjecie, $zuzyj);
        $this->assertNull($wybrany->zdaniePowrotu);
        $this->assertTrue($wybrany->zeszyt->is($a));

        $this->assertSame(0, $zuzyte);
    }

    public function test_wyjecie_innego_wpisu_nie_jest_droga_powrotu(): void
    {
        [$osoba, , , , $wyjecie] = $this->poWyjeciuZDwochZeszytow();
        $inny = Post::factory()->create();
        $zuzyte = 0;

        $wynik = $this->akcja()->zapiszAlboPrzywroc($osoba, $inny, null, false, $wyjecie, function () use (&$zuzyte): void {
            $zuzyte++;
        });

        $this->assertNull($wynik->zdaniePowrotu);
        $this->assertSame(0, $zuzyte);
        $this->assertSame(1, DB::table('collection_items')->where('post_id', $inny->getKey())->count());
        $this->assertSame(0, DB::table('collection_items')->where('post_id', $wyjecie['id'])->count());
    }

    public function test_gdy_zaden_zeszyt_nie_wrocil_wyjecie_jest_zuzyte_i_idzie_zwykly_zapis(): void
    {
        [$osoba, $wpis, $a, $b, $wyjecie] = $this->poWyjeciuZDwochZeszytow();
        $a->delete();
        $b->delete();
        $zuzyte = 0;

        $wynik = $this->akcja()->zapiszAlboPrzywroc($osoba, $wpis, null, false, $wyjecie, function () use (&$zuzyte): void {
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
        $prywatny = Post::factory()->create(['author_id' => $wlasciciel->getKey(), 'visibility' => 'private']);

        try {
            $this->akcja()->zapiszAlboPrzywroc($osoba, $prywatny, null, false, null, static function (): void {});
            $this->fail('Zapis cudzego wpisu prywatnego powinien zostać odrzucony.');
        } catch (BladDlaCzlowieka) {
            $this->assertSame(0, DB::table('collection_items')->count());
        }
    }
}
