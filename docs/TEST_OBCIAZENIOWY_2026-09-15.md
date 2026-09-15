# Test obciążeniowy Kuking.pl — 15 września 2026

**Pytanie, na które ten dokument odpowiada:** czy przepisanie Kuking.pl
na Go + gorm.io + Dragonfly poprawi wydajność serwisu.

**Odpowiedź w jednym zdaniu:** w zmierzonym zakresie — warstwie danych jednej
strony feedu — przepisanie nic nie daje, bo koszt siedzi w **kształcie dwóch
zapytań**; poprawka jednego z nich daje **11×** przepustowości tej strony,
a ten sam kod w Go bez poprawki daje **0,87×**. Narzut samego Laravela
**nie został zmierzony** i to jedyne miejsce, w którym przepisanie mogłoby
wygrać.

---

## 0. Wersja druga tego dokumentu — co poprawiono i dlaczego

Pierwsza wersja (commit `ce8ec0d`) miała cztery wady metodologiczne, wytknięte
w recenzji PR #582. Wszystkie cztery były trafne, wszystkie zostały naprawione,
a pomiary powtórzone. Zapis jest tutaj, nie w historii gita, bo liczby
z pierwszej wersji zdążyły pójść w świat.

| # | Zarzut | Werdykt | Co zrobiono |
|---|---|---|---|
| 1 | Benchmark liczył `count(*)` pozycji zeszytów, a `ZapisyWpisu::podzapytanieLiczby` liczy **różne uprawnione osoby** z wykluczeniem autora, statusów i blokad; licznik komentarzy i widoczność przepisu też pomijały filtry widza | **trafny** | Zapytania przepisane na wierne (§2). Okazało się, że uproszczenie **zaniżało** koszt: 42–47 ms → **49–53 ms** |
| 2 | Kolumna „w bazie" obejmowała `prepare`/`execute`/`fetchAll`, a w Go też konwersje i mapowanie ORM, więc **nie izolowała czasu serwera** | **trafny** | Kolumny przemianowane i zdefiniowane (§3). Dołożony osobny pomiar czasu serwera z `EXPLAIN ANALYZE` |
| 3 | Ścieżka GORM robiła `Raw().Scan()` i **zaraz drugi `Find()`**, a `queries += 7` było stałą, nie pomiarem; drugie pobranie bez `ORDER BY` | **trafny** | Jedno pobranie (`Table("(?) as posts", sub)` + `Preload`), jawny `ORDER BY`, licznik zapytań przez logger GORM-a. **Zmierzone: GORM wysyła 10 instrukcji, nie 8** |
| 4 | Wniosek „przepisanie całego Laravela pogorszy o 42%" nie wynika z porównania PDO vs GORM; „sufit całego serwisu" i odejmowanie 4,2 ms od HTTP z innego sprzętu są nieuprawnione | **trafny** | Werdykt zawężony do warstwy danych tej jednej strony (§6). Wycofane odejmowanie w §8 i sformułowanie o suficie serwisu |

Dołożona została też **kontrola zgodności wyników**, której wcześniej nie było:
wszystkie trzy implementacje zwracają teraz **te same 10 identyfikatorów
wpisów, w tej samej kolejności, z tymi samymi wartościami obu liczników** —
a nie tylko HTML o tej samej długości.

Niezmienione po poprawkach: kierunek i rząd wielkości wniosku. Zamiana
`OR` + podzapytania na `JOIN` nadal jest najtańszą dużą wygraną, a różnice
między językami nadal są małe wobec kosztu zapytań.

---

## 1. Czego ten pomiar NIE obejmuje

**Laravel nie został uruchomiony.** Polityka sieciowa środowiska pomiarowego
blokuje pobieranie paczek z GitHuba (403 z `codeload.github.com`), więc
`composer install` nie zbudował `vendor/`. Zmierzono warstwę danych
i renderowanie HTML w czystym PHP + PDO, a nie prawdziwy Laravel.

- **Zmierzone:** koszt zapytań po stronie PostgreSQL, koszt podróży i sterownika,
  koszt sklejenia relacji i złożenia HTML, przepustowość, zachowanie planisty
  przy wzroście danych. To jest ta część, która przy przepisaniu **zostaje taka
  sama**, bo SQL się nie zmienia.
- **Niezmierzone:** narzut frameworka na żądanie — bootstrap kontenera, stos
  middleware, hydracja modeli Eloquenta, Policy, Blade, Livewire. **To jedyne
  miejsce, w którym przepisanie na Go naprawdę by wygrało**, i akurat go tutaj
  nie zmierzono. Sposób pomiaru w §8.
- **Niezmierzone:** ścieżki zapisu (publikacja wpisu, komentarz, „Ugotowałem"),
  wyszukiwarka pod obciążeniem, kolejka, poczta, moderacja.

Dalsze zastrzeżenia:

- PostgreSQL w środowisku pomiarowym to **16.13**, nie 18 jak na produkcji.
  Planista w 17 i 18 zachowuje się inaczej; przeskok planu z §4.3 może wypaść
  przy innej wielkości tabel. **Każdy wniosek o konkretnym planie trzeba
  powtórzyć na 18.**
- Baza i klient chodzą na **tej samej maszynie** (4 rdzenie, 15 GB RAM), więc
  czas sieci jest bliski zeru. Na Railway dojdzie realne opóźnienie łącza,
  które **powiększa** znaczenie liczby zapytań na stronę, a nie języka.
- Schemat odtworzono z migracji dla tabel gorącej ścieżki (14 tabel,
  `docs/obciazenie/schema.sql`), z indeksami częściowymi i zmaterializowanymi
  kolumnami wyszukiwania. Dane są syntetyczne.
- **Liczby z tego dokumentu opisują ten benchmark, na tym sprzęcie, na tych
  danych.** Nie wolno ich odejmować od pomiarów HTTP zrobionych gdzie indziej
  ani podawać jako pojemności produkcji.

---

## 2. Jak mierzono

Jednostką pomiaru jest **jedna strona feedu odkrywania dla zalogowanego
widza** — to, co robi `DiscoverFeed::paginate()` plus `with([...])`
z `app/Domain/Feed/DiscoverFeed.php`: **8 zapytań**.

1. zapytanie feedu, 2. autorzy, 3. profile, 4. awatary, 5. zdjęcia wpisów,
6. przepisy, 7. zdjęcia główne przepisów, 8. tagi.

### Wierność zapytań

Zapytanie feedu odtwarza filtry z kodu aplikacji, łącznie z tymi, które
pierwsza wersja benchmarku pominęła:

| Element | Źródło w aplikacji | Co zawiera |
|---|---|---|
| `comments_count` | `Comment::scopeWidoczneDla` | `NOT EXISTS` na blokadach w obie strony, `status = 'published'`, `deleted_at is null`, autor o statusie spoza `STATUSY_UKRYWAJACE_TRESC` |
| `zapisow_count` | `ZapisyWpisu::podzapytanieLiczby` | `count(distinct users.id)` przez `collections` i `collection_items`, z wykluczeniem autora wpisu, `users.status = 'active'` i `NOT EXISTS` na blokadach |
| `czy_zapisany` | `ZapisyWpisu::dolicz` (`withExists`) | `EXISTS` przez `collections` właściciela |
| widoczność wpisu | `Post::scopePubliclyVisible` + `scopeWidoczneDla` | status, `published_at`, `visibility`, `NOT EXISTS` na blokadach |
| widoczność przepisu | `Post::scopeZWidocznymPrzepisem` | `recipe_id is null OR EXISTS(...)` — to jest zapytanie z §4.1 |
| autor aktywny | `whereHas('author', status = active)` | podzapytanie skorelowane |

Tekst SQL leży w **jednym pliku wspólnym dla wszystkich trzech implementacji**
(`docs/obciazenie/feed_orig_*.sql`, `feed_fix_*.sql`); różnią się wyłącznie
składnią symbolu parametru (`?` wobec `$1`). Żadna implementacja nie ma
własnej wersji zapytania.

### Implementacje

| Implementacja | Sterownik | Odpowiednik w świecie Laravela |
|---|---|---|
| PHP 8.4 + PDO | `pdo_pgsql` | warstwa danych bez frameworka |
| PHP 8.4 + PDO + cache `prepare` | `pdo_pgsql` | to samo, ale bez ponownego przygotowywania zapytań |
| Go 1.24 + pgx v5.11 | `pgx/v5` | najniższy możliwy narzut w Go |
| Go 1.24 + GORM v1.31 | `gorm.io/driver/postgres` | to, co proponujesz: `Preload` ≙ `with()` |

### Kontrola zgodności

Przy `BENCH_VERIFY=1` każda implementacja wypisuje identyfikatory dziesięciu
wpisów wraz z `comments_count` i `zapisow_count`. Wynik dla skali M:

```
ZGODNE: PHP == Go/pgx  (10 wpisów, kolejność i oba liczniki)
ZGODNE: PHP == Go/gorm (10 wpisów, kolejność i oba liczniki)
```

Bez tej kontroli porównanie czasów byłoby bezwartościowe: szybsza
implementacja mogłaby po prostu robić mniej.

### Zestawy danych

| Skala | Konta | Wpisy | Przepisy | Komentarze | Obserwowania | Rozmiar |
|---|---|---|---|---|---|---|
| S | 2 000 | 30 000 | 8 000 | 45 000 | 109 000 | 71 MB |
| M | 20 000 | 400 000 | 100 000 | 600 000 | 1 071 446 | 845 MB |
| L | 100 000 | 2 000 000 | 500 000 | 3 000 000 | 5 311 909 | 4 098 MB |

---

## 3. Co dokładnie znaczą kolumny czasu

To była druga wada pierwszej wersji i warto ją wyłożyć wprost.

| Kolumna | Co obejmuje | Czego NIE obejmuje |
|---|---|---|
| **podróż** (dawniej „w bazie") | czas od `prepare`/`execute` do zakończenia pobrania wierszy: praca serwera **plus** przygotowanie zapytania, transport, dekodowanie protokołu, konwersje typów, a w GORM także mapowanie na struktury | — |
| **klient** | sklejenie relacji w mapy i złożenie HTML | całej reszty pracy sterownika, która siedzi w „podróży" |
| **serwer** | `Execution Time` z `EXPLAIN ANALYZE`, czyli czysta praca PostgreSQL | transportu i sterownika |

Kolumna „klient" **nie jest** całym kosztem języka. Jest kosztem tej części,
którą da się czysto oddzielić. Różnica „podróż minus serwer" zawiera zarówno
transport, jak i pracę sterownika w danym języku — i to w niej siedzi
większość różnicy między PHP a Go widocznej w §6.

Uwaga do zestawienia liczb: `EXPLAIN ANALYZE` dokłada własny narzut
instrumentacji i mierzy zapytanie w izolacji, bez ciepłego cache'u
z poprzednich iteracji, więc „serwer" bywa większy niż „strona" mierzona
w pętli. To nie jest sprzeczność, tylko dwie różne rzeczy: „ile trwa strona
w pętli" i „ile pracy wykonuje serwer na jedno zapytanie".

---

## 4. Dlaczego strona trwa ~35 ms: dwa zapytania skanujące całe tabele

### 4.1. `orWhereHas('recipe')` — skan wszystkich przepisów

`Post::scopeZWidocznymPrzepisem()` (`app/Models/Post.php:228`) tłumaczy się na
podzapytanie wewnątrz `OR`. `OR` uniemożliwia półzłączenie po indeksie, więc
PostgreSQL materializuje cały zbiór opublikowanych przepisów. Plan **wiernego**
zapytania przy skali M:

```
Limit (actual time=50.485..50.750 rows=10 loops=1)
  ->  Index Scan using posts_published_idx on posts (actual time=48.289..48.312 rows=10)
        Filter: ((recipe_id IS NULL) OR (hashed SubPlan 6))
        SubPlan 6
          ->  Seq Scan on recipes (actual time=0.023..27.803 rows=80000 loops=1)
Execution Time: 51.375 ms
```

**27,8 ms z 51,4 ms na przeczytanie 80 000 przepisów, żeby pokazać 10 wpisów.**
Koszt rośnie liniowo z liczbą przepisów w serwisie.

### 4.2. Liczniki zeszytów też skanują

W tym samym planie widać `Seq Scan on collection_items` (14 285 wierszy przy
skali M) w podzapytaniu liczącym zapisy. Przy skali L ten sam kształt czytał
71 428 wierszy i kosztował ~10 ms. To druga instancja tej samej klasy błędu.

### 4.3. Najgroźniejsze: plan się przełącza

Ten sam SQL, te same indeksy, różne wielkości tabel:

| Skala | Plan dla przepisów | Czas zapytania |
|---|---|---|
| M (100 tys. przepisów) | `Seq Scan` po 80 000 wierszy | **49–53 ms** |
| L (500 tys. przepisów) | `Index Scan using recipes_pkey` | **9–11 ms** |

Przy **większej** tabeli jest **szybciej**, bo planista zmienił zdanie.
Serwis ma więc w najgorętszej ścieżce zapytanie, które potrafi zwolnić
pięciokrotnie po zwykłym `ANALYZE`, bez żadnego wdrożenia. Awaria tego typu
wygląda jak „nagle strona muli" i nie ma związku z ruchem.

**To jest własność PostgreSQL, nie PHP.** GORM napisany „jeden do jednego"
wygeneruje to samo `OR` z podzapytaniem i dostanie ten sam plan.

---

## 5. Ile daje poprawka zapytania (bez zmiany języka)

Zamiana podzapytania w `OR` na `LEFT JOIN` po kluczu głównym
(`docs/obciazenie/feed_fix_*.sql`), wierne zapytania, skala M:

| Miara | Oryginał | Po poprawce | Zysk |
|---|---|---|---|
| Serwer, samo zapytanie feedu (`EXPLAIN`) | 49–53 ms | 2,7–3,0 ms | **17×** |
| Przepustowość zapytania, c=1 | 30 TPS · 33,7 ms | 362 TPS · 2,8 ms | **12×** |
| Przepustowość zapytania, c=8 | 130 TPS · 61,5 ms | 1457 TPS · 5,5 ms | **11×** |
| Przepustowość zapytania, c=16 | 110 TPS · 145,1 ms | 1441 TPS · 11,1 ms | **13×** |
| Cała strona, 8 workerów (PHP + cache) | 140 stron/s | 1578 stron/s | **11×** |

Oryginał **nasyca się przy ~130 zapytaniach na sekundę i potem degraduje**:
przy 16 równoległych sesjach przepustowość spada ze 130 do 110 TPS, a opóźnienie
rośnie z 61 do 145 ms. Poprawiony wariant skaluje się do ~1450 TPS i trzyma
opóźnienie w granicach 11 ms.

To jest sufit **tej strony w tym benchmarku**, nie „sufit całego serwisu" —
takiego zdania pierwsza wersja nie miała prawa postawić.

---

## 6. PHP kontra Go na tym samym, wiernym zapytaniu

Skala M, wariant poprawiony, jeden worker, trzy rundy, **zmierzona** liczba
instrukcji SQL:

| Implementacja | Strona | podróż | klient | instrukcji SQL | Stron/s |
|---|---|---|---|---|---|
| PHP + PDO (`prepare` co wywołanie) | 5,46–5,82 ms | 5,40–5,75 | 0,07 | 8 | 172–183 |
| **PHP + PDO z cache `prepare`** | **2,15–2,27 ms** | 2,09–2,21 | 0,06 | 8 | **440–465** |
| Go + pgx | 2,53–2,59 ms | 2,50–2,56 | 0,03 | 8 | 386–395 |
| Go + GORM | 3,43–3,68 ms | 3,40–3,65 | 0,02 | **10** | 272–292 |

Osiem równoległych workerów, skala M:

| Implementacja | Poprawione zapytanie | Oryginalne zapytanie |
|---|---|---|
| **PHP + PDO z cache `prepare`** | **1578 stron/s** | 140 stron/s |
| Go + pgx | 1449 stron/s | 122 stron/s |
| Go + GORM | 987 stron/s | — |

Co z tego wolno wyczytać, a czego nie:

1. **Przy złym zapytaniu język nie ma znaczenia** — 140 wobec 122 stron/s.
   Przepisanie tej ścieżki na Go bez ruszania zapytania daje **0,87×**.
2. **Pierwsza „przewaga Go" była artefaktem sterownika.** Go wyglądało na
   dwukrotnie szybsze, dopóki PHP przygotowywał każde z ośmiu zapytań od nowa.
   Po włączeniu podręcznej pamięci `prepare` PHP i pgx są w tej samej klasie
   (1578 wobec 1449 stron/s, czyli ~9% na korzyść PHP). Różnica nie była
   w języku, tylko w tym, czy sterownik przygotowuje zapytanie raz, czy za
   każdym razem.
3. **GORM kosztuje więcej, i widać dlaczego** — wysyła **10 instrukcji zamiast
   8**, bo `Preload` rozbija zagnieżdżone relacje (`Author.Profile.Avatar`,
   `Recipe.HeroMedia`) na osobne zapytania. Przy 987 wobec 1578 stron/s to
   ~37% mniej przepustowości **w warstwie danych tej jednej strony**.

**Czego ta tabela NIE dowodzi.** Nie dowodzi, że przepisanie całego Laravela
na Go pogorszy wydajność serwisu. Porównuje surową warstwę danych w PHP
z warstwą danych w Go, a nie Laravela z aplikacją w Go. Prawdziwy Laravel
dokłada narzut, którego tu nie zmierzono (§8), i to on decyduje o końcowym
bilansie. Uczciwy wniosek brzmi: **sama zamiana języka i ORM-a nie przynosi
wygranej w tej warstwie, a GORM w tej ścieżce kosztuje więcej niż ręcznie
pisane zapytania.**

---

## 7. Feed obserwowanych: osobna bomba, też niezależna od języka

`FollowingFeed::paginate()` robi `$viewer->following()->pluck('users.id')->all()`,
a potem wstawia całą listę do `whereIn`. Skala L, trwałe połączenie:

| Obserwowanych | `pluck` | Zapytanie feedu | Razem | Długość SQL |
|---|---|---|---|---|
| 20 | 0,3 ms | 1,4 ms | **1,7 ms** | 0,6 KB |
| 200 | 0,6 ms | 6,9 ms | **7,5 ms** | 1,0 KB |
| 2 000 | 2,6 ms | 16,8 ms | **19,4 ms** | 4,5 KB |
| 10 000 | 4,7 ms | 170,4 ms | **175,1 ms** | 20,1 KB |

Wzrost jest nadliniowy. Przy 10 000 obserwowanych tekst zapytania z wartościami
wstawionymi wprost ma ~390 KB — na tyle dużo, że w trakcie pomiaru przekroczył
limit argumentów powłoki.

Poprawka jest po stronie SQL: zamiast pobierać identyfikatory do PHP i odsyłać
je z powrotem, zrobić `EXISTS` albo `JOIN` na `follows` w jednym zapytaniu.

Zastrzeżenie: ten pomiar użył uproszczonego zapytania feedu obserwowanych,
bez liczników z §2. Rząd wielkości i kształt wzrostu są wiarygodne, wartości
bezwzględne będą wyższe.

---

## 8. Czego brakuje, żeby decyzja miała pełną podstawę

Jedna liczba: **narzut Laravela na żądanie**. Zmierz ją tak:

```bash
composer install
php artisan optimize
php artisan serve &
curl -s -o /dev/null -w '%{time_total}\n' 'http://127.0.0.1:8000/odkryj'
```

Ale **nie odejmuj od tego wyniku liczb z tego dokumentu**. Zostały zmierzone
na innym sprzęcie, na innych danych i na PostgreSQL 16. Żeby rozdzielić narzut
frameworka od kosztu zapytań, trzeba na **tej samej maszynie i tych samych
danych** zestawić trzy rzeczy:

1. czas HTTP strony `/odkryj` z prawdziwego Laravela,
2. sumę `Execution Time` zapytań, które ta strona wykonała (log zapytań albo
   `pg_stat_statements`),
3. czas tej samej strony z harnessu `docs/obciazenie/` na tej samej bazie.

Różnica między (1) a (3) jest narzutem frameworka i tylko ona jest do
odzyskania przepisaniem.

---

## 9. Dragonfly jako cache

Zmierzony koszt dzisiejszego sterownika `database` (odczyt z tabeli `cache`
w PostgreSQL, 50 000 kluczy po 2 KB, c=4): **0,557 ms**, **7 184 odczytów/s**.

Dragonfly albo Redis dałyby rząd 0,05–0,15 ms, czyli oszczędność około
**0,4–0,5 ms na odczyt**. Przy trzech odczytach na stronę to ~1,5 ms, wobec
~33 ms, które daje poprawka jednego zapytania.

Dwie rzeczy dodatkowo przemawiają przeciw:

- **Feed jest chronologiczny i spersonalizowany z założenia** (`AGENTS.md`:
  „Feed obserwowanych: chronologiczny, bez algorytmu"), więc ma z natury niski
  współczynnik trafień. Cacheowalne są tablica dnia, kolaż powitalny i listy
  tagów — rzeczy tanie już dziś.
- **`AGENTS.md` wprost wyklucza Redisa.** Dragonfly mówi protokołem Redisa,
  więc to zmiana decyzji architektonicznej z dziennika, a nie dobór biblioteki.
  Jeśli ma zapaść, powinna zapaść jako wpis w `docs/DECISIONS.md`; zmierzone
  0,5 ms takim uzasadnieniem nie jest.

---

## 10. Co zrobić zamiast przepisywania

W kolejności zwrotu z godziny pracy:

| # | Zmiana | Zysk | Koszt |
|---|---|---|---|
| 1 | `LEFT JOIN` zamiast `orWhereHas('recipe')` w `Post::scopeZWidocznymPrzepisem()` | 17× na zapytaniu, 11× na przepustowości strony | godziny |
| 2 | To samo dla podzapytań liczących zapisy w `ZapisyWpisu` | ~10 ms przy skali L | godziny |
| 3 | `EXISTS`/`JOIN` zamiast `pluck` + `whereIn` w `FollowingFeed` | 175 ms → jednostki ms dla kont z 10 tys. obserwowanych | 1 dzień |
| 4 | Podręczna pamięć przygotowanych zapytań w połączeniu | 5,5 ms → 2,2 ms na stronę | godziny |
| 5 | Zmierzyć narzut Laravela wg §8 | podstawa do dalszych decyzji | godziny |
| 6 | Test regresyjny na plan zapytania (asercja „bez `Seq Scan` na `recipes`") | chroni przed nawrotem z §4.3 | godziny |
| 7 | Powtórzyć §4 i §5 na PostgreSQL 18 | potwierdza, że przeskok planu zachowuje się tak samo | godziny |

Pozycje 1–4 to około **dwóch dni pracy** i według pomiarów dają **11×** na
przepustowości tej strony. Przepisanie na Go + GORM to rząd **wielu miesięcy**
— 341 plików w `app/`, 545 plików testów z 3824 metodami testowymi, 154 widoki
Blade, warstwa Policy, moderacja, DSA i RODO — a w zmierzonej warstwie danych
kończy się wynikiem **gorszym**, nie lepszym.

Uczciwie: powyższe nie rozstrzyga o całym stosie, bo narzut frameworka jest
niezmierzony. Rozstrzyga o tym, że **kolejność jest odwrotna niż zakładana** —
najpierw poprawki zapytań i pomiar z §8, dopiero potem rozmowa o języku.

Tailwind można zostawić; to jedyny element planu, którego pomiary nie
podważają, bo z wydajnością serwera nie ma nic wspólnego.

---

## 11. Jak powtórzyć pomiar

Narzędzie leży w `docs/obciazenie/`:

```bash
cd docs/obciazenie
export PGHOST=127.0.0.1 PGUSER=kuking PGPASSWORD=kuking BENCH_SQL_DIR="$PWD"

./build_scale.sh kuking_bench_m 20000 400000 100000

# kontrola zgodności — najpierw to, potem czasy
BENCH_VERIFY=1 BENCH_FIX=1 BENCH_DB=kuking_bench_m BENCH_ITER=1 php feed_php.php
cd gobench && go build -o kbench . && cd ..
BENCH_VERIFY=1 BENCH_FIX=1 BENCH_DB=kuking_bench_m BENCH_ITER=1 BENCH_MODE=pgx  gobench/kbench
BENCH_VERIFY=1 BENCH_FIX=1 BENCH_DB=kuking_bench_m BENCH_ITER=1 BENCH_MODE=gorm gobench/kbench

# czasy
BENCH_FIX=1 BENCH_CACHE=1 BENCH_DB=kuking_bench_m BENCH_ITER=150 php feed_php.php
BENCH_FIX=1 BENCH_DB=kuking_bench_m BENCH_ITER=150 BENCH_MODE=gorm gobench/kbench

# przepustowość samego zapytania
pgbench -n -T 8 -c 16 -f feed_orig_pgbench.sql -d kuking_bench_m
pgbench -n -T 8 -c 16 -f feed_fix_pgbench.sql  -d kuking_bench_m
```

Zmienne: `BENCH_FIX=1` włącza poprawione zapytanie, `BENCH_CACHE=1` podręczną
pamięć `prepare`, `BENCH_VERIFY=1` kontrolę zgodności, `BENCH_MODE` wybiera
`pgx` albo `gorm`, `BENCH_SQL_DIR` wskazuje katalog z plikami SQL.
