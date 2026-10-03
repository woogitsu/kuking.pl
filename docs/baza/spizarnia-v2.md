# Spiżarnia „Co mam w domu” (V2)

Wydzielone z [`planowanie-v2`](planowanie-v2.md) przy scalaniu paczki L (2.10.2026), gdy plik przekroczył próg rozmiaru (`IndeksDokumentacjiBazyTest`). Planer, lista zakupów i gotowanie zostają tam.

## pantry_items — „Co mam w domu” (V2, D-285)

Prywatna lista produktów jednej osoby; na niej stoi „Co ugotuję z tego,
co mam” (`App\Domain\Pantry\CoUgotuje`). Migracja
`2026_09_28_233700_create_pantry_items_table`.

| Kolumna | Typ | Znaczenie |
|---|---|---|
| `id` | `uuid` PK, `DEFAULT gen_random_uuid()` | |
| `user_id` | `uuid NOT NULL` → `users` (`ON DELETE CASCADE`) | właściciel listy; nie jest w `$fillable` — wiersz powstaje przez `$user->pantryItems()` |
| `name` | `varchar(120) NOT NULL` | nazwa dokładnie tak, jak ją wpisano; tylko ją pokazujemy |
| `rdzenie` | `text[]` `GENERATED ALWAYS AS (kuking_rdzenie_skladnika(name)) STORED` | rdzenie słów do porównania ze składnikami przepisów |
| `klucz` | `text` `GENERATED ALWAYS AS (kuking_klucz_skladnika(name)) STORED` | rdzenie posortowane i sklejone spacją |
| `created_at` | `timestamptz NOT NULL DEFAULT now()` | |
| `expires_on` | `date NULL` | termin z opakowania (#1903, D-333): dzień kalendarzowy bez strefy. `NULL` = osoba nie podała terminu (nie „produkt nie ma terminu”). Ustawia wyłącznie akcja `ZmienTerminProduktu`, poza `$fillable` |
| `expiry_kind` | `varchar(12) NULL` | rodzaj terminu: `use_by` („Należy zużyć do”) albo `best_before` („Najlepiej spożyć przed”). `NULL` dokładnie wtedy, gdy `expires_on IS NULL`. Priorytet liczy się jednakowo, różnica jest na opakowaniu i ma zostać widoczna |
| `quantity_note` | `varchar(40) NULL` | ilość jako WOLNY TEKST własnymi słowami („pół kostki”, „1 litr”); bez liczb i jednostek (D-333: tak jak `ingredient_text`). `NULL` = nie podano. Jedyna nowa kolumna w `$fillable` |
| `frozen` | `boolean NOT NULL DEFAULT false` | produkt w zamrażarce wypada z sekcji „Zużyj w pierwszej kolejności”; wpisany termin zostaje. Poza `$fillable` |
| `first_package_id` | `uuid NULL` | tożsamość pierwszego opakowania po awansie drugiego (#2783). `NULL` oznacza pierwotne opakowanie o tożsamości `pantry_items.id`; po awansie kolumna zachowuje UUID usuniętego wiersza `pantry_second_packages`. Nie pochodzi z formularza ani z `$fillable` |

Ograniczenia:
- `pantry_items_name_check`: `char_length(btrim(name)) BETWEEN 2 AND 120
  AND cardinality(rdzenie) > 0` — nazwa bez słów („500”, „-”) dałaby pustą
  tablicę rdzeni, a pusta tablica zawiera się w każdej (`<@`), czyli „masz
  ten składnik” przy każdym przepisie;
- `pantry_items_user_klucz_unique`: `UNIQUE (user_id, klucz)` — „Jajka”
  i „jajko” to na jednej liście ten sam produkt. Ten indeks obsługuje też
  zapytania po `user_id`.
- Terminy (#1903, migracja `2026_10_01_101500_add_expiry_to_pantry_items`;
  CHECK-i dodane wg AGENTS.md §6: `NOT VALID`, potem osobno `VALIDATE`,
  `$withinTransaction = false`):
  `pantry_items_expiry_kind_check` (`expiry_kind IS NULL OR expiry_kind IN
  ('use_by','best_before')`), `pantry_items_expiry_pair_check`
  (`(expires_on IS NULL) = (expiry_kind IS NULL)` — nie ma terminu bez rodzaju
  i odwrotnie), `pantry_items_expires_on_range_check` (`expires_on` między
  2020-01-01 a 2100-12-31 — stałe, bez `now()`, żeby CHECK był niezmienny),
  `pantry_items_quantity_note_check` (`char_length(btrim(quantity_note))
  BETWEEN 1 AND 40`).
- **Indeksu po terminie nie ma, świadomie.** Lista ma najwyżej 150 pozycji na
  konto i jest zawsze filtrowana po `user_id` (pokrywa ją `UNIQUE (user_id,
  klucz)`), a ekran, blok na Starcie i tryb `?najpierw=termin` nie skanują
  produktów wszystkich kont. Sobotnie przypomnienie idzie od kont ze zgodą
  (częściowy indeks `users_wants_pantry_reminder_idx`, niżej), a dopiero potem
  po produktach konta (`EXISTS` po `user_id`). Gdyby pojawiło się zadanie
  skanujące produkty wszystkich kont, dopisać `CREATE INDEX CONCURRENTLY … ON
  pantry_items (expires_on) WHERE expires_on IS NOT NULL AND NOT frozen`.
- „Dziś” (granica „termin minął”, „do N dni”) liczy aplikacja w `Europe/Warsaw`
  (`PriorytetZuzycia::dzis()`) i podaje jako parametr SQL; baza nie używa
  `now()` w tej regule. `kuking.pantry.pilne_dni` (domyślnie 3) wyznacza
  granicę pilnych.

Funkcje (obie `IMMUTABLE STRICT PARALLEL SAFE`):
- `public.kuking_formy_skladnikow() → jsonb` — zamknięty słownik form
  krótkich słów (forma → rdzeń, np. `maki` → `maka`, `maku` → `mak`,
  `sera` → `ser`). Rdzeń ma najwyżej 4 litery, każda forma zaczyna się od
  jego pierwszych trzech liter — na tym opiera się wstępny filtr `LIKE`
  (#1969). Stała w funkcji, nie tabela, bo kolumna generowana wymaga
  funkcji `IMMUTABLE`;
- `public.kuking_rdzenie_skladnika(text) → text[]` — `kuking_normalize()`,
  podział na słowa, bez liczb i słów jednoliterowych; słowo ze słownika
  form dostaje rdzeń ze słownika, pozostałe — „liczbę mnogą prostą” tylko
  gdy są dłuższe (słowo > 5 liter na „-ow” traci „ow”, słowo > 4 liter
  traci końcową samogłoskę), a krótsze zostają całe. Dawny próg „> 3
  litery” dawał „mąka” i „mak” ten sam rdzeń `mak` (#1969). Wynik
  posortowany i bez powtórzeń — jawnie, `array_agg(DISTINCT r ORDER BY r)`
  na wartości `COLLATE "C"` (migracja `2026_09_30_231500`, #2315; wcześniej
  kolejność wynikała tylko ze sposobu wykonania `DISTINCT`, a na kluczu stoi
  `UNIQUE`). Migracja przelicza `rdzenie` i `klucz` istniejących wierszy
  (`UPDATE … SET name = name`) i odmawia, gdy przeliczenie dałoby dwa
  produkty jednej osoby o tym samym kluczu — nie kasuje ich za człowieka.
  Rollback przywraca poprzednie ciało funkcji; dane zostają. Ta sama funkcja liczy
  rdzenie linijek `recipe_ingredients.ingredient_text` — zapisane w
  `recipe_ingredients.rdzenie` (wyzwalacz, patrz sekcja `recipe_ingredients`);
  reguła mieszka wyłącznie w bazie, bez kopii w PHP;
- `public.kuking_klucz_skladnika(text) → text` — `array_to_string()` z powyższej.
  Osobna funkcja, bo samo `array_to_string()` jest `STABLE` i nie wolno go
  użyć w kolumnie generowanej.

Zapytanie doboru zawęża kandydatów filtrem `recipe_ingredients.rdzenie &&
{najdłuższy rdzeń każdego produktu}`, który idzie po indeksie GIN
`recipe_ingredients_rdzenie_gin_idx`, a dopiero na nich porównuje tablice
(`pantry_items.rdzenie <@ recipe_ingredients.rdzenie`).

Prywatność: lista jest w paczce danych (sekcja `co_mam_w_domu`, bez kolumn
generowanych) i znika w `EraseAccountData` (jawnie — konta się anonimizuje,
nie kasuje, więc kaskada klucza obcego tam nie działa).

**Rollback.** `down()` usuwa tabelę i obie funkcje. Nie dotyka przepisów,
składników ani wyszukiwarki. Przy niepustej tabeli **odmawia** (D-088) —
listy to dane wpisane przez ludzi; wymuszenie po zrobieniu kopii:
`KUKING_ROLLBACK_KASUJE_SPIZARNIE=1`. Na świeżej bazie i w CI
(`migrate:refresh`) przechodzi bez pytania.

**Rollback terminów (#1903).** `down()` migracji
`2026_10_01_101500_add_expiry_to_pantry_items` zdejmuje cztery CHECK-i i cztery
kolumny, ale **odmawia wąsko** (D-088): tylko gdy istnieje wiersz z terminem,
ilością albo `frozen = true` — to dane wpisane przez człowieka, których `up()`
nie odtworzy. Komunikat podaje `CREATE TABLE pantry_items_terminy_kopia AS
SELECT id, expires_on, expiry_kind, quantity_note, frozen FROM pantry_items
WHERE …` i wymuszenie `KUKING_ROLLBACK_KASUJE_TERMINY_SPIZARNI=1` (`getenv()`,
nie `env()`). Na pustej i świeżej bazie przechodzi bez pytania. Przy awaryjnym
rollbacku WDROŻENIA nie trzeba cofać schematu: kolumny są nullable albo ze
stałym `DEFAULT`, więc stary kod ich nie czyta i nic się nie psuje. Testy:
`MigracjaTerminowSpizarniTest` (CHECK-i z kontrolą ujemną i dodatnią),
`CofniecieMigracjiNieKasujeTerminowSpizarniTest`.

**Eksport i wymazanie (#1903).** Sekcja `co_mam_w_domu` paczki ma teraz także
`termin`, `rodzaj_terminu`, `ilosc` i `mrozone` (test
`TerminySpizarniEksportIWymazanieTest`); `EraseAccountData` kasuje cały
`pantry_items` konta, więc nie ma nic nowego do kasowania.

## pantry_second_packages — drugie opakowanie produktu (V2, #2568)

Drugie opakowanie tego samego produktu z „Co mam w domu”, z własnym terminem,
rodzajem terminu, ilością i oznaczeniem „mrożone”. Migracja
`2026_10_03_200000_create_pantry_second_packages_table`. **Nazwa produktu
zostaje jedna** (`UNIQUE (user_id, klucz)` w `pantry_items` bez zmian), a zwykłe
dodanie tej samej nazwy niczego nie tworzy — drugie opakowanie powstaje
wyłącznie jawną akcją „Dodaj drugie opakowanie” (`DrugieOpakowanieProduktu`).
**Pierwsze opakowanie to nadal kolumny `pantry_items`** (`expires_on`,
`expiry_kind`, `quantity_note`, `frozen`); dotychczasowe terminy i ilości nie są
przenoszone ani przeliczane.

| Kolumna | Typ | Znaczenie |
|---|---|---|
| `id` | `uuid` PK, `DEFAULT gen_random_uuid()` | identyfikator drugiego opakowania (niesie go formularz edycji) |
| `pantry_item_id` | `uuid NOT NULL` → `pantry_items` (`ON DELETE CASCADE`), `UNIQUE` | produkt; najwyżej jedno drugie opakowanie na produkt, więc razem dwa. Nie ma własnego `user_id` — właściciel to właściciel produktu |
| `expires_on` | `date NULL` | jak w `pantry_items` (dzień z opakowania, bez strefy) |
| `expiry_kind` | `varchar(12) NULL` | `use_by` albo `best_before`; `NULL` dokładnie wtedy, gdy `expires_on IS NULL` |
| `quantity_note` | `varchar(40) NULL` | ilość jako wolny tekst |
| `frozen` | `boolean NOT NULL DEFAULT false` | to opakowanie w zamrażarce; nie wpływa na pierwsze |
| `created_at` | `timestamptz NOT NULL DEFAULT now()` | |

Ograniczenia: `UNIQUE (pantry_item_id)` (`pantry_second_packages_pantry_item_id_unique`;
limit „dwa opakowania” stoi w bazie, więc dwa równoległe zapisy nie dadzą
trzeciego), `…_expiry_kind_check`, `…_expiry_pair_check`,
`…_expires_on_range_check` (2020-01-01 … 2100-12-31) i `…_quantity_note_check`
(1–40 znaków po obcięciu spacji) — te same reguły co dla pierwszego opakowania.
Nowa, pusta tabela nie potrzebuje wzorca `NOT VALID`. Modelu nie wypełnia się
masowo (`$fillable = []`): wiersz zapisuje tylko akcja domenowa, po walidacji
wspólnej z `ZmienTerminProduktu`.

Reguły, które na tym stoją:
- **Dostępność, pilność i dopasowanie liczy się na każdym opakowaniu osobno**
  (`PriorytetZuzycia::OPAKOWANIA_SQL` — `UNION ALL` opakowań pod aliasem `p`),
  a produkt „jest”, gdy choć jedno opakowanie spełnia warunek (`EXISTS`,
  `count(DISTINCT p.id)`). Produkt z jednym opakowaniem daje wiersze jak dawniej.
  Opakowanie po „Należy zużyć do” nie otwiera produktu do gotowania, ale dobre
  drugie — tak. Mrożone opakowanie nie chowa pilnego niemrożonego.
- **Usunięcie jednego opakowania nie rusza drugiego.** Usunięcie drugiego kasuje
  jego wiersz. Usunięcie pierwszego przenosi treść drugiego na miejsce pierwszego
  (kolumny `pantry_items`) i kasuje wiersz drugiego w jednej transakcji pod
  blokadą wiersza produktu; formularz pierwszego opakowania zawsze niesie odcisk
  treści **i trwałej tożsamości** z chwili otwarcia, także gdy opakowanie było
  wtedy jedyne. Po awansie drugiego jego UUID przechodzi do
  `pantry_items.first_package_id`, więc nawet identyczna treść nie pozwala
  staremu formularzowi nadpisać innego opakowania. Formularz sprzed tej ochrony,
  bez odcisku, jest odrzucany po awansie. Po błędzie formularz zachowuje dawny
  odcisk i wpisane pola; trzeba świadomie otworzyć aktualne opakowanie z listy.
  Kolumnę dodaje `2026_10_03_210000_track_first_pantry_package_identity`.
  Rollback odmawia, gdy choć jeden produkt ma `first_package_id`: bez tej
  kolumny kolejny `up()` nie odtworzy tożsamości awansowanego opakowania.
  Gdy żadne opakowanie nie awansowało, `down()` i ponowny `up()` są bezpieczne.
  Usunięcie całego produktu (`pantry.destroy`) kasuje oba kaskadą.
- **Limit 150** dotyczy produktów (`CoMamWDomu::MAKS_PRODUKTOW`), nie opakowań;
  opakowań jest więc najwyżej 300.
- **Eksport i wymazanie.** Paczka danych (`co_mam_w_domu[].drugie_opakowanie`,
  `null` gdy go nie ma) obejmuje drugie opakowanie; wymazanie konta usuwa
  `pantry_items`, a drugie opakowania znikają kaskadą. Tabela nie ma kolumny
  wskazującej na `users`, więc nie wchodzi do `InwentarzDanychKonta`.
- **Rollback (D-088).** `down()` ODMAWIA, gdy istnieje choć jedno drugie
  opakowanie: to osobna decyzja człowieka (osobny termin, ilość, zamrożenie),
  której nie wolno scalić po cichu z pierwszym ani skasować. Komunikat podaje
  liczbę i polecenie kopii (`CREATE TABLE pantry_second_packages_kopia AS SELECT *
  …`). Wymuszenie po kopii: `KUKING_ROLLBACK_KASUJE_DRUGIE_OPAKOWANIA=1`. Na
  świeżej bazie cofnięcie przechodzi bez pytania, a produkty i pierwsze
  opakowania zostają. Wycofanie samego kodu jest bezpieczne: tabela przestaje być
  czytana.
