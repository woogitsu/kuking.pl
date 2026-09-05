# Model danych

## Zasady

- UUID dla publicznych encji;
- `timestamptz`;
- realne foreign keys;
- constraints w bazie;
- soft delete tam, gdzie pomaga odzyskiwaniu/moderacji;
- JSONB tylko dla półstrukturalnych danych;
- recipe versions od początku.

## Tabele MVP

### users
Konto:
- id;
- email;
- password;
- status;
- locale;
- text_scale;
- verified timestamps.

### profiles
- user_id;
- username;
- display_name;
- bio;
- avatar.

### follows
`follower_id + followed_id` unique.

### blocks
Blokada ma pierwszeństwo przed follow.

### media
Tylko metadata, nie binary:
- owner;
- disk;
- object key;
- MIME;
- bytes;
- width/height;
- status;
- checksum;
- perceptual hash;
- metadata.

### posts + post_media
Najprostszy content społecznościowy.

### recipes
Aktualny stan.

### recipe_versions
Snapshot po istotnych zmianach.

### ingredients + units
Podstawa search i późniejszego planera.

### recipe_ingredients
Musi mieć `ingredient_text`, nawet jeśli normalizacja nie rozpozna składnika.

### recipe_steps
Pozycja + instruction + opcjonalny timer/media.

### cooked_events
Jedno realne gotowanie. Brak unique `(user_id, recipe_id)`.

### comments
Komentarz dotyczy dokładnie jednego:
- post;
- recipe;
- cooked event.

### collections + collection_items
Osobisty zeszyt.

### notifications
In-app.

### reports
Zgłoszenia.

### moderation_actions
Decyzje moderatorów.

### audit_log
Wysokiego znaczenia zmiany.

## V1 / V2

Później:
- groups;
- group_members;
- recipe_forks;
- family_books;
- questions;
- answers;
- meal_plans;
- shopping_lists;
- pantry_items;
- tags;
- subscriptions;
- payments.

## Wyszukiwarka: funkcja `kuking_normalize()`

Migracja `2026_09_05_001300_fix_search_indexes` wprowadza funkcję:

```sql
CREATE FUNCTION public.kuking_normalize(text) RETURNS text
AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, lower($1)) $$
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE;
```

**Po co:** indeksy trigramowe muszą stać na DOKŁADNIE tym samym wyrażeniu,
którego używa zapytanie. Pierwotne indeksy stały na surowych kolumnach
(`gin (title gin_trgm_ops)`), a `SearchQuery` pytał o `unaccent(lower(title))` —
w efekcie żaden indeks nie był używany i każde wyszukiwanie skanowało całą
tabelę. Potwierdzone `EXPLAIN`-em przy `enable_seqscan = off`.

**Dlaczego własna funkcja, a nie `unaccent()` wprost:** `unaccent()` nie jest
`IMMUTABLE` (zależy od słownika), a PostgreSQL nie pozwala indeksować wyrażeń
nieimmutable. Opakowanie z jawnie wskazanym słownikiem to udokumentowane
obejście.

⚠️ **Dlaczego wszystko jest kwalifikowane `public.`:** od PostgreSQL 17
operacje utrzymaniowe — w tym `CREATE INDEX` i `REINDEX` — wykonują się
z ograniczonym `search_path` (`pg_catalog, pg_temp`). Ciało funkcji SQL jest
re-parsowane przy inliningu, więc niekwalifikowane `unaccent(...)` przestaje
być widoczne i budowanie indeksu pada:

```text
ERROR:  function unaccent(unknown, text) does not exist
CONTEXT:  SQL function "kuking_normalize" during inlining
```

Na PostgreSQL 16 to przechodziło, więc błąd był niewidoczny lokalnie
i wyszedł dopiero przy pierwszym przebiegu CI na `postgres:18`. Dlatego
rozszerzenia zakładamy jawnie `WITH SCHEMA public`, a funkcja woła
`public.unaccent` ze słownikiem `'public.unaccent'::regdictionary`.
**Nie polegaj tu na `search_path` — przy budowaniu indeksu go nie ma.**

Pilnują tego dwa testy: `RegressionTest::test_normalizacja_dziala_przy_ograniczonym_search_path`
oraz `::test_indeks_na_kuking_normalize_da_sie_zbudowac`. Oba wymuszają
ograniczony `search_path` ręcznie, więc łapią regresję także na PostgreSQL 16.

⚠️ **Konsekwencja:** podmiana słownika `unaccent` wymagałaby `REINDEX`.
Nie robimy tego.

**Zasada dla przyszłych zmian:** jeśli zmieniasz wyrażenie w
`App\Domain\Search\SearchQuery`, zmień też indeksy. Pilnuje tego test
`RegressionTest::test_wyszukiwarka_korzysta_z_indeksu_trigramowego`, który
wyłącza skan sekwencyjny i sprawdza plan zapytania.

Indeksy na tej funkcji: `profiles` (username, display_name, speciality),
`recipes` (title, summary), `ingredients` (normalized_name),
`recipe_ingredients` (ingredient_text).

## `daily_picks`

Wybór redakcyjny na tablicę „kuKINGi na dziś". Świadomie bez kolumny
z punktami, liczbą polubień ani wynikiem — to nie jest tabela rankingowa
(patrz `../AGENTS.md` §8).

## Normalizacja adresu e-mail

`User::email` ma mutator wymuszający małe litery i przycięcie spacji.
PostgreSQL porównuje teksty z uwzględnieniem wielkości liter, a klawiatury
telefonów kapitalizują pierwszą literę — bez tego konto założone jako
`Jan@example.com` było nie do zalogowania przez `jan@example.com`.

Pełny referencyjny DDL jest w `database/reference/schema_mvp.sql`.
