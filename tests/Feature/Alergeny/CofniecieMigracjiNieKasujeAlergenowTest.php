<?php

declare(strict_types=1);

namespace Tests\Feature\Alergeny;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji alergenów nie kasuje po cichu decyzji autorów (#1902, D-088).
 *
 * `down()` + kolejny `migrate` wróciłby ze stanem „nie sprawdzono” — zaznaczenia
 * autorów znikłyby bez błędu. Odmowa jest WĄSKA: przy samych przepisach
 * niesprawdzonych i na świeżej bazie cofnięcie przechodzi (kontrole dodatnie).
 *
 * KONTROLA UJEMNA (ręcznie): usunięcie `throw` z `down()` oblewa
 * `test_cofniecie_odmawia_gdy_autor_oznaczyl_alergeny` komunikatem
 * „Cofnięcie migracji przeszło…”.
 */
final class CofniecieMigracjiNieKasujeAlergenowTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'database/migrations/2026_10_01_083000_add_allergens_to_recipes.php';

    public function test_cofniecie_odmawia_gdy_autor_oznaczyl_alergeny(): void
    {
        $przepis = $this->przepis();
        DB::table('recipes')->where('id', $przepis->getKey())->update([
            'allergen_status' => 'declared',
            'allergens' => '{milk}',
            'allergens_declared_at' => now(),
        ]);

        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA, '--realpath' => false]);
            $this->fail('Cofnięcie migracji przeszło, mimo że w bazie stoi oznaczenie alergenów wpisane przez autora.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba przepisów z oznaczeniem: 1.', $e->getMessage());
            $this->assertStringContainsString('CO ZROBIĆ ZAMIAST TEGO', $e->getMessage());
        }

        // Odmowa, która zdążyła zdjąć kolumny, byłaby tylko ładniejszym
        // komunikatem o tej samej utracie.
        $this->assertTrue(Schema::hasColumn('recipes', 'allergens'));
        $this->assertSame('declared', DB::table('recipes')->where('id', $przepis->getKey())->value('allergen_status'));
    }

    public function test_cofniecie_odmawia_takze_przy_stanie_do_przegladu(): void
    {
        $przepis = $this->przepis();
        DB::table('recipes')->where('id', $przepis->getKey())->update([
            'allergen_status' => 'needs_review',
            'allergens_declared_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA, '--realpath' => false]);
    }

    public function test_cofniecie_przechodzi_gdy_wszystkie_przepisy_sa_niesprawdzone(): void
    {
        $this->przepis();

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA, '--realpath' => false]);
        $this->assertFalse(Schema::hasColumn('recipes', 'allergen_status'));
        $this->assertFalse(Schema::hasColumn('recipes', 'allergens'));
        $this->assertFalse(Schema::hasColumn('recipes', 'allergens_declared_at'));

        Artisan::call('migrate', ['--path' => self::SCIEZKA, '--realpath' => false]);
        $this->assertTrue(Schema::hasColumn('recipes', 'allergen_status'));
    }

    public function test_cofniecie_przechodzi_na_swiezej_bazie(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA, '--realpath' => false]);
        $this->assertFalse(Schema::hasColumn('recipes', 'allergen_status'));

        Artisan::call('migrate', ['--path' => self::SCIEZKA, '--realpath' => false]);
        $this->assertTrue(Schema::hasColumn('recipes', 'allergen_status'));
    }

    public function test_jawna_zgoda_ze_srodowiska_odblokowuje_cofniecie(): void
    {
        $przepis = $this->przepis();
        DB::table('recipes')->where('id', $przepis->getKey())->update([
            'allergen_status' => 'declared',
            'allergens_declared_at' => now(),
        ]);

        putenv('KUKING_ROLLBACK_KASUJ_ALERGENY=true');

        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA, '--realpath' => false]);
            $this->assertFalse(Schema::hasColumn('recipes', 'allergen_status'), 'Jawna zgoda nie odblokowała cofnięcia.');
        } finally {
            putenv('KUKING_ROLLBACK_KASUJ_ALERGENY');
        }

        Artisan::call('migrate', ['--path' => self::SCIEZKA, '--realpath' => false]);
    }

    private function przepis(): Recipe
    {
        return Recipe::factory()->create(['author_id' => $this->user('autorka_cofniecia_alergenow')->getKey()]);
    }
}
