# Wyszukiwarka i wybory redakcyjne

## Miary kursora dalszych wyników (#2856)

Kursor przepisów zawiera dwie miary podobieństwa, a kursor osób jedną. W SQL
każda jest rzutowana na PostgreSQL `real`. Samo sprawdzenie przez PHP `float`
nie wystarcza: `1e-99` mieści się w PHP `double`, ale PostgreSQL 18 odrzuca
rzutowanie do `real` jako underflow. Przed zapytaniem o wyniki sprawdzamy
oryginalny tekst miary funkcją PostgreSQL `pg_input_is_valid(?, 'real')`.
Niezaakceptowany kursor wraca do istniejącego liczbowego okna `offset`;
prawidłowe zero i podnormalne wartości `real` zachowują kolejność kursorową.
Test HTTP sprawdza konkretne identyfikatory w dalszym oknie obu list, a
kontrola ujemna wyłącza strażnika bez zmiany samego zapytania.

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

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

Indeksy na tej funkcji zostały już tylko dla `ingredients` (normalized_name).
Reszta stoi na kolumnach generowanych — patrz niżej.

## Kolumny `*_search` — znormalizowany tekst leży w tabeli

Migracja `2026_09_09_100000_materialize_search_columns` (issue #116) dokłada
sześć kolumn `GENERATED ALWAYS AS (public.kuking_normalize(...)) STORED`
i przenosi na nie indeksy GIN (nazwy indeksów bez zmian):

| tabela | kolumna generowana | liczona z | indeks |
|---|---|---|---|
| `recipes` | `title_search` | `title` | `recipes_title_trgm_idx` |
| `recipes` | `summary_search` | `coalesce(summary, '')` | `recipes_summary_trgm_idx` |
| `recipe_ingredients` | `ingredient_text_search` | `ingredient_text` | `recipe_ingredients_text_trgm_idx` |
| `profiles` | `display_name_search` | `display_name` | `profiles_display_name_trgm_idx` |
| `profiles` | `username_search` | `username` | `profiles_username_trgm_idx` |
| `profiles` | `speciality_search` | `coalesce(speciality, '')` | `profiles_speciality_trgm_idx` |

**Po co, skoro indeks na wyrażeniu działał.** Bo działał tylko do połowy.
Indeks GIN dla operatora `%` jest **stratny**: oddaje kandydatów, których
PostgreSQL sprawdza jeszcze raz na wierszu tabeli. Przy progu podobieństwa
0,12 (`App\Support\ProgPodobienstwa`) kandydatów jest 35–60% tabeli, a każdy
recheck liczył `unaccent()` po słowniku od nowa. Zmierzone na 10 000 kont /
40 000 przepisów: `SearchQuery::recipes('pierogi')` 160 ms → 119 ms, a sama
gałąź trigramowa 118,8 → 81,8 ms przy **identycznym** zbiorze wyników.
Pełny pomiar, plany zapytań i to, czego ta zmiana NIE naprawia:
`docs/research/WYDAJNOSC.md` §3.4a.

**AKTUALIZACJA 9 września 2026 (issue #187): tych kolumn i indeksów używa dziś
INNY OPERATOR.** Wyszukiwarka i podpowiedzi tagów pytają operatorem `<%`
(`word_similarity`, próg **0,5**, `App\Support\ProgPodobienstwa`), a nie `%`
z progiem 0,12. Powód jest produktowy, nie kosztowy: `%` mierzy podobieństwo
frazy do CAŁEGO tytułu, więc przy tak niskim progu „rosół" znajdował
„Rogaliki", a „sajgonki z krewetkami" — 1 526 wierszy w bazie bez jednej
sajgonki. **Schemat się przez to nie zmienił i nie było migracji:**
`gin_trgm_ops` obsługuje oba operatory tym samym indeksem (dla `<%` przez
komutator `%>`, widać to w `Index Cond`). Zmieniło się natomiast to, co
indeks oddaje: przy `%` 12–20 tysięcy kandydatów na frazę i recheck
odrzucający 90% z nich, przy `<%` tyle kandydatów, ile trafień. Pomiar,
tabela zgubionych trafień i uzasadnienie progu: `docs/research/WYDAJNOSC.md`
§3.4b. Pilnuje tego `TrafnoscWyszukiwarkiTest`.

**Dlaczego kolumna generowana, a nie zwykła + trigger.** Kolumny generowanej
nie da się rozjechać ze źródłem: nie ma do niej drogi zapisu. Trigger da się
wyłączyć, a `UPDATE` z pominięciem triggera zostawiłby wyszukiwarkę szukającą
po starym tytule — usterkę widoczną dopiero wtedy, gdy ktoś nie znajdzie
własnego przepisu. Pilnuje tego `KolumnySzukaniaTest`.

⚠️ **Konsekwencja mocniejsza niż przy indeksie na wyrażeniu:** podmiana
słownika `unaccent` wymaga tu nie `REINDEX`, tylko przeliczenia kolumn
(`ALTER TABLE ... ALTER COLUMN ... DROP EXPRESSION` i dodanie od nowa).
Nie robimy tego.

**Rollback:** `down()` odtwarza indeksy na wyrażeniu i kasuje kolumny —
dokładny stan sprzed migracji, bezstratnie (kolumny są wyliczone z danych,
które zostają). Kosztuje przepisanie trzech tabel pod `ACCESS EXCLUSIVE`,
tak samo jak `up()`; na 40 000 / 80 000 / 10 000 wierszy trwało to ~6 s.
Sprawdza to `KolumnySzukaniaTest::test_cofniecie_migracji_odtwarza_indeksy_na_wyrazeniu`.

## `daily_picks`

Wybór redakcyjny na tablicę „kuKINGi na dziś". Świadomie bez kolumny
z punktami, liczbą polubień ani wynikiem — to nie jest tabela rankingowa
(patrz `../../AGENTS.md` §8).

- `shown_on date NOT NULL` — DZIEŃ, na który wskazanie obowiązuje, a nie
  data wpisania. Wybór na jutro da się przygotować dziś;
- `daily_picks.subject_type varchar(20) NOT NULL` (CHECK: `user` \| `post`)
  + `subject_id uuid NOT NULL` — para „typ + identyfikator" bez klucza obcego,
  bo tablica pokazuje dwie różne rzeczy: konto i wpis (`DailyPick::TYPE_USER`,
  `TYPE_POST`);
- `position smallint NOT NULL DEFAULT 0` (CHECK `>= 0`) — kolejność na
  tablicy, ustawiana ręcznie przez gospodarza;
- `curator_id uuid NULL` → `users` (`ON DELETE SET NULL`) — kto wskazał;
- `daily_picks.note varchar(300) NULL` — zdanie gospodarza przy wskazaniu.
  **Kolumna jest ŻYWA i widoczna dla człowieka.** Zapisuje ją formularz panelu
  (zapis w `app/Domain/Feed/Actions/ZapiszTabliceDnia.php`, odczyt do
  formularza w `DailyBoardController::edit()`), pobiera
  `DailyBoard.php:161-165`, a **wyświetla tablica dnia** —
  `components/kuking-board.blade.php:138` (przy koncie) i `:278` (przy wpisie).
  Asercje: `DailyBoardTest.php:65,317`. `NULL` jest stanem normalnym: gospodarz
  nie musi nic dopisywać;

  > **Sprostowanie z 12 września 2026.** Do tego dnia stało tu „dziś nic tej
  > kolumny nie czyta". **Nieprawda**, z tego samego źródła co przy
  > `collection_items.note` — patrz sprostowanie tam;
- `created_at`.

## `hero_picks`

Zdjęcia wskazane ręcznie do **kolażu w hero strony powitalnej** (migracja
`2026_09_11_800000_utworz_hero_picks`). Zgłoszenie właściciela: „na stronie
głównej na samej górze po prawej stronie można zrobić kolaż w którym będą
najładniejsze (albo wybrane przez admina) zdjęcia użytkowników".

```sql
CREATE TABLE hero_picks (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    media_id    uuid NOT NULL REFERENCES media(id) ON DELETE CASCADE,
    post_id     uuid NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    position    smallint NOT NULL DEFAULT 0,
    curator_id  uuid REFERENCES users(id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE hero_picks ADD CONSTRAINT hero_picks_media_id_unique UNIQUE (media_id);
ALTER TABLE hero_picks ADD CONSTRAINT hero_picks_position_check CHECK (position >= 0);
ALTER TABLE hero_picks ADD CONSTRAINT hero_picks_post_media_foreign
  FOREIGN KEY (post_id, media_id)
  REFERENCES post_media (post_id, media_id)
  ON DELETE CASCADE;
CREATE INDEX hero_picks_position_index ON hero_picks (position);
```

Kształt bliźniaczy do `daily_picks` i z tego samego powodu: **nie ma tu ani
jednej kolumny z punktami, liczbą polubień ani wynikiem.** To nie jest tabela
rankingowa (`../../AGENTS.md` §12).

**Dlaczego dwa klucze obce, a nie sam `media_id`.** O tym, czy zdjęcie wolno
pokazać nieznajomemu, nie decyduje wiersz w `media`, tylko WPIS, przy którym
ono wisi (`posts.visibility`, `posts.status`, stan konta autora). To samo
zdjęcie bywa przypięte do kilku wpisów (`post_media` jest wiele-do-wielu),
więc bez zapisania, którego wpisu dotyczy wskazanie, nie da się później
sprawdzić, czy wciąż jest publiczny.

**Para `(post_id, media_id)` musi istnieć w `post_media`.** Dwa osobne klucze
obce do `posts` i `media` nie wystarczają: dowodzą tylko, że oba wiersze
istnieją, nie że zdjęcie naprawdę wisi przy wskazanym wpisie. To ważne także
dla autoryzacji bajtów zdjęcia — `DostepDoZdjecia` pyta Policy właśnie tego
wpisu i wskazanie w kolażu nie może nadać zdjęciu obcego, publicznego rodzica.

Migracja `2026_09_24_100000_powiaz_hero_picks_z_post_media` przed dodaniem
constraintu blokuje zapisy do `hero_picks` i sprawdza wszystkie istniejące
pary. Jeżeli znajdzie niespójność, **odmawia przed zmianą schematu**, podaje
liczbę oraz zapytanie do ręcznego przeglądu. Niczego nie przepina ani nie
kasuje. Usunięcie relacji zdjęcia z wpisem kasuje tylko odpowiadający wybór
kolażu (`ON DELETE CASCADE`); wpis i zdjęcie zostają.

**Kaskada nie jest zabezpieczeniem prywatności.** `ON DELETE CASCADE` sprząta
wiersz po skasowanym wpisie albo zdjęciu — i tyle. Wpis przełączony na
prywatny, schowany przez moderatora, autor zawieszony: to wszystko zostawia
wiersz na miejscu. Filtr widoczności (`publiclyVisible()` +
`tylkoOdAktywnychAutorow()` + `Media::isReady()`) stoi w
`App\Domain\Feed\HeroKolaz` i liczy się **przy każdym wyświetleniu strony
powitalnej**, nie przy zapisie. Sprawdza to
`KolazPowitalnyPokazujeTylkoPubliczneZdjeciaTest`.

**UNIQUE na `media_id`** pilnuje, żeby to samo zdjęcie nie weszło do kolażu
dwa razy, i jest zarazem hamulcem na wyścig przy podwójnym kliknięciu
„Zapisz" (`Admin\HeroKolazController` przechwytuje zderzenie i kończy cicho —
ten sam wzorzec co `Admin\DailyBoardController`).

**Rollback: `down()` ODMAWIA, gdy w tabeli jest wybór człowieka (D-088).**
`DROP TABLE` kasuje cały wybór, a po `down()` prawie zawsze idzie kolejny
`migrate` — tabela wraca pusta, kolaż po cichu przechodzi w tryb automatyczny
i strona powitalna pokazuje cztery zdjęcia, których nikt nie oglądał. Błędu
nie ma czego zauważyć. Odmowa jest **wąska**: pusta tabela i świeża baza
przechodzą bez pytania. Świadome skasowanie:

```bash
KUKING_ROLLBACK_KASUJ_KOLAZ_POWITALNY=true php artisan migrate:rollback
```

Przed cofnięciem warto zapisać wybór:

```sql
\copy (SELECT media_id, post_id, position FROM hero_picks ORDER BY position)
TO 'hero_picks.csv' CSV HEADER
```

Strażnika i obie kontrole dodatnie sprawdza
`CofniecieMigracjiNieKasujeKolazuTest`.

**Rollback migracji złożonego klucza (#955):** `down()` usuwa wyłącznie
constraint `hero_picks_post_media_foreign`. Wszystkie wiersze `hero_picks`,
`post_media`, `posts` i `media` pozostają bez zmian. Po cofnięciu baza ponownie
dopuszcza niespójne pary, więc rollback osłabia ochronę, lecz nie traci ani
nie zgaduje żadnej wartości semantycznej.
