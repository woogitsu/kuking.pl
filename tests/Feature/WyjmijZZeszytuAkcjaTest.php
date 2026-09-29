<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Domain\Collections\PowrotPoWyjeciu;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „Usuń z zeszytu" żyje w akcji, nie w kontrolerze (issue #970, krok 7):
 * akcja wyjmuje, układa zdanie o zakresie i kształt zapisu dla drogi powrotu.
 * Sesję zamyka kontroler — akcja oddaje zwykłe wartości. Zachowanie przez
 * HTTP pilnują istniejące testy Feature zeszytów.
 */
class WyjmijZZeszytuAkcjaTest extends TestCase
{
    use RefreshDatabase;

    public function test_przepis_z_jednego_zeszytu_mowi_nazwe_i_daje_zapis_dla_powrotu(): void
    {
        $osoba = $this->user('jedna');
        $przepis = Recipe::factory()->create();
        $zeszyt = Collection::create(['owner_id' => $osoba->id, 'name' => 'Niedzielne', 'visibility' => 'private']);
        app(SaveRecipeToCollection::class)->handle($osoba, $przepis, $zeszyt, 'mniej soli');

        $wynik = app(SaveRecipeToCollection::class)->wyjmij($osoba, $przepis);

        $this->assertSame(
            'Przepis wyjęty z zeszytu „Niedzielne”. Nie usunęliśmy go z serwisu — możesz go przywrócić.',
            $wynik->komunikat,
        );
        $this->assertSame(PowrotPoWyjeciu::TYP_PRZEPIS, $wynik->wyjecie['typ']);
        $this->assertSame((string) $przepis->getKey(), $wynik->wyjecie['id']);
        $this->assertSame($wynik->zdjete, $wynik->wyjecie['pozycje']);
        $this->assertSame('mniej soli', $wynik->zdjete[0]['note']);
        $this->assertSame(0, DB::table('collection_items')->where('recipe_id', $przepis->getKey())->count());
    }

    public function test_przepis_z_kilku_zeszytow_mowi_ile_ich_bylo(): void
    {
        $osoba = $this->user('kilka');
        $przepis = Recipe::factory()->create();
        foreach (['Aaa', 'Bbb', 'Ccc'] as $nazwa) {
            $z = Collection::create(['owner_id' => $osoba->id, 'name' => $nazwa, 'visibility' => 'private']);
            app(SaveRecipeToCollection::class)->handle($osoba, $przepis, $z);
        }

        $wynik = app(SaveRecipeToCollection::class)->wyjmij($osoba, $przepis);

        $this->assertSame(
            'Przepis wyjęty z zeszytu — zniknął ze wszystkich Twoich zeszytów, było ich 3. '
            .'Nie usunęliśmy go z serwisu — możesz go przywrócić razem z notatkami.',
            $wynik->komunikat,
        );
        $this->assertCount(3, $wynik->wyjecie['pozycje']);
    }

    public function test_wyjecie_z_wybranego_zeszytu_nie_rusza_pozostalych(): void
    {
        $osoba = $this->user('wybor');
        $przepis = Recipe::factory()->create();
        $a = Collection::create(['owner_id' => $osoba->id, 'name' => 'Aaa', 'visibility' => 'private']);
        $b = Collection::create(['owner_id' => $osoba->id, 'name' => 'Bbb', 'visibility' => 'private']);
        app(SaveRecipeToCollection::class)->handle($osoba, $przepis, $a);
        app(SaveRecipeToCollection::class)->handle($osoba, $przepis, $b);

        $wynik = app(SaveRecipeToCollection::class)->wyjmij($osoba, $przepis, $b);

        $this->assertCount(1, $wynik->zdjete);
        $this->assertStringContainsString('„Bbb”', (string) $wynik->komunikat);
        $this->assertSame(1, $a->recipes()->count());
    }

    public function test_gdy_nic_nie_zdjeto_nie_ma_komunikatu_ani_zapisu_powrotu(): void
    {
        $osoba = $this->user('pusta');

        $przepis = app(SaveRecipeToCollection::class)->wyjmij($osoba, Recipe::factory()->create());
        $wpis = app(SavePostToCollection::class)->wyjmij($osoba, Post::factory()->create());

        foreach ([$przepis, $wpis] as $wynik) {
            $this->assertSame([], $wynik->zdjete);
            $this->assertNull($wynik->komunikat);
            $this->assertNull($wynik->wyjecie);
        }
    }

    public function test_wpis_mowi_wpis_i_zapis_pasuje_do_drogi_powrotu(): void
    {
        $osoba = $this->user('wpisowa');
        $wpis = Post::factory()->create();
        $zeszyt = Collection::create(['owner_id' => $osoba->id, 'name' => 'Inspiracje', 'visibility' => 'private']);
        app(SavePostToCollection::class)->handle($osoba, $wpis, $zeszyt);

        $wynik = app(SavePostToCollection::class)->wyjmij($osoba, $wpis);

        $this->assertSame(
            'Wpis wyjęty z zeszytu „Inspiracje”. Nie usunęliśmy go z serwisu — możesz go przywrócić.',
            $wynik->komunikat,
        );
        $this->assertSame(PowrotPoWyjeciu::TYP_WPIS, $wynik->wyjecie['typ']);

        // Zapis z wyjęcia jest dokładnie tym, co akcja powrotu rozpoznaje.
        $this->assertSame(
            $wynik->zdjete,
            PowrotPoWyjeciu::pozycje($wynik->wyjecie, PowrotPoWyjeciu::TYP_WPIS, (string) $wpis->getKey()),
        );
        $powrot = app(SavePostToCollection::class)->zapiszAlboPrzywroc($osoba, $wpis, null, false, $wynik->wyjecie, static function (): void {});
        $this->assertSame('Wpis wrócił do zeszytu razem z notatką.', $powrot->zdaniePowrotu);
    }
}
