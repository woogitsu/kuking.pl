<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pantry\CoUgotuje;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #2613: produkt wymieniony wyłącznie po „bez” nie jest składnikiem w domu. */
final class CoUgotujeNegacjaSkladnikaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sol_nie_ukrywa_braku_masla_ani_nie_uruchamia_trybu_pilnego(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'sól', true);
        $this->produkt($ja, 'mąka');
        $this->produkt($ja, 'cukier');
        $przepis = $this->przepis('Ciasto bez solonego masła', [
            '200 g masła BEZ SOLI', '300 g mąki', '100 g cukier',
        ]);

        $wynik = app(CoUgotuje::class)->dla($ja);
        $this->assertSame([$przepis->getKey()], $wynik['przepisy']->modelKeys());
        $this->assertSame(1, (int) $wynik['przepisy'][0]->skladnikow_brakuje, 'SPIZARNIA_2613_SOL_NIE_JEST_MASLEM');
        $this->assertSame(['200 g masła BEZ SOLI'], $wynik['brakujace'][$przepis->getKey()], 'SPIZARNIA_2613_SOL_NIE_JEST_MASLEM');

        $this->actingAs($ja)->get(route('pantry.cook'))
            ->assertOk()
            ->assertSee('Masz 2 z 3 składników.')
            ->assertSee('Brakuje: 200 g masła BEZ SOLI.')
            ->assertDontSee('Masz wszystkie składniki (3).');

        $pilny = app(CoUgotuje::class)->dla($ja, 0, 20, true);
        $this->assertTrue($pilny['przepisy']->isEmpty(), 'SPIZARNIA_2613_SOL_NIE_JEST_MASLEM');
        $this->assertSame([], $pilny['do_zuzycia'], 'SPIZARNIA_2613_SOL_NIE_JEST_MASLEM');
        $this->actingAs($ja)->get(route('pantry.cook', ['najpierw' => 'termin']))
            ->assertOk()
            ->assertSee('Żaden przepis nie pasuje do produktów z krótkim terminem')
            ->assertDontSee('Zużyjesz:');
    }

    public function test_maslo_pozostaje_posiadane_mimo_dopisku_bez_soli(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'masło', true);
        $przepis = $this->przepis('Masło bez soli', ['200 g masła bez soli']);

        $wynik = app(CoUgotuje::class)->dla($ja);
        $this->assertSame([$przepis->getKey()], $wynik['przepisy']->modelKeys());
        $this->assertSame(0, (int) $wynik['przepisy'][0]->skladnikow_brakuje);
        $this->assertSame([], $wynik['brakujace'][$przepis->getKey()]);
        $this->actingAs($ja)->get(route('pantry.cook'))->assertOk()->assertSee('Masz wszystkie składniki (1).');

        $pilny = app(CoUgotuje::class)->dla($ja, 0, 20, true);
        $this->assertSame([$przepis->getKey()], $pilny['przepisy']->modelKeys());
        $this->assertSame('masło', $pilny['do_zuzycia'][$przepis->getKey()][0]['nazwa']);
    }

    public function test_zwykla_sol_i_szczypta_soli_nadal_pasuja(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'sól');
        $przepis = $this->przepis('Sól wprost', ['sól', 'szczypta soli']);

        $wynik = app(CoUgotuje::class)->dla($ja);
        $this->assertSame([$przepis->getKey()], $wynik['przepisy']->modelKeys());
        $this->assertSame(0, (int) $wynik['przepisy'][0]->skladnikow_brakuje);
        $this->assertSame([], $wynik['brakujace'][$przepis->getKey()]);
    }

    public function test_pilna_sol_nie_jest_na_liscie_zuzyjesz_gdy_przepis_wchodzi_przez_cukier(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'sól', true);
        $this->produkt($ja, 'cukier', true);
        $przepis = $this->przepis('Cukier bez soli', ['200 g masła bez soli', 'cukier']);

        $pilny = app(CoUgotuje::class)->dla($ja, 0, 20, true);
        $this->assertSame([$przepis->getKey()], $pilny['przepisy']->modelKeys());
        $this->assertSame(1, (int) $pilny['przepisy'][0]->pilnych_pasuje, 'SPIZARNIA_2613_SOL_NIE_JEST_MASLEM');
        $this->assertSame(['200 g masła bez soli'], $pilny['brakujace'][$przepis->getKey()]);
        $this->assertSame(['cukier'], array_column($pilny['do_zuzycia'][$przepis->getKey()], 'nazwa'), 'SPIZARNIA_2613_SOL_NIE_JEST_MASLEM');
        $this->actingAs($ja)->get(route('pantry.cook', ['najpierw' => 'termin']))
            ->assertOk()
            ->assertSee('Zużyjesz:')
            ->assertSee('Brakuje: 200 g masła bez soli.');
    }

    public function test_maslo_wylacznie_po_bez_nie_zalicza_margaryny(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'masło', true);
        $this->produkt($ja, 'cukier');
        $przepis = $this->przepis('Margaryna do ciasta', ['200 g margaryny bez masła', 'cukier']);

        $wynik = app(CoUgotuje::class)->dla($ja);
        $this->assertSame([$przepis->getKey()], $wynik['przepisy']->modelKeys());
        $this->assertSame(['200 g margaryny bez masła'], $wynik['brakujace'][$przepis->getKey()], 'SPIZARNIA_2613_MASLO_NIE_JEST_MARGARYNA');
        $this->assertTrue(app(CoUgotuje::class)->dla($ja, 0, 20, true)['przepisy']->isEmpty(), 'SPIZARNIA_2613_MASLO_NIE_JEST_MARGARYNA');
    }

    public function test_interpunkcja_konczy_przeczenie_a_dodatni_produkt_pozostaje_dopasowany(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'sól');
        $this->produkt($ja, 'cukier');
        $wprost = $this->przepis('Sól występuje też dodatnio', ['sól, 200 g masła bez soli']);
        $poPrzeczeniu = $this->przepis('Cukier po klauzuli', ['200 g masła bez soli; cukier']);
        $liczbaDziesietna = $this->przepis('Cukier po ilości dziesiętnej', ['masło bez 0.5 g soli, cukier']);
        $tylkoZanegowane = $this->przepis('Sama klauzula', ['200 g masła bez soli']);

        $wynik = app(CoUgotuje::class)->dla($ja);
        $this->assertContains($wprost->getKey(), $wynik['przepisy']->modelKeys());
        $this->assertContains($poPrzeczeniu->getKey(), $wynik['przepisy']->modelKeys());
        $this->assertContains($liczbaDziesietna->getKey(), $wynik['przepisy']->modelKeys());
        $this->assertNotContains($tylkoZanegowane->getKey(), $wynik['przepisy']->modelKeys(), 'SPIZARNIA_2613_SOL_NIE_JEST_MASLEM');

        $tylkoSol = $this->user();
        $this->produkt($tylkoSol, 'sól');
        $this->assertNotContains(
            $liczbaDziesietna->getKey(),
            app(CoUgotuje::class)->dla($tylkoSol)['przepisy']->modelKeys(),
            'SPIZARNIA_2613_SOL_NIE_JEST_MASLEM',
        );
    }

    private function produkt(User $user, string $nazwa, bool $pilny = false): void
    {
        $produkt = $user->pantryItems()->create(['name' => $nazwa]);
        if ($pilny) {
            DB::table('pantry_items')->where('id', $produkt->getKey())
                ->update(['expires_on' => '2026-10-10', 'expiry_kind' => 'use_by']);
        }
    }

    /** @param list<string> $skladniki */
    private function przepis(string $tytul, array $skladniki): Recipe
    {
        $recipe = Recipe::factory()->create(['title' => $tytul, 'author_id' => $this->user()->getKey()]);
        foreach ($skladniki as $i => $tekst) {
            $recipe->ingredients()->create(['position' => $i, 'ingredient_text' => $tekst]);
        }

        return $recipe;
    }
}
