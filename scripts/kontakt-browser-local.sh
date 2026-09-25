#!/usr/bin/env bash
set -euo pipefail
cd /home/mateusz/flota/gpt-kontakt-panel-run
export PATH="/opt/kuking-php-8.4-avif/bin:$HOME/.nvm/versions/node/v24.19.0/bin:$PATH"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439
export DB_DATABASE=kuking_flota_gpt-kontakt-panel DB_USERNAME=kuking DB_PASSWORD=kuking
export APP_ENV=testing MAIL_MAILER=array
node scripts/kontakt-panel-browser.mjs
