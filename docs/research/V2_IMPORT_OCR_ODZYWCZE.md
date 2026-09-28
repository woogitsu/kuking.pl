# V2: import przepisu (URL, PDF, zdjęcie, OCR zeszytów) oraz wartości odżywcze i koszt dania — projekt

**Data:** 26 września 2026 · **Status: PROJEKT, bez kodu produkcyjnego** ·
Autor: agent (Claude) na zlecenie właściciela · Punkt wyjścia: `origin/main`
na `3849945e`.

**Podstawa zlecenia:** właściciel 26.09.2026 dopuścił prace nad funkcjami V2
(`docs/FEATURES.md` § „V2”: „OCR starych zeszytów”, „import URL/PDF/zdjęcie”,
„nutrition”, „koszt”) i wskazał model AI: OpenAI **„GPT-6 Luna”**. Ten dokument
rozwija otwarte issue **#28** („V2: OCR starych zeszytów oraz import przepisu
z adresu strony”) i dokłada do niego wartości odżywcze i koszt.

**Czego ten dokument NIE robi:** nie zmienia `AGENTS.md` §12 (tam OCR i
„generator przepisów AI” nadal stoją na liście „nie budujemy teraz”), nie
dopisuje decyzji do dziennika i nie wprowadza kodu. Wszystkie trzy rzeczy
wymagają odpowiedzi na pytania z §11 — patrz issue I-0 w §12.

---

## 0. Streszczenie w jednym akapicie

Import to **zadanie w kolejce, które kończy się prywatnym SZKICEM przepisu
w istniejącym kreatorze** — nigdy publikacją. Model „GPT-6 Luna” jest
używany **tylko tam, gdzie bez niego się nie da**: do odczytu pisma ze zdjęcia
(OCR) i jako ostatnia deska ratunku dla strony bez danych strukturalnych.
Adres strony z JSON-LD `Recipe` i PDF z warstwą tekstu obsługujemy **lokalnie,
bez AI i bez kosztu**. Każde wywołanie modelu przechodzi przez przełącznik
funkcji, dzienny i miesięczny budżet liczony w PostgreSQL (bez Redisa) oraz
limit na osobę. Zdjęcie zeszytu to treść **prywatna** — dziś D-240 zabrania
wysyłania jej do OpenAI, więc import wymaga **nowej decyzji właściciela
i nowej zgody w `dziennik_zgod`**. Wartości odżywcze i koszt liczymy
**deterministycznie z tabeli składników** (nie z modelu), pokazujemy
z napisem „szacunek” i bez żadnych porad zdrowotnych.

---

## 1. Co już jest w repozytorium (rozpoznanie)

### 1.1 Integracja OpenAI — tylko moderacja, tylko treść publiczna

| Co | Gdzie | Co z tego wynika dla importu |
|---|---|---|
| Konfiguracja modelu moderacji: `klucz` = `OPENAI_MODERATION_KEY`, `endpoint`, `nazwa` = `omni-moderation-latest`, `limit_czasu` 8 s | `config/kuking.php:3035–3043` | Wzorzec do skopiowania: brak klucza = funkcja wyłączona i nic nie pada (lokalnie, w CI, w testach). |
| Cienki klient HTTP bez paczki Composera; host na liście w KODZIE (`HOSTY = ['api.openai.com']`), ścieżka kotwiczona `#^/v1/moderations$#` | `app/Moderacja/KlientOpenAI.php:74–83` | Import potrzebuje **drugiego** klienta z własną ścieżką (np. `/v1/responses`) — klient moderacji celowo nie przyjmie innej. |
| Walidacja adresu dostawcy (schemat, `@`, port, ścieżka, query) | `app/Support/DozwolonyHostApi.php` | Używamy bez zmian. |
| Trzy wyniki: ocena / `null` (bez sensu ponawiać) / `ModelChwilowoNiedostepny` (429, 5xx, timeout → ponawia ZADANIE, nie klient) | `KlientOpenAI.php:51–60`, `app/Jobs/PrzeanalizujTresc.php:57–84` | Ten sam podział błędów, te same `PROBY = 3` z opóźnieniem. |
| Granica wysyłki: do OpenAI wychodzi **wyłącznie treść publiczna, pomniejszona do 320 px**; awatar nie wychodzi, bo nie ma mechanizmu zgody | `app/Moderacja/GranicaWysylki.php`, **D-240** (`docs/DECISIONS.md:15786`) | **Konflikt:** OCR potrzebuje prywatnego zdjęcia w rozdzielczości, w której da się przeczytać pismo. Patrz §5. |
| Budżet | brak | **Moderacja nie ma dziś żadnego budżetu kwotowego** — endpoint `/v1/moderations` jest bezpłatny (`app/Console/Commands/SprawdzModel.php:109`). Założenie ze zlecenia „moderacja ma wspólny budżet” nie ma pokrycia w kodzie. Proponuję **osobny budżet i osobny klucz** dla importu (§3.3), żeby wyczerpanie budżetu importu nigdy nie zatrzymało moderacji. |

Wcześniejsza praca, która się przyda: pilot **#814**
(`docs/research/ai-pilots/RAPORT.md`) — model zwraca **wyłącznie granice
fragmentów i etykiety** (składnik / krok / uwaga / do sprawdzenia), a PHP
odtwarza tekst z oryginału. Mechaniczny zakaz dopisywania słów. Tam też
zaprojektowano trwały licznik z rezerwacją kosztu przed żądaniem
i `store: false`. Kod pilota nie jest na `main`; przenosimy **wymagania**.

### 1.2 Kolejki i zdjęcia

- Kolejka: Laravel database queue (`AGENTS.md` §3). Worker w osobnym
  kontenerze ma **proces na kolejkę**: `high default media low`
  (`docker/entrypoint.sh:457–470`, `listy_kolejek()`), w roli `all` — jeden
  proces na wszystkie.
- `ProcessUploadedImage` (kolejka `media`, `tries 3`, `timeout 120`, hook
  `failed()`, żeby zdjęcie nigdy nie zostało w `processing`) — przekodowanie
  zdejmuje EXIF/GPS, warianty `thumb/feed/large`. **Import zdjęcia NIE
  omija tego potoku**: najpierw normalny upload do `media`, dopiero gotowy
  wariant idzie do modelu.
- `PrzeanalizujTresc` na kolejce `low`.

### 1.3 Kreator przepisu

`resources/views/components/recipe-wizard.blade.php` — Livewire, trzy kroki
+ podgląd (1 „o przepisie”, 2 „składniki”, 3 „przygotowanie”, 4 „podgląd”).
Szkic zapisuje się po każdym kroku i po ~3 s bezczynności; jeden szkic na
sesję (`$recipeId` jest `#[Locked]`). Pola, które import wypełni:
`title`, `summary`, `servings`, `prep_minutes`, `cook_minutes`,
`ingredients[]` (wolny tekst na wiersz, `group_name`), `steps[]`,
`source_type` (`own|family|adaptation|external`), `source_url`,
`source_person`, `source_note`, `family_since_year` oraz
**`source_scan_media_id`** — zdjęcie kartki z zeszytu, które już dziś
zostaje przy przepisie, „bez OCR” (`docs/DATABASE.md:1662`). Kreator wymaga
JS, więc obok żyje formularz jednostronicowy (`/dodaj/przepis/jedna-strona`).

Model danych, który import musi uszanować:
- `recipe_ingredients.ingredient_text` — **tekst autora, pokazywany zawsze**;
  `quantity`/`unit_id`/`no_amount` są dodatkiem (D-017, D-033,
  `docs/DATABASE.md` § recipe_ingredients). Import wypełnia **tekst**; ilości
  tylko wtedy, gdy stoją w źródle dosłownie.
- `recipes.status = draft` istnieje (`Recipe::STATUS_DRAFT`); `status` nie
  jest w `$fillable` (AGENTS.md §7, D-006) — import nie ma jak go podnieść.
- Słownik `ingredients` (`canonical_name`, `normalized_name`) i `units`
  (15 jednostek, `unit_type` bez CHECK) — punkt zaczepienia dla wartości
  odżywczych (§8).

### 1.4 Polityka prywatności i rejestr

- `resources/legal/polityka-prywatnosci.md:55` — OpenAI w tabeli odbiorców
  z JEDNYM celem: automatyczne sprawdzanie publicznych treści.
- `:73` — obietnica: „wysyłamy wyłącznie treść **publiczną**”, miniaturę
  ≤ 320 px, bez e-maila, nazwy konta, IP.
- `:83` — OpenAI jako przekazanie poza EOG (DPF + SCC) i zobowiązanie:
  „jeśli dojdzie kolejny dostawca… dopiszemy go… **zanim trafi tam pierwszy
  rekord**”. Nowy **cel** u tego samego dostawcy wymaga tej samej staranności.
- `docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md` §3.7 — jedyna czynność
  z OpenAI; termin usunięcia po stronie OpenAI „zgodnie z jego warunkami”.
- `docs/legal/REJESTR_UMOW_POWIERZENIA.md` §2.5 — **umowy powierzenia (DPA)
  z OpenAI jeszcze nie ma**.
- `dziennik_zgod.cel` — zamknięty CHECK z JEDNĄ wartością
  `tygodniowy_digest` (`docs/DATABASE.md:3164`); druga zgoda = migracja
  i recenzja.

### 1.5 Zasady z AGENTS.md, które ten projekt musi spełnić

UX 50+ (§5: 18 px, 48 px, tekst pod ikoną, bez hover/swipe, błędy po polsku
z instrukcją, dane nie znikają), AI pomaga, nie zapełnia serwisu (§9), brak
Redisa i osobnych serwisów (§3), modularny monolit — nowy kod w
`app/Domain/Import/` i `app/Domain/Odzywcze/` (§4), UUID ≠ autoryzacja,
limity w `config/kuking.php` (§7), JS nie zostawia martwych przycisków
(D-053).

---

## 2. Architektura importu

### 2.1 Przepływ

```text
[Dodaj przepis] → wybór źródła: Zdjęcie kartki | Adres strony | Plik PDF | Wpiszę sam
      │
      ▼
ZlecImportPrzepisu (akcja domenowa, cienki kontroler przed nią)
  1. Policy: ImportPolicy::create (zalogowany, konto aktywne, nie zawieszone)
  2. przełącznik funkcji + przełącznik źródła        → jeśli wyłączone: przycisku nie ma w ogóle
  3. limit osoby (RateLimiter, config)               → polski komunikat, dane zostają
  4. idempotencja: klucz_wyslania (ADR_IDEMPOTENCJA_FORMULARZY)
  5. zapis wiersza `importy_przepisow` (status: oczekuje)
  6. dispatch(new OdczytajPrzepis($importId))->onQueue('import')
      │
      ▼
Ekran „Odczytujemy przepis…” (/import/{uuid}) — Policy: tylko właściciel
      │
      ▼  (worker, kolejka `import`)
OdczytajPrzepis (job, tries 3, timeout 120, failed() → status nieudany)
  a) ZDJĘCIE:  czeka, aż media = ready (ProcessUploadedImage już zdjął EXIF)
               → zgoda w dziennik_zgod aktualna? → budżet: REZERWACJA
               → KlientLuna (obraz ≤ 2000 px, JPEG z GD, bez metadanych)
  b) URL:      PobieraczStron (SSRF, §2.4) → JSON-LD Recipe? → parser LOKALNY, bez AI
               → brak JSON-LD: tekst strony (bez HTML, ≤ 12 000 znaków)
                 → budżet → KlientLuna w trybie „fragmentów” (#814)
  c) PDF:      pdftotext LOKALNIE → tekst? → parser/tryb fragmentów
               → PDF-skan bez tekstu: strony → obrazy → ścieżka (a)
  → rozliczenie budżetu z `usage` (zwolnienie nadwyżki rezerwacji)
  → UtworzSzkicZImportu: Recipe(status=draft, visibility=private,
       source_type/source_url/source_scan_media_id wymuszone) + wiersze
  → status: gotowy, recipe_id
  → powiadomienie w serwisie „Szkic przepisu jest gotowy do sprawdzenia”
      │
      ▼
Kreator (krok 1–3) w trybie „szkic z importu”: baner + oryginał obok
      │
      ▼
Człowiek poprawia → podgląd → [Opublikuj] (ZWYKŁE PublishRecipe, zwykła moderacja)
```

### 2.2 Zasady niepodważalne (i jak je sprawdza test)

1. **Import nigdy nie publikuje.** `UtworzSzkicZImportu` tworzy przepis
   wyłącznie ze `status = draft` i `visibility = private`. Test: żadna ścieżka
   joba nie kończy się `published`; test architektoniczny — w
   `app/Domain/Import/**` nie ma odwołania do `PublishRecipe`.
2. **Oryginał nigdy nie ginie.** Zdjęcie jest zapisane (`media`) ZANIM
   cokolwiek pójdzie do modelu i zostaje jako `source_scan_media_id`
   niezależnie od wyniku — także przy błędzie, limicie i wyłączonej funkcji.
3. **Model nie dopisuje faktów.** Dla źródeł tekstowych (URL bez JSON-LD,
   PDF z tekstem) model zwraca tylko granice fragmentów i etykiety; PHP
   odtwarza treść z oryginału (wymaganie z #814, pełne pokrycie tekstu,
   odrzucenie obcych pól). Dla OCR tego się nie da zagwarantować
   mechanicznie — dlatego OCR ma ekran z oryginałem obok (§6) i znaczniki
   niepewności.
4. **Brak ilości zostaje brakiem.** Model nie przelicza „szklanki” na gramy,
   nie uzupełnia „do smaku”, nie zgaduje liczby porcji.
5. **Awaria importu nie blokuje ręcznego dodania przepisu.** Ścieżka
   „Wpiszę sam” działa zawsze.

### 2.3 Nowe elementy (propozycja, do potwierdzenia w issues)

| Element | Rodzaj | Uwagi |
|---|---|---|
| `app/Domain/Import/` | akcje: `ZlecImportPrzepisu`, `UtworzSzkicZImportu`; `PobieraczStron`, `ParserJsonLdPrzepisu`, `TrybFragmentow`, `BudzetAi` | modularny monolit, bez osobnego pakietu |
| `app/Ai/KlientLuna.php` | klient HTTP (Laravel `Http`) | host `api.openai.com`, ścieżka `#^/v1/responses$#` w kodzie; `store: false`; brak narzędzi; wyjście w ustalonym schemacie JSON; `max_output_tokens` z configu |
| `app/Jobs/OdczytajPrzepis.php` | job | kolejka `import`; wzorzec `failed()` jak `ProcessUploadedImage` |
| tabela `importy_przepisow` | migracja | `id uuid`, `user_id` (FK, `ON DELETE CASCADE`), `zrodlo` CHECK (`zdjecie`/`url`/`pdf`), `status` CHECK (`oczekuje`/`w_toku`/`gotowy`/`nieudany`/`wstrzymany_limitem`), `kod_bledu` (zamknięta lista), `source_url text NULL`, `media_ids uuid[]` lub tabela łącząca, `recipe_id NULL` (`ON DELETE SET NULL`), `koszt_mikrousd bigint NULL`, `odpowiedz_modelu jsonb NULL` (retencja 30 dni, §5.4), `klucz_wyslania`, znaczniki czasu. `status` poza `$fillable`. |
| tabela `ai_budzet_dzienny` | migracja | `dzien date PK`, `zarezerwowano_mikrousd`, `wydano_mikrousd`, `liczba_wywolan`; blokada `SELECT … FOR UPDATE` — **PostgreSQL, bez Redisa** |
| `dziennik_zgod.cel` + `odczyt_ai` | migracja CHECK | `down()` **odmawia**, jeśli istnieją wiersze z tym celem (D-088) |
| kolejka `import` | `docker/entrypoint.sh`, Railway IaC | osobny proces, żeby 60-sekundowe odczyty nie stały przed moderacją (`low`) ani zdjęciami (`media`). **Koszt pamięci — pytanie P-11.** Alternatywa bez nowego procesu: `low`. |
| `notifications` — nowy typ `import_gotowy` | jeśli typy są zamkniętą listą — migracja | alternatywa: tylko ekran statusu i lista „Moje szkice” |

### 2.4 Pobieranie stron (URL) — wymagania bezpieczeństwa

Przenosimy listę z `docs/research/repos/mealie-recipes-mealie.md` §4 (R7):
sprawdzamy **adres IP po rozwiązaniu nazwy**; blokujemy prywatne, loopback,
link-local (`169.254.0.0/16`), multicast, zarezerwowane, CGNAT
`100.64.0.0/10`, IPv4 zapisane jako IPv6; **każde przekierowanie osobno**
(najwyżej 3); tylko `http/https`, porty 80/443; limit czasu 10 s; pobieranie
strumieniowe z limitem 2 MB; tylko `text/html`. Uczciwy `User-Agent`
(„KukingImport/1.0 (+https://kuking.pl/o-kukingu)”), **szanujemy
`robots.txt`**, bez podszywania się pod przeglądarkę i bez obchodzenia
zabezpieczeń (mealie.md, „nie przenosimy”). Adres i stronę pobiera **serwer
Kuking**, nie OpenAI — model nigdy nie dostaje adresu do samodzielnego
otwarcia (brak narzędzi `web_search`/`fetch`).

---

## 3. Konfiguracja modelu „GPT-6 Luna”

### 3.1 Nazwa modelu i klucz — w env/config, nie w kodzie

Proponowana sekcja `config/kuking.php` → `'import'` (obok `'moderation'`):

```php
'import' => [
    // Przełącznik główny. false = przyciski importu NIE są pokazywane (bez martwych przycisków, D-053).
    'wlaczony' => (bool) env('KUKING_IMPORT_WLACZONY', false),
    // Przełączniki źródeł — każde da się wyłączyć osobno (np. URL do decyzji prawnika).
    'zrodla' => [
        'zdjecie' => (bool) env('KUKING_IMPORT_ZDJECIE', true),
        'url'     => (bool) env('KUKING_IMPORT_URL', false),
        'pdf'     => (bool) env('KUKING_IMPORT_PDF', true),
    ],
    'model' => [
        // Brak klucza = odczyt AI wyłączony; JSON-LD i PDF z tekstem działają dalej.
        'klucz'       => env('OPENAI_IMPORT_KEY'),
        'endpoint'    => env('KUKING_IMPORT_ENDPOINT', 'https://api.openai.com/v1/responses'),
        // Nazwa handlowa wskazana przez właściciela: „GPT-6 Luna”.
        // Identyfikator API trzeba ODCZYTAĆ z listy modeli konta przed wdrożeniem (P-1).
        'nazwa'       => env('KUKING_IMPORT_MODEL'),
        'limit_czasu' => (int) env('KUKING_IMPORT_LIMIT_CZASU', 90),
        'max_wyjscie_tokenow' => (int) env('KUKING_IMPORT_MAX_WYJSCIE', 2500),
        // Cennik w mikro-USD za milion tokenów — wpisywany ręcznie z cennika OpenAI;
        // brak ceny = brak wywołań (nie da się zarezerwować budżetu).
        'cena_wejscie_mln' => env('KUKING_IMPORT_CENA_WEJSCIE'),
        'cena_wyjscie_mln' => env('KUKING_IMPORT_CENA_WYJSCIE'),
    ],
    'budzet' => [
        'dzienny_usd'    => (float) env('KUKING_IMPORT_BUDZET_DZIEN', 2.00),   // P-2
        'miesieczny_usd' => (float) env('KUKING_IMPORT_BUDZET_MIESIAC', 30.00), // P-2
    ],
    'limity' => [
        'na_osobe_dzien'    => (int) env('KUKING_IMPORT_NA_OSOBE_DZIEN', 5),
        'na_osobe_miesiac'  => (int) env('KUKING_IMPORT_NA_OSOBE_MIESIAC', 30),
        'zdjec_na_import'   => 4,
        'max_bok_px'        => 2000,
        'pdf_max_mb'        => 10,
        'pdf_max_stron'     => 5,
        'url_max_bajtow'    => 2_000_000,
    ],
],
```

Dlaczego tak:

- **Identyfikator modelu nie jest wpisany na sztywno.** Nazwa „GPT-6 Luna”
  jest nazwą wskazaną przez właściciela; identyfikatora API, cennika ani
  tego, czy model przyjmuje obrazy i PDF, **nie weryfikowałem** — w tej
  sesji nie ma klucza i nie wykonano żadnego żądania. Etap 0 (§9) to
  sprawdza. `KUKING_IMPORT_MODEL` puste = funkcja wyłączona, jak brak klucza.
- **Osobny klucz `OPENAI_IMPORT_KEY`**, najlepiej w osobnym projekcie
  OpenAI z **limitem wydatków ustawionym w panelu dostawcy** — to druga linia
  obrony, niezależna od naszego kodu. Klucz moderacji zostaje z uprawnieniem
  wyłącznie do `/v1/moderations` (`SprawdzModel.php:109`); łączenie ich
  rozszerzałoby uprawnienia klucza, który już jest na produkcji.
- **Adres dostawcy walidowany jak w #991/D-250**: lista hostów i wzór
  ścieżki w kodzie `KlientLuna`, nie w `.env`.
- Komenda diagnostyczna `kuking:sprawdz-import` na wzór `kuking:sprawdz-model`
  (klucz jest? model odpowiada? cennik wpisany? budżet dzisiaj?).

### 3.2 Przełącznik funkcji

Trzy poziomy, od najgrubszego: `KUKING_IMPORT_WLACZONY` (całość) →
`KUKING_IMPORT_{ZDJECIE,URL,PDF}` (źródło) → brak klucza/modelu/ceny (tylko
ścieżki AI; lokalne JSON-LD i PDF z tekstem działają). Gdy źródło jest
wyłączone, **przycisku nie ma** — nie pokazujemy przycisku, który powie
„niedostępne” dopiero po kliknięciu. Wyjątek: wyczerpany budżet (§3.4) — tam
przycisk jest, ale nad nim stoi informacja, zanim ktoś kliknie.

### 3.3 Budżet dzienny i miesięczny — PostgreSQL, rezerwacja przed wywołaniem

```text
BudzetAi::zarezerwuj(szacunek)   -- w transakcji, SELECT … FOR UPDATE na wierszu dnia
   szacunek = tokeny_wejścia(oszacowane z bajtów/obrazu) × cena_we + max_wyjscie × cena_wy
   jeśli zarezerwowano + wydano + szacunek > dzienny  → odmowa (status wstrzymany_limitem)
   jeśli suma miesiąca + szacunek > miesięczny         → odmowa
KlientLuna::odczytaj(...)
BudzetAi::rozlicz(rezerwacja, usage)  -- faktyczny koszt z `usage`; BRAK usage = rezerwacja zostaje jako wydana
```

- Rezerwacja **nie jest zwracana po błędzie sieci** (żądanie mogło dojść
  i zostać policzone) — tak jak w pilocie #814/#912. Lepiej zawyżyć niż
  przekroczyć.
- Próg ostrzegawczy 80% dziennego budżetu → jeden wpis `Log::warning` na
  kanał `blad_webhook` (raz dziennie, `Cache::add` jak w `KlientOpenAI`).
- **Moderacja nie jest w tym budżecie** i nie powinna być (jest bezpłatna,
  a jej zatrzymanie to ryzyko prawne, nie oszczędność).
- Limity na osobę: Laravel `RateLimiter` (cache na bazie danych — bez Redisa),
  klucze w `config/kuking.php` (AGENTS.md §7). Liczy się **zlecenie**, nie
  kliknięcie „Odśwież”.

### 3.4 Zachowanie przy awarii i limicie — komunikaty

| Sytuacja | Co widzi człowiek (po polsku, co zrobić) | Co dzieje się w systemie |
|---|---|---|
| Dzienny budżet serwisu wyczerpany | „Odczytywanie przepisów jest na dziś wstrzymane — wyczerpał się dzienny limit. **Twoje zdjęcie jest zapisane.** Możesz przepisać przepis sam już teraz albo wrócić jutro i kliknąć «Odczytaj ponownie».” | status `wstrzymany_limitem`, bez wywołania; zdjęcie zostaje |
| Limit osoby | „Dziś odczytaliśmy już 5 Twoich przepisów — to dzienny limit. Jutro rano będzie można dalej. Zdjęcie zostaje w szkicu.” | odmowa przed dispatch, dane formularza zostają (`old()`) |
| Chwilowa awaria (429, 5xx, timeout) | Ekran postępu mówi: „To trwa dłużej niż zwykle. Nie musisz czekać — damy znać, gdy szkic będzie gotowy.” | `release()` z opóźnieniem 30 s / 120 s, najwyżej 3 próby |
| Awaria po 3 próbach | „Nie udało się odczytać przepisu. **Nic nie zginęło** — zdjęcie jest zapisane. Kliknij «Spróbuj jeszcze raz» za kilka minut albo przepisz przepis sam.” | status `nieudany`, kod `model_niedostepny`, rezerwacja policzona |
| Zdjęcie nieczytelne (model zwraca „nie da się”) | „Nie umiemy odczytać tego zdjęcia. Zrób je w dziennym świetle, prosto z góry, tak żeby kartka wypełniała cały kadr — i spróbuj jeszcze raz.” | `nieudany`, kod `nieczytelne` |
| Strona blokuje pobranie / `robots.txt` / brak przepisu | „Z tej strony nie da się pobrać przepisu. Skopiuj tekst przepisu ze strony i wklej go w pole «Przygotowanie» — adres strony zapisaliśmy jako źródło.” | szkic z samym `source_url`, bez treści |
| Adres prywatny/nieprawidłowy (SSRF) | „Ten adres nie prowadzi do publicznej strony. Sprawdź, czy zaczyna się od https:// i czy otwiera się w przeglądarce.” | odmowa w walidacji, bez pobierania |
| Brak zgody `odczyt_ai` / wycofana | Przed pierwszym użyciem: ekran zgody (§5.3). Bez zgody: „Bez zgody na odczyt przez OpenAI możesz nadal dodać zdjęcie kartki do przepisu — przepiszesz go sam.” | brak wywołania |
| Klucz/model nie skonfigurowany na produkcji | Przycisków AI nie ma | `Log::warning` z `stage=import_disabled` (jak `sladBrakuKlucza`) |

Wszystkie komunikaty spełniają pięć kryteriów z `docs/UX_50_PLUS.md` § Błędy.

---

## 4. Prawo autorskie przy imporcie z URL

**Zasada: import z URL daje prywatny szkic dla siebie, nie treść do
republikacji.** Techniczna możliwość pobrania ≠ prawo do publikacji
(`docs/research/PUBLIC_REPOS.md` poz. 14, issue #28).

Proponowane reguły (wszystkie `[do weryfikacji z prawnikiem]` przed
włączeniem `KUKING_IMPORT_URL`):

1. **Szkic z URL jest zawsze prywatny** (`visibility = private`).
   Przechowanie kopii do własnego użytku mieści się w dozwolonym użytku
   osobistym (art. 23 pr. aut.) — **szkic nie jest nikomu pokazywany**.
2. **Źródło zapisane obowiązkowo**: `source_type = external`, `source_url`
   = adres po przekierowaniach, bez parametrów śledzących. Pole jest
   zablokowane w kreatorze dla szkicu z importu URL (zmiana typu źródła
   z `external` na `own` wymaga zmiany opisu — patrz 4).
3. **Zdjęć z cudzych stron nie importujemy w ogóle** — ani do szkicu, ani
   jako podgląd.
4. **Publikacja wymaga przepisania opisu własnymi słowami.** Lista
   składników zwykle nie jest utworem; opis przygotowania i wstęp — zwykle
   jest. Przy publikacji szkicu z URL: jeśli tekst kroków pokrywa się
   z pobranym w ≥ 60% (trigramy, `pg_trgm` już jest) — **ostrzeżenie**
   „Opis przygotowania jest prawie taki sam jak na stronie źródłowej. Napisz
   go własnymi słowami, zanim opublikujesz — tak mówią zasady nr 2”. Czy to
   ma być twarda blokada, czy ostrzeżenie — **P-10**.
5. **Bez masowego importu.** Jeden adres na zlecenie, limit na osobę,
   brak importu listy adresów, całych blogów, map witryn, kanałów RSS
   i archiwów. Poza prawem autorskim chroni to też przed naruszeniem prawa
   producenta bazy danych (ustawa o ochronie baz danych) i przed zamianą
   Kuking w agregator — co `AGENTS.md` §9 nazywa nieodwracalnym.
6. Szanujemy `robots.txt` i nie obchodzimy zabezpieczeń (§2.4).

### Lista do „Nie wcześnie” (`docs/FEATURES.md`)

Proponuję dopisać, obok istniejącego „masowy import cudzych treści”:

- import wielu adresów naraz, całych blogów, map witryn i kanałów RSS;
- import z serwisów wymagających logowania (Facebook, Instagram, grupy);
- automatyczne „przepisywanie własnymi słowami” przez AI przed publikacją
  (to byłoby pranie cudzej treści, nie pomoc);
- import zdjęć z cudzych stron;
- publiczna galeria „zaimportowane z…”;
- rozszerzenie przeglądarki / „udostępnij do Kuking” z dowolnej aplikacji.

---

## 5. RODO

### 5.1 Co może być na zdjęciu zeszytu

Zdjęcie zeszytu to **treść prywatna** i często zawiera **dane osób
trzecich**: imiona i nazwiska („sernik cioci Hani Kowalskiej”), adresy
i telefony zapisane na marginesie, daty, dedykacje, a czasem **dane
o zdrowiu** („dla Józka — bez cukru, cukrzyca”), które są szczególną
kategorią danych (art. 9 RODO). Samo pismo odręczne nie jest daną
biometryczną, dopóki nie przetwarzamy go w celu identyfikacji osoby — i nie
będziemy.

### 5.2 Konflikt z D-240 i proponowane rozstrzygnięcie

D-240: „do OpenAI ma wychodzić wyłącznie pomniejszona, publiczna treść;
awatary bez potwierdzonej zgody — nie wysyłać”. OCR łamie oba warunki
naraz: kartka jest prywatna, a 320 px nie wystarcza do odczytu pisma.
D-240 sam wskazuje drogę dla awatara: „Przywrócenie wymaga osobnej decyzji:
celu zgody, ekranu udzielania i wycofania, sprawdzenia przed każdą
wysyłką”. Proponuję tę samą konstrukcję dla importu:

- **nowa decyzja właściciela** (numer z dziennika w chwili wpisu) jako
  wąski wyjątek od D-240: prywatna treść wychodzi do OpenAI **wyłącznie na
  wyraźne żądanie autora tej treści, przy każdym zleceniu z osobna,
  i tylko po udzieleniu zgody `odczyt_ai`**;
- **podstawa prawna:** do rozstrzygnięcia (**P-4**). Rekomendacja: zgoda
  (art. 6 ust. 1 lit. a), zapisana w `dziennik_zgod`, bo (1) D-240 mówi
  „potwierdzona zgoda”, (2) przy danych osób trzecich i ryzyku art. 9 zgoda
  z jasną informacją jest najczystsza, (3) mechanizm już istnieje. Wariant
  alternatywny: art. 6 ust. 1 lit. b (usługa, o którą człowiek sam prosi)
  — prostszy, ale nie spełnia litery D-240;
- **sprawdzenie zgody tuż przed każdą wysyłką** (w jobie, nie tylko
  w formularzu) — jak `GranicaWysylki` czyta stan świeżo z bazy.

### 5.3 Co wysyłamy do OpenAI, a czego nie

| Wysyłamy | Nie wysyłamy |
|---|---|
| obraz kartki: wariant przekodowany u nas (GD → JPEG), dłuższy bok ≤ 2000 px, **bez EXIF/XMP/GPS**, wymiary sprawdzone z bajtów (jak D-240) | oryginału pliku, e-maila, nazwy konta, `display_name`, IP, identyfikatora przepisu, UUID-ów |
| przy URL bez JSON-LD: **czysty tekst** pobranej strony (bez HTML, skryptów, komentarzy czytelników), ≤ 12 000 znaków | adresu URL (model nie potrzebuje go do pracy), ciasteczek, nagłówków |
| stałą instrukcję i schemat odpowiedzi | historii innych importów |
| `store: false` | pola `user`/identyfikatora bezpieczeństwa — **domyślnie nie**; jeśli OpenAI go wymaga, tylko HMAC z sekretu serwera (**P-6**) |

Ekran zgody (raz, przed pierwszym użyciem) i przypomnienie przy każdym
zleceniu, prostym językiem:

> **Zdjęcie odczyta komputer firmy OpenAI (USA).** Wyślemy samo zdjęcie
> kartki — bez Twojego imienia, adresu e-mail i danych z aparatu.
> Jeśli na kartce są czyjeś dane (nazwisko, telefon, informacja
> o zdrowiu), zasłoń je przed zrobieniem zdjęcia. Zgodę możesz wycofać
> w ustawieniach — wtedy zdjęcia kartek dalej dodasz, tylko przepiszesz je
> sam.
>
> [ Zgadzam się, odczytujcie moje kartki ]   [ Nie, przepiszę sam ]

### 5.4 Retencja

| Dane | Gdzie | Jak długo |
|---|---|---|
| Zdjęcie kartki | `media` + `source_scan_media_id` | jak każda treść autora: do usunięcia przepisu/konta (istniejące ścieżki kasowania i eksportu) |
| Wiersz `importy_przepisow` (bez treści) | baza | 90 dni, potem usuwany komendą `kuking:sprzataj-importy` (wzorzec `app/Domain/Compliance`, `docs/decyzje/ADR_RETENCJE.md`) |
| `odpowiedz_modelu` (surowy JSON, do diagnozy błędów) | baza | **30 dni**, potem `NULL` — ta sama komenda |
| Tekst pobranej strony | tylko pamięć joba | nie zapisujemy; zostaje `source_url` |
| Po stronie OpenAI | OpenAI | według warunków API; `store: false` **nie jest** obietnicą zerowej retencji (pilot #912). Aktualny okres przechowywania na potrzeby nadużyć i możliwość Zero Data Retention — sprawdzić i wpisać **przed** startem (**P-5**) |

Eksport danych (`GenerateUserExport`) obejmuje listę importów (data,
źródło, adres, status); usunięcie konta kasuje je kaskadą.

### 5.5 Dokumenty do zmiany (przed pierwszym rekordem — polityka `:83`)

1. `resources/legal/polityka-prywatnosci.md` — drugi wiersz OpenAI w tabeli
   (`:55`): „Odczyt przepisu ze zdjęcia, pliku lub strony — **tylko gdy sam
   o to poprosisz**”; nowy akapit „Co wysyłamy przy odczycie przepisu”;
   korekta akapitu `:73` („wyłącznie treść publiczną” przestaje być prawdą
   bez zastrzeżenia) i `:83`; podbicie `kuking.zgody.wersja_polityki`.
2. `docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md` — nowa czynność §3.18
   „Odczyt przepisu na żądanie (OpenAI)”: cel, kategorie danych (w tym
   możliwe dane osób trzecich i art. 9 — przypadkowo), podstawa, odbiorca,
   przekazanie poza EOG, terminy z §5.4; aktualizacja tabel §4 i §5.
3. `docs/legal/REJESTR_UMOW_POWIERZENIA.md` §2.5 — **DPA z OpenAI podpisane
   przed włączeniem funkcji** (dziś brak) — twardy warunek.
4. Krótka ocena skutków (DPIA-lite) w `docs/legal/` — pełna DPIA z art. 35
   raczej nie jest wymagana (skala), ale nowa technologia + dane osób
   trzecich + transfer do USA uzasadniają spisanie ryzyk na jednej stronie.
5. `dziennik_zgod` — nowy `cel` (migracja CHECK, `docs/DATABASE.md`,
   rollback odmawiający przy istniejących wierszach, D-088).

---

## 6. UX 50+

### 6.1 Wejście

Na ekranie „Dodaj przepis” — **cztery duże przyciski jeden pod drugim**
(≥ 48 px, tekst ≥ 18 px, ikona zawsze z podpisem):

- **Przepisz z kartki lub zeszytu** — „zrób zdjęcie, a my odczytamy pismo”
- **Wklej adres strony** — „przepis zapiszemy dla Ciebie, prywatnie”
- **Dodaj plik PDF**
- **Wpiszę sam** — zawsze obecny (także gdy import jest wyłączony),
  tej samej wielkości co pozostałe

Aparat w telefonie: zwykłe `<input type="file" accept="image/*"
capture="environment">` — działa bez JS, bez gestów. Do czterech zdjęć
(przepis na dwóch stronach zeszytu). Wskazówka pod przyciskiem: „Połóż
kartkę na stole, przy oknie. Zdjęcie z góry, cała kartka w kadrze.”

### 6.2 Postęp

Osobny ekran `/import/{uuid}` z trzema krokami opisanymi słowami, nie
kręciołkiem:

```text
✓ Zdjęcie przyjęte
… Odczytujemy pismo — zwykle trwa to do minuty
  Szkic gotowy do sprawdzenia
```

- `aria-live="polite"`; odświeżanie co 5 s małym skryptem **tylko na tym
  ekranie** (nie `wire:poll` na ekranach często odwiedzanych, AGENTS.md §3).
- Bez JS: przycisk „Sprawdź, czy już gotowe” (zwykły link) — żaden przycisk
  nie jest martwy.
- „**Nie musisz czekać.** Możesz zamknąć tę stronę — szkic znajdziesz
  w «Moje szkice», a my damy znać powiadomieniem.”

### 6.3 Sprawdzanie wyniku — co jeśli OCR się myli

- Szkic otwiera się w **istniejącym kreatorze** (krok 1), z banerem na
  każdym kroku: „**Ten tekst odczytał komputer.** Porównaj każdą linijkę ze
  zdjęciem i popraw, co trzeba. Nic się nie opublikuje, dopóki sam nie
  klikniesz «Opublikuj».”
- **Oryginał obok tekstu** w krokach 2 i 3: na komputerze w kolumnie obok,
  na telefonie nad polem; dotknięcie otwiera zdjęcie w pełnym rozmiarze na
  osobnym ekranie z przyciskiem „Wróć do przepisu” (bez szczypania
  i przesuwania jako jedynej drogi). Działa przy 320 px i 200% powiększenia.
- **Niepewne słowa** model oznacza znacznikiem; w polu stoją jako
  `[?mąki?]`, a nad polem napis „3 słowa do sprawdzenia — oznaczone
  znakiem [?]” (nie tylko kolor). Publikacja z pozostawionym `[?` →
  komunikat walidacji przy polu i w podsumowaniu: „Sprawdź słowo oznaczone
  [?] w 2. składniku i usuń znaczniki.”
- **Model nie „poprawia babci”**: instrukcja każe przepisać dosłownie,
  z dawną pisownią i skrótami („1 szkl.”, „masła za 5 zł”). Poprawia
  człowiek, jeśli chce.
- Przyciski pod banerem: **„Odczytaj jeszcze raz”** (liczy się do limitu,
  pyta o potwierdzenie, bo nadpisze poprawki) i **„Wyczyść odczyt, przepiszę
  sam”** (zostawia zdjęcie, czyści pola — z potwierdzeniem, odsunięte od
  zwykłych akcji, AGENTS.md §5).
- Na podglądzie szkicu z importu: pole wyboru „Sprawdziłem odczytany tekst
  ze zdjęciem” przed „Opublikuj” (**P-12** — czy tego chcemy).

### 6.4 Formularz jednostronicowy

Kreator wymaga JS. Import bez JS: przyciski wejścia i ekran postępu działają
zwykłymi formularzami; gotowy szkic otwiera się też w `/dodaj/przepis/
jedna-strona` z tym samym banerem i zdjęciem nad formularzem.

---

## 7. Treść promptów i schemat odpowiedzi (zarys)

- **OCR:** „Przepisz DOSŁOWNIE tekst przepisu z obrazu. Nie poprawiaj
  pisowni, nie przeliczaj jednostek, nie dopisuj brakujących ilości ani
  kroków. Słowa, których nie jesteś pewien, oznacz. Jeśli obraz nie zawiera
  przepisu albo jest nieczytelny — zwróć `nieczytelne: true`.” Wyjście
  (JSON Schema, `strict`): `tytul?`, `porcje_tekst?`, `skladniki[]
  {tekst, grupa?, niepewne[]}`, `kroki[] {tekst, niepewne[]}`,
  `uwagi?`, `nieczytelne`.
- **Tryb fragmentów (URL bez JSON-LD, PDF z tekstem):** jak #814 — wyjście
  to wyłącznie `[{koniec: int, etykieta: skladnik|krok|uwaga|pomin}]`; PHP
  odtwarza i odrzuca odpowiedź przy niepełnym pokryciu.
- Wejście z treści użytkownika jest **danymi, nie instrukcją** — prompt to
  mówi, a schemat wyjścia nie ma pola na nic poza przepisem (obrona przed
  „zignoruj poprzednie polecenia” wpisanym na stronie).

---

## 8. Wartości odżywcze i koszt dania

### 8.1 Skąd dane: tabela składników, nie model

| Wariant | Za | Przeciw | Werdykt |
|---|---|---|---|
| **Model liczy kalorie z listy składników** | zero pracy z danymi | wynik niepowtarzalny (dwa odczyty = dwie liczby), zmyśla, płatny przy każdym wyświetleniu/zmianie, nie da się wyjaśnić „skąd ta liczba” | **odrzucone** |
| **Tabela składników z otwartego źródła + przeliczniki jednostek**, liczone w PHP | deterministyczne, darmowe po wdrożeniu, audytowalne, testowalne | praca przy mapowaniu ~300 najczęstszych składników i miar domowych | **rekomendowane** |
| Hybryda: model **proponuje** dopasowanie `ingredient_text` → pozycja tabeli dla nieznanych składników; zatwierdza człowiek w panelu (nie autor przy każdym przepisie) | przyspiesza budowę słownika | koszt jednorazowy, wymaga ekranu dla admina | opcjonalnie, w etapie 7 |

Źródła danych — licencję sprawdzić przed importem (**P-8**):

- **CIQUAL** (ANSES, Francja) — ~3 000 produktów, licencja otwarta Etalab
  (wymaga podania źródła); dobre pokrycie produktów podstawowych.
- **USDA FoodData Central** — domena publiczna (CC0).
- Polskie „Tabele składu i wartości odżywczej żywności” (IŻŻ/NIZP-PZH) —
  najlepiej dopasowane do polskiej kuchni, ale **chronione** — tylko na
  licencji.
- Open Food Facts (ODbL) — produkty z kodami kreskowymi, nie surowce;
  obowiązek „share-alike” dla bazy. Nie na start.

Model danych (szkic): `skladniki_odzywcze` (FK do `ingredients`, kcal,
białko, tłuszcz, węglowodany, błonnik, sól na 100 g, `zrodlo`,
`zrodlo_id`), `miary_domowe` (FK do `ingredients` + `units`: „1 szklanka
mąki pszennej = 130 g”, „1 średnia cebula = 110 g”) — bo gęstość zależy od
składnika, a `units.unit_type` dziś niczego nie rozstrzyga.

### 8.2 Kiedy liczymy, a kiedy uczciwie nie

Liczymy tylko wtedy, gdy **składniki pokrywające ≥ 90% masy przepisu** mają
dopasowanie i ilość w gramach po przeliczeniu. `no_amount` („sól do smaku”)
pomijamy jawnie. W przeciwnym razie:

> „Nie liczymy wartości odżywczych tego przepisu: 3 składniki nie mają
> ilości («tyle mąki, żeby ciasto było miękkie»). To nie błąd — tak się
> gotuje.”

To jest zgodne z D-017: wolny tekst autora jest ważniejszy od liczby.
Nigdy nie prosimy autora o dopisanie gramów „żeby kalkulator działał”.

### 8.3 Jak pokazujemy

Zwinięta sekcja pod składnikami: **„Szacunkowe wartości odżywcze (na
porcję)”** → ok. 450 kcal · białko ok. 18 g · tłuszcz ok. 20 g ·
węglowodany ok. 50 g. Zaokrąglenia do 10 kcal i 1 g; słowo **„szacunek”**
w nagłówku; link „Jak to liczymy” (źródło danych, data, zasada 90%).

Czego **nie** robimy (bez porad zdrowotnych):

- żadnych określeń „zdrowe”, „dietetyczne”, „lekkie”, „dla cukrzyków”,
  „fit”, „niski IG” — ani w interfejsie, ani w tagach generowanych
  z wartości (oświadczenia żywieniowe i zdrowotne reguluje rozporządzenie
  (WE) 1924/2006 — nie jesteśmy producentem, ale nie ma powodu udawać, że
  wiemy więcej niż tabela);
- żadnego filtrowania „poniżej 500 kcal” ani sortowania po kaloriach
  na start (przesuwa produkt w stronę aplikacji dietetycznej);
- żadnego profilu diety, alergii czy celów wagowych użytkownika — to byłyby
  dane o zdrowiu (art. 9) → lista „Nie wcześnie”.

Autor może ukryć sekcję przy swoim przepisie (**P-8**).

### 8.4 Koszt dania

- Ceny: **średnie ceny detaliczne GUS** dla ~50 podstawowych produktów
  (dane publiczne, aktualizowane cyklicznie) + ręczne uzupełnienie
  najczęstszych braków, z datą.
- Obliczenie jak wyżej: tylko przy pokryciu ≥ 90% masy; wynik jako
  **przedział**, nie grosz: „**Orientacyjny koszt: ok. 15–20 zł za całość**
  (ceny średnie z GUS, sierpień 2026). W Twoim sklepie może być inaczej.”
- Aktualizacja cen: komenda artisan uruchamiana ręcznie raz na kwartał
  (**P-9**), bez scrapowania sklepów.
- Bez AI. Bez porównań sklepów, bez linków afiliacyjnych.

---

## 9. Plan etapów i szacunek pracy

Szacunki w dniach pracy jednego wykonawcy (agent + przegląd), bez czasu
oczekiwania na decyzje i prawnika. Każdy etap kończy się `./scripts/check.sh`
na zielono i PR-em.

| Etap | Zakres | Szacunek | Warunek startu |
|---|---|---|---|
| **0. Decyzje i pomiar** | Odpowiedzi na §11; odczytanie identyfikatora „GPT-6 Luna” i cennika; **30 prawdziwych zdjęć kartek** (różne pisma, ołówek, zniszczone kartki) → pomiar odsetka błędnych znaków, czasu i kosztu na import; próg go/no-go (np. ≥ 90% poprawnych linijek składników) | 2 dni | klucz testowy, zdjęcia od właściciela (P-13) |
| **1. Fundament** | `config('kuking.import')`, `KlientLuna` (lista hostów, `store:false`, trzy wyniki), `BudzetAi` + tabela, `importy_przepisow`, job + kolejka, `kuking:sprawdz-import`, zgoda `odczyt_ai` (migracja + ekran + wycofanie), aktualizacja `AGENTS.md` §12 i `FEATURES.md` | 4 dni | etap 0, decyzja D-… |
| **2. Prawo i prywatność** | polityka, rejestr, DPA, DPIA-lite, wersja polityki | 1 dzień + prawnik | równolegle z 1; **blokuje włączenie na produkcji** |
| **3. OCR ze zdjęcia** | wejście, upload przez istniejący potok, ekran postępu, `UtworzSzkicZImportu`, baner i oryginał w kreatorze i formularzu jednostronicowym, znaczniki `[?]`, komunikaty z §3.4 | 5 dni | 1, 2 |
| **4. Import z URL** | `PobieraczStron` (SSRF), `ParserJsonLdPrzepisu` (lokalnie), tryb fragmentów, reguły z §4, ostrzeżenie o podobieństwie przy publikacji | 4 dni | 1, decyzja P-3 i prawnik |
| **5. PDF** | `pdftotext`/`pdftoppm` (pakiet systemowy `poppler-utils` w obrazie Dockera — nie zależność Composera), PDF-tekst → parser/fragmenty, PDF-skan → ścieżka OCR, limity stron i MB | 2–3 dni | 3 |
| **6. Badanie z ludźmi** | 5–8 osób 50+, zadanie „przepisz kartkę z zeszytu” (`docs/UX_50_PLUS.md` § Testy z użytkownikami) | 2 dni (właściciel) | 3 |
| **7. Wartości odżywcze** | tabele, import ~300 pozycji z wybranego źródła, miary domowe, kalkulator w PHP, sekcja na stronie przepisu, „Jak to liczymy”, opcjonalnie panel dopasowań | 6 dni | P-8 |
| **8. Koszt dania** | ceny GUS, komenda aktualizacji, przedział na stronie przepisu | 2–3 dni | 7, P-9 |

**Razem:** ok. 28–30 dni pracy (import ≈ 18, odżywcze + koszt ≈ 9, badanie
≈ 2). Etapy 7–8 nie zależą od modelu i mogą iść równolegle z 3–5.

Szacunek kosztu API: **nieznany do etapu 0** — nie ma cennika „GPT-6 Luna”
w repozytorium ani w tej sesji. Dla porządku rzędów wielkości: pilot #912
przyjął 0,01 USD rezerwacji na próbę tekstową małym modelem; odczyt
obrazu większym modelem będzie droższy. Budżet 2 USD/dzień z §3.1 to
propozycja do potwierdzenia (P-2), nie wyliczenie.

---

## 10. Ryzyka

| Ryzyko | Skutek | Środek |
|---|---|---|
| Model źle czyta polskie pismo odręczne | frustracja, błędne przepisy | etap 0 z progiem go/no-go; oryginał obok; `[?]`; publikuje tylko człowiek |
| Koszt wymyka się spod kontroli | rachunek | rezerwacja przed wywołaniem, budżet dzienny i miesięczny, limit osoby, limit wydatków w panelu OpenAI |
| Dane osób trzecich / art. 9 na kartkach | naruszenie RODO | zgoda + ostrzeżenie „zasłoń dane”, minimalizacja, DPA, retencja 30 dni |
| Import URL jako furtka do kopiowania cudzych treści | spór prawny, utrata autentyczności | prywatny szkic, źródło obowiązkowe, bez zdjęć, ostrzeżenie o podobieństwie, bez masowego importu |
| SSRF przez pobieranie adresów | wyciek z sieci wewnętrznej Railway | §2.4, testy na każdy zakres adresów |
| Wstrzyknięcie poleceń w treści strony | model robi coś innego niż odczyt | brak narzędzi, sztywny schemat wyjścia, tryb fragmentów |
| Długie odczyty zatykają worker | opóźnienie moderacji i zdjęć | osobna kolejka `import` |
| Wartości odżywcze brane za poradę medyczną | odpowiedzialność, zaufanie | „szacunek”, bez oświadczeń zdrowotnych, bez filtrów dietetycznych |

---

## 11. Pytania do właściciela

- **P-1.** Jaki jest dokładny identyfikator API modelu „GPT-6 Luna” i czy
  konto ma do niego dostęp? Czy przyjmuje obrazy i PDF? (Bez tego etap 0
  nie ruszy.)
- **P-2.** Limit wydatków na API: proponuję **2 USD dziennie, 30 USD
  miesięcznie** i 5 importów na osobę dziennie / 30 miesięcznie. Jakie kwoty
  akceptujesz? Czy ustawiamy dodatkowo twardy limit w panelu OpenAI?
- **P-3.** **Czy import z adresu strony w ogóle?** Rekomendacja: tak, ale
  jako ostatni z trzech, wyłączony przełącznikiem do czasu opinii prawnika.
  Alternatywa: tylko zdjęcie i PDF.
- **P-4.** Podstawa wysyłki prywatnego zdjęcia do OpenAI: zgoda w
  `dziennik_zgod` (rekomendacja, zgodna z literą D-240) czy wykonanie
  usługi? To jest wyjątek od D-240 — potrzebna Twoja decyzja do dziennika.
- **P-5.** Czy podpisujemy DPA z OpenAI **przed** włączeniem (warunek
  konieczny w tym projekcie) i czy wnioskujemy o Zero Data Retention?
- **P-6.** Osobny klucz i osobny projekt OpenAI dla importu (rekomendacja)?
  Czy zgoda na wysyłanie pseudonimowego identyfikatora (HMAC), jeśli
  OpenAI będzie go wymagać do ochrony przed nadużyciami?
- **P-7.** Dla kogo na start: wszyscy zalogowani czy grupa testowa
  (np. konta założone przed datą X / ręczna lista)?
- **P-8.** Wartości odżywcze: które źródło (CIQUAL/USDA za darmo, czy
  licencja na polskie tabele IŻŻ)? Pokazywać domyślnie zwinięte czy tylko
  na życzenie? Czy autor może je ukryć przy swoim przepisie?
- **P-9.** Koszt dania: czy w ogóle (rekomendacja: tak, jako przedział)?
  Ceny GUS raz na kwartał wystarczą?
- **P-10.** Publikacja szkicu z URL z opisem prawie identycznym ze źródłem:
  twarda blokada czy ostrzeżenie?
- **P-11.** Zgoda na osobną kolejkę `import` (dodatkowy proces w kontenerze
  workera, więcej pamięci na Railway), czy wystarczy `low`?
- **P-12.** Pole „Sprawdziłem odczytany tekst ze zdjęciem” przed publikacją
  szkicu z OCR — tak czy nie?
- **P-13.** Kto dostarczy ~30 zdjęć prawdziwych kartek do pomiaru w etapie 0
  i czy mogą zawierać dane osób trzecich (wtedy pomiar też wymaga zgody
  i DPA)?

---

## 12. Proponowane issues (z kryteriami akceptacji)

Kolejność = kolejność pracy. Etykieta `V2`, priorytet po decyzji
właściciela (dziś #28 jest P2).

### I-0 · Decyzja: import V2 i wyjątek od D-240
- [ ] Odpowiedzi na P-1…P-13 zapisane jako decyzja w dzienniku decyzji.
- [ ] `AGENTS.md` §12 i `docs/FEATURES.md` zaktualizowane (OCR i import
      wychodzą z „nie budujemy teraz”; lista „Nie wcześnie” z §4 dopisana).
- [ ] #28 podzielone na issues poniżej i zamknięte odsyłaczem.

### I-1 · Pomiar OCR „GPT-6 Luna” na 30 kartkach (etap 0)
- [ ] Identyfikator API, cennik i obsługa obrazu/PDF odczytane i zapisane z datą.
- [ ] Wyniki: odsetek poprawnych linijek składników i kroków, czas p50/p95,
      koszt na import (z `usage`) — w `docs/research/`.
- [ ] Rekomendacja go/no-go względem progu z I-0.
- [ ] Żadne zdjęcie nie trafia do gita; skróty SHA-256 w dowodach.

### I-2 · Konfiguracja, klient i budżet AI (bez UI)
- [ ] `config('kuking.import')` jak w §3.1; brak klucza/modelu/ceny = zero żądań (test z `Http::preventStrayRequests`).
- [ ] `KlientLuna` odrzuca inny host/ścieżkę/port/query (testy jak `DozwolonyHostApi`); wysyła `store: false`; nie wysyła e-maila, nazwy, IP (test na ciele żądania).
- [ ] Trzy wyniki: sukces / `null` / chwilowa niedostępność; 429/5xx/timeout → `release()`, najwyżej 3 próby.
- [ ] `BudzetAi`: rezerwacja pod `FOR UPDATE`; test równoległy (dwa połączenia, `tests/Dwa`) nie przekracza budżetu; brak `usage` = rezerwacja wydana.
- [ ] Migracje + `docs/DATABASE.md` + rollback; `status` poza `$fillable`.
- [ ] `kuking:sprawdz-import` mówi po polsku, czego brakuje.
- [ ] Kontrola negatywna: usunięcie sprawdzenia budżetu → test czerwony.

### I-3 · Zgoda `odczyt_ai`
- [ ] Nowy `cel` w CHECK `dziennik_zgod_cel_check`; `down()` odmawia przy istniejących wierszach (D-088).
- [ ] Ekran zgody z §5.3, wycofanie w ustawieniach; oba zdarzenia w dzienniku z `wersja_polityki`.
- [ ] Job sprawdza zgodę świeżo z bazy tuż przed wysyłką; wycofanie między zleceniem a wysyłką = zero żądań (test).

### I-4 · Polityka prywatności, rejestr, DPA, DPIA-lite
- [ ] Zmiany z §5.5 pkt 1–4; wersja polityki podbita.
- [ ] Przełącznik `KUKING_IMPORT_WLACZONY` nie może być `true` na produkcji bez wpisu DPA w rejestrze (kontrola w `kuking:sprawdz-import`).

### I-5 · OCR ze zdjęcia kartki → szkic
- [ ] Wejście z §6.1; do 4 zdjęć; zdjęcia przechodzą przez `ProcessUploadedImage` przed wysyłką; do modelu idzie JPEG ≤ 2000 px bez metadanych (test z bajtów).
- [ ] Wynik: `Recipe` `draft` + `private` + `source_scan_media_id`; **żadna ścieżka nie daje `published`** (test).
- [ ] Zdjęcie zostaje przy każdym błędzie, limicie i wyłączonej funkcji (testy na każdy kod błędu).
- [ ] Ekran postępu z `aria-live`, działa bez JS (link „Sprawdź, czy już gotowe”).
- [ ] Baner, oryginał obok pól w krokach 2–3 i w formularzu jednostronicowym; znaczniki `[?]` blokują publikację z komunikatem przy polu i w podsumowaniu.
- [ ] Komunikaty z §3.4 dosłownie w testach; `node scripts/dostepnosc.mjs` bez naruszeń; 320 px i 200%.
- [ ] Idempotencja: podwójne wysłanie formularza = jeden import.
- [ ] Policy: cudzy import pod UUID → 404/403 (test).

### I-6 · Import z adresu strony
- [ ] SSRF: testy na każdy zakres z §2.4, IPv4-w-IPv6, przekierowanie na adres prywatny, limit rozmiaru i czasu.
- [ ] JSON-LD `Recipe` parsowany lokalnie, **bez wywołania modelu** (test: zero żądań do OpenAI).
- [ ] Fallback przez tryb fragmentów; odpowiedź z niepełnym pokryciem odrzucona.
- [ ] `source_type = external`, `source_url` wymuszone; zdjęcia ze strony nie są pobierane (test: żadne żądanie o obraz).
- [ ] Szanowany `robots.txt`; uczciwy `User-Agent`.
- [ ] Ostrzeżenie/blokada przy publikacji opisu podobnego ≥ 60% (wg P-10).
- [ ] Brak jakiejkolwiek drogi importu wielu adresów naraz.

### I-7 · Import PDF
- [ ] `poppler-utils` w obrazie Dockera, sprawdzenie w `iac.test.mjs`/entrypoincie.
- [ ] PDF z tekstem — lokalnie, bez modelu; PDF-skan — strony jako obrazy ścieżką I-5.
- [ ] Limity 10 MB / 5 stron z polskim komunikatem; PDF z hasłem lub uszkodzony → komunikat, co zrobić.

### I-8 · Retencja importów
- [ ] `kuking:sprzataj-importy`: `odpowiedz_modelu` → `NULL` po 30 dniach, wiersz usunięty po 90; budżet partii jak `kuking.retencja.budzet`.
- [ ] Eksport danych zawiera listę importów; usunięcie konta je kasuje (test).

### I-9 · Tabela wartości odżywczych i miary domowe
- [ ] Źródło i licencja zapisane (P-8); import ~300 pozycji komendą, idempotentny.
- [ ] Migracje + `docs/DATABASE.md`; CHECK na wartości ≥ 0.
- [ ] Miary domowe per składnik; test „1 szklanka mąki” ≠ „1 szklanka cukru”.

### I-10 · Szacunkowe wartości odżywcze na stronie przepisu
- [ ] Liczone w PHP, deterministycznie; test na przepisie wzorcowym z ręcznym wyliczeniem.
- [ ] Pokrycie < 90% masy → komunikat z §8.2, bez liczby (test).
- [ ] `no_amount` pominięte jawnie; nagłówek zawiera słowo „szacunek”; link „Jak to liczymy”.
- [ ] Test negatywny słownictwa: w widoku nie ma „zdrowe”, „dietetyczne”, „fit”, „dla cukrzyków”.
- [ ] Autor może ukryć sekcję (jeśli P-8 = tak).

### I-11 · Orientacyjny koszt dania
- [ ] Ceny GUS z datą; komenda aktualizacji; przedział „ok. X–Y zł”.
- [ ] Pokrycie < 90% → brak kwoty z wyjaśnieniem.
- [ ] Bez AI, bez linków do sklepów (test: brak zewnętrznych odnośników w sekcji).

---

## 13. Źródła w repozytorium

`AGENTS.md` §3, §4, §5, §7, §9, §12 · `docs/FEATURES.md` § V2 i „Nie
wcześnie” · `config/kuking.php:3006–3043` · `app/Moderacja/KlientOpenAI.php`
· `app/Moderacja/GranicaWysylki.php` · `app/Moderacja/OcenaModelem.php`
· `app/Jobs/PrzeanalizujTresc.php` · `app/Jobs/ProcessUploadedImage.php`
· `app/Support/DozwolonyHostApi.php` · `app/Console/Commands/SprawdzModel.php`
· `docker/entrypoint.sh` (`listy_kolejek()`) ·
`resources/views/components/recipe-wizard.blade.php` ·
`app/Domain/Recipes/TekstNaWiersze.php` · `docs/DATABASE.md` (recipes,
recipe_ingredients, ingredients + units, dziennik_zgod) ·
`docs/DECISIONS.md` D-017, D-033, D-055, D-240, D-241 ·
`resources/legal/polityka-prywatnosci.md:55,73,83` ·
`docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md` §3.7 ·
`docs/legal/REJESTR_UMOW_POWIERZENIA.md` §2.5 ·
`docs/research/ai-pilots/RAPORT.md` (#813, #814, #815, #912) ·
`docs/research/PUBLIC_REPOS.md` poz. 13–14 ·
`docs/research/repos/mealie-recipes-mealie.md` §3–4 · issue #28.
