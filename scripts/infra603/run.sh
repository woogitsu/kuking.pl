#!/usr/bin/env bash
# Odtwarza wyłącznie własną bazę stanowiska. Nie uruchamiaj równolegle z testami.
set -euo pipefail
cd /home/mateusz/flota/gpt-redis-ha-run
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"
export APP_ENV=local APP_URL=http://localhost
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439
export DB_DATABASE=kuking_flota_gpt-redis-ha DB_USERNAME=kuking DB_PASSWORD=kuking
unset DB_URL
php scripts/infra603/probe.php check
npm run build >/dev/null
php artisan migrate:fresh --force --no-interaction >/dev/null
php scripts/infra603/probe.php seed
python3 scripts/infra603/measure.py
