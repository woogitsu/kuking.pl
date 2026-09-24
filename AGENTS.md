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

### Co znaczy tu „zawsze” — i trzy przypadki, w których powiadomienia nie ma

Obietnica działająca w większości ścieżek nie działa, więc słowo „zawsze”
obowiązuje na KAŻDEJ drodze, którą w tym serwisie powstaje wykonanie: przez
formularz, przez akcję domenową wołaną wprost i przez dane demonstracyjne.
Do 12 września 2026 `DemoSeeder` zapisywał dwa wykonania i powiadamiał przy
jednym — obietnica była tam prawdziwa w połowie przypadków, a to są dane, na
których ogląda się serwis lokalnie. Dlatego seeder **też** idzie przez
`RecordCookedEvent`, a nie przez gołe `CookedEvent::create()`.

Granice są trzy, wszystkie odcina `NotifyUser` i wszystkie są zmierzone
w `tests/Feature/UgotowalemZawszePowiadamiaAutoraTest.php`:

1. **Autor ugotował własny przepis.** Wolno mu (`RecipePolicy::cook`), ale
   wiadomość o własnej akcji nie niesie informacji.
2. **Konto autora jest zamknięte** — `banned`, `pending_delete` albo `erased`.
   Przy dwóch pierwszych wykonanie w ogóle nie powstaje, bo przepis takiego
   konta jest niewidoczny. Przy `erased` wykonanie powstaje i **zostaje** (to
   dorobek kucharza), a powiadomienia nie ma, bo nie ma komu go przeczytać.
   **Zawieszenie tu nie wchodzi**: zawieszony autor powiadomienie dostaje —
   zawieszenie odcina od pisania, nie od wiadomości, dla której warto wrócić.
3. **Między autorem a kucharzem jest blokada** (w którąkolwiek stronę). Wtedy
   nie powstaje samo wykonanie.

Czego na tej liście nie ma i mieć nie ma: **ustawienia użytkownika**. Jedyna
zgoda, jaką człowiek tu przestawia, dotyczy tygodniowego listu
(`users.wants_weekly_digest`) i powiadomień w serwisie nie dotyka. Ugotowanie
**cofnięte i zrobione ponownie** powiadamia drugi raz, a ta sama osoba
gotująca ten sam przepis dwa razy daje dwa powiadomienia — to są ZDARZENIA,
nie STAN (`NotifyUser::TYPY_WYCISZANE_W_OKNIE`). Jedno ograniczenie jest
wąskie i nazwane: jedno wysłanie formularza to jedno powiadomienie
(`klucz_wyslania`).

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

Jeśli pracujesz nad marką lub wyglądem: najpierw
[`docs/brand/KONSTYTUCJA_MARKI.md`](docs/brand/KONSTYTUCJA_MARKI.md), potem
`docs/brand/COPY_STYLE.md`, `docs/brand/GLOS_MARKI.md` oraz
`docs/design/DESIGN_SYSTEM.md`. Konstytucja wyznacza kierunek marki;
nie zastępuje nadrzędnych zasad tego pliku ani jawnych decyzji właściciela
w `docs/DECISIONS.md`. Historyczna makieta nie unieważnia tych zasad.
Jeśli nad moderacją lub prawem: `docs/legal/`.
Jeśli nad wdrożeniem: `docs/infra/`.

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

Feed, wyszukiwanie, komentarze i „Ugotowałem” korzystają z kontrolerów
i widoków Blade, z JavaScriptem jako ulepszeniem. Livewire obsługuje złożony
formularz kreatora przepisu (`resources/views/components/recipe-wizard.blade.php`).
Dodanie `wire:poll` do często odwiedzanych ekranów wymaga osobnej decyzji
i pomiaru kosztu żądań; nie wynika z wyboru Livewire dla kreatora.

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

**Reguła „ikona nigdy nie jest jedynym opisem ważnej akcji" ma jeden nazwany
wyjątek: menu „więcej" na karcie wpisu** (`components/post-card.blade.php`,
`<details class="post-card-menu">`). Ten jeden przycisk to same trzy kropki,
bez widocznego napisu.

- **Powód.** To jest utrwalony wzorzec z Facebooka, a nasza grupa spędziła
  tam lata. Trzy kropki w rogu wpisu nie są dla niej ikoną do rozszyfrowania,
  tylko znakiem, który już zna. Decyzja właściciela z 12 września 2026,
  podjęta ze znajomością ryzyka — odwraca decyzję z 11 września, która
  dokładała tam napis „Więcej".
- **Granica.** Wyjątek dotyczy **wyłącznie tego jednego menu**. Nie obejmuje
  paska akcji pod wpisem, pasków nawigacji, przycisku zamykania, akcji
  moderacyjnych ani niczego innego — tam reguła obowiązuje bez zmian
  i pilnują jej osobne testy.
- **Co wyjątek zabiera, a czego nie.** Zabiera **widoczny napis**. Nie
  zabiera niczego czytnikowi ekranu: `aria-label` („Więcej przy tym wpisie")
  zostaje i jest wtedy jedyną nazwą dostępną tego przycisku. Nie zabiera też
  celu dotknięcia — przycisk dalej ma 48 × 48 px.
- **Czym to się różni od stanu sprzed 11 września.** Wtedy przyciskiem były
  trzy kropki wpisane z klawiatury, schowane przed czytnikiem ekranu — oko
  dostawało znak bez podpisu, czytnik podpis bez znaku. Teraz kropki rysuje
  komponent ikony, `aria-label` niesie pełną nazwę, a `AGENTS.md`
  i `docs/UX_50_PLUS.md` mówią o tym wprost, zamiast milczeć.
- **Pilnuje tego test** `KartaWpisuTest::test_menu_karty_to_same_kropki_ale_czytnik_ekranu_nie_traci_nic`.
  Rozszerzenie wyjątku na kolejny przycisk wymaga decyzji właściciela
  i wpisu w `docs/DECISIONS.md`, a nie dopisania klasy CSS.

Nawigacja mobilna ma **maksymalnie 5 pozycji**:
`Start | Szukaj | Dodaj | Moje | Profil`.

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
- **żadnego POŚWIADCZENIA w `$fillable`, w żadnej tabeli** — `password`,
  `remember_token`, `two_factor_*`, każde `*_token`, `*_secret`, `*_token_hash`.
  Kto zapisze taką kolumnę, ten wchodzi na konto bez znajomości hasła.
  Wchodzą tam jawnymi, nazwanymi metodami (`assignEmail()`,
  `assignPassword()`, `connectGoogle()`) albo przez `forceFill()` w jednej
  nazwanej akcji domenowej. `ip_hash` i `checksum_sha256` to NIE są
  poświadczenia — reguła mówi „poświadczenie", nie „ciąg szesnastkowy".
  Pilnuje tego `tests/Feature/WrazliweKolumnyPozaMasowymPrzypisaniemTest.php`,
  który przechodzi po WSZYSTKICH modelach i wylicza kolumny wrażliwe
  z schematu bazy i z relacji, a nie z listy przepisanej z palca. Kolumny
  wrażliwe spoza kategorii poświadczeń (stan treści, klucze właściciela,
  widoczność) wolno w `$fillable` zostawić, ale wyłącznie z wpisem w rejestrze
  tego testu mówiącym, skąd ta wartość pochodzi, jeśli nie z żądania.

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

I to na **PostgreSQL 18 lub nowszym** — tak samo lokalnie, w CI i na produkcji.
Jeden próg dla wszystkich trzech, bo próg niższy od produkcyjnego przepuszcza
lokalnie migracje, które w CI padają. Produkcja ma 18, CI stawia
`postgres:18-alpine`, więc `php artisan test` na starszym majorze mierzy silnik,
którego nigdzie nie używamy. Pilnuje tego `TestyChodzaNaPostgresieTest` —
i pilnuje też tego, żeby ten akapit i próg w strażniku mówiły tę samą liczbę.

Przed utworzeniem bazy ustal jej właściciela, host, port i nazwę.
Użyj izolowanej bazy tego zadania i jawnych parametrów połączenia.
Nie polegaj na domyślnym porcie ani nazwie w środowisku współdzielonym.

**Jeśli pracujesz w worktree gita z dowiązanym `vendor`** — dodaj jawną ścieżkę
bazową, inaczej Laravel załaduje trasy i klasy z głównego katalogu, a testy
będą fałszywie zielone:

```bash
APP_BASE_PATH=$(pwd) php artisan test
```

### Gdy instalacja zależności nie działa

Najpierw uruchom zwykłe `composer install` i odczytaj rzeczywisty błąd.
Historyczne kontenery agentów miały proxy odrzucające pobrania z GitHuba;
nie jest to stała właściwość każdego środowiska. Sprawdź bieżący dostęp,
wersję narzędzia i konfigurację, zanim uznasz instalację za niewykonalną.

Jeśli potwierdzisz blokadę pobrań `dist`, a dostęp przez git działa,
możesz spróbować `composer install --prefer-source`. Nie zmieniaj globalnej
konfiguracji Composera w środowisku współdzielonym. Ewentualną konfigurację
obejścia ogranicz do izolowanej kopii lub osobnego katalogu COMPOSER_HOME.

Nie usuwaj zależności z manifestu ani locka, żeby uzyskać pozornie pełną
instalację. Zachowaj dokładne wersje z composer.lock. Historycznie lokalną
instalację PHPStan umożliwiło przygotowanie archiwum wskazanego commita
w cache Composera; to opis zakończonej sesji, nie nakaz stosowania obejścia
przy każdym uruchomieniu.

Przed obejściem wymagającym modyfikacji plików zrób kopię ich aktualnych
bajtów i czasu modyfikacji poza repo. Preferuj izolowaną kopię wykonawczą.
Przywróć dokładnie zapisany stan i sprawdź MD5 oraz mtime; odtworzenie pliku
z commita nie chroni cudzych niezapisanych zmian. Nie ogłaszaj narzędzia
niedostępnym ani testu zaliczonym na podstawie historycznej notatki.
CI pozostaje rozstrzygające, a brak wykonania kontroli musi być jawny.

Czego nadal nie wolno robić: odhaczać w opisie Pull Requesta punktu, którego
nie uruchomiłeś. To dotyczy każdego narzędzia, nie tylko tego.

W nowej izolowanej instancji lokalnej sprawdź, czy istnieje klucz aplikacji.
Generuj go tylko, gdy go brakuje; bez niego wystąpi błąd
„No application encryption key has been specified”. Nie zmieniaj klucza
istniejącej aplikacji produkcyjnej podczas przygotowania testów:

```bash
php artisan key:generate
```

### Przeglądarka w środowisku agenta

Najpierw sprawdź aktualny dostęp do testowanej strony. W jednej z dawnych
sesji Chromium przerywał TLS za proxy; w późniejszych sesjach produkcja
była dostępna zarówno przez Chromium, jak i zalogowany Chrome. Historyczny
błąd nie jest dowodem dzisiejszej blokady ani usterki aplikacji.

Do fixture, formularzy i stanów wymagających danych testowych używaj
izolowanej instancji lokalnej. Przed migracją lub seedowaniem odczytaj
faktyczny host, port i nazwę bazy oraz upewnij się, że należą do tego testu.
Nie zakładaj dostępności domyślnego portu PostgreSQL: może obsługiwać inne
projekty. Współdzielone środowisko wymaga jawnie wybranej bazy i portu.
Nie wykonuj testów niszczących fixture równolegle z oglądem używającym
tej samej bazy lub mediów. Nie obchodź błędów TLS przez wyłączanie ochrony.

Raportuj oddzielnie odczyt kodu, pomiary lokalne i ogląd produkcji.
Brak dostępu do zalogowanej produkcji jest ograniczeniem, nie wynikiem
pozytywnym; odpowiednie stany można sprawdzić lokalnie, bez zmiany danych
użytkowników produkcyjnych.

### Pull Request zawiera

- co i dlaczego (nie „poprawki”),
- testy,
- migracje, jeśli dotyczy,
- ryzyka,
- plan rollbacku,
- aktualizację `docs/`,
- opis zmiany w UI albo zrzut ekranu, jeśli dotyczy interfejsu,
- wpis w `CHANGELOG.md`, jeśli człowiek zobaczy zmianę — patrz niżej.

### Wersja i CHANGELOG

- **Zmiana widoczna dla człowieka** = PR rusza `resources/views/`,
  `resources/css/`, `resources/js/` (bez `*.test.mjs`), `lang/` albo `public/`.
- Taki PR **dopisuje linię `- …` w sekcji `## Nieopublikowane`** na górze
  `CHANGELOG.md`, językiem użytkownika. **Numeru wersji nie podbija** —
  `wersja.etykieta` w `config/kuking.php` rośnie raz, przy wydaniu, a lista
  „Nieopublikowane” przechodzi wtedy pod nowy nagłówek `## Alfa 0.N — …`.
  Powód: podbicie w każdym PR-ze dawało konflikty między równoległymi
  gałęziami (decyzja właściciela, 23.09.2026).
- **Nowy wpis staje w liście w kolejności alfabetycznej**, nie na końcu
  sekcji. Wpis zmniejsza konflikty, ale ich nie znosi: dwa PR-y dopisujące
  linię w tym samym miejscu listy scalą się z konfliktem (małym — zostaw
  obie linie). Kolejność alfabetyczna rozrzuca wstawki po liście.
- Zmiana w tych katalogach bez śladu w interfejsie (martwy CSS, komentarz)
  → linia `Bez-podbicia-wersji: <powód>` **w treści commita** (zostaje
  w historii i przeżywa scalenie; opis PR-a to tylko uzupełnienie). Linia
  zaczyna się od pierwszej kolumny — w cytacie, wcięciu albo bloku kodu
  bramka jej nie liczy, żeby przytoczenie reguły nie otwierało furtki.
- Pilnuje tego job CI `bramka_wersji` (`scripts/bramka-wersji.sh`, da się
  uruchomić lokalnie) — **tylko na PR-ze**, nie przy pushu do `main` (tam nie
  ma opisu PR-a, a czerwień wstrzymałaby wdrożenie). Opis PR-a bramka czyta
  ze zdarzenia — po jego edycji trzeba nowego pushu, samo „Re-run” widzi
  stary opis.
- **Ograniczenie bramki:** polskie komunikaty zapisane w `app/` (walidacja,
  powiadomienia, maile, teksty z Livewire) człowiek widzi, ale bramka ich
  NIE liczy jako zmiany widocznej — `app/` jest poza listą, bo większość
  zmian tam nie ma śladu w interfejsie. Zmieniasz taki tekst → dopisz wpis
  w „Nieopublikowane” sam, bez przypomnienia z CI.

### Bugfix zawsze zawiera test regresyjny

Poprawka bez testu, który by ten błąd złapał, nie jest poprawką — jest
zaproszeniem do jego powtórzenia.

**Test bez kontroli ujemnej nie jest dowodem.** Zepsuj to, czego test pilnuje,
sprawdź, że OBLEWA, przywróć. Pomyłki, które w tym repozytorium przeszły
przez zielone CI — razem z gotowymi wzorcami, jak ich uniknąć — są zebrane
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

**Każdy tekst widoczny dla użytkownika piszesz według `docs/brand/COPY_STYLE.md`
i `docs/brand/GLOS_MARKI.md`.** Oba są wiążące, nie są inspiracją: pierwszy mówi,
JAK napisać zdanie, i ma gotowe teksty do wklejenia; drugi mówi, czym ten głos
JEST i gdzie marka mówi głośno, a gdzie milczy.

W skrócie:

- mówimy „Ugotowałem”, „Zapisuję”, „Zeszyt”, „Napisz kilka słów”;
- nie mówimy „content”, „explore”, „engage”, „creator”, „tapnij”;
- **`kuKING` to nazwa mieszkańca serwisu, nie komplement.** Wolno „Zostań
  kuKINGiem”, nie wolno „Jesteś prawdziwym kuKINGiem!” ani „Top kuKINGi tygodnia”;
- **nazwę piszemy dwukolorowo, komponentem `<x-kuking-word/>`, wszędzie — także
  jako nazwę serwisu w tekście bieżącym.** Limitu „raz na ekran” nie ma
  (decyzja właściciela z 11 września 2026, odwraca tę część D-009 i D-015).
  Obowiązuje kryterium: charakter marki wolno tam, gdzie **nie konkuruje
  z zadaniem**, a w jednym akapicie, nagłówku albo punkcie listy nazwa
  pojawia się raz;
- **nigdy** w komunikacie błędu, wiadomości moderacyjnej, tekście prawnym,
  na ekranie bezpieczeństwa, w liście technicznym, w powiadomieniu o cudzej
  aktywności ani w polu formularza, który ktoś właśnie wypełnia;
- **nigdy tam, gdzie koloru nie ma** — `alt`, `title`, `aria-label`, tytuł
  strony, `meta`, temat listu, pliki eksportu. Tam piszemy zwyczajnie „Kuking”;
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
