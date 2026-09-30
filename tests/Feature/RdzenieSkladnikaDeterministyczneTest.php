<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/**
 * Rdzenie składnika mają jawnie ustaloną kolejność (#2315).
 *
 * `kuking_rdzenie_skladnika()` składała tablicę `array_agg(DISTINCT …)` bez
 * `ORDER BY`, a na sklejonym z niej kluczu stoi `UNIQUE (user_id, klucz)`.
 * Dzisiejszy PostgreSQL i tak sortuje przy `DISTINCT` w agregacie, więc
 * permutacja wejścia nie odtwarza błędu na tej maszynie — dlatego test
 * pilnuje DEFINICJI funkcji w bazie (katalog `pg_proc`, nie plik źródłowy)
 * i osobno zachowania: permutacje słów dają ten sam klucz, a produkt
 * zapisany w innej kolejności słów odbija się od `UNIQUE`.
 *
 * @bez-kontroli-dodatniej Test czyta definicję funkcji z katalogu bazy po wykonaniu migracji, nie plik źródłowy; kontrolę ujemną niesie sam test (down() migracji przywraca starą definicję i asercja oblewa).
 */
class RdzenieSkladnikaDeterministyczneTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'database/migrations/2026_09_30_231500_deterministyczne_rdzenie_skladnika.php';

    private function definicja(): string
    {
        return (string) DB::scalar("SELECT pg_get_functiondef('public.kuking_rdzenie_skladnika(text)'::regprocedure)");
    }

    private function migracja(): object
    {
        return require base_path(self::SCIEZKA);
    }

    public function test_agregat_rdzeni_ma_jawny_porzadek_a_cofniecie_przywraca_stara_definicje(): void
    {
        $this->assertMatchesRegularExpression('/array_agg\(DISTINCT r ORDER BY r\)/', $this->definicja());

        // Kontrola ujemna na tej samej bazie: stara definicja nie ma porządku.
        $this->migracja()->down();
        $this->assertDoesNotMatchRegularExpression('/ORDER BY/', $this->definicja());

        $this->migracja()->up();
        $this->assertMatchesRegularExpression('/array_agg\(DISTINCT r ORDER BY r\)/', $this->definicja());
    }

    public function test_permutacje_slow_daja_ten_sam_klucz_i_odbijaja_sie_od_unikalnosci(): void
    {
        $klucze = array_map(
            fn (string $nazwa): string => (string) DB::scalar('SELECT public.kuking_klucz_skladnika(?)', [$nazwa]),
            ['mąka pszenna tortowa', 'tortowa pszenna mąka', 'pszenna MĄKA tortowa mąka'],
        );
        $this->assertCount(1, array_unique($klucze));
        $this->assertSame(
            (string) DB::scalar("SELECT array_to_string(ARRAY(SELECT unnest(public.kuking_rdzenie_skladnika('tortowa pszenna mąka')) ORDER BY 1), ' ')"),
            $klucze[0],
            'Klucz nie jest posortowanymi rdzeniami.',
        );

        $ja = $this->user();
        $ja->pantryItems()->create(['name' => 'mąka pszenna']);

        $this->expectException(UniqueConstraintViolationException::class);
        $ja->pantryItems()->create(['name' => 'pszenna mąka']);
    }

    public function test_przeliczenie_odmawia_zamiast_kasowac_gdy_wyszedlby_duplikat(): void
    {
        $ja = $this->user();
        // Stan, którego UNIQUE dziś nie wpuści: dwa produkty o tym samym
        // kluczu po przeliczeniu. Symulujemy go bez ograniczenia.
        DB::statement('ALTER TABLE pantry_items DROP CONSTRAINT pantry_items_user_klucz_unique');
        $ja->pantryItems()->create(['name' => 'mąka pszenna']);
        $ja->pantryItems()->create(['name' => 'pszenna mąka']);

        $odmowa = null;
        try {
            $this->migracja()->up();
        } catch (RuntimeException $e) {
            $odmowa = $e->getMessage();
        }

        $this->assertNotNull($odmowa, 'Migracja przeszła mimo duplikatu po przeliczeniu.');
        $this->assertStringContainsString('odmawia: 1 par', $odmowa);

        $this->assertSame(2, DB::table('pantry_items')->where('user_id', $ja->getKey())->count(), 'Migracja skasowała produkt człowieka.');
    }

    /**
     * #2346: odmowa migracji nie może zostawić nowej funkcji przy starych
     * kluczach. Uruchamiamy `up()` tak jak `php artisan migrate`: przez
     * Migrator, który opakowuje migrację w transakcję (brak
     * `$withinTransaction = false`). Stan wyjściowy to „stara kolejność”:
     * funkcja zależna od kolejności słów, więc dwie pozycje jednej osoby mają
     * RÓŻNE klucze, a po nowej funkcji byłyby takie same.
     */
    public function test_odmowa_przez_migrator_cofa_podmiane_funkcji_i_nie_rusza_kluczy(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.kuking_rdzenie_skladnika(text) RETURNS text[]
            AS $$
                SELECT COALESCE(array_agg(r ORDER BY ord), '{}')
                FROM (
                    SELECT s AS r, min(ord) AS ord
                    FROM regexp_split_to_table(
                        regexp_replace(public.kuking_normalize($1), '[^a-z0-9]+', ' ', 'g'), ' '
                    ) WITH ORDINALITY AS t(s, ord)
                    WHERE length(s) >= 2 AND s !~ '^[0-9]+$'
                    GROUP BY s
                ) AS x
            $$
            LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
            SQL);

        $ja = $this->user();
        $ja->pantryItems()->create(['name' => 'mąka pszenna']);
        $ja->pantryItems()->create(['name' => 'pszenna mąka']);

        $kluczePrzed = DB::table('pantry_items')->orderBy('name')->pluck('klucz', 'name')->all();
        $this->assertCount(2, array_unique($kluczePrzed), 'Stan wyjściowy nie rozróżnia kolejności słów.');
        $definicjaPrzed = $this->definicja();
        $this->assertDoesNotMatchRegularExpression('/DISTINCT r ORDER BY r/', $definicjaPrzed);

        // Kopia migracji w katalogu tymczasowym: Migrator zapisuje ją pod
        // własną nazwą, więc nie koliduje z wpisem prawdziwej migracji.
        $katalog = sys_get_temp_dir().'/kuking_migracja_2346_'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($katalog);
        File::copy(base_path(self::SCIEZKA), $katalog.'/2099_01_01_000000_kopia_rdzeni_2346.php');

        $odmowa = null;
        try {
            app(Migrator::class)->run([$katalog]);
        } catch (RuntimeException $e) {
            $odmowa = $e->getMessage();
        } finally {
            File::deleteDirectory($katalog);
        }

        $this->assertNotNull($odmowa, 'Migracja przeszła mimo kolizji kluczy.');
        $this->assertStringContainsString('odmawia: 1 par', $odmowa);

        $this->assertSame($definicjaPrzed, $this->definicja(), 'Odmowa zostawiła nową funkcję rdzeni (stan mieszany).');
        $this->assertSame($kluczePrzed, DB::table('pantry_items')->orderBy('name')->pluck('klucz', 'name')->all(), 'Odmowa zmieniła klucze.');
    }
}
