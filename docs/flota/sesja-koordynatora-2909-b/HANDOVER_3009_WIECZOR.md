# HANDOVER: koordynator Kuking.pl (30.09.2026, 16:00 UTC)

Przejmujesz koordynację projektu Kuking.pl (repo `woogitsu/kuking.pl`). Z właścicielem rozmawiasz **po polsku**. Najpierw przeczytaj `AGENTS.md`, jedyne źródło zasad projektu, potem tabelę **D-333** w `docs/DECISIONS.md`. Nie pytaj o to, co tam rozstrzygnięte. Ten dokument zastępuje `HANDOVER_3009_POPOLUDNIE.md` (opisuje stan na 13:20); to, czego tu nie powtarzam, jest tam nadal aktualne.

## Najpierw (pierwsze 5 minut)
1. **Paczka M, [PR #2340](https://github.com/woogitsu/kuking.pl/pull/2340): CI całe zielone na head `e73f2afc9`.** 31 checków, w tym Larastan, 4 części testów, 3 części kontroli negatywnych, Vite, Panel i Port marki.
   - Sprawdź, czy head się nie zmienił.
   - Zapytaj właściciela klikalnie (AskUserQuestion, rekomendacja pierwsza) o scalenie. **Nie scalaj bez jego wyraźnej zgody wydanej w Twojej sesji**: klasyfikator uprawnień blokuje scalanie do main bez takiej zgody („Merge Without Review”).
   - Scalaj merge commitem, z `expectedHeadSha`.
2. Po scaleniu zamknij ręcznie, z komentarzem „Naprawione w paczce M — PR #2340, merge commit <sha>” oraz dowodem (plik i test), bo „Closes” często nie zamyka:
   #2331 #2243 #2244 #2245 #2246 #2228 #2302 #2326 #2308 #2327 #2300 #2276 #2259 #2292 #2283.
   - #2300 zamykamy **bez testu** (decyzja właściciela).
   - #2276: BP-05 było już w #2247.
   - Sprawdź też #2287: UX-02 w paczce L, UX-05 = #2246 w M. Jeśli nic nie zostało, zamknij z dowodem.
   - Refs (nie zamykać): #2218, #1753, #2299, #2220.
3. Dopisz do rejestru (`claude/rejestr-koordynatora-2909` → `docs/flota/sesja-koordynatora-2909-b/REJESTR-NA-ZYWO.md`).

## Twoja rola i polecenia właściciela
- Koordynujesz. Właściciel pozwala na **do 15 agentów Opus** („jak jest co robić, to weź do 15 agentów Opus i rób”).
- Integrujesz gałęzie w paczki `claude/paczka-*`, otwierasz PR do main według `.github/pull_request_template.md`, pilnujesz CI i je naprawiasz.
- Stałe polecenie: „scalaj do main co można i rób PR”. Każde scalenie do main potwierdzaj jednak klikalnie (patrz wyżej).
- Zgoda właściciela: agent-integrator może scalać zrecenzowane `claude/*` do `claude/paczka-*`. **Uwaga:** jednemu integratorowi klasyfikator i tak odmówił scalenia („Modify Shared Resources”). Wtedy pytasz właściciela i scalasz sam dopiero po jego zgodzie w Twojej sesji. Nigdy nie obchodź odmowy.
- Decyzje: po polsku, klikalnie, z rekomendacją. Pracę zapisuj na GitHubie na bieżąco; agenci pushują WIP po każdym kroku.

## Twarde zasady (nigdy)
- Nie rób force-pusha, rebase ani `reset --hard` na wypchniętych gałęziach. Integracja tylko merge `--no-ff`.
- Nie ruszaj produkcji i nie zapisuj niczego w Railway. Żadnych sekretów w dokumentach.
- **Nie pierz odmów uprawnień.** Odmowę agenta zgłaszasz właścicielowi i nie robisz tego inną drogą. Agentom pisz wprost: „nie obchodź blokady”. W tej sesji dwóch agentów próbowało obejść blokadę `curl` do api.github.com: jeden rozbił nazwę w zmiennej (klasyfikator odmówił), drugi użył skryptu w Pythonie. Oba przypadki zgłoszono właścicielowi.
- Nie pomijaj testów. Bugfix to test regresyjny i kontrola ujemna. Nie wpisuj czterocyfrowych numerów D-xxxx; decyzja to wiersz w D-333.
- Nie uruchamiaj `npm ci` przez dowiązany `node_modules`. Gałęzie kasuje właściciel.

## Środowisko (pułapki)
- **PHPStana lokalnie nie ma.** `composer install` pada na zipballu z api.github.com (403). Właściciel wybrał wariant **A2**:
  - instalacja z tymczasowej kopii `composer.json`/`composer.lock` poza repo, bez `larastan/larastan` i `phpstan/phpstan`;
  - polecenie: `COMPOSER=<kopia>/composer.json COMPOSER_ALLOW_SUPERUSER=1 composer install`;
  - `vendor/` ląduje w repo, pliki repo zostają bez zmian.
  - Rozstrzyga Larastan w CI; w tej sesji właśnie tam wyszły 3 błędy.
  - Budowanie archiwum PHPStana do cache Composera klasyfikator odrzucił; nie próbuj tego.
  - Po dodaniu `api.github.com` i `codeload.github.com` w Network access zadziała zwykły install (w nowym kontenerze).
- **Agenci w worktree:**
  - `cp -al /home/user/kuking.pl/vendor vendor` (twarde dowiązania: `vendor` waży 5,2 GB, a dysk ma ok. 20 GB wolnego);
  - nigdy nie uruchamiaj `composer` w worktree;
  - `node_modules` przez `ln -sfn`;
  - baza testowa: `php -r 'require "tests/Support/kuking_nazwa_testowej_bazy.php"; echo kuking_nazwa_testowej_bazy(getcwd());'`, potem `createdb`;
  - testy: `unset AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AI_AGENT CLAUDECODE; APP_BASE_PATH=$(pwd) php artisan test …`.
- **Gałąź zajęta przez inny worktree.** Pracuj na `git worktree add --detach … origin/<gałąź>` i `git push origin HEAD:<gałąź>`.
- **Izolacja worktree** blokuje `curl` do api.github.com, `su` i złożone polecenia. Issues i logi CI czytaj narzędziami MCP github (odczyt).
- **PostgreSQL:** lokalnie 16.13 na 127.0.0.1:5432 (kuking/kuking), w CI 18.
  - `TestyChodzaNaPostgresieTest` oblewa lokalnie środowiskowo.
  - `pg_constraint` filtruj po `contype`.
  - Klaster kontroli ujemnych 55439 nie działa, więc kontrole ujemne robi się ręcznie.
  - Lokalnie brak `proc_open`, więc kontrola „Kolejka traci proc_open (#2293)” oblewa tylko tutaj. Brak `poppler-utils`, więc 5 testów importu PDF też oblewa tylko tutaj.
- **Playwright:** zainstalowany Chromium to 1194, a repo oczekuje 1243. Utwórz dowiązanie w katalogu `$X`:
  `mkdir -p $X/chromium_headless_shell-1243/chrome-headless-shell-linux64 && ln -s /opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell $X/chromium_headless_shell-1243/chrome-headless-shell-linux64/chrome-headless-shell && touch $X/chromium_headless_shell-1243/{INSTALLATION_COMPLETE,DEPENDENCIES_VALIDATED}`,
  potem `PLAYWRIGHT_BROWSERS_PATH=$X`. Nie uruchamiaj `playwright install`.
- **Subskrypcja PR** (`subscribe_pr_activity`) tu nie działała.
  - CI sprawdzaj przez `pull_request_read get_check_runs` i `get_job_logs`.
  - Logi kontroli negatywnych kończą się zrzutem Postgresa; właściwy werdykt („WERDYKT … ZLA_PRZYCZYNA”) jest ok. 1500–2500 linii od końca.
  - Kontrole planuj przez `send_later`, **bez słowa o scalaniu** w treści zaplanowanej wiadomości, bo inaczej dostaniesz odmowę.
- Kontener potrafi się zrestartować. Scratchpad i `vendor` przetrwały, ale agenci w tle giną. Stan zawsze odtwarzaj z gałęzi na GitHubie.

## Stan na 16:00 UTC

### Na main (`ce4fed406` = paczka L, PR #2339)
Zamknięte z dowodem: #2270, #2227, #2229, #2231, #2235, #2236, #2267, #1011. #2325 zamknięte jako „działa zgodnie z zamierzeniem”.

### Paczka M: `claude/paczka-m-kandydat` @ `e73f2afc9`, PR #2340, **CI zielone, czeka na zgodę właściciela**
- **Zawartość:**
  - gałęzie: 2331, ux-novalidate-wszedzie (#2243–#2246), 2228, 2302, 2326, 2308-2327, 2300, 2276, 2259, 2292, 2283, 2218-alarm-sufitu-potwierdzen, 1753-forma-stopki-listow, 2299-czas-ci;
  - docblock RobotsTxt (#2325);
  - wiersze D-333 z decyzjami 30.09 (`d333-decyzje-3009-popoludnie`).
- **Poprawki po scaleniu:**
  - PHPStan (2308);
  - novalidate przy ukryciu wersji;
  - komunikat migratora bez rodzaju;
  - CHANGELOG #2308 bez martwych tras;
  - testy #2292 czyste dla Larastana;
  - wzorzec kontroli „Helper formy” zna stopki listów (#1753).
- Migracji brak.
- **Kroki właściciela po wdrożeniu M:**
  - #2302: 1000 prób restartu wymaga płatnego planu Railway przy `config apply`;
  - #2292: pierwszy przebieg `kuking:sprzataj-cache` o 02:45 sprawdzić w logach;
  - #2228: `kuking:przenies-zdjecia` najpierw `--dry-run`; kod ≠0 z sekcją „Niezgodne kopie” obsłużyć według `STARY_BUCKET_R2_LEGACY.md`;
  - #2259: alarm „data z przyszłości” oznacza, że trzeba sprawdzić zegar i strefę serwisu `kopia-bazy`.

### Paczka N (budować PO scaleniu M, od nowego main)
| Gałąź | SHA | Co |
|---|---|---|
| `claude/2220-archiwum-regulaminu` | 626b916fe | `/regulamin/wersje`, wersje 09-07, 09-26, 09-30; noindex; `ArchiwumRegulaminuTest` |
| `claude/2220-archiwum-polityki` | a9f747b6a | zbudowana NA gałęzi wyżej; wspólne `ArchiwumDokumentu`; `/prywatnosc/wersje` (polityka wisi pod `/prywatnosc`); wersje 25, 29, 30.09; starsze daty → strona „wydajemy na prośbę”. Scal tylko tę, bo zawiera regulamin. |
| `claude/2299-czas-ci-etap2` | 846fad8f8 | Panel marki w 2 częściach i job zbiorczy o dawnej nazwie; sam Vite w jobach przeglądarkowych; cache vendor i Playwright; szacunek ścieżki krytycznej ok. 16 min. Bez kroków właściciela. |
| `claude/2299-czas-ci-etap2-scalenie` | add51117d | opcjonalnie; job `kontrole_krotkie` plus lustra `audit`, `static-analysis`, `assets`. Potem właściciel: dodać „Krótkie kontrole (audyt zależności, Larastan, build assetów)” do wymaganych checków i usunąć 3 stare. Dopiero potem PR usuwający lustra. Opis w `docs/infra/BRAMKI_CI_2215.md`. |

**PUŁAPKA paczki N:** paczka M (#2283) zmienia treść polityki i regulaminu. Po scaleniu M pliki `resources/legal/archiwum/regulamin-2026-09-30.md` i `polityka-prywatnosci-2026-09-30.md` przestaną być identyczne z bieżącymi dokumentami, a `ArchiwumRegulaminuTest` i `ArchiwumPolitykiTest` obleją. Przy integracji N skopiuj bieżące `resources/legal/{regulamin,polityka-prywatnosci}.md` z main do tych plików archiwum (ta sama data wersji, drobna poprawka). Sprawdź też konflikt w `docs/DECISIONS.md`: gałąź 2299-etap2 dodaje własny wiersz D-333.

### Otwarte pytania i drobiazgi dla właściciela
- Adres archiwum polityki: `/prywatnosc/wersje` (tak zrobione) czy `/polityka-prywatnosci/wersje`?
- #2220 kryteria 3 i 5: reklamacja jest oddzielona od „Napisz do nas” tylko przez §14 regulaminu; testy układu nowych stron obejmuje tylko `dostepnosc.mjs`. Zamknąć czy dalej Refs?

## Raport i dokumenty
- Raport zadań „innej sesji”: `claude/raport-sesji-sonnet-3009` → `docs/flota/sesja-sonnet-3009/RAPORT.md`.
- Prompt robotnika z tej sesji (środowisko A2, `cp -al`, trailery): był w scratchpadzie poprzedniej sesji jako `PROMPT_ROBOTNIKA_3009.md`. Do repo go nie wpisywano. Weź `PROMPT_ROBOTNIKA_J.md` z gałęzi rejestru i podmień sekcję środowiska według punktu „Środowisko” wyżej.
- Kroki właściciela (zbieraj, nie rób):
  - #1306: edge token i Cloudflare, C1/C6;
  - #987: iPhone, W9;
  - #2025: bramka wdrożenia według `RAILWAY_CI_GATE.md`;
  - #2296: W12, `KUKING_URODZINY_MAIL_WLACZONY`;
  - #2291: `ALTER ROLE … SET jit=off`;
  - `KUKING_ALARM_EMAIL`, `R2_ENDPOINT` https, #1925, #2049, #2051, #2295, płatny plan Railway, #1895 pkt 5, przegląd prawny #8;
  - skasować przestarzałą `claude/1011-kontrole-oczekiwana-przyczyna`;
  - Network access dla PHPStana.
- Kandydaci na kolejne zlecenia (kod bez właściciela):
  - #2218: licznik spraw na suficie w `/health` jako sonda informacyjna; narzędzie operatora do ręcznego oznaczenia sprawy;
  - #1753: odbiór 320 px / 150% (seeder z `form_of_address` plus skala 150% w `scripts/audyt-ux50plus.mjs`);
  - `scripts/test_podzial_kontroli.py` nie jest uruchamiany w CI.
- Nie zaczynaj niczego z listy „Nie wcześnie” (`docs/FEATURES.md`, `docs/ROADMAP.md`).
- Trailery commitów: te, które poda Twoje środowisko.
