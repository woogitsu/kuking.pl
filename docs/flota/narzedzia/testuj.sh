#!/bin/bash
# WYMAGA: runtime założony przez przygotuj-runtime.sh w /home/mateusz/flota/<stanowisko>-run i lokalny klaster PostgreSQL na 127.0.0.1:55439 (użytkownik/hasło kuking — dane wyłącznie lokalne, klaster nie istnieje poza tą maszyną).
# Uruchamia testy w runtime jednego stanowiska, na WLASNEJ bazie.
# Uzycie (z WSL): bash testuj.sh <nazwa-stanowiska> [argumenty php artisan test]
set -uo pipefail
N="${1:?podaj nazwe stanowiska}"; shift || true
RT="/home/mateusz/flota/$N-run"
cd "$RT" || exit 1
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439
export DB_DATABASE="kuking_flota_$N" DB_USERNAME=kuking DB_PASSWORD=kuking
export PGHOST=127.0.0.1 PGPORT=55439 PGUSER=kuking PGPASSWORD=kuking
psql -d postgres -tc "SELECT 1 FROM pg_database WHERE datname='kuking_flota_$N'" | grep -q 1 \
  || createdb "kuking_flota_$N"
php artisan test "$@"
