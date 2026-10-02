# Surowiec w dopasowaniu wartości odżywczych (#2607)

D-299 wymaga dopasowania do rzeczywistej pozycji tabeli, bez zgadywania.
Alias „mąka” w naszym CSV oznacza konkretnie mąkę pszenną CIQUAL 9435.
„Mąka z ciecierzycy” ma inny surowiec, a w zatwierdzonym pliku nie ma
jej pozycji. Nie wolno więc odciąć „z ciecierzycy” i policzyć jej jak pszennej.

Przy skracaniu nazwy słownik nie pomija frazy zawierającej przyimek „z”
lub „ze”. To reguła granicy pełnej nazwy produktu, a nie nowa lista nazw
roślin. Pełny alias, np. „sok z cytryny”, nadal ma pierwszeństwo. Zwykła
„mąka”, pełne aliasy pszennej i gryczanej oraz neutralne opisy pozostają
obsługiwane. Ochrona stanu obróbki z #2563 i mleka kokosowego pozostaje
osobna. Nie dopisujemy wartości odżywczych ciecierzycy ani nie zmieniamy
tekstu autora.

Gdy pełnego aliasu nie ma, znane 500 g wchodzi do całkowitej masy jako
nieznany składnik, ale nie do masy pokrytej tabelą. Obowiązujący próg 90%
rozstrzyga o pokazaniu liczb. Regresja używa rzeczywistego importu CSV,
sprawdza klucz produktu i końcowy kalkulator. Kontrola ujemna fizycznie
przywraca ucinanie „z/ze”; oba sprawdzane wyniki muszą wtedy oblać się
na markerze `SUROWIEC_2607_NIE_POMIJAJ_ZRODLA`.

Rollback kodu przywraca błędne zaliczanie mąki z ciecierzycy jako pszennej.
Schemat i dane pozostają bez zmian. Ten słownik nie rozstrzyga alergenów.
