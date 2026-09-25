## D-154 · Odstęp między blokami należy do JEDNEJ strony pary — w rytmie artykułu do `margin-top`

**Data:** 11 września 2026 · Zgłosił właściciel · PR #400 · Status: **obowiązuje**

### Zgłoszenie

> „a propos przepisw, trzeba naprawić te odstępy między tekstami w przepisach"

### Co było zmierzone

Trzynaście par bloków na `/przepisy/{slug}`, w trzech stanach: okno
**1512 px**, okno **400 px** i okno 1512 px przy czcionce przeglądarki **200%**.
**Pięć par stało dosłownie na zero pikseli**, a dwie miały różny odstęp na
telefonie i na desktopie:

| Para bloków | 1512 px | 400 px | 1512 px + 200% |
|---|---:|---:|---:|
| `<h1>` → wiersz autora | **0** | **0** | **0** |
| zdjęcie → plakietki | **0** | **0** | **0** |
| „Skąd ten przepis" `<h2>` → 1. akapit | **0** | **0** | **0** |
| „Składniki" `<h2>` → lista | **0** | **0** | **0** |
| „Przygotowanie" `<h2>` → lista kroków | **0** | **0** | **0** |
| wiersz autora → zdjęcie | 36 | 20 | 72 |
| wstęp → siatka składniki/kroki | 36 | 20 | 72 |

Po zmianie żadna para nie stoi na zerze, każda para bloków artykułu ma **tę samą
liczbę w siatce i poza nią** (24 px przy 1512 i przy 400 px, 48 px przy 200%),
a przepis ubogi — bez zdjęcia, bez plakietek, bez komentarzy — dostaje
**24 / 24 / 24 / 24 / 24 px** bez dziury po pustej liście plakietek.

### Cztery przyczyny, każda inna

1. **Reset Tailwinda zeruje marginesy nagłówków**, a `.app-main > h1` naprawia to
   na ~50 podstronach, ale nie tutaj, bo `<h1>` przepisu siedzi
   w `<article><header>`, nie wprost w `<main>`.
2. **`margin: 0` na `.recipe-facts` zjadało rytm `.stack`** — ta sama swoistość
   (0,1,0), dalsze miejsce w pliku.
3. **Marginesy raz się zlewają, a raz sumują.** Poniżej 80rem `<article>` jest
   blokiem: `mb-4` wiersza autora zlewał się z `margin-top` zdjęcia do 20 px. Od
   80rem ten sam `<article>` jest **siatką**, a marginesy elementów siatki się
   nie zlewają — więc 16 + 20 = 36 px. **Ta sama strona miała dwa różne odstępy
   zależnie od szerokości okna, czego nie widać, dopóki się nie zmierzy obu.**
4. **Klasy `mb-2` / `mt-0` / `mb-4` w szablonie.** Utility w Tailwindzie 4 leży
   w warstwie stojącej **po** `components`, więc dopóki tam były, żadna reguła
   arkusza nie mogła ich poprawić.

### Decyzja

> **Odstęp między dwoma blokami należy do jednej strony pary. Druga strona jest
> wyzerowana — jawnie, tą samą regułą.**

W rytmie artykułu to `margin-top`:

```css
.przepis-uklad > *      { margin-bottom: 0; }
.przepis-uklad > * + *  { margin-top: var(--spacing-6); }
```

`margin-bottom: 0` na dzieciach **jest częścią tej reguły, nie ozdobą**: bez
niego dolny margines dziecka raz się zlewa (blok), a raz sumuje (siatka od
80rem) — czyli wraca przyczyna nr 3.

Wewnątrz jednego bloku odstęp należy do góry pary tak samo konsekwentnie, tylko
realizuje go `margin-bottom` **z wyzerowanym ostatnim dzieckiem**
(`.przepis-uklad > header > :last-child`, `.recipe-story > :last-child`) — bo
tam odstęp od bloku do bloku należy już do rytmu artykułu wyżej. **Jedna para,
jedna strona, zawsze zadeklarowana** — mieszanie stron w jednym zakresie jest
tym, co dało pięć zer i dwie różne liczby na jednej stronie.

### Odstępy z tokenów, a nie z pikseli

To są odstępy **między blokami tekstu**, więc mają rosnąć razem z pismem: przy
czcionce przeglądarki 200% `--spacing-6` to 48 px, nie dalej 24. To druga strona
D-082 i D-107 — **tamte minima są fizyczne** (palec nie rośnie od powiększenia
czcionki), **ten odstęp jest typograficzny.** Osobny test pilnuje, żeby
`--spacing-3/4/5/6` zostały w `rem`: w pikselach asercje o tokenach dalej by
przechodziły, sprawdzając nic.

### Dwa jawne wyjątki i jeden cudzy obszar

- **`.danger-zone` zachowuje `--spacing-8`** (32 px): `AGENTS.md` §5 wymaga, żeby
  akcja destrukcyjna była odsunięta od zwykłych.
- **`.notice` zostaje przy wspólnym `--spacing-5`**: dopisanie go tu poprawiłoby
  rytm przepisu kosztem komponentu widocznego na kilkunastu innych ekranach.
- **`.komu-wyszlo-naglowek` ma 4 px** między `<h2>` i paskiem liczb, gdy pasek
  zejdzie pod nagłówek (zmierzone na 400 px). To też jest zlepione, ale to
  świadoma decyzja z `karta-ugotowania.css` i cudzy obszar — **zgłoszone jako
  obserwacja, nie zmienione przy okazji.**

### Selektory strukturalne, nie pozycyjne

`> header`, `> * + *`, `.recipe-story > p` — nie `:nth-of-type`. Pusta lista
plakietek znika z układu przez `:not(:has(li))`, **nie `:empty`** — Blade
zostawia w `<ul>` znaki nowej linii, a te są węzłami tekstowymi; sprawdzone
w Chromium: reguła z `:empty` nie zadziałała ani razu.

### Kontrola ujemna złapała dwie wady samego testu

Wzorzec `<header>…</header>` trafiał w **belkę serwisu**, nie w nagłówek
przepisu, więc test przechodził także z przywróconymi klasami utility.
A `preg_match_all` zjada `}` razem z dopasowaniem, więc kotwica „reguła musi
stać po `}`" łapała **co drugą regułę** (200 zamiast 407). Dopiero po obu
poprawkach każdy z czterech sabotaży zaświecił na czerwono.

📄 `resources/css/app.css` · `tests/Feature/RytmPionowyStronyPrzepisuTest.php` ·
D-082 · D-107 · D-099 · D-106
