# Koszt autora przy wybranych porcjach (#2524)

Szacunkowy koszt wpisany przez autora dotyczy całego przepisu na jego liczbę
porcji (D-286, część 1). Gdy ktoś wybiera inną, poprawną liczbę porcji,
strona i jej wydruk pokazują ilości składników oraz koszt z **tego samego**
`WyborPorcji`. Kwota przechodzi przez istniejące `KosztPrzepisu::naPorcje()`:
24 zł na 4 porcje daje 12 zł na 2 albo 36 zł na 6. Zdanie mówi, że to
przeliczenie szacunku autora, nie cena sklepu.

Wybór niepoprawny, powrót do ilości autora i przepis bez podstawy porcji
zostawiają oryginalną kwotę z podpisem „wg autora”. Brak kwoty nie tworzy ceny,
a wpisane 0 zł pozostaje widoczne. Przedział z cennika (D-286, część 2)
celowo nie skaluje się. Żądanie GET nie zmienia przepisu, historii wersji
ani zakresu „Do 20 zł”, który nadal korzysta z kwoty zapisanej przez autora.

Test HTTP `SkalowaniePorcjiNaStroniePrzepisuTest` porównuje równocześnie
składnik i tekst kosztu dla zwykłej strony i `?druk=1`, także przy powrocie
oraz niepoprawnym wyborze. Fizyczna kontrola ujemna przywraca w widoku
`costLabel()` i wymaga porażki dokładnie tej asercji. Test i kontrola
wymagają izolowanej bazy PostgreSQL 18; lokalnie bez niej nie są dowodem.

Wycofanie: usunięcie warunku przeliczenia w widoku przywraca dawny opis
kwoty. Schemat ani zapisane wartości nie zmieniają się.
