# CI bez minut GitHub Actions

## Sytuacja

Repozytorium `matmaxalez/kuking.pl` jest **prywatne**. Dla repozytoriów
prywatnych GitHub nalicza minuty Actions z puli konta; konto tej puli
obecnie nie ma. Dla repozytoriów **publicznych** standardowe runnery GitHuba
są darmowe i bez limitu — to jedyna sytuacja, w której „darmowe CI na Actions”
naprawdę istnieje.

Wniosek: **workflow CI jest wyłączony z automatycznego uruchamiania**
(`on: workflow_dispatch`, czyli tylko ręcznie), a bramką jakości jest
kontrola lokalna. Pliki workflow zostają w repozytorium gotowe do włączenia.

Konsekwencja, o której łatwo zapomnieć: `railway.ts` miał `checkSuites: true`
(„Wait for CI”). Przy wyłączonym CI Railway czekałby na check suite, który
nigdy nie powstanie, i **nic by się nie zdeployowało**. Dlatego ta bramka jest
teraz sterowana zmienną `KUKING_WAIT_FOR_CI` i domyślnie wyłączona.

---

## Co działa teraz, za zero złotych

### 1. Kontrola lokalna — dokładnie to samo, co robiłoby CI

```bash
./scripts/check.sh          # pełna kontrola
./scripts/check.sh --szybko # bez budowania assetów
```

Sprawdza: PostgreSQL, formatowanie (Pint), składnię PHP, analizę statyczną,
testy, **odwracalność migracji** (`migrate:refresh`, czyli czy `down()` działa)
i build assetów.

### 2. Hook `pre-push` — żeby nie dało się o tym zapomnieć

```bash
./scripts/install-hooks.sh
```

Od tej chwili `git push` uruchamia kontrolę i przerywa wysyłkę, jeśli coś jest
czerwone. Pominięcie w wyjątkowej sytuacji: `git push --no-verify`.

**To jest szybsze niż CI na GitHubie** — nie ma kolejki runnerów ani czekania
na `composer install` od zera. Wada: działa tylko u osoby, która ma hook
zainstalowany, i nie widać wyniku w PR-ze.

---

## Opcje, kiedy potrzebne będą sprawdzenia w PR-ach

Uporządkowane od najtańszej do najdroższej.

### A. Repozytorium publiczne — 0 zł, CI bez limitu

Jeśli kod może być otwarty, to jest najprostsze rozwiązanie: publiczne
repozytorium ma darmowe i nielimitowane standardowe runnery. Wtedy wystarczy
odkomentować blok `on:` w `.github/workflows/ci.yml`.

**Zanim to zrobisz**, sprawdź:

- czy `LICENSE` odpowiada temu, co chcesz udostępnić (dziś jest tam placeholder
  „proprietary”),
- czy w historii gita nie ma żadnego sekretu (`.env`, klucze R2, DSN Sentry),
- czy udostępnienie planu produktowego z `docs/` jest w porządku — jest tam
  pełna strategia cold startu i analiza konkurencji.

### B. Własny runner (self-hosted) — koszt tylko maszyny

Runner GitHub Actions uruchomiony na własnej maszynie **nie zużywa minut**.
Może to być ten sam Railway, VPS za kilkanaście złotych albo komputer, który
i tak stoi włączony.

Kroki:

1. Ustaw zmienną repozytorium `CI_RUNNER` na `self-hosted`
   (Settings → Secrets and variables → Actions → Variables).
   Workflowy już to czytają: `runs-on: ${{ vars.CI_RUNNER || 'ubuntu-latest' }}`.
2. Zarejestruj runnera: Settings → Actions → Runners → New self-hosted runner.
3. Odkomentuj blok `on:` w `ci.yml`.
4. Ustaw `KUKING_WAIT_FOR_CI=true` przy `railway config apply`, żeby przywrócić
   bramkę „Wait for CI”.

Uwaga bezpieczeństwa: własny runner wykonuje kod z repozytorium. Dla
repozytorium prywatnego z zaufanym zespołem to jest w porządku. **Nigdy nie
podpinaj self-hosted runnera do repozytorium publicznego przyjmującego PR-y
od obcych** — to jest równoznaczne z oddaniem im powłoki na tej maszynie.

Runner potrzebuje: PHP 8.4 z rozszerzeniami z `Dockerfile`, Composer, Node 22,
PostgreSQL 16+ (albo Dockera, żeby postawić usługę `postgres:18-alpine`).

### C. Testy w ramach builda Railway — bez dodatkowej infrastruktury

Do `Dockerfile` (etap `vendor`, gdzie i tak są zależności deweloperskie) można
dodać uruchomienie testów. Nieudane testy przerywają build, więc na produkcję
nie wejdzie czerwony kod.

Zalety: zero dodatkowych narzędzi, działa dla każdego deployu.
Wady: brak informacji zwrotnej przy PR-ze (dowiadujesz się dopiero przy
deployu), dłuższy build, potrzebna baza w trakcie budowania obrazu — a to
jest wyraźnie mniej wygodne niż osobny job.

Traktuj to jako **uzupełnienie** hooka `pre-push`, nie jego zamiennik.

### D. Zewnętrzny darmowy CI

Alternatywy z darmowymi limitami dla repozytoriów prywatnych, np. CircleCI,
Codeberg CI/Woodpecker albo GitLab CI na lustrzanym repozytorium.
Każda z nich oznacza drugie miejsce, w którym trzyma się konfigurację —
warto po nią sięgnąć dopiero, jeśli A i B odpadną.

---

## Rekomendacja

1. **Teraz:** `./scripts/install-hooks.sh` u każdej osoby pracującej nad kodem.
   Zero kosztów, natychmiastowy efekt, łapie to samo, co CI.
2. **Przy pierwszym deployu:** rozważ własny runner na Railway (opcja B) —
   maszyna i tak tam stoi, a wtedy wracają sprawdzenia w PR-ach i można
   przywrócić „Wait for CI”.
3. **Jeśli projekt ma być otwarty:** opcja A rozwiązuje problem całkowicie
   i za darmo.

---

## Checklista włączenia CI, gdy przyjdzie na to czas

- [ ] Odkomentowany blok `on:` w `.github/workflows/ci.yml`
- [ ] Ustawiona zmienna repozytorium `CI_RUNNER` (`ubuntu-latest` lub `self-hosted`)
- [ ] `KUKING_WAIT_FOR_CI=true` przy stosowaniu konfiguracji Railway
- [ ] Sekrety `RAILWAY_TOKEN_PRODUCTION` i `RAILWAY_TOKEN_STAGING` w repozytorium
- [ ] Pierwszy przebieg uruchomiony ręcznie (`workflow_dispatch`) i zielony
- [ ] Reguła ochrony gałęzi `main` wymagająca zielonego CI
