# Kiedy CI uruchamia przeglądarkę — #1039

`zakres.outputs.widok` oznacza: zmiana może wpłynąć na ekran albo jego
pomiar. Nie oznacza tylko zmiany CSS, JavaScriptu lub szablonu Blade.

Oprócz dotychczasowych zasobów i przyrządów filtr obejmuje całe `app/`,
`routes/`, `config/`, `bootstrap/`, `lang/`, `database/` oraz `composer.json`
i `composer.lock`. Kontrolery nie mają wyjątków: nawet przekierowanie,
walidacja, autoryzacja albo dane modelu mogą zmienić zachowanie przeglądarki.
Koszt świadomy: zmiana czysto technicznego kontrolera także uruchomi pomiar.
Nie utrzymujemy kruchej listy plików PHP uznanych za wizualne.

Sama dokumentacja w `docs/` i `README.md` nadal pomija ciężkie joby.
Warunek pozostaje na całych jobach `port_marki`, `port_funkcje`, `dostepnosc`,
nie na krokach. Brak dostępnej bazy porównania uruchamia pełny zestaw.

`PortMarkiMaWlasnaBramkeCiTest` wykonuje rzeczywisty warunek Bash z workflowu
dla reprezentantów każdej klasy ścieżek, zmian mieszanych oraz dużego diffu.
To dowodzi wyjścia `widok`, nie wykonania zdalnego pomiaru przeglądarkowego.
Przed scaleniem nadal trzeba sprawdzić rzeczywiste kroki wymaganych jobów CI.

Zmiana nie wymaga migracji ani ingerencji na produkcji. Wycofanie commita
przywróci dawną lukę w pokryciu PHP — nie jest bezpieczną metodą oszczędzania
minut. Przy konflikcie z inną zmianą filtra zachowaj sumę obu zakresów,
w szczególności skrypty strażnika martwych reguł CSS z PR #960.

## Pomiar lokalny z 21 września 2026

Na bazie `65327e69ddc3423279c7324ff20639ead64c6610`: 25 testów,
187 asercji, wynik dodatni; Pint dla zmienionego testu także przeszedł.
Dwie kontrole przez `scripts/kontrola-ujemna.sh`: zastąpienie gałęzi `app/`
oraz osobno `routes/` niepasującą nazwą dało za każdym razem
PASS → FAIL z oczekiwaną ścieżką → PASS. Przyrząd porównał MD5 i mtime
przywróconego workflowu. Nie usunięto żadnej wcześniejszej asercji testu.

Nie uruchamiano pełnego zestawu równolegle z kolejką floty ani zdalnego CI.
Częstsze uruchamianie trzech jobów jest kosztem poszerzenia ochrony;
jego zdalny czas i koszt nie zostały jeszcze zmierzone. Kryterium issue
o rzeczywistym wykonaniu jobów na GitHubie pozostaje do potwierdzenia.
