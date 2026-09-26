<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Kolumna `recipes.estimated_cost_pln` i jej rollback (D-286, D-088).
 *
 * Cofnięcie migracji kasuje kwotę wpisaną przez autora, a kolejny `migrate`
 * odtwarza kolumnę pustą — bez śladu błędu. Dlatego `down()` odmawia, gdy
 * choć jeden przepis ma koszt, i przechodzi bez pytania na samych `NULL`-ach
 * oraz na świeżej bazie (kontrole dodatnie, `PULAPKI_TESTOW.md` #4).
 */
class KosztPrzepisuMigracjaTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'database/migrations/2026_09_26_100000_add_estimated_cost_pln_to_recipes.php';

    #[Test]
    public function kolumna_ma_typ_numeric_6_2_i_check_na_nieujemnosc(): void
    {
        $kolumna = DB::selectOne(
            "SELECT data_type, numeric_precision, numeric_scale, is_nullable, column_default
             FROM information_schema.columns WHERE table_name = 'recipes' AND column_name = 'estimated_cost_pln'",
        );

        $this->assertNotNull($kolumna);
        $this->assertSame('numeric', $kolumna->data_type);
        $this->assertSame(6, (int) $kolumna->numeric_precision);
        $this->assertSame(2, (int) $kolumna->numeric_scale);
        $this->assertSame('YES', $kolumna->is_nullable);
        $this->assertNull($kolumna->column_default, 'Zero złotych to odpowiedź — kolumna nie może mieć DEFAULT.');

        $check = DB::selectOne(
            "SELECT convalidated FROM pg_constraint WHERE conname = 'recipes_estimated_cost_pln_check'",
        );
        $this->assertNotNull($check);
        $this->assertTrue((bool) $check->convalidated, 'CHECK został NOT VALID — zabrakło VALIDATE.');
    }

    #[Test]
    public function cofniecie_odmawia_gdy_autor_wpisal_koszt(): void
    {
        $przepis = Recipe::factory()->create(['estimated_cost_pln' => 24.5]);

        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA, '--realpath' => false]);
            $this->fail('Cofnięcie przeszło, mimo że w bazie jest koszt wpisany przez autora.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba przepisów z kosztem wpisanym przez autora (estimated_cost_pln IS NOT NULL): 1.', $e->getMessage());
            $this->assertStringContainsString('CO ZROBIĆ', $e->getMessage());
        }

        // Odmowa nie zdążyła niczego zdjąć.
        $this->assertSame(1, $this->iloscKolumn());
        $this->assertSame(24.5, $przepis->fresh()->estimated_cost_pln);
    }

    #[Test]
    public function cofniecie_przechodzi_gdy_nikt_nie_wpisal_kosztu(): void
    {
        Recipe::factory()->create(['estimated_cost_pln' => null]);

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA, '--realpath' => false]);
        $this->assertSame(0, $this->iloscKolumn(), 'Rollback nie przeszedł, choć żaden przepis nie ma kosztu.');

        Artisan::call('migrate', ['--path' => self::SCIEZKA, '--realpath' => false]);
        $this->assertSame(1, $this->iloscKolumn());
    }

    #[Test]
    public function cofniecie_przechodzi_na_swiezej_bazie(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA, '--realpath' => false]);
        $this->assertSame(0, $this->iloscKolumn());

        Artisan::call('migrate', ['--path' => self::SCIEZKA, '--realpath' => false]);
        $this->assertSame(1, $this->iloscKolumn());
    }

    private function iloscKolumn(): int
    {
        return count(DB::select(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'recipes' AND column_name = 'estimated_cost_pln'",
        ));
    }
}
