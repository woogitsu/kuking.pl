# Zeszyt: dokończenie zapisu i powrót do zawartości — #904, #905, #908

## Zachowanie

- #908: GET strony poza zakresem przekierowuje na ostatnią istniejącą
  stronę każdej listy. Poprawny numer drugiej listy zostaje. Liczymy po
  filtrach widoczności, a komunikat wyjęcia przechodzi przez przekierowanie.
  Wzorzec: `643ba106` dla #748; tamten commit odczytano, nie uruchamiano
  jego testów jako dowodu tej gałęzi.
- #905: „Załóż nowy zeszyt” niesie typ i UUID konkretnego przepisu albo
  wpisu. Po założeniu zeszytu człowiek widzi „Dokończ zapis” z treścią
  i osobnym przyciskiem „Zapisuję w tym zeszycie”. Samo utworzenie niczego
  nie odkłada. Można wrócić do treści lub pozostawić zeszyt bez zapisu.
  Zwykła droga z ekranu Moje nie dostaje dodatkowego kroku.
- Kontekst jest w adresie i formularzu konkretnej karty, bez wspólnego
  stanu sesji. UUID przechodzi sprawdzenie formatu, a treść — Policy przed
  wyświetleniem oraz istniejącą autoryzację przy końcowym POST. Zeszyt
  wybierany jest wyłącznie w zbiorze własnych zeszytów. Nie przyjmujemy
  dowolnego return_url. Ukrycie/usunięcie treści przed powrotem daje
  informację o braku zapisu, bez ujawnienia tytułu lub tekstu.
- #904: potwierdzenie usunięcia przepisu i zeszytu zawiera pełną nazwę,
  escapowaną przez Blade. Dwa kliknięcia, CSRF i dotychczasowe uprawnienia
  zostają; długi pojedynczy wyraz może zawijać się w pytaniu.

## Kontrole i granice

Testy HTTP/SQL przechodzą od wyrenderowanego linku przez utworzenie zeszytu
po końcowy formularz zapisu. Osobne sceny obejmują dwie karty, błąd nazwy,
zachowanie opisu, ponowienie utworzenia, treść prywatną/usuniętą, cudzy
zeszyt oraz tworzenie bez kontekstu. Paginację mierzy DELETE i powrót do
12 pozostałych wpisów, niezależnie od drugiej listy.

Nowe regresje widziano na czerwono przed poprawkami. Szczegółowy raport
wykonanych kontroli, w tym ograniczenia przeglądarkowe, leży w
`output/ZESZYT-DROGA-RAPORT.md`. Testy #646 dostosowano do nowego
przekierowania zamiast utrwalać historyczny pusty ekran.

Brak zmiany schematu. Wycofanie: revert commitów interfejsu i kontrolera;
zeszyty oraz zapisane pozycje pozostają. Wraca utrata kontekstu przy
zakładaniu, ogólne pytania usuwania i puste dalsze strony.

Nie zmieniono zakresu wyjmowania treści ani liczników kolekcji z #774–#777.
Semantyka powiadomień o kilku zeszytach pozostaje decyzją #906.