# Schemat pytań — #371

## Zakres

Podstawa: `2a17a5be38adf3d71fa9eab625f8d6830cf32a43`.
Jedna tabela `posts`: `kind=dish|question`, `title` nullable tylko dla dania.
Ograniczenia bazy wchodzą razem z kolumnami. Stare wpisy zachowują treść,
relacje i widoczność. Nie ma osobnej tabeli pytań ani `kind=recipe`.

To przygotowanie danych, bez nowego ekranu i bez publicznego włączenia.
`KUKING_QUESTIONS_ENABLED=false` przygotowuje konfigurację #372; nie jest
jeszcze blokadą wszystkich odczytów ręcznie zapisanych pytań. Nie tworzymy
pytań w produkcji ani w DemoSeeder. Obecny formularz wpisu ignoruje przesłane
`kind` i `title` przy publikacji i edycji. Wersja publicznego interfejsu
pozostaje Alfa 0.65, ponieważ ten pakiet nie zmienia zachowania ekranów.

## Dowody lokalne

Izolowany runtime `/home/mateusz/kuking-371-tests`, osobna baza
`kuking_371_tests`, PostgreSQL na `127.0.0.1:55439`, `PGTZ=UTC`.

- 49 testów / 412 asercji: schemat, model/factory, relacje media/tagi/komentarze,
  stare formularze HTTP, macierz widoczności pytań i wpisów, zgodność dokumentacji
  schematu oraz rejestr masowego przypisania.
- PHPStan: zero błędów. Pint poprawił kolejność importów testu.
- Cztery fizyczne kontrole ujemne migracji: usunięcie warunku nie-NULL tytułu
  pytania, usunięcie ograniczenia tytułu dania, wyłączenie strażnika rollbacku,
  błędna wartość domyślna rodzaju. Każda spowodowała porażkę testów.
  Źródło przywrócono z kopii poza repo, sprawdzając MD5 i mtime; powtórny
  zestaw 49 testów przeszedł. Wynik: `evidence/questions371/negative.json`.

Jedno wywołanie pomocnicze nie uruchomiło poprawnie zestawu, ponieważ powłoka
zinterpretowała alternatywy filtra jako potoki. Nie zaliczono go jako sukces;
wynik powyżej pochodzi z kolejnego uruchomienia przez argumenty procesu Python.

## Wdrożenie i wycofanie

Migrację wykonuje zwykła ścieżka wdrożenia. Nie włączamy flagi pytań.
`down()` przy braku pytań usuwa nowe kolumny i CHECK-i bez usuwania wpisów.
Przy istniejącym pytaniu, także miękko usuniętym, odmawia przed DDL.
W takim przypadku wycofujemy kod bez cofania schematu. Nie należy usuwać
prawdziwych pytań, żeby wymusić rollback. Sprawdzenie i DDL obejmuje jedna
transakcja z blokadą tabeli; lokalne testy nie są pomiarem czasu tej blokady
pod obciążeniem produkcji.

## Pozostałe kroki

Niezależne review końcowych źródeł nie wykazało blokerów; obejmowało odczyt
migracji, modelu, testów i istniejących ścieżek zapisu, bez ponownego wykonania
testów przez reviewera. Pełny zestaw PHP zakończył się kodem 0: 4102 testy / 81604 asercje,
bez porażek i błędów, z trzema PHPUnit Notices. Nie zaliczamy tego jako
przebiegu bez komunikatów. Pint, składnia, skrypty, PHPStan, rollback migracji
i build przeszły w poprzednim pełnym przebiegu. Zwykły push, CI,
merge i odbiór wdrożenia jeszcze nie zostały zakończone. #372 musi objąć flagą odczyty,
moderację i tworzenie, zanim dopuści pierwsze pytania. Brak nowego UI w tym
pakiecie nie jest odbiorem działu „Poradźcie”.

## Korekta środowiska pełnego przebiegu

Pierwszy pełny przebieg zgłosił trzy problemy: brak regionu S3 w lokalnym
środowisku oraz dwa testy dokumentów wrażliwe na CRLF skopiowane z Windows.
Ujednolicono końce linii dwóch plików do LF i ustawiono lokalny region `auto`.
Celowany zestaw 25 testów / 81 asercji przeszedł bez zmian w asercjach.
Powtórny pełny przebieg zakończył się kodem 0 (4102/81604); raport JUnit
zapisano poza repo, wraz z logiem. Trzy PHPUnit Notices dotyczą istniejących mocków `Queue\Job` bez oczekiwań
w `NieudanyListZostawiaSladTest`. Osobny odczyt: 18 testów / 62 asercje,
exit 0; nie zmieniano tych testów w pakiecie schematu pytań.
