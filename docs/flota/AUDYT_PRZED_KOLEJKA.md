# Audyt przed kolejką — czego szukać

Audytor ogląda gałąź, ZANIM wejdzie do kolejki pchania. Jest doradczy:
niczego nie blokuje, niczego nie poprawia, nie commituje. Pisze znaleziska.

Powód istnienia: CI sprawdza, czy testy przechodzą. **Nie sprawdzi, czy test
kiedykolwiek był czerwony, czy strażnik potrafi zapalić, ani czy raport mówi
prawdę o tym, co zmierzono.** Te trzy rzeczy kosztowały ten projekt najwięcej.

## Czego szukać — w kolejności ważności

### 1. Test, którego nikt nie widział na czerwono
Bugfix bez testu regresyjnego, albo test dopisany RAZEM z poprawką bez śladu,
że kiedykolwiek padł. Sprawdź kolejność commitów: test przed poprawką czy po?
Jeśli raport nie podaje, **co dokładnie padło i z jakim komunikatem** — to jest
znalezisko.

### 2. Strażnik bez kontroli dodatniej
W tym repozytorium obowiązuje zasada: **„skan, który nie znajduje żadnego
pliku, PRZECHODZI"**. Każdy nowy strażnik, skan, sonda albo miernik musi mieć
dowód, że potrafi zapalić czerwień. Brak takiego dowodu = znalezisko.
Zdarzyło się dziś dwa razy: sonda wdrożenia meldowała sukces bez pomiaru,
a miernik panelu ogłaszał naruszenie, którego nie było.

### 3. Twierdzenie o pomiarze, którego nie wykonano
Raport mówi „zmierzono", a widać tylko odczyt kodu albo cudzy wynik.
Cudze pomiary mają być oznaczone **[pomiar cudzy: źródło]**. Zdanie
„wygląda na zrobione" nie jest dowodem. Szukaj też liczb bez mianownika —
licznik bez mianownika pozwala wyciągnąć dowolny wniosek.

### 4. Połowiczna robota podana jako skończona
Reguła zastosowana w jednym miejscu, a pominięta w drugim, gdzie obowiązuje
tak samo. Dziś: krok porcji poprawiony w formularzu, a w kreatorze `1.255`
dalej cicho zaokrągla się do `1.26`. Sprawdź, czy poprawka obejmuje WSZYSTKIE
miejsca, w których problem występuje — karta, profil, powiadomienie, eksport.

### 5. Gałąź psująca własny test albo cudzy
Refaktor, który zostawia test odwołujący się do starego kształtu. Dziś:
przeniesienie arytmetyki minutnika do modułu zepsuło skrypt regresyjny tej
samej gałęzi, a CI pokazało to dopiero po pchnięciu i pełnym przebiegu.

### 6. Zmiana schematu bez kompletu
Migracja + test + `docs/DATABASE.md` + plan wycofania. **Wszystkie cztery,
nie trzy.** Brak któregokolwiek = znalezisko.

### 7. Kolizja z inną gałęzią
Ten sam plik ruszany przez dwie gałęzie naraz. Szczególnie groźne, gdy
konflikt da się „rozwiązać" biorąc swoją wersję i **nic nie zaświeci na
czerwono** — tak jest przy wspólnej linii `build` w `package.json` i przy
numerze wersji aplikacji. Wymień parę gałęzi i plik.

### 8. Zasady produktu
- `status` i `role` użytkownika NIGDY w `$fillable`.
- UUID ani nazwa w adresie to NIE autoryzacja — każde wejście przez Policy.
- Poprawne dane użytkownika nigdy nie znikają: po błędzie walidacji formularz
  odtwarza to, co człowiek wpisał, a nie to, co stoi w bazie.
- Martwy przycisk (D-053) i jego odwrotność: działający endpoint bez drogi
  dla człowieka.
- Komunikaty po polsku, mówiące CO ZROBIĆ, nie co się stało.
- UX 50+: tekst ≥ 18 px, cele dotyku ≥ 48 px, bez hover i swipe jako jedynej drogi.
- Obietnica bez pokrycia — tekst gwarantujący coś, czego kod nie zapewnia.

### 9. Pułapki powłoki i środowiska
- `printf '%s' "$X" | grep -q "$WZ"` pod `set -o pipefail` daje warunek
  FAŁSZYWY mimo trafienia (SIGPIPE). Ma być here-string.
- `grep -c` przy zerze trafień drukuje `0` **i** zwraca kod 1 — `|| echo 0`
  dokłada drugie zero.
- `MSYS_NO_PATHCONV=1` przy `git worktree add` zakłada katalog w `C:\c\Users\...`.
  Przy wywołaniach `wsl` jest obowiązkowy.

## Czego NIE robić

- Nie poprawiaj kodu. Nie commituj. Nie pushuj. Nie zmieniaj kolejki.
- Nie zgłaszaj stylu, preferencji ani „można by ładniej". Tylko rzeczy,
  które mają **skutek**: fałszywa zieleń, utrata danych, obietnica bez
  pokrycia, cicha regresja u kogoś innego.
- Nie powtarzaj znaleziska, które autor sam już opisał jako świadome
  ograniczenie. Jeśli raport mówi „tego nie zmierzyłem i dlatego" — to jest
  uczciwość, nie usterka.
- Nie zgaduj. Gdy nie da się rozstrzygnąć bez uruchomienia czegoś, napisz
  wprost „nie do rozstrzygnięcia z odczytu" i podaj, co trzeba uruchomić.

## Jak zapisywać

Jeden plik na przebieg audytu:
`_wspolne/skrzynka/meldunki/AUDYT-<data>-<godzina>.md`

Dla każdej gałęzi sekcja z: nazwą, zakresem zgłoszeń, listą znalezisk
(każde z numerem punktu z tej listy, plikiem i linią) oraz jawnym zdaniem,
gdy nic nie znaleziono. **Brak znalezisk to też wynik** — pod warunkiem, że
napiszesz, co sprawdziłeś.

Znaleziska dotyczące konkretnego stanowiska dopisz TAKŻE na końcu jego pliku
`_wspolne/skrzynka/zlecenia/<stanowisko>.md`, żeby autor je zobaczył.
Oznacz je wyraźnie jako **uwagi audytu, nie polecenie** — decyzja, co z nimi
zrobić, należy do autora i do koordynatora.
