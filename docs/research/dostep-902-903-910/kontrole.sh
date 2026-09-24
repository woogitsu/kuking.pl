#!/usr/bin/env bash
# Uruchom w izolowanym runtime z ustawionymi zmiennymi DB_* stanowiska.
set -euo pipefail
OUT=docs/research/dostep-902-903-910
bash scripts/kontrola-ujemna.sh --nazwa '902 strona przepisu' \
  --plik resources/views/pages/recipes/show.blade.php \
  --zamien "@can('cook', \$recipe)" --na '@if(auth()->check())' \
  --oczekuj 'does not contain.*ugotowalem' --json "$OUT/902-strona.json" \
  -- bash "$OUT/sprawdz.sh" tests/Feature/UgotowalemDostepTest.php
bash scripts/kontrola-ujemna.sh --nazwa '902 koniec gotowania' \
  --plik resources/views/pages/recipes/cooking.blade.php \
  --zamien "@can('cook', \$recipe)" --na '@if(auth()->check())' \
  --oczekuj 'does not contain.*ugotowalem' --json "$OUT/902-gotowanie.json" \
  -- bash "$OUT/sprawdz.sh" tests/Feature/UgotowalemDostepTest.php
bash scripts/kontrola-ujemna.sh --nazwa '910 tekst poprawki' \
  --plik resources/views/components/expired-comment-edit.blade.php \
  --zamien '{{ $body }}' --na 'UTRACONY TEKST' \
  --oczekuj 'Moja poprawka' --json "$OUT/910-tekst.json" \
  -- bash "$OUT/sprawdz.sh" tests/Feature/OdzyskaniePoprawkiKomentarzaTest.php
bash scripts/kontrola-ujemna.sh --nazwa '903 reset odhaczen' \
  --plik app/Http/Controllers/CookingModeController.php \
  --zamien '$request->session()->forget($this->sessionKey($model));' --na '// Mutacja: pozostaw odhaczenia.' \
  --oczekuj 'Session has unexpected key' --json "$OUT/903-reset.json" \
  -- bash "$OUT/sprawdz.sh" tests/Feature/GotowanieOdPoczatkuTest.php
