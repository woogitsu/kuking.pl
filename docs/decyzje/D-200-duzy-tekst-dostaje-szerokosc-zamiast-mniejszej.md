## D-200 · Duży tekst dostaje szerokość zamiast mniejszej czcionki

**Data:** 12 września 2026 · PR #479 · Status: **obowiązuje**

### Decyzja

Kafel dodawania przy dużym tekście oddaje opisowi osobny wiersz, ogranicza wzrost wcięć i chowa ozdobną ikonę. Tytuł i podpis zostają. Pomiar wysokości należy do przeglądarki; test kształtu reguły CSS nie zastępuje pomiaru. Osobny problem rozmiaru podpisu pozostaje zgłoszony w issue #478.

### Stan zapisu

Uzupełnienie dziennika po scaleniu PR #479; opisuje pracę już obecną na `main`.

📄 `tests/Feature/KafelDodawaniaPrzyDuzymTekscieTest.php`
