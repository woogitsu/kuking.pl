# System designu Kuking.pl

> Aktualna integracja palety: [NOWY_STYL.md](NOWY_STYL.md). Tabele kolorów,
> obliczenia kontrastu i opis wyboru fontu poniżej są historyczne. Bieżące
> wartości czyta `scripts/kontrast-marki.mjs` z `resources/css/tokens.css`;
> aplikacja używa lokalnego Inter z systemowym stosem zastępczym.
> Kierunek marki: [KONSTYTUCJA_MARKI.md](../brand/KONSTYTUCJA_MARKI.md).
> Nadrzędne pozostają AGENTS.md i jawne decyzje właściciela; D-206–D-211
> rozstrzygają port kompozycji. Makieta nie jest specyfikacją funkcji backendu.
> Historyczne liczby kontrastu w §1 i uzasadnienie dawnego fontu w §2.2
> nie są wynikiem pomiaru obecnej aplikacji.

Wersja robocza — Laravel 13 + Blade + Livewire 4 + Alpine.js + Tailwind CSS 4 (CSS-first, `@theme`).
Zgodność z `docs/UX_50_PLUS.md`, `docs/BRAND.md`, `docs/PRODUCT.md`, `docs/FLOWS_AND_SCREENS.md` i prototypem w `prototype/`.

Zasada nadrzędna: Kuking **nie jest oznaczony jako „dla seniorów"**. To zwykły, dobrze zaprojektowany serwis, który przypadkiem jest wyjątkowo czytelny — bo dobra czytelność jest dobra dla każdego, nie tylko dla grupy 50+.

Wszystkie kontrasty w tym dokumencie zostały **policzone**, nie oszacowane. Skrypt: `agents/ux/contrast.py` (formuła luminancji WCAG 2.x, `(L1+0.05)/(L2+0.05)`). Dane wejściowe: `pairs_light.json`, `pairs_dark.json`, `pairs_extra.json` w tym samym katalogu — można je uruchomić ponownie po każdej zmianie koloru:

```bash
python3 agents/ux/contrast.py agents/ux/pairs_light.json
python3 agents/ux/contrast.py agents/ux/pairs_dark.json
python3 agents/ux/contrast.py agents/ux/pairs_extra.json
```

Historyczny wynik dawnej palety: **wszystkie 55 sprawdzonych par przechodzi próg WCAG 2.2 AA** (4.5:1 dla tekstu, 3:1 dla dużego tekstu i elementów UI). `pairs_light.json` celowo nie zawiera pary „pierścień fokusu na tle przycisku kolorowego” — to nie jest przeoczenie, tylko świadoma decyzja: taka para nigdy nie występuje w renderowanym UI dzięki technice „halo” opisanej w 1.4 (surowe liczby dla niej podane są tam osobno, do wglądu).

---

## 1. Paleta kolorów

Charakter: ciepła kuchnia, drewniany stół, pomidorowa zupa, poranne światło. Nie „senior beige" (szarawy, bezpłciowy), nie infantylne pastele. Terakota jako kolor marki nawiązuje do pomidorów/przypraw, nie do „aplikacji korpo".

### 1.1 Tryb jasny (domyślny)

| Token | Hex | Rola |
|---|---|---|
| `--color-surface` | `#FAF6F0` | Tło strony (ciepła kość słoniowa, nie szpitalna biel) |
| `--color-surface-raised` | `#FFFFFF` | Tło kart, modali, top bara |
| `--color-surface-sunken` | `#F1EBE1` | Tło pól formularza, wgłębione obszary |
| `--color-border` | `#E4DACB` | Cienkie linie podziału (dekoracyjne, nie niosą informacji) |
| `--color-border-strong` | `#8A7A63` | Obramowania pól, przycisków `secondary`, elementów wymagających 3:1 |
| `--color-ink` | `#2B241D` | Tekst podstawowy |
| `--color-ink-muted` | `#5C5347` | Tekst drugorzędny (metadane, pomoc, znaczniki czasu) |
| `--color-ink-inverse` | `#FFFFFF` | Tekst na kolorowych/ciemnych tłach (przyciski) |
| `--color-brand` | `#B3401F` | Marka / linki / akcje wtórne z akcentem koloru |
| `--color-brand-dark` | `#8C3018` | Hover/active przycisku primary |
| `--color-accent` | `#7A5C10` | Tekst akcentu (np. etykiety „Ugotowałem”, gwiazdki) |
| `--color-accent-tint` | `#FCEACB` | Tło plakietki accent |
| `--color-accent-tint-ink` | `#6B4E0C` | Tekst na plakietce accent |
| `--color-danger` | `#B3261E` | Tekst błędu, obramowania błędnych pól |
| `--color-danger-tint` | `#FBE2E0` | Tło alertu błędu / podsumowania błędów |
| `--color-danger-tint-ink` | `#8C1E17` | Tekst na tle alertu błędu |
| `--color-success` | `#1E7B3E` | Tekst potwierdzeń |
| `--color-success-tint` | `#DFF3E4` | Tło alertu sukcesu, `AutosaveBadge` |
| `--color-success-tint-ink` | `#155C2E` | Tekst na tle alertu sukcesu |
| `--color-focus` | `#155EEF` | Pierścień fokusu klawiatury (niebieski — celowo spoza reszty palety, żeby zawsze się wyróżniał) |

### 1.2 Tryb ciemny

Nie jest to odwrócona jasność — to osobna, przemyślana paleta o tej samej strukturze ról.

**Aktywacja — wyłącznie jawna (docs/DECISIONS.md, D-019).** Jasny jest
motywem domyślnym dla każdego, zalogowanego i gościa — arkusz stylów NIE
ogląda się na `prefers-color-scheme` systemu. Ciemny włącza wyłącznie atrybut
`data-theme="dark"` na `<html>`, ustawiany po jawnym wyborze na
`/ustawienia/czytelnosc` (zalogowany, zapisane na koncie w `users.theme`)
albo przez szybki przełącznik w stopce (też dla gościa, zapamiętany
w ciasteczku — `App\Http\Controllers\ThemeController`). Wcześniej istniało
tu drugie, systemowe wejście przez `@media (prefers-color-scheme: dark)`;
zostało usunięte, bo włączało ciemny motyw samo, gdy urządzenie
odwiedzającego miało własny harmonogram „tryb nocny" — czego nikt nie
zamawiał.

| Token | Hex | Rola |
|---|---|---|
| `--color-surface` | `#1E1A16` | Tło strony (ciepła, prawie czarna czekolada — nie czysta czerń) |
| `--color-surface-raised` | `#2A241E` | Tło kart, modali, top bara |
| `--color-surface-sunken` | `#14110E` | Tło pól formularza |
| `--color-border` | `#382F27` | Linie podziału dekoracyjne |
| `--color-border-strong` | `#8C7D68` | Obramowania pól / przycisków secondary |
| `--color-ink` | `#F5EFE6` | Tekst podstawowy |
| `--color-ink-muted` | `#C9BEB0` | Tekst drugorzędny |
| `--color-ink-inverse` | `#FFFFFF` | Tekst na przyciskach solid |
| `--color-brand` | `#F2986A` | Marka / linki (jaśniejsza terakota, żeby żyła na ciemnym tle) |
| `--color-brand-solid` | `#C1502A` | Tło przycisku primary |
| `--color-brand-solid-hover` | `#A63F1F` | Hover/active przycisku primary |
| `--color-accent` | `#E3B341` | Tekst i tło plakietki accent (ciemny tekst na tym tle, patrz niżej) |
| `--color-accent-ink` | `#3A2C0C` | Tekst na plakietce accent (ciemny, bo tło accent jest jasne nawet w dark mode) |
| `--color-danger` | `#FF8A80` | Tekst błędu |
| `--color-danger-solid` | `#C0392B` | Tło przycisku danger |
| `--color-danger-tint` | `#5C1E17` | Tło alertu błędu |
| `--color-danger-tint-ink` | `#FDECEA` | Tekst na tle alertu błędu |
| `--color-success` | `#7BD79A` | Tekst potwierdzeń |
| `--color-success-solid` | `#2E7D46` | Tło przycisku/plakietki success |
| `--color-success-tint` | `#1E4A2C` | Tło alertu sukcesu |
| `--color-success-tint-ink` | `#EAF9EE` | Tekst na tle alertu sukcesu |
| `--color-focus` | `#6EA8FF` | Pierścień fokusu (jaśniejszy niebieski dla ciemnego tła) |

### 1.3 Policzone kontrasty (wyciąg — pełny log w plikach `pairs_*.json` + wynik skryptu)

**Tryb jasny:**

| Para | Kontrast | Wymóg | Wynik |
|---|---|---|---|
| ink / surface | 14.21:1 | 4.5:1 | ✅ |
| ink / surface-raised | 15.30:1 | 4.5:1 | ✅ |
| ink-muted / surface | 7.01:1 | 4.5:1 | ✅ |
| ink-muted / surface-raised | 7.54:1 | 4.5:1 | ✅ |
| brand (tekst/link) / surface | 5.31:1 | 4.5:1 | ✅ |
| brand / surface-raised | 5.72:1 | 4.5:1 | ✅ |
| biały / brand (btn primary) | 5.72:1 | 4.5:1 | ✅ |
| biały / brand-dark (hover) | 8.23:1 | 4.5:1 | ✅ |
| danger / surface | 6.07:1 | 4.5:1 | ✅ |
| biały / danger (btn danger) | 6.54:1 | 4.5:1 | ✅ |
| success / surface | 4.93:1 | 4.5:1 | ✅ |
| biały / success | 5.31:1 | 4.5:1 | ✅ |
| accent / surface | 5.80:1 | 4.5:1 | ✅ |
| accent-tint-ink / accent-tint (plakietka) | 6.53:1 | 4.5:1 | ✅ |
| danger-tint-ink / danger-tint (alert) | 7.37:1 | 4.5:1 | ✅ |
| success-tint-ink / success-tint (alert) | 6.95:1 | 4.5:1 | ✅ |
| border-strong / surface (obwódka pola, UI) | 3.87:1 | 3:1 | ✅ |
| border-strong / surface-sunken (pole formularza) | 3.51:1 | 3:1 | ✅ |
| focus / surface (pierścień na tle strony) | 5.03:1 | 3:1 | ✅ |
| focus / surface-raised (pierścień na karcie) | 5.41:1 | 3:1 | ✅ |

**Tryb ciemny:**

| Para | Kontrast | Wymóg | Wynik |
|---|---|---|---|
| ink / surface | 15.13:1 | 4.5:1 | ✅ |
| ink-muted / surface | 9.45:1 | 4.5:1 | ✅ |
| brand / surface | 7.80:1 | 4.5:1 | ✅ |
| biały / brand-solid (btn primary) | 4.72:1 | 4.5:1 | ✅ (margines mniejszy — patrz uwaga niżej) |
| biały / brand-solid-hover | 6.26:1 | 4.5:1 | ✅ |
| brand-solid / surface (obrys przycisku, UI) | 3.66:1 | 3:1 | ✅ |
| danger / surface | 7.57:1 | 4.5:1 | ✅ |
| biały / danger-solid | 5.44:1 | 4.5:1 | ✅ |
| danger-solid / surface (UI) | 3.18:1 | 3:1 | ✅ |
| success / surface | 9.90:1 | 4.5:1 | ✅ |
| biały / success-solid | 5.07:1 | 4.5:1 | ✅ |
| success-solid / surface (UI) | 3.41:1 | 3:1 | ✅ |
| accent / surface | 8.88:1 | 4.5:1 | ✅ |
| accent-ink / accent (plakietka) | 6.98:1 | 4.5:1 | ✅ |
| border-strong / surface (UI) | 4.32:1 | 3:1 | ✅ |
| focus / surface (UI) | 7.17:1 | 3:1 | ✅ |
| focus / surface-raised (UI) | 6.36:1 | 3:1 | ✅ |

> **Uwaga o marginesie 4.72:1** (biały tekst na `--color-brand-solid` w dark mode): to najciaśniejsza para w całej palecie. Zostawiono celowo blisko dolnej granicy, bo dalsze przyciemnianie `brand-solid` zbliżyłoby go do tła strony i zepsuło kontrast UI 3:1 z otoczeniem. Margines 0.22 jest bezpieczny (próg to dokładnie 4.5), ale **nie zmieniać tego koloru bez ponownego przeliczenia obu par jednocześnie**.

### 1.4 Pierścień fokusu na kolorowych przyciskach — technika „halo”

Bezpośredni kontrast niebieskiego `--color-focus` na czerwonym/terakotowym tle przycisku wychodzi ok. **1.0–1.2:1 — nie przechodzi**. To nie jest błąd doboru koloru, to fizyka: niebieski i czerwono-pomarańczowy mają zbliżoną jasność. Rozwiązanie (identyczne jak w GOV.UK Design System i US Web Design System): między przyciskiem a pierścieniem fokusu wstawiamy 2 px „halo” w kolorze tła strony/karty, więc **pierścień fokusu nigdy nie styka się bezpośrednio z kolorem przycisku** — styka się z tłem, wobec którego jego kontrast jest już policzony i przechodzi (5.03–7.17:1, patrz tabela wyżej).

```css
.btn:focus-visible {
  outline: none;
  box-shadow:
    0 0 0 2px var(--color-surface),   /* halo — odsuwa pierścień od koloru przycisku */
    0 0 0 5px var(--color-focus);     /* właściwy pierścień, 3px grubości */
}
```

Zaimplementowane w `tokens.css` w klasie `.btn:focus-visible` oraz ogólnie dla wszystkich interaktywnych elementów przez `:focus-visible` w `@layer base`.

---

## 2. Typografia

### 2.1 Skala

| Token | Rozmiar | Zastosowanie |
|---|---|---|
| `--text-help` | 16px | Pomoc kontekstowa, znaczniki czasu — **tylko** tam, gdzie tekst główny obok jest ≥18px |
| `--text-body` | **18px** | Domyślny rozmiar body — nigdy mniej |
| `--text-body-lg` | 20px | Treść wpisu, opis przepisu, ważne fragmenty |
| `--text-lead` | 22px | Lead / zajawka, pierwsze zdanie przepisu |
| `--text-title-sm` | 24px | Tytuły sekcji |
| `--text-title` | 28px | Tytuł **przepisu** (mobile) — zwykły wpis nie ma pola tytułu i nie używa tego tokenu w tej roli: wpis w tym serwisie to „zdjęcie + kilka słów” (decyzja właściciela, `docs/DECISIONS.md` D-030) |
| `--text-title-lg` | 36px | Tytuł strony głównej, hero (desktop) — skaluje się płynnie `clamp(28px, 4vw, 36px)` |

Tekst ciągły zachowuje rytm około 1,55–1,65. Krótkie nagłówki kompozycji
mają ciaśniejszą interlinię właściwą komponentowi; np. kafel publikacji
używa 28 px i 1,2 przy skali domyślnej. Nie wymuszamy 1,4 na wszystkich
tytułach. Dokładne reguły portu stoją w `resources/css/marka-rama.css`.

Długość wiersza: kontener treści ograniczony do `max-width: 42rem–48rem` (≈ 65–75 znaków przy 18–20px) — patrz `--container-content` w tokenach.

### 2.2 Font

**Aktualnie: lokalny Inter z systemowym stosem zastępczym.** Pliki `latin`
i `latin-ext` oraz `font-display: swap` opisuje `resources/css/fonts.css`.
Wszystkie rodziny tekstu używają jednej rodziny bezszeryfowej. Wyjątki
techniczne dla poczty, eksportu i samodzielnych awarii opisuje konstytucja.

Poniższy stos i jego uzasadnienie dokumentują **dawny wybór bez webfontu**,
nie instrukcję usunięcia Inter:

```css
--font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans", "Liberation Sans", Arial, sans-serif;
```

Uzasadnienie:
- **Pełne wsparcie polskich znaków** (ą, ć, ę, ł, ń, ó, ś, ź, ż) we wszystkich wymienionych fontach.
- **Zero opóźnienia sieciowego / FOUT/FOIT** — istotne dla użytkowników na starszych telefonach i wolniejszym internecie (persony Basia 61, Marek 54 korzystają głównie z telefonu).
- Font systemowy **respektuje ustawienia dostępności OS** (np. Android „powiększona czcionka systemowa”, ustawienia czcionki zastępczej w niektórych wersjach Androida/iOS) w sposób, w jaki webfont czasem nie respektuje.
- Brak dodatkowego kosztu wydajności = brak dodatkowego CLS (przesunięcia layoutu przy doładowaniu fontu).

**Rozważona alternatywa (do decyzji właściciela produktu):** `Atkinson Hyperlegible` (Braille Institute) — font zaprojektowany specjalnie pod niską ostrość wzroku, wyraźnie odróżnia znaki podobne (l/I/1, O/0). Nie wdrożony w MVP, bo wymaga webfontu (koszt sieciowy + zależność od Google Fonts). **Do testu A/B po becie**, jeśli badania z użytkownikami 60+ wskażą problem z czytelnością liter podobnych.

### 2.3 Skala tekstu użytkownika (`text_scale`: 70/80/90/100/112/125/140%)

Mechanizm: atrybut na `<html>`, ustawiany w `/ustawienia/czytelnosc` i zapisywany po stronie użytkownika (cookie/DB), niezależny od zoomu przeglądarki.

```html
<html lang="pl" data-text-scale="125">
```

```css
:root { --user-text-scale: 1; }
:root[data-text-scale="112"] { --user-text-scale: 1.12; }
:root[data-text-scale="125"] { --user-text-scale: 1.25; }
:root[data-text-scale="140"] { --user-text-scale: 1.4; }
```

Pełna lista ustawień stoi w `config/kuking.php` (`text.scales`); przykład
powyżej pokazuje powiększenia. Własny wybór mniejszego tekstu nie obniża
standardu domyślnego 18 px. Tokeny tekstowe mnożymy przez
`--user-text-scale`; odstępy i promienie nie są mnożone przez to ustawienie.
Kontrolka ma `min-height` i padding, aby mogła urosnąć po zawinięciu tekstu.
Skala aplikacji 140% nie zastępuje osobnego odbioru przy czcionce
przeglądarki 200% oraz szerokości 320 px. Sam mechanizm skalowania nie
dowodzi, że układ pozostaje używalny — trzeba sprawdzić render.

Element zawierający tekst nie może mieć sztywnej wysokości obcinającej
zawinięte wiersze. Używamy `min-height` i paddingu; również szerokość musi
pozwalać zmieścić powiększony tekst bez utraty treści lub funkcji.

---

## 3. Spacing, radius, cień, focus

### 3.1 Spacing (baza 4px, krok rosnący)

`--space-1: 4px`, `--space-2: 8px`, `--space-3: 12px`, `--space-4: 16px`, `--space-5: 20px`, `--space-6: 24px`, `--space-8: 32px`, `--space-10: 40px`, `--space-12: 48px`, `--space-16: 64px`, `--space-20: 80px`.

Reguła: min. odstęp między akcją zwykłą a destrukcyjną = `--space-8` (32px) LUB osobny wiersz/sekcja z wizualnym oddzieleniem (linia + nagłówek „Strefa zagrożenia”) — nigdy przycisk „Usuń” tuż obok „Zapisz”.

### 3.2 Radius

`--radius-sm: 8px` (plakietki, chipy), `--radius-md: 12px` (przyciski, pola), `--radius-lg: 16px` (karty), `--radius-pill: 999px` (avatar, tag okrągły).

To bazowe tokeny, nie nakaz jednakowego promienia wszystkich komponentów.
Port marki używa około 14 px dla przycisków oraz 24–26 px dla głównych
powierzchni przy domyślnej skali. Obowiązujące role opisuje konstytucja;
wartości rodzin komponentów stoją także w `resources/css/marka-rama.css`.

### 3.3 Cień

Ciepły, nie czarny — cień barwiony w stronę `ink`, żeby pasował do palety:

```css
--shadow-card: 0 1px 2px rgba(43,36,29,.06), 0 6px 16px rgba(43,36,29,.08);
--shadow-popover: 0 8px 24px rgba(43,36,29,.16);
```

W dark mode cienie są niemal niewidoczne na ciemnym tle — separacja kart odbywa się głównie przez `--color-border` (1px) + delikatną różnicę jasności `surface` / `surface-raised`, cień jest tylko dodatkiem:

```css
--shadow-card-dark: 0 1px 2px rgba(0,0,0,.3), 0 6px 16px rgba(0,0,0,.35);
```

### 3.5 Warstwy powierzchni — sześć ról, nie jeden biały prostokąt

Do 11 września 2026 klasa `.card` niosła **126 różnych ról naraz**: była
jednocześnie kartą wpisu, sekcją strony, blokiem prawej szyny, panelem
formularza, ramką z wyjaśnieniem i kaflem, w który się klika. Skutek dawał się
zobaczyć na `/napisz-do-nas`: wyjaśnienie, formularz i „Co się stanie dalej"
miały ten sam kolor, cień i promień, choć tylko jedna z tych trzech rzeczy
czegokolwiek wymagała.

| # | Rola | Klasa | Tło | Obwódka | Promień | Cień |
|---|---|---|---|---|---|---|
| 1 | Karta treści | `.card` | podniesione | cienka | `--radius-xl` | tak |
| 2 | Panel formularza | `.panel-formularza` | podniesione | **mocna** | `--radius-xl` | tak |
| 3 | Sekcja strony | `.sekcja-strony` | podniesione | cienka | `--radius-xl` | **nie** |
| 4 | Blok prawej szyny | `.card .szyna-blok` | podniesione | cienka | `--radius-xl` | **nie** |
| 5 | Ramka pomocnicza | `.ramka-pomocnicza` | **wgłębione** | cienka | `--radius-lg` | **nie** |
| 6 | Kafel akcji | `.kafel-akcji` | podniesione | **mocna** | `--radius-xl` | tak |

Wartości stoją w tokenach `--warstwa-*` (`resources/css/tokens.css`, sekcja 2.1),
nie w klasach — inaczej tryb ciemny i `.blok-ciemny` wymagałyby sześciu
osobnych nadpisań każdej klasy.

**Warstwy 3 i 4 są celowo identyczne wizualnie**, a warstwy 1, 3 i 4 różni
wyłącznie cień — to jest wcześniejsze rozstrzygnięcie o szynie, nie
przeoczenie. Największą odległość mają te warstwy, które naprawdę stają obok
siebie na jednym ekranie: panel formularza i ramka pomocnicza rozchodzą się na
wszystkich pięciu osiach. Pełna macierz różnic: [`ROLE_KART.md`](ROLE_KART.md).

Żadna z tych różnic nie niesie informacji potrzebnej do obsługi ekranu — co
jest formularzem, mówi nagłówek i etykieta pola — więc §8 punkt 9 („kolor nigdy
jedynym nośnikiem informacji") jest spełniony niezależnie od tego, ile osi
dzieli daną parę. W motywie ciemnym, gdzie cienie są prawie niewidoczne,
różnicę niesie jasność powierzchni.

Warstwa 1 zmieniła promień z `--radius-lg` na `--radius-xl`: karta wpisu i blok
szyny miały go od dawna jako nadpisania, a zwykła `.card` obok nich 16 px —
trzy różne promienie w jednej kolumnie czytały się jako niedokończone.

Pełny inwentarz (co dostało którą warstwę i dlaczego) oraz pomiar „przed i po":
[`ROLE_KART.md`](ROLE_KART.md). Miarę hierarchii liczy `scripts/warstwy-pomiar.mjs`.

### 3.4 Focus ring

- Widoczny **zawsze** przy nawigacji klawiaturą (`:focus-visible`, nigdy `:focus` gołe — nie chcemy pierścienia przy kliknięciu myszą, ale MUSI się pojawić przy Tab).
- Grubość 3px, offset 2px (lub technika halo dla przycisków kolorowych — patrz 1.4).
- Kontrast pierścienia ≥ 3:1 względem tła po obu stronach — **policzone** w sekcji 1.3.
- Nigdy nie usuwać `outline` bez zamiennika (żadne `outline: none;` bez `box-shadow`/`outline` zastępczego).

---

## 4. Kontrolki

### 4.1 Przyciski

| Wariant | Wygląd | Użycie |
|---|---|---|
| `primary` | Wypełniony `--color-brand` / biały tekst | Jedna główna akcja na widoku: „Opublikuj”, „Ugotowałem”, „Zapisz” |
| `secondary` | Obrys `--color-border-strong`, tło `surface-raised`, tekst `ink` | Akcje równorzędne: „Zapisz szkic”, „Anuluj” |
| `quiet` | Bez obrysu i tła, tekst `ink` lub `brand`, podkreślenie przy fokusie/hover | Akcje trzeciorzędne w tekście, linki-przyciski |
| `danger` | Wypełniony `--color-danger` / biały tekst | Wyłącznie destrukcja: „Usuń konto”, „Usuń wpis” — zawsze za potwierdzeniem (`ConfirmDialog`) |

Wspólne reguły:
- min. wysokość **48px** (`min-height`, nie `height`), padding poziomy min. 16px.
- Ważna akcja ma widoczny tekst. Jawne wyjątki z AGENTS.md dotyczą menu
  trzech kropek na karcie wpisu oraz przełącznika motywu w stopce (D-051).
  Nie rozszerzamy ich na inne kontrolki; nazwa dostępna pozostaje wymagana.
- stan `disabled`: obniżona opacity + **zawsze towarzyszący komunikat** dlaczego (np. pod przyciskiem: „Dodaj zdjęcie, żeby opublikować” — nigdy cichy, niewyjaśniony `disabled`).
- odstęp między `primary`/`secondary` a `danger` ≥ `--space-8`.

### 4.2 Pola formularza

- min. rozmiar czcionki **18px**, min. wysokość 48px, padding 12px.
- **Etykieta zawsze widoczna nad polem** (`<label>` prawdziwy, powiązany `for`/`id`) — **placeholder nigdy nie zastępuje etykiety**. Placeholder wolno używać wyłącznie jako dodatkowy przykład treści (np. „Np. Pierwszy raz robiłam...”), nigdy jako jedyny opis pola.
- Tekst pomocniczy pod polem (`--text-help`, `ink-muted`) — stały, nie znika po fokusie.
- Stan błędu: czerwona ramka (`border-strong` zamieniona na `--color-danger`) + komunikat pod polem w kolorze `danger` + ikona ostrzeżenia z `aria-hidden` (bo komunikat tekstowy już niesie informację) + `aria-describedby` wskazujący na komunikat + `aria-invalid="true"`.
- Focus: pierścień jak w 3.4, dodatkowo tło pola pozostaje `surface-sunken` (nie zmienia się gwałtownie), żeby nie dezorientować.

### 4.3 Checkbox / radio

- min. 24×24px hit area wizualna, **min. 44×44px obszar klikalny** (padding wokół, cały wiersz z etykietą klikalny — `<label>` obejmuje kontrolkę i tekst).
- Zawsze widoczna etykieta tekstowa obok, nigdy sam kwadracik.
- Stan zaznaczony wyraźny (wypełnienie `brand`, nie tylko subtelna zmiana odcienia szarości).

### 4.4 Linki

- Zawsze wyróżnione czymś więcej niż samym kolorem (podkreślenie w treści biegnącej — WCAG 1.4.1: kolor nie może być jedynym sygnałem). W nawigacji/menu, gdzie kontekst jasno wskazuje że to link, podkreślenie może się pojawiać na hover/focus.
- Kolor `--color-brand`, hover: pogrubienie podkreślenia, nie tylko zmiana odcienia.

---

## 5. Inwentarz komponentów

Dla każdego: cel, stany, warianty, zasady dostępności. Nazwy opisują role;
nie każda jest osobnym komponentem Blade. `COMPONENTS_BLADE.md` i prototypy
są materiałem projektowym, nie dowodem bieżącego zachowania. Zmiana funkcji
wymaga sprawdzenia aktualnego widoku, akcji domenowej i decyzji produktu.

### `AppShell`
Rama według D-206 i D-207: osobny, pływający nagłówek oraz treść z opcjonalną
prawą szyną. Zwykły użytkownik nie ma lewego `SideNav`. Dla szerokiego Startu
punktem odniesienia jest 1120 px: 750 px treści, 40 px odstępu i 330 px szyny.
Nie jest to nakaz szerokości każdego formularza. Na telefonie treść przechodzi
do jednej kolumny; pięć pozycji dolnej nawigacji zastępuje menu desktop.
Odstępy uwzględniają rzeczywistą wysokość pasków i safe area. Przyklejenie
paska ustępuje dostępowi do powiększonej treści i widocznego fokusu.

### `TopBar`
Garnek i logotyp „KuKing.pl” w odrębnej, zaokrąglonej powierzchni odsuniętej
od krawędzi okna. Na desktopie Start / Odkrywaj / Mój zeszyt, osobno Szukaj,
Powiadomienia i dostęp do konta. Stan gościa zachowuje wejścia do logowania
i rejestracji. Nie zastępujemy garnka literą K ani nie usuwamy podpisu
Powiadomienia dla zgodności z makietą. Wysokość rośnie wraz z zawartością.

### `BottomNav`
Dokładnie 5 pozycji: **Start | Szukaj | Dodaj | Moje | Profil**, z widocznymi
podpisami. Pływająca, zaokrąglona powierzchnia; `Dodaj` wyróżnia ciemny plus.
Aktywny stan ma `aria-current="page"` i wyróżnienie niezależne od koloru.
Obszar nawigacji ma nazwę dostępną, ważne cele minimum 48 px. Przy dużym
tekście nie utrzymujemy przyklejenia kosztem zasłaniania treści.

### Nawigacja panelu moderacji
Panel zachowuje własną nawigację roboczą i kontrolę uprawnień (D-206).
Nie przenosimy jej do zwykłej ramy użytkownika.

### Kompozycje Startu i publicznego powitania

Start (D-207): krótkie „Dzień dobry” z nazwą z profilu, bez zgadywania
odmiany; pytanie „Co dziś gotujesz?” oraz przyciski Dodaj zdjęcie / Dodaj
przepis stoją w ciemnym kaflu z pierścieniem. Prawą szynę zaczyna ciemny
wstęp, po nim osobne powierzchnie rzeczywistych osób i dań.

Publiczne powitanie (D-208): otwarte kroki 01–03 z dużym tytułem „Zdjęcie.
Kilka słów. I rozmowa przy okazji.”, bezpośrednio potem ciemny blok
„Ugotowałem / Twój przepis. Czyjś dobry obiad.”, następnie tablica i wpisy.
Fotografia pochodzi z publicznego kolażu i ma podpis autora; bez dostępnej
fotografii blok jest tekstowy. Na telefonie kroki układają się pionowo.
Nie odtwarzamy fikcyjnych osób, liczników ani symulowanych operacji z HTML.

### `PostCard`
Awatar, nazwa i czas → kilka słów → duże zdjęcie → rzeczywiste akcje wpisu.
Kadrowanie respektuje wybrany tryb zdjęcia; nie wymuszamy 4:3 na każdej
fotografii. Karta jest jasna w jasnym motywie, ma miękki cień i zaokrąglenia
według konstytucji. Menu otwierają same trzy kropki z nazwą dostępną
„Więcej przy tym wpisie” oraz celem 48 × 48 px (wyjątek AGENTS.md).
Pozycje menu i pozostałe ważne akcje zachowują widoczne opisy oraz kontrolę
uprawnień. „Ugotowałem” odnosi się do wykonania przepisu, nie polubienia wpisu.

### `RecipeCard`
Karta listy (`resources/views/components/recipe-card.blade.php`) zawiera
miniaturę, tytuł, pochodzenie i liczbę wykonań, jeżeli istnieją. Nie jest
pełnym widokiem przepisu. Akcja „Ugotowałem” i szczegóły przygotowania
należą do widoku przepisu; nie wymagamy dodania wszystkich metadanych ani
tej akcji do każdej miniatury na podstawie dawnego prototypu.

### `CookedCard`
Wpis „Ugotowałem” w obrębie widoku przepisu: zdjęcie wykonania + komentarz + faktyczny czas + odznaka „zrobię ponownie: tak/nie”. Wizualnie odróżniony od oryginalnego przepisu (subtelna ramka `accent`), ale nie osobny, oderwany komponent — żyje pod przepisem w sekcji „Jak wyszło innym?”.

### `ProfileHeader` (nagłówek `/@nazwa`)

Awatar **128 px** (na profilu to zdjęcie osoby, o której jest cała strona, nie znaczek przy treści) → imię → `@nazwa` · region → „Zna się na" → opis → **liczniki** → wiersz akcji.

Liczniki: pięć wierszy „liczba + odmieniony podpis" („2 wpisy", „1 przepis", „5 obserwujących") w JEDNEJ kolumnie, każdy wiersz min. 48 px — dwa z nich (obserwujący, obserwowani) są odnośnikami do listy osób i mają podkreślony podpis, bo kolor nie może być jedynym sygnałem (WCAG 1.4.1). Odmianę liczy `App\Support\Odmiana`; formy stoją w jednym miejscu, w składniku `x-licznik-profilu`. **Nie dwie kolumny:** kolumna treści tej karty ma zmierzone 229–373 px (przy 1280 px zabiera miejsce prawa szyna), więc druga kolumna łamie wyrazy w środku — a `@media (min-width: …)` mierzy okno, nie tę kartę.

Nagłówek profilu jest grafitową powierzchnią z rzeczywistym awatarem,
nazwą i opisem. Własny profil udostępnia zmianę zdjęcia i profilu, dodanie
zdjęcia oraz wylogowanie; cudzy — czynności obserwowania i ochrony zgodne
z uprawnieniami. Nie usuwamy tych funkcji, aby odtworzyć statyczną makietę.
Każda zmiana układu wymaga sprawdzenia własnego i cudzego profilu.

Czego tu nie ma: żadnego porównania z innymi osobami, żadnego miejsca w tabeli (`AGENTS.md` §12).

### `CommentThread`
Lista komentarzy, każdy: awatar, autor, czas, treść, „Odpowiedz” (tekst, nie ikona). Formularz dodania komentarza na końcu, zawsze widoczny (nie wymaga kliknięcia „pokaż formularz”). Bez zagnieżdżenia głębszego niż 1 poziom w MVP (czytelność > funkcja Reddita).

### `PhotoPicker`
Duży obszar „Dodaj zdjęcie” (min. 120px wysokości, wyraźny obrys przerywany + tekst + ikona), po wyborze: **podgląd miniatury** + pasek postępu wysyłki (`role="progressbar"`, `aria-valuenow`) + tekst statusu („Wysyłanie... 42%” / „Gotowe”) + przycisk „Usuń zdjęcie” (tekst). Błąd (za duży plik, zły format): komunikat pod komponentem z konkretną przyczyną i **konkretną instrukcją** („Plik ma 18 MB. Wybierz zdjęcie mniejsze niż 15 MB.”).

### `WizardSteps`
Pasek kroków formularza wieloetapowego (przepis: 3 kroki). Tekst zawsze „Krok X z 3” + nazwa kroku, nie same kropki/ikony. `aria-current="step"` na aktywnym. Przyciski `Wstecz` / `Dalej` / `Zapisz szkic` zawsze widoczne, `Wstecz` nieaktywny (ale widoczny, z wyjaśnieniem) na kroku 1.

### `AutosaveBadge`
Mały tekst + ikona przy formularzu: „Szkic zapisany” (stan spoczynkowy) / „Zapisywanie...” (w trakcie) / „Nie udało się zapisać szkicu — sprawdź połączenie” (błąd, kolor danger). `aria-live="polite"` — czytnik ekranu ogłasza zmianę bez przerywania innej czynności użytkownika.

### `Button`, `Field`, `Alert`, `ErrorSummary`, `EmptyState`, `Avatar`, `FollowButton`, `CookedButton`, `SaveToCollection`, `ConfirmDialog`, `Toast`, `Pagination`, `Skeleton`

| Komponent | Stany / warianty | Zasady a11y |
|---|---|---|
| `Button` | primary/secondary/quiet/danger × normal/hover/focus/disabled/loading | `disabled` zawsze z wyjaśnieniem obok; `loading` z tekstem „Zapisywanie...” nie tylko spinnerem |
| `Field` | text/textarea/select/number × normal/focus/error/disabled | etykieta zawsze widoczna; błąd z `aria-invalid`+`aria-describedby` |
| `Alert` | info/success/warning/danger | ikona + tekst, nigdy sama ikona; `role="alert"` dla danger/warning (przerywa), `role="status"` dla info/success (nie przerywa) |
| `ErrorSummary` | widoczny tylko gdy są błędy | na górze formularza, lista linków „przejdź do pola”, `role="alert"`, fokus przenoszony na nagłówek podsumowania po nieudanej próbie zapisu |
| `EmptyState` | brak wyników / pusty profil / brak powiadomień | zawsze z sugerowaną akcją („Nie masz jeszcze przepisów. [Dodaj pierwszy przepis]”), nigdy sam napis „Brak danych” |
| `Avatar` | z inicjałem / ze zdjęciem / rozmiary sm-md-lg | `alt` z imieniem użytkownika (zdjęcie) lub `aria-hidden` + tekst obok (inicjał, bo inicjał sam nie niesie unikalnej informacji poza tekstem nazwy, który już jest wyświetlony osobno) |
| `FollowButton` | Obserwuj / Obserwujesz (toggle) | tekst zmienia się (nie tylko kolor), `aria-pressed`, nigdy samo serduszko |
| `CookedButton` | Ugotowałem (zawsze primary, główna konwersja) | prowadzi do formularza „Ugotowałem”, nie do modala z jednym kliknięciem — bo wymaga zdjęcia/komentarza |
| `SaveToCollection` | Zapisz / Zapisano (toggle) + wybór kolekcji | rozwijane menu z tekstowymi nazwami kolekcji, `combobox`/`listbox` z klawiaturą |
| `ConfirmDialog` | ostrzegawczy (info) / destrukcyjny (danger) | `role="alertdialog"`, fokus przenoszony do dialogu przy otwarciu i z powrotem do wywołującego przycisku po zamknięciu, `Esc` zamyka, tło nieklikalne (nie „lekki” overlay bez blokady). **Nigdy modal na modalu** |
| `Toast` | info/success/danger, auto-znikający | `aria-live="polite"` (success/info) lub `assertive` (danger), czas wyświetlania min. 5s LUB do ręcznego zamknięcia — nigdy krócej niż da się przeczytać przy powiększonym tekście |
| `Pagination` | „Pokaż więcej” (przycisk) | **nigdy infinite scroll bez alternatywy** — przycisk ładuje kolejną porcję, zachowuje pozycję scrolla, ogłasza `aria-live="polite"` „Załadowano 10 kolejnych wpisów” |
| `Skeleton` | placeholder ładowania | `aria-hidden="true"` (nie czytany przez SR), zastępowany treścią z `aria-live` przy gotowości jeśli ładowanie >1s |

---

## 6. Wzorce błędów i walidacji

Zasada z `docs/UX_50_PLUS.md`: **nigdy kod HTTP, zawsze zdanie po polsku mówiące co zrobić**.

Struktura formularza po nieudanej walidacji:

1. **`ErrorSummary` na górze formularza** — nagłówek „Znaleziono N błędów” + lista, każdy element to link przewijający do pola i ustawiający na nim fokus.
2. **Komunikat przy każdym polu z błędem** — pod polem, kolor `danger`, zawsze wzór: *[co jest nie tak] + [co zrobić]*.
3. **Poprawne dane nigdy nie znikają** — przy przeładowaniu/walidacji Livewire zachowuje stan pól (`wire:model` + błąd walidacji nie czyści inputu).

Przykłady (z dokumentacji produktowej, zachowane 1:1):

| Źle | Dobrze |
|---|---|
| „422 Unprocessable Entity” | „Nie udało się dodać zdjęcia, ponieważ plik ma ponad 15 MB. Wybierz mniejsze zdjęcie.” |
| „Błąd walidacji” | „Podaj nazwę przepisu — to pole nie może być puste.” |
| „Invalid input” | „Liczba porcji musi być liczbą większą od 0. Wpisz np. 4.” |

Fokus po nieudanej próbie zapisu formularza przenosi się na nagłówek `ErrorSummary` (`tabindex="-1"` + `.focus()`), żeby użytkownik czytnika ekranu od razu usłyszał listę problemów zamiast zgadywać, co się stało.

---

## 7. Ruch i animacja

Minimalna, funkcjonalna, nigdy dekoracyjna dla samej dekoracji:

- Przejścia stanu (otwarcie menu, toast, dialog): 150–200ms, `ease-out`.
- Brak animowanych karuzeli, brak parallax, brak „efektownych” wejść treści.
- **`prefers-reduced-motion: reduce`** wyłącza wszystkie przejścia niefunkcjonalne (fade/slide) — zostają tylko natychmiastowe zmiany stanu. Zaimplementowane globalnie w `tokens.css`:

```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: .01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: .01ms !important;
    scroll-behavior: auto !important;
  }
}
```

---

## 8. Twarde reguły „nigdy”

Niepodlegające dyskusji w review kodu i designu:

1. Ważna akcja ma widoczny opis; obowiązują wyłącznie jawne wyjątki AGENTS.md: menu trzech kropek karty i przełącznik motywu stopki. Nazwa dostępna pozostaje wymagana.
2. **Nigdy** hover, swipe, long-press ani gest od krawędzi jako jedyny sposób dotarcia do ważnej funkcji.
3. **Nigdy** infinite scroll bez alternatywy — zawsze przycisk „Pokaż więcej” + zachowana pozycja.
4. **Nigdy** karuzele (auto-przewijające się lub wymagające swipe'a do zobaczenia treści).
5. **Nigdy** modal na modalu — jeden dialog na raz, kolejny dopiero po zamknięciu poprzedniego.
6. **Nigdy** `disabled` bez wyjaśnienia dlaczego i co zrobić, żeby odblokować.
7. **Nigdy** tekst nałożony na zdjęcie bez podkładu (gradient/scrim) zapewniającego kontrast ≥ 4.5:1 niezależnie od treści zdjęcia pod spodem.
8. **Nigdy** placeholder jako jedyna etykieta pola.
9. **Nigdy** kolor jako jedyny nośnik informacji (błąd/sukces/link) — zawsze + tekst/ikona/podkreślenie.
10. **Nigdy** `outline: none` bez w pełni równoważnego zamiennika fokusu.
11. **Nigdy** wymuszanie publikacji podczas onboardingu — zawsze opcja „Na razie tylko pooglądam”.

---

## 9. Decyzje wymagające właściciela produktu

- Ewentualny test innego fontu po badaniach czytelności wymaga nowej decyzji; aktualnym wyborem jest lokalny Inter, nie oczekiwanie na wybór między fontami.
- Docelowa treść tekstu przy `disabled` dla każdego konkretnego formularza (np. dokładne brzmienie „Dodaj zdjęcie, żeby opublikować” vs inne warianty) — copywriting per-ekran.
- Czy `CookedCard` w widoku przepisu ma limit wyświetlanych wpisów domyślnie (np. 5 + „Pokaż więcej”) — wpływa na wydajność i długość strony przy popularnych przepisach.
- Polityka soft-delete dla `ConfirmDialog` usuwania wpisu/konta (okres na cofnięcie) — wspomniana w `UX_50_PLUS.md` jako „preferować”, nie doprecyzowana liczbowo.
