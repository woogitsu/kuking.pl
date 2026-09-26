<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BRAKUJĄCE INDEKSY NA KLUCZACH OBCYCH NA GORĄCYCH ŚCIEŻKACH (audyt B3 W1/W2/W5, B4 W4/W5/N13).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO
 * ────────────────────────────────────────────────────────────────────────
 *
 * PostgreSQL nie zakłada indeksu na kolumnie klucza obcego sam. Tu brakowało
 * go na kolumnach, po których pytamy przy każdym żądaniu albo pod blokadą:
 *
 *   • sześć kolumn wskazujących na `media` (`DostepDoZdjecia::ODWOLANIA`) —
 *     `DostepDoZdjecia::rozstrzygnij()` składa po nich UNION przy KAŻDYM
 *     wydaniu zdjęcia (`media.show`), a `KasujZdjecie`, `OsieroconeZdjecia`
 *     i kontrola FK przy `DELETE FROM media` pytają o nie seryjnie.
 *     `post_media` i `cooked_event_media` miały `media_id` tylko jako DRUGĄ
 *     kolumnę klucza głównego, co zmusza do przejścia całego indeksu;
 *   • `posts.recipe_id` — `WpisWskazujacyPrzepis::dopisz()` szuka po nim pod
 *     `FOR UPDATE` wiersza przepisu (publikacja i autozapis), a twarde
 *     skasowanie przepisu (`ON DELETE SET NULL`) skanowało całe `posts`;
 *   • `notifications.actor_id` — największa tabela; `ON DELETE SET NULL`
 *     przy usunięciu konta to był skan sekwencyjny całości;
 *   • `product_signals (user_id, signal_name)` — `RecordPromptShown` pyta
 *     o to pod blokadą wiersza konta.
 *
 * Kolumny nullable dostają indeks częściowy `WHERE … IS NOT NULL`: szukamy
 * zawsze po konkretnej wartości, a puste wiersze tylko by go puchły.
 * `recipes.hero_media_id OR recipes.source_scan_media_id` planista rozwiązuje
 * przez `BitmapOr` na dwóch osobnych indeksach.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CONCURRENTLY, POZA TRANSAKCJĄ
 * ────────────────────────────────────────────────────────────────────────
 *
 * To indeksy na istniejących, gorących tabelach. Zwykłe `CREATE INDEX`
 * bierze blokadę `SHARE`, która wstrzymuje zapisy na czas budowy. Stąd
 * `CONCURRENTLY` i `$withinTransaction = false` — `CONCURRENTLY` nie działa
 * w bloku transakcji.
 *
 * Nieudane `CREATE INDEX CONCURRENTLY` zostawia indeks INVALID pod tą samą
 * nazwą, a `IF NOT EXISTS` by go potem przepuściło. Dlatego przed budową
 * zdejmujemy taki niedokończony indeks — ponowne `migrate` zaczyna od zera.
 *
 * Gdy migracja jest wołana wewnątrz już otwartej transakcji (test z
 * `RefreshDatabase` wołający `up()`/`down()` wprost), `CONCURRENTLY` jest
 * niemożliwe — wtedy budujemy zwykłym `CREATE INDEX`. `artisan migrate` tego
 * przypadku nie ma, bo `$withinTransaction = false`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ROLLBACK
 * ────────────────────────────────────────────────────────────────────────
 *
 * `down()` zdejmuje indeksy (`DROP INDEX CONCURRENTLY IF EXISTS`). Bezstratnie:
 * indeks nie niesie danych ani decyzji człowieka, więc D-088 nie ma tu
 * czego chronić. Skutkiem cofnięcia jest wyłącznie powrót skanów.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Nazwa indeksu => [tabela, definicja kolumn, warunek częściowy albo null].
     *
     * @var array<string, array{0: string, 1: string, 2: string|null}>
     */
    private const INDEKSY = [
        'post_media_media_idx' => ['post_media', 'media_id', null],
        'cooked_event_media_media_idx' => ['cooked_event_media', 'media_id', null],
        'profiles_avatar_media_idx' => ['profiles', 'avatar_media_id', 'avatar_media_id IS NOT NULL'],
        'recipes_hero_media_idx' => ['recipes', 'hero_media_id', 'hero_media_id IS NOT NULL'],
        'recipes_source_scan_media_idx' => ['recipes', 'source_scan_media_id', 'source_scan_media_id IS NOT NULL'],
        'recipe_steps_media_idx' => ['recipe_steps', 'media_id', 'media_id IS NOT NULL'],
        'posts_recipe_idx' => ['posts', 'recipe_id', 'recipe_id IS NOT NULL'],
        'notifications_actor_idx' => ['notifications', 'actor_id', 'actor_id IS NOT NULL'],
        'product_signals_user_signal_idx' => ['product_signals', 'user_id, signal_name', 'user_id IS NOT NULL'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $wspolbieznie = $this->wspolbieznie();

        foreach (self::INDEKSY as $nazwa => [$tabela, $kolumny, $warunek]) {
            if ($this->jestNiedokonczony($nazwa)) {
                DB::statement('DROP INDEX '.$wspolbieznie.'IF EXISTS '.$nazwa);
            }

            DB::statement(
                'CREATE INDEX '.$wspolbieznie.'IF NOT EXISTS '.$nazwa
                .' ON '.$tabela.' ('.$kolumny.')'
                .($warunek === null ? '' : ' WHERE '.$warunek),
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $wspolbieznie = $this->wspolbieznie();

        foreach (array_keys(self::INDEKSY) as $nazwa) {
            DB::statement('DROP INDEX '.$wspolbieznie.'IF EXISTS '.$nazwa);
        }
    }

    private function wspolbieznie(): string
    {
        return DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }

    private function jestNiedokonczony(string $nazwa): bool
    {
        return DB::selectOne(
            'SELECT 1 AS jest FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid '
            .'WHERE c.relname = ? AND NOT i.indisvalid',
            [$nazwa],
        ) !== null;
    }
};
