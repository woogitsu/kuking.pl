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

/** Dopisek wskazujący inny surowiec nie może udawać aliasu pszenicy (D-299, #2607). */
final class SlownikSurowcaOdzywczegoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ImportujWartosciOdzywcze::class)->handle();
    }

    #[Test]
    public function test_maka_z_ciecierzycy_nie_jest_pszenna_gdy_csv_nie_ma_jej_aliasu(): void
    {
        $this->assertSame(
            ['maki z ciecierzycy'],
            array_column(SlownikSkladnikow::fragmenty('maki z ciecierzycy'), 'tekst'),
            'SUROWIEC_2607_NIE_POMIJAJ_ZRODLA',
        );
        $this->assertSame(
            ['maki ze straczkow'],
            array_column(SlownikSkladnikow::fragmenty('maki ze straczkow'), 'tekst'),
            'SUROWIEC_2607_NIE_POMIJAJ_ZRODLA',
        );
        $dopasowania = app(SlownikSkladnikow::class)->dopasuj([
            'maka z ciecierzycy', 'maki z ciecierzycy',
            'maka', 'maki', 'maka pszenna', 'maki pszennej', 'maki gryczanej',
            'sok z cytryny', 'mleka kokosowego',
        ]);

        $this->assertNull($dopasowania['maka z ciecierzycy'], 'SUROWIEC_2607_NIE_POMIJAJ_ZRODLA');
        $this->assertNull($dopasowania['maki z ciecierzycy'], 'SUROWIEC_2607_NIE_POMIJAJ_ZRODLA');
        foreach (['maka', 'maki', 'maka pszenna', 'maki pszennej'] as $nazwa) {
            $this->assertSame('maka_pszenna', $dopasowania[$nazwa]?->klucz, $nazwa);
        }
        $this->assertSame('maka_gryczana', $dopasowania['maki gryczanej']?->klucz);
        $this->assertSame('sok_z_cytryny', $dopasowania['sok z cytryny']?->klucz);
        $this->assertNull($dopasowania['mleka kokosowego']);
    }

    #[Test]
    public function test_kalkulator_nie_zalicza_500_g_nieznanej_maki_do_pokrytej_masy(): void
    {
        $recipe = new Recipe(['servings' => 1]);
        $recipe->setRelation('ingredients', collect([
            (new RecipeIngredient(['ingredient_text' => '500 g mąki z ciecierzycy', 'no_amount' => false]))->setRelation('unit', null),
            (new RecipeIngredient(['ingredient_text' => '500 g mąki pszennej', 'no_amount' => false]))->setRelation('unit', null),
        ]));

        $wynik = app(KalkulatorWartosci::class)->policz($recipe);

        $this->assertFalse($wynik->policzone(), 'SUROWIEC_2607_NIE_POMIJAJ_ZRODLA');
        $this->assertSame(WierszWyliczenia::NIEZNANY_SKLADNIK, $wynik->wiersze[0]->stan, 'SUROWIEC_2607_NIE_POMIJAJ_ZRODLA');
        $this->assertNull($wynik->wiersze[0]->klucz, 'SUROWIEC_2607_NIE_POMIJAJ_ZRODLA');
        $this->assertSame(500.0, $wynik->wiersze[0]->gramy);
        $this->assertSame('maka_pszenna', $wynik->wiersze[1]->klucz);
        $this->assertSame(500.0, $wynik->wiersze[1]->gramy);
        $this->assertEqualsWithDelta(0.5, $wynik->pokrycie(), 0.0001, 'SUROWIEC_2607_NIE_POMIJAJ_ZRODLA');
    }
}
