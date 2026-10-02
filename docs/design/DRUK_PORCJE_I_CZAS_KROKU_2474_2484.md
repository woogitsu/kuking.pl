# Porcje i czas kroku na kartce

## #2474 — zwykły odnośnik „Drukuj przepis”

Główna akcja korzysta z tego samego budowania adresu co wydruk dla pomocnika.
Do `?druk=1` trafia tylko liczba porcji przyjęta przez `WyborPorcji`: po
wybraniu 2 z przepisu na 4 nawigacja odnośnikiem otwiera kartkę z ilościami
przeliczonymi na 2. Brak wyboru lub błędna wartość wracają do ilości autora.
Dotyczy otwarcia odnośnika w nowej karcie oraz działania bez skryptu;
zwykłe kliknięcie przy działającym skrypcie nadal drukuje bieżący ekran.

Odbiór układu A4 i zapisu jako PDF wymaga osobnego pomiaru przeglądarkowego.
Pomiar `scripts/wydruk-przepisu.mjs` otwiera rzeczywisty odnośnik „Drukuj
przepis” po wyborze 2, 6 i 4 porcji dla krótkiego i długiego przepisu. Sprawdza
parametry odnośnika, przeliczenie na kartce i generuje osobny A4 PDF dla każdego
wyboru, motywu i gościa/autora. Brak `porcje` w odnośniku dla wyboru różnego od
liczby autora oblewa kontrolę ujemną. W CI tylko syntetyczne PDF-y z
`storage/wydruk-765/*.pdf` są dostępne w artefakcie `wydruk-a4-<SHA>` przez
trzy dni. Sam zielony pomiar nadal nie zastępuje obejrzenia stron PDF.

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

Pomiar przeglądarkowy używa lokalnego fixture z trzema kolejnymi krokami:
`timer_seconds` równym 600, 0 i `NULL`. W trybie drukowania sprawdza czas
`10 minut` przy pierwszym kroku oraz brak etykiety przy dwóch następnych,
zarówno na kartce przepisu, jak i w publicznym zeszycie. Z tych stron
przeglądarka generuje pliki A4 PDF. Kontrola ujemna usuwa etykietę czasu
z dokumentu i musi oblać pomiar. Sprawdzenie widocznego tekstu w print DOM
oraz wygenerowanie PDF nie stanowią ekstrakcji tekstu z pliku PDF ani
wizualnego odbioru całych kartek; te dwa odbiory trzeba odnotować osobno.
W artefakcie są również `zeszyt-czas-*.pdf` dla obu motywów i obu stanów
logowania. Odbiór wymaga sprawdzenia wszystkich stron obu ścieżek po pobraniu
artefaktu z dokładnego, zielonego SHA; brak artefaktu oznacza brak odbioru.
