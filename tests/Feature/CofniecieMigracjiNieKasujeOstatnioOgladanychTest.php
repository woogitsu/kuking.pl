<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\OstatnioOgladane;
use App\Models\RecentRecipeView;
use App\Models\Recipe;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji `recent_recipe_views` nie kasuje po cichu czyichś list
 * ostatnio oglądanych przepisów (#2553, AGENTS.md §6 i D-088). Odmowa jest
 * WĄSKA: na pustej tabeli cofnięcie przechodzi (kontrola dodatnia), przy
 * zapisanej wizycie odmawia i mówi, co zrobić; zmienna środowiskowa pozwala
 * świadomie przejść dalej. Dochodzi dowód, że funkcja jest domyślnie WYŁĄCZONA
 * (kolumna zgody bez wartości domyślnej) i że baza pilnuje jednej pozycji na
 * parę (osoba, przepis).
 *
 * @bez-kontroli-dodatniej Test wykonuje down() migracji na prawdziwej bazie i sam niesie obie strony pomiaru (odmowa przy danych, przejście na pustej tabeli), nie asertuje na treści źródła.
 */
class CofniecieMigracjiNieKasujeOstatnioOgladanychTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'database/migrations/2026_10_03_190000_create_recent_recipe_views.php';

    protected function tearDown(): void
    {
        putenv('KUKING_ROLLBACK_KASUJE_OSTATNIO_OGLADANE');
        parent::tearDown();
    }

    public function test_odmawia_gdy_ktos_ma_zapisana_wizyte(): void
    {
        $this->zapiszWizyte();

        try {
            $this->migracja()->down();
            $this->fail('Cofnięcie przeszło, choć skasowałoby czyjąś listę ostatnio oglądanych.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba zapisanych wizyt, które znikną: 1.', $e->getMessage());
            $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_OSTATNIO_OGLADANE=1', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('recent_recipe_views'));
        $this->assertTrue(Schema::hasColumn('users', 'ostatnio_ogladane_wlaczone_at'));
        $this->assertSame(1, RecentRecipeView::query()->count());
    }

    public function test_przechodzi_na_pustej_tabeli_i_zdejmuje_kolumne_zgody(): void
    {
        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('recent_recipe_views'));
        $this->assertFalse(Schema::hasColumn('users', 'ostatnio_ogladane_wlaczone_at'));
    }

    public function test_przechodzi_ze_swiadomym_wymuszeniem(): void
    {
        $this->zapiszWizyte();
        putenv('KUKING_ROLLBACK_KASUJE_OSTATNIO_OGLADANE=1');

        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('recent_recipe_views'));
    }

    public function test_nowe_i_istniejace_konta_maja_funkcje_wylaczona(): void
    {
        $this->assertFalse($this->user()->maWlaczoneOstatnioOgladane());
        $this->assertNull($this->user()->ostatnio_ogladane_wlaczone_at);
    }

    public function test_baza_odrzuca_druga_pozycje_dla_tej_samej_pary(): void
    {
        $this->expectException(QueryException::class);

        $osoba = $this->user();
        $przepis = Recipe::factory()->create(['author_id' => $this->user()->getKey()]);

        foreach ([1, 2] as $_) {
            RecentRecipeView::query()->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $osoba->getKey(),
                'recipe_id' => $przepis->getKey(),
                'viewed_at' => now(),
            ]);
        }
    }

    private function zapiszWizyte(): void
    {
        $osoba = $this->user();
        $przepis = Recipe::factory()->create(['author_id' => $this->user()->getKey()]);

        app(OstatnioOgladane::class)->wlacz($osoba);
        app(OstatnioOgladane::class)->zapisz($osoba->fresh(), $przepis);
    }

    private function migracja(): object
    {
        return require base_path(self::SCIEZKA);
    }
}
