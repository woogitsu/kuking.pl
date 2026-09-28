# Koszt `/odkryj` i strony głównej dla gościa (#1952)

Pomiar lokalny z 28 września 2026, baza kodu `77ce1a87e`. Cel: ustalić, ile
kosztuje jedno anonimowe wejście na publiczny feed i jakie limity już go
chronią, zanim dobierzemy próg.

## Wniosek w trzech zdaniach

1. Koszt rośnie **liniowo z liczbą publicznych wpisów** i jest taki sam na
   każdej stronie kursora: `row_number()` liczy się na całym zbiorze, zanim
   zapytanie odetnie 15 wierszy. Przy 100 tys. wpisów to ok. 130–175 ms
   samego SQL na jedno żądanie.
2. `/odkryj` miało już limit (`limits.discover`, 60/min, PR #1955), ale
   **strona główna `/` dla gościa woła to samo zapytanie i nie miała żadnego
   limitu** — automat nie musiał nawet szukać `/odkryj`.
3. Wdrożono jeden nowy koszyk: `limits.landing` = 120/min na trasie `landing`.
   Cache pierwszej strony nie jest dziś uzasadniony pomiarem przy obecnej
   skali i wymagałby zmian w plikach, które zmieniał PR #1786 (już scalony, b50c8769e) — to etap 2.

## Środowisko

- PostgreSQL 18.6 lokalnie (127.0.0.1:5432), baza `kuking_test_1952`, JIT
  włączony z domyślnymi progami. Maszyna: 4 CPU, współdzielona z innymi
  procesami — liczby mówią o **proporcjach**, nie o pojemności produkcji.
- Żądania przez kernel HTTP Laravela w teście PHPUnit (`$this->get()`),
  bez TLS, bez Cloudflare, bez assetów. Limiter wyłączony na czas pomiaru,
  żeby mierzyć zapytanie, nie 429.
- Dane z fabryk: pusta baza; 300 wpisów / 60 autorów (`Post::factory()`);
  20 300 wpisów / 500 autorów i 100 300 wpisów / 2000 autorów (dopisane
  `INSERT … SELECT generate_series`, bo fabryka przy tej liczbie jest za
  wolna). Po każdym etapie `ANALYZE`. Wpisy z fabryk nie mają zdjęć ani
  tagów, więc liczba zapytań jest dolną granicą — eager loading zdjęć,
  przepisu i tagów dokłada **stałą** liczbę zapytań na stronę, nie N+1.
- Czas: mediana z 5 żądań po jednym rozgrzewającym. „SQL" = suma czasów
  zapytań z `DB::listen`, „całość" = czas żądania w kernelu.
- EXPLAIN (ANALYZE, BUFFERS) na głównym zapytaniu z `row_number()`,
  z tymi samymi parametrami, które wysłała aplikacja.

Skrypt pomiaru nie jest częścią repozytorium (tymczasowy test PHPUnit,
usunięty po pomiarze); procedura wyżej wystarcza, żeby go odtworzyć.

## Wyniki

| Zbiór | Trasa | Zapytań | SQL [ms] | Całość [ms] | EXPLAIN Execution [ms] |
|---|---|---:|---:|---:|---:|
| 0 wpisów | `/odkryj` s. 1 | 2 | 1,8 | 7,3 | 0,3 |
| 0 wpisów | `/` gość | 4 | 2,4 | 8,8 | 0,1 |
| 300 / 60 | `/odkryj` s. 1 | 6 | 3,8 | 32,7 | 0,7 |
| 300 / 60 | `/odkryj` s. 2–5 | 6 | 3,6–3,8 | 32,4–33,5 | 0,6–1,0 |
| 300 / 60 | `/` gość | 8 | 4,4 | 25,7 | 0,6 |
| 20 300 / 500 | `/odkryj` s. 1 | 6 | 25,6 | 55,7 | 31,1 |
| 20 300 / 500 | `/odkryj` s. 2–5 | 6 | 25,6–25,9 | 55,3–56,3 | 30,6–31,2 |
| 20 300 / 500 | `/` gość | 8 | 26,3 | 47,8 | 59,1* |
| 100 300 / 2000 | `/odkryj` s. 1 | 6 | 125,8 | 169,4 | 177,0 |
| 100 300 / 2000 | `/odkryj` s. 2–5 | 6 | 136,8–176,0 | 171,7–214,1 | 169,9–184,2 |
| 100 300 / 2000 | `/` gość | 8 | 130,5 | 159,0 | 184,3 |

\* pojedynczy odczyt EXPLAIN (bez mediany), obciążony współdzieloną maszyną;
mediana SQL tej samej trasy to 26,3 ms.

### Co mówi plan

- Przy 20 tys. wpisów: `Seq Scan on posts` → `Sort (author_id, published_at
  DESC, id DESC)` quicksort 1,9 MB w pamięci → `WindowAgg` na **wszystkich**
  20 300 wierszach → `Hash Join` z `posts` → `top-N heapsort` do 16 wierszy.
- Przy 100 tys.: planista przechodzi na indeks `posts_author_published_idx`
  (bez osobnego sortowania), ale `WindowAgg` dalej przechodzi przez
  wszystkie 100 300 wierszy (ok. 90–100 ms samej numeracji).
- Kolejne strony kursora **nie są tańsze**: warunek kursora stoi na
  zewnątrz podzapytania z numeracją, a `published_at <= chwila` niczego
  przy ustabilizowanym zbiorze nie odcina. Dlatego limit liczy każde
  kliknięcie „Pokaż więcej" tak samo jak pierwszą stronę.
- JIT się nie włączył (koszt planu poniżej `jit_above_cost` = 100 000), więc
  pułapka z #585 (260 ms kompilacji) tutaj jeszcze nie występuje — przy
  kilkukrotnie większym zbiorze może.

Skala na dziś: 1000 publicznych wpisów ≈ 1,3–1,7 ms SQL na żądanie.

## Co już chroniło ruch (stan przed tą zmianą)

| Warstwa | Co | Źródło |
|---|---|---|
| Laravel, `/odkryj` | `throttle:60,1,discover` — gość po adresie, zalogowany po koncie | `config/kuking.php` `limits.discover`, `routes/web.php` (PR #1955) |
| Laravel, `/szukaj`, `/pytania` | `throttle:{search}` | `limits.search` |
| Laravel, `/` | **brak** | — |
| Laravel, rejestr limiterów | nazwany limiter tylko `api` (`ApiServiceProvider`); strony `web` używają wyłącznie `throttle:<próby>,<minuty>,<prefiks>`, czego pilnuje `LicznikiLimitowNieMieszajaSieMiedzyTrasamiTest` | `app/Providers/ApiServiceProvider.php` |
| Log 429 | `Limit zapytań zadziałał` z nazwą trasy i „po koncie / po adresie", **bez IP** | `bootstrap/app.php` |
| Cloudflare cache HTML | `KUKING_HTML_EDGE_CACHE_SECONDS` (domyślnie 0 = wyłączone), obejmuje `landing`, nie `/odkryj` | `PublicznyHtmlGoscia`, `CLOUDFLARE_CACHE_597_610.md` |
| Cloudflare WAF | Managed Ruleset, Bot Fight Mode, jedna reguła rate limiting — w runbooku na POST na stronę logowania (plan Free ma mało reguł) | `DEPLOYMENT_RUNBOOK.md` §10.6, `INFRA_DECISION.md` |

Stanu paneli Cloudflare i Railway nie sprawdzano — tylko dokumenty.

Uwaga przy okazji: runbook §10.6 każe ustawić regułę Cloudflare na ścieżkę
„/logowanie", a formularz logowania w aplikacji to `/login`. Jeśli reguła
w panelu została przepisana z runbooka, nie łapie niczego — do sprawdzenia
przez właściciela w panelu (poza zakresem #1952).

## Wdrożona ochrona

- Trasa `landing` (`/`): `throttle:{$limits['landing']},landing`,
  `limits.landing = '120,1'`. Zalogowany ma koszyk po koncie, więc cudza
  seria z tego samego adresu go nie odcina.
- `/odkryj` bez zmian: 60/min.
- 429 to ta sama polska strona co wszędzie („Za dużo prób… Spróbuj ponownie
  za 1 min."), z `Retry-After`; wpis w logu jak przy każdym 429.
- Bez nowych identyfikatorów, ciasteczek, odcisków urządzeń i bez
  dodatkowego zapisu IP — klucz koszyka to ten sam skrót, którego
  `ThrottleRequests` Laravela używa na każdej innej publicznej trasie.

### Dlaczego te progi

- **Człowiek.** Jedno kliknięcie „Pokaż więcej" to jedno żądanie; osoba,
  która czyta karty, klika co kilka–kilkanaście sekund, czyli 5–12 razy na
  minutę. Cztery osoby za jednym NAT-em, każda w swoim tempie, mieszczą się
  w 60/min na `/odkryj`. Test `PublicznyFeedLimitGosciTest` przechodzi
  wejście na stronę główną i 14 stron „Pokaż więcej" w jednej chwili, bez
  żadnej przerwy — surowiej niż w życiu.
- **Strona główna dwa razy więcej niż `/odkryj`.** To jedyne wejście na
  serwis i cel powrotów z każdej strony (logo, „Wróć na stronę główną",
  wylogowanie). Jeśli `KUKING_ZAUFANE_PRZESKOKI` jest ustawione za nisko
  (`config/proxy.php`), aplikacja widzi adres proxy zamiast adresu osoby —
  wtedy wiele osób dzieli koszyk i to tutaj bolałoby najbardziej. 120/min
  to wciąż dwa żądania na sekundę bez przerwy — ciągłe odpytywanie,
  którego człowiek nie robi.
- **Automat.** Przy 100 tys. wpisów pełny koszyk jednego adresu to ok.
  120 × 130 ms ≈ 16 s pracy bazy na minutę na stronie głównej i 60 × 140 ms
  ≈ 8 s na `/odkryj` — duży, ale skończony koszt; bez limitu nie było
  żadnej granicy. Przy dzisiejszej skali (setki, nie dziesiątki tysięcy
  wpisów) to ułamki sekundy na minutę.

Limit per adres **nie chroni przed ruchem rozproszonym** (wiele adresów).
To jest zadanie brzegu — niżej.

## Etap 2 — do decyzji, nie wdrożone

1. **Cache HTML strony głównej na brzegu (rekomendowane jako pierwsze).**
   Infrastruktura już jest (#610): `KUKING_HTML_EDGE_CACHE_SECONDS=120` na
   stagingu, potem produkcja, według procedury z
   `CLOUDFLARE_CACHE_597_610.md`. Gość bez ciasteczek dostaje wtedy `/`
   z Cloudflare, a ten limiter liczy tylko żądania, które doszły do
   aplikacji. Koszt: zero złotych, ale zmiana zmiennej w Railway i odbiór
   nagłówków — decyzja właściciela (panel).
2. **Reguła rate limiting w Cloudflare na `/odkryj` i `/`** (np. 300 żądań
   / 1 min / IP → Managed Challenge, nie Block). Zaleta: łapie ruch zanim
   dotrze do Railway, także przez adresy spoza kontenera. Wada: plan Free
   ma bardzo mało reguł, a jedyna jest przewidziana dla POST na stronę logowania
   (runbook §10.6); druga reguła może wymagać płatnego planu.
   Rekomendacja: **nie teraz** — dopiero gdy log `Limit zapytań zadziałał`
   z trasą `landing` albo `discover` pokaże powtarzalne odbicia.
3. **Cache pierwszej strony w aplikacji** (np. 30–60 s, tylko gość, klucz
   bez stanu widza). Pomiar go dziś nie uzasadnia (poniżej 30 ms SQL przy
   20 tys. wpisów) i wymaga edycji `DiscoverFeed` / `FeedController`,
   które zmieniał PR #1786 (już scalony). Wrócić po pomiarze
   z produkcyjną liczbą wpisów.
4. **Zapytanie bez numeracji całego zbioru** (np. `row_number()` tylko na
   oknie czasowym albo materializowana runda). To zmiana zachowania rotacji
   autorów z #1807 — osobne zgłoszenie, nie limit.
5. **Monitoring czasu SQL.** Dziś widać odbicia 429 w logu (trasa, bez IP),
   ale nie ma progu „wolne zapytanie" dla feedu. Propozycja:
   `DB::whenQueryingForLongerThan()` z progiem ~500 ms i nazwą trasy w logu —
   osobna zmiana w `MonitoringServiceProvider`, poza zakresem tego PR-a.
