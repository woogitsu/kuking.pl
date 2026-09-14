# Składnik przyjęty przez formularz mieści się także w słowniku — #526

## Zakres

Punkt wyjścia `fc7473ee936323b97f00842e424a8c3923712a48`, osobna gałąź
`fix/526-pelny-skladnik`, worktree `kuking-ingredient526`.
Poprawka obejmuje migrację i dokumentację schematu. Nie zmienia parsera,
normalizacji, walidacji, limitu 240 znaków, modelu ani akcji publikowania.
Nie obcina żadnej nazwy. Bez wersji, changeloga, commita i publikacji.

## Przyczyna i decyzja

`RecipeController` akceptuje `ingredients.*.text` do 240 znaków.
`TekstNaWiersze::skladniki()` rozbija pole tekstowe na wiersze, a
`PublishRecipe::cleanIngredients()` przekazuje tekst do `recipe_ingredients`
oraz do `Ingredient::findOrCreateByName()`. Kolumna `ingredient_text` już
miała 240 znaków, lecz obie kolumny słownika miały `varchar(160)`.
Publikacja kończyła się SQLSTATE 22001 i HTTP 500.

D-017 wymaga dopasowania systemu do tekstu autora. Dlatego rozszerzamy
słownik, zamiast zmuszać autora do skrócenia zaakceptowanej nazwy:

- `canonical_name`: `varchar(160)` → `varchar(240)`;
- `normalized_name`: `varchar(160)` → `text`.

Drugie `varchar(240)` nie wystarcza: `Ingredient::normalize()` używa
`Str::ascii`, małych liter i redukcji białych znaków. **240 znaków `Æ`
zamienia się w 480 znaków `ae`**. Wynik normalizacji pozostaje kompletny;
nie zastępujemy go obciętym prefiksem ani innym kluczem dopasowania.

PostgreSQL zachowuje istniejące `UNIQUE(normalized_name)` oraz indeks
`ingredients_name_trgm_idx` na `kuking_normalize(normalized_name)` z GIN.
To ważne także dla `firstOrCreate()`: unikalność rozstrzyga wyścig dwóch
publikacji, nie sam wcześniejszy SELECT.

## Migracja i cofnięcie

`2026_09_14_100000_dopasuj_slownik_skladnikow_do_formularza.php` zmienia
wyłącznie typy dwóch kolumn. Nie przelicza istniejących nazw.

`down()` wykonuje w jednej transakcji blokadę `ACCESS EXCLUSIVE`, kontrolę
obu długości oraz zwężenie. Dzięki temu pomiędzy kontrolą i zwężeniem nie
może wejść długa nazwa. Zgodnie z D-088:

- krótkie dane: cofnięcie do 160 i ponowne rozszerzenie przechodzą;
- dowolna nazwa ponad 160 znaków: cofnięcie odmawia z instrukcją,
  nie obcina danych ani nie pozostawia połowicznie zmienionych typów.

Po zapisaniu długich nazw pozostawienie rozszerzonego schematu jest
bezpieczniejsze od jego zwężania. Starszy kod może nadal korzystać z tych
kolumn, więc cofnięcie aplikacji nie wymaga cofania tej migracji. Zmiana
typu bierze blokadę tabeli; nie zmierzono czasu takiej operacji na produkcji.

## Wykonane testy

PHP 8.4.24, osobna kopia `/tmp/kuking-ingredient526-exec`, jawny
`APP_BASE_PATH`, `APP_URL=http://localhost`, PostgreSQL `127.0.0.1:55439`,
nowa baza `kuking_proof526`, UTC. Wyłącznie dane testowe, bez zdjęć.

`PelnaNazwaSkladnikaTest` wykonuje rzeczywiste publikacje i edycje zarówno
formularzem wierszowym, jak i podstawowym polem tekstowym:

1. 240 znaków trafia w całości do `recipe_ingredients` i słownika;
2. edycja zmienia ostatni znak, który także zostaje zachowany; jest jeden
   przepis i jeden jego składnik, a dwie różne pełne nazwy w słowniku;
3. 240 znaków `Æ` zapisuje się z pełną 480-znakową normalizacją;
4. edycja na małe `æ` zachowuje pisownię autora w przepisie, lecz nadal
   wskazuje to samo hasło słownika — nie tworzy duplikatu.

`MigracjaPelnejNazwySkladnikaTest` mierzy down/up z krótkimi danymi,
niezmienione wartości i UUID, odmowę dla każdej kolumny osobno, typy kolumn,
obecność obu indeksów oraz rzeczywiste odrzucenie duplikatu przez UNIQUE.
Istniejący `IngredientRaceTest` nadal przechodzi.

Końcowy ograniczony zestaw: **29 testów, 210 asercji, zielony**, 1,698 s,
79 MiB raportowane przez PHPUnit. Oprócz nowych testów obejmuje
`IngredientRaceTest`, `RecipeTest`, `SkladnikBezIlosciTest` i
`DodawaniePrzepisuSzescKontrolekTest`. Pint: 3 pliki poprawne. PHPStan:
nowa migracja bez błędów. Nie uruchamiano całego PHP ani CI.

## Kontrole ujemne

Sześć osobnych mutacji w izolowanej kopii wykonawczej oblało właściwe testy:

1. pozostawienie `canonical_name` z limitem 160;
2. pozostawienie `normalized_name` z limitem 160;
3. pominięcie strażnika długości nazwy kanonicznej w rollbacku;
4. pominięcie strażnika długości normalizacji w rollbacku;
5. usunięcie UNIQUE;
6. usunięcie GIN.

Po każdej próbie przywrócono bajty i mtime migracji. MD5:
`147a5e4d79f8ccccf08e07089a7642ce`. Pełne wyjścia są w
`output/proof526/negative-*.txt`, metryki przywrócenia w `negatives.json`,
reproduktor mutacji w `proof526-negatives.py`. Po negatywach zestaw był
zielony; końcowy wynik po dodaniu drugiego formularza:
`output/proof526/green-final.txt`. Osobne `pint.txt` i `phpstan.txt`
zawierają wyniki tych kontroli.

## Granica poprawki

Nie zmieniono istniejącego traktowania tekstów ponad 240 znaków przez
warstwę domenową ani rozpoznawania ilości. Nie zmieniono kluczy normalizacji,
tożsamości słownika ani wyszukiwania. Ta poprawka usuwa sprzeczność między
już przyjmowaną nazwą składnika a kolumnami potrzebnymi do jej zapisu.

## Odbiór po integracji

Zestaw lokalny obejmujący granice 160/161/240, migrację, kreator, odzyskiwanie i podsumowania walidacji: 72 testy / 891 asercji, wszystkie poprawne. To wynik szerszego filtra, nie aktualizacja historycznych 29/210 powyżej. W prawdziwej lokalnej przeglądarce utworzono prywatny przepis z 240-znakowym składnikiem przez formularz POST, następnie zapisano inną 240-znakową nazwę w kreatorze; pełna nowa wartość była widoczna po ponownym wejściu na przepis. Dane odbioru istnieją wyłącznie lokalnie.
