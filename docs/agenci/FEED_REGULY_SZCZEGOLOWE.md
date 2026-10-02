# Feed: półka „Mój stół”, Obserwowani, nowa reguła doboru

Przeniesione z `AGENTS.md` §8 bez zmian treści; zasada w skrócie i odnośnik do tego pliku zostają w `AGENTS.md`.

Półka **„Mój stół”** (D-304, #1749) dobiera wyłącznie z tej listy: obserwowane
tagi, tag z listy gospodarza, „kuKINGi na dziś” w kolejności gospodarza, czas,
jeden przepis od osoby, bramki i ukrycia.

W **Obserwowanych** nic nie znika poza bramkami i blokadami oraz wpisami, które
widz sam ukrył („Ukryj ten wpis”, a przy wpisach z obserwowanego tagu także
„Ukryj tę osobę”; D-278 — z listą „Ukryte” do cofnięcia). Osoby obserwowane
wprost nie znikają nigdy.
Dopuszczalne jest tylko zwinięcie serii wpisów jednej osoby albo jednego
obserwowanego tagu (D-279: dwa widać, reszta pod „Pokaż”), bez zmiany
kolejności.

Każda nowa reguła doboru = wpis w `docs/DECISIONS.md` + aktualizacja „Jak
dobieramy wpisy” (zdanie w `App\Domain\Feed\JakDobieramyWpisy` z dowodem
w `tests/Feature/JakDobieramyWpisyMowiPrawdeTest.php`, D-305) + strażnik (`tests/Feature/FeedNieSortujePoMierzeReakcjiTest.php`
albo nowy). Reguła spoza tej listy wymaga decyzji właściciela, nie PR-a.
