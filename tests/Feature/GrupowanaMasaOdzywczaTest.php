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
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** #2560: spacja w liczbie nie może zaniżyć masy ani udawać udanego odczytu. */
final class GrupowanaMasaOdzywczaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_kalkulator_liczy_cale_1500_g_zamiast_500_g(): void
    {
        $this->pozycja('maka', 'maka', 100);

        foreach (['mąka (1 500 g)', 'mąka (1500 g)', "mąka (1\u{00A0}500 g)", "mąka (1\u{202F}500 g)"] as $tekst) {
            $wynik = $this->policz($tekst);
            $this->assertTrue($wynik->policzone(), 'ODZYWCZE_2560_GRUPOWANA_MASA');
            $this->assertSame(1500.0, $wynik->wiersze[0]->gramy, 'ODZYWCZE_2560_GRUPOWANA_MASA');
            $this->assertSame(1500.0, $wynik->kcal, 'ODZYWCZE_2560_GRUPOWANA_MASA');
        }
    }

    #[Test]
    public function test_niepoprawna_grupa_odmawia_zamiast_liczyc_fragment_lub_miare_puszki(): void
    {
        $pomidor = $this->pozycja('pomidorow', 'pomidor', 20);
        MiaraDomowa::create(['skladnik_odzywczy_id' => $pomidor->getKey(), 'jednostka' => 'puszka', 'gramy' => 400]);

        foreach (['2 puszki pomidorów (1 50 g)', '2 puszki pomidorów (1 500 00 g)'] as $tekst) {
            $wynik = $this->policz($tekst);
            $this->assertFalse($wynik->policzone(), 'ODZYWCZE_2560_BLAD_GRUPOWANIA_ODMAWIA');
            $this->assertSame(WierszWyliczenia::BEZ_MASY, $wynik->wiersze[0]->stan, 'ODZYWCZE_2560_BLAD_GRUPOWANIA_ODMAWIA');
            $this->assertNull($wynik->wiersze[0]->gramy, 'ODZYWCZE_2560_BLAD_GRUPOWANIA_ODMAWIA');
        }

        $poprawny = $this->policz('2 puszki pomidorów (po 1 500 g)');
        $this->assertTrue($poprawny->policzone());
        $this->assertSame(3000.0, $poprawny->wiersze[0]->gramy);
        $this->assertSame(600.0, $poprawny->kcal);
    }

    #[Test]
    public function test_jawne_kolumny_ilosci_zachowuja_pierwszenstwo_nad_uszkodzonym_tekstem(): void
    {
        $this->pozycja('maka', 'maka', 100);
        $recipe = new Recipe(['servings' => 1]);
        $ingredient = (new RecipeIngredient([
            'ingredient_text' => 'mąka (1 50 g)',
            'quantity' => 1500,
            'no_amount' => false,
        ]))->setRelation('unit', new Unit(['code' => 'g']));
        $recipe->setRelation('ingredients', collect([$ingredient]));

        $wynik = app(KalkulatorWartosci::class)->policz($recipe);

        $this->assertTrue($wynik->policzone(), 'ODZYWCZE_2560_KOLUMNY_MAJA_PIERWSZENSTWO');
        $this->assertSame(1500.0, $wynik->wiersze[0]->gramy, 'ODZYWCZE_2560_KOLUMNY_MAJA_PIERWSZENSTWO');
        $this->assertSame(1500.0, $wynik->kcal);
    }

    private function pozycja(string $alias, string $klucz, int $kcal): SkladnikOdzywczy
    {
        $pozycja = SkladnikOdzywczy::create([
            'klucz' => $klucz,
            'nazwa' => $klucz,
            'zrodlo' => SkladnikOdzywczy::ZRODLO_CIQUAL,
            'zrodlo_id' => 'test-2560-'.$klucz,
            'zrodlo_nazwa' => 'Pozycja testowa',
            'kcal_100g' => $kcal,
            'bialko_100g' => 0,
            'tluszcz_100g' => 0,
            'weglowodany_100g' => 0,
            'pomijalny' => false,
        ]);
        AliasSkladnika::create(['alias' => $alias, 'skladnik_odzywczy_id' => $pozycja->getKey()]);

        return $pozycja;
    }

    private function policz(string $tekst): WynikWartosci
    {
        $recipe = new Recipe(['servings' => 1]);
        $ingredient = (new RecipeIngredient(['ingredient_text' => $tekst, 'no_amount' => false]))->setRelation('unit', null);
        $recipe->setRelation('ingredients', collect([$ingredient]));

        return app(KalkulatorWartosci::class)->policz($recipe);
    }
}
