## D-266 — Dwa runnery zarezerwowane dla `main`: `CI_RUNS_ON_MAIN` przed `CI_RUNS_ON`, ciąg dalszy D-121 (25 września 2026)

**Data:** 25 września 2026 · Status: **obowiązuje** · Ciąg dalszy **D-121**

**Problem.** Pula `CI_RUNS_ON` jest wspólna dla PR-ów i dla `main`. CI na
`main` jest jedynym momentem, po którym Railway wdraża („Wait for CI") —
a przy kilku PR-ach naraz przebiegi `main`-a stały w TEJ SAMEJ kolejce co
PR-y i czekały na wolną maszynę razem z nimi. Wdrożenie na produkcję
głodniało przez ruch, który z produkcją nie ma nic wspólnego.

**Decyzja.** Właściciel oznaczył **dwa** runnery z puli `woogitsu-linux-*`
dodatkową etykietą `kuking-main` (wyłącznie dla przebiegów `main`-a),
a pozostałe etykietą `kuking-pr` (PR-y i `staging`). W `.github/workflows/
ci.yml` każdy job, który czyta `CI_RUNS_ON`, dla przebiegu będącego
PRAWDZIWYM pushem na `main` (`github.ref == 'refs/heads/main' &&
github.event_name == 'push'` — nie dla PR-a do `main`, gdzie `github.ref` to
`refs/pull/<n>/merge`, i CELOWO nie dla ręcznego `workflow_dispatch` na tej
gałęzi) sięga NAJPIERW po `CI_RUNS_ON_MAIN`, dopiero bez niej po `CI_RUNS_ON`:

```yaml
runs-on: ${{ fromJSON((github.ref == 'refs/heads/main' && github.event_name == 'push' && vars.CI_RUNS_ON_MAIN) || vars.CI_RUNS_ON || '"ubuntu-latest"') }}
```

Job przeglądarkowy `port_funkcje` ma analogiczną, osobną parę
(`CI_RUNS_ON_BROWSER_MAIN` / `CI_RUNS_ON_BROWSER`), z tego samego powodu, dla
którego ma już dziś osobną zmienną od zwykłych jobów (własne środowisko
docelowe, patrz nagłówek `ci.yml`, blok „JOB PRZEGLĄDARKOWY").

Bez żadnej z tych dwóch nowych zmiennych zachowanie jest DOKŁADNIE takie jak
dziś (`vars.CI_RUNS_ON || '"ubuntu-latest"'`) — zmiana jest bezpieczna, zanim
właściciel ustawi zmienne.

**Dlaczego nie tylko `deploy.yml`.** Bramką deployu jest `ci.yml` (Railway
czeka na jego check suite), nie `deploy.yml` (ten reaguje na
`deployment_status`, już PO deployu — smoke testy). `deploy.yml`,
`preview.yml` i `railway-iac.yml` NIE uruchamiają się pushem na `main` (kolejno:
`deployment_status`, `pull_request`, `pull_request`), więc warunek main-a
w nich nigdy by nie trafił — dopisanie go byłoby martwym kodem. Zostają przy
samym `CI_RUNS_ON`.

**Kolejność wdrożenia, nie do odwrócenia:**
1. Właściciel oznacza fizycznie DWA runnery etykietą `kuking-main`,
   a pozostałe etykietą `kuking-pr` (GitHub → Settings → Actions → Runners).
2. Dopiero POTEM ustawia zmienne repozytorium `CI_RUNS_ON_MAIN` i `CI_RUNS_ON`
   (Settings → Secrets and variables → Actions → Variables), przykładowo:

```text
CI_RUNS_ON_MAIN = ["self-hosted","Linux","X64","woogitsu","i5-10400f","nvidia-gtx1070","kuking-main"]
CI_RUNS_ON      = ["self-hosted","Linux","X64","woogitsu","i5-10400f","nvidia-gtx1070","kuking-pr"]
```

W odwrotnej kolejności zmienna wskazywałaby etykietę, której żaden runner
jeszcze nie nosi — GitHub Actions nie odrzuca wtedy joba, tylko trzyma go
w „Queued" bez końca, a przez „Wait for CI" stoi wtedy i wdrożenie (ten sam
koszt co offline'owa pula, D-121).

**Co musiałoby się stać, żeby to zmienić:** flota przestaje dzielić maszynę
z runnerami CI (wtedy rezerwacja main-a przestaje być potrzebna) albo
właściciel uzna, że dwa runnery to za mało/za dużo dla `main`.

Dowody: `tests/Feature/DokumentyCiMowiaPrawdeORunnerzeTest.php`
(`test_joby_ci_rezerwuja_zmienna_ci_runs_on_main` i rozszerzone porównanie
dokument-kod).

### Wycofanie
Odwrócić commit w `.github/workflows/ci.yml`. Schemat bazy się nie zmienia.
Zmienne `CI_RUNS_ON_MAIN`/`CI_RUNS_ON_BROWSER_MAIN` w ustawieniach
repozytorium przestają być czytane i można je skasować; etykiety
`kuking-main`/`kuking-pr` na runnerach mogą zostać bez efektu.
