#!/bin/bash
# Uruchamiać po przygotowaniu runtime, poza przebiegiem testów lub przeglądarki.
set -euo pipefail
cd /home/mateusz/flota/gpt-kreator-przepisu-run
export PATH="/opt/kuking-php-8.4-avif/bin:$PATH"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439
export DB_DATABASE=kuking_flota_gpt-kreator-przepisu DB_USERNAME=kuking DB_PASSWORD=kuking
mkdir -p output/kreator
file=resources/views/components/recipe-wizard.blade.php
bash scripts/kontrola-ujemna.sh --nazwa '#893 — nie twórz zastępczego przepisu' \
  --plik "$file" \
  --zamien "throw new BladDlaCzlowieka('Ten przepis nie jest już dostępny. Skopiuj wpisany tekst, zanim opuścisz formularz.');" \
  --na '$this->recipeId = null; return null;' \
  --oczekuj 'Nie wolno tworzyć zastępczego przepisu' --json output/kreator/893.json \
  -- bash -c 'php artisan view:clear >/dev/null && exec "$@"' _ php artisan test tests/Feature/KreatorNieOdtwarzaUsunietegoPrzepisuTest.php
bash scripts/kontrola-ujemna.sh --nazwa '#894 — błąd podąża za krokiem' \
  --plik "$file" --zamien '$this->setErrorBag($errors);' --na '// pomiar: brak przeniesienia błędów' \
  --oczekuj 'Component missing error' --json output/kreator/894.json \
  -- bash -c 'php artisan view:clear >/dev/null && exec "$@"' _ php artisan test tests/Feature/BladZdjeciaPodazaZaKrokiemTest.php
bash scripts/kontrola-ujemna.sh --nazwa '#897 — zachowaj klucze wejścia' \
  --plik resources/views/pages/recipes/szczegoly.blade.php \
  --zamien '@foreach($oldSteps as $i => $stepRow)' --na '@foreach(array_values($oldSteps) as $i => $stepRow)' \
  --oczekuj 'Ostatni nowy krok' --json output/kreator/897.json \
  -- bash -c 'php artisan view:clear >/dev/null && exec "$@"' _ php artisan test tests/Feature/SzczegolyZachowujaKluczeWierszyTest.php
bash scripts/kontrola-ujemna.sh --nazwa '#901 — widoczna droga do wszystkich szkiców' \
  --plik resources/views/pages/add.blade.php \
  --zamien '<p class="mb-0"><a class="btn btn-secondary" href="{{ route('\''recipes.drafts'\'') }}">Wszystkie szkice</a></p>' --na '' \
  --oczekuj 'Wszystkie szkice' --json output/kreator/901.json \
  -- bash -c 'php artisan view:clear >/dev/null && exec "$@"' _ php artisan test tests/Feature/WszystkieSzkiceTest.php
# Przeglądarka rusza dopiero po testach PHP, które odświeżają bazę.
export APP_ENV=local APP_DEBUG=true SESSION_DRIVER=file CACHE_STORE=array QUEUE_CONNECTION=sync
bash scripts/kontrola-ujemna.sh --nazwa '#892 — potwierdzenie dotyczy obecnego tekstu' \
  --plik "$file" --zamien 'x-on:input="$wire.editRevision = ++revision"' --na '' \
  --oczekuj 'STARE_POTWIERDZENIE' --json output/kreator/892.json \
  -- bash -c 'php artisan view:clear >/dev/null && exec "$@"' _ node scripts/kreator-zachowanie.mjs autosave
