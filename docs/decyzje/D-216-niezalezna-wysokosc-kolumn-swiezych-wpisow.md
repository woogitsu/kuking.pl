## D-216 — Niezależna wysokość kolumn świeżych wpisów (15 września 2026)

Właściciel w #560 wskazał pustkę pod krótszą kartą. Zmieniamy wyrównywanie
rzędów z #365: karta trzecia zaczyna się pod pierwszą, czwarta pod drugą.
Nie dzielimy DOM na dwie listy ani nie używamy CSS columns. Chronologiczna
kolejność HTML, czytnika i Tab zostaje; na telefonie nadal jest jedna kolumna.
Na szerokim ekranie czytanie wzrokiem może przechodzić między różnymi
wysokościami — to koszt żądanej kompozycji, nie powód zmiany kolejności danych.
Mały moduł ResizeObserver aktualizuje pozycje po zmianie rozmiaru kart;
brak skryptu zachowuje funkcjonalną siatkę, choć z dawnymi przerwami.
