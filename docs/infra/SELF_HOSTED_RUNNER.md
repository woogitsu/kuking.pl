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

   Same domyślne `self-hosted,Linux,X64` **nie wystarczą**: job z takim
   runnerem nigdy nie wystartuje, bo `runs-on` wymaga wszystkich sześciu
   etykiet. Ten wymóg istnieje po to, żeby joby nie trafiały na starą pulę
   WSL-ową (`woogitsu-wsl-DOM-NEW-01`–`04`), która ma tylko `self-hosted`,
   `Linux`, `X64`, `wsl2`, `woogitsu`.

4. Uruchom jako usługę, żeby przeżył restart maszyny:

```bash
sudo ./svc.sh install
sudo ./svc.sh start
sudo ./svc.sh status
```

W panelu GitHuba runner powinien pokazać się jako **Idle**.

---

## Włączenie CI w repozytorium

### Krok 1 — nic do ustawiania

Runnera **nie wybiera już żadna zmienna repozytorium.** Każdy job ma wpisany
zestaw etykiet:

```yaml
runs-on: [self-hosted, Linux, X64, woogitsu, i5-10400f, nvidia-gtx1070]
```

Etykiety, nie nazwa runnera: nazwa przypięłaby job do jednej maszyny, więc
jej awaria zatrzymywałaby całe CI. Zmienna `CI_RUNNER` nie jest już przez nic
czytana — jeśli gdzieś jeszcze istnieje, można ją usunąć.

**Skutek, który trzeba znać:** te joby nie mają już zapasu w runnerach
GitHuba. Gdy cała pula `woogitsu-linux-01`–`woogitsu-linux-10` jest offline,
przebiegi stoją w kolejce bez końca, a CI jest bramką deployu (Railway ma
„Wait for CI") — stoi wtedy także wdrożenie.

### Krok 2 — odkomentowanie wyzwalaczy

W `.github/workflows/ci.yml` na górze pliku jest zakomentowany blok `on:`
i tymczasowe `on: workflow_dispatch:`. Zamień je: odkomentuj oryginalny blok,
usuń `workflow_dispatch`. To samo w `deploy.yml` i `preview.yml`, gdy dojdzie
do wdrożenia.

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
| Job stoi w „Queued" bez końca | Runner offline albo brakuje etykiety. Sprawdź `sudo ./svc.sh status` i czy runner ma WSZYSTKIE sześć: `self-hosted`, `Linux`, `X64`, `woogitsu`, `i5-10400f`, `nvidia-gtx1070`. Brak jednej wystarcza, żeby job nigdy nie wystartował |
| „could not find driver" | Brak `php8.4-pgsql`. `sudo apt install php8.4-pgsql` i restart usługi runnera |
| Testy padają na połączeniu z bazą | Docker nie działa albo usługa `postgres` nie wstała. `docker ps` w trakcie przebiegu |
| „permission denied" przy Dockerze | Użytkownik runnera nie jest w grupie `docker`: `sudo usermod -aG docker $USER`, potem restart usługi |
| Job trwa bardzo długo za pierwszym razem | Normalne — Composer i npm budują cache. Kolejne przebiegi są znacznie szybsze |
| „Failed to install browsers → exit 100" w jobie dostępności | `apt-get update` na tej maszynie kończy się niezerowo, bo w liście źródeł siedzi PPA `ppa.setup-php.com/ondrej/php`, które od 9 września 2026 zwraca 404 na plik Release dla Ubuntu „resolute". CI już apta nie woła (patrz niżej), ale każde ręczne `sudo apt update` na tej maszynie też będzie krzyczeć. Usuń martwe źródło: `sudo rm /etc/apt/sources.list.d/*setup-php*` (albo `ondrej-*`) i sprawdź `sudo apt update` |

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

`CI_BEZ_ACTIONS.md` (porównanie opcji) · `../DECISIONS.md` D-010 ·
`.github/workflows/ci.yml` · `Dockerfile` (źródło listy rozszerzeń PHP) · issue #4
