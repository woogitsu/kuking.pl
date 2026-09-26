## D-162 · Manifest PWA nie deklaruje orientacji w ogóle, zamiast deklarować „any"

**Data:** 12 września 2026 · PR #414 · Status: **obowiązuje** ·
kontekst: SEO/PWA-01 z audytu 10 września 2026, zależność issue #278

### Decyzja

`public/manifest.webmanifest` traci klucz `orientation` w całości. Nie zostaje
zastąpiony wartością `"any"`, choć audyt dopuszczał oba warianty.

### Dlaczego w ogóle

`"orientation": "portrait-primary"` wymuszało jedną orientację zainstalowanej
aplikacji, co narusza **WCAG 2.2 §1.3.4 Orientation (AA)** — treść nie może być
ograniczona do jednej orientacji, o ile konkretna nie jest niezbędna. W Kuking
niezbędna nie jest: tryb gotowania przy blacie to typowo telefon albo tablet
położony poziomo, więc blokada uderzała dokładnie w to użycie, **dla którego ten
ekran powstał**.

### Dlaczego usunięcie, a nie „any"

Rozstrzygnięte tekstem W3C Web Application Manifest, nie z pamięci:

- **bez klucza** przetwarzanie manifestu kończy się na „If json\[„orientation"\]
  doesn't exist […] return" — aplikacja nie deklaruje niczego i zostaje
  zachowanie systemu, **łącznie z blokadą obrotu włączoną przez samego
  człowieka**;
- **`"any"`** staje się „default screen orientation for the life of the web
  application", a przeglądarka „MUST return the orientation to the default screen
  orientation any time the orientation is unlocked" — to deklaracja **czynna**.

Oba spełniają 1.3.4. Wybrany jest ten, który zostawia decyzję przy ustawieniu
telefonu: dla grupy 50+ blokada obrotu bywa włączona świadomie i ma być nadrzędna
wobec życzeń strony.

### Co zmierzono przed zdjęciem blokady

Chromium, osobna baza, 15 ekranów × 844×390 i 932×430 (390×844 jako odniesienie):
nadmiar w poziomie **0 px na każdym ekranie**; dolna belka zostaje widoczna
(`position: fixed`, 67 px), bo progi układu są **wyłącznie szerokościowe**, a
844 px = 52,75rem, poniżej progu 64rem; najmniejszy cel dotykowy w belce 66 px;
**zero kontrolek całkiem zasłoniętych** przez belkę po przewinięciu na dół;
przyciski trybu gotowania 560×72, 608×72 i 216×60 px. Miejsce na treść między
belkami: 247 px (844×390) i 287 px (932×430) wobec 701 px w pionie — widok jest
niższy, ale nic się nie rozjeżdża.

**Drugiej blokady w CSS nie ma**: w całym `resources/` nie występuje ani jedno
`@media (orientation: …)` ani zapytanie o wysokość okna.

### Strażnik

`tests/Feature/ManifestNieWymuszaOrientacjiTest.php` czyta **plik**, bo
`orientation` działa dopiero w zainstalowanej aplikacji i żaden test strony ani
`scripts/dostepnosc.mjs` nie miał jak tej blokady zobaczyć. Przechodzi wyłącznie
brak klucza albo `"any"` — **lista dozwolonych, nie zakazanych**, więc łapie
także `portrait`, `landscape`, `landscape-primary` i `natural`. Drugi test w tym
pliku jest kontrolą dodatnią (poprawny JSON + komplet pól), bez której strażnik
byłby zielony także nad pustym plikiem.

### Czego ta decyzja NIE rozstrzyga

Nie mówi, czy i jak promować instalację PWA — to jest issue #278 i osobna
decyzja. Zdejmuje tylko przeszkodę, która kazała tamto odłożyć.

📄 `public/manifest.webmanifest` ·
`tests/Feature/ManifestNieWymuszaOrientacjiTest.php` ·
`docs/research/audyt-2026-09-10/08_SEO_PWA_UDOSTEPNIANIE.md` · issue #278
