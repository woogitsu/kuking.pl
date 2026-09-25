<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Usuń z zeszytu” przy przepisie w kilku zeszytach pyta, zanim zdejmie go
 * ze wszystkich (#775, D-267, decyzja właściciela z 25.09.2026).
 *
 * Sceny używają dwóch własnych zeszytów z notatkami i jednego cudzego —
 * i sprawdzają konkretne wiersze pivotu, nie tylko odpowiedź.
 */
class WyjecieZeWszystkichZeszytowWymagaPotwierdzeniaTest extends TestCase
{
    use RefreshDatabase;

    public function test_bez_potwierdzenia_nic_nie_znika_i_jest_strona_z_pytaniem(): void
    {
        [$basia, $przepis, $a, $b, $cudzy] = $this->scena();

        $this->actingAs($basia)
            ->delete(route('collections.unsave', $przepis->slug))
            ->assertOk()
            ->assertSee('Usunąć przepis ze wszystkich 2 zeszytów?')
            ->assertSee('Masz „'.$przepis->title.'” w 2 zeszytach.', false)
            ->assertSee('razem z Twoimi notatkami, zapisanymi w 2 z nich', false)
            ->assertSee('Tak, usuń ze wszystkich 2 zeszytów')
            ->assertSee('Usuń tylko z zeszytu „Obiady”', false)
            ->assertSee('Usuń tylko z zeszytu „Święta”', false)
            ->assertSee('Nie usuwaj — wróć')
            ->assertSee('name="potwierdzam_wszystkie" value="1"', false)
            ->assertSee('Przywróć do zeszytu', false);

        // NIC nie zniknęło: oba własne wiersze z notatkami i cudzy.
        $this->assertSame('bez cukru', $this->notatka($a, $przepis));
        $this->assertSame('mniej soli', $this->notatka($b, $przepis));
        $this->assertSame('cudza', $this->notatka($cudzy, $przepis));
    }

    public function test_z_potwierdzeniem_znika_ze_wszystkich_wlasnych_i_zostaje_droga_powrotu(): void
    {
        [$basia, $przepis, $a, $b, $cudzy] = $this->scena();

        $this->actingAs($basia)
            ->from($przepis->url())
            ->delete(route('collections.unsave', $przepis->slug), ['potwierdzam_wszystkie' => 1])
            ->assertRedirect($przepis->url())
            ->assertSessionHas('status_powrot', fn (array $p): bool => $p['etykieta'] === 'Przywróć do zeszytu');

        $this->assertNull($this->notatka($a, $przepis));
        $this->assertNull($this->notatka($b, $przepis));
        $this->assertSame('cudza', $this->notatka($cudzy, $przepis), 'Potwierdzenie ruszyło cudzy zeszyt.');

        // Droga powrotu wraca z notatkami.
        $this->actingAs($basia)->post(route('collections.save', $przepis->slug))->assertRedirect();
        $this->assertSame('bez cukru', $this->notatka($a, $przepis));
        $this->assertSame('mniej soli', $this->notatka($b, $przepis));
    }

    public function test_wybor_jednego_zeszytu_ze_strony_potwierdzenia_zostawia_drugi(): void
    {
        [$basia, $przepis, $a, $b] = $this->scena();

        $this->actingAs($basia)
            ->delete(route('collections.unsave', $przepis->slug), ['collection_id' => $a->getKey()])
            ->assertRedirect();

        $this->assertNull($this->notatka($a, $przepis));
        $this->assertSame('mniej soli', $this->notatka($b, $przepis));
    }

    public function test_jeden_zeszyt_nie_wymaga_potwierdzenia(): void
    {
        // Kontrola dodatnia: pytanie pojawia się tylko przy kilku zeszytach.
        $basia = $this->user('jeden');
        $przepis = Recipe::factory()->create();
        $a = Collection::create(['owner_id' => $basia->getKey(), 'name' => 'Jedyny', 'visibility' => 'private']);
        $a->recipes()->attach($przepis->getKey(), ['note' => 'x', 'created_at' => now()]);

        $this->actingAs($basia)
            ->delete(route('collections.unsave', $przepis->slug))
            ->assertRedirect();

        $this->assertNull($this->notatka($a, $przepis));
    }

    public function test_strona_potwierdzenia_nie_zdradza_tytulu_przepisu_ktorego_nie_wolno_ogladac(): void
    {
        [$basia, $przepis] = $this->scena();
        $przepis->forceFill(['visibility' => 'private'])->save();

        $this->actingAs($basia)
            ->delete(route('collections.unsave', $przepis->slug))
            ->assertOk()
            ->assertSee('Masz ten przepis w 2 zeszytach.')
            ->assertDontSee($przepis->title)
            ->assertSee('href="'.e(route('collections.index')).'"', false);
    }

    /** @return array{0: User, 1: Recipe, 2: Collection, 3: Collection, 4: Collection} */
    private function scena(): array
    {
        $basia = $this->user('basia');
        $obca = $this->user('obca');
        $przepis = Recipe::factory()->create(['title' => 'Pierogi ruskie babci']);

        $a = Collection::create(['owner_id' => $basia->getKey(), 'name' => 'Obiady', 'visibility' => 'private']);
        $b = Collection::create(['owner_id' => $basia->getKey(), 'name' => 'Święta', 'visibility' => 'private']);
        $cudzy = Collection::create(['owner_id' => $obca->getKey(), 'name' => 'Cudzy', 'visibility' => 'private']);
        $a->recipes()->attach($przepis->getKey(), ['note' => 'bez cukru', 'created_at' => now()->subDay()]);
        $b->recipes()->attach($przepis->getKey(), ['note' => 'mniej soli', 'created_at' => now()->subHours(3)]);
        $cudzy->recipes()->attach($przepis->getKey(), ['note' => 'cudza', 'created_at' => now()]);

        return [$basia, $przepis, $a, $b, $cudzy];
    }

    private function notatka(Collection $zeszyt, Recipe $przepis): ?string
    {
        $wiersz = $zeszyt->recipes()->whereKey($przepis->getKey())->first();

        return $wiersz?->pivot->note;
    }
}
