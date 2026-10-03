# Odbiór paczki Q — 3 października 2026

Paczka bazuje na P `018308105d72bad818107cd28364ed6258ead7d1`.
Nie jest jeszcze wypchnięta, wydana ani uznana za pełną zieleń CI.

## Zakres i przegląd

- **#2856**, `8a5b019b0`: kursor wyszukiwania sprawdza dokładny tekst miary
  przez PostgreSQL `pg_input_is_valid(..., 'real')`, zanim trafi do rzutowania.
  Błędna miara wraca do właściwego okna offsetu, bez błędu serwera.
  Dwie miary przepisu i kursor osób mają HTTP/PG18: **8 / 105 PASS**.
  Fizyczny mutant: **1 FAIL** z własnym markerem, restore **8 PASS**;
  bajty i mtime zachowane. Niezależny reviewer dodatkowo sprawdził
  skrajne miary na PG18; ACCEPT. Poprawny tekst kursora nie jest zaokrąglany.
- **#2849**, `4bd39bf64` i `0ad443bfe`: retencja ponownie ocenia wiek i stan
  szkicu w pojedynczym warunkowym DELETE. Odnowiona po SELECT kopia nie znika.
  Budżet 1000 i tryb bez kasowania pozostają. Własny PG18, dwa połączenia:
  **1 / 18 PASS**, w tym rzeczywiste drugie przywrócenie zachowanej treści.
  Fizyczny DELETE po ID: własny FAIL, dokładny restore i PASS. Istniejąca
  retencja: **1 / 8 PASS**. Niezależny reviewer ACCEPT po uzupełnieniu powrotu.
  Jeden strażnik zależny od uniksowego `find` nie przeszedł na Windows;
  nie zaliczać tego wyniku ani nie wyłączać strażnika w Linux/CI.
- **#2848**, `c0fb440e5` i `41234da9e`: jawne sztuki mają pierwszeństwo przed
  zapamiętanymi porcjami, także dla współczynnika 1 i błędnego zera.
  Link powrotu wybiera autora, a wydruk zachowuje rzeczywistą podstawę.
  Zwykły adres nadal odczytuje zapamiętane porcje. HTTP/PG18:
  **51 / 352 PASS**, trzy fizyczne mutacje i restore. Przegląd znalazł
  niepełny wzorzec przyczyny; followup dopuszcza tylko dwa konkretne markery
  tej samej reguły i został zmierzony rzeczywistym narzędziem kontroli.
  Niezależny reviewer nie znalazł blokera produktu.

Konflikty CHANGELOG rozwiązano sumą wpisów. Q nie dodaje migracji,
zależności ani płatnych wywołań. Wcześniejsze migracje N zachowują własne
warunki odbioru i rollbacku. Zmiany zakresu CI obejmują fizyczną kontrolę
retencji i klasę dwóch połączeń.

## Pozostałe bramki

Root przygotowuje własną kopię Linux z dokładnym lockiem i osobną bazą PG18.
Pomiar całych zmienionych klas, Dwa i pełna analiza typów poprzedzają
zwykły push z niezmienionym hakiem. Następnie pełne CI dokładnego heada,
przegląd końcowego PR i merge z expectedHeadSha do integracji.

Przed zamknięciem issues: zielone wydanie do main wraz z CodeQL, terminalne
CI push, udane wdrożenia web/workera/harmonogramu dla tego samego SHA,
produkcyjne `/wydanie` i `/health`. To nie jest potwierdzenie odbioru produkcji.
