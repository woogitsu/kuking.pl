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
