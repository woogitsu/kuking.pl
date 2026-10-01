# Porcje i czas kroku na kartce

## #2474 — zwykły odnośnik „Drukuj przepis”

Główna akcja korzysta z tego samego budowania adresu co wydruk dla pomocnika.
Do `?druk=1` trafia tylko liczba porcji przyjęta przez `WyborPorcji`: po
wybraniu 2 z przepisu na 4 nawigacja odnośnikiem otwiera kartkę z ilościami
przeliczonymi na 2. Brak wyboru lub błędna wartość wracają do ilości autora.
Dotyczy otwarcia odnośnika w nowej karcie oraz działania bez skryptu;
zwykłe kliknięcie przy działającym skrypcie nadal drukuje bieżący ekran.

Odbiór układu A4 i zapisu jako PDF wymaga osobnego pomiaru przeglądarkowego.
