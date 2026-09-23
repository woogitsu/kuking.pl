#!/usr/bin/env bash
set -euo pipefail
cd /home/mateusz/flota/gpt-2fa-ustawienia-run
export PATH=/opt/kuking-php-8.4-avif/bin:$PATH
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=kuking_flota_gpt-2fa-ustawienia DB_USERNAME=kuking DB_PASSWORD=kuking
export APP_ENV=local MAIL_MAILER=array ASSERT_HIDDEN=1
bash scripts/kontrola-ujemna.sh --nazwa '877 historia Chromium' --plik app/Http/Controllers/Settings/TwoFactorSettingsController.php --zamien 'no-store, private' --na 'no-cache, private' --oczekuj '"visible": true' --json output/playwright/2fa/ujemna-877-browser.json -- bash -c 'if grep -qF "no-cache, private" app/Http/Controllers/Settings/TwoFactorSettingsController.php; then ! grep -qF "no-store, private" app/Http/Controllers/Settings/TwoFactorSettingsController.php || exit 2; echo MUTACJA_CACHE_POTWIERDZONA; export REPORT_PATH=output/playwright/2fa/historia-mutacja.json; else export REPORT_PATH=output/playwright/2fa/historia-po.json; fi; node scripts/fixtures/ustawienia-2fa-historia.mjs'