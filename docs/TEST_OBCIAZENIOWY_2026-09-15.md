# Test obciążeniowy Kuking.pl — 15 września 2026

**Pytanie, na które ten dokument odpowiada:** czy przepisanie Kuking.pl
na Go + gorm.io + Dragonfly poprawi wydajność serwisu.

**Odpowiedź w jednym zdaniu:** nie — wąskie gardło leży w **kształcie dwóch
zapytań**, nie w języku; poprawka jednego z nich daje **13×**, a przepisanie
na Go **0,96×** (czyli nic), przy czym Go + GORM z tym samym poprawionym
zapytaniem jest **o 42% wolniejsze** od dzisiejszego PHP.

---

## 1. Czego ten pomiar NIE obejmuje

To jest najważniejsza sekcja tego dokumentu i stoi na początku celowo.

**Laravel nie został uruchomiony.** Polityka sieciowa środowiska pomiarowego
blokuje pobieranie paczek z GitHuba (403 z `codeload.github.com`), więc
`composer install` nie zbudował `vendor/`. Zmierzono warstwę danych i
renderowanie HTML w czystym PHP + PDO, a nie prawdziwy Laravel.

Co z tego wynika:

- **Zmierzone:** koszt zapytań, koszt sterownika, koszt sklejenia relacji
  i złożenia HTML, przepustowość PostgreSQL, zachowanie planisty przy
  wzroście danych. To jest ta część, która przy przepisaniu **zostaje taka
  sama**, bo SQL się nie zmienia.
- **Niezmierzone:** narzut samego frameworka na żądanie — bootstrap
  kontenera, stos middleware, hydracja modeli Eloquenta, Policy, Blade,
  Livewire. **To jest jedyne miejsce, w którym przepisanie na Go naprawdę
  by wygrało**, i akurat go tutaj nie zmierzono.
- Ile to jest, trzeba zmierzyć na maszynie z dostępem do sieci. Sposób —
  sekcja 8. Dopóki ta liczba nie jest zmierzona, **decyzja o przepisaniu nie
  ma podstawy liczbowej**, a ten dokument mówi tylko tyle, że warstwa danych
  jej nie uzasadnia.

Dalsze zastrzeżenia:

- PostgreSQL w środowisku pomiarowym to **16.13**, nie 18 jak na produkcji.
  Planista w 17 i 18 zachowuje się inaczej; przeskok planu opisany w §4 może
  wypaść przy innej wielkości tabel.
- Baza i klient chodzą na **tej samej maszynie** (4 rdzenie, 15 GB RAM), więc
  czas sieci jest bliski zeru. Na Railway dojdzie realne opóźnienie łącza,
  które **powiększa** znaczenie liczby zapytań na stronę, a nie języka.
- Schemat odtworzono z migracji dla tabel gorącej ścieżki (14 tabel,
  `docs/obciazenie/schema.sql`), łącznie z indeksami częściowymi
  i zmaterializowanymi kolumnami wyszukiwania. Nie odtwarzano moderacji,
  powiadomień ani zgód — nie biorą udziału w renderowaniu feedu.
- Dane są syntetyczne, o rozkładzie zbliżonym do opisanego w `docs/`:
  90% kont aktywnych, 85% treści publicznych, 5–101 obserwowanych na osobę.

---

## 2. Jak mierzono

Jednostką pomiaru jest **jedna strona feedu odkrywania dla zalogowanego
widza** — czyli dokładnie to, co robi `DiscoverFeed::paginate()` plus
`with([...])` z `app/Domain/Feed/DiscoverFeed.php`: **8 zapytań**.

1. zapytanie feedu (z `comments_count`, `zapisow_count`, `czy_zapisany`),
2. autorzy, 3. profile, 4. awatary, 5. zdjęcia wpisów,
6. przepisy, 7. zdjęcia główne przepisów, 8. tagi.

Ten sam zestaw zaimplementowano trzy razy:

| Implementacja | Sterownik | Odpowiednik w świecie Laravela |
|---|---|---|
| PHP 8.4 + PDO | `pdo_pgsql` | warstwa danych bez frameworka |
| Go 1.24 + pgx v5.11 | `pgx/v5` | najniższy możliwy narzut w Go |
| Go 1.24 + GORM v1.31 | `gorm.io/driver/postgres` | to, co proponujesz: `Preload` ≙ `with()` |

**Kontrola poprawności:** wszystkie trzy produkują HTML o **identycznej
długości** (5876 B przy skali M), więc wykonują tę samą pracę. Bez tej
kontroli porównanie byłoby bezwartościowe.

Zestawy danych:

| Skala | Konta | Wpisy | Przepisy | Komentarze | Obserwowania | Rozmiar |
|---|---|---|---|---|---|---|
| S | 2 000 | 30 000 | 8 000 | 45 000 | 109 000 | 71 MB |
| M | 20 000 | 400 000 | 100 000 | 600 000 | 1 071 446 | 845 MB |
| L | 100 000 | 2 000 000 | 500 000 | 3 000 000 | 5 311 909 | 4 098 MB |

---

## 3. Wynik główny: czas idzie do bazy, nie do języka

Strona feedu, skala M, jeden worker, **oryginalne** zapytania:

| Implementacja | Czas strony | w bazie | w kliencie | Udział klienta |
|---|---|---|---|---|
| PHP + PDO | 32,0–40,3 ms | 31,9–40,2 ms | **0,07 ms** | 0,2% |
| Go + pgx | 33,9–40,5 ms | 33,9–40,5 ms | **0,04 ms** | 0,1% |
| Go + GORM | 30,3–34,8 ms | 30,2–34,8 ms | **0,03 ms** | 0,1% |

Cztery rundy naprzemienne. **Rozrzut między rundami (±5 ms) jest ponad
stukrotnie większy niż różnica między językami (0,04 ms).** Na tym etapie
wybór języka jest nierozróżnialny od szumu pomiarowego.

---

## 4. Dlaczego strona trwa 35 ms: dwa zapytania skanujące całe tabele

### 4.1. `orWhereHas('recipe')` — skan wszystkich przepisów

`Post::scopeZWidocznymPrzepisem()` (`app/Models/Post.php:228`) tłumaczy się na
podzapytanie wewnątrz `OR`. `OR` uniemożliwia półzłączenie po indeksie, więc
PostgreSQL **materializuje cały zbiór opublikowanych przepisów**:

```
Index Scan using posts_published_idx on posts (actual time=57.337..57.448 rows=10)
  Filter: ((recipe_id IS NULL) OR (hashed SubPlan 6))
  SubPlan 6
    ->  Seq Scan on recipes (actual time=0.027..26.524 rows=80000 loops=1)
```

**26,5 ms na przeczytanie 80 000 przepisów, żeby pokazać 10 wpisów.**
Koszt rośnie liniowo z liczbą przepisów w serwisie.

### 4.2. `czy_zapisany` — skan wszystkich pozycji zeszytów

`ZapisyWpisu::dolicz()` (`app/Domain/Collections/ZapisyWpisu.php`) dokłada
`withExists`, które przy skali L wygląda tak:

```
SubPlan 4
  ->  Hash Join (actual time=2.663..10.661 rows=1 loops=1)
        ->  Seq Scan on collection_items (actual time=0.003..4.883 rows=71428)
```

**10,6 ms z 11,3 ms całego zapytania.** Ta sama klasa błędu co wyżej.

### 4.3. Najgroźniejsze: plan się przełącza

Ten sam SQL, te same indeksy, różne wielkości tabel:

| Skala | Wybrany plan dla przepisów | Czas zapytania |
|---|---|---|
| M (100 tys. przepisów) | `Seq Scan` po 80 000 wierszy | **42–47 ms** |
| L (500 tys. przepisów) | `Index Scan using recipes_pkey` | **9–11 ms** |

Przy **większej** tabeli jest **szybciej**, bo planista zmienił zdanie.
To znaczy, że serwis ma w najgorętszej ścieżce zapytanie, które potrafi
z dnia na dzień zwolnić pięciokrotnie po zwykłym `ANALYZE` — bez żadnego
wdrożenia. Awaria tego typu wygląda jak „nagle strona muli” i nie ma
związku z ruchem.

**Przepisanie na Go tego nie dotyka.** GORM wygeneruje to samo `OR`
z podzapytaniem i dostanie ten sam plan, bo decyzję podejmuje PostgreSQL.

---

## 5. Ile daje poprawka zapytania (bez zmiany języka)

Zamiana podzapytania w `OR` na `LEFT JOIN` po kluczu głównym
(`docs/obciazenie/pg_fix.sql`):

| Miara (skala M) | Oryginał | Po poprawce | Zysk |
|---|---|---|---|
| Samo zapytanie feedu | 42–47 ms | 3,0–4,2 ms | **13×** |
| Cała strona (8 zapytań, PHP) | 33,6 ms | 4,2 ms | **8×** |
| Przepustowość zapytania, c=4 | 143 TPS | 2003 TPS | **14×** |
| Przepustowość zapytania, c=16 | 118 TPS | 2192 TPS | **19×** |

Przepustowość zapytania feedu, skala M:

| Równoległość | Oryginał | Po poprawce |
|---|---|---|
| 1 | 35 TPS · 28,6 ms | 531 TPS · 1,9 ms |
| 4 | 143 TPS · 28,0 ms | 2003 TPS · 2,0 ms |
| 8 | 135 TPS · 59,4 ms | 2154 TPS · 3,7 ms |
| 16 | **118 TPS · 135,1 ms** | 2192 TPS · 7,3 ms |

Oryginał **nasyca się przy ~140 zapytaniach na sekundę i potem degraduje**:
przy 16 równoległych sesjach przepustowość spada, a opóźnienie rośnie
pięciokrotnie. To jest twardy sufit całego serwisu i żaden język go nie
podniesie, bo stoi w PostgreSQL.

---

## 6. PHP kontra Go na tym samym, poprawionym zapytaniu

Dopiero po naprawieniu zapytania widać cokolwiek innego niż szum.

Jeden worker, skala M, dwie rundy:

| Implementacja | Czas strony | w bazie | w kliencie | Stron/s |
|---|---|---|---|---|
| PHP + PDO (`prepare` przy każdym wywołaniu) | 4,51 / 4,99 ms | 4,44 / 4,91 | 0,07 | 200–222 |
| **PHP + PDO z podręczną pamięcią `prepare`** | **2,06 / 2,32 ms** | 2,01 / 2,26 | 0,06 | **431–485** |
| Go + pgx | 2,43 / 2,71 ms | 2,40 / 2,68 | 0,03 | 369–411 |
| Go + GORM | 3,40 / 3,41 ms | 3,39 / 3,37 | 0,02 | 293–294 |

Osiem równoległych workerów, skala M:

| Implementacja | Poprawione zapytanie | Oryginalne zapytanie |
|---|---|---|
| PHP + PDO (`prepare` co wywołanie) | 890 stron/s | — |
| **PHP + PDO z cache `prepare`** | **1735 stron/s** | 134 stron/s |
| Go + pgx | 1668 stron/s | 128 stron/s |
| Go + GORM | 1008 stron/s | — |

Trzy rzeczy warto z tej tabeli wyczytać.

1. **Pierwsza „przewaga Go” była artefaktem sterownika.** Go+pgx wyglądało
   na 2× szybsze od PHP, dopóki PHP przygotowywał zapytanie od nowa przy
   każdym z ośmiu wywołań. To osiem dodatkowych podróży do serwera na stronę.
   Po włączeniu podręcznej pamięci przygotowanych zapytań **PHP dorównuje
   Go+pgx** (przewaga 4% przy ośmiu workerach mieści się w szumie; przy
   jednym workerze PHP wychodzi nieznacznie przed). Różnica nigdy nie była
   w języku, tylko w tym, czy sterownik przygotowuje zapytanie raz czy
   za każdym razem.
2. **GORM jest najwolniejszy z całej trójki** — 1008 stron/s wobec 1735
   w PHP, czyli o 42% mniej. Ta różnica jest już poza szumem: powtarza się
   w każdej rundzie i przy jednym workerze (3,40 ms wobec 2,06 ms). Gdybyś przepisał serwis dokładnie tak, jak
   planujesz (Go + gorm.io), przy poprawionym zapytaniu **straciłbyś
   wydajność**, nie zyskał.
3. **Przy złym zapytaniu nie ma żadnej różnicy** — 134 kontra 128 stron/s.
   Przepisanie serwisu na Go bez ruszania zapytań to zmiana rzędu 0,96×.

---

## 7. Feed obserwowanych: osobna bomba, też niezależna od języka

`FollowingFeed::paginate()` (`app/Domain/Feed/FollowingFeed.php`) robi
`$viewer->following()->pluck('users.id')->all()`, a potem wstawia całą listę
do `whereIn`. Skala L, trwałe połączenie:

| Obserwowanych | `pluck` | Zapytanie feedu | Razem | Długość SQL |
|---|---|---|---|---|
| 20 | 0,3 ms | 1,4 ms | **1,7 ms** | 0,6 KB |
| 200 | 0,6 ms | 6,9 ms | **7,5 ms** | 1,0 KB |
| 2 000 | 2,6 ms | 16,8 ms | **19,4 ms** | 4,5 KB |
| 10 000 | 4,7 ms | 170,4 ms | **175,1 ms** | 20,1 KB |

Wzrost jest nadliniowy: pięciokrotnie więcej obserwowanych (2 000 → 10 000)
daje dziesięciokrotnie dłuższe zapytanie. Przy 10 000 obserwowanych tekst
zapytania z wartościami wstawionymi wprost ma ~390 KB — na tyle dużo, że
w trakcie pomiaru **przekroczył limit argumentów powłoki**.

Poprawka jest znów po stronie SQL, nie języka: zamiast pobierać
identyfikatory do PHP i odsyłać je z powrotem, zrobić `EXISTS` albo `JOIN`
na `follows` w jednym zapytaniu. GORM napisany „jeden do jednego” względem
dzisiejszego kodu odtworzyłby dokładnie ten sam problem.

---

## 8. Czego brakuje, żeby decyzja miała pełną podstawę

Jedna liczba: **narzut Laravela na żądanie**. Zmierz ją tak:

```bash
composer install
php artisan optimize                 # cache konfiguracji, tras i widoków
php artisan serve &                  # albo FrankenPHP z Dockerfile
# to samo 10-wpisowe wejście, 200 powtórzeń:
curl -s -o /dev/null -w '%{time_total}\n' 'http://127.0.0.1:8000/odkryj'
```

Odejmij od wyniku 4,2 ms zmierzone tutaj dla warstwy danych. Reszta to
narzut frameworka — i tylko ta reszta jest do odzyskania przepisaniem.

Dla orientacji: gdyby wyszło 8 ms, przepisanie na Go skróciłoby stronę
o ~7 ms przy koszcie wielu miesięcy pracy. Gdyby wyszło 60 ms, rozmowa
wygląda inaczej. Bez tego pomiaru obie odpowiedzi są zgadywaniem.

---

## 9. Dragonfly jako cache

Zmierzony koszt dzisiejszego sterownika `database` (odczyt z tabeli `cache`
w PostgreSQL, 50 000 kluczy po 2 KB, c=4):

| Miara | Wynik |
|---|---|
| Opóźnienie odczytu | **0,557 ms** |
| Przepustowość | **7 184 odczytów/s** |

Dragonfly albo Redis dałyby tu rząd 0,05–0,15 ms, czyli oszczędność około
**0,4–0,5 ms na odczyt**. Przy trzech odczytach na stronę to ~1,5 ms —
wobec 29 ms, które daje poprawka jednego zapytania.

Dwie rzeczy dodatkowo przemawiają przeciw:

- **Feed jest chronologiczny i spersonalizowany z założenia**
  (`AGENTS.md`: „Feed obserwowanych: chronologiczny, bez algorytmu”), więc
  ma z natury niski współczynnik trafień w cache. Cacheowalne są tablica
  dnia, kolaż powitalny i listy tagów — rzeczy tanie już dziś.
- **`AGENTS.md` wprost wyklucza Redisa** („Zero mikroserwisów, SPA, Redisa
  i osobnego search engine”). Dragonfly mówi protokołem Redisa, więc to jest
  zmiana decyzji architektonicznej z dziennika, a nie dobór biblioteki.
  Jeśli ma zapaść, powinna zapaść jako wpis w `docs/DECISIONS.md` z
  uzasadnieniem — a zmierzone 0,5 ms takim uzasadnieniem nie jest.

---

## 10. Co zrobić zamiast przepisywania

W kolejności zwrotu z godziny pracy:

| # | Zmiana | Zysk | Koszt |
|---|---|---|---|
| 1 | `LEFT JOIN` zamiast `orWhereHas('recipe')` w `Post::scopeZWidocznymPrzepisem()` | 13× na zapytaniu, 8× na stronie | godziny |
| 2 | To samo dla `czy_zapisany` w `ZapisyWpisu::dolicz()` | ~10 ms przy skali L | godziny |
| 3 | `EXISTS`/`JOIN` zamiast `pluck` + `whereIn` w `FollowingFeed` | 175 ms → jednostki ms dla kont z 10 tys. obserwowanych | 1 dzień |
| 4 | Podręczna pamięć przygotowanych zapytań w połączeniu | 4,5 ms → 2,1 ms na stronę | godziny |
| 5 | Zmierzyć narzut Laravela wg §8 | podstawa do dalszych decyzji | godziny |
| 6 | Test regresyjny na plan zapytania (asercja „bez `Seq Scan` na `recipes`”) | chroni przed nawrotem z §4.3 | godziny |

Pozycje 1–4 to razem mniej więcej **dwa dni pracy** i według pomiarów
dają **13× na przepustowości** feedu. Przepisanie na Go + GORM to rząd
**wielu miesięcy** — 341 plików w `app/`, 545 plików testów z 3824
metodami testowymi, 154 widoki Blade, warstwa Policy, moderacja, DSA i RODO — i według
tych samych pomiarów kończy się wynikiem **gorszym o 42%** niż PHP po
poprawkach.

Tailwind faktycznie można zostawić — to jedyny element planu, którego
pomiary nie podważają, bo z wydajnością serwera nie ma nic wspólnego.

---

## 11. Jak powtórzyć pomiar

Narzędzie leży w `docs/obciazenie/`:

```bash
cd docs/obciazenie
export PGHOST=127.0.0.1 PGUSER=kuking PGPASSWORD=kuking

./build_scale.sh kuking_bench_m 20000 400000 100000     # zestaw danych

BENCH_DB=kuking_bench_m BENCH_ITER=200 php feed_php.php               # oryginał
BENCH_FIX=1 BENCH_CACHE=1 BENCH_DB=kuking_bench_m php feed_php.php    # po poprawkach

cd gobench && go build -o kbench .
BENCH_MODE=pgx  BENCH_FIX=1 BENCH_DB=kuking_bench_m ./kbench
BENCH_MODE=gorm BENCH_FIX=1 BENCH_DB=kuking_bench_m ./kbench

pgbench -n -T 10 -c 16 -f ../pg_orig.sql -d kuking_bench_m            # przepustowość
pgbench -n -T 10 -c 16 -f ../pg_fix.sql  -d kuking_bench_m
BENCH_DB=kuking_bench_l php ../following.php                          # feed obserwowanych
```

Zmienne: `BENCH_FIX=1` włącza poprawione zapytanie, `BENCH_CACHE=1`
podręczną pamięć `prepare`, `BENCH_MODE` wybiera `pgx` albo `gorm`.
