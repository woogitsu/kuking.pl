<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tag tygodnia — wyróżnienie tagu na określone dni (issue #18).
 *
 * DLACZEGO OSOBNA TABELA, A NIE DATY NA `tag_promotions`
 * `tag_promotions` ma klucz główny `tag_id` — jeden tag, jedna promocja, bez
 * historii. Tag tygodnia potrzebuje dwóch rzeczy, których tamta tabela dać
 * nie może: tego samego tagu wyróżnionego drugi raz za rok (pierogi co
 * grudzień) i archiwum zakończonych wyróżnień. Lista promowanych zostaje
 * bez zmian; to jest rytm tygodnia OBOK niej, nie druga taksonomia
 * i nie powrót `Topic` — wiersz wskazuje zwykły tag.
 *
 * JEDNOZNACZNY STAN AKTYWNY JEST W BAZIE, NIE W PHP
 * `EXCLUDE` na zakresie dni zabrania dwóch wyróżnień nachodzących na siebie,
 * więc „które jest dziś" ma zawsze najwyżej jedną odpowiedź — także przy
 * dwóch gospodarzach zapisujących naraz. Daty są `date` (dzień w strefie
 * `kuking.strefa`), bo „tydzień od poniedziałku do niedzieli" jest decyzją
 * o dniach, nie o momentach.
 *
 * ROLLBACK: `DROP TABLE tag_highlights` bez strażnika D-088. To wybór
 * redakcyjny, jak `tag_promotions` — nie zgoda, prywatność ani zakres
 * usunięcia danych. Traci się plan i archiwum wyróżnień; tagi, wpisy
 * i strony `/tag/{tag}` zostają nietknięte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tag_highlights', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            // Jedno zdanie od gospodarza, jak `tag_promotions.note`.
            $table->string('note', 200)->nullable();
            $table->timestampsTz();

            $table->index(['starts_on', 'ends_on']);
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE tag_highlights ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement('ALTER TABLE tag_highlights ADD CONSTRAINT tag_highlights_dates_check CHECK (ends_on >= starts_on)');
            DB::statement("ALTER TABLE tag_highlights ADD CONSTRAINT tag_highlights_no_overlap EXCLUDE USING gist (daterange(starts_on, ends_on, '[]') WITH &&)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_highlights');
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
};
