#!/usr/bin/env bash
set -euo pipefail
cd /home/mateusz/flota/gpt-harmonogram-run
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"
bash scripts/kontrola-ujemna.sh --plik scripts/sprawdz-cache-assetow.sh --zamien '|| [[ "$status" != 200 ]]' --na '|| false' --oczekuj 'FAIL 404: fałszywy sukces' --json /mnt/c/Users/matma/Documents/kuking-flota/_zapas/gpt-harmonogram-przejecie/mutacja-cache-status.json -- bash tests/skrypty/cache-assetow.sh
bash scripts/kontrola-ujemna.sh --plik docker/Caddyfile --zamien '@viteAssets path /build/assets/*' --na '@viteAssets path /build/*' --oczekuj 'viteAssets path /build/assets' --json /mnt/c/Users/matma/Documents/kuking-flota/_zapas/gpt-harmonogram-przejecie/mutacja-caddy.json -- vendor/bin/phpunit tests/Feature/SondaCacheAssetowTest.php
