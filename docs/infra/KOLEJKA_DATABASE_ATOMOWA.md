# Atomowość kolejki `database`

Import przepisu zapisuje zlecenie i zleca zadanie w jednej transakcji bazy.
Dotyczy to importu z adresu, importu z PDF i bezpośredniego zlecenia odczytu.

## Obowiązująca konfiguracja

- `queue.default=database` używa `DB_QUEUE_CONNECTION`, a gdy zmiennej nie ma,
  dziedziczy `DB_CONNECTION`.
- `queue.connections.database.after_commit` pozostaje `false`, ponieważ wpis do
  tabeli `jobs` ma zostać zatwierdzony razem z rekordem importu.
- `QueueConfigurationGuard` odmawia uruchomienia aplikacji, gdy połączenia się
  różnią albo ktoś włączy `after_commit` bez aktualizacji wszystkich ścieżek.

Rozdzielenie połączeń pozwoliłoby workerowi zobaczyć zadanie przed commitem,
a po rollbacku pozostawić osierocone zadanie. Nie ustawiaj `DB_QUEUE_CONNECTION`
na inną bazę bez zmiany kontraktu, testów i wszystkich trzech ścieżek importu.

## Rollback

Zmiana nie modyfikuje schematu ani istniejących rekordów. Przy wycofaniu kodu
przywróć konfigurację i guard w jednym wdrożeniu; nie wykonuj żadnych operacji
na tabeli `jobs` ani na importach produkcyjnych.

## Pomiar atomowości trzech ścieżek importu (#2402)

`tests/Dwa/ImportIKolejkaWJednejTransakcjiTest.php` woła rzeczywiste akcje
URL, PDF i zdjęcia oraz zapis do `jobs`, bez `Queue::fake`. Drugie połączenie
PostgreSQL mierzy niewidoczność obu wierszy przed commitem, widoczność obu
po commicie i brak obu po rollbacku. Test nie uruchamia workera ani modelu:
mierzy trwały zapis i widoczność, a nie ukończenie samego importu.

Kontrola `scripts/kontrola-negatywna-2402.py` w CI celowo przenosi wysyłkę
na osobne połączenie (worker widzi zadanie za wcześnie), a następnie na
`afterCommit()` (zadania nie ma w transakcji). Obie mutacje muszą dać
konkretną porażkę, a po przywróceniu źródeł test musi przejść ponownie.
Wynik wykonania dokumentuje PR; sam opis testu nie jest dowodem przejścia.
