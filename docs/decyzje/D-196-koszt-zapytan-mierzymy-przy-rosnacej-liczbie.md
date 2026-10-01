## D-196 · Koszt zapytań mierzymy przy rosnącej liczbie rzeczy na ekranie

**Data:** 12 września 2026 · PR #474 · Status: **obowiązuje**

### Decyzja

Doładowanie relacji ma obejmować ścieżkę rzeczywiście czytaną przez komponent, również gdy komponent zaczyna od innego modelu. Sprawdzamy wzrost zapytań wraz z liczbą wyników, a kontrola dodatnia potwierdza obecność treści. Sam płaski wynik dla pustej strony nie jest dowodem.

### Stan zapisu

Uzupełnienie dziennika po scaleniu PR #474; opisuje pracę już obecną na `main`.

📄 `tests/Feature/WynikiSzukaniaLudziBezWachlarzaZapytanTest.php`
