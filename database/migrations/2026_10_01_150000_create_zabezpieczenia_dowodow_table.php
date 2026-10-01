<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REJESTR DOWODÓW ZABEZPIECZONYCH PRZED USUNIĘCIEM (ścieżka CSAM w panelu
 * moderacji, D-333 wiersz z 1 października 2026).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO
 * ────────────────────────────────────────────────────────────────────────
 *
 * Procedura zero-tolerancji (`docs/legal/MODERATION_PLAYBOOK.md` §7.1 pkt 1–2)
 * każe ukryć treść, a jednocześnie „zachować metadane … nie kasować rekordu
 * z bazy przed zgłoszeniem organom”. Dotąd tę gwarancję dawało wyłącznie to,
 * że miękko usunięta treść ma wiersz w `reports`/`moderation_actions` —
 * a te kasuje retencja po 36 miesiącach, a sama treść wtedy wraca do
 * kolejki sprzątania. Autor mógł też skasować własne zdjęcie, a wymazanie
 * konta kasuje wszystko, co jego.
 *
 * Ten rejestr jest JEDNYM miejscem, które mówi: „tego obiektu nie wolno
 * skasować żadną drogą”. Pytają o niego: retencja usuniętych treści,
 * retencja wersji przepisów, retencja spraw moderacyjnych, kasowanie zdjęć,
 * wymazanie konta i przywracanie treści.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO W NIM JEST
 * ────────────────────────────────────────────────────────────────────────
 *
 * Jeden wiersz na obiekt (`target_type` + `target_id`, UNIQUE): wpis,
 * przepis, komentarz albo zdjęcie. `subject_user_id` to autor/właściciel —
 * konto z takim wierszem nie zostaje wymazane (dane do zgłoszenia). Dla
 * zdjęcia `previous_media_status` pamięta stan sprzed zabezpieczenia
 * (zdjęcie dostaje wtedy `status = 'secured'`, patrz migracja
 * `..._allow_secured_media_status`).
 *
 * Wiersza NIE MA JAK ZDJĄĆ z panelu — to świadome. Kiedy i na czyje
 * polecenie wolno skasować dowód, ma rozstrzygnąć prawnik (playbook §7.1a,
 * pytanie 2); do tego czasu zabezpieczenie jest bezterminowe, co jest błędem
 * w bezpieczną stronę.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ROLLBACK (D-088)
 * ────────────────────────────────────────────────────────────────────────
 *
 * `down()` ODMAWIA, gdy w tabeli są wiersze: zrzucenie rejestru zdjęłoby
 * ochronę ze wszystkich dowodów naraz, a najbliższa noc retencji by je
 * skasowała. Na pustej tabeli (świeża baza, `migrate:refresh` w CI)
 * przechodzi bez pytania.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zabezpieczenia_dowodow', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('target_type', 30);
            $table->uuid('target_id');

            // Autor treści / właściciel zdjęcia. `nullOnDelete`: konta się nie
            // kasuje (D-022), ale rejestr nie ma prawa blokować tego, co
            // kiedyś by to zmieniło.
            $table->foreignUuid('subject_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('report_id')->nullable()->constrained('reports')->nullOnDelete();
            $table->foreignUuid('moderation_action_id')->nullable()->constrained('moderation_actions')->nullOnDelete();
            $table->foreignUuid('secured_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('previous_media_status', 20)->nullable();
            $table->string('note', 2000)->nullable();

            $table->timestampTz('secured_at')->useCurrent();
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE zabezpieczenia_dowodow ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement("ALTER TABLE zabezpieczenia_dowodow ADD CONSTRAINT zabezpieczenia_dowodow_target_type_check CHECK (target_type IN ('post','recipe','comment','media'))");
            DB::statement("ALTER TABLE zabezpieczenia_dowodow ADD CONSTRAINT zabezpieczenia_dowodow_previous_media_status_check CHECK ((target_type = 'media' AND previous_media_status IS NOT NULL AND previous_media_status IN ('pending','processing','ready','rejected')) OR (target_type <> 'media' AND previous_media_status IS NULL))");
            DB::statement('CREATE UNIQUE INDEX zabezpieczenia_dowodow_target_unique ON zabezpieczenia_dowodow (target_type, target_id)');
            DB::statement('CREATE INDEX zabezpieczenia_dowodow_subject_idx ON zabezpieczenia_dowodow (subject_user_id) WHERE subject_user_id IS NOT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('zabezpieczenia_dowodow')) {
            $ile = DB::table('zabezpieczenia_dowodow')->count();

            if ($ile > 0) {
                throw new RuntimeException(
                    'Odmawiam cofnięcia migracji: w zabezpieczenia_dowodow jest '.$ile.' zabezpieczonych dowodów. '
                    .'Zrzucenie rejestru zdjęłoby ochronę przed retencją i wymazaniem konta ze wszystkich naraz. '
                    .'CO ZROBIĆ: zachowaj rejestr poza bazą (SELECT * FROM zabezpieczenia_dowodow), '
                    .'upewnij się, że organy dostały to, czego potrzebują, wycofaj kod, który go czyta, '
                    .'i dopiero wtedy — świadomie, za zgodą właściciela — opróżnij tabelę ręcznie.',
                );
            }
        }

        Schema::dropIfExists('zabezpieczenia_dowodow');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
