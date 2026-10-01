## D-330 — Słownik wartości odżywczych ma rosnącą wersję danych; starszy import nie nadpisuje nowszego (#2130, 29 września 2026)

**Data:** 29 września 2026 · Status: **obowiązuje** · Decyzja właściciela z 29.09.2026

**Problem.** Import słownika (D-299) porównywał hash plików i odcisk trzech tabel,
ale nie wiedział, który zestaw danych jest NOWSZY. Instancja ze starszego
wdrożenia (np. po wycofaniu) miała inne pliki, więc jej import odbudowywał
słownik i cofał dane do starszych.

**Decyzja.**

- Numer wersji danych żyje w jednym miejscu: `database/data/odzywcze/WERSJA`
  (wiersze „numer sha256-plików-CSV”, numery rosną, obowiązuje ostatni).
- Znacznik ostatniego udanego importu (cache, pod kluczem z D-299; produkcja =
  `CACHE_STORE=database` bez `DB_CACHE_CONNECTION`, więc to samo połączenie i ta sama
  transakcja co słowniki; zapis to `upsert`, który nie przerywa transakcji PostgreSQL) niesie także
  `wersja`. Zapis pod `pg_advisory_xact_lock(2130, 0)`, jak reszta.
- Reguła: wersja pliku > wersja w bazie albo baza niekompletna / odcisk
  niezgodny / hash inny → pełny import. Wersja pliku < wersja w bazie i baza
  kompletna → NIE nadpisujemy, ostrzeżenie w logu (same numery wersji, bez
  danych osobowych). Ta sama wersja i ten sam hash + zgodny odcisk → szybka ścieżka.
  Porównanie powtarzamy POD blokadą, bo znacznik sprzed blokady mógł się zestarzeć.
- `--wymus` świadomie pomija ochronę wersji (jawna decyzja osoby z konsoli).
- Test `WersjaSlownikaOdzywczegoTest` oblewa, gdy CSV zmieniono bez dopisania
  nowego wiersza w `WERSJA`. Zmiana samego kodu normalizacji zmienia hash
  importu (odbudowa przy tej samej wersji), ale nie wymusza podbicia numeru —
  podbij go, gdy zmiana ma dojść do bazy przed starszą instancją.

**Konsekwencje.** Bez migracji. Znacznik w cache można skasować (`cache:clear`):
wtedy wersja bazy jest nieznana i najbliższy import (dowolnej wersji) odbudowuje
słownik — ochrona wraca po nim. Kod sprzed tej zmiany nie zna wersji i nadal
nadpisuje.

**Co musiałoby się stać, żeby zmienić.** Potrzeba trwałej wersji odpornej na
`cache:clear` → osobna tabela z wierszem wersji (migracja, D-088).
