#!/usr/bin/env bash
#
# Przygotowanie środowiska na start sesji agenta AI.
#
# Cel: agent, który dopiero wszedł do repozytorium, ma od razu móc puścić
# `php artisan test` — bez zgadywania, dlaczego baza nie odpowiada.
#
# Skrypt jest CELOWO odporny na błędy (`|| true`): brak Postgresa lokalnie
# nie może zablokować sesji, w której ktoś chce tylko poczytać dokumentację.

set -uo pipefail
cd "$(dirname "$0")/../.." || exit 0

echo "── Kuking: przygotowanie środowiska ──"

# 1. PostgreSQL. Testy Kuking NIE działają na SQLite (indeksy częściowe,
#    num_nonnulls, pg_trgm, unaccent), więc baza musi żyć.
if command -v pg_isready >/dev/null 2>&1; then
    if ! pg_isready -q 2>/dev/null; then
        for wersja in 18 17 16 15; do
            if [ -d "/usr/lib/postgresql/$wersja" ]; then
                pg_ctlcluster "$wersja" main start >/dev/null 2>&1 || true
                break
            fi
        done
        sleep 2
    fi

    if pg_isready -q 2>/dev/null; then
        echo "PostgreSQL: działa"
        su postgres -c "psql -tAc \"SELECT 1 FROM pg_roles WHERE rolname='kuking'\"" 2>/dev/null | grep -q 1 \
            || su postgres -c "psql -c \"CREATE ROLE kuking LOGIN PASSWORD 'kuking' SUPERUSER\"" >/dev/null 2>&1 || true
        for db in kuking kuking_test; do
            su postgres -c "psql -tAc \"SELECT 1 FROM pg_database WHERE datname='$db'\"" 2>/dev/null | grep -q 1 \
                || su postgres -c "createdb -O kuking $db" >/dev/null 2>&1 || true
        done
        echo "Bazy: kuking, kuking_test"
    else
        echo "PostgreSQL: NIE DZIAŁA — testy nie przejdą. Uruchom: pg_ctlcluster 16 main start"
    fi
fi

# 2. Zależności PHP
if [ ! -d vendor ]; then
    echo "Instaluję zależności PHP…"
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --no-progress >/dev/null 2>&1 || true
fi

# 3. Plik .env i klucz aplikacji
if [ ! -f .env ]; then
    cp .env.example .env 2>/dev/null || true
    php artisan key:generate --force >/dev/null 2>&1 || true
    echo "Utworzono .env"
fi

# 4. Zależności front-endu
if [ ! -d node_modules ]; then
    echo "Instaluję zależności front-endu…"
    npm install --no-audit --no-fund >/dev/null 2>&1 || true
fi

# 5. Migracje
php artisan migrate --force >/dev/null 2>&1 && echo "Migracje: aktualne" || true

echo "── Gotowe. Przeczytaj AGENTS.md przed pierwszą zmianą. ──"
exit 0
