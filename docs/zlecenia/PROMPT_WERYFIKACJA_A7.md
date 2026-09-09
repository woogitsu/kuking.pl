# Zlecenie A7 — weryfikacja przeciwna i pomiary, których w Kuking nie zrobiono

**Dla:** modelu z dostępem do repozytorium `woogitsu/kuking.pl`, pracującego
niezależnie od agenta, który pisał kod.
**Data zlecenia:** 9 września 2026 · **Punkt odniesienia:** `main` po scaleniu
`aee5c35`.

---

## 0. Po co to zlecenie i dlaczego akurat teraz

9 września w `main` weszło **trzynaście scaleń w jeden dzień** (plus jedno
otwarte, #184), w tym cztery poprawki bezpieczeństwa i prywatności, z których
każda ruszała kod wrażliwy.

**Sześć zmian dotyka kodu — na nich skup uwagę:**

| commit | PR | co zmieniło |
|---|---|---|
| `dd37872` | #175 | webhook błędów przestał wysyłać treść wyjątku (A6-01) |
| `7af3879` | #176 | `UsunGps` — **ręczna manipulacja bajtami** PNG/WebP/JPEG (A6-02) |
| `e572743` | #177 | 2FA — `lockForUpdate()` w transakcji, zużycie TOTP i kodów (A6-03) |
| `2e1b882` | #178 | retencja — `subMonthsNoOverflow` w trzech klasach (A6-04) |
| `aee5c35` | #162 | CSS: sześć poprawek układu przy powiększonej czcionce |
| `940f6db` | #163 | strona główna — przekaz nad zgięciem na laptopie (CSS + Blade) |

**Jedna zmiana dotyka wdrożenia i jest najgroźniejsza z całej listy:**

| commit | PR | co zmieniło |
|---|---|---|
| `9ec662f` | #174 | **`db:seed --force` w pre-deploy**, czyli zapis do produkcyjnej bazy |

**Siedem zmian to dokumenty, konfiguracja CI i same testy.** Nie pomijaj ich
w A7-2: dokument, który kłamie o kodzie, jest w tym repozytorium traktowany
jak usterka, a test dopisany bez łatki jest dokładnie tym rodzajem dowodu,
który A6 kazał sprawdzać dwa razy.

| commit | PR | co zmieniło |
|---|---|---|
| `bb90ce0` | #171 | CI — trzy runnery dzieliły jedną maszynę, naprawa `pcntl` |
| `a338522` | #172 | dokumenty o funkcjach oznaczonych jako przyszłe (G16) |
| `6e8dea9` | #173 | dokumenty o zwolnieniu z Sekcji 3 DSA (G17) |
| `e941e44` | #179 | dwa ostatnie rozjazdy dokumentów z kodem + **to zlecenie** |
| `d290deb` | #182 | dokumenty produktowe kontra gotowy ekran „Komuś wyszło” (#17) |
| `946444e` | #183 | **sam test**, bez łatki: endpoint uploadu Livewire (#111) |
| `03fb152` | #184 | `FEATURES.md` nie wymieniało wspomnień (reszta G16) — **PR otwarty, sprawdź, czy scalony** |

`946444e` zasługuje na szczególną nieufność: to PR, który **nie zmienia ani
linii kodu produkcyjnego**. Jego autor twierdzi, że łatka na issue #111 już
była w `main`, wniesiona wcześniejszym commitem `680ef24`, do którego nie
podpięto żadnego issue. Sprawdź jedno i drugie: czy łatka tam jest, i czy
dopisany test naprawdę byłby czerwony bez niej.

To nie jest odosobniony przypadek i dlatego jest ważny. Tego dnia cztery
kolejne issues sprawdzone przez agentów okazały się **już zrobione**, bez
żadnego śladu w liście zadań. Jeżeli w trakcie A7-2 natkniesz się na kolejne
takie, wypisz je — pomiar „ile z tego backlogu jest nieprawdziwe” jest wart
tyle samo co znaleziona usterka.

Wszystkie mają testy napisane **przez tego samego agenta, który pisał łatki**.
To jest dokładnie ta konfiguracja, w której poprzedni audyt (A6) znalazł cztery
prawdziwe usterki: test zielony, bo bada założenie autora, a nie zachowanie
systemu. Najostrzejszy przykład z tamtego raportu — plik
`BladTrafiaNaWebhookBezDanychOsobowychTest` przez trzy dni świecił na zielono,
rzucając wyjątek z komunikatem, który sam napisał.

**Zlecenie nie prosi o kolejny przegląd całego repozytorium.** Prosi o cztery
pomiary, których nie ma, i o jedno adwersarialne czytanie czternastu diffów
(sześć to kod, jeden to wdrożenie, siedem to dokumenty, CI i same testy).

---

## 1. Czego NIE robić

Pozycje zamknięte i zweryfikowane. Zgłoszenie ich ponownie to strata Twojego
budżetu i czasu właściciela.

- **G01, G04, G05, G06, G07, G08, G10, G11, G12, G13, G15, G16, G17** — zamknięte,
  każde z testem. Mapę stanu ma `docs/zlecenia/2026-09-09-audyt-a6.md` §5.
- **A6-01 … A6-05** — naprawione dziś. Weryfikuj **poprawki**, nie zgłaszaj
  usterek na nowo.
- **A6-06 i A6-07** (dowód konfiguracji produkcji, odtworzenie kopii) — bramki
  właściciela. Nie masz do nich dostępu i nie da się ich rozstrzygnąć z repo.
- **Kwalifikacja prawna, decyzje produktowe, wybór dostawców** — nie Twoje.
- **Nie proponuj Redisa, mikroserwisów, SPA ani osobnej wyszukiwarki.**
  `AGENTS.md` zakazuje ich wprost i to nie jest do negocjacji.
- **Nie dotykaj produkcji.** Żadnych operacji na `kuking.pl`, R2 ani Railway.

---

## 2. Cztery zadania, w kolejności wartości

### A7-1. Mutation testing całego zestawu — NAJWYŻSZY PRIORYTET

W repozytorium jest **1837 testów i 57 083 asercje**. Nikt nie wie, ile z nich
cokolwiek chroni. Audyt A6 zmutował **jedną** funkcję (`DostepDoZdjecia::moze()`
→ `return true`) i ta jedna mutacja obnażyła test, który przeżywał otwarcie
wszystkich zdjęć wszystkim.

Uruchom prawdziwe narzędzie (Infection albo równoważne) na PostgreSQL, nie na
SQLite. Jeśli pełny przebieg nie mieści się w budżecie, ogranicz **zakres**, nie
rzetelność, i priorytetyzuj w tej kolejności:

1. `app/Domain/Media/UsunGps.php` — kod bajtowy, napisany dziś, najłatwiej
   pomylić się cicho;
2. `app/Domain/Security/` — 2FA, zmienione dziś;
3. `app/Policies/` i `app/Domain/Media/DostepDoZdjecia.php` — cała kontrola
   dostępu do zdjęć;
4. `app/Domain/Compliance/` — retencja, zmieniona dziś;
5. `app/Logging/WebhookBleduHandler.php`.

**Czego oczekuję w wyniku:** lista mutantów, które PRZEŻYŁY, z oceną, które
z nich są prawdziwą dziurą w teście, a które równoważne semantycznie. Sam
mutation score bez tej oceny jest bezużyteczny.

### A7-2. Adwersarialne czytanie czternastu dzisiejszych diffów

Dla każdego commita z §0 odpowiedz na jedno pytanie: **co musiałoby
być prawdą, żeby ta łatka była zła, i czy to jest prawdą?**

Miejsca, w których sam bym się siebie bał, wypisane uczciwie:

- **`UsunGps` (`7af3879`)** — przejście po chunkach PNG i RIFF napisane ręcznie.
  Interesuje mnie: plik z chunkiem `eXIf` **po** `IDAT`; WebP z chunkiem
  o nieparzystej długości; JPEG z APP1 innym niż EXIF przed właściwym;
  plik obcięty w środku chunku; chunk deklarujący długość większą niż plik;
  PNG z `eXIf` **oraz** `zTXt` z profilem EXIF. Sprawdź też, czy poprawka nie
  psuje plików, których wcześniej nie ruszała.
- **2FA (`e572743`)** — czy `lockForUpdate()` w `DB::transaction` naprawdę
  szereguje dwa RÓWNOLEGŁE żądania HTTP, nie tylko dwa obiekty w jednym
  procesie? Test w repo odtwarza przeplot w jednym procesie i mówi to wprost.
  **Zrób pomiar z dwoma prawdziwymi połączeniami.** Sprawdź też, czy blokada
  trzymana przez pętlę `Hash::check` nie tworzy zagłodzenia przy limiterze prób.
- **`db:seed` w pre-deploy (`9ec662f`)** — co się stanie, gdy seeder padnie
  w połowie? Czy deploy się zatrzyma i czy baza zostanie w stanie spójnym?
  Co przy dwóch replikach? Czy `TrescZalazkowaSeeder` jest idempotentny także
  wtedy, gdy prawdziwy człowiek zajął nazwę **między** dwoma przebiegami?
- **CSS (`aee5c35`)** — `overflow-wrap: anywhere` weszło na `.btn` globalnie.
  Czy gdzieś łamie napis, który musi zostać w całości (np. kod, adres e-mail)?
- **Retencja (`2e1b882`)** — czy `subMonthsNoOverflow` daje poprawny wynik
  w strefie `Europe/Warsaw` przy zmianie czasu, nie tylko w UTC?

### A7-3. Pomiar wydajności zapytań na reprezentatywnych danych

Audyt A6 świadomie odmówił twierdzeń o wydajności bez pomiaru i miał rację.
Teraz ten pomiar jest potrzebny, bo od niego zależy jedno otwarte issue.

Zbuduj bazę o realistycznej skali (**10 000 kont, ~40 000 przepisów, ~80 000
wpisów, ~250 000 zdjęć**) i zmierz `EXPLAIN (ANALYZE, BUFFERS)` dla:

- wyszukiwarki przepisów — **issue #116** twierdzi, że `OR` w `WHERE` blokuje
  indeks trigramowy. Potwierdź albo obal, planem zapytania;
- feedu obserwowanych i `/odkryj`;
- trasy `/zdjecia/{media}/{wariant}` — D-020 mówi wprost, że każde żądanie
  zdjęcia to teraz żądanie do Laravela i kilka zapytań o rodziców, i że
  **dopiero pomiar z produkcji ma rozstrzygnąć, czy potrzebny jest cache
  decyzji**. Podaj liczbę zapytań i czas na jedną stronę feedu.

**Nie proponuj rozwiązania bez planu zapytania.** Wniosek „dodaj indeks" bez
`EXPLAIN` jest w tym repozytorium bezwartościowy.

### A7-4. Dostępność, której automat w repo nie mierzy

`scripts/dostepnosc.mjs` robi axe-core na 23 ekranach × 4 warianty plus pomiar
przepełnienia przy 320/360/414/768/1280 px w trzech skalach tekstu, w tym przy
podwojonej czcionce przeglądarki przez CDP. Zero naruszeń.

Czego ten skrypt **nie** mierzy i co jest do zrobienia:

- **natywny zoom przeglądarki (Ctrl +) 200% i 400%** — to inny mechanizm niż
  powiększenie czcionki: zoom skaluje piksele CSS razem z tekstem. WCAG 2.2 AA
  kryterium 1.4.10 mówi o 400%;
- **czytnik ekranu** — smoke NVDA albo VoiceOver na trzech drogach: rejestracja,
  dodanie zdjęcia, odwołanie od decyzji moderacyjnej;
- **Windows High Contrast**;
- **pełny obieg ważnych formularzy z wyłączonym JavaScriptem**, w świeżej sesji.

---

## 3. Zasady, których trzymał się poprzedni audyt i które chcę zachować

Raport A6 był dobry nie dlatego, że znalazł dużo, tylko dlatego, że **oddzielał
zmierzone od wywnioskowanego i wycofywał własne błędne zarzuty**. Trzy z nich
wycofał wprost. To jest wzorzec.

1. **Każde znalezisko ma etykietę: ZMIERZONE / WYWNIOSKOWANE / NIEROZSTRZYGNIĘTE.**
2. **Każde ZMIERZONE ma sposób odtworzenia** — komenda, którą da się uruchomić.
3. **Osobna sekcja „czego nie sprawdziłem"** przy każdym znalezisku.
4. **Wycofuj własne zarzuty**, gdy okażą się nietrafione, i pisz to wprost.
5. **Nie licz punktów.** „6 z 7 sond zielonych" nie jest wynikiem
   bezpieczeństwa — tak napisał sam A6 o sobie i miał rację.
6. **Skutek dla człowieka**, nie dla systemu: nie „naruszenie poufności", tylko
   „adres kuchni tej osoby leży w pliku, który dostaje z powrotem w eksporcie".
7. **Po polsku.** Cały projekt, łącznie z komentarzami w kodzie, jest po polsku.

---

## 4. Kontekst, bez którego łatwo pomylić się w tę samą stronę co ja

Dwa razy dziś pomyliłem się identycznie i warto, żebyś tego uniknął.

**`grep` trafia w komentarz, nie w kod.** Sprawdzając, czy komenda `kuking:wac`
istnieje, `grep -r 'kuking:wac' app/` trafił najpierw w komentarz w innym pliku.
Raz uznałem funkcję za istniejącą na tej podstawie, raz — po „korekcie" — za
nieistniejącą, choć była. Rozstrzyga `php artisan list`, `routes/web.php`
i uruchomienie, nie wyszukiwanie tekstu.

**„Klasa istnieje" to nie „człowiek może tego użyć".** Kryterium w tym projekcie
brzmi: jest trasa albo komenda, jest test, i da się przejść ścieżkę.

**Metoda pomiaru bywa źródłem fałszywego znaleziska.** A6 zgłosił przepełnienie
układu przy 1280 px, mierząc przez `document.documentElement.style.fontSize`.
Ta droga podwaja tekst, ale **nie przesuwa progów media query**, bo `rem`
w media query liczy się od początkowego rozmiaru pisma przeglądarki. Zmierzone:

| metoda | korzeń | `(min-width: 64rem)` przy 1280 px |
|---|---|---|
| bez zmian | 16 px | `true` |
| `style.fontSize = '32px'` | 32 px | `true` ← nieprawda |
| CDP `Page.setFontSizes` | 32 px | `false` ← tak jest naprawdę |

Po przejściu na CDP przy 1280 px nie ma ani jednego przepełnienia. Tamto
znalezisko było artefaktem metody.

---

## 5. Środowisko

- Laravel 13 · PHP 8.4 · Blade + Livewire 4 · Tailwind 4 · **PostgreSQL 18**
- **Testy chodzą na PostgreSQL, nigdy na SQLite** — `AGENTS.md` zakazuje.
- Przed każdym PR-em: `vendor/bin/pint` i `php artisan test`.
- Przeglądarka: Playwright + Chromium; `scripts/dostepnosc.mjs` sam podnosi
  `php artisan serve` i sam go gasi.
- Osobna baza do własnych prób. **Nigdy `migrate:fresh` bez jawnego
  `DB_DATABASE`.**

---

## 6. Jak oddać wynik

**Preferowane — gałąź i pull request w repozytorium:**

- gałąź `audit/a7-YYYYMMDD`,
- raport jako `docs/zlecenia/YYYY-MM-DD-audyt-a7.md`,
- dowody (logi, JSON-y, plany zapytań, skrypty odtwarzające) w
  `docs/zlecenia/dowody-a7/`,
- PR **bez** poprawek aplikacji: audyt ma mierzyć, nie naprawiać. Jeśli masz
  gotową łatkę, dołącz ją jako diff w raporcie, osobno.

**Alternatywnie — zip:** ten sam układ katalogów, plus `SHA256SUMS`.

**Czego nie umieszczaj nigdzie:** sekretów, adresu webhooka, zawartości paneli
Railway i Cloudflare, danych prawdziwych użytkowników. Dane testowe mają być
syntetyczne. Poprzednia paczka dowodowa trzymała się tego wzorowo.

---

## 7. Gdyby budżet starczył tylko na jedno

**Zrób A7-1 na `app/Domain/Media/UsunGps.php`.**

To jest kod, który ręcznie chodzi po bajtach czterech formatów kontenerowych,
powstał dzisiaj, ma dziewięć testów napisanych przez jego autora, a jego
zadaniem jest usunąć z pliku adres domu człowieka. Jeśli którakolwiek z jego
gałęzi jest niesprawdzona, nikt się o tym nie dowie, dopóki ktoś nie porówna
zapisanego pliku niezależnym dekoderem — a to jest dokładnie to, co poprzednim
razem znalazło usterkę.
