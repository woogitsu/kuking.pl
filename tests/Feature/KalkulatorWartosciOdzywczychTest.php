<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Odzywcze\ImportujWartosciOdzywcze;
use App\Domain\Recipes\Odzywcze\KalkulatorWartosci;
use App\Domain\Recipes\Odzywcze\WierszWyliczenia;
use App\Domain\Recipes\Odzywcze\WynikWartosci;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Unit;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kalkulator szacunkowych wartości odżywczych (D-299, I-10).
 */
final class KalkulatorWartosciOdzywczychTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ImportujWartosciOdzywcze::class)->handle();
    }

    /**
     * Przepis wzorcowy policzony RĘCZNIE z liczb w `skladniki.csv`
     * i `miary.csv` (stan z 26.09.2026). Zmiana tych plików, która
     * przestawia wynik, ma to zrobić świadomie — ten test pokaże różnicę.
     *
     *  200 g mąki pszennej    CIQUAL 9435: 346 kcal, B 10,  T 1,    W 71,5  /100 g
     *  2 jajka = 2 × 50 g     CIQUAL 22000: 140 kcal, B 12,8, T 9,83, W 0,06
     *  1 szklanka mleka=258 g CIQUAL 19033: 47,5 kcal, B 3,46, T 1,55, W 4,97
     *  szczypta soli = 0,5 g  CIQUAL 11017: 0
     *
     *  kcal: 692 + 140 + 122,55 + 0          = 954,55 → na 2 porcje 477,3 → „ok. 480”
     *  B:    20  + 12,8 + 8,9268             = 41,727 → 20,86 → 21 g
     *  T:    2   + 9,83 + 3,999              = 15,829 → 7,91  → 8 g
     *  W:    143 + 0,06 + 12,8226            = 155,88 → 77,94 → 78 g
     */
    #[Test]
    public function test_przepis_wzorcowy_zgadza_sie_z_rachunkiem_recznym(): void
    {
        $wynik = $this->policz(['200 g mąki pszennej', '2 jajka', '1 szklanka mleka', 'szczypta soli'], 2);

        $this->assertTrue($wynik->policzone());
        $this->assertEqualsWithDelta(477.275, $wynik->kcal, 0.01);
        $this->assertEqualsWithDelta(20.8634, $wynik->bialko, 0.001);
        $this->assertEqualsWithDelta(7.9145, $wynik->tluszcz, 0.001);
        $this->assertEqualsWithDelta(77.9413, $wynik->weglowodany, 0.001);
        $this->assertSame(480, $wynik->kcalDoPokazania());
        $this->assertTrue($wynik->naPorcje());
    }

    /**
     * #1963 — miara „kotlet” jest w `miary.csv` (`schab,kotlet,120`), ale
     * `JednostkiMiary::SLOWA` nie znała słowa „kotlety”, więc parser nie
     * zwracał jednostki i kalkulator szukał dla schabu miary „szt”, której
     * nie ma — wiersz wychodził jako `BEZ_MASY`.
     */
    #[Test]
    public function test_dwa_kotlety_schabowe_licza_sie_jako_240_gramow_schabu(): void
    {
        $wynik = $this->policz(['2 kotlety schabowe'], 1);

        $this->assertTrue($wynik->policzone());
        $wiersz = $wynik->wiersze[0];
        $this->assertSame(WierszWyliczenia::POLICZONY, $wiersz->stan);
        $this->assertSame('schab', $wiersz->klucz);
        $this->assertEqualsWithDelta(240.0, $wiersz->gramy, 0.001);
        $this->assertEqualsWithDelta(393.6, $wynik->kcal, 0.01);
        $this->assertEqualsWithDelta(47.52, $wynik->bialko, 0.01);
        $this->assertEqualsWithDelta(22.32, $wynik->tluszcz, 0.01);
        $this->assertEqualsWithDelta(0.912, $wynik->weglowodany, 0.001);
    }

    #[Test]
    public function test_pokrycie_ponizej_90_procent_masy_nie_daje_liczb(): void
    {
        // 150 g zakwasu nie ma w tabeli: 150 / 1010 g ≈ 14,9% masy.
        $wynik = $this->policz(['150 g aktywnego zakwasu żytniego', '350 g mąki pszennej', '150 g mąki żytniej', '350 ml wody', '10 g soli'], 1);

        $this->assertFalse($wynik->policzone());
        $this->assertEqualsWithDelta(860 / 1010, $wynik->pokrycie(), 0.0001);
        $this->assertStringContainsString('Znamy skład 85% masy przepisu, a liczymy dopiero od 90%', implode(' ', $wynik->powodyBrakuLiczb()));
        $this->assertStringContainsString('„150 g aktywnego zakwasu żytniego”', implode(' ', $wynik->powodyBrakuLiczb()));
    }

    #[Test]
    public function test_pokrycie_dokladnie_na_progu_daje_liczby(): void
    {
        // 90 g znanych + 10 g nieznanych = dokładnie 90%.
        $wynik = $this->policz(['90 g mąki pszennej', '10 g sosu z tajemnicą'], 1);

        $this->assertTrue($wynik->policzone());
        $this->assertSame(1, $wynik->ile(WierszWyliczenia::NIEZNANY_SKLADNIK));
    }

    #[Test]
    public function test_skladnik_bez_ilosci_blokuje_wynik_z_uczciwym_komunikatem(): void
    {
        $wynik = $this->policz(['4 ziemniaki', '1 jajko', 'olej do smażenia'], 3);

        $this->assertFalse($wynik->policzone());
        $powody = implode(' ', $wynik->powodyBrakuLiczb());
        $this->assertStringContainsString('Składników bez ilości, którą da się przeliczyć na gramy: 1 (na przykład „olej do smażenia”)', $powody);
        $this->assertStringContainsString('To nie błąd — tak się gotuje.', $powody);
        $this->assertStringNotContainsString('dopisz', mb_strtolower($powody), 'Nie prosimy autora o gramy (D-017).');
    }

    #[Test]
    public function test_bez_ilosci_z_formularza_jest_pomijany_jawnie(): void
    {
        $recipe = Recipe::factory()->create(['servings' => 2]);
        RecipeIngredient::create(['recipe_id' => $recipe->getKey(), 'ingredient_text' => '200 g mąki pszennej', 'position' => 0]);
        // „mleko, ile weźmie” z zaznaczonym „Bez ilości” (issue #44) — bez
        // flagi ten wiersz blokowałby wynik jako składnik bez masy.
        RecipeIngredient::create(['recipe_id' => $recipe->getKey(), 'ingredient_text' => 'mleko', 'no_amount' => true, 'quantity' => null, 'unit_id' => null, 'position' => 1]);

        $wynik = app(KalkulatorWartosci::class)->policz($recipe->fresh());

        $this->assertTrue($wynik->policzone());
        $this->assertSame(1, $wynik->ile(WierszWyliczenia::POMINIETY));
        $this->assertEqualsWithDelta(346.0, $wynik->kcal, 0.01);
    }

    #[Test]
    public function test_sol_i_pieprz_bez_ilosci_nie_blokuja_ale_nieznany_skladnik_bez_ilosci_blokuje(): void
    {
        $this->assertTrue($this->policz(['200 g mąki', 'sól, pieprz', 'natka pietruszki'], 1)->policzone());
        $this->assertFalse($this->policz(['200 g mąki', 'coś od sąsiadki'], 1)->policzone());
    }

    #[Test]
    public function test_miara_domowa_zalezy_od_skladnika(): void
    {
        $maka = $this->policz(['1 szklanka mąki pszennej'], 1)->wiersze[0]->gramy;
        $cukier = $this->policz(['1 szklanka cukru'], 1)->wiersze[0]->gramy;
        $olej = $this->policz(['100 ml oleju'], 1)->wiersze[0]->gramy;

        $this->assertSame(140.0, $maka);
        $this->assertSame(220.0, $cukier);
        $this->assertEqualsWithDelta(92.0, $olej, 0.001, 'Mililitry przez gęstość oleju 0,92 g/ml.');
    }

    #[Test]
    public function test_bez_liczby_porcji_wynik_jest_na_caly_przepis(): void
    {
        $wynik = $this->policz(['100 g cukru'], null);

        $this->assertFalse($wynik->naPorcje());
        $this->assertEqualsWithDelta(399.0, $wynik->kcal, 0.01);
    }

    #[Test]
    public function test_wartosci_na_porcje_nie_zaleza_od_skalowania_przepisu(): void
    {
        // Przepis razy 2 (skalowanie porcji, V2) = dwa razy więcej
        // wszystkiego i dwa razy więcej porcji. Na jedną porcję — to samo.
        $raz = $this->policz(['200 g mąki pszennej', '2 jajka', '1 szklanka mleka'], 2);
        $dwa = $this->policz(['400 g mąki pszennej', '4 jajka', '2 szklanki mleka'], 4);

        $this->assertEqualsWithDelta($raz->kcal, $dwa->kcal, 0.001);
        $this->assertEqualsWithDelta($raz->bialko, $dwa->bialko, 0.001);
    }

    #[Test]
    public function test_kolumny_strukturalne_maja_pierwszenstwo_przed_tekstem(): void
    {
        $this->seed(UnitSeeder::class);
        $recipe = Recipe::factory()->create(['servings' => 1]);
        RecipeIngredient::create([
            'recipe_id' => $recipe->getKey(),
            'ingredient_text' => 'mąka pszenna',
            'quantity' => 300,
            'unit_id' => Unit::where('code', 'g')->value('id'),
            'position' => 0,
        ]);

        $wynik = app(KalkulatorWartosci::class)->policz($recipe->fresh());

        $this->assertSame(300.0, $wynik->wiersze[0]->gramy);
    }

    #[Test]
    public function test_dopasowanie_nie_zgaduje_produktu_po_pierwszym_slowie(): void
    {
        $wynik = $this->policz(['200 ml mleka kokosowego', '100 g letniej wody', '50 g roztopionego masła', '2 łyżki soku z cytryny'], 1);
        $klucze = array_map(static fn (WierszWyliczenia $w): ?string => $w->klucz, $wynik->wiersze);

        $this->assertSame([null, 'woda', 'maslo', 'sok_z_cytryny'], $klucze, 'Mleko kokosowe to nie mleko; sok z cytryny to nie cytryna.');
    }

    /**
     * Przepisy z seedów (demo i zalążkowe) — raport pokrycia słownika jako
     * test: każdy zmieniony wynik trzeba świadomie przestawić w fiksturze.
     */
    #[Test]
    public function test_przepisy_z_seedow_licza_sie_tak_jak_zapisano(): void
    {
        foreach (require base_path('tests/Fixtures/Odzywcze/przepisy_z_seedow.php') as $przepis) {
            $wynik = $this->policz($przepis['skladniki'], $przepis['porcje']);
            $this->assertSame(
                $przepis['oczekiwane'],
                $wynik->policzone() ? 'policzone' : 'bez_liczb',
                "„{$przepis['tytul']}”: ".implode(' ', $wynik->powodyBrakuLiczb()),
            );
        }
    }

    /**
     * @param  list<string>  $skladniki
     */
    private function policz(array $skladniki, int|float|null $porcje): WynikWartosci
    {
        $recipe = new Recipe(['servings' => $porcje]);
        $recipe->setRelation('ingredients', collect(array_map(
            static fn (string $tekst): RecipeIngredient => (new RecipeIngredient(['ingredient_text' => $tekst, 'no_amount' => false]))->setRelation('unit', null),
            $skladniki,
        )));

        return app(KalkulatorWartosci::class)->policz($recipe);
    }
}
