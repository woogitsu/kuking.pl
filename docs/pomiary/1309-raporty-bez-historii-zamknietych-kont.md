# Pomiar #1309 — raporty analityczne a historia zamkniętych kont

**Po co ten dokument.** Issue #1309 wymaga, żeby poprawa była zmierzona, nie
założona: `EXPLAIN (ANALYZE, BUFFERS)`, czas i pamięć raportów przed i po
zmianie, na reprezentatywnym zbiorze. Zmiana kodu jest w PR #1593 (gałąź
`claude/1308-1309-tagi-raporty`, commit `6b1d0271`): `CookEligibility::tylkoLiczeni()`
filtruje zamknięte, zalążkowe i wykluczone z nazwy konta przez `NOT EXISTS`
w SQL, zamiast pobierać ich UUID do PHP (`excludedUserIds()`) i wstawiać je jako
parametry `NOT IN`. Ten dokument jest tylko pomiarem — nie zmienia kodu produkcyjnego.

Data: 24 września 2026. „Przed" to `origin/main` `441e7962`, „po" to `6b1d0271`.

## Najważniejsze: przy ~16 tys. zamkniętych kont stary kod przestaje działać

Przy 50 000 zamkniętych kont `kuking:wac` i `kuking:raport` na `main` **padają**:

```text
SQLSTATE[HY000]: General error: 7 number of parameters must be between 0 and 65535
```

To nie jest spowolnienie, tylko błąd. Protokół PostgreSQL przyjmuje najwyżej
65 535 parametrów w jednym zapytaniu, a stary kod wstawia listę wykluczonych
kont (zamknięte + 12 zalążkowych + gospodarz, N) do każdej gałęzi UNION:

| Komenda | Parametrów w największym zapytaniu (zmierzone) | Przestaje działać, gdy N > |
|---|---|---|
| `kuking:raport` (kohorty) | 4·N + 2 (60 054 przy N = 15 013) | ≈ 16 383 |
| `kuking:wac` | 3·N + 2 (45 041 przy N = 15 013) | ≈ 21 844 |
| `kuking:policz-kukingow` | N + 3 (50 016 przy N = 50 013) | ≈ 65 532 |

Po zmianie z PR #1593 liczba parametrów jest stała i nie zależy od N: 17 (WAC),
najwyżej 22 w jednym zapytaniu raportu, 5 w liczniku.

## Środowisko i zbiór

- Lokalny PostgreSQL **16.13** w kontenerze sesji (4 vCPU, 15 GB RAM).
  Uwaga: projekt wymaga PostgreSQL 18 (D-227), a w tym środowisku jest tylko 16.
  Limit 65 535 parametrów wynika z protokołu i obowiązuje także w 18;
  czasy na 18 mogą się różnić.
- PHP 8.4, Laravel z `vendor/` projektu; obie wersje kodu jako osobne drzewa
  robocze z własnym `composer dump-autoload`, ta sama baza.
- Dane: [`scripts/pomiar-1309-dane.sql`](../../scripts/pomiar-1309-dane.sql) na
  pustej bazie po `migrate`. 3 000 aktywnych kont z aktywnością z ostatnich 60 dni
  (3 wpisy, 1 przepis, 4 wykonania na osobę), rejestracje z ostatnich 90 dni;
  Z zamkniętych kont (40% `banned`, 40% `pending_delete`, 20% `erased`) z historią
  sprzed 1–3 lat, a do tego co dziesiąte ma wpis z ostatnich 30 dni i co piąte
  wykonanie z ostatnich 30 dni — te MUSZĄ zostać odcięte; 12 kont zalążkowych
  i gospodarz `woogitsu`. Po wstawieniu `ANALYZE`.
- Zmierzone trzy zbiory: Z = 5 000, 15 000 i 50 000.
- Pomiar: [`scripts/pomiar-1309.php`](../../scripts/pomiar-1309.php). Woła komendy
  (nie klasy, które #1593 zmienia), więc ten sam skrypt mierzy obie wersje.
  Każda komenda raz na rozgrzewkę, potem raz mierzona: czas ściany, szczyt
  pamięci PHP ponad stan wyjściowy, każde zapytanie z parametrami, a potem
  `EXPLAIN (ANALYZE, BUFFERS)` każdego SELECT-a z tymi samymi parametrami.

## Wyniki

Kolumny: czas całej komendy; szczyt pamięci PHP; liczba zapytań; parametry
razem / w największym zapytaniu; najdłuższy tekst SQL; suma czasu planowania
i wykonania z `EXPLAIN (ANALYZE, BUFFERS)`; bufory `shared hit` (odczytów z dysku
`read` nie było w żadnym przebiegu — zbiór mieści się w pamięci).

| Z | Komenda | Wersja | Czas [ms] | Pamięć PHP [KB] | Zapytań | Parametrów (max) | SQL [znaków] | Plan. [ms] | Wyk. [ms] | Bufory hit |
|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|
| 5 000 | `kuking:wac` | przed | 111,8 | 4 047 | 4 | 15 046 (15 041) | 46 030 | 18,2 | 50,1 | 872 |
| 5 000 | `kuking:wac` | po | 37,6 | 51 | 1 | 17 (17) | 1 957 | 0,9 | 45,3 | 1 008 |
| 5 000 | `kuking:raport` | przed | 233,9 | 8 833 | 24 | 50 165 (20 054) | 61 374 | 29,3 | 76,9 | 2 572 |
| 5 000 | `kuking:raport` | po | 98,0 | 101 | 9 | 60 (22) | 2 597 | 2,8 | 102,3 | 37 377 |
| 5 000 | `kuking:policz-kukingow` | przed | 21,8 | 2 615 | 5 | 5 024 (5 016) | 15 131 | 1,8 | 3,4 | 393 |
| 5 000 | `kuking:policz-kukingow` | po | 6,1 | 23 | 2 | 8 (5) | 397 | 0,3 | 3,8 | 263 |
| 15 000 | `kuking:wac` | przed | 189,0 | 13 325 | 4 | 45 046 (45 041) | 136 030 | 39,0 | 46,5 | 1 692 |
| 15 000 | `kuking:wac` | po | 52,6 | 51 | 1 | 17 (17) | 1 957 | 0,8 | 46,5 | 2 125 |
| 15 000 | `kuking:raport` | przed | 529,6 | 22 978 | 24 | 150 165 (60 054) | 181 374 | 78,0 | 106,9 | 32 426 |
| 15 000 | `kuking:raport` | po | 134,1 | 101 | 9 | 60 (22) | 2 597 | 2,9 | 138,6 | 66 649 |
| 15 000 | `kuking:policz-kukingow` | przed | 55,1 | 7 559 | 5 | 15 024 (15 016) | 45 131 | 5,4 | 8,4 | 894 |
| 15 000 | `kuking:policz-kukingow` | po | 10,8 | 23 | 2 | 8 (5) | 397 | 0,3 | 10,1 | 597 |
| 50 000 | `kuking:wac` | przed | **błąd: > 65 535 parametrów** | | | | | | | |
| 50 000 | `kuking:wac` | po | 68,9 | 51 | 1 | 17 (17) | 1 957 | 0,7 | 81,2 | 7 345 |
| 50 000 | `kuking:raport` | przed | **błąd: > 65 535 parametrów** | | | | | | | |
| 50 000 | `kuking:raport` | po | 234,1 | 100 | 9 | 60 (22) | 2 597 | 2,8 | 236,0 | 83 734 |
| 50 000 | `kuking:policz-kukingow` | przed | 160,6 | 25 501 | 5 | 50 024 (50 016) | 150 131 | 24,4 | 20,9 | 2 647 |
| 50 000 | `kuking:policz-kukingow` | po | 26,0 | 23 | 2 | 8 (5) | 397 | 0,3 | 28,2 | 1 766 |

### Jak to czytać — uczciwie w obie strony

- **Wygrana jest po stronie PHP, protokołu i planowania, nie wykonania SQL.**
  Stary kod płacił za: trzy dodatkowe zapytania o listę UUID, trzymanie jej
  w PHP (pamięć rośnie liniowo: 4 → 13 → 25 MB dla WAC/licznika, 23 MB dla
  raportu przy 15 tys.), przesłanie jej w tekście SQL (do 181 tys. znaków) i jej
  parsowanie przez planer (planowanie WAC 39 ms przy 15 tys. wobec 0,8 ms po).
  Czas całej komendy spada 3,5–5×.
- **Samo wykonanie w bazie nie jest szybsze, a w raporcie jest wolniejsze.**
  WAC przy 15 tys.: 46,5 ms przed i po. Raport: 106,9 → 138,6 ms przy 15 tys.
  (+30%), bufory hit 32 tys. → 67 tys. Powód widać w planie: `NOT EXISTS` robi
  `Hash Anti Join` z `users` w **każdej** gałęzi UNION i w każdym zapytaniu
  kohorty, czyli przy każdym zapytaniu skanuje `users` od nowa, zamiast raz
  pobrać listę. Przy tym rozmiarze to wciąż mniej niż zaoszczędzone planowanie
  i narzut PHP, a koszt rośnie z liczbą wierszy aktywności, nie z liczbą
  zamkniętych kont. Jeśli raport kiedyś stanie się wąskim gardłem, pierwszym
  krokiem jest wspólne CTE z wykluczonymi kontami — nie powrót do listy UUID.
- **Poprawność.** Wynik każdej komendy (sha1 wyjścia) jest identyczny przed i po
  na zbiorach 5 i 15 tys. Jedyna różnica w pierwszym przebiegu (`kuking:raport`,
  15 tys., mianownik D7 2 760 zamiast 2 761) wynikła z upływu czasu między
  przebiegami: konto `8c4ee102…` przekroczyło granicę 7 dni. Ponowny przebieg
  `main` dał ten sam skrót co PR (`3e61b7f4`). Przy 50 tys. „przed" nie ma wyniku,
  więc WAC „po" sprawdzono niezależnym SQL-em po surowych tabelach — liczby
  tygodni 2026-08-24…2026-09-21 (1 903, 1 882, 1 882, 1 878, 1 280) są
  identyczne, a bez wykluczenia byłoby ich 4 277–5 118, czyli zamknięte konta
  z aktywnością z ostatnich 30 dni naprawdę są w danych i naprawdę są odcinane.

## Plan `EXPLAIN (ANALYZE, BUFFERS)` — WAC, Z = 15 000

Plany skrócone do węzłów, które niosą różnicę; pełne plany daje skrypt.

**Przed** (`441e7962`): 45 041 parametrów, trzy `Seq Scan` z filtrem
`author_id <> ALL ('{…15 013 UUID…}')`, planowanie dłuższe od wykonania.

```text
GroupAggregate  (actual time=37.730..41.104 rows=10 loops=1)
  Buffers: shared hit=1095
  ->  Sort  (actual time=37.460..38.712 rows=24000 loops=1)
        ->  Append  (actual time=0.541..17.418 rows=24000 loops=1)
              ->  Seq Scan on posts  (actual time=0.539..8.100 rows=9000 loops=1)
                    Filter: (... AND (author_id <> ALL ('{29fd8265-…, …}'::uuid[])))
                    Rows Removed by Filter: 31515
              ->  Seq Scan on recipes  (actual time=0.572..1.462 rows=3000 loops=1)
                    Filter: (... AND (author_id <> ALL ('{…}'::uuid[])))
              ->  Seq Scan on cooked_events  (actual time=0.548..4.694 rows=12000 loops=1)
                    Filter: (user_id <> ALL ('{…}'::uuid[]))
                    Rows Removed by Filter: 15000
Planning Time: 38.747 ms
Execution Time: 41.206 ms
```

Do tego trzy zapytania wcześniej (`select id from users where status in (…)`,
`… where is_seeded`, `… profiles where lower(username) in (…)`), łącznie ~5 ms.

**Po** (`6b1d0271`): 17 parametrów, `Hash Anti Join` z `users` i `Nested Loop
Anti Join` z indeksem `profiles_username_lower_unique` w każdej gałęzi.

```text
GroupAggregate  (actual time=37.564..46.370 rows=10 loops=1)
  Buffers: shared hit=2125
  ->  Gather Merge  (Workers Launched: 2)
        ->  Parallel Append  (actual time=10.371..21.923 rows=8000 loops=3)
              ->  Nested Loop Anti Join  (rows=9000)            -- posts
                    ->  Hash Anti Join  (actual time=6.399..19.617 rows=9003 loops=1)
                          Hash Cond: (posts.author_id = wykluczone_konto.id)
                          ->  Seq Scan on posts  (rows=40515)
                          ->  Hash  (rows=15012)  Memory Usage: 832kB
                                ->  Seq Scan on users wykluczone_konto
                                      Filter: ((status = ANY ('{banned,pending_delete,erased}')) OR is_seeded)
                    ->  Index Scan using profiles_username_lower_unique on profiles wykluczony_profil
                          Index Cond: (lower(username) = 'woogitsu')
              ->  Nested Loop Anti Join  (rows=12000)           -- cooked_events, ten sam kształt
              ->  Nested Loop Anti Join  (rows=3000)            -- recipes, Hash Right Anti Join
Planning Time: 0.764 ms
Execution Time: 46.491 ms
```

## Jak powtórzyć

```bash
createdb kuking_pomiar_1309                                  # lokalnie, nigdy produkcja
DB_DATABASE=kuking_pomiar_1309 php artisan migrate --force
psql -v zamkniete=50000 -d kuking_pomiar_1309 -f scripts/pomiar-1309-dane.sql
git worktree add ../przed origin/main && git worktree add ../po <gałąź-z-#1593>
# w każdym drzewie: cp -r vendor, cp .env, composer dump-autoload
DB_DATABASE=kuking_pomiar_1309 php scripts/pomiar-1309.php ../przed plany-przed.txt > przed.json
DB_DATABASE=kuking_pomiar_1309 php scripts/pomiar-1309.php ../po plany-po.txt > po.json
```

Skrypt odmawia pracy poza lokalną bazą o nazwie zaczynającej się od
`kuking_pomiar_1309`.

## Czego ten pomiar nie mówi

- Nic o produkcji: nie łączono się z nią, liczba zamkniętych kont na produkcji
  nie jest tu znana. Próg awarii (~16 tys. dla raportu kohort) jest daleko
  od skali alfy, ale rośnie wyłącznie w jedną stronę.
- Czasy są z jednego przebiegu po rozgrzewce na PostgreSQL 16, nie 18.
  Powtórny przebieg `main` na 15 tys. różnił się o 2–10% (WAC 189 → 174 ms,
  raport 530 → 516 ms, licznik 55 → 54 ms). Rząd wielkości i liczby parametrów
  są stabilne, pojedyncze milisekundy nie.
- Test regresyjny z kryteriów #1309 (liczba parametrów niezależna od liczby
  zamkniętych kont, z kontrolą ujemną) jest w PR #1593
  (`RaportyNieNiosaHistoriiZamknietychKontTest`), nie tutaj.
