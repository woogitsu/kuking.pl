# A7-3 — wydajność na reprezentatywnej skali

PostgreSQL 18.6. Dane syntetyczne: 10 000 użytkowników, 40 000 przepisów, 80 000 wpisów, 250 000 mediów i 250 000 `post_media`; badany użytkownik obserwuje 500 kont. Pełny skrypt odtworzeniowy: `pomiar-wydajnosci.php`.

## Wyniki

| ścieżka | mediana wall | mediana SQL | mediana czasu DB |
|---|---:|---:|---:|
| feed obserwowanych, 15 wpisów | 12,305 ms | 6 | 8,83 ms |
| `/odkryj`, gość | 7,007 ms | 5 | 4,19 ms |
| `/odkryj`, zalogowany | 8,53 ms | 7 | 5,01 ms |

Jedna strona feedu obserwowanych miała 60 zdjęć. Przejście rzeczywistej trasy `/zdjecia/{media}/feed` przez kernel HTTP dla wszystkich 60 wykonało 420 zapytań SQL = dokładnie 7 na obraz. Czas DB łącznie: 1003,96 ms; wall 1258,604 ms. Dysk lokalny, więc pomiar obejmuje Laravel, route-model binding, autoryzację i DB, ale nie sieć R2. Odpowiedzi lokalnego dysku: 60 × HTTP 200.

Nie wolno interpretować 1258 ms jako 1258 ms dodanych szeregowo do czasu renderowania strony w przeglądarce: żądania obrazów mogą być wykonywane równolegle. Ten pomiar rozstrzyga koszt backendowy i liczbę zapytań, nie produkcyjny LCP.

## `EXPLAIN (ANALYZE, BUFFERS)` — feed obserwowanych

```text
Limit time=3.135 rows=16 hits=335
  Result time=3.132 rows=16 hits=335
    Sort time=3.088 rows=16 hits=303
      Hash Join time=2.444 rows=4008 hits=303
        Bitmap Heap Scan posts time=0.749 rows=4008 hits=122
          Bitmap Index Scan posts_author_published_idx time=0.209 rows=4008 hits=41
        Hash time=1.280 rows=10000 hits=181
          Seq Scan users time=0.610 rows=10000 hits=181
    Aggregate time=0.002 rows=1 hits=32
      Nested Loop
        Index Scan comments_post_idx
        Bitmap Heap Scan blocks
          BitmapOr -> blocks_pkey / blocks_pkey
        Index Scan users_pkey
Execution Time: 3.375 ms; Shared Read Blocks: 0
```

Indeks `posts_author_published_idx` jest faktycznie używany. Sekwencyjny skan 10 tys. `users` jest wyborem planera na tej skali; pomiar nie uzasadnia dokładania indeksu.

## `EXPLAIN (ANALYZE, BUFFERS)` — `/odkryj`, gość

```text
Limit time=0.274 rows=16 hits=83
  Nested Loop
    Index Scan posts_published_idx posts time=0.015 rows=16 hits=3
    Memoize
      Index Scan users_pkey users
    Aggregate
      Index Scan comments_post_idx
      Index Scan users_pkey
Execution Time: 0.363 ms; Shared Read Blocks: 0
```

## `EXPLAIN (ANALYZE, BUFFERS)` — `/odkryj`, zalogowany

```text
Limit time=0.057 rows=16 hits=83
  Nested Loop
    Index Scan posts_published_idx posts time=0.022 rows=16 hits=3
    Index Scan users_pkey users
    Aggregate
      Nested Loop
        Index Scan comments_post_idx
        Bitmap Heap Scan blocks
          BitmapOr -> blocks_pkey / blocks_pkey
        Index Scan users_pkey
Execution Time: 0.170 ms; Shared Read Blocks: 0
```

## `/zdjecia` — kształt siedmiu zapytań na obraz

Dla gościa każdy obraz wykonywał kolejno: pobranie `media`, sprawdzenie rodzica `post`, `recipe`, `cooked_event`, `comment`, profilu/awatara oraz odczyt sesji. Wynik 7 SQL/obraz pochodzi z realnego przejścia trasy HTTP, nie z liczenia metod w kodzie.
