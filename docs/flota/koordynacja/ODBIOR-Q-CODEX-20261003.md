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
- **#2858**, `63a47cf3f`: dopisek zachowuje jawny podgląd ze spisu kroków.
  Rzeczywista droga HTTP od linku przez POST, błąd i powrót nie wyświetla
  fałszywego pytania „Jak wyszło”; zwykły ostatni krok nadal je wyświetla.
  Akceptowana jest tylko lokalna, nazwana trasa powrotu. Własny PG18:
  **43 / 292 PASS**, dwie fizyczne kontrole ujemne z właściwym markerem,
  dokładne przywrócenie. Niezależny przegląd i odczyt koordynatora: ACCEPT.
- **#2852**, `7039a8503`: wydruk całego zeszytu i wybranych pozycji podaje
  podpis oryginału „Mojej wersji”. Oryginały są pobierane zbiorczo z pełną
  kontrolą widoczności i dostępności autora; brak dostępu daje neutralny
  tekst bez tytułu, adresu i danych autora. Własny PG18: **57 / 418 PASS**,
  w tym liczba zapytań dla jedenastu różnych oryginałów i granice dostępu.
  Fizyczny mutant: właściwy `PODPIS_2852_WIDOCZNY`, dokładny restore.
  To jest odbiór HTML i zapytań; nie wykonano oglądu PDF ani przeglądarki.

Koordynator zmierzył trzy pierwsze poprawki na osobnej instancji Linux,
PG18 na porcie 55488: **66 / 2152 PASS**, wyścig retencji **1 / 18 PASS**,
fizyczny DELETE po ID oblał z właściwą przyczyną, a dokładny restore przeszedł.
Pełna analiza typów: zero błędów. Baza wyścigów należy do tego worktree
(`kuking_race_repo_q`). Dwie ostatnie poprawki wymagają pomiaru wspólnej bazy.

Konflikty CHANGELOG rozwiązano sumą wpisów. Q nie dodaje migracji,
zależności ani płatnych wywołań. Wcześniejsze migracje N zachowują własne
warunki odbioru i rollbacku. Zmiany zakresu CI obejmują fizyczną kontrolę
retencji i klasę dwóch połączeń.

## Pozostałe bramki

Root odświeża własną kopię Linux do kompletu pięciu poprawek z dokładnym
lockiem i osobną bazą PG18. Pomiar całych zmienionych klas i pełna analiza typów poprzedzają
zwykły push z niezmienionym hakiem. Następnie pełne CI dokładnego heada,
przegląd końcowego PR i merge z expectedHeadSha do integracji.

Przed zamknięciem issues: zielone wydanie do main wraz z CodeQL, terminalne
CI push, udane wdrożenia web/workera/harmonogramu dla tego samego SHA,
produkcyjne `/wydanie` i `/health`. To nie jest potwierdzenie odbioru produkcji.
