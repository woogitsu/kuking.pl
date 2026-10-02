# Import: błąd dopasowania robots.txt (#2617)

`preg_match()` ma trzy wyniki: dopasowanie, brak dopasowania i błąd. Wzorzec
`/` + 20 razy `*a` + `*b$` może wyczerpać limit backtrackingu dla adresu
z 100 literami `a` i końcowym `b`. Dotychczas błąd znikał jak brak
dopasowania, więc domyślna decyzja pozwalała pobrać stronę.

Teraz błąd oceny przerywa import przed żądaniem HTML, także po
przekierowaniu. To nie jest stwierdzenie, że wydawca odmówił dostępu.
Komunikat prosi o późniejszą próbę albo ręczne wpisanie przepisu.
Zadanie importu zapisuje istniejący kod błędu wewnętrznego; nie wymaga
zmiany schematu ani nie tworzy szkicu z domniemaną zgodą. Krótsza reguła,
rzeczywisty brak dopasowania, grupy, query i kodowanie z #2569
zachowują dotychczasową semantykę.

Regresja `ImportRobotsPcreTest` ustawia jawny limit PCRE, sprawdza
oryginalny przypadek i brak żądania HTML po przekierowaniu. Kontrola
ujemna przywraca mylenie błędu PCRE z niedopasowaniem i musi oblać
oznaczoną asercję. Limit silnika pozostaje ograniczony; nie podnosimy go,
żeby ukryć koszt wzorca. Cofnięcie commitu przywróci błędną zgodę
przy wyczerpaniu limitu, więc nie jest bezpiecznym sposobem naprawy
awarii importu.
