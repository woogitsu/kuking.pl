# Audyt wydajności bazy danych (30 września 2026)

Obszar: wydajność bazy. Baza kodu: `origin/claude/paczka-i-kandydat` w `5548c7e16`.
Dokument w budowie (zapisywany przyrostowo).

## Wyniki cząstkowe

### Środowisko pomiaru

- PostgreSQL **16.13** lokalnie na 127.0.0.1:5432 (nie 18, choć dokumenty z #599 i #1952 podają 18.6
  na tym samym porcie). Ustawienia domyślne: `jit = on`, `jit_above_cost = 100000`,
  `max_connections = 100`, `shared_buffers = 128MB`, `work_mem = 4MB`.
- Maszyna: 4 CPU, obciążenie ok. 7 (współdzielona z innymi agentami). Czasy mówią o proporcjach.
- Baza `kuking_audyt_perf_af7a`: `migrate` + `db:seed` (DemoSeeder), potem `INSERT … SELECT` na
  prawdziwym schemacie: 5016 kont, 6041 przepisów (48 tys. składników, 30 tys. kroków), 30 088 wpisów
  (29 tys. zdjęć, 60 tys. tagów), 20 tys. wykonań, 40 tys. komentarzy, 100 tys. obserwacji,
  30 tys. reakcji, 2000 zeszytów po 20 pozycji, 62 tys. powiadomień (konto `marek`: 2000),
  1500 zgłoszeń. Po wszystkim `ANALYZE`. 163 MB.
- Przyrząd: skrypt PHP uruchamia kernel HTTP Laravela (świeża aplikacja na żądanie),
  `DB::listen` liczy zapytania i ich czas, mediana z 2–3 przebiegów po rozgrzewce.

### JIT poza feedem obserwowanych (#599 naprawił tylko `FollowingFeed`)

| Zapytanie | Koszt planu | Wykonanie z JIT | JIT | Wykonanie bez JIT (`SET jit=off`) |
|---|---:|---:|---:|---:|
| `/odkryj` zalogowany, główne (rotacja autorów) | 524 600 | 2008–2652 ms | 1864–2483 ms | 116–150 ms |
| `/powiadomienia`, `COUNT(*)` z `paginate()` (2000 powiadomień) | 319 599 | 523–682 ms | 482–600 ms | 6–14 ms |
| `/` gość, główne (rotacja) | 317 120 | 149–317 ms | 48–115 ms | 125–146 ms |
| `/odkryj` gość, główne | 317 831 | 217–250 ms | 54–78 ms | 110–147 ms |

### Kolejka `database`: pobieranie zadania przy zaległości

- 50 tys. zadań `default`, przeplatane z `media`: `SELECT … FOR UPDATE SKIP LOCKED` 0,03–0,04 ms.
- 40 tys. zadań opóźnionych (`available_at` w przyszłości) przed dostępnymi: 29–38 ms na jedno
  pobranie (`Rows Removed by Filter: 40000–44444`, skan `jobs_pkey`).
- 49 990 zadań `default` przed 10 zadaniami `media`: planer bierze `jobs_queue_index`, 0,07–1 ms.

### Zadania: zdjęcia

- `ProcessUploadedImage` 4000×3000: 1298 ms, 6 zapytań, szczyt PHP 39 MB, RSS 192 MB.
- 8165×6124 (50 Mpx): 4058 ms, 6 zapytań, szczyt PHP 48 MB, RSS 491 MB. Zgodne z
  `docs/MEDIA_PIPELINE.md` (161 / 452 MB).
