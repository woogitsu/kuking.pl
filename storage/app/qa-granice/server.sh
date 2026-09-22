#!/usr/bin/env bash
set -euo pipefail
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=kuking_flota_gpt-wyszukiwanie-granice DB_USERNAME=kuking DB_PASSWORD=kuking
export APP_ENV=local APP_DEBUG=false APP_URL=http://127.0.0.1:18885 SESSION_DRIVER=file CACHE_STORE=array
cd /home/mateusz/flota/gpt-wyszukiwanie-granice-run/public
printf '%s' "$$" > /mnt/c/Users/matma/Documents/kuking-flota/gpt-wyszukiwanie-granice/storage/app/qa-granice/server.pid
exec php -S 127.0.0.1:18885 /home/mateusz/flota/gpt-wyszukiwanie-granice-run/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php