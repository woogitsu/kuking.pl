<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Jeden ALTER: kolumny nigdy nie istnieją bez ograniczeń (#371).
        // PostgreSQL nie dopuszcza NUL w text; pozostałe znaki PHP trim
        // to spacja, tabulator, LF, CR i tabulator pionowy (chr(11)).
        DB::statement(<<<'SQL'
            ALTER TABLE posts
                ADD COLUMN kind varchar(20) NOT NULL DEFAULT 'dish',
                ADD COLUMN title varchar(180) NULL,
                ADD CONSTRAINT posts_kind_check CHECK (kind IN ('dish', 'question')),
                ADD CONSTRAINT posts_kind_title_check CHECK (
                    (kind = 'dish' AND title IS NULL)
                    OR (kind = 'question' AND title IS NOT NULL
                        AND char_length(btrim(title, E' \t\n\r' || chr(11))) BETWEEN 10 AND 180)
                )
            SQL);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            // Blokada obejmuje sprawdzenie i DDL, także przy bezpośrednim down().
            DB::statement('LOCK TABLE posts IN ACCESS EXCLUSIVE MODE');

            // Bez scope: ukryte i miękko usunięte pytania też mają znaczenie.
            if (DB::table('posts')->where('kind', 'question')->exists()) {
                throw new RuntimeException(
                    'Nie można cofnąć schematu pytań: istnieją pytania. Zachowaj kolumny kind i title; wycofaj kod aplikacji bez cofania tej migracji.',
                );
            }

            DB::statement(<<<'SQL'
                ALTER TABLE posts
                    DROP CONSTRAINT posts_kind_title_check,
                    DROP CONSTRAINT posts_kind_check,
                    DROP COLUMN title,
                    DROP COLUMN kind
                SQL);
        });
    }
};
