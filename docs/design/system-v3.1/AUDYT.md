# Audyt systemu — 7 września 2026

Co zostało sprawdzone przy przenoszeniu paczki `KuKING-design-system-v3.1-poprawiony`
do tego systemu, co się nie zgadzało i co z tym zrobiono. Plik jest po to, żeby
przy następnej podmianie arkusza nikt nie „naprawił” tych rzeczy z powrotem.

---

## 1. Błędy znalezione i naprawione

### 1.1 Brak resetu `box-sizing` — błąd globalny

**Objaw:** belka górna wystawała poza stronę o 48 px, strona miała poziomy pasek
przewijania przy każdej szerokości okna.

**Przyczyna:** arkusze źródłowe (`komponenty.css`, `strona-www.css`,
`szablony.css`) były pisane pod preflight Tailwinda 4 i milcząco na nim polegają.
Bez `box-sizing: border-box` `padding-inline: 24px` na `.pas-wnetrze` dolicza się
do `width: 100%`.

**Poprawka:** odpowiednik resetu w `tokens/base.css`, w zakresie dokładnie takim,
na jakim opierają się arkusze — `box-sizing`, wyzerowane marginesy
`blockquote`/`figure`/`fieldset`, listy bez punktora, dziedziczenie kroju
w kontrolkach, `svg { display: block }`, `table { border-collapse: collapse }`.

> Gdyby ten system kiedyś wrócił pod Tailwinda, ten blok trzeba usunąć —
> inaczej dwa resety będą się nadpisywać.

### 1.2 Tytuł dotykał krawędzi karty

`.karta-tytul` ma margines tylko z dołu, bo w paczce zawsze stała nad nim główka
z autorem. Karta przepisu bez główki zaczyna się tytułem.
**Poprawka:** `.karta-wpisu > .karta-tytul:first-child { margin-top: var(--spacing-5) }`.

### 1.3 Opisy w „Kto to widzi” były pogrubione

`.choice` jest etykietą, a warstwa base daje każdej etykiecie wagę 700.
`.pole-zaznaczenia` zerowało to u siebie, `.choice-help` nie.
**Poprawka:** `.choice-help { font-weight: 400 }`.

### 1.4 Dwa pola liczbowe rozjeżdżały się do dwóch wierszy

**Objaw:** „Ile minut” i „Ile porcji” łamały się na dwa wiersze mimo tego, że
każde ma sufit 10 znaków.

**Prawdziwa przyczyna** (pierwsza diagnoza była błędna — patrz §1.11): klasy
szerokości w ogóle nie działały. Pole miało wewnętrzną szerokość około
dwudziestu znaków (257 px), więc dwa takie nie wchodziły w kolumnę 423 px.
**`min-width: 0` nie miało tu szans:** przy `flex-wrap: wrap` przydział do
wiersza liczy się z hipotetycznej szerokości elementu, PRZED ściskaniem — więc
zawinięcie następowało wcześniej, niż ściskanie miało okazję zadziałać.
**Poprawka:** §1.11. Po niej pola mają po 104 px i same mieszczą się w jednym
wierszu; `.rzad-pol .field { min-width: 0 }` zostaje jako zabezpieczenie na
węższe kolumny.

### 1.11 Wszystkie klasy szerokości pól były martwe — całe D-109 nie działało

**Objaw:** `szerokosc="liczba"` (10 znaków) dawało pole 257 px, a
`szerokosc="srednie"` (40 znaków) — pełną szerokość kolumny. Prostokąt przestał
być obietnicą długości odpowiedzi, czyli regułą D-109 — martwą w każdym
ekranie, kicie i szablonie.

**Przyczyna:** blok „audyt układu” z v3.1 (§17 arkusza) ustawia
`.field-input { max-width: 100% }`. Ta deklaracja ma **tę samą wagę** co
`.field-input-rok/-liczba/-krotkie/-srednie`, ale stoi **później w kaskadzie**,
więc wygrywała i kasowała wszystkie cztery sufity (8ch, 10ch, 22ch, 40ch).

**Poprawka:** `max-width: 100%` usunięte z tej reguły (`min-width: 0` zostaje).
Przy `box-sizing: border-box` i `width: 100%` było zresztą zbędne. Zmierzone po
poprawce: `liczba` 104 px, `srednie` 415 px, oba pola liczbowe w jednym wierszu.

> To jedyne miejsce, w którym **odjąłem** deklarację z arkusza źródłowego,
> a nie dopisałem własną. Gdyby kiedyś wróciła, cztery klasy szerokości znowu
> przestaną działać — i znowu nikt tego nie zauważy, bo nic nie psuje się
> widocznie.

### 1.5 Kolizja nazw właściwości w `RecipeCard`

`czas` znaczyło jednocześnie „data wpisu” (dziedziczone z `PostCard`) i „czas
gotowania”. Karta przepisu w strumieniu gubiła przez to datę.
**Poprawka:** czas gotowania to `czasPrzygotowania`; `czas` zostaje datą.

### 1.6 Kropka rozdzielająca zostawała sama na końcu wiersza

W karcie „Ugotowałem” przy zawinięciu nazwy przepisu `·` lądowała na początku
następnej linii. **Poprawka:** kropka i czas w jednym elemencie `nowrap`.

### 1.7 Odpowiedź w wątku czytała się od tyłu

„w odpowiedzi do Haliny” stało **nad** nazwiskiem autora, więc czytnik ekranu
i oko dostawały „w odpowiedzi do Haliny / Marek”.
**Poprawka:** autor pierwszy, dopisek pod nim.

### 1.8 Przeskok nagłówka na ekranie Zeszytu

`h1` → `h3` bez `h2` po drodze (karty zwarte miały `poziomTytulu="h3"` bez
nagłówka sekcji nad nimi). **Poprawka:** `h2`.

### 1.9 Brak „Przejdź do treści” na stronach publicznych

Ekrany zalogowanego dostawały odnośnik pomijający ze szkieletu `AppShell`;
strony publiczne mają własny szkielet i go nie miały.
**Poprawka:** odnośnik w `BelkaGoscia` i w szablonie strony powitalnej.

### 1.10 Kolizja arkuszy `komponenty.css` i `strona-www.css`

W paczce nigdy nie były wczytywane razem; tutaj consumer dostaje jeden
`styles.css`. Kolidujące reguły strony publicznej (`.kroki`,
`.lista-skladnikow`, `.pochodzenie`, `.przepis-zdjecie`) są zawężone do
korzenia `.strona`.

---

### 1.12 Belka górna zamieniała się na telefonie w wieżę wysoką na 209 px

**Objaw:** przy 380 px okna „Powiadomienia” i „Konto” stawały jedno pod drugim,
a belka górna była wyższa niż kompozytor pod nią.

**Przyczyna (zmierzona):** akcje mają razem 348 px (205 + 12 + 143), a przy
380 px okna zostaje 332 px szerokości treści. Przy `flex-wrap: wrap` przydział
do wiersza liczy się z hipotetycznej szerokości elementu, **przed** ściskaniem —
więc zawinięcie następowało, zamiast dopasowania.

**Poprawka:** poniżej 40rem znikają **ikony** obu akcji, nie napisy. To reguła
„nigdy” nr 1 zastosowana od właściwej strony: ikona jest ozdobą i ona ustępuje
pierwsza. Zmierzone po poprawce: akcje 173 + 12 + 95 = **280 px w jednym
wierszu**, belka **128 px** zamiast 209.

Paczka źródłowa mówi tylko, że poniżej 1024 px znika **pole szukania** i że
szukanie przenosi się do dolnego paska. O dwóch akcjach konta nie mówi nic —
a „Powiadomienia” nie ma gdzie się podziać, bo nie ma jej w pięciu pozycjach
dolnego paska. **To rozstrzygnięcie jest moje i wymaga potwierdzenia
właściciela.**

### 1.13 Nowa karta zadeklarowana na oko, nie zmierzona

Karta „Telefon” dostała wysokość 780 px z oszacowania, a miała 2377 px — czyli
ucinała drugą kartę i stopkę. To ten sam błąd, który chwilę wcześniej
poprawiałem na 44 kartach.

**Poprawka:** demo skrócone do kompozytora i jednej karty, a wysokość
**zmierzona w ramce o prawdziwej szerokości 380 px** — nie przez zwężenie
elementu, bo zapytania medialne patrzą na okno, nie na element. Wynik 1058 px,
zadeklarowane 1060.

## 2. Co zostało sprawdzone i jest w porządku

### Kontrast — policzony, nie oceniony na oko

24 pary tokenów w obu motywach, licznik uruchamiany na żywym arkuszu:

| Motyw | Sprawdzonych par | Najniższy wynik | Poniżej progu |
|---|---|---|---|
| jasny | 24 | **3.51 : 1** (obwódka pola, próg 3:1) | 0 |
| ciemny | 24 | **3.83 : 1** (obwódka pola, próg 3:1) | 0 |

Najniższy wynik dla **tekstu** to 6.67:1 (`brand-tint-ink` na `brand-tint`),
czyli półtora raza ponad wymagane 4.5:1.

### Dostępność — trzynaście ekranów serwisu i trzy strony publiczne

Sprawdzane automatycznie na żywym drzewie: obrazy bez `alt`, kontrolki bez
nazwy dostępnej, nawigacje bez `aria-label`, pola bez etykiety, cele dotyku
poniżej 44 px, poziome przepełnienie, liczba `h1`, przeskoki poziomów nagłówków.

Po poprawkach 1.8 i 1.9 — **zero uwag na każdym ekranie**.

### Kaskada — czy któryś modyfikator jest martwy jak D-109

Po znalezieniu §1.11 przeszukałem **376 reguł jednoklasowych** we wszystkich
trzech arkuszach w poszukiwaniu tego samego układu: klasa bazowa stojąca
później w kaskadzie i ustawiająca właściwość, którą wcześniej ustawia klasa
modyfikująca o tej samej wadze. **Poza D-109 nie ma ani jednego takiego
miejsca** — trzy trafienia okazały się fałszywe (rodzic i dziecko, nie
modyfikator; albo identyczne wartości w regule spod zapytania medialnego).

Sprawdziłem też każdy modyfikator na żywo, w oderwaniu od demo: `btn-duzy`
56 px, `btn` 48 px, `btn-pelny` pełna szerokość, `avatar-sm` 40 px,
`avatar` 48 px, `avatar-lg` 80 px, `badge-cichy` 15 px, `field-input-dlugie`
192 px, `field-input-rok` 83 px (8 znaków), `field-input-krotkie` 228 px
(22 znaki), `chip` 48 px, kółko „Dodaj” 48 px w kolorze marki z białą ikoną.
Wszystkie wygrywają.

### Fokus

Dziewięć reguł `:focus-visible`, żadnego gołego `:focus` poza jednym
`outline: none` — czyli standardowy układ: pierścień pokazuje się przy
klawiaturze, nie przy kliknięciu myszą. Dołożyłem do tego zabezpieczenie
`@supports not selector(:focus-visible)`: w przeglądarce, która nie zna tego
selektora, `outline: none` zostawiłoby stronę **bez żadnego znaku fokusu**.
Teraz w takim wypadku wraca zwykły `:focus`.

### Twarde wysokości i szerokości
Reguła „żaden element z tekstem nie ma `height` na sztywno” trzyma się w całym
arkuszu: wszystkie znalezione `height` siedzą na ikonach i awatarach, czyli na
kwadratowych grafikach. Nie ma ani jednej szerokości w pikselach większej niż
300 px poza `boards.css`, gdzie wymiar jest geometrią wymuszoną przez serwis
albo przez drukarkę.

---

## 3. Czego nie dało się sprawdzić

- **Wygląd w prawdziwych przeglądarkach na prawdziwych urządzeniach.**
  Wszystko powyżej mierzono w jednym silniku.
- **Czytelność z ludźmi.** Rozmiar podstawowy 18 px, skala tekstu i wybór kroju
  to decyzje, które rozstrzyga test z odbiorcą, a nie pomiar.
- **Wydruk.** `boards.css` ma reguły `@page` i `@media print` przeniesione
  z paczki, ale nikt tego nie wydrukował.
- **Trzy zdjęcia demonstracyjne** (`soup`, `cake`, `pasta`) mają po 92 px
  szerokości i w karcie są rozmyte. Stoją jako zaślepki.
