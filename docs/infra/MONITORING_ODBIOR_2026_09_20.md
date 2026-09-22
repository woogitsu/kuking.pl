# Monitoring i budżet połączeń — odbiór lokalny #598 / #599

Data: 20.09.2026. Gałąź `gpt/monitoring`, baza kodu
`4c811cc7bff365fb8f86d87eabac93b7738a45cd`.
**To jest pomiar lokalny i instrukcja wdrożenia, nie odbiór produkcji.**
Zgłoszenia i komentarze odczytano przez `gh issue view`.

## 1. Najpierw #598: liczby zmierzone samodzielnie

Przed zmianą klas aplikacji uruchomiono PHP 8.4.24, PostgreSQL 18.6,
`127.0.0.1:55439`, własną bazę `kuking_flota_gpt-monitoring`, użytkownik `kuking`.
Sesje, cache i kolejka korzystały z `database`. Dane: 200 publicznych wpisów,
40 przepisów, 12 lokalnych JPEG 2400×1600 (3,84 Mpx). Nie wysłano poczty.

Próbnik co około 5 ms, każde zapytanie w osobnej transakcji; liczy wyłącznie
`client backend` własnej bazy, **bez swojego PID**. Inne stanowiska nie
wchodzą do wyniku. Limit klastra jest jednak wspólny dla wszystkich baz.
Dowód surowy: plik `output/monitoring/baseline.json` (patrz uwaga o dowodach na końcu).

| Uruchomiony scenariusz | Szczyt zajętych | Szczyt aktywnych | Szczyt idle | Dowód pracy |
|---|---:|---:|---:|---|
| Spoczynek przed próbą | 0 | 0 | 0 | 90 próbek |
| Kontrola dynamiczna: 6 procesów uruchomionych już po starcie próbnika | 6 | 6 | 1 | 468 próbek, każdy proces zakończony poprawnie |
| Jeden proces obsługi HTTP, 60 żądań | **1** | 1 | 1 | 20 × `/odkryj`, 20 × `/szukaj?q=pierogi`, 20 × rzeczywisty przepis; wszystkie HTTP 200 i niepuste HTML |
| Jeden `queue:work`, kolejka `media` | **1** | 1 | 1 | 12 rzeczywistych `ProcessUploadedImage`, 12 zdjęć `ready`, 0 jobs, 0 failed_jobs |
| Jeden `schedule:run`, 10 przebiegów | **1** | 1 | 1 | ustawiona minuta :25, uruchamiane czujka i liczniki, 424 próbki |
| `migrate --force` bez oczekujących migracji | **1** | 1 | 1 | pomiar samego sprawdzenia migracji, nie koszt dowolnej przyszłej migracji |
| Spoczynek po próbie | 0 | 0 | 0 | 113 próbek |

Maksima aktywnych i idle występują w **różnych chwilach**, nie należy ich
sumować. Połączenie zajęte obejmuje oba stany. Podczas dekodowania obrazu
worker pozostaje połączony, choć baza nie wykonuje wtedy zapytania.

Lokalny limit: `max_connections=100`, rezerwa superusera 3, rezerwa zwykła 0,
czyli **97 miejsc poza rezerwami**. Użytkownik aplikacji nie powinien mieć
uprawnień superusera. Rezerwa może umożliwić wejście administratorowi nawet
po wyczerpaniu miejsc zwykłych użytkowników — wcześniejsze stwierdzenie
„nie połączy się nikt, łącznie z administratorem” było zbyt szerokie.

Żądania lokalne: p50 **162 ms**, p95 **245 ms**, p99 **265 ms** (nearest-rank,
60 próbek). To pomocniczy pomiar małego zbioru na współdzielonej maszynie,
nie SLO produkcji ani test maksymalnej przepustowości. Serwer to `php -S`,
**nie FrankenPHP**; zmierzono jeden slot wykonania PHP. Nie zmierzono tu
produkcyjnych wątków, TLS, Cloudflare, zdalnego R2 ani logowania.

## 2. Ile replik mieści się w puli — obliczenie, nie pomiar

[pomiar cudzy: komentarze #598 z 17.09 i
`WERYFIKACJA_BUDZETU_POLACZEN_598.md` §1a z 19.09]
Produkcja miała limit 500, rezerwy 3 + 0 i FrankenPHP `max_threads=4`.
**Nie odczytano produkcji w tej sesji; liczby mogą być nieaktualne.**
Jeden wielowątkowy proces FrankenPHP nie jest jednym slotem PHP: dla
czterech równoległych wątków przyjmujemy cztery połączenia na replikę web.

Dla R replik web, Q procesów kolejki (w tym przyszłych media workerów)
i S procesów harmonogramu:

```text
zwykły szczyt       = 4R + Q + S
budżet wdrożeniowy  = 2 × (4R + Q + S) + 1 migracja + 3 administracja
```

Mnożnik 2 obejmuje stary i nowy komplet podczas wdrożenia. To konserwatywne
założenie, a nie zmierzony szczyt wdrożenia. Próbnik i narzędzia operacyjne
muszą mieścić się w trzech miejscach administracyjnych. Dodatkowe procesy
CLI lub osobne połączenia read/write wymagają przeliczenia.

| Topologia po rozdzieleniu ról | R / Q / S | Zwykły szczyt | Budżet wdrożeniowy | Z 497 miejsc |
|---|---|---:|---:|---:|
| Podstawowa #595 | 1 / 1 / 1 | 6 | **16** | 3,2% |
| Druga replika web | 2 / 1 / 1 | 10 | **24** | 4,8% |
| Osobny dodatkowy media worker | 1 / 2 / 1 | 7 | **18** | 3,6% |
| Obie zmiany | 2 / 2 / 1 | 11 | **26** | 5,2% |

**Sprostowanie „ponad stu replik”.** Przy Q=S=1 wzór to `8R+8`.
W 497 mieści się najwyżej **61 replik web** (budżet 496), a w lokalnych
97 — **11** (96). Obie liczby oznaczają niemal całkowite wyczerpanie puli,
więc **nie są bezpiecznym limitem skalowania**. Nie uwzględniają też RAM/CPU,
limitów planu Railway ani zajętości przez inne aplikacje.

Przy obecnym ostrzeżeniu 50 planowany budżet powinien być **poniżej 50**:
mieszczą się maksymalnie **5 replik web** (48), a szósta daje 56 i wymaga
nowego pomiaru oraz przeglądu progów. Przy dodatkowym workerze Q=2 pięć daje
50, więc bez zmiany progu mieszczą się **4**. To granica tego modelu
operacyjnego, nie zgoda na zwiększenie liczby replik.

## 3. Próg i czas wykrycia

Pozostają skonfigurowane progi produkcyjne **50 / 125**, budżet **16**:
50 jest ponad trzykrotnością budżetu 16, a 125 to około 25% historycznych
497 miejsc. Dodatkowy web i worker dają 26, więc nadal zostaje 24 miejsc
przed pierwszym alarmem i 471 do granicy puli. Po zmianie topologii ustaw
`KUKING_POLACZENIA_BUDZET` na nowy policzony budżet.

Ważne ograniczenie: obecna czujka połączeń chodzi **raz na godzinę**,
kolejki co **15 minut**. Próg nie gwarantuje reakcji przed wyczerpaniem
przy szybkim wzroście. Zaległość 600 s może zostać zauważona dopiero niemal
25 minut po powstaniu zadania. Zalecenie przed skalowaniem: pomiar obu co
minutę, połączenia również co 1 s podczas uzgodnionego okna wdrożenia.
To rekomendacja do wdrożenia; harmonogram nie został w tym pakiecie zmieniony.

Na lokalnym klastrze 100 próg 125 zostaje w kodzie obcięty do 97 — alarm
krytyczny przychodzi więc dopiero przy pełnej puli zwykłych użytkowników.
**Nie kopiuj 50/125 do mniejszego planu.** Dla samodzielnej instancji 100
z budżetem 16 proponowane wartości to 25/50 (zapas 9 przed ostrzeżeniem,
47 przed limitem przy krytycznym). Na współdzielonym klastrze trzeba najpierw
dodać budżety pozostałych baz; sam wynik tej aplikacji nie wyznacza progów.

## 4. Co naprawiono i co naprawdę odebrano

Przed poprawką dwa testy `AlarmPrzyAwariiCacheTest` zakończyły się
`QueryException` przy `Cache::get`, przed wysyłką. Próba używała prawdziwego
PostgreSQL i nieistniejącej tabeli cache w izolowanej bazie. Alarm o awarii
bazy zależał od tej samej bazy.

`AlarmMemory` toleruje niedostępny odczyt/zapis/usunięcie pamięci wyciszania.
Obie istniejące klasy alarmu nadal sprawdzają rzeczywiste potwierdzenie
HTTP 2xx. Przy niedostępnym cache próbują alarmować przy każdym przebiegu;
nie mogą wtedy zapewnić deduplikacji ani trwałego odwołania. Po naprawie
cache może wrócić stary stan i dodatkowe odwołanie. Bez nowej tabeli,
pakietu, migracji lub zmiany progów. Kanał wyłączony nadal nic nie wysyła.

**Kontrola dodatnia transportu:** 16 przypadków, dokładnie 12 odebranych
wiadomości przez prawdziwy serwer HTTP `127.0.0.1:8599`, bez `Http::fake`.
Próby połączeń zaniżają progi tylko w pamięci lokalnego procesu do 1;
liczba zajętych pochodzi z rzeczywistego `pg_stat_activity`. Nie zajmujemy
125 połączeń współdzielonego klastra, żeby sprawdzić alarm.

| Sygnał / próba | Wynik |
|---|---|
| `kuking:sprawdz-alarm` | 1 wiadomość próbna |
| Przekroczony próg ostrzegawczy / krytyczny | po 1 wiadomości; eskalacja natychmiast |
| Powtórzone ostrzeżenie | 0 dodatkowych wiadomości |
| Powrót połączeń do normy | 1 odwołanie |
| Gotowe zadanie media czeka 900 s przy progu 600 | 1 wiadomość |
| Powtórzona zaległość | 0 dodatkowych wiadomości |
| Świeży failed job | 1 wiadomość |
| Rezerwacja starsza niż próg | 1 wiadomość |
| Powrót do normy po każdym stanie kolejki | po 1 odwołaniu |
| Brak dostępu do bazy i cache jednocześnie | po 1 wiadomości z obu komend |
| Spokojne stany początkowe | cisza |

Sprawdzono liczbę i kolejność wiadomości, treść sygnału, dokładnie jeden
nagłówek oraz brak nazwy bazy i adresu połączenia. Dowody:
`output/monitoring/received.json`,
`output/monitoring/alert-cases.json`.
Odbiornik HTTP nie dowodzi odbioru przez człowieka, działania Discorda ani TLS.

Weryfikacja poprawki: **4399 testów, 83 710 asercji, wszystkie zaliczone**
w 506 s na PostgreSQL. Pominięto wyłącznie `ProbaOdtworzeniaTest`, zgodnie
z instrukcją floty o jego wspólnej bazie. Zestaw alarmów: 103 testy,
453 asercje. Pint: 6 plików; PHPStan: trzy klasy alarmów i nowy test,
bez błędów. Dowód kontroli ujemnej: plik `output/monitoring/negative.json` (patrz uwaga niżej).
Celowa mutacja powoduje błąd `CACHE_BLOKUJE_ALARM`; po odtworzeniu test
przechodzi. Pole `przywrocenie` istniejącego skryptu pozostaje historycznie
„nie wykonane”, bo zapis JSON poprzedza końcowy trap; niezależne porównanie
MD5 pliku w worktree i runtime dało `d349de59801d55e09b64299e93902b2e`,
zgodne z `md5_przed`. Nie poprawiano przy okazji narzędzia wspólnego.

Powtórzenie końcowego przyrządu ponownie dało szczyty **1/1/1**, kontrolę
dynamiczną **6** i **12** odebranych alarmów. Strażnik przyrządu dopuszcza
własną bazę i odmawia innej kodem 2 przed migracją. Sprawdzono oba przypadki;
samo wypisanie wyjątku przez handler Laravela nie zapewniało niezerowego
kodu wyjścia, dlatego przyrząd ma jawny kod odmowy.

## 5. Konkretny odbiorca i postępowanie po alarmie — propozycja do zatwierdzenia

Proponowany kanał: prywatny Discord **`#kuking-alarmy`**, powiadomienia
o wszystkich wiadomościach na telefonie właściciela. Adres webhooka ze
zakończeniem `/slack` jako `LOG_BLAD_WEBHOOK_URL`, osobny kanał staging.
Właściciel wskazuje osobę zastępującą go przy braku reakcji w 10 minut.
Nie zakładano serwera, nie tworzono webhooka i nikomu nic nie wysłano.

Odbiór wymaga, aby wskazana osoba odczytała znacznik czasu próbnej wiadomości
**z telefonu z zablokowanym ekranem**. Zapisz czas wysłania i potwierdzenia.
Bez tego kontakt ma status „niepotwierdzony”, nawet przy HTTP 200.
Uptime i alerty Railway muszą mieć także kontakt niezależny od aplikacji:
rzeczywistą, potwierdzoną skrzynkę właściciela z powiadomieniami na telefonie.
Nie zakładamy, że proponowane w starym runbooku `alerty@kuking.pl` istnieje.

| Alarm | Pierwsza reakcja człowieka |
|---|---|
| Połączenia ≥50 | Wstrzymaj skalowanie; uruchom `kuking:budzet-polaczen --bez-alarmu`, sprawdź liczbę replik i trwający deploy; porównaj aktywne/idle/idle-in-transaction. Nie zabijaj wszystkich sesji. |
| Połączenia ≥125 albo brak bazy | Sprawdź stan usługi PostgreSQL i ostatnie wdrożenie; ogranicz nowe procesy do poprzedniej topologii, jeżeli to one wywołały wzrost. Użyj rezerwowego dostępu administracyjnego. |
| Kolejka | `kuking:sprawdz-kolejke --bez-alarmu`, stan workera i jego logi, następnie `kuking:martwe-zadania`. Nie wykonuj zbiorowego retry starych resetów haseł. |
| Uptime | Sprawdź domenę z innej sieci oraz stan Railway/Cloudflare; porównaj `/` i `/health`; po związanym czasowo wdrożeniu rozważ powrót do poprzedniej wersji. |
| Pamięć / CPU / restart | Ustal usługę i replikę, sprawdź OOM oraz ostatni job; nie dokładaj procesów przed sprawdzeniem budżetu bazy. |
| Koszt | Sprawdź usługę powodującą wzrost, preview i transfer; usuń przyczynę lub jawnie zaakceptuj nowy budżet. |

## 6. Wdrożenie i koszt — bez wykonywania zmian w panelach

Źródła dostawców sprawdzono 20.09.2026. Ceny w USD; nie są ofertą ani
odczytem planu konta właściciela.

1. **Kanał aplikacji:** wdrożyć kod przez kolejkę floty, ustawić sekret,
   odświeżyć zapieczoną konfigurację restartem, wykonać
   `php artisan kuking:sprawdz-alarm`. Potwierdzić odbiór jak w §5.
2. **Uptime poza Railway:** proponuję Better Stack Free: 10 monitorów,
   10 heartbeatów, sprawdzanie co 3 min, **0 USD/mies.** według
   [oferty dostawcy](https://betterstack.com/uptime). Utworzyć monitory
   `https://kuking.pl/` i `https://kuking.pl/health`, timeout 10 s, oczekiwane
   HTTP 200; sprawdzić certyfikat. `/health` może mieć `degraded` przy 200:
   nie utożsamiać odpowiedzi 200 ze zdrowiem wszystkich podsystemów.
   Darmowy interwał **nie spełnia alarmu w 2 minuty**; jeżeli 2 minuty są
   wymaganiem, wybrać płatny wariant 60 s po potwierdzeniu ceny w panelu.
3. **Dashboard Railway:** Observability w odpowiednim środowisku →
   „Start with a simple dashboard”; wykresy CPU, pamięci, dysku, transferu,
   kosztu oraz logi obu czujek. Po #595 oddzielnie web/worker/scheduler,
   z filtrem repliki i markerem wdrożenia. Alerty zasobowe wymagają Pro:
   [opis dashboardu](https://docs.railway.com/observability).
   [Pro kosztuje minimum 20 USD/mies. i obejmuje 20 USD zużycia](https://docs.railway.com/pricing/plans);
   to nie jest dodatkowe 20 USD do każdego serwisu. Nie sprawdzono obecnego planu.
4. **Koszt:** Workspace Usage → Set Usage Limits → **Custom email alert**.
   Propozycja: 80% zatwierdzonego miesięcznego budżetu B, np. 40 USD przy
   B=50 USD. B wybiera właściciel. [Hard limit wyłącza usługi](https://docs.railway.com/pricing/cost-control),
   więc nie zastępuje ostrzeżenia i wymaga odrębnej decyzji.
5. **Częstość:** zatwierdzić minutowy pomiar czujek przed wzrostem. Daje
   2880 linii/dobę łącznie zamiast 120; cenę zużycia zmierzyć przed/po,
   bez dodatkowej bazy metryk. W oknie wdrożenia potrzebny gęstszy odczyt
   `pg_stat_activity`, bo seria godzinowa nie wyznacza peaku.

## 7. Próby alertów zewnętrznych — bramka wdrożenia, obecnie niewykonana

Każda próba ma mieć: zdrowy stan → przekroczenie → odebraną wiadomość
ze znacznikiem czasu → powrót → odwołanie. Brak wiadomości to porażka.
Nie przeprowadzać awarii na produkcji. Tymczasowe progi przywrócić i odczytać.

| Alert | Proponowany próg początkowy | Kontrola dodatnia na stagingu / w panelu | Stan tutaj |
|---|---|---|---|
| Dostępność `/` i `/health` | 2 kolejne nieudane próby; Free: do ok. 6 min + timeout, bez gwarancji 2 min | Osobny monitor stagingu: zdrowe 200, kontrolowane 503, potem 200; potwierdzenie obu wiadomości przez osobę dyżurną | niewykonana — brak konta/zgody na uruchomienie usługi |
| CPU | >80% limitu przez 10 min | Zaniżyć próg tylko monitora stagingu poniżej zmierzonego zużycia, poczekać pełne okno, odebrać alarm, przywrócić | niewykonana |
| RAM | >80% limitu przez 5 min; OOM natychmiast | Jak CPU; osobno kontrolowany restart stagingu i zdarzenie platformy. Nie wywoływać OOM bazy | niewykonana |
| Dysk DB | >80% zajętości | Tymczasowy próg poniżej bieżącego zużycia stagingu; żadnego zapełniania dysku | niewykonana |
| Nieudane wdrożenie / crash | zdarzenie platformy | Nieudany deploy osobnego stagingu; odbiór powiadomienia platformy | niewykonana |
| Koszt | 80% B | Funkcja testowa dostawcy, a jeśli jej brak: uzgodniony tymczasowy soft limit poniżej bieżącego zużycia; potwierdzić wiadomość i przywrócić. Nie obniżać hard limitu | niewykonana |
| p95/p99 HTTP | propozycja p95 >1 s lub p99 >2 s przez 10 min, ≥100 żądań/okno | Kontrolowane opóźnienie stagingu, zdrowe okno i alarm; trzeba najpierw uruchomić pomiar tych metryk | niewykonana; próg do kalibracji |

Progi czasu, CPU i pamięci są propozycjami operacyjnymi, nie ustalonym SLO
produktu. Pamięć 80% zostawia 20% na krótkie alokacje, CPU przez 10 min
odcina pojedynczy pik, a minimum próbek chroni percentyle przed jedną
powolną odpowiedzią. Bez historycznej serii wymagają kalibracji.

## 8. Pozostały zakres #599 — jawne luki, nie zielone pola

Ten pakiet nie zamyka całego issue. Railway mierzy zasoby, a nie automatycznie
p50/p95/p99 czy error rate aplikacji
([dokumentacja metryk](https://docs.railway.com/observability/metrics)).
Właściciel powinien wybrać wariant dla pozostałego pomiaru:

| Brak | Wariant minimalny / koszt pracy i ograniczenie |
|---|---|
| Percentyle HTTP, 4xx/5xx, RPS, concurrency, klasy feed/search/recipe/media | Instrumentacja z nazwą trasy, czasem, kodem i znacznikiem repliki; bez URL, query string, IP i użytkownika. Agregacja w oknach; potrzebny osobny pakiet implementacji i pomiar narzutu. Alternatywa: świadomie wybrany APM z kosztem zależnym od ingestu. Nie instalowano SDK. |
| DB time / p95 zapytań | Mierzyć czas bez SQL/bindings. `pg_stat_statements` daje agregaty, nie p95 sam z siebie. Rozszerzenie i magazyn histogramów wymagają oceny zasobów; nie włączano. |
| Lock waits / deadlocks / IOPS | Liczbowe próbki `pg_stat_activity`/`pg_stat_database`, osobno źródło I/O hosta. Nie udawać, że licznik połączeń mierzy IOPS. |
| Kolejki oddzielnie / throughput | Obecna czujka agreguje wszystkie kolejki i nie liczy wykonanych jobów. Rozdzielić ready/reserved/failed per high/default/media/low oraz rejestrować zakończenia. |
| Cloudflare/R2 | Osobne dashboardy cache hit, origin requests, transferu i błędów; dostępność/retencja według faktycznego planu. Nie wykonywano odczytu konta. |
| Brak schedulera | Czujka uruchamiana przez martwy scheduler nie wykryje własnego zatrzymania. Potrzebny heartbeat do niezależnego monitora po udanym przebiegu; sam `/health` tego nie zastępuje. |

Koszt lokalnego pakietu: zero nowych usług i zależności. Nie wyceniono pracy
nad pełnym APM ani przyszłego transferu bez pomiaru ruchu. Decyzje właściciela:
odbiorca i zastępstwo, akceptowalny czas reakcji, B oraz wybór wariantu metryk.

## 9. Powtórzenie, wycofanie i granice

W Git Bash na Windows, najpierw przygotowanie runtime:

```bash
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh gpt-monitoring
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /home/mateusz/flota/gpt-monitoring-run/scripts/monitoring/prepare-local.sh
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- python3 /home/mateusz/flota/gpt-monitoring-run/scripts/monitoring/receive.py
```

`prepare-local.sh` **odtwarza od zera wyłącznie bazę własnego stanowiska**;
nie uruchamiaj jednocześnie testów na niej. Kopiuj wyniki ze `storage/`
przed kolejnym przygotowaniem runtime. Przyrządy nie nadają się do produkcji:
odmawiają innego hosta/portu/nazwy bazy. Nie publikować ich jako endpointów.

Wycofanie: odwrócić commit poprawki klas alarmu; schemat bez zmian. Wróci
wtedy znana wada zależności alarmu od cache. Wycofanie dokumentów i lokalnych
przyrządów nie zmienia zachowania serwisu. Nie czyścić wspólnego cache
produkcji w celu wycofania — usuwałoby to pamięć innych funkcji.

Nie wykonano push, PR, wdrożenia, zmian kont dostawców, wysyłek do ludzi,
pomiaru produkcji ani odczytu jej sekretów. #598 pozostaje bez aktualnego
produkcyjnego peaku i #599 bez odebranych alertów zewnętrznych.

## Uwaga o dowodach surowych (dopisane 21.09.2026)

Cztery pliki wymienione wyżej — `baseline.json`, `received.json`,
`alert-cases.json` i `negative.json` — **nie są w repozytorium**. Powstały
lokalnie w katalogu `output/`, a ten katalog przepadł razem z lokalnymi
commitami przy awarii repozytorium 20 września. Katalog `output/` nie jest
ignorowany przez `.gitignore`, więc nie chodzi o połknięcie przez wzorzec:
plików po prostu nie zdążono zacommitować.

Były to odnośniki prowadzące donikąd, więc zostały zamienione na same nazwy
plików. Liczby w tabelach wyżej pochodzą z tamtych przebiegów i **nie zostały
powtórzone** — do czasu odtworzenia dowodów należy je czytać jako zapis
z pomiaru, którego nie da się dziś niezależnie sprawdzić.

Odtworzenie: uruchomić próbnik ponownie i zacommitować `output/monitoring/*.json`
razem z raportem.
