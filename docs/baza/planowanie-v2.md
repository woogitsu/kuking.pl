# Planer, lista zakupów, spiżarnia i gotowanie (V2)

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

### meal_plan_entries

Planer tygodnia (#27, **D-310**) — pierwszy krok z „najmniejszej kolejności”
opisanej w issue: dzień plus przepis ALBO własny wpis („obiad u mamy”). Listy
zakupów tu nie ma i nie było w tej zmianie.

| kolumna | typ | uwagi |
|---|---|---|
| `id` | `uuid` | `DEFAULT gen_random_uuid()` |
| `user_id` | `uuid` | → `users(id)` `ON DELETE CASCADE` |
| `day` | `date` | dzień planu, data kalendarzowa (nie `timestamptz`) |
| `recipe_id` | `uuid` NULL | → `recipes(id)` **`ON DELETE SET NULL`** |
| `label` | `varchar(120)` NULL | własny wpis, gdy pozycja nie jest przepisem |
| `done_at` | `timestamptz(6)` NULL | prywatne „Zrobione” (#2593, migracja `2026_10_02_190000`); NULL = nieoznaczona, wartość = chwila oznaczenia i znacznik wersji stanu |
| `created_at` / `updated_at` | `timestamptz` | |

**Plan jest prywatny.** Nie ma kolumny widoczności, bo nie ma czego pokazywać
innym: każda trasa (`/planer`) chodzi po pozycjach zalogowanego, a usunięcie
przechodzi przez `MealPlanEntryPolicy`.

Ograniczenia:

- `meal_plan_entries_jedno_z_dwoch_check` — `recipe_id IS NULL OR label IS NULL`,
  czyli NAJWYŻEJ jedno z dwóch. **Nie `num_nonnulls(...) = 1`** jak
  w `collection_items`, i to jest różnica zamierzona: `ON DELETE SET NULL`
  zostawia po twardo usuniętym przepisie wiersz bez obu wartości. Plan ma
  przetrwać zniknięcie przepisu (kryterium z #27), a ekran mówi wtedy
  „Przepis został usunięty.”;
- `meal_plan_entries_label_check` — tekst po obcięciu białych znaków ma 1–120
  znaków, więc `label` nie bywa pusty ani sam ze spacji;
- `meal_plan_entries_przepis_raz_na_dzien` i `meal_plan_entries_wpis_raz_na_dzien`
  — indeksy unikalne częściowe: ten sam przepis i ten sam tekst nie stoją
  dwa razy w jednym dniu. To one czynią „Skopiuj poprzedni tydzień”
  idempotentnym (`insertOrIgnore`) i chronią przed podwójnym kliknięciem;
- `meal_plan_entries_user_day_idx` (odczyt tygodnia) i
  `meal_plan_entries_recipe_idx` (klucz obcy — bez niego kasowanie przepisu
  robi pełny skan).

**Rollback.** `down()` ODMAWIA, gdy w tabeli są wiersze: kasowanie tabeli
zabrałoby ludziom prywatne plany bez śladu. Komunikat mówi, co zrobić ręcznie
(kopia `pg_dump -t meal_plan_entries`, usunięcie wierszy, ponowny rollback).
Na świeżej i pustej bazie — w CI i przy `migrate:refresh` — przechodzi bez
pytania. Odmowa i kontrola dodatnia:
`tests/Feature/PlanerTygodniaTest.php`.

**Wymazanie konta** kasuje wiersze bezwarunkowo
(`EraseAccountData`) — to prywatne notatki jednej osoby, nikomu innemu
niepotrzebne. **Paczka RODO** wydaje je w sekcji `planer`
(`InwentarzDanychKonta`).

Kopia tygodnia i zwykłe dopisanie sprawdzają świeży, aktywny stan konta pod
blokadą jego wiersza `users`. Kopia czyta źródłowe pozycje dopiero pod tą
blokadą. Dzięki temu równoległe wymazanie nie może zatwierdzić usunięcia
planu między odczytem a ponownym zapisem prywatnej notatki (#2551).
Zawieszenie nadal pozwala na odczyt planu, ale nie na jego zapis.
Test przeplotu z prawdziwym `EraseAccountData`:
`tests/Dwa/KopiaPlanuPoWymazaniuKontaTest.php`; kontrola ujemna w CI:
`scripts/kontrola-negatywna-2551.py`.
Wycofanie samego kodu przywróciłoby możliwość odtworzenia planu po wymazaniu;
bezpieczny rollback wymaga zachowania równoważnej blokady i świeżej kontroli
stanu konta. Schemat bazy w #2551 pozostaje bez zmian.

**„Zrobione” (#2593, V2).** `done_at` jest poza `$fillable`; ustawia je tylko
akcja `OznaczPozycjePlanu` (żądany stan, nie przełączenie), pod blokadą wiersza
`users` i wiersza pozycji, po świeżej kontroli konta i własności. Formularz niesie
znacznik stanu widzianego na stronie; gdy stan zmienił się w innej karcie, żądanie
jest odrzucane z komunikatem (konflikt), a identyczne powtórzenie jest no-opem.
Oznaczenie nie tworzy `cooked_events` ani powiadomień. „Skopiuj poprzedni tydzień”
wstawia pozycje z `done_at = NULL`. Paczka RODO ma pola `zrobione` i
`oznaczono_jako_zrobione`; wymazanie konta usuwa je razem z wierszem.
**Rollback (D-088):** `down()` ODMAWIA, gdy choć jedna pozycja ma `done_at`
(komunikat podaje kopię `pg_dump -t meal_plan_entries` i
`UPDATE meal_plan_entries SET done_at = NULL`); bez oznaczeń przechodzi.
Test: `tests/Feature/PlanerZrobioneTest.php`.

### shopping_list_items

Lista zakupów (#27, etap 2; decyzja właściciela z 29.09.2026, **D-333** —
budować bez czekania na pomiar planera z D-310). Migracja
`2026_09_30_090000_create_shopping_list_items_table`. Jeden wiersz to jedna
pozycja prywatnej listy jednej osoby. **Pozycja jest tekstem**: wpisanym
ręcznie albo skopiowaną dosłownie linią składnika przepisu
(`recipe_ingredients.ingredient_text`), bez sumowania i łączenia — składnik
jest u nas wolnym tekstem.

| kolumna | typ | uwagi |
|---|---|---|
| `id` | `uuid` | `DEFAULT gen_random_uuid()` |
| `user_id` | `uuid` | → `users(id)` `ON DELETE CASCADE` (pas bezpieczeństwa; konta się anonimizuje, listę kasuje `EraseAccountData`) |
| `text` | `varchar(240)` | treść pozycji; 240 = długość `ingredient_text`, żeby żadna linia nie była obcinana |
| `source` | `varchar(10)` | `manual` (dopisana ręcznie) albo `recipe` (skopiowana z przepisu) — oznaczenie pozycji na ekranie |
| `recipe_id` | `uuid` NULL | → `recipes(id)` **`ON DELETE SET NULL`**: z KTÓREGO przepisu skopiowano linię; służy do ostrzeżenia przy ponownym dodaniu tego samego przepisu |
| `position` | `integer` | kolejność dopisywania (kolejność linii przepisu jest częścią przepisu) |
| `checked_at` | `timestamptz` NULL | `NULL` = do kupienia; data = odhaczona |
| `created_at` / `updated_at` | `timestamptz` | |

**Lista jest prywatna i nie jest furtką do treści.** Nie ma kolumny
widoczności. Każda trasa (`/lista-zakupow`) pracuje na pozycjach zalogowanego,
a odhaczenie i usunięcie po identyfikatorze przechodzi przez
`ShoppingListItemPolicy`. Tytuł i link przepisu ekran pokazuje TYLKO wtedy,
gdy właściciel listy wciąż go widzi (ta sama reguła co planer,
`PlanerTygodnia::widocznePrzepisy()`); przepis ukryty, zawężony, usunięty
miękko albo odcięty blokadą zostawia pozycję jako sam tekst z dopiskiem
„Przepis jest już niedostępny.”, a po twardym usunięciu — „Przepis został
usunięty.” (`source` zostaje `recipe`, więc ekran wie, że pozycja pochodziła
z przepisu). W `$fillable` modelu stoi wyłącznie `text`: właściciela, źródło,
przepis, kolejność i odhaczenie ustawia akcja domenowa (`ListaZakupow`).

Ograniczenia (nowa tabela, więc razem z `CREATE TABLE` — AGENTS.md §6):

- `shopping_list_items_source_check` — `source IN ('manual', 'recipe')`;
- `shopping_list_items_recipe_source_check` — `recipe_id IS NULL OR source =
  'recipe'`: ręczna pozycja nie ma przepisu (odwrotnie nie wymuszamy, bo
  `ON DELETE SET NULL` zostawia po twardo usuniętym przepisie `recipe` bez
  `recipe_id`);
- `shopping_list_items_text_check` — tekst po obcięciu białych znaków ma
  1–240 znaków;
- `shopping_list_items_user_position_idx` (odczyt listy) i
  `shopping_list_items_recipe_idx` (klucz obcy — bez niego kasowanie przepisu
  robi pełny skan; indeks częściowy `WHERE recipe_id IS NOT NULL`).

Nie ma indeksu unikalnego na tekście ani na przepisie: lista zakupów to wolny
tekst, „2 jajka” może stać dwa razy, a ten sam przepis wolno dodać drugi raz
po **ostrzeżeniu** (ekran „Te składniki już są na liście”, GET, bez
skutku ubocznego). Limity: `kuking.zakupy.pozycji_max` (300 pozycji na listę),
`kuking.zakupy.znakow_max` (240), własny koszyk `kuking.limits.zakupy`.

**Rollback.** `down()` ODMAWIA, gdy w tabeli są wiersze (D-088): kasowanie
tabeli zabrałoby ludziom prywatne listy bez śladu. Komunikat mówi, co zrobić
ręcznie (kopia tabeli, ponowny rollback z
`KUKING_ROLLBACK_KASUJE_LISTE_ZAKUPOW=1`). Na świeżej i pustej bazie — w CI
i przy `migrate:refresh` — przechodzi bez pytania. Odmowa i kontrola dodatnia:
`tests/Feature/ListaZakupowTest.php`.

**Wymazanie konta** kasuje wiersze bezwarunkowo (`EraseAccountData`) —
prywatna lista jednej osoby, nikomu innemu niepotrzebna. **Paczka RODO**
wydaje ją w sekcji `lista_zakupow` (`InwentarzDanychKonta`), z tytułem
przepisu tylko przy przepisie widocznym dla osoby.

### shopping_list_undos

„Cofnij usunięcie” z listy zakupów (#2630, rozszerzenie **D-333**, decyzja
właściciela z 2.10.2026). Migracja `2026_10_02_100000_create_shopping_list_undos_table`.
Jeden wiersz to **jedna ostatnia operacja usunięcia** jednej osoby („Usuń”
przy pozycji albo „Wyczyść odhaczone”). Kolejne usunięcie zastępuje wiersz —
nie ma archiwum, kosza ani historii zakupów.

| kolumna | typ | uwagi |
|---|---|---|
| `id` | `uuid` | `DEFAULT gen_random_uuid()` |
| `user_id` | `uuid` UNIQUE | → `users(id)` `ON DELETE CASCADE` (pas bezpieczeństwa; konta się anonimizuje, wiersz kasuje `EraseAccountData`). UNIQUE = najwyżej jedna migawka na osobę, także przy dwóch równoległych usunięciach |
| `scope` | `varchar(10)` | `single` (jedna pozycja) albo `checked` („Wyczyść odhaczone”); od tego zależy komunikat „wróciły jako odhaczone” |
| `items` | `jsonb` | tablica usuniętych pozycji: `id`, `text`, `source`, `recipe_id`, `position`, `checked_at`, `created_at`. **Bez kluczy obcych** i bez tytułu, linku czy zdjęcia przepisu — przy cofnięciu `recipe_id` zostaje tylko dla przepisu, który wciąż istnieje (inaczej sam tekst, jak przy `ON DELETE SET NULL`) |
| `items_count` | `smallint` | liczba pozycji w migawce; limit konta (`kuking.zakupy.pozycji_max`) sprawdza się przy cofnięciu |
| `expires_at` | `timestamptz` | koniec okna cofnięcia: `created_at` + `kuking.zakupy.cofniecie_minut` (15) |
| `created_at` | `timestamptz` | |

Ograniczenia (nowa tabela, więc razem z `CREATE TABLE` — AGENTS.md §6):
`shopping_list_undos_scope_check` (`single`/`checked`),
`shopping_list_undos_items_check` (`items` jest tablicą o długości
`items_count` ≥ 1), `shopping_list_undos_expires_idx` (sprzątanie).

**Retencja** (ADR_RETENCJE §5.9): po `expires_at` cofnięcie odrzuca migawkę;
kopia jest kasowana przy odczycie listy przez osobę, przy próbie cofnięcia i
zadaniem `kuking:sprzataj-cofniecia-zakupow` (co kwadrans). **Wymazanie konta**
kasuje wiersz od razu (`EraseAccountData`). W paczce RODO tabela jest
oznaczona jako `NIE_DOTYCZY` (te same pozycje są w `lista_zakupow`; kopia żyje
najwyżej 15 minut).

**Rollback.** `down()` ODMAWIA, gdy w tabeli są świeże (niewygasłe) wiersze
(D-088): kasowanie zabrałoby ludziom możliwość odzyskania omyłkowo usuniętych
pozycji. Wygasłe wiersze nie blokują. Na świeżej bazie (CI, `migrate:refresh`)
przechodzi bez pytania; wymuszenie: `KUKING_ROLLBACK_KASUJE_COFNIECIA_ZAKUPOW=1`.
Wycofanie samego kodu jest bezpieczne: tabela po prostu przestaje być czytana,
a wiersze wygasają.

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
  blokadą wiersza produktu; formularz niesie odcisk treści z chwili otwarcia, więc
  stary formularz nie zadziała na opakowaniu, którego człowiek nie widział.
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

## cooking_progress — zapamiętany postęp gotowania (V2, #2016)

Opcjonalna synchronizacja odhaczonych kroków trybu gotowania między
urządzeniami jednego konta. Migracja `2026_09_29_170000_create_cooking_progress_table`.
Obecność wiersza JEST zgodą osoby (włącza ją świadomie, osobno dla każdego
przepisu; wyłączenie kasuje wiersz), więc nie ma flagi w `users`. Bez wiersza
— i dla gości — postęp zostaje w sesji jak dotąd (`CookingModeController`).

| Kolumna | Typ | Znaczenie |
|---|---|---|
| `id` | `uuid` PK, `DEFAULT gen_random_uuid()` | |
| `user_id` | `uuid NOT NULL` → `users` (`ON DELETE CASCADE`) | właściciel; poza `$fillable` (model ma pusty `$fillable`, zmienia go tylko `PostepGotowania`) |
| `recipe_id` | `uuid NOT NULL` → `recipes` (`ON DELETE CASCADE`) | przepis |
| `done_step_ids` | `jsonb NOT NULL DEFAULT '[]'` | ID odhaczonych kroków (nie numery — #756); ID nieistniejących już kroków są odrzucane przy odczycie i wypadają przy zapisie |
| `servings` | `numeric(6,2) NULL` | (etap 2, migracja `2026_09_29_193700`) wybrana liczba porcji; NULL = z przepisu; `CHECK` 1–100 (`cooking_progress_servings_check`) |
| `prepared_ingredient_ids` | `jsonb NOT NULL DEFAULT '[]'` | (etap 2) ID składników „przygotowanych” (#2069, nie pozycje); tablica ≤ 300 (`cooking_progress_prepared_check`); nieistniejące już ID odpadają przy odczycie i zapisie; zmiana porcji czyści wyłącznie te odhaczenia (#2502), bo dotyczą wcześniej odmierzonych ilości |
| `revision` | `integer NOT NULL DEFAULT 1` | wspólna dla kroków, składników i porcji; rośnie o 1 przy każdej zmianie; drugie urządzenie po niej widzi, że stan zmienił się bez niego |
| `servings_revision` | `integer NOT NULL DEFAULT 1` | migracja `2026_10_02_180000`; rośnie tylko po zmianie kontekstu porcji albo ponownym włączeniu wygasłego postępu, więc wykrywa też sekwencję 4→8→4 bez odrzucania addytywnych zmian składników/kroków |
| `expires_at` | `timestamptz NOT NULL` | ważność: `kuking.cooking_progress.retention_hours` (24 h) od OSTATNIEJ zmiany; wygasły wiersz jest dla serwisu nieistniejący |
| `created_at`, `updated_at` | `timestamptz` | |

Ograniczenia i indeksy:
- `UNIQUE (user_id, recipe_id)` — jeden postęp na osobę i przepis (obsługuje też zapytania po `user_id`);
- `cooking_progress_done_check`: `jsonb_typeof(done_step_ids) = 'array' AND jsonb_array_length(done_step_ids) <= 200`;
- `cooking_progress_revision_check`: `revision >= 1`;
- `cooking_progress_servings_revision_check`: `servings_revision >= 1`;
- indeks po `expires_at` — nocne sprzątanie.

Konflikt dwóch urządzeń: zapis to idempotentne USTAWIENIE jednego kroku pod
blokadą wiersza (`SELECT … FOR UPDATE`), więc różne kroki nie gubią się
nawzajem, a na ten sam wygrywa ostatni zapis; formularz niesie rewizję, którą
widział, i przy rozbieżności osoba dostaje komunikat.

Składniki potwierdza się dla widocznej liczby porcji (#2502). Formularz
przesyła porcje pokazane na ekranie, wartość zapisaną wtedy na koncie oraz
`servings_revision` i ID wiersza postępu; warunki są sprawdzane pod blokadą wiersza. Osobny znacznik
odrzuca stary formularz nawet po zmianie i powrocie do tej samej ilości.
Zmiana liczby porcji czyści
listę przygotowanych składników z jawną informacją, ale nie odhaczenia
kroków. Jawny adres z inną liczbą porcji nie pokazuje dawnej checklisty;
przy zapisie od tej strony wymaga ponownego zaznaczenia. Pamięć jednej
karty przeglądarki usuwa dawne klucze tylko tego przepisu przy zmianie
efektywnej ilości, także przy powrocie do wcześniejszej liczby porcji.
Stary klucz bez porcji nie jest uznawany za potwierdzenie nowej ilości.
Rollback migracji znacznika przechodzi dla wartości domyślnych i pustej bazy,
ale odmawia przy aktywnej historii zmian porcji: trzeba poczekać na wygaśnięcie
postępu albo wyłączyć jego synchronizację świadomie, zamiast dopuścić stare
formularze po utracie znacznika.

Prywatność: widoczne wyłącznie dla właściciela (`CookingProgressPolicy`),
każde wejście przechodzi też przez `RecipePolicy::view`. Paczka danych ma
sekcję `postep_gotowania` (tytuł przepisu tylko przy przepisie widocznym dla
osoby), `EraseAccountData` kasuje wiersze jawnie (konta się anonimizuje).
Retencja: `kuking:sprzataj-postep-gotowania`, codziennie o 03:00.

Etap 2 (#2016): składniki zapisuje się RÓŻNICĄ (`bylo[]` → `zaznaczone[]`), więc
dwa urządzenia zaznaczające różne składniki nic sobie nie gubią, a powtórzenie
żądania nie podbija rewizji; porcje to jedna wartość (wygrywa ostatni zapis,
jawne `?porcje=` w adresie ma pierwszeństwo przy wyświetlaniu). Minutników
świadomie NIE synchronizujemy: odliczają na zegarze monotonicznym karty
(#751) i dzwonią lokalnie; wspólny minutnik wymagałby stałego odpytywania
serwera albo push, a bez tego drugie urządzenie pokazałoby przycisk, który
niczego nie uruchamia (D-053).

**Rollback etapu 2.** `down()` migracji `2026_09_29_193700` usuwa obie kolumny
i **odmawia** (D-088), gdy niewygasły wiersz ma wybrane porcje albo
przygotowane składniki; wiersze z samymi krokami i wygasłe nie blokują.
Wymuszenie po kopii tabeli: `KUKING_ROLLBACK_KASUJE_SKLADNIKI_I_PORCJE_GOTOWANIA=1`.
Test: `CofniecieMigracjiPorcjiISkladnikowGotowaniaTest`.

**Rollback etapu 1.** `down()` usuwa tabelę. Przy choć jednym NIEWYGASŁYM wierszu
**odmawia** (D-088) — to dane wpisane przez ludzi w trakcie gotowania;
na pustej tabeli, przy samych wygasłych wierszach i w CI (`migrate:refresh`)
przechodzi bez pytania. Wymuszenie po kopii tabeli:
`KUKING_ROLLBACK_KASUJE_POSTEP_GOTOWANIA=1` (albo wcześniej
`php artisan kuking:sprzataj-postep-gotowania --wszystkie`). Test:
`CofniecieMigracjiNieKasujePostepuGotowaniaTest`.

## cooking_notes — prywatny roboczy dopisek z gotowania (V2, #2587)

Jeden krótki dopisek zalogowanej osoby do jednego przepisu, zrobiony w trakcie
trybu „Gotuję” („mniej soli”, „dłuższy czas”). Migracja
`2026_10_02_220000_create_cooking_notes_table`. To NIE jest wykonanie: wiersz
nie tworzy `cooked_events`, powiadomienia ani oznaczenia „Ugotowałem”. Do pola
„Coś po swojemu?” trafia dopiero na wyraźną prośbę osoby (odnośnik na
formularzu „Ugotowałem”) i nadal wymaga wysłania formularza.

| Kolumna | Typ | Znaczenie |
|---|---|---|
| `id` | `uuid` PK, `DEFAULT gen_random_uuid()` | |
| `user_id` | `uuid NOT NULL` → `users` (`ON DELETE CASCADE`) | właściciel; poza `$fillable` (model ma pusty `$fillable`, zmienia go tylko `RoboczyDopisek`) |
| `recipe_id` | `uuid NOT NULL` → `recipes` (`ON DELETE CASCADE`) | przepis |
| `body` | `text NOT NULL` | treść; `CHECK` 1–500 znaków (`cooking_notes_body_check`) |
| `revision` | `integer NOT NULL DEFAULT 1` | rośnie o 1 przy każdym zapisie; formularz niesie rewizję, którą widział, a zapis ze starą rewizją jest odrzucany jako konflikt (`cooking_notes_revision_check`: `>= 1`) |
| `expires_at` | `timestamptz NOT NULL` | ważność: `kuking.cooking_note.retention_hours` (24 h) od OSTATNIEJ zmiany; wygasły wiersz jest dla serwisu nieistniejący |
| `created_at`, `updated_at` | `timestamptz` | |

Ograniczenia i indeksy:
- `UNIQUE (user_id, recipe_id)` — jeden dopisek na osobę i przepis, więc nie
  przechodzi na inny przepis ani konto (obsługuje też zapytania po `user_id`);
- indeks po `expires_at` — nocne sprzątanie.

Życie dopisku: wygasa po 24 h od ostatniej zmiany; znika po zapisaniu
wykonania tego przepisu (`CookedEventController::store`), po przycisku „Usuń
dopisek” i przy wymazaniu konta. Nocne `kuking:sprzataj-postep-gotowania`
(03:00) kasuje wygasłe wiersze razem z postępem gotowania — bez osobnego
zadania w harmonogramie.

Prywatność: widoczne wyłącznie dla właściciela (`CookingNotePolicy`), każde
wejście przechodzi też przez `RecipePolicy::view`. Tekst nie trafia do adresu,
cache, SEO ani telemetrii. Paczka danych ma sekcję `dopiski_z_gotowania`
(tytuł przepisu tylko przy przepisie widocznym dla osoby), `EraseAccountData`
kasuje wiersze jawnie (konta się anonimizuje). Wpis w rejestrze czynności:
`docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md` §3.29.

Rollback (D-088): `down()` usuwa tabelę, ale ODMAWIA, gdy jest choć jeden
niewygasły wiersz. Na pustej tabeli, przy samych wygasłych wierszach i w CI
(`migrate:refresh`) przechodzi bez pytania. Wymuszenie po kopii tabeli:
`KUKING_ROLLBACK_KASUJE_DOPISKI_GOTOWANIA=1` (albo wcześniej
`php artisan kuking:sprzataj-postep-gotowania --wszystkie`). Test:
`CofniecieMigracjiNieKasujeDopiskowZGotowaniaTest`.

## weekly_recipe_picks — „Ugotujmy razem” (F3, 30.09.2026)

Przepis tygodnia wybrany przez gospodarza. Migracja
`2026_09_30_180000_create_weekly_recipe_picks_table`. Jeden wiersz = jeden
tydzień ISO i jeden przepis. Wykonań NIE kopiujemy: strona
`/ugotujmy-razem` czyta zwykłe `cooked_events` tego przepisu z okna tygodnia
(`cooked_at` w [poniedziałek 00:00, następny poniedziałek 00:00) czasu
polskiego) przez `CookedEvent::widoczneDla()`. To nie jest grupa (#22): nie
ma członkostwa ani listy uczestników.

| Kolumna | Typ | Znaczenie |
|---|---|---|
| `id` | `uuid` PK, `DEFAULT gen_random_uuid()` | adres zdjęcia wyboru w panelu (`/admin/ugotujmy-razem/{id}`) |
| `week_starts_on` | `date NOT NULL UNIQUE` | poniedziałek tygodnia w strefie `kuking.strefa` (Europe/Warsaw); w adresie jako `2026-W40` (`TydzienGotowania`) |
| `recipe_id` | `uuid NOT NULL` → `recipes` (`ON DELETE CASCADE`) | przepis tygodnia; przepisy kasujemy miękko, twarde usunięcie (wymazanie autora) zabiera wybór |
| `chosen_by` | `uuid NULL` → `users` (`ON DELETE SET NULL`) | który gospodarz wybrał; w inwentarzu paczki RODO jako praca w serwisie (`na_zadanie`) |
| `created_at`, `updated_at` | `timestamptz` | |

Ograniczenia i indeksy:
- `UNIQUE (week_starts_on)` — jeden przepis na tydzień także przy dwóch
  gospodarzach zapisujących naraz (drugi dostaje komunikat, nie 500);
- `weekly_recipe_picks_poniedzialek_check`: `EXTRACT(ISODOW FROM week_starts_on) = 1`;
- indeksy po `recipe_id` i `chosen_by` (klucze obce).

Model `WeeklyRecipePick` ma pusty `$fillable`: wszystkie kolumny to decyzja
gospodarza i wchodzą tylko przez `ZapisPrzepisuTygodnia` (`forceFill`),
w jednej transakcji z audytem `ugotujmy_razem.chosen` (z poprzednim
przepisem, gdy wybór zastępuje inny) i `ugotujmy_razem.removed`. Zakończonego
tygodnia nie zmienia się ani nie zdejmuje — archiwum mówi, co naprawdę
gotowaliśmy. Widoczność: `WeeklyRecipePickPolicy::view` — tydzień już się
zaczął, przepis jest dziś opublikowany i publiczny, a `RecipePolicy::view`
wpuszcza widza (blokady, konto autora). Przepis ukryty przez moderację albo
przestawiony na niepubliczny znika ze strony dla wszystkich.

**Rollback.** `down()` usuwa tabelę. Gdy są wiersze, **odmawia** (D-088) —
to decyzje gospodarza i archiwum tygodni, których `up()` nie odtworzy; na
pustej tabeli (CI, `migrate:refresh`) przechodzi bez pytania. Wymuszenie po
kopii tabeli (`pg_dump -t weekly_recipe_picks`):
`KUKING_ROLLBACK_KASUJE_UGOTUJMY_RAZEM=1`. Przepisy i wykonania zostają
nietknięte. Test: `CofniecieMigracjiNieKasujeUgotujmyRazemTest`.

## recipe_serving_preferences — zapamiętana liczba porcji przy przepisie (V2, #2602)

Jawna, prywatna preferencja „ten przepis zwykle robię na tyle porcji” (rozszerzenie
D-284, decyzja właściciela z 2.10.2026 w D-333). Migracja
`2026_10_02_210000_create_recipe_serving_preferences_table`. Wiersz powstaje
wyłącznie po przycisku „Zapamiętaj dla mnie” zalogowanej osoby i znika po
„Zapomnij moje ustawienie”; nic nie zapisuje się samo, nie ma backfillu z adresów,
postępów gotowania, planera ani wykonań. Gość nie zapisuje niczego.

| Kolumna | Typ | Znaczenie |
|---|---|---|
| `id` | `uuid` PK, `DEFAULT gen_random_uuid()` | |
| `user_id` | `uuid NOT NULL` → `users` (`ON DELETE CASCADE`) | właściciel; zawsze z sesji, nigdy z żądania; model ma pusty `$fillable` |
| `recipe_id` | `uuid NOT NULL` → `recipes` (`ON DELETE CASCADE`) | przepis |
| `servings` | `numeric(6,2) NOT NULL` | wybrana liczba porcji; `CHECK` 1–100 (`recipe_serving_preferences_servings_check`), jak w `WyborPorcji`; zapisujemy tylko liczbę, nigdy przeliczonych składników |
| `created_at`, `updated_at` | `timestamptz` | |

Ograniczenia i indeksy:
- `UNIQUE (user_id, recipe_id)` — stan, nie zdarzenie: zmiana liczby nadpisuje wiersz;
- indeks po `recipe_id` (klucz obcy);
- limit `kuking.porcje_zapamietane.limit_na_osobe` (500 przepisów na osobę) pilnuje akcja domenowa, bo `CHECK` nie liczy wierszy.

Reguły użycia (`App\Domain\Recipes\Porcje\ZapamietanePorcje`): `?porcje=N` z adresu
ma pierwszeństwo przed zapamiętaną liczbą; zapamiętana działa tylko przy adresie
bez porcji; `?porcje=autor` to jawny powrót do ilości autora (nie kasuje
preferencji). Odczyt idzie po `RecipePolicy::view`, zapis i usunięcie to
`POST`/`DELETE /przepisy/{recipe}/moje-porcje` (limit `ustawienia`). Aktywny
postęp gotowania (`cooking_progress`) nie zmienia się od późniejszej zmiany
preferencji. Eksport: sekcja `zapamietane_porcje`; `EraseAccountData` kasuje
wiersze konta jawnie. Test: `ZapamietanaLiczbaPorcjiTest`.

**Rollback.** `down()` usuwa tabelę. Gdy są wiersze, **odmawia** (D-088) — to
świadome wybory ludzi, których `up()` nie odtworzy; na pustej tabeli (CI,
`migrate:refresh`) przechodzi bez pytania. Wymuszenie po kopii tabeli:
`KUKING_ROLLBACK_KASUJE_PORCJE_PRZEPISOW=1`. Test:
`CofniecieMigracjiNieKasujeZapamietanychPorcjiTest`.
