# Przepisy, ceny i wersje

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

### recipes
Aktualny stan przepisu; wersje historyczne leżą w `recipe_versions`.

- `id`, `author_id`, `klucz_wyslania` (patrz niżej), `title`, `slug`
  (`UNIQUE`, 220 znaków);
- `summary` — patrz niżej;
- `servings`, `prep_minutes`, `cook_minutes`, `difficulty`
  (CHECK: `easy` \| `medium` \| `hard`) — o czasach patrz niżej;
- `czas_laczny_zrodla_minut` — łączny czas podany przez źródło importu, osobno
  od `prep_minutes` i `cook_minutes` (patrz niżej);
- `estimated_cost_pln` — szacunkowy koszt wg autora, patrz niżej (D-286);
- `visibility` (`public` \| `followers` \| `private`),
  `status` (`draft` \| `published` \| `hidden` \| `removed`), `hero_media_id`;
- pochodzenie: `source_type`, `source_url`, `source_person`, `source_note`,
  `family_since_year`, `source_scan_media_id` — patrz niżej;
- `published_at`, `created_at`, `updated_at`, `deleted_at` (soft delete);
- `content_revision` (`bigint`, domyślnie `0`) — licznik zapisu treści.
  Formularz i kreator przekazują odczytaną rewizję; `PublishRecipe` porównuje
  ją pod blokadą wiersza przepisu i zwiększa przy każdym zapisie, także
  autozapisie. `updated_at` nie zastępuje licznika: może mieć ten sam czas
  dla dwóch zapisów wykonanych w jednej sekundzie (issues #2034 i #2032);
- `tresc_zmieniona_at` (`timestamptz NULL`, bez DEFAULT) — kiedy ostatnio
  zmieniła się TREŚĆ przepisu; źródło `dateModified` w JSON-LD (#2014) —
  patrz niżej.
- „Moja wersja": `forked_from_id`, `forked_at` — patrz niżej;
- `title_search`, `summary_search` — patrz „Kolumny `*_search`".
- `pokazuj_wartosci_odzywcze boolean NOT NULL DEFAULT true` — patrz
  sekcja `skladniki_odzywcze` niżej (D-299).
- `allergen_status`, `allergens`, `allergens_declared_at` — alergeny według
  autora, patrz „Alergeny przepisu" niżej (#1902, D-333).

**`prep_minutes`, `cook_minutes` — puste to „nie wiem", zero to „nie ma"**
(#1090). `NULL` oznacza, że autor nie podał czasu; `0` — że tego etapu nie ma
(np. surówka bez gotowania). Czas całkowity jest znany TYLKO przy obu
kolumnach różnych od `NULL` i sumie większej od zera. Jedna reguła w modelu:
`Recipe::totalMinutes()` (strona przepisu, `totalTime` w JSON-LD, podgląd
kreatora) i jej odpowiednik SQL `Recipe::scopeGotoweWCiagu()` (filtr
„Do 30 minut"). Przepis z samym czasem przygotowania nie pokazuje czasu
całkowitego i nie trafia do szybkich wyników. Bez zmiany schematu.

**`czas_laczny_zrodla_minut` — `totalTime` ze źródła importu** (#2572,
migracja `2026_10_02_190000_add_czas_laczny_zrodla_to_recipes`).

```sql
ALTER TABLE recipes ADD COLUMN czas_laczny_zrodla_minut integer NULL;
ALTER TABLE recipes ADD CONSTRAINT recipes_czas_laczny_zrodla_check
    CHECK (czas_laczny_zrodla_minut IS NULL
        OR (czas_laczny_zrodla_minut > 0 AND czas_laczny_zrodla_minut <= 10080));
```

Wypełnia ją tylko `ZapiszSzkicZImportu` (jawny `totalTime` z JSON-LD lub
mikrodanych, ten sam parser ISO 8601 co prep/cook). To informacja źródła, NIE
czas Kuking: nie wchodzi do `Recipe::totalMinutes()`, filtra „Do 30 minut"
ani `totalTime` w JSON-LD strony, i nigdy nie trafia do `prep_minutes` ani
`cook_minutes`. Gdy źródło podaje prep, cook i total, wszystkie trzy zostają
bez sprawdzania sumy. Strona pokazuje zdanie „Źródło podaje łącznie: około …”
(`Czas::czasPrzepisu()`) tylko gdy czas całkowity autora jest nieznany.
`PublishRecipe` zmienia kolumnę tylko przy jawnym kluczu, więc kreator jej nie
czyści. Usuwanie wartości przez autora: na później (wymaga zmiany kreatora).
Eksport danych: `czas_laczny_zrodla_minuty`.

**Rollback:** `down()` ODMAWIA (D-088), gdy któryś przepis ma wartość —
informacji ze źródła `up()` nie odtworzy; komunikat podaje ręczne wyzerowanie
(`UPDATE recipes SET czas_laczny_zrodla_minut = NULL`). Bez wartości usuwa CHECK
i kolumnę. Pilnuje `tests/Feature/CzasLacznyZrodlaImportuTest.php`.

**`tresc_zmieniona_at` — data zmiany treści, nie zapisu wiersza** (#2014,
migracja `2026_09_28_210000_add_tresc_zmieniona_at_to_recipes`).

```sql
ALTER TABLE recipes ADD COLUMN tresc_zmieniona_at timestamptz NULL;
```

Ustawia ją wyłącznie `PublishRecipe` (kolumna poza `$fillable`): przy
pierwszej publikacji równą `published_at`, potem `now()` tylko wtedy, gdy
odcisk treści (`App\Domain\Recipes\TrescPrzepisu` — pola przepisu,
składniki, kroki, zdjęcie główne, skan źródła, zdjęcia kroków) różni się od
stanu sprzed zapisu, także przy autozapisie. Moderacja, zmiana widoczności
i zapis bez zmian jej nie ruszają — `updated_at` przesuwają wszystkie trzy,
a `recipe_versions` powstaje przy każdym „Zapisz” z publikacją i nie powstaje
przy autozapisie, więc żadne z nich nie jest datą zmiany treści.
`NULL` znaczy „nie wiemy” (przepisy sprzed migracji — bez backfillu, bo
zgadnięta data byłaby nieprawdą w danych strukturalnych); wtedy strona pomija
`dateModified`, tak samo jak przy dacie wcześniejszej niż `published_at`.
`ADD COLUMN … NULL` bez DEFAULT zmienia tylko katalog, bez przepisywania
tabeli (§6).

**Rollback:** `ALTER TABLE recipes DROP COLUMN tresc_zmieniona_at`. `down()`
nie odmawia (D-088): kolumna niesie wyliczony znacznik, nie decyzję
człowieka. Po ponownym `up()` wraca `NULL`, czyli stan, w którym JSON-LD
pomija opcjonalne pole — nic nie odwraca się w stronę nieprawdy; traci się
tylko dokładność `dateModified` do następnej zmiany treści. Pilnuje
`tests/Feature/CofniecieDatyZmianyTresciPrzepisuTest.php`.

**Alergeny przepisu — oznaczenie autora** (#1902, D-333, migracja
`2026_10_01_083000_add_allergens_to_recipes`).

```sql
ALTER TABLE recipes
  ADD COLUMN allergen_status varchar(16) NOT NULL DEFAULT 'unchecked',
  ADD COLUMN allergens text[] NOT NULL DEFAULT '{}',
  ADD COLUMN allergens_declared_at timestamptz NULL;
-- cztery CHECK-i, każdy NOT VALID, potem osobno VALIDATE (§6)
recipes_allergen_status_check:          allergen_status IN ('unchecked','declared','needs_review')
recipes_allergens_closed_list_check:    allergens <@ ARRAY['gluten','crustaceans','eggs','fish','peanuts',
                                          'soy','milk','nuts','celery','mustard','sesame','sulphites',
                                          'lupin','molluscs']::text[]
recipes_allergens_only_when_declared_check: allergen_status <> 'unchecked' OR cardinality(allergens) = 0
recipes_allergens_declared_at_check:    (allergen_status = 'unchecked') = (allergens_declared_at IS NULL)
```

- Poziom PRZEPISU, nie składnika: `PublishRecipe::syncIngredients()` kasuje
  i zakłada wiersze składników przy każdym zapisie, więc oznaczenie na wierszu
  by przepadło. Składnik dalej jest wolnym tekstem (`ingredient_text`).
- 14 kodów to Załącznik II rozporządzenia 1169/2011; polskie nazwy w enumie
  `App\Domain\Recipes\Alergeny\Alergen`. Lista w enumie i w CHECK-u musi być
  identyczna — pilnuje `AlergenyOgraniczeniaBazyTest` (odczyt `pg_get_constraintdef`).
- Trzy stany: `unchecked` (domyślny, także wszystkie istniejące przepisy —
  „Alergeny: nie sprawdzono"), `declared` (autor zaznaczył i potwierdził, że
  lista jest pełna; lista MOŻE być pusta), `needs_review` (po potwierdzeniu
  zmieniono składniki; lista zostaje zapisana, ale nie jest pokazywana, do
  ponownego potwierdzenia). Filtr w wyszukiwarce przepuszcza wyłącznie `declared`.
- Wszystkie trzy kolumny POZA `$fillable` (pole sterujące, D-006): stan
  `declared` powstaje tylko w `OznaczAlergenyPrzepisu` (`forceFill`), a
  `needs_review` ustawia `PublishRecipe` w transakcji zapisu składników.
- Bez indeksu: filtr działa na zbiorze zawężonym frazą; GIN `CONCURRENTLY`
  dopiero po pomiarze.
- Migawka wersji (`recipe_versions.snapshot`) dostaje `allergen_status` i
  `allergens`; starsze migawki ich nie mają i brak klucza znaczy „nieznane".
  Kolumny są też w odcisku treści (`TrescPrzepisu`), w eksporcie danych konta
  (`alergeny_stan`, `alergeny`, `alergeny_potwierdzone`) i — za flagą
  `KUKING_ALERGENY_WLACZONE` — w API, zawsze jako para stan + lista.
- Usunięcie konta (D-022): dane są kolumnami przepisu, idą z nim; nie zawierają
  danych osobowych. Żadnych danych o widzu filtr nie zapisuje (brak kolumny w
  `users`, brak zapisu w sesji) — D-299, art. 9 RODO.

Zapytanie do naprawy wierszy, gdy walidacja CHECK-ów w migracji odmówi:

```sql
SELECT id, allergen_status, allergens, allergens_declared_at FROM recipes
WHERE NOT (allergen_status IN ('unchecked','declared','needs_review'))
   OR (allergen_status = 'unchecked' AND (cardinality(allergens) > 0 OR allergens_declared_at IS NOT NULL))
   OR (allergen_status <> 'unchecked' AND allergens_declared_at IS NULL);
```

**Rollback ODMAWIA** (D-088): `down()` rzuca wyjątek, gdy choć jeden przepis ma
`allergen_status <> 'unchecked'`, bo `down()` + kolejny `migrate` wróciłby ze
stanem „nie sprawdzono" i po cichu skasował decyzję autora. Komunikat po polsku
podaje zapytanie `\copy` do zachowania danych i zmienną
`KUKING_ROLLBACK_KASUJ_ALERGENY=true` na świadome skasowanie. Blokada
`LOCK TABLE recipes` obejmuje sprawdzenie i `DROP COLUMN`. Na świeżej bazie i
przy samych przepisach niesprawdzonych cofnięcie przechodzi. Test odmowy i
kontrola dodatnia: `tests/Feature/Alergeny/CofniecieMigracjiNieKasujeAlergenowTest.php`.

**`forked_from_id`, `forked_at` — „Moja wersja", przepis na podstawie
cudzego** (issue #23, D-301, migracja `2026_09_26_100000_add_forked_from_to_recipes`).

```sql
ALTER TABLE recipes ADD COLUMN forked_from_id uuid NULL
    REFERENCES recipes (id) ON DELETE SET NULL;          -- recipes_forked_from_id_foreign
ALTER TABLE recipes ADD COLUMN forked_at timestamptz(0) NULL;
ALTER TABLE recipes ADD CONSTRAINT recipes_forked_spojny_check CHECK (
    (forked_from_id IS NULL OR forked_at IS NOT NULL)
    AND (forked_from_id IS NULL OR forked_from_id <> id));
CREATE INDEX recipes_forked_from_idx ON recipes (forked_from_id)
    WHERE forked_from_id IS NOT NULL;
```

- `forked_from_id` — KTÓRY przepis był oryginałem. `ON DELETE SET NULL`:
  twarde skasowanie oryginału (wymazanie konta jego autora,
  `EraseAccountData`) nie kasuje cudzej wersji i nie zatrzymuje kasowania
  konta. Zwykłe usunięcie jest miękkie, więc wskazanie zostaje.
- `forked_at` — ŻE przepis jest wersją cudzego i od kiedy. Zostaje także po
  wyzerowaniu `forked_from_id`, więc wersja nigdy nie wygląda w bazie jak
  przepis własny. Obie kolumny ustawia wyłącznie `ZrobWlasnaWersje`
  (`forceFill()`); w `$fillable` ich nie ma — podpis jest nieusuwalny.
- Indeks częściowy obsługuje listę „Wersje innych osób" na stronie oryginału
  i `ON DELETE SET NULL`.

DDL na istniejącej tabeli: `ADD COLUMN` bez `DEFAULT` (bez przepisania
tabeli), klucz obcy i CHECK przez `NOT VALID` + `VALIDATE`, indeks
`CONCURRENTLY`, poza jedną transakcją.

**Rollback odmawia, gdy w bazie jest choć jedna wersja** (D-088): po
`migrate:rollback` → `migrate` kolumny wróciłyby puste, a każda wersja stałaby
się po cichu przepisem swojego autora. Komunikat podaje zapytanie, którym
zapisać powiązania przed ręcznym cofnięciem. Na bazie bez wersji cofnięcie
przechodzi. `down()` bierze `ACCESS EXCLUSIVE` przed liczeniem wersji i trzyma
blokadę do końca usunięcia kolumn; dzięki temu równoległy zapis nie może wejść
między strażnik a DDL (#2059). Zależny indeks znika razem z kolumną w tej
samej transakcji, bez osobnego `DROP INDEX CONCURRENTLY`. Testy:
`tests/Feature/CofniecieMigracjiNieGubiPodpisuWersjiTest.php` i
`tests/Dwa/RollbackWersjiTrzymaBlokadeTest.php`.

**`klucz_wyslania` — jedno wysłanie formularza to jeden przepis** (D-027,
migracja `2026_09_12_600000_add_klucz_wyslania_to_recipes`).

```sql
ALTER TABLE recipes ADD COLUMN klucz_wyslania uuid NULL;
CREATE UNIQUE INDEX recipes_one_per_klucz_wyslania
    ON recipes (author_id, klucz_wyslania)
    WHERE klucz_wyslania IS NOT NULL;
```

Zmierzone przed tą migracją (audyt podwójnego wysłania, 12 września 2026):
dwa razy `POST /dodaj/przepis` z identycznym ciałem dawały **dwa** wiersze
w `recipes`, drugi pod adresem z doklejoną dwójką (`…-2`), razem z drugim
kompletem składników i kroków, drugą wersją w `recipe_versions`, drugim
wpisem `recipe.published` w dzienniku audytowym i drugim wpisem w strumieniu
obserwujących. Po migracji: **jeden** wiersz, a drugie kliknięcie odsyła pod
ten sam adres.

Klucz jest w indeksie razem z `author_id`, nie sam — dokładnie jak w `posts`
i `cooked_events`: klucz wygenerowany w cudzej przeglądarce nie ma prawa
wskazywać na przepis innej osoby.

**Kolumnę wypełnia wyłącznie ZAŁOŻENIE przepisu.** `recipes.update` nie
przysyła klucza i go nie nadpisuje — inaczej pierwsze dopisanie szczegółów
zdejmowałoby ochronę po cichu.

**Kolumna jest `NULL`-owalna i nie ma backfillu.** Przepisy sprzed tej
migracji, z seederów i z fabryk mają `NULL`, a indeks częściowy
(`WHERE klucz_wyslania IS NOT NULL`) ich nie obejmuje.

**Wyłącznik:** `kuking.formularze.klucz_wyslania_wlaczony` (`false` →
formularz nie renderuje ukrytego pola, kolumna dostaje `NULL`, indeks
przestaje cokolwiek odbijać).

**Rollback:** `DROP INDEX IF EXISTS recipes_one_per_klucz_wyslania`, potem
`DROP COLUMN klucz_wyslania`. Bezstratnie i dlatego `down()` niczego nie
odmawia (D-088 dotyczy wartości semantycznych): kolumna niesie wyłącznie
identyfikator wysłania wygenerowany przez serwer, ani jednego słowa
napisanego przez człowieka i ani jednej decyzji, którą ktoś podjął.

**`summary varchar(2000) NULL`** — „Krótko o przepisie", zdanie albo dwa nad
składnikami. Idzie też do `<meta name="description">` (przycięte do 155 znaków)
i do `description` w JSON-LD, więc jest tekstem, który człowiek zobaczy
w wynikach wyszukiwania. `NULL` jest stanem normalnym — przepis bez opisu
publikuje się tak samo.

#### `estimated_cost_pln` — szacunkowy koszt całego przepisu wg autora (V2, D-286)

Migracja `2026_09_26_100000_add_estimated_cost_pln_to_recipes`.

```sql
ALTER TABLE recipes ADD COLUMN estimated_cost_pln numeric(6,2) NULL;
ALTER TABLE recipes ADD CONSTRAINT recipes_estimated_cost_pln_check
    CHECK (estimated_cost_pln IS NULL OR estimated_cost_pln >= 0) NOT VALID;
ALTER TABLE recipes VALIDATE CONSTRAINT recipes_estimated_cost_pln_check;
```

- **Złote z groszami za CAŁY przepis** (nie za porcję), najwyżej
  9999,99 zł — sufit niesie sam typ `numeric(6,2)`, dolną granicę CHECK.
- **`NULL` = autor nie podał** i strona przepisu o koszcie milczy. **`0` to
  odpowiedź** („z tego, co w ogródku"), dlatego kolumna nie ma `DEFAULT`
  ani backfillu.
- Wpisuje ją wyłącznie autor: kreator (`KrokOPrzepisie`) i formularz
  szczegółów (`ZapisPrzepisuRequest`), wspólne reguły i komunikaty
  w `App\Domain\Recipes\KosztPrzepisu` (przecinek, spacje i dopisek „zł"
  są przyjmowane; więcej niż dwa miejsca po przecinku — komunikat, nie
  ciche zaokrąglenie). **Brak pola w żądaniu nie czyści kwoty**
  (`PublishRecipe` zapisuje ją tylko, gdy klucz przyszedł) — ekran dodawania
  tego pola nie ma.
- Idzie do snapshotu wersji (`SnapshotRecipeVersion`), do paczki danych
  (`CollectUserExportData`: `szacunkowy_koszt_zl`) i do czytelnego pliku
  przepisu w paczce. Sekcja `przepisy` jest już w `InwentarzDanychKonta`
  przez `recipes.author_id`, więc rejestr nie wymagał nowego wpisu.
- Wyszukiwarka: zakres „Do 20 zł" (`sekcja=tanie`) to sam warunek
  `estimated_cost_pln <= 20`, bez wpływu na kolejność; przepis bez kosztu
  wypada. Bez indeksu — warunek działa na zbiorze kandydatów z trigramów,
  tak jak „Do 30 minut".

**Rollback:** `down()` **odmawia**, gdy choć jeden przepis ma koszt —
zdjęcie kolumny skasowałoby liczbę wpisaną przez człowieka, a kolejny
`migrate` odtworzyłby ją jako `NULL` bez śladu (D-088). Komunikat mówi, jak
najpierw zachować wartości (kopia tabeli), wyczyścić kolumnę i powtórzyć.
Przy samych `NULL`-ach i na świeżej bazie przechodzi bez pytania
(`tests/Feature/KosztPrzepisuMigracjaTest.php`: odmowa + dwie kontrole
dodatnie).

#### Pochodzenie przepisu: `source_type`, `source_person`, `source_note`, `source_url`

Cztery kolumny z pierwszej migracji przepisów
(`2026_09_05_000400_create_recipes_tables`). To nie jest metadana — „skąd znam
ten przepis" odróżnia Kuking od bazy receptur, a przy prawach autorskich jest
deklaracją pochodzenia (`docs/MODERATION.md`).

| Kolumna | Typ | Co w niej naprawdę jest |
|---|---|---|
| `source_type` | `varchar(20) NOT NULL DEFAULT 'own'` | Zamknięta lista, CHECK `recipes_source_type_check`: `own` \| `family` \| `adaptation` \| `external`. Etykiety dla człowieka trzyma `Recipe::SOURCE_LABELS`. |
| `source_person` | `varchar(120) NULL` | **Wolny tekst od człowieka.** Patrz niżej — to nie jest osoba. |
| `source_note` | `varchar(2000) NULL` | Historia przepisu, wspomnienie. Pokazywane pod nagłówkiem „Skąd ten przepis", PRZED składnikami, z zachowaniem łamań wierszy (`whitespace-pre-line`). |
| `source_url` | `text NULL` | Adres strony, z której przepis pochodzi. Widok pokazuje go **tylko przy `source_type = 'external'`**; link z `rel="nofollow noopener"` powstaje tylko dla HTTP/HTTPS, inne zachowane adresy są zwykłym tekstem. W bazie bez limitu długości; formularz i kreator przyjmują najwyżej 2000 znaków. Nowy lub zmieniony adres musi być HTTP/HTTPS (`url:http,https`), niezmieniony dawny adres z bazy może zostać (#900, D-254). |

Puste i złożone z samych spacji wartości `PublishRecipe` zamienia na `NULL`
**przed** zapisem (`nullIfBlank`), więc „pole wyczyszczone" i „pole nigdy nie
wypełnione" to w bazie ten sam stan. Wszystkie cztery idą do snapshotu wersji
(`SnapshotRecipeVersion`) i do eksportu danych (`CollectUserExportData`:
`skad_przepis`, `zrodlo_adres`, `od_kogo`, `notatka_o_zrodle`).

**`source_person` NIE JEST OSOBĄ — i to jest fakt o danych, nie ostrożność**
(D-156, PR #403). Nazwa kolumny obiecuje człowieka, a pole pyta **„Od kogo albo
skąd masz ten przepis"** z podpowiedzią `od mamy · z gazety · z bloga Nasze
smaki`. Właściciel potwierdził, że wpisuje tam **nazwę grupy na Facebooku**.
W jednej kolumnie `varchar(120)` leżą więc obok siebie: nazwa grupy, tytuł
gazety, imię babci i zdanie „od mamy" — i **z wiersza nie da się rozpoznać,
który to przypadek**.

Wynikają z tego dwie twarde reguły, obie już wdrożone:

1. **Widok pokazuje tę wartość DOSŁOWNIE** — bez doklejonego przyimka i bez
   kropki (D-153, PR #397). Nagłówek „Skąd ten przepis" niesie całe znaczenie;
   doklejane „Po " dawało „Po po mamie." i „Po Nasze smaki.". Pierwsza litera
   idzie przez `Str::ucfirst()` (wielobajtowe). Podpis przepisu składa się
   z członów rozdzielonych „·" (`Recipe::attributionLine()`), nigdy z formy
   wymagającej przypadka.
2. **Ta wartość nie trafia do pola, które wymusza typ encji** (D-156). W JSON-LD
   `author` opisuje wyłącznie konto publikujące, a `source_person` idzie do
   `citation` jako zwykły `Text`. `@type: Person` z tą wartością deklarowałby
   typ, którego nikt nie zna.

**Czego świadomie nie zrobiono: migracji danych.** Wartości wpisane pod starym
pytaniem („po mamie") zostają w bazie takie, jakie są — automatyczna zamiana
cudzego tekstu byłaby zgadywaniem (D-153).

`family_since_year smallint NULL` (CHECK `1850..2100`) — sam rok, bez daty
dziennej; więcej nie zbieramy. `source_scan_media_id uuid NULL` → `media`
(`ON DELETE SET NULL`) — zdjęcie kartki z zeszytu albo wycinka, bez OCR.
To zdjęcie bywa skanem odręcznej kartki z nazwiskami, więc dostęp do niego
idzie tą samą drogą co do każdego innego zdjęcia przepisu
(`App\Domain\Media\DostepDoZdjecia`).
Na zwykłej stronie przepisu skan otwiera sekcję „Skąd ten przepis” także bez
opcjonalnych `source_person` i `source_note`; wariant dla pomocnika nadal ją
pomija. Zdjęcie renderuje istniejący komponent z przetworzonym wariantem,
nigdy oryginał z metadanymi pliku.

### ceny_skladnikow
Cennik składników do **orientacyjnego kosztu dania**, gdy autor nie wpisał
własnej kwoty (V2, D-286 część 2; migracja
`2026_09_26_110000_create_ceny_skladnikow_table`).

To **słownik, nie treść użytkowników**. Jedyna droga zapisu to komenda
`php artisan kuking:ceny-skladnikow`, która wczytuje plik
`database/data/ceny_skladnikow.csv` z repozytorium — w całości albo wcale,
w jednej transakcji; wiersze spoza pliku znikają. Produkcja niczego nie
pobiera z sieci: plik odświeża osoba prowadząca dwoma skryptami, jednym na
źródło (D-286, część 3):

- `scripts/ceny-gus-pobierz.py` — mięso, nabiał, pieczywo, produkty suche:
  API Banku Danych Lokalnych GUS, temat P1466, średnie roczne ceny
  detaliczne dla Polski. Wiersz ma numer zmiennej w `zmienna_bdl`.
- `scripts/ceny-warzyw-zsrir-pobierz.py` — **warzywa detaliczne**
  (`ziemniaki`, `cebula`, `marchew`, `papryka_czerwona`, `pomidor`):
  GUS/BDL nie podaje dziś ich cen (seria miesięczna z ziemniakami, cebulą
  i marchwią kończy się w 2019 r.). Zamiennik to Zintegrowany System
  Rolniczej Informacji Rynkowej (ZSRIR) Ministerstwa Rolnictwa i Rozwoju
  Wsi — otwarte dane dane.gov.pl (zbiór 912, CC BY 4.0), arkusz „ZAKUP
  WARZ DETAL — do 2 kg” (cena zakupu warzyw przez detal, opakowania do
  2 kg — najbliższy oficjalny odpowiednik detalu, jaki ZSRIR ma). Od
  26.09.2026 (D-286, część 3, decyzja właściciela) ten skrypt uruchamia
  się **automatycznie, co tydzień**, w GitHub Actions
  (`.github/workflows/ceny-warzyw-auto.yml`) — produkcja nadal niczego
  nie pobiera z sieci, automatyzacja dotyczy wyłącznie CI, a wynik idzie
  do `main` przez zwykły PR, który merguje człowiek.
- **Warzywa liczone hurtowo × przelicznik** (`kapusta`, `buraki`, `por`,
  `seler`, `pietruszka`, `salata`, `ogorek`): ZSRIR notuje je TYLKO
  hurtowo (arkusz „HURT WARZ”, pięć rynków: Bronisze, Kalisz, Łódź,
  Poznań, Rzeszów). Cena w pliku to średnia z min–max tych pięciu
  rynków razy `App\Domain\Recipes\Koszt\SzacunekKosztuZCen::MNOZNIK_HURT_DETAL`
  (`1,6` — decyzja właściciela z 26.09.2026, uzasadnienie stałej w kodzie
  i w `docs/DECISIONS.md`, D-286 część 3). Liczone RĘCZNIE, nie przez
  żaden skrypt — arkusz „HURT WARZ” ma inny układ kolumn (pięć rynków,
  min i max osobno) i nie jest dziś zautomatyzowany. `zrodlo` każdego
  z tych wierszy zaczyna się od frazy „szacunek z cen hurtowych” —
  `SzacunekKosztuZCen` wykrywa tę frazę i dokłada do zdania na stronie
  przepisu wprost napisaną klauzulę, że to szacunek z hurtu, nie zwykła
  cena detaliczna.

Wszystkie trzy źródła (GUS, ZSRIR detal, ZSRIR hurt × przelicznik)
zmieniają WYŁĄCZNIE wiersze swojego źródła; zmiana cen przechodzi
przegląd w PR-ze jak każda inna — ręcznie albo (dla warzyw detalicznych)
przez automatyczny PR z `ceny-warzyw-auto.yml`.

| Kolumna | Typ | Znaczenie |
|---|---|---|
| `klucz` | `varchar(60)` PK | stały klucz z pliku (`maka_pszenna`) |
| `nazwa` | `varchar(160)` | nazwa dla człowieka |
| `wzorce` | `text` | formy słowa w tekście składnika, ASCII, rozdzielone `\|`; dopasowanie CAŁYMI słowami (CHECK: niepuste) |
| `wyklucz` | `text NULL` | początki słów, które odrzucają dopasowanie („ziemniaczan") |
| `cena_zl`, `za_ilosc`, `jednostka` | `numeric(8,2)`, `numeric(8,3)`, `varchar(3)` | cena za `za_ilosc` jednostek `kg` \| `l` \| `szt` (CHECK) |
| `g_na_jednostke` | `numeric(8,2)` | ile gramów waży jednostka ceny (1 l oleju ≈ 920 g, 1 jajko ≈ 60 g) |
| `g_szklanka`, `g_lyzka`, `g_lyzeczka`, `g_sztuka` | `numeric(8,2) NULL` | miary domowe TEGO składnika w gramach |
| `kolejnosc` | `smallint` | kolejność dopasowania — pierwszy pasujący wiersz wygrywa |
| `okres`, `zrodlo`, `zmienna_bdl` | `varchar` | skąd jest cena: rok, opis źródła, numer zmiennej GUS (`NULL` dla wierszy spoza GUS, np. ZSRIR) |
| `zaimportowano_at` | `timestamptz` | kiedy komenda wczytała wiersz |

CHECK `ceny_skladnikow_liczby_check`: `cena_zl >= 0`, `za_ilosc > 0`,
`g_na_jednostke > 0`, miary domowe `NULL` albo `> 0`. `cena_zl = 0` ma
dokładnie jeden sens: składnik bez kosztu (woda), który **nie liczy się do
pokrycia** masy przepisu.

Szacunek liczy `App\Domain\Recipes\Koszt\SzacunekKosztuZCen` — w PHP,
deterministycznie, bez AI: przedział ±15% wokół sumy, tylko gdy składniki
z ceną to ≥ 90% masy przepisu i każdy składnik z ilością da się przeliczyć
na gramy. Tekst składnika nie jest zmieniany.

**Rollback:** `DROP TABLE ceny_skladnikow` bez strażnika — nikt tu nic nie
wpisał, a ponowne uruchomienie komendy odtwarza stan z pliku.

### recipe_slug_redirects
Stary adres przepisu nadal działa po zmianie tytułu — link wysłany córce
SMS-em nie może umrzeć, bo autor poprawił literówkę
(`docs/seo/SEO_TECHNICAL.md`).

- **`recipe_slug_redirects.slug varchar(220) PRIMARY KEY`** — porzucony slug.
  Klucz główny jest tu SAMYM SLUGIEM, nie osobnym `id`: wiersz jest
  odwzorowaniem „adres → przepis" i pytamy o niego wyłącznie po adresie,
  a PK na sluggu z urzędu zabrania dwóch przepisów pod jednym starym adresem;
- `recipe_id uuid NOT NULL` → `recipes` (`ON DELETE CASCADE`) — dokąd
  przekierować;
- `created_at`.

### recipe_versions
Snapshot po istotnych zmianach.

- `recipe_id`, `editor_id` (`ON DELETE RESTRICT` — wersji nie wolno osierocić
  przez skasowanie konta edytora);
- `version_number integer` (CHECK `> 0`, `UNIQUE(recipe_id, version_number)`) —
  numer kolejny w obrębie jednego przepisu, nie w całym serwisie;
- `snapshot jsonb NOT NULL` — pełna treść przepisu w chwili zapisu, składana
  przez `App\Domain\Recipes\Actions\SnapshotRecipeVersion` (tytuł, opis,
  czasy, wszystkie cztery kolumny pochodzenia i `family_since_year`,
  składniki z `no_amount`, kroki). `source_url` i `ingredients[].no_amount` są
  w migawce od issue #896 — **w starszych migawkach tych kluczy nie ma
  i brak znaczy „nieznane"**; nie uzupełniamy ich dzisiejszą wartością
  z przepisu. Numer wersji i migawka powstają w transakcji zapisu treści,
  pod blokadą wiersza `recipes` (issue #895). Zmiana kształtu JSON, nie
  schematu — bez migracji;
- `change_note varchar(500) NULL` — **wolny tekst od człowieka**: czym ta
  wersja różni się od poprzedniej. `NULL` znaczy „nic nie napisał" i jest
  stanem normalnym;
- `created_at`.

Porównanie dwóch migawek (#2451) sprawdza również **widoczną kolejność**
grup i składników po zastosowaniu `GrupySkladnikow::ulozyc()`. Porównuje
wspólne wiersze, nie numery `position`: dodanie albo usunięcie składnika nie
oznacza każdej późniejszej pozycji jako zmienionej. Nie pobiera dzisiejszej
treści przepisu do odtwarzania starszej wersji. To zmiana odczytu historii,
bez zmiany schematu i bez migracji; cofnięcie samego kodu przywracałoby błędny
komunikat „brak różnic” przy przestawieniu.

**Kiedy powstaje wersja (issue #1316).** Przy każdej publikacji
(„Pierwsza publikacja", „Aktualizacja przepisu") oraz przy ŚWIADOMYM zapisie
BEZ publikacji na przepisie, który jest opublikowany — „Zapisz zmiany",
wyjście z kreatora („Nie teraz"), `action=draft` w formularzu bez
JavaScriptu (`SnapshotRecipeVersion::poprawka()`):

- treść równa ostatniej wersji → nowej wersji nie ma;
- inaczej → nowa wersja z opisem „Poprawka opublikowanego przepisu".

Autozapis kreatora (pauza w pisaniu, „Dalej", „Wstecz") zapisuje treść, ale
wersji nie tworzy. **Istniejącej wersji nie zmienia się nigdy** (decyzja
właściciela z 24.09.2026): model `RecipeVersion` odmawia `update()` wyjątkiem
— jedynym wyjątkiem są dwie kolumny ukrycia (niżej, #2270), które nie są
treścią wersji.
Szkic przed pierwszą publikacją nie ma wersji. Zmiana zachowania, nie
schematu — bez migracji.

**Retencja (#2024, D-333 — wartości potwierdzone przez właściciela 30.09).**
`kuking:sprzataj-wersje-przepisow` (codziennie 06:40, `routes/console.php`)
kasuje wersję, która jest **starsza niż 24 miesiące** (próg to początek dnia
w Polsce sprzed 24 miesięcy, `config('kuking.strefa')`, bez przepełnienia
końca miesiąca) **i nie należy do 3 najnowszych wersji swojego przepisu**
(`config('kuking.przepisy.version_retention_months')`, `version_keep_latest`,
minimum 2). Pierwsza wersja NIE jest chroniona — historia jest publiczna,
a w najstarszych wersjach zostaje treść, którą autor później usunął.
Nie kasujemy wersji przepisu, na który wskazuje `reports` albo
`moderation_actions`. Wersje usuniętego przepisu idą razem z nim
(`PrzedawnioneUsunieteTresci`, 30 dni). Kasowanie idzie partiami po 500,
budżet przebiegu to 20 000 wierszy; błąd partii daje kod wyjścia ≠ 0, który
harmonogram zamienia w wyjątek. **Luki w `version_number` są normalne**:
numer nowej wersji to `max + 1`, ekrany historii liczą sąsiadów z faktycznej
listy. Eksport danych (`wersje_przepisow`) niesie to, co zostało — kształt
bez zmian. Bez zmiany schematu; rollback to wyłączenie zadania (skasowanych
wersji żaden rollback nie przywróci — to cel zmiany).

**Ukrycie pojedynczej wersji (#2270, D-333 — decyzja właściciela 30.09).**
Migracja `2026_09_30_201700_add_hidden_at_to_recipe_versions`:

```sql
ALTER TABLE recipe_versions ADD COLUMN hidden_at timestamptz NULL;
ALTER TABLE recipe_versions ADD COLUMN hidden_by_role varchar(10) NULL;
ALTER TABLE recipe_versions ADD CONSTRAINT recipe_versions_hidden_spojny_check CHECK (
    (hidden_at IS NULL AND hidden_by_role IS NULL)
    OR (hidden_at IS NOT NULL AND hidden_by_role IS NOT NULL
        AND hidden_by_role IN ('author','moderator'))) NOT VALID;
ALTER TABLE recipe_versions VALIDATE CONSTRAINT recipe_versions_hidden_spojny_check;
```

- `hidden_at` — od kiedy wersja jest ukryta; `NULL` = widoczna jak dotąd.
  Wersję ukrytą widzi wyłącznie autor przepisu i czynna moderacja
  (`HistoriaWersji::widziUkryte`), z oznaczeniem; dla reszty jej adres daje
  404, lista jej nie pokazuje, a porównanie bierze za poprzednika najbliższą
  widoczną wersję i mówi, ile ukrytych pominęło.
- `hidden_by_role` — **strona**, nie konto: `author` albo `moderator`.
  Rozstrzyga, kto może ukrycie cofnąć (autor nie cofa ukrycia moderacji,
  moderacja nie odsłania tego, co autor ukrył sam — `RecipeVersionPolicy`).
  **Które konto** ukryło, stoi wyłącznie w `audit_log`
  (`recipe_version.hidden` / `recipe_version.restored`). Kolumny z `uuid`
  konta świadomie nie ma: weszłaby do inwentarza danych konta, eksportu
  i wymazywania, a reguła jej nie potrzebuje.
- Obie kolumny są poza `$fillable` (pola sterujące widocznością) i zmieniają
  je tylko `RecipeVersion::ukryj()` / `odkryj()`. Strażnik `updating` nadal
  odrzuca każdą inną zmianę istniejącej wersji — ukrycie nie jest furtką
  do poprawiania treści.
- **Najnowszej wersji nie da się ukryć** (`UkrywanieWersji`, sprawdzane pod
  blokadą wiersza `recipes`, tą samą co przy nadawaniu numeru): to treść
  przepisu widoczna na jego stronie. Żeby usunąć z niej tekst, autor poprawia
  przepis — powstaje nowa wersja, a poprzednią da się ukryć.
- Ukrycie przez **moderację** jest decyzją moderacyjną (DSA): obok
  `hidden_by_role = 'moderator'` powstaje wiersz `moderation_actions`
  (`target_type = 'recipe_version'`, sekcja `moderation_actions`), autor
  dostaje powiadomienie z drogą odwołania, a uznane odwołanie zdejmuje
  ukrycie. Ukrycie przez autora wiersza w `moderation_actions` nie tworzy.
  **Przejęcie (decyzja 30.09.2026, bez migracji):** moderacja może przejąć
  ukrycie zrobione przez autora — ta sama droga co zwykłe ukrycie, pod tą samą
  blokadą: `hidden_by_role` zmienia się z `author` na `moderator`, powstaje
  wiersz `moderation_actions` (`hide`), a `audit_log` (`recipe_version.hidden`)
  niesie dodatkowo `przejeto_od = author`. Reguła CHECK dopuszcza obie
  wartości, więc schemat się nie zmienia.
- Retencja (akapit wyżej) ukrycia nie patrzy: stara ukryta wersja spoza
  3 najnowszych znika tak samo jak widoczna. Eksport (`wersje_przepisow`)
  niesie ukrytą wersję całą, z `ukryto` (data) i `ukryl` (`autor` |
  `moderacja` | `null`).
- Bez indeksu: każde zapytanie idzie po `recipe_id` (indeks unikalny
  `recipe_id, version_number`), a wersji jednego przepisu jest kilka.

Zapytanie kontrolne (CHECK odmówiłby walidacji):
`SELECT id FROM recipe_versions WHERE (hidden_at IS NULL) <> (hidden_by_role IS NULL);`

**Rollback: `down()` ODMAWIA, gdy choć jedna wersja jest ukryta (D-088).**
Zdjęcie kolumny odsłania ukryte wersje publicznie, a ponowne `up()` wraca
z `NULL` — czyli nic nie ukrywa, bez śladu błędu. Komunikat odmowy mówi, co
zrobić ręcznie (kopia `id, hidden_at, hidden_by_role`, rollback, po ponownym
`migrate` przywrócenie z kopii). Bez ukrytych wersji (świeża baza, CI)
`down()` zdejmuje CHECK i obie kolumny bez pytania. Pilnuje
`tests/Feature/UkrywanieWersjiPrzepisuTest.php` (odmowa + kontrola dodatnia).

### recipe_shares — udostępnienie jednego przepisu wskazanej osobie (#2650)

Migracja `2026_10_03_120000_create_recipe_shares_table` (nowa tabela).
Decyzja i granice: wiersz #2650 w **D-333**. Jeden wiersz = „autor pozwolił
tej osobie CZYTAĆ ten przepis". Udostępnienie **nie zmienia**
`recipes.visibility`: przepis „Tylko ja" dalej nie wychodzi w feedzie,
wyszukiwarce, mapie strony, JSON-LD ani na profilu.

- `id uuid` (`gen_random_uuid()`);
- `recipe_id uuid NOT NULL` → `recipes` `ON DELETE CASCADE` (trwałe
  usunięcie); zwykłe usunięcie przepisu przez autora kasuje udostępnienia
  jawnie (`OdbierzDostepDoPrzepisu::wszystkieDlaPrzepisu()`);
- `recipient_id uuid NOT NULL` → `users` `ON DELETE CASCADE`; wymazanie konta
  (wiersz `users` zostaje jako `erased`) kasuje udostępnienia obu stron
  jawnie (`KoniecUdostepnienPrzepisow::przyWymazaniu()`);
- `created_at`, `updated_at timestamptz` — od kiedy osoba ma dostęp.

Autor nie ma kolumny — jest nim `recipes.author_id` (druga kopia mogłaby się
rozjechać). Ograniczenia:

- `recipe_shares_recipe_recipient_unique` — `UNIQUE (recipe_id, recipient_id)`:
  jedno udostępnienie na parę, także przy dwóch równoległych kliknięciach
  (`insertOrIgnore`);
- `recipe_shares_recipient_idx` — lista „Przepisy udostępnione mi";
- **wyzwalacz `recipe_shares_guard`** (`BEFORE INSERT OR UPDATE`, funkcja
  `recipe_shares_guard()`) odmawia (`check_violation`, 23514) wpisania autora
  jako odbiorcy jego własnego przepisu — CHECK tego nie wyrazi, bo warunek
  dotyczy wiersza `recipes` (wzór: `collection_members_guard`).

**Brak stanu „odebrane".** Odebranie dostępu, rezygnacja odbiorcy, blokada
(`ZerwijUdostepnieniaPrzepisow::miedzy()` pod `ZamekPary`) i usunięcie
przepisu KASUJĄ wiersz — nic nie może go po cichu przywrócić (odblokowanie,
zmiana widoczności). Zawieszenie, ban, zamykanie konta i ukrycie przepisu
przez moderację wiersza nie kasują; dostęp wstrzymuje
`RecipePolicy::readShared()`, która pyta bazę przy każdym żądaniu (bez cache).

`$fillable` modelu `RecipeShare` jest puste — klucze ustawia wyłącznie
`UdostepnijPrzepis` (pod `ZamekPary`, potem blokada doradcza `2650` na przepis
dla limitu `kuking.udostepnienia.max_osob`; kolejność blokad: konta, potem
przepis — D-079 §1).

**Rollback (D-088).** `down()` kasuje tabelę i funkcję wyzwalacza, więc
**odmawia**, gdy w tabeli jest choć jeden wiersz: decyzja autora o tym, komu
pokazał przepis, nie wróci po ponownym `up()`. Komunikat mówi, co zrobić
(kopia `CREATE TABLE recipe_shares_kopia AS SELECT * FROM recipe_shares`,
potem `KUKING_ROLLBACK_KASUJE_UDOSTEPNIENIA_PRZEPISOW=1`). Na pustej tabeli
(CI, `migrate:refresh`) przechodzi bez pytania. Pilnuje
`tests/Feature/UdostepnieniePrzepisuSchematTest.php` (odmowa, wymuszenie,
kontrola dodatnia).
