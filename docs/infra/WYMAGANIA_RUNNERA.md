# Czego workflowy Kuking wymagają od runnera

**Dla osoby (albo modelu) konfigurującej pulę `woogitsu-linux-01`–`woogitsu-linux-10`.**

Od decyzji D-028 wszystkie 14 jobów Kuking chodzi wyłącznie na tej puli
i **nie mają zapasu w runnerach GitHuba**. Jeśli czegoś tu brakuje, joby nie
padną „na czerwono z sensownym komunikatem" — będą wisieć w kolejce albo
przewracać się na pierwszym kroku. A CI jest bramką deployu (Railway ma
„Wait for CI"), więc razem z nimi stoi wdrożenie.

Ten dokument opisuje **stan zastany w workflowach**, nie życzenia. Każda
pozycja ma wskazany plik i job, z którego wynika.

> **Jeśli czytasz to, żeby naprawić pulę — zacznij od sekcji 12.** Pierwszy
> przebieg na tej puli już się odbył (7 września, 20:07 UTC) i brakuje
> dokładnie dwóch rzeczy: Dockera widocznego z WSL-a i poprawnych ścieżek
> w konfiguracji runnerów. Sekcja 12 ma to z logami. Reszta dokumentu jest
> po to, żeby po naprawieniu tych dwóch nie wyszło trzecie.

---

## 1. Etykiety — dokładnie sześć, bez wyjątku

Przy `./config.sh` runner musi zarejestrować się z etykietami:

```text
self-hosted,Linux,X64,woogitsu,i5-10400f,nvidia-gtx1070
```

Wszystkie joby mają:

```yaml
runs-on: [self-hosted, Linux, X64, woogitsu, i5-10400f, nvidia-gtx1070]
```

**Brak choćby jednej etykiety = job nigdy nie wystartuje** (nie „padnie" —
będzie wisiał jako `Queued`). Etykiety `i5-10400f` i `nvidia-gtx1070` są
w zestawie celowo: stara pula WSL-owa (`woogitsu-wsl-DOM-NEW-01`–`04`) ma
tylko `self-hosted`, `Linux`, `X64`, `wsl2`, `woogitsu`, więc bez nich joby
trafiałyby także na nią.

> Etykieta `nvidia-gtx1070` **nie znaczy, że Kuking potrzebuje GPU.** Żaden
> job go nie używa. Jest wyłącznie znacznikiem tożsamości nowej puli.

> **ZMIERZONE 7 września (przebieg 34158198715): etykiety NIE rozdzielają
> sprzętu.** Runnery `woogitsu-linux-01` i `woogitsu-linux-02` raportują
> `Machine name: 'DOM'`, a wszystkie katalogi robocze leżą pod
> `/home/matma/actions-runner/woogitsu-linux-NN/`. To są dystrybucje WSL 2 na
> tej samej maszynie Windows, której nazwa siedzi też w nazwach starej puli
> (`woogitsu-wsl-DOM-NEW-*`) — tylko bez etykiety `wsl2`. Rozdzielenie po
> etykietach jest więc rozdzieleniem REJESTRACJI, nie maszyn, i wszystko
> z sekcji 2 poniżej dotyczy ich realnie, a nie teoretycznie.

---

## 2. Jedna maszyna = JEDEN job naraz

To jest wymóg, który najłatwiej przeoczyć i który daje najbrzydszą awarię.

Dwa joby — `test` (`ci.yml:213-228`) i `dostepnosc` (`ci.yml:360-373`) —
podnoszą kontener usługi z **mapowaniem portu na hosta**:

```yaml
services:
  postgres:
    image: postgres:18-alpine
    ports:
      - 5432:5432
```

Na runnerach GitHuba każdy job dostaje własną maszynę wirtualną, więc port
5432 jest wolny za każdym razem. **Na jednej maszynie z dwoma procesami
runnera oba joby walczą o ten sam port hosta** i drugi przewraca się na
`driver failed programming external connectivity: Bind for 0.0.0.0:5432
failed: port is already allocated`. Oba joby tego samego przebiegu CI mogą
wystartować równocześnie.

**To NIE jest hipoteza.** Zmierzone 7 września: siedem jobów jednego
przebiegu wylądowało na `woogitsu-linux-01`, `-02`, `-05`, `-06`, `-07`,
`-08` i `-12`, a co najmniej dwa z tych runnerów raportują tę samą maszynę
(`DOM`). Joby `test` i `dostepnosc` trafiły na `-02` i `-07` i wystartowały
w tej samej sekundzie. Gdyby Docker działał, drugi z nich przewróciłby się na
zajętym porcie.

Dlatego: **na każdej maszynie dokładnie jeden zarejestrowany runner
i jeden job jednocześnie.** Jeśli dziesięć rejestracji stoi na jednej
maszynie, to jest dokładnie ten układ, którego być nie może.

Jeśli z jakiegoś powodu na jednej maszynie ma stać więcej runnerów, trzeba
najpierw zdjąć mapowanie portów z obu jobów i wpisać im `DB_HOST` kontenera
usługi — to jest zmiana w `ci.yml`, nie na maszynie, i wtedy proszę o issue,
a nie o obejście po cichu.

---

## 3. Docker — obowiązkowy, z dostępem dla użytkownika runnera

Trzy rzeczy w Kuking bez Dockera nie działają:

| Co | Gdzie | Po co |
|---|---|---|
| kontener usługi `postgres:18-alpine` | `ci.yml` joby `test`, `dostepnosc` | Testy chodzą na PostgreSQL, nigdy na SQLite (D-002). Schemat używa indeksów częściowych, `num_nonnulls()`, `gen_random_uuid()`, `pg_trgm` i `unaccent` — na innym silniku test przechodziłby, nic nie sprawdzając |
| `docker/setup-buildx-action@v4` | `ci.yml` job `docker-build` | Build obrazu jako weryfikacja przed wypchnięciem na Railway |
| `docker/build-push-action@v7` | `ci.yml` job `docker-build` | To samo |

Wymagania:

- Docker Engine działający jako usługa (`systemctl status docker`).
- **Użytkownik runnera w grupie `docker`** (`sudo usermod -aG docker <user>`,
  potem restart usługi runnera). Bez tego kontener usługi nie wstanie,
  a komunikat mówi tylko `permission denied`.
- Runner **nie może** sam siedzieć w kontenerze bez docker-in-docker.
- Możliwość pobrania obrazów z Docker Huba (`postgres:18-alpine`,
  obrazy buildx).

Kontener usługi ma healthcheck (`pg_isready -U kuking -d kuking_test`,
`--health-retries=10`), więc job nie startuje przed przyjęciem połączeń.
Nie trzeba nic dodawać.

---

## 4. PHP — 8.4, dziesięć rozszerzeń

Joby stawiają PHP przez `shivammathur/setup-php@v2`. Ta akcja **działa na
self-hosted Linuksie, ale wymaga `sudo` bez hasła** i sieci do repozytoriów
pakietów. Bez tego pada na pierwszym kroku.

```text
PHP_VERSION: 8.4     (ci.yml:68 — minimum frameworka to 8.3)
```

Rozszerzenia, w sumie ze wszystkich jobów:

```text
mbstring, tokenizer, intl, pdo_pgsql, pgsql, gd, zip, exif, bcmath, pcntl
```

- `pdo_pgsql` i `pgsql` — bez nich testy padają na `could not find driver`;
- `gd` i `exif` — pipeline zdjęć (re-enkodowanie zdejmujące EXIF/GPS);
- `intl`, `bcmath`, `zip`, `pcntl`, `mbstring`, `tokenizer` — Laravel, Composer, Pint.

**Zalecenie, nie wymóg:** wstępna instalacja PHP 8.4 z tymi rozszerzeniami
na obrazie maszyny. `setup-php` wykryje gotową wersję i skróci krok do
kilku sekund. Przy instalacji od zera każdy job traci na to 1-2 minuty,
a joby mają `timeout-minutes` od 10 do 30.

Do tego **Composer 2** (`tools: composer:v2`) — akcja dociąga go sama,
o ile ma sieć.

---

## 5. Node — 22

```text
NODE_VERSION: 22     (ci.yml:69)
```

Stawiany przez `actions/setup-node@v7` z `cache: npm`. Wymaga sieci do
`registry.npmjs.org`. Joby: `assets`, `dostepnosc`, `audit`, a w `deploy.yml`
job `operate` dokłada `npm install -g @railway/cli` — czyli **globalna
instalacja npm musi się udać bez `sudo`** (prefiks npm w katalogu domowym
użytkownika runnera albo nvm).

---

## 6. Chromium dla automatu dostępności

Job `dostepnosc` (`ci.yml:457`) robi:

```bash
npx playwright install --with-deps chromium
```

`--with-deps` uruchamia `apt-get install` dla bibliotek systemowych
przeglądarki, więc znowu potrzebne jest **`sudo` bez hasła**.

Alternatywa, która oszczędza ~26 sekund na przebieg: wstępnie zainstalowane
Chromium plus zmienna `PLAYWRIGHT_BROWSERS_PATH`. `scripts/dostepnosc.mjs`
sam wykrywa, skąd wziąć przeglądarkę (`znajdzChromium()`): bierze
`CHROMIUM_PATH`, jeśli jest ustawiona i plik istnieje; potem stałą ścieżkę
obrazu deweloperskiego; a gdy nie ma żadnej — przeglądarkę Playwrighta.
Nie trzeba niczego podawać w workflow.

---

## 7. Sieć wychodząca

Bez tych hostów joby padają, a komunikaty bywają mylące (`403`, timeout
proxy, „could not authenticate"):

| Host | Kto go potrzebuje |
|---|---|
| `github.com`, `api.github.com`, `objects.githubusercontent.com` | `actions/checkout`, wszystkie akcje, `actions/cache`, `upload-artifact` |
| `repo.packagist.org` | `composer install` (metadane) |
| **`api.github.com` dla dowolnego repozytorium** | `composer install` — pakiety dystrybucyjne idą z `https://api.github.com/repos/<owner>/<repo>/zipball/<ref>`. **To jest realna pułapka:** `phpstan/phpstan` ma w `composer.lock` `"source": null`, czyli JEDYNIE dystrybucję z tego adresu. Środowisko, które ogranicza `api.github.com` do własnych repozytoriów, nie zainstaluje go w ogóle (zmierzone w tej sesji: HTTP 403 i `Could not authenticate against github.com`) |
| `registry.npmjs.org` | `npm ci`, `npx playwright install` |
| Docker Hub | `postgres:18-alpine`, buildx |
| repozytoria pakietów systemowych (`ppa.launchpadcontent.net`, `deb.debian.org` / `archive.ubuntu.com`) | `setup-php`, `playwright install --with-deps` |
| `railway.com` / API Railway | tylko `deploy.yml` i `railway-iac.yml` |
| `sentry.io` | tylko `deploy.yml`, krok `getsentry/action-release@v3` |

---

## 8. Miejsce na dysku i pamięć

Zmierzone w środowisku deweloperskim tego repozytorium:

| Co | Ile | Skąd ta liczba |
|---|---|---|
| `node_modules/` po `npm ci` | **168 MB** | zmierzone (`du -sh node_modules`) |
| przeglądarka Chromium Playwrighta | **597 MB** | zmierzone (`du -sh /opt/pw-browsers/chromium-1194`) |
| `vendor/` bez katalogów `.git` | **1,3 GB** | zmierzone (`du -sh --exclude=.git vendor`) — tyle zajmuje instalacja `--prefer-dist`, czyli ta, którą robi CI |
| `vendor/` z klonami git | 5,0 GB | zmierzone; dotyczy instalacji ze ŹRÓDEŁ (121 klonów). CI używa `--prefer-dist`, więc nie powinno tu trafić — ale jeśli dystrybucje z `api.github.com` będą blokowane (sekcja 7), Composer sam przejdzie na źródła i tyle zajmie |
| cache Composera | 4,0 GB | zmierzone dla instalacji ze źródeł; przy `--prefer-dist` jest to rząd setek MB |
| obrazy Dockera (`postgres:18-alpine` + warstwy buildu Kuking) | ~1,5 GB | szacunek, NIE pomiar |

Liczby poza obrazami Dockera są zmierzone w środowisku deweloperskim tej
sesji (PHP 8.4, Node 22), nie przepisane z dokumentacji.

**Rekomendacja: minimum 20 GB wolnego na maszynę** i okresowe
`docker system prune`. Pamięci: 4 GB starcza, przy równoległym buildzie
obrazu i kontenerze Postgresa wygodniej 8 GB — to jest szacunek, nie pomiar.

---

## 9. Sekrety i zmienne repozytorium

- **`CI_RUNNER` — do usunięcia.** Po D-028 nie czyta jej już żaden workflow.
  Dopóki istnieje, jest tylko mylącym śladem.
- Sekrety potrzebne **wyłącznie** workflowom wdrożeniowym (dziś zablokowanym
  zmienną `KUKING_DEPLOY_ENABLED`): `RAILWAY_TOKEN_PRODUCTION`,
  `RAILWAY_TOKEN_STAGING`, `SENTRY_AUTH_TOKEN`.
- `ci.yml` **nie potrzebuje żadnego sekretu.** `APP_KEY` w jobie `test` jest
  statyczną wartością wpisaną w workflow i nie jest sekretem — służy tylko
  do tego, żeby szyfrowanie miało poprawnie sformatowany klucz.

---

## 10. Bezpieczeństwo — jedna rzecz, o której trzeba wiedzieć

Runner self-hosted **wykonuje kod z repozytorium**. Dla repozytorium
prywatnego z zaufanym zespołem to jest w porządku. **Gdyby Kuking kiedyś
stał się publiczny i przyjmował Pull Requesty od obcych, tej puli nie wolno
zostawić podpiętej** — to byłoby oddanie powłoki na tych maszynach każdemu,
kto otworzy PR. Sprawa jest otwarta w `docs/decyzje/REPO_PUBLICZNE.md`.

---

## 11. Jak sprawdzić, że jest dobrze

1. W panelu GitHuba: **Settings → Actions → Runners** — dziesięć runnerów
   `Idle`, każdy z sześcioma etykietami.
2. Ręczny przebieg: `ci.yml` ma `workflow_dispatch`, więc da się go odpalić
   bez pushowania czegokolwiek.
3. Zielony przebieg oznacza, że wszystko z tego dokumentu jest na miejscu.
   Do przejrzenia po przebiegu: czy `Konfiguracja PHP` i `Przeglądarka`
   trwają sekundy (wstępna instalacja działa), czy minuty (instalują od zera).

Objawy i przyczyny są w `docs/infra/SELF_HOSTED_RUNNER.md`, sekcja
„Gdy coś nie działa".

---

## 12. PIERWSZY PRZEBIEG NA TEJ PULI — co konkretnie padło

**Przebieg [34158198715](https://github.com/woogitsu/kuking.pl/actions/runs/34158198715),
7 września 2026, 20:07 UTC, PR #125, commit `18499b8`.** To nie jest lista
obaw — to odczyt z logów. Sześć z siedmiu jobów padło, **żaden na kodzie
aplikacji**: wszystkie na kroku przygotowania środowiska, zanim wykonał się
jakikolwiek test.

| Job | Runner | Krok, na którym padł | Błąd |
|---|---|---|---|
| Build assetów (Vite) | `-08` | — | **PRZESZEDŁ** |
| Pint (styl kodu) | `-06` | Konfiguracja PHP | `ENOENT … woogitsu-run-06/_work/_actions/shivammathur/setup-php/v2/src/scripts/linux.sh` |
| Larastan | `-05` | Konfiguracja PHP | jak wyżej |
| Audyt zależności | `-01` | Konfiguracja PHP | `ENOENT … woogitsu-run-01/_work/_actions/…` |
| Testy (PostgreSQL 18) | `-02` | Initialize containers | `The command 'docker' could not be found in this WSL 2 distro` → `Value cannot be null. (Parameter 'network')` |
| Dostępność (axe-core) | `-07` | Initialize containers | jak wyżej |
| Build obrazu | `-12` | Konfiguracja Buildx | `The command 'docker' could not be found in this WSL 2 distro` |

**Job, który przeszedł, jest tu najważniejszą informacją.** „Build assetów"
to jedyny job, który nie potrzebuje ani PHP, ani Dockera — i przeszedł
w całości (Node 22, `npm ci`, `vite build`, weryfikacja manifestu, artefakt).
Czyli runnery, sieć, checkout, cache i artefakty **działają**. Brakuje
dokładnie dwóch rzeczy.

### 12.1 Docker nie jest widoczny z WSL-a

```text
[command]/mnt/c/Program Files/Docker/Docker/resources/bin/docker version
The command 'docker' could not be found in this WSL 2 distro.
We recommend to activate the WSL integration in Docker Desktop settings.
```

Jedyny `docker` w `PATH` to **windowsowy plik Docker Desktopa**, wywoływany
z WSL-a przez `/mnt/c/…`, a integracja WSL dla tej dystrybucji jest
wyłączona. Skutek: nie wstaje kontener usługi `postgres:18-alpine` (sekcja 3),
więc **testy nie mają na czym się uruchomić**, i nie działa buildx.

Do zrobienia, jedno z dwóch:

- **Docker Desktop → Settings → Resources → WSL integration** → włączyć dla
  dystrybucji, w której stoją runnery; albo
- zainstalować w tej dystrybucji **native Docker Engine** (`docker.io` /
  `docker-ce`) i dopisać użytkownika `matma` do grupy `docker`. Ta droga jest
  pewniejsza: nie zależy od tego, czy Docker Desktop w Windowsie jest
  uruchomiony.

Sprawdzenie: `docker version` **w tej dystrybucji WSL** ma pokazać serwer,
a `which docker` **nie** ma wskazywać na `/mnt/c/…`.

### 12.2 W runnerach została stara ścieżka `woogitsu-run-NN`

```text
Working directory is '/home/matma/actions-runner/woogitsu-linux-01/_work/…'
##[error]ENOENT: no such file or directory, open
  '/home/matma/actions-runner/woogitsu-run-01/_work/_actions/shivammathur/setup-php/v2/src/scripts/linux.sh'
```

Runner pracuje w katalogu `woogitsu-linux-01`, a akcja szuka swoich plików
w `woogitsu-run-01`. Ta sama rozbieżność jest na `-06`. `actions/checkout`
przechodzi (używa poprawnej ścieżki), więc problem dotyczy tego, co runner
eksportuje do akcji jako lokalizację `_work`/`_tool`/`_actions`.

Wygląda to na **pozostałość po zmianie nazwy katalogów runnerów** z
`woogitsu-run-NN` na `woogitsu-linux-NN` bez ponownej konfiguracji. Do
sprawdzenia w katalogu każdego runnera:

- plik **`.env`** — wpisy `RUNNER_TOOL_CACHE`, `AGENT_TOOLSDIRECTORY`
  i podobne, wskazujące na starą nazwę;
- plik **`.path`**;
- **`.runner`** i **`.credentials`** (pole z katalogiem roboczym);
- zmienne w definicji usługi systemd runnera
  (`/etc/systemd/system/actions.runner.*.service` albo `svc.sh`).

Najpewniejsza naprawa, jeśli grzebanie w plikach nie pomoże: **wyrejestrować
i zarejestrować runnera od nowa** w katalogu o docelowej nazwie
(`./config.sh remove`, potem `./config.sh` z sześcioma etykietami z sekcji 1).
Zmiana nazwy katalogu po konfiguracji nie jest wspierana.

### 12.3 Czego ten przebieg NIE sprawdził

Ani jeden test aplikacji nie został wykonany, więc **przebieg nie mówi nic
o kodzie z PR #125** — w szczególności PHPStan/Larastan nie zobaczył tej
zmiany ani razu, bo padł przed uruchomieniem. Lokalnie w sesji, która ten PR
przygotowała, zielone były: `pint`, `php artisan test` (1636 testów, 56064
asercji, PostgreSQL) i `npm run build`; PHPStana nie dało się tam
zainstalować z powodu opisanego w sekcji 7.
