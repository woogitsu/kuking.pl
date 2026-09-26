## D-258 — Poprawiony wpis przechodzi analizę automatu jeszcze raz; wpisu pod decyzją moderacji nie da się edytować (24 września 2026)

**Data:** 24 września 2026 · Issue #936 · **Decyzja właściciela 24.09.2026** · Status: **obowiązuje**
(zmienia jeden wiersz „ODŁOŻONE” z D-052)

**Co.**
1. Gdy autor **rzeczywiście zmieni** treść opublikowanego wpisu — tekst,
   tytuł pytania albo zestaw tagów — `EditPost` zleca
   `PrzeanalizujTresc::dlaWpisu()`: to samo zadanie, w tej samej kolejce
   `low`, co po publikacji, dopiero po zatwierdzeniu transakcji
   (`afterCommit`). Zapis bez zmiany i sama zmiana widoczności nie zlecają
   nic — z jednym wyjątkiem: wpis wychodzący z „tylko ja”. Prywatnego wpisu
   analiza przy publikacji nie ogląda, więc pierwsze pokazanie go ludziom
   jest pierwszą okazją do analizy.
2. Wpis `hidden` albo `removed` nie jest edytowalny (`PostPolicy::update()`,
   także zdjęcia i „wspomnienia”, które idą przez tę samą regułę). Status
   jest sprawdzany ponownie pod blokadą wiersza w `EditPost`. Autor dostaje
   komunikat, co może zrobić (odwołanie), a tekst wpisany mimo to wraca
   na ekranie do skopiowania.

**Dlaczego wariant „zablokuj”, a nie „pozwól poprawić i oznacz do
ponownego przeglądu”.** Ten sam kontrakt co dla komentarzy (#937). Drugi
wariant wymaga nowego stanu workflow (kolumna, ekran, przegląd przed
przywróceniem), którego nie ma. Przy blokadzie `RestoreContent` przywraca
dokładnie tę treść, o której moderator zdecydował, a odwołanie (DSA art. 20)
dotyczy tego samego tekstu. Autor, który chce poprawić wpis, odwołuje się
albo publikuje nowy.

**Obietnica „odrzucone nie wraca” (D-052)** — nienaruszona:
`OznaczDoPrzegladu::juzOgladane()` i indeks `reports_jeden_automat_na_tresc`
przepuszczają jedno oznaczenie na wpis, na zawsze. Kilka szybkich poprawek
daje kilka zadań (ograniczonych limitem trasy i tylko rzeczywistą zmianą),
ale każde czyta wpis po ID w chwili wykonania, więc ocenia najnowszą wersję,
a wynik to najwyżej jedna pozycja w kolejce.

**Czego to nie zmienia.** Wynik jest sygnałem dla moderatora (D-052, D-055):
wpis zostaje opublikowany, autor nie dostaje powiadomienia. Wyłącznik
`KUKING_SYGNALY_AUTOMATU` i granica widoczności (`GranicaWysylki`, D-240)
działają jak przy publikacji.

**Znana granica.** Wpis, którego oznaczenie moderator już odrzucił, po
edycji nie wraca do kolejki automatu — cena obietnicy z D-052. Zostaje
zgłoszenie od człowieka.

Dowody: `tests/Feature/AnalizaPoEdycjiWpisuTest.php`.

### Wycofanie
Odwrócić commit. Schemat bazy się nie zmienia; oznaczenia postawione po
edycji zostają w kolejce jak każde inne.
