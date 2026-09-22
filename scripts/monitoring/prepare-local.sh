#!/bin/bash
# Wyłącznie własny runtime i baza stanowiska; żadnego połączenia produkcyjnego.
set -euo pipefail
cd /home/mateusz/flota/gpt-monitoring-run
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439
export DB_DATABASE=kuking_flota_gpt-monitoring DB_USERNAME=kuking DB_PASSWORD=kuking
export APP_ENV=local CACHE_STORE=database SESSION_DRIVER=database QUEUE_CONNECTION=database
export MAIL_MAILER=array LOG_BLAD_WEBHOOK_URL=''
unset DB_URL
php scripts/monitoring/local.php check
php artisan migrate:fresh --force --no-interaction
npm run build
php scripts/monitoring/local.php seed
python3 scripts/monitoring/measure.py
