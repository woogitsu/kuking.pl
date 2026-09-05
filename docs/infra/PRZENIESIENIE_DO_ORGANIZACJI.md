# Przeniesienie repozytorium do nowej organizacji

Decyzja D-010: repozytorium zostaje prywatne i przenosi się pod **nową
organizację GitHub**, żeby dostać nieużywaną pulę 2 000 minut Actions
miesięcznie.

Ten dokument to lista kroków i — ważniejsze — lista rzeczy, które przy
transferze **nie przenoszą się same**.

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

Nazwa organizacji: warto, żeby była neutralna i trwała (np. `kuking`),
bo trafi do adresu repozytorium i do wszystkich linków.

### 2. Transfer

Repozytorium → **Settings** → na dole **Danger Zone** → **Transfer ownership**.
Jako nowego właściciela podaj nazwę organizacji.

Musisz być właścicielem organizacji albo mieć w niej prawo tworzenia repozytoriów.

### 3. Co trzeba ustawić PONOWNIE po transferze

To jest najważniejsza część tego dokumentu. Transfer nie przenosi wszystkiego:

| Rzecz | Co zrobić |
|---|---|
| **Zmienna `CI_RUNNER`** | Jeśli była ustawiona — teraz jest zbędna. Usuń ją albo ustaw na `ubuntu-latest`; workflowy mają `runs-on: ${{ vars.CI_RUNNER \|\| 'ubuntu-latest' }}`, więc bez zmiennej same wybiorą runnery GitHuba |
| **Sekrety Actions** | Sprawdź, czy przeżyły transfer. Jeśli nie — dodaj ponownie: `RAILWAY_TOKEN_PRODUCTION`, `RAILWAY_TOKEN_STAGING`, `SENTRY_AUTH_TOKEN` `[do weryfikacji]` |
| **Ochrona gałęzi `main`** | Reguły trzeba ustawić od nowa. W organizacji można to zrobić raz, jako ruleset |
| **Uprawnienia Actions** | Settings → Actions → General: sprawdź, czy workflowy mogą się w ogóle uruchamiać (organizacja może mieć restrykcyjne domyślne) |
| **Integracja Railway ↔ GitHub** | Aplikacja GitHub Railway musi dostać dostęp do **organizacji**, nie tylko do konta osobistego. Bez tego deploy przestanie działać |
| **Dostęp dla agentów AI** | Sesje Claude Code mają zakres ustawiony na `matmaxalez/kuking.pl`. Po transferze trzeba dodać nową ścieżkę `<organizacja>/kuking.pl` |
| **Zdalne repozytorium lokalnie** | `git remote set-url origin https://github.com/<organizacja>/kuking.pl.git` — przekierowanie działa, ale lepiej mieć poprawny adres |

### 4. Włączenie CI

Dopiero teraz, gdy repozytorium jest już w organizacji:

1. Odkomentuj blok `on:` w `.github/workflows/ci.yml`
   (i usuń tymczasowe `on: workflow_dispatch:`)
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
- Nic w kodzie nie wymaga zmiany. Jedyne miejsca z pełnym adresem to opisy
  w dokumentacji — przekierowania GitHuba i tak je obsłużą

---

## Plan awaryjny

Gdyby limit organizacji też się wyczerpał albo przebiegi zrobiły się długie,
**własny runner nadal jest gotowy do postawienia**:
`SELF_HOSTED_RUNNER.md` pozostaje aktualny, a workflowy przełącza się na niego
jedną zmienną repozytorium `CI_RUNNER` = `self-hosted`.

## Referencje

`../DECISIONS.md` D-010 · `CI_BEZ_ACTIONS.md` · `SELF_HOSTED_RUNNER.md` · issue #4
