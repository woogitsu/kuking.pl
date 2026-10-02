<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Odzyskanie omyłkowo usuniętego prywatnego zeszytu (#2567, V2, D-333).
 *
 * CO TU JEST
 * Tabela `deleted_collections`: krótka, ograniczona KOPIA ODZYSKANIA zeszytu,
 * który jego właściciel właśnie usunął. Jeden wiersz = jeden usunięty zeszyt:
 * nazwa, opis, data założenia i pozycje (`items`, jsonb: wskazanie przepisu
 * albo wpisu, własny dopisek i oryginalna data zapisania). Kopia powstaje w tej
 * samej transakcji co usunięcie zeszytu (`UsunZeszyt`) i tylko dla zeszytu
 * prywatnego, bez członków i bez oczekujących zaproszeń.
 *
 * DLACZEGO KOPIA, A NIE `deleted_at` NA `collections`
 * Miękkie usunięcie zeszytu zostawiłoby jego pozycje w `collection_items`,
 * a na tę tabelę patrzą liczniki, eksport, „kto zapisał mój przepis”, unikalna
 * nazwa zeszytu i unikalny zeszyt domyślny. Każde z tych miejsc musiałoby
 * pamiętać o filtrze. Kopia nie zmienia znaczenia żadnej istniejącej tabeli.
 *
 * CZEGO KOPIA NIE NIESIE: tytułów, tekstów ani zdjęć cudzych przepisów i wpisów
 * — tylko identyfikatory. Przy odzyskaniu pozycja wraca wyłącznie wtedy, gdy
 * jej cel nadal istnieje; dostępu do niego i tak rozstrzyga zwykła widoczność.
 *
 * KLUCZE I OGRANICZENIA
 *  - `owner_id` -> `users` `ON DELETE CASCADE`; wymazanie konta kasuje też kopie
 *    jawnie (`EraseAccountData`), bo konto się anonimizuje;
 *  - `collection_id` UNIQUE: ten sam zeszyt nie ma dwóch kopii, a odzyskany
 *    zeszyt wraca pod swoim dawnym identyfikatorem;
 *  - CHECK-i: tablica pozycji, liczba pozycji, długość nazwy;
 *  - indeks po `deleted_at` obsługuje nocne sprzątanie po upływie okna
 *    `kuking.usuniete_tresci.retention_days` (to samo okno co przepisy).
 *
 * Nowa tabela — reguły `lock_timeout`/`NOT VALID` z AGENTS.md §6 jej nie
 * dotyczą, nikt jeszcze na nią nie czeka.
 *
 * ROLLBACK
 * `down()` usuwa tabelę, ale ODMAWIA, gdy jest choć jedna kopia w oknie
 * odzyskania (D-088: odmowa wąska — świeża baza, CI i tabela z samymi
 * przedawnionymi kopiami przechodzą bez pytania). Wymuszenie po kopii tabeli:
 * `KUKING_ROLLBACK_KASUJE_USUNIETE_ZESZYTY=1`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deleted_collections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();
            // Dawny identyfikator zeszytu — bez klucza obcego (zeszytu już nie ma).
            $table->uuid('collection_id')->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->timestampTz('collection_created_at');
            $table->jsonb('items')->default('[]');
            $table->unsignedInteger('items_count')->default(0);
            $table->timestampTz('deleted_at')->useCurrent();

            $table->index(['owner_id', 'deleted_at']);
            $table->index('deleted_at');
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE deleted_collections ALTER COLUMN id SET DEFAULT gen_random_uuid()');
            DB::statement("ALTER TABLE deleted_collections ADD CONSTRAINT deleted_collections_items_check CHECK (jsonb_typeof(items) = 'array' AND jsonb_array_length(items) = items_count)");
            DB::statement('ALTER TABLE deleted_collections ADD CONSTRAINT deleted_collections_name_check CHECK (char_length(name) BETWEEN 1 AND 120)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('deleted_collections')) {
            $this->upewnijSieZeWolnoKasowac();
        }

        Schema::dropIfExists('deleted_collections');
    }

    private function upewnijSieZeWolnoKasowac(): void
    {
        $dni = max(1, (int) config('kuking.usuniete_tresci.retention_days'));

        $ile = (int) DB::table('deleted_collections')
            ->where('deleted_at', '>', now()->subDays($dni))
            ->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null.
        if (getenv('KUKING_ROLLBACK_KASUJE_USUNIETE_ZESZYTY') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji skasuje kopie odzyskania usuniętych zeszytów — bezpowrotnie.
            Liczba kopii w oknie odzyskania, które znikną: {$ile}.
            Ludzie, którzy usunęli zeszyt przez pomyłkę, stracą jedyną drogę, żeby go odzyskać
            (razem z własnymi dopiskami i datami zapisania).

            Zanim cofniesz:
              1. zrób kopię tabeli:
                 CREATE TABLE deleted_collections_kopia AS SELECT * FROM deleted_collections;
              2. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — kod sprzed tej migracji
                 po prostu usuwa zeszyty na stałe, więc przy awaryjnym rollbacku WDROŻENIA nie
                 trzeba cofać bazy;
              3. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_KASUJE_USUNIETE_ZESZYTY=1.

            Na świeżym środowisku albo przy samych przedawnionych kopiach cofnięcie działa bez pytania.
            TEKST);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
