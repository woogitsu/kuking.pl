---
name: kuking-migracja
description: Zmienia schemat bazy Kuking.pl — migracja PostgreSQL z ograniczeniami, model, test, aktualizacja docs/DATABASE.md i plan rollbacku. Użyj przy dodawaniu tabeli, kolumny, indeksu albo zmianie modelu danych w tym repozytorium.
---

# Zmiana schematu w Kuking

Każda zmiana schematu wymaga **czterech** rzeczy. Trzy z czterech to za mało.

1. migracja,
2. test,
3. aktualizacja `docs/DATABASE.md`,
4. opis rollbacku (albo wyjaśnienie, dlaczego rollback nie jest bezpieczny).

## Wzorzec migracji

Migracje Kuking używają PostgreSQL świadomie i wprost. Ograniczenia idą
**do bazy**, nie tylko do walidatora w PHP — walidator da się ominąć nowym
endpointem, `CHECK` w bazie nie.

```php
Schema::create('nazwa', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();
    $table->timestampsTz();
});

if ($this->isPostgres()) {
    DB::statement('ALTER TABLE nazwa ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    DB::statement("ALTER TABLE nazwa ADD CONSTRAINT nazwa_status_check CHECK (status IN ('a','b'))");
    DB::statement('CREATE INDEX nazwa_idx ON nazwa (owner_id, created_at DESC) WHERE deleted_at IS NULL');
}
```

Zasady:

- UUID dla encji publicznych, `timestampTz`/`timestampsTz` dla czasu,
- prawdziwe klucze obce i `CHECK`-i,
- indeksy częściowe tam, gdzie zapytanie i tak filtruje po `deleted_at`/`status`,
- `pg_trgm` (GIN) dla kolumn, po których się szuka,
- JSONB tylko dla danych półstrukturalnych.

**Klucz obcy wskazujący na własną tabelę** dodawaj osobnym `Schema::table()`
po `Schema::create()` — w jednym `CREATE TABLE` PostgreSQL nie widzi jeszcze
własnego klucza głównego.

**`down()` musi działać.** CI woła `migrate:refresh`, który to sprawdza.

## Istniejąca tabela: CONCURRENTLY i NOT VALID (AGENTS.md §6, audyt B3 W3)

Migracje chodzą na żywej bazie, każda z `lock_timeout = 5s`
(`App\Support\Baza\LimitBlokadMigracji` — nic nie dopisujesz). Na tabeli,
która już istnieje:

```php
return new class extends Migration
{
    public $withinTransaction = false; // CONCURRENTLY i VALIDATE poza transakcją

    public function up(): void
    {
        // przerwana budowa zostawia INVALID — IF NOT EXISTS by go przepuściło
        if (DB::selectOne("SELECT 1 FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
                WHERE c.relname = 'posts_recipe_idx' AND NOT i.indisvalid") !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS posts_recipe_idx');
        }
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS posts_recipe_idx
            ON posts (recipe_id) WHERE recipe_id IS NOT NULL');

        DB::statement("ALTER TABLE posts ADD CONSTRAINT posts_x_check CHECK (…) NOT VALID");
        DB::statement('ALTER TABLE posts VALIDATE CONSTRAINT posts_x_check');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE posts DROP CONSTRAINT IF EXISTS posts_x_check');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS posts_recipe_idx');
    }
};
```

Test, który woła `up()`/`down()` wprost pod `RefreshDatabase`, chodzi w
transakcji — tam `CONCURRENTLY` jest niemożliwe. Migracja może wtedy
sprawdzić `DB::transactionLevel() === 0` i zbudować indeks zwykłym
`CREATE INDEX`.

## Zakazy

- **Nigdy** `UNIQUE (user_id, recipe_id)` w `cooked_events`. Ta sama osoba może
  gotować ten sam przepis wiele razy i to jest sedno produktu.
- **Nigdy** `status` ani `role` użytkownika w `$fillable`.
- **Nigdy** destrukcyjna operacja na produkcyjnej bazie bez jawnej zgody właściciela.

## Weryfikacja

```bash
php artisan migrate --force        # w przód
php artisan migrate:refresh --force # i z powrotem: sprawdza down()
php artisan test
```

W worktree gita z dowiązanym `vendor` każda komenda artisana potrzebuje
`APP_BASE_PATH=$(pwd)` — bez tego Laravel ładuje trasy i klasy z głównego
katalogu, a testy są fałszywie zielone.
