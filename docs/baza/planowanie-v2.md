# Planer, lista zakupów i gotowanie (V2)

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
| `note` | `varchar(80)` NULL | prywatny dopisek przy pozycji z przepisem (#2549, migracja `2026_10_03_130000`); NULL = brak |
| `planned_servings` | `numeric(5,2)` NULL | prywatna, świadomie wybrana liczba porcji na ten dzień (#2509, migracja `2026_10_06_200100`); NULL = ilości autora. Nie migawka składników: otwarcie z planu przelicza AKTUALNĄ treść przepisu przez `WyborPorcji` |
| `created_at` / `updated_at` | `timestamptz` | |

**Plan jest prywatny.** Nie ma kolumny widoczności, bo nie ma czego pokazywać
innym: każda trasa (`/planer`) chodzi po pozycjach zalogowanego, a usunięcie
przechodzi przez `MealPlanEntryPolicy`.

Formularz GET „Szukaj w moich planach” (#2581) zachowuje wpisaną frazę po
odmowie walidacji. Błąd ma własne podsumowanie z linkiem do widocznego pola
`#szukaj-w-planach` oraz komunikat przy tym polu (#2846); nie zasłania błędów
innych formularzy Planera zapisanych w sesji. Pusta i poprawna fraza nie
tworzą podsumowania. Ta poprawka nie zmienia zapytań ani schematu bazy.

**Kopia jednego dnia (#2836).** Nowa pozycja zachowuje `planned_servings`
oraz prywatny `note`, tak jak kopia poprzedniego tygodnia. NULL nadal oznacza
brak osobnego wyboru porcji; nie utrwalamy automatycznie liczby autora.
Istniejąca pozycja celu zachowuje własne porcje, dopisek i „Zrobione”, a nowa
kopia dostaje nowe UUID bez `done_at`. Niedostępne przepisy nie są kopiowane.
Odcisk podglądu obejmuje też porcje i dopisek: zmiana albo wyczyszczenie
któregokolwiek po podglądzie odrzuca cały zapis i wymaga nowego podglądu
przez istniejący komunikat konfliktu. Pola odcisku mają jawne granice (JSON),
więc znak `|` w prywatnym tekście ich nie zaciera. To korekta zachowania
zatwierdzonych #2494/#2509, bez zmiany schematu, zakupów i receptury.
Zachowanie dopisku przy kopii dnia rozstrzygnięto według zasady D-333 dla
paczki C/D: zachować dane osoby, zgodnie z istniejącą kopią tygodnia.
Test: `PlanerKopiujDzienTest`; pomiar i wycofanie w
[`ODBIOR-2836-CODEX-20261003`](../flota/koordynacja/ODBIOR-2836-CODEX-20261003.md).

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

- `meal_plan_entries_planned_servings_check` — `planned_servings IS NULL OR
  (planned_servings >= 1 AND planned_servings <= 100)` (zakres `WyborPorcji`);
- `meal_plan_entries_planned_servings_nie_przy_wlasnym_check` —
  `planned_servings IS NULL OR label IS NULL` (nie przy własnym wpisie; celowo
  bez wymogu `recipe_id`, bo `ON DELETE SET NULL` zostawia pozycję bez przepisu).

**Rollback `planned_servings` (#2509, D-088).** Migracja
`2026_10_06_200100_add_planned_servings_to_meal_plan_entries` ODMAWIA cofnięcia,
gdy choć jedna pozycja ma zapisaną liczbę (komunikat podaje, jak zapisać mapę
`SELECT id, planned_servings …` i wyzerować kolumnę); bez zapisanych liczb
przechodzi. Kod sprzed migracji kolumny nie zna, więc awaryjny rollback
wdrożenia nie wymaga cofania schematu. Test: `tests/Feature/PlanerPorcjeTest.php`.

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

**Dopisek (#2549, V2).** `note` jest poza `$fillable`; ustawia go tylko akcja
`ZapiszDopisekPlanu` (żądana wartość, pusta = wyczyść), pod blokadą wiersza `users`
i pozycji, po świeżej kontroli konta i własności (`MealPlanEntryPolicy::editNote`).
Więzy: `meal_plan_entries_note_check` (po `btrim` 1–80 znaków; pusty dopisek to NULL)
i `meal_plan_entries_note_nie_przy_wlasnym_check` (`note IS NULL OR label IS NULL` —
dopisek nie stoi przy własnym wpisie; NIE wymaga `recipe_id`, bo `ON DELETE SET NULL`
zostawia po usuniętym przepisie wiersz bez obu wartości, a kasowanie przepisu nie może
się na tym wywrócić). Przy przepisie niedostępnym albo usuniętym dopisek zostaje i jest
widoczny jako „Dopisek:” (własny tekst osoby, nigdy tytuł przepisu). Kopia tygodnia
przenosi `note`, a pozycji już obecnej w celu nie rusza (`insertOrIgnore`). Paczka RODO:
pole `dopisek` w sekcji `planer`; wymazanie konta kasuje wiersz razem z dopiskiem.
**Rollback (D-088):** `down()` ODMAWIA, gdy choć jedna pozycja ma dopisek (komunikat
podaje kopię `pg_dump -t meal_plan_entries` i `UPDATE meal_plan_entries SET note = NULL`);
bez dopisków zdejmuje więzy i kolumnę. Test: `tests/Feature/PlanerDopisekTest.php`.

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
| `scaled_servings` | `numeric(5,2)` NULL | #2489, migracja `2026_10_06_200200`: NULL = dosłowna linia autora albo ręczny wpis; liczba = ilość w `text` policzył Kuking z linii autora na tyle porcji (`WyborPorcji`/`PrzeliczSkladnik`). Trwałe rozróżnienie przeliczonej kopii od linii autora — także po edycji albo utracie dostępu do przepisu |
| `edited_at` | `timestamptz(6)` NULL | chwila ostatniej RĘCZNEJ korekty tekstu przez właściciela listy (#2443, migracja `2026_10_05_143127`); `NULL` = tekst taki, jak dopisano albo skopiowano. Trwały znacznik „skopiowane, a potem poprawione na liście użytkownika” — bez porównywania z aktualnym przepisem |
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

**Poprawianie tekstu (#2443, V2).** „Popraw” przy pozycji zmienia WYŁĄCZNIE
`text` i `edited_at` (plus `updated_at`) tej samej pozycji: akcja
`ListaZakupow::popraw()` pod blokadą wiersza `users`, z pozycją czytaną po
blokadzie i zapisem zapytaniem o te kolumny (równoległe odhaczenie nie ginie,
usunięta pozycja nie powstaje ponownie). Formularz niesie skrót widzianego
tekstu; zmiana w innym oknie daje konflikt bez zapisu. `edited_at` jest poza
`$fillable`, wchodzi do migawki „Cofnij usunięcie” i do paczki RODO
(`tekst_poprawiony_przez_wlasciciela`, `poprawiono`). Ekran mówi o pozycji
skopiowanej z przepisu i poprawionej, że nie jest już dosłowną linią z przepisu.
„Anuluj, zostaw jak jest” jest zwykłym odnośnikiem GET: wraca do listy tej
pozycji (domyślnej lub nazwanej) i do jej kotwicy, również po błędzie formularza.
Nie zapisuje pozycji. Wybrana nazwana lista jest ponownie sprawdzana przy
otwieraniu strony, więc odnośnik nie daje dostępu do cudzej listy.
**Rollback (D-088):** `down()` ODMAWIA, gdy choć jedna pozycja ma `edited_at`
(komunikat podaje kopię `pg_dump -t shopping_list_items` i
`UPDATE shopping_list_items SET edited_at = NULL`); bez korekt przechodzi.
Dodanie kolumny NULL bez wartości domyślnej nie przepisuje tabeli i nie
potrzebuje CHECK-a ani indeksu. Test: `tests/Feature/ListaZakupowPoprawkaTest.php`.

Ograniczenia (nowa tabela, więc razem z `CREATE TABLE` — AGENTS.md §6):

- `shopping_list_items_source_check` — `source IN ('manual', 'recipe')`;
- `shopping_list_items_recipe_source_check` — `recipe_id IS NULL OR source =
  'recipe'`: ręczna pozycja nie ma przepisu (odwrotnie nie wymuszamy, bo
  `ON DELETE SET NULL` zostawia po twardo usuniętym przepisie `recipe` bez
  `recipe_id`);
- `shopping_list_items_scaled_servings_check` — `scaled_servings IS NULL OR
  (scaled_servings >= 1 AND scaled_servings <= 100)` (zakres `WyborPorcji`) oraz
  `shopping_list_items_scaled_servings_tylko_z_przepisu_check` —
  `scaled_servings IS NULL OR source = 'recipe'` (#2489; oba `NOT VALID` +
  `VALIDATE`). Migawka cofnięcia (`shopping_list_undos.items`) niesie
  `scaled_servings` i przywraca je z pozycją;
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

**Rollback `scaled_servings` (#2489, D-088).** Migracja
`2026_10_06_200200_add_scaled_servings_to_shopping_list_items` ODMAWIA cofnięcia,
gdy choć jedna pozycja ma zapisane przeliczenie (bez kolumny przeliczone ilości
wyglądałyby jak dosłowne linie autora); komunikat podaje, jak zapisać mapę
`SELECT id, scaled_servings …`. Bez zapisanych przeliczeń przechodzi. Kod sprzed
migracji kolumny nie zna. Test: `tests/Feature/ZakupyPrzeliczonePorcjeTest.php`.

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

### shopping_lists — nazwane listy zakupów (#2528, V2, paczka E)

Migracje `2026_10_07_110000_create_shopping_lists_table` (tabela) i
`2026_10_07_110100_add_list_id_to_shopping_list_items` (kolumna
`shopping_list_items.list_id`). Decyzja właściciela z 2.10.2026 (D-333, wiersz
„Paczka E V2”). Jeden wiersz to **dodatkowa, nazwana** prywatna lista jednej
osoby („Święta”). **Lista domyślna („Na co dzień”) nie ma wiersza**: to pozycje
z `list_id IS NULL`, czyli wszystko, co ludzie mieli przed tą funkcją — bez
backfillu, bez przepisywania `shopping_list_items`, z zachowanym tekstem,
kolejnością, odhaczeniem i pochodzeniem.

| kolumna | typ | uwagi |
|---|---|---|
| `id` | `uuid` | `DEFAULT gen_random_uuid()` |
| `user_id` | `uuid` | → `users(id)` `ON DELETE CASCADE` (pas bezpieczeństwa; konta się anonimizuje, listy kasuje `EraseAccountData`) |
| `name` | `varchar(60)` | nazwa — wolny tekst osoby (**dana osobowa**: paczka RODO, polityka, wymazanie) |
| `created_at` / `updated_at` | `timestamptz` | |

Ograniczenia (nowa tabela — razem z `CREATE TABLE`): `shopping_lists_name_check`
(nazwa po obcięciu białych znaków ma 1–60 znaków) oraz indeks unikalny
`shopping_lists_user_name_lower_unique (user_id, lower(name))` — dwie listy tej
samej osoby nie mogą nazywać się tak samo (domena dodatkowo porównuje bez
względu na wielkość polskich liter i odrzuca „Na co dzień”).

`shopping_list_items.list_id uuid NULL` → `shopping_lists(id)` **`ON DELETE
CASCADE`** (klucz `NOT VALID` + `VALIDATE`, indeks częściowy
`shopping_list_items_list_idx WHERE list_id IS NOT NULL` przez `CREATE INDEX
CONCURRENTLY`, AGENTS.md §6). Usunięcie listy kasuje jej pozycje — wyłącznie po
potwierdzeniu, które niesie liczbę pozycji widzianą na ekranie (gdy w międzyczasie
się zmieniła, nic nie jest kasowane).

**Reguły (`ListaZakupow`, `ShoppingListPolicy`).** Najwyżej `kuking.zakupy.list_max`
(5) list razem z domyślną; limit pozycji `pozycji_max` (300) liczy się dla
**całego konta**, pod blokadą wiersza użytkownika, jak dotąd. Lista docelowa jest
zawsze jawna (ukryte pole `lista` / `<select>` „Na którą listę zakupów?”; pusta
wartość = domyślna). Odhaczanie, usuwanie, „Wyczyść odhaczone” i ostrzeżenie o
ponownym dodaniu przepisu dotyczą jednej listy. Identyfikator cudzej listy daje
odmowę, listy usuniętej w innej karcie — komunikat po polsku. „Cofnij usunięcie”
(`shopping_list_undos.items[].list_id`) przywraca pozycję na jej listę, a gdy tej
listy już nie ma — na listę domyślną.

Jeśli druga karta zajmie ostatnie miejsce na nową listę, odmowa założenia
listy zachowuje wpisaną nazwę, widoczne pole i błąd przy nim. Link w
podsumowaniu prowadzi do tego pola; przy pełnym limicie przycisk założenia
jest nieaktywny, a tekst mówi, że trzeba usunąć niepotrzebną listę.
Normalny ekran pełnego konta nadal pokazuje sam komunikat o limicie.

Po odmowie dodania składników przepisu przez globalny limit 300 pozycji ekran
wraca na nadal istniejącą i własną listę, którą osoba wybrała. Widać tam
odhaczone pozycje i „Wyczyść odhaczone”. Gdy lista zniknęła w innej karcie,
powrót prowadzi bezpiecznie na listę domyślną z komunikatem. Żadna pozycja
nie jest usuwana automatycznie; limit i wybór listy nie zmieniają się (#2818).

**Paczka RODO:** sekcja `listy_zakupow` (nazwa, data założenia — także puste listy)
oraz pole `lista` przy każdej pozycji w `lista_zakupow`. **Wymazanie konta**
kasuje listy bezwarunkowo (`EraseAccountData`). Polityka prywatności: wiersz „Lista
zakupów” (drobna poprawka wersji z 30.09.2026, bez nowej daty).

**Spójność paczki (#2847).** Eksporter odczytuje pozycje i wszystkie nazwane
listy, także puste, w krótkiej transakcji pod tą samą blokadą konta, której
używa przemianowanie i zapis zakupów. Po zwolnieniu blokady buduje dalsze
dane oraz ZIP z zapamiętanej migawki. Zmiana nazwy w trakcie tworzenia ZIP-a
nie przypisuje pozycji innej liście, nawet gdy ta zajmie dawną nazwę. Nie
zmienia to formatu paczki ani reguły widoczności tytułów cudzych przepisów.
Test na dwóch połączeniach zatrzymuje prawdziwy eksport między odczytami,
wykonuje przemianowanie i sprawdza `dane.json` w gotowym archiwum; kontrola
ujemna przywraca dwa niezależne odczyty i musi oblać znacznik spójności.
Rollback kodu przywróciłby ryzyko mieszania nazw w nowych paczkach; nie
przepisuje już pobranych archiwów i nie wymaga cofania schematu bazy.

**Rollback (D-088).** `down()` drugiej migracji ODMAWIA, gdy choć jedna pozycja ma
`list_id` (wpadłaby po cichu na listę domyślną; wymuszenie po kopii:
`KUKING_ROLLBACK_SCALA_LISTY_ZAKUPOW=1`); `down()` pierwszej ODMAWIA, gdy istnieje
choć jedna lista (`KUKING_ROLLBACK_KASUJE_LISTY_ZAKUPOW=1`). Bez nazwanych list —
świeża baza, CI, `migrate:refresh` — przechodzą bez pytania. Test:
`tests/Feature/NazwaneListyZakupowTest.php`.

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
| `items` | `jsonb` | tablica usuniętych pozycji: `id`, `text`, `source`, `recipe_id`, `list_id` (od #2528; brak = lista domyślna), `position`, `checked_at`, `created_at`. **Bez kluczy obcych** i bez tytułu, linku czy zdjęcia przepisu — przy cofnięciu `recipe_id` zostaje tylko dla przepisu, który wciąż istnieje (inaczej sam tekst, jak przy `ON DELETE SET NULL`) |
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

Lista „Gotowanie zapamiętane na koncie” (#2439) czyta tylko niewygasłe wiersze
własnej osoby, w kolejności `updated_at DESC, id DESC`. Odczyt odbywa się
porcjami po 100 wierszy; po każdej porcji obowiązuje aktualna widoczność
przepisu i `RecipePolicy::view`. Dopiero dostępne wiersze liczą się do offsetu
i przycisku „Pokaż więcej”. Nie ma limitu pierwszych 500 surowych wierszy,
który mógłby ukryć dostępny przepis za usuniętymi lub prywatnymi.

Konflikt dwóch urządzeń: zapis to idempotentne USTAWIENIE jednego kroku pod
blokadą wiersza (`SELECT … FOR UPDATE`), więc różne kroki nie gubią się
nawzajem, a na ten sam wygrywa ostatni zapis. Formularz niesie rewizję i UUID
wiersza, które widziała karta. Rozbieżna rewizja tego samego wiersza daje
komunikat, ale zapis kroku może się odbyć. Po wyłączeniu i ponownym włączeniu
powstaje nowy UUID z rewizją 1: żądanie starej karty albo bez UUID odmawia pod
blokadą, nie przenosi dawnego kroku ani porcji na nowy postęp (#2860).
Prywatny odczyt dla pasa zmiany zwraca oba znaczniki bez listy kroków;
przeglądarka nie przeładowuje strony samoczynnie. Bez zapamiętywania formularz
kroku dalej zapisuje odhaczenie tylko w sesji. Schemat i rollback bez zmian.

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

## recent_recipe_views — opcjonalna, prywatna lista ostatnio oglądanych przepisów (V2, #2553)

Lista tylko do powrotu do przepisu, który osoba obejrzała, ale nie zapisała.
**Domyślnie wyłączona**; włącza ją wyłącznie jawny przycisk w ustawieniach
(„Ustawienia → Ostatnio oglądane”, zwykły POST). Migracja
`2026_10_03_190000_create_recent_recipe_views` robi dwie rzeczy: zakłada tabelę
i dodaje `users.ostatnio_ogladane_wlaczone_at` (zgoda — patrz
[`konta-ustawienia-zgody`](konta-ustawienia-zgody.md)). Wiersz zapamiętuje
wyłącznie przepis i czas ostatniej wizyty: bez kopii tytułu, zdjęcia, adresu
z parametrami, wyszukiwanej frazy i wyboru alergenów.

| Kolumna | Typ | Znaczenie |
|---|---|---|
| `id` | `uuid` PK, `DEFAULT gen_random_uuid()` | |
| `user_id` | `uuid NOT NULL` → `users` (`ON DELETE CASCADE`) | właściciel; model ma pusty `$fillable`, wiersz zmienia tylko `OstatnioOgladane` |
| `recipe_id` | `uuid NOT NULL` → `recipes` (`ON DELETE CASCADE`) | przepis |
| `viewed_at` | `timestamptz NOT NULL` | chwila OSTATNIEJ wizyty; powrót przesuwa tę wartość |

Ograniczenia i indeksy: `UNIQUE (user_id, recipe_id)` (powrót do przepisu
przesuwa jedną pozycję, nie dokłada drugiej); indeksy `(user_id, viewed_at)`
(odczyt listy i przycinanie), `recipe_id` (kaskada przy twardym usunięciu
przepisu) i `viewed_at` (nocne sprzątanie).

Limit i czas życia (`kuking.ostatnio_ogladane.limit` = 10 różnych przepisów
i `.dni` = 7; wartości z propozycji w issue, **do potwierdzenia przez
właściciela** — wiersz w D-333) działają trzy razy: przy zapisie (przycięcie),
przy ODCZYCIE (starsze i nadliczbowe pozycje są niewidoczne, zanim posprząta
je zadanie) i w nocnym `kuking:sprzataj-ostatnio-ogladane` (02:15; kasuje też
wizyty osób z wyłączoną funkcją). Zmiana wartości nie wymaga migracji.

Zapis. `RecipeController::show()` po autoryzacji woła
`OstatnioOgladane::zaplanujZapis()`: gość, autor własnego przepisu i osoba z
wyłączoną funkcją nie robią nic, a pozostali odkładają zapis przez `defer()` na
czas PO odpowiedzi, bez żadnego zapytania w ścieżce żądania (stan zgody jest w
zalogowanym modelu). Odroczony zapis ponownie pyta Policy `view` jak o konto
BEZ roli obsługi — wgląd moderacyjny nie jest wizytą — i robi `INSERT … ON
CONFLICT DO UPDATE` z warunkiem zgody oraz `FOR SHARE` na wierszu konta. Żądanie
zapamiętuje chwilę aktualnego włączenia z już wczytanego konta; pod blokadą
zapis przechodzi tylko wtedy, gdy w bazie nadal jest **ten sam okres zgody**
(pełna precyzja `timestamptz`, także mikrosekundy). Wyłączenie i ponowne
włączenie nie wpuszcza więc starego odroczonego zapisu do nowej historii.
Strona przepisu
gościa nie zmienia się ani o bajt (cache publiczny bez zmian); zalogowany ma
`private, no-store`, a lista nie trafia do localStorage ani do cache
service workera (`public/sw.js` nie trzyma stron).

Odczyt. `OstatnioOgladane::lista()` zwraca tylko niewygasłe pozycje w limicie,
ponownie filtruje je zakresem `Recipe::widoczneDla()` i Policy `view` (jak
zwykły widz): przepis prywatny, usunięty, ukryty, zdjęty, od zablokowanej osoby
albo z konta zbanowanego znika z listy, a jego tytuł, autor i zdjęcie nie
opuszczają bazy. Ekran: `/ustawienia/ostatnio-ogladane`, odnośnik także w
„Moje”. Wyłączenie („Wyłącz i usuń zapamiętane”) kasuje wiersze i zgodę w jednej
transakcji; „Wyczyść listę” kasuje wiersze jednym kliknięciem.

Czego lista NIE robi: nie zasila feedu, rekomendacji, statystyk ani
powiadomień, nie zapisuje nic do zeszytu, planera, postępu gotowania ani
`cooked_events` i nie sugeruje „Ugotowałem”. Pilnuje tego
`OstatnioOgladaneNieWyciekajaPozaListeTest` (zamknięta lista plików, które mogą
jej dotykać).

Konto: paczka danych ma sekcję `ostatnio_ogladane` (tylko przepis i czas;
tytuł tylko przy przepisie widocznym dla osoby) oraz `konto.ostatnio_ogladane_wlaczone_od`;
`EraseAccountData` kasuje wiersze jawnie i zeruje zgodę (konta się anonimizuje).
Wpis w rejestrze czynności: `docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md`
§3.31, w polityce prywatności wiersz „Lista ostatnio oglądanych przepisów”.

Rollback (D-088): `down()` usuwa tabelę i kolumnę zgody, ale ODMAWIA, gdy w
tabeli jest choć jedna wizyta (to dane o zachowaniu, których `up()` nie
odtworzy). Na pustej tabeli i w CI przechodzi bez pytania; zdjęcie samej
kolumny zgody jest bezpieczne w stronę prywatności (po cofnięciu nic się nie
zapisuje). Wymuszenie po kopii tabeli:
`KUKING_ROLLBACK_KASUJE_OSTATNIO_OGLADANE=1` (albo wcześniej
`php artisan kuking:sprzataj-ostatnio-ogladane --wszystkie`). Test:
`CofniecieMigracjiNieKasujeOstatnioOgladanychTest`.

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
