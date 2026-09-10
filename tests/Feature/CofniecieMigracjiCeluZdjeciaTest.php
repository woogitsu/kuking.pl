<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Report;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * CHECK NA `reports.target_type` I JEGO COFNIĘCIE (issue #237).
 *
 * DWIE RZECZY, KTÓRE MUSZĄ BYĆ PRAWDZIWE NARAZ:
 *
 *  1. baza naprawdę przyjmuje `media` — bez tego cała funkcja wywala się
 *     dopiero na produkcji, przy pierwszym oznaczonym awatarze, w zadaniu
 *     z kolejki, którego nikt nie obserwuje;
 *  2. cofnięcie migracji **odmawia**, gdy takie oznaczenia w tabeli leżą.
 *     To są sprawy moderacyjne z decyzjami i odwołaniami (DSA art. 17), więc
 *     rollback schematu nie może ich po cichu odrzucić ani skasować.
 */
class CofniecieMigracjiCeluZdjeciaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_baza_przyjmuje_zdjecie_jako_cel_oznaczenia(): void
    {
        $zdjecie = $this->zdjecie();

        $oznaczenie = Report::create([
            'reporter_id' => null,
            'autor_tresci_id' => $zdjecie->owner_id,
            'source' => Report::SOURCE_AUTOMAT,
            'target_type' => 'media',
            'target_id' => $zdjecie->getKey(),
            'reason' => 'automat_model',
            'details' => 'Zdjęcie profilowe: treść seksualna (88%).',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->assertSame('media', $oznaczenie->refresh()->target_type);
    }

    #[Test]
    public function test_typ_spoza_listy_nadal_odbija_sie_o_check(): void
    {
        // Asercja kontrolna: gdyby migracja zdjęła CHECK zamiast go poszerzyć,
        // test wyżej przechodziłby, a baza przyjmowałaby cokolwiek.
        $this->expectException(QueryException::class);

        DB::table('reports')->insert([
            'id' => (string) Str::uuid(),
            'source' => Report::SOURCE_AUTOMAT,
            'target_type' => 'zdjecie-w-tle',
            'target_id' => (string) Str::uuid(),
            'reason' => 'automat_model',
            'status' => Report::STATUS_OPEN,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function test_cofniecie_odmawia_gdy_leza_oznaczenia_zdjec(): void
    {
        $zdjecie = $this->zdjecie();

        Report::create([
            'reporter_id' => null,
            'autor_tresci_id' => $zdjecie->owner_id,
            'source' => Report::SOURCE_AUTOMAT,
            'target_type' => 'media',
            'target_id' => $zdjecie->getKey(),
            'reason' => 'automat_model',
            'details' => 'Zdjęcie profilowe: mowa nienawiści (93%).',
            'status' => Report::STATUS_OPEN,
        ]);

        try {
            // Po ŚCIEŻCE, nie `--step=1`: gdyby ktoś dopisał później nowszą
            // migrację, „ostatnia" przestałaby być tą sprawdzaną i test
            // cofałby coś innego, przechodząc albo oblewając z przypadku.
            Artisan::call('migrate:rollback', [
                '--path' => 'database/migrations/2026_09_10_300000_zdjecie_jako_cel_oznaczenia.php',
                '--realpath' => false,
            ]);
            $this->fail('Cofnięcie migracji przeszło, mimo że w tabeli leży oznaczenie zdjęcia.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('oznaczeń zdjęć', $e->getMessage());
        }

        // Sprawa musi zostać nietknięta — także wtedy, gdy rollback odmówił.
        $this->assertSame(1, Report::query()->where('target_type', 'media')->count());
    }

    private function zdjecie(): Media
    {
        $osoba = $this->user('zdjeciowa');

        return Media::factory()->create([
            'owner_id' => $osoba->getKey(),
            'status' => Media::STATUS_READY,
        ]);
    }
}
