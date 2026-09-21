# Plan techniczny przed wzrostem — uzgodnienie #614 z kodem

**Kanonicznym planem pozostaje [#614](https://github.com/woogitsu/kuking.pl/issues/614),
oparty na niezależnym audycie z 16.09.2026.** Ten dokument jest datowanym
uzgodnieniem jego zaleceń z repozytorium i stanami zgłoszeń, nie nowym audytem
ani konkurencyjną listą zadań. Zakres: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`,
odczyt GitHub 20.09.2026. Podstawą reguł projektu pozostaje AGENTS.md.

Nie wdrażamy zaleceń audytu kodem w ramach tego uzgodnienia. Nie zamieniamy
otwartego checkboxa w dowód braku implementacji ani zamknięcia issue w dowód
działania produkcji. Nazwane niżej odbiory pozostają wymagane.

## Kierunek i kolejność

Zostaje modularny monolit Laravel 13/PHP 8.4/PostgreSQL, Blade, Alpine,
Tailwind/Vite; Livewire tam, gdzie pomaga złożonemu formularzowi, R2 dla
mediów i FrankenPHP na Railway. Nie ma podstaw do przepisywania produktu,
mikroserwisów, Redisa „na zapas” ani cache wszystkiego. Miernikiem wzrostu
nie jest sama liczba osób online, lecz RPS, współbieżność, opóźnienie,
CPU/RSS, czas i blokady bazy, połączenia, kolejka, błędy, koszt oraz
zaakceptowane SLA/RPO/RTO.

| Etap | Praca i zależność | Warunek zakończenia, który można sprawdzić |
|---|---|---|
| 1. Dane i uruchomienie | #596 → #595, równolegle #120 + #594 + #193 + #617 + #619 | Brak volume blokującego replikację aplikacji; odebrany podział web/worker/scheduler; bramka prawdziwego R2; odtworzona kopia bazy i mediów poza źródłową awarią; potwierdzona jurysdykcja R2. |
| 2. Widoczność kosztu i awarii | #599 + #598 przed zwiększaniem replik w #600 | Szeregi czasowe i odbiór alertu przez właściwy kanał; jawny budżet połączeń obejmujący web, worker, scheduler, backup i rezerwę. Sam logger ani harmonogram to za mało. |
| 3. Granica obecnego układu | #605, następnie decyzje #600; #612 dopiero po podziale usług, obserwowalności i #601 | Wiarygodna seria obciążenia z odrzuceniem zakłóconych prób, punkt nasycenia i koszt; dopiero potem wybór replik/PgBouncer/Octane. |
| 4. Media | #601 → obserwacja i worker mediów → #613 → #602; #119 według potrzeby | Odbiór jednego dekodowania na produkcji, RAM/czas/błędy; porównanie silników na tej samej próbce; kwarantanna bez ujawnienia oryginału. HEIC nie jest powodem do przebudowy całego pipeline bez danych. |
| 5. Feed i frontend | #585 → #609 oraz #607/#608; CI według #611 | Nie powtarzać zakończonego benchmarku bez nowego pytania. Zachować decyzję o obecnym zapytaniu; frontend zgodny z rzeczywistym użyciem, nie obietnicą globalnej migracji na Livewire. |

Etapy nie znaczą, że trzeba czekać z dokumentacją do końca DR. Znaczą, że
zwiększanie obciążenia i skali nie omija zabezpieczeń danych ani pomiarów.

## Zalecenia audytu a to, co już wykonano

Stan issue to własny odczyt API. Informacje o wykonaniu produkcyjnym, jeśli
pochodzą od innego autora, są oznaczone. Odczyt pliku nie jest pomiarem usługi.

| Obszar i zgłoszenia | Stan 20.09 | Dowód i rozjazd względem planu z 16.09 | Następny krok |
|---|---|---|---|
| Volume #596 | CLOSED | [pomiar cudzy: odbiór #596 z 17.09](https://github.com/woogitsu/kuking.pl/issues/596#issuecomment-5705280772) volume odłączono, nie usunięto; zachowano kopię. [Uzupełnienie dowodów #596/#606](WERYFIKACJA_ZAMKNIEC_606_596.md) zawiera daty, historię i własny odczyt konfiguracji Railway. Zalecenie wykonane, nie wraca na listę implementacji. | Zachować zasady retencji kopii; nie kasować volume „porządkując” audyt. |
| Podział procesów #595 | OPEN | `.railway/railway.ts` ma `PRODUCTION_SPLIT_SERVICES = true`, ale dokumenty odbioru mówią o jednej usłudze `all`. Flaga konfiguracji nie dowodzi zastosowania. | Odbiór wdrożenia web/worker/scheduler i ich zdrowia, po przygotowaniu operacyjnym. |
| DR bazy #594, kopia offsite #193, media #617, R2 #619 | OPEN | Kod backupu i lokalne roundtripy istnieją. [pomiar cudzy: `PRZEKAZANIE_PRAC_OPERACYJNYCH_2026_09_18.md`] nie odebrano produkcyjnej kopii/alertu ani produkcyjnego RTO. `LOKALIZACJA_DANYCH_R2.md` nie potwierdza jurysdykcji z panelu. | Aktywacja, odtworzenie izolowane, czasy i alarm; jurysdykcja `eu` zamiast zgadywania z location hint. Bucket Lock nie jest niezależnym backupem. |
| Monitoring #599, budżet #598 | OPEN | Jest kanał `pomiary` w `config/logging.php`, komendy i harmonogram w `routes/console.php`. [pomiar cudzy: `WERYFIKACJA_BUDZETU_POLACZEN_598.md`, 19.09] obserwowano 1×all, 4 wątki, 497 dostępnych połączeń i brak odbioru webhooka. To nie dowód obecnego stanu produkcji. | Odbiór alertów i szeregów po rozdziale usług, następnie ponowne liczenie budżetu. |
| Skalowanie #600 | OPEN | Pozostaje decyzją po pomiarze; przygotowane IaC nie jest dowodem potrzeby replik ani PgBouncer. | Decyzja na podstawie #598/#599/#605, z ceną i granicą kosztu. |
| Kolejka #606 | CLOSED | [#606](https://github.com/woogitsu/kuking.pl/issues/606) zamknięto 16.09 po odczycie frameworka, bez commita zamykającego. Własna próba na wersji z locka: `DatabaseQueue::pop()` ominęło zablokowany rekord; kontrola bez `SKIP LOCKED` zakończyła się `55P03`. [Dowód i granice](WERYFIKACJA_ZAMKNIEC_606_596.md). Nie ma kolejnej implementacji do wykonania. | Zachować kontrolę zachowania przy rzeczywistych workerach. |
| Feed #585 i #609 | CLOSED | Bieżący `FollowingFeed` nadal używa `pluck` → `whereIn` i dynamicznych liczników. To zgodność z decyzją, nie zaległe przepisanie. [pomiar cudzy: #609, #628 / `e951554c`, CI `35117632571`] pomiar zakończony, wybrano pozostawienie rozwiązania. | Nowy benchmark dopiero przy nowej próbce/obciążeniu. JIT pozostaje hipotezą, nie nakazem zmiany produkcji. |
| Frontend #607 | CLOSED | AGENTS.md opisuje Blade/Alpine oraz Livewire w kreatorze. Ogólna lista warstw w ARCHITECTURE.md nie rozszerza tego zakresu na wszystkie ekrany. | Nie budować migracji frameworka z historycznego opisu stacku. |
| Dysk Livewire #608 | CLOSED | Jawna konfiguracja dysku jest w manifestach. [pomiar cudzy: #608, komentarz `5733174838`, 18.09] potwierdzono jawne i efektywne `r2` w konsoli. Tytuł sugerujący niedokończony odbiór jest starszy niż aktualizacja opisu. | Nie otwierać ponownie tego samego zadania; wynik konsoli nie zastępuje odbioru całego uploadu i workera. |
| Jedno dekodowanie #601 | OPEN, część kodowa wykonana | `ProcessUploadedImage` czyta oryginał przed pętlą wariantów, kolejne tworzy z obrazu natywnego. #625 wdrożył zmianę; dokument odbioru rozróżnia pomiar lokalny i brak produkcyjnego. | Odbiór produkcyjny RAM/czas/kolejka/błędy; nie implementować ponownie dekodowania. |
| Obciążenie #605 | OPEN | W repo są werdykty `NIEWYKONANY`, m.in. `werdykt-r030-niewykonany.json`; zakłócony host nie dał wiarygodnej serii. Test przyrządu w CI nie mierzy pojemności aplikacji. | Zapewnić warunki pomiaru i wykonać serię; nie wyliczać limitu użytkowników z prób odrzuconych. |
| Octane #612 | OPEN | W audycie jest warunkowe wobec #605; nie staje się obowiązkiem przez samą obecność issue. | Porównać dopiero po stwierdzeniu wąskiego gardła. |
| Silnik mediów #613, upload #602 | OPEN | Kolejne etapy, nie przesłanka do pominięcia #601 i monitoringu. | Próbka i koszt pamięci/czasu; projekt kwarantanny i odbiór ochrony oryginału. |
| CI #611 | OPEN, częściowo wykonane | #783 scalił pojedynczy filtr i composite action. Ten pakiet usuwa tylko trzy nieużywane pełne historie; [mapa](UPROSZCZENIE_CI_611.md) obejmuje 20 jobów. | Decyzje o ochronie gałęzi/retencji oraz dozwolony przebieg zdalny, bez osłabiania testów. |
| Bramka R2 #120 | OPEN, część aplikacyjna wykonana | Aktualne issue wskazuje własny adapter bez ACL i prywatne warianty, a nie powrót do publicznego CDN. [Procedura](BRAMKA_R2.md) wymaga rzeczywistego medium i kompletu obecnych/historycznych hostów. Brak własnego odbioru prawdziwych bucketów. | Odczyt podpisany działa, niepodpisany odmawia, warianty nie mają EXIF, zapis bez ACL działa; osobno panel i #619. Nie budować drugiego adaptera. |
| Cache zdjęć #597 | OPEN | `MediaController` sprawdza dostęp, przekierowuje na signed URL i różnicuje `public` oraz `private, no-store`; przenosi też Cache-Control na odpowiedź R2. Stan reguł Cloudflare nie wynika z pliku. | Odczytać panel, zmierzyć ruch; przed dłuższym TTL jawnie wybrać akceptowane okno po zmianie prywatności/blokadzie. |
| Cache publicznych stron #610 | OPEN, po #597 | Opis opiera stan brzegu na dokumentacji, nie na aktualnym odczycie Cloudflare. Nie odebrano konfiguracji brzegu w tej pracy. | Wykluczyć sesje, odebrać zmianę widoczności i unieważnianie; żadnego ogólnego Cache Everything. |
| Redis/Valkey #603 | OPEN, warunkowe | Konfiguracje `queue`, `cache` i `session` domyślnie wskazują `database`; to zgodne z planem. Brak własnego pomiaru uzasadniającego migrację. | Najpierw udział SQL infrastrukturalnego, kolejka i locki; jeśli potrzeba się potwierdzi, queue/cache/locks przed sesjami. Horizon dopiero z Redisowym backendem. |
| HA/read scaling #604 | OPEN | Issue rozdziela dostępność funkcji platformy od kosztu, failover i potrzeby odczytowych replik. Nie wykonano lokalnie ani produkcyjnie testu HA. | Koszt, budżet połączeń, kontrolowany failover stagingu i reconnect aplikacji; nie zakładać, że HA samo rozwiązuje skalowanie odczytów. |
| TCO/SLA hostingu #615 | OPEN, warunkowe | Audyt odrzuca migrację „na przyszłość”; brak nowego pomiaru wskazującego dostawcę zastępczego. | Wrócić dopiero przy mierzalnej przeszkodzie, porównać cały koszt operacyjny i ten sam profil #605, nie same ceny CPU. |

## Rozjazdy dokumentacyjne, które trzeba widzieć

1. Niezaznaczone pozycje historycznego #614 nie opisują dzisiejszego braku
   kodu: #596/#606/#585/#609/#607/#608 są zamknięte. Zamiast ponownie je
   realizować, przy aktualizacji zgłoszenia wpisać źródło zakończenia i
   oddzielić brakujące odbiory, szczególnie całego uploadu.
2. `POMIAR_FEEDU_585.md` zachował etap „niewysłane / brak CI / otwarte
   zgłoszenia”; późniejszy opis #609 i merge #628 dokumentują zakończenie.
   Historyczny dokument nie unieważnia późniejszej decyzji o pozostawieniu
   `pluck/whereIn` i dynamicznych liczników.
3. `ODBIOR_JEDNEGO_DEKODOWANIA_601.md` opisuje filtrowanie pomiarów kolejki
   przez poziom warning. Późniejszy kanał `pomiary` (historia `0c96de71`)
   rozwiązuje część kodową tego problemu. Nadal brakuje pomiaru produkcyjnego;
   nie wolno z tego zrobić zdania „monitoring jest odebrany”.
4. `UPROSZCZENIE_CI_611.md` §7 opisuje brak narzędzi i zdalnego uruchomienia,
   choć §5.7 dokumentuje późniejszy wynik. Dodano oznaczenie historycznego
   zakresu §7. Nowy pakiet ma własne, osobno opisane dowody.
5. Opisy starej puli runnerów i dziewięciu jobów nie są bieżącą konfiguracją.
   Własny odczyt: 13 jobów CI, 20 we wszystkich workflowach, zmienna repo
   `CI_RUNS_ON = "ubuntu-latest"`. Nie przepisano starej decyzji właściciela
   tak, jakby od początku wybierała dzisiejszą wartość.
6. Przygotowany podział usług, harmonogram alarmów, narzędzie backupu i
   test obciążeniowy to **cztery przygotowania**, nie cztery odebrane
   operacje. Ich pomylenie zmieniłoby kolejność planu bez jawnej decyzji.
7. #597 nazywa wydłużenie publicznego podpisu zmianą bez wpływu na model
   zagrożeń. Bieżący komentarz w `MediaController` wprost opisuje TTL
   jako górną granicę opóźnienia zmiany prywatności, blokady lub moderacji.
   To **pytanie o akceptowane okno**, nie usterka do zamknięcia asercją.
   Krótsze okno kosztuje więcej żądań; dłuższe zwiększa czas dostępności
   wcześniej podpisanego adresu. Właściciel wybiera granicę po pomiarze.

To ten sam rodzaj problemu, który ujawnił
[PR #787](https://github.com/woogitsu/kuking.pl/pull/787), scalony jako
`def9534a`: historia decyzji wymaga adnotacji, nie cichego nadpisania.
[pomiar cudzy: opis PR #787 — 16 cicho odwróconych decyzji]. Nie powtórzono
tu całego audytu 202 wpisów; porównanie dotyczy zaleceń #614 i ich zależności.

## Decyzje właściciela i granica gotowości

- **RPO/RTO i koszt DR:** wybrać akceptowaną utratę czasu danych i czas
  odtworzenia oraz niezależną lokalizację kopii. Krótszy RPO/RTO zwiększa
  koszt i częstotliwość operacji; lokalny czas przywrócenia nie wybiera SLA.
- **Odbiór infrastruktury:** wskazać okno zastosowania podziału usług,
  osobę/kanał odbierający alarm i budżet kosztowy. Pozostawienie jednej
  usługi oszczędza operację, ale zachowuje wspólny obszar awarii procesów.
- **Wzrost:** po #605 wybrać rozmiar/liczbę usług według kosztu i wyników;
  wariant pozostania przy obecnym układzie jest prawidłowy, jeśli spełnia
  cele. Octane/PgBouncer/nowy silnik nie są z góry zwycięzcami.
- **CI:** ochrona main i los starych niescalonych gałęzi wymagają wyboru
  opisanego w [mapie CI](UPROSZCZENIE_CI_611.md). Nie zamieniono wyboru w asercję.

Nie zmieniono issue ani produkcji, nie publikowano komentarzy. Dokument
jest gotowym materiałem do aktualizacji kanonicznego #614 przez właściciela
lub kolejkę integracyjną. Pozostawienie issue otwartego do odbioru tych
decyzji nie oznacza, że należy powtarzać wykonane poprawki.
