<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kopia własnego szkicu do pracy nad drugim wariantem (#2507, V2, D-333 — paczka E).
 *
 * JEDNA KOLUMNA
 *
 *  - `kopia_z_id` — z KTÓREGO własnego przepisu (szkicu) powstała ta kopia.
 *    Służy jednej regule: kopia, w której nic nie zmieniono względem źródła,
 *    nie zostaje opublikowana (publiczna seria identycznych przepisów) —
 *    `MojaWersja::pilnujRoznicyKopii()`. Klucz obcy `ON DELETE SET NULL`:
 *    skasowanie źródła nie kasuje kopii; miękkie usunięcie źródła zostawia
 *    wskazanie (porównanie liczy się też z usuniętym). To NIE jest podpis
 *    „Na podstawie cudzego przepisu” — ten (`forked_from_id`, `forked_at`)
 *    kopia dziedziczy bez zmian i nigdy go nie traci.
 *
 * Jedno wysłanie formularza = jedna kopia: tożsamość wysłania niesie ISTNIEJĄCY
 * `klucz_wyslania` (unikalny w parze z autorem — `recipes_one_per_klucz_wyslania`,
 * D-027), więc nie dokładamy drugiej kolumny na to samo. Ponowienie zwraca tę
 * samą kopię, a nowy formularz (nowy klucz) zakłada kolejny wariant.
 *
 * DDL NA ISTNIEJĄCEJ TABELI (AGENTS.md §6): `ADD COLUMN` bez `DEFAULT` nie
 * przepisuje tabeli; klucz obcy `NOT VALID` + osobne `VALIDATE`, indeksy
 * `CONCURRENTLY`, wszystko poza jedną transakcją (`$withinTransaction = false`).
 *
 * ROLLBACK ODMAWIA (D-088), gdy istnieje choć jedna kopia, która nie jest jeszcze
 * opublikowana: po zdjęciu kolumn taka kopia straciłaby powiązanie ze źródłem, a
 * reguła „niezmieniona kopia nie wychodzi do ludzi” przestałaby ją chronić.
 * Opublikowane kopie i świeża baza przechodzą bez pytania.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const KLUCZ_OBCY = 'recipes_kopia_z_id_foreign';

    private const CHECK = 'recipes_kopia_spojna_check';

    private const INDEKS_ZRODLO = 'recipes_kopia_z_idx';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasColumn('recipes', 'kopia_z_id')) {
            Schema::table('recipes', function (Blueprint $table): void {
                $table->uuid('kopia_z_id')->nullable();
            });
        }

        DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS '.self::KLUCZ_OBCY);
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT '.self::KLUCZ_OBCY
            .' FOREIGN KEY (kopia_z_id) REFERENCES recipes (id) ON DELETE SET NULL NOT VALID');
        DB::statement('ALTER TABLE recipes VALIDATE CONSTRAINT '.self::KLUCZ_OBCY);

        DB::statement('ALTER TABLE recipes DROP CONSTRAINT IF EXISTS '.self::CHECK);
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT '.self::CHECK
            .' CHECK (kopia_z_id IS NULL OR kopia_z_id <> id) NOT VALID');
        DB::statement('ALTER TABLE recipes VALIDATE CONSTRAINT '.self::CHECK);

        $wspolbieznie = DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';

        // Przerwana budowa zostawia indeks INVALID pod tą samą nazwą — zdejmujemy go przed budową.
        DB::statement('DROP INDEX '.$wspolbieznie.'IF EXISTS '.self::INDEKS_ZRODLO);

        DB::statement('CREATE INDEX '.$wspolbieznie.'IF NOT EXISTS '.self::INDEKS_ZRODLO
            .' ON recipes (kopia_z_id) WHERE kopia_z_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE recipes IN ACCESS EXCLUSIVE MODE');

            $niepublikowanych = Schema::hasColumn('recipes', 'kopia_z_id')
                ? (int) DB::table('recipes')->whereNotNull('kopia_z_id')->whereNull('published_at')->count()
                : 0;

            if ($niepublikowanych > 0) {
                throw new RuntimeException(
                    'Cofnięcie odmówione. Liczba kopii szkiców, które nie są jeszcze opublikowane '
                    .'(recipes.kopia_z_id IS NOT NULL AND published_at IS NULL): '.$niepublikowanych.'. '
                    .'Bez tych kolumn kopia traci powiązanie ze źródłem i reguła „niezmieniona kopia nie wychodzi do ludzi” '
                    .'przestaje ją chronić (D-088).'."\n\n"
                    ."CO ZROBIĆ:\n"
                    ."  - przy awaryjnym rollbacku WDROŻENIA nie cofaj tej migracji — kod sprzed niej kolumn nie zna i działa z nimi bez zmian;\n"
                    ."  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz mapę PRZED cofnięciem:\n"
                    ."      SELECT id, kopia_z_id FROM recipes WHERE kopia_z_id IS NOT NULL;\n"
                    .'    i odtwórz ją tym samym `UPDATE` po powrocie na ten schemat.',
                );
            }

            // DROP COLUMN usuwa też zależny indeks, klucz obcy i CHECK.
            DB::statement('ALTER TABLE recipes DROP COLUMN IF EXISTS kopia_z_id');
        });
    }
};
