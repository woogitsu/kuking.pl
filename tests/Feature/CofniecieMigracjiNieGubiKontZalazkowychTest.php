<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\TrescZalazkowaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Rollback `users.is_seeded` nie zamienia person z treści zalążkowej
 * w „prawdziwych ludzi" (D-088, D-025, audyt B3 W4).
 *
 * Cykl `migrate:rollback` → `migrate` przywracał kolumnę z `DEFAULT false`
 * dla wszystkich: etykieta „przykładowe konto" znikała, a persony wchodziły
 * do WAC i „Liczby Kukingów". Ochroną było zdanie w `docs/DATABASE.md`.
 *
 * Test odmowy idzie przez prawdziwy `TrescZalazkowaSeeder` — jedyną drogę,
 * którą `is_seeded = true` trafia do bazy. Dwie kontrole dodatnie, bo
 * zablokowanie rollbacku na zawsze jest błędem tej samej wagi w drugą
 * stronę (AGENTS.md §6).
 */
class CofniecieMigracjiNieGubiKontZalazkowychTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA_MIGRACJI = 'database/migrations/2026_09_07_700000_add_is_seeded_to_users.php';

    public function test_cofniecie_odmawia_gdy_sa_konta_z_tresci_zalazkowej(): void
    {
        $this->seed(TrescZalazkowaSeeder::class);

        $zalazkowe = DB::table('users')->where('is_seeded', true)->count();
        $this->assertGreaterThan(0, $zalazkowe, 'Seeder nie oznaczył żadnego konta — test mierzyłby nie to.');

        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
            $this->fail('Cofnięcie przeszło, choć w bazie są konta z treści zalążkowej.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(
                'Liczba kont z treści zalążkowej (is_seeded = true): '.$zalazkowe.'.',
                $e->getMessage(),
            );
            $this->assertStringContainsString('CO ZROBIĆ', $e->getMessage());
        }

        // Najważniejsze: kolumna i etykiety są NADAL na miejscu.
        $this->assertSame(1, $this->iloscKolumn());
        $this->assertSame($zalazkowe, DB::table('users')->where('is_seeded', true)->count());
    }

    public function test_cofniecie_przechodzi_gdy_sa_tylko_zwykle_konta(): void
    {
        $this->user('zwyklakonto');

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertSame(0, $this->iloscKolumn(), 'Rollback nie przeszedł, choć nie ma kont z pliku.');

        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertSame(1, $this->iloscKolumn());
    }

    public function test_cofniecie_przechodzi_na_swiezej_bazie(): void
    {
        $this->assertSame(0, DB::table('users')->count());

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertSame(0, $this->iloscKolumn());

        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
    }

    private function iloscKolumn(): int
    {
        return count(DB::select(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'is_seeded'",
        ));
    }
}
