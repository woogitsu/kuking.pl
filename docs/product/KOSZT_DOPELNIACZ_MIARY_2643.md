# Dopełniacz miary przy orientacyjnym koszcie — #2643

Zapis „0,5 kilograma masła” oznacza 500 g. Parser kosztu wcześniej nie znał
formy „kilograma” i traktował ją jak nazwę produktu po liczbie, czyli pół
sztuki. Przy cenniku, w którym sztuka masła ma 200 g, dawało to 100 g.
Ten sam błąd dotyczył form „grama”, „dekagrama” i „mililitra”.

`IloscZTekstu` rozpoznaje teraz te cztery dopełniacze tak samo jak istniejące
formy mianownika i liczby mnogiej. To skończona lista zgodna z rozpoznawanymi
formami `JednostkiMiary` dla wartości odżywczych. Zwykła nazwa po liczbie,
np. „0,5 jajka”, nadal oznacza sztukę. Nie zgadujemy innych miar.

Regresja porównuje wynik parsera dla zapisu dziesiętnego, słownego i ułamka
oraz rzeczywistą wycenę na stałym małym cenniku: pół kilograma musi dać
ten sam wynik co 500 g, a inny niż pół sztuki. Kontrola ujemna usuwa alias
„kilograma” i wymaga porażki przy własnym znaczniku testu.

Zakres to tylko odczyt tekstu do szacunku zgodnie z D-286. Składnik autora,
ceny, próg pokrycia i pierwszeństwo kwoty podanej przez autora nie zmieniają
się. Bez migracji. Cofnięcie commitu przywraca błędną wycenę dopełniaczy;
zapisane przepisy i ceny pozostają nietknięte.
