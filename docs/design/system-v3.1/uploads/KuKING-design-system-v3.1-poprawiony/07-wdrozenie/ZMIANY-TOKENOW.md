# Zmiany tokenów — dzisiejszy arkusz obok nowego, token po tokenie

Porównuje `03-kod-wygladu/tokens.css` z paczki właściciela (stan serwisu na
7 września 2026) z `01-fundamenty/tokens.css` z tej paczki.

**Wniosek w jednym zdaniu: nie zmienia się ani jedna wartość. Dochodzi
dwanaście nazw, znikają trzy, a to, co widać na ekranie po podmianie, bierze
się nie z tokenów, tylko z tego, co ten plik ze sobą zabiera i przynosi
w warstwie `@layer base` — i to jest sekcja 6, najważniejsza w tym dokumencie.**

---

## 0. Czym liczyłem

Wszystkie liczby w tym pliku są policzone, nie oszacowane. Polecenia do
powtórzenia (uruchamiane z katalogu `03-kod-wygladu/` paczki właściciela):

```bash
# ile razy w ogóle serwis sięga po token
grep -o -- 'var(--[a-z0-9-]*' app.css ekran-*.css karta-ugotowania.css tokens.css | wc -l

# ile razy sięga po KONKRETNY token (także z wartością zapasową)
grep -oE -- 'var\(--leading-title[,)]' app.css ekran-*.css karta-ugotowania.css tokens.css | wc -l

# nazwy tokenów zdefiniowanych w pliku
grep -oE '^\s*--[a-z0-9-]+:' tokens.css | tr -d ' :' | sort -u | wc -l
```

Wynik zbiorczy:

| co | ile |
|---|---|
| sięgnięć `var(--…)` w arkuszach serwisu razem | **580** |
| z tego w `app.css` | **439** |
| w `tokens.css` (tokeny liczone przez inne tokeny) | **106** |
| w `ekran-dodawania.css` | **22** |
| w `ekran-wyszukiwania.css` | **7** |
| w `ekran-profilu.css` | **3** |
| w `karta-ugotowania.css` | **3** |
| w `fonts.css` | **0** |
| unikalnych nazw tokenów dziś | **70** |
| unikalnych nazw tokenów w nowym pliku | **79** |

---

## 1. Co zostało bez zmian — 94 definicje z 94, czyli wszystkie wspólne

To jest najliczniejsza grupa i stoi na początku celowo. **Każdy token, który
istnieje w obu plikach, ma w obu dokładnie tę samą wartość — 66 z 66 w trybie
jasnym i 28 z 28 w trybie ciemnym.** Nie ma ani jednego wyjątku.

Znaczy to tyle: podmiana pliku nie może popsuć żadnego z 580 istniejących
sięgnięć `var(--…)`, bo każde z nich dostanie tę samą liczbę co dziś. Nowy
arkusz nie jest przepisaniem serwisu w imię odświeżenia — jest dopisaniem
dwunastu rzeczy do tego, co już działa.

### 1.1 Tryb jasny — 66 tokenów bez zmiany wartości

| Token | Wartość (w obu plikach) |
|---|---|
| `--color-surface` | `#FAF6F0` |
| `--color-surface-raised` | `#FFFFFF` |
| `--color-surface-sunken` | `#F1EBE1` |
| `--color-border` | `#E4DACB` |
| `--color-border-strong` | `#8A7A63` |
| `--color-ink` | `#2B241D` |
| `--color-ink-muted` | `#5C5347` |
| `--color-ink-inverse` | `#FFFFFF` |
| `--color-brand` | `#B3401F` |
| `--color-brand-dark` | `#8C3018` |
| `--color-brand-tint` | `#F5E4DC` |
| `--color-brand-tint-ink` | `#8C3018` |
| `--color-brand-solid` | `#B3401F` |
| `--color-brand-solid-hover` | `#8C3018` |
| `--color-accent` | `#7A5C10` |
| `--color-accent-tint` | `#FCEACB` |
| `--color-accent-tint-ink` | `#6B4E0C` |
| `--color-danger` | `#B3261E` |
| `--color-danger-tint` | `#FBE2E0` |
| `--color-danger-tint-ink` | `#8C1E17` |
| `--color-danger-solid` | `#B3261E` |
| `--color-danger-solid-hover` | `#8C1E17` |
| `--color-success` | `#1E7B3E` |
| `--color-success-tint` | `#DFF3E4` |
| `--color-success-tint-ink` | `#155C2E` |
| `--color-focus` | `#155EEF` |
| `--font-sans` | `"Inter Variable", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans", "Liberation Sans", Arial, sans-serif` |
| `--text-help` | `calc(1rem * var(--user-text-scale, 1))` |
| `--text-body` | `calc(1.125rem * var(--user-text-scale, 1))` |
| `--text-body-lg` | `calc(1.25rem * var(--user-text-scale, 1))` |
| `--text-lead` | `calc(1.375rem * var(--user-text-scale, 1))` |
| `--text-title-sm` | `calc(1.5rem * var(--user-text-scale, 1))` |
| `--text-title` | `calc(1.75rem * var(--user-text-scale, 1))` |
| `--text-title-lg` | `calc(2.25rem * var(--user-text-scale, 1))` |
| `--leading-body` | `1.55` |
| `--leading-title` | `1.25` — **patrz sekcja 3, jedyne miejsce sporne** |
| `--spacing-1` … `--spacing-20` (11 tokenów) | `0.25rem` / `0.5` / `0.75` / `1` / `1.25` / `1.5` / `2` / `2.5` / `3` / `4` / `5rem` |
| `--radius-sm` | `0.5rem` |
| `--radius-md` | `0.75rem` |
| `--radius-lg` | `1rem` |
| `--radius-xl` | `1.5rem` |
| `--radius-pill` | `999px` |
| `--shadow-card` | `0 1px 2px rgba(43,36,29,.06), 0 6px 16px rgba(43,36,29,.08)` |
| `--shadow-popover` | `0 8px 24px rgba(43,36,29,.16)` |
| `--control-height-min` | `3rem` |
| `--control-height-touch` | `3.75rem` |
| `--hit-area-min` | `2.75rem` |
| `--breakpoint-sm` / `-md` / `-lg` / `-xl` | `40rem` / `48rem` / `64rem` / `80rem` |
| `--container-content` | `45rem` |
| `--container-sidenav` | `15rem` |
| `--container-rail` | `22rem` |
| `--container-strona` | `calc(sidenav + spacing-8 + content + 2×spacing-6)` = 1040 px |
| `--container-strona-solo` | `calc(content + 2×spacing-6)` = 768 px |

### 1.2 Tryb ciemny — 28 tokenów bez zmiany wartości

`--color-surface` `#1E1A16` · `--color-surface-raised` `#2A241E` ·
`--color-surface-sunken` `#14110E` · `--color-border` `#382F27` ·
`--color-border-strong` `#8C7D68` · `--color-ink` `#F5EFE6` ·
`--color-ink-muted` `#C9BEB0` · `--color-ink-inverse` `#FFFFFF` ·
`--color-brand` `#F2986A` · `--color-brand-dark` `#A63F1F` ·
`--color-brand-tint` `#3A2418` · `--color-brand-tint-ink` `#F2986A` ·
`--color-brand-solid` `#C1502A` · `--color-brand-solid-hover` `#A63F1F` ·
`--color-accent` `#E3B341` · `--color-accent-tint` `#E3B341` ·
`--color-accent-tint-ink` `#3A2C0C` · `--color-danger` `#FF8A80` ·
`--color-danger-tint` `#5C1E17` · `--color-danger-tint-ink` `#FDECEA` ·
`--color-danger-solid` `#C43127` · `--color-danger-solid-hover` `#A3281F` ·
`--color-success` `#7BD79A` · `--color-success-tint` `#1E4A2C` ·
`--color-success-tint-ink` `#EAF9EE` · `--color-focus` `#6EA8FF` ·
`--shadow-card` · `--shadow-popover`.

**W tym `--color-brand-solid` `#C1502A`, którego kontrast z bielą wynosi
4.72:1 przy progu 4.5 — najciaśniejsza para w całej palecie.** Nie została
ruszona i nie wolno jej ruszyć bez ponownego przeliczenia obu par naraz
(`DESIGN_SYSTEM.md` §1.3, uwaga o marginesie 4.72).

---

## 2. Co jest nowe — dwanaście nazw

Każda z jednym zdaniem, po co jest. Żadna z nich nie zmienia niczego, dopóki
jakiś widok jej nie użyje: nowy token, którego nikt nie woła, jest niewidoczny.

| Nowy token | Wartość (jasny) | Wartość (ciemny) | Po co |
|---|---|---|---|
| `--color-surface-brand-wash` | `#F7E9E2` | `#2E211A` | Ciepłe tło sekcji marki (hero, pasek zachęty, cytat „Skąd ten przepis”, karta wpisu bez zdjęcia) — cieplejsze od `sunken`, więc oko nie myli go z polem formularza. |
| `--scrim-ink` | `#1A130E` | `#000000` | Kolor podkładu pod tekstem na zdjęciu; biel na nim daje 18.37:1 (jasny) i 21.00:1 (ciemny). |
| `--scrim-gradient` | gradient od `rgb(26 19 14 / .88)` do przezroczystości | to samo na czerni | Sam podkład: pełny u dołu, przezroczysty u góry, więc działa także wtedy, gdy pod spodem jest biały talerz. |
| `--text-meta` | `calc(0.9375rem * scale)` = 15 px | — | Jedyny rozmiar poniżej 16 px w systemie, wyłącznie dla plakietki cichej i wiersza metadanych (D-103, D-110). |
| `--text-title-xl` | `calc(3rem * scale)` = 48 px | — | Wyłącznie hero strony powitalnej — jedyny ekran, który ma kogoś przekonać. |
| `--leading-title-wiele` | `1.3` | — | Interlinia tytułu, KTÓRY SIĘ ZAWIJA (tytuł karty przy 320 px i skali 140 % ma trzy wiersze); D-101. |
| `--spacing-24` | `6rem` | — | Jedyne użycie: zapas, o który skraca się przyklejona szyna, żeby zmieściła się w oknie. |
| `--shadow-card-hover` | mocniejszy `--shadow-card` | mocniejszy | Najechanie na kartę podnosi cień zamiast przesuwać kartę — ruch pod kursorem dezorientuje. |
| `--container-czytanie` | `38rem` = 608 px | — | Sufit długiego dokumentu prawnego. **Ta liczba już jest w serwisie na sztywno** — `app.css:1383` `.prose { max-width: 38rem; }` — a wartość na sztywno łamie punkt 8 twardych ograniczeń z `CZYTAJ-MNIE.md`. Token ją nazywa, nie wymyśla. |
| `--container-strona-szeroka` | `calc(strona + spacing-8 + rail)` = 1424 px | — | Następca `--container-strona-z-szyna`; ta sama liczba, nowa nazwa, bo szyna jest teraz zawsze (D-102). |
| `--czas-szybki` | `150ms` | — | Jedna nazwa zamiast `150ms` wpisywanego przy każdym przejściu. |
| `--czas-zwykly` | `200ms` | — | To samo dla przejść wolniejszych (cień karty). |

Nowe tokeny mają wersję ciemną tam, gdzie jest potrzebna: cztery
(`--color-surface-brand-wash`, `--scrim-ink`, `--scrim-gradient`,
`--shadow-card-hover`). Reszta to rozmiary i czasy, które motyw nie dotyczy.

Wszystkie pary kontrastu z nowymi kolorami są policzone:
`node 01-fundamenty/kontrast.mjs` — **70 par, 0 nie przechodzi**
(`01-fundamenty/kontrast-wynik.md`). Poprzednia wersja liczyła 55 par;
15 nowych dotyczy `--color-surface-brand-wash` i `--scrim-ink`.

---

## 3. Co zmieniło wartość — **nic. Ale jedna liczba wymaga sprawdzenia w repozytorium**

W obu plikach nie ma ani jednego tokenu o tej samej nazwie i innej wartości.
Sprawdzone maszynowo: 66/66 w jasnym, 28/28 w ciemnym.

Zostaje natomiast jedna sprzeczność **w dokumentach właściciela**, nie
w tych plikach, i trzeba ją rozstrzygnąć zanim ktokolwiek podmieni plik:

| Źródło | Co mówi o `--leading-title` |
|---|---|
| `03-kod-wygladu/tokens.css`, wiersz 94 | `--leading-title: 1.25;` z komentarzem „Wcześniej stało tu 1.4” |
| `02-co-obowiazuje/STAN_WDROZENIA_KITU.md`, wiersz 27 | tabela: kit 1.25, **repozytorium 1.4** |
| `02-co-obowiazuje/STAN_WDROZENIA_KITU.md`, wiersz 123 | „`--leading-title` (1.25 w kicie, 1.4 u nas) **zostaje 1.4**” |

Arkusz i jego rozliczenie mówią co innego. Arkusz jest kopią pliku
z repozytorium, a jego komentarz opisuje zmianę z 1.4 na 1.25 jako już
dokonaną, więc **wygląda na to, że nowszy jest arkusz, a `STAN_WDROZENIA_KITU.md`
opisuje stan sprzed tej zmiany.** Nie mogę tego rozstrzygnąć bez repozytorium.

- Jeżeli repozytorium ma dziś **1.25** — podmiana nie zmienia nic.
- Jeżeli ma **1.4** — podmiana zmienia interlinię **każdego `h1`, `h2` i `h3`
  w całym serwisie**, bo `var(--leading-title)` jest wołany dokładnie raz, w
  regule `h1, h2, h3` w warstwie base (policzone: `grep -oE 'var\(--leading-title[,)]'`
  → **1 trafienie**, `tokens.css:301`). Jedno trafienie, każdy ekran.

**Do sprawdzenia w repozytorium jednym poleceniem:**
`grep -n 'leading-title' resources/css/tokens.css`.

---

## 4. Co zniknęło — trzy nazwy

| Zniknął | Użyć w `03-kod-wygladu/*.css` | Czym zastąpić |
|---|---|---|
| `--container-wide` (`70rem`) | **0** | Niczym. Był sufitem, którego układ nigdy nie dotykał — mówi to wprost komentarz w dzisiejszym `tokens.css`, wiersze 154–156. Usunięcie jest sprzątaniem, nie zmianą. |
| `--container-ultra` (`92rem`) | **0** | Jak wyżej. |
| `--container-strona-z-szyna` (1424 px) | **6** (wszystkie w `app.css`) | `--container-strona-szeroka` — ta sama liczba, wyliczana tym samym `calc()`. Sześć miejsc do podmiany nazwy. |

Polecenie, które znajduje te sześć miejsc:

```bash
grep -n -- 'var(--container-strona-z-szyna)' resources/css/app.css
```

Zamiana jest mechaniczna i nie zmienia ani jednego piksela:

```bash
sed -i 's/--container-strona-z-szyna/--container-strona-szeroka/g' resources/css/app.css
```

Przy okazji, żeby nie było niespodzianki: **osiem tokenów jest dziś
zdefiniowanych i nieużywanych** przez żaden arkusz —
`--breakpoint-sm`, `--breakpoint-md`, `--breakpoint-lg`, `--breakpoint-xl`
(używa ich generator Tailwinda, nie `var()`), `--color-brand-dark`
(występuje wyłącznie w komentarzach `app.css:459`, `1426`, `1429`),
`--spacing-16`, `--container-wide`, `--container-ultra`. Nowy plik zostawia
z nich sześć, bo dwa ostatnie znikają, a `--color-brand-dark` jest opisany
w `DESIGN_SYSTEM.md` jako nazwa roli i jego usunięcie byłoby zmianą słownika,
nie sprzątaniem.

---

## 5. Czego nowy plik **nie zmienia**, choć mógłby

Warte zapisania, bo to jest tarcza przed „szybkimi poprawkami” przy następnej
zmianie:

- **żadnej reguły `@media (prefers-color-scheme: …)`** — motyw bierze się
  wyłącznie z jawnego wyboru człowieka (D-019 właściciela). Nowy plik tego nie
  przywraca i pilnuje tego test `tests/Feature/WyborMotywuTest.php`;
- `color-scheme: light` na `:root`, nie `light dark` — z tego samego powodu;
- nazewnictwo `--spacing-*` (nie `--space-*`), bo tak wymaga Tailwind 4;
- wszystkie wartości kolorów, promieni, cieni i wysokości kontrolek.

---

## 6. **Uwaga, która jest ważniejsza niż cała reszta tego dokumentu**

Podmiana pliku nie jest wymianą samych tokenów. Nowy `tokens.css` **zabiera
i przynosi rzeczy w warstwach `@layer base` i `@layer components`**, a to
widać na ekranie natychmiast.

### 6.1 Zabiera: całą warstwę `@layer components`

Dzisiejszy `03-kod-wygladu/tokens.css` ma od wiersza 383 warstwę
`@layer components` z **25 selektorami**:

`.btn` · `.btn:disabled` · `.btn:focus-visible` · `.btn-primary` ·
`.btn-secondary` · `.btn-quiet` · `.btn-danger` (+ cztery reguły `:hover`) ·
`.danger-zone` · `.field` · `.field-input` · `.field-input::placeholder` ·
`.field-help` · `.field-error` · `.field.has-error` · `.card` ·
`.empty-state` · `.empty-state-title` · `.error-summary` ·
`.error-summary-title` · `.wizard-steps` · `.wizard-steps-current` ·
`.wizard-steps-track` · `.wizard-steps-dot`.

**Nowy `tokens.css` nie ma tej warstwy w ogóle.** Wszystkie 25 selektorów
mają odpowiednik w `02-komponenty/komponenty.css` — sprawdzone maszynowo,
zero braków — ale to znaczy, że **podmiana samego `tokens.css`, bez wpięcia
`komponenty.css`, zdejmuje z serwisu wszystkie przyciski, pola formularza,
karty, puste stany, podsumowanie błędów i kroki kreatora naraz.** To nie jest
regresja, którą się zauważa w code review; to jest biała strona.

Dlatego sekwencja w sekcji 7 ma dwa pliki, nie jeden, i dlatego są w niej
w tej kolejności.

### 6.2 Przynosi: pięć zmian w `@layer base`, które widać na każdym ekranie

| Zmiana | Co robi | Kogo dotyczy |
|---|---|---|
| **`data-text-scale="90"` i `="140"`** | Dzisiejszy arkusz zna wyłącznie `112`, `125` i `150` (wiersze 249–251). D-111 i `STAN_WDROZENIA_KITU.md` mówią, że baza pozwala na **90–140**. Jeśli oba są prawdziwe, to człowiek, który wybierze 90 % albo 140 %, dostaje dziś **100 %** — atrybut jest na `<html>`, a reguły do niego nie ma. | Ekran 14 i każdy, kto włączył większy tekst. **Do sprawdzenia w repozytorium**: jakie wartości przyjmuje `users.text_scale` i co wypisuje `layout.blade.php`. |
| **`h1, h2, h3 { margin: 0 0 var(--spacing-4) }`** | Dziś nagłówki biorą margines z resetu Tailwinda. Nowy plik ustawia go jawnie. | Każdy ekran. Kolizji w arkuszach serwisu jest niewiele: reguły dla nagłówków są dokładnie dwie (`app.css:1068` `.przepis-siatka h2`, `app.css:1384` `.prose h2`) i obie ustawiają `margin-top`, więc obie wygrywają specyficznością. |
| **`h3 { font-size: var(--text-body-lg) }`** | Dziś `h3` nie ma jawnego rozmiaru — ma tylko interlinię i wagę 800 z reguły `h1, h2, h3`. Nowy plik daje mu 20 px. | Każdy ekran z trzecim poziomem nagłówka; najbardziej ekran 07 i 15. |
| **`:target` i `.field:target`** | Link `#pole` z podsumowania błędów podświetla pole, do którego skoczył — **bez JavaScriptu**. Dziś `x-error-summary` musi to załatwiać skryptem (`COMPONENTS_BLADE.md` §3 pokazuje `x-init="$el.focus()"`). | Ekrany 03, 04, 12, 13 i każdy formularz. |
| **`.tylko-dla-czytnika`** | Nowa nazwa dla tekstu wyłącznie dla czytnika ekranu, z powrotem na ekran przy fokusie („Przejdź do treści”). Serwis ma dziś **dwie** klasy o tej roli: `.visually-hidden` (`app.css:1524`) i `.skip-link` (`app.css`, 2 wystąpienia), plus wbudowaną Tailwindową `.sr-only` używaną w `COMPONENTS_BLADE.md`. | Ekran każdy; do sprzątnięcia przy okazji, nie w tym samym kroku. |

Zmienia się też jeden szczegół w `@media (forced-colors: active)`: dziś reguła
łapie `.btn, .field input, .field select, .field textarea`, nowy plik łapie
`.btn, .field-input, .card`. To jest zamiana selektora elementowego na klasę —
**jeśli w widokach są pola bez klasy `.field-input`, w trybie wysokiego
kontrastu stracą obramowanie.** Sposób sprawdzenia jest w
`LISTA-KONTROLNA-A11Y.md`, sekcja „tryb wysokiego kontrastu”.

---

## 7. Dokładna sekwencja podmiany

Zakładam nazwy plików z paczki właściciela: `resources/css/tokens.css`
i `resources/css/app.css`. **Jeśli w repozytorium jest inaczej — sprawdzić
przed pierwszym krokiem**, bo cała reszta zależy od kolejności importów.

```bash
# 0. Punkt wyjścia. Bez tego nie ma czego cofać.
git switch -c tokeny-v3
node scripts/dostepnosc.mjs                 # zapis stanu PRZED, do porównania
cp storage/dostepnosc.json storage/dostepnosc-przed.json

# 1. Zamień nazwę tokenu, który zniknął. 6 miejsc, wszystkie w app.css.
grep -n -- 'var(--container-strona-z-szyna)' resources/css/app.css   # ma pokazać 6
sed -i 's/--container-strona-z-szyna/--container-strona-szeroka/g' resources/css/app.css
grep -c -- 'var(--container-strona-szeroka)' resources/css/app.css    # ma pokazać 6

# 2. Podmień plik tokenów.
cp 01-fundamenty/tokens.css resources/css/tokens.css

# 3. Wepnij warstwę komponentów — PO app.css, nie przed.
#    Kolejność ma znaczenie: obie warstwy siedzą w @layer components,
#    więc przy równej specyficzności wygrywa ta wczytana później.
cp 02-komponenty/komponenty.css resources/css/komponenty.css
#    a w resources/css/app.css (albo tam, gdzie stoi lista importów):
#       @import "./tokens.css";      <- pierwszy, ma @import "tailwindcss"
#       …dotychczasowa treść app.css…
#       @import "./komponenty.css";  <- ostatni

# 4. Zbuduj i zobacz, czy w ogóle się kompiluje.
npm run build
```

**Krok 3 jest miejscem, w którym można się zabić.** `komponenty.css` definiuje
**59 nazw klas, które serwis już ma** (policzone: `comm -12` na listach klas
z `app.css` + `tokens.css` przeciw `komponenty.css`) — między innymi `.btn`,
`.card`, `.field`, `.field-input`, `.badge`, `.avatar`, `.chip`, `.choice`,
`.empty-state`, `.error-summary`, `.wizard-steps`, `.app-body`, `.topbar`,
`.side-nav`, `.bottom-nav`, `.wordmark`. Dla każdej z nich nowa warstwa
nadpisuje **te właściwości, które ustawia**, a reszta zostaje z `app.css`.
Powstaje stan mieszany. Jeśli chcesz go uniknąć, wytnij z `app.css` te
59 selektorów — ale wtedy nie jest to już podmiana pliku tokenów, tylko
Etap 2 z `WDROZENIE.md`, i tak trzeba go wypuszczać.

> **Ta liczba rośnie i trzeba ją przeliczyć, a nie przepisać.** Przy pierwszym
> pomiarze było 50, po dopisaniu sekcji 16 do `komponenty.css` jest 59.
> Nagłówek samego `komponenty.css` niesie jeszcze zestaw z pierwszego pomiaru
> (139 / 50 / 89 / 186) — dziś jest **163 / 59 / 104 / 177**. Polecenie do
> powtórzenia stoi w `WDROZENIE.md` §9.

**Zalecenie: krok 3 wykonać osobnym wydaniem.** Kroki 0–2 i 4 same w sobie
są bezpieczne i już coś naprawiają (skala tekstu 90 % i 140 %).

---

## 8. Co sprawdzić po podmianie

Kolejność jest od najtańszego do najdroższego. Pierwsze cztery zajmują razem
mniej niż pięć minut.

| # | Co | Czym | Przejście | Oblanie |
|---|---|---|---|---|
| 1 | Czy arkusz się zbudował | `npm run build` | kod wyjścia 0 | jakikolwiek błąd `@theme` albo nieznana funkcja — najczęściej literówka w `calc()` |
| 2 | Czy nie zostało stare `--container-strona-z-szyna` | `grep -rn -- 'container-strona-z-szyna' resources/` | zero trafień | jakiekolwiek trafienie znaczy, że sześć szerokości strony liczy się z tokenu, którego nie ma → `max-width` staje się nieprawidłowy i cała strona rozjeżdża się na całą szerokość okna |
| 3 | Czy `--leading-title` nie zmienił się niepostrzeżenie | `git diff resources/css/tokens.css \| grep leading-title` | `1.25` po obu stronach albo świadoma zmiana `1.4 → 1.25` opisana w commicie | zmiana bez opisu — to dotyka każdego nagłówka w serwisie |
| 4 | Czy przyciski i pola w ogóle jeszcze istnieją | otwórz `/login` | przycisk ma tło marki, pole ma obwódkę i 48 px wysokości | goły `<button>` i gołe `<input>` znaczą, że `komponenty.css` nie został wpięty (sekcja 6.1) |
| 5 | Kontrasty | `node 01-fundamenty/kontrast.mjs` | `Sprawdzonych par: 70. Nie przechodzi: 0.`, kod wyjścia 0 | jakikolwiek wiersz „nie przechodzi” — nie wypuszczać |
| 6 | Skala tekstu | ustaw `data-text-scale="140"` na `<html>` w narzędziach przeglądarki na `/home` | tekst podstawowy rośnie z 18 px na 25.2 px | brak zmiany znaczy, że atrybut ma inną wartość, niż zna arkusz — patrz sekcja 6.2 |
| 7 | Skala tekstu, wersja prawdziwa | `/ustawienia/czytelnosc`, wybierz każdy krok po kolei | każdy krok zmienia rozmiar tekstu | krok, który nic nie robi, znaczy, że baza i arkusz mają inne listy wartości |
| 8 | Oba motywy | przełącznik w stopce, na `/home` i `/przepisy/…` | ciemny ma ciepłe, prawie czarne tło `#1E1A16`, nie czerń; pola formularza i pasek przewijania też są ciemne | jasne kontrolki przeglądarki w ciemnym motywie = wróciło `color-scheme: light dark` |
| 9 | Brak przewijania w poziomie | `node scripts/dostepnosc.mjs` | 0 naruszeń o wadze `serious`/`critical`, brak przewijania przy 320 px | porównaj ze `storage/dostepnosc-przed.json`: każde NOWE naruszenie blokuje wydanie |
| 10 | Wysoki kontrast | Windows, motyw kontrastowy, `/dodaj/zdjecie` | pola i przyciski mają obramowanie | pole bez obramowania = pole bez klasy `.field-input` (sekcja 6.2) |

### 8.1 Zanim cokolwiek trafi do repozytorium serwisu — trzy automaty w tej paczce

Te trzy uruchamia się **na paczce**, nie na serwisie, i przechodzą, zanim
którykolwiek plik ruszy dalej. Kod wyjścia niezerowy blokuje.

```bash
node 01-fundamenty/kontrast.mjs        # 70 par kontrastu
node 01-fundamenty/zbuduj-tokeny.mjs   # składa tokens.json z policzonymi kontrastami
node podglad/zbuduj-podglad.mjs        # składa arkusz dla makiet
node 07-wdrozenie/sprawdz-paczke.mjs   # twarde ograniczenia: skrypt, style=, wartości z ręki, słowa zakazane
node 07-wdrozenie/sprawdz-uklad.mjs    # przewijanie w poziomie i 48 px, w prawdziwej przeglądarce
```

**`zbuduj-tokeny.mjs` i `zbuduj-podglad.mjs` muszą iść przed dwoma
sprawdzającymi**, bo obydwa czytają pliki wynikowe: `sprawdz-paczke.mjs`
sprawdza `01-fundamenty/tokens.json`, a `sprawdz-uklad.mjs` otwiera makiety,
które wczytują `podglad/podglad.css`. Sprawdzanie stanu sprzed przebudowy
mierzy poprzednią wersję i wypada zielono na czymś, czego już nie ma.

**Cofnięcie:** `git revert` jednego commitu. Kroki 0–2 to jeden plik i jedna
zamiana `sed`, więc cofnięcie jest zupełne i natychmiastowe. Jeśli krok 3
poszedł tym samym commitem — nie jest, bo `komponenty.css` zmienia wygląd
50 klas naraz i po cofnięciu trzeba jeszcze raz obejrzeć ekrany. Stąd
zalecenie z sekcji 7.
