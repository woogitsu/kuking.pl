# AGENTS.md — instrukcje dla agentów AI pracujących nad Kuking.pl

> **To jest JEDYNE ŹRÓDŁO PRAWDY dla wszystkich modeli.**
> `CLAUDE.md`, `GEMINI.md`, `.github/copilot-instructions.md`, `.cursor/rules/`
> i `.windsurfrules` to cienkie wskaźniki na ten plik. Jeśli zmieniasz zasady —
> zmieniasz je TUTAJ, nigdy tylko w jednym z pliku-wskaźników.

---

## 1. Czym jest Kuking i czym NIE jest

**Kuking to polska społeczność ludzi, którzy naprawdę gotują.**
Nie jest to baza przepisów z funkcją komentowania. Centrum produktu stanowią
LUDZIE, ich codzienne gotowanie, zdjęcia, rodzinne receptury i relacje.

Główna grupa: **osoby 50+**, ale produkt NIE jest oznaczany jako „dla seniorów”.
Ma być po prostu wyjątkowo czytelny i spokojny — na tym korzystają wszyscy.

Główna akcja produktu:

```text
Co dziś ugotowałeś?  →  zdjęcie + kilka słów  →  Opublikuj
```

Najważniejszy sygnał jakości przepisu to **„Ugotowałem”** — realne wykonanie
przez inną osobę. Jest silniejszy niż jakikolwiek lajk i to on generuje
najcenniejsze powiadomienie w całym serwisie.

### Hierarchia priorytetów

```text
prostota            >  liczba funkcji
zrozumiałość        >  modne wzorce UI
realne ugotowanie   >  lajki
retencja            >  pageviews
społeczność         >  anonimowa baza treści
```

---

## 2. Zanim napiszesz choćby linijkę

Przeczytaj w tej kolejności:

1. ten plik,
2. `docs/PRODUCT.md` — czym jest produkt,
3. `docs/FEATURES.md` — co jest w MVP, a co świadomie NIE,
4. `docs/UX_50_PLUS.md` — twardy standard interfejsu,
5. `docs/ARCHITECTURE.md` — jak to jest zbudowane,
6. `docs/DATABASE.md` — model danych,
7. `docs/ROADMAP.md` — **żeby nie budować funkcji z V2 podczas prac nad MVP**,
8. **`docs/DECISIONS.md` — dziennik decyzji już podjętych.** Czytaj go, zanim
   zaproponujesz zmianę architektury, pakiet albo inny sposób pisania tekstów.
   Połowa „dobrych pomysłów" jest tam już rozstrzygnięta wraz z uzasadnieniem;
9. dokument dotyczący obszaru, który zmieniasz (`docs/` ma katalogi tematyczne).

Jeśli pracujesz nad wyglądem: `docs/design/DESIGN_SYSTEM.md`.
Jeśli nad moderacją lub prawem: `docs/legal/`.
Jeśli nad wdrożeniem: `docs/infra/`.

---

## 3. Stack (i czego NIE wolno dokładać)

| Warstwa | Wybór |
|---|---|
| Backend | Laravel 13 |
| PHP | 8.4 (minimum frameworka: 8.3) |
| UI | Blade + Livewire 4 + Alpine.js |
| CSS | Tailwind CSS 4 (konfiguracja CSS-first, `@theme`, bez `tailwind.config.js`) |
| Baza | PostgreSQL 18 (lokalnie i w CI wystarczy 16+) |
| Kolejka | Laravel database queue |
| Hosting | Railway |
| DNS / CDN / storage | Cloudflare + R2 |
| Wyszukiwarka | PostgreSQL FTS + `pg_trgm` + `unaccent` |
| Monitoring | Sentry |
| Analityka | PostHog (EU) |
| Mobile | PWA |

### Zakaz overengineeringu

Bez zmierzonej, udokumentowanej potrzeby **nie dodawaj**:

- mikroserwisów,
- Kafki / RabbitMQ,
- GraphQL,
- osobnego SPA (React/Vue/Inertia),
- Redisa „na przyszłość”,
- osobnego silnika wyszukiwania (Typesense, Meilisearch, Elastic),
- WebSocketów,
- Kubernetesa,
- CQRS / Event Sourcing,
- kolejnej biblioteki, gdy Laravel ma to w standardzie.

Jeśli uważasz, że coś z powyższej listy jest potrzebne — **najpierw otwórz
issue z pomiarem**, który to uzasadnia. Nie wprowadzaj tego w PR-ze przy okazji.

---

## 4. Struktura kodu

```text
app/
├── Domain/            # przypadki użycia i reguły domenowe
│   ├── Collections/
│   ├── Comments/
│   ├── Feed/
│   ├── Media/
│   ├── Moderation/
│   ├── Notifications/
│   ├── Posts/
│   ├── Recipes/
│   ├── Search/
│   └── Social/
├── Http/Controllers/  # cienkie: walidacja → akcja → widok
├── Jobs/              # zadania w tle
├── Models/            # Eloquent
└── Policies/          # autoryzacja
```

**Kontroler ma być cienki.** Reguła domenowa („blokada kasuje obserwowanie
w obie strony”) żyje w `app/Domain`, nie w kontrolerze i nie w widoku.
Dzięki temu da się ją przetestować bez HTTP i nie da się jej obejść,
dodając drugi endpoint.

To jest **modularny monolit**. Nie robimy z katalogów `app/Domain/*` osobnych
pakietów ani serwisów.

---

## 5. UX 50+ — twarde reguły, nie sugestie

Każdy nowy ekran MUSI spełniać:

- tekst podstawowy **minimum 18 px**, pola formularza też,
- ważne przyciski **minimum 48 px wysokości**,
- **ikona nigdy nie jest jedynym opisem ważnej akcji** — pod ikoną jest tekst,
- **żadna ważna funkcja nie wymaga** hover, swipe, long-press ani gestu od krawędzi,
- etykieta pola jest **zawsze widoczna**; placeholder nie jest etykietą,
- błędy po polsku, mówiące **co zrobić**, nie „422 Unprocessable Entity”,
- błąd **przy polu ORAZ** w podsumowaniu na górze formularza,
- **poprawnie wpisane dane nigdy nie znikają** po nieudanej walidacji (`old()`),
- akcja destrukcyjna wymaga potwierdzenia i jest odsunięta od zwykłych akcji,
- przy 200% powiększenia i przy szerokości 320 px strona pozostaje używalna,
- cel: **WCAG 2.2 AA**.

Nawigacja mobilna ma **maksymalnie 5 pozycji**:
`Start | Szukaj | Dodaj | Zeszyt | Profil`.

Paginacja to **przycisk „Pokaż więcej”**, nie infinite scroll.

### JavaScript jest ulepszeniem, nie warunkiem

Rejestracja, logowanie, publikacja wpisu, przepis, komentarz i „Ugotowałem”
**muszą działać bez JavaScriptu**. Powód nie jest ideologiczny: przy słabym
zasięgu skrypt się nie dociąga, a użytkownik zostaje z formularzem, który
nic nie robi po kliknięciu.

---

## 6. Baza danych

Każda zmiana schematu wymaga **wszystkich czterech** rzeczy:

1. migracji,
2. testu,
3. aktualizacji `docs/DATABASE.md`,
4. opisu rollbacku (albo wyjaśnienia, dlaczego rollback nie jest bezpieczny).

Zasady modelu:

- UUID dla encji publicznych, `timestamptz` dla czasu,
- **prawdziwe klucze obce i prawdziwe CHECK-i w bazie** — walidacja w PHP
  jest dodatkiem, nie zamiennikiem,
- soft delete tam, gdzie pomaga odzyskiwaniu i moderacji,
- JSONB tylko dla danych półstrukturalnych,
- wersje przepisów (`recipe_versions`) od pierwszego dnia.

**Nigdy nie dodawaj `UNIQUE (user_id, recipe_id)` do `cooked_events`.**
Ta sama osoba może gotować ten sam przepis dziesiątki razy przez lata
i każde takie wykonanie jest osobnym, wartościowym wydarzeniem.

**Nigdy nie wykonuj destrukcyjnych operacji na produkcyjnej bazie
bez jawnej zgody właściciela.**

---

## 7. Bezpieczeństwo

Nigdy:

- `.env` w repozytorium,
- tokeny, hasła ani PII w logach,
- wyłączanie CSRF,
- zaufanie do MIME, rozszerzenia albo nazwy pliku od klienta,
- renderowanie surowego HTML-a użytkownika,
- hardcoded hasło administratora,
- `status` ani `role` użytkownika w `$fillable` — zmiana stanu konta jest
  zawsze jawną, nazwaną metodą (`suspend()`, `ban()`, `markForDeletion()`).

Każdy endpoint przechodzi przez pięć pytań:
**auth → authorization → validation → rate limit → audit.**

**UUID w adresie NIE JEST autoryzacją.** Każde wejście na cudzą treść
przechodzi przez Policy.

Limity zapytań są w `config/kuking.php`, nie rozsiane po trasach.

### Pipeline zdjęć

```text
przyjęcie pliku
→ rozmiar w bajtach
→ czy to naprawdę obraz (magic bytes)
→ limit megapikseli (obrona przed „decompression bomb”)
→ zapis pod WŁASNYM kluczem (nazwa od użytkownika nie trafia do ścieżki)
→ zadanie w tle: dekodowanie i zapis od nowa (to zdejmuje EXIF/GPS)
→ warianty thumb/feed/large
→ status `ready`
```

Widoki **nie pokazują zdjęcia w stanie innym niż `ready`**.
Nigdy nie serwujemy pliku, który przyszedł od użytkownika, bez re-enkodowania —
w EXIF-ie siedzi dokładna lokalizacja kuchni, w której zrobiono zdjęcie.

---

## 8. Feed

MVP: obserwowani, **chronologicznie**.

```sql
WHERE author_id IN (...) ORDER BY published_at DESC, id DESC
```

Paginacja kursorowa. Bez fanout-on-write. **Nie projektuj skomplikowanego
rankingu bez danych** — algorytmiczny feed natychmiast dzieli użytkowników
na „widzianych” i „niewidzianych” i wyłącza publikowanie u większości.

Gdy feed obserwowanych jest pusty, pokazujemy „Świeżo z Kuking” i propozycje
osób. Pusty ekran u nowego użytkownika to koniec korzystania z serwisu.

---

## 9. AI w produkcie

AI ma **pomagać użytkownikowi**:

- OCR starych zeszytów (V2),
- porządkowanie składników,
- skalowanie porcji,
- zamienniki,
- tagowanie,
- moderacja pomocnicza (flagowanie, nigdy samodzielny ban).

AI **nie generuje masowo publicznych przepisów pod SEO**. To skasowałoby
jedyny realny wyróżnik Kuking — autentyczność — i jest nieodwracalne.

---

## 10. Praca z repozytorium

### Zanim otworzysz Pull Request

```bash
./scripts/check.sh       # formatowanie + składnia + testy + migracje + assety
```

**GitHub Actions są włączone** (D-010): repozytorium żyje w organizacji
`woogitsu`, która ma własną pulę 2 000 minut miesięcznie. CI chodzi na
`push` do `main` i `staging` oraz na każdym Pull Requeście do tych gałęzi.

Kontrola lokalna **zostaje mimo to** — jest szybsza i łapie błąd, zanim ten
zje minuty z puli. Zainstaluj hook raz:

```bash
./scripts/install-hooks.sh
```

Szczegóły i plan awaryjny: `docs/infra/CI_BEZ_ACTIONS.md`.

Pojedyncze kroki, gdy chcesz coś sprawdzić osobno:

```bash
vendor/bin/pint          # formatowanie
php artisan test         # testy (wymagają PostgreSQL, patrz niżej)
npm run build            # assety się budują
```

Testy chodzą na **PostgreSQL**, nie na SQLite — schemat używa indeksów
częściowych, `num_nonnulls()`, `gen_random_uuid()`, `pg_trgm` i `unaccent`.
Test na SQLite przechodziłby, nic nie sprawdzając.

```bash
createdb kuking_test     # jednorazowo
```

**Jeśli pracujesz w worktree gita z dowiązanym `vendor`** — dodaj jawną ścieżkę
bazową, inaczej Laravel załaduje trasy i klasy z głównego katalogu, a testy
będą fałszywie zielone:

```bash
APP_BASE_PATH=$(pwd) php artisan test
```

### Pull Request zawiera

- co i dlaczego (nie „poprawki”),
- testy,
- migracje, jeśli dotyczy,
- ryzyka,
- plan rollbacku,
- aktualizację `docs/`,
- opis zmiany w UI albo zrzut ekranu, jeśli dotyczy interfejsu.

### Bugfix zawsze zawiera test regresyjny

Poprawka bez testu, który by ten błąd złapał, nie jest poprawką — jest
zaproszeniem do jego powtórzenia.

### Issues

Praca idzie **po kolei, z issues**. Etykiety priorytetu: `P0` → `P1` → `P2`,
kolejność merytoryczna z `docs/ROADMAP.md`. Nowe pomysły zapisuj jako issue
z opisem, uzasadnieniem i kryteriami akceptacji — nie dokładaj ich
do niepowiązanego PR-a.

---

## 11. Język

- **Interfejs, komunikaty błędów, dokumentacja i komentarze: po polsku.**
- Kod (nazwy klas, metod, zmiennych): po angielsku, zgodnie z konwencją Laravela.
- Adresy URL widoczne dla użytkownika: po polsku (`/przepisy`, `/zeszyt`,
  `/ustawienia`), z wyjątkiem `/home`, `/login`, `/register`.
- Nazwy zdarzeń analitycznych: `snake_case` po angielsku.

**Każdy tekst widoczny dla użytkownika piszesz według `docs/brand/COPY_STYLE.md`.**
To jest dokument wiążący, nie inspiracja — ma gotowe teksty do wklejenia
dla większości ekranów.

W skrócie:

- mówimy „Ugotowałem”, „Zapisuję”, „Zeszyt”, „Napisz kilka słów”;
- nie mówimy „content”, „explore”, „engage”, „creator”, „tapnij”;
- **`kuKING` to nazwa mieszkańca serwisu, nie komplement.** Wolno „Zostań
  kuKINGiem”, nie wolno „Jesteś prawdziwym kuKINGiem!” ani „Top kuKINGi tygodnia”;
- gra słowem `kuKING` **maksymalnie raz na ekran** i **nigdy** w komunikacie
  błędu, wiadomości moderacyjnej ani tekście prawnym;
- zero emoji w tekstach interfejsu, najwyżej jeden wykrzyknik na ekran;
- komunikat błędu ma powiedzieć, **co zrobić**;
- unikamy konstrukcji zakładających rodzaj, gdzie da się inaczej
  („Co dziś gotujesz?” zamiast form z „-łeś/-łaś”).

Pełny słownik i lista słów zakazanych: `docs/brand/BRAND_EXTENDED.md`.

---

## 12. Czego świadomie NIE budujemy teraz

Poza MVP (patrz `docs/FEATURES.md` i `docs/ROADMAP.md`):
wiadomości prywatne, natywne aplikacje, planer posiłków, lista zakupów,
spiżarnia, OCR, generator przepisów AI, rozbudowana gamifikacja, marketplace,
transmisje live, wypłaty dla twórców.

Anty-wzorce, których **nie wprowadzamy nigdy**:
streaki i punkty za liczbę postów, publiczne rankingi użytkowników,
algorytmiczny feed, masowy import cudzych przepisów, sztuczne konta,
liczniki lajków wyeksponowane w interfejsie.
