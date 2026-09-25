# Deployment: GitHub + Railway

## Topologia startowa

```text
Kuking
├── web
└── postgres
```

Queue MVP: database.

Po wzroście (`PRODUCTION_SPLIT_SERVICES = true` w `.railway/railway.ts`):
```text
Kuking
├── web
├── worker
├── scheduler
└── postgres
```

`scheduler` to **długo działający** proces Laravel `schedule:work` (nie
Railway Cron — ten ma granulację 5 minut, a `everyMinute()` wymaga odpytania
co minutę). Ma zawsze dokładnie 1 replikę: dwie odpalałyby ten sam
harmonogram dwa razy. Pełne uzasadnienie: `docs/infra/INFRA_DECISION.md`
§5, kontrakt ról: `.railway/railway.ts`.

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
