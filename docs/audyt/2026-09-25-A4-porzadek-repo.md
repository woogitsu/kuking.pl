# Audyt A4 — porządek w repozytorium (25 września 2026)

Zakres: `origin/main` z 25.09.2026. Sześć obszarów ze zlecenia: pliki, które
nie powinny być w repo; martwe odnośniki i rozjazdy w dokumentacji; numeracja
decyzji i CHANGELOG; martwy kod i zmienne środowiska; zgodność CI
z `scripts/check.sh`; jakość testów.

Wagi: **wysoka** — kontrola albo zasada, na której ktoś polega, a która nie
działa; **średnia** — rozjazd, który wprowadzi w błąd następną osobę;
**niska** — bałagan bez skutku dla działania.

## Co naprawiono w tej gałęzi (`claude/audyt-raporty-a4`)

| Zmiana | Pliki |
|---|---|
| Usunięte 19 artefaktów z `storage/` (≈ 9,6 MB): logi przebiegów testów, JUnit XML, wynik strażnika kaskady, skrypty jednorazowego QA z zaszytymi ścieżkami Windows/WSL. Nic w kodzie, testach ani CI ich nie czyta. `docs/research/ai-pilots/WERYFIKACJA.md:30` sam mówi, że JUnit „pozostaje lokalnie” | `storage/app/qa-granice/*`, `storage/app/pomiary-wersje/testy.xml`, `storage/ai-pilots/full-suite.xml`, `storage/kaskada-martwe-reguly.json`, `storage/kreator-testy-*.log`, `storage/logs/cache-597-610-*.log` |
| Te trzy źródła dopisane do `.gitignore`, żeby nie wróciły przy następnym `git add -A` | `.gitignore` |
| `GEMINI.md` i `.windsurfrules` podawały „przed PR-em: pint + test”, a `CLAUDE.md` i AGENTS.md §10 każą uruchomić `./scripts/check.sh`, bo sam pint i test pomijają składnię, migracje i assety. Do `GEMINI.md` dopisane też pole `kind` wpisu (D-006, uzupełnienie z 20.09) | `GEMINI.md`, `.windsurfrules` |
| `CLAUDE.md` i AGENTS.md §2 p. 7 odsyłały po listę V2 do `docs/ROADMAP.md`. Tam jej nie ma, jest w `docs/FEATURES.md` w sekcji „V2” | `CLAUDE.md`, `AGENTS.md` |
| Brakowała pusta linia przed `## Alfa 0.40`, więc nagłówek przyklejał się do punktu listy | `CHANGELOG.md` |
| Historyczną ścieżkę `scripts/tagi-potwierdzenie.test.mjs` uzupełniłem o dzisiejsze położenie pliku | `docs/SKLADNIKI_PORCJE_TAGI_878_741_750_754.md` |

Sprawdzenie po zmianach: `pint --test` przeszedł. Przeszły też testy
strażników dotkniętych plików: `InstrukcjeChroniaSrodowiskoTest`,
`DokumentyMdNieMajaMartwychOdnosnikowTest`, `NumeryDecyzjiMajaWpisyTest`,
`PodbicieWersjiWymagaWpisuWChangelogTest`, `PoswiadczeniaPozaRepozytoriumTest`,
`TabelaStackuMowiPrawdeTest`, `PulapkiTestowNieSaMartwymOdnosnikiemTest`,
`PaczkaNieNiesieKomentarzyDeweloperskichTest` i `WyjatkiRolKartTest`
(łącznie 40 testów). Jeden test oblał i nie ma związku z tą gałęzią:
`TestyChodzaNaPostgresieTest::test_serwer_melduje_wersje_postgresa…`.
Kontener audytu ma PostgreSQL 16, a próg to 18. Pełnego `check.sh` nie
uruchamiałem, bo zmiany nie dotykają kodu.

Kodu aplikacji ani testów nie usuwałem. Martwy kod jest tylko zgłoszony niżej.

---

## 1. Pliki, które nie powinny być w repozytorium

| # | Co | Gdzie | Waga | Propozycja |
|---|---|---|---|---|
| 1.1 | ~~Artefakty przebiegów w `storage/`~~ | patrz tabela wyżej | średnia | **naprawione** |
| 1.2 | `.codex/heic-119/` (13 plików, 7,2 MB, w tym `live.mov` 4,2 MB i dwa `.heic`) jest **śledzony**, choć `.gitignore:13` ignoruje katalog `.codex` (wzorzec z ukośnikiem na początku). Próbki czyta `docs/research/heic-119/HeicMeasurementTest.php:41`, a `RAPORT.md:61` nazywa ten katalog „ignorowanym”. Ta sama prawda jest zapisana na dwa sposoby | `.codex/heic-119/*` | średnia | Właściciel decyduje. Jeśli próbki mają zostać, trzeba je przenieść do `docs/research/heic-119/probki/` i poprawić ścieżkę w teście pomiarowym. Jeśli nie, `git rm -r --cached .codex` |
| 1.3 | `R1-tagi-kopia.md` (47 kB) leży w katalogu głównym. Audyt 10.09 (`docs/research/audyt-2026-09-10/01_ARCHITEKTURA_I_JAKOSC_KODU.md:42`) kazał go przenieść albo usunąć. Nie zrobiono ani jednego, a test `DokumentyMdNieMajaMartwychOdnosnikowTest.php:80` wyłącza go po nazwie | `R1-tagi-kopia.md` (katalog główny) | niska | Przenieść do `docs/research/` z nazwą opisującą status i usunąć wpis z listy wykluczeń testu |
| 1.4 | Katalog `evidence/kontakt-panel/` (13 plików) leży w katalogu głównym, choć dowody w całym repo mieszkają w podkatalogach `evidence/` pod `docs/`. Odsyła do niego tylko `docs/flota/DZIENNIK_NOCNY.md` | `evidence/` (katalog główny) | niska | Przenieść pod `docs/infra/evidence/` albo `docs/design/evidence/` i poprawić odsyłacz |
| 1.5 | `docs/ROBOCZE-sec01-raport-agenta.md` (360 linii) to roboczy raport agenta z nagłówkiem „Nic nie zostało wypchnięte”. Nic do niego nie odsyła | `docs/` | niska | Przenieść do `docs/research/` albo `docs/audits/`, ewentualnie usunąć |
| 1.6 | Dowody ważą razem ≈ 80 MB z 141 MB całego drzewa: `docs/design/evidence` 51 MB, `docs/infra/evidence` 29 MB. Pojedyncze JSON-y z `feed585` mają 3,5–4 MB. Do tego 112 plików w `docs/` to bajtowe kopie innych plików, np. `docs/flota/STAN_SESJI_CZESC5.md` = `docs/flota/zapis/archiwum/STAN_SESJI_CZESC5.md`, `docs/flota/JAK-DZIALA-SKRZYNKA.md` = `docs/flota/skrzynka/JAK-DZIALA-SKRZYNKA.md`, a `docs/audits/evidence/dlug-713-492/issue-59x.json` = `docs/infra/evidence/odbior64/issue-59x.json` | katalogi `evidence/` pod `docs/` | niska | Przyjąć regułę: dowody > 1 MB tylko w artefakcie CI albo w wydaniu, w repo zostaje skrót. Duplikaty z `docs/flota/` zastąpić odsyłaczem |
| 1.7 | `tests/skrypty/kontrola-cache.sh` sprawdza, czy `$PWD == /home/mateusz/flota/gpt-cloudflare-cache-run`, i poza tym katalogiem kończy się błędem. Nic go nie woła. `tests/skrypty/kontrola-sondy.sh` przywołuje tylko `docs/infra/SONDA_WDROZENIA_805_808.md` z lokalną ścieżką WSL | `tests/skrypty/` | niska | Przenieść obok dowodów (`docs/infra/evidence/…`) albo usunąć. W `tests/` powinny leżeć tylko skrypty, które coś uruchamia |
| 1.8 | Nie ma śledzonych `__pycache__`, `*.pyc`, `.DS_Store`, `*.tmp`, `*.bak`, `*.swp` ani `Thumbs.db` | — | — | nic do zrobienia |

Uwaga: 18 plików `*.log` w podkatalogach `evidence/` pod `docs/` (kontrole ujemne itp.)
dodano świadomie, mimo `*.log` w `.gitignore`. Są przywoływane w dokumentach
dowodowych, więc to nie są śmieci.

## 2. Odnośniki i dokumentacja niezgodna z kodem

| # | Co | Gdzie | Waga | Propozycja |
|---|---|---|---|---|
| 2.1 | Odnośniki markdown i trasy w backtickach pilnuje `DokumentyMdNieMajaMartwychOdnosnikowTest` (zielony, 49 asercji). Martwych linków `[]()` nie ma | — | — | — |
| 2.2 | **AGENTS.md §7 nie zna pola `kind`.** `CLAUDE.md:22–23` mówi „pola sterujące nigdy w `$fillable`: `status` i `role` użytkownika, `kind` wpisu. Pełna reguła i powody w AGENTS.md §7”. Tymczasem `AGENTS.md:373` wymienia tylko `status` i `role`. Uzupełnienie trzeciego pola istnieje wyłącznie w D-006 (`docs/DECISIONS.md:103`). Źródło prawdy jest węższe niż wskaźnik, który do niego odsyła | `AGENTS.md:373` | średnia | Dopisać `kind` wpisu (`Post::oznaczJakoPytanie()`) do punktu w §7. Nie zmieniałem tego sam, bo to treść reguły w pliku kanonicznym |
| 2.3 | `.github/copilot-instructions.md:13` też mówi tylko „`status` ani `role`”, bez `kind` | `.github/copilot-instructions.md:13` | niska | Poprawić razem z 2.2 |
| 2.4 | ~~`GEMINI.md`, `.windsurfrules`: „przed PR-em pint + test”~~ | — | średnia | **naprawione** |
| 2.5 | ~~Lista V2 szukana w `ROADMAP.md`~~ | `CLAUDE.md`, `AGENTS.md:85` | niska | **naprawione** |
| 2.6 | `docs/ROADMAP.md` nie ma żadnego znacznika stanu (zrobione / w toku). Wszystkie 12 etapów wygląda tak samo, choć większość MVP jest zbudowana. Osoba, która według CLAUDE.md ma „pracować po kolei”, nie odczyta z niego, gdzie jest projekt | `docs/ROADMAP.md` | niska | Dopisać przy etapach stan albo odsyłacz do epików/issues |
| 2.7 | Komendy `artisan` w bieżących dokumentach istnieją. Wyjątki są uzasadnione: `sentry:publish` to jawnie opisany zamiar (D-041), a `kuking:puls` pochodzi z historycznego `AUDYT_GPT_2026-09.md` | — | — | — |
| 2.8 | Gołe ścieżki w backtickach (bez `[]()`), które nie istnieją, to cytaty historyczne albo dowody nieistnienia: `config/sentry.php`, `.github/settings.yml`, gałęzie `docs/…` w `UPROSZCZENIE_CI_611.md:157`, migracja z gałęzi w `PLAN_SCALANIA_20260920.md:181`, usunięty celowo `PomiarIdempotencjiFormularzyTest.php`. Jedną nieaktualną ścieżkę uzupełniłem (`SKLADNIKI_…:23`) | — | — | — |

## 3. Numeracja decyzji i CHANGELOG

| # | Co | Gdzie | Waga | Propozycja |
|---|---|---|---|---|
| 3.1 | Nie ma zduplikowanych nagłówków `D-xxx`. `NumeryDecyzjiMajaWpisyTest` jest zielony | — | — | — |
| 3.2 | **`D-1009-ROBOCZA`** trafiła na `main` z roboczym numerem („Numer ostateczny przydziela koordynator przy scalaniu”). Nikt go nie przydzielił. Strażnik numerów łapie tylko 3 cyfry, więc tego nie widzi | `docs/DECISIONS.md:15176` | średnia | Nadać pierwszy wolny numer (> D-256), a strażnika rozszerzyć o wzorzec `D-\d{4}` i słowo `ROBOCZA` |
| 3.3 | **„Reguła D-235”** jest przywołana dwa razy jako obowiązująca zasada numeracji („ustępuje gałąź, której numeru nie ma jeszcze na `main`”), ale wpisu D-235 nie ma w dzienniku ani w `docs/decyzje/` | `docs/DECISIONS.md:15663`, `:15984` | średnia | Dopisać D-235 (reguła rozstrzygania kolizji numerów) albo przepisać oba zdania na odsyłacz do `docs/flota/MAPA_NUMEROW_DECYZJI.md` |
| 3.4 | D-228 jest opisana jako „zajęta (#966)” (`DECISIONS.md:15564`, `MAPA_NUMEROW_DECYZJI.md:137`), ale na `main` jej nie ma. Tak samo D-243 (`:15983`). To numery z gałęzi, które nie weszły albo weszły pod innym numerem | `docs/DECISIONS.md:15564`, `:15983` | niska | W `MAPA_NUMEROW_DECYZJI.md` oznaczyć je jako „spalone”, żeby ktoś ich nie wziął i nie pomylił z opisem |
| 3.5 | Wpisy w dzienniku nie idą po kolei: D-069 stoi po D-078, D-072 po D-081, D-062 po D-092, D-224 po D-1009, D-232 po D-254, D-236 na końcu po D-246. Są też dwa style nagłówków: `## D-001 · …` do D-119 i `## D-220 — …` od D-220 | `docs/DECISIONS.md` | niska | Nie przenumerowywać, bo odsyłacze się rozjadą. Na początku pliku dopisać zdanie „wpisy w kolejności scalenia, nie numeru” i spis numerów z kotwicami |
| 3.6 | Luki D-084, D-086 i D-094 są udokumentowane jako świadome (`DECISIONS.md:21`). Pozostałe luki (066–068, 070, 073–074, 095–097, 100–102, 108–112, 120–219, 226, 234, 237, 247–248, 250) są opisane w `MAPA_NUMEROW_DECYZJI.md` albo nikt się do nich nie odwołuje | — | — | — |
| 3.7 | CHANGELOG nie ma duplikatów wersji. Brak **Alfy 0.46** jest znany i opisany (`DECISIONS.md:10982`) | — | — | — |
| 3.8 | Sekcja `## Przygotowane — bezpieczeństwo logowania (#584)` wisi między 0.41 a 0.40, poza schematem „Alfa x.yy”. Po wydaniu powinna trafić do wersji albo do „Nieopublikowane” | `CHANGELOG.md:176` | niska | Przenieść do właściwej wersji albo do „Nieopublikowane” |

## 4. Martwy kod, TODO, zmienne środowiska

| # | Co | Gdzie | Waga | Propozycja |
|---|---|---|---|---|
| 4.1 | Klasy w `app/`: każda ma odwołanie poza własnym plikiem (komendy przez sygnaturę, harmonogram lub test). Widoki Blade: bez sierot (`recipe-wizard` to komponent Livewire, `errors/*` i `vendor/mail` ładuje framework) | — | — | — |
| 4.2 | `kuking:raport-sygnalow` (`app/Console/Commands/RaportSygnalow.php`) nie ma testu, nie ma go w harmonogramie ani w CI. Jest tylko w `docs/MODERATION.md:258` jako pomiar ręczny | `app/Console/Commands/RaportSygnalow.php` | niska | Dopisać test dymny komendy albo oznaczyć ją w docstringu jako narzędzie ręczne |
| 4.3 | Znaczników TODO/FIXME/XXX/HACK bez numeru issue nie ma. Trafienia w `DokumentyPrawneNieKlamiaTest` to wzorce, których ten test szuka | — | — | — |
| 4.4 | **`CLOUDFLARE_ZONE_ID` i `CLOUDFLARE_PURGE_TOKEN`** czyta `config/kuking.php:203`, a używają ich `WyczyscZalegleCdn` i `/health` (`HealthController.php:635` melduje ich brak). Nie ma ich w `.env.example` ani w runbooku wdrożenia | `.env.example` | średnia | Dopisać obie do `.env.example` z komentarzem, co bez nich nie działa (czyszczenie CDN po wdrożeniu) |
| 4.5 | `config/` czyta około 80 zmiennych `KUKING_*`, których nie ma w `.env.example`, np. `KUKING_ZAUFANE_HOSTY` (wspomniana w runbooku), `KUKING_STREFA`, `KUKING_WYDANO`, `KUKING_ROLLBACK_KASUJ_*`, progi poczty i retencje. Wszystkie mają wartości domyślne, więc nic nie pada. Operator nie ma jednak spisu pokręteł | `.env.example` / `config/kuking.php` | niska | Dodać sekcję „pokrętła z domyślną wartością” albo test, który porównuje `env('KUKING_*')` z `.env.example` i jawną listą wyjątków |
| 4.6 | `VITE_APP_NAME` jest w `.env.example:691`, a nic go nie czyta (ani `resources/js`, ani `vite.config.js`) | `.env.example:691` | niska | Usunąć z szablonu |
| 4.7 | Domyślna konfiguracja Laravela dla Redisa, Memcached, DynamoDB, SQS i Beanstalkd siedzi w `config/database.php`, `cache.php` i `queue.php`. To nie jest błąd, ale stack mówi „zero Redisa”, a `docs/infra/REDIS_HA_DECYZJE_603_604.md` istnieje, więc ktoś może uznać tę konfigurację za zachętę | `config/` | niska | Zostawić. Ewentualnie dopisać jednozdaniowy komentarz przy `redis` w `config/database.php` z odsyłaczem do AGENTS.md §3 |
| 4.8 | `composer.json` → `post-create-project-cmd` tworzy `database/database.sqlite` (szablon Laravela). W projekcie, który zakazuje SQLite, to pozostałość, choć wykonuje się tylko przy `create-project` | `composer.json` | niska | Usunąć ten krok |

## 5. CI a `scripts/check.sh`

AGENTS.md §10 stawia `check.sh` jako jedną komendę przed PR-em, a CI jako
zabezpieczenie po stronie serwera. Zestawy kontroli się rozjechały **w obie
strony**:

| # | Co | Gdzie | Waga | Propozycja |
|---|---|---|---|---|
| 5.1 | **CI nie uruchamia czterech zestawów testów powłoki, które uruchamia `check.sh`:** `tests/skrypty/entrypoint-nadzor.sh` (nadzór entrypointu kontenera), `scripts/kontrola-ujemna.sh` (przyrząd kontroli ujemnych, używany jako dowód w wielu PR-ach), `tests/skrypty/kontrola-sondy-wdrozenia.sh` (sondy testu dymnego wdrożenia) oraz `bash -n` na `docker/entrypoint.sh`, `docker/kopia/*.sh`, `scripts/*.sh` i `tests/skrypty/*.sh`. `cache-assetow.sh` i `kopia-bazy.sh` są pokryte (PHPUnit i job `lint`). Regresja w entrypoincie albo w sondzie wdrożenia przejdzie przez zielone CI, jeśli autor nie uruchomił `check.sh` | `scripts/check.sh:88–127` vs `.github/workflows/ci.yml:530–647` | **wysoka** | Dodać do joba `lint` krok „Skrypty powłoki” identyczny z `check.sh` (albo wydzielić wspólny `scripts/kontrole-powloki.sh`, który wołają oba) |
| 5.2 | `check.sh` sprawdza składnię `php -l` na `app config database routes tests`, a CI nie. W praktyce pokrywa to PHPStan, który ma w `phpstan.neon` te same pięć katalogów, więc to rozjazd opisu, nie dziura | `scripts/check.sh:76` | niska | Zostawić `php -l` jako szybki krok lokalny i opisać w §10, że w CI pokrywa go PHPStan |
| 5.3 | CI robi rzeczy, których `check.sh` nie robi: `composer audit`, `npm audit` (`ci.yml:1892–1897`), kontrole CSS w Chromium (proporcje małej skali, stopka bez pustego pasa, potwierdzenie tagu, szybki panel wyglądu — `ci.yml:1089–1119`), weryfikację manifestu Vite i clampów tytułów, joby `port_panelu`, `port_marki` i `port_funkcje` oraz `docker-build` z testem dymnym. Zdanie „jedna komenda przed PR-em” jest więc prawdziwe tylko częściowo | `ci.yml` vs `check.sh` | średnia | W AGENTS.md §10 dopisać tabelę „kontrola → check.sh / CI” albo dodać do `check.sh` tanie kroki (`composer audit`, testy DOM w Chromium) |
| 5.4 | `.github/workflows/audyt-a7-final-check.yml` to jednorazowy workflow audytu A7. Uruchamia się tylko na pushu do gałęzi `audit/a7-20260909`, a mimo to ma `permissions: contents: write` | `.github/workflows/audyt-a7-final-check.yml:1–10` | średnia | Usunąć (audyt A7 jest zamknięty), a jeśli ma zostać, zmienić na `contents: read`. Nie usuwałem sam, bo to decyzja o CI |
| 5.5 | Skrypty npm: wszystkie `*.test.mjs` są wołane (przez `build`, `ci.yml` albo `check.sh`). `composer dev` → `php artisan dev` istnieje. `.railway/railway.ts` pilnuje `scripts/railway/iac.test.mjs` w CI i w `check.sh` | — | — | — |

## 6. Testy

| # | Co | Gdzie | Waga | Propozycja |
|---|---|---|---|---|
| 6.1 | `phpunit.xml` ma `failOnRisky="true"`, a `RiskyTestFailsGateTest` z fiksturą `tests/Fixtures/PhpunitRisky` sprawdza, że test bez asercji oblewa. Statyczny skan znalazł 19 metod bez bezpośredniej asercji. Wszystkie delegują do metod pomocniczych z asercjami (`obejdzEkran`/`zakonczObchod`, `zmierz`, `deliver`, `zaOdmowa`, `odmowa`, `checkInvalidRow`) | — | — | — |
| 6.2 | Każde `markTestSkipped` ma powód. Pięć z nich pilnuje „tylko PostgreSQL”: `KolumnySzukaniaTest:48`, `NumerSprawyTest:222`, `RegressionTest:284`, `:311` i `TrafnoscWyszukiwarkiTest:70`. To martwe gałęzie, bo `TestyChodzaNaPostgresieTest` wymusza PostgreSQL 18+ | `tests/Feature/*` | niska | Usunąć te warunki przy okazji zmian w tych plikach. Opisuje to już `TestyChodzaNaPostgresieTest.php:29` |
| 6.3 | `PomiarOdcieciaDostepuDoPlikuTest:117` pomija się bez `POMIAR_S3_ENDPOINT`. W zwykłym przebiegu i w CI jest więc zawsze pominięty | `tests/Feature/PomiarOdcieciaDostepuDoPlikuTest.php:117` | niska | Przenieść do osobnej grupy (`#[Group('pomiar')]`), żeby „1 skipped” nie wisiało w każdym przebiegu |
| 6.4 | Nie ma zduplikowanych nazw plików testów ani plików o identycznej treści | — | — | — |
| 6.5 | `docs/research/heic-119/HeicMeasurementTest.php` to test PHPUnit poza `tests/` (świadomie, bo to pomiar). Zależy od `.codex/heic-119`, patrz 1.2 | — | niska | Razem z 1.2 |

---

## Propozycje nowych issues

1. **CI uruchamia te same kontrole skryptów powłoki co `check.sh`** (P1).
   CI nie wykonuje `entrypoint-nadzor.sh`, `scripts/kontrola-ujemna.sh`,
   `kontrola-sondy-wdrozenia.sh` ani `bash -n`, więc regresja entrypointu albo
   sondy wdrożenia przechodzi przez zielony PR. Wydzielić wspólny skrypt
   i wołać go z `check.sh` i z joba `lint`.

2. **Nadać ostateczny numer `D-1009-ROBOCZA` i spisać regułę D-235** (P2).
   Na `main` jest decyzja z roboczym numerem, a dziennik dwa razy powołuje się
   na „regułę D-235”, której wpisu nie ma. Strażnik numerów trzeba rozszerzyć
   o numery czterocyfrowe i słowo „ROBOCZA”.

3. **AGENTS.md §7 wymienia `kind` wpisu obok `status` i `role`** (P2).
   `CLAUDE.md` odsyła do §7 po pełną regułę o trzech polach sterujących,
   a §7 zna tylko dwa. Poprawić też `.github/copilot-instructions.md`
   i najlepiej dopisać test, który trzyma listę pól w obu plikach w zgodzie.

4. **`.env.example` dokumentuje zmienne Cloudflare do czyszczenia CDN** (P2).
   `CLOUDFLARE_ZONE_ID` i `CLOUDFLARE_PURGE_TOKEN` są czytane przez config
   i meldowane przez `/health`, ale nie ma ich w szablonie ani w runbooku.
   Przy okazji rozważyć test porównujący `env('KUKING_*')` z szablonem.

5. **Usunąć jednorazowy workflow `audyt-a7-final-check.yml`** (P2).
   Służył jednemu audytowi z 9 września, a nadal ma `contents: write`.
   Mniej workflowów to mniej powierzchni uprawnień.

6. **Uporządkować pliki poza konwencją katalogów** (P2).
   `.codex/heic-119` (7 MB, śledzony wbrew `.gitignore`), `R1-tagi-kopia.md`
   i `evidence/` w katalogu głównym, `docs/ROBOCZE-sec01-raport-agenta.md`
   oraz dwa skrypty z zaszytymi lokalnymi ścieżkami w `tests/skrypty/` trzeba
   przenieść na właściwe miejsca albo usunąć, zgodnie z decyzją właściciela.

7. **Zasada dla dowodów większych niż 1 MB** (P2).
   Dowody zajmują ≈ 80 MB z 141 MB drzewa, a 112 plików w `docs/` to bajtowe
   kopie innych. Duże surowe wyniki powinny trafiać do artefaktów CI, a w repo
   zostawać streszczenie z sumą kontrolną.

8. **`check.sh` a CI: jawna tabela pokrycia w AGENTS.md §10** (P2).
   CI robi audyty zależności, testy DOM w Chromium i build Dockera, których
   `check.sh` nie robi, więc „jedna komenda przed PR-em” obiecuje więcej, niż
   daje. Tabela „kontrola → gdzie chodzi” usuwa tę niejasność.
