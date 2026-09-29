# Audyt błędów poprawności w przepływach domeny — 29/30 września 2026

**Obszar:** `bledy-przeplywy` — logika domeny w `app/Domain`, `app/Http`,
`app/Jobs`, `app/Livewire`.
**Baza:** `origin/claude/paczka-i-kandydat` = `5548c7e16` (przyszły `main`).
**Status:** w toku (plik uzupełniany przyrostowo).

Każde znalezisko ma test, który **dziś oblewa na bazie**. Testy są wklejone
niżej jako gotowy kod regresyjny; nie są dodane do `tests/` (to audyt, nie
poprawka). Uruchamiane jako
`APP_BASE_PATH=$(pwd) php artisan test <plik>` na PostgreSQL 18.

## Znaleziska

| ID | Waga | Tytuł | Dowód (plik:linia na bazie) | Odtworzenie | Wpływ | Poprawka i test | Rozmiar | Duplikat? |
|---|---|---|---|---|---|---|---|---|
| BP-01 | P2 | Tygodniowe podsumowanie: konta bez treści na zawsze zajmują okno kandydatów i głodzą osoby z treścią | `app/Console/Commands/WyslijPodsumowaniaTygodnia.php:201-205` (pusty list: `continue` bez znacznika), `app/Domain/Digest/OdbiorcyDigestu.php` `naDzis()` (`ORDER BY weekly_digest_sent_at ASC NULLS FIRST, created_at` + `LIMIT budżet*3`) | test BP-01 | patrz opis | patrz opis | S | sprawdzane |
| BP-02 | P2 | „Zrób swoją wersję” gubi zamienniki składników (D-284) | `app/Domain/Recipes/Actions/ZrobWlasnaWersje.php:94-105` (brak `substitutes`) | test BP-02 | patrz opis | patrz opis | S | sprawdzane |
| BP-03 | P3 | Pierwsza publikacja szkicu zapisuje wersję 1 jako „Aktualizacja przepisu” | `app/Domain/Recipes/Actions/PublishRecipe.php:628-632` | test BP-03 | patrz opis | patrz opis | S | sprawdzane |

Opisy, testy i sekcja „sprawdzone i w porządku” — niżej (uzupełniane).
