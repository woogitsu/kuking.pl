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
najcenniejsze powiadomienie w całym serwisie. **„Ugotowałem” ZAWSZE powiadamia
autora przepisu.**

### „Ugotowałem” — trzy granice powiadomienia ([szczegóły](docs/agenci/UGOTOWALEM_POWIADAMIA_AUTORA.md))

- „Zawsze” dotyczy KAŻDEJ drogi, którą powstaje wykonanie: formularza, akcji domenowej i danych demonstracyjnych (`DemoSeeder` idzie przez `RecordCookedEvent`, nie przez gołe `CookedEvent::create()`).
- Powiadomienia nie ma tylko gdy: autor ugotował własny przepis; konto autora jest zamknięte (`banned`, `pending_delete`, `erased` — zawieszony DOSTAJE); jest blokada między autorem a kucharzem (wtedy nie powstaje samo wykonanie). Pilnuje `UgotowalemZawszePowiadamiaAutoraTest`.
- Żadne ustawienie użytkownika nie wycisza powiadomień w serwisie; wyjątek (D-303) to wyłącznie kanały zewnętrzne (Web Push, cisza nocna, dzienny limit).

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

1. **Zawsze** przeczytaj ten plik w całości.
2. Potem TYLKO dokumenty dotyczące obszaru zmiany — mapa obszar → lektura: [szczegóły](docs/agenci/LEKTURY_PRZED_PRACA.md). Interfejs (widok, formularz, CSS) → zawsze [`docs/UX_50_PLUS.md`](docs/UX_50_PLUS.md). Marka i wygląd: najpierw `docs/brand/KONSTYTUCJA_MARKI.md`; prawo i moderacja: `docs/legal/`; wdrożenie: `docs/infra/`.
3. **Duże pliki** (`docs/DATABASE.md`, `CHANGELOG.md`, `docs/DECISIONS.md`, `docs/PULAPKI_TESTOW.md`) — nie czytaj w całości: przeszukaj (grep) i czytaj sekcję swojego obszaru. `docs/DATABASE.md` to indeks modelu danych, a opis tabel leży w `docs/baza/` (szukaj tabeli w indeksie). Starsze wersje `CHANGELOG.md` leżą w `docs/changelog/` — czytaj tylko górę `CHANGELOG.md`.
4. **Decyzje:** zanim zaproponujesz zmianę architektury, pakiet albo sposób pisania tekstów, przeszukaj `docs/DECISIONS.md` (indeks) i `docs/decyzje/`. Duża, osobna decyzja = nowy plik `docs/decyzje/D-NNN-krotki-slug.md` (numer: `php scripts/decyzje-indeks.php --nastepny`), potem `php scripts/decyzje-indeks.php`; nigdy nie dopisuj treści decyzji do `docs/DECISIONS.md` (pilnuje `DziennikDecyzjiZgodnyZIndeksemTest`). **Drobna decyzja właściciela = nowy wiersz tabeli w aktualnym zbiorczym pliku (obecnie D-333), bez nowego numeru D**; gdy plik przekroczy ~60 KB, zamyka się go i otwiera następny zbiorczy (numer z `--nastepny`). **Duża** = zmienia zasadę z AGENTS.md, architekturę, stos, model uprawnień albo schemat wielu obszarów; reszta (zakres jednej funkcji, brzmienie, próg, kolejność) to drobna.
5. **V2 wolno budować od D-282 (26 września 2026)**, po kolei P0 → P1 → P2 (§10); sekcja „V2” w `docs/FEATURES.md`. Lista „Nie wcześnie” w tym samym pliku pozostaje zakazana bez zmian.

---

## 3. Stack (i czego NIE wolno dokładać)

| Warstwa | Wybór | Gdzie to sprawdzić |
|---|---|---|
| Backend | Laravel 13 | `composer.json`: `laravel/framework` |
| PHP | 8.4 (minimum frameworka: 8.3) | `composer.json`: `php` |
| UI | Blade + Alpine.js; Livewire 4 w kreatorze przepisu | `composer.json`: `livewire/livewire` |
| CSS | Tailwind CSS 4 (konfiguracja CSS-first, `@theme`, bez `tailwind.config.js`) | `package.json`: `tailwindcss`, `@tailwindcss/vite` |
| Baza | PostgreSQL — wymagane **18+** lokalnie, w CI i na produkcji (D-227) | usługa zewnętrzna |
| Kolejka | Laravel database queue | `composer.json`: `laravel/framework` |
| Hosting | Railway | usługa zewnętrzna |
| DNS / CDN / storage | Cloudflare + R2 | usługa zewnętrzna · `composer.json`: `league/flysystem-aws-s3-v3` |
| Wyszukiwarka | PostgreSQL: `pg_trgm` (`word_similarity`, próg 0,5) + `unaccent` — dopasowanie trigramowe (D-004, D-046) | w repozytorium: `database/migrations/0001_01_01_000000_enable_postgres_extensions.php`, `app/Domain/Search/SearchQuery.php` |
| Monitoring | dziennik serwera + kanał `blad_webhook` na Slack/Discord (D-041) | w repozytorium: `app/Logging/WebhookBleduHandler.php` |
| Analityka | własna, serwerowa (`App\Domain\Analytics\*`) + Cloudflare Web Analytics (bez ciasteczek — D-092) | w repozytorium: `app/Domain/Analytics`, `app/Support/AnalitykaCloudflare.php` · usługa zewnętrzna |
| Mobile | PWA | w repozytorium: `public/manifest.webmanifest` |
| API dla aplikacji mobilnej | prefiks `api/v1`, Laravel Sanctum (tokeny osobistego dostępu, bez sesji), domyślnie wyłączone flagą `KUKING_API_ENABLED` (D-270) | `composer.json`: `laravel/sanctum` · w repozytorium: `routes/api.php` |

Feed, wyszukiwanie, komentarze i „Ugotowałem” to kontrolery i widoki Blade (JavaScript jako ulepszenie); Livewire tylko w kreatorze przepisu. `wire:poll` na często odwiedzanych ekranach wymaga osobnej decyzji i pomiaru kosztu żądań ([szczegóły](docs/agenci/STACK_TABELA_PRAWDY.md)).

### Tabela stacku opisuje STAN, nie zamiar (D-104) — [szczegóły](docs/agenci/STACK_TABELA_PRAWDY.md)

- Do tabeli wchodzi rzecz wdrożona; zamiar żyje w `docs/ROADMAP.md` i `docs/DECISIONS.md`.
- Trzecia kolumna ma jeden z czterech kształtów: `composer.json`: pakiet · `package.json`: pakiet · w repozytorium: ścieżka · usługa zewnętrzna (kilka lokalizatorów rozdziela `·`). Sprawdza to `tests/Feature/TabelaStackuMowiPrawdeTest.php`.
- „usługa zewnętrzna” nie jest wytrychem (nie wpisuj tego przy pakiecie PHP).

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

**Kontroler ma być cienki.** Reguła domenowa („blokada kasuje obserwowanie
w obie strony”) żyje w `app/Domain`, nie w kontrolerze i nie w widoku.
Dzięki temu da się ją przetestować bez HTTP i nie da się jej obejść,
dodając drugi endpoint.

To jest **modularny monolit**. Nie robimy z katalogów `app/Domain/*` osobnych
pakietów ani serwisów.

---

Struktura katalogów `app/` (`Domain/`, `Http/Controllers/`, `Jobs/`, `Models/`, `Policies/`): [szczegóły](docs/agenci/STRUKTURA_KODU.md).

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

Te dwie reguły (18 px i ikona z opisem) mają nazwane wyjątki, oba na świadomą decyzję właściciela — nie ma furtki ogólnej, a kolejny wyjątek wymaga nowej decyzji właściciela i dopisania go tutaj:

- **D-051** — metryczka wersji i przełącznik motywu w stopce (`docs/DECISIONS.md`, D-051);
- **D-262** — tylko reguła 18 px i tylko panel moderacji: napisy pomocnicze 15–16 px w czterech selektorach — `.side-nav-moderacja-naglowek`, `.sygnal-podglad-cytat`, `.tabela-kont .drobne` i `.stan-konta` (przy rozjeździe wiążąca jest nazwa selektora, nie numer linii w CSS; pełny opis z plikami i liniami: [szczegóły](docs/agenci/UX_WYJATKI_I_JAVASCRIPT.md)). Przyciski i inne cele dotyku w panelu mają nadal ≥ 48 px.

**Reguła „ikona nigdy nie jest jedynym opisem ważnej akcji” ma jeden nazwany wyjątek: menu „więcej” na karcie wpisu** (`components/post-card.blade.php`, trzy kropki bez widocznego napisu) — wyłącznie to jedno menu, nie pasek akcji, nawigacja, zamykanie ani akcje moderacyjne. `aria-label` („Więcej przy tym wpisie”) zostaje, cel dotknięcia dalej 48 × 48 px. Powód, granice i test (`KartaWpisuTest::test_menu_karty_to_same_kropki_ale_czytnik_ekranu_nie_traci_nic`): [szczegóły](docs/agenci/UX_WYJATKI_I_JAVASCRIPT.md). Rozszerzenie wyjątku wymaga decyzji właściciela i wpisu w `docs/DECISIONS.md`, a nie dopisania klasy CSS.

Nawigacja mobilna ma **maksymalnie 5 pozycji**:
`Start | Szukaj | Dodaj | Moje | Profil`.

Paginacja to **przycisk „Pokaż więcej”**, nie infinite scroll.

### JavaScript jest wymagany tam, gdzie chroni serwis — i nigdzie nie zostawia martwego przycisku

1. Newralgiczne formularze (rejestracja i logowanie za Turnstile, D-050) mogą wymagać JavaScriptu; gdzie indziej JavaScript jest mile widziany i nie trzeba go dublować wersją bez skryptu.
2. **Nigdy martwego przycisku:** jeśli bez skryptu coś nie zadziała, człowiek widzi `<noscript>` z konkretną instrukcją po polsku, CO ZROBIĆ (nie ogólnik „wymagany JavaScript”).
3. Awaria weryfikacji tokenu (nasza albo Cloudflare) nie zamyka drzwi — formularz przechodzi (D-050).

Uzasadnienie zmiany zasady i pełne brzmienie (D-053): [szczegóły](docs/agenci/UX_WYJATKI_I_JAVASCRIPT.md).

---

## 6. Baza danych

Każda zmiana schematu wymaga **wszystkich czterech** rzeczy:

1. migracji,
2. testu,
3. opisu w pliku obszaru w `docs/baza/` i — dla nowej tabeli — wiersza w indeksie `docs/DATABASE.md`,
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

Procedury ([szczegóły](docs/agenci/BAZA_MIGRACJE_ROLLBACK_WERSJA.md)): migracja chodzi z `lock_timeout = 5s` (`LimitBlokadMigracji`); indeks na istniejącej tabeli to `CREATE INDEX CONCURRENTLY IF NOT EXISTS` z `$withinTransaction = false`; CHECK i FK na istniejącej tabeli to `NOT VALID` + osobno `VALIDATE CONSTRAINT`; unikaj przepisania gorących tabel; nowa tabela tych reguł nie potrzebuje (strażnik: `NoweMigracjeTrzymajaSieParagrafu6Test`).

### `down()` przy wartościach semantycznych ODMAWIA, zamiast zgadywać (D-088)

> **`down()` nie ma prawa przywracać stanu groźnego ani zmieniać znaczenia
> decyzji człowieka.** Przy wartościach semantycznych — zgoda, zakres
> usunięcia danych, widoczność, prywatność zeszytu — rollback ma **odmówić**
> z komunikatem mówiącym, co zrobić ręcznie. Zgadywanie cichą wartością
> domyślną jest najgorszą z opcji, bo nie zostawia śladu błędu.

Rollback jest WĄSKI (blokuje się tylko, gdy w bazie jest wartość, której `up()` nie odtworzy; test odmowy **i** kontrola dodatnia); komentarz „najpierw kopia kolumny” NIE jest zabezpieczeniem — jest nim `throw` w `down()`. Preferencja WYGLĄDU nie jest wartością semantyczną. Szczegóły i wzorce: [szczegóły](docs/agenci/BAZA_MIGRACJE_ROLLBACK_WERSJA.md).

**Nigdy nie wykonuj destrukcyjnych operacji na produkcyjnej bazie
bez jawnej zgody właściciela.**

### Numer wersji — reguła (issue #1932, D-318)

- DUŻY numer (`kuking.wersja.etykieta` w `config/kuking.php`, np. „Alfa 0.68”) podbijasz RĘCZNIE w PR-ze, razem z wpisem na górze `CHANGELOG.md` (`PodbicieWersjiWymagaWpisuWChangelogTest`) — przy zmianie, którą człowiek ZOBACZY; testy, refaktor i dokumentacja go nie ruszają.
- `CHANGELOG.md` trzyma tylko sekcję „Nieopublikowane” i kilka najnowszych wydanych wersji; przy podbiciu numeru wersji, gdy plik bez „Nieopublikowane” przekroczy ok. 40 KB, najstarsze wydane sekcje przenosi się bez zmian do `docs/changelog/archiwum-alfa-<od>-<do>.md` (do ok. 60 KB na plik) i dopisuje link w „Starsze wersje”; testy numeracji i duplikatów czytają całość przez `Tests\Support\PelnyChangelog`.
- KOŃCÓWKI (`.005` w „Alfa 0.68.005”) NIGDY nie ustawiasz ręcznie: rośnie sama przy KAŻDYM wdrożeniu, a po podbiciu dużego numeru wraca do `.001`. Mechanizm: [szczegóły](docs/agenci/BAZA_MIGRACJE_ROLLBACK_WERSJA.md), `docs/baza/migracje-danych-i-wdrozenia.md`, D-318.

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
- `kind` wpisu (`posts.kind`) w `$fillable` — to trzecie pole sterujące, tej
  samej rodziny co `status` i `role` (D-006): rozstrzyga, czy wpis jest daniem,
  czy pytaniem, więc też o strumieniach, adresie i Policy. Zmienia je wyłącznie
  nazwana metoda `Post::oznaczJakoPytanie()`.

- **żadnego POŚWIADCZENIA w `$fillable`, w żadnej tabeli** — `password`, `remember_token`, `two_factor_*`, każde `*_token`, `*_secret`, `*_token_hash`; wchodzą tam jawnymi, nazwanymi metodami (`assignEmail()`, `assignPassword()`, `connectGoogle()`) albo przez `forceFill()` w jednej nazwanej akcji domenowej. Pilnuje `WrazliweKolumnyPozaMasowymPrzypisaniemTest`; pełna reguła i rejestr wyjątków: [szczegóły](docs/agenci/BEZPIECZENSTWO_FILLABLE.md).

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

MVP: obserwowani — osoby **razem z** obserwowanymi tagami (D-277, #1808),
**chronologicznie**.

```sql
WHERE (author_id IN (...)                                  -- obserwowane osoby i widz
       OR (visibility = 'public' AND EXISTS (tag z obserwowanych)))
ORDER BY published_at DESC, id DESC
```

Paginacja kursorowa. Bez fanout-on-write.

**Reguła doboru treści — zamknięta lista (D-275, #1806).** Żadna lista wpisów
ani osób nie jest układana ani przycinana według reakcji innych (obserwujący,
„Ugotowałem”, reakcje, zapisy w zeszytach, komentarze, odsłony) ani według
przewidywania gustu z zachowania widza. Taki dobór natychmiast dzieli ludzi
na „widzianych” i „niewidzianych” i wyłącza publikowanie u większości.

Dozwolone są **wyłącznie**:

- kolejność po czasie;
- równość autorów (np. rotacja w „Świeżo z Kuking”: najpierw po jednym wpisie od każdej osoby, potem po drugim — D-276);
- wybór gospodarza, oznaczony w interfejsie jako jego wybór;
- bramki widoczności i blokady;
- jawne polecenia widza (obserwuj, ukryj) — z listą, na której może je cofnąć.

Półka „Mój stół” (D-304) dobiera wyłącznie z tej listy. W Obserwowanych nic nie znika poza bramkami, blokadami i wpisami ukrytymi przez widza (D-278); osoby obserwowane wprost nie znikają nigdy; dopuszczalne jest tylko zwinięcie serii wpisów (D-279). Każda nowa reguła doboru = wpis w `docs/DECISIONS.md` + aktualizacja „Jak dobieramy wpisy” (`JakDobieramyWpisy`, `JakDobieramyWpisyMowiPrawdeTest`, D-305) + strażnik (`FeedNieSortujePoMierzeReakcjiTest` albo nowy); reguła spoza listy wymaga decyzji właściciela, nie PR-a. [Pełne brzmienie](docs/agenci/FEED_REGULY_SZCZEGOLOWE.md).

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

- CI (GitHub Actions, D-010) chodzi na `push` do `main` i `staging` oraz na PR-ach do nich, ale kontrola lokalna zostaje (hook: `./scripts/install-hooks.sh`); pojedyncze kroki, plan awaryjny i szczegóły: [szczegóły](docs/agenci/SRODOWISKO_TESTY_CI.md).

Testy chodzą na **PostgreSQL**, nie na SQLite — schemat używa indeksów
częściowych, `num_nonnulls()`, `gen_random_uuid()`, `pg_trgm` i `unaccent`.
Test na SQLite przechodziłby, nic nie sprawdzając.

- **PostgreSQL 18 lub nowszy** lokalnie, w CI i na produkcji; progu nie obniżaj (`TestyChodzaNaPostgresieTest`; kontener agentów z PG 16 oblewa tu środowiskowo, rozstrzyga CI). Używaj izolowanej bazy zadania z jawnym hostem, portem i nazwą.

**Jeśli pracujesz w worktree gita z dowiązanym `vendor`** — dodaj jawną ścieżkę
bazową, inaczej Laravel załaduje trasy i klasy z głównego katalogu, a testy
będą fałszywie zielone:

```bash
APP_BASE_PATH=$(pwd) php artisan test
```

### Instalacja zależności i przeglądarka agenta ([szczegóły](docs/agenci/SRODOWISKO_TESTY_CI.md))

- Gdy instalacja nie działa: zwykłe `composer install` i odczyt rzeczywistego błędu; bez zmiany globalnej konfiguracji Composera i bez usuwania zależności z manifestu lub locka; przed obejściem modyfikującym pliki kopia bajtów i mtime poza repo, po nim sprawdź MD5 oraz mtime. CI rozstrzyga, brak wykonania kontroli musi być jawny.

Czego nadal nie wolno robić: odhaczać w opisie Pull Requesta punktu, którego
nie uruchomiłeś. To dotyczy każdego narzędzia, nie tylko tego.

- Klucza aplikacji produkcyjnej nie zmieniaj. Przeglądarka: sprawdź aktualny dostęp, nie obchodź błędów TLS, raportuj oddzielnie odczyt kodu, pomiary lokalne i ogląd produkcji.

### Pull Request zawiera

- co i dlaczego (nie „poprawki”),
- testy,
- migracje, jeśli dotyczy,
- ryzyka,
- plan rollbacku,
- aktualizację `docs/`,
- opis zmiany w UI albo zrzut ekranu, jeśli dotyczy interfejsu,
- **wpis w `CHANGELOG.md`, jeśli PR dodaje nową funkcję albo zachowanie widoczne dla użytkownika** — z dopiskiem `[nowa funkcja]` i akapitem w `resources/nowosci/tresc.md` (`StraznikNowosciKazdaNowaFunkcjaMaAkapitTest`); [szczegóły](docs/agenci/PULL_REQUEST_I_TESTY_REGRESYJNE.md).

### Bugfix zawsze zawiera test regresyjny

Poprawka bez testu, który by ten błąd złapał, nie jest poprawką — jest
zaproszeniem do jego powtórzenia.

- **Test bez kontroli ujemnej nie jest dowodem:** zepsuj to, czego test pilnuje, sprawdź, że OBLEWA, przywróć. Pułapki z gotowymi wzorcami: [`docs/PULAPKI_TESTOW.md`](docs/PULAPKI_TESTOW.md) — przeszukaj go (grep) po słowie kluczowym swojego testu.
- **Test czytający kod źródłowy dostaje kontrolę mutacyjną w CI:** wpis w `scripts/kontrole-negatywne-alfa08.py` + wzorzec oczekiwanej porażki w `scripts/kontrole_oczekiwana_przyczyna.py` (bez wzorca: `BEZ_WZORCA`, dowód niepełny); wyjątek tylko przez `@bez-kontroli-dodatniej <powód>` (`StraznikTekstuMaKontroleDodatniaTest`). [szczegóły](docs/agenci/PULL_REQUEST_I_TESTY_REGRESYJNE.md)

### Issues

Praca idzie **po kolei, z issues**. Etykiety priorytetu: `P0` → `P1` → `P2`,
kolejność merytoryczna z `docs/ROADMAP.md`. Nowe pomysły zapisuj jako issue
z opisem, uzasadnieniem i kryteriami akceptacji — nie dokładaj ich
do niepowiązanego PR-a.

### Praca równoległa wielu sesji (flota)

Jeśli prowadzisz albo koordynujesz równoległe sesje Claude Code w chmurze,
czyli jesteś **sesją główną**, pracujesz według
[`docs/flota/chmura/SESJA_GLOWNA.md`](docs/flota/chmura/SESJA_GLOWNA.md).
Tam jest opisane:
- wznawianie pracy bez pytania;
- limit i dokładanie sesji;
- kontrola pełnej listy sesji;
- przegląd kodu przed PR-em;
- zamykanie issues z dowodem;
- scalanie paczkami.

Sesja robocza nie otwiera PR-ów. Pushuje swoją gałąź i kończy raportem.

---

## 11. Język

- **Interfejs, komunikaty błędów, dokumentacja i komentarze: po polsku.**
- Kod (nazwy klas, metod, zmiennych): po angielsku, zgodnie z konwencją Laravela.
- Adresy URL widoczne dla użytkownika: po polsku (`/przepisy`, `/zeszyt`,
  `/ustawienia`), z wyjątkiem `/home`, `/login`, `/register`.
- Nazwy zdarzeń analitycznych: `snake_case` po angielsku.

Każdy tekst widoczny dla użytkownika piszesz według `docs/brand/COPY_STYLE.md` (JAK napisać zdanie) i `docs/brand/GLOS_MARKI.md` (czym jest głos marki) — oba są wiążące; [pełne brzmienie](docs/agenci/JEZYK_I_PRAWO.md).

W skrócie:

- mówimy „Ugotowałem”, „Zapisuję”, „Zeszyt”, „Napisz kilka słów”;
- nie mówimy „content”, „explore”, „engage”, „creator”, „tapnij”;
- **`kuKING` to nazwa mieszkańca serwisu, nie komplement.** Wolno „Zostań
  kuKINGiem”, nie wolno „Jesteś prawdziwym kuKINGiem!” ani „Top kuKINGi tygodnia”;
- zero emoji w tekstach interfejsu, najwyżej jeden wykrzyknik na ekran;
- komunikat błędu ma powiedzieć, **co zrobić**;
- nazwę serwisu piszemy dwukolorowo komponentem `<x-kuking-word/>` (raz w akapicie, nagłówku albo punkcie listy), ale **nigdy** w komunikacie błędu, wiadomości moderacyjnej, tekście prawnym, na ekranie bezpieczeństwa, w liście technicznym, powiadomieniu o cudzej aktywności ani w polu wypełnianego formularza, i **nigdy tam, gdzie koloru nie ma** (`alt`, `title`, `aria-label`, tytuł strony, `meta`, temat listu, eksport) — tam zwykłe „Kuking”;
- rodzaj gramatyczny: formę, którą osoba sama wybrała, stosujemy wyłącznie przez helper z wariantem neutralnym (D-332); jawne wyjątki są frazami, nie słowami (lista `WYJATKI` w `tests/Support/WzorceRodzaju.php`), kolejny wyjątek wymaga decyzji właściciela. [Pełne brzmienie](docs/agenci/JEZYK_I_PRAWO.md).

Pełny słownik i lista słów zakazanych: `docs/brand/BRAND_EXTENDED.md`.

**Dokumenty prawne: data publikacji to nie data wejścia w życie** (D-327). Zmiana istotna obowiązuje 14 dni po publikacji, drobna od razu; przy podbiciu `kuking.zgody.wersja_*` ustaw jawnie `kuking.zgody.zmiana_*.istotna` (`true`/`false`, bez wartości domyślnej); zgodę zapisuj z wersją obowiązującą (`WersjaDokumentu::…->obowiazujaca()`). [Szczegóły](docs/agenci/JEZYK_I_PRAWO.md).

---

## 12. Czego świadomie NIE budujemy teraz

Poza MVP (patrz `docs/FEATURES.md` i `docs/ROADMAP.md`):
wiadomości prywatne, natywne aplikacje, planer posiłków, lista zakupów,
generator przepisów AI, rozbudowana gamifikacja, marketplace,
transmisje live, wypłaty dla twórców.

**Zeszły z tej listy** (zakres i granice: [szczegóły](docs/agenci/NIE_WCZESNIE_HISTORIA_LISTY.md)): planer tygodnia (D-310); prywatna lista zakupów (D-333) wyłącznie jako etap 2 — prywatna lista konta, składniki kopiowane dosłownie, bez sumowania, reszta wymaga nowej decyzji; spiżarnia (D-282, #1903); OCR starych zeszytów (D-296–D-298, zawsze prywatny szkic). „Generator przepisów AI” zostaje zakazany.

Anty-wzorce, których **nie wprowadzamy nigdy**:
streaki i punkty za liczbę postów, publiczne rankingi użytkowników,
ranking po popularności i uczenie z zachowania (§8), masowy import cudzych przepisów, sztuczne konta,
liczniki lajków wyeksponowane w interfejsie.

**Jeden wyjątek, i tylko ten: „ile osób zapisało to u siebie w zeszycie”** pod wpisem (D-081, issue #275) — to NIE jest licznik lajków ani ranking: autor widzi liczbę od pierwszej osoby, ktokolwiek inny od trzeciej; liczba nigdzie nie sortuje, nie promuje i nie tworzy zestawień, a na tablicy „kuKINGi na dziś”, w wyszukiwarce i na stronie powitalnej jej nie ma. Zanim ją gdziekolwiek dołożysz, przeniesiesz albo użyjesz do porządkowania treści — przeczytaj D-081; przesunięcie granic wymaga osobnej decyzji właściciela. [Pełne brzmienie](docs/agenci/NIE_WCZESNIE_HISTORIA_LISTY.md).
