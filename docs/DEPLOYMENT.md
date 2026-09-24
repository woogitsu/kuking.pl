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

## Dziennik serwera i polityka prywatności

Produkcja loguje na `stderr` (`.railway/railway.ts` → `LOG_CHANNEL`,
`LOG_STDERR_FORMATTER`). Railway przechwytuje `stdout`/`stderr` do swojego
narzędzia dzienników, więc wpisy **nie znikają** razem z instancją — żyją
tyle, ile pozwala plan konta Railway.

Polityka prywatności (§2, wiersz „Wykrywanie i naprawa błędów
technicznych”, oraz tabela dostawców w §3) opisuje ten przepływ.
Liczby dni celowo w niej nie ma, dopóki właściciel nie odczyta jej
z panelu Railway (#994). Nikt w repozytorium tej wartości nie potwierdził.

**Kiedy trzeba zmienić tekst polityki** (razem z datą stanu w jej nagłówku
i `docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md` §3.18):

- zmiana planu Railway albo ustawień przechowywania dzienników;
- potwierdzenie liczby dni w panelu — wtedy wpisać ją w miejsce zdania
  „Nie podajemy tu liczby dni…”;
- zmiana `LOG_CHANNEL` na inny odbiornik (plik, zewnętrzne narzędzie
  do zbierania błędów) — nowy odbiorca trafia też do tabeli dostawców.

Powrotu do zdania, które wiązało retencję z życiem instancji, pilnuje
`PolitykaOpisujeRetencjeDziennikaSerweraTest`.

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
