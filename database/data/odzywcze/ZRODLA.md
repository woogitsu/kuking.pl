# Wartości odżywcze — źródła danych i licencje (D-299)

Pliki w tym katalogu są **jedynym** źródłem tabeli wartości odżywczych
w Kuking. Produkcja wczytuje je komendą
`php artisan kuking:importuj-wartosci-odzywcze`; aplikacja niczego nie
pobiera z sieci (decyzja właściciela z 26 września 2026).

| Plik | Co zawiera |
|---|---|
| `skladniki.csv` | pozycja tabeli: klucz, polska nazwa, polskie formy nazwy (`aliasy`, rozdzielone `\|`), źródło i identyfikator pozycji w źródle, gęstość w g/ml (do przeliczania mililitrów, pusta = nie przeliczamy), `pomijalny` (1 = sól, przyprawy, zioła, woda: bez ilości nie blokują wyniku), nazwa pozycji w źródle i cztery wartości na 100 g części jadalnej |
| `miary.csv` | miary domowe per składnik: „1 szklanka mąki pszennej = 140 g”, „1 cebula = 110 g” |

## Źródło 1: CIQUAL 2025 (Anses, Francja)

- **Nazwa:** Table de composition nutritionnelle des aliments Ciqual 2025.
- **Wydawca:** Agence nationale de sécurité sanitaire de l'alimentation, de
  l'environnement et du travail (Anses), Observatoire des Aliments.
- **Wersja:** plik `Table Ciqual 2025_FR_2025_11_03.xlsx`, zbiór opublikowany
  19 listopada 2025, DOI **10.57745/RDMHWY**
  (entrepot.recherche.data.gouv.fr), strona: ciqual.anses.fr.
- **Licencja:** **Licence Ouverte / Open Licence 2.0 (Etalab)**
  (SPDX `etalab-2.0`) — odczytane z metadanych zbioru w API Dataverse
  26 września 2026. Wolno kopiować, zmieniać i wykorzystywać także
  komercyjnie, pod warunkiem **podania źródła i daty ostatniej
  aktualizacji**. Podajemy je w sekcji „Jak to liczymy” na stronie przepisu
  („CIQUAL 2025 (Anses, Francja, wersja z 3 listopada 2025, Licencja Otwarta
  Etalab 2.0)”) i w tym pliku.

## Źródło 2: USDA FoodData Central, SR Legacy

- **Nazwa:** FoodData Central — SR Legacy (zbiór `FoodData_Central_sr_legacy_food_csv_2018-04`).
- **Wydawca:** U.S. Department of Agriculture, Agricultural Research Service.
- **Licencja:** **domena publiczna, CC0 1.0 Universal**. Zgoda nie jest
  potrzebna; USDA prosi o wskazanie źródła: „U.S. Department of
  Agriculture, Agricultural Research Service. FoodData Central, 2019.
  fdc.nal.usda.gov.” — robimy to w sekcji „Jak to liczymy”.
- **Po co drugie źródło:** CIQUAL nie ma kilku produktów typowych dla
  polskiej kuchni albo nie ma dla nich kompletu wartości. Z USDA bierzemy
  wyłącznie te pozycje (kolumna `zrodlo = usda`, 18 z 218): kapusta kiszona,
  ogórek kiszony, kasza gryczana prażona, kasza jaglana, maślanka, twaróg
  i serek wiejski (patrz „Znane przybliżenia”), kiełbasa, chleb żytni,
  majonez, chrzan, cukier puder, ricotta, mięso mielone wieprzowe, ziele
  angielskie, czosnek granulowany, skórka z cytryny, kapary.

## Czego NIE używamy

- **Polskie „Tabele składu i wartości odżywczej żywności” (IŻŻ / NIZP-PZH)**
  — najlepiej dopasowane do polskiej kuchni, ale chronione; tylko na
  licencji (projekt `docs/research/V2_IMPORT_OCR_ODZYWCZE.md`, P-8).
- **Open Food Facts** (ODbL, share-alike) — produkty z kodami kreskowymi,
  nie surowce.

## Jak powstają liczby w `skladniki.csv`

Człowiek wybiera w pliku tylko `zrodlo` i `zrodlo_id`. Nazwę i wartości
dopisuje skrypt `scripts/odzywcze/uzupelnij_wartosci.py` z pobranych
plików źródłowych (pliki źródłowe NIE leżą w repozytorium — ich adresy są
wyżej). Zasady odczytu:

- CIQUAL: energia wg rozporządzenia UE 1169/2011 (kcal/100 g), a gdy jej
  brak — energia „N × współczynnik Jonesa, z błonnikiem”; białko
  „N × współczynnik Jonesa”, a gdy brak — „N × 6,25”; „Glucides”, „Lipides”;
- „traces” i „< x” zapisujemy jako 0;
- „-” (brak oznaczenia) przy którejkolwiek z czterech wartości — skrypt
  odmawia i pozycję trzeba zamienić na inną;
- USDA SR Legacy: składniki 1008 (Energy, kcal), 1003 (Protein), 1004
  (Total lipid), 1005 (Carbohydrate, by difference).

## Miary domowe i gęstości

Gramatury w `miary.csv` i gęstości w `skladniki.csv` ustaliliśmy sami
z typowych polskich miar kuchennych (szklanka 250 ml, łyżka 15 ml,
łyżeczka 5 ml, szczypta 0,5 g), a tam, gdzie się dało, sprawdziliśmy je
z porcjami USDA (`food_portion`, np. 125 g mąki i 200 g cukru na amerykański
kubek 237 ml — kolumna `uwagi`). To są przybliżenia, nie pomiary, i dlatego
wynik na stronie nazywa się „szacunkiem”.

## Znane przybliżenia (do poprawy, gdy będzie lepsze źródło)

- **Twaróg** — ani CIQUAL, ani USDA nie mają polskiego twarogu. Używamy
  „Cheese, cottage, creamed” (USDA 172179: 98 kcal, B 11 g, T 4,3 g,
  W 3,4 g). Polski twaróg półtłusty ma zwykle więcej białka i energii,
  więc wynik dla serników jest raczej zaniżony.
- **Kiełbasa** — jedna pozycja „Sausage, Polish, pork and beef, smoked”
  (USDA 172954) dla wszystkich kiełbas; biała i podwawelska różnią się od
  niej.
- **Kurczak w całości** — wartości „mięso ze skórą” (CIQUAL 36016), miara
  1 sztuka = 900 g części jadalnej (kurczak ok. 1,5 kg bez kości).
- **Ziele angielskie, goździki, liść laurowy** — pozycje przypraw, masa
  pojedynczej sztuki 0,1–0,2 g; wpływ na wynik pomijalny.
