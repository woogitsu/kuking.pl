# Przeniesienie repozytorium do nowej organizacji

Decyzja D-010: repozytorium zostaje prywatne i przenosi się pod **nową
organizację GitHub**, żeby dostać nieużywaną pulę 2 000 minut Actions
miesięcznie.

Ten dokument to lista kroków i — ważniejsze — lista rzeczy, które przy
transferze **nie przenoszą się same**.

> **Stan: transfer wykonany 5 września 2026.**
> Repozytorium żyje pod adresem `woogitsu/kuking.pl`, wszystkie 38 issues
> zachowało numery. Kroki 1 i 2 są historią; **kroki 3 i 5 zostają aktualne**,
> bo opisują ustawienia, które trzeba było odtworzyć po stronie GitHuba
> i Railwaya — a tego nie da się zrobić z poziomu repozytorium.
>
> **Sprostowanie, 11 września 2026.** Stało tu „kroki 3-5 zostają aktualne",
> a krok 4 („Włączenie CI") jest wykonany od 5 września: CI chodzi (D-010,
> commit `4714011`), `.github/workflows/ci.yml` ma aktywny blok `on:`
> z `push`/`pull_request` na `[main, staging]` **oraz** `workflow_dispatch`
> obok. Nie ma tam czego odkomentowywać i nie ma tymczasowego
> `on: workflow_dispatch:` do usunięcia — punkt 1 kroku 4 był nieprawdą
> od 5 września. Zgłosił to audyt z 8 września
> (`docs/AUDYT_2026-09.md`, wiersz 7 tabeli dokumentów).

---

## Dlaczego transfer, a nie nowe repozytorium

**Przenieś repozytorium (Transfer ownership), nie zakładaj nowego i nie
kopiuj plików.** Transfer zachowuje:

- całą historię gita,
- **issues wraz z numerami** — a mamy ich 38 i są ze sobą polinkowane,
- Pull Requesty i komentarze,
- **przekierowania ze starego adresu** — stare linki, także te w opisach
  issues, dalej działają.

Założenie nowego repozytorium i wypchnięcie kodu traci issues. Przy 38 issues
z opisami, kryteriami akceptacji i wzajemnymi odwołaniami to jest realna strata.

---

## Kroki

### 1. Utworzenie organizacji

GitHub → menu konta → **Your organizations** → **New organization** → plan **Free**.

Plan Free dla organizacji daje **2 000 minut Actions miesięcznie** dla
repozytoriów prywatnych. Pula jest liczona per konto/organizacja, więc nowa
organizacja startuje z pełnym limitem. `[do weryfikacji na liczniku po transferze]`

Nazwa organizacji: warto, żeby była neutralna i trwała, bo trafi do adresu
repozytorium i do wszystkich linków. **Wybrano `woogitsu`.**

### 2. Transfer

Repozytorium → **Settings** → na dole **Danger Zone** → **Transfer ownership**.
Jako nowego właściciela podaj nazwę organizacji.

Musisz być właścicielem organizacji albo mieć w niej prawo tworzenia repozytoriów.

### 3. Co trzeba ustawić PONOWNIE po transferze

To jest najważniejsza część tego dokumentu. Transfer nie przenosi wszystkiego:

| Rzecz | Co zrobić |
|---|---|
| **Zmienna `CI_RUNS_ON`** | **Sprostowanie z 11 września 2026:** stało tu, że zmienna `CI_RUNNER` jest zbędna, „bo każdy job ma wpisany zestaw etykiet własnej puli". Nieprawda od 8 września (wieczorem): `CI_RUNNER` faktycznie zniknęła, ale zastąpiła ją `CI_RUNS_ON`, którą czyta `runs-on` we wszystkich czterech workflow-ach (`${{ fromJSON(vars.CI_RUNS_ON \|\| '"ubuntu-latest"') }}`). Bez niej joby chodzą na runnerach GitHuba; wartość na własną pulę: `["self-hosted","Linux","X64","woogitsu","i5-10400f","nvidia-gtx1070"]` (D-028) |
| **Sekrety Actions** | Sprawdź, czy przeżyły transfer. Jeśli nie — dodaj ponownie: `RAILWAY_TOKEN_PRODUCTION`, `RAILWAY_TOKEN_STAGING`, `SENTRY_AUTH_TOKEN` `[do weryfikacji]` |
| **Ochrona gałęzi `main`** | Reguły trzeba ustawić od nowa. W organizacji można to zrobić raz, jako ruleset |
| **Uprawnienia Actions** | Settings → Actions → General: sprawdź, czy workflowy mogą się w ogóle uruchamiać (organizacja może mieć restrykcyjne domyślne) |
| **Integracja Railway ↔ GitHub** | Aplikacja GitHub Railway musi dostać dostęp do **organizacji**, nie tylko do konta osobistego. Bez tego deploy przestanie działać |
| **Dostęp dla agentów AI** | Sesje Claude Code miały zakres ustawiony na starą ścieżkę. Po transferze trzeba dodać `woogitsu/kuking.pl` — inaczej agent traci dostęp do repozytorium. ✅ zrobione |
| **Zdalne repozytorium lokalnie** | `git remote set-url origin https://github.com/woogitsu/kuking.pl.git` — przekierowanie działa, ale lepiej mieć poprawny adres. ✅ zrobione |

### 4. Włączenie CI — ZROBIONE 5 września 2026, krok historyczny

⚠️ Ten krok jest **wykonany**; zostaje jako zapis, co się wtedy robiło.
Punkt „odkomentuj blok `on:`" był nieprawdą już wtedy — blok `on:` w `ci.yml`
nie był zakomentowany nigdy, a `workflow_dispatch` nie jest „tymczasowy",
tylko stoi celowo obok wyzwalaczy automatycznych.

1. ~~Odkomentuj blok `on:` w `.github/workflows/ci.yml`~~ — nic do zrobienia:
   `ci.yml` wyzwala CI na `push` i `pull_request` do `main` i `staging`,
   a `workflow_dispatch` zostaje obok, żeby dało się puścić przebieg ręcznie
2. Uruchom pierwszy przebieg **ręcznie**: Actions → wybierz workflow → Run workflow
3. Sprawdź licznik: Settings organizacji → **Billing and plans** → sekcja Actions
4. Gdy przebieg jest zielony — ustaw ochronę gałęzi `main` wymagającą CI

### 5. Na końcu: „Wait for CI" w Railway

⚠️ `KUKING_WAIT_FOR_CI=true` ustawiamy **dopiero po pierwszym zielonym
przebiegu**. Wcześniej Railway czekałby na check suite, który nie powstaje,
i nic by się nie zdeployowało.

---

## Czego transfer NIE zmienia

- Repozytorium **zostaje prywatne** — transfer nie zmienia widoczności
- Historia gita, issues i ich numery są nienaruszone
- Przekierowania GitHuba obsługują stary adres, więc nic nie przestaje działać
  z dnia na dzień. Pełny adres występował w `README.md`, `Dockerfile`
  (`org.opencontainers.image.source`), `.railway/railway.ts` (stała `REPO`)
  i w kilku dokumentach — **wszystkie zaktualizowano na `woogitsu/kuking.pl`**,
  żeby nie polegać bezterminowo na przekierowaniu

---

## Plan awaryjny

Ten akapit jest już historią: workflowy **chodzą** na własnej puli
`woogitsu-linux-01`–`woogitsu-linux-10` i nie sięgają po runnery GitHuba
w ogóle. Runnera wskazuje zestaw etykiet w każdym jobie
(`[self-hosted, Linux, X64, woogitsu, i5-10400f, nvidia-gtx1070]`), a nie
zmienna repozytorium. Rejestracja i lista etykiet: `SELF_HOSTED_RUNNER.md`.

## Referencje

`../DECISIONS.md` D-010 · `CI_BEZ_ACTIONS.md` · `SELF_HOSTED_RUNNER.md` · issue #4
