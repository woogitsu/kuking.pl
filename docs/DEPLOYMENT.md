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
