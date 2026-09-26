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
 * Cofnięcie `recipes.pokazuj_wartosci_odzywcze` nie odkrywa sekcji, którą
 * autor świadomie ukrył (D-088, D-299). Kontrola dodatnia: przy samych
 * wartościach domyślnych cofnięcie przechodzi.
 */
final class CofniecieMigracjiNiePokazujeUkrytychWartosciTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'database/migrations/2026_09_26_400100_add_pokazuj_wartosci_odzywcze_to_recipes.php';

    #[Test]
    public function test_cofniecie_odmawia_gdy_autor_ukryl_sekcje(): void
    {
        $recipe = Recipe::factory()->create();
        $this->actingAs($recipe->author)->patch(route('recipes.wartosci-odzywcze', $recipe), ['pokazuj' => '0']);
        $this->assertFalse((bool) $recipe->fresh()->pokazuj_wartosci_odzywcze);

        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA, '--realpath' => false]);
            $this->fail('Cofnięcie przeszło mimo ukrytej sekcji — kolejny migrate odkryłby ją z DEFAULT true.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(pokazuj_wartosci_odzywcze = false): 1.', $e->getMessage());
            $this->assertStringContainsString('SELECT id FROM recipes WHERE pokazuj_wartosci_odzywcze = false', $e->getMessage());
        }

        $this->assertSame(1, $this->kolumna());
    }

    #[Test]
    public function test_cofniecie_przechodzi_przy_wartosciach_domyslnych(): void
    {
        Recipe::factory()->create();

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA, '--realpath' => false]);
        $this->assertSame(0, $this->kolumna());

        Artisan::call('migrate', ['--path' => self::SCIEZKA, '--realpath' => false]);
        $this->assertSame(1, $this->kolumna());
    }

    private function kolumna(): int
    {
        return (int) DB::scalar("SELECT count(*) FROM information_schema.columns WHERE table_name = 'recipes' AND column_name = 'pokazuj_wartosci_odzywcze'");
    }
}
