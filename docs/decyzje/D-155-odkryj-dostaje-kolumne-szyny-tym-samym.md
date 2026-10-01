## D-155 · `/odkryj` dostaje kolumnę szyny tym samym mechanizmem co strona przepisu

**Data:** 11 września 2026 · Zgłosił właściciel · PR #401 · Status: **obowiązuje** ·
rozwinięcie D-139

### Zgłoszenie

> „tu się zepsuło albo nie było naprawione, prawa kolumna pusta wszystko na środku"

### Co było nie tak — i dla kogo inaczej

`resources/views/pages/discover.blade.php` wołało `<x-layout>` **bez szyny**.
Skutki były dwa i różne:

- **zalogowany** dostawał trzecią kolumnę **zarezerwowaną i pustą**, bo
  `.app-body` od 80rem robi trzy kolumny na każdym ekranie, żeby nawigacja
  boczna nie przeskakiwała między podstronami;
- **gość** dostawał całą stronę zwiniętą do **768 px**, bo ekran bez szyny
  bierze `--container-strona-solo` (D-122).

Tablica „kuKINGi na dziś" stała przez ten czas w kolumnie czytania. Czyli: ta
sama tablica, w dwóch zakładkach jednej listy, raz **obok** tekstu (`/`), raz
**nad** nim (`/odkryj`).

### Pustka po prawej — zmierzona

| okno | rola | pustka z prawej PRZED | PO |
|---|---|---:|---:|
| 1920 | zalogowany | **656 px** | 272 px |
| 1512 | zalogowany | **452 px** | 68 px |
| 1920 | gość (rama 768 px) | 600 px | 408 px (rama 1152) |
| 1512 | gość (rama 768 px) | 396 px | 204 px (rama 1152) |
| 400 | oba | 0 px | 0 px |
| 1512 / czcionka 200% | oba | 36 px | 36 px |

Po zmianie `/odkryj` ma te same liczby co `/` i co strona przepisu. Przy okazji
strona zrobiła się krótsza, bo tablica przestała stać nad wpisami: przy 1512 px
**10 557 → 8 898 px** (−1 659).

**Dwie liczby, które nie miały się zmienić i się nie zmieniły:** kolumna tekstu
**688 px przed i 688 px po** (`docs/UX_50_PLUS.md`: 55–75 znaków — rośnie rama
i to, co OBOK, a nie długość wiersza) oraz rytm pionowy: `h1` [96, 131], wstęp
[155, 210] przed i po, na każdej z trzech szerokości.

### Dlaczego NIE `<x-slot:rail>` — dokładnie z powodu z D-139

Slot renderuje się w kodzie **za całym `<main>`**. Tablica stoi dziś PRZED
wpisami i to jest jej miejsce na telefonie — pod slotem zjechałaby pod wszystkie
karty wpisów i przycisk „Pokaż więcej", czyli **zniknęłaby z ekranu komuś, kto
wchodzi tu z telefonu.**

Dlatego **kolejność w kodzie zostaje kolejnością z telefonu**, a w bok przesuwa
blok dopiero siatka samego ekranu (`.odkryj-uklad` / `.odkryj-szyna`) — ten sam
zabieg i z tego samego powodu co `.przepis-uklad`. Zmierzone: przy 360 px blok
szyny stoi **nad** kolumną czytania (y = 327), przy 1280 i 1512 px **obok** niej
(y = 96).

**To jest już druga strona z tym wzorcem, więc wzorzec przestaje być wyjątkiem
strony przepisu i staje się drogą domyślną dla ekranu, który ma blok do
przeniesienia w bok, a nie treść do dołożenia.**

### Co do szyny weszło i czego tam nie ma

Do kolumny szyny weszła tablica dnia, **która już była na tym ekranie** — to
przeprowadzka jednego bloku w bok, **nie wypełniacz**. Odrzucona droga: szersza
kolumna czytania (1104 px na wpisy to wiersz, którego się nie czyta). Tablica
zachowuje stopkę „Tu nie ma rankingu. Pokazujemy różne osoby, nie najlepsze.";
nie dołożono niczego, co porządkuje ludzi (`AGENTS.md` §12).

> **Reguła ogólna: pustą kolumnę zapełnia się tym, co na ekranie już jest — albo
> wcale. Wypełniacz zostaje na zawsze, a pustkę ktoś w końcu naprawi.**

### Koszt, którego nie było w zgłoszeniu

Owijka siatki **zabrała nagłówkowi regułę `.app-main > h1`** z `tokens.css` —
zmierzony odstęp spadał **24 → 0 px**, czyli wracała usterka zgłoszona
9 września. Arkusz ekranu odtwarza go **tym samym tokenem**, a pilnuje tego
osobna asercja.

Drugi koszt jest jawny: `app.css` dostał jeden wyjątek
`:not(.przepis-uklad):not(.odkryj-uklad)` — lista, która zestarzeje się przy
trzecim takim ekranie. Napisane wprost w komentarzu przy regule.

### Dwa komentarze i jedna lista przestały być prawdziwe

Komentarze w `kuking-board.blade.php` i `DwieKolumnyTamGdzieSieMieszczaTest`
mówiły „tablica stoi w głównej kolumnie `/odkryj`". `SzynaGosciaTest` trzymał
`discover` na liście „ekran gościa BEZ szyny". **Lista, która zostaje po zmianie
produktu, tłumaczy regułę, której już nie uzasadnia** — dokładnie jak komentarz
naprostowany przez D-122.

### Pomiar w automacie też ma kontrolę ujemną

`scripts/dostepnosc.mjs` mierzy od tej zmiany także szynę zajmowaną **od środka
`<main>`** (**progów nie ruszono**). Sprawdzone, że ten pomiar nie jest martwy:
po zmianie `grid-column: 2` → `1` skrypt zgłasza „blok szyny został w kolumnie
czytania… po prawej stronie treści zostaje pusty pas". Po przywróceniu: `0`.

📄 `resources/css/ekran-odkrywania.css` · `resources/views/pages/discover.blade.php` ·
`tests/Feature/OdkrywanieUzywaKolumnySzynyTest.php` · `scripts/dostepnosc.mjs` ·
D-139 · D-122 · issue #365
