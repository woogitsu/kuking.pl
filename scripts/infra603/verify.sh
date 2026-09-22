#!/usr/bin/env bash
# Kontrola przyrządu i projektu, bez połączeń z produkcją.
set -euo pipefail
cd /home/mateusz/flota/gpt-redis-ha-run
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"
export APP_ENV=local DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439
export DB_DATABASE=kuking_flota_gpt-redis-ha DB_USERNAME=kuking DB_PASSWORD=kuking
unset DB_URL
mkdir -p storage/infra603
php vendor/bin/pint --test >storage/infra603/pint.txt
php scripts/infra603/probe.php check >storage/infra603/guard-positive.txt
set +e
DB_DATABASE=obca_baza php scripts/infra603/probe.php check >storage/infra603/guard-negative.txt 2>&1
status=$?
set -e
test "$status" -eq 2
printf 'Kod odmowy: %s\n' "$status" >>storage/infra603/guard-negative.txt
# Wyjątek jawnie dopuszczony przez właściciela: test używa wspólnej bazy próby.
unset APP_ENV
export APP_BASE_PATH="$PWD"
bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-redis-ha \
  --compact --filter '^(?!.*ProbaOdtworzeniaTest)' >storage/infra603/tests.txt 2>&1
tail -n 6 storage/infra603/tests.txt
