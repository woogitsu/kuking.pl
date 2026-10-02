<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pantry\CoUgotuje;
use App\Models\Collection;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „Co ugotuję z tego, co mam” z zakresem „Z moich zeszytów” (#2591).
 * Zakres to WŁASNOŚĆ zeszytu: własny (także wspólny) liczy się, samo
 * członkostwo w cudzym — nie. Zapis nie nadaje prawa do przepisu.
 */
class CoUgotujeZZeszytowTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(string $tytul): Recipe
    {
        $przepis = Recipe::factory()->create(['title' => $tytul]);
        $przepis->ingredients()->create(['position' => 0, 'ingredient_text' => 'jajka']);

        return $przepis;
    }

    private function zeszyt(User $wlasciciel, string $nazwa = 'Zeszyt'): Collection
    {
        return $wlasciciel->collections()->create(['name' => $nazwa, 'visibility' => 'private']);
    }

    private function zapisz(Collection $zeszyt, Recipe $przepis): void
    {
        DB::table('collection_items')->insert([
            'collection_id' => $zeszyt->getKey(),
            'recipe_id' => $przepis->getKey(),
            'created_at' => now(),
        ]);
    }

    private function osobaZJajkami(): User
    {
        $osoba = $this->user();
        $osoba->pantryItems()->create(['name' => 'jajka']);

        return $osoba;
    }

    public function test_zakres_zeszytow_pokazuje_tylko_przepisy_z_wlasnych_zeszytow(): void
    {
        $osoba = $this->osobaZJajkami();
        $swoj = $this->przepis('Omlet ZZ-swoj');
        $this->przepis('Jajecznica ZZ-obcy');
        $this->zapisz($this->zeszyt($osoba), $swoj);

        $this->actingAs($osoba)->get(route('pantry.cook', ['zakres' => 'zeszyty']))->assertOk()
            ->assertSee('Omlet ZZ-swoj')
            ->assertDontSee('Jajecznica ZZ-obcy')
            ->assertSee('tylko przepisy z moich zeszytów');

        $this->actingAs($osoba)->get(route('pantry.cook'))->assertOk()
            ->assertSee('Omlet ZZ-swoj')
            ->assertSee('Jajecznica ZZ-obcy');
    }

    public function test_przepis_w_kilku_wlasnych_zeszytach_to_jeden_wynik(): void
    {
        $osoba = $this->osobaZJajkami();
        $przepis = $this->przepis('Omlet ZZ-dwa');
        $this->zapisz($this->zeszyt($osoba, 'Pierwszy'), $przepis);
        $this->zapisz($this->zeszyt($osoba, 'Drugi'), $przepis);

        $wynik = app(CoUgotuje::class)->dla($osoba, 0, CoUgotuje::NA_STRONE, false, true);

        $this->assertCount(1, $wynik['przepisy']);
    }

    public function test_cudzy_zeszyt_z_moim_czlonkostwem_nie_rozszerza_zakresu(): void
    {
        $osoba = $this->osobaZJajkami();
        $inna = $this->user();
        $przepis = $this->przepis('Omlet ZZ-cudzy-zeszyt');
        $cudzy = $this->zeszyt($inna);
        $this->zapisz($cudzy, $przepis);
        DB::table('collection_members')->insert([
            'collection_id' => $cudzy->getKey(),
            'user_id' => $osoba->getKey(),
            'created_at' => now(),
        ]);

        $this->actingAs($osoba)->get(route('pantry.cook', ['zakres' => 'zeszyty']))->assertOk()
            ->assertDontSee('Omlet ZZ-cudzy-zeszyt')
            ->assertSee('W Twoich zeszytach nie ma przepisu z tymi produktami');
    }

    public function test_zapis_nie_nadaje_prawa_do_przepisu_szkic_i_cudzy_prywatny_odpadaja(): void
    {
        $osoba = $this->osobaZJajkami();
        $zeszyt = $this->zeszyt($osoba);
        $szkic = $this->przepis('Omlet ZZ-szkic');
        DB::table('recipes')->where('id', $szkic->getKey())->update(['status' => 'draft', 'published_at' => null]);
        $this->zapisz($zeszyt, $szkic);

        $this->actingAs($osoba)->get(route('pantry.cook', ['zakres' => 'zeszyty']))->assertOk()
            ->assertDontSee('Omlet ZZ-szkic');
    }

    public function test_pusty_wynik_w_zeszytach_ma_osobny_komunikat_i_droge_do_wszystkich(): void
    {
        $osoba = $this->osobaZJajkami();
        $this->przepis('Omlet ZZ-katalog');

        $odpowiedz = $this->actingAs($osoba)->get(route('pantry.cook', ['zakres' => 'zeszyty']))->assertOk();

        $odpowiedz->assertSee('W Twoich zeszytach nie ma przepisu z tymi produktami')
            ->assertDontSee('Najpierw wpisz, co masz w domu')
            ->assertDontSee('Żaden przepis nie ma jeszcze niczego z Twojej listy')
            ->assertSee('href="'.route('pantry.cook').'"', false);
    }

    public function test_zakres_i_tryb_terminu_dzialaja_razem_i_zostaja_przy_pokaz_wiecej(): void
    {
        $osoba = $this->osobaZJajkami();
        $zeszyt = $this->zeszyt($osoba);
        for ($i = 1; $i <= CoUgotuje::NA_STRONE + 1; $i++) {
            $this->zapisz($zeszyt, $this->przepis('Omlet ZZ-strona '.$i));
        }

        $this->actingAs($osoba)->get(route('pantry.cook', ['zakres' => 'zeszyty']))->assertOk()
            ->assertSee('Pokaż więcej')
            ->assertSee('zakres=zeszyty', false);

        $this->actingAs($osoba)->get(route('pantry.cook', ['zakres' => 'zeszyty', 'od' => CoUgotuje::NA_STRONE]))->assertOk()
            ->assertSee('Omlet ZZ-strona ', false);

        $this->actingAs($osoba)->get(route('pantry.cook', ['zakres' => 'zeszyty', 'najpierw' => 'termin']))->assertOk()
            ->assertSee('tylko przepisy z moich zeszytów');
    }
}
