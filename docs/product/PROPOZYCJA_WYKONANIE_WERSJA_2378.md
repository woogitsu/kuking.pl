# Wykonanie pamięta wersję przepisu (#2378) — przyjęte domyślne i pytania do właściciela

**Status: zbudowana część bezsporna; poniższe domyślne wynikają z istniejących
decyzji (D-333: #2024, #2270), ale NIE są osobną decyzją właściciela.**
Brak wpisu w `docs/DECISIONS.md` jest celowy — numer D-… nadaje właściciel
po odpowiedzi.

## Co jest zbudowane

- `cooked_events.recipe_version_id` (NULL, FK `ON DELETE SET NULL`), zapis tylko
  przez `RecordCookedEvent`.
- Identyfikator wersji niesie formularz „Ugotowałem” (wersja widoczna przy jego
  otwarciu). Brak pola (API, stary formularz) albo cudzy identyfikator →
  najnowsza wersja przepisu w chwili zapisu.
- Czyta wyłącznie kucharz: sekcja na karcie wykonania i `/ugotowane/{id}/wersja`.
  Obcy, autor przepisu i moderator → 404. Publiczne widoki bez zmian.
- Brak dostępu (wersja skasowana przez retencję, ukryta, przepis prywatny/zdjęty,
  blokada) → komunikat bez tytułu i treści; wykonanie zostaje.

## Przyjęte domyślne (do potwierdzenia)

| Sprawa | Przyjęte | Dlaczego |
| --- | --- | --- |
| Retencja a wskaźnik | Retencja #2024 (24 mies. + 3 najnowsze) **nie** chroni wersji przypiętych do wykonań; po skasowaniu wskaźnik → `NULL` | Wersja jest treścią publiczną, którą autor chce móc usunąć; wykonanie nie wydłuża jej życia |
| Wersja ukryta (#2270) | Kucharz, który nie jest autorem, jej nie widzi | Ukrycie ma być skuteczne wobec wszystkich poza autorem i moderacją |
| Backfill starych wykonań | Brak (wskaźnik pusty = „nie wiadomo") | Zgadywanie z dat byłoby zmyślonym stanem historycznym |
| Eksport danych konta | Dodany tylko numer wersji (`numer_wersji_przepisu`), bez treści | Wskaźnik to dane osoby; treść wersji jest cudzą pracą |

## Pytania do właściciela

1. Czy wersja przypięta do wykonania ma **chronić się przed retencją** (np. wersja,
   z której ktoś gotował, żyje dłużej niż 24 miesiące)? Kosztem jest dłuższe
   trzymanie treści, którą autor mógł chcieć usunąć — kłóci się to z uzasadnieniem
   D-333 (#2024). Domyślnie: nie.
2. „Wersja otwarta podczas gotowania” to dziś wersja z chwili otwarcia **formularza
   „Ugotowałem”**. Tryb gotowania (`/przepisy/{slug}/gotuj`) może być otwarty
   godzinę wcześniej. Czy zapisywać wersję z wejścia w tryb gotowania (wymaga
   zapamiętania jej w sesji/`cooking_progress`)? Domyślnie: nie.
3. Czy API (to dla aplikacji mobilnej) ma przyjmować `wersja_przepisu` od
   klienta? Dziś API zapisuje najnowszą wersję z chwili wysłania.
4. Czy kucharz, który stracił dostęp do wersji (np. przepis prywatny), ma dostać
   osobne wyjaśnienie powodu? Domyślnie: nie, jedno zdanie bez powodu (nie
   ujawniamy, że przepis jest prywatny/ukryty).
