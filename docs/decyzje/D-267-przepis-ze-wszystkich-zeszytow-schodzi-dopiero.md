## D-267 — Przepis ze WSZYSTKICH zeszytów schodzi dopiero po potwierdzeniu (#775, sprostowanie D-242 pkt 4, 25 września 2026)

**Decyzja właściciela z 25 września 2026.** Gdy „Usuń z zeszytu” przy
przepisie zdjęłoby go z więcej niż jednego zeszytu tej osoby, serwis
najpierw pyta. Odwracalność („Przywróć do zeszytu” z notatkami, D-242) tego
nie zastępuje: przycisk powrotu żyje jedno kliknięcie i znika przy
następnym wyjęciu, a notatka, która przepadła, bo ktoś nie zauważył
przycisku, przepadła naprawdę.

### Jak to działa
- To samo `DELETE collections.unsave` bez `collection_id`, gdy przepis leży
  w ≥ 2 zeszytach, **oddaje stronę potwierdzenia** zamiast wyjmować
  (`pages/collections/potwierdz-wyjecie-ze-wszystkich.blade.php`). Bez
  JavaScriptu, bez nowej trasy; reguła stoi po stronie serwera, więc chroni
  też stronę narysowaną, zanim przepis trafił do drugiego zeszytu.
- Strona mówi, z ilu zeszytów zejdzie przepis i ile notatek zniknie, daje
  „Usuń tylko z zeszytu „…”” dla każdego zeszytu, „Nie usuwaj — wróć”
  i — odsunięte, za kreską — „Tak, usuń ze wszystkich N zeszytów”
  (`potwierdzam_wszystkie=1`).
- Po akcji zostaje komunikat z liczbą i „Przywróć do zeszytu” (D-242).
- Jeden zeszyt albo wskazany `collection_id` — bez pytania, jak dotąd.

### Czego to nie zmienia
Wpisy (`collections.unsave-post`) działają jak dotąd; ta decyzja dotyczy
przepisu. Rozszerzenie na wpisy to osobne zgłoszenie.

### Wycofanie
Odwrócić commit. Schemat bazy się nie zmienia.
