# Audyt infra i niezawodność (30 września 2026)

**Stan kodu:** `origin/claude/paczka-i-kandydat` @ `5548c7e16` (przyszły `main`).
**Tryb:** tylko raport. Kodu aplikacji nie zmieniano. Nie łączono się
z produkcją ani z Railway. Stan zmiennych produkcji pochodzi z listy
**samych nazw** od koordynatora (wartości nieznane, szczegóły w §3).
Czasy CI pochodzą z API GitHub Actions (odczyt przebiegów, niczego nie uruchamiano).

Zakres: `.github/workflows/*`, `Dockerfile`, `docker/*`, `.railway/railway.ts`
wobec nazw zmiennych produkcji, kopie i DR (#193, #594, #617), harmonogram
(`routes/console.php`), kolejki, migracje (§6, D-088), zależności.

Wcześniejsze audyty tego obszaru to `docs/audyt/2026-09-25-B10.md` (wdrożenie,
konfiguracja, zależności) i `docs/audyt/2026-09-25-B8.md` (kolejki,
harmonogram). Ich znalezisk nie powtarzam. Stan tych z B10, które sprawdziłem,
jest w §5. Decyzje świadomie rozstrzygnięte (D-333: okno 130 s, plan Pro,
Discord jako odbiorca alarmów; D-331; D-334) nie są zgłaszane jako wady.

## 1. Podsumowanie

- **Import przepisu z PDF (V2) nie zadziała na obrazie produkcyjnym.**
  `docker/php.ini` wyłącza `proc_open`, a odczyt PDF uruchamia Popplera przez
  `Process`. Odtworzone lokalnie z produkcyjnym `php.ini`: `LogicException`.
  CI tego nie widzi, bo testy chodzą bez tego pliku. Dziś import jest na
  produkcji wyłączony (D-333, czeka na DPA). Włączenie po DPA da działający
  przycisk i zadanie, które zawsze pada (IN-01).
- **Pierwsze `railway config apply` (#595) wyzeruje większość sekretów, jeśli
  wcześniej nie powstaną Shared Variables pod INNYMI nazwami niż dzisiejsze
  zmienne serwisu.** Produkcja ma `AWS_*`, `EMAILLABS_*`, `GOOGLE_*` i inne
  jako zmienne serwisu `kuking.pl`, a `railway.ts` czyta je z `ctx.shared.R2_*`
  itd. Runbook o tym wspomina (krok 0.4), ale żadne narzędzie w repo tego nie
  liczy. Istniejący skrypt `zmienne-spoza-iac.mjs` sprawdza co innego (IN-02).
- **Zmienne „ustawiane ręcznie w panelu” (lista `WYJATKI` w
  `ZmienneRailwayaPerRolaTest`) kłócą się z runbookiem apply.** Chodzi np.
  o `AWS_LEGACY_BUCKET`: dla części zdjęć stary bucket jest jedyną kopią. Apply
  może je usunąć, a tabela „stop” spodziewa się usunięcia tylko
  `TRUSTED_PROXIES` (IN-03).
- **Decyzje zapisane tylko w `railway.ts` nie działają na produkcji, bo apply
  nigdy nie ruszył.** Przykład: D-269 mówi, że życzenia urodzinowe mailem są
  „po scaleniu włączone na produkcji”. `KUKING_URODZINY_MAIL_WLACZONY` nie ma
  w zmiennych produkcji, a wartość domyślna to `false` (IN-04).
- **Produkcja nie ma żadnej kopii bazy, a czujka kopii milczy.** Stan
  `WYŁĄCZONA` wypisuje ostrzeżenie do bufora `Artisan::call`, który adapter
  harmonogramu wyrzuca przy kodzie 0. Odtworzone: przez harmonogram z
  `LOG_LEVEL=debug` w logu jest tylko `DONE` (IN-05). Stan DR: §4.
- **CI:** przebieg `main` trwa w medianie 30,4 min (47 udanych, 26–29.09),
  maksymalnie 67 min. Jeden przebieg to 20–22 joby, a organizacja na planie Free
  ma limit 20 równoległych jobów. Równoległy PR już kolejkuje joby `main`.
  Propozycje skrócenia bez utraty kontroli są w IN-07.
- **Repozytorium jest dziś PUBLICZNE** (odczyt API: `visibility: public`).
  Nagłówki workflowów dalej mówią „prywatne, 2000 minut”, a `runs-on` pozwala
  wysłać joby PR-ów, także z forków, na self-hosted runnery przez jedną zmienną.
  Nic przed tym nie chroni. Dziś zmienne nie są ustawione (joby idą na
  `ubuntu-latest`), więc to ryzyko uśpione (IN-06).

Liczba znalezisk: **P0: 0 · P1: 3 · P2: 6 · P3: 6** (+1 podejrzenie).

## 2. Znaleziska

| ID | Waga | Tytuł | Dowód (plik:linia na BAZIE) | Odtworzenie | Wpływ | Proponowana poprawka i test regresyjny | Rozm. | Duplikat? |
|---|---|---|---|---|---|---|---|---|
| IN-01 | **P1** | Import z PDF pada na obrazie produkcyjnym: `proc_open` wyłączony, a `TekstZPdf` i `OdczytajSkanPdf` używają `Process` | `docker/php.ini` (`disable_functions=exec,passthru,shell_exec,system,proc_open,popen`); `Dockerfile:245` (ini w `conf.d`, więc także CLI workera); `app/Domain/Import/Pdf/TekstZPdf.php:76,112`; `app/Domain/Import/Pdf/OdczytajSkanPdf.php:31`; łapany jest tylko `ProcessTimedOutException` (`TekstZPdf.php:78`) | Skrypt z `Illuminate\Process\Factory` uruchomiony z `PHP_INI_SCAN_DIR` wskazującym kopię `docker/php.ini`: `Symfony\Component\Process\Exception\LogicException: The Process class relies on proc_open, which is not available`. Bez tego ini ten sam skrypt: `kod=0 ok=true`. | Osoba 50+ wgrywa PDF z przepisem i zawsze dostaje błąd, a płatne żądanie nie idzie. Funkcja jest martwa od pierwszego dnia po DPA. `kuking:sprawdz-import` pokazuje tylko flagę, więc tego nie widać. | Nie osłabiać `php.ini` dla web. Wariant A: worker i `all` uruchamiają `queue:work` z `-d disable_functions=exec,passthru,shell_exec,system,popen` (bez `proc_open`), wtedy odblokowana jest tylko kolejka, nie HTTP. Wariant B: osobny proces bez PHP. Test: `DockerowyPhpIniBlokujeProcesyPdfTest`, czyli uruchomienie `TekstZPdf` w podprocesie PHP z `docker/php.ini`, oczekiwany odczyt PDF z fixture; plus kontrola w `kuking:sprawdz-import` (`function_exists('proc_open')` w roli workera). | M | nowe; brak w issues i w `docs/` (grep `proc_open` + PDF) |
| IN-02 | **P1** | Apply #595 podmieni zmienne serwisu na puste referencje `${{shared.…}}`, a repo nie ma narzędzia, które to policzy | `.railway/railway.ts:227` (`APP_KEY: ctx.shared.APP_KEY`), `:357-364` (`AWS_* ← ctx.shared.R2_*`), `:559-567` (EmailLabs), `:593-594,599-600,650-651,685-686,714` (Turnstile, Google, Facebook, Cloudflare), `:752,778`; `docs/infra/PRZELACZENIE_NA_3_SERWISY_595.md:57-70` (krok 0.4 opisuje to słownie); `scripts/railway/zmienne-spoza-iac.mjs:38-50` (porównuje tylko nazwy z panelu z nazwami w pliku) | Statyczny odczyt `railway.ts` wobec nazw produkcji z §3. Każda sekretna zmienna produkcji w §3 ma w pliku źródło `ctx.shared.<inna nazwa>` (`AWS_ACCESS_KEY_ID ← R2_ACCESS_KEY_ID` itd.). Odczyt z 17.09 w #594: „lista zmiennych współdzielonych jest pusta”. Kompilacji grafu lokalnie nie uruchomiłem (odmowa uprawnień środowiska), wniosek pochodzi z odczytu pliku. | Po apply bez przygotowania: brak kluczy R2 (zdjęcia nie wgrywają się i nie wyświetlają), poczty (brak linków logowania, czyli główna droga wejścia 50+), OAuth, Turnstile, a `APP_KEY` pusty daje `die` w entrypoincie (`docker/entrypoint.sh:103-107`), czyli przestój. | Skrypt `scripts/railway/referencje-bez-shared.mjs`: na wejściu same NAZWY Shared Variables (`railway variables --json` na poziomie środowiska) i nazwy serwisu, na wyjściu lista referencji `ctx.shared.X`, których nie ma, z podziałem na krytyczne (`APP_KEY`, `R2_*`, `EMAILLABS_*`) i opcjonalne. Kod 1 przy braku krytycznej. Wpis w kroku 0.4 i w tabeli „stop”. Test `node --test` z fikcyjną listą nazw i kontrola ujemna. | M | rozszerza #595 (komentarz 27.09 o „potwierdzeniu Shared Variables”), #1895 pkt 5 (tylko 4 zmienne) |
| IN-03 | **P1** | Zmienne „tylko w panelu z założenia” (`WYJATKI`) są w sprzeczności z runbookiem apply. Najgroźniejsza to `AWS_LEGACY_BUCKET` | `tests/Feature/ZmienneRailwayaPerRolaTest.php:217-233` (`AWS_LEGACY_BUCKET` „ustawiany ręcznie”, `KUKING_ZAUFANE_HOSTY`, `TURNSTILE_HOSTY_STAGINGU`…); `config/filesystems.php:257-266` (`r2_legacy` czyta `AWS_LEGACY_BUCKET`, `AWS_LEGACY_URL`, para `AWS_LEGACY_*`); `docs/infra/STARY_BUCKET_R2_LEGACY.md:11-24` (stary bucket to jedyna kopia części zdjęć); `docs/infra/PRZELACZENIE_NA_3_SERWISY_595.md:107` (spodziewane usunięcie tylko `TRUSTED_PROXIES`); `docs/infra/ZMIENNE_SPOZA_IAC.md` („spodziewane dziś: pusta lista”) | Odczyt: `railway.ts` nie deklaruje żadnej z `WYJATKI`. Produkcja ma grupę `AWS_*` (bez podziału). Nie wiem, czy jest w niej `AWS_LEGACY_BUCKET`. **Zależne od stanu produkcji:** jeśli jest, a apply usuwa zmienne spoza pliku (niezmierzone, `ZMIENNE_SPOZA_IAC.md`), stare zdjęcia przestają się wyświetlać. Jeśli jej nie ma, a wiersze `media.disk='r2_legacy'` istnieją, to te zdjęcia już dziś się nie wyświetlają (`kuking:zaleznosc-od-starego-bucketu`, kod 1 „NIE SERWUJĄ SIĘ”). | Znikające zdjęcia z pierwszych tygodni społeczności, czyli najstarsze wpisy i przepisy ludzi. | (1) `AWS_LEGACY_BUCKET`/`_URL`/`_ACCESS_KEY_ID`/`_SECRET_ACCESS_KEY` jako `ctx.shared.R2_LEGACY_*` w `appEnv` (dopóki `kuking:przenies-zdjecia` nie skończy), (2) tabelę „stop” i `ZMIENNE_SPOZA_IAC.md` uzupełnić o listę `WYJATKI` jako „oczekiwane w panelu — przenieś przed apply”, (3) test: każda nazwa z `WYJATKI` opisana „ustawiana ręcznie” musi się pojawić w runbooku kroku 0 (odczyt pliku, wpis w kontrolach ujemnych). Przed apply: `kuking:zaleznosc-od-starego-bucketu --pliki`. | S | nowe (#595 i #1895 go nie wymieniają) |
| IN-04 | P2 | Wartości dosłowne z `railway.ts` nie obowiązują na produkcji. D-269 („życzenia mailem włączone na produkcji”) nie działa | `.railway/railway.ts:846-848` (`KUKING_URODZINY_MAIL_WLACZONY: isProduction ? "true"`); `config/kuking.php:2786` (domyślnie `false`); `docs/DECISIONS.md:18451-18452`; nazw z §3 (brak tej zmiennej) | Odczyt konfiguracji wobec listy nazw. Tak samo `KUKING_ZAUFANE_PRZESKOKI`, `KUKING_EXPORT_DISK`, `MAIL_FROM_ADDRESS` i `KUKING_CONTACT_EMAIL`, ale te mają wartości domyślne równe tym z pliku (`config/proxy.php:60`, `config/kuking.php:2247-2250`, `config/mail.php:175`, `config/kuking.php:3417`), więc bez skutku. | Osoby, które zaznaczyły zgodę na maila urodzinowego, go nie dostają. To funkcja społeczności, obiecana w ustawieniach. | Właściciel ustawia `KUKING_URODZINY_MAIL_WLACZONY=true` w panelu (odwracalne) albo świadomie czeka na apply i dopisuje to do D-269. W repo: do `iac.test.mjs` dopisać listę wartości dosłownych, które różnią się od domyślnych w `config/`, żeby było wiadomo, co naprawdę zmieni pierwszy apply. | S | nowe (#1755 zamknięte) |
| IN-05 | P2 | Czujka kopii w stanie `WYŁĄCZONA` nie zostawia żadnego śladu w logu produkcji | `app/Console/Commands/SprawdzKopieBazy.php:33-38,58-63` (ostrzeżenie przez `$this->warn`, kod 0); `app/Support/Harmonogram.php` (`Artisan::output()` czytane tylko przy kodzie ≠ 0); `routes/console.php` (`kuking:sprawdz-kopie` codziennie 06:15) | `CACHE_STORE=array LOG_CHANNEL=stderr LOG_LEVEL=debug AWS_KOPIE_BUCKET= php artisan schedule:test --name=kuking:sprawdz-kopie` → jedyne wyjście to `Running [kuking:sprawdz-kopie] … DONE`. Ręczne `php artisan kuking:sprawdz-kopie --bez-alarmu` wypisuje ostrzeżenie, więc treść istnieje, tylko nie trafia do logu. | Produkcja nie ma dziś ani jednej kopii (brak `AWS_KOPIE_*`, #594), a codzienne sprawdzenie melduje sukces i nic nie mówi. Właściciel dowiaduje się o tym z dokumentów, nie z serwisu. | Nie zmieniać kodu wyjścia (uzasadnienie w komentarzu jest dobre). Przy `WYŁĄCZONA` dopisać `Log::channel('pomiary')->info(...)` albo `Log::warning` raz na dobę. Po decyzji D-333 (Pro) dodać `/health`→`checks.kopie` z powodem `kopie_wylaczone` (bez `degraded`, żeby nie ruszać healthchecku). Test: `SprawdzKopieBazyZostawiaSladTest` z `Log::spy()`. | S | związane z #594/#193; sama cisza nieopisana |
| IN-06 | P2 | Repozytorium jest publiczne, a `runs-on` pozwala wysłać joby PR-ów z forków na self-hosted. Nagłówki twierdzą, że repo jest prywatne | `.github/workflows/ci.yml:1-60` („Repozytorium jest prywatne…”), `ci.yml:326,406,…,2198` (`runs-on` bez warunku na fork), `.github/workflows/preview.yml:142,168,296`, `deploy.yml:1-3`; `docs/infra/SELF_HOSTED_RUNNER.md:23-26` („Nigdy nie podpinaj tego runnera do repozytorium publicznego”) | Odczyt: API zwraca `visibility: public` dla `woogitsu/kuking.pl`. Ostatnie przebiegi `main` i PR mają etykiety `ubuntu-latest` (zmienne nieustawione), więc dziś bez skutku. | Ustawienie `CI_RUNS_ON` zgodnie z instrukcją z nagłówka `ci.yml` daje każdemu autorowi PR-a z forka powłokę na maszynach właściciela, tych samych, na których chodzą joby z tokenem produkcji Railway (`deploy.yml`). | W `runs-on` jobów uruchamianych na `pull_request` dodać warunek `github.event.pull_request.head.repo.fork && '"ubuntu-latest"' ‖ …`. Nagłówki workflowów i `SELF_HOSTED_RUNNER.md` poprawić na „publiczne”. W Settings → Actions właściciel ustawia „Require approval for all outside collaborators”. Test: rozszerzyć `DokumentyCiMowiaPrawdeORunnerzeTest` o regułę, że każdy `runs-on` czytający `vars.CI_RUNS_ON*` w workflow z `pull_request` ma warunek na fork. | S | nowe (#1962 dotyczyło licencji) |
| IN-07 | P2 | Czas CI: ścieżka krytyczna 23–28 min, limit 20 równoległych jobów, przebieg ma 22 joby | Przebieg `main` 36630483774 (74189ff): 20 jobów, 19 wystartowało w +0,3 min, `kontrole 3/3` czekała 0,6 min w kolejce. Najdłuższe: `port_funkcje` 28,3 min (na BAZIE już podzielony na 2), `kontrole negatywne` 20,9 / 22,7 / 23,3 min, `Panel marki` 21,4 min (krok TOTP 19,2), `Dostępność` 17,2 min. Suma ok. 194 min pracy na przebieg. Mediana `main` 30,4 min, maks. 67,4 (47 udanych, 26–29.09). BAZA dokłada `kaskada` i drugą część `port_funkcje` (`ci.yml:1505-1520,2022`), razem 22 joby. | Odczyt `list_workflow_jobs` i `list_workflow_runs` z API. | Wdrożenie poprawki dla ludzi czeka pół godziny, a przy równoległym PR-ze dłużej. Każdy job ponad 20 czeka w kolejce. | Bez utraty kontroli: (1) złączyć krótkie joby w jeden „szybkie kontrole” (`audit` 0,5 min, `static-analysis` 1,6, `przyrzad_605` 1,4, `assets` 2,8). Kroki i ich nazwy zostają, zwalniają się 3 sloty. Uwaga: `Build assetów`, `Larastan` i `Audyt` są wymaganymi checkami (`BRAMKI_CI_2215.md`), więc potrzebna nowa nazwa i zmiana listy u właściciela. (2) Zwolnione sloty dać `kontrole` (3→5 części, `--czesc N/5`), ścieżka krytyczna ok. 14 min. (3) Podzielić `Panel marki` na 2 części jak `port_funkcje`. (4) Cache `vendor/` i przeglądarki Playwright między jobami (dziś każdy job ok. 1,5 min przygotowania). Test: istniejące strażniki podziału (`PortMarkiMaWlasnaBramkeCiTest`, `test_podzial_kontroli.py`). | M | #611 (etapy 1–9) — kontynuacja |
| IN-08 | P2 | Na `main` oczekujący przebieg CI jest ZASTĘPOWANY nowszym, a komentarz obiecuje kolejkę. Skutek #2025 | `.github/workflows/ci.yml:286-294` („Na main przebiegi kolejkują się zamiast się ścinać”) | API: `main`/push 26.09 miało 44 `cancelled` na 64, a 27.09 6 na 22, przy `cancel-in-progress: false`. Dokumentacja GitHuba: nowy przebieg w grupie anuluje wcześniejszy OCZEKUJĄCY. Przykład: CI 36310199951 (`ed948478`, `cancelled`), a Railway wdrożył ten SHA (#1895 pkt 9). 28–29.09 zero anulowań, bo scalenia szły paczkami. | Przy kilku scaleniach naraz commity pośrednie nie mają CI, a „Wait for CI” może je wdrożyć. | Poprawić komentarz. Włączyć bramkę `railway-ci-gated-deploy.yml` (to już jest plan #2025). Alternatywa: na `main` grupa `ci-${{ github.sha }}` (bez zastępowania), kosztem większej kolejki (IN-07). | S | #2025 (mechanizm znany jako „anulowane CI”, przyczyna w `concurrency` nieopisana) |
| IN-09 | P2 | Dependabot nie otworzył ani jednego PR-a dla Composera, a pakiety PHP odstają | `.github/dependabot.yml` (composer, grupa minor/patch, `laravel/*` wykluczone z grupy) | `composer outdated --direct --locked`: `laravel/framework 13.30.1 → 13.34.0`, `livewire/livewire 4.4.3 → 4.4.7`, `larastan 3.11.0 → 3.12.2`, `pint`, `paratest`; `intervention/image 3 → 4` (major). Wyszukiwanie PR-ów `author:app/dependabot`: 8 PR-ów (npm, actions, docker), **zero composer**. `composer audit`: brak zaleceń, `npm audit --omit=dev`: 0. | Poprawki bezpieczeństwa frameworka i Livewire (sesje, upload) przychodzą tylko z ręcznej aktualizacji. | Właściciel sprawdza Insights → Dependency graph → Dependabot → log zadania composer (**podejrzenie**: błąd rozwiązywania zależności, np. `php ^8.4` albo pakiet `laravel/pao`). W repo: cotygodniowy `composer outdated --direct --locked` jako krok informacyjny w `audit` z podsumowaniem. | S | #2009 (major, zamknięte) — nie to samo |
| IN-10 | P3 | Nagłówki workflowów mówią „prywatne repozytorium, 2000 minut” | `.github/workflows/ci.yml:4-6`, `deploy.yml:2-3`, `railway-iac.yml` i `preview.yml` (nagłówki) | API: `visibility: public`. `docs/decyzje/REPO_PUBLICZNE.md` §1: runnery GitHuba są dla repo publicznych darmowe. | Błędne decyzje kosztowe (oszczędzanie minut, których nikt nie liczy). | Jedna poprawka tekstu razem z IN-06. | S | — |
| IN-11 | P3 | `ZMIENNE_SPOZA_IAC.md` twierdzi, że `KUKING_EDGE_TRYB`, `KUKING_TAG_TYGODNIA` i `KUKING_HTML_EDGE_CACHE_SECONDS` stoją w panelu. Na produkcji ich nie ma | `docs/infra/ZMIENNE_SPOZA_IAC.md` (tabela „O co chodzi”); `config/kuking.php:26` (`KUKING_TAG_TYGODNIA` domyślnie `false`) | Nazwy z §3: brak `KUKING_EDGE_*`, `KUKING_TAG_TYGODNIA`, `KUKING_HTML_EDGE_CACHE_SECONDS`, `KUKING_HEALTH_TOKEN`. | Bramka krawędzi jest w trybie obserwacji, tag tygodnia wyłączony, `/health` bez tokenu szczegółów. Stan bezpieczny, ale dokument opisuje inny. | Zaktualizować dokument (stan 30.09 z nazw), #1895 pkt 5 odhaczyć jako „nie dotyczy”. Jeśli tag tygodnia ma działać, właściciel zakłada Shared Variable. | S | #1895 pkt 5 |
| IN-12 | P3 | `KUKING_DIGEST_WLACZONY` nie ma w `railway.ts`. Włączenie w panelu zniknie przy apply | `config/kuking.php:2690`; brak w `.railway/railway.ts` i w `WYJATKI` | grep | Po włączeniu podsumowań przez właściciela pierwszy apply może je po cichu wyłączyć. | Dopisać `KUKING_DIGEST_WLACZONY: ctx.shared.KUKING_DIGEST_WLACZONY` w `schedulerEnv` (pusto = `false`, bezpieczny kierunek). | S | — |
| IN-13 | P3 | Web i worker: `restartPolicyType: ON_FAILURE` z limitem 10 prób. Po 10 awariach usługa zostaje wyłączona | `.railway/railway.ts:1210-1215,1325-1326` (komentarz: „10 prób to maksimum dostępne również na planach darmowych”) | Odczyt. Worker wychodzi z kodem 1 m.in. po 900 s czekania na migracje (`docker/entrypoint.sh:464-470`) i po 5 szybkich śmierciach kolejki (`:374-377`). | Seria awarii w nocy (np. baza niedostępna) zostawia kolejkę martwą do rana: zdjęcia `PENDING`, listy nie wychodzą. Alarm z `kuking:sprawdz-kolejke` przychodzi, ale nikt nic nie restartuje. | Po przejściu na Pro (D-333) rozważyć `ALWAYS` dla workera i schedulera (**podejrzenie**: limit prób zależy od planu, do sprawdzenia w dokumentacji Railway). Test w `iac.test.mjs`. | S | — |
| IN-14 | P3 | Zadania importu mają limit czasu większy niż okno zamknięcia 130 s i `tries=1` | `app/Jobs/ImportujPrzepisZPdf.php:50-52` (200 s), `app/Jobs/ImportujPrzepisZAdresu.php:56-58` (150 s); `.railway/railway.ts:171` (`ZAMKNIECIE_Z_KOLEJKA_S = 130`) | Odczyt. `iac.test.mjs` pilnuje tylko `ProcessUploadedImage`. | Wdrożenie w trakcie importu: zadanie zabite, po `retry_after` (960 s) `MaxAttemptsExceeded`, a człowiek widzi „Spróbuj jeszcze raz” po kwadransie. D-333 przyjęło 130 s z myślą o eksporcie, importów nie wymienia. | Dopisać importy do świadomej decyzji D-333 albo do strażnika. Dziś import jest wyłączony na produkcji. | S | #1860/#1030 (eksport) |
| IN-15 | P3 | Osiem migracji ma ten sam znacznik `2026_09_26_100000` | `database/migrations/2026_09_26_100000_*` (8 plików) | `ls database/migrations` | Kolejność ustala sortowanie nazw. Dziś zgodna z zależnościami (`create_importy_przepisow` < `create_przepisy_z_importu`), ale krucha. | Strażnik unikalności znacznika dla migracji nowszych niż dzisiejsze (jak `StraznikNowychMigracji`). | S | — |

**Podejrzenie (niezweryfikowane):** `Dockerfile:383` ustawia `ENTRYPOINT ["/usr/bin/tini", "-g", "--"]`, a Railway uruchamia `startCommand` z `railway.ts`. Nie sprawdziłem, czy Railway przy własnej komendzie startowej zachowuje `ENTRYPOINT` obrazu. Jeśli go zastępuje, PID 1 na produkcji to bash entrypointu, a nie tini. W roli `all` pułapka `trap shutdown` i tak przekazuje SIGTERM (`docker/entrypoint.sh:766`), więc skutek byłby mały. Do sprawdzenia: `railway ssh -- cat /proc/1/cmdline`.

## 3. `.railway/railway.ts` a zmienne produkcji (#595, `railway config apply`)

Nazwy na produkcji (serwis `kuking.pl`, od koordynatora): `APP_*`, `AWS_*`
(bez `AWS_ZDJECIA_KOPIA_*`), `CACHE_STORE`, `CLOUDFLARE_ANALYTICS_TOKEN`,
`CLOUDFLARE_PURGE_TOKEN`, `CLOUDFLARE_ZONE_ID`, `DB_*`, `EMAILLABS_*`,
`FACEBOOK_*`, `GOOGLE_*`, `FILESYSTEM_DISK`, `KUKING_MEDIA_DISK`,
`KUKING_MODEL_ALARM_EMAIL`, `KUKING_QUESTIONS_ENABLED`,
`LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK`, `LOG_*`, `MAIL_MAILER`,
`OPENAI_MODERATION_KEY`, `PHP_WORKER_MEMORY_LIMIT`, `QUEUE_CONNECTION`,
`SESSION_*`, `TRUSTED_PROXIES`, `TURNSTILE_*`, `RAILWAY_PUBLIC_DOMAIN`.
Brak: `VAPID_*`, `KUKING_EDGE_*`, `OPENAI_IMPORT_KEY`.

### 3.1 Co apply zrobi ze zmiennymi, które są dziś w serwisie

| Dziś w serwisie | `railway.ts` każe | Warunek bezpiecznego apply |
|---|---|---|
| `APP_KEY` | `ctx.shared.APP_KEY` | Shared `APP_KEY` = ta sama wartość. **Inaczej przestój** (entrypoint odmawia startu). |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_PUBLIC_BUCKET`, `AWS_EXPORTS_BUCKET`, `AWS_ENDPOINT` | `ctx.shared.R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_PUBLIC_BUCKET`, `R2_EXPORTS_BUCKET`, `R2_ENDPOINT` | Shared pod **nazwami `R2_*`** (inne niż w serwisie). |
| `AWS_LEGACY_*` (jeśli są w grupie `AWS_*`) | nic (usunięcie?) | IN-03 |
| `EMAILLABS_APP_KEY`, `EMAILLABS_SECRET_KEY`, `EMAILLABS_SMTP_ACCOUNT` | te same nazwy przez `ctx.shared` | Shared o tych nazwach |
| `GOOGLE_*`, `FACEBOOK_*`, `TURNSTILE_*`, `CLOUDFLARE_*` | te same nazwy przez `ctx.shared` | Shared o tych nazwach |
| `OPENAI_MODERATION_KEY`, `KUKING_MODEL_ALARM_EMAIL` | `ctx.shared` (tylko produkcja; po rozbiciu klucz modelu tylko w `worker`) | Shared o tych nazwach |
| `DB_*` | tylko `DB_CONNECTION` i `DB_URL = ${{Postgres.DATABASE_URL}}` | Jeśli w serwisie są `DB_HOST`/`DB_PASSWORD`…, plan pokaże ich usunięcie. To nie jest awaria (entrypoint przyjmuje `DB_URL`, `docker/entrypoint.sh:119-123`), ale nie ma tego w tabeli „stop”. |
| `LOG_*`, `SESSION_*`, `CACHE_STORE`, `QUEUE_CONNECTION`, `FILESYSTEM_DISK`, `KUKING_MEDIA_DISK`, `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK`, `KUKING_QUESTIONS_ENABLED`, `PHP_WORKER_MEMORY_LIMIT`, `MAIL_MAILER` | wartości dosłowne | Powinny wyjść bez zmian, jeśli panel ma te same wartości. `LOG_BLAD_WEBHOOK_URL` (Discord, D-333) przechodzi na `ctx.shared`, więc bez Shared alarmy zamilkną. |
| `TRUSTED_PROXIES` | usunięcie (oczekiwane, SEC-01) | — |

### 3.2 Co apply DOŁOŻY (dziś nie ma tego w serwisie)

Z wartością dosłowną: `KUKING_ZAUFANE_PRZESKOKI=1`, `KUKING_EXPORT_DISK=r2_eksporty`,
`MAIL_SCHEME=smtp`, `MAIL_FROM_ADDRESS`, `KUKING_CONTACT_EMAIL`,
`AWS_DEFAULT_REGION=auto`, `AWS_USE_PATH_STYLE_ENDPOINT=false`,
`KUKING_URODZINY_MAIL_WLACZONY=true` (**zmiana zachowania**, IN-04), `APP_ROLE`.
Z pustą referencją, jeśli nie założono Shared: `VAPID_*` (Web Push zostaje
wyłączony), `OPENAI_IMPORT_KEY` i `KUKING_IMPORT_CENA_*` (import wyłączony,
zgodnie z D-333), `KUKING_EDGE_*`, `KUKING_TAG_TYGODNIA`, `KUKING_HEALTH_TOKEN`,
`KUKING_HOST_USER_ID` (fallback po nazwie gospodarza), `MAIL_HOST…`,
`KUKING_PULS_HARMONOGRAMU_URL` (puls wyłączony), `AWS_KOPIE_*`,
`AWS_ZDJECIA_KOPIA_*` (czujki kopii `WYŁĄCZONA`).

### 3.3 Wniosek dla #595

1. Przed pierwszym planem trzeba założyć około 20 Shared Variables, z czego
   6 pod innymi nazwami niż dziś (`R2_*`). Bez tego apply daje przestój
   (`APP_KEY`) albo ciche wyłączenia (IN-02).
2. Plan powinien pokazać jako **oczekiwane** także usunięcie `DB_*` spoza
   `DB_URL`/`DB_CONNECTION` i ewentualnych `AWS_LEGACY_*` (IN-03). Dziś tabela
   „stop” każe się wtedy zatrzymać, bo wymienia tylko `TRUSTED_PROXIES`.
3. `kopia-bazy` powstaje w tym samym apply (`railway.ts:1583`,
   `dataServices`) także przy `PRODUCTION_SPLIT_SERVICES=false`. DR nie musi
   czekać na rozbicie na trzy serwisy, ale musi czekać na przygotowanie
   zmiennych z punktu 1.
4. Nie udało się skompilować grafu lokalnie (`scripts/railway/iac-graf.mjs`,
   odmowa uprawnień środowiska), więc tabela pochodzi z odczytu `railway.ts`.
   Właściciel potwierdza ją poleceniem z kroku 0.4 runbooka.

## 4. Kopie i DR (#193, #594, #617)

- **Baza:** zero kopii produkcyjnych. `kopia-bazy` nie istnieje (odczyt
  Railway z 18.09 w #594), a `AWS_KOPIE_*` nie ma na produkcji (§3). Tabela
  wyników `docs/infra/KOPIE_I_ODTWORZENIE.md` §5 jest pusta. Narzędzia są
  gotowe i przećwiczone lokalnie: `docker/kopia/`, `scripts/proba-odtworzenia.sh`,
  `docs/infra/evidence/dr594/`. Brakuje wyłącznie czynności właściciela
  (`DR594_PIERWSZY_ZRZUT_WLASCICIEL.md`). Po D-333 (Pro) dochodzą Volume Backups
  i PITR (`RAILWAY_PRO_WYKORZYSTANIE.md` §4.1–4.2). Uzupełniają one kopię
  offsite, ale jej nie zastępują (tamże §5). Z tym się zgadzam.
- **Zdjęcia (#617):** brak `AWS_ZDJECIA_KOPIA_*` na produkcji, więc nie ma
  migawki. `kuking:sprawdz-kopie-zdjec` nie jest w harmonogramie (świadomie,
  `railway.ts:790-797`). Stary bucket `r2_legacy` nie jest objęty migawką
  (`STARY_BUCKET_R2_LEGACY.md` §1) i łączy się z IN-03.
- **Ponowne wymazanie po odtworzeniu** (komentarz #594 z 21.09) ma już
  mechanizm: `kuking:dziennik-wymazan` w harmonogramie 05:30,
  `kuking:wymaz-ponownie` i procedurę w `KOPIE_I_ODTWORZENIE.md` §3.1.
- Deklaracja „Twoje przepisy nie zginą” pozostaje wstrzymana (D-333), co jest
  zgodne ze stanem.

## 5. Status znalezisk B10 (25.09) na BAZIE

| B10 | Stan | Dowód |
|---|---|---|
| B10-01 token produkcji w `plan` z gałęzi PR | naprawione | `.github/workflows/railway-iac.yml:175-180` (`environment: production`) |
| B10-02 Railway CLI bez wersji | naprawione | `.github/workflows/deploy.yml:682-712` (wersja 5.62.1 + SHA-256) |
| B10-03 `cache:clear` w entrypoincie | naprawione | `docker/entrypoint.sh:163-166` (tylko `config/route/view/event:clear`) |
| B10-05 `lang/**` w `watchPatterns` | naprawione | `.railway/railway.ts` (blok `watchPatterns`) |
| B10-06 `public/` należy do `www-data` | **otwarte** | `docker/entrypoint.sh:75` |
| B10-08 `redeploy` tylko web | **otwarte, opisane** | `.github/workflows/deploy.yml:790-799` |
| B10-09 audyt zależności nie blokuje | naprawione | #2215, `docs/infra/BRAMKI_CI_2215.md` |
| B10-10 `laravel/tinker` w produkcji | naprawione | `composer.json` (`require-dev`), #2223 |
| B10-11 `HEALTHCHECK` zakłada rolę web | naprawione | `docker/healthcheck.sh` (rozpoznaje rolę z `/proc/1/cmdline`) |

## 6. Sprawdzone i w porządku

- **Harmonogram:** wszystkie 40 zadań idzie przez `App\Support\Harmonogram::artisan()`,
  więc kod ≠ 0 zamienia się w wyjątek, a wyjątek trafia do `report()` i kanału
  `blad_webhook` (Discord, D-333). Każde zadanie ma `onOneServer()`
  i `withoutOverlapping()`. `HarmonogramSprawdzaKodWyjsciaTest` przechodzi
  (uruchomione, zielone). Pętla harmonogramu w entrypoincie loguje pominięte
  minuty (`docker/entrypoint.sh:707-722`).
- **Kolejki:** `retry_after` 960 s > najdłuższy `$timeout` 900 s
  (`GenerateUserExport`, `failOnTimeout`). `--tries`/`--backoff` mają wartości
  domyślne w entrypoincie, a zadania nadpisują je jawnie. Planowy recykling
  (`--max-time`, `--max-jobs`) jest odróżniany od awarii (`nadzoruj`). `failed_jobs`
  są przycinane po 30 dniach (`queue:prune-failed --hours=720`). Nowe nieudane
  zadania i zaległość alarmuje `kuking:sprawdz-kolejke` co 15 min.
- **Migracje:** `NoweMigracjeTrzymajaSieParagrafu6Test` i
  `MigracjeMajaLimitBlokadTest` przechodzą (19 testów razem z harmonogramem,
  2364 asercje). Przejrzane `down()` migracji z 26–29.09: odmowy D-088 są wąskie
  (tabela niepusta albo wartość różna od domyślnej). Wyjątek „Mój stół” jest
  opisany w `docs/DATABASE.md` (preferencja wyświetlania, test cyklu).
  `preDeployCommand` zatrzymuje wdrożenie przy błędzie migracji, a worker
  i scheduler czekają na migracje (`czekaj_na_migracje`, #2044).
- **Zamykanie kontenera:** role `all` i `worker` przekazują SIGTERM do
  `queue:work` (pułapki w `nadzoruj_kolejki`/`nadzoruj_jedna_kolejke`), a web
  kończy przez `exec frankenphp`. Śmierć jednej usługi w roli `all` zamyka
  kontener z kodem 1 zamiast zostawiać stronę bez kolejki (lekcja z 5–6.09).
- **Obraz:** bazy przypięte digestem, `tini` z migawki Debiana, `setpriv`
  sprawdzany w buildzie, zejście z roota w entrypoincie. `display_errors=Off`,
  `expose_php=Off`, `allow_url_include=Off`. Caddy: limity ciała żądania
  (2 MB poza ścieżkami uploadu, 112 MiB z uploadem), 404 na `/.env*`, `/.git/*`.
- **Workflowy:** minimalne `permissions`, akcje przypięte SHA, token produkcji
  tylko w jobach z `environment: production`. `railway-ci-gated-deploy.yml`
  sprawdza `head_repository == repository`, zdarzenie `push` na `main`
  i sukces. Bramka `zakres` przy braku bazy albo nieznanym SHA puszcza pełny
  zestaw. Job zbiorczy `Testy (PostgreSQL 18)` czerwieni się, gdy sam `zakres`
  padnie (inaczej pominięte joby liczyłyby się jako zielone).
- **Zależności:** `composer audit` — brak zaleceń, `npm audit --omit=dev` — 0.
  Brak porzuconych pakietów (`abandoned` w `composer.lock`: żaden ze 136).
  `npm outdated`: tylko łatki (`vite 8.3.0 → 8.3.1`, `railway 3.11.0 → 3.12.0`,
  `@laravel/multiplex`).
- **`railway.ts`:** region jawny, `numReplicas: 1` schedulera z uzasadnieniem,
  `drainingSeconds` workera ≥ limit `ProcessUploadedImage`, `KUKING_WAIT_FOR_CI`
  musi być jawne (#1390), `sleepApplication` tylko poza produkcją, klucze
  modelu i alarmów tylko na produkcji.

## 7. Metoda i granice

Odczyt plików na BAZIE, odczyt API GitHub (przebiegi, joby, issues, PR-y
Dependabota, widoczność repo), lokalne uruchomienia: skrypt `Process` z
produkcyjnym `php.ini` (IN-01), `schedule:test` czujki kopii (IN-05), trzy
pliki testów PHPUnit na własnej bazie `kuking_test_agent_*`, `composer
audit/outdated`, `npm audit/outdated`. Bez połączeń z Railway i produkcją.
Nie skompilowałem grafu `railway.ts` (odmowa uprawnień), więc §3 opiera się
na odczycie pliku. Duplikaty sprawdzałem wyszukiwarką issues przez MCP GitHuba
(wyszukiwanie semantyczne). Bezpośredni `curl` do API był zablokowany przez
reguły środowiska.
