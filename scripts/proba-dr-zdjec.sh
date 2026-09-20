#!/usr/bin/env bash
# Wyłącznie runtime floty; nie uruchamia migracji ani nie tworzy bazy.
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439
export DB_DATABASE=kuking_flota_gpt-dr-zdjecia DB_USERNAME=kuking DB_PASSWORD=kuking
python3 scripts/proba-dr-zdjec.py "$@"
