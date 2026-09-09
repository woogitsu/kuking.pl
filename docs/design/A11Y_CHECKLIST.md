# Checklista dostępności — Kuking.pl

Do wklejenia w `.github/pull_request_template.md` (sekcja „Dostępność”) oraz jako plan testów manualnych przed każdym większym release'em. Cel: WCAG 2.2 AA. Każdy punkt ma metodę weryfikacji **do 30 sekund** — jeśli zajmuje dłużej, coś jest nie tak z testowanym ekranem, nie z metodą testu.

---

## A. Checklista PR-owa (wklej do szablonu PR)

```markdown
## Dostępność (WCAG 2.2 AA)

Zaznacz, jeśli dotyczy tego PR-a. Jeśli PR nie zmienia UI, zaznacz wszystko jako N/A i napisz dlaczego.

- [ ] **Klawiatura**: cały nowy/zmieniony interfejs da się obsłużyć samym Tab/Shift+Tab/Enter/Spacja/Esc, bez myszy.
- [ ] **Focus widoczny**: każdy interaktywny element ma wyraźny pierścień fokusu przy nawigacji Tab (sprawdzone `:focus-visible`, nie samo `:focus`).
- [ ] **Kolejność fokusu** logiczna (odpowiada kolejności wizualnej/czytania).
- [ ] **Etykiety pól** zawsze widoczne (nie tylko placeholder) i powiązane `<label for>`/`aria-label`.
- [ ] **Kontrast**: nowe kolory tekstu/tła sprawdzone `agents/ux/contrast.py` lub narzędziem z sekcji C — ≥4.5:1 (tekst), ≥3:1 (duży tekst, obramowania pól, ikony funkcjonalne).
- [ ] **Błędy formularza**: komunikat mówi co jest nie tak I co zrobić; poprawne dane w innych polach nie znikają; błąd powiązany `aria-describedby`+`aria-invalid`.
- [ ] **Brak ikony jako jedynego opisu akcji** — każdy przycisk/link ma tekst.
- [ ] **Brak funkcji wymagającej wyłącznie hover/swipe/long-press/gestu od krawędzi.**
- [ ] **Alt text** na każdym znaczącym obrazku; dekoracyjne obrazy `alt=""`/`aria-hidden`.
- [ ] **`aria-live`** na komponentach dynamicznych (toast, autosave, pasek postępu, „Pokaż więcej”) — sprawdzone, że nie przerywa niepotrzebnie (`polite` vs `assertive` dobrane świadomie).
- [ ] **200% zoom i 320px szerokości**: brak poziomego przewijania strony, brak ucinania treści.
- [ ] **`prefers-reduced-motion`**: nowe animacje/przejścia respektują to ustawienie.
- [ ] **Rozmiar dotykowy**: nowe klikalne elementy ≥ 48×48px (główne akcje) / ≥ 44×44px (pozostałe).
- [ ] **Automaty przeszły**: axe-core (dostępność) i Lighthouse (wydajność, SEO) bez nowych błędów (patrz sekcja C) — link do wyniku CI w opisie PR.
```

---

## B. Plan testów manualnych — jak sprawdzić w 30 sekund

| # | Test | Jak sprawdzić (≤30s) | Na co patrzeć |
|---|---|---|---|
| 1 | **Klawiatura** | Otwórz ekran, kliknij raz w pasek adresu (żeby wyzerować fokus), potem sam Tab przez cały widok, Enter/Spacja na przyciskach, Esc w dialogu. | Każdy element interaktywny osiągalny; nic „w pułapce” fokusu poza dialogiem; Esc zamyka `ConfirmDialog`/`Toast`. |
| 2 | **Widoczny focus** | To samo co #1, patrz na pierścień. | Pierścień wyraźny na KAŻDYM tle (w tym na przyciskach `primary`/`danger` — technika halo z `DESIGN_SYSTEM.md` §1.4), nigdy niewidoczny/ledwo widoczny. |
| 3 | **200% zoom** | Ctrl/Cmd + „+” trzy razy w przeglądarce (do 200%) na desktopie. | Brak poziomego scrolla strony; tekst się zawija, nie ucina; przyciski nadal klikalne całą powierzchnią. |
| 4 | **320px szerokości** | DevTools → responsive mode → szerokość 320px (najmniejszy powszechny telefon). | `BottomNav` czytelny (5 pozycji nie ściśnięte do nieczytelności), karty się nie łamią, żadna treść nie wychodzi poza ekran. |
| 5 | **Powiększona czcionka systemowa/produktowa** | Ustaw `data-text-scale="150"` na `<html>` w DevTools (Elements → edytuj atrybut) LUB w `/settings/accessibility` wybierz 150%. | Tekst rośnie, przyciski (min-height) najwyżej robią się wyższe, nic nie jest ucięte ani nienachodzące. |
| 6 | **Screen reader smoke — NVDA (Windows)** | Uruchom NVDA (Ctrl+Alt+N), przejdź przez ekran klawiszem Tab i strzałkami w trybie przeglądania. | Każdy przycisk ogłasza swój cel (nie „przycisk” bez nazwy); nagłówki (`H` w NVDA) tworzą logiczną strukturę; formularz ogłasza etykietę+błąd razem. |
| 7 | **Screen reader smoke — VoiceOver (macOS/iOS)** | Cmd+F5 (macOS) lub potrójne kliknięcie bocznego przycisku (iOS), VO+strzałki / przesunięcie palcem. | To samo co #6; dodatkowo: `aria-live` (toast, autosave) ogłasza się samoczynnie bez przenoszenia fokusu. |
| 8 | **Windows High Contrast (forced-colors)** | Windows: Ustawienia → Ułatwienia dostępu → Kontrast → włącz motyw kontrastowy. Chrome/Edge respektują `forced-colors: active`. | Obramowania przycisków/pól nadal widoczne (nie znikają przy usunięciu kolorowych teł); pierścień fokusu nadal widoczny. |
| 9 | **`prefers-reduced-motion`** | DevTools → Rendering tab → „Emulate CSS media feature prefers-reduced-motion: reduce”. | Toasty/dialogi pojawiają się bez animacji wjazdu/fade; nic nie pulsuje/nie przesuwa się automatycznie. |
| 10 | **Kontrast kolorów** | Uruchom `python3 agents/ux/contrast.py agents/ux/pairs_light.json` (i `pairs_dark.json`) po każdej zmianie koloru; dla nowych par dopisz wiersz do pliku JSON. | Wszystkie wiersze `OK`, żaden `FAIL`. |

---

## C. Automaty — narzędzia i komendy CI

### axe-core + Playwright — DZIAŁA, `scripts/dostepnosc.mjs` (issue #26)

```bash
./scripts/check.sh --dostepnosc      # w ramach kontroli przed wysłaniem
node scripts/dostepnosc.mjs          # osobno, z pełnym wynikiem na konsoli
node scripts/dostepnosc.mjs --szybko # sam wariant jasny, gdy się spieszysz
```

Skrypt sam podnosi `php artisan serve` i sam go gasi, sam zasiewa dane
z `DemoSeeder` i sam się loguje przez prawdziwy formularz. Nie trzeba
niczego przygotowywać.

**11 ekranów × 4 warianty = 44 przebiegi:**

| | |
|---|---|
| ekrany | powitalna, „Świeżo z Kuking", logowanie, rejestracja, przepis, profil, tablica, dodaj zdjęcie, dodaj przepis, czytelność, szukaj |
| warianty | jasny · ciemny · tekst 140% · szerokość 320 px |

Warianty nie są ozdobą. **Kontrast liczy się osobno dla każdego motywu**,
a przy skali tekstu 140% i szerokości 320 px wychodzą nakładające się
elementy. Sprawdzanie samego „normalnego" widoku przepuszczało dokładnie
te usterki, które dotykają naszej grupy najczęściej — bo to ona włącza
większy tekst.

Wynik idzie do **`storage/dostepnosc.json`**, nie tylko na konsolę: przy 44
przebiegach lista naruszeń nie mieści się w oknie terminala. Kod wyjścia jest
niezerowy tylko przy wagach `critical` i `serious`.

**Co złapało przy pierwszym uruchomieniu** — trzy usterki niewidoczne
w codziennej pracy, bo wszystkie trzy dotyczyły trybu ciemnego albo linków
w tekście ciągłym:

- bieżąca pozycja nawigacji: kontrast **2.32** w trybie ciemnym (w jasnym 6.67);
- przycisk „Usuń": kontrast **2.28** w trybie ciemnym;
- linki w tekście pomocniczym odróżnione **wyłącznie kolorem** (WCAG 1.4.1).

To jest dokładnie ta klasa błędów, po którą się sięga po automat: żaden
z nich nie psuł niczego widocznego przy zwykłym przeglądaniu strony.

```bash
npx playwright test tests/a11y/axe.spec.ts
```

### pa11y-ci (szybki dodatkowy automat, dobry jako „drugie zdanie”)

```bash
npm install -D pa11y-ci
```

```json
// .pa11yci.json
{
  "defaults": {
    "standard": "WCAG2AA",
    "timeout": 15000,
    "chromeLaunchConfig": { "args": ["--no-sandbox"] }
  },
  "urls": [
    "http://localhost:8000/",
    "http://localhost:8000/discover",
    "http://localhost:8000/add",
    "http://localhost:8000/posts/create",
    "http://localhost:8000/recipes/create"
  ]
}
```

```bash
npx pa11y-ci
```

### Lighthouse — DZIAŁA, `scripts/wydajnosc.mjs` (issue #26, druga połowa)

**Nie mierzy dostępności.** Kategoria „Accessibility” Lighthouse'a liczy się
tym samym silnikiem co axe-core wyżej — drugi przebieg dokładałby te same
naruszenia WCAG, tylko po 8-12 s renderowania strony zamiast ułamka sekundy.
Ten automat liczy wyłącznie `performance` i `seo`: to, czego axe-core
z definicji nie mierzy.

```bash
node scripts/wydajnosc.mjs                  # sam podnosi serwer i bazę
ADRES=http://127.0.0.1:8123 node scripts/... # gotowy serwer
```

**8 stron publicznych**, bez logowania — profil mobile, throttling
symulowany (domyślne ustawienia Lighthouse'a):

| | |
|---|---|
| ekrany | powitalna, „Świeżo z Kuking", logowanie, rejestracja, przepis, profil, strona tagu, regulamin |
| progi | wydajność ≥ 70, SEO ≥ 85 (ustalone z pomiaru lokalnego, nie z głowy — liczby i uzasadnienie w nagłówku pliku) |

Ekran logowania jest liczony do wydajności, ale **nie do SEO** — ma celowy
`<meta name="robots" content="noindex, nofollow">` (formularz logowania nie
ma prawa trafić do wyszukiwarki), więc niska ocena SEO tam jest poprawnym
działaniem, nie usterką.

Wynik idzie do **`storage/wydajnosc.json`**, tak samo jak
`storage/dostepnosc.json` wyżej.

### Job CI (GitHub Actions) — jest, `.github/workflows/ci.yml`, job `dostepnosc`

Prawdziwy job (nie propozycja) to `dostepnosc` w `.github/workflows/ci.yml`.
Lighthouse jedzie tam jako KOLEJNY KROK w TYM SAMYM joobie co axe-core, po
kroku „axe-core (23 ekrany × 4 warianty)” — ten job ma już postawioną bazę,
PHP, Node i ściągnięte Chromium, więc osobny job powielałby to wszystko od
zera tylko po to, żeby postawić drugi raz ten sam serwer. Pełne uzasadnienie
(i to, dlaczego Lighthouse nie potrzebuje własnej przeglądarki) stoi
w komentarzach przy tym joobie i w nagłówku `scripts/wydajnosc.mjs`.

Job (jak i krok axe-core) uruchamia się **tylko przy zmianach w warstwie
widoku** (krok „Czy zmieniła się warstwa widoku”) — zmiana w kontrolerze albo
w migracji go nie odpala.

Reguła bramkowania: `dostepnosc` jest wymaganym checkiem CI — naruszenie axe
na poziomie `critical`/`serious` LUB wynik Lighthouse'a poniżej progu blokuje
merge. Kryterium scalenia to „zielone ALBO pominięte” (patrz komentarz przy
jobie „Zakres zmiany” w `ci.yml`), nie samo „zielone” — zmiana wyłącznie
w dokumentacji pomija ten job w całości.

---

## D. Testy z użytkownikami (przed publiczną betą)

Zgodnie z `docs/UX_50_PLUS.md` — automaty nie zastępują tego kroku:

- 5 osób 50–59, 5 osób 60–69, 3 osoby 70+; Android, iPhone i desktop.
- Scenariusze: założenie konta, dodanie zdjęcia, dodanie przepisu, wyszukiwanie, zapis do kolekcji, komentarz, zwiększenie rozmiaru tekstu, usunięcie wpisu.
- Metryka jakościowa priorytetowa: liczba momentów, w których pada pytanie „Co mam teraz kliknąć?” — cel: zero na scenariusz podstawowy (dodanie zdjęcia).
