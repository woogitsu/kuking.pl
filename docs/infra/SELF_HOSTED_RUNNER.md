# Własny runner GitHub Actions

> **To jest plan awaryjny, nie droga podstawowa.**
> Decyzja D-010 zmieniła się: repozytorium przenosi się pod nową organizację
> GitHub, która ma własną, nieużywaną pulę 2 000 minut Actions miesięcznie —
> patrz `PRZENIESIENIE_DO_ORGANIZACJI.md`.
>
> Ten dokument zostaje aktualny i przyda się, gdyby limit organizacji też się
> wyczerpał albo gdyby przebiegi zrobiły się na tyle długie, że własna maszyna
> zacznie się opłacać.

Runner self-hosted **nie zużywa minut Actions** — płacisz tylko za maszynę.

Ten dokument to instrukcja od zera do zielonego CI.

---

## ⚠️ Przeczytaj najpierw: jedna zasada bezpieczeństwa

Runner **wykonuje kod z repozytorium**. Przy repozytorium prywatnym
i zaufanym zespole to jest w porządku.

**Nigdy nie podpinaj tego runnera do repozytorium publicznego przyjmującego
Pull Requesty od obcych osób.** To jest równoznaczne z oddaniem im powłoki
na tej maszynie. Gdybyś kiedyś upublicznił Kuking (opcja rozważana w
`CI_BEZ_ACTIONS.md`), runnera trzeba najpierw odłączyć.

Praktyczne wnioski:

- runner na **osobnej maszynie**, nie na tej, na której trzymasz hasła i klucze,
- **nie dawaj mu dostępu do produkcyjnej bazy ani do R2**,
- jeśli to VPS — osobny użytkownik systemowy bez `sudo`.

---

## Gdzie postawić maszynę

| Miejsce | Koszt | Uwagi |
|---|---|---|
| **VPS w Polsce lub UE** | ~15-30 zł/mies. | Najprostsze i najbardziej przewidywalne. 2 vCPU, 4 GB RAM wystarczy z zapasem |
| **Railway jako osobny serwis** | doliczane do zużycia | Wygodne, bo konto już będzie; runner musi być długo żyjący, więc nie może zasypiać |
| **Komputer, który i tak stoi włączony** | 0 zł | Działa, ale CI wtedy nie działa, gdy komputer jest wyłączony albo śpi |
| **Mini PC / Raspberry Pi 5** | jednorazowo | Uwaga: ARM. Wszystkie zależności są dostępne, ale to więcej grzebania |

**Rekomendacja: VPS 2 vCPU / 4 GB.** Przebieg CI dla Kuking to kilka minut,
więc nie potrzebujesz mocnej maszyny — potrzebujesz dostępnej.

---

## Co musi być na maszynie

Zestaw **musi odpowiadać temu z `Dockerfile`**, inaczej CI przepuści kod,
który nie wystartuje na produkcji.

- **PHP 8.4** z rozszerzeniami: `mbstring`, `intl`, `pdo_pgsql`, `pgsql`,
  `gd`, `zip`, `exif`, `bcmath`, `pcntl`
- **Composer 2**
- **Node 22** i npm
- **PostgreSQL 18** — albo Docker, żeby workflow mógł postawić usługę
  `postgres:18-alpine` (tak to jest napisane w `ci.yml`)
- `git`, `unzip`, `curl`

### Ubuntu 24.04 — jednym ciągiem

```bash
sudo apt update
sudo apt install -y software-properties-common curl git unzip
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y \
  php8.4-cli php8.4-mbstring php8.4-intl php8.4-pgsql \
  php8.4-gd php8.4-zip php8.4-bcmath php8.4-curl php8.4-xml
php -v

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node 22
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
node -v

# Docker — po to, żeby workflow mógł postawić PostgreSQL 18 jako usługę
curl -fsSL https://get.docker.com | sudo sh
```

> `php8.4-exif` i `php8.4-pcntl` są w tym repozytorium częścią `php8.4-cli`.
> Sprawdź na koniec: `php -m | grep -E 'exif|pcntl'`.

### Sprawdzenie, czy maszyna jest gotowa

```bash
php -m | tr '\n' ' '     # muszą być: mbstring intl pdo_pgsql pgsql gd zip exif bcmath pcntl
composer -V
node -v                  # v22.x
docker run --rm hello-world
```

---

> **Konfigurujesz pulę od zera?** Kompletna lista tego, czego workflowy
> Kuking wymagają od maszyny — etykiety, Docker, rozszerzenia PHP, sieć
> wychodząca, miejsce na dysku i jedna pułapka z portem 5432 — jest
> w [`WYMAGANIA_RUNNERA.md`](./WYMAGANIA_RUNNERA.md).

## Rejestracja runnera

1. GitHub → repozytorium `woogitsu/kuking.pl` → **Settings** → **Actions**
   → **Runners** → **New self-hosted runner**
2. Wybierz **Linux / x64**. GitHub pokaże gotowe komendy z tokenem —
   wykonaj je na maszynie:

```bash
mkdir -p ~/actions-runner && cd ~/actions-runner
# (skopiuj komendy curl + tar + config.sh z panelu GitHuba — token jest jednorazowy)
```

3. Przy `./config.sh` zostaniesz zapytany o etykiety. **Podaj
   `self-hosted,Linux,X64,woogitsu,i5-10400f,nvidia-gtx1070`** — workflowy
   Kuking szukają dokładnie tego zestawu.

   Same domyślne `self-hosted,Linux,X64` **nie wystarczą**, gdy zmienna
   `CI_RUNS_ON` wymienia komplet sześciu etykiet: job z takim runnerem nigdy
   wtedy nie wystartuje. Komplet ustawia się po to, żeby joby nie trafiały na
   starą pulę WSL-ową (`woogitsu-wsl-DOM-NEW-01`–`04`), która ma tylko
   `self-hosted`, `Linux`, `X64`, `wsl2`, `woogitsu` — a dziś, przy zmiennej
   ustawionej na samo `self-hosted`, właśnie na nią trafiają (patrz Krok 1).

4. Uruchom jako usługę, żeby przeżył restart maszyny:

```bash
sudo ./svc.sh install
sudo ./svc.sh start
sudo ./svc.sh status
```

W panelu GitHuba runner powinien pokazać się jako **Idle**.

---

## Włączenie CI w repozytorium

### Krok 1 — ustaw zmienną repozytorium `CI_RUNS_ON`

Runnera **wybiera zmienna repozytorium `CI_RUNS_ON`** (D-121). Każdy job
w `deploy.yml`, `preview.yml`, `railway-iac.yml` i większość jobów w `ci.yml`
ma dokładnie to samo:

```yaml
runs-on: ${{ fromJSON(vars.CI_RUNS_ON || '"ubuntu-latest"') }}
```

**Wyjątek od 21.09.2026 (zawężony tego samego dnia):** JEDEN job w `ci.yml`,
`port_funkcje` („Port marki — rodziny ekranów, zoom i kreator"), czyta osobną
zmienną, `CI_RUNS_ON_BROWSER`, tym samym wzorcem. Powód i pełne uzasadnienie
stoją w nagłówku `.github/workflows/ci.yml`, blok „JOB PRZEGLĄDARKOWY
`port_funkcje`" — własne runnery zaczęły dzielić maszynę z flotą agentów,
a `port_funkcje` (najdłuższy job w CI, medianą 51 s, ale maksimum 1638 s na
25 przebiegach) pod tym obciążeniem padł na `main` z `TimeoutError`.
Pierwsza wersja tej decyzji przełączyła PIĘĆ jobów (`assets`, `port_panelu`,
`port_marki`, `port_funkcje`, `dostepnosc`) — właściciel to cofnął po
zobaczeniu kosztu w minutach (pięć jobów = 66 min na przebieg = tylko
7 przebiegów z puli ~500 minut; sam `port_funkcje` = 27 min = ok. 18
przebiegów). Pozostałe cztery joby wróciły na `CI_RUNS_ON`. Ten dokument
opisuje mechanizm `CI_RUNS_ON`; mechanizm `CI_RUNS_ON_BROWSER` jest z nim
identyczny, tylko dotyczy jednego, węższego joba.

**Od 25.09.2026: dwa runnery zarezerwowane dla `main` — `CI_RUNS_ON_MAIN`
(D-266).** Pula `CI_RUNS_ON` jest wspólna dla PR-ów i dla `main`; przy kilku
PR-ach naraz przebiegi `main`-a (jedyne, po których Railway wdraża — „Wait
for CI") stały w tej samej kolejce co PR-y. Właściciel oznaczył **dwa**
z runnerów puli `woogitsu-linux-*` dodatkową etykietą `kuking-main`
(WYŁĄCZNIE dla przebiegów `main`-a), a pozostałe etykietą `kuking-pr`. Każdy
job w `ci.yml`, który dziś czyta `CI_RUNS_ON`, dla przebiegu będącego
prawdziwym pushem na `main` (`github.ref == 'refs/heads/main' &&
github.event_name == 'push'` — nie dla PR-a do `main` i nie dla ręcznego
`workflow_dispatch` na tej gałęzi) sięga NAJPIERW po `CI_RUNS_ON_MAIN`,
a dopiero bez niej po `CI_RUNS_ON`:

```yaml
runs-on: ${{ fromJSON((github.ref == 'refs/heads/main' && github.event_name == 'push' && vars.CI_RUNS_ON_MAIN) || vars.CI_RUNS_ON || '"ubuntu-latest"') }}
```

`port_funkcje` ma analogiczną parę: `CI_RUNS_ON_BROWSER_MAIN` przed
`CI_RUNS_ON_BROWSER`. Przykładowe wartości:

```text
CI_RUNS_ON_MAIN = ["self-hosted","Linux","X64","woogitsu","i5-10400f","nvidia-gtx1070","kuking-main"]
CI_RUNS_ON      = ["self-hosted","Linux","X64","woogitsu","i5-10400f","nvidia-gtx1070","kuking-pr"]
```

**Kolejność wdrożenia, nie do odwrócenia:** najpierw właściciel oznacza
fizycznie runnery etykietami `kuking-main`/`kuking-pr` w GitHub → Settings →
Actions → Runners, **dopiero potem** ustawia te dwie zmienne. W odwrotnej
kolejności zmienna wskazywałaby etykietę, której żaden runner jeszcze nie
nosi — job wtedy nie pada, tylko stoi w „Queued" bez końca (ten sam koszt co
offline'owa pula, patrz „Skutek, który trzeba znać" niżej), a przez „Wait for
CI" stoi wtedy i wdrożenie. `deploy.yml`, `preview.yml` i `railway-iac.yml`
tej pary zmiennych nie mają: żaden z nich nie uruchamia się pushem na `main`
(deploy reaguje na `deployment_status`, preview i railway-iac na
`pull_request`), więc dopisanie tam warunku main-a byłoby martwym kodem.

Bez tej zmiennej joby idą na `ubuntu-latest`. Żeby trafiły na własną pulę,
ustaw w **Settings** → **Secrets and variables** → **Actions** →
**Variables**:

```text
CI_RUNS_ON = ["self-hosted","Linux","X64","woogitsu","i5-10400f","nvidia-gtx1070"]
```

Wartość jest **tablicą JSON**, bo `fromJSON` dostaje ją w całości — pojedyncza
etykieta to `"self-hosted"` (z cudzysłowami), a nie `self-hosted`.

Komplet etykiet, a nie nazwa runnera: nazwa przypięłaby job do jednej maszyny,
więc jej awaria zatrzymywałaby całe CI. Zmienna `CI_RUNNER` (z D-028) nie jest
przez nic czytana — jeśli gdzieś jeszcze istnieje, można ją usunąć; to `CI_RUNS_ON`
jest tą żywą.

**Skutek, który trzeba znać:** dopóki zmienna wskazuje własną pulę, a cała pula
`woogitsu-linux-01`–`woogitsu-linux-10` jest offline, przebiegi stoją w kolejce
bez końca, a CI jest bramką deployu (Railway ma „Wait for CI") — stoi wtedy także
wdrożenie. Wyjście awaryjne to **skasowanie zmiennej**: joby wracają wtedy na
`ubuntu-latest` bez PR-a i bez przeglądu. Po to ten zapas istnieje.

**Czego ten dokument nie powie:** jaka jest DZIŚ wartość zmiennej. Zmienna żyje
w ustawieniach repozytorium i z kodu jej nie widać. Ostatni pomiar — 11 września
2026, przebieg CI nr 661 — mówi `labels: ["self-hosted"]`,
`runner_name: kuking-wsl-DOM-NEW-02`, czyli zmienna stoi na **samym**
`self-hosted` i joby lądują na starej puli WSL-owej. Ustawienie jej na komplet
sześciu etykiet jest czynnością właściciela w ustawieniach repozytorium, nie
zmianą w kodzie.

#### Decyzja właściciela z 12 września 2026: zmienna ZOSTAJE na samym `self-hosted`

**To jest wybór, nie przeoczenie.** D-121 zostawiło właścicielowi trzy drogi —
komplet sześciu etykiet, skasowanie zmiennej albo świadome zostawienie stanu
zastanego. **12 września 2026 właściciel wybrał trzecią: nie zmieniamy niczego.**

Ten akapit istnieje po to, żeby przy następnej awarii CI nikt nie szukał nie tam.
Stan „przebiegi chodzą na starej puli WSL" wygląda **dokładnie tak samo** jak
przeoczenie, więc bez tego zapisu każdy kolejny czytelnik sprawdzałby to od
początku i szukał usterki w `runs-on:`, gdzie jej nie ma. Powodu decyzji ten
dokument nie zgaduje — zapisuje **wybór i jego koszt**.

**Przyjęty koszt, nazwany po imieniu:**

1. **Przebiegi idą na starą pulę WSL** (`kuking-wsl-DOM-NEW-01`–`-03`), a to są
   **trzy rejestracje na JEDNEJ maszynie**, nie trzy maszyny: katalogi
   `/home/mateusz/actions-runner-kuking-0N` w jednym systemie, pod jednym
   użytkownikiem. Wspólne mają jeden katalog domowy, jedno `/usr/local/bin`,
   jedną systemową instalację PHP, jeden cache Playwrighta, jednego demona
   Dockera i jeden dysk — pełna lista w tabeli „Co jeszcze na tej maszynie jest
   wspólne" niżej.
2. **To ta sama maszyna, która dała wyścig o binarkę Composera z #262** — kod
   wyjścia 126 i `composer: /usr/bin/env: bad interpreter: Text file busy`,
   zmierzone na przebiegu 34473498102 z 10.09.2026. Ten jeden wyścig jest od
   11.09.2026 zamknięty **konstrukcyjnie** (każdy job dostaje własny katalog na
   binarki narzędzi) i poprawka działa na obu pulach — ale zamknięty jest
   **jeden plik**, nie współdzielenie maszyny. Reszta wspólnych zasobów z tabeli
   niżej zostaje na swoim miejscu.
3. **Dziewięć jobów `ci.yml` startuje naraz, a trzy rejestracje wykonują
   najwyżej trzy joby jednocześnie** (jeden runner robi jeden job naraz), więc
   przebieg szereguje się na tury zamiast rozłożyć się na pulę
   `woogitsu-linux-01`–`woogitsu-linux-10`.
4. **Cała pula to jedna maszyna**, więc jej wyłączenie albo restart zatrzymuje
   całe CI, a przez „Wait for CI" także wdrożenie na Railway.

**Co trzeba zrobić, żeby to zmienić** — obie drogi są czynnością w ustawieniach
repozytorium (Settings → Secrets and variables → Actions → Variables), żadna
nie jest zmianą w kodzie i żadna nie przechodzi przez PR:

| Cel | Co ustawić | Co się wtedy dzieje |
|---|---|---|
| **Nowa pula** `woogitsu-linux-01`–`-10` | `CI_RUNS_ON = ["self-hosted","Linux","X64","woogitsu","i5-10400f","nvidia-gtx1070"]` — **tablica JSON w całości**, bo `fromJSON` dostaje całą wartość; pojedyncza etykieta to `"self-hosted"` z cudzysłowami, a nie `self-hosted` | Joby proszą o komplet sześciu etykiet, więc stara pula WSL (ma tylko `self-hosted`, `Linux`, `X64`, `wsl2`, `woogitsu`) przestaje je wpuszczać. Runner bez kompletu etykiet nie wystartuje ani razu |
| **Runnery GitHuba** | **skasować zmienną** `CI_RUNS_ON` | `fromJSON(vars.CI_RUNS_ON \|\| '"ubuntu-latest"')` schodzi na wartość domyślną, czyli `ubuntu-latest`; żaden self-hosted wtedy nie pracuje. Repozytorium jest prywatne, więc minuty są płatne — pełny przebieg `ci.yml` to dziewięć jobów, orientacyjnie 25-35 minut maszynowych z puli 2 000 minut organizacji |

**Po czym poznać, że decyzję trzeba odwrócić** — sygnał jest jeden i konkretny:
**pierwsza po 12.09.2026 awaria z rodziny „jedna maszyna, trzy rejestracje".**
Poznaje się ją po którymkolwiek z trzech objawów:

- job pada z kodem **126** i `Text file busy` przy pliku wykonywalnym **spoza**
  `/usr/local/bin` (to jedno miejsce jest już wyjęte z gry poprawką z #262 —
  każde inne znaczy, że wyścig przeniósł się na kolejny wspólny plik);
- w logu stoi `No space left on device` albo narzędzie melduje brak pliku,
  którego zapis padł, a **ten sam commit przechodzi lokalnie** (jeden dysk na
  trzy rejestracje — pułapka 8b w `../PULAPKI_TESTOW.md`);
- dziewięć jobów jednego przebiegu `ci.yml` rusza w turach: **ostatni startuje
  ponad 15 minut po starcie przebiegu**, a w „Set up job" każdego z nich stoi
  `Runner name: kuking-wsl-…`.

**Jeden taki przypadek wystarczy**, bo zerowym było #262. Wtedy nie szukaj
usterki w workflow — powiedz właścicielowi, że sygnał padł, bo przełączenie puli
jest jego czynnością, i podaj mu wartość z tabeli wyżej.

**Dlaczego ten sygnał da się zauważyć bez pamiętania o nim:** stoi w tabeli
„Gdy coś nie działa" niżej, czyli tam, gdzie się trafia, szukając przyczyny
czerwonego CI — a nie tam, gdzie trzeba by najpierw pamiętać, że taka decyzja
w ogóle zapadła. Drugi egzemplarz tego odsyłacza jest w nagłówku
`.github/workflows/ci.yml`, przy zmierzonej wartości zmiennej.

### Krok 2 — wyzwalacze są już włączone, nic nie odkomentowujesz

W `.github/workflows/ci.yml` blok `on:` jest **aktywny**: `push` i `pull_request`
na `main` i `staging`, obok nich `workflow_dispatch`. `workflow_dispatch`
**zostaje** — pozwala puścić przebieg ręcznie (Actions → CI → Run workflow) bez
pustego commita, co przydaje się przy pierwszym uruchomieniu i przy sprawdzaniu,
czy problem nie był chwilowy. Powód stoi przy nim w komentarzu w `ci.yml`.

**Pozostałe trzy workflow-y też chodzą same.** Sprawdzone parserem YAML
12 września 2026, plik po pliku: `deploy.yml` ma `deployment_status`
i `workflow_dispatch`, `preview.yml` ma `pull_request` (opened, synchronize,
reopened) i `workflow_dispatch`, `railway-iac.yml` ma sam `pull_request`
(opened, synchronize, reopened, closed) na ścieżkach `.railway/**` i na własnym
pliku — ręcznego wyzwalacza tam nie ma. **W żadnym z tych plików nie ma dziś
zakomentowanego bloku `on:`.**

Do 12.09.2026 stało tu zdanie odwrotne — że te trzy pliki czekają na włączenie.
Była to nieprawda tej samej klasy co ta z D-165: kazała zrobić rzecz, której nie
ma do zrobienia, a przy okazji mówiła, że deploy i preview nie chodzą, choć
chodzą. Zgodności tego akapitu ze stanem bloków `on:` pilnuje teraz
`../../tests/Feature/DokumentyCiMowiaPrawdeORunnerzeTest.php`.

### Krok 3 — pierwszy przebieg

Najpierw **ręcznie**, zanim włączysz automat:
**Actions** → wybierz workflow → **Run workflow**.

Sprawdź, że job „Testy (PostgreSQL 18)" przechodzi. Jeśli padnie na braku
rozszerzenia PHP — dopisz je na maszynie i uruchom ponownie.

### Krok 4 — ochrona gałęzi

**Settings** → **Branches** → reguła dla `main`: wymagaj zielonego CI
przed scaleniem.

### Krok 5 — dopiero teraz „Wait for CI" w Railway

⚠️ **Kolejność ma znaczenie.** `KUKING_WAIT_FOR_CI=true` ustawiasz
**po pierwszym zielonym przebiegu**. Wcześniej Railway czekałby na check suite,
który nie powstaje, i nic by się nie zdeployowało (`DECISIONS.md` D-010).

---

## Utrzymanie

- **Aktualizacja runnera:** aktualizuje się sam, ale wersję sprawdź raz na kwartał
- **Miejsce na dysku:** katalog `_work` rośnie. `docker system prune -af`
  raz w miesiącu i gotowe
- **Cache Composera i npm** przeżywa między przebiegami — to jest zaleta
  własnego runnera, przebiegi są szybsze niż na GitHubie
- **Runner offline:** joby stoją w kolejce i czekają. Nic się nie psuje,
  ale nikt nie dostaje informacji zwrotnej — warto ustawić alert

---

## Gdy coś nie działa

| Objaw | Przyczyna |
|---|---|
| Job stoi w „Queued" bez końca | Runner offline albo brakuje etykiety, której żąda `CI_RUNS_ON`. Sprawdź `sudo ./svc.sh status` i czy runner ma wszystkie etykiety z tej zmiennej — przy komplecie to `self-hosted`, `Linux`, `X64`, `woogitsu`, `i5-10400f`, `nvidia-gtx1070`; brak jednej wystarcza, żeby job nigdy nie wystartował. Wyjście awaryjne: skasuj `CI_RUNS_ON`, joby wrócą na `ubuntu-latest` |
| „could not find driver" | Brak `php8.4-pgsql`. `sudo apt install php8.4-pgsql` i restart usługi runnera |
| Testy padają na połączeniu z bazą | Docker nie działa albo usługa `postgres` nie wstała. `docker ps` w trakcie przebiegu |
| „permission denied" przy Dockerze | Użytkownik runnera nie jest w grupie `docker`: `sudo usermod -aG docker $USER`, potem restart usługi |
| Job trwa bardzo długo za pierwszym razem | Normalne — Composer i npm budują cache. Kolejne przebiegi są znacznie szybsze |
| „Failed to install browsers → exit 100" w jobie dostępności | `apt-get update` na tej maszynie kończy się niezerowo, bo w liście źródeł siedzi PPA `ppa.setup-php.com/ondrej/php`, które od 9 września 2026 zwraca 404 na plik Release dla Ubuntu „resolute". CI już apta nie woła (patrz niżej), ale każde ręczne `sudo apt update` na tej maszynie też będzie krzyczeć. Usuń martwe źródło: `sudo rm /etc/apt/sources.list.d/*setup-php*` (albo `ondrej-*`) i sprawdź `sudo apt update` |
| Job pada z kodem **126** na `composer install`, bez ani jednego wyniku testu, a w logu jest `composer: /usr/bin/env: bad interpreter: Text file busy` | Dwa joby naraz na tej samej maszynie: jeden nadpisuje binarkę Composera, drugi ją w tej chwili wykonuje. Pełny opis, sposób rozpoznania i co z tym zrobiono — sekcja „Text file busy" niżej (issue #262) |
| Kod **126** z `Text file busy` przy pliku **spoza** `/usr/local/bin`; albo `No space left on device` przy commicie, który lokalnie przechodzi; albo dziewięć jobów `ci.yml` rusza w turach — ostatni ponad 15 minut po starcie przebiegu, a w „Set up job" stoi `Runner name: kuking-wsl-…` | **To jest sygnał do odwrócenia decyzji z 12.09.2026** o zostawieniu `CI_RUNS_ON` na samym `self-hosted`: przebiegi chodzą wtedy na trzech rejestracjach JEDNEJ maszyny. Nie szukaj usterki w workflow — powiedz właścicielowi, bo przełączenie puli jest jego czynnością. Co dokładnie ustawić: „Krok 1" → „Decyzja właściciela z 12 września 2026" |

---

## CI nie instaluje pakietów systemowych

Job dostępności pobiera samo Chromium (`npx playwright install chromium`),
**bez** `--with-deps`. Flaga wołała `apt-get update` jako root i wiązała los
każdego przebiegu z dostępnością cudzego repozytorium pakietów — 9 września
2026 kosztowało to cztery zablokowane PR-y, bo PPA `ondrej/php` przestało
zwracać plik Release (szczegóły w `tests/Feature/JobDostepnosciNieWolaAptaTest.php`).

Konsekwencja dla tej maszyny: **biblioteki systemowe Chromium instaluje się tu
raz, ręcznie.** Dziś są zainstalowane. Jeśli po aktualizacji systemu któraś
zniknie, Playwright powie to wprost przy starcie przeglądarki i wymieni pakiety
do doinstalowania — wtedy jedno `sudo npx playwright install-deps chromium`
na maszynie, nie zmiana w CI.

---

## „composer: bad interpreter: Text file busy" — wyścig o jedną binarkę (issue #262)

### Objaw

Job pada z kodem **126** na kroku `composer install`, **przed** uruchomieniem
czegokolwiek — w raporcie nie ma ani jednego oblanego testu, bo nie ma ani
jednego uruchomionego:

```text
…/_work/_temp/b904f771….sh: …/_work/_tool/setup-php/tools/composer:
  /usr/bin/env: bad interpreter: Text file busy
```

Ten sam commit lokalnie przechodzi w całości. Objaw jest **losowy**: pokazuje
się tylko wtedy, gdy dwa joby trafią na siebie w tej samej sekundzie.

### Przyczyna

`Text file busy` (ETXTBSY) to odpowiedź jądra na `execve()` pliku, który
**w tej samej chwili ktoś inny trzyma otwarty do zapisu**. Nie jest to usterka
Composera ani żadnego PR-a — to wyścig o jeden plik na współdzielonej maszynie:

1. `shivammathur/setup-php` zapisuje binarkę narzędzia **w miejsce, z którego
   się ją potem wykonuje**, i domyślnie jest to ścieżka wspólna dla całej
   maszyny — `/usr/local/bin` (funkcja `read_env` w `src/scripts/unix.sh`
   akcji: `tool_path_dir="${setup_php_tools_dir:-${SETUP_PHP_TOOLS_DIR:-/usr/local/bin}}"`).
2. Nadpisanie jest robione w miejscu: `sudo cp -a` z cache albo `sudo curl -o`
   wprost na ten plik (`add_tool()` w `src/scripts/tools/add_tools.sh`).
3. Ścieżka z komunikatu, `…/_work/_tool/setup-php/tools/composer`, jest tylko
   **dowiązaniem** do tamtego pliku (`ln -sfn` w tej samej funkcji). Dlatego
   komunikat wskazuje katalog konkretnego runnera, a zajęty jest plik wspólny.
4. Blokada, którą akcja ma u siebie (`/tmp/sp-lck-…` w funkcji `get()`),
   serializuje tylko **piszących**. Nie wie nic o jobie, który ten plik w tej
   samej sekundzie **wykonuje**.

Nasza pula to **rejestracje na jednej maszynie**, nie osobne maszyny
(`kuking-wsl-DOM-NEW-01`–`-03` to `/home/mateusz/actions-runner-kuking-0N`
na jednym systemie; to samo stwierdzono dla `woogitsu-linux-01`–`-02`
w `WYMAGANIA_RUNNERA.md`, sekcja 1). Siedem jobów jednego przebiegu startuje
równolegle, więc dzielą jedno `/usr/local/bin`.

**Zmierzone na przebiegu [34473498102](https://github.com/woogitsu/kuking.pl/actions/runs/34473498102)
(10.09.2026, gałąź `claude/kolejne-zdjecie-autora`):** o 11:53:47,54 padł job
„Testy (PostgreSQL 18)" na runnerze `-03`, a w tej samej sekundzie krok
„Konfiguracja PHP" wykonywał job „Dostępność" na runnerze `-02` (11:53:46→47).
**Oba joby były z tego samego przebiegu i tej samej gałęzi** — to ważne, bo
przesądza, że żadna grupa `concurrency` po gałęzi tego nie rozdzieli.

### Po czym rozpoznać, że to znowu to

1. **kod wyjścia 126** (nie 1) i **zero wyników testów** w raporcie;
2. w logu `Text file busy` przy pliku z katalogu `setup-php/tools/`;
3. w Actions, w czasach kroków: inny job — z tego samego albo z sąsiedniego
   przebiegu — miał krok „Konfiguracja PHP" w tej samej sekundzie.

Jeśli punkt 3 się nie zgadza, to jest coś innego i szukaj dalej. Sam komunikat
„Text file busy" mówi tylko, że plik był zajęty, nie kto go zajął.

### Co zrobiono w repozytorium (11.09.2026)

Każdy job, który stawia PHP, dostaje **własny katalog na binarki narzędzi** —
`$RUNNER_TEMP/kuking-narzedzia/<numer przebiegu>-<próba>-<nazwa joba>` — przez
dwie zmienne czytane przez akcję: `SETUP_PHP_TOOLS_DIR`
i `SETUP_PHP_TOOL_CACHE_DIR`. Nie ma już pliku, do którego jeden job pisze,
a drugi go w tej chwili wykonuje: wyścig znika konstrukcyjnie, nie
statystycznie. Krok stoi tuż **przed** „Konfiguracja PHP" (po nim zmienne nie
mają już na co wpłynąć) i pilnuje go
`tests/Feature/CiDajeKazdemuJobowiWlasneNarzedziaTest.php`.

Katalog zakłada nasz krok, a nie `sudo mkdir` z akcji — katalog rootowy
w `_temp` blokuje potem sprzątanie katalogu roboczego przez runnera, który
chodzi jako zwykły użytkownik.

**Czego świadomie NIE zrobiono:** wspólnej grupy `concurrency` (biją się joby
JEDNEGO przebiegu, a grupa wspólna dla wszystkich przebiegów trzyma jeden bieg
i jeden oczekujący — trzeci anuluje oczekującego) ani ponowienia kroku (retry
ukrywa wyścig i uczy, że czerwone CI się powtarza, a nie czyta).

### Co zostaje po stronie maszyny

- **Wstępnie zainstalowane PHP 8.4 z dziesięcioma rozszerzeniami.** `setup-php`
  nadal konfiguruje **jedną, systemową** instalację PHP; gdy wersja i
  rozszerzenia są już na miejscu, krok kończy się w sekundę i niczego nie
  podmienia. To jest ta sama rekomendacja co w `WYMAGANIA_RUNNERA.md` §4 —
  teraz ma drugie uzasadnienie.
- **Nie ustawiaj `RUNNER_TOOL_CACHE` ani `AGENT_TOOLSDIRECTORY` na katalog
  wspólny dla kilku rejestracji.** Dziś każdy runner ma własny `_work/_tool`
  i dlatego Node jest bezpieczny: w logu joba dostępności z 10.09 stoi
  `Found in cache @ /home/mateusz/actions-runner-kuking-03/_work/_tool/node/22.23.2/x64`,
  a jeden runner wykonuje jeden job naraz. Wspólny toolcache przeniósłby ten
  sam wyścig na binarkę Node'a.
- **Zmierzone przy okazji, do decyzji właściciela:** joby proszą dziś
  o **jedną etykietę** — `runs-on` widziane w API to `["self-hosted"]`, czyli
  zmienna repozytorium `CI_RUNS_ON` jest ustawiona na `self-hosted`, a nie na
  komplet sześciu etykiet z Kroku 1. Skutek jest taki, że przebiegi trafiają
  na starą pulę WSL-ową (`kuking-wsl-DOM-NEW-01`–`-03`) — trzy rejestracje na
  jednej maszynie — dokładnie tak, jak ostrzega nagłówek `ci.yml`. Poprawka
  z #262 działa na obu pulach, ale rozrzedzenie jobów na więcej maszyn wymaga
  właśnie tej zmiennej.

### Co jeszcze na tej maszynie jest wspólne

Trzy rejestracje chodzą jako JEDEN użytkownik, więc mają jeden katalog domowy.
Wspólne są między innymi:

| Ścieżka | Kto pisze | Czy to grozi tym samym |
|---|---|---|
| `/usr/local/bin` | `setup-php` (`tools:`) | **TAK — to była przyczyna #262.** Od 11.09 joby tam nie piszą |
| jedna systemowa instalacja PHP i `php.ini` | `setup-php` (wersja i rozszerzenia) | Nie ETXTBSY, ale ta sama rodzina: ostatni job ustawia stan dla pozostałych. Dlatego wszystkie joby mają w `ci.yml` IDENTYCZNĄ listę rozszerzeń, a na maszynie ma stać gotowe PHP 8.4 |
| `~/.npm` (cache npm) i `~/.cache/composer` | `npm ci`, `composer install`, `actions/cache` | Nie widzieliśmy z tego awarii. Oba narzędzia zapisują przez plik tymczasowy i podmianę nazwy, więc nie ma tu pliku wykonywanego w trakcie zapisu. **To jest brak obserwacji, nie dowód bezpieczeństwa** |
| `~/.cache/ms-playwright` (przeglądarki) | `npx playwright install` | Tak — i dlatego ten krok chodzi pod `flock` (patrz `ci.yml`, krok „Przeglądarka") |
| jeden demon Dockera i jeden dysk | usługi `postgres`, buildx, obrazy | Nie ETXTBSY. Port Postgresa jest już dynamiczny; zostaje miejsce na dysku — `docker system prune -af` i pilnowanie `_work` |

### To NIE to samo co ciasny dysk — ta sama klasa, inny mechanizm

Na tej samej maszynie zdarza się drugi objaw z tej rodziny: kilka przebiegów
naraz zapełnia dysk i narzędzie melduje **skutek, nie przyczynę** (np. „Brak
pliku źródłowego w storage." zamiast „nie było miejsca na zapis").

Rozpoznanie jest jednoznaczne i nie trzeba tu zgadywać:

| Co widzisz | Co to jest |
|---|---|
| `Text file busy` przy pliku wykonywalnym, kod 126 | wyścig `execve()` z zapisem — sprawa opisana wyżej. Miejsce na dysku nie ma z tym nic wspólnego: jądro odmawia, bo plik jest otwarty do zapisu |
| `No space left on device`, „cannot execute binary file", plik krótszy niż powinien, zapisy padające w losowych miejscach | ciasny dysk. `df -h`, `docker system prune -af`, czyszczenie `_work` |

**Hipoteza, nie pomiar:** obciążony dysk wydłuża każdy zapis, więc okno, w którym
binarka jest otwarta do zapisu, robi się szersze i w wyścig łatwiej trafić.
Zmierzone tego nie potwierdza ani nie podważa — odtworzenie ETXTBSY niżej
wychodzi przy 14 GB wolnego miejsca (zmierzone `df -h` w chwili odtworzenia),
czyli **brak miejsca nie jest do tego objawu potrzebny.**

### Jak potwierdzić jednym przebiegiem

Wyścig widać tylko przy równoległości, więc pomiar jest taki:

1. **Actions → CI → Run workflow** na pięciu gałęziach w tej samej minucie
   (`ci.yml` ma `workflow_dispatch`, więc bez pustych commitów).
2. Warunek zaliczenia: **żaden job nie kończy się kodem 126** i w żadnym logu
   nie ma `Text file busy`.
3. Kontrola, że pomiar cokolwiek mierzy: w logu kroku „Konfiguracja PHP" każdy
   job ma **inną** ścieżkę narzędzi — `…/_temp/kuking-narzedzia/<numer>-1-<job>`.
   Jeśli ścieżki są identyczne albo wskazują `/usr/local/bin`, zmienne nie
   doszły i przebieg niczego nie dowodzi.

Odtworzenie samego mechanizmu (bez runnerów, na dowolnej maszynie) — proces
trzyma plik wykonywalny otwarty do zapisu, drugi próbuje go uruchomić:

```bash
printf '#!/usr/bin/env php\n' > composer && chmod 755 composer
python3 -c "import time; f=open('composer','r+b'); time.sleep(3)" &
sleep 0.5 && ./composer --version
# bash: ./composer: /usr/bin/env: bad interpreter: Text file busy   (kod 126)
```

---

## Co to zmienia w codziennej pracy

Hook `pre-push` **zostaje**. Nie jest zbędny — jest szybszy i łapie błąd,
zanim ten w ogóle trafi na GitHuba. CI na runnerze jest drugą siatką:
sprawdza to samo, ale niezależnie od tego, czy ktoś pominął hook przez
`--no-verify`.

```text
lokalnie          ./scripts/check.sh   (hook pre-push, sekundy)
na GitHubie       CI na własnym runnerze (minuty, widoczne w PR-ze)
przed deployem    "Wait for CI" w Railway
```

## Referencje

`CI_BEZ_ACTIONS.md` (porównanie opcji) · `../DECISIONS.md` D-010, D-028, D-121 ·
`.github/workflows/ci.yml` · `Dockerfile` (źródło listy rozszerzeń PHP) · issue #4 ·
issue #342 · `../../tests/Feature/DokumentyCiMowiaPrawdeORunnerzeTest.php`
(strażnik: ten dokument i komentarze w `ci.yml` mają mówić o mechanizmie wyboru
runnera to samo, co naprawdę stoi w `runs-on:`)
