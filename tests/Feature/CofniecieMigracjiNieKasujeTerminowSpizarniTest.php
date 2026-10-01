<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WpisZgody;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji terminów przy produktach i zgody na sobotnie
 * przypomnienie nie kasuje po cichu niczego, co wpisał człowiek (#1903,
 * AGENTS.md §6 i D-088). Odmowa jest WĄSKA: na pustej bazie cofnięcie
 * przechodzi (kontrola dodatnia), z danymi — odmawia i mówi, co zrobić.
 *
 * @bez-kontroli-dodatniej Test wykonuje down() migracji na prawdziwej bazie i sam niesie obie strony pomiaru (odmowa przy danych, przejście na pustej bazie), nie asertuje na treści źródła.
 */
class CofniecieMigracjiNieKasujeTerminowSpizarniTest extends TestCase
{
    use RefreshDatabase;

    private const TERMINY = 'database/migrations/2026_10_01_101500_add_expiry_to_pantry_items.php';

    private const ZGODA = 'database/migrations/2026_10_01_102000_add_pantry_reminder_consent_to_users.php';

    private const SYGNALY = 'database/migrations/2026_10_01_103000_add_pantry_product_signals.php';

    protected function tearDown(): void
    {
        putenv('KUKING_ROLLBACK_KASUJE_TERMINY_SPIZARNI');
        parent::tearDown();
    }

    public function test_terminy_odmawia_gdy_jest_termin_ilosc_albo_mrozone(): void
    {
        foreach ([
            ['expires_on' => '2026-10-13', 'expiry_kind' => 'use_by'],
            ['quantity_note' => 'pół kostki'],
            ['frozen' => true],
        ] as $i => $dane) {
            $produkt = $this->user('osoba'.$i)->pantryItems()->create(['name' => 'mleko']);
            DB::table('pantry_items')->where('id', $produkt->getKey())->update($dane);

            try {
                $this->migracja(self::TERMINY)->down();
                $this->fail('Cofnięcie przeszło, choć skasowałoby dane wpisane przez człowieka: '.json_encode($dane));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Liczba produktów z takimi danymi: 1.', $e->getMessage());
                $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_TERMINY_SPIZARNI=1', $e->getMessage());
                $this->assertStringContainsString('CREATE TABLE pantry_items_terminy_kopia', $e->getMessage());
            }

            $this->assertTrue(Schema::hasColumn('pantry_items', 'expires_on'));
            DB::table('pantry_items')->delete();
        }
    }

    public function test_terminy_przechodza_na_pustej_tabeli_i_na_produktach_bez_nowych_danych(): void
    {
        $this->user()->pantryItems()->create(['name' => 'mąka']);

        $this->migracja(self::TERMINY)->down();

        foreach (['expires_on', 'expiry_kind', 'quantity_note', 'frozen'] as $kolumna) {
            $this->assertFalse(Schema::hasColumn('pantry_items', $kolumna), $kolumna);
        }
        $this->assertSame(1, DB::table('pantry_items')->count(), 'Produkt zostaje, znikają tylko puste kolumny.');
    }

    public function test_terminy_przechodza_ze_swiadomym_wymuszeniem(): void
    {
        $produkt = $this->user()->pantryItems()->create(['name' => 'mąka']);
        DB::table('pantry_items')->where('id', $produkt->getKey())->update(['frozen' => true]);
        putenv('KUKING_ROLLBACK_KASUJE_TERMINY_SPIZARNI=1');

        $this->migracja(self::TERMINY)->down();

        $this->assertFalse(Schema::hasColumn('pantry_items', 'frozen'));
    }

    public function test_zgoda_odmawia_gdy_ktos_ja_ma_albo_dziennik_ma_wiersz(): void
    {
        $osoba = $this->user('osoba');
        DB::table('users')->where('id', $osoba->getKey())->update(['wants_pantry_reminder' => true]);

        try {
            $this->migracja(self::ZGODA)->down();
            $this->fail('Cofnięcie przeszło, choć zgoda wróciłaby jako false bez śladu.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(wants_pantry_reminder = true): 1', $e->getMessage());
            $this->assertStringContainsString('SELECT id FROM users WHERE wants_pantry_reminder = true', $e->getMessage());
        }

        DB::table('users')->update(['wants_pantry_reminder' => false]);
        WpisZgody::create([
            'user_id' => $osoba->getKey(), 'cel' => WpisZgody::CEL_PRZYPOMNIENIE_SPIZARNI, 'czynnosc' => WpisZgody::WYCOFANA,
            'zrodlo' => WpisZgody::ZRODLO_USTAWIENIA, 'wersja_polityki' => '2026-09-30', 'wystapilo_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('przypomnienie_spizarni: 1');
        $this->migracja(self::ZGODA)->down();
    }

    public function test_zgoda_przechodzi_na_czystej_bazie_i_zdejmuje_kolumne_indeks_i_cel(): void
    {
        $this->migracja(self::ZGODA)->down();

        $this->assertFalse(Schema::hasColumn('users', 'wants_pantry_reminder'));
        $this->assertNull(DB::selectOne("SELECT 1 FROM pg_class WHERE relname = 'users_wants_pantry_reminder_idx'"));

        $osoba = $this->user('osoba');
        $this->expectException(QueryException::class);
        DB::table('dziennik_zgod')->insert([
            'user_id' => $osoba->getKey(), 'cel' => 'przypomnienie_spizarni', 'czynnosc' => 'udzielona',
            'zrodlo' => 'ustawienia', 'wersja_polityki' => '2026-09-30', 'wystapilo_at' => now(),
        ]);
    }

    public function test_wymiana_checka_celu_zgody_nie_zostawia_nazwy_tymczasowej_i_zachowuje_ochrone(): void
    {
        $check = fn (): array => array_map(
            fn ($w): string => $w->conname.'|'.($w->convalidated ? 'valid' : 'notvalid'),
            DB::select("SELECT conname, convalidated FROM pg_constraint WHERE conrelid = 'dziennik_zgod'::regclass AND conname LIKE 'dziennik_zgod_cel_check%'"),
        );

        $this->assertSame(['dziennik_zgod_cel_check|valid'], $check());

        // Kolejność instrukcji: stary CHECK znika DOPIERO po walidacji nowego.
        foreach (['down', 'up'] as $kierunek) {
            $sql = [];
            DB::listen(function ($q) use (&$sql): void {
                $sql[] = $q->sql;
            });
            $this->migracja(self::ZGODA)->{$kierunek}();

            $walidacja = $zdjecie = null;
            foreach ($sql as $i => $zapytanie) {
                if (str_contains($zapytanie, 'VALIDATE CONSTRAINT dziennik_zgod_cel_check_nowy')) {
                    $walidacja = $i;
                }
                if (str_contains($zapytanie, 'DROP CONSTRAINT IF EXISTS dziennik_zgod_cel_check') && ! str_contains($zapytanie, '_nowy')) {
                    $zdjecie = $i;
                }
            }
            $this->assertNotNull($walidacja, "{$kierunek}(): brak walidacji nowego CHECK-a pod nazwą tymczasową.");
            $this->assertNotNull($zdjecie, "{$kierunek}(): brak zdjęcia starego CHECK-a.");
            $this->assertLessThan($zdjecie, $walidacja, "{$kierunek}(): stary CHECK zdjęty przed walidacją nowego — okno bez ochrony.");
        }

        $this->migracja(self::ZGODA)->down();
        $this->assertSame(['dziennik_zgod_cel_check|valid'], $check(), 'Po down() ma zostać jeden zwalidowany CHECK pod starą nazwą.');

        $this->migracja(self::ZGODA)->up();
        $this->assertSame(['dziennik_zgod_cel_check|valid'], $check(), 'Po up() ma zostać jeden zwalidowany CHECK pod starą nazwą.');
    }

    public function test_sygnaly_kasuja_tylko_swoje_wiersze_telemetrii(): void
    {
        foreach (['pantry_expiry_set', 'search_performed'] as $nazwa) {
            DB::table('product_signals')->insert(['signal_name' => $nazwa, 'properties' => '{}', 'occurred_at' => now()]);
        }

        $this->migracja(self::SYGNALY)->down();

        $this->assertSame(['search_performed'], DB::table('product_signals')->pluck('signal_name')->all());
    }

    private function migracja(string $sciezka): object
    {
        return require base_path($sciezka);
    }
}
