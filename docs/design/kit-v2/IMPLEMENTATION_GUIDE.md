# KuKing.pl — przewodnik implementacji mockupów

Celem tej paczki nie jest stworzenie drugiego frontendu obok aplikacji. Repozytorium ma już Laravel + Blade + Livewire + Tailwind i istniejące komponenty, więc należy **przenieść wygląd do obecnych komponentów**, a nie kopiować statyczny HTML 1:1 do produkcji.

## 1. Co jest czym

| Mockup | Widok / funkcja | Główne elementy do przeniesienia |
|---|---|---|
| `01_desktop_feed` | `/home` | AppShell, TopBar, SideNav, composer, PostCard, prawa szyna |
| `02_desktop_recipe` | `/recipes/{slug}` | RecipeView, hero, stats, ingredients, steps, CookedCard |
| `03_desktop_search_discover` | `/search` / `/discover` | SearchBox, chips, result cards, filtry |
| `04_desktop_profile` | `/@username` | profil, follow, statystyki, archiwum |
| `05_mobile_feed` | responsive `/home` | TopBar + BottomNav + PostCard |
| `06_mobile_recipe` | responsive recipe | sticky/visible CTA `Ugotowałem`, sekcje przepisu |
| `07_mobile_search` | responsive search | pole wyszukiwania + proste filtry |
| `08_mobile_add` | `/add` / post create | PhotoPicker, textarea, visibility, publish |
| `09_mobile_menu_profile` | profil / ustawienia | profil, menu, ustawienia tekstu/czytelności |
| `10_brand_identity` | referencja | tokeny, logo, komponenty |
| `11_brand_materials` | referencja | social/PWA/watermark |

## 2. Najważniejsza reguła

**Nie twórz osobnych szablonów mobile i desktop.**

Mobile i desktop powinny używać tych samych danych i w miarę możliwości tych samych komponentów Blade/Livewire. Różnica ma wynikać głównie z CSS/Tailwind (`lg:` / `xl:`), nie z duplikacji logiki.

## 3. Co już istnieje w repo

Aktualne repo ma m.in. komponenty w `resources/views/components/`, takie jak:

- `layout.blade.php`;
- `post-card.blade.php`;
- `cooked-card.blade.php`;
- `comment-thread.blade.php`;
- `avatar.blade.php`;
- `field.blade.php`;
- `empty-state.blade.php`;
- komponenty marki `kuking-*`.

Najpierw **reskin tych komponentów**, dopiero później dodawanie nowych.

## 4. CSS / Tailwind

Repo ma już `resources/css/tokens.css` i `resources/css/app.css`.

Rekomendowana kolejność:

1. porównaj `css/tailwind-theme.css` z obecnym `resources/css/tokens.css`;
2. zachowaj obecne nazwy tokenów z repo jako source of truth;
3. dodaj `Inter Variable` do `--font-sans`, jeśli akceptujesz dodatkowy font payload;
4. nie zmieniaj kontrastów bez ponownego przeliczenia;
5. prototypowy `css/prototype.css` traktuj jako **referencję wyglądu**, nie jako plik do wklejenia do aplikacji.

## 5. Proponowana kolejność wdrożenia

### Etap A — fundament

- [ ] nowe logo 01 „Uśmiech”;
- [ ] Inter Variable + fallback albo pozostawienie system stacku;
- [ ] paleta `surface / raised / brand / ink`;
- [ ] radii i subtelne cienie;
- [ ] topbar + sidenav + bottomnav;
- [ ] 48 px controls + focus ring.

### Etap B — rdzeń produktu

- [ ] `PostCard` zgodny z `01` i `05`;
- [ ] composer „Co dziś gotujesz?”;
- [ ] `Ugotowałem` jako główna akcja;
- [ ] „Twój zeszyt” (nie „Mój zeszyt” — D-073);
- [ ] responsive feed.

### Etap C — przepisy

- [ ] recipe hero;
- [ ] stats: czas / porcje / trudność;
- [ ] składniki z liczbą porcji;
- [ ] kroki przygotowania;
- [ ] sekcja „Skąd ten przepis?”;
- [ ] `CookedCard` / „Jak wyszło innym?”.

### Etap D — search/profile/add

- [ ] wyszukiwarka i chipsy;
- [ ] profil i archiwum;
- [ ] add flow poniżej 60 s;
- [ ] mobile profile/menu/accessibility.

### Etap E — QA

- [ ] 320 px;
- [ ] 360/390/432 px mobile;
- [ ] 1024 / 1280 / 1440 / 1920 desktop;
- [ ] zoom 200%;
- [ ] text scale 112/125/150%;
- [ ] keyboard only;
- [ ] Windows High Contrast;
- [ ] dark mode;
- [ ] `prefers-reduced-motion`;
- [ ] screen reader smoke test.

## 6. Komponenty — proponowane API

Nie jest to wymagany kod, tylko docelowy podział odpowiedzialności.

```text
<x-layout>
  <x-top-bar />
  <x-side-nav />
  <main>
    <x-composer />
    <x-post-card :post="$post" />
  </main>
  <x-home-rail />
  <x-bottom-nav />
</x-layout>
```

`PostCard` powinien odpowiadać wyłącznie za prezentację wpisu i akcje. Logika follow/save/cooked może pozostać w Livewire/action classes.

## 7. Font

Mockupy korzystają z Inter. W produkcji preferowane jest self-hostowanie variable WOFF2. Nie trzeba jednak blokować wdrożenia fontem: systemowy stack z obecnego repo jest poprawnym fallbackiem.

Opcjonalny `Atkinson Hyperlegible Next` powinien być trybem dostępności, a nie podstawową marką.

## 8. Obrazki

Do implementacji nie używaj plików z `mockups/` jako elementów UI — są tylko referencją.

Fotografie w `assets/photos/` można wykorzystać jako fixture/demo data. Produkcja powinna nadal korzystać z obecnego pipeline obrazów (thumb/feed/large).

## 9. Wierność vs. funkcjonalność

Priorytet wdrożenia:

1. hierarchia i ergonomia;
2. layout i spacing;
3. typografia;
4. kolory;
5. fotografie;
6. dopiero na końcu pixel-perfect detale.

Nie warto kopiować statycznego układu 1920×1200 literalnie. Mockup pokazuje **proporcje i system**, nie sztywne piksele produkcyjne.

## 10. Szybki podgląd paczki

Z katalogu paczki:

```bash
python3 -m http.server 8080
```

Następnie otwórz `http://localhost:8080/`.
