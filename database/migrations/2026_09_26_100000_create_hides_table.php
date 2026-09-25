<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prywatne ukrycia: „Ukryj ten wpis" i „Ukryj tę osobę" (issue #1810, D-278).
 *
 * Jeden wiersz = jedno jawne polecenie widza (AGENTS.md §8): ukryj TEN wpis
 * albo TĘ osobę, domyślnie na 30 dni. `hidden_until = NULL` znaczy „na stałe"
 * (przycisk „Zostaw ukryte" na liście w Ustawieniach). Wiersz po terminie
 * nic nie ukrywa — czyta go tylko lista, żeby pokazać, co wróciło.
 *
 * OGRANICZENIA W BAZIE
 *  - dokładnie jeden obiekt: `num_nonnulls(post_id, hidden_user_id) = 1`;
 *  - nie można ukryć siebie: `hidden_user_id <> user_id`;
 *  - jeden wiersz na parę (widz, wpis) i (widz, osoba) — indeksy unikalne
 *    częściowe; ponowne ukrycie przedłuża istniejący wiersz, nie dubluje go.
 *  - `ON DELETE CASCADE` z trzech stron: usunięcie konta widza zabiera jego
 *    ukrycia, usunięcie wpisu albo konta ukrytej osoby — wiersze o nich.
 *
 * BEZ AGREGACJI. Ta tabela nie jest czytana nigdzie poza listami widza
 * i filtrami jego własnych strumieni — ani przez moderację, ani przez
 * analitykę, ani przez nic, co mówi cokolwiek o autorze (EROD 3/2025 pkt 95).
 *
 * ROLLBACK (D-088). `down()` ODMAWIA, gdy jest choć jedno AKTYWNE ukrycie
 * (na stałe albo z terminem w przyszłości): zrzucenie tabeli cicho
 * przywróciłoby ludziom na ekran wpisy i osoby, które świadomie schowali,
 * a kolejny `migrate` odtworzyłby pustą tabelę bez śladu błędu. Na świeżej
 * bazie i przy samych wygasłych ukryciach rollback przechodzi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hides', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('post_id')->nullable()->constrained('posts')->cascadeOnDelete();
            $table->foreignUuid('hidden_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestampTz('hidden_until')->nullable();
            $table->timestampsTz();

            // Klucze obce po stronie ukrywanego obiektu — kaskada przy
            // usunięciu wpisu albo konta nie może skanować całej tabeli.
            $table->index('post_id');
            $table->index('hidden_user_id');
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE hides ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement('ALTER TABLE hides ADD CONSTRAINT hides_one_target_check CHECK (num_nonnulls(post_id, hidden_user_id) = 1)');
            DB::statement('ALTER TABLE hides ADD CONSTRAINT hides_not_self_check CHECK (hidden_user_id IS NULL OR hidden_user_id <> user_id)');
            DB::statement('CREATE UNIQUE INDEX hides_user_post_unique ON hides (user_id, post_id) WHERE post_id IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX hides_user_person_unique ON hides (user_id, hidden_user_id) WHERE hidden_user_id IS NOT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hides')) {
            $aktywne = DB::table('hides')
                ->where(fn ($q) => $q->whereNull('hidden_until')->orWhere('hidden_until', '>', now()))
                ->count();

            if ($aktywne > 0) {
                throw new RuntimeException(
                    "Nie cofam tabeli hides: {$aktywne} aktywnych ukryć. Zrzucenie tabeli przywróciłoby ludziom "
                    .'na ekran wpisy i osoby, które sami schowali (D-088). Jeśli rollback jest konieczny, najpierw '
                    .'zrób kopię: \\copy hides TO hides.csv CSV HEADER, uzgodnij z właścicielem, czy ukrycia mają '
                    .'wrócić po ponownym wdrożeniu, i dopiero wtedy usuń wiersze ręcznie.'
                );
            }
        }

        Schema::dropIfExists('hides');
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
};
