# System projektowy v3.1 — co to jest i jak się ma do reszty `docs/design/`

Paczka **„Kuking.pl — system projektowy"**, przysłana przez właściciela
8 września 2026, zrobiona w Claude Design. Wchodzi do repozytorium w całości,
razem z oryginałem w `uploads/`, bo istniała dotąd wyłącznie jako plik ZIP
u właściciela — a `docs/design/README.md` wymaga, żeby obowiązujący wygląd
leżał w repozytorium, nie obok niego.

## Czym to jest wobec `kit-v2/`

**Następcą, nie konkurentem.** Sama paczka mówi o sobie, że jest przepisana
z `KuKING-design-system-v3.1-poprawiony`, a ta z kolei wywodzi się z tego
samego materiału co `kit-v2/`. Wartości tokenów, klasy i decyzje są
przeniesione co do znaku — nic tu nie jest nowym projektem.

`kit-v2/` zostaje na miejscu. Nie kasujemy go, dopóki wdrożenie v3.1 nie jest
skończone: to jedyny materiał, na którym opisano etapy A–D i porównania
zrzutami ekranu, a `STAN_WDROZENIA_KITU.md` wciąż się do niego odwołuje.

## Co zmierzono przy pierwszym zetknięciu z kodem (8 września 2026)

Zanim cokolwiek zmieniono, porównano paczkę z arkuszami w `resources/css/`:

| Co | Wynik |
|---|---|
| Tokeny | **67 z 70 identycznych co do znaku** — `#FAF6F0`, `#2B241D`, `#7A5C10`. Brakowało jedenastu tokenów z v3.1; weszły do `resources/css/tokens.css` |
| `.card` | identyczna deklaracja po deklaracji |
| `.btn-primary`, `.btn-secondary`, `.btn-quiet` | identyczne |
| `.btn` (baza) | dwie realne różnice: wcięcie `--spacing-5`, interlinia 1.25, wyśrodkowanie napisu, reakcja na wciśnięcie |
| Klasy | 53 wspólne, **170 jest w systemie i nie ma ich w kodzie** |

Wniosek, który wyznacza kolejność prac: **paleta i komponenty podstawowe już
się zgadzają.** Różnica w wyglądzie siedzi w tych 170 klasach, a te grupują
się w układ i strony publiczne: `karta-*` (17), `pas-*` (11), `szyna-*` (10),
`stopka-*` (10), `hero-*` (8), `sekcja-*` (4), `topbar-*` (3).

Zgadza się to co do słowa z tym, co `STAN_WDROZENIA_KITU.md` zapisał przy
kicie v2: „Kolory, typografia i logo są wdrożone i zgadzają się co do
wartości. Nie zgadza się **układ**".

## Czego z tej paczki NIE bierzemy do aplikacji

1. **`tokens/fonts.css`** — ładuje Inter z serwera Google. Aplikacja hostuje
   Inter u siebie, w dwóch podzbiorach (`latin` 48 kB + `latin-ext` 85 kB,
   `resources/fonts/`), i tak zostaje. Bez `latin-ext` polskie znaki
   `ł ą ę ć ń ś ź ż` lecą z fontu zastępczego — słowo „żurek" ma wtedy trzy
   różne kroje. Zapytanie do obcego serwera przeczyłoby też sekcji
   „Prywatność”. Sama paczka wymienia to jako brak numer jeden.
2. **`boards.css`** — plansze społecznościowe i arkusze do druku. Do
   aplikacji się nie ładuje; byłby martwym kodem w każdym żądaniu. Zostaje
   tutaj jako materiał do plansz.
3. **`tokens/base.css` w części resetu** — to odpowiednik preflightu
   Tailwinda, dopisany dlatego, że paczka jedzie bez Tailwinda. Aplikacja ma
   Tailwind 4, więc preflight już jest; wciągnięcie drugiego dałoby dwie
   deklaracje tego samego.

## Do czego służą poszczególne katalogi

| Ścieżka | Do czego |
|---|---|
| `uploads/KuKING-design-system-v3.1-poprawiony/` | **oryginał, rozstrzygający przy każdej wątpliwości** — decyzje D-101…D-112, fundamenty, audyt ze zrzutami |
| `ui_kits/serwis/` | jedenaście ekranów serwisu jako wzorzec odniesienia — to jest to, do czego dopasowujemy widoki |
| `components/` | komponenty referencyjne w JSX z typami i promptami; **nie wdrażamy Reacta**, czytamy je jak specyfikację |
| `components.css`, `site.css` | źródło reguł do przeniesienia do `resources/css/` |
| `guidelines/` | karty specyfikacji: kolor, typografia, rytm, marka |
| `templates/` | pięć punktów wyjścia dla nowych ekranów |
| `AUDYT.md` | co się nie zgadzało przy przenoszeniu paczki i co z tym zrobiono |

## Etapu 2 z `WDROZENIE.md` NIE DA SIĘ wykonać dosłownie — zmierzone

Instrukcja w `uploads/…/07-wdrozenie/WDROZENIE.md` §4 mówi, żeby w etapie 2
wpiąć `komponenty.css` **w całości, po `app.css`**, bez dotykania widoków:
59 wspólnych nazw dostaje wtedy nowy wygląd, a cofa się to usunięciem jednej
linijki. Dokument sam uprzedza, że powstał **bez dostępu do repozytorium**.

Sprawdzone 8 września 2026 na kodzie. Z 53 wspólnych nazw klas **43 się
różnią**, a część różnic jest strukturalna, nie kosmetyczna:

| Klasa | Aplikacja | System |
|---|---|---|
| `.app-body` | `display: grid` + kolumna nawigacji bocznej | `display: flex` + `--container-strona-solo` |
| `.app-rail` | `display: flex` | `display: none` (odsłaniana wyżej medią) |
| `.avatar` | `inline-flex`, `object-fit: cover`, rozmiar z parametru | `grid`, sztywne `3rem` |
| `.badge` | `--radius-pill`, tło wgłębione | `--radius-sm` |
| `.bottom-nav` | `z-index: 30`, `flex-wrap` | `z-index: 40` |

Wpięcie arkusza po `app.css` nałożyłoby właściwości flexa na siatkę grid
w szkielecie strony. To jest dokładnie ryzyko **R-2** z §6 tamtego dokumentu —
„powstaje wygląd, którego nie zaprojektował nikt" — tyle że nie w pojedynczym
komponencie, a w układzie każdej podstrony.

**Zamiast tego: uzgadnianie klasa po klasie**, w małych commitach, z decyzją
przy każdej, która wersja wygrywa i dlaczego. Wolniej, ale każdy krok da się
obejrzeć i cofnąć osobno, a żaden nie zostawia stanu mieszanego.

Aplikacja nie jest tu uboższym krewnym systemu: to druga, równie rozwinięta
implementacja tego samego projektu, miejscami z własnymi, świadomymi
odejściami od kitu (opisanymi w `STAN_WDROZENIA_KITU.md`). Import „na wierzch"
skasowałby je bez śladu.

## Stan wdrożenia

Prowadzony w `docs/HANDOVER.md`. Na 8 września 2026: warstwa tokenów
podniesiona do v3.1, `.btn` zgodny z systemem. Układ — belka, nawigacja
boczna, szyna, stopka, pasy stron publicznych — **przed nami**.
