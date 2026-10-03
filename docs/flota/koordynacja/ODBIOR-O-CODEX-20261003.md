# Odbiór paczki O — 3 października 2026

O powstaje na własnej `codex/paczka-o-20261003`, od zamrożonej N
`c088b2208f629776dff203a05174151ade394a01`. Nie przesuwa M ani N podczas
trwających bramek. Wydanie O wymaga wcześniej odbioru M i N oraz świeżej
bazy i pełnych kontroli końcowego heada.

## Przyjęte poprawki

- #2829 `e7e7dbb3d`: błąd notatki z dalszej porcji zeszytu kieruje na
  właściwe `page` albo `wpisy`. Pierwszy GET renderuje pole przed zużyciem
  flash, zachowuje tekst oraz świeży odcisk. Adres jest złożony wyłącznie
  przez własną trasę; numer musi być dodatnią liczbą co najmniej 2.
  Raport wykonawcy: prawdziwy PATCH → redirect → GET na własnym PG18,
  15 testów / 131 asercji PASS; mutant `adresPorcjiPoBledzie` oblał
  `NOTATKA_2829_DRUGA_STRONA`, przywrócono bajty i mtime. Lokalny runner
  całego rejestru nie ruszył na Windows z braku `cp`; nie jest zaliczony.
  Root przeczytał kontroler, Blade oraz oba przepływy regresji.
- #2821 `e98b6cc5b`: lista utworzona w drugiej karcie nie zabiera pola
  z wpisaną nazwą po odmowie limitu. Zostają widoczna etykieta, błąd
  przy polu i działający odnośnik podsumowania. Przy pełnym limicie
  przycisk zapisu jest nieaktywny. Raport wykonawcy: 26 / 196 PASS i
  fizyczna mutacja warunku widoku z właściwym markerem oraz przywróceniem.
  Niezależny odbiór: dodatkowy prawdziwy HTTP na własnym PG18, 1 / 12
  PASS. Podejrzenie różnicy wielkiej litery wycofano po pomiarze:
  oczekiwany tekst pochodzi z renderowanej walidacji domenowej. ACCEPT.
- #2818 `0615502b8`: odmowa limitu zakupów wraca na ponownie sprawdzoną
  własną listę. Lista usunięta lub niepoprawna daje ekran domyślny, cudza
  nadal 403. Podgląd porcji pozostaje. Raport wykonawcy: 28 / 199 oraz
  8 / 88 PASS, faktyczne POST → Location → GET; fizyczny mutant oblał
  właściwym markerem i źródło przywrócono. Niezależny ACCEPT sprawdził
  Policy, brak przekierowania poza własną trasę i współistnienie z #2821.
- #2814 `1eb0c1c06`: powrót z alarmu wcześniejszego kroku pozwala dodać
  czas. Osobny znacznik zakończenia ma tożsamość przepisu, UUID i odcisk,
  TTL 15 minut i nie jest aktywnym timerem. Start, dodanie czasu,
  anulowanie i zakończenie sprzątają go w swoim zakresie. Raport:
  rzeczywisty Laravel HTTP i Chromium na własnym PG18 PASS, kontrola
  ujemna braku zapisu znacznika oblała własnym markerem, dokładny restore
  i PASS. Moduły 22 / 22, budowa assetów PASS. Niezależny ACCEPT:
  oryginalny skrypt HTTP ponownie przeszedł; jednorazowy eksperyment
  z prawdziwym „Wstecz” nie wznowił timera ani alarmu. `pageshow.persisted`
  było false przy istniejącym `private, no-store`. Nie twierdzimy, że
  wykonano bfcache w innych przeglądarkach. Ten dodatkowy eksperyment
  nie jest testem zapisanym w CI; zapisany test obejmuje link i reload.

- #2837 `d1ab2c533`, dodatkowy test `830859fb8`: osiem anonimowych
  wywołań ponawia sprawdzenie sprzeciwu z kontem pod istniejącym
  `FOR SHARE`, po czym zapisuje `user_id=NULL`. Sprzeciw odmawia sygnału,
  zachowując funkcje gotowania i spiżarni. Raport wykonawcy: 53 / 398
  PASS, fizyczny mutant bramki oblał `SPRZECIW_2837_ANI_ANONIMOWO`,
  bajty i mtime przywrócone, 3 / 73 PASS. Niezależny review ACCEPT.
  Root na O830859: 73 / 2122 PASS, pełny PHPStan 0, Pint czterech
  zmienionych PHP PASS. Dodatkowy rzeczywisty HTTP test potwierdza,
  że cofnięcie sprzeciwu liczy przyszłe działania anonimowo i nie
  odtwarza pominiętych działań ani powiązań wcześniejszej historii.
- #2838 `5d81c9e9b`: odmowa i przyjęcie linku biorą konta przed
  zaproszeniem. NULL → UUID adresata nadal podlega rzeczywistemu FK;
  świeży adresat, właściciel i stan są ponownie sprawdzane. Raport:
  Feature 32 / 201 PASS, prawdziwe dwa połączenia w obu kolejnościach
  2 / 38 PASS. Fizycznie odwrócona kolejność odtworzyła `40P01` oraz
  jeden FAIL własnego markera, restore bajtów/mtime i PASS.
  Root przeczytał domenę, wymuszenie obu przeplotów, prywatne komunikaty
  i rejestr CI. Niezależny przegląd potwierdził ACCEPT. Pełny CI
  końcowego złożenia pozostaje wymagany.
- #2820 `c64e168a7`: formularz i zapis odblokowują uwagę dokładnie
  z wygaśnięciem prośby. Przyjęta wskazówka i otwarte zgłoszenie nadal
  chronią tekst. Własne HTTP/PG18: 26 / 177 PASS; fizyczny powrót starego
  `whereIn` oblał `KOREKTA_2820_WYGASLA_PROSBA_ODBLOCKOWUJE`, bajty i mtime
  przywrócone, test znów PASS. Pint/scoped PHPStan PASS. Niezależny ACCEPT
  odczytowo potwierdził granicę oraz świeży stan przy zapisie pod blokadami.
- #2828 `eba7f9b61`: oba warianty sluga ładują zdjęcia kroków razem.
  Tylko aktywna potrawa pokazuje bieżące zdjęcie przez istniejące `x-photo`;
  nie ma obrazów w zapisywanym stanie kolejki. Prawdziwy HTTP/PG18:
  28 / 145 i po końcowej zmianie 4 / 33 PASS. Mutant `@if(false)` oblał
  `ZDJECIE_2828_BIEZACY_KROK`, dokładny restore i cache widoków. Pint oraz
  scoped PHPStan PASS. Rzeczywisty Chrome: 320 px, HTML 200% i font Chrome
  200%, zmiana kroku/potrawy, Tab i powiększenie zdjęcia, zachowanie timera
  PASS; zrzuty wizualnie obejrzane, scrollWidth 320. Ogląd nie dotyczy
  danych użytkowników produkcyjnych. Niezależny odbiór kodu ACCEPT.

Przyjęto także odczyt JUnit z M #2844 (`344f62310`) z siedmioma testami
mechanizmu oraz krokem w istniejącym jobie lint CI. Z N (`8b100493a`)
dołączono podział dwóch dokumentów schematu bez zmiany treści i limitów.
Opis #2838 przeniesiono do nowego dokumentu zeszytów, pozostawiając #2820
przy cooked_events. Nie zgubiono nowych opisów przy rozwiązywaniu konfliktu.

Konflikty CHANGELOG, dokumentacji oraz rejestrów kontroli rozwiązano
sumą dodatków. Nie zmieniano limitów, migracji, zależności ani kosztu.

## Połączony pomiar root

Na `040be5daa7f5d3d12efcb7cc331c6198b8a09a7d`, w odrębnej kopii Linux,
z zależnościami z dokładnego locka i własnym kluczem aplikacji, PostgreSQL
18 / C.utf8 / 127.0.0.1:55488, osobna baza `kuking_test_o_20261003`:

- 43 testy / 1952 asercje PASS, bez failures/errors/skips;
- pojedynczy moduł `minutnik-krok.test.mjs`: 13 / 13 PASS;
- pełny PHPStan: zero błędów; Pint pięciu zmienionych PHP PASS,
  `git diff --check` PASS, czyste drzewo kopii pomiarowej.

To kontrola połączonych zmienionych obszarów i strażnika, nie pełna
bramka pusha, CI ani odbiór produkcji. Niezmienione opcje lokalnych
skryptów i pomiary, których nie wykonano, nie są zaliczone.

## Praca w toku i bramki

O obejmuje osiem odebranych poprawek. Dalsza praca agentów trafia do P:
#2806 (także usunięcie listy między odczytem a transakcją), #2842,
#2846 i #2822. Nie przyjmować niezakończonego zakresu na podstawie
raportu wstępnego. M i N mają własne trwające normalne bramki.

Przed wydaniem wymagane: pełne terminalnie zielone CI dokładnego heada,
CodeQL finalnego PR do main i merge z expectedHeadSha. Po merge:
terminalne CI push main, SUCCESS wszystkich trzech usług Railway,
zgodny produkcyjny `/wydanie` oraz `/health` 200. Dopiero potem zamknięcia
zastąpionych PR i issues z pełnymi kryteriami. #2025, #2708, #2713
i pilot z ludźmi 50+ zachowują osobne otwarte czynności.
