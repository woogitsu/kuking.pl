# HANDOVER: koordynator Kuking.pl (30.09.2026, ok. 13:20 UTC)

Przejmujesz koordynację projektu Kuking.pl (repo `woogitsu/kuking.pl`). Z właścicielem rozmawiasz po polsku. Najpierw przeczytaj `AGENTS.md`, jedyne źródło zasad projektu, potem tabelę **D-333** w `docs/DECISIONS.md`. Nie pytaj o to, co tam rozstrzygnięte.

Poprzednia sesja koordynatora (`session_01WvaGtnCzE32Mk9Nj9j5F3e`) kończy się na limicie. **Jej agenci w tle mogą zginąć razem z nią.** Stan ich pracy odczytasz wyłącznie z gałęzi na GitHubie, bo agenci pushowali WIP po każdym kroku.

## Twoja rola i polecenia właściciela
- Koordynujesz i zlecasz pracę agentom. Właściciel pozwala na **do 15 agentów Opus** („jak jest co robić, to weź do 15 agentów Opus i rób”). Integrujesz ich gałęzie w paczki `claude/paczka-*`, otwierasz PR do `main`, pilnujesz CI i je naprawiasz.
- Stałe polecenie właściciela brzmi „scalaj do main co można i rób PR”. **Uwaga:** w tej sesji klasyfikator uprawnień blokował scalanie do `main` bez wyraźnej zgody właściciela wydanej w tej samej sesji („Merge Without Review”). Przed każdym scaleniem PR do main zapytaj właściciela klikalnie (AskUserQuestion), z rekomendacją na pierwszym miejscu. Scalaj zawsze merge commitem (`merge_method: merge`), z `expectedHeadSha`.
- Zgoda właściciela (rejestr, 30.09 ok. 09:4x): agent-integrator MOŻE scalać zrecenzowane gałęzie `claude/*` do gałęzi `claude/paczka-*`.
- Zapisuj pracę na GitHubie na bieżąco. Agenci pushują WIP po każdym kroku.
- Decyzje dla właściciela: po polsku, klikalnie, rekomendacja pierwsza.

## Twarde zasady (nigdy)
- Żadnego force-push, rebase ani `reset --hard` na wypchniętych gałęziach. Integracja tylko przez merge (`--no-ff`).
- Żadnych operacji na produkcji ani zapisów w Railway. Żadnych sekretów w dokumentach.
- Nie pierz odmów uprawnień. Gdy agent albo sesja dostanie odmowę, zgłoś ją właścicielowi i nie rób tego sam innym sposobem.
- Nie pomijaj, nie wyłączaj i nie kwarantannuj testów. Bugfix to test regresyjny i kontrola ujemna.
- Nigdy nie uruchamiaj `npm ci` przez dowiązany `node_modules`.
- Gałęzie kasuje właściciel; sesja dostaje 403.
- Nie dopisuj czterocyfrowych numerów D-xxxx. Decyzja to nowy wiersz w tabeli D-333.

## Środowisko (pułapki z tej sesji)
- **`composer install` pada na PHPStanie:** zipball z api.github.com zwraca 403.
  - Właściciel wybrał **A2**: instalację z tymczasowej kopii `composer.json`/`composer.lock` poza repo, bez `larastan/larastan` i `phpstan/phpstan`:
    - kopia w katalogu roboczym, z `require-dev` bez larastan i z `packages-dev` bez larastan/phpstan;
    - uruchomienie: `COMPOSER=<kopia>/composer.json COMPOSER_ALLOW_SUPERUSER=1 composer install`;
    - `vendor/` ląduje w repo, a pliki repo się nie zmieniają.
  - Lokalnie nie ma więc PHPStana; rozstrzyga Larastan w CI.
  - Budowanie archiwum PHPStana ze źródła do cache Composera klasyfikator odrzucił („Untrusted Code Integration”). Nie próbuj tego.
  - Jeśli właściciel doda w Network access domeny `api.github.com` i `codeload.github.com`, w nowym kontenerze zadziała zwykłe `composer install`.
- **Agenci w worktree:** `cp -al <główny>/vendor vendor` (twarde dowiązania, zero miejsca, autoload liczy się względem worktree). NIGDY nie uruchamiaj `composer` w worktree. `node_modules` dowiąż przez `ln -sfn`. `vendor` z source-install waży 5,2 GB, a na dysku jest ok. 20 GB, więc kopia pełna na agenta się nie zmieści.
- **Izolacja worktree** blokuje `curl` do api.github.com (słowo „git” w adresie), `su` i złożone polecenia. Agent ma czytać issues **narzędziami MCP github, tylko do odczytu**. Wpisz agentom wprost: „nie obchodź blokady innym sposobem”. W tej sesji dwóch agentów próbowało obejścia (rozbicie nazwy w zmiennej, skrypt Pythona) i trzeba to było zgłosić właścicielowi.
- **PostgreSQL:** lokalnie 16.13 na 127.0.0.1:5432 (kuking/kuking), w CI 18. `TestyChodzaNaPostgresieTest` oblewa środowiskowo. Zapytania o `pg_constraint` filtruj po `contype`. Klaster kontroli ujemnych 127.0.0.1:55439 nie działa, więc kontrole ujemne robi się ręcznie.
- **Playwright:** zainstalowany Chromium to 1194, a repo oczekuje headless shell 1243. Działa katalog z dowiązaniem:
  `mkdir -p $X/chromium_headless_shell-1243/chrome-headless-shell-linux64 && ln -s /opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell $X/chromium_headless_shell-1243/chrome-headless-shell-linux64/chrome-headless-shell && touch $X/chromium_headless_shell-1243/{INSTALLATION_COMPLETE,DEPENDENCIES_VALIDATED}`, potem `PLAYWRIGHT_BROWSERS_PATH=$X`. Nie uruchamiaj `playwright install`.
- **Subskrypcja zdarzeń PR** (`subscribe_pr_activity`) w tej sesji nie działała. CI sprawdzaj przez `pull_request_read get_check_runs` oraz `get_job_logs` i planuj kontrole przez `send_later`, bez merge w treści zaplanowanej wiadomości.
- **Trailery commitów:** te, które poda Twoje środowisko (Co-Authored-By oraz Claude-Session Twojej sesji).

## Stan na 13:20 UTC

### Scalone i zamknięte dziś
- **Paczka L (#2339) jest na main**, merge commit `ce4fed406`. Na końcu CI zielone po poprawce testów przeglądarkowych: `bd6473838` i `010ef7f45`, bo `szybki-wyglad.js` importuje `kolor-paska.js`.
- Zamknięte z dowodem (plik i test): #2270, #2227, #2229, #2231, #2235, #2236, #2267, #1011. #2325 zamknięte jako „działa zgodnie z zamierzeniem” (decyzja właściciela).

### 1. Paczka M: `claude/paczka-m-kandydat`, head `cb09bc3dd` (13:01), PR jeszcze nie otwarty
Najpierw 6 gałęzi scaliła poprzednia-poprzednia sesja (`session_01WgxV…`, do `a80795e72`, PHPStan 0). Potem integrator tej sesji scalił `origin/main` (paczka L) i kolejne gałęzie:

| Gałąź | Issue | W paczce |
|---|---|---|
| 2331-szukanie-emoji | #2331 | tak |
| ux-novalidate-wszedzie (zawiera ux-2243-2246 i przycisk „Odwołaj się”) | #2243–#2246 | tak |
| 2228-migrator-checksum | #2228 | tak |
| 2302-infra-p3 | #2302 | tak |
| 2326-limit-obserwowanych-tagow | #2326 | tak |
| 2308-2327-kursor-i-uuid | #2308, #2327 | tak |
| 2300-kolejka-ci-main | #2300 (zamknąć BEZ testu, decyzja właściciela) | tak |
| 2276-bp03-pierwsza-publikacja | #2276 (BP-05 już w #2247, więc Closes) | tak |
| 2259-kopia-z-przyszlosci | #2259 | tak |
| 2292-wydajnosc-f5-f7 | #2292 | tak |
| 2283-prywatnosc-z7-z11 | #2283 | tak |
| 2218-alarm-sufitu-potwierdzen | Refs #2218 | tak |
| 1753-forma-stopki-listow | Refs #1753 | tak |
| 2299-czas-ci | Refs #2299 | tak |
| docblock `RobotsTxt::wolno()` (1e0573b87) | #2325 | tak |
| **d333-decyzje-3009-popoludnie (`b2454c53f`)** | wiersze D-333 | **NIE, jeszcze do scalenia** |

**TODO (paczka M):**
1. Sprawdź, czy agent-integrator jeszcze żyje, np. po nowych commitach na gałęzi po 13:01. Jeśli nie, dokończ sam w worktree na `claude/paczka-m-kandydat`:
   - scal `origin/claude/d333-decyzje-3009-popoludnie` (konflikt w tabeli D-333: zachowaj wszystkie wiersze, bez duplikatów);
   - weryfikacja:
     - pint na zmienionych plikach;
     - strażnicy: `--filter='StraznikTekstuMaKontroleDodatnia|Harmonogram|Polityka|DokumentyPrawne|TekstyNiePrzypisujaPlci|Changelog|Nowosci|DziennikDecyzji|PodzialTestow|KazdaTrasaZIdentyfikatorem|SchematBazy|KanalyAtom'`;
     - testy z `git diff --name-only origin/main...HEAD -- tests`;
     - skrypty: `python3 scripts/test_zawezenie_testow.py`, `python3 tests/skrypty/kontrole-negatywne-przyczyna.py`, `python3 scripts/test_podzial_kontroli.py`, `node --test scripts/railway/iac.test.mjs`;
     - `npm run build` na WŁASNYM `node_modules`.
   - Zwróć uwagę na `Route::patterns` (UUID z 2308-2327) wobec tras kanałów Atom z paczki L.
2. Otwórz PR według `.github/pull_request_template.md`:
   - Closes #2331 #2243 #2244 #2245 #2246 #2228 #2302 #2326 #2308 #2327 #2300 #2276 #2259 #2292 #2283;
   - Refs #2218 #1753 #2299 #2287;
   - w PR zaznacz: „PHPStan lokalnie nie był uruchamiany w części gałęzi (A2) — rozstrzyga Larastan”.
3. Pilnuj CI i naprawiaj na gałęzi paczki.
   - #2299 zmienia infrastrukturę kontroli negatywnych (zawężenie `--filter` do plików, `scripts/zawezenie_testow.py`, wyłącznik `KUKING_KONTROLE_BEZ_ZAWEZENIA=1`). Jeśli kontrole negatywne w CI zachowują się dziwnie, zacznij od tego.
   - Zmierz czas części kontroli (szacunek: ok. 5 min zamiast 20–28).
4. Zielone CI: zapytaj właściciela klikalnie o scalenie, scal merge commitem, a potem ręcznie zamknij issues z listy Closes z komentarzem „Naprawione w paczce M — PR #…, merge commit …” oraz dowodem (plik i test).

### 2. Agenci w toku (mogą zginąć razem z poprzednią sesją; sprawdź gałęzie)
- **#2220, archiwum wersji regulaminu:** `claude/2220-archiwum-regulaminu`, WIP `9747793ff` (13:17). Decyzja właściciela brzmi „budujemy” (wiersz D-333).
  - Zakres: statyczne pliki wersji w repo, strona „Poprzednie wersje” do czytania i pobrania bez JS, stała ścieżka powiązana z `dziennik_zgod.wersja_regulaminu`, test pilnujący, że bieżąca wersja ma plik w archiwum. Politykę prywatności dołączyć, jeśli to tanie.
  - CHANGELOG `[nowa funkcja]` i akapit w `resources/nowosci/tresc.md`. Idzie do paczki N.
  - Jeśli agent nie skończył, zleć dokończenie od WIP.
- **#2299 etap 2, CI:** `claude/2299-czas-ci-etap2`, WIP `45307ea22` (13:19). Baza to `claude/2299-czas-ci`.
  - Decyzja właściciela: pomiar i przyspieszenie Panelu marki (WIP: panel w 2 częściach z jobem zbiorczym), cache `vendor/` i Playwrighta, scalenie krótkich jobów (Audyt, Larastan, #605, Vite).
  - **Zmianę listy wymaganych checków w ochronie gałęzi robi właściciel.** Scalenie jobów nie może zablokować scalania PR-ów, zanim właściciel ją zrobi.
  - Nie ruszać bloku `concurrency`. Idzie do paczki N, po M, bo zależy od 2299.

### 3. Raport i rejestr
- Raport zbiorczy dla „innej sesji”: `claude/raport-sesji-sonnet-3009` → `docs/flota/sesja-sonnet-3009/RAPORT.md` (`7dc761d69`). Zawiera dowody, testy, kontrole ujemne i propozycje PR dla każdej gałęzi.
- Rejestr: `claude/rejestr-koordynatora-2909` → `docs/flota/sesja-koordynatora-2909-b/REJESTR-NA-ZYWO.md`. Dopisuj do niego każde scalenie, decyzję i zlecenie.
- Prompt robotnika z tej sesji (dostosowany do kontenera `/home/user/kuking.pl`, A2, `cp -al`) był w scratchpadzie poprzedniej sesji i nie jest w repo. Weź `PROMPT_ROBOTNIKA_J.md` z gałęzi rejestru i podmień sekcję środowiska według punktu „Środowisko” wyżej.

## Decyzje właściciela z 30.09 po południu (w D-333 na gałęzi d333-decyzje-3009-popoludnie)
- Discord **nie** trafia do polityki prywatności (bez danych osobowych na kanale).
- Retencja `failed_jobs`: 30 dni wystarczy, bez wcześniejszego kasowania.
- Kopia z datą z przyszłości: tolerancja 5 minut.
- Archiwum wersji regulaminu: budujemy.
- #2300: bez testu.
- Czas CI: zlecić trzy kroki z #2299 (patrz wyżej).
- #2325: zamknąć jako „działa zgodnie z zamierzeniem” (już zamknięte).
- Scalenie #2339: tak (już scalone).

## Kroki właściciela (zbieraj, nie rób)
- Z weryfikacji issues:
  - #1306: `KUKING_EDGE_TOKEN`, reguła Cloudflare `X-Kuking-Edge-Token`, potem `KUKING_EDGE_TRYB=egzekwowanie` (LISTA_KROKOW_ALFA C1/C6);
  - #987: odbiór na fizycznym iPhonie (W9);
  - #2025: przełączenie bramki wdrożenia według `docs/infra/RAILWAY_CI_GATE.md` (`RAILWAY_TOKEN_PRODUCTION`, wyłączenie autodeploy, `KUKING_CI_GATED_RAILWAY_DEPLOY=true`);
  - #2296: `KUKING_URODZINY_MAIL_WLACZONY=true` albo czekanie na apply #595 (W12);
  - #2291: `ALTER ROLE … SET jit=off`, restart workera, obserwacja.
- Z poprzedniego handoveru:
  - `KUKING_ALARM_EMAIL` (opcjonalnie `KUKING_ALARM_EMAIL_KONTAKT_NA_DOBE`);
  - `R2_ENDPOINT` musi być https;
  - #1925 (reviewerzy środowiska production), #2049 (DMARC), #2051 (R2 lifecycle `livewire-tmp/`);
  - #2295 (`kuking:zaleznosc-od-starego-bucketu --pliki` przed `railway config apply`), #2296, płatny plan Railway przed apply (#2302), #1895 pkt 5;
  - przegląd prawny #8, #2218, #2220; migracja zdjęć najpierw `--dry-run` (#2228);
  - opcjonalnie `*/kanal` w regule cache Cloudflare.
- Do skasowania przez właściciela: przestarzała gałąź `claude/1011-kontrole-oczekiwana-przyczyna`.
- Dostęp sieci: `api.github.com` i `codeload.github.com` w Network access (PHPStan lokalnie).

## Otwarte issues z pracą kodową bez właściciela (kandydaci na kolejne zlecenia)
- #2218: licznik spraw na suficie w `/health` jako sonda informacyjna bez `degraded`; narzędzie operatora do ręcznego oznaczenia sprawy.
- #1753: odbiór 320 px przy skali 150% z kontem w formie żeńskiej (seeder z `form_of_address` plus skala 150% w `scripts/audyt-ux50plus.mjs`).
- #2287: UX-05 idzie jako #2246, jest w paczce M przez ux-novalidate. Po scaleniu M sprawdź, czy #2287 da się zamknąć.
- `scripts/test_podzial_kontroli.py` nie jest uruchamiany w CI (zgłoszenie agenta #2299; kandydat na małą poprawkę).
- Lista „Nie wcześnie” z `docs/FEATURES.md` i V2 nadal według `docs/ROADMAP.md`: nie zaczynaj niczego z „Nie wcześnie”.
