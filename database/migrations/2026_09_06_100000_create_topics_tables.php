<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tematy — zamknięta lista redakcyjna (issue #31, SOUL.md 4.7).
 *
 * PO CO TO JEST
 * Onboarding pytał o zainteresowania i zapisywał odpowiedź DO SESJI, gdzie
 * ginęła po zakończeniu kroku. Marnowaliśmy najcenniejsze dane, jakie mamy
 * przy cold starcie, bo padają w jedynym momencie, w którym człowiek chętnie
 * odpowiada na pytania o siebie.
 *
 * Temat rozwiązuje dwie rzeczy naraz (docs/product/COLD_START.md):
 *
 *   1. RATUJE FEED osoby, która nikogo nie obserwuje. Nowe konto widzi dziś
 *      pustą stronę, a pusty ekran dla kogoś po sześćdziesiątce znaczy
 *      „to nie jest dla mnie" — i taka osoba nie wraca.
 *   2. Mówi, KTÓRE KOŁA TEMATYCZNE otwierać w V1. Zamiast zgadywać,
 *      otwieramy te, na które ludzie już się zapisali.
 *
 * DLACZEGO LISTA JEST ZAMKNIĘTA, A NIE WOLNE TAGI
 * Wolne tagi rozsypują się natychmiast: „zakwas", „na zakwasie", „chleb
 * zakwas", „ZAKWAS". Po miesiącu nie ma czego obserwować, bo każdy wpis
 * ma własny tag. SOUL.md 4.7 rozstrzyga to wprost: około 30 pozycji
 * wybieranych z listy, bez wpisywania własnych, do V1.
 *
 * DLACZEGO `posts.topic_id`, A NIE TABELA WIELE-DO-WIELU
 * Wpis należy do JEDNEGO tematu. Wielokrotny wybór przy publikacji to
 * kolejna decyzja do podjęcia w momencie, w którym chcemy, żeby człowiek
 * po prostu wrzucił zdjęcie — a cel produktowy to poniżej 60 sekund
 * od wejścia do opublikowania. Gdyby kiedyś okazało się, że jeden temat
 * to za mało, tabela pośrednia doda się bez utraty danych.
 *
 * ROLLBACK: down() kasuje obie tabele i kolumnę. Traci przy tym informację,
 * kto co obserwował — ale to są dane odtwarzalne (człowiek wybierze ponownie),
 * a nie treść, której nie da się odzyskać.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Slug jest w adresie strony tematu (/temat/chleb-i-zakwas),
            // więc musi być stabilny. Zmiana slugu to zmiana adresu.
            $table->string('slug', 60)->unique();
            $table->string('name', 80);

            // Krótkie zdanie na stronie tematu. Temat bez opisu wygląda
            // jak kategoria w katalogu, a ma wyglądać jak miejsce.
            $table->string('description', 200)->nullable();

            // Kolejność redakcyjna. Alfabetyczna postawiłaby „Bez mięsa"
            // przed „Obiadami na co dzień", a to nie jest kolejność,
            // w jakiej ludzie o tym myślą.
            $table->unsignedSmallInteger('position')->default(0);

            // Temat wycofany znika z wyboru, ale NIE znika z wpisów, które
            // już go mają — inaczej czyjś wpis straciłby przypisanie
            // przy decyzji redakcyjnej, o której ta osoba nic nie wie.
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
        });

        Schema::create('topic_follows', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('topic_id')->constrained('topics')->cascadeOnDelete();
            $table->timestampTz('created_at')->nullable();

            // Klucz główny na parze zamiast osobnego `id`: to jest relacja,
            // nie encja. Przy okazji baza sama pilnuje, że nie da się
            // zaobserwować tematu dwa razy — a „Obserwuj" bywa klikane
            // dwa razy z niepewności.
            $table->primary(['user_id', 'topic_id']);

            // Odczyt idzie w obie strony: „co obserwuje ta osoba"
            // (klucz główny) i „ile osób obserwuje ten temat" (ten indeks,
            // potrzebny do widoku w panelu).
            $table->index('topic_id');
        });

        Schema::table('posts', function (Blueprint $table): void {
            // `nullOnDelete`, nie `cascade`: skasowanie tematu przez redakcję
            // NIE MOŻE skasować czyichś wpisów. Wpis traci wtedy przypisanie
            // i zostaje — treść człowieka jest ważniejsza niż porządek
            // w słowniku tematów.
            $table->foreignUuid('topic_id')->nullable()->after('recipe_id')
                ->constrained('topics')->nullOnDelete();
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE topics ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement("ALTER TABLE topics ADD CONSTRAINT topics_slug_check CHECK (slug ~ '^[a-z0-9-]{2,60}$')");

            // Strona tematu to lista opublikowanych wpisów w kolejności
            // chronologicznej — dokładnie ten sam kształt zapytania co feed.
            // Indeks częściowy, bo szkiców i ukrytych na tej liście nie ma.
            DB::statement(
                'CREATE INDEX posts_topic_published_idx ON posts (topic_id, published_at DESC, id DESC) '
                ."WHERE status = 'published' AND deleted_at IS NULL",
            );
        }
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('topic_id');
        });

        Schema::dropIfExists('topic_follows');
        Schema::dropIfExists('topics');
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
};
