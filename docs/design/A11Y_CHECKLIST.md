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
- [ ] **Automaty przeszły**: axe-core / pa11y / Lighthouse bez nowych błędów (patrz sekcja C) — link do wyniku CI w opisie PR.
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

### axe-core + Playwright (rekomendowane jako główny automat)

```bash
npm install -D @axe-core/playwright @playwright/test
```

```ts
// tests/a11y/axe.spec.ts
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const paths = ['/', '/discover', '/add', '/posts/create', '/recipes/create', '/@basia68', '/recipes/sernik-babci-heleny'];

for (const path of paths) {
  test(`axe: ${path} nie ma naruszeń WCAG 2.2 AA`, async ({ page }) => {
    await page.goto(path);
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag22aa'])
      .analyze();
    expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
  });
}
```

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

### Lighthouse CI (śledzenie regresji w czasie, budżet ≥ 95 na kategorię Accessibility)

```bash
npm install -D @lhci/cli
```

```json
// lighthouserc.json
{
  "ci": {
    "collect": {
      "url": ["http://localhost:8000/", "http://localhost:8000/recipes/sernik-babci-heleny"],
      "numberOfRuns": 2
    },
    "assert": {
      "assertions": {
        "categories:accessibility": ["error", { "minScore": 0.95 }]
      }
    },
    "upload": { "target": "temporary-public-storage" }
  }
}
```

```bash
npx lhci autorun
```

### Job CI (GitHub Actions) — propozycja

```yaml
# .github/workflows/a11y.yml
name: Dostępność
on: [pull_request]

jobs:
  a11y:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env && php artisan key:generate
      - run: php artisan serve --port=8000 &
        env:
          APP_ENV: testing

      - uses: actions/setup-node@v4
        with:
          node-version: 22

      - run: npm ci
      - run: npx playwright install --with-deps chromium

      - name: Poczekaj na serwer
        run: npx wait-on http://localhost:8000

      - name: axe-core (Playwright)
        run: npx playwright test tests/a11y/axe.spec.ts

      - name: pa11y-ci
        run: npx pa11y-ci

      - name: Lighthouse CI (budżet dostępności ≥ 95)
        run: npx lhci autorun
```

Reguła bramkowania: `a11y` jest **wymaganym** checkiem przed merge do `main` (branch protection) — naruszenie axe-core na poziomie `serious`/`critical` blokuje merge; `moderate`/`minor` tworzy komentarz w PR do ręcznej oceny, nie blokuje automatycznie (żeby nie zatrzymywać release'u na fałszywych alarmach), ale wymaga jawnego „zaakceptowano” w review.

---

## D. Testy z użytkownikami (przed publiczną betą)

Zgodnie z `docs/UX_50_PLUS.md` — automaty nie zastępują tego kroku:

- 5 osób 50–59, 5 osób 60–69, 3 osoby 70+; Android, iPhone i desktop.
- Scenariusze: założenie konta, dodanie zdjęcia, dodanie przepisu, wyszukiwanie, zapis do kolekcji, komentarz, zwiększenie rozmiaru tekstu, usunięcie wpisu.
- Metryka jakościowa priorytetowa: liczba momentów, w których pada pytanie „Co mam teraz kliknąć?” — cel: zero na scenariusz podstawowy (dodanie zdjęcia).
