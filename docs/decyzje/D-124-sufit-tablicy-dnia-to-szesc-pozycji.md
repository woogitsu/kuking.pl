## D-124 · Sufit tablicy dnia to sześć pozycji — bo panel przyjmował sześć od początku, a automat stawał na czterech

**Data:** 11 września 2026 · Status: **obowiązuje**

### Rozjazd

`Admin\DailyBoardController` przyjmował `max:6` osób i `max:6` dań. `DailyBoard`
miał `PEOPLE = 4` i `POSTS = 4`. Gospodarz mógł wskazać więcej, niż tablica była
w stanie pokazać — i nic o tym nie mówiło.

### Decyzja

Sufit to sześć. Sufit zmienia, ILE pozycji widać, a nie to, KTÓRE stoją wyżej —
więc §12 pozostaje nietknięty. Limit gościa na landingu (3/3) zostaje bez zmian,
bo to osobna decyzja z audytu 60+.

### Dlaczego zmiana sufitu niczego nie przelicza

Regułę „najwyżej jedna pozycja od osoby" trzyma `DISTINCT ON (posts.author_id)` —
**struktura zapytania**, nie zgadywany zapas nad limitem. Przy poprzednim
podejściu („pobierz `POSTS * 6` i odsiej") każda zmiana sufitu wymagałaby
przeliczenia zapasu od nowa.

### Zmierzone

Liczba zapytań jest identyczna przed i po: `peopleToFollow()` 4 zapytania przy
limicie 4 i 4 przy 6; całe `forViewer()` 10 i 10. Limit idzie w SQL, relacje przez
`with()`/`withCount()`, więc liczba zapytań nie zależy od liczby wierszy.

Pomiar `28,6 ms` kontra `12,9 ms` z komentarza przy `peopleToFollow` zostaje ważny:
obie wersje płacą za agregację i sortowanie całości, a `LIMIT` obcina dopiero
posortowany wynik. Koszt rośnie z liczbą kont i wpisów, nie z sufitem.
