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

### Ile realnie kosztuje jeden przebieg — POMIAR, nie szacunek

Pierwotny szacunek („4-8 minut na przebieg, 250-500 przebiegów") liczył czas
`./scripts/check.sh` na jednej maszynie. **To była zła jednostka.**

GitHub nalicza minuty **per job i zaokrągla każdy job w górę do pełnej minuty**.
Nasze CI ma sześć równoległych jobów, więc jeden przebieg kosztuje minimum
6 minut, nawet gdyby każdy job trwał po 10 sekund.

Zmierzone na przebiegu z 5 września 2026 (repozytorium prywatne, `ubuntu-latest`,
mnożnik ×1):

| Job | Czas | Naliczone |
|---|---|---|
| Build assetów (Vite) | 15 s | 1 min |
| Pint | 20 s | 1 min |
| Larastan | 24 s | 1 min |
| Audyt zależności | 17 s | 1 min |
| Testy (PostgreSQL 18) | 61 s | 2 min |
| **Build obrazu** | **184 s** | **4 min** |
| **Razem** | ~3,3 min zegarowo | **≈10 min** |

Czyli **2 000 minut to około 200 przebiegów miesięcznie**, nie 250-500.
To nadal jest spory zapas — ~6-7 pushy dziennie przez cały miesiąc — ale
warto planować na tej liczbie, a nie na tamtej.

**Najdroższy pojedynczy job to build obrazu: 4 z 10 minut.** Gdyby zrobiło się
ciasno, to on jest pierwszym kandydatem do ograniczenia (np. tylko przy zmianach
`Dockerfile`, `composer.*`, `package*.json`, `docker/`). Nie robimy tego teraz,
bo jest bramką deployu.

Co już oszczędza minuty:

- `concurrency: cancel-in-progress` w `ci.yml` — nowy push anuluje poprzedni
  przebieg zamiast go dokańczać;
- `preview.yml` i `railway-iac.yml` pomijane przez `KUKING_DEPLOY_ENABLED`
  (pominięty job kosztuje 0);
- hook `pre-push` — błąd złapany lokalnie to przebieg, który się nie odbył.

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

**Baza testowa musi być podana jawnie** (issue #732): `DB_HOST`, `DB_PORT`,
`DB_DATABASE` i `DB_USERNAME`. Bez nich pełna kontrola kończy się od razu
z listą brakujących zmiennych; w trybie `--szybko` (hook `pre-push`) brak
zmiennych daje tylko ostrzeżenie i pomija sondę — nie zgaduje domyślnego portu, bo w środowisku
współdzielonym to może być cudza baza. Sonda nie uruchamia żadnego klastra;
„przyjmuje połączenia” nie znaczy jeszcze, że hasło i baza są poprawne
(to sprawdzają testy). Przykład z własną bazą na 55439:

```bash
DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=kuking_test_zadanie DB_USERNAME=kuking ./scripts/check.sh
```

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

1. Nic nie ustawiaj — workflowy nie czytają już żadnej zmiennej. Każdy job ma
   wpisany zestaw etykiet:
   `runs-on: [self-hosted, Linux, X64, woogitsu, i5-10400f, nvidia-gtx1070]`.
2. Zarejestruj runnera: Settings → Actions → Runners → New self-hosted runner.
   Przy `./config.sh` podaj WSZYSTKIE sześć etykiet — brak jednej wystarcza,
   żeby job nigdy nie wystartował. Dodatkowe `i5-10400f` i `nvidia-gtx1070`
   są tam po to, żeby joby nie trafiały na starą pulę WSL-ową
   (`woogitsu-wsl-DOM-NEW-01`–`04`), która ich nie ma.
3. Odkomentuj blok `on:` w `ci.yml`.
4. Ustaw `KUKING_WAIT_FOR_CI=true` przy `railway config apply`, żeby przywrócić
   bramkę „Wait for CI”.

Uwaga bezpieczeństwa: własny runner wykonuje kod z repozytorium. Dla
repozytorium prywatnego z zaufanym zespołem to jest w porządku. **Nigdy nie
podpinaj self-hosted runnera do repozytorium publicznego przyjmującego PR-y
od obcych** — to jest równoznaczne z oddaniem im powłoki na tej maszynie.

Runner potrzebuje: PHP 8.4 z rozszerzeniami z `Dockerfile`, Composer, Node 22,
PostgreSQL 18+ (albo Dockera, żeby postawić usługę `postgres:18-alpine`).

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
- [x] Krok PHPStana warunkowy — bez `phpstan.neon` job kończy się zielony
      z adnotacją, zamiast zapalać lampkę, której nie da się naprawić kodem.
      Zacznie blokować sam, gdy #32 doda konfigurację — **nic nie trzeba
      wtedy zmieniać w workflow**
- [ ] Runnery `woogitsu-linux-01`–`woogitsu-linux-10` są **Idle** w panelu
      i mają wszystkie sześć etykiet z `runs-on`. Zmienna `CI_RUNNER` nie jest
      już przez nic czytana i można ją usunąć. **Joby nie mają zapasu
      w runnerach GitHuba** — gdy cała pula jest offline, przebiegi wiszą
      w kolejce w nieskończoność, a razem z nimi wdrożenie (Railway ma
      „Wait for CI")
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
