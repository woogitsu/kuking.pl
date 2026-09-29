# Audyt wydajności bazy danych (30 września 2026)

Obszar: wydajność bazy. Baza kodu: `origin/claude/paczka-i-kandydat` w `5548c7e16`.
Dokument w budowie (zapisywany przyrostowo).

## Wstępne ustalenia (w toku)

- `/odkryj` dla zalogowanej osoby: główne zapytanie ma koszt planu ok. 525 tys., PostgreSQL kompiluje je
  przez JIT (1,9–2,5 s z 2,0–2,7 s wykonania). Bez JIT: 116–150 ms.
- `/powiadomienia`: `COUNT(*)` z `paginate()` przy 2000 powiadomieniach ma koszt ok. 320 tys., JIT 480–720 ms.
  Bez JIT: 6–14 ms.
- `/` i `/odkryj` dla gościa: koszt ok. 317 tys., JIT 50–120 ms.
