## D-197 · Komunikat walidacji mierzymy przez wywołanie błędu

**Data:** 12 września 2026 · PR #475 · Status: **obowiązuje**

### Decyzja

Komunikat nazywa pole tak jak ekran i mówi, co zrobić. Podsumowanie prowadzi do istniejącego pola, pole wskazuje swój błąd, a poprawne wartości zostają. Test wyzwala walidację żądaniem HTTP; samo znalezienie tekstu w pliku językowym nie wystarcza.

### Stan zapisu

Uzupełnienie dziennika po scaleniu PR #475; opisuje pracę już obecną na `main`.

📄 `tests/Feature/BledyMowiaCoZrobicTest.php`
