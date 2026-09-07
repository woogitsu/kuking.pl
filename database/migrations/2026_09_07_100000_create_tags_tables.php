<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tagi — otwarta taksonomia użytkowników, zastępująca Tematy (D-021,
 * `docs/DECISIONS.md`).
 *
 * PO CO CZTERY TABELE, A NIE SZEŚĆ
 * Specyfikacja właściciela projektowała też `tag_relations` (podpowiedzi
 * semantyczne typu „sernik” ↔ „ciasto”) i `tag_merge_suggestions` (skrzynka
 * odbiorcza AI dla kandydatów do scalenia). Niezależny przegląd tej
 * specyfikacji (R1) zalecił odłożenie obu — i to jest rekomendacja, którą tu
 * wdrażamy, ze świadomym odejściem od dosłownego tekstu specyfikacji:
 *
 *   - obie tabele są CZYSTO ADDYTYWNE: żadna istniejąca tabela nie dostaje
 *     do nich klucza obcego, więc dodanie ich później (gdy/jeśli powstanie
 *     prawdziwa potrzeba) nigdy nie wymaga migracji łamiącej dane;
 *   - `tag_relations` przy garstce kont i wpisów dziennie nie ma jeszcze
 *     danych, z których jakikolwiek `weight` miałby sens — a „powiązane
 *     tagi” da się policzyć W LOCIE zapytaniem po `post_tags`, dokładnie tak,
 *     jak `RecipeController` liczy już dziś `withCount('cookedEvents')`
 *     zamiast trzymać osobny licznik;
 *   - jedynym celem istnienia `tag_merge_suggestions` w specyfikacji jest
 *     bycie skrzynką odbiorczą dla pipeline'u AI, który jest OSOBNĄ,
 *     jeszcze nie podjętą decyzją właściciela (AGENTS.md §9 wymienia
 *     „tagowanie” jako zastosowanie AI, ale nie rozstrzyga integracji).
 *     Sama OPERACJA scalenia (transakcyjna, patrz `App\Domain\Tags\Actions\MergeTags`)
 *     nie potrzebuje tej tabeli — potrzebuje tylko decyzji administratora,
 *     podejmowanej ręcznie z panelu.
 *
 * AGENTS.md §3 („Zakaz overengineeringu”) wymaga zmierzonej, udokumentowanej
 * potrzeby przed dołożeniem mechanizmu — przy 20–50 kontach zamkniętej bety
 * tej potrzeby dziś nie ma.
 *
 * DLACZEGO TAKIE, A NIE INNE TYPY KLUCZY (R1 §3, cytowane tu wprost, żeby
 * uzasadnienie żyło przy kodzie, a nie tylko w raporcie przeglądu)
 *
 *   `tags`         → uuid.      Encja PUBLICZNA: ma slug, ma własną stronę
 *                                (/tag/{slug}), jest linkowana z zewnątrz.
 *                                Dokładnie kryterium z `docs/DATABASE.md`
 *                                („UUID dla publicznych encji”) i dokładnie
 *                                kształt tabeli `ingredients`
 *                                (`canonical_name` + `normalized_name unique`).
 *   `tag_aliases`  → bigserial. NIGDY nie jest adresowana z zewnątrz — alias
 *                                nie ma własnej strony (wejście na alias
 *                                prowadzi do tagu kanonicznego). Ten sam
 *                                wybór co `product_signals`/`audit_log`:
 *                                „wiersz nigdy nie jest adresowany z zewnątrz
 *                                ani pokazywany człowiekowi”.
 *   `post_tags`    → bez `id`,  To jest relacja, nie encja — dokładnie ten
 *                    PRIMARY KEY sam wzorzec co `post_media`
 *                    (post_id, tag_id) (`PRIMARY KEY (post_id, media_id)`) i
 *                                `topic_follows`
 *                                (`PRIMARY KEY (user_id, topic_id)`) w tym
 *                                repozytorium. Nazwa w liczbie mnogiej
 *                                (`post_tags`, nie `post_tag`) — bo tak
 *                                nazywa się już `post_media`, nie
 *                                `post_medium`.
 *   `tag_follows`  → bez `id`,  Jeden do jednego z `topic_follows`: ten sam
 *                    PRIMARY KEY kształt klucza głównego i ten sam powód
 *                    (user_id, tag_id) (komentarz przy `topic_follows` w
 *                                `2026_09_06_100000_create_topics_tables.php`
 *                                tłumaczy to wprost, i przenosi się bez
 *                                zmian: to relacja, nie encja).
 *
 * NORMALIZACJA DO UNIKALNOŚCI — POPRAWKA TECHNICZNA Z D-021
 * `tags.normalized_name` powstaje z `mb_strtolower(trim(...))` + redukcja
 * wielokrotnych białych znaków + normalizacja Unicode NFC — BEZ `unaccent`.
 * Istniejąca funkcja `kuking_normalize()` (migracja
 * `2026_09_05_001300_fix_search_indexes`) ROBI `unaccent` i służy WYŁĄCZNIE
 * do wyszukiwania/podpowiadania (indeksy trigramowe niżej) — użycie jej też
 * do unikalności złamałoby wymóg „`zurek` i `żurek` to dwa różne tagi”: oba
 * znormalizowałyby się do tego samego ciągu i drugi z nich nigdy by nie
 * powstał jako osobny wiersz. Dlatego unikalny indeks stoi na zwykłej
 * kolumnie `normalized_name` (liczonej w PHP, patrz `App\Models\Tag`), a nie
 * na wyrażeniu z `kuking_normalize()`.
 *
 * CO NIE JEST TU KASOWANE
 * Stara migracja tworząca Tematy (`2026_09_06_100000_create_topics_tables`)
 * ZOSTAJE bez zmian — inne środowiska mogły ją już wykonać. Tematy znikają
 * osobną, późniejszą migracją (D-021, etap 4), dopiero gdy nic w kodzie już
 * ich nie używa.
 *
 * ROLLBACK: `down()` kasuje wszystkie cztery tabele. Bezpieczne bez
 * zastrzeżeń — to jest funkcja budowana od zera, przy zerowym ruchu
 * produkcyjnym (D-021: „W lokalnej bazie deweloperskiej: 0 tematów, 0 wpisów
 * z tematem” — tagi jeszcze nie istniały, więc na pewno 0 wierszy wszędzie).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Nazwa kanoniczna, z zachowanymi polskimi znakami — to jest to,
            // co widzi człowiek na stronie tagu i pod wpisem.
            $table->string('name', 30);

            // Do UNIKALNOŚCI — patrz komentarz przy migracji wyżej. Liczona
            // w PHP (App\Models\Tag::znormalizujNazwe), nie w bazie, żeby
            // dokładnie ta sama funkcja rządziła zapisem i porównaniem.
            $table->string('normalized_name', 30)->unique();

            // Adres strony tagu (/tag/{slug}). Osobno od nazwy (SPEC §1.2:
            // „slug generować osobno od nazwy wyświetlanej”) — nazwa może
            // mieć spacje i polskie znaki, slug nie.
            $table->string('slug', 40)->unique();

            // active | hidden | merged. CHECK w bazie niżej, tak jak
            // `posts.display_mode` — czwarta wartość nie ma prawa się tu
            // znaleźć żadną drogą, nie tylko przez formularz.
            $table->string('status', 10)->default('active');

            // Wypełnione, gdy status = 'merged' — wskazuje tag kanoniczny,
            // pod którym ten tag teraz żyje (SPEC §1.8: „nie kasować
            // źródłowego tagu twardo”, zachować jako alias).
            //
            // ŚWIADOMIE BEZ `ON DELETE` (ani `nullOnDelete`, ani `cascade`).
            // Domyślne zachowanie PostgreSQL dla foreign key bez klauzuli
            // `ON DELETE` to `NO ACTION` — czyli baza SAMA odmawia skasowania
            // tagu kanonicznego, dopóki są do niego przypięte tagi scalone.
            // To jest dokładnie reguła z R1 §3 („usunięcie tagu kanonicznego
            // powinno być zablokowane, dopóki są do niego przypięte scalone
            // tagi”) — i to jest reguła egzekwowana w BAZIE, nie tylko
            // w PHP (AGENTS.md §6: „prawdziwe klucze obce i prawdziwe
            // CHECK-i w bazie — walidacja w PHP jest dodatkiem, nie
            // zamiennikiem”). Sam mechanizm scalania i tak nigdy nie kasuje
            // wiersza (SPEC §1.8) — ta blokada jest siatką bezpieczeństwa na
            // wypadek pomyłki gdzie indziej (np. w `php artisan tinker`).
            $table->uuid('merged_into_tag_id')->nullable();

            // Tag z początkowej bazy redakcyjnej (SPEC §1.4) — atrybut
            // pochodzenia danych, NIE osobny system widoczny dla
            // użytkownika. Onboarding wybiera z tej puli wąski, ręcznie
            // dobrany podzbiór przez `Tag::scopePromowane()` (D-021, „tag
            // promowany — lista gospodarza") — ta kolumna sama w sobie nie
            // jest tą listą, tylko atrybutem pochodzenia danych.
            $table->boolean('is_seeded')->default(false);

            // Kategoria TECHNICZNA (danie, składnik, kuchnia, technika,
            // okazja, dieta) do sortowania/raportowania seeda — SPEC §1.3
            // wprost: „nie jest osobnym systemem widocznym dla użytkownika”.
            // Nullable: tag dodany przez użytkownika nie ma i nie musi mieć
            // kategorii, żeby powstać.
            $table->string('internal_category', 20)->nullable();

            $table->timestampsTz();
        });

        Schema::create('tag_aliases', function (Blueprint $table): void {
            // bigserial, nie uuid — patrz uzasadnienie przy migracji wyżej.
            $table->id();

            $table->foreignUuid('tag_id')->constrained('tags')->cascadeOnDelete();

            // To, co ktoś naprawdę wpisał/zaimportowano jako wariant
            // (np. „serniki” dla kanonicznego „sernik”).
            $table->string('alias', 30);

            // Do unikalności aliasu — TA SAMA normalizacja co
            // `tags.normalized_name` (bez unaccent). Alias „zurek” dla
            // kanonicznego „żurek” jest dozwolony (SPEC §1.2), ale musi
            // pochodzić z seeda/scalenia/admina — nigdy z automatycznego
            // dopasowania po samym `unaccent`.
            $table->string('normalized_alias', 30);

            // seed | admin | ai_suggestion — skąd wzięła się ta relacja
            // (SPEC §1.3). CHECK w bazie niżej.
            $table->string('source', 20)->default('seed');

            $table->timestampTz('created_at')->useCurrent();

            // Jeden alias wskazuje dokładnie jeden tag kanoniczny — inaczej
            // wejście na alias nie wiedziałoby, dokąd przekierować.
            $table->unique('normalized_alias');

            // Odwrotny odczyt: „wszystkie aliasy tego tagu”, potrzebny m.in.
            // przez `MergeTags` przy przepinaniu aliasów.
            $table->index('tag_id');
        });

        Schema::create('post_tags', function (Blueprint $table): void {
            $table->foreignUuid('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignUuid('tag_id')->constrained('tags')->cascadeOnDelete();

            // Kolejność, w jakiej autor dodawał tagi — pokazywana tak samo
            // jak wybrał, nie alfabetycznie. Ten sam wzorzec co
            // `post_media.position`.
            $table->smallInteger('position')->default(0);

            // Klucz główny na parze zamiast osobnego `id`: to jest relacja,
            // nie encja — dokładnie `post_media` i `topic_follows`. Baza sama
            // pilnuje, że ten sam tag nie da się przypiąć do wpisu dwa razy.
            $table->primary(['post_id', 'tag_id']);

            // Dwie kolejności odczytu: „tagi tego wpisu, w kolejności
            // dodania” (klucz główny + position) i „wpisy z tym tagiem”
            // (ten indeks — bez niego strona tagu skanowałaby całą tabelę).
            $table->index('tag_id');

            // Bez dwóch tagów na tej samej pozycji w jednym wpisie —
            // dokładnie `post_media.unique(['post_id', 'position'])`.
            $table->unique(['post_id', 'position']);
        });

        Schema::create('tag_follows', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->timestampTz('created_at')->nullable();

            // Jeden do jednego z `topic_follows` — patrz uzasadnienie przy
            // migracji wyżej.
            $table->primary(['user_id', 'tag_id']);
            $table->index('tag_id');
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE tags ALTER COLUMN id SET DEFAULT gen_random_uuid()');

            DB::statement("ALTER TABLE tags ADD CONSTRAINT tags_status_check CHECK (status IN ('active','hidden','merged'))");
            DB::statement("ALTER TABLE tags ADD CONSTRAINT tags_slug_check CHECK (slug ~ '^[a-z0-9-]{1,40}$')");

            // Spójność stanu w bazie, nie tylko w PHP: tag „merged” ZAWSZE
            // ma cel scalenia, a tag, który ma cel scalenia, ZAWSZE jest
            // „merged” — jedno bez drugiego to niespójny wiersz, którego
            // żaden kod aplikacji nie powinien umieć wytworzyć, a mimo to
            // baza go odrzuci, gdyby jednak spróbował (np. z `tinkera`).
            DB::statement(
                'ALTER TABLE tags ADD CONSTRAINT tags_merged_consistency_check '
                ."CHECK ((status = 'merged') = (merged_into_tag_id IS NOT NULL))",
            );

            // Self-referencing FK dorzucony PO utworzeniu tabeli — kolumna
            // musi już istnieć, żeby wskazać samą siebie. Świadomie BEZ
            // `ON DELETE` — patrz komentarz przy kolumnie `merged_into_tag_id`
            // wyżej: to jest zamierzona blokada usunięcia tagu kanonicznego.
            Schema::table('tags', function (Blueprint $table): void {
                $table->foreign('merged_into_tag_id')->references('id')->on('tags');
            });

            DB::statement("ALTER TABLE tag_aliases ADD CONSTRAINT tag_aliases_source_check CHECK (source IN ('seed','admin','ai_suggestion'))");
            DB::statement('ALTER TABLE post_tags ADD CONSTRAINT post_tags_position_check CHECK (position >= 0)');

            // Indeksy trigramowe na DOKŁADNIE tym wyrażeniu, którego pyta
            // `App\Domain\Tags\TagSuggester` — inaczej PostgreSQL nie użyje
            // indeksu i każda podpowiedź skanuje całą tabelę (dokładnie ta
            // pułapka, którą opisuje migracja `..._fix_search_indexes`).
            // `kuking_normalize()` istnieje już od tamtej migracji (wcześniej
            // w historii migracji niż ta), więc nie definiujemy jej drugi raz.
            DB::statement('CREATE INDEX tags_name_trgm_idx ON tags USING gin (kuking_normalize(name) gin_trgm_ops)');
            DB::statement('CREATE INDEX tag_aliases_alias_trgm_idx ON tag_aliases USING gin (kuking_normalize(alias) gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_follows');
        Schema::dropIfExists('post_tags');
        Schema::dropIfExists('tag_aliases');
        Schema::dropIfExists('tags');
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
};
