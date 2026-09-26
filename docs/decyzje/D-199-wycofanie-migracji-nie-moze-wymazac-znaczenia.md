## D-199 · Wycofanie migracji nie może wymazać znaczenia ustawienia

**Data:** 12 września 2026 · PR #477 · Status: **obowiązuje**

### Decyzja

Obchód migracji obejmuje wycofanie i ponowne zastosowanie. Przy danych, których znaczenia nie da się odtworzyć, wycofanie odmawia wąsko i z instrukcją. Przypadek domyślny ma nadal przechodzić. To rozwinięcie D-088, nie zgoda na bezwarunkowe blokowanie rollbacku.

### Stan zapisu

Uzupełnienie dziennika po scaleniu PR #477; opisuje pracę już obecną na `main`.

📄 `tests/Feature/KazdaMigracjaMaWycofanieTest.php`
