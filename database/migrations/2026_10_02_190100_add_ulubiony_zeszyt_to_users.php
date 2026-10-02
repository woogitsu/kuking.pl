<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Skrót do jednego własnego zeszytu na ekranie „Moje" (#2542, decyzja
 * właściciela z 2.10.2026).
 *
 * `users.ulubiony_zeszyt_id uuid NULL` -> `collections` `ON DELETE SET NULL`:
 * usunięcie zeszytu zostawia osobę z kontem bez skrótu, nie z martwym
 * odnośnikiem. To preferencja przy koncie, nie pole sterujące, ale i tak NIE
 * wchodzi do `$fillable` — ustawia ją wyłącznie akcja, która sprawdza, że
 * zeszyt jest WŁASNY (Policy + `where owner_id`).
 *
 * `users` jest ISTNIEJĄCĄ, gorącą tabelą (AGENTS.md §6):
 *  - kolumna bez wartości domyślnej — zmiana samego katalogu, bez przepisania;
 *  - klucz obcy `NOT VALID`, potem osobno `VALIDATE` (stąd brak transakcji);
 *  - indeks częściowy `CONCURRENTLY` — bez niego każde usunięcie zeszytu
 *    skanowałoby całe `users`, żeby znaleźć wiersze do `SET NULL`.
 *
 * WYCOFANIE ODMAWIA, GDY ZGUBIŁOBY WYBÓR CZŁOWIEKA (D-088)
 * `up()` nie odtworzy niczyjego wyboru: po cofnięciu i ponownej migracji
 * każdy skrót byłby pusty, a osoba musiałaby go ustawiać od nowa, nie wiedząc
 * dlaczego. Gdy choć jedno konto ma ustawiony skrót, `down()` przerywa.
 * Na bazie, gdzie nikt go nie ustawił, cofa się bez pytania. Świadome
 * wymuszenie: `KUKING_ROLLBACK_KASUJE_SKROT_ZESZYTU=1`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const FK = 'users_ulubiony_zeszyt_fk';

    private const INDEKS = 'users_ulubiony_zeszyt_idx';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS ulubiony_zeszyt_id uuid NULL');

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS '.self::FK);
        DB::statement('ALTER TABLE users ADD CONSTRAINT '.self::FK
            .' FOREIGN KEY (ulubiony_zeszyt_id) REFERENCES collections (id) ON DELETE SET NULL NOT VALID');
        DB::statement('ALTER TABLE users VALIDATE CONSTRAINT '.self::FK);

        $wspolbieznie = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';

        if ($this->jestNiedokonczony()) {
            DB::statement('DROP INDEX '.$wspolbieznie.'IF EXISTS '.self::INDEKS);
        }

        DB::statement('CREATE INDEX '.$wspolbieznie.'IF NOT EXISTS '.self::INDEKS
            .' ON users (ulubiony_zeszyt_id) WHERE ulubiony_zeszyt_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->odmowJesliZgubiWybory();

        $wspolbieznie = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
        DB::statement('DROP INDEX '.$wspolbieznie.'IF EXISTS '.self::INDEKS);
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS '.self::FK);
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS ulubiony_zeszyt_id');
    }

    private function odmowJesliZgubiWybory(): void
    {
        $kolumna = DB::selectOne(
            'SELECT 1 AS jest FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['users', 'ulubiony_zeszyt_id'],
        );

        if ($kolumna === null) {
            return;
        }

        $ile = (int) DB::selectOne(
            'SELECT count(*) AS ile FROM users WHERE ulubiony_zeszyt_id IS NOT NULL',
        )->ile;

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null.
        if (getenv('KUKING_ROLLBACK_KASUJE_SKROT_ZESZYTU') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie odmówione. Liczba kont z wybranym skrótem do zeszytu (users.ulubiony_zeszyt_id): {$ile}.
            Po cofnięciu i ponownym `migrate` kolumna wróciłaby pusta — ludzie straciliby skrót,
            który sami wybrali (D-088).

            CO ZROBIĆ:
              - przy awaryjnym rollbacku WDROŻENIA nie cofaj tej migracji — kod sprzed niej
                nie czyta tej kolumny;
              - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz wybory przed cofnięciem:
                  CREATE TABLE users_ulubiony_zeszyt_kopia AS
                    SELECT id, ulubiony_zeszyt_id FROM users WHERE ulubiony_zeszyt_id IS NOT NULL;
                i po ponownej migracji odtwórz je tym samym `UPDATE ... FROM`;
              - jeśli utrata skrótów jest świadoma, uruchom ponownie z KUKING_ROLLBACK_KASUJE_SKROT_ZESZYTU=1.
            TEKST);
    }

    private function jestNiedokonczony(): bool
    {
        return DB::selectOne(
            'SELECT 1 AS jest FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid '
            .'WHERE c.relname = ? AND NOT i.indisvalid',
            [self::INDEKS],
        ) !== null;
    }
};
