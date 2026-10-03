<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Odzyskanie wcześniejszego tekstu prywatnego szkicu (#2512, V2, D-333).
 *
 * CO TU JEST
 * Tabela `draft_restore_points`: JEDEN ograniczony punkt odzyskania na szkic.
 * Powstaje, gdy autor otwiera swój istniejący szkic w kreatorze i szkic nie ma
 * jeszcze punktu; trzyma TEKST sprzed tej sesji edycji (`snapshot`, jsonb:
 * tytuł, opis, porcje, czasy, trudność, pochodzenie, składniki z grupami
 * i „Bez ilości”, kroki z minutnikami). NIE trzyma zdjęć (tylko wskazanie
 * `media_id` przy kroku, żeby przywrócenie nie odpięło aktualnych zdjęć),
 * alergenów, widoczności ani niczego spoza szkicu.
 *
 * DLACZEGO JEDEN PUNKT, A NIE HISTORIA
 * Autozapis nie tworzy wersji (decyzja z 24.09.2026) i nadal nie tworzy:
 * punkt NIE powstaje przy każdym zapisie. Ponowne otwarcie szkicu po pomyłce
 * nie zastępuje użytecznej kopii już uszkodzonym stanem — istniejący punkt
 * zostaje do przedawnienia (`kuking.przepisy.szkic_punkt_odzyskania_dni`)
 * albo do przywrócenia, które zamienia go miejscami z bieżącym tekstem.
 *
 * KLUCZE I OGRANICZENIA
 *  - `recipe_id` -> `recipes` `ON DELETE CASCADE` + UNIQUE (jeden punkt na szkic);
 *  - `user_id` -> `users` `ON DELETE CASCADE` (autor szkicu; wymazanie konta
 *    kasuje punkty jawnie w `EraseAccountData`, bo konto się anonimizuje);
 *  - CHECK: `snapshot` jest obiektem JSON;
 *  - indeks po `taken_at` obsługuje nocne sprzątanie.
 *
 * Nowa tabela — reguły `lock_timeout`/`NOT VALID` z AGENTS.md §6 jej nie
 * dotyczą, nikt jeszcze na nią nie czeka.
 *
 * ROLLBACK
 * `down()` usuwa tabelę, ale ODMAWIA, gdy jest choć jeden punkt w oknie
 * odzyskania (D-088: odmowa wąska — świeża baza, CI i tabela z samymi
 * przedawnionymi punktami przechodzą bez pytania). Wymuszenie po kopii tabeli:
 * `KUKING_ROLLBACK_KASUJE_PUNKTY_ODZYSKANIA_SZKICU=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draft_restore_points', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recipe_id')->unique()->constrained('recipes')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->jsonb('snapshot');
            $table->timestampTz('taken_at')->useCurrent();

            $table->index('taken_at');
            $table->index('user_id');
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE draft_restore_points ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement("ALTER TABLE draft_restore_points ADD CONSTRAINT draft_restore_points_snapshot_check CHECK (jsonb_typeof(snapshot) = 'object')");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('draft_restore_points')) {
            $this->upewnijSieZeWolnoKasowac();
        }

        Schema::dropIfExists('draft_restore_points');
    }

    private function upewnijSieZeWolnoKasowac(): void
    {
        $dni = max(1, (int) config('kuking.przepisy.szkic_punkt_odzyskania_dni'));

        $ile = (int) DB::table('draft_restore_points')
            ->where('taken_at', '>', now()->subDays($dni))
            ->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null.
        if (getenv('KUKING_ROLLBACK_KASUJE_PUNKTY_ODZYSKANIA_SZKICU') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji skasuje punkty odzyskania tekstu szkiców — bezpowrotnie.
            Liczba punktów w oknie odzyskania, które znikną: {$ile}.
            Ludzie, którzy przypadkowo zastąpili tekst szkicu, stracą jedyną drogę, żeby go odzyskać.

            Zanim cofniesz:
              1. zrób kopię tabeli:
                 CREATE TABLE draft_restore_points_kopia AS SELECT * FROM draft_restore_points;
              2. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — kod sprzed tej migracji
                 po prostu nie tworzy punktów, więc przy awaryjnym rollbacku WDROŻENIA nie trzeba
                 cofać bazy;
              3. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_KASUJE_PUNKTY_ODZYSKANIA_SZKICU=1.

            Na świeżym środowisku albo przy samych przedawnionych punktach cofnięcie działa bez pytania.
            TEKST);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
