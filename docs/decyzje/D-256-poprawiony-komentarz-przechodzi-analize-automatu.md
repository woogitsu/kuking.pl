## D-256 — Poprawiony komentarz przechodzi analizę automatu jeszcze raz (24 września 2026)

**Data:** 24 września 2026 · Issue #909 · Status: **obowiązuje**
(potwierdzone przez właściciela 25 września 2026 — zmienia jeden wiersz
„ODŁOŻONE” z D-052)

**Potwierdzenie właściciela (25 września 2026):** treść decyzji z
24 września obowiązuje bez zmian. Audyt dokumentacji B6 (znalezisko
B6-12) zwrócił uwagę, że commit wszedł na `main` (#909), zanim wpis dostał
status inny niż „do decyzji właściciela” — właściciel potwierdza tę treść
zamiast wycofywać commit.

**Co.** Gdy autor w 15-minutowym oknie **rzeczywiście zmieni** tekst
opublikowanego komentarza, `CommentController::update()` zleca
`PrzeanalizujTresc::dlaKomentarza()` — to samo zadanie, w tej samej kolejce
`low`, co po publikacji. Zapis bez zmiany (`wasChanged('body')` fałszywe)
nic nie zleca. Wpisy i przepisy zostają bez zmian.

**Dlaczego.** D-052 odłożył ponowną analizę po edycji „ze świadomą luką”,
z dwóch powodów. Oba przy komentarzu nie trzymają:

1. *Obietnica „odrzucone nie wraca”* — nienaruszona. Zadanie kończy się
   w `OznaczDoPrzegladu`, a tam `juzOgladane()` i indeks
   `reports_jeden_automat_na_tresc` przepuszczają jedno oznaczenie na
   komentarz, na zawsze. Edycja NIE otwiera sprawy odrzuconej i nie stawia
   drugiej pozycji przy otwartej (moderator i tak ogląda aktualny tekst).
2. *Koszt zadania za każdą literówkę* — ograniczony: okno 15 minut, limit
   trasy `comment`, tylko rzeczywista zmiana. Kilka szybkich poprawek daje
   kilka zadań, ale każde czyta komentarz po ID, więc każde ocenia
   najnowszy tekst, a wynik to najwyżej jedna pozycja w kolejce.

Luka była najtańszym obejściem wykrywacza: neutralny komentarz → zakończona
analiza → dopisany spam.

**Czego to nie zmienia.** Wynik jest sygnałem dla moderatora (D-052, D-055):
treść zostaje opublikowana, autor nie dostaje powiadomienia. Wyłącznik
`KUKING_SYGNALY_AUTOMATU` i granica widoczności (`GranicaWysylki`, D-240)
działają jak przy publikacji — zadanie ogląda tylko opublikowany komentarz.

**Znana granica.** Komentarz, którego oznaczenie moderator już odrzucił,
po edycji nie wraca do kolejki automatu — to cena obietnicy z D-052.
Zostaje zgłoszenie od człowieka.

Dowody: `tests/Feature/AnalizaPoEdycjiKomentarzaTest.php` (zakończona
pierwsza analiza, pierwsze zadanie wciąż w kolejce, zapis bez zmiany,
wyłącznik, odrzucone nie wraca).

### Wycofanie
Odwrócić commit. Schemat bazy się nie zmienia; oznaczenia postawione po
edycji zostają w kolejce jak każde inne.
