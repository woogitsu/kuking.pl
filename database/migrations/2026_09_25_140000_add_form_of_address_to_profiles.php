<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FORMA ZWRACANIA SIĘ — „Jak mamy do Ciebie pisać?” (issue #1752, D-332).
 *
 * Trzy odpowiedzi: forma żeńska, forma męska, forma neutralna. Neutralna jest
 * DOMYŚLNA i zapisuje się jako `NULL` — dzięki temu każde istniejące konto
 * po wdrożeniu ma dokładnie te same teksty bez rodzaju co wczoraj, bez
 * backfillu i bez zgadywania.
 *
 * To NIE jest płeć i nie wolno jej tu zgadywać ani wypełniać z Google czy
 * Facebooka (D-332). Pole stoi na `profiles`, nie na `users`, bo jest
 * widoczne dla innych („Ania ugotowała”) — należy do publicznej twarzy konta
 * i znika razem z polami opisowymi przy anonimizacji (art. 17 RODO).
 *
 * CHECK (AGENTS.md §6): zamknięta lista dwóch wartości albo `NULL`.
 * Walidator w kontrolerze da się ominąć nowym endpointem, CHECK nie.
 *
 * NOT VALID + VALIDATE, POZA TRANSAKCJĄ (AGENTS.md §6): CHECK na istniejącej
 * tabeli `profiles` dodajemy jako `NOT VALID` (krótka blokada, bez czytania
 * tabeli), a `VALIDATE CONSTRAINT` idzie osobną transakcją, która nie blokuje
 * zapisów. Stąd `$withinTransaction = false`. Kolumna jest nowa i pusta, więc
 * walidacja nie może znaleźć niezgodnego wiersza; kroki są idempotentne,
 * żeby przerwana migracja dała się uruchomić ponownie.
 *
 * ROLLBACK (D-088): `down()` ODMAWIA, gdy choć jeden profil ma wybraną formę.
 * Stary schemat nie ma gdzie jej zapisać, więc cofnięcie po cichu zamieniłoby
 * wybór człowieka na formę neutralną. Na bazie bez wyborów cofnięcie
 * przechodzi bez pytania i nic nie ginie.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('profiles', 'form_of_address')) {
            Schema::table('profiles', function (Blueprint $table): void {
                $table->string('form_of_address', 10)->nullable();
            });
        }

        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('ALTER TABLE profiles DROP CONSTRAINT IF EXISTS profiles_form_of_address_check');
        DB::statement(<<<'SQL'
            ALTER TABLE profiles
            ADD CONSTRAINT profiles_form_of_address_check
            CHECK (form_of_address IS NULL OR form_of_address IN ('feminine','masculine')) NOT VALID
        SQL);
        DB::statement('ALTER TABLE profiles VALIDATE CONSTRAINT profiles_form_of_address_check');
    }

    public function down(): void
    {
        // Strażnik PRZED jakąkolwiek zmianą schematu (wzorzec z
        // `2026_09_24_100000_add_punishment_status_to_users`), pod blokadą
        // tabeli — jak bliźniak `..._add_policy_notice_dismissed_version_to_users`:
        // bez niej wybór zapisany między policzeniem a DROP COLUMN zginąłby
        // po cichu. Migracja chodzi poza transakcją (`$withinTransaction`),
        // więc transakcję otwieramy tu sami; `lock_timeout` 5 s (AGENTS.md §6)
        // obowiązuje i tutaj.
        DB::transaction(function (): void {
            if ($this->isPostgres()) {
                DB::statement('LOCK TABLE profiles IN ACCESS EXCLUSIVE MODE');
            }

            $zWyborem = DB::table('profiles')->whereNotNull('form_of_address')->count();

            if ($zWyborem > 0) {
                throw new RuntimeException(
                    'Liczba profili z wybraną formą zwracania się (profiles.form_of_address): '.$zWyborem.'. '.
                    'Stary schemat nie ma gdzie jej zapisać: po cofnięciu te osoby dostałyby teksty '.
                    "bez rodzaju, choć same wybrały formę (D-332). Migracja odmawia.\n\n".
                    "CO ZROBIĆ:\n".
                    '  - przy awaryjnym rollbacku WDROŻENIA nie cofaj tej migracji — stary kod nie czyta '.
                    "tej kolumny i działa z nią bez zmian;\n".
                    "  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz wybory PRZED cofnięciem:\n".
                    "      SELECT user_id, form_of_address FROM profiles WHERE form_of_address IS NOT NULL;\n".
                    '    i odtwórz je po ponownym wdrożeniu, albo uzyskaj decyzję właściciela, że te wybory mają przepaść.',
                );
            }

            if ($this->isPostgres()) {
                DB::statement('ALTER TABLE profiles DROP CONSTRAINT IF EXISTS profiles_form_of_address_check');
            }

            Schema::table('profiles', function (Blueprint $table): void {
                $table->dropColumn('form_of_address');
            });
        });
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
};
