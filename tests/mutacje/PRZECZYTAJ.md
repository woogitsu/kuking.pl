# Testy mutacyjne — jak czytać wyniki

Program: `scripts/mutacje.sh` · przyrząd: `scripts/kontrola-ujemna.sh`

    scripts/mutacje.sh tests/mutacje/*.txt --json storage/mutacje.json

## Cztery rozstrzygnięcia, nie dwa

Najważniejsza rzecz w tym katalogu: **„mutacja przeżyła" nie znaczy „test jest
atrapą"**. Są cztery różne rzeczy i mylenie ich produkuje fałszywe alarmy,
które zniechęcają ludzi do narzędzia szybciej, niż jakikolwiek błąd.

| Rozstrzygnięcie | Co znaczy | Co z tym zrobić |
|---|---|---|
| **ZABITA** | mutacja weszła, test oblał z właściwego powodu | nic — gwarancja ma dowód |
| **MIERZYŁEM NIE TEN TEST** | przeżyła wskazany test, ale ginie na pełnym pakiecie | popraw `polecenie:` w katalogu. To błąd katalogu, nie dziura |
| **DZIURA W POKRYCIU** | przeżyła także pełny pakiet, a zachowanie naprawdę się zmienia | dopisz asercję |
| **MUTACJA BEZ ZNACZENIA** | przeżyła, bo zachowanie się NIE zmienia (kod nieosiągalny, druga warstwa obrony, jawny wyjątek w teście) | wpisz uzasadnienie w `ocena:` i zostaw |

Trzeciej kolumny nie wypełnia maszyna. Program tego nie udaje i mówi o tym
wprost w wyniku.

## Jak rozstrzygnąć „nie ten test" tanio

Puść tę samą mutację z `polecenie:` wskazującym cały pakiet. Jeśli zginie —
gwarancji pilnuje inny test, a katalog był zły. Wzorzec jest w
`triaz-dziewiatki.txt`.

**Uwaga na koszt:** przyrząd uruchamia polecenie trzy razy na mutację (kontrola
dodatnia, po mutacji, po przywróceniu). Przy pełnym pakiecie to ~15 minut na
jedną mutację. Do triażu pojedynczych przypadków — w porządku; do całej serii
— nie.

## Zanim uwierzysz w jakiekolwiek liczby

Przy pierwszym przebiegu 34 z 40 prób wyszły jako `WADLIWA_BRAK_KD`, bo
w kopii roboczej zniknął `.env`. Bez kontroli dodatniej wynik brzmiałby
„5 zabitych, 1 przeżyła" i wyglądałby jak pomiar. **Nie wyłączaj kroku 1/4,
żeby przyspieszyć** — to jedyna rzecz, która odróżnia pomiar od liczby.

`WADLIWE PRÓBY > 0` znaczy: nie masz wyniku. Napraw i powtórz.

## A jeśli `ZLA_PRZYCZYNA` wygląda na pomyłkę przyrządu — sprawdź to, zanim rozluźnisz wzorzec

Zdarzyło się raz i kosztowało cztery fałszywe pozycje w rejestrze. Przyrząd
sprawdzał wzorzec przez `printf … | grep -q` pod `set -o pipefail`; `grep -q`
wychodził na pierwszym trafieniu, `printf` dostawał SIGPIPE (141) i status
całego potoku był niezerowy **mimo trafienia**. Objawiało się wyłącznie przy
dużym wyjściu, więc krótkie atrapy tego nie łapały. Naprawione (`<<<` zamiast
potoku), z atrapą wypisującą ~200 kB — szczegóły w `docs/PULAPKI_TESTOW.md` §5c.

Morał na przyszłość: **nigdy nie rozluźniaj `--oczekuj`, żeby „w końcu
trafił"**. Jeśli widzisz wzorzec w wyjściu gołym okiem, a przyrząd twierdzi
inaczej, to jest usterka przyrządu i należy ją zgłosić, a nie obejść.
