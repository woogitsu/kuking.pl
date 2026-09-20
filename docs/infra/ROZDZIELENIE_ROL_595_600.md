# Rozdzielenie ról — przygotowanie #595 i #600

Stan pakietu: 20.09.2026. Baza pracy: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`,
gałąź `gpt/rozbicie-uslug`. **Nie wykonano planu przeciw Railway, apply,
wdrożenia, zmiany zmiennych ani zwiększenia liczby procesów na produkcji.**
To instrukcja przyszłego wykonania, nie potwierdzenie odbioru produkcji.

## 1. Warunek #598 i granice liczb

Zgłoszenia #595, #598 i #600 wraz z komentarzami odczytano samodzielnie przez
`gh issue view … --repo woogitsu/kuking.pl`. Liczba wymagana przed skalowaniem
**istnieje**. Nie oznacza to zakończenia wszystkich kryteriów #598.

[pomiar cudzy: #598, komentarze z 17.09 oraz
`WERYFIKACJA_BUDZETU_POLACZEN_598.md`, odczyt z 19.09]:
`max_connections=500`, rezerwa 3, **497 dostępnych miejsc**;
FrankenPHP `num_threads=4`, `max_threads=4`; worker i scheduler po jednym
połączeniu. Limit wątków jest właściwością konkretnego uruchomienia,
nie obietnicą dla dowolnego CPU czy nowej wersji obrazu.

| Etap | Szczyt zwykły przy pełnym zajęciu wątków | Budżet z dwoma kompletami przy deployu, jedną migracją i 3 miejscami administracyjnymi |
|---|---:|---:|
| Jeden `all` albo web + worker + scheduler | 6 | 16 |
| Dwa weby + worker + scheduler | 10 | 24 |
| Jeden web + worker + media + scheduler | 7 | 18 |
| Dwa weby + worker + media + scheduler | 11 | 26 |

To **wyliczenia**, nie nowy pomiar produkcji: `2 × (4 × web + worker + media + scheduler) + 1 + 3`.
Lokalny pomiar z 19.09 widział chwilowo 5 backendów przy 4 workerach HTTP;
rezerwa i obserwacja okna wdrożenia pozostają potrzebne. Kopia bazy, CLI,
inny klient, równoległy deploy i pozostałe usługi też zużywają pulę.
Wolne 471 miejsc przy wariancie 26 jest arytmetyką, nie odczytem stanu.

**PgBouncer: na podstawie dostępnych liczb jeszcze niepotrzebny.**
Nie dodano go ani Redisa. Powrót do decyzji: rzeczywista presja na pulę,
wzrost liczby procesów lub brak bezpiecznego zapasu po przeliczeniu budżetu.
Progi 50/125 pozostają bez zmian; `KUKING_POLACZENIA_BUDZET` w manifestach
podąża za wybraną topologią. Przed uruchomieniem trzeba odczytać aktualny
limit i zajętość oraz potwierdzić alarm #599. Godzinowa próbka nie mierzy
kilkusekundowego szczytu przy deployu — podczas próby potrzebne jest
częstsze próbkowanie samych agregatów `pg_stat_activity`.

## 2. Jawny podział odpowiedzialności

Wszystkie usługi używają tego samego wydania obrazu i bazy danego środowiska.
Sekrety pozostają w Railway. Cache, sesje i kolejka są w PostgreSQL;
prywatne pliki tymczasowe Livewire w `r2`, eksporty w `r2_eksporty`.
Brak wspólnego dysku lokalnego. Sprawdzić zgodność `APP_KEY`, bazy,
`CACHE_PREFIX`, tabel cache/blokad i dysków między rolami, bez wypisywania sekretów.

| Usługa | Polecenie startowe / kolejki | Robi | Nie robi |
|---|---|---|---|
| web | `kuking-entrypoint web` | HTTP, jedyna domena publiczna i `/health`; jedyny pre-deploy z migracją/seedem | Nie odbiera kolejki, nie uruchamia harmonogramu |
| worker, etap #595 | `kuking-entrypoint worker`, `QUEUE_NAMES=high,default,media,low` | Jeden nadzorowany proces kolejki | Bez HTTP, harmonogramu, migracji |
| worker po wydzieleniu mediów | to samo, `QUEUE_NAMES=high,default,low` | Kolejki poza mediami, w tym eksporty | Nie odbiera `media`; bez HTTP, harmonogramu, migracji |
| media, opcja #600 | `kuking-entrypoint worker`, `QUEUE_NAMES=media` | Jeden nadzorowany proces tylko dla mediów | Bez lekkich kolejek, HTTP, harmonogramu, migracji |
| scheduler | `kuking-entrypoint scheduler` | Pętla powłoki `schedule:run`; komendy wykonuje synchronicznie, część z nich dopiero dodaje joby | Bez serwera HTTP, `queue:work`, migracji; nie robi kopii bazy |
| all, wycofanie | `kuking-entrypoint all`, `QUEUE_NAMES=high,default,media,low` | HTTP + kolejka + harmonogram w jednym kontenerze | Nie zapewnia izolacji zasobów i awarii |

`kopia-bazy` jest osobną, istniejącą w deklaracji rolą poza tym podziałem;
nie przejmować jej przez scheduler ani nie zmieniać w tym wdrożeniu.
Nazwa usługi `media` nie jest nową rolą entrypointu: rola nadal brzmi `worker`.
Argument startowy wygrywa z `APP_ROLE`; przy rollbacku trzeba zmienić oba.

Manifest pozostawia `PRODUCTION_SPLIT_SERVICES=true` jako zastany zamiar #595.
Nowe opcje domyślnie **nie włączają #600**:

- `PRODUCTION_WEB_REPLICAS=1` (po odbiorze #595 i zgodzie kosztowej: 2);
- `PRODUCTION_MEDIA_WORKER=false` (po pomiarze kolejek: true);
- STAGING_SPLIT_SERVICES=false (true tylko na czas próby podziału);
- STAGING_WEB_REPLICAS=1, STAGING_MEDIA_WORKER=false: osobne ustawienia
  próby #600 na stagingu; 2/true pozwala odebrać wariant docelowy przed produkcją.

Preview zawsze pozostaje `all`. Wyłączenie podziału ogranicza web do jednej
repliki także wtedy, gdy opcje #600 pozostały na 2/true. Manifest pomija wtedy
worker/scheduler/media, co w planie może oznaczać **usunięcie usług**;
nie jest to bezpieczna kolejność awaryjnego rollbacku — patrz §5.

## 3. Co zrobi drugi scheduler

**Przed poprawką: mógł wykonać te same zadania ponownie.** Własny pomiar:
na kodzie aplikacji bez poprawki `HarmonogramJednegoSerweraTest` pokazał
`kuking:sprzataj-osierocone-zdjecia` i `kuking:policz-kolejki` dwa razy
w jednej minucie. Istniejące 3 testy `HarmonogramBezProcOpenTest` wcześniej
przechodziły (40 asercji), więc nie wykrywały tej usterki.

`withoutOverlapping()` zwalnia blokadę po zakończeniu pracy. Drugi proces,
który wystartuje później w tej samej minucie, może wtedy wykonać pracę drugi raz.
Jedna replika nie wyklucza tego przy nakładaniu starego i nowego wdrożenia.

Poprawka: **wszystkie 19 zadań mają `onOneServer()` i `withoutOverlapping()`**.
Laravel 13 używa wspólnego cache database do wyboru jednego wykonawcy dla
nazwy zadania i minuty. Nazwy i prefiks muszą pozostać wspólne w obu kontenerach.
Zmiana nazwy podczas deployu tworzy inną blokadę. Nie czyścić cache/blokad
przy przełączaniu usług. Nie ustawiać `CACHE_STORE=array` ani `file` na produkcji.

Własny test używa rzeczywistego `ScheduleRunCommand`, definicji aplikacji,
cache PostgreSQL oraz dwóch świeżych obiektów schedulera. Sprawdza wszystkie
zadania, pominięcie drugiego przebiegu i wykonanie następnego terminu.
Komendy domenowe zastępuje licznik: **nie wysyła listów i nie mierzy
równoległych procesów ani skutków domenowych**. Blokady mają osobne
połączenie z tą samą bazą testową, bez transakcji `RefreshDatabase`;
konflikt UNIQUE w implementacji DatabaseLock nie może zatruć transakcji testu.

To ochrona przed drugim uruchomieniem harmonogramu, **nie exactly-once**.
Awaria po zdobyciu blokady może opuścić ten termin; retry kolejki nadal może
powtórzyć job. Ręczne wywołanie komendy omija scheduler. Digest ma dodatkową
barierę `weekly_digest_sends` (D-077); zdanie w dawnym #595 „dwa schedulery =
dwa identyczne digesty” było zbyt mocne. Test dowodzi podwójnego wywołania,
a nie podwójnego doręczenia.

Dokumentacja: [Laravel 13 — pojedynczy wykonawca](https://laravel.com/docs/13.x/scheduling#running-tasks-on-one-server).

## 4. Kolejność przyszłego wdrożenia i warunki przejścia

Każdy krok: jeden operator, jedna zmiana naraz, zapis czasu, SHA, identyfikatorów
usług i wdrożeń oraz wyników. Brak dowodu = zatrzymanie przed następnym krokiem.
Poniższych czynności w tej sesji **nie wykonano**.

### A. Przygotuj rzeczywisty plan, zachowaj istniejące zasoby

1. Potwierdź dokładny projekt i środowisko przez `railway status`.
   W osobnym katalogu operatora zaimportuj aktualny stan przez
   `railway config pull` (nie nadpisuj nim tego repozytorium).
2. Uzgodnij mapowanie istniejącego `kuking.pl` na rolę web po identyfikatorze
   usługi. Manifest ma `web`; sama podobna nazwa **nie dowodzi adopcji**.
   Zachowaj istniejący Postgres, wolumen bazy, backup, domeny i sekrety.
   Plan tworzący nową bazę, usuwający starą usługę lub przenoszący domenę
   poza uzgodnionym krokiem jest powodem STOP.
3. Zapisz bezpieczną kopię konfiguracji i identyfikator sprawnego obrazu do
   powrotu; sekrety przechowuj wyłącznie w przeznaczonym do tego miejscu.
   Sprawdź kopię bazy i ostatnie ćwiczenie odtworzenia; nie wykonuj migracji
   cofających ani seedowania przy samym rozdzielaniu ról.
4. Porównaj cały `railway config plan`, nie tylko role. Zastany IaC zawiera
   np. `db:seed` w pre-deploy, inne nazwy usług i referencje shared variables;
   #595 nie upoważnia do automatycznej zmiany wszystkiego, co pokaże diff.
   Zgodnie z #611 sprawdź też bramkę CI przed wyłączeniem starego procesu.
5. Sprawdź workflow `.github/workflows/railway-iac.yml`: może wykonać apply
   po merge przy `KUKING_DEPLOY_ENABLED=true`. Sam komentarz „tylko przygotowanie”
   go nie zatrzymuje. Koordynator nie może scalić zmian IaC do takiej ścieżki
   bez przejrzanego planu i działającej bramki zatwierdzenia środowiska.

**Punkt kontrolny:** plan nie narusza bazy, kopii, domen ani zmiennych spoza
zakresu; koszt zaakceptowany; nie ma nieznanych operacji usunięcia.
To lokalny pakiet przygotowawczy — nie dołączono aktualnego planu Railway,
bo bez odczytu połączonego środowiska nie da się go uczciwie wygenerować.
[Oficjalne polecenia Railway](https://docs.railway.com/cli).

### B. Najpierw staging, z jego własną bazą i odbiornikiem testowym

1. Wdróż poprawkę harmonogramu w dotychczasowej roli `all`.
   Przy pierwszym wdrożeniu stary kod nie respektuje jeszcze `onOneServer()`.
   Na czas wymiany wstrzymaj harmonogram komendą `schedule:pause` na wspólnym
   cache i poczekaj na zakończenie już pracujących zadań. Sprawdź obsługę tej
   komendy w używanym wydaniu przed rozpoczęciem. Po usunięciu starego kontenera
   `schedule:continue`; spisz pominięte terminy i uzupełnij tylko potrzebne,
   bezpieczne komendy. Nie kasuj blokad jako metody wznowienia.
2. Ustaw `STAGING_SPLIT_SERVICES=true` w konfiguracji próby. Wygeneruj plan
   na stagingu, sprawdź go, dopiero wtedy wykonaj zatwierdzony apply.
   Uruchom worker i scheduler; po potwierdzeniu ich działania zmień istniejący
   web z `all` na `web`. Ta przejściowa obecność dodatkowego workera/schedulera
   wymaga budżetu i wyłącznie kodu z poprawką we wszystkich kontenerach.
   Pełny manifest jest stanem końcowym, nie sekwencerem tych kroków.
3. Sprawdź procesy: web bez kolejki i schedulera; worker bez HTTP; scheduler
   bez HTTP i queue:work. Tylko web ma pre-deploy. Potwierdź wspólne blokady
   przez dwie instancje schedulera w izolowanej próbie, na odbiorniku testowym.
4. Przejdź logowanie/sesję, upload zdjęcia i `pending → ready`, kreator Livewire,
   eksport `ready → pobranie` oraz odczyt metryk kolejki i połączeń.
   Zatrzymaj worker w próbie: web ma odpowiadać, alarm zaległości ma się pojawić.
5. Przećwicz wycofanie z §5 ze stoperem. Zapisz czas faktycznego powrotu,
   pominięte terminy harmonogramu i najstarsze zadanie kolejki.

**Punkt kontrolny:** testy funkcjonalne i awarii przechodzą, 19 zadań ma wspólną
blokadę, alarm dociera do testowego odbiornika, rollback ma zmierzony czas.
Staging po próbie wraca do uzgodnionej topologii; preview nie przejmuje zmian.

### C. Produkcja — #595

Po osobnej zgodzie na wykonanie i koszt powtórz A/B z aktualnym planem produkcji.
Najpierw poprawka harmonogramu na `all`, potem usługi pomocnicze, na końcu web
bez nich. Zbadaj brak wolumenu web (#596), efektywny prywatny dysk Livewire
(#608), realne logi czujek i alarm #599. Nie twórz kont demonstracyjnych ani
nie wysyłaj wiadomości ludziom w ramach technicznego odbioru bez osobnej zgody.

**Punkt kontrolny:** aktywne SHA każdej roli zgodne, stary `all` usunięty z ruchu
i zatrzymany, 1 × web / worker / scheduler, HTTP bez wzrostu błędów i p95/p99,
zdjęcia gotowe, kolejki obsługiwane, zajętość puli poniżej uzgodnionego budżetu
z marginesem. Obserwuj także pełny nocny cykl; samo `/health=200` nie odbiera
workera ani schedulera. Przekroczenie budżetu wymaga wyjaśnienia przed #600.

### D. #600 — każdy wariant osobno

**Drugi web:** najpierw próba z `STAGING_WEB_REPLICAS=2`; po odebranym #595, #596, #598, #599 i zgodzie kosztowej ustaw
`PRODUCTION_WEB_REPLICAS=2`. Plan, apply, odbiór obu identyfikatorów replik,
ten sam SHA i efektywny limit wątków. Sprawdź rozkład ruchu, tę samą sesję
między replikami, upload między żądaniami do różnych replik, healthcheck,
zewnętrzny uptime, a na stagingu awarię jednej repliki i wdrożenie nowej wersji.
Dwie repliki web nie zapewniają HA bazy. Budżet 24 jest warunkowy od 4 wątków.

**Media:** decyzję wyzwala pomiar queue lag mediów, opóźnień innych kolejek,
CPU/RSS albo OOM (#600). Najpierw próba `STAGING_MEDIA_WORKER=true` na stagingu z podziałem. Po zgodzie ustaw `PRODUCTION_MEDIA_WORKER=true`.
Najpierw uruchom odbiorcę `media`, potem odbierz tę kolejkę ogólnemu workerowi.
Końcowy manifest zawiera oba ustawienia; podczas apply pilnuj kolejności,
żeby nie pozostawić `media` bez odbiorcy. `DatabaseQueue` z #606 pozwala na
chwilowe nakładanie konsumentów; nie zastępuje to idempotencji jobów.
Budżet 18 bez drugiego web, 26 z nim.

**Punkt kontrolny:** dla KAŻDEJ kolejki osobno zapisz depth, wiek najstarszego
gotowego joba, liczbę zarezerwowanych, przepustowość i przyrost failed jobs;
dla obu workerów CPU/RSS/restarty. Obecna czujka zbiorcza nie dowodzi,
że `media` nie zagłodziły innych. Nie loguj payloadów ani treści wyjątków jobów.
Eksporty na `low` nadal mogą zajmować worker ogólny — nie obiecujemy pełnej
izolacji każdej lekkiej kolejki. PgBouncer pozostaje wyłączony.

## 5. Wycofanie do jednego `all`

Nie cofaj schematu, danych ani poprawki `onOneServer()`.
Nie usuwaj bazy, R2, backupu, istniejącej domeny ani usług pomocniczych
przed odzyskaniem działania starej topologii.

1. Zatrzymaj następne wdrożenia. Wybierz sprawdzony obraz z poprawką harmonogramu.
2. Zmniejsz web do **jednej** repliki i potwierdź zakończenie drugiej.
   Dopiero potem zmień start na `kuking-entrypoint all`, `APP_ROLE=all`
   i `QUEUE_NAMES=high,default,media,low`. Te ustawienia muszą być w faktycznej
   konfiguracji wdrożenia, nie tylko w lokalnym pliku.
3. Poczekaj na zdrowy web oraz widoczną pracę kolejki i schedulera wewnątrz
   `all`; sprawdź sesję, zdjęcie i odbiór nowych jobów. W tym krótkim oknie
   stare usługi pomocnicze jeszcze pracują; wspólne blokady i budżet obejmują
   nakładanie, ale nie gwarantują dokładnie jednego skutku joba po awarii.
4. Łagodnie zatrzymaj osobne worker/media/scheduler, poczekaj na ich zniknięcie.
   Nie używaj globalnego `queue:restart` jako wyłącznika — nadzorca wznowi
   worker i sygnał dotknie też workera wewnątrz `all`. Zatrzymaj konkretne usługi.
5. Dopiero teraz uzgodnij trwały manifest (`PRODUCTION_SPLIT_SERVICES=false`,
   `PRODUCTION_WEB_REPLICAS=1`, `PRODUCTION_MEDIA_WORKER=false`) i obejrzyj
   plan usunięcia zbędnych usług. Nie wykonuj ślepo apply tylko po zmianie flagi.
6. Potwierdź, że jest tylko jeden `all`, wszystkie kolejki mają odbiorcę,
   harmonogram tyka, domena odpowiada i metryki wróciły do poziomu sprzed zmiany.

**Ile trwa:** nie zmierzono na Railway, więc nie ma gwarantowanego RTO.
Do zaplanowania okna przyjmij **10–20 minut z gotowym obrazem**, jako szacunek
operacyjny do zastąpienia wynikiem próby stagingowej. Czas = zmniejszenie
replik + start/healthcheck `all` + zatrzymanie usług + kontrola funkcjonalna.
Healthcheck web ma limit 180 s; samo to nie obejmuje budowania ani kolejki CI.
Z oczekiwaniem na build/CI operacja może trwać dłużej i nie ma tu górnej gwarancji.

Istniejący worker ma `drainingSeconds=120`, eksport może trwać 900 s,
`retry_after=960`. Nie wolno obiecać bezstratnego dokończenia każdego eksportu
w 120 s: przed zatrzymaniem sprawdź aktywne eksporty; po przerwaniu job może
czekać do ok. 16 minut na odzyskanie rezerwacji. Media mają okno 150 s przy
limicie joba 120 s. Sam powrót HTTP nie oznacza zakończenia odzyskiwania kolejki.

## 6. Decyzje właściciela i niewykonane czynności

- Budżet miesięczny: historyczne **12–18 → 40–65 USD/mies.** jest
  [szacunek cudzy: #595 / komentarz w IaC], nie aktualna oferta ani rachunek.
  Koszt drugiego web/media wymaga wyceny rzeczywistego zużycia i planu Railway.
- Drugi web: koszt w zamian za odporność warstwy HTTP i/lub przepustowość;
  podstawą odbiór #595 i pomiary, nie liczba osób online.
- Media: dodatkowy koszt w zamian za izolację przetwarzania zdjęć;
  wymagany sygnał z kolejek/CPU/RSS opisany w #600.
- Okno przełączenia, dopuszczalna przerwa i RTO po próbie stagingowej.
- Aktualny plan adopcji usług i zakres jawnie zatwierdzonych różnic IaC.

Nie wykonano push, PR, zamknięcia issues, Railway apply, zmian zmiennych,
próby na stagingu/produkcji, wysyłki listów ani bieżącego pomiaru produkcyjnej
puli. Zakres sesji to przygotowanie i lokalna weryfikacja. Wyniki własnych
kontroli i ich ograniczenia: `ROZDZIELENIE_ROL_595_600_POMIARY.md`.
