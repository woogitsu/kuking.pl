#!/usr/bin/env bash
set -euo pipefail
cd /home/mateusz/flota/gpt-kontakt-panel-run
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439
export DB_DATABASE=kuking_flota_gpt-kontakt-panel DB_USERNAME=kuking DB_PASSWORD=kuking
bash scripts/kontrola-ujemna.sh --nazwa '#844: SET NULL' \
  --plik database/migrations/2026_09_20_140000_allow_null_handled_by_on_contact_messages.php \
  --zamien 'handled_at IS NOT NULL))' --na 'num_nonnulls(handled_by, handled_at) = 2))' \
  --oczekuj 'contact_messages_handled_complete' \
  --json storage/kontakt-844.json \
  -- php artisan test --filter test_usuniecie_konta_operatora_ustawia_null_w_handled_by_i_zachowuje_status
