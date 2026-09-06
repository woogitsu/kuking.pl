# KuKing.pl — identyfikacja wizualna i system UI

**Wersja:** 2.0  
**Kierunek:** miękki minimalizm / ciepła kuchnia  
**Logo:** wariant 01 „Uśmiech”  
**Stan:** rekomendacja do wdrożenia; spójna z aktualnymi założeniami produktu i systemem designu w repozytorium.

---

## 1. Decyzja projektowa

KuKing.pl powinien wyglądać jak **spokojna, ludzka społeczność wokół domowego gotowania**, a nie jak marketplace, katalog przepisów, aplikacja delivery ani „portal dla seniorów”.

Najlepiej pasuje kierunek:

- duże fotografie jedzenia;
- ciepłe, jasne tła zamiast zimnej bieli;
- wyraźny, ale nie agresywny kolor marki;
- dużo oddechu i maksymalnie dwie główne kolumny na desktopie;
- duże teksty i kontrolki;
- ważne działania opisane tekstem, nie samą ikoną;
- „Ugotowałem” jako charakterystyczna akcja społecznościowa zamiast zwykłego lajka;
- elementy wyglądające współcześnie także dla użytkowników 20–40, ale wygodne również dla 50+.

### Pięć słów opisujących markę

**domowa · ludzka · spokojna · czytelna · prawdziwa**

### Czego unikać

- neonów, gradientów „AI/SaaS”, glassmorphismu;
- czystej bieli jako tła całej aplikacji;
- bardzo jasnego czerwonego w małym tekście;
- drobnych szarych etykiet 12–14 px;
- ściany małych kafelków z przepisami;
- ikon bez etykiet dla ważnych funkcji;
- wyglądu „specjalnie dla seniorów”.

---

## 2. Logo

Wybrany kierunek: **01 „Uśmiech”** — garnek + korona + delikatny uśmiech.

Dlaczego działa:

1. garnek od razu komunikuje gotowanie;
2. korona naturalnie uzasadnia „King” w nazwie;
3. uśmiech zmienia znak z „katalogu kuchennego” w znak społeczności;
4. znak jest wystarczająco prosty na favicon i ikonę PWA;
5. można używać go jako symbolu przy akcji „Ugotowałem”.

### Warianty

- `assets/logo/kuking-logo-smile.svg` — podstawowy poziomy logotyp;
- `assets/logo/kuking-icon-smile.svg` — sam znak;
- `assets/logo/kuking-logo-smile-inverse.svg` — biały na ciemnym/brandowym tle;
- `assets/logo/kuking-icon-smile-inverse.svg` — biały znak.

### Zasady użycia

- podstawowy wordmark: **KuKing** w ciemnym `#2B241D`, `.pl` i znak w `#B3401F`;
- na tle brandowym/ciemnym: cały znak biały;
- nie dodawać gradientu, cienia ani obrysu do logo;
- nie rozciągać znaku;
- minimalny zalecany rozmiar poziomego logo w UI: ok. **150 px szerokości**;
- minimalny rozmiar samego znaku: **32×32 px**;
- pole ochronne: co najmniej **1/2 wysokości garnka** wokół znaku.

---

## 3. Kolory

### Paleta podstawowa

| Token | Hex | Zastosowanie |
|---|---:|---|
| Surface | `#FAF6F0` | tło aplikacji, ciepła kość słoniowa |
| Raised | `#FFFFFF` | karty, topbar, modal |
| Sunken | `#F1EBE1` | pola formularza, spokojne tła sekcji |
| Border | `#E4DACB` | subtelne separatory |
| Border Strong | `#8A7A63` | pola, ważne obrysy |
| Ink | `#2B241D` | tekst główny |
| Ink Muted | `#5C5347` | metadane, pomocniczy tekst |
| Brand | `#B3401F` | linki, akcje, brand, CTA |
| Brand Dark | `#8C3018` | hover/active |
| Brand Tint | `#F5E4DC` | aktywna pozycja menu, subtelne akcenty |
| Accent | `#7A5C10` | odznaki/secondary highlight |
| Accent Tint | `#FCEACB` | tło odznaki |
| Success | `#1E7B3E` | sukces |
| Danger | `#B3261E` | błędy/destrukcja |
| Focus | `#155EEF` | fokus klawiatury — celowo spoza palety |

### Dlaczego nie `#E53935` jako główny kolor tekstowy

W pierwszych wizualizacjach pojawiał się jaśniejszy czerwony `#E53935`. Dla normalnego tekstu na bieli ma kontrast ok. **4.23:1**, więc nie osiąga progu WCAG AA 4.5:1. Na kremowym `#FAF6F0` spada do ok. **3.93:1**.

`#B3401F` daje ok. **5.72:1 na bieli** i **5.31:1 na kremowym tle**, dlatego lepiej nadaje się jednocześnie na markę, linki i tekstowe akcje.

### Dark mode

Dark mode nie powinien być prostą inwersją. Rekomendowana baza:

- tło `#1E1A16`;
- karty `#2A241E`;
- tekst `#F5EFE6`;
- muted `#C9BEB0`;
- brand jako tekst `#F2986A`;
- brand jako tło przycisku `#C1502A`;
- focus `#6EA8FF`.

---

## 4. Typografia

### Rekomendacja główna: Inter Variable

**Inter** zostaje najlepszym wyborem dla podstawowego UI:

- został zaprojektowany z myślą o ekranach i interfejsach;
- ma wysokie x-height, co pomaga czytelności małych liter;
- ma pełny zakres wag i variable font;
- obsługuje polskie znaki i bardzo szeroki zestaw języków;
- wizualnie pasuje do miękkiego minimalizmu bez efektu „medycznego” czy „senior mode”.

**Produkcja:** najlepiej self-hostować WOFF2/variable font; w tych prototypach używany jest CDN tylko dla wygodnego podglądu. Fontów nie ma w paczce.

### Opcjonalny tryb zwiększonej czytelności

Dla użytkownika, który chce jeszcze bardziej rozróżnialne znaki, można dodać opcję **Atkinson Hyperlegible Next**. Nie proponuję go jako domyślnego brand fontu — lepiej jako ustawienie dostępności.

### Skala

| Rola | Rozmiar |
|---|---:|
| pomoc / metadata | 16 px |
| body | **18 px** |
| treść wpisu | 20 px |
| lead | 22 px |
| tytuł sekcji | 24 px |
| tytuł mobile | 28 px |
| tytuł desktop | 36 px |

**Line-height:** 1.5–1.65 dla treści, 1.2–1.35 dla dużych tytułów.  
**Długość wiersza:** najlepiej ok. 55–75 znaków.  
**Wagi:** 400 / 500 / 600 / 700 / 800; nie opierać hierarchii wyłącznie na boldzie.

### Fallback

```css
font-family: InterVariable, Inter, -apple-system, BlinkMacSystemFont,
  "Segoe UI", Roboto, "Noto Sans", Arial, sans-serif;
```

---

## 5. Siatka, odstępy i promienie

### Spacing

Baza **4 px**:

`4 / 8 / 12 / 16 / 20 / 24 / 32 / 40 / 48 / 64`

### Radius

| Element | Radius |
|---|---:|
| chip / mała plakietka | 8 px |
| input / button | 12 px |
| karta | 16 px |
| duży shell / panel marketingowy | 24 px |
| pill / avatar | 999 px |

### Cień

Cienie mają być bardzo delikatne i ciepłe:

```css
box-shadow:
  0 1px 2px rgba(43, 36, 29, .06),
  0 6px 16px rgba(43, 36, 29, .08);
```

Najpierw różnica tła i border, dopiero potem cień.

---

## 6. Kontrolki i dostępność

KuKing ma być łatwy w obsłudze bez etykiety „dla seniorów”. Dlatego standard produktowy jest celowo wyższy od minimum WCAG.

### Target size

- główne buttony i pola: **min. 48 px wysokości**;
- klikalne wiersze/BottomNav: 56–60 px;
- małe checkboxy/radio mogą mieć mniejszą grafikę, ale hit-area co najmniej 44×44 px;
- nie układać kilku małych ikon ciasno obok siebie.

WCAG 2.2 AA wymaga 24×24 CSS px w określonych warunkach, ale 48 px jest bezpieczniejsze dla projektu kierowanego m.in. do osób 50+.

### Focus

- zawsze `:focus-visible`;
- 3 px niebieski pierścień `#155EEF`;
- przy czerwonych przyciskach stosować 2 px „halo” w kolorze tła między przyciskiem a pierścieniem.

### Ikona + tekst

Ważna akcja **zawsze ma tekst**:

- `Ugotowałem`
- `Komentarze`
- `Zapisz`
- `Obserwuj`
- `Dodaj`

Ikona może wspierać etykietę, ale nie powinna jej zastępować.

---

## 7. Ikonografia

- styl line icon;
- stroke **1.75–2 px**;
- zaokrąglone końce linii;
- standardowy rozmiar 20–24 px;
- aktywny stan może być brandowy;
- logo-garnek jest **specjalnym znakiem produktu**, nie zamiennikiem każdej ikony.

Najbardziej charakterystyczne użycie garnka poza logo: **„Ugotowałem”**.

---

## 8. Fotografia

Fotografie są bardzo ważne, bo to one budują emocję produktu.

### Styl

- prawdziwe domowe jedzenie;
- naturalne albo miękkie światło;
- ciepła temperatura barwowa;
- widoczne tekstury i składniki;
- jedzenie może być „trochę niedoskonałe” — nie wszystko powinno wyglądać jak reklama restauracji;
- osoby mogą się pojawiać, ale feed nie może zamienić się w lifestyle influencerów.

### Kadry

- feed desktop/mobile: 4:3 lub ok. 16:10;
- hero przepisu: 4:3;
- miniatury zeszytu: 4:3;
- awatary: 1:1;
- social: 4:5 jako domyślny format feedowy.

Nie umieszczać długiego tekstu bezpośrednio na zdjęciu jedzenia.

---

## 9. Layout desktop

### App shell

- topbar: 72–82 px;
- SideNav: 240–260 px;
- centralna kolumna feedu: ok. 720–820 px;
- prawa szyna: 340–390 px tylko wtedy, gdy ekran ma na to miejsce;
- maksymalnie **2 główne kolumny treści** — prawa szyna jest pomocnicza, nie trzeci równorzędny feed;
- duże zdjęcia zamiast wielu małych kafelków.

### Feed

Kolejność elementów wpisu:

1. autor + czas;
2. krótki tekst;
3. duże zdjęcie;
4. `Ugotowałem` / `Komentarze` / `Zapisz`.

### Prawa szyna

Najbardziej użyteczne elementy:

- Mój zeszyt;
- inspirujący ludzie;
- ewentualnie sezonowe inspiracje.

Nie wkładać tam reklam/boxów w MVP, jeśli niszczą spokój layoutu.

---

## 10. Layout mobile

Stała dolna nawigacja ma dokładnie 5 pozycji:

**Start · Szukaj · Dodaj · Moje · Profil**

`Dodaj` jest wizualnie najmocniejszą akcją.  
Topbar jest prosty: logo / tytuł + powiadomienia lub akcja kontekstowa.

**Nie robić osobnych komponentów mobile i desktop**, jeśli nie jest to konieczne — te same Blade/Livewire komponenty powinny zmieniać układ CSS-em.

---

## 11. Ruch i mikrointerakcje

- standard: 150–220 ms;
- `ease-out` dla wejścia elementu, `ease-in-out` dla zmian stanu;
- bez sprężyn/bounce w podstawowym UI;
- hover subtelny: border/tint, nie przesuwanie całej karty;
- respektować `prefers-reduced-motion: reduce`.

---

## 12. Ton tekstów w UI

Interfejs ma mówić po ludzku i konkretnie.

**Dobre:**

- „Co dziś gotujesz?”
- „Dodaj zdjęcie”
- „Napisz kilka słów”
- „Ugotowałem”
- „Zapisz w zeszycie”
- „Szkic zapisany”

**Unikać:**

- „Utwórz content”
- „Submit”
- „422 Unprocessable Entity”
- technicznych nazw stanów i błędów.

---

## 13. Materiały marki

### Avatar / PWA

- tło `#B3401F`;
- biały znak garnka;
- duży margines, znak nie dotyka krawędzi.

### Watermark na zdjęciu/video

- wariant biały;
- prawy dolny róg;
- bez dodatkowego prostokąta, jeśli zdjęcie ma wystarczający kontrast;
- jeśli tło jest jasne, można użyć delikatnego ciemnego scrimu pod logo;
- margines od krawędzi min. 16–24 px.

### Social post

- jasne/kremowe pole tekstowe;
- jedna duża fotografia;
- logo niewielkie, nie jako główny element;
- brand jako CTA lub linia/akcent, nie pełne czerwone tło każdego posta.

---

## 14. Research — uzasadnienie

### W3C / WCAG

- normalny tekst powinien osiągać co najmniej **4.5:1** kontrastu;
- WCAG 2.2 wprowadza minimum target size **24×24 CSS px** w określonych warunkach;
- starsi użytkownicy częściej potrzebują większego tekstu, możliwości skalowania do 200% i prostych układów bez utraty treści.

Źródła:

- https://www.w3.org/WAI/standards-guidelines/wcag/new-in-22/
- https://www.w3.org/WAI/test-evaluate/preliminary/
- https://www.w3.org/WAI/older-users/developing/

### Nielsen Norman Group

Badania nad starszymi użytkownikami konsekwentnie wskazują problemy z małym tekstem, małymi celami kliknięcia i zbyt ciasno rozmieszczonymi kontrolkami; dropdowny i inne precyzyjne elementy interakcji bywają trudniejsze przy pogarszającej się motoryce.

Źródła:

- https://www.nngroup.com/articles/usability-for-senior-citizens/
- https://www.nngroup.com/reports/senior-citizens-on-the-web/

### Inter

Inter jest projektowany jako font ekranowy/UI, ma wysokie x-height, variable font i szerokie wsparcie językowe.

Źródło: https://rsms.me/inter/

### Atkinson Hyperlegible Next

Braille Institute projektuje tę rodzinę z naciskiem na rozróżnialność podobnych znaków i czytelność dla osób z obniżonym wzrokiem. Wersja Next z 2025 r. ma większy zakres wag i obsługę ponad 150 języków.

Źródło: https://www.brailleinstitute.org/freefont/

---

## 15. Pliki techniczne w tej paczce

- `css/tokens.css` — czyste CSS variables;
- `css/tailwind-theme.css` — skrót tokenów do Tailwind CSS 4;
- `css/prototype.css` — dokładny CSS użyty do wizualizacji;
- `design-tokens.json` — tokeny w formacie łatwym dla agentów/skryptów;
- `html/*.html` — wersje implementacyjne korzystające ze wspólnego CSS;
- `standalone-html/*.html` — oryginalne, samodzielne wersje referencyjne;
- `mockups/*` — wszystkie wygenerowane obrazy;
- `assets/logo/*` — logo i warianty;
- `assets/photos/*` — fotografie użyte w prototypach.
