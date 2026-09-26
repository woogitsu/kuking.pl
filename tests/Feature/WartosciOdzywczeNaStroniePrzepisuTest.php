<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Odzywcze\ImportujWartosciOdzywcze;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sekcja „Szacunkowe wartości odżywcze” na stronie przepisu (D-299, I-10).
 */
final class WartosciOdzywczeNaStroniePrzepisuTest extends TestCase
{
    use RefreshDatabase;

    /** Słowa, których sekcja nie ma prawa użyć (projekt §8.3, rozp. WE 1924/2006). */
    private const ZAKAZANE = ['zdrow', 'dietetyczn', 'lekkie', 'fit ', 'dla cukrzyk', 'niski ig', 'odchudz', 'mało kalorii', 'niskokaloryczn'];

    protected function setUp(): void
    {
        parent::setUp();
        app(ImportujWartosciOdzywcze::class)->handle();
    }

    #[Test]
    public function test_przepis_z_pelnym_pokryciem_pokazuje_szacunek_na_porcje(): void
    {
        $recipe = $this->przepis(['200 g mąki pszennej', '2 jajka', '1 szklanka mleka', 'szczypta soli'], 2);

        $html = $this->get(route('recipes.show', $recipe))->assertOk()->getContent();
        $sekcja = $this->sekcja((string) $html);

        $this->assertStringContainsString('Szacunkowe wartości odżywcze (na porcję)', $sekcja);
        $this->assertStringContainsString('ok. 480 kcal', $sekcja);
        $this->assertStringContainsString('ok. 21 g', $sekcja);
        $this->assertStringContainsString('ok. 8 g', $sekcja);
        $this->assertStringContainsString('ok. 78 g', $sekcja);
        $this->assertStringContainsString('Szacunek na podstawie tabel CIQUAL/USDA.', $sekcja);
        $this->assertStringContainsString('Jak to liczymy', $sekcja);
        $this->assertStringContainsString('Etalab', $sekcja, 'Licencja CIQUAL wymaga podania źródła.');
    }

    #[Test]
    public function test_male_pokrycie_daje_komunikat_bez_zadnej_liczby(): void
    {
        $recipe = $this->przepis(['4 ziemniaki', '1 jajko', 'olej do smażenia'], 3);

        $sekcja = $this->sekcja((string) $this->get(route('recipes.show', $recipe))->assertOk()->getContent());

        $this->assertStringContainsString('Nie liczymy wartości odżywczych tego przepisu.', $sekcja);
        $this->assertStringContainsString('olej do smażenia', $sekcja);
        $this->assertDoesNotMatchRegularExpression('/ok\. \d+ kcal/', $sekcja);
        $this->assertStringNotContainsString('<dl', $sekcja);
        $this->assertStringNotContainsString('Szacunek na podstawie', $sekcja);
    }

    #[Test]
    public function test_sekcja_nie_uzywa_slow_o_zdrowiu_ani_diecie(): void
    {
        $policzony = $this->przepis(['200 g mąki pszennej', '2 jajka'], 2);
        $bezLiczb = $this->przepis(['200 g mąki', 'coś od sąsiadki'], 2);

        foreach ([$policzony, $bezLiczb] as $recipe) {
            $sekcja = mb_strtolower($this->sekcja((string) $this->actingAs($recipe->author)->get(route('recipes.show', $recipe))->getContent()));
            foreach (self::ZAKAZANE as $slowo) {
                $this->assertStringNotContainsString($slowo, $sekcja, "Sekcja użyła słowa „{$slowo}”.");
            }
        }
    }

    #[Test]
    public function test_przepis_bez_skladnikow_nie_ma_sekcji(): void
    {
        $recipe = Recipe::factory()->create();

        $this->get(route('recipes.show', $recipe))->assertOk()->assertDontSee('id="wartosci-odzywcze"', false);
    }

    #[Test]
    public function test_autor_ukrywa_sekcje_a_inni_jej_wtedy_nie_widza(): void
    {
        $recipe = $this->przepis(['200 g mąki pszennej', '2 jajka'], 2);
        $gosc = $this->user('gosc');

        $this->actingAs($gosc)->get(route('recipes.show', $recipe))->assertSee('Szacunkowe wartości odżywcze')
            ->assertDontSee('Ukryj tę sekcję w moim przepisie');

        $this->actingAs($recipe->author)->get(route('recipes.show', $recipe))->assertSee('Ukryj tę sekcję w moim przepisie');
        $this->actingAs($recipe->author)
            ->patch(route('recipes.wartosci-odzywcze', $recipe), ['pokazuj' => '0'])
            ->assertRedirect(route('recipes.show', $recipe).'#wartosci-odzywcze')
            ->assertSessionHas('status');

        $this->assertFalse((bool) $recipe->fresh()->pokazuj_wartosci_odzywcze);

        $this->actingAs($gosc)->get(route('recipes.show', $recipe))
            ->assertDontSee('Szacunkowe wartości odżywcze')
            ->assertDontSee('kcal');
        auth()->logout();
        $this->get(route('recipes.show', $recipe))->assertDontSee('Szacunkowe wartości odżywcze');

        $this->actingAs($recipe->author)->get(route('recipes.show', $recipe))
            ->assertSee('Ta sekcja jest ukryta.')
            ->assertSee('Pokaż wartości odżywcze')
            ->assertDontSee('kcal');

        $this->actingAs($recipe->author)->patch(route('recipes.wartosci-odzywcze', $recipe), ['pokazuj' => '1']);
        $this->assertTrue((bool) $recipe->fresh()->pokazuj_wartosci_odzywcze);
    }

    #[Test]
    public function test_domyslnie_sekcja_jest_widoczna(): void
    {
        $recipe = $this->przepis(['200 g mąki pszennej'], 1);

        $this->assertTrue((bool) $recipe->fresh()->pokazuj_wartosci_odzywcze);
    }

    #[Test]
    public function test_ukrycie_nie_zmienia_daty_edycji_przepisu(): void
    {
        $recipe = $this->przepis(['200 g mąki pszennej'], 1);
        $przed = $recipe->fresh()->updated_at;
        $this->travel(2)->days();

        $this->actingAs($recipe->author)->patch(route('recipes.wartosci-odzywcze', $recipe), ['pokazuj' => '0']);

        $this->assertEquals($przed, $recipe->fresh()->updated_at);
    }

    #[Test]
    public function test_cudzy_przepis_nie_da_sie_przelaczyc(): void
    {
        $recipe = $this->przepis(['200 g mąki pszennej'], 1);

        $this->actingAs($this->user('obcy'))
            ->patch(route('recipes.wartosci-odzywcze', $recipe), ['pokazuj' => '0'])
            ->assertForbidden();

        $this->assertTrue((bool) $recipe->fresh()->pokazuj_wartosci_odzywcze);
    }

    #[Test]
    public function test_gosc_jest_odsylany_do_logowania(): void
    {
        $recipe = $this->przepis(['200 g mąki pszennej'], 1);

        $this->patch(route('recipes.wartosci-odzywcze', $recipe), ['pokazuj' => '0'])->assertRedirect(route('login'));
        $this->assertTrue((bool) $recipe->fresh()->pokazuj_wartosci_odzywcze);
    }

    #[Test]
    public function test_bledna_wartosc_konczy_sie_komunikatem_po_polsku(): void
    {
        $recipe = $this->przepis(['200 g mąki pszennej'], 1);

        $this->actingAs($recipe->author)
            ->from(route('recipes.show', $recipe))
            ->patch(route('recipes.wartosci-odzywcze', $recipe), ['pokazuj' => 'może'])
            ->assertSessionHasErrors(['pokazuj' => 'Nie udało się zapisać wyboru. Odśwież stronę i naciśnij przycisk jeszcze raz.']);
    }

    /**
     * @param  list<string>  $skladniki
     */
    private function przepis(array $skladniki, int $porcje): Recipe
    {
        $recipe = Recipe::factory()->create(['servings' => $porcje, 'author_id' => User::factory()]);
        foreach ($skladniki as $i => $tekst) {
            RecipeIngredient::create(['recipe_id' => $recipe->getKey(), 'ingredient_text' => $tekst, 'position' => $i]);
        }

        return $recipe;
    }

    private function sekcja(string $html): string
    {
        $this->assertSame(1, preg_match('/<section class="wartosci-odzywcze".*?<\/section>/s', $html, $m), 'Na stronie nie ma sekcji wartości odżywczych.');

        return $m[0];
    }
}
