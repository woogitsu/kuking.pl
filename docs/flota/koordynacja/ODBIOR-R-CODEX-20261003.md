# Odbiór paczki R — 3 października 2026

R opiera się na Q i dodaje #2863 cache kanału Atom, #2855 granicę okresu
prywatnej historii, #2859 rezygnację z niedostępnego udostępnienia
oraz #2867 świeżą kopię usuniętego zeszytu. #2860 i #2869 to późniejsza praca.

## Niezależne pomiary koordynatora

Własny Linux, PostgreSQL 18, 127.0.0.1:55488, rola kuking_pg18_owner,
bazy kuking_test_r_20261003 i kuking_race_repo_r, jawny APP_BASE_PATH:

- 102 testy funkcjonalne / 4765 asercji PASS na wspólnym kodzie.
- 8 rzeczywistych testów dwóch połączeń / 153 asercje PASS:
  kanał Atom, odzyskiwanie zeszytu kontra sprzątanie i historia OFF→ON.
- #2863: trzy fizyczne mutanty blokad cache wykryte z właściwych przyczyn,
  dokładne przywrócenie źródeł i ponownie dodatnie testy PASS.
- #2855: oba fizyczne mutanty — dawny warunek SQL i sekundowy znacznik
  zgody — oblały na własnych markerach. Bajty i czas modyfikacji przywrócone,
  test ponownie PASS po każdej próbie. Okresy rozróżniane także w tej samej sekundzie.
- #2867: przywrócenie braku kontroli UUID kopii oblało właściwy test
  starego formularza. Po odtworzeniu źródła PASS.
- #2859: przywrócenie filtra usuwającego niedostępne udostępnienia oblało
  oba właściwe warianty. Po odtworzeniu źródła PASS.
- Pełny PHPStan: zero błędów.
- Planer i izolacja Livewire: 18 / 101 PASS przy APP_DEBUG=false
  oraz ponownie 18 / 101 PASS przy APP_DEBUG=true.
- Fixture instalacji haków działa z odziedziczonym środowiskiem Gita;
  konfiguracja, haki i stan wywołującego repozytorium zachowane.

To pomiary kodu i żądań testowej aplikacji, nie test zadaniowy z osobą 50+
ani odbiór rzeczywistego telefonu, klawiatury i powiększenia. R nie zastępuje
brakujących kryteriów pilota opisanych w źródłowych issues.

## Pozostałe bramki i wycofanie

Po odbiorze koordynator wykonuje zwykły push z pełnym, niezmienionym hakiem.
Wymagany niedraftowy PR do niedeployowanej integracji, terminalne pełne CI
dokładnego heada i merge z expectedHeadSha. Wydanie do main wymaga osobnego
zielonego końcowego PR-a i CodeQL, terminalnego CI push, SUCCESS web,
workera i harmonogramu tego samego SHA oraz /wydanie i /health 200.

Bez migracji i nowego kosztu. Rollback czterech poprawek przywraca znane
błędy świeżości i prywatności; przed cofnięciem wstrzymać odpowiednią ścieżkę
do naprawionego wydania. Nie cofać zgód, retencji, danych użytkowników
ani decyzji moderacyjnych.
