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
