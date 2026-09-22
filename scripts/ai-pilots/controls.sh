#!/usr/bin/env bash
# Kontrole tylko w izolowanym runtime. Żadne połączenie z dostawcą.
set -euo pipefail
cd /home/mateusz/flota/gpt-ai-piloty-run
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439
export DB_DATABASE=kuking_flota_gpt-ai-piloty DB_USERNAME=kuking DB_PASSWORD=kuking
EVIDENCE=/mnt/c/Users/matma/Documents/kuking-flota/gpt-ai-piloty/docs/research/ai-pilots/evidence
mkdir -p "$EVIDENCE"
bash scripts/kontrola-ujemna.sh --nazwa 'pełne pokrycie tekstu #814' \
  --plik scripts/ai-pilots/Pilot.php \
  --zamien 'if ($cursor !== count($tokens) || implode('\'''\'', array_column($segments, '\''text'\'')) !== $source)' \
  --na 'if (false)' --oczekuj 'Failed asserting that exception' \
  --json "$EVIDENCE/control-coverage.json" \
  -- php artisan test --filter test_nie_przyjmuje_dopiskow_ani_pominiec
bash scripts/kontrola-ujemna.sh --nazwa 'zamknięte pola #814' \
  --plik scripts/ai-pilots/Pilot.php \
  --zamien 'if ($actual !== $expected)' --na 'if (false)' \
  --oczekuj 'Failed asserting that exception' --json "$EVIDENCE/control-fields.json" \
  -- php artisan test --filter test_nie_przyjmuje_dopiskow_ani_pominiec
bash scripts/kontrola-ujemna.sh --nazwa 'granica wyjścia #912' \
  --plik scripts/ai-pilots/Transport.php \
  --zamien 'public const MAX_BODY_BYTES = 24000;' --na 'public const MAX_BODY_BYTES = 2400000;' \
  --oczekuj 'Limit bajtów nie zatrzymał wysyłki' --json "$EVIDENCE/control-bytes.json" \
  -- php artisan test --filter test_limit_calego_zadania_obejmuje_numerowane_tokeny
bash scripts/kontrola-ujemna.sh --nazwa 'limit wydatków' \
  --plik scripts/ai-pilots/Budget.php \
  --zamien 'if ($count >= $limit)' --na 'if (false)' \
  --oczekuj 'Failed asserting that exception' --json "$EVIDENCE/control-budget.json" \
  -- php artisan test --filter test_budzet_jest_trwaly_i_odcina_przed_kolejna_proba
