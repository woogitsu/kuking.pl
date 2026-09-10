> **STATUS: HISTORYCZNE — nie opisuje bieżącej konfiguracji.**
>
> To raport analityczny („R1"), napisany **7 września 2026** na commicie
> `0068de5`, jako materiał wejściowy do decyzji o tagach. Decyzje, które
> z niego wyszły, żyją w `docs/DECISIONS.md` (**D-021** — Tematy znikają,
> zostają same tagi; **D-026** — słownik tagów) oraz w
> `docs/decyzje/TAGI_PROMOWANE.md`. **Przy sporze o stan produktu
> obowiązuje kod i dziennik decyzji, nie ten plik.**
>
> Leży w archiwum, a nie w `docs/`, bo część jego treści jest z założenia
> nieaktualna: opisuje `Topic`, `topic_follows` i `posts.topic_id` jako
> istniejące, a D-021 je usunęła. Zostaje dlatego, że niesie rzeczy, których
> nie ma nigdzie indziej: rozpoznanie istniejących wzorców (`kuking_normalize()`,
> `canonical_name`/`normalized_name`, `recipe_slug_redirects`), argumenty za
> odłożeniem `tag_relations` i `tag_merge_suggestions`, ścieżkę bez
> JavaScriptu i cztery otwarte pytania do właściciela z §10.
>
> Do 10 września 2026 plik leżał w korzeniu repozytorium pod nazwą
> `R1-tagi-kopia.md` i nic się do niego nie odwoływało.

---

# R1 — Tagi: model danych, migracja z Tematów, nadużycia

Zakres: SPEC.md §1.1–1.6 oraz §1.8–1.10. AI (§1.7, §1.11–1.15) świadomie pominięte —
gdzie rekomendacja od niego zależy, jest to zaznaczone jako założenie.

## 0. Wnioski w pięciu zdaniach

System `Topic` **nie jest** starym długiem — to działający, przetestowany kod
scalony **dzień przed tą specyfikacją** (`#82`, `#95`, 2026‑09‑06), zbudowany
wprost po to, żeby rozwiązać udokumentowany problem cold-startu; decyzja
„zamiast" jest mimo to słuszna co do kierunku (jeden system taksonomii), ale
specyfikacja nie odnotowuje, że dokłada realny koszt przepisania czegoś, co
właśnie zaczęło działać, i nie mówi, co zrobić z `posts.topic_id`, które od
wczoraj może już mieć prawdziwe wartości. Model danych z sześciu tabel jest
przedwczesny przy 20–50 kontach — `tag_relations` i `tag_merge_suggestions`
da się bezpiecznie odłożyć bez migracji łamiącej dane, bo obie są czysto
addytywne. Repozytorium ma już dokładnie te klocki, których wdrożenie tagów
potrzebuje — `kuking_normalize()` (pg_trgm + unaccent), wzorzec
`canonical_name`/`normalized_name` z tabeli `ingredients`, mechanizm ratowania
zdjęć w `PostController::store`, `recipe_slug_redirects` — więc realny koszt
budowy jest niższy, niż SPEC sugeruje, o ile te wzorce zostaną powtórzone,
a nie wymyślone od nowa. Lokalna baza wulgaryzmów w opisanej, rozbudowanej
postaci (severity × typ × wyjątki kontekstowe × obrona przed obchodzeniem) to
przy obecnej skali overengineering wobec problemu, który jeszcze nie wystąpił —
warto zbudować szkielet schematu, ale nie inwestować w wyrafinowane wykrywanie
obejść, dopóki nie będzie na to dowodu.

## 1. Rozbieżności między specyfikacją a stanem repozytorium

### 1.1. Temat NIE jest nieużywanym reliktem — jest kodem sprzed jednego dnia

`database/migrations/2026_09_06_100000_create_topics_tables.php`,
`app/Models/Topic.php`, `TopicController`, `TopicFollowController`,
`app/Domain/Feed/TopicFeed.php` — wszystko to wygląda na dojrzały,
przemyślany system, bo nim jest:

```
$ git log -1 --format="%ai" eb235fc   # "Temat wpisu zapisany w bazie" (#31 część A, #82)
2026-09-06 09:06:17 +0200
$ git log -1 --format="%ai" ce6f48d   # "Nowe konto przestaje widzieć pusty ekran" (#31 część B, #95)
2026-09-06 11:16:22 +0200
$ git log -1 --format="%ai %H"        # HEAD
2026-09-07 06:37:00 +0000
```

Czyli: Temat wszedł **wczoraj**, jego druga część (cold-start feed +
onboarding zapisujący wybór do bazy zamiast do sesji, naprawiający realny,
udokumentowany problem — komentarz w migracji cytuje `docs/product/COLD_START.md`)
weszła **też wczoraj**, a ta specyfikacja każe to usunąć **dziś**. SPEC §1.1
mówi „dotychczasowe Tematy nie były faktycznie używane" — to prawdopodobnie
prawda w sensie „nie ma jeszcze prawdziwych użytkowników", ale nie jest to
samo, co „ten kod jest zbędny". `TopicFeed` i `TopicController` (komentarze
w kodzie cytują `SOUL.md 4.7` i `COLD_START.md`) rozwiązują dokładnie ten sam
problem, który SPEC każe teraz rozwiązać tagami: pusty feed nowego konta.
To nie jest argument przeciw tagom — to argument za tym, żeby raport
właściciela projektu jasno nazwał to, co się dzieje: **nie „usuwamy nieużywaną
funkcję", tylko „zastępujemy jednodniowy kod tym samym mechanizmem
zbudowanym drugi raz, szerzej"**. Konsekwencja: warto to zrobić szybko, zanim
ktokolwiek zdąży się przyzwyczaić do `/temat/...` i zanim onboarding wygeneruje
więcej danych do migracji — okno jest teraz, nie za miesiąc.

### 1.2. Wpis ma DOKŁADNIE JEDEN temat, nie wiele

`database/migrations/2026_09_06_100000_create_topics_tables.php:90-97` —
`posts.topic_id` to zwykły `foreignUuid(...)->nullable()->nullOnDelete()`,
nie tabela pośrednia. Migracja to komentuje wprost: „Wpis należy do JEDNEGO
tematu (...) Gdyby kiedyś okazało się, że jeden temat to za mało, tabela
pośrednia doda się bez utraty danych." SPEC poprawnie projektuje tagi jako
relację wiele-do-wielu (do 5) — to jest zgodne z tym komentarzem, nie
sprzeczne. Ale to oznacza, że **migracja z Tematu na Tagi zmienia kardynalność
danych z 1 na 0..5**, a SPEC §1.1 nie mówi nic o tym, co zrobić z istniejącymi
wartościami `posts.topic_id`, jeśli jakiekolwiek wpisy już go mają (patrz 1.4
niżej).

### 1.3. Tematów jest dokładnie 30, aktywnych wszystkich 30

`database/seeders/TopicSeeder.php` — stała lista 30 pozycji, brak nieaktywnych
w seedzie (kolumna `is_active` istnieje w schemacie, ale seeder jej nie
nadpisuje przy ponownym uruchomieniu — świadomie, żeby ręcznie wycofany temat
zostawał wycofany). To potwierdza liczbę z SPEC §1.9 („kilkanaście/kilkadziesiąt
tagów startowych z `is_seeded=true`") jako rozsądną kontynuację, nie zmyśloną.
Onboarding (`OnboardingController::interests()`) i widok listy 30 checkboxów
— to jest realny wzorzec UI do skopiowania dla „prostej listy tagów
startowych", nie coś do projektowania od zera.

### 1.4. Prawdopodobny brak — ale niesprawdzony — stan produkcyjnych danych

Nie mam dostępu do bazy produkcyjnej, więc **nie potwierdzam**, czy istnieją
już wiersze w `topic_follows` albo wpisy z niepustym `topic_id`. Fakty
pośrednie za tym, że ryzyko jest niskie, ale niezerowe:

- `README.md` (u szczytu repo): „72 testy" i status „działająca aplikacja",
  bez wzmianki o rzeczywistych kontach produkcyjnych — obraz wczesnej,
  zamkniętej bety, spójny z „20–50 kont" z briefu zadania.
- Funkcja Temat #31 część B (onboarding zapisujący do bazy) działa dopiero
  od wczoraj — każde konto założone PRZED tą godziną nie mogło jej użyć,
  a każde założone PO mogło.

**Rozbieżność ze specyfikacją**: SPEC §1.1 każe migracji/komendzie sprawdzić
przed zniszczeniem, „czy nie zawierają rekordów" — mówi to o „starych
tabelach/kolumnach" ogólnie, ale nie wymienia wprost `posts.topic_id` jako
kolumny do sprawdzenia osobno od `topic_follows`. To jest luka do domknięcia
w implementacji: sprawdzenie musi objąć **trzy** rzeczy, nie dwie —
`SELECT count(*) FROM topic_follows`, `SELECT count(*) FROM posts WHERE
topic_id IS NOT NULL`, i (przy założeniu, że dojdzie do tego konta zdążyły
coś opublikować) ewentualne dane w innych miejscach odwołujące się do
`topics.id`. Sam pomysł przerwania migracji przy niezerowym wyniku jest
słuszny — ale warto, żeby raport dla właściciela to nazwał wprost, bo
„sprawdź, czy tabele są puste" i „sprawdź, czy kolumna na wpisach jest pusta"
to w praktyce dwa różne zapytania łatwe do pominięcia przy pisaniu jednej
migracji.

### 1.5. Wyszukiwarka: `pg_trgm` + `unaccent` już istnieją i są zahartowane w bojach

`app/Domain/Search/SearchQuery.php` i
`database/migrations/2026_09_05_001300_fix_search_indexes.php` — jest funkcja
`public.kuking_normalize(text)` = `unaccent(lower($1))`, `IMMUTABLE`,
z GIN-owymi indeksami trigramowymi na dokładnie tym wyrażeniu. Komentarz w
migracji opisuje **dwie realne pułapki**, które już raz kosztowały czas:
(a) indeks na wyrażeniu musi być identyczny z wyrażeniem w zapytaniu, inaczej
PostgreSQL robi Seq Scan; (b) od PostgreSQL 17 `CREATE INDEX`/`REINDEX`
działają z ograniczonym `search_path`, więc `unaccent(...)` w ciele funkcji
SQL musi być kwalifikowane `public.unaccent(...)`, inaczej budowanie indeksu
pada dopiero na produkcyjnej wersji Postgresa (wyszło na CI dopiero na
`postgres:18`). **To jest rozbieżność w drugą stronę niż zwykle**: SPEC §1.5
pisze ostrożnie „Jeżeli PostgreSQL w projekcie może używać `pg_trgm`" — owszem
może, i już go używa produkcyjnie, z gotową, przetestowaną funkcją do
ponownego użycia. Nie trzeba tego projektować — trzeba dopisać kolejne
`CREATE INDEX ... USING gin (kuking_normalize(name) gin_trgm_ops)` na `tags`
tą samą funkcją.

Osobna, ważna rozbieżność koncepcyjna: `kuking_normalize()` **usuwa
diakrytyki** (`unaccent`). SPEC §1.2 wymaga jednocześnie: (a) „porównanie
nazw odbywa się po znormalizowanej postaci Unicode" i (b) „nie uznawać
automatycznie `zurek` i `żurek` za ten sam tag". Te dwa wymagania są zgodne
**tylko jeśli** `tags.normalized_name` (unikalność, „czy to jest już ten sam
tag") liczy się inną funkcją niż `kuking_normalize()` (wyszukiwanie/ranking
podobieństwa) — normalizacja do unikalności może co najwyżej: NFC, `lower()`,
przycięcie białych znaków, redukcja wielokrotnych spacji, **bez** `unaccent`.
`kuking_normalize()` służy wyłącznie do rankingu/podpowiedzi, gdzie „zurek"
ma **znaleźć** „żurek" (to jest cel wyszukiwarki), ale nie ma **stać się** tym
samym tagiem automatycznie (to jest wymóg tagów). SPEC tego rozróżnienia nie
nazywa wprost — warto to zapisać jako jawną decyzję implementacyjną, bo
pomylenie tych dwóch funkcji w jednym miejscu kodu jest łatwym błędem.

### 1.6. `ingredients` to gotowy wzorzec tabeli-słownika, którego SPEC nie cytuje

`database/migrations/2026_09_05_000400_create_recipes_tables.php:108-113`:

```php
Schema::create('ingredients', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->string('canonical_name', 160);
    $table->string('normalized_name', 160)->unique();
    $table->timestampTz('created_at')->useCurrent();
});
```

To jest dokładnie kształt, którego `tags` potrzebuje: UUID, nazwa kanoniczna
+ nazwa znormalizowana z unikalnością na tej drugiej. SPEC §1.3 proponuje ten
sam kształt niezależnie — dobra wiadomość, to nie jest rozbieżność, tylko
potwierdzenie, że model z SPEC pasuje do istniejącej konwencji. Warto to
nazwać repozytorium właścicielowi jako argument „za" (nie trzeba wymyślać
nowego wzorca, trzeba powtórzyć istniejący).

### 1.7. Konwencja kluczy: UUID dla encji publicznych, `bigserial` dla wierszy nigdy nie adresowanych z zewnątrz

`docs/DATABASE.md` (sekcja `product_signals`) i komentarz w
`2026_09_06_220000_create_product_signals_table.php`: `id` jest `bigserial`,
**nie** `uuid`, bo „wiersz `product_signals` nigdy nie jest adresowany
z zewnątrz ani pokazywany człowiekowi — to ten sam przypadek co `audit_log`".
`docs/DATABASE.md` §„Zasady": „UUID dla publicznych encji". To jest kryterium
rozstrzygające dla typów kluczy w modelu tagów (patrz §3 niżej) — SPEC mówi
tylko „dopasować typy kluczy do konwencji repozytorium", nie podaje samego
kryterium; ono jest w kodzie i w `docs/DATABASE.md`, więc warto je zacytować
wprost przy każdej z sześciu proponowanych tabel.

### 1.8. `recipe_slug_redirects` to gotowy wzorzec przekierowania po scaleniu/zmianie

`database/migrations/2026_09_05_000400_create_recipes_tables.php:90-95` +
`app/Http/Controllers/RecipeController.php:188` — osobna tabela
`(slug PRIMARY KEY, recipe_id FK, created_at)`, sprawdzana przy nietrafionym
odczycie po slugu. SPEC §1.8 pkt 6 chce „stara strona tagu ma przekierowywać
do kanonicznej" — ale model z SPEC (`tags.status='merged'` +
`merged_into_tag_id`, wiersz **nie kasowany**) w rzeczywistości **nie
potrzebuje** osobnej tabeli redirectów, bo — w odróżnieniu od przepisu, gdzie
stary wiersz znika i slug trzeba by inaczej zwolnić — stary tag zostaje
w `tags` z własnym slugiem. `TagController::show` może po prostu sprawdzić
`status === 'merged'` i zrobić redirect na `merged_into_tag_id->slug`. To jest
uproszczenie względem `recipe_slug_redirects`, nie kopiowanie go — ale warto
wiedzieć, że ten wzorzec istnieje i **dlaczego tagi go nie potrzebują** (żeby
ktoś przy implementacji nie dodał niepotrzebnej siódmej tabeli).

### 1.9. Formularz wpisu: mechanizm ratowania zdjęć już istnieje i jest gotowym wzorcem dla tagów

`app/Http/Controllers/PostController.php:47-160` — zdjęcia są wgrywane
**przed** resztą walidacji (`zebranZdjecia()`), a przy błędzie walidacji
wracają jako ukryte pola `media_ids[]` (`wejscieBezPlikow()`,
`resources/views/pages/posts/create.blade.php:16-40`). Komentarz w kodzie
wprost cytuje wymóg „poprawne dane nigdy nie znikają" (ten sam, który SPEC
przywołuje jako §0 pkt 10). To jest dokładnie mechanizm, którego potrzebują
tagi po nieudanej walidacji — **nie trzeba go projektować od zera**, trzeba
dodać analogiczne ukryte pola `tag_ids[]` obok `media_ids[]` i przepuszczać je
przez tę samą ścieżkę `wejscieBezPlikow()`/`withInput()`. SPEC §1.6 tego nie
cytuje, choć dokładnie o to prosi we wstępie („tagi mają być ratowane tym
samym mechanizmem").

Luka, której SPEC nie domyka: formularz bez JS ma mieć wewnątrz siebie trzy
osobne submity („Znajdź tag", „Dodaj" przy każdej propozycji, „Usuń" przy
każdym wybranym tagu) **w tym samym formularzu**, co "Opublikuj". Dziś
`PostController::store()` przy każdym POST-cie od razu próbuje wgrać
zdjęcia i opublikować wpis. Trzeba rozróżnić „to był pośredni krok
(szukanie/dodawanie/usuwanie tagu)" od „to jest właściwa publikacja" —
najprościej przez odczyt nazwy naciśniętego przycisku (`$request->has('szukaj_tagu')`
itp.) i wczesny return z ponownym renderem formularza **bez** uruchamiania
pełnej walidacji treści/widoczności. To jest konkretna praca implementacyjna
nieopisana w SPEC — warto ją nazwać, żeby nie odkryto jej dopiero w trakcie
kodowania.

### 1.10. Sitemap nie wspomina Tematów w ogóle

`app/Http/Controllers/SitemapController.php` i
`resources/views/sitemap.blade.php` — zero wystąpień słowa „topic". Strony
tematów **nie są dziś celowo inwestowane w SEO** (nie ma ich w sitemapie).
Znaczy to, że argument „tracimy strony tematów jako SEO" — który SPEC każe
mi ocenić — jest w praktyce **słabszy, niż mógłby się wydawać**: nie ma
świadomej inwestycji do stracenia, bo tej inwestycji jeszcze nie zrobiono.
(Strony mogą być incydentalnie indeksowane przez linki wewnętrzne — tego nie
sprawdzam, bo wymagałoby to danych z Google Search Console, których nie mam.)

### 1.11. `docs/DATABASE.md` wprost odkłada `tags` na „V1/V2 — Później"

`docs/DATABASE.md`, sekcja „V1/V2": `tags` jest wymienione obok `groups`,
`meal_plans`, `subscriptions` jako coś **poza MVP**. SPEC nie wspomina o tej
kolizji z zapisanym planem — nie jest to argument przeciw wdrożeniu teraz
(właściciel ma prawo zmienić priorytety), ale AGENTS.md §6 wymaga
aktualizacji `docs/DATABASE.md` przy **każdej** zmianie schematu, a przy
tej zmianie trzeba będzie też przenieść `tags` z sekcji „Później" do sekcji
MVP i skasować `topics` z opisu — inaczej dokumentacja i kod rozjadą się od
pierwszego dnia.

### 1.12. AGENTS.md już sankcjonuje „tagowanie" jako zastosowanie AI

`AGENTS.md` §9 („AI w produkcie"): „tagowanie" jest wprost wymienione jako
jedno z zastosowań AI w produkcie, obok OCR i skalowania porcji. To znaczy,
że AI-wspomagane sugerowanie tagów (§1.7, poza moim zakresem) nie jest
scope creepem względem architektury projektu — jest już przewidziane. Nie
wpływa to na moje rekomendacje w §1.1–1.10, ale odpowiada na pytanie
„czy to pasuje do tego kodu" z brief zadania: tak, pasuje.

### 1.13. Brak jakiejkolwiek istniejącej infrastruktury wulgaryzmów

`grep -ril "wulgar|profanity|blacklist|banned_word"` w `app/`, `config/`,
`database/`, `resources/` — zero wyników. To pole jest całkowicie zielone,
nie ma nic do zachowania ani do migrowania.

## 2. Decyzja główna: zamiast / obok / trzecia droga

**Rekomendacja: zamiast — ale z jawnym uznaniem kosztu i z zachowaniem
funkcji Tematu jako podzbioru tagów, nie jako osobnego bytu.**

To jest w istocie to, co SPEC §1.1 już mówi („Jeśli potrzebne jest oznaczenie
tagów przygotowanych przez właściciela serwisu, ma to być wewnętrzny atrybut
tagu, niewidoczny jako osobny system") — czyli SPEC **już wybrała** wersję
„trzeciej drogi" wewnątrz decyzji „zamiast": `is_seeded=true` na tagu pełni
dokładnie rolę dzisiejszego Tematu (kontrolowana lista redakcyjna do
onboardingu i cold-startu), tylko bez osobnej tabeli i osobnego pojęcia w UI.
Zgadzam się z tym kierunkiem z dwóch powodów:

1. **Jeden system jest tańszy w utrzymaniu na zawsze**, nie tylko teraz. Dwa
   równoległe systemy taksonomii („Temat" redakcyjny + „Tag" użytkownika)
   to gwarantowany dryf: ktoś kiedyś zapyta „dlaczego wpis może mieć temat
   ORAZ tagi, i czy strona tematu i strona tagu to to samo", i odpowiedzi nie
   będzie.
2. **Koszt jest dziś najniższy, jaki kiedykolwiek będzie.** Temat ma 30
   wierszy redakcyjnych i (najpewniej) zero albo bardzo mało prawdziwych
   przypisań na wpisach. Za miesiąc, przy prawdziwych 50 kontach i realnym
   użyciu `topic_follows`/`posts.topic_id`, ta sama migracja będzie musiała
   obsłużyć prawdziwe utracone dane, a nie hipotetyczne.

Co realnie tracimy i ile to kosztuje:

- **Kontrola jakości pierwszego feedu.** Temat dawał zamkniętą listę 30
  pozycji — nie da się jej „zaspamować" wariantami pisowni. Otwarte tagi
  bez seeda dają dokładnie ten efekt, przed którym ostrzega komentarz
  w `TopicSeeder.php` („wolne tagi rozsypują się natychmiast: zakwas / na
  zakwasie / chleb zakwas / ZAKWAS"). **To ryzyko jest realne i SPEC je
  rozwiązuje** przez seed 1200 tagów + aliasy + `is_seeded` jako
  ukryty odpowiednik redakcyjności — pod warunkiem, że seed rzeczywiście
  powstanie **przed** otwarciem tagowania szerszej grupie, a nie „przy
  okazji". To jest krytyczna zależność kolejności, którą warto nazwać
  właścicielowi wprost: **otwarte tagowanie bez gotowego seeda i aliasów to
  powrót dokładnie do problemu, który Temat miał rozwiązać.**
- **Onboarding.** Prosty checkbox 30 pozycji zamienia się na prosty checkbox
  „kilkanaście/kilkadziesiąt" pozycji `is_seeded=true` — utrata jest zerowa,
  jeśli lista startowa jest równie starannie dobrana jak `TopicSeeder::TEMATY`
  (30 pozycji, ręcznie ułożona kolejność, opisy). Koszt: przepisanie
  `OnboardingController::interests()`/`saveInterests()` z `Topic` na `Tag`
  — mechanicznie proste, bo kod już istnieje jako wzorzec do skopiowania.
- **Strony tematów w SEO.** Jak ustalono w §1.10, sitemap nie inwestuje w to
  dziś — więc nie ma czego bronić. Strony tagów (`/tag/{slug}`) przejmują tę
  rolę i mogą wejść do sitemapu od razu, na tych samych zasadach.
- **Migracja danych i przekierowania starych adresów.** Jeśli
  `posts.topic_id` ma dziś niezerowe wartości (niepotwierdzone, patrz §1.4),
  migracja musi: dla każdego wpisu z `topic_id IS NOT NULL` utworzyć wiersz
  w pivot `post_tag` wskazujący na odpowiedni tag `is_seeded=true` o tej samej
  nazwie/slugu co temat (mapowanie 1:1 na starcie, bo `TopicSeeder` i seed
  tagów startowych będą się w dużej mierze pokrywać tematycznie), **zanim**
  kolumna `topic_id` zostanie skasowana. `/temat/{slug}` powinno przekierować
  na `/tag/{slug}` dla tych samych 30 slugów (tania, jednorazowa tabelka albo
  nawet stały routing 30 wpisów w `routes/web.php`, bo lista jest zamknięta
  i znana — nie trzeba do tego generycznego mechanizmu redirectów).

**Trzecia droga rozważona i odrzucona jako osobny byt:** „tagi otwarte +
wąska lista tematów promowanych jako PODZBIÓR tagów" — to jest dokładnie to,
co SPEC już projektuje przez `is_seeded`/`internal_category`. Nie ma powodu
robić z tego oddzielnej rekomendacji, bo SPEC już tam doszła; jedyna zmiana,
jaką proponuję, to nazwanie tego wprost właścicielowi jako świadomej
kontynuacji Tematu, a nie jako czystego usunięcia — to zmienia wewnętrzną
narrację PR-a i commitów (SPEC §0 pkt 9 wymaga polskich nazw i uczciwych
komunikatów; ta sama uczciwość dotyczy opisu commitu: „zastępujemy system
Tematów tagami, przenosząc jego funkcję cold-startu do `is_seeded`", nie
„usuwamy nieużywaną funkcję").

## 3. Model danych — rekomendacja z uzasadnieniem

**Rekomendacja: budować teraz cztery tabele, nie sześć. Odłożyć
`tag_relations` i `tag_merge_suggestions` — obie są czysto addytywne
(żadna istniejąca tabela nie dostaje FK do nich), więc dodanie ich później
nigdy nie wymaga migracji łamiącej dane.**

### Budować teraz

| Tabela | Klucz | Uzasadnienie |
|---|---|---|
| `tags` | `uuid` | Encja publiczna: ma slug, ma własną stronę, jest linkowana. Dokładnie kryterium z `docs/DATABASE.md` („UUID dla publicznych encji") i dokładnie kształt `ingredients` (`canonical_name` + `normalized_name unique`, patrz §1.6). |
| `tag_aliases` | `bigserial` | **Nigdy nie jest adresowana z zewnątrz** — alias nie ma własnej strony (SPEC §1.3 wprost: „Alias nie tworzy osobnej publicznej strony"), więc kryterium z `product_signals`/`audit_log` (bigserial dla wierszy nieadresowanych) stosuje się tu wprost. FK `tag_id` do `tags.id` (uuid) — typ klucza obcego nie musi być tego samego typu co PK wskazywanej tabeli w PostgreSQL/Laravel, więc to nie jest przeszkodą. |
| `post_tag` → **rekomendacja: `post_tags`** | bez własnego `id`, `PRIMARY KEY(post_id, tag_id)` | Sama SPEC każe pilnować unikalności pary — to jest dokładnie wzorzec `post_media` (`PRIMARY KEY (post_id, media_id)`) i `topic_follows` (`PRIMARY KEY (user_id, topic_id)`) w tym repo: żadna z tabel pośrednich w `docs/DATABASE.md` nie ma osobnego `id`. Nazwa: repo konsekwentnie używa liczby mnogiej dla tabel-pivotów nazwanych od encji-właściciela (`post_media`, nie `post_medium`) — `post_tag` (liczba pojedyncza, domyślna konwencja Laravela) łamie tę lokalną konwencję bez powodu; `post_tags` jest spójne. |
| `tag_follows` | bez własnego `id`, `PRIMARY KEY(user_id, tag_id)` | Jeden do jednego z `topic_follows` — ta sama nazwa kolumn, ten sam kształt klucza głównego, ten sam powód (bez osobnego `id`, bo to relacja, nie encja — komentarz w `2026_09_06_100000_create_topics_tables.php:78-82` tłumaczy to wprost i argument przenosi się bez zmian). |

### Odłożyć na później

- **`tag_relations`** — przedwczesna. Przy 20–50 kontach i garści wpisów
  dziennie nie ma jeszcze danych, z których jakikolwiek `weight` miałby
  sens; ręcznie wpisane relacje `seed` (sernik↔ciasto) da się na start
  zaszyć w prostszej formie: stały plik konfiguracyjny/seeder z listą par,
  czytany przy podpowiedziach, **bez osobnej tabeli z `weight`/`source`/
  timestampami do utrzymywania**. Gdy (i jeśli) pojawi się prawdziwy sygnał
  współwystępowania tagów na wpisach, „powiązane tagi" da się policzyć
  **w locie** zapytaniem po `post_tags` (`SELECT tag_id, count(*) ... GROUP BY
  ... JOIN post_tags on post_id WHERE other_tag_id = ?`) — dokładnie tak, jak
  `SearchQuery`/`RecipeController` liczą już dziś `withCount('cookedEvents')`
  zamiast trzymać osobny licznik. AGENTS.md §3 („Zakaz overengineeringu")
  wymaga „zmierzonej, udokumentowanej potrzeby" przed dołożeniem
  mechanizmu — tej potrzeby dziś nie ma.
- **`tag_merge_suggestions`** — jej **jedyny** sens istnienia w SPEC to
  bycie skrzynką odbiorczą dla AI (§1.8 pkt 5–6: „zadanie asynchroniczne
  może poprosić `gpt-5.6-luna`... AI tworzy wpis w `tag_merge_suggestions`"),
  a to jest wprost poza moim zakresem (inny agent). Sama **operacja scalania**
  (transakcyjna, opisana w §1.8 „Operacja scalenia") nie wymaga tej tabeli —
  wymaga tylko akcji domenowej (`MergeTags::handle($source, $target, $admin)`)
  wywoływanej ręcznie przez administratora z panelu, bez kolejki sugestii.
  Przy 20–50 kontach właściciel może sam zauważyć `sernik`/`serniki` na
  liście tagów i kliknąć „Scal" — kolejka sugestii rozwiązuje problem skali,
  którego jeszcze nie ma. Rekomendacja: zbudować `MergeTags` teraz (patrz §7),
  dodać `tag_merge_suggestions` dopiero razem z pipeline'em AI, gdy ten
  faktycznie powstanie — to jest dodanie jednej tabeli bez żadnej migracji
  ruszającej istniejące dane.

**Koszt tej rekomendacji**: dwie tabele mniej do napisania, przetestowania
i udokumentowania teraz (AGENTS.md §6: migracja + test + `docs/DATABASE.md`
+ opis rollbacku za **każdą** tabelę — to nie jest darmowe). **Jak się psuje**:
jeśli za pół roku pojawi się prawdziwa potrzeba `tag_relations` z wagami
uczonymi z danych, dochodzi się do niej nową migracją `CREATE TABLE`, zero
ryzyka dla istniejących wierszy. **Odwracalność**: pełna w obie strony —
obie odłożone tabele można dodać później bez dotykania `tags`/`tag_aliases`/
`post_tags`/`tag_follows`.

### Uwaga do pól `tags`

`merged_into_tag_id` — self-referencing FK, powinien być `nullable()`
z `nullOnDelete()` **lub** lepiej bez `ON DELETE` w ogóle (skoro §1.8 mówi
„nie kasować źródłowego tagu twardo", docelowy tag też nigdy nie powinien
zniknąć spod scalonego — ewentualne usunięcie tagu kanonicznego powinno być
zablokowane na poziomie aplikacji, dopóki są do niego przypięte scalone tagi,
tak jak `Topic` blokuje dezaktywację przez UI, ale nie usuwanie z bazy).
CHECK analogiczny do `posts_display_mode_check`: `status IN ('active',
'hidden','merged')` w bazie, nie tylko w PHP (AGENTS.md §6).

## 4. Ograniczenia tagu — konkretne liczby

**Rekomendacja: przyjąć liczby z SPEC bez zmian — 5 tagów/wpis, 2–30 znaków,
opcjonalność, redukcja białych znaków — dopisując jedną doprecyzowaną
regułę normalizacji.**

- **5 tagów / wpis** — dobra liczba dla formularza pisanego kciukiem: dość,
  żeby dodać `sernik`, `ciasta`, `święta`, `dla dzieci`, `po babci` naraz,
  za mało, żeby stać się polem na słowa kluczowe SEO wklejone hurtem.
  Egzekwowanie w warstwie domenowej (SPEC to wymaga) ma gotowe miejsce:
  `App\Domain\Posts\Actions\PublishPost::handle()` (`app/Domain/Posts/Actions/PublishPost.php`)
  już rzuca `BladDlaCzlowieka` dla złych danych wejściowych (np. brak
  zdjęcia i tekstu) — dodanie `count($tagIds) > 5` do tej samej metody jest
  spójne z istniejącym wzorcem, nie nowym mechanizmem.
- **30 znaków max, 2 min** — akceptuję. Minimum 2 znaki jest już dokładnie
  progiem używanym w wyszukiwarce (`SearchQuery::recipes()`/`people()`:
  `if (mb_strlen($phrase) < 2) { return new Collection; }`) — spójność
  z istniejącym kodem, nie przypadek do kwestionowania.
- **Normalizacja do unikalności — jedna zmiana względem literalnego czytania
  SPEC**: `tags.normalized_name` powinno powstawać z `mb_strtolower(trim(...))`
  + redukcja wielokrotnych białych znaków + normalizacja Unicode NFC, **bez
  `unaccent`**. `Sernik`, ` sernik `, `SERNIK` → jeden tag (wymóg z §1.16 pkt
  4) — to działa samym `lower()`+`trim()`, bez potrzeby unaccent. `zurek`
  i `żurek` **muszą pozostać różnymi wpisami w `tags`** (wymóg z §1.2) —
  gdyby normalizacja do unikalności używała `kuking_normalize()` (który robi
  `unaccent`), `zurek` i `żurek` kolidowałyby na `UNIQUE(normalized_name)`
  i drugi z nich nigdy by nie powstał jako osobny tag, co łamie ten wymóg
  wprost. `kuking_normalize()` ma być użyty **wyłącznie** do indeksu
  trigramowego służącego wyszukiwaniu/podpowiedziom (§5), nie do unikalności
  kanonicznej. To jest jedyna korekta, jaką wnoszę do §1.2 — reszta liczb
  i reguł jest do przyjęcia bez zmian.
- Limit tagów **na wpis** liczony jest po unikalnych `tag_id`, nie po
  wpisanych frazach — to samo „bramka własności/unikalności liczona na
  sumie, nie osobno na każdej drodze" co już stosuje `PostController::zebranZdjecia()`
  dla zdjęć (`count($wszystkie) > LimityZdjec::maksZdjecNaWysylke()` liczone
  na połączonym zbiorze nowych i odzyskanych). Rekomenduję analogiczną klasę
  `App\Support\LimityTagow` (wzorem `LimityZdjec`) trzymającą `5`, `30`, `2`
  jako pochodne `config('kuking.tags.*')` — nie liczby wpisane wprost
  w kontrolerze, zgodnie z AGENTS.md §7 („Limity zapytań są w
  `config/kuking.php`, nie rozsiane po trasach") i z historią błędu opisaną
  w komentarzu `LimityZdjec.php` (ta sama liczba przepisana ręcznie w pięciu
  miejscach rozjechała się kiedyś w praktyce).

## 5. Podpowiadanie bez AI

**Rekomendacja: `pg_trgm` na `kuking_normalize(name)` (i tak samo na
`normalized_alias`) wystarcza w zupełności — to jest dokładnie ten sam
mechanizm, który już dziś obsługuje wyszukiwanie przepisów i profili przy
5000 kontach / 10000 przepisach w ok. 14 ms (zmierzone, cytat w
`SearchQuery.php`). Przy 1200 tagach i 20–50 kontach koszt jest
nieistotny.**

Kształt zapytania (jeden `UNION ALL`, nie `OR` — z tego samego powodu, który
`SearchQuery.php` opisuje na poważnie zmierzonym przykładzie: PostgreSQL nie
składa planu z kilku indeksów pod jednym warunkiem `OR`):

```sql
SELECT id, name, slug FROM tags WHERE status='active' AND kuking_normalize(name) LIKE ?||'%'  -- prefiks nazwy
UNION ALL
SELECT t.id, t.name, t.slug FROM tag_aliases a JOIN tags t ON t.id=a.tag_id
    WHERE t.status='active' AND kuking_normalize(a.alias) = ?                                  -- dokładny alias
UNION ALL
SELECT id, name, slug FROM tags WHERE status='active' AND kuking_normalize(name) % ?           -- podobieństwo trgm
ORDER BY <priorytet gałęzi>, similarity(kuking_normalize(name), ?) DESC
LIMIT 8
```

**Popularność** — rekomenduję **nie** dodawać licznika utrzymywanego ręcznie
(`tags.usage_count` inkrementowany przy każdym dodaniu/usunięciu tagu z
wpisu). To jest dokładnie klasa błędu, przed którą ostrzega komentarz
w `LimityZdjec.php` (dwie kopie tej samej liczby w różnych miejscach
się rozjeżdżają) — tu byłyby to dwa miejsca aktualizujące licznik (dodanie
tagu, usunięcie tagu, scalenie tagów) plus edycja wpisu. Zamiast tego:
`withCount('posts')` przy zapytaniu podpowiedzi, dokładnie jak
`RecipeController`/`SearchQuery` robią już dziś dla `cookedEvents` i
komentarzy (`->withCount(['comments' => fn ($q) => ...])`). Przy setkach, nie
milionach wierszy w `post_tags`, koszt `COUNT` w podzapytaniu jest
nieistotny; gdy (jeśli) to się kiedyś zmieni, jest to jedna linijka do
zamiany na kolumnę z pomiarem uzasadniającym zmianę — nie odwrotnie.

**Nie zabić bazy przy każdym naciśnięciu klawisza**: repo ma już gotowy,
konfigurowalny mechanizm throttle per-akcja —
`config('kuking.limits')` + `routes/web.php` (`throttle:{$limits['search']},search`
z `'search' => '60,1'` w `config/kuking.php`). Rekomendacja: dodać klucz
`'tag_suggest' => '60,1'` (albo dzielić limit z `search`, jeśli to ten sam
budżet zapytań na konto) i podpiąć tą samą składnią middleware —
**nie** wymyślać nowego mechanizmu limitowania dla tego jednego endpointu.
Debounce 200 ms po stronie JS jest sensowny jako pierwsza linia obrony
(mniej requestów w ogóle), throttle serwerowy jest drugą, niezależną od
tego, czy klient w ogóle uruchamia JS.

## 6. Ścieżka bez JavaScriptu

**Ocena: podejście z SPEC (pełny postback, przycisk „Znajdź tag", zwykłe
przyciski formularza „Dodaj"/„Usuń") jest realne dla osoby 50+ na telefonie —
jest to ten sam styl interakcji, jaki cały formularz wpisu już ma
(`resources/views/pages/posts/create.blade.php`: `<details><summary>Dodaj
temat</summary>` + zwykły `<select>`, cały formularz to jeden `<form
method="POST">` bez ani jednej linijki JS). SPEC nie wymyśla tu nowego wzorca
UX, tylko rozszerza istniejący na przypadek, gdzie lista jest za duża na
`<select>` (1200 tagów vs. 30 tematów).**

Dwie konkretne poprawki, których SPEC nie precyzuje, a które warto dodać:

1. **Zakotwiczenie przewijania po każdym postbacku.** Pełny reload strony po
   „Znajdź tag"/„Dodaj"/„Usuń" przy formularzu, który ma też pole tekstu
   i zdjęcia wyżej, zrzuca osobę z powrotem na górę strony przy każdej z
   potencjalnie kilku rund (szukaj → dodaj → szukaj kolejny → dodaj...).
   To jest dokładnie ten typ szczegółu, o który ten kod już dba gdzie
   indziej (np. `open` na `<details>` tematu warunkowane przez `old('topic_id')`,
   żeby sekcja nie zwijała się z powrotem po błędzie). Rekomendacja:
   przekierowanie po akcji na tagu powinno nieść fragment `#sekcja-tagow`
   (`return back()->withFragment('#tagi')` albo równoważnik w URL), żeby
   przeglądarka wróciła w miejsce, gdzie ta osoba faktycznie pracuje, a nie
   na górę formularza z tekstem i zdjęciami nad nim.
2. **Rozróżnienie "akcja pośrednia" od "publikacja".** Opisane w §1.9 —
   `PostController::store()` musi rozpoznać, że POST z naciśniętym „Dodaj
   tag" nie jest próbą publikacji, i odesłać z powrotem sam formularz
   (z zachowanym tekstem, zdjęciami przez istniejący mechanizm `media_ids`,
   i zaktualizowaną listą tagów), **bez** uruchamiania walidacji `body`/
   `visibility`, które na tym etapie mogą być jeszcze puste/niedokończone.
   To nie jest trudne, ale nie jest też „za darmo" — jest to nowa gałąź
   w kontrolerze, której dziś nie ma (dziś jest jeden tryb: waliduj i albo
   publikuj, albo odrzuć).

Testy odbiorowe z §1.16 pkt 7–9 i 14 (obserwowanie bez JS, zdjęcie przeżywa
znalezienie/dodanie/usunięcie tagu, awaria Luny nie blokuje publikacji, brak
JS nie blokuje podpowiedzi AI) są dobrze dobrane i odpowiadają dokładnie
temu, co ten kod już dziś udowadnia dla zdjęć — nie dodawałbym ani nie
usuwał żadnego z nich.

## 7. Scalanie tagów

**Ocena: proces z §1.8 jest bezpieczny co do kierunku (transakcyjny, bez
twardego kasowania, z zachowaniem aliasu) — ale specyfikacja pomija dwa
konkretne miejsca kolizji, które trzeba domknąć w implementacji, nie
w projekcie.**

1. **Kolizja w `tag_follows`, nie tylko w `post_tags`.** SPEC §1.8 „Operacja
   scalenia" pkt 2 mówi „usunąć kolizje pivotu bez tworzenia duplikatów" —
   ale to zdanie stoi przy `post_tag`, a pkt 3 („przepiąć obserwacje
   użytkowników") nie powtarza tego samego zastrzeżenia dla `tag_follows`.
   Jeśli użytkownik obserwuje **oba** tagi (źródłowy i docelowy) przed
   scaleniem, proste `UPDATE tag_follows SET tag_id = :target WHERE tag_id =
   :source` uderzy w `UNIQUE(user_id, tag_id)` i cała transakcja się cofnie.
   Wzorzec do skopiowania już istnieje w tym repo:
   `TopicFollowController::follow()` używa `syncWithoutDetaching()` z
   dokładnie tym uzasadnieniem („Obserwuj bywa klikane dwa razy... klucz
   główny na parze zamieniłby drugie kliknięcie w błąd bazy zamiast w nic").
   Operacja scalenia powinna robić odpowiednik `INSERT ... ON CONFLICT DO
   NOTHING` dla `tag_follows`, nie zwykły `UPDATE`.
2. **Blokada współbieżności.** Dwa równoległe requesty (dwóch adminów, albo
   admin + zadanie w tle) scalające tę samą parę tagów, albo scalenie
   biegnące w tym samym momencie, co ktoś tagujący właśnie wpis tagiem
   źródłowym — SPEC nie mówi nic o blokadzie. Rekomendacja: `MergeTags`
   działa w jednej transakcji z `SELECT ... FOR UPDATE` na obu wierszach
   `tags` (source i target) na starcie — to jest ten sam rodzaj wymogu
   atomowości, jaki SPEC sama stawia gdzie indziej (§1.13: „brak możliwości
   podjęcia dwóch sprzecznych decyzji przez dwa równoległe requesty — użyć
   blokady/warunku atomowego" dla panelu moderacji), więc to nie jest obca
   norma dla tego dokumentu — tylko nie została powtórzona przy scalaniu.

**Odwracalność**: częściowa, i to jest uczciwa ocena, nie luka do naprawienia.
Nie ma opisanego „odwróć scalenie" (guzik „Cofnij"), ale ponieważ SPEC każe
nie kasować twardo źródłowego tagu, tylko oznaczyć go `merged` i zachować
jako alias — **techniczne odwrócenie jest możliwe ręcznie** (admin/dev może
z powrotem ustawić `status='active'`, wyczyścić `merged_into_tag_id`
i ręcznie przepiąć `post_tags`/`tag_follows` z powrotem), tylko nie jest to
operacja jednoklikowa z panelu. Przy 20–50 kontach i decyzjach podejmowanych
ręcznie przez administratora to jest akceptowalny kompromis — dodanie
prawdziwego „Cofnij scalenie" jako guzika w UI jest tanie do dorzucenia
później i nie wymaga zmiany modelu danych (informacja potrzebna do cofnięcia
— który tag był źródłem, kto i kiedy scalił — już jest w SPEC-owym
`tag_merge_suggestions.reviewed_by`/`status`, **jeśli** ta tabela powstanie;
jeśli zostanie odłożona zgodnie z §3, to samo minimum warto zapisać choćby
jednym wpisem w istniejącej `audit_log` zamiast wcale, żeby historia decyzji
administracyjnej z §1.8 pkt 8 nie zniknęła).

Reprezentacja przekierowania starej strony — patrz §1.8 wyżej: nie trzeba
osobnej tabeli, wystarczy sam `tags.status='merged'` + `merged_into_tag_id`,
bo wiersz źródłowy zostaje w bazie ze swoim slugiem.

## 8. Wulgaryzmy i nadużycia

### Lokalna baza wulgaryzmów

**Ocena: schemat (severity × typ × wyjątki kontekstowe) jest tani do
zbudowania i warto go zapisać teraz jako strukturę danych — ale
wyrafinowane wykrywanie obejść, którego SPEC się domaga (normalizacja
zamian znaków, dopasowanie tokenów, obrona przed „contains()"), jest w tej
chwili rozwiązywaniem problemu bez dowodu, że on istnieje.**

Znane pułapki, dla porządku (niepotwierdzone empirycznie dla polskiego —
oznaczam wprost jako wiedzę ogólną o filtrach treści, nie o tym konkretnym
serwisie):

- **Efekt Scunthorpe** — dopasowanie substringu zamiast tokenu blokuje
  niewinne słowa zawierające zakazany ciąg znaków wewnątrz innego słowa
  (klasyczny angielski przykład: nazwa miasta „Scunthorpe" zawiera
  zakazany 4-literowiec). SPEC już to poprawnie adresuje wprost („dopasowanie
  całych tokenów lub jawnych wzorców, nigdy zwykłe `contains()`") — to jest
  dobra, tania reguła i warto ją przyjąć bez zmian.
- **Polska fleksja.** Lista dokładnych tokenów nie złapie odmiany
  (przypadki, zdrobnienia, formy czasownikowe) bez ręcznego wypisania
  wariantów albo reguł wzorców (regex na rdzeniu + typowe końcówki). Pełny
  stemming dla polskiego to osobny, nietrywialny problem inżynierski —
  dokładnie ten rodzaj kosztu, przed którym ostrzega AGENTS.md §3
  („zmierzona, udokumentowana potrzeba" zanim się to zbuduje).
- **Obchodzenie przez zamianę znaków / rozdzielanie spacjami / powtórzenia
  liter.** SPEC wspomina najprostsze podstawienia (`0/o`, `1/i`) i zaznacza,
  żeby robić to „bezpiecznie... jeśli nie powoduje dużej liczby false
  positive" — to jest już właściwa ostrożność wpisana w sam dokument.
  Rozdzielanie spacjami/myślnikami wewnątrz słowa i wielokrotne powtórzenia
  liter SPEC pomija.

**Rekomendacja**: tak, warto zbudować **teraz**, bo tagi są **publiczną
etykietą indeksowaną przez wyszukiwarkę i strony** (wyższa ekspozycja niż
wolny tekst wpisu, SPEC to trafnie odróżnia w ostatnim zdaniu §1.10) —
ale w wersji minimalnej: krótka (rząd wielkości: kilkadziesiąt–sto kilkadziesiąt
pozycji) ręcznie utrzymywana lista dokładnych tokenów po `kuking_normalize()`
(lower + unaccent — tu unaccent akurat pomaga, bo `kurwa`/`kurwa` pisane bez
polskich znaków to wciąż to samo słowo, a ryzyko false-positive z unaccent
przy krótkiej, ręcznie dobranej liście jest niskie), `severity='hard'`
blokujące **tylko tworzenie nowego tagu** (nie tekstu wpisu — to bardziej
błędogenny przypadek i jest już i tak pokryty przez §1.11, poza moim
zakresem). Świadomie **nie** inwestować teraz w: tabelę wariantów
leetspeak, wykrywanie rozdzielania spacjami, ani morfologię polską. Te trzy
rzeczy zostawić jako udokumentowany, ale nie zbudowany, następny krok — do
uruchomienia dopiero, gdy pojawi się pierwszy realny przypadek obejścia.
Przy 20–50 kontach (prawdopodobnie ludziach znanych właścicielowi na starcie
zamkniętej bety) ryzyko celowego, wyrafinowanego obchodzenia filtra jest
bliskie zeru — koszt zbudowania obrony przed nim teraz przewyższa
prawdopodobną szkodę.

Schemat tabeli z SPEC (`normalized_form`, `severity`, `type`, wyjątki
kontekstowe) jest tani i dobrze zaprojektowany jako **struktura** — przyjąć
bez zmian, tylko nie wypełniać go od razu całą wyobrażoną złożonością.

### Nadużycia: 40 tagów, spam, tag jako kanał obraźliwej treści

- **Wpychanie wielu tagów na wpis** — domknięte przez limit 5 w warstwie
  domenowej (§4). Nie potrzeba nic więcej.
- **Tagi-spam** (masowe zakładanie nowych, bezsensownych tagów zamiast
  używania istniejących) — przy 20–50 kontach realny wektor to raczej
  literówki i warianty niż zamierzony spam. Seed 1200 tagów + trafne
  podpowiedzi (§5) same w sobie redukują pokusę tworzenia nowego tagu zamiast
  wybrania istniejącego z listy podpowiedzi — to jest najtańsza obrona,
  tańsza niż jakakolwiek reguła antyspamowa, i już jest częścią projektu.
  Twardy limit tworzenia nowych tagów na konto/dzień (`config`-owalny,
  wzorem reszty limitów) jest tani do dodania i warto go mieć jako siatkę
  bezpieczeństwa, ale nie widzę potrzeby bardziej wyrafinowanego wykrywania
  spamu na tym etapie.
- **Tag jako kanał obraźliwej treści widocznej publicznie** — to jest
  najpoważniejszy z trzech, bo strona tagu jest publiczna i nie wymaga
  interakcji (w odróżnieniu od komentarza, gdzie trzeba wejść pod wpis).
  Lokalny prefilter z §1.11 (poza moim zakresem, ale wymieniony w SPEC jako
  warstwa 0, synchroniczna, bez zależności od API) jest właściwym miejscem
  na tę obronę — działa dokładnie w momencie tworzenia tagu, zanim
  cokolwiek trafi na publiczną stronę. To jest argument za tym, żeby minimalna
  lokalna lista wulgaryzmów (opisana wyżej) istniała **od pierwszego dnia
  tagowania**, nawet zanim jakikolwiek element z §1.11–1.15 (AI) zostanie
  zbudowany — bo sama obecność publicznej strony `/tag/{slug}` bez żadnego
  filtra byłaby furtką, o której SPEC §1.10 słusznie ostrzega.

## 9. Co ze specyfikacji przyjąć bez zmian

- Jedna taksonomia „Tagi" zamiast równoległych pojęć (§1.1) — kierunek
  słuszny, patrz §2.
- Limity 5 tagów/wpis, 2–30 znaków, opcjonalność, redukcja białych znaków,
  dozwolone znaki (§1.2) — poza jedną poprawką normalizacji opisaną w §4.
- Kształt tabel `tags`, `tag_aliases`, pivot, `tag_follows` (§1.3) —
  wystarczy zmienić dwa typy kluczy i nazwę pivotu na `post_tags`, patrz §3.
- Skala i zawartość początkowego seeda (~1200 tagów, 1500–2500 aliasów,
  wymóg normalizacji/dedupu/kolizji slugów/raportu z importu) (§1.4) —
  rozsądne, spójne z tym, jak `TopicSeeder` był budowany (ręcznie ułożona
  lista z uzasadnieniem, nie losowy zrzut).
- Ranking podpowiedzi: prefiks → alias → podobieństwo → popularność →
  obserwowane → powiązane (§1.5) — kolejność sensowna; realizacja przez
  `pg_trgm`/`kuking_normalize()`, patrz §5.
- Wymóg limitu 8 wyników, min. 2 znaki zapytania (§1.5) — spójne z istniejącym
  progiem w `SearchQuery`.
- Ogólny kształt formularza bez JS: pole tekstowe, „Znajdź tag", lista
  propozycji z przyciskami „Dodaj", lista wybranych z przyciskami „Usuń"
  (§1.6) — z dwiema doprecyzowanymi poprawkami z §6.
- Proces scalania krok po kroku (normalizacja → wyszukanie podobnych →
  dokładny alias → kandydaci → (AI poza zakresem) → decyzja administratora)
  i zasada „nie kasować twardo" (§1.8) — z dwiema poprawkami z §7.
- Obserwowanie tagów: publiczna strona, „Obserwuj"/„Przestań obserwować",
  jeden wpis w feedzie mimo wielu obserwowanych tagów, `Pokaż więcej` (§1.9)
  — to jest bezpośrednia kontynuacja `TopicFeed`/`TopicController`, zero
  zmian koncepcyjnych potrzebnych.
- Struktura wpisu w bazie wulgaryzmów: znormalizowana forma, severity, typ,
  wyjątki kontekstowe (§1.10) — jako **schemat**, patrz zastrzeżenie co do
  zakresu wdrożenia w §8.

## 10. Czego nie wiem — pytania do właściciela

1. **Czy w bazie produkcyjnej (czy tam, gdzie ten kod już działa) istnieją
   już wiersze w `topic_follows` albo wpisy z niepustym `posts.topic_id`?**
   Nie mam dostępu do uruchomionej bazy, więc opieram się wyłącznie na dacie
   scalenia kodu (wczoraj) i ogólnym opisie stanu jako zamkniętej bety.
   Odpowiedź na to pytanie decyduje, czy migracja z §2 potrzebuje
   rzeczywistego kroku backfillu, czy tylko sprawdzenia i przerwania
   (SPEC już wymaga tego drugiego — pytanie dotyczy tylko tego, czy trzeba
   też pierwszego).
2. **Czy `is_seeded`/wąska lista promowanych tagów ma zachować identyczne 30
   nazw/slugów co dzisiejsze Tematy, czy to okazja do przeprojektowania
   listy przy okazji?** Wpływa na to, czy przekierowania `/temat/{slug}` →
   `/tag/{slug}` mogą być prostym mapowaniem 1:1, czy potrzebują ręcznej
   tabeli dopasowań.
3. **Czy scalanie manualne (bez `tag_merge_suggestions`, patrz §3) jest
   akceptowalne jako pierwszy krok**, z dołożeniem kolejki sugestii AI
   dopiero razem z resztą pipeline'u moderacji (§1.11–1.15, inny agent)?
   To pytanie jest na styku mojego zakresu i AI — zaznaczam je tu, bo
   odpowiedź zmienia kolejność prac bardziej niż cokolwiek innego w tym
   raporcie.
4. **Jak duży jest realny apetyt na ręczną pracę redakcyjną przy starcie?**
   1200 tagów + 1500–2500 aliasów to nie jest praca, którą da się w pełni
   zautomatyzować bez ryzyka (SPEC sama tego wymaga: normalizacja, dedup,
   filtr wulgaryzmów, raport) — kto realnie przegląda wynikowy raport
   odrzuceń przed uruchomieniem seeda na produkcji?

## 11. Źródła

Wewnętrzne (repozytorium `/workspace/kuking.pl`, odczyt na commicie
`0068de54957ce5395813fbca23dc3b8825e1110e`, 2026‑09‑07):

- `database/migrations/2026_09_06_100000_create_topics_tables.php`
- `app/Models/Topic.php`
- `app/Http/Controllers/TopicController.php`
- `app/Http/Controllers/TopicFollowController.php`
- `app/Domain/Feed/TopicFeed.php`
- `app/Http/Controllers/OnboardingController.php`
- `database/seeders/TopicSeeder.php`
- `app/Domain/Search/SearchQuery.php`
- `database/migrations/2026_09_05_001300_fix_search_indexes.php`
- `database/migrations/2026_09_05_000400_create_recipes_tables.php`
  (tabele `ingredients`, `recipe_slug_redirects`)
- `app/Http/Controllers/RecipeController.php:188`
- `app/Http/Controllers/PostController.php`
- `resources/views/pages/posts/create.blade.php`
- `app/Domain/Posts/Actions/PublishPost.php`
- `app/Support/LimityZdjec.php`
- `config/kuking.php` (sekcja `limits`)
- `routes/web.php` (użycia `throttle:`)
- `app/Http/Controllers/SitemapController.php`, `resources/views/sitemap.blade.php`
- `docs/DATABASE.md`
- `AGENTS.md` (§3, §6, §7, §8, §9, §12)
- `README.md`
- historia git: `git log` dla `eb235fc` (#82), `ce6f48d` (#95), HEAD

Zewnętrzne, przywołane ogólnie (bez weryfikacji konkretnego adresu/daty —
oznaczam jako wiedza ogólna, nie potwierdzone źródło na potrzeby tego
raportu):

- Zjawisko znane jako „efekt Scunthorpe" (filtr treści blokujący
  niewinne słowo zawierające zakazany substring) — powszechnie opisywany
  problem inżynierii filtrów treści, przywołany tu z pamięci ogólnej, **nie**
  z konkretnego zweryfikowanego źródła.
- Zachowanie PostgreSQL wobec `IMMUTABLE`/indeksów na wyrażeniach i
  ograniczonego `search_path` od wersji 17 przy operacjach `CREATE
  INDEX`/`REINDEX` — **potwierdzone w tym repozytorium** przez komentarz
  migracji `2026_09_05_001300_fix_search_indexes.php`, który cytuje
  rzeczywisty błąd odtworzony na CI (`postgres:18`), nie przez zewnętrzną
  dokumentację sprawdzoną osobno na potrzeby tego raportu.
