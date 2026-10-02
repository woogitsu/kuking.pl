# Budżet połączeń PostgreSQL (issue #598)

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

## Budżet połączeń PostgreSQL — issue #598

**Pomiar: 17 września 2026.** Ten rozdział rozdziela trzy rzeczy, które
w poprzednich notatkach się zlewały: **co zmierzono na produkcji**, **co
zmierzono lokalnie** i **co z tego policzono**. Liczba policzona nie ma prawa
udawać pomiaru, a pomiar ma prawo zaprzeczyć obliczeniu — i wtedy to
obliczenie jest do poprawki, nie pomiar.

### Dlaczego to nie jest zwykła metryka pojemności

Wyczerpanie `max_connections` jest awarią **skokową**. Dopóki zostaje jedno
wolne miejsce, wszystko wygląda normalnie i `/health` odpowiada „baza działa"
— bo właśnie to ostatnie miejsce zajął. Po jego zajęciu nie łączy się **nikt**,
łącznie z administratorem, który przyszedł to naprawić. Dlatego próg alarmowy
stoi daleko przed limitem, a nie tuż przed nim, i dlatego pomiar połączeń jest
osobny od `/health`.

### A. Zmierzone na produkcji

| Co | Wartość | Metoda i data |
|---|---|---|
| `max_connections` | 500 | odczyt z aktywnej instancji produkcyjnej, 17.09.2026 (issue #598, komentarz z 17.09) |
| `superuser_reserved_connections` | 3 | tamże |
| `reserved_connections` | 0 | tamże |
| miejsc dla aplikacji | **497** | 500 − 3 − 0 |
| topologia | jeden serwis, `startCommand: kuking-entrypoint all`, `numReplicas: 1` | panel Railway (API), 17.09.2026 ok. 21:50 |
| limit CPU kontenera | 2 vCPU | metryki Railway, okno 7 dni |
| limit pamięci kontenera | 1,0 GB (szczyt użycia 0,53 GB) | metryki Railway, okno 7 dni |
| **`num_threads` / `max_threads` FrankenPHP** | **4 / 4** | log startowy wdrożenia `fa8f012e`, 17.09.2026 19:58:17 UTC: `FrankenPHP started 🐘 php_version=8.4.25 num_threads=4 max_threads=4` |
| `GOMAXPROCS` | 2 | ten sam log: `maxprocs: Updating GOMAXPROCS=2: determined from CPU quota` |
| `FRANKENPHP_CONFIG`, `GOMAXPROCS` jako zmienne | nie ustawione | lista zmiennych usługi, 17.09.2026 |

**To `max_threads` jest twardym sufitem równoległości HTTP**, nie liczba
rdzeni hosta i nie liczba osób online. Do 17.09.2026 ta liczba była w #598
otwartym pytaniem („efektywnej liczby wątków jeszcze nie zmierzono");
teraz jest odczytana z logu startowego działającego procesu.

### B. Zmierzone lokalnie (PostgreSQL 18.6, `127.0.0.1:55439`, baza `kuking_598_pomiar`)

Próbnik: `pg_stat_activity`, tylko `backend_type = 'client backend'`.
Procesy wewnętrzne serwera (autovacuum launcher, walwriter, checkpointer)
nie zajmują miejsc z puli `max_connections` i celowo nie są liczone.

| Co uruchomiono | Szczyt backendów | Uwaga |
|---|---|---|
| 6 równoległych cykli żądania | **6** | dokładnie tyle, ile procesów — sesje, cache i kolejka na bazie **dzielą jedno połączenie**, nie otwierają własnych |
| pojedyncze żądanie HTTP (24 żądania po kolei) | **1**, po zakończeniu **0** | połączenie jest zwalniane po żądaniu, nic nie zostaje |
| `queue:work` (jeden proces, jak w entrypoincie) | **1**, trwale | |
| `schedule:run` (jeden przebieg) | **1**, chwilowo | próbnik co 100 ms |
| `migrate --force` (jak `preDeployCommand`) | **1**, chwilowo | próbnik co 100 ms |

**Kontrola metody:** ten sam próbnik przy sześciu równoległych procesach
pokazał 6, a nie 1 — czyli pomiar „1 przy pojedynczym żądaniu" jest
własnością modelu wykonania, a nie zepsutego licznika.

Ustawienia lokalne odpowiadały produkcyjnym w tym, co dotyczy połączeń:
`CACHE_STORE=database`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`.

### C. Policzone z A i B

```text
szczyt zwykły (dziś, APP_ROLE=all, 1 replika)
    web (max_threads)                        4
    worker kolejki                           1
    harmonogram (co minutę, chwilowo)        1
                                          ----
                                             6

szczyt wdrożeniowy (stary i nowy kontener obok siebie)
    stary kontener                           6
    nowy kontener                            6
    preDeployCommand: migrate                1
                                          ----
                                            13

zapas na administrację, CLI i diagnostykę   +3
                                          ----
BUDŻET SZCZYTOWY dzisiejszej topologii      16   z 497 dostępnych miejsc (3,2%)
```

Po rozdzieleniu ról z `#595` (`web` / `worker` / `scheduler`, po jednej
replice) liczba jest praktycznie ta sama: 4 + 1 + 1 = 6 w spoczynku,
a wdrożenie z nakładaniem daje 12 + 1 = **13**.

**Koszt każdej dodatkowej repliki `web`:** +4 w spoczynku, +8 w oknie
wdrożenia. Przy 497 miejscach, jednym workerze i jednym schedulerze wzór
wdrożeniowy to `8R + 8`: mieści się maksymalnie 61 replik web (496 miejsc),
ale to wyczerpuje pulę i **nie jest bezpiecznym limitem skalowania**.
Poniżej progu ostrzegawczego 50 mieści się 5 replik (48 miejsc).
Wcześniejsze „ponad sto replik” pomijało nakładanie wdrożeń.
Własny pomiar z 20.09.2026, ograniczenia tego wyliczenia i wariant z osobnym
workerem media: [odbiór lokalny #598/#599](../infra/MONITORING_ODBIOR_2026_09_20.md).

### D. Progi alarmowe i skąd się wzięły

| Próg | Wartość | Czego pilnuje |
|---|---|---|
| ostrzegawczy | **50** | ponad trzykrotność policzonego szczytu (16). Przy poprawnej topologii nie da się tego osiągnąć, więc przekroczenie znaczy **wyciek połączeń albo procesy, o których nikt nie wie**. To sygnał diagnostyczny, nie awaryjny. |
| krytyczny | **125** | jedna czwarta z 497 dostępnych miejsc. Zostawia trzy czwarte puli na reakcję. Nie „90% i alarm" — przy awarii skokowej alarm przy 90% przychodzi wtedy, gdy nie ma już czasu na nic. |

Oba progi są w `config/kuking.php` (`kuking.polaczenia.*`) i dają się
nadpisać zmiennymi środowiskowymi. Próg krytyczny jest dodatkowo ograniczony
do liczby faktycznie dostępnych miejsc — na małym klastrze deweloperskim
wartość 125 stałaby powyżej twardego limitu i nie zapaliłaby się nigdy.

Mierzy i alarmuje `php artisan kuking:budzet-polaczen` (harmonogram: co
godzinę, minuta 25). Kanał jest ten sam, co przy błędach 500 i czujce kopii —
`blad_webhook` (D-041). Powtórzenia są ograniczone do jednej wiadomości na
`kuking.polaczenia.cisza_godzin` (domyślnie 6 h) przy **niezmienionym** stanie;
eskalacja `ostrzezenie → krytyczny` dzwoni natychmiast, a powrót do normy daje
dokładnie jedną wiadomość odwołującą.

### E. Czego ten rozdział NIE dowodzi

- **Że alarm dociera.** Kanał `blad_webhook` włącza zmienna
  `LOG_BLAD_WEBHOOK_URL`, a tej zmiennej **nie ma dziś w usłudze
  produkcyjnej** (odczyt listy zmiennych, 17.09.2026). Mechanizm jest
  sprawdzony lokalnie na atrapie odbiornika; dopóki zmienna nie istnieje,
  na produkcji nie dzwoni nic — patrz issue #599.
- **Że produkcja ma dziś zapas.** Zapas produkcji jest stanem produkcji
  i mierzy go ta sama komenda uruchomiona **na produkcji**. Pojedyncza próbka
  z 17.09 (1 połączenie bezczynne, 1 aktywne) nie jest ani poziomem typowym,
  ani historycznym maksimum: nie ma jeszcze szeregu czasowego ani pomiaru
  szczytu w oknie wdrożenia.
- **Ilu użytkowników serwis obsłuży.** Liczba osób online nie przekłada się
  1:1 na połączenia. Decyzja o PgBouncerze (#600) ma wynikać z tych liczb,
  a nie z progu „ilu jest online" — i na dziś te liczby jej **nie uzasadniają**.

### F. Skąd weźmie się szereg czasowy — i co go może zablokować

**Zmierzone 17.09.2026 23:25:20 UTC:** harmonogram produkcji uruchomił
`kuking:budzet-polaczen` i zameldował „DONE" w 21 ms — po czym zmierzone
liczby przepadły. `Schedule::call()` woła komendę przez `Artisan::call()`,
a to przechwytuje wyjście konsoli do bufora, który kończy się razem
z przebiegiem. Czujka mierzyła co godzinę i za każdym razem zapominała, więc
definicji gotowości „znany peak active connections" nie dało się spełnić
**mimo działającego kodu**.

Od 18.09.2026 obie czujki zapisują jedną linię pomiaru do dziennika serwera:

```text
kuking:budzet-polaczen  {"stan":…,"zajete_serwer":…,"zajete_baza":…,"aktywne":…,
                         "bezczynne":…,"w_transakcji":…,"dostepne":…,
                         "max_connections":…,"budzet_szczytowy":…,"prog_ostrzegawczy":…}
kuking:sprawdz-kolejke  {"stan":…,"oczekujace":…,"zaleglosc_sekundy":…,"zawieszone":…,
                         "nieudane_w_oknie":…,"nieudane_razem":…,…}
```

W sesji pomiarowej nie było dostępu do produkcyjnego Postgresa z zewnątrz
ani zalogowanego CLI. W tej implementacji historia dziennika Railway jest
magazynem szeregu czasowego; nie zakładamy tabeli ani zewnętrznej bazy metryk.
Pojedynczy odczyt w konsoli produkcji nie zastępuje historii pomiarów.

**Co go blokowało — ustalone 19.09.2026, blokada była realna.** Wpisy idą
poziomem `info`, bo zdrowy pomiar nie jest ostrzeżeniem. Szły jednak kanałem
`stderr`, a ten bierze poziom z `env('LOG_LEVEL', 'debug')` — i
`.railway/railway.ts` ustawia `LOG_LEVEL: isProduction ? "warning" : "debug"`.
Na produkcji stało więc `warning`, `info` jest niżej i **Monolog odrzucał
pomiar, zanim cokolwiek dotarło do strumienia**. Zmierzone: dwa przebiegi
harmonogramu 19.09.2026 (11:25:02 i 12:25:11 UTC) zameldowały „DONE" i nie
zostawiły ani jednej linii z liczbami, przy obecnych w tej samej sekundzie
innych wpisach poziomu `info`.

Uwaga „trzeba to sprawdzić w panelu", która stała tu wcześniej, była złym
tropem: wartość pochodzi z manifestu wdrożenia w repozytorium, więc zmiana
w panelu i tak rozjechałaby się z `.railway/railway.ts`.

**Poprawka:** pomiar idzie teraz osobnym kanałem `pomiary`
(`config/logging.php`) z poziomem `info` wpisanym **na sztywno** — tak samo jak
`blad_webhook` ma na sztywno `error`. `LOG_LEVEL` produkcji zostaje bez zmian,
bo jego obniżenie wpuściłoby do dziennika każde `info` w serwisie, żeby
przepchnąć dwie linie na godzinę. Pełny opis: [`docs/infra/WERYFIKACJA_BUDZETU_POLACZEN_598.md`](../infra/WERYFIKACJA_BUDZETU_POLACZEN_598.md) §2.

**Czego to nadal nie dowodzi:** że szereg powstanie. Dowiedzie tego dopiero
odczyt dziennika Railway po najbliższym wdrożeniu — linia
`kuking:budzet-polaczen {...}` o minucie :25.

Czego w tych liniach nie ma: nazwy bazy, hosta, użytkownika, treści zapytań,
`payload` ani `exception`. Dziennik produkcyjny czyta także dostawca hostingu
— to ta sama zasada, którą stosujemy do webhooka (audyt A6-01). Pilnuje tego
`PomiarCzujekTrafiaDoDziennikaTest`.

### G. Jak zmierzyć szczyt — kroki dla właściciela (dopisane 25.09.2026)

Definicja gotowości #598 wymaga **zmierzonego** szczytu, a §C daje tylko
policzony (16 z zapasem, 13 w oknie wdrożenia). Czujka godzinna go nie
złapie: próbkuje raz na godzinę o :25, a okno wdrożenia trwa minutę–dwie.
Dlatego są trzy narzędzia — wszystkie tylko do odczytu, żadne nie dzwoni
na webhook i żadne nie zmienia konfiguracji:

| Narzędzie | Co mierzy | Gdzie chodzi |
|---|---|---|
| `scripts/szczyt-polaczen-z-dziennika.php` | min / mediana / **max** z szeregu czujki godzinnej i z okien `--probki` | lokalnie, na pliku wyeksportowanym z dziennika Railway |
| `php artisan kuking:budzet-polaczen --probki=N --odstep=S` | szczyt w oknie N próbek co S s (max 3600 próbek, odstęp 1–60 s); jedna linia `kuking:budzet-polaczen:szczyt {...}` do kanału `pomiary` na końcu | konsola kontenera aplikacji |
| `scripts/szczyt-polaczen.sql` + `\watch 1` | to samo zapytanie co czujka, co sekundę | psql w usłudze Postgres — **przeżywa wdrożenie aplikacji** |

**Krok 1 — szczyt zwykłego ruchu (ok. 10 minut, raz w tygodniu przez miesiąc).**
Railway → serwis aplikacji → *Logs*, filtr `kuking:budzet-polaczen`, okno
możliwie długie (retencja zależy od planu). Skopiuj wynik do pliku
i uruchom lokalnie:

```bash
php scripts/szczyt-polaczen-z-dziennika.php dziennik-598.txt
```

Kod wyjścia **2** znaczy „w pliku nie ma ani jednej linii pomiaru" —
czyli szeregu nie ma (np. wrócił problem z §F), a **nie** „zapas jest".
Zapisz tu datę, liczbę pomiarów, MAX i chwilę MAX.

**Krok 2 — szczyt w oknie wdrożenia (ok. 10 minut, przy zwykłym wdrożeniu).**
Wariant preferowany, bo konsola nie ginie razem ze starym kontenerem:
otwórz psql w usłudze **Postgres** (nie w aplikacji), wklej zawartość
`scripts/szczyt-polaczen.sql`, w nowej linii wpisz `\watch 1`, a potem
uruchom zwykłe wdrożenie aplikacji. Po jego zakończeniu i minucie spokoju
przerwij `Ctrl+C` i zanotuj największą wartość `zajete_serwer`.
Nie wystawiaj w tym celu publicznego portu bazy (proxy TCP) — jeśli
psql w usłudze Postgres nie jest dostępny, użyj wariantu z konsolą aplikacji.

Wariant z konsolą aplikacji: `php artisan kuking:budzet-polaczen --probki=300`
(5 minut). Uruchomiony w kontenerze, który wdrożenie zastępuje, zostanie
przerwany razem z nim — wtedy linia podsumowania do dziennika nie powstanie,
ale wiersze `próbka i/N` wypisane w terminalu zostają i to je się notuje.

**Krok 3 — decyzja.** Zmierzony MAX porównaj z §D: poniżej 50 — progi
zostają, #600 nie ma liczb za PgBouncerem; powyżej 50 w spokojnym ruchu —
szukać wycieku albo nieznanych procesów, zanim doda się replikę. Ten sam
MAX wpisz w #598 z datą i SHA wdrożenia.

**Czego ta sekcja nie dowodzi.** Dostępność psql w usłudze Postgres
na Railway i dokładna nazwa przycisku konsoli **nie były sprawdzone**
w sesji, która to pisała — tylko zapytanie (test na PostgreSQL 18)
i oba narzędzia PHP (`ProbkowanieSzczytuPolaczenTest`,
`SzczytPolaczenZDziennikaTest`). Wycofanie: odwrócenie commita; nie ma
migracji ani zmiennych środowiskowych.
