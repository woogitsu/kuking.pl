# mealie-recipes/mealie — notatka researchowa

**Licencja: AGPL-3.0** (`LICENSE`). Te same skutki co przy Pixelfedzie:
udostępnianie pochodnej przez sieć wymusiłoby otwarcie źródeł Kuking, więc
**kodu stąd nie bierzemy**. Bierzemy kształt modelu danych, przepływy edytora
przepisu i listę zabezpieczeń importera z URL — te ostatnie są tu najlepsze
z wszystkich analizowanych repozytoriów.

Snapshot: `git clone --depth 1` z 2026-09-05. Python (FastAPI + SQLAlchemy)
+ Vue. Ciekawostka: repozytorium ma własne `AGENTS.md` i `CLAUDE.md` — pracują
nad nim agenci AI, podobnie jak u nas.

---

## 1. Co wynika z licencji

- Zero kopiowanego kodu, także z `mealie/pkgs/safehttp/` (kuszące, bo gotowe).
- Lista **warunków**, które musi spełnić bezpieczny pobieracz URL-i, to nie
  utwór, tylko wiedza dziedzinowa — i tę listę przenosimy (sekcja 4).
- Ich `alembic/versions/` (migracje) czyta się jak historię problemów.
  Nazwy plików i treść migracji są tekstem chronionym; wnioski z nich — nie.

## 2. Użyteczny model danych

### 2.1 Aliasy składnika i jednostki jako osobne tabele

`mealie/db/models/recipe/ingredient.py:379-460`:

```
ingredient_units_aliases:  id, unit_id FK, name, name_normalized (index)
ingredient_foods_aliases:  id, food_id FK, name, name_normalized (index)
```

To jest **czystsze rozwiązanie synonimów niż `Automation FOOD_ALIAS`
w Tandoorze** (reguła w bazie, patrz notatka o Tandoorze, sekcja 7): alias jest
zwykłym wierszem wskazującym na kanoniczny wpis, można go dodać z panelu,
policzyć i usunąć.

Dla nas to jest odpowiedź na pytanie „co zrobić z «cebulka», «cebula biała»,
«cebule»”: `ingredient_aliases (ingredient_id, name_normalized UNIQUE)`.
Mamy już `ingredients.normalized_name UNIQUE`
(`database/migrations/2026_09_05_000400_create_recipes_tables.php`) i indeks
trigramowy na niej — alias wchodzi obok, bez przebudowy.

### 2.2 Kolumny `*_normalized` zamiast `unaccent(lower())` w zapytaniu

To najważniejsze techniczne znalezisko z tego repozytorium i **bezpośrednia
odpowiedź na problem, który znalazłem przy Tandoorze**.

Mealie trzyma znormalizowane kopie pól: `recipes.name_normalized`,
`recipes.description_normalized`, `recipes_ingredients.note_normalized`,
`original_text_normalized`, `*_aliases.name_normalized`. Normalizacja jest
liczona **przy zapisie** (`mealie/db/models/_model_base.py:31-33`:
usunięcie znaków diakrytycznych → interpunkcja na spacje → małe litery →
przycięcie do 255 znaków), a indeks GIN `gin_trgm_ops` stoi **na kolumnie
znormalizowanej**, nie na surowej (`mealie/db/models/recipe/recipe.py:247-268`).

Dzięki temu zapytanie brzmi `WHERE name_normalized % ?` — i indeks działa.

U nas jest odwrotnie: indeksy są na surowych kolumnach, a
`App\Domain\Search\SearchQuery` odpytuje `unaccent(lower(title))`
(`app/Domain/Search/SearchQuery.php:40-52`), więc **indeksy nie są używane**
(szczegóły w notatce o Tandoorze, sekcja 6 i propozycja issue).

**Odniesienie do D-004** (wyszukiwarka na PostgreSQL, bez Scouta):
to znalezisko **nie podważa D-004, tylko ją chroni**. Warunek zmiany decyzji
brzmi „przekroczenie SLA wyszukiwania przy realnym ruchu” — a przy dzisiejszej
implementacji SLA zostanie przekroczone nie dlatego, że PostgreSQL nie
wystarcza, tylko dlatego, że robi pełny skan przy każdym zapytaniu. Naprawa
indeksów jest warunkiem uczciwego sprawdzenia D-004.
Mealie pokazuje, że kolumna znormalizowana + `gin_trgm_ops` na niej to
rozwiązanie sprawdzone w produkcji, a nie teoria.
Ma też przewagę nad funkcją `IMMUTABLE`-owym opakowaniem `unaccent`:
normalizację widać w danych, można ją obejrzeć w `psql`, a zmiana reguł
normalizacji jest migracją danych, nie przebudową indeksu na żywym systemie.

### 2.3 Zamienniki na dwóch poziomach

`ingredient_foods_substitutions` (globalnie: masło ↔ margaryna) oraz
`recipes_ingredients_substitutions` (w tym konkretnym przepisie).
Obie tabele mają `substitute_food_id` **nullable** plus `note` — czyli wolno
zapisać zamiennik, który nie jest pozycją słownika:
„można zastąpić czymkolwiek kwaśnym”.

Warta uwagi jest funkcja `resolve_substitutions`
(`mealie/db/models/recipe/ingredient.py:31-90`) i jej komentarz: identyfikatory
zamienników przychodzą **prosto z żądania**, więc nie obejmuje ich zwykłe
ograniczenie zakresu w repozytorium — trzeba je rozwiązać osobno wobec
właściciela i odrzucić te, które wskazują na cudze albo usunięte dane.
To jest dokładnie nasza reguła „**UUID w adresie nie jest autoryzacją**”
(`AGENTS.md` sekcja 7), tylko zastosowana do identyfikatorów **w treści
formularza**, a nie w URL-u. Nasz przyszły edytor przepisu będzie miał
ten sam problem przy `unit_id` i `ingredient_id` przesyłanych z formularza.

### 2.4 `recipe_settings` — ustawienia per przepis

`mealie/db/models/recipe/settings.py`: `public`, `disable_comments`, `locked`,
`show_nutrition`, `show_assets`, `landscape_view`.

Istotne są dwa:
- **`disable_comments` per przepis** — zbieżne z `users.comment_policy`
  z Fresns (notatka o Fresns, sekcja 2.3). Dwa niezależne projekty doszły do
  tego samego, co jest mocnym sygnałem, że to realna potrzeba, a nie
  wymyślona funkcja.
- **`locked`** — przepis zablokowany do edycji. U nas odpowiednikiem będzie
  raczej stan moderacyjny niż ustawienie autora.

Reszta (`landscape_view`, `show_assets`) to wybór wyglądu oddany użytkownikowi
— odrzucamy z tego samego powodu co u Tandoora: spójny ekran jest ważniejszy
niż konfigurowalny (`docs/UX_50_PLUS.md`).

### 2.5 `recipe_share_tokens` — link czasowy do prywatnego przepisu

`mealie/db/models/recipe/shared.py`: `recipe_id`, `expires_at` (domyślnie
30 dni). Pozwala pokazać komuś prywatny przepis bez publikowania go i bez
zakładania konta przez odbiorcę.

To jest realna potrzeba naszej grupy: „chcę wysłać siostrze przepis babci,
ale nie chcę go wrzucać publicznie”. Dziś `recipes.visibility` ma trzy wartości
(`public` / `followers` / `private`) i takiego przypadku nie obsługuje —
jedyne wyjście to opublikować.

Kształt dla nas: `recipe_share_links (token_hash, recipe_id, created_by,
expires_at, revoked_at)`. **Token trzymany jako hash**, nie jawnie —
inaczej wyciek kopii bazy to wyciek wszystkich prywatnych przepisów.
Ich model trzyma UUID wprost; to jest miejsce, gdzie warto zrobić lepiej.

### 2.6 `recipe_timeline_events` — jedna oś czasu przepisu

`mealie/db/models/recipe/recipe_timeline.py`: `recipe_id`, `user_id`,
`subject`, `message`, `event_type`, `image`, `timestamp`.
Jedna tabela na wszystko, co się przy przepisie wydarzyło: „ktoś to ugotował”,
komentarz, zdarzenie systemowe („przepis zaktualizowano”).

**Nie kopiujemy tego kształtu** — nasz rozdział na `cooked_events` (z
`would_make_again`, `perceived_difficulty`, `actual_minutes`, `changes_note`
i osobną tabelą zdjęć) jest bogatszy i pilnowany CHECK-ami, a `comments` mają
własny cykl moderacyjny. Sklejenie ich w jedną tabelę z polem `event_type`
byłoby cofnięciem.

**Bierzemy natomiast pomysł widoku**: strona przepisu pokazuje **jedną
chronologiczną oś** — kto ugotował, co napisał, jak mu wyszło — zamiast dwóch
osobnych sekcji „komentarze” i „wykonania”. Dla nas to jest wprost sedno
produktu (`AGENTS.md` sekcja 1: „Ugotowałem” jest silniejsze niż lajk), a nie
wymaga żadnej migracji: to `UNION` dwóch zapytań w widoku.
`HouseholdToRecipe.last_made` i `recipes.last_made` u nich są polami
pochodnymi — u nas taką liczbę zawsze wyliczamy z `cooked_events`, bo
**D-005** (brak `UNIQUE (user_id, recipe_id)`) oznacza, że wykonań jest wiele
i to jest cała wartość tej tabeli.

## 3. Przepływy UX warte adaptacji

1. **Nagłówek sekcji jako pole wiersza składnika** (`recipes_ingredients.title`
   — „Section Header - Shows if Present”). Trzecie repozytorium z innym
   rozwiązaniem tego samego problemu (Tandoor: `is_header`, my: `group_name`).
   Wniosek: nasza wersja jest równie dobra, ale wymaga testu na ciągłość grup
   (rekomendacja R8 z notatki o Tandoorze).
2. **`note` nadpisujące sklejony tekst** („Force Show Text - Overrides Concat”):
   gdy automatyczne złożenie „ilość + jednostka + składnik” wychodzi kiepsko,
   autor wpisuje własny tekst i to on jest pokazywany. U nas rolę „tekstu
   obowiązującego” pełni `ingredient_text` — **zrealizowane**, ale warto
   pilnować, żeby widok przepisu pokazywał go zawsze, gdy istnieje, zamiast
   składać własny opis ze znormalizowanych pól.
3. **Import z URL: podgląd przed zapisem.** Ich `cleaner.py` (600 linii) ma
   osobną funkcję czyszczącą dla każdego pola JSON-LD: `clean_yield`,
   `clean_time`, `clean_instructions`, `clean_ingredients`, `clean_nutrition`.
   Skala tego pliku to najlepszy argument, żeby import z URL był w V2, a nie
   wcześniej — i żeby zawsze kończył się ekranem „sprawdź i popraw”, nigdy
   zapisem od razu.
4. **Edytor jako SPA (Vue) z autosave** — **nie dla nas**. Publikacja przepisu
   musi działać bez JavaScriptu (**D-007**). Wieloetapowy formularz w Blade
   + Livewire z zapisem wersji roboczej po stronie serwera daje to samo bez
   łamania tej decyzji.

## 4. Przypadki brzegowe bezpieczeństwa i moderacji

`mealie/pkgs/safehttp/transport.py` to najlepsza lista kontrolna SSRF, jaką
znalazłem w tym researchu. Dla naszego importera z URL (V2) przenosimy
**wymagania**, nie kod:

1. **Sprawdzamy adres IP, na który rozwiązuje się nazwa, a nie samą nazwę.**
   `evil.example.com` może wskazywać na `127.0.0.1`. Filtr po stringu jest
   bezużyteczny.
2. **Blokujemy pełen zestaw zakresów**: prywatne, loopback, link-local
   (`169.254.0.0/16` — tam siedzą metadane chmur), multicast, zarezerwowane,
   nieokreślone **oraz CGNAT `100.64.0.0/10`**, który — jak mówi ich komentarz
   (`transport.py:14-16`) — nie na każdej wersji biblioteki jest uznawany za
   prywatny. Tego byśmy nie wymyślili.
3. **Rozpakowujemy adresy IPv4 zapisane jako IPv6** (`::ffff:127.0.0.1`),
   bo inaczej sprawdzenie IPv4 można ominąć zapisem
   (`transport.py:36-40`). Tego byśmy nie wymyślili tym bardziej.
4. **Każde przekierowanie sprawdzamy osobno.** Adres publiczny może
   przekierować na `127.0.0.1` — walidacja tylko pierwszego adresu to dziura.
5. **Twardy limit czasu i pobieranie strumieniowe z limitem rozmiaru**
   (`fetch.py:8-13`) — obrona przed adresem, który zwraca nieskończony
   strumień. To jest odpowiednik naszego limitu megapikseli przy zdjęciach
   (`config/kuking.php:29`).
6. Osobno, poza SSRF: **nie zakładamy, że możliwość technicznego pobrania
   treści daje prawo do jej publikacji** — to już jest zapisane
   w `docs/research/PUBLIC_REPOS.md` (pozycja 14) i pozostaje aktualne.

Poza importerem:

7. **Identyfikatory z formularza wymagają autoryzacji tak samo jak te z URL-a**
   — patrz 2.3. Konkretnie u nas: przy zapisie przepisu formularz przyśle
   `unit_id` i `ingredient_id`; trzeba sprawdzić, że wskazują na istniejące
   wpisy słownika, a nie na cokolwiek innego.
8. **Token udostępniania trzymany jako hash** — nasza poprawka do ich modelu
   (2.5).

## 5. Wzorce testowe i jakościowe

- **Osobny katalog `tests/multitenant_tests/`** z klasą bazową
  `ABCMultiTenantTestCase` i przypadkiem na każdy typ zasobu
  (`case_foods.py`, `case_units.py`, `case_tags.py`, `case_categories.py`,
  `case_tools.py`). Każdy przypadek: zasiej dane w dwóch grupach → zapytaj
  jako użytkownik grupy 1 → sprawdź, że nie widać niczego z grupy 2.

  **To jest wzorzec, którego u nas brakuje najbardziej.** Naszym
  odpowiednikiem wielodzierżawności jest **widoczność**: `public` / `followers`
  / `private`, blokady, treść ukryta przez moderację, wersje robocze.
  Zamiast pojedynczych asercji rozsianych po testach warto mieć jedną klasę
  bazową „przygotuj treść w każdym stanie widoczności → sprawdź, że obcy
  użytkownik, gość, zablokowany i moderator widzą dokładnie to, co powinni”,
  uruchamianą dla `Post`, `Recipe`, `Comment`, `CookedEvent`, `Collection`.
  Jeden nowy typ treści = jeden nowy plik przypadku, a nie pięć zapomnianych
  asercji. To jest też najtańsza obrona przed regresją, którą trudno zauważyć:
  wyciek prywatnej treści nie wywala testu, tylko cicho pokazuje za dużo.
- **Katalog `tests/unit_tests/ingredient_parser/`** — potwierdzenie
  rekomendacji z notatki o Tandoorze (test parsera jako zbiór przypadków).
- `tests/e2e`, `tests/integration_tests`, `tests/unit_tests`,
  `tests/validator_tests` — podział, w którym widać, co ile kosztuje.
  W kontekście **D-010** (CI na własnym runnerze): przy własnej maszynie
  warto od razu tak podzielić testy, żeby hook `pre-push` uruchamiał tanie,
  a runner wszystkie.

## 6. Wzorce wydajnościowe

- **Normalizacja przy zapisie zamiast przy odczycie** (2.2) — jedna decyzja,
  która zamienia pełny skan w użycie indeksu.
- **Przycięcie znormalizowanego pola do 255 znaków** z komentarzem
  odsyłającym do dokumentacji PostgreSQL o limicie rozmiaru wpisu w B-drzewie
  (`_model_base.py:31-33`). Nas dotyczy przy `recipes.summary` (2000 znaków)
  — indeks trigramowy na całości byłby duży, a wartość mała.
- **Indeks tworzony warunkowo tylko dla PostgreSQL**
  (`recipe.py:247`) — u nas problem nie istnieje, bo **D-002** przesądza,
  że testy chodzą na PostgreSQL, więc możemy używać `gin_trgm_ops`
  bez rozgałęzień. To jest konkretna korzyść z tej decyzji.
- **Migracja usuwająca indeks** (`2025-02-09_..._remove_instructions_index.py`)
  — indeks na treści kroków okazał się kosztem bez zysku. Kolejne
  potwierdzenie rekomendacji „komentuj indeks zapytaniem, które obsługuje”
  (R6 z notatki o Fresns).

## 7. Czego świadomie nie przenosić

| Rzecz | Dlaczego nie |
|---|---|
| `groups` + `households` (wielodzierżawność, dwa poziomy) | Kuking to jeden serwis. Rodzina/„household” wraca dopiero przy V1 i wtedy jako funkcja, nie jako fundament schematu |
| Edytor przepisu jako SPA z autosave | **D-007**: publikacja przepisu musi działać bez JavaScriptu |
| Sklejanie „ugotowałem”, komentarza i zdarzenia systemowego w jedną tabelę `timeline_events` z polem `event_type` | Nasze `cooked_events` mają własne pola i CHECK-i, `comments` własny cykl moderacji; scalenie byłoby cofnięciem (**D-005** czyni z wykonań osobną, bogatą encję) |
| `recipe_settings` z ustawieniami wyglądu (`landscape_view`, `show_assets`) | Wybór wyglądu oddany autorowi = niespójne ekrany (`docs/UX_50_PLUS.md`) |
| Podszywanie się pod przeglądarkę przy pobieraniu stron (`BROWSER_IMPERSONATIONS`, obchodzenie JA3/JA4, FlareSolverr) | Świadome obchodzenie zabezpieczeń cudzego serwisu; ryzyko prawne i wizerunkowe nieproporcjonalne do zysku z importu |
| `is_ocr_recipe` i cały tor OCR | V2 (`AGENTS.md` sekcja 12) |
| `rating` jako pole przepisu | Ocena gwiazdkowa to nie nasz sygnał jakości — nim jest „Ugotowałem” (`AGENTS.md` sekcja 1). Publiczna średnia ocen zniechęca do publikowania przepisów rodzinnych |
| Token udostępniania trzymany jawnie | Trzymamy hash (2.5) |

## 8. Rekomendacje dla Kuking

| # | Rekomendacja | Nasz plik / tabela | Waga |
|---|---|---|---|
| R1 | **Kolumny znormalizowane + indeks `gin_trgm_ops` na nich**: `recipes.title_normalized`, `recipe_ingredients.ingredient_text_normalized`, `profiles.display_name_normalized`; zapytania odpytują wyłącznie te kolumny. Rozwiązanie sprawdzone u nich w produkcji; alternatywą jest `IMMUTABLE`-owe opakowanie `unaccent` | `app/Domain/Search/SearchQuery.php:40-52,73-78`, migracje `2026_09_05_000400` i `2026_09_05_000200`, `docs/DATABASE.md`; **chroni D-004** | **P1 — bez tego wyszukiwarka robi pełny skan, a D-004 zostanie oceniona niesprawiedliwie** |
| R2 | Klasa bazowa testów widoczności („każdy stan × każdy typ obserwatora”) uruchamiana dla `Post`, `Recipe`, `Comment`, `CookedEvent`, `Collection` | `tests/Feature/`, wzór: `tests/multitenant_tests/case_abc.py` | **P1 — wyciek prywatnej treści nie wywala testu, tylko cicho pokazuje za dużo** |
| R3 | `ingredient_aliases (ingredient_id, name_normalized UNIQUE)` — synonimy jako dane, nie jako reguły | `ingredients`, `app/Domain/Search/SearchQuery.php` | P2 (V1) |
| R4 | Sprawdzać identyfikatory przychodzące **w formularzu** (`unit_id`, `ingredient_id`, `media_id`) tak samo jak te w URL-u | przyszły edytor przepisu, `app/Http/Controllers/RecipeController.php`, `AGENTS.md` sekcja 7 | P1 przy pracach nad edytorem |
| R5 | Widok przepisu jako jedna oś czasu (wykonania + komentarze w porządku chronologicznym), bez zmian w schemacie | `resources/views/pages/recipe/`, `app/Http/Controllers/RecipeController.php` | P2 — tanie, a bezpośrednio wzmacnia „Ugotowałem” |
| R6 | `recipe_share_links` z **hashem** tokenu i `expires_at` — pokazanie prywatnego przepisu bez publikowania | nowa tabela, `app/Policies/RecipePolicy.php`, `docs/DATABASE.md` | P2 (V1) — realna potrzeba grupy 50+ |
| R7 | Pełna lista wymagań SSRF (rozwiązanie DNS, zakresy prywatne + CGNAT, IPv4-w-IPv6, każde przekierowanie osobno, limit czasu i rozmiaru) wpisana do issue o imporcie **zanim** powstanie kod | `docs/ROADMAP.md` (V2), przyszły `app/Domain/Recipes/Import/`, `docs/legal/SECURITY_BASELINE.md` | P2 (V2), zapisać teraz |
| R8 | `profiles.comment_policy` — potwierdzenie rekomendacji R3 z notatki o Fresns przez drugi niezależny projekt | `app/Domain/Comments/Actions/PublishComment.php`, `profiles` | P1 |
| R9 | Import z URL zawsze kończy się ekranem „sprawdź i popraw”, nigdy zapisem od razu | `docs/ROADMAP.md` (V2) | LATER |
| R10 | Podział testów na tanie i drogie, żeby hook `pre-push` uruchamiał tanie, a runner z **D-010** wszystkie | `phpunit.xml`, `scripts/check.sh`, `docs/infra/SELF_HOSTED_RUNNER.md` | P2 |

### Odniesienie do podjętych decyzji

- **D-003** (własny model `media`): Mealie trzyma zdjęcie jako `image: String`
  na przepisie i pojedynczy `image` na zdarzeniu osi czasu — bez statusu,
  bez checksumy, bez moderacji. Kolejne potwierdzenie, że gotowe rozwiązania
  nie obsługują cyklu `pending → processing → ready | rejected`.
  **Research nie daje argumentu do zmiany D-003.**
- **D-004** (PostgreSQL zamiast Scouta): Mealie robi dokładnie to samo
  (trigramy w PostgreSQL, bez zewnętrznego silnika) na znacznie większej
  bazie przepisów. **Research potwierdza D-004** i jednocześnie pokazuje,
  że nasza implementacja indeksów jest błędna (R1).
- **D-005** (brak `UNIQUE` w `cooked_events`): ich `last_made` jako pojedynczy
  znacznik czasu to model „ostatnio zrobione”, a nie „historia gotowania”.
  **Potwierdza D-005** — ich rozwiązanie gubi dokładnie to, co u nas jest
  najcenniejsze.
- **D-007** (bez JavaScriptu): ich edytor przepisu bez skryptu nie działa
  w ogóle. To jest koszt, który świadomie odrzucamy.
