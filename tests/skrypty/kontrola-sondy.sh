#!/usr/bin/env bash
# Uruchamiaj w odświeżonym runtime floty; mutacje odtwarza wspólny przyrząd.
set -euo pipefail
cd "$(dirname "$0")/../.."
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"

bash scripts/kontrola-ujemna.sh --nazwa '#806 ignorowanie transportu' \
    --plik scripts/sprawdz-wdrozenie.sh \
    --zamien '[ "$kod_curl" -eq 0 ] || return 1' \
    --na ': # Mutacja: ignoruj kod transportu' \
    --oczekuj 'Failed asserting that 0 is identical to 1' \
    --json storage/sonda-mutacja-806.json \
    -- vendor/bin/phpunit tests/Unit/SondaWdrozeniaTest.php --filter '806'

bash scripts/kontrola-ujemna.sh --nazwa '#807 podciąg zamiast atrybutu' \
    --plik scripts/sprawdz-wdrozenie.sh \
    --zamien "grep -qiE ';[[:space:]]*secure[[:space:]]*(;|\$)'" \
    --na "grep -qi 'secure'" \
    --oczekuj 'Failed asserting that 0 is identical to 1' \
    --json storage/sonda-mutacja-807.json \
    -- vendor/bin/phpunit tests/Unit/SondaWdrozeniaTest.php --filter '807'

bash scripts/kontrola-ujemna.sh --nazwa '#808 pomijanie celu' \
    --plik scripts/sprawdz-wdrozenie.sh \
    --zamien ' && [[ "${www_cel,,}" == https://* ]] && [[ "$www_host" == "${HOST,,}" ]]' \
    --na '' \
    --oczekuj 'Failed asserting that 0 is identical to 1' \
    --json storage/sonda-mutacja-808.json \
    -- vendor/bin/phpunit tests/Unit/SondaWdrozeniaTest.php --filter '808'

bash scripts/kontrola-ujemna.sh --nazwa '#805 tłumienie błędu CLI' \
    --plik .github/workflows/preview.yml \
    --zamien 'railway environment delete "$env_name" --yes' \
    --na 'railway environment delete "$env_name" --yes || true' \
    --oczekuj 'Failed asserting that 0 is identical to 73' \
    --json storage/sonda-mutacja-805.json \
    -- vendor/bin/phpunit tests/Unit/SondaWdrozeniaTest.php --filter '805'
