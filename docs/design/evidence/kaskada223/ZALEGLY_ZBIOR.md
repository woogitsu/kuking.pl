# Zaległy zbiór D-223 — rozpoznanie, nie sprzątanie

Stanowisko `kaskada223`, 21.09.2026. Gałąź raportu odgałęziona od `origin/main`
(`cd966aae`). Pomiar biegł na warstwie widoku z `flota/martwe-kaskady`
(`7034a45b`) — ta gałąź zmienia `resources/css/app.css` i
`resources/css/marka-ekrany.css`, więc pomiar na `main` byłby pomiarem czegoś
innego.

**Nic nie zostało usunięte ani zmienione.** Ten katalog zawiera wyłącznie
pomiar, próbę kontrolną i ten opis.

---

## Odpowiedź na pytanie zlecenia

Zaległy zbiór to **173 deklaracje w 91 regułach** (nie „~100" — „~100" to liczba
trafień na JEDNEJ stronie w JEDNEJ konfiguracji; po odsianiu zwykłych nadpisań
werdykt zapada na 173).

Z tych 173:

- **152 to martwy kod** — potwierdzony drugim, niezależnym pomiarem na
  wszystkich sześciu mierzonych stronach;
- **18 to fałszywe trafienia miernika** — deklaracje ŻYWE na stronie, której
  strażnik dla nich nie zbadał, choć na niej był;
- **2 są poza zasięgiem pomiaru** — czekają na stan przeglądarki, w który
  Chromium podczas skanu nie wchodzi;
- **1 z tych 173 dubluje się na parze (selektor, własność)**, więc próba
  krzyżowa widziała 172 pozycje. Stąd 152 + 18 + 2 = 172.

Nie znalazłem ani jednej deklaracji, którą dałoby się uczciwie nazwać
**zamierzoną rezerwą dla starszych przeglądarek**. Jedyne udokumentowane
przykrycie zamierzone to wpis `.hero-kolaz-blok { display }` z listy `WYJATKI`
w samym strażniku, i on nie wchodzi do tych 173.

---

## Jak to zmierzyłem

1. **Pełny skan bez zawężenia.** `node scripts/kaskada-martwe-reguly.mjs` (bez
   `--tylko`, bez `--szybko`) w runtime WSL, PostgreSQL `127.0.0.1:55439`, baza
   `kuking_audyt_kaskada223` po `migrate:fresh --seed` (nosiciel
   `/przepisy/rosol-babci-zofii` sprawdzony przed skanem — bez zasianej bazy
   wynik jest pusty i wygląda jak sukces).
   Zmierzone: **144 konfiguracje** (6 stron × 12 szerokości × 2 motywy),
   31 480 reguł z nosicielem, 17 856 deklaracji przepytanych kaskadowo.
   Kolejność warstw odczytana z przeglądarki:
   `properties < theme < base < components < marka < utilities < (kod poza warstwami)`.
   Wynik: `zalegly-zbior-skan.log`, `zalegly-zbior-pomiar.json`.

2. **Próba krzyżowa.** Strażnik wydaje werdykt tylko tam, gdzie tani przesiew
   tekstowy znalazł przykrywacz. Napisałem osobną sondę
   (`zalegly-zbior-proba-krzyzowa.mjs`), która zadaje to samo pytanie —
   „czy zdjęcie tej deklaracji zmienia `getComputedStyle` któregokolwiek
   nosiciela" — **na każdej z sześciu stron, bez przesiewu**, dla wszystkich
   173 deklaracji. Wynik: `zalegly-zbior-proba-krzyzowa.log`.

Sonda jest osobnym plikiem celowo: **`scripts/kaskada-martwe-reguly.mjs` nie
został tknięty**, bo PR #960 jest w toku.

---

## Rodziny znalezisk

| # | Rodzina | Deklaracji | Reguł | Co z tym zrobić |
|---|---|---:|---:|---|
| R0 | **Fałszywe trafienie miernika** — deklaracja żywa na stronie, której strażnik dla niej nie zbadał | 18 | 12 | Poprawić miernik. Nie usuwać. |
| R1 | **Poza zasięgiem pomiaru** — czeka na stan, w który przeglądarka nie weszła | 2 | 2 | Decyzja właściciela. Nie usuwać. |
| R2 | **Martwy kod: arkusz spoza `@layer` bije warstwy** | 79 | 46 | Usuwać partiami albo naprawić warstwowanie arkuszy marki. |
| R3 | **Martwy kod: przykrycie wewnątrz warstw** | 34 | 25 | Usuwać partiami. |
| R4 | **Martwy kod: powtórzenie tej samej wartości** | 40 | 26 | Usuwać partiami — najtańsza partia. |
| | **razem** | **173** | **91** | |

Rodziny R2–R4 to łącznie **153 deklaracje**; po odjęciu jednej pozycji, która
wpada do R1 po rozstrzygnięciu (patrz niżej), zostaje **152 potwierdzonego
martwego kodu**.

---

### R0 — fałszywe trafienia miernika (18 deklaracji, 12 reguł)

**Mechanizm, zmierzony.** Strażnik liczy `badanaW` (mianownik w „martwa w N/N
zbadanych konfiguracjach") **dopiero po znalezieniu przykrywacza**
(`scripts/kaskada-martwe-reguly.mjs`, pętla werdyktu: `if (!przykrywacz)
continue;` stoi PRZED `wynik.zbadane.push(klucz)`). Konfiguracje, w których
deklaracja ma nosiciela, ale nikt jej nie przykrywa — czyli te, w których ona
**działa** — nie trafiają do mianownika w ogóle. Jeżeli przykrywacz istnieje
tylko na jednej z sześciu stron, werdykt brzmi „martwa w 24/24 zbadanych
konfiguracjach" i czyta się jak jednomyślność, choć pięć stron, na których
deklaracja jest jedyną obowiązującą, nigdy nie weszło do rachunku.

Dwa przykłady z nazwą selektora i własności:

- **`.field-input { min-height: var(--pole-wysokosc-min) }`** (warstwa
  `components`). Strażnik: „martwa w 24/24", przykrywacz `.podziel-sie-adres`
  = `0px` na stronie przepisu. Próba krzyżowa: **żywa na `/login`, 2/2
  nosicielach, przy 320 px i przy 1280 px.** Przykrywacz istnieje wyłącznie na
  stronie przepisu, gdzie jedynym `.field-input` jest pole „podziel się
  adresem".
- **`.mt-6 { margin-top: var(--spacing-6) }`** (warstwa `utilities`).
  Strażnik: „martwa w 24/24", przykrywacz `.chipsy` na `/szukaj`. Próba
  krzyżowa: **żywa na `/odkryj` (1/1), `/tagi` (1/1) i `/login` (1/2)**.

Pozostałe z tej rodziny: `.sekcja-strony { border-radius }`,
`{ background-color }`, `{ border }`, `{ padding }`; `.field-input
{ background-color }`, `{ border-radius }`; `.field-help { font-size }`;
`.nadtytul { margin }`; `h1 { font-size }`; `h1, h2, h3 { color }`;
`.kuking-board-person, .kuking-board-post { padding }`; `.kuking-board-post-photo
img { object-fit }`, `{ border-radius }`; `.kuking-board-person-body,
.kuking-board-post-body { max-width }`; `.kuking-board-post-body { gap }`;
`.kuking-board-footer { font-size }`.

Wszystkie osiemnaście ma **jedną przyczynę** i jedną poprawkę: mianownik ma
liczyć konfiguracje, w których selektor **miał nosiciela** (strażnik już to
zbiera, w `wynik.zNosicielem`), a nie te, w których przesiew znalazł
przykrywacza.

### R1 — poza zasięgiem pomiaru (2 deklaracje)

- **`.btn, .field input, .field select, .field textarea { border: 2px solid
  buttontext }`**, warstwa `base`, wewnątrz `@media (forced-colors: active)`.
  Chromium podczas skanu nie jest w trybie wymuszonych kolorów, więc pomiar tej
  reguły nie dotyczy. Uwaga: z samej algebry warstw ona i tak przegrywa
  (`base` < `components`, a `components .btn` ustawia `border`) — czyli
  obwódka wysokiego kontrastu prawdopodobnie **nie działa**, ale to jest
  hipoteza wyprowadzona z kolejności warstw, a nie pomiar. W arkuszach jest
  osiem bloków `forced-colors`; strażnik nie mierzy żadnego.
- **`*, ::before, ::after { transition-duration: 0.01ms }`**, warstwa `base`,
  wewnątrz `@media (prefers-reduced-motion: reduce)`. Chromium startuje
  z `no-preference`, więc deklaracja jest bezczynna z definicji pomiaru.
  Zgłoszony przykrywacz to `components .skip-link` = `0.12s`. Moja sonda nie
  rozstrzygnęła jej wcale (selektor z `::before` po odcięciu pseudoelementów
  przestaje być poprawny).

**Żadnej z tych dwóch nie wolno usunąć na podstawie tego pomiaru.**

### R2 — arkusz spoza `@layer` bije warstwy (79 deklaracji, 46 reguł)

To jest dominująca **strukturalna** przyczyna zaległego zbioru, i to nie
„osiemdziesiąt niezależnych pomyłek", tylko jedna decyzja o arkuszach:

    marka-rama.css   marka-panel.css   marka-powiadomienia.css   marka-przepis.css
    marka-szukaj.css marka-wejscie.css marka-zeszyt.css          szybki-wyglad.css

nie zawierają ani jednego `@layer`. Kod poza warstwami bije **każdą** warstwę,
łącznie z `utilities`, niezależnie od szczegółowości. 66 z 79 tych znalezisk
przykrywa selektor zaczynający się od `[data-marka]`, a `data-marka="kuking-2026"`
stoi bezwarunkowo na `<body>` w `resources/views/components/layout.blade.php:363`
— **nie ma trybu, w którym te reguły z `components` mogłyby jeszcze zadziałać.**

Przykłady:

- `components | .topbar-inner { padding: var(--spacing-3) var(--spacing-4) }` —
  przykrywa `[data-marka] .topbar-inner` (poza warstwami), martwa w 144/144.
- `components | :root { --rezerwa-pod-belka: calc(8rem * var(--user-text-scale,1)) }`
  — przykrywa `:root:has(body[data-marka])` = `calc(11rem * …)`, martwa
  w 432/432.

### R3 — przykrycie wewnątrz warstw (34 deklaracje, 25 reguł)

Uczciwa kaskada: `components` pod `marka`, `base` pod `components`, cokolwiek
pod `utilities`. Przykłady:

- `components | .step-number { font-size: var(--text-title-sm) }` — przykrywa
  `marka | .przepis-siatka .step-number` = `var(--text-body)`, martwa w 24/24.
- `components @(width >= 64rem) | .hero { grid-template-columns: minmax(0px, 34rem) minmax(0px, 1fr) }`
  — przykrywa `marka | .hero` = `minmax(0px, 1fr)`, martwa w 24/24. Miernik
  **odwiedził** szerokości powyżej 64 rem (1025, 1280, 1281, 1537), więc to nie
  jest przypadek nieodwiedzonego progu.

### R4 — powtórzenie tej samej wartości (40 deklaracji, 26 reguł)

Późniejsza reguła nie zmienia wartości, tylko ją powtarza. Usunięcie
wcześniejszej jest niewidoczne dzisiaj i jutro. Przykłady:

- `components | .topbar-inner { display: flex }` — `[data-marka] .topbar-inner`
  też `flex`.
- `marka | .chipsy[aria-label="Co przeszukujemy"] .chip { min-height: var(--control-height-min) }`
  — nieolayerowane `.chip` ustawia dokładnie `var(--control-height-min)`.
  **Wygląda jak strażnik celu dotykowego, a jest kopią.** Cała rodzina
  `.chipsy[aria-label="Co przeszukujemy"]` (14 deklaracji) to powtórzenie reguł
  `.chip` — poza `padding`, `border-radius`, `border` i trzema deklaracjami
  `[aria-current="page"]`, które faktycznie nic nie zmieniają.

---

## Czego ten miernik NIE odwiedza (wynik o mierniku, nie o arkuszu)

To jest osobny wynik i nie należy go mylić z powyższymi 173:

| Luka | Rozmiar | Skutek |
|---|---:|---|
| Strony za logowaniem | `SCIEZKI` to 6 stron gościa | profil, zeszyt, kreator przepisu, panel moderacji, onboarding, powiadomienia **w ogóle nie są mierzone** |
| Selektory bez nosiciela na tych 6 stronach | **1056** | zgłoszone jako `niezmierzone`, nierozstrzygnięte |
| Selektory ze stanem interakcji (`:hover`, `:focus-visible`, `:active`, `:target`) | **61** | odcięte świadomie, nierozstrzygnięte |
| Reguły o zasięgu masowym (> 300 elementów) | 3 | odcięte świadomie |
| `@media (max-width: …)` i `(max-height: …)` | 15+ bloków w źródłach, m.in. `max-width: 12rem`, `15rem`, `16em`, `(max-width: 30rem) and (max-height: 25rem)` | strażnik zbiera **tylko** progi `min-width`/`width >=`; mierzy od 320 px przy stałej wysokości 900 px, więc do tych bloków nigdy nie wchodzi. Dzisiaj nie dały żadnego fałszywego trafienia, ale mogą. |
| `forced-colors`, `prefers-reduced-motion` | 8 + 2 bloki w źródłach | patrz R1 |
| Skala tekstu i układu z profilu (`data-text-scale`, `--user-layout-scale`) | — | nie jest wariantem pomiaru |

Strażnik **uczciwie melduje** 1056 + 61 + 3 jako `niezmierzone` i nazywa to
granicą narzędzia, nie zielenią. To jest dobrze zrobione. Ale to znaczy też, że
zaległy zbiór 173 opisuje **sześć stron gościa w spoczynku**, a nie repozytorium.

---

## Czy któraś z tych reguł mogła chronić minimum UX 50+

Tak — i to jest najważniejsze zdanie tego raportu:
**`.field-input { min-height: var(--pole-wysokosc-min) }` (a `--pole-wysokosc-min`
to `max(48px, calc(64px * var(--user-layout-scale,1)))`) jest jedyną rzeczą
trzymającą minimalną wysokość pól formularza na `/login`, a strażnik zgłosił ją
jako martwą.** W tej samej rodzinie R0 siedzą jeszcze `.field-help { font-size }`
(rozmiar tekstu pomocniczego pod polem), `h1 { font-size }` i `.sekcja-strony
{ padding }`. Gdyby ktoś posprzątał zaległy zbiór „po liście", zdjąłby
gwarancję celu dotykowego z ekranu logowania — czyli dokładnie to, przed czym
ostrzega `AGENTS.md`.

Dodatkowo obie pozycje z R1 to gwarancje dostępności (wysoki kontrast,
ograniczenie ruchu), a nie kosmetyka.

---

## Rekomendacja

**Najpierw poprawić miernik, potem poszerzać bramkę. W tej kolejności, z powodu
liczbowego.**

1. **Nie poszerzać `--tylko` dzisiaj.** Przy obecnym mierniku bramka na całym
   arkuszu byłaby czerwona na 173 pozycjach, z których **18 (10,4%) jest
   nieprawdziwych**, a wśród nich gwarancja 48 px na ekranie logowania.
   Bramka, która każe usunąć żywą regułę, jest gorsza niż brak bramki —
   pierwsza osoba, która wykona jej zalecenie, zepsuje produkt, mając rację
   formalną.
2. **Poprawka miernika jest jedna i tania.** Wszystkie 18 fałszywych trafień
   ma tę samą przyczynę: mianownik `badanaW` liczy konfiguracje
   z przykrywaczem zamiast konfiguracji z nosicielem. Strażnik już zbiera
   `zNosicielem` — brakuje wyłącznie zmiany, po której `zbadane` rośnie także
   wtedy, gdy przykrywacza nie ma. To zdejmuje **100%** fałszywych trafień
   z tego pomiaru. Poprawka wymaga własnej kontroli ujemnej i nie należy jej
   robić w PR #960.
3. **Po poprawce zostają 152 pozycje prawdziwego martwego kodu i wtedy warto
   poszerzać — ale nie wprost.** 79 z nich (52%) ma jedną przyczynę: osiem
   arkuszy marki stoi poza `@layer`. Decyzja „opakować te arkusze w `@layer
   marka`" unieważnia całą rodzinę R2 jednym ruchem i przywraca sens
   deklarowanej kolejności warstw; decyzja „usunąć 79 deklaracji" utrwala stan,
   w którym `@layer` jest w tym repozytorium ozdobą. **To jest pytanie do
   właściciela, nie do agenta** — zmienia zachowanie na wypadek zniknięcia
   arkusza marki.
4. **Rodzina R4 (40 deklaracji, 26 reguł) nadaje się na pierwszą partię
   usuwania niezależnie od punktu 3** — to czyste powtórzenia tej samej
   wartości, więc ich usunięcie nie zmienia zachowania w żadnym scenariuszu,
   łącznie ze zniknięciem warstwy późniejszej.
5. **Rozszerzyć `SCIEZKI` o stronę za logowaniem** przed jakąkolwiek decyzją
   o sprzątaniu. 1056 selektorów bez nosiciela to nie jest „czysto" — to jest
   „niezmierzone", a całe formularze produktu (kreator przepisu, „co dziś
   ugotowałeś") leżą w tej dziurze. Tam są kolejne miejsca, gdzie różnica
   między martwą regułą a gwarancją 48 px jest nierozstrzygnięta.

**Czeka na decyzję właściciela:** punkt 3 (warstwowanie arkuszy marki),
dwie pozycje z R1 (obwódka `forced-colors` i wyłącznik `prefers-reduced-motion`)
oraz kolejność partii usuwania. Nie domykam tego asercją.

---

## Pliki dowodowe w tym katalogu

| Plik | Co zawiera |
|---|---|
| `zalegly-zbior-skan.log` | pełny przebieg strażnika bez `--tylko`, 144 konfiguracje |
| `zalegly-zbior-pomiar.json` | surowy wynik strażnika (`storage/kaskada-martwe-reguly.json`) |
| `zalegly-zbior-proba-krzyzowa.mjs` | sonda: to samo pytanie na każdej stronie, bez przesiewu |
| `zalegly-zbior-pytania.json` | 173 pary (warstwa, selektor, własność) podane sondzie |
| `zalegly-zbior-proba-krzyzowa.log` | wynik sondy — na nim stoi podział 152 / 18 |
