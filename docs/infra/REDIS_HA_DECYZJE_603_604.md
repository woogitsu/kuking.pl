# Kiedy Redis, kiedy PostgreSQL HA — #603 / #604

20 września 2026. Gałąź `gpt/redis-ha`, kod aplikacji niezmieniony względem
`4c811cc7bff365fb8f86d87eabac93b7738a45cd`. Status: **materiał do decyzji,
bez wdrożenia i bez podstaw do zamknięcia obu zgłoszeń**.

## Wniosek dla właściciela

**Na razie zostawić kolejkę i cache w PostgreSQL.** Obecne odczyty zasobów
nie pokazują presji, a lokalna próba nie wykazała powtarzalnej istotnej
szkody od ruchu infrastrukturalnego. Nie oznacza to, że zmierzono pełny
produkcyjny udział SQL: **tego pomiaru nadal brakuje**. Nie nazywamy braku
telemetrii dowodem zerowego kosztu.

**Railway HA ma automatyczne przełączenie**, nie tylko replikę do ręcznej
promocji. Dostęp do odczytów na standby istnieje. Jego włączenie wymaga
jednak osobnej decyzji o spójności i zmian w aplikacji. Model kosztu samego
HA daje **43–90 zł netto/mies.** przy obecnym odczycie dysku oraz
**45–92 zł przy 10× większym dysku i niezmienionym zużyciu CPU/RAM**.
To scenariusze budżetowe, nie pomiar działającego klastra ani oferta.
Wariant większej pamięci i CPU daje **160 zł/mies.**; pełne założenia poniżej.

Redis w dwóch jawnych wariantach pojedynczej instancji: **11–43 zł
netto/mies.** Zwiększa liczbę zależności krytycznych. Awaria cache'u dotknie
także limitów logowania, budżetu poczty i harmonogramu, nie tylko szybkości
wyświetlania strony.

## 1. Co odczytałem sam, a co przejąłem

### Produkcja — odczyt własny przez Railway, bez SQL i zmian

Projekt `ideal-exploration`, środowisko `production`. Wyniki z API zapisano
w [katalogu dowodów](evidence/redis-ha/). Odczyt zasobów: 168 godzin,
próbka co 300 s, po 2017 próbek na metrykę. Szczyty krótsze od interwału
mogą pozostać niewidoczne; średnie nie wyznaczają maksymalnej przepustowości.

| Sygnał | Wynik | Dowód / ograniczenie |
| --- | ---: | --- |
| CPU PostgreSQL: średnia / maks. próbka | 0,001991 / 0,012728 vCPU | `pgmetrics.json`; nie znamy udziału poszczególnych rodzajów SQL |
| RAM PostgreSQL: średnia / maks. / ostatnia | 0,08513 / 0,09553 / 0,08823 GB | metryka usługi |
| Dysk: średnia / ostatnia | 0,12501 / **0,126713856 GB** | metryka `DISK_USAGE_GB`, **nie `pg_database_size()`** |
| Topologia aplikacji | 1 replika, `kuking-entrypoint all` | `webconfig.json`; rozdzielenie #595 nie jest wdrożone |
| PostgreSQL | obraz `postgres-ssl:18`, 1 replika, Amsterdam | `pgconfig.json`; brak własnego start command |
| Dostępność konwersji HA | potwierdzona w konfiguracji usługi | domyślnie 2 standby, 3 etcd, 3 instancje HAProxy |
| Połączenia o 17:25 i 18:25 UTC | 2 zajęte: 1 active, 1 idle; limit 500, dostępne 497 | `connectionlogs.json`; active obejmuje pomiar, **to nie peak** |
| Kolejka, 5 próbek 17:30–18:30 UTC | 0 gotowych, 0 zaległości, 0 zawieszonych; 0 nowych failed, 4 historyczne | `queuelogs.json`; nie liczy zadań odłożonych na przyszłość ani rozmiaru tabeli |

Istnieje więc dziś liczbowy ślad czujek w logu, choć starsze raporty go
nie potwierdzały. Nie odczytywałem wartości sekretów. Port PostgreSQL
używany w moich próbach to wyłącznie `127.0.0.1:55439`.

**Granica obecnego pomiaru produkcji:** brak calls/s i DB time według tabel,
brak p95/p99 SQL, IOPS, czasu GC, lock waits i rozmiaru `jobs`. Metryka dysku
usługi jest jedyną aktualną wskazówką rozmiaru do kalkulacji; nie pozwala
oddzielić bazy Kuking od WAL, metadanych i pozostałych plików. Nie podstawiam
lokalnej bazy testowej pod produkcję.
Dostępne odczyty konektora dostarczają agregatów zasobów i logów czujek,
nie statystyk klas SQL. Zgodnie z granicą zadania nie wykonywano połączeń
PostgreSQL poza lokalnym portem 55439 ani nie dodawano instrumentacji
do działającego serwisu. Uzupełnienie tej luki wymaga osobnego pomiaru
produkcyjnych agregatów według planu z §3.

### Zależności — przeczytane przed liczeniem

- **[pomiar cudzy: `gpt/monitoring`, commit
  `c3c51041732cb5a5939e2934911eb8fbb4edcaa2`,
  `docs/infra/MONITORING_ODBIOR_2026_09_20.md`, §1–3]**:
  lokalnie pojedynczy slot HTTP / worker / scheduler zajmuje po 1 połączeniu;
  kontrola 6 procesów dała 6. Produkcyjny `max_threads=4` pochodzi z #598.
  Model wdrożeniowy `2 × (4R + Q + S) + 4` daje 16 / 24 / 18 / 26
  dla podstawy / dodatkowego web / dodatkowego media / obu zmian.
  To obliczenia budżetu, nie zmierzone piki.
- **[pomiar cudzy: `gpt/rozbicie-uslug`, commit
  `6fc9621738f2e7b9b7baab3bef99e6aa76365d72`,
  `docs/infra/ROZDZIELENIE_ROL_595_600_POMIARY.md` oraz
  `ROZDZIELENIE_ROL_595_600.md`]**: przygotowano podział ról i ochronę
  `onOneServer()`, bez wdrożenia. Nie naliczam ich jako istniejących usług.

Raporty są dostępne przez `git show <SHA>:<ścieżka>`; nie kopiowałem zmian
tych gałęzi do własnej. Wszystkie cztery zgłoszenia przeczytałem przez
`gh issue view NUMER --repo woogitsu/kuking.pl`.

## 2. Własny pomiar lokalny na niezmienionej aplikacji

Przed dodaniem przyrządu: `UmowaKolejkiTest` **4 PASS, 8 asercji**.
Przyrząd leży w `scripts/infra603/`, poza kodem uruchamianym przez serwis.
PHP 8.4.24, osobna baza `kuking_flota_gpt-redis-ha`, użytkownik `kuking`,
port 55439. Schemat rzeczywisty, sesje/cache/kolejka `database`.
Dane syntetyczne: 1 konto, 200 wpisów, 40 przepisów; dodatkowo 6 JPEG 3,84 Mpx
w drugim przebiegu. Brak zewnętrznej poczty, R2 i webhooków.

`DB::listen` zbiera liczby i czas udanych SQL po stronie klienta PHP.
Nie zbiera tekstu SQL ani bindings. Ten czas obejmuje komunikację i oczekiwanie,
**nie jest czasem CPU PostgreSQL**. Nie obejmuje osobno BEGIN/COMMIT,
autovacuum, WAL/fsync ani nieudanych prób SQL. Rozdziela `sessions`, `cache`,
`cache_locks`, `jobs`/`failed_jobs`/`job_batches`; reszta w tych scenariuszach
to zapytania do danych aplikacji. Nie jest to uniwersalny klasyfikator dla
dowolnych joinów, CTE i zapytań administracyjnych.

Każde żądanie używa nowego procesu i pełnego kernela HTTP wraz z terminate;
20 żądań na trasę po rozgrzewce, wszystkie 200 i niepuste HTML. To lokalny
kernel, nie test FrankenPHP ani sieci. Nowy gość na każde żądanie: nie jest
to rozkład ruchu realnych zalogowanych osób. Losowe GC sesji wyłączone
wyłącznie w przyrządzie i zmierzone osobno.

### Ile pracy przypada na co

Pierwszy przebieg: [pełne próbki](evidence/redis-ha/measurement-first.json).
Drugi: [pełne próbki i kontrola dodatnia](evidence/redis-ha/measurement.json).
[Podsumowanie obu](evidence/redis-ha/summary.json) zachowuje tabelę czasów.

| 60 żądań: odkryj / szukaj / przepis po 20 | Liczba SQL | Czas SQL, przebieg 1 | Czas SQL, przebieg 2 |
| --- | ---: | ---: | ---: |
| Dane aplikacji | 800 | 630,35 ms | 1005,42 ms |
| Sesje | 180 | 390,86 ms | 834,72 ms |
| Cache | 180 | 68,74 ms | 101,94 ms |
| Kolejka i cache_locks w tych odsłonach | 0 | 0 | 0 |

Cache to **15,5% liczby SQL**, lecz tylko **6,3% / 5,2% mierzonego czasu SQL**.
Sesje to **35,9% / 43,0% czasu**. Przeniesienie samej kolejki i cache'u nie
usuwa kosztu sesji. Te udziały dotyczą dokładnie powyższej próbki, nie dnia
produkcji. Nie łączę sztuczną wagą workerów i odsłon w jeden „udział portalu”.

| Osobny scenariusz | Wynik |
| --- | --- |
| Bezczynny prawdziwy worker, 4 kolejki, `--sleep=1`, ok. 10 s | 40 SQL jobs + 31 cache; 25,37 ms w próbie 1, 39,89 ms w próbie 2; ok. **7 SQL/s**, nie 7 połączeń |
| 200 cykli cache put/get + lock acquire/release | 400 SQL cache, 405 / 407 SQL locks; dodatkowe odczyty/sprzątanie zależą od sterownika; to pomiar prymitywów |
| 6 rzeczywistych ProcessUploadedImage | 6 ready, 0 failed, 1,97 s; SQL: dane 24 / 57,91 ms, queue 25 / 7,86 ms, cache 22 / 4,95 ms; przygotowanie fixture poza pomiarem |
| GC 2000 przeterminowanych sesji | 1 DELETE, 10,38 ms, sprawdzono 2000 usunięć |
| Odczyt i usunięcie 2000 przeterminowanych kluczy cache | 2 SQL, 18,31 ms; sprawdzono brak tych kluczy po próbie |

DatabaseStore usuwa wygasłe klucze przy odczycie. Ta próba nie udaje
nieistniejącego okresowego `cache:prune`. Nie zmierzono dużego prune failed
jobs/batches, autovacuum ani wielodniowego bloatu.

### Tabela jobs — konkretny punkt do obserwowania

Każdy rozmiar: 3 serie po 200 prawdziwych `DatabaseQueue::pop('high')`.
Wszystkie rekordy odłożone o dobę, brak gotowego zadania, payload 1024 B,
`ANALYZE` po załadowaniu. To nie test odbioru 100 tys. gotowych zadań.

| Wiersze jobs | Rozmiar z indeksami | p95 SQL: próba 1 / 2 | p99 SQL: próba 1 / 2 |
| --- | ---: | ---: | ---: |
| 0 | 24 576 B | 0,33 / 0,84 ms | 0,39 / 2,86 ms |
| 1000 | 1 261 568 B | 0,37 / 0,95 ms | 0,46 / 2,52 ms |
| 10 000 | 12 066 816 B | 1,18 / 6,60 ms | 1,63 / 9,74 ms |
| 100 000 | 120 012 800 B | **14,75 / 56,10 ms** | **17,86 / 69,11 ms** |

Proponuję **10 tys. wierszy jako próg przeglądu**, a **100 tys. jako pilną
diagnostykę** czasu pop, planu zapytania i porządkowania kolejki. Nie jest
to uniwersalny limit Postgresa ani automatyczna decyzja o Redisie. Ready,
reserved i delayed mierzyć oddzielnie; sama liczba wierszy nie mówi o lag.

### QPS i przyczynowość — czego eksperyment nie dowiódł

3 pary A/B: ten sam SELECT 20 publicznych wpisów, 1000 wykonań, najpierw
sam, potem obok czterech procesów generujących cache/lock/polling przez 12 s.
Klucze blokad różne per proces: **to nie test kontencji jednego klucza**.

| Przebieg | Łączny QPS infrastruktury w 3 próbach | p95 biznesowego SQL bez → z infrastrukturą |
| --- | --- | --- |
| 1 | 5028 / 4745 / 5078 | 0,37→0,37; 0,35→0,37; 0,45→0,42 ms |
| 2 | 1386 / 2028 / 1836 | 0,48→0,71; 0,96→0,76; 0,71→0,85 ms |

Host współdzielony: load average na początku/końcu pierwszego przebiegu
9,71/10,09, drugiego 13,46/24,06. Nie odejmowano cudzego obciążenia.
Stała kolejność A/B i krótka próba także ograniczają wnioskowanie.
**Nie wyznaczono wiarygodnego progu nasycenia QPS produkcji.** W szczególności
5 tys. krótkich SQL/s lokalnie nie znaczy 5 tys. żądań HTTP/s na Railway.
Druga próba pokazuje, jak mocno otoczenie zmienia wynik; nie wybieramy
wygodniejszej liczby i nie przenosimy jej do produkcyjnego alarmu.

## 3. Próg decyzji #603 — propozycja do zatwierdzenia

Kolejność: **obserwacja → diagnoza przyczyny → porównanie wariantów → decyzja**.
Nie zamieniono poniższych wartości w testy produktu ani konfigurację.

| Sygnał | Proponowany próg i okno | Co robimy |
| --- | --- | --- |
| Udział queue + cache + locks w DB time | ≥20% w 3 kolejnych oknach 15 min, przynajmniej 1000 SQL/okno | Zbieramy próbę reprezentatywną; same 20% bez szkody użytkownika nie wystarcza |
| Pogorszenie biznesowego SQL | p95 lub p99 ≥20% ponad dopasowaną bazę odniesienia, jednocześnie naruszone uzgodnione SLO | Sprawdzamy korelację z infrastrukturą, plany i czekanie na blokady |
| Czas stron | kandydat z #598: p95 >1 s / p99 >2 s przez 10 min, ≥100 żądań | Właściciel zatwierdza SLO; nie liczymy startu PHP z przyrządu jako tej metryki |
| jobs | 10 tys. przegląd / 100 tys. pilna diagnoza; lub p95 pop >10 ms przez 15 min | Najpierw delayed/ready/reserved, indeks/plan, bloat i autovacuum |
| Wiek gotowego zadania | obecne 600 s | Sprawdzić żywego workera i koszt media; Redis nie przyspiesza dekodowania zdjęć |
| Połączenia | obecne ostrzeżenie 50, krytyczne 125; planowany budżet <50 | Zgodnie z #598: procesy/idle/wycieki, ewentualnie pooling; nie jest to samodzielny trigger Redisa |
| Blokady | p95 czekania >100 ms przez 15 min albo deadlock >0 | Najpierw ustalić rodzaj blokady i skrócić sekcję krytyczną; transakcyjne blokady domenowe zostają w PG |
| CPU / I/O | CPU >70% faktycznego limitu przez 15 min razem z pogorszeniem SQL; I/O wait narasta | IOPS mierzyć z hosta/metryk, nie utożsamiać QPS z IOPS |

**Warunek zgody na migrację:** na izolowanym środowisku o zasobach zbliżonych
do Railway, z mieszanym obciążeniem z #605, co najmniej 3 powtórzenia
pokazują poprawę p95/p99 biznesowego SQL o ≥20% po usunięciu badanego ruchu
infrastruktury; nie pogarszają błędów i trwałości. Dopiero wtedy porównujemy
koszt Redis/Valkey z optymalizacją PG. Liczby 20%, 100 ms i okna są
**propozycją operacyjną**, nie fizyczną granicą technologii.

Próg QPS ma wynikać z ostatniego stabilnego poziomu takiej próby: alarm
przy 70% zmierzonego poziomu, na którym pierwszy raz naruszono SLO,
przy tym samym miksie zapytań. Dziś ta wartość pozostaje **nieznana**.
Sztuczna stała „Redis od 1000 QPS” byłaby nieuczciwa.

### Jak uzupełnić brakujące pomiary produkcji

Przez 7 dni, w tym szczyt i wdrożenie, zbierać minutowe agregaty:
calls i czas dla sessions/cache/cache_locks/jobs/danych, histogram czasów
SQL biznesowych, liczbę błędów, ready/reserved/delayed per kolejka, wiek
najstarszego gotowego zadania, bytes i dead tuples tabel, lock waits,
deadlocks, CPU i I/O. Obecne czujki co godzinę / 15 min są za rzadkie.

Jeżeli `pg_stat_statements` już działa, użyć różnic calls/total_exec_time
między próbkami, odrzucić okno po resecie; sprawdzić kompletność klasyfikacji
i osobno wyłączyć sam próbnik. Nie resetować globalnych statystyk.
Agregaty `pg_stat_statements` **nie dostarczą p95/p99** — potrzebny histogram
z instrumentacji żądań/SQL bez treści, bindings, URL i identyfikatorów osób.
Jeśli rozszerzenia nie ma, jego uruchomienie oraz narzut instrumentacji
to osobne zadanie operacyjne. Tutaj ich nie włączano.

Nie wyłączać sesji, limiterów ani locków na produkcji w celu uzyskania
wariantu „bez infrastruktury”. Porównanie wykonywać na lokalnej kopii
z syntetycznymi danymi, a koszty różnych funkcji raportować oddzielnie.

## 4. Co zabierze awaria Redis/Valkey

Poniżej **wnioski z aktualnego kodu**, nie wykonany test awarii wdrożonego
Redisa. Zakładamy zakres z #603: najpierw queue, potem cache/locks, sesje
pozostają w PG. Odczyt `config/cache.php`, `config/queue.php`, `Dockerfile`,
`StanKolejki`, `DziennyBudzetListow`, `routes/console.php`, `NotifyUser`.

| Zakres przeniesienia | Skutek niedostępności / utraty stanu |
| --- | --- |
| Tylko queue | Dispatch nowych zadań może rzucać wyjątek; odbiór staje. Nowe zdjęcia nie osiągają `ready`, eksporty nie powstają, zadania poczty i czyszczenia CDN czekają. Odczyty danych PG mogą działać. Skutek zapisu przed nieudanym dispatch wymaga osobnego testu każdej ścieżki. |
| Cache domyślny | Także odczyty stron ze stopką/licznikami i limiterami mogą kończyć się błędem. Nie ma automatycznego przełączenia z wybranego `redis` na PG. Sama obecność definicji `failover` nie aktywuje jej. |
| Cache limits i budżet poczty | Liczniki logowania/2FA i dobowy budżet listów z D-076 przestają być dostępne. Utrata kluczy może odnowić limit, choć wysłane listy nie cofają się. `DziennyBudzetListow` łapie LockTimeoutException, a nie każdy wyjątek połączenia. |
| Locks harmonogramu | `withoutOverlapping` i przyszłe `onOneServer` z #595 zależą od wspólnego cache. Niedostępność może zatrzymać zadania; utrata blokady przy jeszcze działającym procesie może dopuścić duplikat. |
| Sesje zostawione w PG | Redis nie usuwa sesji i nie wylogowuje bezpośrednio, ale awaria cache nadal może uniemożliwić przejście żądania. |
| Powiadomienie „Ugotowałem” | `NotifyUser` zapisuje je synchronicznie w PostgreSQL, nie przez kolejkę Redis. Nie ma podstaw twierdzić, że pada razem z samą kolejką; całą ścieżkę może jednak zablokować middleware/cache. |
| Awaria w roli `all` | Nadzorca ma ograniczoną tolerancję szybkich śmierci procesów. Trzeba przetestować, czy awaria workera nie eskaluje do restartu wspólnego kontenera web. #595 zmniejsza tę wspólną zależność. |

**Nie wolno automatycznie ratować locków sterownikiem `array`/`file` ani
przełączać tylko części procesów na inną bazę locków.** Powstaną dwa
niezależne mechanizmy, a nie wspólna blokada. Transakcyjne
`pg_advisory_xact_lock` komentarzy/tagów i blokady wierszy nie przechodzą
do Redisa przez zmianę `CACHE_STORE` i powinny zostać w PostgreSQL.

Warunki przed ewentualnym wdrożeniem:

1. Zapewnić klienta Redis: obecny Dockerfile nie instaluje `phpredis`,
   a `composer.json` nie deklaruje Predis. Sama zmiana zmiennych nie wystarczy.
   Valkey wymaga potwierdzenia zgodności wybranego klienta/wersji.
2. Dostosować `retry_after`: database ma **960 s**, szablon Redis **90 s**,
   eksport ma timeout **900 s**. Obecny `UmowaKolejkiTest` bada database.
   Przełączenie bez zmiany i regresji grozi ponownym wydaniem trwającego joba.
3. Zmienić czujkę kolejki: `StanKolejki` czyta twardo `jobs`. Po migracji
   mogłaby pokazywać pustą, zdrową kolejkę, podczas gdy Redis ma zaległość.
   Alarm ma dochodzić także podczas awarii cache (zależność od #598/#599).
4. Oddzielić ulotny cache od kolejki/locków/limitów. Logiczny numer bazy
   Redis nie izoluje limitu RAM, eviction ani awarii procesu. Dla stanu
   krytycznego brak eviction, alarm RAM/dysku i świadomie wybrana trwałość.
5. Zatwierdzić RPO kolejki. AOF z fsync co sekundę może stracić ostatnią
   sekundę zapisów; snapshot RDB ma większe okno. HA nie zastępuje trwałości.
   Źródło: [Redis persistence](https://redis.io/docs/latest/operate/oss_and_stack/management/persistence/).
6. Próba odcięcia sieci, restartu, utraty klucza i pełnej pamięci na stagingu:
   brak podwójnego listu/eksportu, odzyskane pending media, zachowane formularze,
   czytelny błąd i działający alarm. Zweryfikować atomowość DB + enqueue:
   dwóch magazynów nie obejmuje jedna transakcja PostgreSQL.

Wycofanie przyszłej migracji: zatrzymać producentów, rozliczyć queued,
reserved i delayed, opróżnić lub jawnie przenieść zadania z identyfikacją
duplikatów, przełączyć wszystkich konsumentów i producentów razem.
Zachować starą usługę do rozliczenia. **Samo ustawienie `database` z powrotem
nie przeniesie zadań z Redisa.** Nie czyścić locków działających zadań.

## 5. HA: obietnica, skalowanie odczytu i cena

### Co oferuje Railway

[Oficjalna procedura konwersji](https://docs.railway.com/databases/postgresql-ha)
opisuje Patroni, etcd i HAProxy, automatyczną promocję standby i zerwanie
trwających połączeń podczas failover. Klient musi się ponownie połączyć;
transakcja nie przeżywa awarii. Konwersja wymaga oficjalnego obrazu
z przypiętą wersją 14–18 i braku własnego start command. Nasz odczyt
konfiguracji spełnia te dwa warunki. Domyślnie: primary + 2 standby,
3 etcd i 3 instancje proxy — w kalkulacji **9 instancji**, nie sam primary
z jedną kopią. Nie uruchamiano konwersji.

Konwersja przerywa połączenia i zmienia endpointy; referencje zmiennych
w projekcie są przepinane, literalne adresy wymagają ręcznej zmiany.
Powrót do standalone wymaga oryginalnego węzła jako lidera. Pozostawione
wolumeny nadal kosztują. To procedura z dokumentacji, **nie odebrany rollback
Kuking**. Nie zakładamy, że backup konwersji zastępuje sprawdzoną kopię #594.

### Odczyty i ograniczenia

Sprawdzono [kod szablonu Railway, SHA
3f3faee6f105f3355c2bc3c64110a3f7f795a527](https://github.com/railwayapp-templates/postgres-ha/tree/3f3faee6f105f3355c2bc3c64110a3f7f795a527).
`haproxy/src/template.rs` wystawia port **5433** dla standby, sprawdza
sondę Patroni pod ścieżką /replica i wybiera backend przez **leastconn** (README wspomina round-robin;
przyjmujemy kod, nie ten skrót). Zwykły endpoint 5432 idzie do primary.
Nie ma automatycznego rozpoznawania SELECT i rozdzielania SQL przez proxy.

`config/database.php` Kuking nie ma obecnie osobnych read/write hosts.
Włączenie HA samo nie zmniejszy obciążenia odczytami primary. Trzeba wskazać
bezpieczne zapytania; autoryzacja, blokady osób, prywatność, sesje, kolejka,
odczyt bezpośrednio po zapisie i transakcje powinny trafiać na primary.
Spóźniona replika nie może ponownie odsłonić ukrytej treści. Samo Laravel
`sticky` nie gwarantuje świeżości między kolejnymi żądaniami.

Kod `postgres-patroni/src/patroni/config.rs` domyślnie ma
`PATRONI_SYNCHRONOUS_MODE=false`, TTL lidera 45 s, pętlę 10 s i retry 17 s.
To **nie jest zmierzona konfiguracja przyszłego klastra w panelu**. Dlatego
marketingowe „<10 sekund” z README nie staje się tutaj RTO. Asynchroniczna
replikacja może stracić ostatnie zatwierdzone zapisy; wybór synchronizacji
zmienia kompromis między dostępnością, trwałością i opóźnieniem.
[Patroni: tryby replikacji](https://patroni.readthedocs.io/en/latest/replication_modes.html).

HA nie zwiększa przepustowości zapisów pojedynczego primary. Nie dowiedziono
rozmieszczenia w niezależnych domenach awarii ani odporności na utratę regionu.
Dostępność całej witryny nadal ogranicza pojedynczy web `all`, R2 i reszta
ścieżki. HA nie cofa omyłkowego DELETE, który replikuje się na standby.

### Koszt miesięczny w PLN

Kurs **1 USD = 3,7998 PLN**, tabela NBP **182/A/NBP/2026**, 18.09.2026,
odczyt 20.09: [NBP](https://api.nbp.pl/api/exchangerates/rates/a/usd/2026-09-18/?format=json).
To przeliczenie, bez podatku i marży przewalutowania; nie jest fakturą.

[Cennik Railway](https://docs.railway.com/pricing/plans): RAM 10 USD/GB/mies.,
CPU 20 USD/vCPU/mies., wolumen 0,15 USD/GB/mies., egress 0,05 USD/GB.
Liczymy rzeczywiste średnie zużycie, nie limity kontenera. Minimum Hobby
5 USD = 19,00 zł, Pro 20 USD = 76,00 zł; obejmują odpowiednio 5/20 USD
zużycia całego workspace. Nie dodawać minimum drugi raz do każdej usługi.
Nie ustalono aktualnego planu ani pozostałego kredytu konta.

Model: `PLN = 3,7998 × (10M + 20C + 0,15V + 0,05E)`, gdzie M/C to
średni RAM/CPU łącznie wszystkich węzłów, V użyty dysk, E płatny transfer.
[Wolumeny są płatne za wykorzystanie](https://docs.railway.com/volumes/reference),
w tym metadane. Sam rozmiar danych nie wyznacza RAM/CPU.

| Założenie NA JEDNĄ instancję | Wariant mały | Wariant większy |
| --- | --- | --- |
| PostgreSQL, ×3 | 0,25 GB RAM; 0,02 vCPU; D dysku | 0,5 GB RAM; 0,05 vCPU; D dysku |
| etcd, ×3 | 0,05 GB RAM; 0,005 vCPU; 0,01 GB dysku | 0,1 GB RAM; 0,01 vCPU; 0,05 GB dysku |
| HAProxy, ×3 | 0,025 GB RAM; 0,002 vCPU | 0,05 GB RAM; 0,005 vCPU |

**Te wielkości HA są założeniami do budżetu, nie zmierzonym minimum ani
gwarancją zmieszczenia się w nim.** Zweryfikować minimum 24 h na stagingu.
Podane koszty nie obejmują dodatkowego WAL ponad D, backupów, PITR,
dodatkowych wolumenów po rollbacku i publicznego egress.

| Scenariusz | Zużycie USD/mies. | Zużycie PLN netto/mies. |
| --- | ---: | ---: |
| Dzisiejszy pojedynczy PG: projekcja średnich z 7 dni | 0,91 | **3,46** |
| HA: D = 0,126713856 GB, metryka dysku usługi | 11,43–23,48 | **43,44–89,22** |
| HA: D ×10 = 1,26713856 GB; RAM/CPU jak wyżej | 11,94–23,99 | **45,39–91,17** |
| HA: D ×10 i większe obciążenie; każdy PG 1 GB/0,1 vCPU, etcd/proxy wariant większy | 41,99 | **159,56** |
| Redis pojedynczy: 0,25 GB RAM, 0,01 CPU, 1 GB dysku | 2,85 | **10,83** |
| Redis pojedynczy: 1 GB RAM, 0,05 CPU, 2 GB dysku | 11,30 | **42,94** |

HA zastępuje pojedynczy PG: modelowany przyrost przy D wynosi około
**39,98–85,76 zł/mies.** ponad projekcję obecnego PG, zanim rozliczymy
minimum/kredyt workspace. Podwojenie usług w czasie próby stagingowej jest
dodatkowo płatne proporcjonalnie do czasu. Dla samego klastra na Pro
minimalny rachunek wyniósłby **76,00–89,22 zł** przy D i **76,00–91,17 zł**
przy 10D; realny rachunek obejmuje także aplikację i inne zużycie workspace.

Wzrost samego dysku 10× dodaje tu tylko 1,95 zł/mies.; **nie oznacza to,
że dziesięciokrotny ruch kosztuje o 1,95 zł więcej**. Przyrost CPU/RAM,
WAL i pracy indeksów zależy od ruchu. [PITR ma osobne zużycie bucketu
i egress archiwum](https://docs.railway.com/volumes/point-in-time-recovery):
według [cennika bucketów](https://docs.railway.com/storage-buckets/billing)
0,015 USD/GB-mies. plus egress z usługi; nie zmierzono retencji/WAL, więc
nie dodano fikcyjnej kwoty. Model w [costs.json](evidence/redis-ha/costs.json),
przeliczenie: `python3 scripts/infra603/costs.py`.

Redis/Valkey nie ma tutaj ceny za „pakiet Kuking”: płacimy za zasoby.
[Railway Redis](https://docs.railway.com/databases/redis) ma także wariant HA
z Sentinel/HAProxy. Powyższe **11–43 zł nie obejmuje Redis HA** ani dwóch
osobnych instancji dla ulotnego cache i trwałej kolejki. Dwie takie same
instancje podwajają koszt zasobów. Nie wyceniono nieprzetestowanego Valkey
jako oddzielnej oferty dostawcy.

## 6. Decyzje właściciela i warunki odbioru

| Decyzja | Warianty i koszt |
| --- | --- |
| Redis teraz czy po danych? | Zalecenie: po danych; koszt usługi dziś 0 dodatkowo. Najpierw tydzień pomiarów i próba #605. Przedwczesne włączenie: 11–43 zł/mies. na jedną instancję plus testy trwałości, limity, monitoring i obsługa awarii. |
| Jak długo może nie działać baza i ile zapisów wolno utracić? | RTO/RPO wybiera właściciel. HA z asynchronicznym standby preferuje dostępność; wariant synchroniczny ogranicza ryzyko utraty kosztem opóźnień i możliwej odmowy zapisów. Nie zatwierdzono żadnej wartości. |
| Czy kupić HA już przy małej bazie? | Można dla dostępności, niezależnie od QPS. Najpierw sprawdzona kopia/restore #594, odbiorca alarmu #599 i budżet opisany wyżej. Obecne niskie CPU nie rozstrzyga wartości ciągłości działania. |
| Czy rozdzielać odczyty? | Teraz brak dowodu potrzeby. Później wydzielone bezpieczne zapytania i testy laga/prywatności; sam HA ich nie rozdziela. |
| SLO i budżet | Zatwierdzić progi z §3 oraz miesięczny budżet i ostrzeżenie kosztowe. Nie ustawiać twardego limitu odcinającego produkcję jako zamiennika alarmu. |

Odbiór HA dopiero na odrębnym stagingu: odczyt aktualnej konfiguracji
i kosztów węzłów; backup i odtworzenie; zapis oznaczonych transakcji przed
awarią; utrata primary; pomiar czasu do pierwszego **udanego i poprawnego
zapisu aplikacji**, brakujących potwierdzonych transakcji oraz duplikatów;
powrót starego węzła jako standby; utrata jednego etcd/proxy; test reconnectów
HTTP/worker/scheduler. Test lag obejmuje blokadę użytkownika i ukrycie wpisu.
Sprawdzić cofnięcie do standalone i koszt pozostawionych wolumenów.
Nie uznawać samego zielonego statusu Patroni za odbiór Kuking.

## 7. Powtórzenie i granice dostarczenia

W Git Bash na Windows:

```bash
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh gpt-redis-ha
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /home/mateusz/flota/gpt-redis-ha-run/scripts/infra603/run.sh
```

`run.sh` **odtwarza wyłącznie własną bazę**. Nie uruchamiać obok testów na
tej samej bazie. Sprawdza host/port/nazwę/użytkownika przed migracją, buduje
assety i odmawia produkcji. Pierwsza próba HTTP rzeczywiście wykryła brak
manifestu Vite; po buildzie wykonano cały pomiar ponownie. Wynik błędnego
żądania nie wszedł do tabel. Wyniki skopiować ze `storage/infra603` przed
ponowną synchronizacją runtime. Surowe JSON skrócono bez usuwania próbek
(porównano zdekodowaną zawartość przed i po).

Kontrole projektu i przyrządu opisuje [odbiór techniczny](REDIS_HA_ODBIOR_603_604.md).
Nie wprowadzono poprawki zachowania aplikacji, migracji ani nowej zależności;
nie ma więc regresji aplikacyjnej wymagającej testu przed poprawką.
Progi produktowe nie zostały zamienione w asercje.

**Nie wykonano:** push, PR, komentarzy/zamknięcia issues, Railway plan/apply,
utworzenia usług/kont, zmian zmiennych, SQL produkcyjnego, testów awarii
produkcji/stagingu, wysyłek do ludzi. Brak aktualnego podziału produkcyjnego
SQL, maksymalnego QPS i pomiaru kosztu działającego HA pozostaje jawny.
Wycofanie tego pakietu to usunięcie dokumentów/przyrządów z commita;
zachowanie portalu i schemat bazy pozostają takie same.
