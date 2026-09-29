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
#  DOMYŚLNE ZAWĘŻENIE TO `.przepis-liczba svg` — ZMIERZONE 29.09.2026 NA BAZIE.
#
#  Całe `.przepis-liczba` jest dziś CZERWONE, i to z powodu czterech deklaracji,
#  które nikt nie rozstrzygnął (wartość ani ich usunięcie nie jest decyzją
#  tego pliku):
#    - `.przepis-liczba` { padding, border-radius } — przykryte przez
#      `marka-ekrany.css` (warstwa `marka`);
#    - `.przepis-liczba span` { font-size } — przykryte tamtędy;
#    - `.przepis-liczba strong` { font-size } — przykryte przez późniejszą regułę
#      spoza warstw, `body:has(.przepis-uklad) .przepis-uklad *`.
#  Strażnik oblewałby więc na czymś, czego nikt nie wybrał, a bramka, która jest
#  czerwona od pierwszego dnia, uczy ignorować czerwień. `.przepis-liczba svg`
#  jest ZIELONE przy realnym nosicielu (samokontrola zawężenia w strażniku
#  kończy się kodem 2, gdyby zawężenie nie objęło ani jednej reguły z nosicielem).
#
#  NIE ZAWĘŻAJ TEGO DALEJ ani nie dopisuj tu wyjątków, żeby uciszyć czerwień.
#  Poszerzenie do całego `.przepis-liczba` wraca razem z rozstrzygnięciem tych
#  czterech deklaracji (usunąć albo `WYJATKI` z powodem) — patrz #960.
#
#  Kontrola ujemna dla tego zawężenia: `scripts/kaskada-kontrola-ujemna.sh`.
set -euo pipefail
cd "$(dirname "$0")/.."

npx vite build >/dev/null 2>&1

exec node scripts/kaskada-martwe-reguly.mjs --szybko --tylko "${1:-.przepis-liczba svg}"
