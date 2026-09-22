#!/bin/bash
set -euo pipefail
cd /home/mateusz/flota/gpt-kreator-przepisu-run
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439
export DB_DATABASE=kuking_flota_gpt-kreator-przepisu DB_USERNAME=kuking DB_PASSWORD=kuking
export APP_ENV=local APP_DEBUG=true SESSION_DRIVER=file CACHE_STORE=array QUEUE_CONNECTION=sync
if [ "${1:-}" = tests ]; then
    unset APP_ENV SESSION_DRIVER
    exec bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-kreator-przepisu --filter '^(?!.*ProbaOdtworzeniaTest)' --compact
fi
node scripts/kreator-zachowanie.mjs "${1:-autosave}"
