#!/usr/bin/env bash
# Wykonuj wyłącznie w przygotowanym runtime tego stanowiska, po testach.
set -euo pipefail
cd "$(dirname "$0")/../.."
[[ "$PWD" == /home/mateusz/flota/gpt-cloudflare-cache-run ]] || { echo 'Użyj runtime gpt-cloudflare-cache.'; exit 1; }
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439
export DB_DATABASE=kuking_flota_gpt-cloudflare-cache DB_USERNAME=kuking DB_PASSWORD=kuking
mkdir -p storage/cache-evidence
bash scripts/kontrola-ujemna.sh --nazwa 'Odmów cache sesji' \
  --plik app/Http/Middleware/PreventSharedSessionCache.php \
  --zamien "'Cache-Control', 'private, no-store'" --na "'Cache-Control', 'public, max-age=600'" \
  --oczekuj 'test_ochrona_nadpisuje_bledny_publiczny_naglowek' --json storage/cache-evidence/session.json \
  -- vendor/bin/phpunit tests/Feature/CloudflareCachePrivacyTest.php --filter test_ochrona_nadpisuje_bledny_publiczny_naglowek
bash scripts/kontrola-ujemna.sh --nazwa 'Nie wydawaj sesji z publicznym zdjęciem' \
  --plik app/Http/Middleware/StartSessionExceptAnonymousMedia.php \
  --zamien "routeIs('media.show')" --na "routeIs('nieistniejaca-trasa')" \
  --oczekuj 'Publiczny cache nie może rozdawać sesji' --json storage/cache-evidence/cookie.json \
  -- vendor/bin/phpunit tests/Feature/CloudflareCachePrivacyTest.php --filter test_publiczne_zdjecie_nie_wydaje_ciasteczek
bash scripts/kontrola-ujemna.sh --nazwa 'Nie wydłużaj prywatnego podpisu' \
  --plik app/Http/Controllers/MediaController.php \
  --zamien '$publiczne = $decyzja->dlaAnonima;' --na '$publiczne = true;' \
  --oczekuj 'test_prywatny_podpis_zostaje_krotki' --json storage/cache-evidence/private-ttl.json \
  -- vendor/bin/phpunit tests/Feature/CloudflareCachePrivacyTest.php --filter test_prywatny_podpis_zostaje_krotki
bash scripts/kontrola-ujemna.sh --nazwa 'Nie zaliczaj złych nagłówków sesji w sondzie' \
  --plik scripts/lib/cache-gate.sh \
  --zamien 'cache_private() {' --na 'cache_private() { return 0;' \
  --oczekuj 'test_bramka_odmawia_bez_pelnego_pomiaru' --json storage/cache-evidence/probe.json \
  -- vendor/bin/phpunit tests/Unit/CloudflareCacheGateTest.php
for file in app/Http/Middleware/PreventSharedSessionCache.php app/Http/Middleware/StartSessionExceptAnonymousMedia.php app/Http/Controllers/MediaController.php scripts/lib/cache-gate.sh; do
  cmp "$file" "/mnt/c/Users/matma/Documents/kuking-flota/gpt-cloudflare-cache/$file"
done
echo 'Przywrócone pliki zgodne z worktree.'
