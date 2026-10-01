# Porcje i czas kroku na kartce

## #2474 — zwykły odnośnik „Drukuj przepis”

Główna akcja korzysta z tego samego budowania adresu co wydruk dla pomocnika.
Do `?druk=1` trafia tylko liczba porcji przyjęta przez `WyborPorcji`: po
wybraniu 2 z przepisu na 4 nawigacja odnośnikiem otwiera kartkę z ilościami
przeliczonymi na 2. Brak wyboru lub błędna wartość wracają do ilości autora.
Dotyczy otwarcia odnośnika w nowej karcie oraz działania bez skryptu;
zwykłe kliknięcie przy działającym skrypcie nadal drukuje bieżący ekran.

Odbiór układu A4 i zapisu jako PDF wymaga osobnego pomiaru przeglądarkowego.

## #2484 — czas osobnego minutnika kroku

Zwykła strona przepisu (także po wejściu przez „Drukuj przepis”) i wydruk
zeszytu pokazują obok instrukcji „Czas kroku: …”, gdy autor zapisał dodatni
`timer_seconds`. Obie listy używają `RecipeStep::timerLabel()`, tak samo jak
tryb gotowania; brak minutnika i zero niczego nie dopisują. Czas nie zależy
od liczby wybranych porcji. Instrukcja oraz zapis w bazie pozostają bez zmian,
a na papierze nie pojawia się przycisk odliczania. Czas jest częścią
instrukcji gotowania, więc dziedziczy podstawowe pismo strony (co najmniej
18 px), zamiast mniejszego pisma metadanych.

Odbiór dwóch układów A4 / PDF — pojedynczego przepisu i zeszytu — pozostaje
do wykonania w izolowanej przeglądarce. Test HTTP potwierdza treść obu stron,
ale nie zastępuje tego odbioru.
