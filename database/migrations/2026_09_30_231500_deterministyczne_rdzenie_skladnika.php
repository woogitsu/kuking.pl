<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rdzenie składnika w JAWNIE ustalonej kolejności (#2315).
 *
 * CO BYŁO ŹLE
 * `kuking_rdzenie_skladnika()` z migracji `2026_09_28_233700` składała tablicę
 * przez `array_agg(DISTINCT …)` bez `ORDER BY`, a komentarz przy
 * `kuking_klucz_skladnika()` zakładał, że wynik jest posortowany. PostgreSQL
 * tego nie obiecuje (dokumentacja funkcji agregujących: kolejność wejścia
 * agregatu bez `ORDER BY` jest nieokreślona). Dziś sortuje, bo tak wykonuje
 * `DISTINCT` w agregacie — ale to szczegół wykonania, a na kluczu stoi
 * `UNIQUE (user_id, klucz)`. Ten sam zbiór rdzeni w innej kolejności to inny
 * tekst klucza, czyli logiczny duplikat produktu, którego ograniczenie nie
 * zatrzyma.
 *
 * CO ROBI
 * Ta sama reguła rdzeni, tylko z `array_agg(DISTINCT r ORDER BY r)` na
 * wartości w porządku `COLLATE "C"` (bajtowym — nie zależy od kolacji bazy;
 * rdzenie to wyłącznie `a-z0-9`, więc dla dzisiejszych danych kolejność jest
 * ta sama co dotąd). Potem przelicza zapisane kolumny generowane
 * (`UPDATE … SET name = name`) — sama zmiana funkcji ich nie przelicza.
 *
 * ODMOWA ZAMIAST CICHEGO KASOWANIA
 * Jeśli po przeliczeniu dwa produkty jednej osoby dostałyby ten sam klucz,
 * `UNIQUE` by to przewrócił. Nie kasujemy za człowieka jego pozycji
 * (AGENTS.md: bez destrukcyjnych operacji bez zgody) — migracja odmawia
 * i mówi, co zrobić. Na świeżej bazie i bez duplikatów przechodzi.
 *
 * ROLLBACK
 * `down()` przywraca poprzednie ciało funkcji. Dane zostają — przy
 * dzisiejszym sposobie wykonania dają te same klucze.
 */
return new class extends Migration
{
    private const FUNKCJA_NOWA = <<<'SQL'
        CREATE OR REPLACE FUNCTION public.kuking_rdzenie_skladnika(text) RETURNS text[]
        AS $$
            SELECT COALESCE(array_agg(DISTINCT r ORDER BY r), '{}')
            FROM (
                SELECT (COALESCE(
                    public.kuking_formy_skladnikow() ->> s,
                    CASE
                        WHEN length(s) > 5 AND s LIKE '%ow' THEN left(s, -2)
                        WHEN length(s) > 4 THEN regexp_replace(s, '[aeiouy]$', '')
                        ELSE s
                    END)) COLLATE "C" AS r
                FROM regexp_split_to_table(
                    regexp_replace(public.kuking_normalize($1), '[^a-z0-9]+', ' ', 'g'), ' '
                ) AS s
                WHERE length(s) >= 2 AND s !~ '^[0-9]+$'
            ) AS rdzenie
        $$
        LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
        SQL;

    private const FUNKCJA_STARA = <<<'SQL'
        CREATE OR REPLACE FUNCTION public.kuking_rdzenie_skladnika(text) RETURNS text[]
        AS $$
            SELECT COALESCE(array_agg(DISTINCT COALESCE(
                public.kuking_formy_skladnikow() ->> s,
                CASE
                    WHEN length(s) > 5 AND s LIKE '%ow' THEN left(s, -2)
                    WHEN length(s) > 4 THEN regexp_replace(s, '[aeiouy]$', '')
                    ELSE s
                END)), '{}')
            FROM regexp_split_to_table(
                regexp_replace(public.kuking_normalize($1), '[^a-z0-9]+', ' ', 'g'), ' '
            ) AS s
            WHERE length(s) >= 2 AND s !~ '^[0-9]+$'
        $$
        LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
        SQL;

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(self::FUNKCJA_NOWA);

        if (! Schema::hasTable('pantry_items')) {
            return;
        }

        $duplikaty = (int) DB::scalar(<<<'SQL'
            SELECT count(*) FROM (
                SELECT user_id, public.kuking_klucz_skladnika(name)
                FROM pantry_items
                GROUP BY 1, 2
                HAVING count(*) > 1
            ) AS d
            SQL);

        if ($duplikaty > 0) {
            throw new RuntimeException(
                "Migracja #2315 odmawia: {$duplikaty} par produktów w „Co mam w domu” dostałoby ten sam klucz "
                .'po ustaleniu kolejności rdzeni. Znajdź je zapytaniem '
                .'SELECT user_id, public.kuking_klucz_skladnika(name), array_agg(name) FROM pantry_items GROUP BY 1, 2 HAVING count(*) > 1; '
                .'i za zgodą właściciela zostaw po jednym produkcie w każdej parze, potem uruchom migrację jeszcze raz.',
            );
        }

        // Kolumny generowane (`rdzenie`, `klucz`) liczą się przy zapisie
        // wiersza, nie przy zmianie funkcji.
        DB::statement('UPDATE pantry_items SET name = name');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(self::FUNKCJA_STARA);
    }
};
