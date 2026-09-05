# TandoorRecipes/recipes — notatka researchowa

**Licencja: AGPL-3.0 + „Commons Clause” v1.0** (`LICENSE.md`, pierwsze linie).
To jest **ostrzejsze, niż zakłada `docs/research/PUBLIC_REPOS.md`**, który podaje
samo AGPL-3.0. Commons Clause odbiera prawo do „Sell the Software”, definiując
sprzedaż szeroko — obejmuje też **hosting i usługi wsparcia, których wartość
pochodzi w istotnej części z funkcjonalności tego oprogramowania**.

Konsekwencja dla Kuking: gdybyśmy kiedykolwiek rozważali użycie kodu Tandoora
(np. „postawimy Tandoora obok Kukinga”), to przy jakimkolwiek planie
monetyzacji (`docs/MONETIZATION.md`) wchodzimy dokładnie w to, czego zabrania
Commons Clause. **Kod: nie dotykamy. Modele danych i przypadki brzegowe
parsowania: bierzemy — to fakty o polskiej i niemieckiej kuchni, nie utwór.**
Warto poprawić wpis w `docs/research/PUBLIC_REPOS.md` (pozycja 4), bo różnica
jest istotna prawnie.

Snapshot: `git clone --depth 1` z 2026-09-05. Django + PostgreSQL, 1747 linii
w `cookbook/models.py`.

---

## 1. Co wynika z licencji

- AGPL: sieciowe udostępnianie pochodnej wymusza otwarcie źródeł Kuking.
- Commons Clause na wierzchu: dodatkowo zabrania odsprzedaży i płatnego
  hostingu opartego na ich funkcjonalności.
- Praktycznie: to najbardziej restrykcyjna licencja w całym naszym researchu.
  **Zero kodu, zero kopiowanych plików fixture'ów, zero przeniesionych
  wyrażeń regularnych.** Listę przypadków brzegowych z ich testów opisujemy
  własnymi słowami i weryfikujemy na polskich przykładach.

## 2. Użyteczny model danych

To repozytorium było w planie jako źródło modelu składników — i rzeczywiście
tu jest jego wartość. Poniżej **tylko to, czego u nas nie ma**; podstawa
(`recipes` + `recipe_ingredients` + `ingredients` + `units` + `recipe_steps`
+ zachowany surowy tekst) jest już zrobiona i potwierdzona w tabeli
„Stan na dziś” w `docs/research/PUBLIC_REPOS.md`.

### 2.1 `Ingredient.no_amount` — brak ilości z premedytacją

`cookbook/models.py:936-958`:

```
Ingredient: food FK (null), unit FK (null), amount decimal,
            note, is_header bool, no_amount bool,
            order, original_text
```

`no_amount` odróżnia **„soli do smaku” (ilości nie ma i nie powinno być)** od
**„nie udało się rozpoznać ilości”**. U nas `recipe_ingredients.quantity` jest
`nullable` i te dwa przypadki są nieodróżnialne
(`database/migrations/2026_09_05_000400_create_recipes_tables.php`).

Kiedy to zaboli: przy skalowaniu porcji (`AGENTS.md` sekcja 9 wymienia
„skalowanie porcji” jako funkcję AI). Skalując przepis z 4 na 8 porcji trzeba
wiedzieć, których pozycji **nie wolno** przemnożyć — „sól do smaku” razy dwa
to „sól do smaku”, a nie „2 × do smaku”. Bez tej flagi trzeba zgadywać
z tekstu przy każdym przeliczeniu.

Koszt u nas: jedna kolumna `boolean not null default false`. Warto dodać
**zanim** powstanie skalowanie porcji, bo potem trzeba to wywnioskować
wstecznie z 10 000 wierszy.

### 2.2 `is_header` kontra nasze `group_name`

Tandoor robi nagłówek grupy („Ciasto”, „Krem”) jako **wiersz składnika
z `is_header=True`**. My mamy `recipe_ingredients.group_name` powtarzane
w każdym wierszu grupy.

Nasze podejście jest lepsze w jednym: kolejność wewnątrz grupy nie zależy od
tego, gdzie stoi nagłówek. Ale ma dziurę, której warto być świadomym: **nic
nie wymusza, żeby wiersze jednej grupy leżały obok siebie**. `UNIQUE
(recipe_id, position)` pilnuje unikalności pozycji, nie ciągłości grupy.
Można zapisać: poz. 0 „Ciasto”, poz. 1 „Krem”, poz. 2 „Ciasto” — i widok
albo pokaże trzy grupy, albo posklei je zmieniając kolejność.
To jest rzecz do **testu**, nie do migracji.

### 2.3 `UnitConversion` z przelicznikiem zależnym od produktu

`cookbook/models.py:909-933`:

```
UnitConversion: base_amount, base_unit FK, converted_amount, converted_unit FK,
                food FK (NULLABLE), UNIQUE (space, base_unit, converted_unit, food)
```

Sedno tkwi w tym, że `food` może być `NULL`. Konwersja `1 kg = 1000 g` jest
uniwersalna (`food = NULL`), ale **`1 szklanka mąki = 130 g`, a `1 szklanka
cukru = 200 g`** — to samo pytanie ma różne odpowiedzi zależnie od produktu.

Dla polskiej kuchni to jest kluczowe, bo nasze przepisy rodzinne są pisane
w szklankach i łyżkach: „szklanka mąki”, „łyżka smalcu”, „garść kaszy”.
Bez tabeli konwersji zależnej od produktu funkcja „przelicz na gramy”
(oczywisty kandydat na V1) będzie po prostu podawać złe liczby, a to jest
gorsze niż brak funkcji.

Nasze `units` (`code`, `name`, `name_plural`, `unit_type`) nie mają żadnych
przeliczników. Kształt tabeli do zapisania na V1, nie do wdrożenia teraz.

### 2.4 `Food` jako drzewo z substytutami

`Food` dziedziczy po `TreeModel` (materialized path) i ma
`substitute = ManyToMany("self")`, `substitute_siblings`, `substitute_children`,
`plural_name`.

Dwie funkcje, których nasze płaskie `ingredients` nie daje:

1. **Hierarchia**: „mąka” → „mąka pszenna” → „mąka tortowa”. Wyszukiwanie
   „przepisy z mąką” powinno znaleźć przepis używający mąki tortowej.
   Dziś nasz `SearchQuery` szuka po tekście (`ingredient_text LIKE`), więc
   „mąka tortowa” znajdzie się przy szukaniu „mąka”, ale „mąka krupczatka”
   już nie — bo to inne słowo.
2. **Zamienniki**: `AGENTS.md` sekcja 9 wymienia „zamienniki” jako funkcję AI.
   Tandoor pokazuje, że zamiennik to **relacja w danych**, a nie odpowiedź
   modelu językowego generowana za każdym razem. Masło ↔ margaryna,
   śmietana 18% ↔ jogurt grecki — to jest skończona lista, którą warto
   trzymać w tabeli i móc poprawić, gdy okaże się zła.

**Czego nie brać**: całej maszynerii dziedziczenia pól po drzewie
(`FoodInheritField`, `reset_inheritance`, `child_inherit_fields`,
sygnały `post_save`, ostrzeżenie w kodzie „avoid using UPDATE … unless you
intend to bypass those signals”, `cookbook/models.py:774-900`). To jest
~130 linii logiki na to, żeby dziecko w drzewie dziedziczyło kategorię
supermarketu po rodzicu. Dla nas: `ingredients.parent_id` (samo drzewo) plus
osobna tabela `ingredient_substitutes` — bez dziedziczenia czegokolwiek.

### 2.5 `Step.step_recipe` — krok, który jest innym przepisem

`cookbook/models.py:972`. Krok może wskazywać na cały przepis: „przygotuj
ciasto kruche według przepisu X”. Przy polskiej kuchni domowej to jest częste
(zakwas, sos beszamelowy, ciasto na pierogi, kruszonka) — i dziś taki przepis
albo się kopiuje, albo linkuje w treści kroku, tracąc powiązanie.

U nas: `recipe_steps.linked_recipe_id` nullable. Konsekwencje do przemyślenia
przed wdrożeniem: usunięcie przepisu podrzędnego (u nich `on_delete=PROTECT`),
widoczność (przepis prywatny w kroku publicznego przepisu — **to jest wyciek
prywatnej treści, jeśli krok renderuje jego zawartość**), pętle (A używa B,
B używa A). LATER, ale kształt zapisany.

### 2.6 Scalanie duplikatów (`MergeModelMixin`)

`cookbook/models.py:200-220` + `Food.merge_into()` / `Unit.merge_into()`.
Przenosi wszystkie odwołania na obiekt docelowy i kasuje źródłowy, z trzema
zabezpieczeniami: nie scalaj z samym sobą, nie scalaj między przestrzeniami,
nie scalaj rodzica w dziecko.

Nam to będzie potrzebne na pewno. `ingredients.normalized_name` jest unikalny,
co łapie „Cebula” i „cebula”, ale nie złapie „cebulka”, „cebula biała”,
„cebule”. Po roku będziemy mieli listę składników pełną wariantów, a moderator
nie będzie miał czym ich scalić. Warto wiedzieć, że to jest potrzebne, zanim
lista urośnie.

### 2.7 `PropertyType` z kategorią `ALLERGEN`

`cookbook/models.py:990-1065`. Właściwości produktu (wartości odżywcze,
alergeny, cena) jako typ + wartość na 100 g, z `FoodProperty` jako pivotem.
Dla nas: V2 (`docs/ROADMAP.md`), ale sygnał, że alergeny są **właściwością
składnika**, a nie tagiem przepisu — przepis dziedziczy alergen po składnikach.
Ważne, bo tag na przepisie użytkownik zapomni ustawić, a składnik i tak wpisze.

## 3. Przepływy UX warte adaptacji

1. **Surowy tekst jest wersją obowiązującą, parsowanie jest ulepszeniem.**
   `Ingredient.original_text` istnieje obok `food`/`unit`/`amount`, a test
   parsera (`cookbook/tests/other/test_ingredient_parser.py`) wprost zawiera
   przypadki opisane jako „does not always work perfectly”, np. „1 Zwiebel
   gehackt” rozpoznane jako jednostka „Zwiebel”. Mimo to zapis przechodzi.
   **U nas ta decyzja jest już podjęta i zrealizowana**
   (`recipe_ingredients.ingredient_text` NOT NULL — `docs/DATABASE.md`).
   Ważne, żeby przy implementacji parsera tego nie odwrócić: **nieudane
   parsowanie nigdy nie może zablokować publikacji przepisu.** Dla osoby 50+
   komunikat „nie rozumiem składnika: 1 szklanka mąki” przy zapisywaniu
   przepisu babci to koniec korzystania z serwisu.
2. **Zakres ilości sprowadzany do minimum + notatka**: „10–200 g czegoś”
   zapisywane jako 10 g z notatką „10–200”. Prosta reguła, która nie gubi
   informacji i nie wymaga dwóch kolumn.
3. **Nawias to notatka, przecinek to notatka.** „3 cebule, posiekane” →
   składnik „cebule”, notatka „posiekane”. Nasze `recipe_ingredients.note`
   (300 znaków) już na to czeka.
4. **Krok bez nagłówka i bez tabeli składników** (`Step.show_as_header`,
   `show_ingredients_table`). Autor decyduje, czy krok wygląda jak sekcja,
   czy jak zwykły akapit. Dla nas prawdopodobnie zbędne — jeden spójny wygląd
   kroku jest zgodniejszy z `docs/UX_50_PLUS.md` niż wybór dla autora.

## 4. Przypadki brzegowe bezpieczeństwa i moderacji

Tandoor jest aplikacją do samodzielnego hostowania dla rodziny, więc moderacji
w naszym rozumieniu nie ma. Wartościowe są za to przypadki brzegowe parsera —
bo one są **wektorem wejścia od użytkownika**:

1. **Twardy limit długości przed parsowaniem**: `parse()` odrzuca łańcuchy
   dłuższe niż 512 znaków (`cookbook/helper/ingredient_parser.py:177-179`)
   **zanim** cokolwiek zrobi. Nasze `recipe_ingredients.ingredient_text` ma
   240 znaków w bazie, ale parser trzeba będzie chronić osobno — kilka wyrażeń
   regularnych z nawrotami na łańcuchu 100 kB to jest ReDoS.
2. **Warunek `len(ingredient) < 1000` postawiony wprost przed jednym z regexów**
   (`ingredient_parser.py:186`) — ślad po realnym incydencie z wydajnością
   wyrażenia regularnego. Wniosek do zapisania: każde wyrażenie regularne
   działające na tekście od użytkownika dostaje limit długości wejścia.
3. **Dzielenie przez zero przy ułamkach**: `parse_fraction()` łapie
   `ZeroDivisionError` — „1/0 szklanki” to poprawne wejście z klawiatury.
4. **`get_food()` / `get_unit()` tworzą rekord, jeśli nie znajdą**
   (`ingredient_parser.py:38-60`). To jest wygodne i **niebezpieczne**:
   każdy literówkowy składnik zakłada nowy wiersz w słowniku. U nich to
   ograniczone do jednej rodziny (`space`), u nas byłoby publiczne i wspólne.
   Rekomendacja: `ingredients` uzupełniamy **tylko przez dopasowanie do
   istniejącego wpisu**; nierozpoznany składnik zostaje wyłącznie jako
   `ingredient_text` z `ingredient_id = NULL` — schemat już to dopuszcza.
   Inaczej pierwszy spamer wpisze nam do słownika 500 wierszy z linkami.
5. **`NEVER_UNIT` — słowo, które nigdy nie jest jednostką**
   (`cookbook/models.py:1674`). Klasyczny błąd parsera: „1 jajko” → ilość 1,
   jednostka „jajko”, składnik pusty. Po polsku wpadną w to: jajko, cebula,
   ząbek (czosnku), garść, ziarno, listek, gałązka.
   Lista wyjątków musi być **polska i własna**, ich lista jest bezużyteczna.
6. **`TRANSPOSE_WORDS`** — „cukier trzcinowy” kontra „trzcinowy cukier”.
   Kolejność przymiotnika w polszczyźnie jest swobodniejsza niż w niemieckim,
   więc dopasowanie do słownika musi to znieść.

## 5. Wzorce testowe i jakościowe

- **Test parsera jako jeden słownik wejście → oczekiwany wynik**
  (`cookbook/tests/other/test_ingredient_parser.py`, ~60 przypadków w jednym
  `@pytest.mark.parametrize`). Dodanie przypadku brzegowego to jedna linijka.
  To jest **dokładnie ten kształt, który powinien mieć nasz przyszły test
  normalizacji składnika** — i uzasadnienie dla katalogu `tests/Unit`
  (rekomendacja R14 z notatki o Pixelfedzie).
- **Przypadki, o których wiadomo, że są parsowane źle, zostają w teście**
  z komentarzem, zamiast być usuwane. Test dokumentuje wtedy granicę
  możliwości, a nie udaje, że jej nie ma. Bardzo dobra praktyka dla nas,
  bo polskie „1 ząbek czosnku” będzie takim przypadkiem.
- **Testy podzielone tematycznie po funkcji, nie po klasie**:
  `test_recipe_search_text.py`, `test_recipe_search_filters.py`,
  `test_recipe_search_makenow.py`, `test_unit_conversion.py`,
  `test_ingredient_parser.py`. Nasz `tests/Feature/SearchTest.py` jest jednym
  plikiem — przy trzech typach wyszukiwania warto go rozbić.
- `pytest.ini` + `conftest.py` z fabrykami (`cookbook/tests/factories/`) —
  odpowiednik naszych fabryk Laravela.

## 6. Wzorce wydajnościowe

- **`SearchVectorField` + `GinIndex` na kroku przepisu**
  (`cookbook/models.py:970,988`) — kolumna `tsvector` **materializowana
  w tabeli**, nie liczona w zapytaniu. To potwierdza kierunek, który mamy
  w `docs/ARCHITECTURE.md`.
- **`unaccent` stosowany selektywnie, per pole, na podstawie ustawień**
  (`cookbook/helper/recipe_search.py:186-193`) — bo `unaccent()` na kolumnie
  psuje użycie zwykłego indeksu.

  **To prowadzi do konkretnego znaleziska u nas.** Nasze indeksy trigramowe
  są założone na surowych kolumnach
  (`database/migrations/2026_09_05_000400_create_recipes_tables.php:87`):
  ```sql
  CREATE INDEX recipes_title_trgm_idx ON recipes USING gin (title gin_trgm_ops);
  ```
  a `App\Domain\Search\SearchQuery::recipes()` (`app/Domain/Search/SearchQuery.php:40-52`)
  odpytuje wyrażeniem:
  ```sql
  unaccent(lower(title)) % ?   -- oraz  unaccent(lower(title)) LIKE '%...%'
  ```
  PostgreSQL użyje indeksu **tylko wtedy, gdy wyrażenie w zapytaniu jest
  identyczne z wyrażeniem w indeksie**. `gin (title gin_trgm_ops)` nie
  obsłuży `gin (unaccent(lower(title)))`. Efekt: pełny skan `recipes`
  i `recipe_ingredients` przy każdym wyszukiwaniu, mimo trzech założonych
  indeksów GIN, które nic nie robią.
  To samo dotyczy `recipe_ingredients_text_trgm_idx`,
  `profiles_username_trgm_idx` i `profiles_display_name_trgm_idx`
  (ta ostatnia jest odpytywana jako `unaccent(lower(display_name))`).
  Dodatkowa pułapka: **`unaccent()` nie jest w PostgreSQL funkcją `IMMUTABLE`**,
  więc nie da się jej użyć w indeksie wyrażeniowym wprost — potrzebna jest
  własna funkcja-opakowanie zadeklarowana jako `IMMUTABLE`.
  → **propozycja issue** na końcu notatki.
- **`Index(fields=['id'])` na `Ingredient` i `Food`** — indeks na kluczu
  głównym, czyli duplikat. Ślad po nieprzemyślanej optymalizacji; wart
  odnotowania jako przypomnienie, że indeks bez zapytania to koszt zapisu
  bez zysku (patrz rekomendacja R6 z notatki o Fresns: komentować indeksy).
- **Cache reguł automatyzacji przy tworzeniu parsera** (`cache_mode=True`) —
  parser wczytuje reguły raz na import całego przepisu, nie raz na składnik.
  Przy imporcie przepisu z 20 składnikami to różnica 1 kontra 20 odpytań.

## 7. Czego świadomie nie przenosić

| Rzecz | Dlaczego nie |
|---|---|
| **`Space` (wielodzierżawność) w każdej tabeli** + `ScopedManager` | Kuking to jeden serwis, jedna przestrzeń. `space_id` w każdym indeksie i każdym `UNIQUE` to koszt bez korzyści |
| **Dziedziczenie pól w drzewie produktów** (`FoodInheritField`, `reset_inheritance`, sygnały `post_save`) | ~130 linii i ostrzeżenie „nie używaj UPDATE, bo ominiesz sygnały”. Klasyczny overengineering z `AGENTS.md` sekcja 3 |
| **Automatyczne tworzenie składnika/jednostki przy parsowaniu** | Publiczny słownik zaśmiecony literówkami i spamem — patrz 4.4 |
| **`Automation` jako konfigurowalne reguły w bazie** (10 typów, `param_1..3`) | To samo co `archives` w Fresns: reguła w bazie jest nietestowalna. Nasze wyjątki parsera należą do kodu i mają test |
| **`CustomFilter` — zapisane zapytania użytkownika jako tekst w bazie** | Zapytanie budowane z tekstu od użytkownika; ryzyko i złożoność bez potrzeby w MVP |
| **`AiProvider` z `api_key` w tabeli i `ai_credits_balance` w przestrzeni** | Klucz API w bazie aplikacji; u nas sekrety są w środowisku (`docs/legal/SECURITY_BASELINE.md`) |
| **Motywy i kolory nawigacji w bazie** (`nav_bg_color`, `logo_color_*` × 7) | Kuking ma jeden system projektowy (`docs/design/DESIGN_SYSTEM.md`) |
| **`Step.show_as_header` / `show_ingredients_table`** | Wybór wyglądu oddany autorowi; niespójne ekrany są sprzeczne z `docs/UX_50_PLUS.md` |
| Planer, lista zakupów, spiżarnia, `ConnectorConfig` | Poza MVP (`AGENTS.md` sekcja 12); wracamy przy V1/V2 |

## 8. Rekomendacje dla Kuking

| # | Rekomendacja | Nasz plik / tabela | Waga |
|---|---|---|---|
| R1 | **Indeksy trigramowe muszą pasować do wyrażeń z zapytań**: `IMMUTABLE`-owe opakowanie na `unaccent` + indeksy na `f_unaccent(lower(kolumna)) gin_trgm_ops`, albo zdjęcie `unaccent(lower(...))` z zapytań i przechowywanie znormalizowanej kolumny | `database/migrations/2026_09_05_000400_create_recipes_tables.php:87,165-166`, `database/migrations/2026_09_05_000200_create_profiles_table.php:42-43`, `app/Domain/Search/SearchQuery.php:40-52,73-78` | **P1 — dziś trzy indeksy GIN nie są używane, wyszukiwanie robi pełny skan** |
| R2 | `recipe_ingredients.no_amount boolean not null default false` — „do smaku” to nie to samo co „nie wiadomo ile” | `database/migrations/2026_09_05_000400_create_recipes_tables.php`, `docs/DATABASE.md` | P1 przed skalowaniem porcji (`AGENTS.md` sekcja 9) |
| R3 | Zasada, także w komentarzu w kodzie: parser składników **nigdy** nie blokuje zapisu przepisu; nierozpoznane wchodzi jako sam `ingredient_text` | `app/Domain/Recipes/Actions/PublishRecipe.php`, `docs/UX_50_PLUS.md` | P1 |
| R4 | Nie tworzyć wpisów w `ingredients` automatycznie z tekstu użytkownika — tylko dopasowanie do istniejących; reszta zostaje w `ingredient_text` | `app/Domain/Recipes/`, `database/migrations/2026_09_05_000400_create_recipes_tables.php` (`ingredient_id` już nullable) | P1 — inaczej publiczny słownik zapełni się literówkami i spamem |
| R5 | Polska lista słów, które nigdy nie są jednostką (jajko, cebula, ząbek, garść, listek, gałązka, ziarno) w `config/kuking.php` + test jednostkowy | `config/kuking.php`, `tests/Unit/` | P1 razem z parserem |
| R6 | Test parsera jako słownik wejście → wynik, z zachowanymi przypadkami, które parsujemy źle, opisanymi w komentarzu | `tests/Unit/`, wzór: ich `test_ingredient_parser.py` | P1 |
| R7 | Limit długości wejścia przed każdym wyrażeniem regularnym działającym na tekście użytkownika | przyszły parser w `app/Domain/Recipes/`, `docs/legal/SECURITY_BASELINE.md` | P1 — ReDoS |
| R8 | Test na ciągłość grup składników (`group_name` nie może się „przeplatać”) | `tests/Feature/RecipeTest.php`, `recipe_ingredients` | P2 |
| R9 | Scalanie duplikatów składników i jednostek w panelu (przenieś odwołania → usuń źródło, w transakcji) | `app/Http/Controllers/Admin/`, `ingredients`, `units`, `recipe_ingredients` | P2 — potrzebne, zanim słownik urośnie |
| R10 | `ingredient_substitutes` (zamienniki jako dane, nie jako odpowiedź modelu AI za każdym razem) | nowa tabela, `AGENTS.md` sekcja 9 | LATER (V1) |
| R11 | `unit_conversions` z `ingredient_id` nullable — „szklanka mąki” ≠ „szklanka cukru” | nowa tabela, `units`, `docs/DATABASE.md` sekcja V1 | LATER (V1) — ale kształt zapisać teraz |
| R12 | `recipe_steps.linked_recipe_id` (krok jako inny przepis) z rozstrzygniętą widocznością: krok nie może ujawniać treści przepisu prywatnego | `recipe_steps`, `app/Policies/RecipePolicy.php` | LATER (V1) |
| R13 | `ingredients.parent_id` — płaska hierarchia produktów, bez dziedziczenia pól | `ingredients`, `app/Domain/Search/SearchQuery.php` | LATER (V1) |
| R14 | Poprawić `docs/research/PUBLIC_REPOS.md` poz. 4: licencja to AGPL-3.0 **+ Commons Clause** | `docs/research/PUBLIC_REPOS.md` | P2 — różnica prawnie istotna |

### Propozycja issue (nie naprawiam, zgodnie z zakresem)

**„Indeksy trigramowe nie są używane przez wyszukiwarkę”.**
Wszystkie indeksy `gin (... gin_trgm_ops)` są założone na surowych kolumnach
(`recipes_title_trgm_idx`, `recipe_ingredients_text_trgm_idx`,
`profiles_username_trgm_idx`, `profiles_display_name_trgm_idx`), a
`App\Domain\Search\SearchQuery` odpytuje wyrażeniem `unaccent(lower(kolumna))`.
PostgreSQL dopasowuje indeks wyrażeniowy tylko do identycznego wyrażenia, więc
każde wyszukiwanie robi pełny skan. Dodatkowo `unaccent()` nie jest
`IMMUTABLE`, więc indeks wyrażeniowy wymaga własnej funkcji-opakowania.
Kryteria akceptacji: (1) `EXPLAIN ANALYZE` na wyszukiwaniu przepisu pokazuje
`Bitmap Index Scan`, a nie `Seq Scan`; (2) test na zbiorze ≥ 1000 przepisów;
(3) migracja z planem rollbacku (`AGENTS.md` sekcja 6).
