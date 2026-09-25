# Weryfikacja budżetu połączeń PostgreSQL — 19 września 2026 (#598)

> **Ten dokument NIE zakłada budżetu połączeń od nowa.** Pomiar, wyprowadzenie
> progów i czujka powstały 17–18.09.2026 i stoją w
> [`docs/DATABASE.md`, §„Budżet połączeń PostgreSQL — issue #598"](../DATABASE.md).
> Tu jest **niezależna weryfikacja tamtych liczb** z 19.09.2026, jedno
> ustalenie nowe (i wynikająca z niego wąska poprawka) oraz lista tego,
> czego zmierzyć się nie dało.

## 0. Stan zgłoszenia: mechanizm ISTNIEJE, chodzi i nie trzeba go pisać drugi raz

| Rzecz | Gdzie |
|---|---|
| pomiar stanu puli | `app/Domain/Polaczenia/StanPolaczenBazy.php` |
| alarm z wyciszaniem i odwołaniem | `app/Domain/Polaczenia/AlarmPolaczen.php` |
| komenda | `app/Console/Commands/BudzetPolaczen.php` (`kuking:budzet-polaczen`) |
| harmonogram, co godzinę o :25 | `routes/console.php` |
| progi | `config/kuking.php` → `kuking.polaczenia.*` |
| testy | `tests/Feature/BudzetPolaczenBazyTest.php` (21 testów) |
| wyprowadzenie liczb | `docs/DATABASE.md` §598, sekcje A–F |

**Czujka działa na produkcji.** Dziennik wdrożenia `b40add77` pokazuje jej
przebiegi 19.09.2026 o 11:25:02 i 12:25:11 UTC — `Running
[kuking:budzet-polaczen] ... DONE`, 15,15 ms i 13,65 ms (odczyt dziennika
Railway).

## 1. Co zweryfikowano 19.09.2026 — z warstwą dowodu przy każdej liczbie

### 1a. Produkcja (odczyt Railway, projekt `ideal-exploration`)

| Liczba | Wartość | Warstwa dowodu |
|---|---|---|
| liczba serwisów aplikacji | 1 (`kuking.pl`) | `describe-environment`, 19.09.2026 |
| `numReplicas` | **1** | tamże (`multiRegionConfig.europe-west4-drams3a`) |
| `startCommand` | `kuking-entrypoint all` | `get-service-config`, 19.09.2026 |
| `preDeployCommand` | `php artisan migrate --force --no-interaction` | tamże |
| **`num_threads` / `max_threads` FrankenPHP** | **4 / 4** | dziennik startowy wdrożenia `609569cc`, **19.09.2026 12:47:29 UTC**: `FrankenPHP started 🐘 php_version=8.4.25 num_threads=4 max_threads=4` |
| `GOMAXPROCS` | 2 | ten sam wpis: `maxprocs: Updating GOMAXPROCS=2: determined from CPU quota` |
| `KUKING_POLACZENIA_*` | **nie ustawione** | `list-variables`, 19.09.2026 — obowiązują wartości domyślne z `config/kuking.php` (16 / 50 / 125) |
| `LOG_BLAD_WEBHOOK_URL` | **nadal nie istnieje** | `list-variables`, 19.09.2026, `sealedVariableNames` puste |
| proxy TCP na usłudze Postgres | **brak** | `list-tcp-proxies`, 19.09.2026 — pusta lista |

`max_threads = 4` jest **odczytane ponownie, z innego wdrożenia niż 17.09** —
nie przepisane z poprzedniej notatki. To ono jest sufitem równoległości HTTP,
nie liczba rdzeni i nie liczba osób online.

Rola `all` uruchamia dokładnie trzy rzeczy (lektura `docker/entrypoint.sh`,
gałąź `all`): nadzorowany `queue:work`, pętlę `schedule:run` co 60 s
i `frankenphp run`. Zgadza się z topologią przyjętą w `docs/DATABASE.md` §C.

### 1b. Pomiar lokalny (PostgreSQL 18.6, `127.0.0.1:55439`, baza `kuking_598_pomiar2`)

Scena z `origin/main` na SHA `ab91185c` — **tym samym commicie, który w chwili
pomiaru działał na produkcji** (wdrożenie `609569cc`, SUCCESS). Sterowniki jak
na produkcji: `CACHE_STORE=database`, `SESSION_DRIVER=database`,
`QUEUE_CONNECTION=database`.

Próbnik: `pg_stat_activity`, wyłącznie `backend_type = 'client backend'`.

| Co uruchomiono | Szczyt backendów | Uwaga |
|---|---|---|
| spoczynek, nic nie chodzi | **0** | |
| jeden proces web, 24 żądania po kolei | **1**, po zakończeniu **0** | połączenie wraca po żądaniu |
| **12 żądań równolegle przy 4 workerach serwera** | **5** | **nie 12** — sufitem jest liczba workerów, nie liczba żądań |
| `queue:work` (jeden proces) | **1**, trwale | |
| `schedule:run` (10 przebiegów) | **1**, chwilowo | |
| `kuking:budzet-polaczen` (10 przebiegów) | **1**, chwilowo | |

**Najważniejsza z tych liczb:** 12 równoległych żądań przy 4 workerach dało
5 backendów, a nie 12. Zużycie połączeń rośnie z liczbą **procesów
obsługujących**, nie z ruchem. Piąty backend to nakładanie się zwalniania
starego połączenia ze startem nowego — sufit to więc „liczba wątków plus
chwilowy zapas na wymianę", a nie „liczba wątków" co do jednego.

Wyniki 1b potwierdzają tabelę z `docs/DATABASE.md` §B — powtarzają ją
niezależnie i zgodnie, nie zastępują jej.

### 1c. Kontrola metody próbnika — i pułapka, która ją najpierw zepsuła

Pierwsza wersja próbnika liczyła backendy w pętli **wewnątrz jednego bloku
`DO $$`** i pokazała `0` nawet dla `kuking:budzet-polaczen`, który z definicji
musi otworzyć połączenie. Powód: **`pg_stat_activity` zwraca w obrębie jednej
transakcji ZAMROŻONY snapshot statystyk.** Pętla czytała w kółko ten sam
pierwszy odczyt.

Fałszywie uspokajała też „kontrola dodatnia": sześć procesów wstawało
**przed** startem próbnika, więc zastane `6` było widoczne w pierwszym
(i jedynym realnym) odczycie. Kontrola przechodziła, a próbnik był ślepy
na zmianę.

Poprawka i kontrole, które faktycznie coś sprawdzają:

- `PERFORM pg_stat_clear_snapshot();` **przed każdym** odczytem w pętli;
- **kontrola ujemna** — próbnik przy pustej scenie: `0`;
- **kontrola dodatnia dynamiczna** — sześć procesów wstaje **w trakcie**
  próbkowania, próbnik musi zauważyć przejście 0 → **6**. Zauważa.

Dopiero po tej poprawce `schedule:run` i komenda czujki pokazały `1` zamiast
`0`. Kto powtarza ten pomiar — niech zacznie od kontroli dynamicznej, nie
od zastanej.

## 2. Ustalenie NOWE: szereg czasowy nie powstawał, i wiadomo dlaczego

`docs/DATABASE.md` §F zostawiło pytanie otwarte: „jeżeli produkcyjne
`LOG_LEVEL` stoi powyżej `info`, szereg nie powstanie mimo działającej czujki
— wartości tej zmiennej nie dało się odczytać z tej sesji".

**Odpowiedź brzmi: tak, stoi powyżej — i szereg nie powstawał.**

### Dowód obserwacyjny

Dziennik wdrożenia `b40add77`, okno 12:24–12:27 UTC, bez filtra:

```text
12:24:18  INFO  No scheduled commands are ready to run.
12:25:11  Running [kuking:budzet-polaczen] .......... 13.65ms DONE
12:25:11  Running [kuking:policz-kolejki] ........... 31.71ms DONE
12:26:18  INFO  No scheduled commands are ready to run.
```

Czujka wystartowała i skończyła się sukcesem, a **linii z liczbami nie ma** —
przy obecnych w tej samej sekundzie innych wpisach poziomu `info`. To samo dla
czujki kolejki: ani jednego wpisu z `zaleglosc_sekundy` w całym oknie.
Kod zapisujący pomiar **był wdrożony**: commit `7c300ccc` (18.09) jest
przodkiem obu sprawdzonych wdrożeń (`git merge-base --is-ancestor`,
sprawdzone dla `8a2ecb2a` i `ab91185c`).

### Przyczyna — odczytana z repozytorium, nie zgadnięta

Zmiennych w panelu nie da się odczytać (konektor oddaje `valuesRedacted: true`),
ale **wartość nie pochodzi z panelu, tylko z manifestu wdrożenia w repozytorium**:

```ts
// .railway/railway.ts:198-200
LOG_CHANNEL: "stderr",
LOG_STDERR_FORMATTER: "\\Monolog\\Formatter\\JsonFormatter",
LOG_LEVEL: isProduction ? "warning" : "debug",
```

Na produkcji `LOG_LEVEL` to **`warning`**. Kanał `stderr` bierze poziom
z `env('LOG_LEVEL', 'debug')`, a `info` jest niżej niż `warning` — rekord był
odrzucany przez Monologa, zanim cokolwiek dotarło do strumienia. Zgadza się
to z obserwacją co do joty.

To zarazem wyjaśnia, dlaczego uwaga „trzeba to zmienić w panelu" (komentarz
w `BudzetPolaczen`) nie była dobrą receptą: zmiana w panelu rozjechałaby się
z manifestem.

### Dlaczego test tego nie złapał

`PomiarCzujekTrafiaDoDziennikaTest` używał `Log::spy()`, a to przechwytuje
wywołanie **zanim** Monolog odfiltruje rekord po poziomie. Test dowodził, że
komenda **woła** `info()` — nie że wpis **przeżywa**. Komenda wołała, test
przechodził, szeregu nie było.

### Co z tym zrobiono (wąska zmiana w repozytorium)

Dodany kanał `pomiary` w `config/logging.php`: `php://stderr`, poziom **`info`
wpisany na sztywno**, bez `env('LOG_LEVEL')`. Wzorzec nie jest nowy — kanał
`blad_webhook` ma dokładnie tak samo zadrutowane `'level' => 'error'`,
z komentarzem mówiącym, że kanał o jednym celu nie ma dziedziczyć ogólnego
progu aplikacji. Obie czujki (`BudzetPolaczen`, `SprawdzKolejke`) piszą teraz
`Log::channel('pomiary')->info(...)`.

**Nie obniżono `LOG_LEVEL` na produkcji.** To wpuściłoby do dziennika każde
`info` w serwisie, żeby przepchnąć dwie linie na godzinę.

Testy regresyjne (`PomiarCzujekTrafiaDoDziennikaTest`):

- `kanal_pomiarow_przepuszcza_info_mimo_log_level_warning` — przy
  `stderr.level = warning` kanał `pomiary` **nadal** obsługuje `info`;
- `kontrola_ujemna_kanal_stderr_przy_warning_odrzuca_info` — ten sam mechanizm
  (`isHandling`) musi powiedzieć „nie" dla `stderr` i „tak" dla `warning`,
  inaczej poprzednia asercja nie znaczyłaby nic;
- `poziomu_kanalu_pomiarow_nie_bierze_sie_z_log_level` — czyta konfigurację
  wprost. **Ten jest tu konieczny**: lokalnie `LOG_LEVEL=debug`, więc powrót do
  `env('LOG_LEVEL', 'debug')` przeszedłby dwa pierwsze testy i oblałby dopiero
  na produkcji, czyli tam, gdzie nikt nie patrzy.

**Czego to nadal NIE dowodzi:** że szereg powstanie. Dowiedzie tego dopiero
odczyt dziennika Railway po najbliższym wdrożeniu — linia
`kuking:budzet-polaczen {...}` o minucie :25.

## 3. Ile z sufitu zostaje — i ile zostałoby po zamiarach z #600

Sufit produkcyjny (`max_connections = 500`, `superuser_reserved_connections = 3`,
`reserved_connections = 0`, czyli **497 miejsc dla aplikacji**) pochodzi
**z odczytu z 17.09.2026** zapisanego w `docs/DATABASE.md` §A.
**W tej sesji nie dało się go powtórzyć** — patrz §5.

Koszty jednostkowe wynikają z §1a i §1b: replika `web` to `max_threads` = 4
połączenia w spoczynku i dwa razy tyle w oknie wdrożenia (stary i nowy
kontener stoją obok siebie); osobny worker to 1 i 2.

| Topologia | Budżet szczytowy | Wolne z 497 | Zajęte |
|---|---|---|---|
| dziś: 1 × `all` | **16** | 481 | 3,2 % |
| + druga replika `web` (#600) | **24** | 473 | 4,8 % |
| + osobny worker mediów (#600) | **18** | 479 | 3,6 % |
| oba naraz | **26** | 471 | 5,2 % |

Budżet „dziś = 16" to 6 w spoczynku (4 web + 1 worker + 1 harmonogram),
13 w oknie wdrożenia (6 + 6 + 1 na `preDeployCommand: migrate`) i +3 zapasu
na administrację i diagnostykę — wyprowadzenie w `docs/DATABASE.md` §C.

**Wniosek: połączenia nie są ograniczeniem dla #600.** Obie zmiany naraz zjadają
~5 % puli. Korekta z 20.09: przy nakładaniu wdrożeń `8R+8` mieści najwyżej
61 replik web w 497 miejscach, a poniżej ostrzeżenia 50 — tylko 5.
To wyliczenie, nie zgoda na skalowanie; patrz
[nowszy pomiar lokalny i ograniczenia](MONITORING_ODBIOR_2026_09_20.md).
Jeżeli #600 ma argument za
PgBouncerem, to **nie jest nim liczba połączeń** — i warto, żeby padło to w #600
wprost, zanim ktoś dołoży komponent bez pomiaru (AGENTS.md §3, zakaz
overengineeringu).

## 4. Próg alarmowy — rekomendacja: ZOSTAWIĆ 50 / 125 bez zmiany

Progi istnieją, są wpięte w czujkę i nadpisywalne zmiennymi. Pomiar z 19.09
**nie dostarczył przesłanki do ich ruszenia**:

| Próg | Wartość | Dlaczego zostaje |
|---|---|---|
| ostrzegawczy | **50** | ponad trzykrotność obliczonego budżetu szczytowego (16). Przy poprawnej topologii nieosiągalny, więc przekroczenie znaczy wyciek połączeń albo proces, o którym nikt nie wie. Po obu zmianach z #600 budżet rośnie do 26 — wciąż **około 1,92-krotność budżetu (50 / 26)** pod progiem, więc #600 też go nie wymusza. |
| krytyczny | **125** | ¼ z 497. Zostawia trzy czwarte puli na reakcję. Przy awarii skokowej próg „90 %" zapala się wtedy, gdy nie ma już czasu na nic. |

Zmiana progu bez zmierzonej potrzeby byłaby dokładnie tym, czego zakazuje
AGENTS.md §3. **Nie zmieniono ani jednej wartości w `config/kuking.php`.**

### Obserwacja na przyszłość, bez zmiany kodu

`StanPolaczenBazy::ocen()` ogranicza do liczby dostępnych miejsc **tylko próg
krytyczny** (`min($this->progKrytyczny(), $dostepne)`), ostrzegawczego nie.
Na produkcji (497 miejsc) nie ma to znaczenia. Miałoby na serwerze, gdzie
`dostepne` spadłoby poniżej 50: ostrzeżenie nigdy by się nie zapaliło,
a krytyczne dopiero przy faktycznym wyczerpaniu puli — wczesne ostrzeżenie
zniknęłoby po cichu. **Nie jest to dziś usterka i nie zmieniam tego bez
potrzeby** — to notatka dla kogoś, kto zmieni plan bazy na mniejszy.

## 5. Czego NIE dało się zmierzyć i dlaczego

1. **`max_connections` produkcji — nie potwierdzono w tej sesji.** Liczba 500
   pochodzi z odczytu z 17.09.2026 (`docs/DATABASE.md` §A). Do produkcyjnego
   Postgresa nie ma dostępu z zewnątrz: `list-tcp-proxies` na usłudze Postgres
   zwraca pustą listę, nie ma zalogowanego CLI Railway, a konektor MCP nie
   wykonuje SQL. Dziennik serwera Postgresa `max_connections` nie wypisuje —
   przejrzane, są tam wyłącznie `checkpoint` i `could not accept SSL connection`.
   **Wszystkie procenty w §3 stoją na tej jednej niepotwierdzonej liczbie.**
2. **Bieżące zużycie połączeń na produkcji — nie odczytane.** Jedyną drogą jest
   linia pomiaru czujki, a ta do 19.09 nie dochodziła (§2). Próbka z 17.09
   (1 bezczynne, 1 aktywne) to pojedynczy odczyt, nie szereg i nie szczyt.
3. **Szczyt w oknie wdrożenia — nie zmierzony nigdzie.** Liczba 13 jest
   **policzona**, nie zmierzona. Zmierzyłby ją dopiero szereg czasowy z §2,
   próbkowany częściej niż raz na godzinę.
   *(25.09.2026: narzędzia do tego pomiaru i kroki dla właściciela są
   w `docs/DATABASE.md` §598 G — sam pomiar nadal nie jest wykonany.)*
4. **Wartość `LOG_LEVEL` w panelu — nieodczytywalna** (`valuesRedacted: true`).
   Ustalono ją z manifestu `.railway/railway.ts`, co jest lekturą kodu, nie
   odczytem stanu. Gdyby ktoś nadpisał ją ręcznie w panelu, ten wniosek
   trzeba by powtórzyć.
5. **Treść zgłoszenia #598 — nieprzeczytana.** W tym środowisku nie ma `gh`
   (ani w WSL, ani w Windows), a API GitHuba bez tokenu zwraca 404 na
   prywatnym repozytorium. Zakres odtworzono z `docs/DATABASE.md` §598,
   `docs/infra/MONITORING_BLEDOW.md` i komentarzy w kodzie.

Żadna liczba w §1a i §1b nie jest wyliczona z konfiguracji: 1a to odczyty
z Railway, 1b to odczyty z `pg_stat_activity` przy działających procesach.
Budżety w §3, w tym **16**, oraz wolne miejsca i procenty są **obliczone**; 497 to historyczny limit dostępnych miejsc przyjęty z odczytu z 17.09, nie ponowny pomiar.

## 6. Korekta terminologii — 20 września 2026

Weryfikacja na `4c811cc7bff365fb8f86d87eabac93b7738a45cd`:
**16 jest obliczonym budżetem, nie zmierzonym szczytem produkcji**.
Dla przyjętej topologii `R = Q = S = 1` wzór wynosi
`2 × (4R + Q + S) + 1 migracja + 3 zapasu = 16`.
Warianty z §3 dają odpowiednio 24, 18 i 26. To rachunek oparty na
historycznych danych z §1, nie odczyt dzisiejszej topologii Railway.

Historia rozstrzyga kierunek poprawki. Commit
`3656a1dd0f9918fcb7cd05231fa3883b19dd2d5a` z 17.09 wprowadził wartość 16
w konfiguracji z jawnym komentarzem „Liczba policzona, nie zmierzona”.
`StanPolaczenBazy::budzet()` odczytuje konfigurację; pomiar bieżących
backendów pochodzi osobno z `pg_stat_activity`. `BudzetPolaczen` zapisuje
obie wielkości do kanału `pomiary`: pole `budzet_szczytowy` nie jest
maksimum zaobserwowanym w czasie, nawet gdy występuje w dzienniku pomiarów.

Commit `0c96de71dfe62e6f931e4f01b17cd4b8fdda411d` z 19.09 dodał ten
raport i poprawił kanał logowania, jawnie pozostawiając progi bez zmian.
Jego dowód `evidence/polaczenia598/pomiar-lokalny-2026-09-19.txt`
[pomiar cudzy: artefakt tego commita] zawiera lokalne odczyty 0, 1, 5 i 6,
a nie produkcyjny szczyt 16. §5 wyklucza pomiar szczytu wdrożeniowego.
W przejrzanej historii i artefaktach nie znaleziono dowodu produkcyjnego
szczytu 16; w tej sesji nie pobierano nowych logów Railway.

To korekta błędu redakcyjnego §4 oraz wyjątku dla 16 w końcówce §5,
nie odwrócenie decyzji. Skorygowano też rachunek zapasu: 50 / 26 to około
1,92, nie dokładnie 2. Kod, budżet i progi pozostają bez zmian.
Ograniczenia §5 opisują sesję z 19.09; treść #598 odczytano już 20.09
przez `gh issue view`. Zgłoszenie pozostaje otwarte i nadal wymaga pomiaru
produkcyjnego szczytu. Korekta tego dokumentu nie zamyka tej części pracy.
