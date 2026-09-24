# Deployment: GitHub + Railway

## Topologia startowa

```text
Kuking
├── web
└── postgres
```

Queue MVP: database.

Po wzroście:
```text
Kuking
├── web
├── worker
├── postgres
└── cron
```

Zdjęcia docelowo: Cloudflare R2.

## GitHub

- `main` → production;
- `staging` → staging;
- feature branches → PR.

Włączyć Railway **Wait for CI**.

## Railway IaC

Nowy kierunek Railway to:
`.railway/railway.ts`

Nie projektować nowego repo wokół starego `railway.json` / `railway.toml`, ponieważ Config as Code jest oznaczone jako deprecated.

## Production env

- `APP_ENV=production`;
- `APP_DEBUG=false`;
- sekrety tylko w platformie;
- osobne staging secrets;
- HTTPS;
- healthcheck;
- backup.

## Kolejki — ile procesów `queue:work` (runbook, #1030)

Liczbę procesów i ich kolejki wybiera `listy_kolejek()` w
`docker/entrypoint.sh`:

| Rola | Domyślnie | Dlaczego |
|------|-----------|----------|
| `worker` (osobny kontener) | 3 procesy: `default`, `media`, `low` | żadna kolejka nie czeka za zaległością innej |
| `all` (produkcja dziś, jeden kontener 1024 MB) | 1 proces: `default,media,low` | trzy szczyty pamięci naraz (zdjęcie 50 Mpx ~452 MB, eksport do 512M, WWW) to OOM, który kładzie też stronę |

W roli `all` kolejność na liście to **ścisły priorytet**: przy stałej
zaległości `default` (np. fala maili) zdjęcia i eksporty czekają. Widać to
w `php artisan kuking:sprawdz-kolejke` — rośnie zaległość `media` albo `low`
przy żywym `default`. Lekarstwo docelowe: osobny serwis `worker`
(`PRODUCTION_SPLIT_SERVICES` w `.railway/railway.ts`).

Ręczne sterowanie, bez wdrożenia kodu (zmienna w panelu Railway + restart):

- `QUEUE_WORKERS` — procesy rozdzielone **spacją**, w każdym lista po
  przecinku. Wygrywa z domyślną wartością każdej roli. Przykład dla `all`
  przy dużym zapasie pamięci: `QUEUE_WORKERS="default,low media"`.
  **Nigdy** nie dawaj `media` do dwóch procesów.
- `QUEUE_NAMES` — dawna zmienna: lista po przecinku dla **jednego** procesu.
  Działa jako alias, gdy `QUEUE_WORKERS` nie jest ustawione; przy obu naraz
  `QUEUE_NAMES` jest pomijane z ostrzeżeniem w logu.

Zatrzymanie (deploy, SIGTERM): entrypoint przekazuje TERM każdemu procesowi
`queue:work` i czeka, aż dokończy bieżące zadanie. Okno na to daje
`drainingSeconds` w `.railway/railway.ts` (serwis `worker` 120 s, rola `all`
30 s — wystarcza na zdjęcie, nie zawsze na eksport); po nim Railway wysyła
SIGKILL, a przerwane zadanie wraca do kolejki po `retry_after` i jest
ponawiane.

## Migrations

Preferowany rollout:
```text
build
→ CI passed
→ deploy
→ migrate --force
→ healthcheck
```

Ryzykowne zmiany:
`expand → migrate/backfill → switch → contract`.

## Rollback

Kod:
- poprzedni commit/deployment.

Baza:
- migracje mają być backward-compatible;
- rollback kodu nie naprawia automatycznie destrukcyjnej migracji.

## Staging

Osobna:
- baza;
- storage;
- secrets.

Nie kopiować produkcyjnych danych użytkowników bez potrzeby i podstawy.
