## D-122 · Gość dostaje prawą szynę obok treści, a nie pod nią — bo komentarz mówił, że gość szyny nie ma, i był nieprawdziwy od 7 września

**Data:** 11 września 2026 · Zgłosił i rozstrzygnął właściciel · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Decyzja obowiązuje — gość
> dostaje szynę obok treści — ale WSZYSTKIE liczby w niej są dziś martwe, bo
> nadpisał je późniejszy port marki. `resources/css/marka-rama.css:185` daje
> szynę **330 px** (wpis mówi 352), `:39` ramę `min(1120px, …)` (wpis: sufit
> 1152), `:50` kolumnę treści **760 px** (wpis: 720). Do tego `:49` ukrywa
> `.side-nav` całkowicie, co znosi przesłankę odróżniającą gościa od
> zalogowanego, na której zbudowana jest sekcja „Ekran gościa BEZ szyny
> zostaje jednokolumnowy"; atrybut `data-marka="kuking-2026"` siedzi na
> `<body>` bezwarunkowo (`resources/views/components/layout.blade.php:359`).
> Strażnik `tests/Feature/SzynaGosciaTest.php:277-310` niczego nie zauważył,
> bo czyta TEKST ARKUSZA, a nie wynik kaskady.

### Zgłoszenie

„niektóre podstrony jak napisz do nas jest bardzo wąskie, gdzie po prawej i lewej
można coś dodać na kompie". Audyt UI/UX niezależnie nazwał to §4 i było to jego
jedyne P0.

### Co było nieprawdą i od kiedy

`resources/css/app.css` zwijał układ niezalogowanego do JEDNEJ kolumny 768 px na
każdej szerokości, a uzasadniał to zdaniem „Gość nie ma nawigacji bocznej ANI
SZYNY". Pierwsza połowa jest prawdą do dziś. **Druga była prawdą jeden dzień:**
zdanie powstało 6 września, 7 września `/szukaj` dostało `<x-slot:rail>`,
10 września doszły `/napisz-do-nas` i `/@nazwa` (#231). Komentarz został i przez
kolejne dni tłumaczył regułę, której już nie uzasadniał.

Zmierzone 11 września, okno 1920 px, gość na `/napisz-do-nas`: treść 720 px
w ramce 768 px, a blok „Nie możesz się zalogować" — czyli odpowiedź, po którą ta
osoba przyszła — na **y = 1964 px**, dwa ekrany niżej. Na `/@nazwa` szyna zaczynała
się na y = 5573 px.

### Decyzja

Gość na ekranie Z SZYNĄ dostaje od 80rem dwie kolumny: treść 720 px + szyna 352 px,
sufit `--container-strona-solo-z-szyna` = 1152 px. Trzech kolumn nie dostaje, bo
nawigacji bocznej nie ma. Po zmianie blok szyny stoi na **y = 96 px** przy 1920
i przy 1280 px.

### Ekran gościa BEZ szyny zostaje jednokolumnowy i wyśrodkowany

Zalogowany ma trzecią kolumnę zarezerwowaną NAWET bez szyny (#294), żeby nawigacja
boczna stała na każdym ekranie w tym samym miejscu. U gościa ten powód nie istnieje
— rezerwacja dołożyłaby 384 px pustki po prawej i zepchnęła treść w lewo, czyli
powtórzyłaby zgłoszenie właściciela o panelu moderacji. Cena: strony gościa mają
dwie szerokości, 768 i 1152 px. Przyjęta świadomie.

### Liczy się TREŚĆ slotu, nie sam slot

`<x-slot:rail>` bywa podany i pusty — cudzy profil bez tagów i bez publicznych
zeszytów, oglądany przez gościa, nie wypisuje ani jednego bloku (blok z liczbami
stoi pod `@auth`). Samo `isset($rail)` dałoby tam pustą kolumnę 352 px, a pusta
kolumna wygląda na usterkę układu, nie na wybór.

### `app-body-solo` znaczy „układ gościa", nie „jedna kolumna"

Klasa `app-body-solo-z-szyna` **dochodzi** do `app-body-solo`, nie zastępuje jej.
`ekran-profilu.css` czyta `:not(.app-body-solo)`, żeby zostawić gościowi liczby
o osobie w karcie profilu — bloku w szynie gość nie dostaje (D-091), więc
zastąpienie klasy zabrałoby mu te liczby całkiem.

### Czego pilnują pomiary

`SzynaGosciaTest` (klasy układu, publiczne ekrany z szyną, pusta szyna, kolejność
reguł w arkuszu), `UkladGosciaTest` oraz sekcja „Szyna gościa (D-122)"
w `scripts/dostepnosc.mjs`. Lista publicznych ekranów z szyną **nie jest wpisana
z ręki** — test skanuje katalog widoków, więc czwarty taki ekran wejdzie do pomiaru
sam.

### Co musiałoby się stać, żeby to zmienić

Pomiar pokazałby, że na ekranie gościa szyna odciąga uwagę od treści, po którą
przyszedł. Wtedy znika treść szyny na tych ekranach, a nie kolumna.

---


> **Uwaga (20.09.2026, pomiar do D-223).** Liczby tej decyzji nadal obowiązują
> jako ROZSTRZYGNIĘCIE, ale reguły CSS, w których je zapisano, **w większości nie
> dochodzą do przeglądarki**. Zmierzone `getComputedStyle` na wyrenderowanych
> stronach, 72 konfiguracje: `.app-body { grid-template-columns:
> var(--container-sidenav) … }`, `.app-body { max-width: var(--container-strona) }`
> oraz `.uklad-solo .topbar-inner, .uklad-solo .site-… { max-width:
> var(--container-strona-solo…) }` są **całkowicie przykryte** przez arkusze
> `resources/css/marka-*.css`, które nie są owinięte w żadną warstwę — a kod
> spoza warstw bije każdą warstwę nazwaną, także `utilities`.
>
> Znaczy to, że układ, który widzi gość, ustala dziś warstwa marki, a nie te
> reguły. Sama decyzja zostaje bez zmian i nic tu nie usuwamy: usunięcie martwej
> reguły JEST zmianą zachowania na wypadek zniknięcia arkuszy marki i wymaga
> osobnego rozstrzygnięcia. Pilnuje tego `scripts/kaskada-martwe-reguly.mjs`.
> Strażnik, który czytał TEKST arkusza, opisywał tu stan nieistniejący — po to
> powstało D-223.
