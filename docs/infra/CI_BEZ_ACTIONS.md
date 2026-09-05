# CI: jak długo działaliśmy bez minut i jak je odzyskaliśmy

> **Stan: CI jest WŁĄCZONE** (D-010, issue #4).
> Ten dokument opisuje, dlaczego przez pewien czas go nie było, i zostaje
> w repozytorium jako **plan awaryjny** — opcje A-D niżej są nadal aktualne
> i przydadzą się, gdyby limit organizacji się wyczerpał.

## Sytuacja pierwotna

Repozytorium `woogitsu/kuking.pl` jest **prywatne**. Dla repozytoriów
prywatnych GitHub nalicza minuty Actions z puli konta; konto właściciela tej
puli nie miało — została zużyta na inny projekt. Dla repozytoriów
**publicznych** standardowe runnery GitHuba są darmowe i bez limitu — to
jedyna sytuacja, w której „darmowe CI na Actions” naprawdę istnieje.

Przez ten czas workflow CI był wyłączony z automatycznego uruchamiania
(`on: workflow_dispatch`), a bramką jakości była kontrola lokalna.

## Jak to rozwiązaliśmy

Pula minut jest liczona **per konto lub organizacja**, nie globalnie na
człowieka. Repozytorium przeniosło się więc pod nową organizację `woogitsu`
na planie Free, która startuje z pełnym, nieużywanym limitem **2 000 minut
miesięcznie** — także dla repozytoriów prywatnych.

`./scripts/check.sh` trwa 4-8 minut, więc to jest **250-500 przebiegów
miesięcznie**, z dużym zapasem przy obecnym tempie prac.

Szczegóły transferu: `PRZENIESIENIE_DO_ORGANIZACJI.md`.

⚠️ Konsekwencja, o której łatwo zapomnieć: `railway.ts` ma bramkę
„Wait for CI” (`source.checkSuites`) sterowaną zmienną `KUKING_WAIT_FOR_CI`.
Przy wyłączonym CI Railway czekałby na check suite, który nigdy nie powstanie,
i **nic by się nie zdeployowało**. Dlatego `KUKING_WAIT_FOR_CI=true`
ustawiamy **dopiero po pierwszym zielonym przebiegu**.

---

## Kontrola lokalna — zostaje mimo działającego CI

Hook `pre-push` **nie jest** zastąpiony przez CI. Jest szybszy i łapie błąd,
zanim ten trafi na GitHuba — czyli zanim zje minuty z puli.

### 1. Kontrola lokalna — dokładnie to samo, co robi CI

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

## Plan awaryjny: opcje, gdyby limit organizacji się wyczerpał

Uporządkowane od najtańszej do najdroższej. **Żadna nie jest w tej chwili
potrzebna** — są tu na wypadek, gdyby 2 000 minut przestało wystarczać.

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

1. **Zawsze:** `./scripts/install-hooks.sh` u każdej osoby pracującej nad kodem.
   Zero kosztów, natychmiastowy efekt, łapie to samo, co CI — tylko szybciej
   i bez zużywania minut.
2. **Gdy licznik zacznie się zbliżać do limitu:** własny runner (opcja B).
   Instrukcja jest gotowa w `SELF_HOSTED_RUNNER.md`, przełączenie to jedna
   zmienna repozytorium.
3. **Jeśli projekt kiedykolwiek ma być otwarty:** opcja A znosi problem
   całkowicie i na stałe.

Licznik zużycia: Settings organizacji → **Billing and plans** → sekcja Actions.
Warto na niego zerknąć po pierwszym miesiącu, żeby zweryfikować szacunek
4-8 minut na przebieg.

---

## Checklista włączenia CI

- [x] Odkomentowany blok `on:` w `.github/workflows/ci.yml`
- [ ] Zmienna repozytorium `CI_RUNNER` **usunięta** albo ustawiona na
      `ubuntu-latest` (Settings → Secrets and variables → Actions → Variables).
      Workflowy mają `runs-on: ${{ vars.CI_RUNNER || 'ubuntu-latest' }}`, więc
      bez zmiennej same wybiorą runnery GitHuba — ale **jeśli zmienna przeżyła
      transfer z wartością `self-hosted`, joby będą wisieć w kolejce
      w nieskończoność**, czekając na runnera, którego nie ma
- [ ] Uprawnienia Actions w organizacji pozwalają uruchamiać workflowy
      (Settings → Actions → General)
- [x] Workflowy wdrożeniowe (`preview.yml`, `railway-iac.yml`) zablokowane
      zmienną `KUKING_DEPLOY_ENABLED`. Bez projektu Railway i sekretów padały
      na każdym Pull Requeście (D-011 odkłada deploy, #3), a stała czerwona
      lampka uczy, że czerwone CI się ignoruje. Pominięty job nie zużywa minut.
      **Przy zamykaniu #3 ustaw tę zmienną na `true`** — inaczej deploy
      i testy dymne zostaną wyłączone po cichu
- [ ] Pierwszy przebieg zielony
- [ ] Reguła ochrony gałęzi `main` wymagająca zielonego CI
- [ ] Sekrety `RAILWAY_TOKEN_PRODUCTION` i `RAILWAY_TOKEN_STAGING`
      (dopiero przy deployu — #3, zablokowane przez D-011)
- [ ] **Na końcu** `KUKING_WAIT_FOR_CI=true` przy stosowaniu konfiguracji Railway
