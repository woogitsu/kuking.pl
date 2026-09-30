<?php

declare(strict_types=1);

namespace Tests\Feature\Alergeny;

use App\Domain\Recipes\Alergeny\Alergen;
use App\Models\Recipe;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Oznaczenie alergenów (#1902): prawdziwe CHECK-i w bazie, nie tylko walidacja w PHP
 * (AGENTS.md §6).
 *
 * KONTROLA UJEMNA (ręcznie, opisana w raporcie): usunięcie jednego z czterech
 * `ADD CONSTRAINT` z migracji `2026_10_01_083000_add_allergens_to_recipes.php` oblewa
 * odpowiadający mu test „baza odrzuca…”; zmiana kodu w enumie `Alergen` oblewa
 * `test_lista_w_enumie_jest_ta_sama_co_w_checku_bazy`.
 */
final class AlergenyOgraniczeniaBazyTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(): Recipe
    {
        return Recipe::factory()->create(['author_id' => $this->user('autor_alergenow')->getKey()]);
    }

    /** @param array<string, mixed> $kolumny */
    private function ustaw(Recipe $przepis, array $kolumny): void
    {
        DB::table('recipes')->where('id', $przepis->getKey())->update($kolumny);
    }

    public function test_nowy_przepis_jest_niesprawdzony_z_pusta_lista(): void
    {
        $przepis = $this->przepis()->refresh();

        $this->assertSame('unchecked', $przepis->allergen_status);
        $this->assertSame([], $przepis->allergens);
        $this->assertNull($przepis->allergens_declared_at);
        $this->assertFalse($przepis->alergenyZdeklarowane());
    }

    public function test_baza_odrzuca_nieznany_stan(): void
    {
        $przepis = $this->przepis();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('recipes_allergen_status_check');

        $this->ustaw($przepis, ['allergen_status' => 'safe', 'allergens_declared_at' => now()]);
    }

    public function test_baza_odrzuca_nieznany_kod_alergenu(): void
    {
        $przepis = $this->przepis();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('recipes_allergens_closed_list_check');

        $this->ustaw($przepis, [
            'allergen_status' => 'declared',
            'allergens' => '{gluten,banany}',
            'allergens_declared_at' => now(),
        ]);
    }

    public function test_baza_odrzuca_liste_alergenow_przy_stanie_niesprawdzonym(): void
    {
        $przepis = $this->przepis();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('recipes_allergens_only_when_declared_check');

        $this->ustaw($przepis, ['allergens' => '{milk}']);
    }

    public function test_baza_odrzuca_deklaracje_bez_daty_potwierdzenia(): void
    {
        $przepis = $this->przepis();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('recipes_allergens_declared_at_check');

        $this->ustaw($przepis, ['allergen_status' => 'declared', 'allergens' => '{milk}']);
    }

    public function test_baza_odrzuca_date_potwierdzenia_przy_stanie_niesprawdzonym(): void
    {
        $przepis = $this->przepis();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('recipes_allergens_declared_at_check');

        $this->ustaw($przepis, ['allergens_declared_at' => now()]);
    }

    public function test_kontrola_dodatnia_poprawna_deklaracja_przechodzi_takze_z_pusta_lista(): void
    {
        $przepis = $this->przepis();

        $this->ustaw($przepis, [
            'allergen_status' => 'declared',
            'allergens' => '{gluten,milk}',
            'allergens_declared_at' => now(),
        ]);
        $this->assertSame(['gluten', 'milk'], $przepis->refresh()->allergens);

        $this->ustaw($przepis, ['allergens' => '{}']);
        $this->assertSame([], $przepis->refresh()->allergens);
        $this->assertTrue($przepis->alergenyZdeklarowane());

        $this->ustaw($przepis, ['allergen_status' => 'needs_review']);
        $this->assertSame('needs_review', $przepis->refresh()->allergen_status);
    }

    public function test_lista_w_enumie_jest_ta_sama_co_w_checku_bazy(): void
    {
        $definicja = (string) DB::selectOne(
            "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'recipes_allergens_closed_list_check' AND contype = 'c'",
        )?->def;

        $this->assertNotSame('', $definicja, 'Brak CHECK-a z zamkniętą listą alergenów.');

        preg_match_all("/'([a-z_]+)'::text/", $definicja, $m);
        $wBazie = $m[1];

        $this->assertCount(14, Alergen::cases(), 'Załącznik II rozporządzenia 1169/2011 ma 14 pozycji.');
        $this->assertSame(Alergen::kody(), $wBazie, 'Lista kodów w enumie Alergen rozjechała się z listą w CHECK-u.');
    }

    public function test_kazdy_alergen_ma_polska_nazwe_i_etykiete(): void
    {
        foreach (Alergen::cases() as $alergen) {
            $this->assertNotSame('', $alergen->nazwa());
            $this->assertNotSame('', $alergen->etykieta());
        }
    }

    public function test_normalizacja_odrzuca_nieznane_usuwa_powtorzenia_i_sortuje_wg_slownika(): void
    {
        $this->assertSame(['gluten', 'eggs', 'milk'], Alergen::znormalizuj(['milk', 'banany', 'gluten', 'milk', 'eggs', 7]));
        $this->assertSame(3, Alergen::nieznane(['milk', 'banany', 7, 'gluten', '']));
        $this->assertSame('gluten, jaja, mleko', Alergen::nazwyZKodow(['milk', 'eggs', 'gluten']));
    }
}
