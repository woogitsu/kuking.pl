<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Odzywcze\ImportujWartosciOdzywcze;
use App\Domain\Recipes\Odzywcze\KalkulatorWartosci;
use App\Domain\Recipes\Odzywcze\SlownikSkladnikow;
use App\Domain\Recipes\Odzywcze\WierszWyliczenia;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Jawny stan składnika nie może zmienić produktu w tabeli (D-299, #2563). */
final class SlownikStanProduktuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ImportujWartosciOdzywcze::class)->handle();
    }

    #[Test]
    public function test_stan_przed_nazwa_nie_pozwala_dopasowac_surowego_produktu(): void
    {
        $nazwy = [
            'ugotowanego ryzu', 'ugotowanego makaronu',
            'upieczonego ryzu', 'surowych sliwek', 'suszonych ziemniakow',
        ];
        $dopasowania = app(SlownikSkladnikow::class)->dopasuj($nazwy);

        foreach ($nazwy as $nazwa) {
            $this->assertNull($dopasowania[$nazwa], 'STAN_2563_PRZED_NAZWA: '.$nazwa);
        }
    }

    #[Test]
    public function test_stan_po_nazwie_nie_pozwala_dopasowac_surowego_produktu(): void
    {
        $nazwy = [
            'ryzu ugotowanego', 'makaronu ugotowanego',
            'ryzu upieczonego', 'sliwek surowych', 'ziemniakow suszonych',
        ];
        $dopasowania = app(SlownikSkladnikow::class)->dopasuj($nazwy);

        foreach ($nazwy as $nazwa) {
            $this->assertNull($dopasowania[$nazwa], 'STAN_2563_PO_NAZWIE: '.$nazwa);
        }
    }

    #[Test]
    public function test_pelne_aliasy_stanu_i_opisy_neutralne_nadal_dzialaja(): void
    {
        $dopasowania = app(SlownikSkladnikow::class)->dopasuj([
            'ugotowanych ziemniakow', 'ziemniakow gotowanych',
            'ziemniaki', 'surowego boczku', 'suszonych sliwek',
            'swiezej maki pszennej', 'maki pszennej swiezej',
            'mleka kokosowego',
        ]);

        $this->assertSame('ziemniaki_gotowane', $dopasowania['ugotowanych ziemniakow']?->klucz);
        $this->assertSame('ziemniaki_gotowane', $dopasowania['ziemniakow gotowanych']?->klucz);
        $this->assertSame('ziemniaki', $dopasowania['ziemniaki']?->klucz);
        $this->assertSame('boczek_surowy', $dopasowania['surowego boczku']?->klucz);
        $this->assertSame('sliwki_suszone', $dopasowania['suszonych sliwek']?->klucz);
        $this->assertSame('maka_pszenna', $dopasowania['swiezej maki pszennej']?->klucz);
        $this->assertSame('maka_pszenna', $dopasowania['maki pszennej swiezej']?->klucz);
        $this->assertNull($dopasowania['mleka kokosowego']);
    }

    #[Test]
    public function test_kalkulator_traktuje_ugotowany_ryz_i_makaron_jako_nieznane_mimo_gramow(): void
    {
        $recipe = new Recipe(['servings' => 1]);
        $recipe->setRelation('ingredients', collect([
            (new RecipeIngredient(['ingredient_text' => '100 g ugotowanego ryżu', 'no_amount' => false]))->setRelation('unit', null),
            (new RecipeIngredient(['ingredient_text' => '100 g makaronu ugotowanego', 'no_amount' => false]))->setRelation('unit', null),
        ]));

        $wynik = app(KalkulatorWartosci::class)->policz($recipe);

        $this->assertFalse($wynik->policzone());
        $this->assertSame(2, $wynik->ile(WierszWyliczenia::NIEZNANY_SKLADNIK));
        foreach ($wynik->wiersze as $wiersz) {
            $this->assertSame(WierszWyliczenia::NIEZNANY_SKLADNIK, $wiersz->stan);
            $this->assertNull($wiersz->klucz);
            $this->assertSame(100.0, $wiersz->gramy);
        }
        $this->assertSame(0.0, $wynik->pokrycie());
    }
}
