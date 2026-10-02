<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Odzywcze\KalkulatorWartosci;
use App\Domain\Recipes\Odzywcze\WierszWyliczenia;
use App\Domain\Recipes\Odzywcze\WynikWartosci;
use App\Models\AliasSkladnika;
use App\Models\MiaraDomowa;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\SkladnikOdzywczy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** #2487: jawna masa łączna nie staje się wagą każdej puszki. */
final class MasaLacznaWNawiasieTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_kalkulator_uzywa_lacznej_masy_raz_i_odmawia_przy_sprzecznym_nawiasie(): void
    {
        // Kontrolowana lokalnie pozycja: nie zależy od zewnętrznego importu CSV.
        $pomidor = SkladnikOdzywczy::create([
            'klucz' => 'pomidor_test_2487',
            'nazwa' => 'pomidor testowy',
            'zrodlo' => SkladnikOdzywczy::ZRODLO_CIQUAL,
            'zrodlo_id' => 'test-2487',
            'zrodlo_nazwa' => 'Pozycja testowa',
            'kcal_100g' => 20,
            'bialko_100g' => 1,
            'tluszcz_100g' => 0,
            'weglowodany_100g' => 4,
            'pomijalny' => false,
        ]);
        AliasSkladnika::create(['alias' => 'pomidorow', 'skladnik_odzywczy_id' => $pomidor->getKey()]);
        MiaraDomowa::create(['skladnik_odzywczy_id' => $pomidor->getKey(), 'jednostka' => 'puszka', 'gramy' => 400]);

        foreach (['2 puszki pomidorów (800 g razem)', '2 puszki pomidorów (łącznie 800 g)'] as $tekst) {
            $wynik = $this->policz($tekst);
            $this->assertTrue($wynik->policzone(), 'ODZYWCZE_2487_KALKULATOR_LACZNA_MASA');
            $this->assertSame(800.0, $wynik->wiersze[0]->gramy, 'ODZYWCZE_2487_KALKULATOR_LACZNA_MASA');
            $this->assertSame(160.0, $wynik->kcal, 'ODZYWCZE_2487_KALKULATOR_LACZNA_MASA');
        }

        $sprzeczny = $this->policz('2 puszki pomidorów (po 400 g razem)');
        $this->assertFalse($sprzeczny->policzone(), 'ODZYWCZE_2487_SPRZECZNY_NAWIAS_ODMAWIA');
        $this->assertSame(WierszWyliczenia::BEZ_MASY, $sprzeczny->wiersze[0]->stan, 'ODZYWCZE_2487_SPRZECZNY_NAWIAS_ODMAWIA');
        $this->assertNull($sprzeczny->wiersze[0]->gramy, 'ODZYWCZE_2487_SPRZECZNY_NAWIAS_ODMAWIA');
    }

    private function policz(string $tekst): WynikWartosci
    {
        $recipe = new Recipe(['servings' => 1]);
        $ingredient = (new RecipeIngredient(['ingredient_text' => $tekst, 'no_amount' => false]))->setRelation('unit', null);
        $recipe->setRelation('ingredients', collect([$ingredient]));

        return app(KalkulatorWartosci::class)->policz($recipe);
    }
}
