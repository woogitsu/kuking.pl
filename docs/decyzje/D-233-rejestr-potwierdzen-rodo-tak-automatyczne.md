## D-233 — Rejestr potwierdzeń RODO tak, automatyczne kasowanie wpisów NIE (#1222 nie dotyczy)

22 września 2026, jawna decyzja właściciela przy odbiorze gałęzi
`naprawa/minimalne-potwierdzenie-rodo`. Gałąź robiła dwie rzeczy: zakładała
rejestr potwierdzeń obsługi żądań RODO z zapisem **atomowym, w tej samej
transakcji co skutek**, i włączała **automatyczne kasowanie tych wpisów po 36
miesiącach, domyślnie, bez przełącznika**. Właściciel przyjmuje pierwszą część
i wstrzymuje drugą.

Autor gałęzi uzasadniał brak przełącznika zdaniem „wyłącznik retencji to
bezterminowość pod inną nazwą”. Argument zostaje zapisany, bo jest sensowny
i bo za tydzień ktoś wyprowadzi go ponownie. Nie przeważa jednak dwóch rzeczy.
Po pierwsze, **okresu nie potwierdził prawnik**: 36 miesięcy to analogia do
dokumentacji sprawy moderacyjnej (art. 442¹ k.c., D-057 i ADR_RETENCJE §4), nie
ustalenie dla tej kategorii. Po drugie, kasowanie jest **twardym `DELETE`,
nieodwracalnym** — bez soft-delete i bez eksportu. Po jego włączeniu, dla kont,
których ostatnie zdarzenie RODO jest starsze od progu, na pytanie „czy i kiedy
usunęliście dane tej osoby” nie zostaje nic. Polityka prywatności mówi przy tym
o kopiach zapasowych: „Nie podajemy tu liczby dni, bo nie ustaliliśmy jej
jeszcze z dostawcą” — czyli nie jest znana nawet długość drogi odzysku.

Wyłączenie stoi na dwóch niezależnych barierach, żeby nie zdejmowała go jedna
pomyłka: `kuking.potwierdzenia_rodo.retencja_wlaczona` jest `false`, a zadanie
`kuking:sprzataj-potwierdzenia-rodo` **nie jest wpięte w `routes/console.php`**.
`retention_months` jest `null`, nie 36, więc samo przestawienie flagi nie
uruchamia kasowania według okresu, którego nikt nie potwierdził. Komenda
istnieje i jest przetestowana; `--na-sucho` działa mimo wyłączenia, bo tym mają
zostać przygotowane dane historyczne.

Ta decyzja **nie cofa** niczego, co gałąź zrobiła dobrze: dziewięciu ograniczeń
CHECK, braku ekranu dla tej tabeli (osobny test skanuje trasy i widoki),
zapamiętania zakresu żądania **przed** anonimizacją ani atomowości zapisu.
Wyłączenie ma być zdjęte świadomie, po potwierdzeniu okresu — droga w trzech
krokach stoi przy kluczu `potwierdzenia_rodo` w `config/kuking.php`
i w `docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md` §6.

Numer wzięty po sprawdzeniu gałęzi, nie tylko `main`: D-223 (kaskada), D-227
(#1164), D-228 (numer #966 na gałęzi, który nie wszedł na `main`; od 29 września 2026 bezpiecznik baz z tej gałęzi ma numer D-334, więc D-228 jest wolny), D-229 (#1180), D-230
(#1168) są zajęte, a D-232 jest
zarezerwowany dla poprawki kolizji numeru w #1222. Niczego nie przenumerowano.

Pilnuje tego `tests/Feature/RetencjaPotwierdzenRodoTest.php` — obie strony:
że domyślnie nic się nie kasuje i że po jawnym włączeniu automat działa.

**Aktualizacja 2 października 2026 (#2708).** Właściciel uznał analizę z
2.10.2026 (pytanie 7) za potwierdzenie okresu i zdjął wyłączenie: 36 miesięcy
od `zakonczono`, kasowanie włączone, komenda w harmonogramie, droga w
kolejności z `PROJEKT_POTWIERDZENIA_RODO.md` §6. Pełny zapis jest w wierszu
„Retencja potwierdzeń żądań RODO: 36 miesięcy” w D-333. Reszta tej decyzji
(twardy `DELETE`, brak ekranu, CHECK-i) obowiązuje bez zmian.
