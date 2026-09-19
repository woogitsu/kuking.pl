# Panel moderacji — port marki #581

Status: **podstawowy port wdrożony; odbiór całości częściowy**.
Pierwotna gałąź fix/581-marka-panelu miała podstawę ac5ff9d7716d000318870a2120522fdd7930303a.
PR #587 został scalony. [Odbiór produkcyjny dziewięciu sekcji na Alfa 0.45 / e30492c](https://github.com/woogitsu/kuking.pl/issues/581#issuecomment-5704855089)
potwierdził nową oprawę i wskazał długą nawigację mobilną jako pozostałe tarcie.
Nie był odbiorem wszystkich operacji, walidacji ani stanów 2FA.

## Bramki dostępu na produkcji — 18 września 2026

Odczyt `https://kuking.pl`, 13:12 UTC, wyłącznie HTTP GET bez sesji.
Stopka **w chwili pomiaru**: `Alfa 0.65 · wydanie 18 września 2026, 12:12 ·
55877e2`. Kontrola powtórzona o 19:01 UTC na `3f315b3` (Alfa 0.67) — patrz
koniec tej sekcji; tamten przebieg ma zapis maszynowy, ten jest prozą.
**Nie logowano się, nie wykonano żadnej decyzji moderacyjnej, nie zmieniano
ustawień konta ani 2FA.**

Wszystkie dziewięć pozycji nawigacji panelu odpowiada gościowi
przekierowaniem 302 na `https://kuking.pl/login` — bez różnicowania
odpowiedzi, czyli bez wycieku informacji o istnieniu rekordów:

| trasa | odpowiedź |
| --- | --- |
| `/admin/zgloszenia` | 302 → `/login` |
| `/admin/sygnaly` | 302 → `/login` |
| `/admin/wiadomosci` | 302 → `/login` |
| `/admin/odwolania` | 302 → `/login` |
| `/admin/bez-odpowiedzi` | 302 → `/login` |
| `/admin/kolaz-powitalny` | 302 → `/login` |
| `/admin/kuking-na-dzis` | 302 → `/login` |
| `/admin/tagi-promowane` | 302 → `/login` |
| `/admin/uzytkownicy` | 302 → `/login` |

Trasy szczegółowe i z parametrami zachowują się tak samo, również dla
zmyślonego UUID — `/admin/uzytkownicy/{uuid}`, `/admin/wiadomosci/{uuid}`,
`/admin/zgloszenia?strona=2`, `/admin/odwolania?stan=rozpatrzone`:
wszystkie 302 → `/login`. Ustawienia `/ustawienia/2fa` również: 302 →
`/login`.

Adresy „admin" bez dalszego członu oraz „admin/tagi" zwracają 404 — i to
jest poprawne, bo takich tras nie ma (`routes/web.php`: spis tagów stoi
publicznie pod `/tagi`, a panel ma wyłącznie `/admin/tagi-promowane`).
Nie jest to niespójność bramki.

### Czego ta kontrola NIE pokazuje

- **Odmowy dla osoby zalogowanej bez uprawnień** — do tego trzeba konta bez
  roli moderatora. Nie było takiej sesji i nie zakładano konta.
- **Bramki 2FA w działaniu** — `moderator.2fa` stoi za `moderator`, więc
  gość nigdy do niej nie dociera. Bez uprawnionej sesji nie da się jej
  zobaczyć.
- **Szczegółów zgłoszeń i odwołań z decyzjami** — wymagają uprawnionej
  sesji, a treści decyzji nie wolno wytwarzać na produkcji.

Brak sesji jest **granicą dowodu, nie wynikiem pozytywnym**. Pozostałe
warunki odbioru #581 z opisu issue są w tej części nadal niespełnione.

## Kontynuacja mobilnej nawigacji — 17 września 2026

Na gałęzi `fix/581-menu-mobilne`, na podstawie `89c45e69b895c4a65defda938da6e855d8a6371e`,
przygotowano zwijanie istniejącego spisu narzędzi poniżej 64rem.
Przycisk „Wróć do Kuking” pozostaje widoczny. Bez JavaScriptu cały spis pozostaje dostępny.
Zmiana jest lokalna, jeszcze niewysłana i niewdrożona.

Nowy odbiór jest odrębny od historycznych wyników poniżej: przebieg zakończył
się wynikiem **600/600** (288 pustych i 312 z danymi). Po drobnej korekcie
etykiety przycisku wykonano celowany odbiór końcowych źródeł:

- [8 scenariuszy menu](evidence/menu581/menu-dodatkowe.json): zmiana szerokości i zachowanie fokusu, brak JavaScriptu z rzeczywistym przejściem linkiem, emulowany dotyk oraz cleanup i podwójna ponowna inicjalizacja Livewire;
- [4 pomiary zoomu 200%](evidence/menu581/menu-zoom200.json): rzeczywiste `setZoom/getZoom`, szerokość CSS 320/720, oba motywy, tekst 140%, 18/18 kontrolek w przejściu Tab;
- [2 fizyczne kontrole ujemne](evidence/menu581/menu-negatywy.json): zmiana JS i CSS wykryta przez regresję, przywrócone MD5 i mtime oraz dodatni wynik po odtworzeniu;
- ogląd [zwiniętego menu](evidence/menu581/menu-zwiniete-light-100.png) i [poprawionej etykiety przy zoomie](evidence/menu581/menu-zoom200-640-dark.png).

Pierwszy wariant próby ujemnej CSS przerwał runner bez rozstrzygającej diagnozy;
nie zaliczamy go. Końcowa próba ukrycia przycisku wykazała błąd oczekiwanej
asercji. Kopie źródeł przechowano poza repo, wcześniejsze bazy i dowody zachowano.
Niezależne review kodu nie znalazło blockera; wskazany brak testu cyklu Livewire
uzupełniono i wykonano z wynikiem dodatnim. Przygotowano Alfę 0.50, changelog
oraz D-220. Pozostają hook, CI i odbiór wdrożenia.
Historyczne 600/600 i pomiary zoomu pierwotnego portu nie są dowodem nowego menu.
Zakres pozostałych powierzchni opisuje [audyt marki](AUDYT_POZOSTALOSCI_MARKI_2026_09_15.md).
Dokładną kolejkę nieodebranych rozwinięć, alternatywnych kompozycji i walidacji
opisują [stany panelu](PANEL_STANY_ROZWINIETE_581.md). To wynik odczytu kodu,
nie wykonania wymienionych scenariuszy.

## Końcowy odbiór lokalny pierwotnego portu (historia)

- Jedna świeża izolowana sesja obu faz: **600/600** konfiguracji PASS.
- Rzeczywisty zoom 200%, tekst 140%: **26/26** głównych ekranów i **12/12** dodatkowych stanów PASS; zakres asercji podany przy dowodach poniżej.
- Walidacja jedenastu formularzy w obu motywach przy rzeczywistym zoomie 200%: **22/22** PASS, badane tabele bez zmian.
- Najnowsza kontrola PHP: **24 testy / 152 asercje**, Pint dwóch plików PASS. Bajtowo porównano istotne źródła runtime z worktree przed tym przebiegiem.
- Fizyczne negatywy mają dowody przywrócenia źródeł. Log negatywu podsumowania błędów zawierał lokalne tokeny CSRF w HTML; przed publikacją zastąpiono siedem wartości znacznikiem `[REDACTED_LOCAL_CSRF]`. MD5/mtime w raporcie opisują źródło Blade, nie zredagowany log.
- Niezależne review nie znalazło blokera kodu; redakcja tokenów i uporządkowanie raportu zakończone. PHPStan: 0 błędów. W chwili tego historycznego pomiaru hook, push, CI, merge i odbiór produkcji pozostawały do wykonania; późniejsze scalenie i zakres odbioru wskazano na początku raportu.

Poniższe sekcje zachowują historię prób. Ich dawne sformułowania „pozostaje” i „w toku” nie zastępują późniejszych, konkretnych wyników. Wyniki nie obejmują wszystkich poprawnych operacji moderacyjnych ani wszystkich możliwych komunikatów błędów.

## Potwierdzony problem i zmiana

Zrzut właściciela i odczyt kodu potwierdzają pozostawienie dawnej oprawy panelu. Wspólna rama nowej marki wykluczała data-tryb-panelu. Nowy, odrębny arkusz marka-panel.css obejmuje ramę, nawigację, oznaczenie trybu, karty, formularze, filtry i tabelę. Zachowano dwie kolumny na szerokim ekranie, brak pustej prawej szyny oraz jawny spis narzędzi na telefonie. Zakładki kolejki korzystają ze wspólnego wyglądu. Bramka 2FA ma własną oznaczoną sekcję. Trasy, autoryzacja i operacje domenowe pozostają bez zmian.

Nie uznajemy tego opisu za dowód odbioru. Pełne i puste stany, walidacja, rozwinięte działania, klawiatura oraz rzeczywisty zoom wymagają pomiarów i oglądu.

## Wykonane kontrole — 15 września 2026

- Build Vite i 72 pary kontrastu PASS dla pierwszej implementacji; CSS app-hYMX7KhZ.css, JS app-BlSF1GKB.js.
- 36 testów / 239 asercji PASS: PanelModeracjiWMenuTest, PanelBezOdpowiedziTest i rodziny kolejki gospodarza. Test oznaczenia panelu nadal wymaga klasy panel-pasek pod main oraz tytułu, dopuszcza dodatkowe klasy.
- Pierwsze dwa lokalne przebiegi miały jedną porażkę: 32 zamiast 30 godzin. Nowa baza odziedziczyła strefę klastra; dotychczasowa baza testowa miała TimeZone=UTC. Po ustawieniu UTC wyłącznie w obu nowych bazach wynik jest poprawny. Nie zmieniono kodu obliczeń, danych oczekiwanych ani progów testu.
- Kontrola składni nowego miernika i git diff --check PASS. Nie oznacza to przejścia miernika w przeglądarce.

## Izolacja środowiska

Runtime /home/mateusz/kuking-panel581-runtime ma osobne kopie vendor i node_modules. Źródła pochodzą z worktree kuking-panel581; APP_BASE_PATH jest jawne. PostgreSQL 127.0.0.1:55439, właściciel kuking, bazy kuking_581_tests oraz kuking_581_browser, obie UTC. Baza przeglądarkowa rozpoczęła odbiór od samych migracji. Następnie fixture przygotowała puste i pełne stany bez kasowania wcześniejszych rekordów. Obecnie zawiera pełne lokalne dane demonstracyjne; nie ma danych produkcji. Dodatkowy fixture alternatywnych stanów jest przygotowany, ale jeszcze nie został uruchomiony.

## Pozostaje

Końcowy wspólny przebieg obu faz miernika oraz dodatkowych stanów; rzeczywisty zoom 200%; ogląd odmiennych kompozycji, walidacji i otwartych działań; fizyczne negatywy źródeł z kopią poza repo, MD5/mtime i dodatnim wynikiem po przywróceniu; końcowy review; wersja i changelog; hook, PR, CI, merge oraz potwierdzenie Railway i produkcji. Pełny port marki pozostaje CZĘŚCIOWO.

Pierwsze uruchomienie fixture pustej fazy ujawniło zapamiętaną pustą relację profilu po fabryce. Transakcja wycofała dane (users=0, profiles=0), plik stanu nie powstał. Fixture odświeża teraz model i instaluje bezpieczny handler błędów po bootstrapie. Ponowne uruchomienie przygotowało 12 pustych scenariuszy; oba konta zalogowano rzeczywistym formularzem, administrator dodatkowo kodem TOTP. Nie tworzono sesji z pominięciem autoryzacji.

Pierwsze oglądy: Zgłoszenia na 1440 i 320 px. Zauważono zawijanie etykiety Wiadomości do nas pod ikonę; poprawka w toku. Pomiar nadal niezaliczony: dopracowano błędy sond koloru i zaokrąglonych narożników; badane jest oczekiwanie na rzeczywisty końcowy kolor pierścienia. Obejrzenie tych dwóch zrzutów nie jest odbiorem całego panelu.

## Aktualizacja odbioru lokalnego

Faza pusta zakończyła się PASS: 288 konfiguracji (12 scenariuszy, sześć szerokości, dwa motywy, tekst 100/140%). To pomiar GET, geometrii i początkowo widocznych kontrolek; nie obejmuje wysyłania formularzy ani rzeczywistego zoomu.

Zakończono przygotowanie pełnej fazy: 13 scenariuszy. Jej pomiar zatrzymał się na widoczności fokusu kontenera tabeli użytkowników przy 320 px / jasnym motywie / 100%. Przyczyna jest badana; faza pełna nie jest zaliczona.

Rzeczywista kontrola Tab ujawniła brak obrysu podczas przejścia na natywną ikonę kalendarza. Pole zachowuje wtedy :focus-within, ale traci :focus-visible. Wąska reguła panelu zachowuje niebieski obrys. Ponowna próba ośmiu przejść Tab w obu datach pokazała obrys we wszystkich ośmiu; fizyczna kontrola ujemna pozostaje do wykonania.

Niezależny ogląd dziesięciu PNG wskazał zbyt wąskie pola dat i zawijanie ich opisów przy 1440 px / 140%. Kadry mobilne początku strony pokazują nawigację, a nie treść poniżej; konieczny jest osobny ogląd po przewinięciu. Nie uznajemy samych zrzutów początku strony za odbiór mobilnego panelu.

Osobny job port_panelu i runner przygotowano do 600 konfiguracji obu faz. Niezależny odczyt nie wskazał blokera izolacji ani kompletności. Czas pełnego joba nie został jeszcze zmierzony. Runner odmawia startu przy niepustym katalogu dowodów, aby nie pozostawiać mylących raportów z poprzedniego przebiegu. CI tego pakietu nie zostało jeszcze uruchomione.

## Pełna faza i ogląd po poprawkach

Proces lokalny 68488 zakończył się kodem 0: **312/312 PASS** (13 scenariuszy,
sześć szerokości, dwa motywy, tekst 100/140%). Raport lokalny:
`output/panel581/wyniki-pelny.json` w repo kanonicznym, SHA256
`244ce5deb70531ccc125f1a03a03cd2b8d569250ac14b765ee31e5ec08a1e780`.
Badane assety: CSS `app-BCXL_liv.css`, JS `app-BfcwSyV6.js`.
Przebieg obejmuje ograniczenie wysokości przewijanej tabeli oraz odsłanianie
kontrolki przy przejściu Tab wewnątrz jej obszaru. Nie zastępuje kontroli
ujemnych, prawdziwego zoomu ani odbioru rozwiniętych działań.

Ogląd zrzutów treści Użytkowników (1440 px, jasny, 140%) i szczegółu wiadomości
(320 px, ciemny, 140%) potwierdził poprawę szerokości pól dat. Ujawnił jednak
łamane wewnątrz słów etykiety bocznej nawigacji przy dużym tekście, m.in.
„Zgłoszenia” i „Bez odpowiedzi”. To rzeczywisty brak czytelności mimo dodatniego
pomiaru geometrii; wymaga poprawki i rozszerzenia regresji. Zrzut wiadomości
pokazuje też nakładanie stałych elementów na dolną część kadru; pełny odbiór
osiągalności ostatnich treści i kontrolek pozostaje otwarty.

Nie sumujemy starszych 288 pustych konfiguracji z obecnymi 312 jako końcowego
odbioru 600: pusta faza poprzedza ostatnie poprawki CSS/JS i wymaga powtórzenia
na tych samych końcowych źródłach.

Po tym oglądzie przygotowano skalowanie szerokości bocznej kolumny wraz
z tekstem (minimum 16rem) oraz kontrolę rzeczywistego podziału pojedynczych
słów przez DOM Range. Składnia miernika i diff przeszły kontrolę. Ta ostatnia
zmiana **nie ma jeszcze wyniku przeglądarkowego ani kontroli ujemnej**;
312/312 powyżej dotyczy źródeł sprzed jej wprowadzenia. Odbiór wstrzymano
na czas niezależnego pomiaru obciążenia, aby nie zaburzać jego wyników.

## Kontrole ujemne i rzeczywisty zoom — wykonane po pomiarze obciążenia

Cztery fizyczne mutacje źródeł runtime zostały wykryte przez regresje:
powrót stałej szerokości 16rem, usunięcie obrysu daty, usunięcie ograniczenia
wysokości tabeli i wyłączenie obsługi odsłaniania linku w tabeli.
Każda próba wykonała dodatni baseline, build zmienionego źródła, oczekiwany
błąd, przywrócenie z kopii poza repo, kontrolę MD5/mtime oraz ponowny build
i dodatni pomiar. [Dowody](evidence/panel581/negatives/rail/report.json)
obejmują osobne katalogi `rail`, `date`, `table-height`, `table-focus`.
Kod błędu daty ma separator `P581_FOKUS {`, aby nie pomylić go z błędem
zasłonięcia `P581_FOKUS_ZASLONIETY`.

Rzeczywisty zoom Chromium ustawiony przez `chrome.tabs.setZoom(2)`:
**26/26 PASS**, 13 pełnych scenariuszy w obu motywach, tekst 140%,
320 CSS px, DPR 2 i przeliczony font 25,2 px. Wspólna kontrola panelu
uwzględnia jedno wejście Tab w grupę radio i przejście strzałkami.
[Raport zoomu](evidence/panel581/zoom-200.json) nie obejmuje rozwijanych sekcji
ani poprawnego wysyłania formularzy. Obejrzano również raster użytkowników
przy tym zoomie i jasny desktop po poszerzeniu nawigacji: słowa w etykietach
nie są już rozcinane.

Osobna próba rozwiniętych sekcji dała **22/24 PASS**. Dwa wyniki dotyczą
końca wiadomości przy 1440 px / 140%: przycisk Wygląd pokrywa część ostatniej
linii po `scrollIntoViewIfNeeded`. Trwa rozróżnienie błędu aplikacji od
niewystarczającego przewinięcia w sondzie. Nie traktujemy tych dwóch stanów
jako odebranych ani nie zmieniamy progów widoczności.

Końcowy odbiór rozwiniętych details: 24/24 PASS, zero żądań zapisujących i zewnętrznych (dowód evidence/panel581/details-24.json). Trzy kompozycje, 320 i 1440 px, oba motywy, tekst 100/140%. Test zachowuje pomiary przed/po naturalnym przewinięciu i wymaga widoczności wszystkich linii akapitu w jednej osiągniętej pozycji. Wcześniejsze wyniki 22/24 oraz 20/24 nie oznaczały błędu aplikacji: pierwszy miernik zatrzymywał przewijanie przed końcem, drugi niepotrzebnie wymagał widoczności akapitu main na końcu wysokiej stopki. Odbiór nie obejmuje zoomu ani walidacji POST. Końcowa macierz 312 z nowym pomiarem nazw nawigacji jest obecnie w toku, bez deklaracji sukcesu.

Końcowy przebieg z pomiarem słów zatrzymał się na 222. konfiguracji: 221 PASS, 1 FAIL, 90 niewykonanych. Przy 768 px / light / 140% raster potwierdził rozcięte słowa odpowiedzi i Wiadomości w trzech kolumnach nawigacji. Minimalna szerokość kafla 13rem została powiązana ze skalą tekstu (z ograniczeniem do szerokości kontenera). To zmiana po wcześniejszych kontrolach: wymaga nowego buildu, odbioru, fizycznego negatywu i powtórzenia macierzy. Nie przypisujemy wcześniejszych wyników nowemu CSS.

## Końcowy odbiór po poprawce mobilnych kafli

Proces 91770 zakończył się kodem 0: **312/312 PASS**, po 24 konfiguracje każdego z 13 pełnych scenariuszy. Wspólny miernik zachował kontrolę słów przez DOM Range. Dowód lokalny `output/panel581/final312-mobile/wyniki-pelny.json` (repo kanoniczne), SHA256 `f5594bbd502f5d99da9142b3db89fcf461d290055aef657fa862aca57b0886f7`. Starszy przebieg 221 PASS / 1 FAIL / 90 niewykonanych pozostał osobno w `output/panel581/final312` i nie został nadpisany. Pusta faza nadal wymaga ponowienia na końcowych źródłach; nie deklarujemy końcowego 600/600.

Piąta fizyczna kontrola ujemna `nav-mobile` dotyczy **nowego CSS**, MD5 `4deaad0af4fbcc2de1663fbff6fef73d`, mtime_ns `1789498049309966700`. Powrót rzeczywistej reguły minimalnej szerokości kafla do stałego 13rem wykrył `P581_ROZCIETE_SLOWO_NAWIGACJI` na zgłoszeniach przy 768 px / 140% w obu motywach. Baseline 2/2 PASS, negatyw 2/2 oczekiwane FAIL (exit 1), dokładne przywrócenie bajtów/MD5/mtime, ponowny build i restored 2/2 PASS. [Raport piątego negatywu](evidence/panel581/negatives/nav-mobile/report.json); bezpieczne JSON/PNG i zapis diagnostyki są w tym katalogu. Kopia źródła pozostaje poza repo w `/home/mateusz/panel581-negative-z7k8ez1u/source.backup`. Cztery wcześniejsze katalogi nie zostały zmienione i dotyczą wcześniejszego stanu źródeł.

Obejrzano oba rastry `nav-mobile/restored/nav-mobile-light.png` i `nav-mobile/restored/nav-mobile-dark.png`: przy 768 px / 140% dwie kolumny pokazują pełne słowa. To ogląd przywróconego przypadku, nie deklaracja wizualnego obejrzenia wszystkich 312 PNG. Macierz mierzy szerokości CSS, oba motywy, tekst 100/140%, reducedMotion reduce, początkowo widoczne kontrolki Tab i grupy radio; nie zastępuje rzeczywistego zoomu, odbioru wszystkich stanów walidacji ani wysyłania formularzy. Podczas tego odbioru nie zmieniano danych.

## Świeża faza pusta — 15 września 2026

Na osobnej bazie `kuking_581_acceptance` (127.0.0.1:55439), po migracjach bez seedera, runner przeszedł logowanie i 2FA oraz zakończył fazę pustą: **288/288 PASS**. Dowód: [acceptance-pusty.json](evidence/panel581/acceptance-pusty.json), SHA256 `4fab792c14c95e7cda8d46a8e940769727e0166c1e0ff52de90acd62a8fbde4a`. CSS panelu zachował MD5 `4deaad0af4fbcc2de1663fbff6fef73d`. Faza pełna tego samego procesu nadal trwa, więc nie ogłaszamy jeszcze końcowego wyniku 600/600.

Zakres pozostaje opisany w JSON: początkowo widoczne kontrolki, Tab i strzałki grup radio, geometria i tekst. Ten przebieg nie obejmuje prawdziwego zoomu 200%, otwierania wszystkich zamkniętych sekcji ani wysyłania formularzy. Zrzuty dwóch mobilnych układów obejrzano; nie deklarujemy oglądu wszystkich rastrów.

## Zakończenie świeżego przebiegu

Proces 53429 zakończył się kodem 0: **600/600 PASS** — 288 konfiguracji pustych i 312 pełnych. [Agregat](evidence/panel581/acceptance-agregat.json) oraz [pełna faza](evidence/panel581/acceptance-pelny.json), SHA256 pełnego JSON `94efb403e94e98ccfc4cda33ea29ba77ba978e98486a0f0e5ec79da9005e377b`. Jest to jeden zakończony przebieg runnera na świeżej bazie, a nie suma historycznych testów różnych źródeł. Ograniczenia zakresu opisane wyżej pozostają aktualne: osobny zoom, dodatkowe stany i walidacja POST nie są objęte liczbą 600.

Po zakończeniu procesu uruchomiono `scripts/fixtures/panel-stany.php` w drugiej, dotychczasowej bazie `kuking_581_browser` na 55439. Utworzono sześć ścieżek dodatkowych stanów; prywatny indeks znajduje się poza repo. Brak wysyłki listów i wykonania akcji moderacyjnych — statusy historii są jawnie danymi poglądowymi. Samo przygotowanie tych danych nie jest odbiorem nowych ekranów.

## Walidacja formularzy i brak komunikatu sygnałów

Dodatkowe sześć ścieżek otrzymało 48/48 pozytywnych kontroli GET/geometrii (320/1440, oba motywy, tekst 100/140). Raport `evidence/panel581/stany-get-48.json` nie oznacza wykonania Tab, zoomu ani wszystkich stanów interaktywnych.

Dziesięć błędnych POST z rzeczywistą sesją i CSRF sprawdzono lokalnie: dwa formularze wiadomości, cztery wyborów oraz cztery moderacji. Raporty `walidacja-wiadomosci.json`, `walidacja-wyborow.json`, `walidacja-moderacji.json` zawierają skróty stanu odpowiednich tabel przed/po; były identyczne. Zakres: 1440 px, komunikat serwera, bez pełnej macierzy skal i fokusu.

Odtworzony brak: `/admin/sygnaly` nie pokazywało podsumowania błędów ukrytego pola `autor`. Dodano widoczną listę z role=alert i tabindex=-1, bez martwego linku do ukrytego pola. Obejrzano raster desktop: komunikat jest widoczny, a standardowy mechanizm skupia podsumowanie.

Regresja PHP: 4 testy / 28 asercji PASS. Test musi przenosić ciasteczko tej samej sesji JSON na kolejny GET; bez niego diagnostyka wykazała inny identyfikator sesji i pustą torbę błędów widoku. Nie zmieniano serializacji sesji. Fizyczne usunięcie nowego bloku Blade wykryto (exit 1), po odtworzeniu bajtów/MD5/mtime i wyczyszczeniu skompilowanych widoków ponownie 4/28 PASS. Dowód: `evidence/panel581/negatives/sygnaly-summary/report.json`. Czyszczenie cache jest konieczne po przywróceniu starszego mtime źródła; sam przywrócony plik nie unieważnia nowszej kompilacji Blade.

Przebieg 600/600 poprzedza dodanie bloku błędów; nowy stan błędu sprawdzono osobno. Nie przypisujemy mu automatycznie wcześniejszej kompletności. Nadal wymagane: rozszerzony mobilny odbiór walidacji i fokusu, końcowy zoom, review, hook i CI.

Mobilna walidacja czterech formularzy moderacji: **8/8 PASS**, 320 px, tekst 140%, oba motywy. Prawdziwe błędne POST z CSRF; niezmieniony stan danych, widoczne podsumowanie, aktywny fokus i dziewięć punktów kontroli zasłaniania wewnątrz podsumowania. Dowód `evidence/panel581/walidacja-mobile.json`. To nie jest pomiar wszystkich krawędzi obrysu ani wszystkich pozostałych formularzy. Obejrzano ciemny raster błędu sygnałów; pełnostronicowy zrzut nie odtwarza pozycji stałych elementów przy każdym przewinięciu, dlatego pomiar zasłaniania pochodzi z aktywnego viewportu.

Pozostałe sześć formularzy otrzymało mobilny odbiór przy 320 px / 140% w obu motywach: wybory 8/8 PASS, wiadomości 4/4 PASS. Z poprzednią czwórką moderacji daje to **20 konfiguracji błędnych POST dla 10 formularzy**, nie 20 różnych formularzy. Sprawdzono odpowiedź/przekierowanie, widoczny komunikat, aktywny fokus podsumowania, dziewięć punktów zasłaniania i zgodne skróty stanu tabel przed/po. Dowody: `walidacja-wyborow-mobile.json`, `walidacja-wiadomosci-mobile.json`, `walidacja-mobile.json`. Nie obejmuje to prawidłowego zapisu, wszystkich rodzajów błędów ani rzeczywistego zoomu.

Aktualny odbiór zoomu: proces 11250 zakończył się kodem 0, **26/26 PASS** dla 13 pełnych ekranów w obu motywach. Rzeczywiste `chrome.tabs.setZoom(2)`, odczyt zoom=2, 320 CSS px, DPR=2, tekst 25,2 px (140%). Kontrola wspólnego przejścia klawiaturą oraz selektorów danych; dowód `evidence/panel581/zoom-current-200.json`. Przebieg wykonano po dodaniu podsumowania błędów sygnałów, ale GET bez błędów nie obejmuje samego nowego komunikatu. Starszy `zoom-200.json` pozostaje historycznym dowodem, nie został nadpisany.

Dodatkowe sześć adresów przy zoomie 200%: **12/12 PASS**, oba motywy, 320 CSS px, tekst 140%, kontrola szerokości, rzeczywistego zoomu i klawiatury. Raport `evidence/panel581/zoom-stany-200.json` sprawdza także obecność nagłówka, lecz nie posiada osobnych asercji treści każdego statusu; nie należy przedstawiać go jako regresji semantyki wszystkich decyzji. Obejrzano ciemny viewport wiadomości bez adresu. Kontrole POST przy rzeczywistym zoomie pozostają osobnym zakresem.

### Dodatkowy odbiór walidacji przy rzeczywistym zoomie 200%

Osiem przypadków PASS: cztery błędne formularze (decyzja, przywrócenie, odwołanie, sygnały) w obu motywach. Chromium chrome.tabs.setZoom(2), viewport 640 × 1800, szerokość układu 320 CSS px i tekst aplikacji 140%. Rzeczywisty POST z CSRF oraz sesją, przekierowanie do widocznego podsumowania błędów. Sprawdzono aktywny fokus, obrys, dziewięć punktów podsumowania bez zasłonięcia i brak poziomego overflow. Skróty danych wskazanych tabel przed i po identyczne. Dowód: evidence/panel581/walidacja-zoom.json. Obejrzano zrzut sygnałów w ciemnym motywie. Pozostałe rodziny formularzy nadal wymagają tego samego odbioru zoomu; nie jest to potwierdzenie całej macierzy ani wdrożenia. Skrypt pomiarowy pozostaje w lokalnym output/panel581/walidacja-zoom.mjs.

### Uzupełnienie: wszystkie dziesięć formularzy przy zoomie 200%

Dokończono odbiór pozostałych rodzin: 8/8 przypadków tablicy, kolażu i tagów oraz 4/4 przypadków wiadomości. Łącznie z poprzednimi 8 przypadkami daje to 20/20 błędnych POST dla dziesięciu formularzy w obu motywach. Rzeczywisty zoom Chromium 200%, szerokość 320 CSS px, tekst 140%, aktywny i niezasłonięty fokus podsumowania, brak poziomego overflow; skróty badanych tabel przed/po identyczne. Obejrzano zrzuty tablicy (jasny) i pustej odpowiedzi (ciemny). Dowody: walidacja-wyborow-zoom.json i walidacja-wiadomosci-zoom.json oraz zrzuty obok. Jest to uzupełnienie poprzedniego ograniczenia dotyczącego tych sześciu formularzy, nie odbiór wszystkich możliwych błędów ani poprawnych operacji moderacji. Pomiar korzysta z POST przez klienta HTTP współdzielącego ciasteczka przeglądarki, a nie kliknięcia przycisku formularza. Wdrożenie pozostaje niepotwierdzone.

Dodatkowo sprawdzono jedenasty formularz: pustą odpowiedź body w kolejce bez odpowiedzi. 2/2 motywy PASS przy tym samym rzeczywistym zoomie 200% i tekście 140%; comments, posts, działania, jobs i notifications bez zmian. Łącznie prób walidacji zoomu jest 22, dla 11 formularzy. Dowód walidacja-kolejki-zoom.json. Nie oznacza to pokrycia wszystkich odmian błędów wyszczególnionych w kolejce odbioru.

## Zatrzymanie wysyłki na prośbę domknięcia prac

Commit pakietu: 9a2a40dd8470f614a85341f307e8d9a9df56d353. Zwykły push zakończył się kodem 1: hook wykrył niepowodzenie pełnych testów PHP. Pint, składnia, skrypty, PHPStan i odwracalność migracji przeszły. Nie wykonano obejścia hooka. Gałąź nie została opublikowana i PR panelu nie powstał.

Celowane odtworzenie czterech rodzin dało 25 testów, 267 asercji, 4 porażki: LicznikiKolejekPaneluTest::test_czytnik_ekranu_slyszy_czego_dotyczy_liczba; OdstepMiedzyDrogamiWejsciaTest::test_zadna_inna_regula_nie_ustawia_marginesu_na_powierzchniach; PanelSzerokiTelefonTest::test_panel_nie_rezerwuje_pustej_kolumny_szyny_od_80rem; TrybPaneluWMenuTest::test_w_trybie_panelu_widac_powrot_do_serwisu. Należy porównać kontrakty tych regresji z nową strukturą, zachować ich sens i wymagane kontrole ujemne. Nie uznano ich automatycznie za przestarzałe. Pełny hook trzeba ponowić dopiero po naprawie.

Log hooka: /home/mateusz/push581-final.log. Proces wysyłki zakończony. Runtime ma teraz własne repo git utworzone z bundle; nie kopiować jego metadanych do repo kanonicznego. Helper push581-final.py służył do pierwszego przygotowania i odmawia ponowienia przy istniejącym .git; do kolejnej próby przygotować świadomie zwykły push po synchronizacji źródeł. Pozostaje niezależny PR bezpieczeństwa #586, wcześniej opublikowany, nadal draft przy ostatnim odczycie.


## Wznowienie: cztery kontrakty regresji

Po przeniesieniu ostatniej normalizacji whitespace do runtime: 25 testów /272asercje PASS. Cztery fizyczne mutacje źródeł wykryte osobno (licznik aria-hidden, etykieta powrotu, atrybut trybu panelu, zakres marginesów). Każda: dodatni baseline, ujemny wynik kod1, przywrócenie identycznych bajtów/MD5/mtime, dodatni wynik. Dowody: evidence/panel581/negatives/contracts/report.json. Pierwsze próby baseline kod2 były błędem nieczynnego PostgreSQL55439 i nie stanowią negatywów. Po uruchomieniu wyłącznie izolowanego klastra wykonano komplet prób. Pełny hook/push nadal do ponowienia.

Review korekt wykryło zbyt szeroki wyjątek listy selektorów. Zastąpiono go dokładnym porównaniem normalizowanego selektora panelu. Powtórzono pięć fizycznych kontroli, w tym dopisanie selektora .panel-formularza poza panelem: wszystkie wykryte, źródła przywrócone MD5/mtime. Końcowe celowane25testów/272asercje i Pint PASS. Próbę przygotowania wysyłki przerwano przed git push na czas tej poprawki.


## PR #637 — stabilność pomiaru zoomu, 17 września 2026

CI 35167308602: 11/12 zadań PASS. W zadaniu panelu przeszło 288 pustych i 312 pełnych konfiguracji oraz 8 scenariuszy menu; zawiódł późniejszy pomiar zoomu. Lokalnie odtworzono przejściowy font 18 px przy już ustawionej skali 140% i tokenie 1.4; następna klatka miała poprawne 25.2 px. Nie był to reset preferencji użytkownika.

Pomiar czeka teraz maksymalnie 2 s na trzy kolejne poprawne klatki, bez ponawiania ustawień. Zachowano wymóg rzeczywistego zoomu 2, DPR 2, szerokości, braku overflow i przejścia klawiaturą. Błędy zapisują bezpieczny kod, etap i liczby, bez URL ani ciasteczek. Review wskazało prefiks wyjątków Playwright oraz zbędne 30 klatek diagnostycznych; poprawiono oba punkty i dodano osobny timeout.

Fizyczne wymuszenie fontu 18 px w prawdziwym marka-panel.css zostało wykryte jako P581_ZOOM_FONT_NIEUSTALONY. Kopia poza repo, MD5 i mtime potwierdzają dokładne przywrócenie. Po przywróceniu: 8/8 dodatkowych scenariuszy i 4/4 rzeczywistych zoomów PASS. Dowody: evidence/menu581/zoom-font-negative.json, zoom-stable-200.json, menu-stable-extra.json. Pierwsza próba negatywu oblała prawidłowo, lecz miała kod NIEZNANY; nie zaliczono jej jako pełnego dowodu diagnostyki.

To poprawka narzędzia pomiarowego; świeży CI i wdrożenie PR #637 pozostają do potwierdzenia.

## Powtórzenie kontroli bramek — 18 września 2026, 19:01 UTC

Pierwsza kontrola (13:12 UTC) była prozą bez pliku wynikowego. Ta jest
zapisana maszynowo:
[`evidence/panel581/bramki-20260918T1901Z.json`](evidence/panel581/bramki-20260918T1901Z.json).
Produkcja stała wtedy na `3f315b3` (Alfa 0.67, wydanie 18 września 2026,
20:53), czyli na innym wdrożeniu niż rano. **Wyłącznie HTTP GET bez sesji —
nie logowano się, nie podjęto żadnej decyzji moderacyjnej, nie zmieniono
ustawień konta ani 2FA.**

Wynik identyczny co do jednej odpowiedzi: dziewięć tras panelu, dwie trasy
szczegółowe ze zmyślonym UUID, dwie z parametrami zapytania i `/ustawienia/2fa`
odpowiadają gościowi **302 na `/login`**, bez różnicowania. Adres panelu bez
dalszego członu oraz `admin/tagi` zwracają **404** — tych tras po prostu nie
ma (`routes/web.php`: grupa `auth` + `moderator` + `moderator.2fa` w l. 1010,
spis tagów stoi publicznie poza panelem).

To potwierdza trwałość bramki przez zmianę wdrożenia, ale **nie poszerza
zakresu**: wszystko, czego ta kontrola nie pokazuje, wypisane jest wyżej
i pozostaje aktualne.

### Brakujące dowody #581 — scenariusze i kryteria

1. **Odmowa dla zalogowanej osoby bez uprawnień.**
   Scenariusz: wejść na trasę panelu jako zalogowany użytkownik bez roli
   moderatora. Potrzebne uprawnienie: zwykłe konto testowe — **lokalne**,
   nie produkcyjne; na produkcji kont nie zakładamy. Kryterium: odpowiedź
   403 (albo 404 bez różnicowania), identyczna dla rekordu istniejącego
   i nieistniejącego.
2. **Bramka 2FA w działaniu.**
   Scenariusz: wejść na trasę panelu jako moderator bez potwierdzonego 2FA.
   Potrzebne uprawnienie: konto moderatora z włączonym 2FA — lokalnie.
   Kryterium: przekierowanie na ekran potwierdzenia 2FA, a nie na treść
   panelu, i brak obejścia przez trasę szczegółową.
3. **Port marki na ekranach z danymi.**
   Scenariusz: ogląd dziewięciu ekranów panelu z realnymi zgłoszeniami
   i odwołaniami. Potrzebne dane: scena moderacyjna — **lokalna**; treści
   decyzji nie wytwarzamy na produkcji. Kryterium: pełna macierz szerokości
   i motywów, tekst ≥ 18 px, przyciski ≥ 48 px, zero poziomego przewijania
   przy 320 px.

**Nie zamykamy #581 na tej podstawie.**
