# Odwołanie i edycja przepisu: kolejność blokad (#2165)

## Przeplot

Przy uznaniu odwołania zgłaszającego od decyzji „Bez działania” nowa kara
`ban` lub `suspend` dotyczy autora treści. Dla przepisu stara ścieżka brała
`recipes FOR UPDATE`, a dopiero potem `users FOR UPDATE` przez `User::ban()`.
Edycja przepisu w `PublishRecipe` bierze `users FOR KEY SHARE`, potem
`recipes FOR UPDATE`. Te kolejności tworzą cykl oczekiwania i PostgreSQL
przerywa jedną transakcję kodem `40P01`.

## Porządek po poprawce

`ResolveAppeal` trzyma wspólny zamek roli i świeży wiersz moderatora. Dla
nowej kary `DecyzjaPoOdwolaniu` ustala autora bez blokady celu, bierze jego
`users FOR UPDATE`, a następnie blokuje cel. Po czekaniu sprawdza ponownie
istnienie celu i tożsamość autora; polityka kary oraz przejście stanu używają
świeżego, zablokowanego konta. Inne decyzje nie biorą dodatkowej blokady.

## Test

`tests/Dwa/OdwolanieNieZakleszczaEdycjiPrzepisuTest.php` uruchamia prawdziwe
`ResolveAppeal` i `PublishRecipe` w osobnych procesach na bazie
`kuking_race*`. Edycja trzyma `users FOR KEY SHARE` i czeka na barierze,
zanim sięgnie po przepis. W tym czasie decyzja dochodzi do następnej
blokady. Test wymaga końca obu operacji bez `40P01`, zatwierdzonej edycji,
kary i odwołania oraz dokładnie jednego wpisu decyzji, powiadomienia i obu
śladów audytu.

W CI `scripts/kontrola-negatywna-2165.py` przestawia blokadę przepisu przed
blokadę konta. Test musi wtedy oblać się przez `40P01`; skrypt przywraca
źródło bajt w bajt i uruchamia ten sam test ponownie. Kontrola działa na
osobnej bazie przygotowanej przez `scripts/testy-dwa-polaczenia.sh`.
