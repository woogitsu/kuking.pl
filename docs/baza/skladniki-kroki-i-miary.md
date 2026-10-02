# Składniki, kroki, miary

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

### ingredients + units
Podstawa search i późniejszego planera.

`ingredients` — słownik składników **wspólny dla serwisu**, budowany
z tego, co ludzie wpisują:

- `canonical_name varchar(240)` — nazwa w pisowni, którą pokazujemy
  („cebula czerwona"). To jest tekst pochodzący od człowieka, nie z żadnej
  zewnętrznej bazy;
- `normalized_name text UNIQUE` — klucz dopasowania obliczany w PHP przez
  `Ingredient::normalize()` (`Str::ascii`, małe litery i redukcja białych
  znaków). Transliteracja może wydłużyć nazwę: 240 znaków `Æ` daje 480
  znaków `ae`, więc limit kolumny nie może wynosić 240. Indeks wyszukiwarki
  GIN nadal używa `kuking_normalize(normalized_name) gin_trgm_ops`.

Migracja `2026_09_14_100000_dopasuj_slownik_skladnikow_do_formularza`
(#526) rozszerza poprzednie `varchar(160)`. Nie obcina danych, nie zmienia
normalizacji ani indeksów. `down()` w transakcji blokuje tabelę i odmawia,
jeżeli którakolwiek z obu nazw ma ponad 160 znaków; przy krótszych danych
cofnięcie przechodzi. Powód: formularz i `recipe_ingredients` już wcześniej
akceptowały 240 znaków, lecz zapis słownika kończył wtedy publikację błędem500.

`units` — jednostki miary. Tabela słownikowa, którą wypełnia seeder, a nie
człowiek przy przepisie:

- `code varchar(30) UNIQUE` — identyfikator maszynowy (`g`, `ml`, `lyzka`);
- `name varchar(80)` i `name_plural varchar(80) NULL` — forma pojedyncza
  i mnoga do pokazania („łyżka" / „łyżki"). Kolumna dopuszcza `NULL`, ale
  `UnitSeeder` wypełnia ją przy każdej z 15 jednostek;
- `unit_type varchar(30) NULL` — rodzaj jednostki. Seeder wpisuje `waga`,
  `objetosc` albo `ilosc`, ale **w bazie nie ma CHECK-a** i nie ma zamkniętej
  listy w kodzie. Dopóki przeliczania jednostek nie ma (V2), ta kolumna
  niczego nie rozstrzyga — i dlatego nie zamykamy jej przedwcześnie.

### recipe_ingredients
Musi mieć `ingredient_text`, nawet jeśli normalizacja nie rozpozna składnika.

**Wiersz niesie DWIE postacie tego samego składnika i to jest celowe:**

- `ingredient_text varchar(240) NOT NULL` — to, co naprawdę napisał autor
  („mąka pszenna typ 500"). Zapisywane dosłownie i tylko to jest pokazywane
  człowiekowi. Kolumna generowana `ingredient_text_search` trzyma obok wersję
  znormalizowaną dla wyszukiwarki (patrz „Kolumny `*_search`" niżej);
- `ingredient_id uuid NULL` → `ingredients` (`ON DELETE SET NULL`) — ten sam
  składnik jako hasło słownikowe. Wpisuje je `PublishRecipe::syncIngredients()`
  przez `Ingredient::findOrCreateByName()`, czyli **wiersz publikowany dziś
  ma to pole zawsze wypełnione** — hasła, którego nie ma, słownik się
  dorabia. `NULL` zostaje w schemacie dla wierszy z fabryk i seederów oraz
  jako skutek `ON DELETE SET NULL`. `SET NULL`, a nie `CASCADE`, bo usunięcie
  hasła ze słownika nie ma prawa zabrać komuś linijki z przepisu — zabiera
  wyłącznie dopasowanie. Normalizacja jest DODATKIEM do tekstu autora i nigdy
  go nie nadpisuje (`App\Models\RecipeIngredient`);

**Ilość jest rozbita na liczbę i jednostkę, obie opcjonalne:**
`quantity numeric(12,4) NULL` (CHECK `quantity IS NULL OR quantity >= 0`)
i `unit_id uuid NULL` → `units` (`ON DELETE SET NULL`). `numeric`, a nie
`float`, bo „1/3 szklanki" ma się zapisać i odczytać tak samo po skalowaniu
porcji (V2); cztery miejsca po przecinku wystarczają na ułamki z kuchni.
`NULL` w obu znaczy „ilości nie podano" i jest czymś innym niż `no_amount`
niżej, które znaczy „ilości NIE MA".

**`recipe_ingredients.rdzenie text[] NOT NULL`** (migracja
`2026_10_01_140300_add_rdzenie_to_recipe_ingredients`, „Co ugotuję z tego, co
mam”) — zapisany wynik `public.kuking_rdzenie_skladnika(ingredient_text)`:
rdzenie linijki, posortowane i bez powtórzeń. Wypełnia je wyzwalacz
`recipe_ingredients_rdzenie_trg` (BEFORE INSERT OR UPDATE OF `ingredient_text`,
funkcja `public.kuking_recipe_ingredients_rdzenie()`), więc kod aplikacji niczego
nie liczy ani nie pamięta — także `INSERT … SELECT` i seedery. Zapytanie
`App\Domain\Pantry\CoUgotuje` czyta tę kolumnę (`p.rdzenie <@ ri.rdzenie`,
wstępny filtr `ri.rdzenie && {…}`) zamiast liczyć funkcję na każdą linijkę
każdego kandydata przy każdym żądaniu (pomiar: 500 przepisów × 7 składników,
20 produktów — ok. 985 ms i ok. 109 tys. wywołań funkcji → ok. 25 ms
i 0 wywołań). Indeks `recipe_ingredients_rdzenie_gin_idx` (GIN, `array_ops`)
obsługuje `&&`, `@>`, `<@`. CHECK `recipe_ingredients_rdzenie_not_null_check`
(`rdzenie IS NOT NULL`).

Dlaczego zwykła kolumna z wyzwalaczem, a nie `GENERATED … STORED`:
`ADD COLUMN … STORED` przepisuje całą tabelę pod `ACCESS EXCLUSIVE`. Tu:
`ADD COLUMN` bez DEFAULT (zmiana katalogu, milisekundy) → wyzwalacz →
backfill partiami po 2000 wierszy, każda partia w osobnej transakcji →
`CHECK … NOT VALID` + `VALIDATE` (`SHARE UPDATE EXCLUSIVE`, zapisy idą dalej)
→ `CREATE INDEX CONCURRENTLY`. Koszt na produkcji: backfill przepisuje każdy
wiersz raz (tymczasowo ok. 2× rozmiar tabeli do VACUUM, WAL ok. 1–2×
rozmiaru tabeli); migracja wznawialna. Zapis przepisu kasuje i zakłada
wiersze składników, więc wyzwalacz liczy funkcję raz na linijkę przy zapisie
(zamiast wielokrotnie przy każdym odczycie).

⚠️ Kolumna jest ZWYKŁA: zmiana ciała `kuking_rdzenie_skladnika()` jej nie
przelicza. Migracja zmieniająca funkcję musi przeliczyć też tę tabelę
(partiami, `UPDATE recipe_ingredients SET ingredient_text = ingredient_text
WHERE …`) — jak `UPDATE pantry_items SET name = name` w #2315. Rozjazd wyłapuje
`CoUgotujeKosztTest::test_zapisane_rdzenie_rowna_sie_funkcji_na_zywo`.

**Rollback:** `down()` zdejmuje indeks, CHECK, wyzwalacz, jego funkcję i kolumnę.
Bezstratny i **nie odmawia** (D-088 chroni wartości semantyczne, a to dane
pochodne w całości wyliczalne z `ingredient_text`, który zostaje). Po nim kod
z tej wersji (`CoUgotuje`) nie zadziała — wycofanie wdrożenia cofa kod razem
z migracją.

**`recipe_ingredients.note varchar(300) NULL`** — dopisek przy JEDNYM
składniku („najlepiej wiejskie", „albo margaryna"), **wolny tekst od
człowieka**. Coś innego niż `ingredient_text`, który jest samym składnikiem
w postaci wpisanej przez autora: dopisek da się pominąć przy liście zakupów,
składnika nie. `NULL` jest stanem normalnym.

**`recipe_ingredients.substitutes varchar(300) NULL`** (migracja
`2026_09_26_100000_add_substitutes_to_recipe_ingredients`, D-284) — czym autor
radzi zastąpić TEN składnik („margaryna albo olej kokosowy”). Wolny tekst od
człowieka, pokazywany pod składnikiem jako „Zamiast tego: …” na stronie
przepisu i w trybie gotowania, w eksporcie danych jako
`przepisy[].skladniki[].zamienniki` i w `recipe_versions.snapshot`
(`ingredients[].substitutes`). Osobno od `note`, bo to informacja o INNYM
produkcie, potrzebna wtedy, gdy czegoś nie ma w domu. `NULL` jest stanem
normalnym. CHECK `recipe_ingredients_substitutes_check`:
`substitutes IS NULL OR btrim(substitutes) <> ''` — pusty zamiennik to `NULL`,
inaczej widok pisałby „Zamiast tego:” i nic; `PublishRecipe` zamienia puste na
`NULL` przed zapisem. Kolumna `NULL` bez wartości domyślnej nie przepisuje
tabeli; CHECK wszedł jako `NOT VALID` + osobne `VALIDATE` (AGENTS.md §6).
Kolumna nie wskazuje na `users`, więc `InwentarzDanychKonta` jej nie wylicza —
wchodzi do paczki razem z resztą wiersza składnika.

**Rollback:** `down()` **odmawia**, gdy choć jeden składnik ma zamiennik
(D-088 — po `down()` idzie kolejny `migrate`, kolumna wróciłaby pusta bez
błędu). Na pustej kolumnie i na świeżej bazie przechodzi. Sprawdzenie i DDL są
pod `LOCK TABLE … ACCESS EXCLUSIVE`, żeby zapis nie wszedł pomiędzy. Wtedy
wycofujemy sam kod (stary kod kolumny nie czyta) albo zapisujemy dane
(`\copy` w komunikacie odmowy) i ustawiamy `KUKING_ROLLBACK_KASUJ_ZAMIENNIKI=true`.
Pilnuje `tests/Feature/CofniecieMigracjiNieKasujeZamiennikowTest.php`.

**Skalowanie porcji (D-284) NIE czyta `quantity` ani `unit_id`.** Formularze
ich nie wypełniają, więc przelicznik (`App\Domain\Recipes\Porcje\PrzeliczSkladnik`)
czyta ilość z `ingredient_text` w chwili pokazania i niczego nie zapisuje.
Wybór widza żyje w adresie (`?porcje=N`), nie w bazie.

**`no_amount boolean NOT NULL DEFAULT false`** (migracja
`2026_09_06_130000_add_no_amount_to_recipe_ingredients`, issue #44) —
„ten składnik nie ma wymiernej ilości": sól do smaku, pieprz, mleko — ile
weźmie. Przy skalowaniu porcji (V2, wdrożone w D-284) takiego składnika **się nie mnoży**:
przepis razy trzy poprosiłby inaczej o trzy szczypty soli i o trzy razy
„ile weźmie".

**Prezentacja (#878, decyzja właściciela z 20 września 2026):** flaga nie
określa sposobu dozowania. Widok pokazuje wyłącznie tekst składnika i uwagę
autora, bez automatycznego „do smaku” ani „bez podanej ilości”. Autor wpisuje
„do smaku”, „ile weźmie” lub inne określenie w nazwie składnika. To zmienia
dawne kryterium prezentacji z #44, nie CHECK ani znaczenie zapisanej flagi.

Kolumna weszła **przed** funkcją, która jej używa, i to jest jedyny powód,
dla którego istnieje już teraz: dopisanie jej dziś kosztuje jedną linijkę,
a po tym, jak w tabeli znajdą się przepisy prawdziwych ludzi, kosztowałoby
migrację danych i **zgadywanie**, które składniki są „do smaku".

CHECK `recipe_ingredients_no_amount_check`: `no_amount = false OR (quantity
IS NULL AND unit_id IS NULL)`. Bez niego dałoby się zapisać wiersz mówiący
naraz „nie mam ilości" i „mam 200 ml" — wtedy pytanie „czy to skalować"
nie ma poprawnej odpowiedzi. `PublishRecipe` rozstrzyga konflikt **przed**
zapisem, kasując ilość, żeby CHECK nie zamienił się w błąd 500 na publikacji.

**Rollback (D-088):** `down()` sprawdza pod blokadą tabeli
(`LOCK TABLE ... IN ACCESS EXCLUSIVE MODE`) istnienie `no_amount = true`
i **odmawia**, gdy takie wiersze istnieją — dopiero wtedy liczy je do
komunikatu. `SET LOCAL statement_timeout = '2s'` ogranicza czas trzymania
blokady, która wstrzymuje także odczyty. Komunikat po polsku mówi ile ich
jest i co zrobić (kopia tabeli, potem ponowne uruchomienie ze zmienną
`KUKING_ROLLBACK_KASUJE_SKLADNIKI_BEZ_ILOSCI=1`). Na świeżej bazie, bez
żadnego takiego składnika, `down()` przechodzi bez pytania. Powód odmowy:
po wdrożeniu skalowania porcji (V2, D-284) ta flaga rozstrzyga, których
składników NIE mnożyć, i nie da się jej odtworzyć z samego tekstu składnika —
cichy `dropColumn` byłby utratą informacji bez śladu błędu.

#### `group_name` — „Ciasto", „Farsz", „Do podania" (D-033)

**`group_name varchar(120) NULL`** (kolumna z pierwszej migracji przepisów
`2026_09_05_000400_create_recipes_tables`; CHECK dołożony migracją
`2026_09_08_100000_add_group_name_check_to_recipe_ingredients`) — śródtytuł
części przepisu. `NULL` znaczy „ten składnik nie należy do żadnej części"
i jest **stanem normalnym**: większość przepisów nie ma grup i nic w bazie
ani w interfejsie nie traktuje pustej wartości jako braku do uzupełnienia.

**Kolejność grup nie ma własnej kolumny.** Bierze się z `position`
składników: grupa pojawia się tam, gdzie stoi jej pierwszy składnik. Autor
pisze listę od góry do dołu i to jest cała informacja o kolejności, jaką ma;
druga liczba obok byłaby drugim miejscem, w którym kolejność może się
rozjechać z pierwszym.

**Odrzucona osobna tabela grup** (`recipe_ingredient_groups` z `position`
plus `group_id` przy składniku). Grupa nie ma własnego życia — nikt nie
zakłada „Farszu", żeby potem wkładać do niego składniki — więc skasowanie
ostatniego składnika zostawiałoby pusty nagłówek. Do tego obie drogi zapisu
kasują składniki i piszą je od nowa (`PublishRecipe::syncIngredients`), więc
każdy zapis przepisu stawałby się synchronizacją dwóch list zamiast jednej,
a UNIQUE na nazwie działa i tak wyłącznie w obrębie jednego przepisu — czyli
daje tyle, co ujednolicenie nazw przy zapisie, za cenę klucza obcego i JOIN-a
na najczęściej czytanej stronie serwisu. Pełna lista odrzuconych wariantów
(słownik nazw wspólny dla serwisu, `group_position`, nagłówek jako wiersz
składnika z flagą `is_header`) stoi w komentarzu migracji.

CHECK `recipe_ingredients_group_name_check`: `group_name IS NULL OR
btrim(group_name) <> ''`. Pusty ciąg znaków to nagłówek bez treści — pusta
linia na ekranie, a w czytniku ekranu „nagłówek poziomu trzeciego" i cisza.
`PublishRecipe` zamienia puste i same spacje na `NULL` **przed** zapisem
i przycina nazwę do 120 znaków, żeby CHECK i długość kolumny nie zamieniły
się w błąd 500 na publikacji.

**Czego baza NIE pilnuje: ciągłości grup.** Da się zapisać „poz. 0 Ciasto,
poz. 1 Farsz, poz. 2 Ciasto" — CHECK nie widzi sąsiednich wierszy
(`docs/research/repos/TandoorRecipes-recipes.md` §2.2, rekomendacja R8).
Pilnują tego dwie warstwy nad bazą: `PublishRecipe` ujednolica pisownię nazw
w obrębie przepisu (wygrywa pierwsza pisownia autora, więc „Farsz" i „farsz"
to jedna grupa), a `App\Domain\Recipes\GrupySkladnikow` układa listę do
wyświetlenia — składniki bez grupy na górze i bez nagłówka, grupy w kolejności
autora, wiersze jednej grupy pod jednym nagłówkiem. Ten sam kod czyta strona
przepisu, podgląd w kreatorze i przepis w eksporcie danych.

**Rollback:** `down()` zdejmuje sam CHECK i nie rusza danych ani kolumny —
nazwy grup zostają. Nieodwracalna jest jedna rzecz z `up()`: nazwy będące
pustym ciągiem znaków stają się `NULL`. To nie jest utrata informacji, bo
pusty ciąg nigdy nie był nazwą grupy.

### „Mój stół” — `users.moj_stol_enabled` (issue #1749, D-304)

Migracja `2026_09_26_190000_add_moj_stol_enabled_to_users`.

- **`users.moj_stol_enabled`** (`boolean NOT NULL DEFAULT false`) — czy osoba
  włączyła sobie dobrowolną półkę propozycji „Mój stół”. Domyślnie wyłączone:
  półka jest propozycją serwisu, więc bez włączenia nie liczymy ani jednej
  pozycji. W `$fillable` (preferencja wyświetlania, nie pole sterujące —
  AGENTS.md §7), w eksporcie jako `konto.moj_stol_wlaczony`, a wymazanie konta
  ustawia `false`.

To **jedyne**, co zapisujemy o półce. Nie ma tabeli dopasowań, wag ani historii
kliknięć — dobór liczy się przy każdym wyświetleniu z obserwowanych tagów,
listy gospodarza (`tag_promotions`), wyboru gospodarza na dziś (`daily_picks`)
i ukryć (`hides`), wyłącznie regułami
z zamkniętej listy AGENTS.md §8. Dlatego nie ma też czego „resetować”.

**Rollback:** `down()` zdejmuje kolumnę **bez odmowy**. Cykl
`migrate:rollback` → `migrate` odtwarza ją z `DEFAULT false`, czyli wyłącza
półkę tym, którzy ją włączyli. To świadome odstępstwo od odmowy z D-088:
utracona wartość to preferencja wyświetlania (jak `theme`), a kierunek utraty
jest bezpieczny — po cyklu nikt nie widzi propozycji, których nie chciał,
najwyżej włączy półkę jeszcze raz. Cykl sprawdza
`tests/Feature/MojStolTest.php::test_rollback_migracji_zdejmuje_kolumne_i_wraca_wylaczony`.

### skladniki_odzywcze

**Wartości odżywcze (V2, D-299)** — trzy tabele: `skladniki_odzywcze`,
`miary_domowe`, `aliasy_skladnikow`.

Szacunek kcal, białka, tłuszczu i węglowodanów na porcję, liczony w PHP
(`App\Domain\Recipes\Odzywcze\KalkulatorWartosci`) z otwartych tabel CIQUAL
2025 i USDA FoodData Central — bez modelu AI. Migracja
`2026_09_26_400000_create_wartosci_odzywcze_tables`. Trzy tabele są
**słownikami**: wypełnia je wyłącznie komenda
`php artisan kuking:importuj-wartosci-odzywcze` z plików
`database/data/odzywcze/skladniki.csv` i `miary.csv` (źródła i licencje:
`database/data/odzywcze/ZRODLA.md`). Żadna trasa HTTP do nich nie pisze,
aplikacja niczego nie pobiera z sieci. Import jest idempotentny i idzie w jednej
transakcji: po nim baza zawiera dokładnie to, co pliki.

**Wdrożenie (#1961).** Komenda stoi w `preDeployCommand` w `.railway/railway.ts`,
po `migrate` i `db:seed` — leci automatycznie przy KAŻDYM wdrożeniu, nie tylko
ręcznie (wcześniej migracja tworzyła puste tabele i nikt ich nie wypełniał).
Żeby zwykły deploy bez zmiany źródeł nie przepisywał ~600 wierszy za każdym
razem, komenda przechowuje hash CSV i kodu normalizacji oraz odcisk wartości
wszystkich trzech tabel po udanym imporcie. Szybka ścieżka odczytuje tabele,
ale nie parsuje CSV i niczego nie zapisuje. Brak choćby jednego składnika,
aliasu albo miary, zmieniona wartość przy tej samej liczbie wierszy lub stary
znacznik w cache uruchamia pełną odbudowę (#2130). Nowy znacznik jest zapisywany
dopiero po zatwierdzeniu transakcji; `--wymus` pomija szybkie sprawdzenie.

`skladniki_odzywcze` — jedna pozycja tabeli źródłowej:

- `klucz varchar(80) UNIQUE` (CHECK `^[a-z0-9_]+$`), `nazwa` — polska nazwa;
- `zrodlo` (CHECK `ciqual` \| `usda`), `zrodlo_id`, `zrodlo_nazwa` — skąd
  pochodzą liczby, co do identyfikatora pozycji w źródle;
- `kcal_100g numeric(7,2)` (CHECK 0–950), `bialko_100g`, `tluszcz_100g`,
  `weglowodany_100g numeric(6,2)` (CHECK 0–100) — na 100 g części jadalnej;
- `gestosc_g_ml numeric(5,3) NULL` (CHECK `> 0 AND < 3`) — do przeliczania
  mililitrów; `NULL` = mililitrów tego składnika nie przeliczamy;
- `pomijalny boolean` — sól, przyprawy, zioła, woda: wiersz BEZ ilości nie
  blokuje wyniku (z ilością liczy się normalnie).

### miary_domowe

`miary_domowe` — `skladnik_odzywczy_id` → `skladniki_odzywcze` (`ON DELETE
CASCADE`), `jednostka varchar(30)` (CHECK `^[a-z]+$`, kody z
`JednostkiMiary::SLOWA`), `gramy numeric(8,2)` (CHECK `> 0 AND <= 10000`),
`uwagi`; `UNIQUE (skladnik_odzywczy_id, jednostka)`. Miara jest per składnik,
bo szklanka mąki (140 g) waży co innego niż szklanka cukru (220 g) —
`units.unit_type` tego nie rozstrzyga.

### aliasy_skladnikow

`aliasy_skladnikow` — `alias varchar(240) UNIQUE` (CHECK: niepusty i małymi
literami; zapisany po `ParserSkladnika::normalizuj()` i `oczyscNazwe()`, czyli
bez ogonków), `skladnik_odzywczy_id` (`ON DELETE CASCADE`, indeks). Formy
„mąka”, „mąki”, „mąki pszennej” wskazują tę samą pozycję. Ten sam alias przy
dwóch pozycjach odrzuca import.

**Dlaczego nie klucz obcy do `ingredients`** (jak w szkicu projektu):
`ingredients` dostaje z formularza CAŁY tekst wiersza („2 szklanki mąki”
i „mąka” to dwa hasła), więc przypinanie wartości do haseł wymagałoby
ręcznej pracy przy każdym nowym przepisie. Słownik aliasów robi to raz.

**Rollback (wszystkie trzy tabele):** `down()` zdejmuje trzy tabele. Bezstratnie — to kopia plików
z repozytorium, którą import odtwarza w całości.

**`recipes.pokazuj_wartosci_odzywcze boolean NOT NULL DEFAULT true`**
(migracja `2026_09_26_400100_add_pokazuj_wartosci_odzywcze_to_recipes`) —
autor może ukryć sekcję przy swoim przepisie (domyślnie widoczna, decyzja
właściciela z 26.09.2026). Zapisuje ją tylko nazwana akcja
`UstawWidocznoscWartosci` (bez `$fillable`, bez zmiany `updated_at`
i bez nowej wersji przepisu). `ADD COLUMN … DEFAULT <stała>` nie przepisuje
tabeli. **Rollback odmawia (D-088)**, gdy choć jeden przepis ma `false`:
kolejny `migrate` odtworzyłby kolumnę z `DEFAULT true` i odkrył sekcję, którą
autor schował. Komunikat mówi, co zapisać przed cofnięciem. Przy samych
wartościach domyślnych cofnięcie przechodzi
(`CofniecieMigracjiNiePokazujeUkrytychWartosciTest`).

### recipe_steps
Pozycja + instruction + opcjonalny timer/media.

**`instruction text NOT NULL`** — treść jednego kroku, **wolny tekst od
człowieka**, bez górnego limitu w bazie. Zapisywana dosłownie: nic jej nie
skraca, nie numeruje i nie przepisuje — numer kroku bierze się z `position`
(`UNIQUE(recipe_id, position)`, CHECK `>= 0`), a nie z tego, co autor napisał
na początku zdania. Pusty krok nie jest zapisywany: `PublishRecipe::cleanSteps()`
odrzuca wiersze bez treści, zanim dojdą do bazy, więc `NOT NULL` nie ma szansy
zamienić się w błąd 500 na publikacji.

`timer_seconds` i `media_id` ustawia od migracji poza schematem — czyli od
issue #21 — **formularz przepisu**, obiema drogami: `/dodaj/przepis/jedna-strona`
(zwykły POST) i kreator Livewire. Wcześniej obie kolumny czytał tryb gotowania,
a nie zapisywała ich żadna droga dostępna człowiekowi.

Człowiek wpisuje **minuty**; zamiana na sekundy należy do
`App\Domain\Recipes\StepTimer` — jedynego miejsca tego przelicznika — i tam
też stoją granice (0–10080 minut, pełne minuty, zero znaczy „bez minutnika",
nie „minutnik na zero"). CHECK `timer_seconds IS NULL OR timer_seconds >= 0`
zostaje ostatnią linią obrony dla dróg omijających aplikację.

Zdjęcie kroku idzie tym samym potokiem co każde inne (`ObslugiwaneZdjecie`
w walidacji, `StoreUploadedImage` w zapisie) i liczy się do budżetu
`App\Support\LimityZdjec::maksZdjecKrokowNaZapis()` (= `max_per_post − 2`,
dziś 4 na jeden zapis, bo dwa pola plikowe formularz ma zawsze).

Formularz identyfikuje krok **ukrytym `steps[i][id]`, nie pozycją**:
`PublishRecipe::cleanSteps()` pomija puste wiersze, więc numer wiersza w
formularzu nie równa się pozycji w bazie. `PublishRecipe` dziedziczy zdjęcie po
tożsamości kroku, dzięki czemu wyczyszczenie albo przestawienie wiersza nie
przenosi zdjęcia na sąsiedni krok. Mapa tożsamości jest budowana wyłącznie
z kroków tego przepisu i **przed** `delete()` — to jest cała autoryzacja tego
identyfikatora.

**`section_name varchar(120) NULL`** — opcjonalna nazwa etapu przygotowania
(#2652, decyzja właściciela z 2.10.2026; migracja
`2026_10_03_110000_add_section_name_to_recipe_steps`). Nagłówek etapu **nie jest
osobnym wierszem**, tylko polem kroku, od którego etap się zaczyna (jak
`recipe_ingredients.group_name`); etap trwa do następnego kroku z nazwą, a kroki
przed pierwszą nazwą zostają bez nagłówka. Dzięki temu nazwanie lub zmiana nazwy
etapu nie zmienia UUID kroków (a z nimi `timerFingerprint()` i odhaczeń w
`cooking_progress`), a numer i liczba kroków dalej liczą instrukcje. `NULL` =
brak nagłówka, nigdy pusty tekst: CHECK `recipe_steps_section_name_check`
(`btrim(section_name) <> ''`, dodany `NOT VALID` + `VALIDATE`) i
`PublishRecipe::nazwaEtapu()` (obcina spacje, zwija białe znaki, limit 120 przez
`LimityTekstuPrzepisu`). Pole to treść autora, więc jest w `$fillable`
(nie jest polem sterującym). Nazwa wpisana przy kroku bez treści przechodzi na
następny krok bez własnej nazwy — usunięcie treści nie kasuje nagłówka.
Migawka wersji (`recipe_versions.snapshot.steps[].section_name`), `TrescPrzepisu`
(odcisk treści), „Zrób swoją wersję”, eksport danych konta (`kroki[].etap`) i
wczytanie własnej paczki niosą nazwę; starsze migawki i paczki jej nie mają i
znaczą „bez etapu”.

**Rollback odmawia (D-088)**, gdy któryś krok ma zapisaną nazwę etapu: to treść
autora, której `up()` nie odtworzy. Komunikat podaje ręczne obejście
(`UPDATE recipe_steps SET section_name = NULL`, potem ponowny rollback). Bez
zapisanych nazw cofnięcie przechodzi (`SekcjePrzygotowaniaTest`).
