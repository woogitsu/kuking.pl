## D-201 · Dokumentację tabel porównujemy ze schematem w obie strony

**Data:** 12 września 2026 · PR #480 · Status: **obowiązuje**

### Decyzja

Strażnik wykrywa zarówno tabelę pominiętą w opisie, jak i opis tabeli nieistniejącej. Dokument historycznego schematu nie może być nazywany pełnym aktualnym DDL. Liczba sprawdzonych pozycji jest częścią kontroli, bo pusty odczyt nie dowodzi zgodności.

### Stan zapisu

Uzupełnienie dziennika po scaleniu PR #480; opisuje pracę już obecną na `main`.

📄 `tests/Feature/SchematBazyTrzymaSieDokumentuTest.php`
