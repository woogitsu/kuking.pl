# #1000 — fonty Inter: pomiar przed i po (wolna sieć, zimny cache)

Dowód do kryterium „Pomiar na wolnej sieci i zimnym cache sprawdza transfer
oraz CLS; poprawy nie uznaje się wyłącznie na podstawie rozmiaru pliku”
z issue #1000. Pomiar lokalny, 29.09.2026; nie zmienia kodu aplikacji.

## Wersje

| Etykieta | Commit | Fonty w buildzie |
|---|---|---|
| przed (`main`) | `de27cbddf` (origin/main, scalenie #2209) | `inter-latin` 48 256 B + `inter-latin-ext` 85 068 B = **133 324 B** |
| po (BAZA) | `13dd5faf8` (origin/claude/paczka-f, PR #2210; podzbiór z `75f3acc7e`) | `inter-podstawa` 38 688 B + `inter-europa` 14 672 B = **53 360 B** |

Build obu wersji (`npm run build`) daje ten sam JS (`app-DFQ42K6D.js`) i CSS
różniący się tylko blokiem `@font-face` (163,18 kB vs 163,00 kB), więc różnice
poniżej przypisuję fontom. Dodatkowo `git diff origin/main..BAZA` nie dotyka
w `resources/css`, `resources/fonts` i `vite.config.js` niczego poza fontami.

## Artefakty CI

Artefakt `wydajnosc-<sha>` powstaje w `ci.yml` tylko przy porażce kroku
Lighthouse (`if: failure()`), a Lighthouse na `main` i PR #2210 przechodzi.
API GitHuba (`repos/{repo}/actions/artifacts`, 20 stron po 100) nie zwróciło żadnego artefaktu
`wydajnosc-*`, więc porównanie z CI nie było możliwe. Wartości z komentarzy
w issue (LCP 2,7–3,1 s na `main` 65327e69) pochodzą ze starszego przebiegu
i nie są tu porównywane.

## Metoda

Skrypt: `docs/pomiary/1000-mierz-fonty.mjs` (Playwright + Chromium 1194
z `/opt/pw-browsers`, CDP). Surowe wyniki: `1000-surowe-slow4g.json`,
`1000-surowe-slow3g.json`.

- Dwie kopie aplikacji (osobne worktree, osobne bazy PostgreSQL z `DemoSeeder`),
  każda na `php artisan serve --no-reload` (127.0.0.1, porty 18101/18102).
- Ekrany: `/`, przepis „Rosół babci Zofii” z `DemoSeeder` (trasa `recipes.show`), `/@basia` (profil) —
  wszystkie z polskimi znakami, więc uruchamiają oba pliki fontu.
- Każdy pomiar w NOWYM kontekście przeglądarki (pusty cache HTTP i fontów),
  viewport 360×640, DPR 2, mobile.
- Throttling sieci przez CDP: **Slow 4G** = 1,6 Mb/s w dół, 750 kb/s w górę,
  RTT 150 ms (jak Lighthouse); **Slow 3G** = 500 kb/s, 400 kb/s, RTT 400 ms
  (jak DevTools). CPU 4× wolniej.
- 3 przebiegi na wersję i ekran, kolejność przeplatana (main, baza, main…).
  W tabelach mediana z 3.
- Metryki: bajty i liczba żądań fontów z `Network.loadingFinished`
  (`encodedDataLength`, czyli z nagłówkami — stąd ok. 300 B więcej niż rozmiar
  plików); „koniec pobierania fontu” = koniec ostatniego żądania fontu
  względem pierwszego żądania; „font zastosowany” = ostatnie zdarzenie
  `loadingdone` z `document.fonts` w ms od startu nawigacji (moment, gdy
  przeglądarka może przemalować tekst docelowym krojem); FCP, LCP, CLS z
  `PerformanceObserver` (CLS bez `hadRecentInput`, okno 2,5 s po `load`).

## Wyniki — Slow 4G (mediana z 3)

| Ekran | Wersja | Bajty fontów | Żądań | Koniec pobr. fontu | Font zastosowany | FCP = LCP | CLS |
|---|---|---:|---:|---:|---:|---:|---:|
| `/` | przed | 133 618 | 2 | 3014 ms | 3414 ms | 2292 ms | 0,0006 |
| `/` | po | 53 654 | 2 | 2558 ms | 3124 ms | 2156 ms | 0 |
| przepis | przed | 133 618 | 2 | 3008 ms | 3226 ms | 2224 ms | 0,0007 |
| przepis | po | 53 654 | 2 | 2924 ms | 3284 ms | 2516 ms | 0 |
| profil | przed | 133 618 | 2 | 2867 ms | 2983 ms | 2100 ms | 0,0006 |
| profil | po | 53 654 | 2 | 2536 ms | 2606 ms | 2164 ms | 0 |
| **razem (mediana z 9)** | przed | 133 618 | 2 | — | **3226 ms** | 2228 ms | 0,0006 |
| **razem (mediana z 9)** | po | 53 654 | 2 | — | **2888 ms** | 2164 ms | 0 |

## Wyniki — Slow 3G (mediana z 3)

| Ekran | Wersja | Bajty fontów | Żądań | Koniec pobr. fontu | Font zastosowany | FCP = LCP | CLS |
|---|---|---:|---:|---:|---:|---:|---:|
| `/` | przed | 133 618 | 2 | 7920 ms | 8064 ms | 5420 ms | 0,0006 |
| `/` | po | 53 654 | 2 | 6349 ms | 6475 ms | 5132 ms | 0,0005 |
| przepis | przed | 133 618 | 2 | 7383 ms | 7456 ms | 4912 ms | 0,0007 |
| przepis | po | 53 654 | 2 | 6531 ms | 6800 ms | 5392 ms | 0,0007 |
| profil | przed | 133 618 | 2 | 7120 ms | 7204 ms | 4640 ms | 0,0006 |
| profil | po | 53 654 | 2 | 5975 ms | 6140 ms | 4768 ms | 0,0005 |
| **razem (mediana z 9)** | przed | 133 618 | 2 | — | **7526 ms** | 4984 ms | 0,0006 |
| **razem (mediana z 9)** | po | 53 654 | 2 | — | **6473 ms** | 5088 ms | 0,0006 |

Cały transfer strony (wszystkie żądania, mediana): 393 878 B przed,
313 728 B po (−80 150 B, −20%).

## Wnioski

1. **Transfer fontów: −79 964 B (−59,8%)** przy tej samej liczbie żądań (2).
   Wynik zgodny z rozmiarami plików (133 324 → 53 360 B, −60,0%). Podzbiór
   wdrożony jest większy niż próba z issue (44 388 B), bo zawiera też zakres
   „europejski” z kontraktu znaków.
2. **Czas zastosowania fontu skraca się** o ok. 340 ms na Slow 4G
   (3226 → 2888 ms, mediana z 9) i ok. 1050 ms na Slow 3G
   (7526 → 6473 ms). Na Slow 4G wynik zależy od ekranu (−290 / +58 / −377 ms
   dla `/` / przepisu / profilu), rozrzut między przebiegami jest rzędu
   ±300 ms, więc dla pojedynczego ekranu to granica szumu; dla Slow 3G poprawa
   jest jednoznaczna na `/` i profilu (−1,6 s, −1,1 s) i mała na przepisie
   (−0,7 s).
3. **CLS nie pogarsza się i jest znikomy w obu wersjach**: 0–0,0007, czyli
   ponad 100 razy poniżej progu 0,1. Jedyne przesunięcie (ok. 0,0006) wypada
   w momencie zamiany kroju z zapasowego na Inter po pobraniu fontu;
   `font-display: swap` pozostaje zachowany. Wersja „po” ma je tylko na
   Slow 3G (0,0005–0,0007) — na Slow 4G zamiana nie powoduje przesunięcia.
4. **FCP i LCP się nie zmieniają** (różnice ±300 ms mieszczą się w szumie,
   w obu kierunkach): na wszystkich trzech ekranach LCP jest elementem
   tekstowym równym FCP i maluje się w kroju zapasowym, zanim font dojdzie.
   Zgodnie z ostrzeżeniem w issue („nie dowodzi, że fonty są jedyną
   przyczyną LCP”) — ten podzbiór skraca moment podmiany kroju i oszczędza
   łącze (mniej konkurencji z obrazami i JS), ale nie przyspiesza pierwszego
   malowania. Poprawy LCP należy szukać gdzie indziej (patrz #1029).

## Ograniczenia

- Pomiar lokalny na jednej maszynie, `php artisan serve` bez kompresji
  i CDN, obrazy demonstracyjne z seedera; symulowany throttling CDP (nie
  prawdziwa sieć). Wartości bezwzględne nie przekładają się na produkcję,
  istotna jest różnica przed/po przy identycznych warunkach.
- 3 przebiegi na ekran: wystarcza do bajtów i CLS (praktycznie
  deterministyczne), ale nie do rozstrzygania różnic FCP/LCP poniżej ok. 300 ms.
- Brak porównania z artefaktem CI (`wydajnosc-<sha>` powstaje tylko przy
  porażce — patrz wyżej) i brak Lighthouse; to jest pomiar własny, nie
  zamiennik budżetów z `scripts/wydajnosc-progi.mjs`.
- Ekrany zalogowane (tablica, edycja) nie były mierzone; wszystkie mierzone
  zawierają polskie znaki, więc oba pliki fontu są pobierane. Strona tylko
  z ASCII pobrałaby jeden plik (`inter-podstawa`, 38 688 B).
- Ponowienie: zbuduj obie wersje, zasiej bazy `DemoSeeder`, uruchom
  `node docs/pomiary/1000-mierz-fonty.mjs przed=<adres> po=<adres>`
  (opcjonalnie `SIEC=slow3g`, `PRZEBIEGI=5`).
