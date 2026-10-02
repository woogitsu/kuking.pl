<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Kolumny `recipes.yield_count` i `recipes.yield_unit` i ich rollback (#2645, D-088).
 *
 * Cofnięcie migracji kasuje liczbę sztuk wpisaną przez autora, a kolejny
 * `migrate` odtwarza kolumny puste — bez śladu. Dlatego `down()` odmawia, gdy
 * choć jeden przepis ma liczbę sztuk, i przechodzi na samych `NULL`-ach oraz
 * na świeżej bazie (kontrole dodatnie, `PULAPKI_TESTOW.md` #4).
 *
 * @bez-kontroli-dodatniej Test wykonuje down() migracji na prawdziwej bazie i sam niesie obie strony pomiaru (odmowa przy danych, przejście bez nich), nie asertuje na treści źródła.
 */
class GotoweSztukiMigracjaTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'database/migrations/2026_10_02_200000_add_yield_to_recipes.php';

    protected function tearDown(): void
    {
        putenv('KUKING_ROLLBACK_KASUJE_LICZBE_SZTUK');
        parent::tearDown();
    }

    #[Test]
    public function kolumny_sa_opcjonalne_a_ograniczenia_zwalidowane(): void
    {
        foreach (['yield_count' => 'integer', 'yield_unit' => 'character varying'] as $kolumna => $typ) {
            $opis = DB::selectOne(
                'SELECT data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
                ['recipes', $kolumna],
            );

            $this->assertNotNull($opis, "Brak kolumny {$kolumna}.");
            $this->assertSame($typ, $opis->data_type);
            $this->assertSame('YES', $opis->is_nullable);
            $this->assertNull($opis->column_default, 'Brak danych to NULL, nie wartość domyślna.');
        }

        foreach (['recipes_yield_count_check', 'recipes_yield_unit_check'] as $nazwa) {
            $check = DB::selectOne('SELECT convalidated FROM pg_constraint WHERE conname = ?', [$nazwa]);
            $this->assertNotNull($check, "Brak ograniczenia {$nazwa}.");
            $this->assertTrue((bool) $check->convalidated, "{$nazwa} został NOT VALID — zabrakło VALIDATE.");
        }
    }

    #[Test]
    public function cofniecie_odmawia_gdy_autor_podal_liczbe_sztuk(): void
    {
        $przepis = Recipe::factory()->create(['yield_count' => 24, 'yield_unit' => 'pierogi']);

        try {
            $this->migracja()->down();
            $this->fail('Cofnięcie przeszło, mimo że w bazie jest liczba sztuk wpisana przez autora.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_LICZBE_SZTUK=1', $e->getMessage());
            $this->assertStringContainsString('Liczba przepisów, którym zniknie ta informacja: 1.', $e->getMessage());
        }

        // Odmowa nie zdążyła niczego zdjąć.
        $this->assertTrue(Schema::hasColumn('recipes', 'yield_count'));
        $this->assertSame(24, $przepis->fresh()->yield_count);
    }

    #[Test]
    public function cofniecie_przechodzi_gdy_nikt_nie_podal_sztuk_i_up_je_przywraca(): void
    {
        Recipe::factory()->create(['yield_count' => null]);

        $this->migracja()->down();
        $this->assertFalse(Schema::hasColumn('recipes', 'yield_count'), 'Rollback nie przeszedł, choć żaden przepis nie ma sztuk.');
        $this->assertFalse(Schema::hasColumn('recipes', 'yield_unit'));

        $this->migracja()->up();
        $this->assertTrue(Schema::hasColumn('recipes', 'yield_count'));
        $this->assertTrue(Schema::hasColumn('recipes', 'yield_unit'));
    }

    #[Test]
    public function cofniecie_przechodzi_ze_swiadomym_wymuszeniem(): void
    {
        Recipe::factory()->create(['yield_count' => 24]);
        putenv('KUKING_ROLLBACK_KASUJE_LICZBE_SZTUK=1');

        $this->migracja()->down();

        $this->assertFalse(Schema::hasColumn('recipes', 'yield_count'));
    }

    private function migracja(): object
    {
        return require base_path(self::SCIEZKA);
    }
}
