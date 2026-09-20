# Niezawodność paczek danych — #819–#825

## Przepływ po poprawce

1. `RequestDataExport` zapisuje prośbę i zadanie `GenerateUserExport` w jednej
   transakcji PostgreSQL. Odmowa zapisu `jobs` cofa prośbę. Istniejący indeks
   jednego aktywnego eksportu nadal rozstrzyga konkurencyjne zamówienia.
2. `ExportQueue` używa istniejącej kolejki `database`, na tym samym obiekcie
   połączenia co `DataExport`. Niezgodność konfiguracji odrzuca operację przed
   zatwierdzeniem. `beforeCommit()` zapobiega przeniesieniu zapisu zadania
   poza transakcję przez `after_commit` w konfiguracji.
3. Budowanie używa kolejki `low`, trzech prób i przerw 120/300 sekund.
   Podczas przerw paczka pozostaje `processing`. Dopiero `failed()` po
   wyczerpaniu prób lub timeoucie ustawia końcową porażkę. Bezpośrednie
   wywołanie bez workera nie ma retry i kończy się porażką od razu.
4. `false` z `writeStream` rzuca `DataExportStorageFailure`; taka paczka
   nie może uzyskać `ready` ani zadania powiadomienia.
5. Po zapisaniu ZIP stan `ready` i zadanie `NotifyUserExportReady` zatwierdzają
   się razem. List idzie kolejką `default`, z trzema próbami i przerwami
   120/300 sekund. Porażka listu nie odbiera paczki ani nie buduje jej od nowa.
6. Zadanie listu odczytuje aktualny adres i termin. Pomija usunięty eksport,
   wygasłą paczkę, brak terminu oraz konto usunięte lub oczekujące usunięcia.
   Blokada wiersza i `notified_at` chronią przed oczywistym powtórzeniem listu.
   Zakaz pisania w serwisie nie odbiera prawa do informacji o własnych danych.
7. Ekran używa tej samej dostępności co link pobrania. Wygasła paczka mówi
   „wygasła” także przed sprzątaniem, a `ready` bez terminu — „niedostępna”.
   Oba przypadki wskazują przycisk przygotowania nowej paczki. GET nie
   usuwa plików ani nie zmienia wierszy eksportu.
   Termin zawiera godzinę tak jak list. Potwierdzenie zamówienia obiecuje
   powiadomienie tylko wtedy, gdy istniejący `Poczta::dziala()` potwierdza
   możliwość utworzenia transportu dostarczającego wiadomości.
8. Nazwy przepisów i klucze nowych paczek zawierają pełny UUID. Prefiks v7
   oznacza czas i nie rozróżnia obiektów. Pobieranie i sprzątanie starych
   paczek nadal używa zapisanego `object_key` — brak przepisywania historii.

## Konfiguracja i granice gwarancji

Manifest `.railway/railway.ts` ustawia `QUEUE_CONNECTION=database`.
`config/queue.php` domyślnie używa połączenia aplikacji. To odczyt repozytorium,
nie pomiar zmiennych działającej produkcji. Przed wdrożeniem sprawdzić, że
`DB_QUEUE_CONNECTION` jest puste albo wskazuje to samo nazwane połączenie co
model, a worker odbiera `default` i `low` z tej samej tabeli `jobs`.
Inny backend wymaga osobnego rozwiązania trwałego dostarczania; ta poprawka
nie udaje atomowości dwóch baz ani nie dodaje nowej infrastruktury.

Magazyn plików i PostgreSQL nie mają wspólnej transakcji: awaria po zapisie
ZIP, przed commitem, może pozostawić obiekt bez zakończonego rekordu.
Ponowienie używa tego samego pełnego klucza. Nie rozszerzano tutaj sprzątania
o osierocone pliki.

E-mail nie ma gwarancji dokładnie jednego doręczenia: transport może przyjąć
wiadomość i zerwać odpowiedź, zanim zapisze się `notified_at`. Ponowienie
może wtedy wysłać drugi list. Znacznik potwierdza sukces transportu, nie
odczytanie wiadomości. Log i wyjątek przekazany do kolejki nie zawierają
surowego komunikatu transportu, adresu ani tokenu; końcowa porażka daje
osobny wpis `error` z identyfikatorem paczki.

## Istniejące zaległości — procedura ręczna

Nie naprawiamy automatycznie starych `queued` ani nie odtwarzamy historycznych
powiadomień. Stare `notified_at = NULL` nie dowodzi braku wysyłki.

Podejrzaną prośbę należy sprawdzić przy zatrzymanym workerze: porównać UUID
eksportu z zadaniami oczekującymi i zarezerwowanymi oraz z historią porażek.
Nie wystarczy brak zadania oczekującego — zarezerwowane może nadal działać.
Po potwierdzeniu osierocenia można, za zgodą właściciela, zakolejkować jeden
`GenerateUserExport` dla istniejącego UUID, bez kasowania prośby i bez nowego
rekordu. Przed wznowieniem sprawdzić, że w kolejce jest dokładnie jeden job.

Odczytowy audyt powielonych `object_key` na produkcji oraz ewentualne nowe
paczki dla dotkniętych osób wymagają osobnego zlecenia. Ta gałąź nie otwiera
produkcji i nie wysyła wiadomości do użytkowników.

## Wycofanie

Zmiana schematu: nullable `data_exports.notified_at` (timestamptz), bez
backfillu. Na pustych znacznikach `down()` działa. Jeżeli choć jeden list
został oznaczony jako wysłany, migracja odmawia utraty tej informacji.

Najbezpieczniej wycofać kod, pozostawiając dodatkową kolumnę. Najpierw
zatrzymać workery i zakończyć lub usunąć wyłącznie rozpoznane zadania nowej
klasy `NotifyUserExportReady` — stary kod jej nie zna. Jeżeli kolumna musi
zniknąć, najpierw zachować jej kopię wraz z UUID eksportów, a następnie
przeprowadzić ręczną migrację po zatwierdzeniu planu przez właściciela.
Powrót do tej wersji wymaga odtworzenia znaczników przed wznowieniem zadań.

Cofnięcie pozostałego kodu przywraca opisane błędy kolejki i kolizje nowych
nazw. Istniejących pełnych kluczy nie zmieniać — wcześniejszy kod pobierania
również czyta `object_key` z bazy.

Zakres zawartości paczki pozostaje bez zmian zgodnie z decyzją właściciela
z 20 września: nie dodano ośmiu dodatkowych kategorii danych.
