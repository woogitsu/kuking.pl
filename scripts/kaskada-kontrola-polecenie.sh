#!/usr/bin/env bash
# =============================================================================
#  Polecenie dla kontroli ujemnej strażnika kaskady (D-223).
# =============================================================================
#
#  Strażnik `kaskada-martwe-reguly.mjs` czyta ZBUDOWANY arkusz
#  (`public/build/assets/app-*.css`), a nie źródła w `resources/css/`.
#  Kontrola ujemna mutuje ŹRÓDŁO — więc między mutacją a pomiarem MUSI stanąć
#  przebudowanie. Bez tego strażnik mierzyłby arkusz sprzed mutacji, zostałby
#  zielony i przyrząd orzekłby `STRAZNIK_NIE_STRZEZE` o strażniku, który
#  działa poprawnie.
#
#  To jest dokładnie pułapka §11 z docs/PULAPKI_TESTOW.md w wersji dla
#  kontroli ujemnej: mierzysz nie ten arkusz, który zmieniłeś.
#
#  Zawężenie `--tylko` jest tu świadome i jawne: repozytorium ma dziś duży
#  zaległy zbiór deklaracji przykrytych (D-223), więc na całym arkuszu nie da
#  się uzyskać kontroli DODATNIEJ. Zawężamy do obszaru, który jest zielony,
#  i w nim pokazujemy przejście zielone → czerwone → zielone.
#
#  DOMYŚLNE ZAWĘŻENIE TO `.przepis-liczba svg`, NIE `.przepis-liczba`.
#  Zmierzone 20.09.2026 (stanowisko `kaskada`, runtime WSL, 72 konfiguracje):
#  samo `.przepis-liczba` jest CZERWONE i nie nadaje się na kontrolę dodatnią —
#  `marka-ekrany.css` (warstwa `marka`) przykrywa `padding` i `border-radius`
#  z warstwy `components`, a `.przepis-liczba span` przykrywa `font-size`.
#  To są prawdziwe znaleziska tego samego rodzaju, którego strażnik pilnuje,
#  i idą do zaległości D-223 — nie do wyjątków i nie do kontroli dodatniej.
#
#  Kontrola ujemna dla tego zawężenia: patrz `scripts/kaskada-kontrola-ujemna.sh`.
set -euo pipefail
cd "$(dirname "$0")/.."

npx vite build >/dev/null 2>&1

exec node scripts/kaskada-martwe-reguly.mjs --szybko --tylko "${1:-.przepis-liczba svg}"
