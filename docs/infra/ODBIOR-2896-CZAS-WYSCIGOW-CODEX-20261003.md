# Budżet kompletnego zadania wyścigów — odbiór #2896

Refs #2896. Jedyna zmiana wykonywalna: `.github/workflows/ci.yml`,
`jobs.dwa-polaczenia.timeout-minutes`, **15 → 25 minut**. Nazwa, warunki,
runner, PostgreSQL, polecenie, wszystkie testy i kontrole pozostają bez zmian.
Nie zmieniamy czasu oczekiwania na kolejkę ani limitów SQL i procesów Dwa.

## Powód i kontrakt

Pełne CI W/X/Y na `e8e7ff6a683d54d84eaed963a6b933e11b591e21`
([run 37147521904](https://github.com/woogitsu/kuking.pl/actions/runs/37147521904),
[job 111274465911](https://github.com/woogitsu/kuking.pl/actions/runs/37147521904/job/111274465911))
anulowało Dwa z adnotacją „maximum execution time of 15m0s”. Według świeżego
issue i odczytu koordynatora: 478/8856 przeszło, 24 z 25 kontroli skończyły się,
S4 nie ma terminalnego werdyktu; 23/24 jobów SUCCESS, jeden CANCELLED.
Ten przebieg nie jest zieloną bramką ani podstawą merge.

Nowy budżet dotyczy CAŁEGO joba, w tym przygotowania zależności, schematu,
pełnej grupy oraz kontroli uruchamianych przy `CI=true`. Pozostaje skończony.
Pomiar peer opisał pozostałe trzy istniejące timeouty S4 po 180 s i granicę
prefiks + ogon około 23 min 50 s, przed sprzątaniem. Nie dodajemy usługi,
runnera ani nowej decyzji produktu lub kosztu.

D-105 i `BRAMKI_CI_2215.md` nadal wymagają prawdziwego pomiaru i blokowania
czerwieni. `SEKUNDY_NA_KOLEJKE=15` to osobne **sekundy**, nie budżet joba;
nie podnosimy go dla uspokojenia migających testów. D-333 zachowuje pełny
zakres niedraftowych PR i kontrolę właściciela nad required checks.

## Źródło i izolacja

- Własny WT: `C:\Users\matma\.codex\worktrees\2896-czas-wyscigow-ci-20261003\Portale`, gałąź `codex/2896-czas-wyscigow-ci-20261003`, rodzic dokładnie `e8e7ff6a683d54d84eaed963a6b933e11b591e21`.
- Runtime: `/home/codex-admin/kuking-koordynacja-20261003-codex/repo-2896-czas-wyscigow-ci`, jawny `APP_BASE_PATH`, własne media i nowy klucz z `.env.example`. Bez czytania cudzych `.env`.
- Preflight przed createdb: PostgreSQL **180006**, host `127.0.0.1`, port **55488**, rola/owner `kuking_pg18_owner`; obie nazwy wcześniej nie istniały. UTF8/template0/C.utf8, owner sprawdzony po utworzeniu.
- Feature: `kuking_test_2896_czas_wyscigow_ci`; Dwa: `kuking_race_kat_repo_2896_czas_wyscigow_ci_29ec8868`, wyliczona rzeczywistą funkcją repo i sprawdzona przed przygotowaniem. Brak operacji na gołym `kuking_race` i cudzych bazach.
- Fizyczne vendor/node_modules z własnej zakończonej instancji, zwykłe composer install i npm ci: **136 pakietów PHP / 202 npm**, dokładne wersje i integralności z locków. Katalogi zależności nie są dowiązaniami.
- Konfiguracja Laravela i Reflection potwierdzają własną ścieżkę klas. Jawne `PGHOST/PGPORT/PGUSER/PGDATABASE` kierują również `pg_isready` skryptu na własny klaster, bez jego gałęzi uruchamiającej domyślny klaster.
- Brak zewnętrznego `DB_DATABASE` w procesie canonical; skrypt sam ustawia własną nazwę Dwa. `DB_URL` puste, `CI=true` wyłącznie w środowisku tego procesu.

## Jeden terminalny pełny przebieg

Polecenie **`bash scripts/testy-dwa-polaczenia.sh`**, bez filtrów i skrótów,
PID **314661**, kontroler **314550**. Zakończone jeden raz, **exit 0**,
**907,146554 s = 15 min 7 s**. Nie powtarzano skryptu ani jego części.

- Główna grupa: **478 PASS / 8856 asercji**.
- Wszystkie **25** istniejących kontroli ujemnych oraz **2** odrębne sprawdzenia celu #2783/#2855 zakończone. W niezmienionym skrypcie każda nieudana kontrola kończy go exit 1; końcowe „Przebiegów zielonych: 1 z 1” nastąpiło po S4.
- S4 z tego samego wykonania: **9/649 PASS → 9/595 z 9 właściwymi FAIL → 9/649 PASS**. Pełne identities i marker `COOKIE_S4_A_ODMOWA`, `ExpectationFailedException`, zero ERROR/SKIP.
- Odczytowy obserwator zachował trzy rzeczywiste JUnit S4 przed usunięciem tempfile: tylko potomkowie własnego PID z właściwym cwd i ich konkretny `--log-junit`; bez modyfikacji skryptu/helpera i bez dodatkowego uruchomienia.
- Istniejące strażniki CI, **subset 20/4949 PASS**, exit 0, zero ERROR/SKIP: blokowanie wyścigów/audytu, duplikaty kluczy YAML, bramka zakresu i zakres draftów. To nie pełny hook.

Nie dodano testu kopiującego literal nowego timeoutu. Regresją jest istniejący
kompletny skrypt: wcześniejszy realny limit GitHuba uciął końcówkę, a teraz
lokalnie wykonano ją całą. Lokalny czas nie zastępuje pełnego CI nowego heada.
Parsed YAML wykazuje zmianę tylko wskazanego timeoutu; **7046** plików archiwum
źródłowego zgadza się z runtime. Oba rejestry bez zmian: **580 checks / 580 wzorców**.

## Bajty i mtime — dwa oddzielne dowody

Canonical przywrócił SHA256/MD5/rozmiar wszystkich **4134** obserwowanych źródeł.
Surowy snapshot po nim ma zmienione wyłącznie mtime w
`DecyzjaPoOdwolaniu.php` (#2165) i `UnfollowUser.php` (#2404): stare helpery
nie zapewniają odtworzenia tego metadatum. Nie zmieniono tych helperów.
Obserwator zakończył się exit 1 na swoim zbyt szerokim założeniu pełnego mtime;
**nie jest to exit canonical**, którego zapisany wynik wynosi 0.

Po terminalnym wykonaniu, po weryfikacji identycznych SHA/MD5/rozmiaru,
przywrócono tylko zapamiętane mtime tych dwóch własnych plików. Zachowano
before, raw-after oraz terminal snapshot i osobny `terminal-restore.json`.
Terminalne **4134 źródła są dokładnie zgodne z before**; SHA256 obu snapshotów
`256fd72efc8b5272905d0704dc2c77e710df05fa21fbdac06d70d3e5fdac06ed`.
S4 ma własne finally i exact bytes/mtime bez tego dodatkowego kroku:
SHA256 `fcbb6fc35f984addf882bf83d82872dd1b4199dea0ff066221bc0af172bfdbe8`,
mtime_ns `1791052801000000000`, zgodne z logiem i snapshotem.

## Artefakty i dalsza bramka

Poza repo: `C:\Users\matma\.codex\worktrees\2896-czas-wyscigow-ci-20261003\evidence`;
surowe w `raw`. Zdalnie sąsiedni katalog `evidence-2896-czas-wyscigow-ci`.
Zachowane canonical.sh/log/exit/pid/process/result, trzy JUnit S4, preflight,
zależności, strażniki, trzy snapshoty, terminal restore, audyty i manifest.
Pełny log **226779 bajtów**, SHA256
`5044cd28bb2194820ce239f76c37d9a1a38e3f6391ce4ace32733765f7779110`.
Canonical skrypt SHA256
`387514517615f0e93c4e5c05a0bce5d5e2da78352fb5d760846d6c52f234c459`,
niezmieniony git blob `1734c8b92b1b0af05f204635fc52fa81b6b4c16c`.
Workflow po korekcie SHA256
`8e1ba93de07818b7518d24b6ea0b1d2f1820ca0715d1bd305d2f5e43ee60d14c`.

Osobny czysty lokalny commit podany w raporcie, bez pusha/PR/merge i zmian
W/Claude/#2812. Nie wykonano pełnego 14k hooka, nie ponawiano GitHub CI.
Root wykona zwykły pełny push/hook i pełne niedraftowe CI nowego dokładnego
heada. Odbiór wydania nadal wymaga CodeQL, CI main i trzech usług produkcji.
**#2896 pozostaje OPEN do właściwego wydania.**
