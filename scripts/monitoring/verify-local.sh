#!/bin/bash
set -euo pipefail
cd /home/mateusz/flota/gpt-monitoring-run
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439
export DB_DATABASE=kuking_flota_gpt-monitoring DB_USERNAME=kuking DB_PASSWORD=kuking
export PGHOST=127.0.0.1 PGPORT=55439 PGUSER=kuking PGPASSWORD=kuking
unset DB_URL
mkdir -p output/monitoring
vendor/bin/pint app/Domain/Monitoring/AlarmMemory.php app/Domain/Polaczenia/AlarmPolaczen.php app/Domain/Kolejka/AlarmKolejki.php tests/Feature/AlarmPrzyAwariiCacheTest.php scripts/monitoring/local.php scripts/monitoring/alerts.php
php artisan test --compact --filter 'AlarmPrzyAwariiCacheTest|BudzetPolaczenBazyTest|CzujkaKolejkiTest|EpizodyAlarmowTest|NieudanyDzwonekNieKupujeCiszyTest|PomiarCzujekTrafiaDoDziennikaTest' > output/monitoring/focused.txt 2>&1
tail -5 output/monitoring/focused.txt
bash scripts/kontrola-ujemna.sh --nazwa 'Awaria cache zatrzymuje alarm' \
  --plik app/Domain/Monitoring/AlarmMemory.php \
  --zamien 'return null;' --na "throw new \\RuntimeException('CACHE_BLOKUJE_ALARM');" \
  --oczekuj 'CACHE_BLOKUJE_ALARM' --json output/monitoring/negative.json \
  -- vendor/bin/phpunit tests/Feature/AlarmPrzyAwariiCacheTest.php
php artisan test --compact --filter '/^(?!.*ProbaOdtworzeniaTest)/' > output/monitoring/full.txt 2>&1
tail -8 output/monitoring/full.txt
