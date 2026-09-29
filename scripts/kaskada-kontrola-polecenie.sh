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
#  DOMYŚLNE ZAWĘŻENIE TO `.przepis-liczba` — całe, razem z `svg`, `span`
#  i `strong` (decyzja właściciela z 29.09.2026, #960; D-223, D-333).
#
#  Do 29.09 zawężenie było węższe (`.przepis-liczba svg`), bo cztery deklaracje
#  w `app.css` były w całości przykryte i strażnik oblewał na czymś, czego nikt
#  nie wybrał: `.przepis-liczba` { padding, border-radius }, `.przepis-liczba
#  span` { font-size } (przykryte przez `marka-ekrany.css`, warstwa `marka`)
#  i `.przepis-liczba strong` { font-size } (przykryta przez regułę spoza warstw
#  `body:has(.przepis-uklad) .przepis-uklad *`). Właściciel zdecydował je
#  USUNĄĆ. Usunięcie jest niewidoczne — dowód (odcisk kafla przed i po,
#  komplet własności `getComputedStyle` i geometria): `docs/design/evidence/
#  martwe-liczby/`.
#
#  Samokontrola zawężenia w strażniku kończy się kodem 2, gdyby zawężenie nie
#  objęło ani jednej reguły z nosicielem. NIE ZAWĘŻAJ TEGO DALEJ ani nie
#  dopisuj wyjątków, żeby uciszyć czerwień — nowa martwa deklaracja w kaflu
#  liczb ma być widoczna.
#
#  Kontrola ujemna dla tego zawężenia: `scripts/kaskada-kontrola-ujemna.sh`.
set -euo pipefail
cd "$(dirname "$0")/.."

npx vite build >/dev/null 2>&1

exec node scripts/kaskada-martwe-reguly.mjs --szybko --tylko "${1:-.przepis-liczba}"
