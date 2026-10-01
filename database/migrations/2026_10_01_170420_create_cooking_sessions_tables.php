<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wspólne gotowanie: sesja jednego przepisu dla gospodarza i pomocnika (#2385).
 *
 * Projekt, autoryzacja i uzasadnienia: `docs/product/PROJEKT_WSPOLNE_GOTOWANIE_2385.md`.
 *
 * CO TU JEST
 * - `cooking_sessions` — sesja: przepis, gospodarz, `status` (pole STERUJĄCE),
 *   `revision` (rośnie przy każdej realnej zmianie postępu) i stały termin
 *   `expires_at` (24 h od założenia). Jedna sesja na parę (gospodarz, przepis).
 * - `cooking_session_participants` — pomocnicy. Gospodarz jest w `host_id`,
 *   nie tutaj; `role` ma dziś jedną wartość (`helper`), ale jest kolumną z
 *   CHECK-iem, żeby nowa rola była świadomą zmianą schematu.
 * - `cooking_session_steps` — wspólny postęp: jeden wiersz = „ten krok jest
 *   zrobiony”. Klucz główny `(session_id, step_id)` sprawia, że dwa
 *   równoczesne odhaczenia tego samego kroku dają JEDEN wiersz bez błędu
 *   (`INSERT … ON CONFLICT DO NOTHING`). `done_by_id`/`done_at` to audyt.
 * - `cooking_session_invitations` — jednorazowy link: w bazie leży wyłącznie
 *   SHA-256 tokenu (`token_hash`, poświadczenie), kasowany przy użyciu i
 *   odwołaniu.
 *
 * DANE I RETENCJA: wiersze potomne znikają kluczem obcym razem z sesją;
 * sesję kasuje gospodarz (zakończenie) albo `kuking:sprzataj-wspolne-gotowanie`
 * po terminie. Konto: gospodarz → jego sesje znikają (CASCADE), pomocnik →
 * jego udział znika, a podpis `done_by_id` przechodzi w NULL.
 *
 * ROLLBACK
 * `down()` usuwa cztery tabele. To dane ludzi w trakcie gotowania, więc
 * `down()` ODMAWIA (D-088), gdy jest choć jedna niewygasła sesja; na świeżej
 * bazie, w CI i przy samych wygasłych sesjach przechodzi bez pytania.
 * Wymuszenie: `KUKING_ROLLBACK_KASUJE_WSPOLNE_GOTOWANIE=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cooking_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->foreignUuid('host_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->unique(['host_id', 'recipe_id']);
            $table->index('expires_at');
            $table->index('recipe_id');
        });

        Schema::create('cooking_session_participants', function (Blueprint $table): void {
            $table->foreignUuid('session_id')->constrained('cooking_sessions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16)->default('helper');
            $table->timestampTz('joined_at');

            $table->primary(['session_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('cooking_session_steps', function (Blueprint $table): void {
            $table->foreignUuid('session_id')->constrained('cooking_sessions')->cascadeOnDelete();
            $table->foreignUuid('step_id')->constrained('recipe_steps')->cascadeOnDelete();
            $table->foreignUuid('done_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('done_at');

            $table->primary(['session_id', 'step_id']);
            $table->index('done_by_id');
        });

        Schema::create('cooking_session_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('cooking_sessions')->cascadeOnDelete();
            $table->string('token_hash', 64)->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestampTz('expires_at');
            $table->foreignUuid('accepted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('responded_at')->nullable();
            $table->timestampsTz();

            $table->index('session_id');
            $table->index('accepted_by_id');
        });

        if ($this->isPostgres()) {
            foreach (['cooking_sessions', 'cooking_session_invitations'] as $tabela) {
                DB::statement("ALTER TABLE {$tabela} ALTER COLUMN id SET DEFAULT gen_random_uuid()");
            }

            DB::statement("ALTER TABLE cooking_sessions ADD CONSTRAINT cooking_sessions_status_check CHECK (status IN ('active'))");
            DB::statement('ALTER TABLE cooking_sessions ADD CONSTRAINT cooking_sessions_revision_check CHECK (revision >= 1)');
            DB::statement('ALTER TABLE cooking_sessions ADD CONSTRAINT cooking_sessions_expiry_check CHECK (expires_at > created_at)');
            DB::statement("ALTER TABLE cooking_session_participants ADD CONSTRAINT cooking_session_participants_role_check CHECK (role IN ('helper'))");
            DB::statement("ALTER TABLE cooking_session_invitations ADD CONSTRAINT cooking_session_invitations_status_check CHECK (status IN ('pending', 'accepted', 'revoked'))");
            // Zużyty albo odwołany link nie może nosić skrótu tokenu, a oczekujący musi.
            DB::statement(
                'ALTER TABLE cooking_session_invitations ADD CONSTRAINT cooking_session_invitations_token_check '
                ."CHECK ((status = 'pending' AND token_hash IS NOT NULL) OR (status <> 'pending' AND token_hash IS NULL))",
            );
            DB::statement(
                'ALTER TABLE cooking_session_invitations ADD CONSTRAINT cooking_session_invitations_accepted_check '
                ."CHECK (status <> 'accepted' OR responded_at IS NOT NULL)",
            );
            DB::statement(
                'CREATE UNIQUE INDEX cooking_session_invitations_token_hash_unique '
                .'ON cooking_session_invitations (token_hash) WHERE token_hash IS NOT NULL',
            );
            // Najwyżej jeden oczekujący link naraz w sesji (nowy unieważnia stary).
            DB::statement(
                'CREATE UNIQUE INDEX cooking_session_invitations_one_pending_idx '
                ."ON cooking_session_invitations (session_id) WHERE status = 'pending'",
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cooking_sessions')) {
            $this->upewnijSieZeWolnoKasowac();
        }

        Schema::dropIfExists('cooking_session_invitations');
        Schema::dropIfExists('cooking_session_steps');
        Schema::dropIfExists('cooking_session_participants');
        Schema::dropIfExists('cooking_sessions');
    }

    private function upewnijSieZeWolnoKasowac(): void
    {
        $ile = (int) DB::table('cooking_sessions')->where('expires_at', '>', now())->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null.
        if (getenv('KUKING_ROLLBACK_KASUJE_WSPOLNE_GOTOWANIE') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji skasuje trwające wspólne gotowania — bezpowrotnie,
            razem z odhaczeniami i zaproszeniami. Liczba niewygasłych sesji, które znikną: {$ile}.

            Zanim cofniesz:
              1. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — usterkę ekranu sesji
                 naprawia się bez ruszania bazy, a sesja i tak wygasa po 24 godzinach
                 (`php artisan kuking:sprzataj-wspolne-gotowanie --wszystkie` skasuje je od razu);
              2. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_KASUJE_WSPOLNE_GOTOWANIE=1.

            Na świeżym środowisku albo przy samych wygasłych sesjach cofnięcie działa bez pytania.
            TEKST);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
