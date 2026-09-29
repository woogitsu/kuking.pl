# Usunięcie czterech martwych deklaracji `.przepis-liczba` (#960)

Data pomiaru: 29 września 2026. Decyzja właściciela z tego dnia: usunąć
z `resources/css/app.css` cztery deklaracje, które przeglądarka nigdy nie
stosuje (`.przepis-liczba` { padding, border-radius }, `.przepis-liczba span`
{ font-size }, `.przepis-liczba strong` { font-size }), i poszerzyć zawężenie
strażnika kaskady z `.przepis-liczba svg` na całe `.przepis-liczba`.

Po usunięciu tych czterech strażnik na całym `.przepis-liczba` zgłosił jeszcze
jedną deklarację tej samej rodziny: `.przepis-liczba span` { font-size } w
`resources/css/marka-ekrany.css` (warstwa `marka`), przykrytą w pomiarze przez
`body:has(.przepis-uklad) .przepis-uklad *`. To przypisanie strażnika jest
tylko wskazówką: ta reguła stoi w `@media print` (`wydruk-przepisu.css`), więc
przykrywa deklarację wyłącznie w druku. Na ekranie deklaracja nie zmienia nic,
bo `span` dziedziczy ten sam rozmiar — i to rozstrzyga pomiar
`getComputedStyle` oraz odcisk niżej, nie wskazanie. Usunięto ją razem z czterema, a odcisk poniżej mierzy sumę pięciu
deklaracji: PRZED = stan bazy, PO = stan z usuniętym kompletem.

## Wynik

Odcisk kafla liczb przed i po usunięciu jest **identyczny**:

```
Konfiguracji w odcisku PRZED: 96, PO: 96
Węzłów porównanych: 2208. Wartości wyliczonych porównanych: 1289472.
✓ ODCISKI IDENTYCZNE — ta sama geometria i te same wartości wyliczone przed i po.
```

Konfiguracje: 2 przepisy × 8 szerokości (320, 360, 480, 481, 768, 769, 1024,
1280 px) × 2 motywy (light, dark) × 3 warianty pisma (bez, `data-text-scale=140`,
korzeń 24 px) = 96. Na węzeł 584 własności `getComputedStyle` plus geometria.
Pełny wynik porównania: `porownanie-przed-po.log`.

## Polecenia (własna baza na porcie 55439, nigdy 5432)

```
npx vite build
node docs/design/evidence/martwe-liczby/odcisk-liczb.mjs przed.json
# usunięcie deklaracji w resources/css/app.css, ponowny `npx vite build`
node docs/design/evidence/martwe-liczby/odcisk-liczb.mjs po.json
node docs/design/evidence/martwe-liczby/odcisk-liczb.mjs --porownaj przed.json po.json
```

Zmienne: `DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=<baza z DemoSeeder>
CHROMIUM_PATH=/opt/pw-browsers/chromium`. Pliki `przed.json` i `po.json`
(kilka MB każdy, pełne własności) nie są w repozytorium; do odtworzenia
wystarczy powyższa sekwencja.

Starszy pomiar (20.09.2026) dla dwóch z tych deklaracji:
`usuniecie-martwych-deklaracji.log`.
