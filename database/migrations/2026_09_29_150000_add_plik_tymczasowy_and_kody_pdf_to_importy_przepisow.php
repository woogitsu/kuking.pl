<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Import z pliku PDF chodzi w kolejce (#28, etap 2).
 *
 * Wysłany PDF nie może czekać na worker w `UploadedFile::getRealPath()` —
 * po wydzieleniu workera to inny kontener. Trafia na prywatny dysk
 * współdzielony (`kuking.import.pdf.dysk`), a zlecenie zapamiętuje jego
 * ścieżkę w nowej kolumnie `plik_tymczasowy` — tak jak import z adresu
 * zapamiętuje adres w `source_url`. Kolumna jest zerowana w KAŻDYM stanie
 * końcowym razem ze skasowaniem pliku, a `kuking:odzyskaj-importy` sprząta
 * to, czego nie domknęło ani zadanie, ani `failed()` (retencja, #2051).
 *
 * Do zamkniętej listy `importy_przepisow_kod_bledu_check` dochodzi siedem
 * powodów odmowy odczytu PDF-a — te same napisy co `ImportOdrzucony::PDF_*`
 * i `NARZEDZIE_PDF_NIEDOSTEPNE`, z tym samym zdaniem „co zrobić”:
 * `pdf_za_duzy`, `pdf_za_duzo_stron`, `pdf_uszkodzony`, `pdf_zaszyfrowany`,
 * `pdf_bez_tekstu`, `pdf_brak_przepisu`, `narzedzie_pdf_niedostepne`.
 *
 * Ta migracja ustawia PEŁNĄ listę (stare + adres z `2026_09_29_100000…` +
 * PDF), więc nie zależy od kolejności względem tamtej: gdyby tamta
 * (z węższą listą) uruchomiła się PÓŹNIEJ, zgubiłaby kody PDF — dlatego
 * ta ma być późniejsza w kolejności migracji.
 *
 * ROLLBACK NIE ODMAWIA (AGENTS.md §6, D-088), ale nie jest bezstratny:
 * kody PDF zamieniamy na `blad_wewnetrzny` (status `nieudany` zostaje),
 * kolumna `plik_tymczasowy` znika. Pliki, które w niej wskazywały, ZOSTAJĄ
 * na dysku bez wiersza — po cofnięciu kodu nikt ich nie posprząta, więc
 * przed cofnięciem: `KUKING_IMPORT_PDF=false`, poczekać na dokończenie
 * zadań `ImportujPrzepisZPdf` albo skasować katalog `import-pdf-tmp/`
 * na dysku importu ręcznie (to kopie plików, które człowiek ma u siebie).
 */
return new class extends Migration
{
    // AGENTS.md §6: indeks współbieżnie (CONCURRENTLY nie wchodzi w transakcję),
    // a CHECK przez `NOT VALID` i osobne `VALIDATE CONSTRAINT`.
    public $withinTransaction = false;

    private const INDEKS = 'importy_przepisow_plik_idx';

    private const STARE = [
        'limit_osoby', 'budzet_dzienny', 'budzet_miesieczny', 'brak_zgody', 'wylaczony', 'model_niedostepny',
        'nieczytelne', 'odpowiedz_bledna', 'zdjecie_niedostepne', 'szkic_zmieniony', 'blad_wewnetrzny',
        'adres_nieprawidlowy', 'adres_niepubliczny', 'strona_niedostepna', 'za_duzo_przekierowan',
        'za_duza_strona', 'za_dlugo', 'nie_strona',
    ];

    private const PDF = [
        'pdf_za_duzy', 'pdf_za_duzo_stron', 'pdf_uszkodzony', 'pdf_zaszyfrowany',
        'pdf_bez_tekstu', 'pdf_brak_przepisu', 'narzedzie_pdf_niedostepne',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('importy_przepisow', 'plik_tymczasowy')) {
            Schema::table('importy_przepisow', function (Blueprint $table): void {
                // Ścieżka pliku na dysku importu; `text`, bo zależy od katalogu z konfiguracji.
                $table->text('plik_tymczasowy')->nullable();
            });
        }

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE importy_przepisow DROP CONSTRAINT IF EXISTS importy_przepisow_plik_check');
        DB::statement("ALTER TABLE importy_przepisow ADD CONSTRAINT importy_przepisow_plik_check CHECK (plik_tymczasowy IS NULL OR zrodlo = 'pdf') NOT VALID");
        DB::statement('ALTER TABLE importy_przepisow VALIDATE CONSTRAINT importy_przepisow_plik_check');

        // Sprzątanie pyta tylko o wiersze z plikiem — reszta tabeli go nie ma.
        // Przerwane CREATE INDEX CONCURRENTLY zostawia indeks INVALID, który
        // `IF NOT EXISTS` uznałby za gotowy — usuwamy go przed budową.
        $wspolbieznie = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';

        if ($this->jestNiedokonczony()) {
            DB::statement('DROP INDEX '.$wspolbieznie.'IF EXISTS '.self::INDEKS);
        }

        DB::statement(
            'CREATE INDEX '.$wspolbieznie.'IF NOT EXISTS '.self::INDEKS
            .' ON importy_przepisow (updated_at) WHERE plik_tymczasowy IS NOT NULL',
        );

        $this->ustawListe([...self::STARE, ...self::PDF]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('importy_przepisow')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $wspolbieznie = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';

            DB::statement('DROP INDEX '.$wspolbieznie.'IF EXISTS '.self::INDEKS);
            DB::statement('ALTER TABLE importy_przepisow DROP CONSTRAINT IF EXISTS importy_przepisow_plik_check');

            DB::table('importy_przepisow')->whereIn('kod_bledu', self::PDF)->update(['kod_bledu' => 'blad_wewnetrzny']);

            $this->ustawListe(self::STARE);
        }

        if (Schema::hasColumn('importy_przepisow', 'plik_tymczasowy')) {
            Schema::table('importy_przepisow', function (Blueprint $table): void {
                $table->dropColumn('plik_tymczasowy');
            });
        }
    }

    /** @param list<string> $kody */
    private function ustawListe(array $kody): void
    {
        $lista = implode(', ', array_map(static fn (string $k): string => "'".$k."'", $kody));

        DB::statement('ALTER TABLE importy_przepisow DROP CONSTRAINT IF EXISTS importy_przepisow_kod_bledu_check');
        DB::statement(
            'ALTER TABLE importy_przepisow ADD CONSTRAINT importy_przepisow_kod_bledu_check '
            .'CHECK (kod_bledu IS NULL OR kod_bledu IN ('.$lista.')) NOT VALID',
        );
        DB::statement('ALTER TABLE importy_przepisow VALIDATE CONSTRAINT importy_przepisow_kod_bledu_check');
    }

    /** Indeks po przerwanym CREATE INDEX CONCURRENTLY jest w katalogu, ale nieprawidłowy. */
    private function jestNiedokonczony(): bool
    {
        return DB::selectOne(
            'SELECT 1 AS jest FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid '
            .'WHERE c.relname = ? AND NOT i.indisvalid',
            [self::INDEKS],
        ) !== null;
    }
};
