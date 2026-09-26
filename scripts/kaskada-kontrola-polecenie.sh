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
#  DOMYŚLNE ZAWĘŻENIE TO CAŁE `.przepis-liczba` — POSZERZONE 20.09.2026.
#
#  Historia, żeby nikt nie cofnął tego przez pomyłkę: do 20.09.2026 domyślne
#  zawężenie brzmiało `.przepis-liczba svg`, bo samo `.przepis-liczba` było
#  CZERWONE (`marka-ekrany.css` z warstwy `marka` przykrywało `padding`
#  i `border-radius` z warstwy `components`, a `.przepis-liczba span`
#  przykrywało `font-size`). Strażnik oblewałby wtedy na czymś, czego nikt
#  nie wybrał, więc obszar trzeba było zawęzić do jednej reguły.
#
#  Właściciel rozstrzygnął te trzy deklaracje 20.09.2026: USUNĄĆ. Po usunięciu
#  zmierzone (runtime WSL, 72 konfiguracje, stanowisko `martwe-kaskady`):
#  całe `.przepis-liczba` jest ZIELONE przy 7 regułach z nosicielem — czyli
#  zawężenie nie zzieleniało przez to, że przestało cokolwiek obejmować.
#  Dowód niewidoczności usunięcia: `docs/design/evidence/martwe-liczby/`.
#
#  NIE ZAWĘŻAJ TEGO Z POWROTEM, żeby uciszyć czerwień. Czerwień na
#  `.przepis-liczba` znaczy, że doszła kolejna martwa deklaracja — należy ją
#  usunąć albo dopisać do `WYJATKI` z POWODEM, a nie wyprowadzić poza zakres.
#
#  Kontrola ujemna dla tego zawężenia: patrz `scripts/kaskada-kontrola-ujemna.sh`.
set -euo pipefail
cd "$(dirname "$0")/.."

npx vite build >/dev/null 2>&1

exec node scripts/kaskada-martwe-reguly.mjs --szybko --tylko "${1:-.przepis-liczba}"
