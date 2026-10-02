# Wizualnie pusta linia wklejanego przepisu (#2621)

Zgodnie z D-136 każda niepusta linia składników staje się jednym składnikiem,
a pusta linia rozdziela akapity przygotowania. Tekst wklejony z innych
edytorów bywa wizualnie pusty, choć linia zawiera U+00A0 albo U+202F.
Dotychczas taki znak tworzył niewidoczny składnik i sklejał dwa kroki.

`TekstNaWiersze` uznaje całą linię złożoną wyłącznie ze zwykłych spacji,
tabulatorów i tych dwóch spacji nierozdzielających za pustą. Nie zastępuje
znaków wewnątrz niepustej linii: `1 000 g mąki` pozostaje tekstem autora.
Pojedynczy enter nadal nie dzieli kroku. Normalizacja CRLF i CR pozostaje
bez zmian. Nie zmienia się schemat bazy ani wygląd formularza.

Regresja obejmuje parser i zapis przepisu przez istniejący formularz HTTP.
Dwie kontrole ujemne przywracają wadliwe rozpoznawanie pustej linii osobno
dla składników i kroków; CI wymaga porażki z własną przyczyną. Lokalny
sondaż PHP potwierdził wynik starej klasy `3 składniki / 1 krok`, a
poprawionej `2 składniki / 2 kroki`. Pełny zapis z PostgreSQL sprawdza CI.

Rollback: zwykły revert zmiany kodu i testów, bez migracji. Przywróci on
opisane sklejanie kroków; zapisane wcześniej receptury nie są automatycznie
przepisywane, ponieważ nie można bezpiecznie odgadnąć intencji autora.
