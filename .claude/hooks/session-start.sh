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
    # Port z `DB_PORT`/`.env`, nie domyślny 5432 — na maszynie dewelopera
    # klaster 5432 należy do innego projektu. Patrz `scripts/port-bazy.sh`.
    # shellcheck source=scripts/port-bazy.sh
    . ./scripts/port-bazy.sh
    kuking_ustal_dostep_do_bazy . || true
    PORT_BAZY="${KUKING_DB_PORT:-5432}"
    HOST_BAZY="${KUKING_DB_HOST:-127.0.0.1}"

    if ! pg_isready -q -h "$HOST_BAZY" -p "$PORT_BAZY" 2>/dev/null; then
        for wersja in 18 17 16 15; do
            if [ -d "/usr/lib/postgresql/$wersja" ]; then
                pg_ctlcluster "$wersja" main start >/dev/null 2>&1 || true
                break
            fi
        done
        sleep 2
    fi

    if pg_isready -q -h "$HOST_BAZY" -p "$PORT_BAZY" 2>/dev/null; then
        echo "PostgreSQL: działa na ${HOST_BAZY}:${PORT_BAZY}"
        su postgres -c "psql -tAc \"SELECT 1 FROM pg_roles WHERE rolname='kuking'\"" 2>/dev/null | grep -q 1 \
            || su postgres -c "psql -c \"CREATE ROLE kuking LOGIN PASSWORD 'kuking' SUPERUSER\"" >/dev/null 2>&1 || true

        # Baza testowa TEJ kopii roboczej. Nazwę liczy `tests/nazwa-bazy.php` —
        # TA SAMA funkcja, co w `tests/bootstrap.php`, a nie przepisana tu
        # drugi raz w bashu. Do 19 września stała tu bashowa kopia reguły
        # i to ona się rozjechała: znała tylko `git worktree`, więc wszędzie
        # indziej zakładała `kuking_test`, czyli bazę wspólną dla wszystkich
        # kopii roboczych naraz. `tests/nazwa-bazy.php` jest świadomie wolny
        # od Composera, więc działa także tutaj — przed `composer install`.
        baza_testowa="$(php -r 'require "tests/nazwa-bazy.php"; echo kuking_nazwa_testowej_bazy(__DIR__);' 2>/dev/null)"

        if [ -z "$baza_testowa" ]; then
            echo "Bazy: nie umiem wyliczyć nazwy bazy testowej (brak php?) — pomijam"
        else
            for db in kuking "$baza_testowa"; do
                su postgres -c "psql -tAc \"SELECT 1 FROM pg_database WHERE datname='$db'\"" 2>/dev/null | grep -q 1 \
                    || su postgres -c "createdb -O kuking $db" >/dev/null 2>&1 || true
            done
            php -r 'require "tests/nazwa-bazy.php"; kuking_zapisz_rejestr_bazy($argv[1], __DIR__);' \
                "$baza_testowa" >/dev/null 2>&1 || true
            echo "Bazy: kuking, $baza_testowa"
        fi
    else
        echo "PostgreSQL: NIE DZIAŁA na ${HOST_BAZY}:${PORT_BAZY} — testy nie przejdą."
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
