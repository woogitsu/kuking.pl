<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Klucz wysłania dla „Ugotowałem": jedno wysłanie formularza to jedno
 * wykonanie i JEDNO powiadomienie u autora przepisu.
 *
 * Decyzja właściciela z 7 września 2026, ADR
 * `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md` §4 (wariant A3), krok 2 z §8.1.
 *
 * CO BYŁO ZMIERZONE
 * Dwa razy POST /przepisy/{slug}/ugotowalem dawało dwa wiersze
 * w `cooked_events` i DWA powiadomienia `cooked` u autora (ADR, POMIAR 2
 * i 2c). Wykonanie da się usunąć; powiadomienia nie da się cofnąć, a to jest
 * „najcenniejsze powiadomienie w całym serwisie" (`AGENTS.md` §1).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TO NIE JEST `UNIQUE (user_id, recipe_id)` I D-005 ZOSTAJE NIENARUSZONE
 * ────────────────────────────────────────────────────────────────────────
 *
 * `AGENTS.md` §6 i D-005 („obowiązuje, nienaruszalne") zakazują tu jednej
 * konkretnej rzeczy: unikalności na parze (osoba, przepis). Ten indeks jest
 * na parze (osoba, KLUCZ WYSŁANIA) i ta różnica jest zmierzona, nie założona
 * (ADR §3.4, POMIAR 2d):
 *
 *   1. pierwsze wysłanie (klucz A):                   PRZESZŁO
 *   2. TO SAMO wysłanie jeszcze raz (klucz A):        ODRZUCONE przez bazę
 *   3. NOWE gotowanie tego samego przepisu (klucz B): PRZESZŁO  ← D-005
 *   4. wpis bez klucza, np. z seedera (klucz NULL):   PRZESZŁO
 *   5. drugi wpis bez klucza (klucz NULL):            PRZESZŁO
 *
 * Czyli: ta sama osoba nadal może zapisać dowolnie wiele wykonań tego samego
 * przepisu — byle każde przyszło z własnego, osobno wyrenderowanego
 * formularza. Zakazane jest wyłącznie dwukrotne policzenie JEDNEGO wysłania.
 * Pilnują tego testy `IdempotencjaUgotowalemTest::
 * test_drugie_prawdziwe_gotowanie_tego_samego_przepisu_zapisuje_sie`
 * i `test_baza_przyjmuje_wiele_wykonan_tej_samej_pary_bez_klucza`.
 *
 * DLACZEGO KOLUMNA MOŻE BYĆ `NULL` I DLACZEGO INDEKS JEST CZĘŚCIOWY
 * Bez backfillu i bez `NOT NULL`: wiersze istniejące, wiersze z fabryk i
 * z seederów zostają poza indeksem (wiersze 4-5 pomiaru). `NOT NULL`
 * rozwaliłoby `database/seeders/` i każdy test tworzący wykonanie fabryką,
 * a mechanizm ma z założenia zawodzić OTWARCIE: brak klucza znaczy „zapisz
 * normalnie", nigdy „odmawiam" (ADR §4.3).
 *
 * DLACZEGO NAZWA KOLUMNY NIE ZAWIERA „token"
 * `OdzyskiwalneDane::jestWrazliwe()` dopasowuje po FRAGMENCIE nazwy, a lista
 * fragmentów zawiera `token` — pole nazwane `token_wyslania` zniknęłoby
 * z ekranu 419, czyli dokładnie tam, gdzie jest najbardziej potrzebne
 * (ADR §1.4.4, POMIAR 4). Nazwa nie jest kwestią gustu.
 *
 * ROLLBACK: `DROP INDEX`, potem `DROP COLUMN`. Bezstratnie i dlatego `down()`
 * niczego nie odmawia: kolumna niesie wyłącznie identyfikator wysłania
 * wygenerowany przez serwer, nigdy treść od człowieka. Po cofnięciu wracają
 * duplikaty, ale nie ginie ani jedno wykonanie ani jedno słowo.
 */
return new class extends Migration
{
    private const INDEKS = 'cooked_events_one_per_klucz_wyslania';

    public function up(): void
    {
        Schema::table('cooked_events', function (Blueprint $table): void {
            $table->uuid('klucz_wyslania')->nullable()->after('recipe_id');
        });

        if ($this->isPostgres()) {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::INDEKS.' ON cooked_events (user_id, klucz_wyslania) '
                .'WHERE klucz_wyslania IS NOT NULL',
            );
        }
    }

    public function down(): void
    {
        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEKS);
        }

        Schema::table('cooked_events', function (Blueprint $table): void {
            $table->dropColumn('klucz_wyslania');
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
