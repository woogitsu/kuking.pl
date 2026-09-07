<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Usunięcie Tematów (D-021, `docs/DECISIONS.md`) — etap 4/5.
 *
 * Tagi przejęły wszystko, co robił Temat: oznaczanie wpisów, obserwowanie,
 * publiczną stronę, feed cold-startu (`TagFeed`) i onboarding
 * (`Tag::promowane()`, lista gospodarza z etapu 1/5 i panelu z etapu 5/5).
 * Żaden kod aplikacji nie odwołuje się już do `Topic`.
 *
 * DLACZEGO STARA MIGRACJA (`2026_09_06_100000_create_topics_tables`)
 * ZOSTAJE NA MIEJSCU
 * Inne środowiska mogły ją już wykonać — przepisywanie historii migracji
 * złamałoby je przy następnym `php artisan migrate`. Ta migracja jest
 * DRUGĄ, osobną operacją: tworzy nic, tylko kasuje to, co tamta stworzyła.
 *
 * SPRAWDZENIE PRZED DESTRUKCYJNĄ OPERACJĄ (SPEC §1.1, doprecyzowane przez
 * R1 §1.4: TRZY osobne zapytania, nie jedno)
 * D-021 zapisuje stan z chwili decyzji: „W lokalnej bazie deweloperskiej:
 * 0 tematów, 0 wpisów z tematem" — ale WPROST zastrzega, że stanu produkcji
 * nie dało się sprawdzić z kontenera roboczego. Migracja więc NIE ufa temu
 * zapisowi i sprawdza sama, tuż przed wykonaniem:
 *
 *   1. `topic_follows` — czy ktokolwiek obserwuje jakikolwiek temat,
 *   2. `posts.topic_id` — czy jakikolwiek wpis ma przypisany temat.
 *
 * (Trzecia rzecz z R1 §1.4 — inne miejsca odwołujące się do `topics.id` —
 * nie istnieje: `grep -rn topic_id` poza tymi dwiema kolumnami i kodem
 * usuniętym w tym samym etapie wraca pusto.)
 *
 * Sama tabela `topics` MA wiersze (seed redakcyjny, ~30 pozycji) i to jest
 * W PORZĄDKU — to dane odtwarzalne z historii git, nie treść użytkownika.
 * Nie sprawdzamy jej liczby wierszy z tego samego powodu, dla którego
 * `TagSeeder` nie boi się nadpisać `TopicSeeder`: seed redakcyjny nie jest
 * „danymi do zachowania" w rozumieniu SPEC §1.1.
 *
 * Jeśli KTÓRAKOLWIEK z dwóch liczb jest niezerowa, migracja PRZERYWA
 * operację (`RuntimeException`) zamiast po cichu skasować dane — dokładnie
 * wzorem `2026_09_06_190000_one_decision_per_report`.
 *
 * ROLLBACK: `down()` odtwarza DOKŁADNIE schemat sprzed tej migracji —
 * bezpiecznie, bez utraty danych, WŁAŚNIE DLATEGO że `up()` odmawia
 * działania, dopóki obie tabele/kolumna są puste. Nie ma więc czego
 * odzyskiwać poza samym kształtem tabel.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->isPostgres()) {
            $ileObserwacji = DB::table('topic_follows')->count();
            $ilePrzypisanychWpisow = DB::table('posts')->whereNotNull('topic_id')->count();

            if ($ileObserwacji > 0 || $ilePrzypisanychWpisow > 0) {
                throw new RuntimeException(
                    'Migracja przerwana: `topic_follows` ma '.$ileObserwacji.' '
                    .'wierszy, a `posts.topic_id` ma '.$ilePrzypisanychWpisow.' niepustych wartości. '
                    .'D-021 (docs/DECISIONS.md) wymaga, żeby właściciel potwierdził stan PRODUKCJI '
                    .'przed usunięciem Tematów — to nie jest zmiana samego schematu, tylko rozmowa '
                    .'z ludźmi, którym coś zniknie z profilu. Żadne dane nie zostały skasowane.',
                );
            }
        }

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('topic_id');
        });

        Schema::dropIfExists('topic_follows');
        Schema::dropIfExists('topics');
    }

    /**
     * Odtwarza schemat sprzed tej migracji — bezpiecznie, bo `up()` nigdy
     * nie skasował ani jednego wiersza rzeczywistych danych (patrz komentarz
     * klasy). Kopia kształtu z `2026_09_06_100000_create_topics_tables`,
     * nie wywołanie tamtej migracji wprost — Laravel nie daje do tego
     * gotowego mechanizmu dla migracji anonimowych klas.
     */
    public function down(): void
    {
        Schema::create('topics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 60)->unique();
            $table->string('name', 80);
            $table->string('description', 200)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('topic_follows', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('topic_id')->constrained('topics')->cascadeOnDelete();
            $table->timestampTz('created_at')->nullable();
            $table->primary(['user_id', 'topic_id']);
            $table->index('topic_id');
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->foreignUuid('topic_id')->nullable()->after('recipe_id')
                ->constrained('topics')->nullOnDelete();
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE topics ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement("ALTER TABLE topics ADD CONSTRAINT topics_slug_check CHECK (slug ~ '^[a-z0-9-]{2,60}$')");
            DB::statement(
                'CREATE INDEX posts_topic_published_idx ON posts (topic_id, published_at DESC, id DESC) '
                ."WHERE status = 'published' AND deleted_at IS NULL",
            );
        }
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
};
