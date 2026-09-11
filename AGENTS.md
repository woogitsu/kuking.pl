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

| Warstwa | Wybór | Gdzie to sprawdzić |
|---|---|---|
| Backend | Laravel 13 | `composer.json`: `laravel/framework` |
| PHP | 8.4 (minimum frameworka: 8.3) | `composer.json`: `php` |
| UI | Blade + Livewire 4 + Alpine.js | `composer.json`: `livewire/livewire` |
| CSS | Tailwind CSS 4 (konfiguracja CSS-first, `@theme`, bez `tailwind.config.js`) | `package.json`: `tailwindcss`, `@tailwindcss/vite` |
| Baza | PostgreSQL 18 (lokalnie i w CI wystarczy 16+) | usługa zewnętrzna |
| Kolejka | Laravel database queue | `composer.json`: `laravel/framework` |
| Hosting | Railway | usługa zewnętrzna |
| DNS / CDN / storage | Cloudflare + R2 | usługa zewnętrzna · `composer.json`: `league/flysystem-aws-s3-v3` |
| Wyszukiwarka | PostgreSQL FTS + `pg_trgm` + `unaccent` | w repozytorium: `database/migrations/0001_01_01_000000_enable_postgres_extensions.php` |
| Monitoring | dziennik serwera + kanał `blad_webhook` na Slack/Discord (D-041) | w repozytorium: `app/Logging/WebhookBleduHandler.php` |
| Analityka | własna, serwerowa (`App\Domain\Analytics\*`) + Cloudflare Web Analytics (bez ciasteczek — D-092) | w repozytorium: `app/Domain/Analytics`, `app/Support/AnalitykaCloudflare.php` · usługa zewnętrzna |
| Mobile | PWA | w repozytorium: `public/manifest.webmanifest` |

### Ta tabela opisuje STAN, nie zamiar — i trzecia kolumna jest sprawdzana testem (D-104)

Tabela nosi nagłówek „Stack” i jest czytana jako odpowiedź na pytanie „co
w tym projekcie JEST”. Do 10 września 2026 stało w niej `| Monitoring | Sentry |`,
a Sentry'ego tu nie ma i nigdy nie było: zero trafień w `composer.json`, brak
`config/sentry.php`, a `SENTRY_LARAVEL_DSN` jest przewleczone przez
`.env.example`, `.railway/railway.ts` i `ci.yml`, ale **nie czyta go ani jedna
linijka PHP**. Autor PR-a #253 zbudował na tym wierszu całe zdanie o tym, że
„właściciel ma szansę dowiedzieć się o awarii bez zaglądania” — założył, że
`Log::error()` dojdzie do Sentry. Nie dochodzi. Prostowanie tego zajęło komuś
innemu trzy pliki i wpis w dzienniku decyzji.

Dlatego wiersz `Monitoring` mówi teraz, co **działa dziś**, a Sentry jako wybór
docelowy stoi tam, gdzie zamiary mają stać: **D-041** w `docs/DECISIONS.md`
i `docs/ROADMAP.md` §0. Tak samo postępuj z każdym następnym wierszem: do tabeli
wchodzi rzecz wdrożona, do roadmapy — zamiar.

Trzecia kolumna nie jest ozdobą. Czyta ją
`tests/Feature/TabelaStackuMowiPrawdeTest.php` i sprawdza, czy rzecz naprawdę
jest tam, gdzie wiersz obiecuje. Dozwolone są **cztery kształty wpisu i nic
poza nimi**:

| Kształt | Znaczenie | Co sprawdza test |
|---|---|---|
| `composer.json`: nazwa pakietu | zależność PHP | klucz jest w `require` albo `require-dev` |
| `package.json`: nazwa pakietu | zależność npm | klucz jest w `dependencies`, `devDependencies` albo `optionalDependencies` |
| w repozytorium: ścieżka | rzecz jest naszym kodem | plik albo katalog istnieje |
| usługa zewnętrzna | konto u kogoś — w repozytorium nie ma czego sprawdzać | tylko to, że wiersz to MÓWI |

Kilka lokalizatorów w jednym wierszu rozdziela `·`. Kształt piąty nie przejdzie:
test oblewa i podaje wiersz z nazwy. Nowy wiersz nie wejdzie więc do tabeli bez
odpowiedzi na pytanie „a gdzie to jest”, i odpowiada na nie ten, kto go dopisuje.

**„usługa zewnętrzna” nie jest wytrychem.** Wpisanie tego przy pakiecie PHP
(Sentry jest SDK, nie usługą) test przepuści — ale wtedy tabela kłamie JAWNIE,
w jednym widocznym wierszu, zamiast po cichu przez zwykłą nazwę w kolumnie
„Wybór”. Żeby nie dało się uciszyć testu przepisaniem wszystkich wierszy na tę
wartość, test wymaga minimalnej liczby wierszy sprawdzalnych w repozytorium.

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

Te dwie reguły mają jeden nazwany, udokumentowany wyjątek — metryczka wersji
i przełącznik motywu w stopce, na świadomą decyzję właściciela: patrz
`docs/DECISIONS.md`, **D-051**. To nie jest furtka ogólna: gdziekolwiek
indziej w serwisie te reguły obowiązują bez zmian.

Nawigacja mobilna ma **maksymalnie 5 pozycji**:
`Start | Szukaj | Dodaj | Zeszyt | Profil`.

Paginacja to **przycisk „Pokaż więcej”**, nie infinite scroll.

### JavaScript jest wymagany tam, gdzie chroni serwis — i nigdzie nie zostawia martwego przycisku

**Zmiana zasady, 9 września 2026 (D-053).** Wcześniej stało tu, że rejestracja,
logowanie, publikacja wpisu, przepis, komentarz i „Ugotowałem” **muszą działać
bez JavaScriptu**. Właściciel to zmienił i ma rację co do faktów: nasi
użytkownicy nie wchodzą tu z telefonu bez skryptów, tylko z Samsunga, Xiaomi
albo z komputera. Pełne uzasadnienie i skutki: **D-053** w `docs/DECISIONS.md`.

Obowiązuje teraz to:

1. **Newralgiczne formularze mogą wymagać JavaScriptu.** Rejestracja i logowanie
   stoją za Turnstile (D-050), a Turnstile bez skryptu nie istnieje. Wymóg jest
   świadomy: chroni serwis przed ruchem automatycznym.
2. **Gdziekolwiek indziej JavaScript jest mile widziany** — podgląd zdjęcia
   przed wysłaniem, licznik znaków, kadrowanie awatara. Nie trzeba tego
   uzasadniać ani dublować wersją bez skryptu.
3. **Czego nie wolno nigdy: martwego przycisku.** Jeśli coś bez skryptu nie
   zadziała, człowiek ma zobaczyć zdanie po polsku mówiące, CO ZROBIĆ, a nie
   formularz, który po kliknięciu milczy. `<noscript>` z konkretną instrukcją,
   nie z ogólnikiem „wymagany JavaScript”. Powód jest ten sam co dawniej i nie
   zniknął: przy słabym zasięgu skrypt bywa **nie dociągnięty** na telefonie,
   który JavaScript ma i ma go włączonego.
4. **Awaria po naszej stronie albo po stronie Cloudflare nie zamyka drzwi.**
   Gdy weryfikacja tokenu nie odpowiada, formularz przechodzi (D-050). Wymóg
   dotyczy skryptu u człowieka, nie sprawności cudzej usługi.

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

### `down()` przy wartościach semantycznych ODMAWIA, zamiast zgadywać (D-088)

> **`down()` nie ma prawa przywracać stanu groźnego ani zmieniać znaczenia
> decyzji człowieka.** Przy wartościach semantycznych — zgoda, zakres
> usunięcia danych, widoczność, prywatność zeszytu — rollback ma **odmówić**
> z komunikatem mówiącym, co zrobić ręcznie. Zgadywanie cichą wartością
> domyślną jest najgorszą z opcji, bo nie zostawia śladu błędu.

Powód jest jeden i nie jest teoretyczny: **`down()` prawie nigdy nie
występuje sam.** Po nim idzie kolejny `migrate` — `migrate:refresh` w CI albo
awaryjny rollback WDROŻENIA, który pociąga bazę za sobą. Kolumna wraca, CHECK-i
wracają, żaden wiersz nie ginie, więc nie ma błędu do zauważenia — a wartość
jest już ta, którą umie nadać `DEFAULT` albo backfill z `up()`, czyli zwykle
ODWROTNOŚĆ tego, co człowiek wybrał.

Trzy przypadki tej jednej choroby, złapane w tym repozytorium:

| Gdzie | Co się cicho odwracało |
|---|---|
| `..._default_weekly_digest_to_off` (DB2) | `DEFAULT true` wracał, czyli nowe konta znów zapisywane na mailing bez zgody |
| `..._add_erased_status_and_delete_scope_to_users` (#287, MIG-01) | „usuń wszystkie moje treści" wracało jako „usuń minimum" |
| `..._add_memories_to_users_and_posts` (#287, przeoczone przy MIG-01) | wyłącznik wspomnień osoby w żałobie włączał się sam, schowany wpis wracał na stronę główną |

**Napisanie w komentarzu migracji „przy cofaniu na produkcji najpierw kopia
kolumny" NIE jest zabezpieczeniem.** Trzeci wiersz tabeli wyżej miał dokładnie
takie zdanie — prawdziwe, konkretne i bezwartościowe, bo przenosiło ochronę na
czyjąś pamięć w jedynym momencie, w którym nikt nie czyta komentarzy
w migracjach. Zabezpieczeniem jest `throw` w `down()`.

**Odmowa musi być WĄSKA.** Rollback blokuje się tylko wtedy, gdy w bazie
naprawdę jest wartość, której `up()` nie odtworzy — na wartościach domyślnych
i na świeżej bazie przechodzi bez pytania. Zablokowanie rollbacku na zawsze
jest błędem tej samej wagi w drugą stronę, więc każdy taki strażnik ma test
odmowy **i** kontrolę dodatnią (wzorce:
`tests/Feature/CofniecieMigracjiNiePodmieniaZakresuUsunieciaTest.php`,
`tests/Feature/CofniecieMigracjiNieWlaczaWspomnienTest.php`).

Preferencja WYGLĄDU to nie wartość semantyczna: `theme` i `posts.display_mode`
zostają świadomie bez strażnika (uzasadnienie w D-088).

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

### Gdy `composer install` pada na „Could not authenticate against github.com"

Dotyczy kontenerów agentów, w których ruch wychodzi przez proxy.
`api.github.com` i `codeload.github.com` oddają wtedy **403**, więc Composer
nie pobierze ani jednej paczki jako `dist` — a bez `vendor/autoload.php` nie
ruszy ani `php artisan test`, ani `vendor/bin/pint`. Łatwo z tego wyciągnąć
wniosek, że lokalnie nie da się nic sprawdzić, i zacząć wypychać każdą
poprawkę na CI. **Da się**, i to jest ważne, bo pula minut Actions jest
skończona.

`git` przez to samo proxy **przechodzi**, więc paczki instalują się ze
źródeł:

```bash
composer install --prefer-source
```

Jeszcze pewniej działa to z jawnym wyłączeniem API GitHuba, bo inaczej
Composer i tak próbuje najpierw `dist`:

```bash
composer config -g use-github-api false
composer install --prefer-source
```

Blokuje to dokładnie jedna paczka: `phpstan/phpstan` nie ma w `composer.lock`
wpisu `source` (to repozytorium dystrybucyjne, tylko `dist`), a klon lustrzany
jej repozytorium przekracza limit czasu Composera.

**Obejście doraźne** — wyjmij **na czas instalacji** `phpstan/phpstan`
i `larastan/larastan` z `composer.json` i `composer.lock`, zainstaluj resztę,
po czym **przywróć oba pliki z gita**:

```bash
git checkout composer.json composer.lock
```

`vendor/` zostaje sprawne, a repozytorium nietknięte. Kosztem jest brak
analizy statycznej.

> ### ⚠️ SPROSTOWANIE, 9 września 2026: Larastan CHODZI lokalnie
>
> Ten akapit twierdził, że „**Larastan nie chodzi lokalnie**, więc analiza
> statyczna zostaje po stronie CI". **Nieprawda** — i to nieprawda kosztowna,
> bo każdy agent czytał ją jako zwolnienie z obowiązku i odhaczał analizę
> statyczną jako niewykonalną.
>
> Zmierzone tego dnia w kontenerze agenta: **`PHPStan 2.2.13`, poziom 1
> z `phpstan.neon`, `0 errors`** na dwóch gałęziach niezależnie. Da się.
>
> Trzeba tylko podłożyć tę jedną paczkę do cache Composera samodzielnie:
> sklonuj `phpstan/phpstan` na płytko (`git fetch --depth 1`) na commicie
> zablokowanym w `composer.lock`, spakuj w kształt zipballa GitHuba i wrzuć
> do cache Composera **pod dwiema nazwami** — `<reference>.zip`
> oraz `sha1(<adres dist>).zip`. Ta druga jest tą, której Composer faktycznie
> szuka, i pominięcie jej jest powodem, dla którego „podłożenie do cache"
> zwykle nie działa za pierwszym razem.
>
> To jest zabieg na kilka minut, więc **nie jest wymówką**, żeby go pominąć:
> jeśli piszesz w opisie PR-a, że analizy nie uruchomiłeś, napisz też
> dlaczego — brak czasu jest uczciwym powodem, „nie da się" już nie jest.
>
> CI zostaje **rozstrzygające**. Chodzi o to, żeby nie wypychać na nie
> błędów, które łapie się lokalnie w trzydzieści sekund.

Czego nadal nie wolno robić: odhaczać w opisie Pull Requesta punktu, którego
nie uruchomiłeś. To dotyczy każdego narzędzia, nie tylko tego.

Po instalacji ustaw jeszcze klucz aplikacji, inaczej każdy test padnie na
„No application encryption key has been specified":

```bash
php artisan key:generate
```

### Przeglądarka w kontenerze agenta

Chromium **nie przejdzie przez proxy sesji** do adresu zewnętrznego: tunel
CONNECT staje, ale połączenie TLS zrywa się po kilku sekundach bez jednego
bajta odpowiedzi. Dotyczy to każdego hosta, nie tylko kuking.pl, więc nie
jest to usterka serwisu i nie ma sensu tego naprawiać w kodzie.

Obejście: postaw instancję lokalnie i chodź po `127.0.0.1`, bo localhost
jest poza proxy:

```bash
php artisan migrate && php artisan db:seed   # dane demo
php artisan serve --host=127.0.0.1 --port=8000
```

W Playwrighcie **nie podawaj wtedy `proxy`**:

```js
chromium.launch({ executablePath: '/opt/pw-browsers/chromium', headless: true })
```

Instancja lokalna ma tę przewagę, że wolno się na niej zalogować i wysyłać
formularze, więc widać także tę połowę produktu, która na produkcji jest za
logowaniem. Hasła do kont demo wypisuje `DemoSeeder`.

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

**Test bez kontroli ujemnej nie jest dowodem.** Zepsuj to, czego test pilnuje,
sprawdź, że OBLEWA, przywróć. Sześć pomyłek, które w tym repozytorium przeszły
przez zielone CI — razem z gotowymi wzorcami, jak ich uniknąć — jest zebranych
w [`docs/PULAPKI_TESTOW.md`](docs/PULAPKI_TESTOW.md). Przeczytaj to raz, zanim
napiszesz pierwszy test w tym projekcie; każda z tych pułapek wróci.

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

**Jeden wyjątek, i tylko ten: „ile osób zapisało to u siebie w zeszycie"**
pod wpisem — decyzja właściciela **D-081** (`docs/DECISIONS.md`, issue #275).
To NIE jest licznik lajków ani ranking: autor widzi liczbę od pierwszej osoby,
ktokolwiek inny od trzeciej, liczba nigdzie nie sortuje, nie promuje i nie
tworzy zestawień, a na tablicy „kuKINGi na dziś", w wyszukiwarce i na stronie
powitalnej jej celowo nie ma. Zanim tę liczbę gdziekolwiek dołożysz, przeniesiesz
albo użyjesz do porządkowania treści — przeczytaj D-081, bo granice są tam
wypisane wprost i ich przesunięcie wymaga osobnej decyzji właściciela.
