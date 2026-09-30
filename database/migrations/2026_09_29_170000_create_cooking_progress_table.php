<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Opcjonalna synchronizacja postępu gotowania między urządzeniami (#2016, V2).
 *
 * CO TU JEST
 * Tabela `cooking_progress`: jeden wiersz = „ta osoba włączyła zapamiętywanie
 * postępu tego przepisu na koncie” + lista ID odhaczonych kroków. Obecność
 * wiersza JEST zgodą: domyślnie (i dla gości) postęp zostaje w sesji, jak do
 * tej pory (`CookingModeController`). Osoba włącza to świadomie, osobno dla
 * każdego przepisu, przyciskiem w trybie gotowania, i tak samo wyłącza —
 * wyłączenie kasuje wiersz. Dzięki temu nie trzeba nowej kolumny w gorącej
 * tabeli `users` (AGENTS.md §6).
 *
 * CO SYNCHRONIZUJEMY: wyłącznie odhaczone kroki, po ID kroku (nie po
 * numerze — #756). Składniki „przygotowane” (#2069) i porcje dochodzą w etapie 2
 * (migracja `2026_09_29_193700`); minutniki zostają w przeglądarce.
 *
 * RETENCJA: `expires_at` (domyślnie 24 godziny od ostatniej zmiany,
 * `config('kuking.cooking_progress.retention_hours')`). Wygasły wiersz jest
 * traktowany jak brak wiersza, a `kuking:sprzataj-postep-gotowania` kasuje go
 * co noc.
 *
 * KONFLIKT DWÓCH URZĄDZEŃ: `revision` rośnie o 1 przy każdej zmianie.
 * Zmiana kroku jest idempotentnym ustawieniem „ten krok: zrobiony/nie”, więc
 * dwa urządzenia klikające różne kroki nic sobie nie gubią; na ten sam krok
 * wygrywa ostatni zapis. Formularz niesie rewizję, którą widział, i przy
 * rozbieżności osoba dostaje komunikat, że postęp zmienił się gdzie indziej.
 *
 * PRYWATNOŚĆ: widoczne wyłącznie dla właściciela (`CookingProgressPolicy`),
 * odczyt i zapis zawsze przechodzą też przez `RecipePolicy::view`; jest
 * w paczce danych (`postep_gotowania`) i znika przy wymazaniu konta.
 *
 * ROLLBACK
 * `down()` usuwa tabelę. Wiersze są krótkotrwałe (24 h), ale to dane wpisane
 * przez ludzi w trakcie gotowania, więc `down()` ODMAWIA, gdy w tabeli jest
 * choć jeden niewygasły wiersz (D-088: odmowa wąska — świeża baza, CI i tabela
 * z samymi wygasłymi wierszami przechodzą bez pytania). Wymuszenie:
 * `KUKING_ROLLBACK_KASUJE_POSTEP_GOTOWANIA=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cooking_progress', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->jsonb('done_step_ids')->default('[]');
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->unique(['user_id', 'recipe_id']);
            $table->index('expires_at');
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE cooking_progress ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement(
                'ALTER TABLE cooking_progress ADD CONSTRAINT cooking_progress_done_check '
                ."CHECK (jsonb_typeof(done_step_ids) = 'array' AND jsonb_array_length(done_step_ids) <= 200)",
            );
            DB::statement('ALTER TABLE cooking_progress ADD CONSTRAINT cooking_progress_revision_check CHECK (revision >= 1)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cooking_progress')) {
            $this->upewnijSieZeWolnoKasowac();
        }

        Schema::dropIfExists('cooking_progress');
    }

    private function upewnijSieZeWolnoKasowac(): void
    {
        $ile = (int) DB::table('cooking_progress')->where('expires_at', '>', now())->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null.
        if (getenv('KUKING_ROLLBACK_KASUJE_POSTEP_GOTOWANIA') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji skasuje zapamiętany postęp gotowania — bezpowrotnie.
            Liczba niewygasłych postępów, które znikną: {$ile}.

            Zanim cofniesz:
              1. zrób kopię tabeli:
                 CREATE TABLE cooking_progress_kopia AS SELECT * FROM cooking_progress;
              2. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — usterkę ekranu
                 gotowania naprawia się bez ruszania bazy, a postęp i tak wygasa po 24 godzinach
                 (`php artisan kuking:sprzataj-postep-gotowania --wszystkie` skasuje go od razu);
              3. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_KASUJE_POSTEP_GOTOWANIA=1.

            Na świeżym środowisku albo przy samych wygasłych wierszach cofnięcie działa bez pytania.
            TEKST);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
