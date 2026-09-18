# Mieszany test obciążeniowy do nasycenia — metoda (#605)

> **W TYM KATALOGU NIE MA JESZCZE ŻADNEGO WYNIKU WYDAJNOŚCIOWEGO.**
> Jedyny plik z czasami odpowiedzi — `kontrola-przyrzadu.json` — jest
> **kontrolą działania przyrządu**, zdjętą przy `load average` 12–15
> pochodzącym od cudzych procesów na współdzielonej maszynie. Jego liczb
> nie wolno cytować, wstawiać do issue ani porównywać z load581; pokazują
> najwyżej KOLEJNOŚĆ kosztu tras. Powód wstrzymania serii i pełny stan
> przygotowania: `PRZYGOTOWANIE.md` w tym katalogu.

Data pomiaru: 18.09.2026. Gałąź `perf/605-obciazenie-mieszane` z `main` `bdc56b8`.
Topologia: **`all`** — web, worker kolejki i scheduler w JEDNYM kontenerze.
To jest dzisiejsza produkcja (`kuking-entrypoint all`, jedna replika), a nie
topologia po #595. Wyników z tego katalogu **nie wolno zestawiać** z wynikami
po rozbiciu usług jako „przed/po”, dopóki taki pomiar nie zostanie zrobiony
tym samym przyrządem.

---

## 0. Czym ten pomiar jest wobec baseline'u load581

Zlecenie zawierało informację, że katalog `docs/infra/evidence/load581/`
nie istnieje w repozytorium. **Istnieje.** Sprawdzone w tym klonie:

- `git ls-tree -r --name-only HEAD -- docs/infra/evidence/load581` → **50 plików**;
- dodane commitem `9a44ccc` z 15.09.2026;
- `git log --all --diff-filter=D -- 'docs/infra/evidence/load581/*'` → **pusto**,
  czyli nigdy nie został usunięty.

Źródłem pomyłki jest najpewniej kanoniczne repozytorium na dysku Windows
(`C:\Users\matma\Documents\Codex\kuking.pl`): stoi ono na gałęzi
`fix/579-kolejka-gospodarza`, commit `ac5ff9d`, i na tym commicie katalogu
`docs/infra/evidence` nie ma **w ogóle**. Sprawdzenie na tamtej kopii daje
więc „nie ma” dla całego drzewa dowodów, nie tylko dla load581.

Liczby cytowane w opisie #605 (20 żądań/s, p95 33–54 ms) są w `TABELE.md`
tamtego katalogu i zgadzają się z opisem. **Mimo to ten pomiar nie jest
„kontynuacją baseline'u”** i nie porównuje się z nim liczbowo, bo:

| | load581 (15.09) | ten pomiar (18.09) |
|---|---|---|
| przyrząd | k6 v2.2.0 | własny generator w Node 24 (brak k6 w tym środowisku) |
| model ruchu | jeden typ żądania w pętli, scenariusze osobno | mieszanka 14 typów jednocześnie |
| zbiór danych | mały | 2000 autorów, 200 tys. wpisów, 200 tys. komentarzy, rozkład nierówny |
| zdjęcia | WebP ok. 2,3 KB | prawdziwe JPEG 12 / 24 / 48 Mpx, 5,6–13,6 MB |
| limit środowiska | kontener 2 CPU / 2 GiB (`load581/RAPORT.md`, wiersz „Web: twardy limit”) | kontener **2 CPU / 1 GB** (tyle ma produkcja wg #598) |
| nasycenie | nie osiągnięte (raport mówi to wprost) | cel pomiaru |

Dwa różne przyrządy na dwóch różnych zbiorach danych dają liczby, których nie
wolno odejmować. Ten katalog jest **pierwszym zapisanym pomiarem mieszanym do
nasycenia**, a load581 zostaje tym, czym był: baseline'em pojedynczych ścieżek.

---

## 1. Stanowisko

### 1.1 Aplikacja — obraz produkcyjny, nie `artisan serve`

```
docker run -d --name kuking-b605-app --network host \
  --cpus=2 --memory=1g --memory-swap=1g \
  --env-file <runtime.env poza repo> \
  -v /home/mateusz/kuking-b605-run/storage-app:/app/storage/app \
  kuking:ci-bdc56b8cf9b664eda104b628d85149b08d84d8d9 \
  /usr/local/bin/kuking-entrypoint all
```

Obraz `kuking:ci-bdc56b8…` to obraz CI zbudowany z **dokładnie tego commita**,
na którym stoi ta gałąź. Nie jest to przybliżenie produkcji — to ten sam
artefakt: FrankenPHP, opcache z `opcache.jit=tracing`, `docker/php.ini`,
`docker/Caddyfile`, ten sam `entrypoint`.

Dlaczego nie `php artisan serve`: lokalne PHP 8.4.24 w tym środowisku **nie ma
rozszerzenia opcache** (`php -m` bez OPcache), a wbudowany serwer PHP nie jest
tym, co obsługuje produkcję. Pomiar na takim stosie mierzyłby narzut, którego
na produkcji nie ma.

Log startowy kontenera potwierdza zgodność równoległości z produkcją:

```
maxprocs: Updating GOMAXPROCS=2: determined from CPU quota
FrankenPHP started 🐘 php_version=8.4.25 num_threads=4 max_threads=4 max_requests=0
```

To te same wartości, które #598 odczytał z logu produkcyjnego wdrożenia
`fa8f012e` (17.09, 19:58:17 UTC). **`max_threads = 4` jest twardym sufitem
równoległości HTTP repliki** i obowiązuje także tutaj.

### 1.2 Czym to stanowisko RÓŻNI się od produkcji

| | produkcja (odczyt #598, 17.09) | stanowisko |
|---|---|---|
| CPU / pamięć kontenera | 2 vCPU / 1,0 GB | **to samo** (`--cpus=2 --memory=1g`) |
| `num_threads` / `max_threads` | 4 / 4 | **to samo** |
| rola | `kuking-entrypoint all` | **to samo** |
| PostgreSQL | usługa Railway, `max_connections=500` | lokalny 18.6, port 55439, `max_connections=100`, **bez osobnego limitu CPU/RAM**, współdzielony z innymi agentami |
| zdjęcia | R2 + Cloudflare | dysk lokalny w kontenerze (`KUKING_MEDIA_DISK=public`) |
| sieć | Cloudflare → Railway | pętla zwrotna, `--network host` |
| adres klienta | `X-Forwarded-For` od Cloudflare, limity per prawdziwy odwiedzający | jeden adres (127.0.0.1) — patrz §4.2 |

Baza nie dostała limitu CPU/RAM **celowo i zgodnie z load581** („PostgreSQL
współdzieli host i nie otrzymał osobnego limitu CPU/RAM”). Skutek trzeba znać
przy czytaniu wyników: **baza w tym pomiarze ma więcej mocy względem aplikacji
niż na produkcji**, więc nasycenie po stronie aplikacji zobaczymy wcześniej niż
nasycenie bazy. To zawęża, a nie poszerza, wnioski o bazie.

### 1.3 Czego to stanowisko NIE mierzy

- **Ścieżki dostarczania mediów przez R2/Cloudflare — NIEZMIERZONA.** Panel
  Cloudflare jest za ścianą logowania, a haseł nie wpisujemy; R2 nie jest
  dostępne z tej sesji. Dysk lokalny **nie jest** zamiennikiem CDN-a: nie ma
  ani cache'u brzegowego, ani signed redirect, ani opóźnienia sieci. W tabelach
  wiersz `zdjecie` opisuje **origin**, i tylko origin.
- **Pojemności z jednego adresu IP.** Patrz §4.2.
- **Sprzętu produkcyjnego.** Host to WSL2, Intel Core Ultra 7 270K Plus,
  24 rdzenie, 32 GB RAM; kontener dostaje z tego 2 rdzenie. Rdzeń tego
  procesora nie jest rdzeniem maszyny Railwaya.

---

## 2. Zbiór danych

Buduje go `scripts/dane-obciazenia-605.php` — rozszerzenie mechanizmu
z `scripts/dane-pomiaru-feedu.php` (#585): konta fabrykami (`User::factory()`),
tagi istniejącym `TagSeeder`-em, wolumen wsadowymi `insert`-ami.

Zbiór jest **nierówny z rozmysłu** (#605: „nierówny rozkład: konta z 10/100/500+
obserwowanymi, kilka treści z setkami komentarzy”):

- 60 % wpisów pisze **20 autorów** z dwóch tysięcy;
- 40 % przepisów pisze **50 autorów**;
- **40 przepisów ma po 300–600 komentarzy**, reszta rozkłada się cienko;
- konta widzów obserwują **10 / 25 / 50 / 100 / 250 / 500 / 800 / 1200** osób;
- tagi po Zipfie: pierwsza dziesiątka zbiera większość przypięć;
- co dziesiąty zeszyt ma 250 pozycji, pozostałe po kilka;
- widoczność wpisów: 80 % publiczne, 15 % dla obserwujących, 5 % prywatne;
- blokady obejmują także konta widzów — inaczej filtr blokad nigdy nie
  zadziałałby na mierzonej trasie.

Dokładne liczby powstałych wierszy: `dane.json` w tym katalogu.

---

## 3. Zdjęcia

`scripts/zdjecia-obciazenia-605.php` robi **prawdziwe pliki JPEG** przez GD:

| plik | wymiary | Mpx | jakość | rozmiar |
|---|---|---:|---:|---:|
| `kuking-b605-12mpx.jpg` | 4000 × 3000 | 12,0 | 88 | 5,64 MB |
| `kuking-b605-24mpx.jpg` | 5657 × 4243 | 24,0 | 88 | 11,12 MB |
| `kuking-b605-48mpx.jpg` | 8000 × 6000 | 48,0 | 72 | 13,62 MB |

Obraz to gradient + elipsy + ziarno na siatce 8 px. Ani szum (plik przekracza
`media.max_bytes` = 15 MB i nie przechodzi walidacji), ani płaska plama
(200 KB przy 48 Mpx — czyli dokładnie ten błąd, który #605 wypomina
baseline'owi: „testowy WebP miał ok. 2,3 KB”).

Zdjęcia wchodzą **ścieżką produktową**: `POST /dodaj/zdjecie` → `StoreUploadedImage`
→ synchroniczny `podglad` 640 px → zadanie `ProcessUploadedImage` w kolejce →
warianty `thumb` 320 / `feed` 960 / `large` 1600, wszystkie WebP q82.
Żadnych wierszy `media` wstawianych z palca.

Pliki leżą poza repozytorium (`/home/mateusz/kuking-b605-run/zdjecia`) — kilkanaście
megabajtów binariów nie ma czego szukać w gicie.

---

## 4. Ruch

### 4.1 Mieszanka

Generuje `scripts/generator-obciazenia-605.mjs` (Node 24, zero zależności).
Model napływu jest **otwarty** (constant arrival rate): żądania startują
według zegara, a nie „gdy poprzednie wróci”. Pętla zamknięta dławi się razem
z serwerem i nasycenie nigdy nie wychodzi na jaw; tutaj objawem nasycenia jest
rosnąca liczba żądań w locie (`w_locie_szczyt`) i rosnące p95.

Wagi (suma 100), jedyna arbitralna liczba w przyrządzie:

| scenariusz | waga | co to jest |
|---|---:|---|
| `anon_landing` | 8 | `/` bez sesji |
| `anon_przepis` | 14 | `/przepisy/{slug}` bez sesji |
| `anon_wpis` | 7 | `/wpisy/{uuid}` bez sesji |
| `anon_tag` | 4 | `/tag/{tag}` bez sesji |
| `anon_profil` | 2 | `/@{username}` bez sesji |
| `zal_feed` | 14 | `/home` — feed obserwowanych |
| `zal_feed_str2` | 4 | `/home?page=2` |
| `zal_discover` | 4 | `/odkryj` z sesją |
| `zal_zeszyt` | 3 | `/zeszyt/{id}` |
| `szukaj` | 8 | `/szukaj?q=…` |
| `zdjecie` | 25 | `/zdjecia/{uuid}/{wariant}` |
| `komentarz` | 4 | `POST /przepisy/{slug}/komentarz` |
| `ugotowalem` | 2 | `POST /przepisy/{slug}/ugotowalem` |
| `upload` | 1 | `POST /dodaj/zdjecie` z plikiem 12 Mpx |

Adresy celów generator bierze **z aplikacji** (`/sitemap.xml`, `/odkryj`,
`/tagi`, strony wpisów), nie z bazy — dzięki temu żadna seria nie mierzy
przypadkiem trasy zwracającej 404.

### 4.2 Limity zapytań a wiarygodność pomiaru — rzecz do przeczytania przed tabelą

Limity z `config/kuking.php` liczą się **po id użytkownika dla zalogowanych
i po adresie IP dla anonimowych**. Generator stoi na jednym hoście, więc cały
ruch anonimowy ma jeden adres. Wynikają z tego trzy rzeczy, wszystkie zapisane
tutaj, a nie ukryte w kodzie:

1. **Logowanie kosztuje 13 sekund na konto.** `POST /login` ma limit `5,1`
   liczony po adresie. 60 kont widzów to ok. 13 minut fazy przygotowania.
   Limitu nie obchodzimy — płacimy go raz.
2. **`/szukaj` i `/zdjecia/{uuid}/{wariant}` idą w serii z sesją**, choć obie trasy są
   publiczne. Bez sesji limity `search` (60/min) i `zdjecie` (600/min) zaczęłyby
   oddawać 429 już przy 1 i 10 żądaniach/s, i pomiar dotyczyłby wyłącznie
   własnej konfiguracji limitów, a nie aplikacji. Z sesją limit jest liczony
   po użytkowniku, czyli 60 kont × limit.
3. **Ten pomiar nie mówi, ile serwis wytrzyma z JEDNEGO adresu.** Z jednego
   adresu wcześniej niż aplikacja zadziała throttle. To osobne pytanie
   i osobny pomiar.

Ścieżek zapisu dotyczą limity per użytkownik: `comment` 10/min, `post`
20/10 min. Przy 60 kontach daje to sufit ok. 10 komentarzy/s i ok. 2 wgrań/s.
Odpowiedzi 429 **nie są liczone jako błąd serwera** — stoją osobno w polu
`statusy` każdego scenariusza, bo zadziałanie limitu jest wynikiem, nie awarią.

### 4.3 Koszt samego generatora

Generator chodzi na tej samej maszynie co aplikacja, więc każdy jego takt jest
taktem zabranym aplikacji. Każdy wynik serii zawiera `koszt_generatora`:
`cpu_user_s`, `cpu_system_s`, `cpu_rdzenie_srednio` (własny czas CPU procesu
Node podzielony przez czas trwania serii) i `maxrss_mb`. Bez tej liczby wynik
jest nieinterpretowalny.

---

## 5. Co jest rejestrowane

`scripts/probnik-obciazenia-605.sh` zapisuje jedną linię JSON na sekundę:

| pole | skąd | po co |
|---|---|---|
| `load1` | plik loadavg w /proc | maszyna jest współdzielona z runnerem CI i worktree innych agentów — bez tego nie da się odróżnić degradacji aplikacji od cudzego hałasu |
| `kontener_rdzenie`, `kontener_rss_mb`, `kontener_rss_szczyt_mb` | cgroup v2 kontenera | zużycie względem limitu 2 CPU / 1 GB |
| `web_rdzenie`, `web_rss_mb` | katalog procesu frankenphp w /proc | **osobno web** |
| `worker_rdzenie`, `worker_rss_mb` | katalog procesu queue:work w /proc | **osobno worker** — w topologii `all` to ich wzajemne wypieranie się jest przedmiotem pomiaru |
| `db_polaczenia`, `db_aktywne` | `pg_stat_activity`, tylko `client backend` | budżet połączeń wg #598 |
| `db_czeka_na_blokade` | `wait_event_type = 'Lock'` | oczekiwania na blokady |
| `kolejka`, `najstarsze_zadanie_s`, `nieudane_zadania` | `jobs`, `failed_jobs` | zaległość kolejki i wiek najstarszego zadania |

PID-y web i workera próbnik bierze **z `cgroup.procs` tego kontenera**, nie
przez `pgrep -f` po wzorcu — na tej maszynie wzorzec trafiłby w procesy innych
agentów.

Wolne zapytania: `ALTER DATABASE kuking_b605_obciazenie SET log_min_duration_statement`,
czyli ustawienie **zakresu jednej bazy**, bez `ALTER SYSTEM` i bez restartu
współdzielonego klastra. `pg_stat_statements` jest dostępne jako rozszerzenie,
ale `shared_preload_libraries` jest puste, a włączenie go wymagałoby restartu
klastra, z którego korzystają inni — dlatego **nie zostało użyte**.

---

## 6. Higiena pomiaru: bramkowanie obciążeniem zamiast czekania na ciszę

### 6.1 Dlaczego bramka, a nie „ciche okno"

Ta maszyna jest **wspólnym hostem CI pięciu projektów**: 17 zarejestrowanych
runnerów (`kuking` 4, `woogitsu` 4, `lockstate` 3, `metro` 3, `osadale` 3).
Zajętość nie pochodzi od tego zadania, **nie wolno jej wyłączać** i nie mija
sama — jest strukturalna, nie chwilowa. Każdy push w którymkolwiek z pięciu
repozytoriów startuje job, który może wypaść w środku stopnia rampy.

Umawianie się na 45 minut ciszy byłoby więc umawianiem się na coś, co nie
nadejdzie. Zamiast czekać — **bramkujemy**: stopień startuje tylko wtedy, gdy
obce obciążenie utrzyma się pod progiem przez pełne 60 s, a stopień, w którym
próg został przebity, dostaje werdykt SKAŻONY i nadaje się wyłącznie do
powtórzenia.

**Bramka niczego cudzego nie zatrzymuje ani nie usypia.** Czyta /proc i czeka.

### 6.2 Jak liczone jest „obce obciążenie"

```
obce = rdzenie zajęte na hoście  −  rdzenie zjedzone przez własne stanowisko
```

Zajętość hosta z plik stat w /proc (suma pól minus `idle` i `iowait`, podzielona
przez czas i takt zegara). Własne stanowisko = cgroup kontenera aplikacji
+ proces generatora + sam próbnik. Różnica jest zacinana na zerze, bo dwa
niezależne liczniki potrafią na jednej próbce dać minimalnie ujemny wynik.

Druga, **niezależna** miara: plik pressure/cpu w /proc, pole `some avg10` — ile
procent czasu zadania CZEKAŁY na procesor. Wolne rdzenie i brak czekania to
dwie różne rzeczy i przy bramkowaniu potrzebne są obie.

### 6.3 Próg — liczba z pomiaru, nie z odczucia

Przed wyborem progu zmierzyłem rozkład obcego obciążenia próbnikiem co
sekundę (`rozpoznanie-obcego.csv`, 278 próbek, 18.09 ok. 12:14–12:19, przy
24 rdzeniach):

| miara | min | p10 | mediana | p90 | max |
|---|---:|---:|---:|---:|---:|
| obce rdzenie | 9,3 | 14,4 | **17,9** | 20,3 | 23,2 |
| PSI cpu `some avg10` | 3,5 % | 5,5 % | **22,2 %** | 42,4 % | 46,1 % |

Najdłuższy ciąg spełniający oba warunki naraz, w tym samym oknie:

| próg | najdłuższy ciągły spokój |
|---|---:|
| obce ≤ 12 i PSI ≤ 15 % | 2 s |
| obce ≤ 14 i PSI ≤ 20 % | 3 s |
| obce ≤ 16 i PSI ≤ 20 % | 13 s |
| **obce ≤ 18 i PSI ≤ 25 %** | **70 s** |
| obce ≤ 20 i PSI ≤ 30 % | 139 s |

**Przyjęty próg: obce ≤ 18,0 rdzeni z 24 ORAZ PSI cpu `some avg10` ≤ 25 %,
utrzymane przez 60 s z rzędu.**

Uzasadnienie od góry i od dołu, bo próg musi być broniony z obu stron:

- **Dlaczego nie wyżej.** Przy progu 20 rdzeni przechodzi 88,5 % próbek, czyli
  bramka przepuszczałaby **zwyczajny stan tej maszyny** — a to jest dokładnie
  ten stan, który mamy wykluczyć. Próg 18 przepuszcza 51,1 % próbek, czyli
  wybiera spokojniejszą połowę.
- **Dlaczego nie niżej.** Przy progu 16 najdłuższy spokój w oknie pomiarowym
  trwał 13 s, a przy 12 — dwie sekundy. Bramka z takim progiem **nigdy by się
  nie otworzyła**, a próg dobrany tak, żeby nic nie przeszło, to wybór
  niemierzenia niczego, nie ostrożność.
- **Dlaczego akurat 18 ma sens fizyczny, a nie tylko statystyczny.**
  Stanowisko potrzebuje w szczycie ok. **2,6 rdzenia**: 2,0 (twardy przydział
  kontenera `--cpus=2`) + ok. 0,5 (generator przy wysokim rps) + 0,05 (próbnik).
  Przy 18 zajętych zostaje **6 wolnych rdzeni, czyli 2,3 × tyle, ile stanowisko
  potrzebuje** — przydział kontenera da się zaspokoić bez stania w kolejce do
  procesora. Degradacja, którą wtedy widać, pochodzi z **własnego limitu 2 CPU**,
  czyli z rzeczy mierzonej, a nie z konkurencji o host.

### 6.3a Trzeci warunek: żaden runner `kuking` nie może pracować

Progi CPU i PSI opisują hałas jako zjawisko. Trzeci warunek opisuje jego
**jedyną część, na którą mamy wpływ**.

Runnery `kuking-01..04` pracują dlatego, że **ktoś z nas wypchnął gałąź** —
każdy push uruchamia komplet zadań CI, w tym dwa długie: „Panel marki"
(ok. 17 min) i „Testy (PostgreSQL 18)" (ok. 9 min). Runnery `lockstate`,
`metro` i `osadale` należą do cudzych projektów: **nie zatrzymujemy ich, nie
prosimy o nic i nie czekamy na nie w nieskończoność** — są pogodą, nie
warunkiem. Jeśli po zamknięciu naszego CI obce obciążenie i tak nie zejdzie
pod próg, to jest wynik i tak go zapisujemy.

Warunek jest **osobny od progu CPU**, bo runner ma przerwy między zadaniami:
obciążenie na chwilę spada pod próg, bramka by się otworzyła, a trzy minuty
później startuje „Panel marki" i rozjeżdża stopień. Dokładnie tak przepadły
`r005-p1` i `r005-p2`.

W regule skażenia ten warunek jest **twardy, bez marginesu**: jedna próbka
z pracującym runnerem `kuking` skaża stopień. Próg procentowy dotyczy hałasu,
którego nie kontrolujemy; własnego CI się nie toleruje.

**Wykrywanie — prosty wzorzec po nazwie katalogu NIE DZIAŁA i to jest pułapka
warta zapisania.** Komenda

```
ps -eo args | grep -oE 'actions-runner-kuking-0[0-9]' | sort -u
```

zwraca **wszystkie cztery ZAWSZE**, bo `Runner.Listener` każdego runnera stoi
nieprzerwanie jako usługa i ma tę ścieżkę w linii poleceń. Zmierzone: przy
dwóch faktycznie pracujących runnerach ta komenda pokazywała cztery. Gdyby
bramka opierała się na niej, **nie otworzyłaby się nigdy** — i wyglądałoby to
na wynik („maszyna zawsze zajęta"), a nie na błąd przyrządu.

Rozstrzyga `Runner.Worker` — ten proces istnieje wyłącznie wtedy, gdy runner
ma przydzielone zadanie:

```
ps -eo args | grep 'Runner\.Worker' | grep -oE 'actions-runner-kuking-0[0-9]' | sort -u
```

**Czego próg NIE usuwa:** współdzielonej przepustowości pamięci i wspólnego
cache'u ostatniego poziomu. Nawet przy wolnych rdzeniach cudze procesy
podnoszą opóźnienia dostępu do pamięci. Tego nie da się wybramkować i dlatego
wszystkie liczby z tego katalogu są **pomiarem na maszynie współdzielonej**,
a nie na stanowisku laboratoryjnym.

### 6.4 Kiedy stopień jest skażony

> **skażony = przebicia w ponad 3 % próbek stopnia ALBO choć jedno przebicie
> trwające co najmniej 5 sekund z rzędu ALBO choć jedna próbka z pracującym
> runnerem `kuking`**

Ta reguła jest moja i stoi tu jawnie, żeby dało się ją zakwestionować.
Pojedyncza sekunda ponad progiem w przebiegu 180-sekundowym to ziarnistość
pomiaru, nie cudza interferencja; gdyby dyskwalifikowała stopień, na tej
maszynie nie dałoby się zdjąć **niczego**. Pięć sekund z rzędu albo 3 %
przebiegu to już cudzy job, który wystartował w środku.

Stopnie skażone **zostają w dowodach** razem z powodem — to jest dowód, że
bramka działała, a nie że dobierano wyniki. Stopień, którego nie udało się
zdjąć czysto mimo powtórzeń, zapisujemy jako **NIEWYKONANY z powodem**.
Wiersz „nie udało się zmierzyć przy 110 rps, trzy próby skażone" jest wynikiem.
Zmyślony punkt nasycenia nie jest.

**Reguły nie zmieniamy po zobaczeniu wyniku.** Pierwszy stopień `r005-p2`
został odrzucony granicznie: trzy POJEDYNCZE sekundy ponad progiem na 92 próbki,
czyli 3,3 % wobec odcięcia 3,0 %, przy najdłuższym przebiciu 1 s i medianie
obcego obciążenia 11,9 rdzenia. Ten przebieg był w istocie spokojny i odrzuciła
go arytmetyka limbu procentowego, a nie stwierdzona interferencja. Poluzowanie
odcięcia w tym momencie byłoby dokładnie tym dobieraniem wyników, przed którym
ta reguła ma chronić — więc `r005-p2` zostaje skażony. Jeżeli reguła ma być
poprawiona (np. „3 % ORAZ choć jedno przebicie ≥ 2 s"), to **przed** kolejnymi
przebiegami i dla wszystkich stopni tak samo.

### 6.5 Pozostała higiena

- Serie **szeregowo**, nigdy równolegle.
- Obce obciążenie jest **osobną kolumną przy każdej liczbie** (`werdykt-*.json`),
  obok kosztu własnego generatora — czytelnik ma widzieć, w jakich warunkach
  powstał każdy wiersz.
- Wszystko na własnej bazie `kuking_b605_obciazenie` (port 55439, **nigdy 5432**)
  i własnym porcie aplikacji 8605.
- Budowanie zbioru danych pod `nice -n 19 ionice -c3`, żeby ustępowało
  runnerom CI i testom innych agentów.
- Żadnych `pkill` po wzorcu, żadnego zatrzymywania cudzych procesów.

---

## 7. Jak to powtórzyć

```bash
cd /home/mateusz/kuking-B-obciazenie
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439 \
       DB_DATABASE=kuking_b605_obciazenie DB_USERNAME=kuking DB_PASSWORD=kuking \
       APP_BASE_PATH="$(pwd)" APP_URL=http://localhost PGTZ=UTC

# 1. baza i migracje
createdb -h 127.0.0.1 -p 55439 -U kuking kuking_b605_obciazenie
php artisan migrate --force

# 2. zbiór danych (ok. 10 min; wypisze hasło kont widzów)
php scripts/dane-obciazenia-605.php > dane.json

# 3. pliki zdjęć
php -d memory_limit=1G scripts/zdjecia-obciazenia-605.php /sciezka/poza/repo/zdjecia

# 4. kontener z obrazu produkcyjnego tego commita (patrz §1.1)

# 5. sesje i cele (ok. 13 min — limit logowania)
node scripts/generator-obciazenia-605.mjs przygotuj --manifest <poza repo> \
     --baza http://127.0.0.1:8605 --haslo <z dane.json> --widzowie 60

# 6. rozgrzewka mediów + zebranie adresów /zdjecia/*
node scripts/generator-obciazenia-605.mjs media --manifest <…> --ile 24
node scripts/generator-obciazenia-605.mjs zbierz-media --manifest <…>

# 7. seria — jedna komenda, bo zapis otoczenia jest obowiązkowy
#    (uruchamia próbnik, generator i zapisuje `uptime` + ciężkie procesy;
#     po zdjęciu obciążenia próbnik chodzi jeszcze 60 s — to jest pomiar
#     powrotu do normy)
bash scripts/seria-obciazenia-605.sh r050 50 180 <katalog-wynikow> <manifest>
```

Serie wykonuje się **pojedynczo, szeregowo**. Nigdy dwie naraz.

Manifest zawiera ciasteczka sesji i **nie trafia do repozytorium**.
