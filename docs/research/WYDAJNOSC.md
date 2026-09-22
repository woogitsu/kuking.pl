# WYDAJNOSC.md — pomiar wydajności przy pierwszym tysiącu wpisów

> Dokument badawczy, nie plan prac. Wszystkie liczby poniżej pochodzą
> z rzeczywistego `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` na PostgreSQL,
> na dwóch syntetycznych zbiorach danych (100 i 1000 kont), nie z domysłu.
> Tam, gdzie czegoś nie zmierzyłem, jest to napisane wprost w sekcji na końcu
> — nie zgaduję.
>
> **Relacja do `USPRAWNIENIA-2.md`:** zadaniem było zweryfikować B3 i B4.
> Obie usterki **są już naprawione** w kodzie (commity `ee9c24d`/PR #98 dla B3,
> `cfb1000`/PR #99 dla B4) i obie mają dziś testy regresyjne pilnujące kształtu
> zapytania (`tests/Feature/PropozycjeOsobDoObserwowaniaTest.php` dla B4).
> Poniżej — świeży, niezależny pomiar potwierdzający to na nowych danych,
> nie tylko odczyt kodu.

---

## 1. Metoda

### 1.1 Środowisko

- PHP 8.4.19, Laravel 13.30.1.
- **PostgreSQL 16.13** (Ubuntu 16.13-0ubuntu0.24.04.1), nie 18 — mimo że
  opis środowiska tej sesji mówił o wersji 18. `SELECT version()` na porcie
  `127.0.0.1:5432` potwierdza 16.13. Nie zmieniałem tego, tylko odnotowuję:
  wnioski poniżej dotyczą 16.13, produkcja na Railway deklaruje 18 w
  `docs/DATABASE.md`/`AGENTS.md`.
- `shared_buffers = 128MB`, `work_mem = 4MB`, `effective_cache_size = 4GB`
  — to są wartości domyślne kontenera, **nie** konfiguracja z Railway. Nikt
  ich świadomie nie strojił pod ten pomiar.
- Kontener: 4 rdzenie, 15 GiB RAM, dysk współdzielony z resztą sesji (w tym
  samym czasie w katalogu roboczym pracowały równolegle inne agenty AI —
  `composer install` innego agenta rywalizował o to samo łącze podczas
  przygotowania środowiska). To nie jest izolowana maszyna pomiarowa.
- Dwie osobne bazy: `kuking_perf_100` i `kuking_perf_1000`, każda po
  `php artisan migrate` na czystym schemacie — `kuking` i `kuking_test`
  nietknięte.
- **Bufory ciepłe.** Każde zapytanie EXPLAIN uruchamiałem 5 razy pod rząd
  i liczyłem medianę — pierwszy przebieg bywał wolniejszy (dysk), od
  drugiego `Buffers: shared hit=…` bez `read=…` na wszystkich sześciu
  zapytaniach: dane leżały już w `shared_buffers`. To jest pomiar **ciepłego
  cache'u**, nie zimnego startu po restarcie kontenera.
- Wszystkie plany EXPLAIN pochodzą z **prawdziwego SQL-a z prawdziwymi
  bindingami**, złapanego przez `DB::listen()` podczas wywołania tego
  samego kodu domenowego, którego używają kontrolery (`FollowingFeed`,
  `DiscoverFeed`, `SearchQuery`, replika `ProfileController::show()`,
  replika `ModerationController::reports()`, `NotificationController::index()`)
  — nie przepisywałem SQL-a ręcznie.

### 1.2 Dane syntetyczne

Zasilone przez jednorazowy skrypt PHP (opis w załączniku), z użyciem
istniejących fabryk (`UserFactory`, `ProfileFactory`, `PostFactory`,
`RecipeFactory`, `CommentFactory`, `CookedEventFactory`) tam, gdzie to miało
sens, i masowym `insert()` tam, gdzie tworzenie 10 000 modeli Eloquent jedno
po drugim byłoby samo w sobie tym, co mierzymy zamiast tego, co mieliśmy
zmierzyć. Graf społeczny (kto kogo obserwuje, kto kogo blokuje) budowany
ręcznie z rozkładem: ~5% kont to „gospodarze" (hub), reszta obserwuje
głównie ich — bo płaski, w pełni losowy graf zafałszowałby estymacje
plannera i selektywność filtrów bardziej niż jakikolwiek prawdziwy serwis.

Liczby w tabeli poniżej to **`SELECT count(*)`**, nie liczby żądane —
kilka tabel wyszło nieco inne niż z góry zaplanowane (przez losowość
i odsiew blokad kasujących część `follows`).

| Tabela | `kuking_perf_100` | `kuking_perf_1000` |
|---|---:|---:|
| users | 100 | 1 000 |
| profiles | 100 | 1 000 |
| posts | 1 000 | 10 000 |
| recipes | 200 | 2 000 |
| recipe_ingredients | 971 | 9 924 |
| comments | 1 318 | 14 084 |
| cooked_events | 663 | 6 519 |
| follows | 1 011 | 17 208 |
| blocks | 8 | 51 |
| notifications | 2 979 | 37 793 |
| reports | 22 | 220 |
| moderation_actions | 6 | 85 |
| media | 1 002 | 10 302 |
| post_media | 768 | 7 954 |

Po zasileniu obu baz: `ANALYZE` (bez tego planner licząc na puste statystyki
kłamie — dokładnie to, przed czym ostrzega zadanie).

### 1.3 Powtórzenia

Dla każdego z sześciu zapytań: **5 przebiegów** `EXPLAIN (ANALYZE, BUFFERS,
FORMAT JSON)` z rzędu na tym samym połączeniu, mediana `Planning Time`
i `Execution Time`. Rozrzut między przebiegami był mały (rząd pojedynczych
mikrosekund do ~1 ms przy zapytaniu wyszukiwania) — nie wklejam wszystkich
pięciu wartości do tabeli głównej, są w surowych plikach JSON w scratchpadzie
sesji, dostępne na żądanie.

---

## 2. Tabela wyników

„Zapytań/żądanie" liczy WSZYSTKIE zapytania SQL wykonane podczas budowania
danych dla tego ekranu (główna lista + `paginate()`'owy `COUNT` + eager
loady `with()`/`withCount()`), złapane przez `DB::listen()` — to jest
odpowiedź na pytanie o N+1.

| Zapytanie | Konta | Planning (mediana) | Execution (mediana) | Kształt planu (węzeł najdroższy) | `SubPlan`? | Zapytań/żądanie |
|---|---:|---:|---:|---|:---:|---:|
| Feed obserwowanych | 100 | 0,26 ms | 0,51 ms | Seq Scan `posts` → Sort → Limit | tak* | 7 |
| Feed obserwowanych | 1000 | 0,32 ms | **2,96 ms** | Seq Scan `posts` → Sort → Limit | tak* | 7 |
| Feed odkrywania (gość) | 100 | 0,23 ms | 0,10 ms | Index Scan `posts_published_idx` → Nested Loop → Limit | tak* | 6 |
| Feed odkrywania (gość) | 1000 | 0,22 ms | **0,11 ms** | jw. (+ `Memoize` dodany przez planner) | tak* | 6 |
| Strona profilu | 100 | 0,20 ms | 0,09 ms | Index Scan `posts_author_published_idx` → Limit | tak* | 12 |
| Strona profilu | 1000 | 0,21 ms | **0,12 ms** | jw. | tak* | 12 |
| Wyszukiwanie (`pierogi`) | 100 | 0,73 ms | 1,90 ms | Seq Scan `recipes` (Nested Loop przez `users`) → Sort → Limit | tak | 4 |
| Wyszukiwanie (`pierogi`) | 1000 | 1,02 ms | **33,7 ms** | Nested Loop OD `users` (978 pętli!) → Index Scan `recipes` → Sort → Limit | tak | 4 |
| Panel moderacji | 100 | 0,05 ms | 0,02 ms | Seq Scan `reports` → Sort → Limit | nie | 8 |
| Panel moderacji | 1000 | 0,04 ms | **0,03 ms** | Index Scan `reports_status_created_idx` → Limit | nie | 8 |
| Lista powiadomień | 100 | 0,12 ms | 0,08 ms | Index Scan `notifications_user_created_idx` → Nested Loop → Limit | nie | 5 |
| Lista powiadomień | 1000 | 0,13 ms | **0,08 ms** | jw. | nie | 5 |

\* `SubPlan` obecny, ale **ograniczony rozmiarem strony, nie rozmiarem
tabeli** — to skorelowany `(SELECT count(*) FROM comments WHERE …)` w liście
`SELECT` (`withCount()`), wykonywany raz na KAŻDY wiersz **zwrócony po
`LIMIT`** (12–16 razy), nie raz na każdy wiersz tabeli. Szczegóły w §3.1.
To jest inny rodzaj `SubPlan` niż ten z B4 — dlatego mimo `SubPlan = tak`
te zapytania skalują się dobrze (patrz kolumna Execution).

Dodatkowo — **weryfikacja B4** (nie jedno z sześciu wymaganych zapytań, ale
zadanie wprost prosiło o sprawdzenie tego ustalenia): `DailyBoard::peopleToFollow()`.

| | 100 kont | 1000 kont |
|---|---:|---:|
| Execution (mediana z 5) | 0,50 ms | 4,36 ms |
| `SubPlan`? | **nie** | **nie** |
| Kształt planu | Hash Join po `joinSub` (agregat `max(published_at)` per autor) | Nested Loop po tym samym `joinSub` |

Wzrost ~8,7× przy 10× większych danych — liniowo, żadnego skorelowanego
podzapytania. **B4 potwierdzone jako naprawione**, świeżym pomiarem, nie
tylko lekturą kodu.

---

## 3. Omówienie każdego zapytania

### 3.1 Feed obserwowanych (`FollowingFeed::paginate`)

Zapytanie idzie `WHERE author_id IN (...)` (lista obserwowanych + widz sam)
i sortuje po `published_at DESC, id DESC` z kursorem — dokładnie tak, jak
opisuje komentarz w `app/Domain/Feed/FollowingFeed.php:19-21`. Przy widzu
obserwującym ~76 osób (w tym kilku „gospodarzy" z dużą liczbą wpisów)
planner **celowo** wybiera Seq Scan zamiast indeksu — bo lista obserwowanych
obejmuje autorów prawie jednej piątej wszystkich wpisów w tabeli (selektywność
zbyt niska, żeby indeks się opłacał). To jest poprawna decyzja plannera, nie
błąd:

```
Limit  (actual time=2.961 rows=16)
  Result
    Sort
      Seq Scan on posts
        Filter: published_at IS NOT NULL AND deleted_at IS NULL
              AND visibility = ANY('{public,followers}') AND author_id = ANY(...)
        Rows Removed by Filter: 8162   -- z 10000 wierszy
    SubPlan (per zwrócony wiersz, 16 razy)
      Aggregate → Nested Loop (comments + NOT EXISTS blocks)
```

Wzrost 0,51 ms → 2,96 ms dla 10× wpisów (~5,8×) — **lepiej niż liniowo**,
bo rosną też statystyki selektywności, nie tylko rozmiar tabeli. 7 zapytań
na żądanie w obu przypadkach (główna lista + `users`/`profiles`/`media`
w `IN (...)` + pivot `post_media` + `recipes:id,title,slug`) — stałe,
nie rośnie z danymi. **Nie jest to N+1**: to jest dokładnie tyle zapytań,
ile relacji w `->with([...])`.

### 3.2 Feed odkrywania / „Świeżo z Kuking" (gość, `DiscoverFeed::paginate(null)`)

Najlepiej zachowujące się z sześciu zapytań. Indeks częściowy
`posts_published_idx` (`WHERE deleted_at IS NULL AND status='published' AND
visibility='public'`, sortowany `published_at DESC, id DESC`) daje Index Scan
z wczesnym zatrzymaniem po znalezieniu 10 wierszy — koszt praktycznie nie
zależy od rozmiaru tabeli:

```
Limit (rows=10)
  Nested Loop
    Index Scan using posts_published_idx on posts   -- zatrzymuje się po ~10-17 kandydatach
    Memoize                                          -- (przy 1000 kontach: planner sam dodał cache wyników)
      Index Scan using users_pkey (status='active')
    SubPlan: Aggregate (comments count, per zwrócony wiersz)
```

0,10 ms → 0,11 ms dla 10× danych — praktycznie płasko. Przy 1000 kontach
planner sam dorzucił węzeł `Memoize` (cache'uje wynik sprawdzenia
`status='active'` dla powtarzających się `author_id`) — adaptacyjna
optymalizacja Postgresa 14+, nie coś, co zrobiliśmy my.

### 3.3 Strona profilu (replika `ProfileController::show`, zakładka „Wszystko")

Indeks `posts_author_published_idx` (author_id, published_at DESC, id DESC)
trafiony bezpośrednio — Index Scan z `Index Cond: author_id = ?`, zero Seq
Scan. 0,09 ms → 0,12 ms dla 10× danych. **12 zapytań na żądanie w obu
przypadkach**: to nie jest N+1, to jest 1 lista + 1 `isFollowing()` (EXISTS)
+ eager loady (3) + **5 osobnych `COUNT`** (wpisy/przepisy/ugotowane/
obserwujący/obserwowani — liczniki obok zakładek). Te pięć liczników to
pięć osobnych, tanich zapytań (0,02–0,25 ms każde) — Laravel nie potrafi
ich scalić w jedno bez ręcznego przepisania na współdzielone CTE, a przy
tych kosztach nie ma po co.

### 3.4 Wyszukiwanie (`SearchQuery::recipes('pierogi')`) — jedyne zapytanie z realnym problemem skalowania

To jest jedyne z sześciu, które **nie** skaluje się dobrze i **zmienia
kształt planu** między 100 a 1000 kontami:

**Przy 100 kontach / 200 przepisach** (3,4 ms razem z planowaniem):

```
Nested Loop  (rows=8)
  Seq Scan on recipes           -- 200 wierszy, tanio
    Filter: (title % 'pierogi') OR (title LIKE '%pierogi%')
          OR (summary LIKE '%pierogi%') OR (hashed SubPlan: recipe_ingredients)
  Index Scan using users_pkey (status='active')   -- 8 pętli
```

**Przy 1000 kontach / 2000 przepisach (33,7 ms — 17,7× dla 10× danych):**

```
Nested Loop Anti Join
  Nested Loop
    Seq Scan on users (status='active')            -- 978 wierszy
    Index Scan using recipes_author_published_idx   -- RAZ NA KAŻDEGO Z 978 UŻYTKOWNIKÓW
      Index Cond: author_id = users.id
      Filter: (title % ...) OR (title LIKE ...) OR (summary LIKE ...) OR (SubPlan: recipe_ingredients)
      SubPlan: Bitmap Heap Scan on recipe_ingredients  -- loops=1136
  Materialize → Seq Scan on blocks
```

**Co się stało:** planner ODWRÓCIŁ kolejność złączenia. Przy większej tabeli
`recipes` uznał, że taniej jest wejść przez **każde ze 978 aktywnych kont**
i dla każdego zrobić Index Scan po jego przepisach, niż skanować całą
tabelę `recipes` raz. To oznacza, że koszt wyszukiwania rośnie **z liczbą
aktywnych kont**, nie z liczbą trafień — nawet gdyby fraza pasowała do
zera przepisów, zapytanie i tak przejdzie przez wszystkie aktywne konta.

**Dlaczego indeks trigramowy (`recipes_title_trgm_idx`) nie jest użyty**:
sprawdziłem osobno, izolując sam warunek `title % 'pierogi'` bez reszty
`OR`-a:

```sql
EXPLAIN (ANALYZE, BUFFERS) SELECT * FROM recipes
WHERE kuking_normalize(title) % 'pierogi'
ORDER BY similarity(kuking_normalize(title),'pierogi') DESC LIMIT 21;
-- Bitmap Index Scan on recipes_title_trgm_idx (actual time=0.117ms, rows=200)
-- Execution Time: 3.177 ms
```

Sam trigram korzysta z indeksu. Ale `SearchQuery::recipes()` łączy go przez
`OR` z trzema innymi warunkami na różnych kolumnach/tabelach (`title LIKE`,
`summary LIKE`, `EXISTS (recipe_ingredients)`) — Postgres **nie potrafi**
złożyć czterech różnych indeksów w jeden plan przez `OR` w tym układzie
kosztowym i woła zamiast tego pełny skan (tabeli albo — jak tutaj — kont).
To jest architektura zapytania (`app/Domain/Search/SearchQuery.php:59-69`),
nie brakujący indeks — indeksy są, ale kształt `WHERE` nie pozwala ich użyć
razem.

**Czy to jest problem PRZY TYSIĄCU KONT?** 33,7 ms to wciąż akceptowalny
czas dla wyszukiwarki. **Ale wzrost 17,7× dla 10× danych jest gorszy niż
liniowy** i mechanizm (skan po kontach, nie po trafieniach) będzie się
pogłębiał wraz z rejestracjami niezależnie od tego, ile osób faktycznie
wpisze „pierogi". Przy 10 000 aktywnych kontach ekstrapolacja (bardzo
zgrubna, nie zmierzona) daje rząd 150–300 ms na wyszukanie hasła — to już
zauważalne opóźnienie dla akcji, która ma być natychmiastowa. Zobacz issue
otwarte w §5.

#### 3.4a AKTUALIZACJA 9 września 2026 — pomiar na dziesięć razy większej bazie i CO Z TEGO WYSZŁO INACZEJ

> Ta sekcja nie poprawia liczb wyżej — one zostają takie, jakie zmierzono.
> Poprawia **diagnozę**, bo pomiar na większej bazie jej nie potwierdził.

**Warunki.** PostgreSQL 16.13 (ten sam kontener, `SELECT version()`),
osobna baza `kuking_pomiar_szukania`: **10 000 kont, 40 000 przepisów,
80 000 wpisów, 80 000 składników, 80 blokad**. Dane z fabryk, tytuły ze
słownika 150 polskich dań z diakrytykami. Bufory ciepłe (pierwszy przebieg
odrzucany), mediana z 5 przebiegów `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)`,
`Planning + Execution`. Maszyna dzielona z innymi sesjami — między
przebiegami widać ±10% rozrzutu, więc liczby poniżej mają sens jako rzędy
wielkości i jako różnica PRZED/PO mierzona **obok siebie w tej samej sesji**,
a nie jako wartości bezwzględne z dokładnością do dziesiątej milisekundy.

**Co się nie potwierdziło.**

1. **„`OR` w `WHERE` blokuje indeks trigramowy" — nie jako reguła.**
   Próba kontrolna to `SearchQuery::people()`, która `OR` ma do dziś: trzy
   warunki `LIKE` na trzech kolumnach `profiles`. PostgreSQL składa z nich
   `BitmapOr` z trzech `Bitmap Index Scan` i tabeli nie czyta (7,6 ms przy
   10 000 profili). Rozstrzyga nie słowo `OR`, tylko to, czy wszystkie
   człony siedzą na **jednej tabeli**. Stary `recipes()` miał czwarty człon
   jako skorelowany `EXISTS` na `recipe_ingredients` — takiego składnika
   `BitmapOr` przyjąć nie może, więc cała alternatywa spadała do `Filter`
   na pełnym skanie. To jest prawdziwa treść tamtej obserwacji.

2. **„Koszt rośnie z liczbą kont" — nie na tej skali.** Przy 10 000 kont
   `users` nie steruje niczym: jest budowaną raz stroną `Hash Join`
   (9 500 wierszy, ~2–3 ms). Plan z §3.4, w którym `users` jest sterownikiem
   pętli, nie odtworzył się ani razu.

**Co się potwierdziło — objaw, nie mechanizm.** Koszt naprawdę rośnie
z rozmiarem tabeli `recipes`, tylko z zupełnie innego powodu, niż zgadywało
issue. Indeksy trigramowe **są używane** (`Bitmap Index Scan` we wszystkich
czterech gałęziach `UNION ALL`). Problem jest o krok dalej: indeks GIN dla
operatora `%` jest **stratny**, więc każdy kandydat sprawdzany jest po raz
drugi na wierszu tabeli — a przy progu podobieństwa 0,12
(`App\Support\ProgPodobienstwa`) kandydatów jest bardzo dużo:

| fraza | kandydaci z indeksu | zostaje po rechecku | czas gałęzi |
|---|---:|---:|---:|
| `pierogi` | 17 644 | 1 783 | 118,8 ms |
| `żurek` | 24 080 | 776 | 151,2 ms |
| `ser` | 15 160 | 360 | 105,5 ms |
| `xyzqva` | 0 | 0 | 0,1 ms |

Czyli: dla frazy, która **cokolwiek** trafia, PostgreSQL przepuszcza przez
recheck 35–60% tabeli i dla każdego wiersza liczy `kuking_normalize()`
(`unaccent()` po słowniku) od nowa. Dla frazy, która nie trafia nic
(`xyzqva`), całe zapytanie kosztuje ~1 ms — więc „koszt niezależny od liczby
trafień" też nie jest prawdą w tej ostrej formie.

**Drugi, osobny przypadek: fraza 2-znakowa.** `LIKE '%ry%'` nie da się
obsłużyć indeksem trigramowym (dwa znaki to zero trigramów). Plan spada
wtedy do `Parallel Seq Scan on recipes`, a na `recipe_ingredients` do skanu
indeksu oddającego **wszystkie 80 000 wierszy** i rechecku każdego z nich.
To jedyny zmierzony przypadek, w którym indeks jest naprawdę pomijany —
i wchodzi w grę, bo `SearchController` przepuszcza frazy od 2 znaków.

**Co z tym zrobiono.** Znormalizowany tekst przeniesiono do kolumn
generowanych `*_search` (migracja
`2026_09_09_100000_materialize_search_columns`), a indeksy GIN stoją teraz
na kolumnach, nie na wyrażeniu. Recheck czyta gotowy tekst, zamiast liczyć
`unaccent()` raz na wiersz. Zmierzone obok siebie, ta sama baza, ta sama
sesja, `SearchQuery::recipes()` / `::people()` w całości:

| fraza | przed | po |
|---|---:|---:|
| `ser` | 225,5 ms | 149,3 ms |
| `ry` (2 znaki) | 292,2 ms | 146,9 ms |
| `pierogi` | 160,0 ms | 119,1 ms |
| `pierogi z kapusta i grzybami` | 192,5 ms | 154,0 ms |
| `żurek` | 178,7 ms | 118,6 ms |
| `gołąbki` | 140,7 ms | 93,6 ms |
| `sernk` (literówka) | 122,0 ms | 84,0 ms |
| `xyzqva` (nic nie znajduje) | 1,19 ms | 1,00 ms |
| `people('ry')` | 67,7 ms | 10,0 ms |
| `people('pierogi')` | 8,8 ms | 5,3 ms |
| `people('pierogi z kapusta i grzybami')` | 0,50 ms | **3,12 ms** |

**Jedna pozycja jest gorsza i tak ma zostać zapisane.** Przy długiej frazie
`people()` przestaje wybierać `BitmapOr` i idzie `Seq Scan on profiles`:
filtr po kolumnie jest tani, więc kosztorys skanu sekwencyjnego spadł
PONIŻEJ kosztorysu ścieżki indeksowej i planner zmienił zdanie. 2,5 ms
różnicy przy 10 000 profili, ale rośnie liniowo z liczbą kont. Zostawione
świadomie: to samo uproszczenie filtra daje przy frazie 2-znakowej
67,7 → 10,0 ms, czyli bilans na `people()` jest dodatni.

**Trafność sprawdzona osobno.** 26 fraz × 2 metody (dania, literówki, brak
diakrytyków, wielkie litery, składniki, imię, nazwa konta, fraza bez trafień,
frazy 2-znakowe): zbiór wyników i ich kolejność **identyczne co do wiersza**
przed i po. To jest zmiana kosztu, nie wyszukiwarki.

**Czego ta zmiana NIE naprawia — i dlaczego to osobna sprawa.** Rekordzistą
w koszcie zostaje recheck 17–24 tysięcy kandydatów, tylko dwa razy tańszy.
Mechanizm zostaje: liczba kandydatów wynika z progu 0,12 przy operatorze `%`,
który mierzy podobieństwo do CAŁEGO tytułu. Zmierzony wariant: operator
`<%` (`word_similarity`, próg domyślny 0,6) na tym samym indeksie GIN daje
dla `pierogi` **777 wierszy w 7,3 ms zamiast 17 644 kandydatów w 118 ms**,
zachowuje literówkę (`word_similarity('sernk', 'sernik babci haliny')`
= 0,67 przy progu 0,6) i wycina śmieci (dzisiejsze `%` przy 0,12 zwraca
21 „wyników" na frazę `sajgonki z krewetkami`, których w bazie nie ma
wcale; `<%` zwraca zero w 0,95 ms). To jest jednak zmiana TRAFNOŚCI, czyli
decyzja produktowa — a `AGENTS.md` §10 mówi wprost, żeby nowych pomysłów
nie doklejać do niepowiązanego PR-a. Należy jej osobne issue z tymi liczbami.

> **Zrobione:** issue #187, decyzja właściciela i pomiar tego, co ta zmiana
> gubi — §3.4b niżej. Liczby powyżej zostają takie, jakie zmierzono na tamtej
> bazie; §3.4b mierzy na własnej i mówi wprost, że korpus jest inny.

#### 3.4b 9 września 2026 — operator `<%` zamiast `%`: CO WYSZUKIWARKA PRZESTAJE ZNAJDOWAĆ (issue #187)

> Ta sekcja nie poprawia liczb z §3.4 ani §3.4a — one zostają. Dokłada pomiar
> tego, o co §3.4a się tylko otarła: **zmiany TRAFNOŚCI**. Poprzednia zmiana
> (PR #185) mogła uczciwie napisać „zbiór wyników identyczny co do wiersza".
> Ta nie może i nie próbuje: z definicji zmienia, co wyszukiwarka znajduje.

**Decyzja właściciela (wiążąca):** przechodzimy z operatora `%` (podobieństwo
do CAŁEGO tytułu, próg 0,12) na `<%` (`word_similarity` — podobieństwo do
najlepiej pasującego FRAGMENTU tytułu). Powód: przy 0,12 fraza „sajgonki
z krewetkami" zwracała wyniki w bazie, w której nie ma ani jednej sajgonki,
a to wygląda jak zepsuta wyszukiwarka. Zadaniem tego pomiaru nie było
rozstrzygać wyboru, tylko **pokazać jego cenę**.

**Warunki.** PostgreSQL **16.13** (kontener agenta; produkcja ma 18 —
ta różnica jest odnotowana, nie ukryta), osobna baza `kuking_pomiar_trafnosc_a845`
(skasowana po pomiarze): **10 000 kont, 40 000 przepisów, 80 000 składników,
80 blokad**, 1 446 tagów i 2 542 aliasy ze `TagSeeder`. Tytuły ze słownika
160 polskich dań × 40 dopełnień, dobierane niezależnym hashem, 40% tytułów to
samo danie („Pierogi", „Rosół z kury"). Bufory ciepłe, mediana z 5 przebiegów.

⚠️ **Baza z §3.4a już nie istnieje** (poprzedni agent ją posprzątał), a jej
generator nie jest w repozytorium — korpus jest więc INNY i liczby bezwzględne
nie są porównywalne z §3.4a (tam „pierogi" dawało 17 644 kandydatów, tu 18 178,
ale to zbieżność, nie ta sama baza). Porównywalne jest to, co zmierzono
**obok siebie, w tej samej bazie i sesji**: PRZED kontra PO.

##### Próg: 0,5, a nie domyślne 0,6 — i to jest wynik pomiaru, nie gust

Issue #187 zakładało próg domyślny (0,6). Przy 0,6 z wyszukiwarki znikają
trafienia, których nikt nie zamawiał — „szybka wyszukiwarka, która przestała
znajdować rosół, jest gorsza niż wolna". Zmierzone (liczba trafień gałęzi
trigramowej):

| fraza | word_similarity do celu | `<%` 0,6 | `<%` 0,5 | `<%` 0,4 |
|---|---:|---:|---:|---:|
| `rosul` (typowa pisownia „rosuł") | 0,50 do „Rosół" | **0** | 782 | 782 |
| `piergi` | 0,57 do „Pierogi" | **0** | 1 313 | 1 313 |
| `kotlet schabowy z ziemniakami` | 0,55 do „Kotlet schabowy …" | 45 | 232 | 232 |
| `pierogi ruskie babci haliny` | — | 9 | 246 | 246 |
| `gołombki` | 0,42 do „Gołąbki" | 0 | **0** | 263 |

A tak rośnie śmieć przy schodzeniu z progiem (wiersze NIEZAWIERAJĄCE szukanego
dania):

| fraza | `%` 0,12 | `<%` 0,6 | `<%` 0,5 | `<%` 0,4 | `<%` 0,3 |
|---|---:|---:|---:|---:|---:|
| `pierogi` | 1 485 | 0 | 760 | 4 024 | 4 024 |
| `sajgonki z krewetkami` | 1 621 | 0 | 0 | 0 | 0 |
| `tortilla z kurczakiem` | 3 209 | 0 | 0 | 233 | 725 |
| `kartacze` | 384 | 0 | 0 | 0 | 704 |
| `żurek` | 0 | 0 | 0 | 0 | 2 150 |
| `rosół` | 96 | 0 | 0 | 0 | 1 565 |

**Wybrano 0,5.** Odzyskuje literówki, których 0,6 nie przepuszcza, i pełną
trafność długich fraz, a kanarki z issue (`sajgonki z krewetkami`, `kartacze`,
`tortilla z kurczakiem`) dalej zwracają zero. Zejście do 0,4 kupuje jedną
frazę („gołombki") za 3 264 nowe śmieci przy „pierogach" — bilans ujemny.

⚠️ **0,5 leży dokładnie na granicy dla „rosul"** (word_similarity = 0,5000).
Zmierzone: `<%` porównuje `>=`, mimo że dokumentacja PostgreSQL mówi „greater
than" — trafienie równe progowi wchodzi. Gdyby to się zmieniło, rosół zniknie
z wyników; pilnuje tego `TrafnoscWyszukiwarkiTest::test_literowki_nadal_znajduja_przepis`.

##### Tabela różnic — 38 fraz, cały wynik `SearchQuery::recipes()`, nie sama gałąź

„Znika" i „dochodzi" liczone na PEŁNYM zbiorze wyników (wszystkie cztery
gałęzie razem, filtr widoczności i aktywności autora włączony), nie na
pierwszej dwudziestce.

| fraza | `%` 0,12 | `<%` 0,5 | znika | dochodzi | co znika (najliczniejsze tytuły) |
|---|---:|---:|---:|---:|---|
| `żurek` | 2155 | 2155 | 0 | 0 | — |
| `zurek` | 2155 | 2155 | 0 | 0 | — |
| `gołąbki` | 374 | 253 | 121 | 0 | „Golonka w piwie" ×94; „Flaki po góralsku" ×6; „Flaki dla gości" ×5; … +6 tytułów |
| `golabki` | 374 | 253 | 121 | 0 | jak wyżej (normalizacja daje tę samą frazę) |
| `rosół` | 839 | 747 | 92 | 0 | „Rogaliki" ×92 |
| `ROSÓŁ` | 839 | 747 | 92 | 0 | „Rogaliki" ×92 |
| `pierogi` | 2654 | 1955 | 902 | 203 | „Gęś pieczona" ×101; „Udka z piekarnika" ×93; „Kaczka pieczona" ×92; … +58 tytułów |
| `sernik` | 1501 | 992 | 519 | 10 | „Drożdżówka z serem" ×92; „Krupnik" ×92; „Makaron z serem" ×88; … +49 tytułów |
| `bigos` | 493 | 493 | 0 | 0 | — |
| `barszcz` | 792 | 696 | 96 | 0 | „Bogracz" ×94; „Bogracz jak u babci" ×2 |
| `makowiec` | 456 | 225 | 231 | 0 | „Makaron z serem" ×88; „Mazurek" ×78; „Mazurek na święta" ×6; … +18 tytułów |
| `placki ziemniaczane` | 984 | 462 | 522 | 0 | „Ziemniaki z koperkiem" ×96; „Pączki" ×89; „Placek po zbójnicku" ×86; … +72 tytułów |
| `pierogy` (literówka) | 2633 | 1955 | 890 | 212 | „Gęś pieczona" ×101; „Udka z piekarnika" ×93; „Kaczka pieczona" ×92; … +54 tytułów |
| `sernk` (literówka) | 816 | 1481 | 0 | 665 | — (nic nie znika; dochodzą „Sernik babci Haliny …" i tytuły „… z serem") |
| `kotlet schabwy` (literówka) | 1863 | 223 | 1640 | 0 | **„Schabowy" ×95**; **„Kotlety mielone" ×89**; „Schab ze śliwką" ×108; „Kotlety z kaszy" ×93; … +267 tytułów |
| `gołombki` (literówka) | 485 | 0 | 485 | 0 | **„Gołąbki" ×104**; „Golonka w piwie" ×94; … +79 tytułów |
| `rosul` (literówka) | 559 | 747 | 92 | 280 | „Rogaliki" ×92 (dochodzą 280 rosołów, których `%` nie znajdowało) |
| `piergi` (literówka) | 2778 | 1955 | 1046 | 223 | „Gęś pieczona" ×101; „Kaczka pieczona" ×92; … +75 tytułów (same pierogi zostają) |
| `pierogi z kapustą i grzybami` | 4971 | 288 | 4683 | 0 | „Pierogi z mięsem" ×110; „Kiszona kapusta" ×108; „Uszka z grzybami" ×103; … +880 tytułów |
| `sernik babci haliny` | 2138 | 830 | 1308 | 0 | „Piernik" ×103; **„Sernik" ×99**; „Bliny" ×91; … +248 tytułów |
| `zupa krem z dyni` | 3448 | 490 | 2958 | 0 | „Zupa jarzynowa" ×121; „Zupa ogórkowa" ×120; „Zupa cebulowa" ×117; … +462 tytułów |
| `kotlet schabowy z ziemniakami` | 3167 | 223 | 2944 | 0 | „Zapiekanka ziemniaczana" ×109; **„Schabowy" ×95**; „Ziemniaki z koperkiem" ×96; … +482 tytułów |
| `żurek na zakwasie z jajkiem` | 3209 | 279 | 2930 | 0 | **„Żurek z białą kiełbasą" ×99**; „Jajecznica na maśle" ×92; „Chleb na zakwasie" ×89; … +625 tytułów |
| `ciasto drożdżowe z kruszonką` | 1115 | 250 | 865 | 0 | „Zupa krem z dyni" ×102; „Drożdżówka z serem" ×92; **„Bułeczki drożdżowe" ×78**; … +137 tytułów |
| `pierogi ruskie babci haliny` | 2989 | 316 | 2673 | 0 | **„Pierogi z kapustą i grzybami" ×111**; **„Pierogi z mięsem" ×110**; „Piernik" ×103; … +490 tytułów |
| `gulasz węgierski z papryką` | 1642 | 210 | 1432 | 0 | „Pasztet z królika" ×114; **„Kasza gryczana z gulaszem" ×95**; „Papryka konserwowa" ×95; … +237 tytułów |
| `mąka` (składnik) | 4726 | 4575 | 189 | 38 | „Surówka z marchewki" ×93; „Mazurek" ×69; … +8 tytułów |
| `grzyby suszone` (składnik) | 2885 | 1783 | 1102 | 0 | **„Zupa grzybowa" ×91**; **„Uszka z grzybami" ×100**; „Kiszone ogórki" ×111; … +153 tytułów |
| `kapusta kiszona` (składnik) | 3135 | 2195 | 940 | 0 | „Kiszone ogórki" ×109; „Kanapki z pastą" ×99; „Kaczka pieczona" ×90; … +136 tytułów |
| `twaróg` (składnik) | 1492 | 1532 | 0 | 40 | — (dochodzi „Twarożek ze szczypiorkiem …") |
| `koperek` (składnik) | 2493 | 1992 | 501 | 0 | „Kopytka" ×98; „Kołduny" ×95; „Mazurek" ×78; … +26 tytułów |
| `ry` (2 znaki) | 7084 | 7000 | 84 | 0 | „Rosół" ×84 |
| `ka` (2 znaki) | 24359 | 24359 | 0 | 0 | — |
| `se` (2 znaki) | 4286 | 4286 | 0 | 0 | — |
| `xyzqva` (bez trafień) | 0 | 0 | 0 | 0 | — |
| `sajgonki z krewetkami` | 1526 | **0** | 1526 | 0 | „Knedle ze śliwkami" ×103; „Zupa krem z dyni" ×102; … +256 tytułów |
| `kartacze` | 375 | **0** | 375 | 0 | „Karp smażony" ×102; „Kaczka pieczona" ×92; … +5 tytułów |
| `tortilla z kurczakiem` | 3050 | **0** | 3050 | 0 | „Żurek z białą kiełbasą" ×99; „Tort bezowy" ×97; … +599 tytułów |

**Co z tego jest realną stratą** (pogrubione wyżej), a nie sprzątaniem:

1. **`gołombki` przestaje znajdować cokolwiek** — 104 gołąbki znikają. Jedyna
   fraza w tym zestawie, która z pełnego wyniku spada do zera. Cena progu 0,5.
2. **Literówka w długiej frazie gubi dania pokrewne.** „kotlet schabwy"
   zostawia same „Kotlety schabowe" — znika „Schabowy" (95), „Kotlety mielone"
   (89), „Schab ze śliwką" (108). Dla kogoś, kto szukał czegoś schabowego,
   to zawężenie; dla kogoś, kto szukał kotleta schabowego — porządek.
3. **Długa fraza nie zaciąga już dań pokrewnych po jednym słowie.**
   „pierogi ruskie babci haliny" przestaje pokazywać „Pierogi z mięsem"
   i „Pierogi z kapustą i grzybami". To jest największa pojedyncza zmiana
   zachowania i **największe ryzyko** tej decyzji: fraza opisowa zamiast
   dokładnej („pierogi ruskie babci haliny", gdy w bazie są tylko „Pierogi
   ruskie") zwraca dziś mniej. Rekompensata: te same pierogi znajdzie fraza
   krótsza („pierogi" → 1 955 wyników), a wyniki nie są już wymieszane
   z piernikami.
4. **Składnik wpisany jako fraza gubi tytuły pokrewne.** „grzyby suszone" nie
   pokazuje już „Zupy grzybowej" ani „Uszek z grzybami", jeśli nie mają
   dokładnie takiego składnika. Ścieżka po składniku (`recipe_ingredients`)
   działa bez zmian, więc przepisy Z suszonymi grzybami zostają.
5. **`ry` gubi „Rosół"** (84 wiersze) — dwuznakowa fraza to zawsze loteria,
   ale wypada to zapisać.

Reszta „znika" to jednoznaczny śmieć: „rosół" → „Rogaliki", „barszcz" →
„Bogracz", „gołąbki" → „Golonka w piwie", „sajgonki z krewetkami" → „Knedle
ze śliwkami".

**Co DOCHODZI** (kolumna „dochodzi" powyżej): to nie jest przypadek.
`<%` znajduje trafienia, których `%` nie widziało, bo tytuł był za długi:
„sernk" dostaje 665 nowych wierszy (m.in. wszystkie „Sernik babci Haliny …"),
„rosul" 280 rosołów, „twaróg" 40 „Twarożków ze szczypiorkiem".

##### Koszt: kandydaci z indeksu, nie tylko czas

Sama gałąź trigramowa (`EXPLAIN (ANALYZE, BUFFERS)`, `enable_seqscan = off`,
mediana z 3 przebiegów po rozgrzewce):

| fraza | `%` kandydaci → trafienia | `%` ms | `<%` kandydaci → trafienia | `<%` ms |
|---|---|---:|---|---:|
| `pierogi` | 18 178 → 2 798 | 62,2 | 2 134 → 2 073 | 9,9 |
| `żurek` | 20 450 → 987 | 75,6 | 987 → 987 | 4,5 |
| `zupa krem z dyni` | 19 288 → 3 626 | 92,8 | 573 → 509 | 5,0 |
| `pierogi z kapustą i grzybami` | 11 600 → 5 249 | 72,6 | 365 → 306 | 7,5 |
| `sernik` | 12 765 → 1 580 | 45,7 | 1 052 → 1 041 | 4,4 |
| `sajgonki z krewetkami` | 13 170 → 1 621 | 73,7 | 0 → 0 | 1,4 |
| `ka` (2 znaki) | 19 013 → 870 | 65,0 | 5 553 → 4 099 | 25,7 |

Recheck stratnego indeksu przestaje być głównym kosztem: przy `%` odrzucał
5–19 tysięcy wierszy na frazę, przy `<%` **zero albo kilkadziesiąt**.

Całe `SearchQuery::recipes()` (limit 20, przez kod aplikacji, mediana z 5):

| fraza | przed (`%` 0,12) | po (`<%` 0,5) |
|---|---:|---:|
| `ser` | 82,9 ms | 59,2 ms |
| `ry` (2 znaki) | 122,7 ms | 139,7 ms |
| `pierogi` | 64,4 ms | 60,7 ms |
| `pierogi z kapusta i grzybami` | 141,2 ms | **27,4 ms** |
| `żurek` | 104,2 ms | 47,3 ms |
| `gołąbki` | 62,2 ms | 14,5 ms |
| `sernk` | 59,9 ms | 36,9 ms |
| `rosół` | 41,8 ms | 25,4 ms |
| `sajgonki z krewetkami` | 96,1 ms | **6,1 ms** |
| `xyzqva` | 3,1 ms | 3,9 ms |
| `grzyby suszone` | 74,0 ms | 49,9 ms |
| `placki ziemniaczane` | 52,9 ms | 25,3 ms |
| `people('pierogi')` | 7,4 ms | 7,0 ms |

**Fraza 2-znakowa nie poprawiła się i nie miała jak** — `LIKE '%ry%'` to zero
trigramów, więc koszt siedzi w gałęziach `LIKE`, nie w operatorze podobieństwa
(to samo mówi §3.4a). 122,7 → 139,7 ms mieści się w rozrzucie ±10% maszyny
dzielonej z innymi sesjami; nie ma tu poprawy ani regresji.

##### Kolejność wyników: `word_similarity`, potem `similarity`

Rozstrzygnięte pomiarem pozycji, nie teorią. Trzy warianty na tym samym
zbiorze wyników:

| wariant | „pierogi": ile przepisów „Pierogi …" stoi ZA pierwszym „Piernikiem" | „sernk": ile „Pierników" stoi PRZED pierwszym „Sernikiem babci Haliny" |
|---|---:|---:|
| a) `similarity` (jak dotąd) | **722** | **6** |
| c) `word_similarity`, potem `similarity` | 0 | 0 |

Wariant b) (samo `word_similarity`) odpada z innego powodu: wszystkie tytuły
zawierające całe szukane słowo mają 1,00, więc o kolejności decyduje data —
przy frazie „żurek" pierwszą dziesiątkę zajmują „Żurek na zakwasie mojej
mamy" i podobne, a sam „Żurek" spada na dziesiąte miejsce. Wariant c) trzyma
dokładny tytuł na pozycji 1 tak samo jak dziś, a jednocześnie nie pozwala
krótkiemu, przypadkowo podobnemu tytułowi („Piernik") wyprzedzić prawdziwych
trafień. Dlatego wybrano c).

`SearchQuery::people()` zostaje przy `similarity`: dopasowanie idzie tam przez
`LIKE`, zbiór wyników nie zależy od żadnej miary podobieństwa, a nazwy profili
są krótkie — nie ma czego naprawiać.

##### Podpowiedzi tagów (`TagSuggester`) — ta sama zmiana, ten sam indeks

Czwarta gałąź podpowiedzi używała `%` z tym samym progiem 0,12. Przeszła na
`<%` 0,5 razem z wyszukiwarką: dwa progi w dwóch miejscach to rozjazd, który
w tym repozytorium wychodził już kilka razy. Indeks `tags_name_trgm_idx` stoi
na wyrażeniu i obsługuje `<%` przez komutator `%>` (`Bitmap Index Scan`,
0,2 ms) — **żadnej migracji**. Zmierzone na pełnym słowniku (1 446 tagów),
wpisane → podpowiedzi:

| wpisane | `%` 0,12 | `<%` 0,5 |
|---|---|---|
| `pierogy` | pierogi, pierogi ruskie, **piernik**, pierogi z kaszą… | same pierogi (ruskie, z grzybami, z jabłkami, z jagodami…) |
| `bezglutenowe` | bez glutenu, **ciasto bezowe**, **bezy**, **bez ryb**, **bez soi**… | bez glutenu |
| `wegetarianskie` | wegetariańskie, wegańskie, **borówki amerykańskie**, **orzechy włoskie** | wegetariańskie |
| `kotlet schabwy` | kotlet schabowy, kotlety, schab, kotlety rybne… | kotlet schabowy |
| `zakwas na barszc` | zakwas na barszcz biały, zakwas na żurek, zakwas, barszcz… | zakwas na barszcz biały, zakwas na żurek, chleb na zakwasie |
| `golombki` | gołąbki, golonka, gołąbki z kaszą… | **— (nic)** |
| `chleb`, `zupa`, `maka`, `sernik` | — | bez zmian |

Ta sama cena co w wyszukiwarce i w tym samym miejscu: ciężka literówka
fonetyczna przestaje podpowiadać. 16 z 20 sprawdzonych fraz zmienia listę,
w 15 przypadkach przez wycięcie podpowiedzi niezwiązanych z wpisanym słowem.

##### Czego ten pomiar NIE obejmuje

- **PostgreSQL 18** (produkcja). Mierzone na 16.13; `word_similarity`
  i `gin_trgm_ops` są w obu, ale planów na 18 nie sprawdzono.
- **Prawdziwych fraz użytkowników.** `search_performed` zbiera `query_length`,
  nie treść frazy (świadomie) — lista 38 fraz jest ułożona ręcznie i to jest
  jej ograniczenie, mimo że pokrywa kategorie z issue #187.
- **Wpływu na `people()` poza czasem** — tam nic się nie zmieniło, bo ta
  metoda nigdy nie używała operatora trigramowego.
- **Progu innego niż zmierzone** (0,25–0,6 co 0,05). Przy zmianie `PROG`
  trzeba ten pomiar powtórzyć — jest to jedna liczba w `App\Support\ProgPodobienstwa`.

### 3.5 Panel moderacji (replika `ModerationController::reports`)

Wzorcowe zapytanie. `WHERE status = 'open'` trafia w indeks częściowy
`reports_status_created_idx` (przy 1000 kontach: Index Scan, 135 kandydatów
→ 25 po `LIMIT`; przy 100 kontach tabela jest tak mała — 22 wiersze — że
planner słusznie woli Seq Scan). 0,02 ms → 0,03 ms. Zero `SubPlan`. 8 zapytań
na żądanie (COUNT paginacji + lista + eager loady reporter/resolver +
`moderation_actions` dla „przywracalne" + 3× COUNT liczników zakładek)
— stałe, niezależne od danych.

### 3.6 Lista powiadomień (replika `NotificationController::index`)

Indeks `notifications_user_created_idx` trafiony bezpośrednio, filtr blokad
(`scopeVisibleTo`) realizowany jako `NOT EXISTS` w `WHERE`, nie jako pętla
w PHP. 0,08 ms w obu przypadkach — praktycznie płasko. Widz z 5 blokadami
skierowanymi na niego (specjalnie dorzuconymi w danych syntetycznych, patrz
załącznik) naprawdę odsiewa część powiadomień: `Rows Removed by Filter` > 0
na węźle `Seq Scan on blocks` w podplanie. 5 zapytań na żądanie, stałe.

---

## 4. Wnioski

**Skaluje się dobrze (liniowo albo lepiej), nic nie trzeba robić teraz:**
feed obserwowanych, feed odkrywania, strona profilu, panel moderacji, lista
powiadomień. Wszystkie mają indeksy trafione dokładnie tam, gdzie trzeba,
i żadne nie ma N+1 ani nieograniczonego `SubPlan`. B3 i B4 — potwierdzone
jako naprawione (§0, §2).

**Jedyne zapytanie warte uwagi: wyszukiwanie przepisów.** Konkretna
rekomendacja: przepisać `WHERE` w `SearchQuery::recipes()` tak, żeby trigram
po tytule był **jedynym** warunkiem trafiającym w indeks, a dopasowanie po
`summary` i po `recipe_ingredients` szło jako osobne zapytanie dorzucane
tylko wtedy, gdy pierwsze da mało wyników (albo `UNION` trzech osobno
indeksowalnych zapytań zamiast jednego `OR`). Oczekiwany efekt: powrót do
planu „Bitmap Index Scan na `recipes_title_trgm_idx`", czyli koszt
proporcjonalny do liczby TRAFIEŃ, a nie do liczby kont. Koszt zmiany: S/M
(przepisanie jednej metody + test na dwóch rozmiarach danych, żeby złapać
regresję kształtu planu — dokładnie taki test, jaki już istnieje dla B4:
`tests/Feature/PropozycjeOsobDoObserwowaniaTest.php` jest wzorem).

**Nie proponuję cache'u dla wyszukiwania.** Wynik zależy od frazy I od
widza (blokady) — współdzielenie między użytkownikami jest ograniczone do
tej samej frazy, a liczba możliwych fraz jest nieograniczona. Cache'owałby
się źle (niski hit rate) i nie rozwiązałby źródła problemu (kształt
zapytania). To jest dokładnie przypadek, przed którym ostrzega `AGENTS.md`
§3 — problem trzeba rozwiązać w SQL-u, nie obchodzić cache'em.

**Nie proponuję Typesense/Meilisearch/Elastic.** Zmierzony problem to
zapytanie, które nie korzysta z indeksu, który JUŻ ISTNIEJE (`recipes_title_trgm_idx`)
— to jest argument za poprawieniem zapytania, nie za wymianą silnika.
33 ms przy 1000 kontach to nie jest liczba uzasadniająca dokładanie nowej
zależności zabronionej przez `AGENTS.md` §3.

---

## 5. GitHub issues

Otworzone: **jedno**, dla jedynego zmierzonego problemu (§3.4). Pozostałe
pięć zapytań mierzy się dobrze przy obu rozmiarach danych — zgłaszanie ich
byłoby dokładnie tym, przed czym ostrzega zadanie: issue bez zmierzonego
problemu.

B3 i B4 nie dostały nowych issues — są już naprawione (§0).

---

## 6. Czego nie zmierzyłem i dlaczego

1. **Zachowanie na PostgreSQL 18.** Serwer w tym kontenerze to 16.13.
   Główne mechanizmy tu opisane (indeksy częściowe, GIN trigram, plan
   Nested Loop/Seq Scan) nie zmieniają się fundamentalnie między 16 a 18,
   ale nie sprawdziłem tego — nie mam gdzie.
2. **Zimny start.** Wszystkie pomiary to bufory ciepłe po pierwszym
   przebiegu (§1.1). Pierwsze żądanie po restarcie kontenera na Railway
   (albo po `pg_stat_reset`) będzie wolniejsze niż mediana tu podana —
   o ile, nie wiem, bo tego nie zmierzyłem.
3. **Współbieżność.** Każdy pomiar to jedno połączenie, jedno zapytanie na
   raz. Nie sprawdziłem, co się dzieje przy 20 równoczesnych żądaniach do
   tej samej bazy (blokady, kontencja na `work_mem`, kolejka połączeń).
4. **10 000 kont i więcej.** Zadanie prosiło o 100 i 1000 — nie
   ekstrapolowałem dalej niż jedno zdanie w §3.4, i to zdanie jest jawnie
   oznaczone jako „bardzo zgrubne, nie zmierzone".
5. **Zapytania spoza sześciu wymienionych** — w tym `TopicFeed`
   (feed tematów, trzeci stopień na `/home`) i tablica „kuKINGi na dziś"
   (`DailyBoard::forViewer`, poza samym `peopleToFollow`, które sprawdziłem
   osobno dla B4). Nie były częścią zadania.
6. **Renderowanie widoku (Blade).** Mierzę SQL, nie czas generowania HTML
   ani transferu. Serwis może być wolniejszy niż te liczby sugerują z
   powodów niezwiązanych z bazą.
7. **Prawdziwy rozkład fraz wyszukiwania.** Sprawdziłem `pierogi` — trafia
   w 8 (100 kont) / 21+ (1000 kont) przepisów. Nie sprawdziłem frazy, która
   trafia w zero wyników (prawdopodobnie tańsza — Seq Scan/Nested Loop kończy
   się szybciej bez kandydatów do posortowania) ani frazy 1-2 znakowej
   (odcinana wcześniej przez `mb_strlen($phrase) < 2` w `SearchQuery`).

---

## Załącznik: skrypt zasilający i pomiarowy

Dwa jednorazowe skrypty PHP w scratchpadzie sesji — **nie trafiają do
repozytorium**, nie są kodem produkcyjnym.

**`seed_perf.php`** — bootuje aplikację Laravel (`bootstrap/app.php`, jak
`artisan`), obniża runtime'owo koszt bcrypt (`config(['hashing.bcrypt.rounds'
=> 4])`, tylko w pamięci tego procesu — żaden plik nie jest dotykany) i
wstawia dane masowym `DB::table()->insert()` w paczkach po 500–1000 wierszy,
zachowując kolejność wymuszoną kluczami obcymi (media → recipes →
recipe_ingredients; media+posts → post_media). ID generowane w PHP
(`Str::uuid()`), nie przez `gen_random_uuid()` bazy, żeby dało się od razu
budować relacje między tabelami. Rozkłady: status kont 97% aktywne / 1,5%
zawieszone / 1% zbanowane; ~5% kont to „gospodarze" faworyzowani przy
losowaniu autora wpisu (55%) i celu obserwowania (70%); wizualizacja
przepisów z puli 30 nazw polskich dań (żeby wyszukiwanie miało czego szukać)
i puli 20 składników. Powiadomienia generowane 1:1 z rzeczywistymi
zdarzeniami (każdy `follow`, każdy komentarz cudzego wpisu, każde
„Ugotowałem" cudzego przepisu tworzy wiersz w `notifications`) — nie osobno,
żeby wolumen powiadomień był naturalną konsekwencją grafu społecznego,
a nie zgadywanym mnożnikiem.

**Ograniczenia skryptu zasilającego:** losowość (`mt_srand`/`Faker::seed`
ustawione na stałe ziarno, więc powtarzalna, ale nie identyczna z tym, co
zrobiłby inny generator); tekst z `Faker::realText()` to polski „lorem
ipsum" wygenerowany statystycznie z korpusu, nie prawdziwe zdania o
gotowaniu — nie wpływa to na plany zapytań (Postgres nie czyta treści),
ale gdyby ktoś chciał tego użyć do czegokolwiek innego niż EXPLAIN, to
ważne zastrzeżenie. Brak `cooked_event_media`, `daily_picks`, `topics` —
świadomie pominięte, bo żadne z sześciu wymaganych zapytań ich nie dotyka.

**`explain_report.php`** — dla każdego z sześciu zapytań: rejestruje
`DB::listen()`, wywołuje TEN SAM kod domenowy co kontrolery, identyfikuje
zapytanie „główne" (pierwsze czytające z tabeli będącej sercem ekranu,
z pominięciem zapytań `COUNT`/`EXISTS`/pomocniczych uruchamianych wcześniej),
podstawia bindingi do SQL-a (`$pdo->quote()`) i odpala na nim
`EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` pięć razy, licząc medianę. Wykrywanie
`SubPlan`: przeszukanie tekstu JSON-a planu pod kątem `"SubPlan"`/`"InitPlan"`
— heurystyka tekstowa, nie parsowanie drzewa węzeł po węźle dla TEGO celu
(dla podsumowania w tabeli plan JEST parsowany rekurencyjnie, do głębokości
12 poziomów). **Nie jest to bezpieczne wobec SQL injection** — interpolacja
bindingów wprost do tekstu zapytania jest zaakceptowanym skrótem na
zaufanych, syntetycznych danych w skrypcie jednorazowym, nigdy nie na
danych od użytkownika i nigdy w kodzie produkcyjnym.
