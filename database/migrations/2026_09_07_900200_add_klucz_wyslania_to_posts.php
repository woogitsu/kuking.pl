<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Klucz wysłania dla wpisu: jedno wysłanie formularza „Opublikuj" to jeden
 * wpis.
 *
 * Decyzja właściciela z 7 września 2026, ADR
 * `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md` §4 (wariant A3), krok 3 z §8.1.
 *
 * CO BYŁO ZMIERZONE
 * Dwa razy POST /dodaj/zdjecie z identycznym ciałem dawało dwa wiersze
 * w `posts` i dwa RÓŻNE adresy w `Location` (ADR, POMIAR 1 i 1c) — serwis
 * odsyłał człowieka do drugiego wpisu, o którego istnieniu ten człowiek nie
 * wiedział. Dotyczy głównej akcji produktu i zachowania typowego, nie
 * brzegowego: w grupie 50+ drugie kliknięcie nie jest pomyłką, tylko sposobem
 * obsługi komputera.
 *
 * DLACZEGO NA KLUCZU WYSŁANIA, A NIE NA TREŚCI
 * Dwa prawdziwe wpisy o tej samej treści od tej samej osoby są w tym
 * produkcie POPRAWNE — ktoś gotuje rosół co niedzielę i pisze o tym za
 * każdym razem. „Ta sama treść, ale nie w ciągu 30 sekund" nie jest przy tym
 * warunkiem wyrażalnym w indeksie UNIQUE, bo indeks nie ma pojęcia „teraz"
 * (ADR §3.4). Jedyne, co odróżnia drugie kliknięcie od drugiego wpisu, to
 * tożsamość WYSŁANIA — i ona musi przyjść w żądaniu, bo w treści jej nie ma.
 *
 * Odcisk treści liczony po `media_id` też by tego nie załatwił: każde wgranie
 * tworzy nowy wiersz `media` z nowym UUID-em, więc ten sam plik wgrany dwa
 * razy wygląda jak dwie różne treści (ADR §1.4.1, POMIAR 1d i 1e).
 *
 * `NULL` I INDEKS CZĘŚCIOWY — jak w migracji dla `cooked_events`: bez
 * backfillu, bez `NOT NULL`, wiersze bez klucza zostają poza indeksem.
 * Mechanizm zawodzi OTWARCIE (ADR §4.3): brak albo nieznany klucz znaczy
 * „opublikuj normalnie", nigdy „odmawiam" — zduplikowany wpis jest dla
 * odbiorcy 50+ mniej szkodliwy niż wpis utracony.
 *
 * INDEKS JEST NA PARZE (autor, klucz), nie na samym kluczu. Klucz podstawiony
 * z cudzego formularza nie może więc ani zablokować własnego wysłania, ani
 * pokazać cudzego wpisu — UUID w żądaniu nie jest autoryzacją (`AGENTS.md`
 * §7). Pilnuje tego `IdempotencjaWpisuTest::
 * test_klucz_z_cudzego_formularza_nie_daje_dostepu_do_cudzego_wpisu`.
 *
 * INDEKS NIE WYKLUCZA WPISÓW USUNIĘTYCH MIĘKKO — i to jest wybór, nie
 * przeoczenie. Gdyby ktoś usunął wpis i wrócił „wstecz" na ten sam formularz,
 * klucz wciąż siedzi w indeksie, więc `INSERT` się odbije; akcja domenowa nie
 * znajdzie wtedy wpisu do pokazania (usunięty jest poza domyślnym zakresem)
 * i zapisze nowy wpis BEZ klucza. Skutek dla człowieka: wpis powstaje, czyli
 * to samo, co przy zawodzeniu otwartym. Warunek `deleted_at IS NULL`
 * w indeksie dałby ten sam efekt inną drogą i niczego nie ratuje.
 *
 * ROLLBACK: `DROP INDEX`, potem `DROP COLUMN`. Bezstratnie i dlatego `down()`
 * niczego nie odmawia: kolumna niesie wyłącznie identyfikator wysłania
 * wygenerowany przez serwer, nigdy treść od człowieka.
 */
return new class extends Migration
{
    private const INDEKS = 'posts_one_per_klucz_wyslania';

    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->uuid('klucz_wyslania')->nullable()->after('recipe_id');
        });

        if ($this->isPostgres()) {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::INDEKS.' ON posts (author_id, klucz_wyslania) '
                .'WHERE klucz_wyslania IS NOT NULL',
            );
        }
    }

    public function down(): void
    {
        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEKS);
        }

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropColumn('klucz_wyslania');
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
