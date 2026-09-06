<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Odwołania od decyzji moderacyjnych (issue #10, DSA art. 17 i 20).
 *
 * Do tej pory „możesz się odwołać" było zdaniem w powiadomieniu i w
 * podręczniku moderacji, bez żadnego mechanizmu pod spodem. Odwołanie szło
 * na adres e-mail: nie było go w logu, nie dawało się na nie odpowiedzieć
 * w produkcie i nikt nie wiedział, ile ich leży i od kiedy.
 *
 * JEDNO ODWOŁANIE NA JEDNĄ DECYZJĘ — `UNIQUE (moderation_action_id)`
 *
 * Limit jest w BAZIE, nie tylko w kontrolerze, bo to jedyne miejsce, którego
 * nie da się obejść drugim endpointem ani podwójnym kliknięciem. Przy zespole
 * 1-2 osób (docs/legal/MODERATION_PLAYBOOK.md §8) jedna zdeterminowana osoba
 * bez limitu potrafi zająć całą moderację na tydzień, a każde kolejne pismo
 * w tej samej sprawie nie wnosi nowych faktów, tylko emocje.
 *
 * Termin na złożenie odwołania (14 dni od decyzji) egzekwuje kod, nie baza:
 * jest liczony względem `moderation_actions.created_at`, a CHECK nie sięga
 * do drugiej tabeli. Patrz `ModerationAction::appealDeadline()`.
 *
 * `decision_note` TO ODPOWIEDŹ, KTÓRĄ CZŁOWIEK PRZECZYTA
 *
 * DSA art. 20 wymaga rozpatrzenia odwołania i UZASADNIONEJ odpowiedzi, nie
 * samego przycisku. Dlatego CHECK pilnuje, że odwołanie zamknięte ma i datę,
 * i uzasadnienie — „podtrzymuję" bez zdania wyjaśniającego nie jest
 * odpowiedzią i nie da się go zapisać.
 *
 * ROLLBACK
 * `down()` kasuje tabelę. To jest utrata danych: odwołania i odpowiedzi na nie
 * znikają bezpowrotnie. Przed cofnięciem tej migracji na produkcji zrób
 * `COPY appeals TO ...` — inaczej tracisz dowód, że odpowiedzieliśmy
 * (a to jest dokładnie ten dokument, o który zapyta regulator).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appeals', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Decyzja, od której się odwołujemy. Kasowanie kaskadowe, bo
            // odwołanie bez decyzji nie znaczy nic.
            $table->foreignUuid('moderation_action_id')->unique()
                ->constrained('moderation_actions')->cascadeOnDelete();

            // Osoba, która się odwołuje. Kasowane razem z kontem — to jej
            // dane osobowe, a sprawa po usunięciu konta jest bezprzedmiotowa
            // (RODO art. 17). Ślad samej DECYZJI zostaje w `moderation_actions`.
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('body', 2000);

            $table->string('status', 20)->default('open');

            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 2000)->nullable();
            $table->timestampTz('decided_at')->nullable();

            $table->timestampsTz();
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE appeals ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement("ALTER TABLE appeals ADD CONSTRAINT appeals_status_check CHECK (status IN ('open','upheld','overturned'))");

            // Rozpatrzone znaczy: jest data ORAZ jest uzasadnienie.
            // Otwarte znaczy: nie ma ani jednego, ani drugiego.
            DB::statement("ALTER TABLE appeals ADD CONSTRAINT appeals_decision_complete_check CHECK ((status = 'open' AND decided_at IS NULL AND decision_note IS NULL) OR (status <> 'open' AND decided_at IS NOT NULL AND decision_note IS NOT NULL))");

            // Kolejka moderatora: najstarsze otwarte na górze, bo termin
            // odpowiedzi liczy się od zgłoszenia.
            DB::statement('CREATE INDEX appeals_status_created_idx ON appeals (status, created_at)');
            DB::statement('CREATE INDEX appeals_user_idx ON appeals (user_id, created_at DESC)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('appeals');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
