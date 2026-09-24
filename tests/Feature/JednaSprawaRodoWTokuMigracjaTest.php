<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\SlownikPotwierdzenRodo;
use App\Support\NumerZadaniaRodo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Migracja „jedna otwarta sprawa RODO na konto" (#1346): odmowa i obie jej
 * granice (AGENTS.md §6, D-088).
 *
 * DWIE STRONY, OBIE SPRAWDZONE — tak jak w `IdempotencjaMigracjiTest`.
 * Strażnik, który odmawia zawsze, jest równie zły jak brak strażnika, więc
 * obok testu odmowy stoi kontrola dodatnia na czystej bazie. Trzeci test
 * pilnuje cofnięcia: `down()` zdejmuje ochronę, a nie dowody obsługi żądań.
 */
class JednaSprawaRodoWTokuMigracjaTest extends TestCase
{
    use RefreshDatabase;

    private const INDEKS = 'potwierdzenia_zadan_rodo_jedna_w_toku_na_konto';

    private function migracja(): object
    {
        return require database_path('migrations/2026_09_24_160000_jedna_sprawa_rodo_w_toku_na_konto.php');
    }

    public function test_migracja_odmawia_gdy_konto_ma_dwie_sprawy_w_toku(): void
    {
        // Indeks musi zniknąć, żeby dało się odtworzyć stan sprzed migracji —
        // czyli bazę, w której duplikaty już leżą.
        DB::statement('DROP INDEX IF EXISTS '.self::INDEKS);

        $konto = $this->user('dwiesprawyrodo');
        $this->sprawaWToku($konto->getKey());
        $this->sprawaWToku($konto->getKey());

        // Odmowa do zmiennej, ocena poza blokiem: `$this->fail()` wewnątrz
        // `try` wpadłby do `catch (RuntimeException)` — powód opisany
        // w `IdempotencjaMigracjiTest`.
        $odmowa = null;

        try {
            $this->migracja()->up();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Migracja przeszła, mimo że konto ma dwie otwarte sprawy RODO.');

        $komunikat = $odmowa->getMessage();
        $this->assertStringContainsString('Liczba kont z więcej niż jedną otwartą sprawą RODO (wynik = w_toku): 1.', $komunikat);
        $this->assertStringContainsString('GROUP BY konto_id HAVING count(*) > 1', $komunikat);
        $this->assertStringContainsString('Nie kasuj wierszy', $komunikat);

        // UUID konta w sprawie RODO nie ma czego szukać w logu wdrożenia.
        $this->assertStringNotContainsString($konto->getKey(), $komunikat);

        $this->assertSame(0, $this->ileIndeksow(), 'Mimo odmowy indeks powstał.');
        $this->assertSame(2, DB::table('potwierdzenia_zadan_rodo')->count(), 'Migracja usunęła sprawę RODO.');
    }

    public function test_migracja_zaklada_indeks_na_bazie_bez_duplikatow(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEKS);

        // Wąskość odmowy: sprawy w toku RÓŻNYCH kont i zamknięte sprawy tego
        // samego konta to stan dozwolony — nie mogą blokować migracji.
        $pierwsze = $this->user('jednasprawarodo');
        $drugie = $this->user('innasprawarodo');
        $this->sprawaWToku($pierwsze->getKey());
        $this->sprawaWToku($drugie->getKey());
        $this->sprawaCofnieta($pierwsze->getKey());

        $this->migracja()->up();

        $this->assertSame(1, $this->ileIndeksow());
    }

    public function test_cofniecie_zdejmuje_indeks_bez_utraty_wierszy_i_pozwala_zalozyc_go_ponownie(): void
    {
        $konto = $this->user('cofnieciesprawyrodo');
        $this->sprawaWToku($konto->getKey());
        $this->sprawaCofnieta($konto->getKey());

        $this->assertSame(1, $this->ileIndeksow(), 'Indeks powinien istnieć po zwykłym migrate.');

        $this->migracja()->down();

        $this->assertSame(0, $this->ileIndeksow());
        $this->assertSame(2, DB::table('potwierdzenia_zadan_rodo')->count(), 'Cofnięcie usunęło sprawę RODO.');

        $this->migracja()->up();

        $this->assertSame(1, $this->ileIndeksow(), 'Po down() ponowne up() nie założyło indeksu.');
        $this->assertSame(2, DB::table('potwierdzenia_zadan_rodo')->count());
    }

    private function sprawaWToku(string $kontoId): void
    {
        $this->sprawa($kontoId, 'w_toku', null);
    }

    private function sprawaCofnieta(string $kontoId): void
    {
        $this->sprawa($kontoId, 'cofniete', now()->toDateString());
    }

    /**
     * Gołym zapytaniem, nie przez `RejestrPotwierdzenRodo`: ścieżka zapisu
     * aplikacji słusznie nie pozwala postawić drugiej sprawy w toku, a ten
     * test musi odtworzyć bazę, w której ktoś to już zrobił inną drogą.
     */
    private function sprawa(string $kontoId, string $wynik, ?string $zakonczono): void
    {
        DB::table('potwierdzenia_zadan_rodo')->insert([
            'id' => (string) Str::uuid(),
            'numer' => NumerZadaniaRodo::wygeneruj(),
            'rodzaj' => SlownikPotwierdzenRodo::RODZAJE[0],
            'wynik' => $wynik,
            'zakres' => null,
            'otrzymano' => now()->toDateString(),
            'zakonczono' => $zakonczono,
            'wersja_procedury' => SlownikPotwierdzenRodo::WERSJA_PROCEDURY_DZIS,
            'wyjatki' => null,
            'konto_id' => $kontoId,
            'wstrzymanie_do' => null,
            'wstrzymanie_sprawa' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ileIndeksow(): int
    {
        return DB::table('pg_indexes')->where('indexname', self::INDEKS)->count();
    }
}
