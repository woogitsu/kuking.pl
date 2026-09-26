<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Import\Actions\ZapiszSzkicZImportu;
use App\Domain\Import\OdczytanyPrzepis;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\PrzepisZImportu;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * `down()` migracji `przepisy_z_importu` ODMAWIA, gdy istnieje niesprawdzony
 * szkic z importu (D-088, D-300) — inaczej po cofnięciu i ponownym `migrate`
 * dałoby się go opublikować bez „Sprawdziłem odczytany tekst". Odmowa jest
 * wąska: przy samych sprawdzonych wierszach i na pustej tabeli przechodzi.
 */
final class CofniecieMigracjiImportuTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): Migration
    {
        return require base_path('database/migrations/2026_09_26_100000_create_przepisy_z_importu_table.php');
    }

    private function szkic(): string
    {
        return (string) app(ZapiszSzkicZImportu::class)->handle(
            $this->user(),
            PrzepisZImportu::ZRODLO_URL,
            'json_ld',
            new OdczytanyPrzepis('Bigos', kroki: ['Duś trzy godziny.']),
            'https://przepisy.example.pl/bigos',
        )->getKey();
    }

    public function test_cofniecie_odmawia_przy_niesprawdzonym_szkicu_i_niczego_nie_zdejmuje(): void
    {
        $this->szkic();

        try {
            $this->migracja()->down();
            $this->fail('Cofnięcie przeszło mimo niesprawdzonego szkicu z importu.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba: 1.', $e->getMessage());
            $this->assertStringContainsString('CO ZROBIĆ', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('przepisy_z_importu'));
        $this->assertSame(1, PrzepisZImportu::query()->count());
    }

    public function test_kontrola_dodatnia_sprawdzone_szkice_nie_blokuja_cofniecia(): void
    {
        $id = $this->szkic();
        PrzepisZImportu::query()->whereKey($id)->update(['sprawdzone_at' => now()]);

        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('przepisy_z_importu'));

        $this->migracja()->up();
        $this->assertTrue(Schema::hasTable('przepisy_z_importu'));
    }

    public function test_baza_wymusza_adres_zrodla_dla_importu_z_adresu(): void
    {
        $this->expectException(QueryException::class);

        $user = $this->user();
        $recipe = app(PublishRecipe::class)->handle($user, ['title' => 'Kompot', 'visibility' => 'private']);

        (new PrzepisZImportu)->forceFill([
            'recipe_id' => $recipe->getKey(),
            'user_id' => $user->getKey(),
            'zrodlo' => 'url',
            'droga' => 'json_ld',
            'source_url' => null,
        ])->save();
    }
}
