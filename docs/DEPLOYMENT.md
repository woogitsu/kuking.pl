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
| `worker` (osobny kontener) | 4 procesy: `high`, `default`, `media`, `low` | żadna kolejka nie czeka za zaległością innej; `high` (listy wejścia na konto, B8-06) to lekki proces z samymi e-mailami |
| `all` (produkcja dziś, jeden kontener 1024 MB) | 1 proces: `high,default,media,low` | trzy szczyty pamięci naraz (zdjęcie 50 Mpx ~452 MB, eksport do 512M, WWW) to OOM, który kładzie też stronę |

W roli `all` kolejność na liście to **ścisły priorytet**: przy stałej
zaległości `default` (np. fala maili) zdjęcia i eksporty czekają. Widać to
w `php artisan kuking:sprawdz-kolejke` — rośnie zaległość `media` albo `low`
przy żywym `default`. Lekarstwo docelowe: osobny serwis `worker`
(`PRODUCTION_SPLIT_SERVICES` w `.railway/railway.ts`).

Ręczne sterowanie, bez wdrożenia kodu (zmienna w panelu Railway + restart):

- `QUEUE_WORKERS` — procesy rozdzielone **spacją**, w każdym lista po
  przecinku. Wygrywa z domyślną wartością każdej roli. Przykład dla `all`
  przy dużym zapasie pamięci: `QUEUE_WORKERS="high,default,low media"`.
  Każda lista musi zawierać `high` — inaczej listy logowania zostaną w bazie.
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

## Konto gospodarza (`KUKING_HOST_USER_ID`, #1089)

Mechanizmy społeczności (auto-obserwowanie przy rejestracji, alert pierwszego
wpisu, wykluczenia w analityce) rozpoznają gospodarza po stabilnym UUID konta,
nie po nazwie profilu. Nazwę da się zmienić, a zwolnioną może zająć ktoś inny.

Przed wdrożeniem kodu z #1089:

1. Odczytaj UUID aktualnego konta gospodarza zapytaniem **tylko do odczytu**
   (np. w konsoli bazy z rolą bez prawa zapisu albo w transakcji
   `BEGIN READ ONLY; … ROLLBACK;`):

   ```sql
   SELECT u.id, p.username
   FROM users u JOIN profiles p ON p.user_id = u.id
   WHERE lower(p.username) = lower('<obecna nazwa gospodarza>');
   ```

   Oczekiwany wynik: dokładnie jeden wiersz. Zero albo więcej wierszy —
   zatrzymaj się i wyjaśnij, zanim cokolwiek ustawisz.
2. Wpisz wartość `id` do **Shared Variable** `KUKING_HOST_USER_ID` środowiska
   (wartość tylko w platformie, nie w repozytorium). `railway.ts` przekazuje
   ją web i schedulerowi; każde środowisko ma własny UUID.
3. Wdróż kod. Dopiero potem gospodarz może zmienić nazwę profilu.

Zachowanie konfiguracji:

- **`KUKING_HOST_USER_ID` pusty** — działa przejściowy fallback po nazwie
  z `KUKING_HOST_USERNAME` (jak przed #1089). Jest bezpieczny tylko dopóki
  nikt nie zmienia nazwy konta gospodarza.
- **`KUKING_HOST_USER_ID` ustawiony poprawnie** — nazwa profilu nie ma
  znaczenia; `KUKING_HOST_USERNAME` nie jest czytane przez te mechanizmy.
- **`KUKING_HOST_USER_ID` ustawiony, ale błędny** (nie-UUID albo nieistniejące
  konto) — serwis celowo działa **bez gospodarza** i nie cofa się do nazwy.
  Nowi użytkownicy nie obserwują nikogo automatycznie, alert pierwszego wpisu
  nie trafia do nikogo. Sprawdź wartość i popraw zmienną.

Migracja `2026_09_21_100900_create_first_post_events` (już wdrożona) nie jest
zmieniana: jej backfill wykonał się raz, po nazwie gospodarza z dnia
wdrożenia. Jej `down()` nadal odtwarza dane po `KUKING_HOST_USERNAME`; jeżeli
nazwa gospodarza się zmieni, rollback tej migracji może świadomie odmówić
(D-088) — to bezpieczny kierunek, dane zostają.

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
