<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Gotowanie\PostepGotowania;
use App\Domain\Recipes\Gotowanie\ZapamietaneGotowania;
use App\Models\CookedEvent;
use App\Models\CookingProgress;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prywatna lista „Gotowanie zapamiętane na koncie” (#2439). Pilnuje: zakresu
 * właściciela, wygaśnięcia niezależnego od sprzątania, ponownej kontroli
 * widoczności (soft-delete, prywatność, blokada), liczenia po aktualnych
 * krokach, braku przedłużania retencji i braku „Ugotowałem”.
 */
class GotowanieZapamietaneNaKoncieTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Recipe, 1: list<RecipeStep>} */
    private function przepis(string $tytul, ?User $autor = null, string $widocznosc = 'public'): array
    {
        $przepis = Recipe::factory()->create([
            'title' => $tytul,
            'author_id' => ($autor ?? $this->user())->getKey(),
            'visibility' => $widocznosc,
        ]);
        $kroki = [];
        foreach ([0, 1, 2] as $i) {
            $kroki[] = RecipeStep::create([
                'recipe_id' => $przepis->getKey(),
                'position' => $i,
                'instruction' => 'Krok '.($i + 1).'.',
            ]);
        }

        return [$przepis, $kroki];
    }

    /** @param list<RecipeStep> $kroki @param list<RecipeStep> $odhaczone */
    private function zapamietaj(User $osoba, Recipe $przepis, array $kroki, array $odhaczone = []): CookingProgress
    {
        $id = fn (RecipeStep $k): string => (string) $k->getKey();

        return app(PostepGotowania::class)->wlacz(
            $osoba,
            $przepis,
            array_map($id, $kroki),
            array_map($id, $odhaczone),
        );
    }

    private function lista(User $osoba)
    {
        return $this->actingAs($osoba)->get(route('collections.cooking-progress'));
    }

    public function test_lista_pokazuje_tylko_wlasne_zapamietane_gotowania(): void
    {
        $ja = $this->user();
        $inna = $this->user();
        [$moj, $mojeKroki] = $this->przepis('Barszcz ZP-moj');
        [$cudzy, $cudzeKroki] = $this->przepis('Bigos ZP-cudzy');
        $this->zapamietaj($ja, $moj, $mojeKroki);
        $this->zapamietaj($inna, $cudzy, $cudzeKroki);

        $this->lista($ja)->assertOk()
            ->assertSee('Barszcz ZP-moj')
            ->assertDontSee('Bigos ZP-cudzy');
    }

    public function test_gosc_nie_wchodzi_na_liste(): void
    {
        $this->get(route('collections.cooking-progress'))->assertRedirect();
    }

    public function test_sam_opt_in_bez_odhaczen_wszystkie_odhaczone_i_czesciowe_maja_trzy_opisy(): void
    {
        $ja = $this->user();
        [$a, $ka] = $this->przepis('Zupa ZP-pusta');
        [$b, $kb] = $this->przepis('Zupa ZP-czesc');
        [$c, $kc] = $this->przepis('Zupa ZP-wszystkie');
        $this->zapamietaj($ja, $a, $ka);
        $this->zapamietaj($ja, $b, $kb, [$kb[0]]);
        $this->zapamietaj($ja, $c, $kc, $kc);

        $this->lista($ja)->assertOk()
            ->assertSee('Bez odhaczeń')
            ->assertSee('Odhaczone: 1 z 3')
            ->assertSee('Wszystkie kroki odhaczone');
    }

    public function test_skasowany_krok_nie_jest_liczony(): void
    {
        $ja = $this->user();
        [$przepis, $kroki] = $this->przepis('Zupa ZP-skasowany');
        $this->zapamietaj($ja, $przepis, $kroki, [$kroki[0], $kroki[1]]);
        $kroki[0]->delete();

        $this->lista($ja)->assertOk()->assertSee('Odhaczone: 1 z 2');
    }

    public function test_wygasly_rekord_jest_niewidoczny_mimo_braku_sprzatania(): void
    {
        $ja = $this->user();
        [$przepis, $kroki] = $this->przepis('Zupa ZP-wygasla');
        $postep = $this->zapamietaj($ja, $przepis, $kroki);
        DB::table('cooking_progress')->where('id', $postep->getKey())->update(['expires_at' => now()->subMinute()]);

        $this->lista($ja)->assertOk()
            ->assertDontSee('Zupa ZP-wygasla')
            ->assertSee('Nic tu jeszcze nie ma');
        $this->assertSame(1, CookingProgress::query()->count());
    }

    public function test_przepis_utracony_prywatny_lub_zablokowany_nie_zdradza_tytulu(): void
    {
        $ja = $this->user();
        $autor = $this->user();
        [$usuniety, $k1] = $this->przepis('Zupa ZP-usuniety', $autor);
        [$prywatny, $k2] = $this->przepis('Zupa ZP-prywatny', $autor);
        [$zablokowany, $k3] = $this->przepis('Zupa ZP-blokada', $this->user());
        [$widoczny, $k4] = $this->przepis('Zupa ZP-widoczny', $autor);
        foreach ([[$usuniety, $k1], [$prywatny, $k2], [$zablokowany, $k3], [$widoczny, $k4]] as [$p, $k]) {
            $this->zapamietaj($ja, $p, $k);
        }

        $usuniety->delete();
        DB::table('recipes')->where('id', $prywatny->getKey())->update(['visibility' => 'private']);
        DB::table('blocks')->insert([
            'blocker_id' => $zablokowany->author_id,
            'blocked_id' => $ja->getKey(),
            'created_at' => now(),
        ]);

        $this->lista($ja)->assertOk()
            ->assertSee('Zupa ZP-widoczny')
            ->assertDontSee('Zupa ZP-usuniety')
            ->assertDontSee('Zupa ZP-prywatny')
            ->assertDontSee('Zupa ZP-blokada');
    }

    public function test_niedostepne_rekordy_nie_wypieraja_dostepnych_z_pierwszej_strony(): void
    {
        $ja = $this->user();
        $autor = $this->user();
        for ($i = 1; $i <= ZapamietaneGotowania::NA_STRONE + 2; $i++) {
            [$p, $k] = $this->przepis('Zupa ZP-ukryta '.$i, $autor);
            $this->zapamietaj($ja, $p, $k);
            $p->delete();
        }
        [$dostepny, $kd] = $this->przepis('Zupa ZP-jedyny', $autor);
        $postep = $this->zapamietaj($ja, $dostepny, $kd);
        // Najstarszy rekord, więc leży PO wszystkich niedostępnych.
        DB::table('cooking_progress')->where('id', $postep->getKey())->update(['updated_at' => now()->subHours(3)]);

        $this->lista($ja)->assertOk()->assertSee('Zupa ZP-jedyny');
    }

    public function test_pokaz_wiecej_i_kolejnosc_po_ostatniej_zmianie(): void
    {
        $ja = $this->user();
        for ($i = 1; $i <= ZapamietaneGotowania::NA_STRONE + 1; $i++) {
            [$p, $k] = $this->przepis('Zupa ZP-nr'.$i);
            $postep = $this->zapamietaj($ja, $p, $k);
            DB::table('cooking_progress')->where('id', $postep->getKey())->update(['updated_at' => now()->subMinutes($i)]);
        }

        $this->lista($ja)->assertOk()
            ->assertSee('Pokaż więcej')
            ->assertSee('Zupa ZP-nr1')
            ->assertDontSee('Zupa ZP-nr11');

        $this->actingAs($ja)->get(route('collections.cooking-progress', ['od' => ZapamietaneGotowania::NA_STRONE]))->assertOk()
            ->assertSee('Zupa ZP-nr11')
            ->assertDontSee('Pokaż więcej');
    }

    public function test_odczyt_listy_nie_odnawia_wygasniecia_i_nie_tworzy_ugotowalem(): void
    {
        $ja = $this->user();
        [$przepis, $kroki] = $this->przepis('Zupa ZP-retencja');
        $postep = $this->zapamietaj($ja, $przepis, $kroki, $kroki);
        $przed = DB::table('cooking_progress')->where('id', $postep->getKey())->first();

        $this->lista($ja)->assertOk();
        $this->actingAs($ja)->get(route('cooking.show', $przepis->slug))->assertOk();

        $po = DB::table('cooking_progress')->where('id', $postep->getKey())->first();
        $this->assertNotNull($przed);
        $this->assertEquals($przed->expires_at, $po->expires_at);
        $this->assertEquals($przed->updated_at, $po->updated_at);
        $this->assertSame(0, CookedEvent::query()->count());
    }

    public function test_wejscie_w_moje_prowadzi_do_listy(): void
    {
        $ja = $this->user();

        $this->actingAs($ja)->get(route('collections.index'))->assertOk()
            ->assertSee('data-link-gotowanie-zapamietane', false)
            ->assertSee(route('collections.cooking-progress'), false);
    }
}
