# Strony tagów — #370

Stan: implementacja i lokalne pomiary przeglądarkowe; końcowe review i CI w toku przygotowania.
Robocza wersja: Alfa 0.63 — jeszcze niewdrożona.
Baza: `e22b79de7d1d37f449cf2e69b204421ad2226457` (statystyki #369).

## Zachowanie

Strona tagu zachowuje chronologiczną listę wpisów i istniejące obserwowanie.
Nagłówek dodaje zaproszenie lub notatkę gospodarza, kolaż oraz odnośnik do
formularza z wybranym tagiem. Promowane tagi otrzymują karty ze zdjęciami;
pozostały spis pozostaje alfabetyczną listą. Nie powstaje nowy typ treści ani ranking.

`TagCollage` wybiera najwyżej pięć gotowych publicznych zdjęć, po jednym od
autora. Filtry publikacji, aktywności autora, powiązanego przepisu i blokad
widza działają przed selekcją. Remisy mają pełny porządek po identyfikatorach.
Jedno zapytanie okienkowe wybiera rekordy wielu tagów, a relacje są ładowane
zbiorczo. Zdjęcia nadal przechodzą przez `Media::url()` i kontrolę dostępu.

Formularz przyjmuje istniejący aktywny tag z parametru `tag`; tag scalony
rozwiązuje do aktywnego celu. Nie tworzy tagów na podstawie adresu. Stare
wejście formularza ma pierwszeństwo, także gdy użytkownik usunął ostatni tag.

## Dowody lokalne

- Formularz i dotychczasowa obsługa tagów: 25 testów / 100 asercji PASS.
  Cztery fizyczne mutacje kontrolera wykryte, po każdej przywrócone MD5 i mtime,
  końcowy wynik dodatni. Testy obejmują rzeczywiste logowanie, usunięcie tagu,
  walidację i publikację przez istniejący formularz.
- Domena kolażu: 9 testów / 262 asercje PASS, Pint i PHPStan PASS.
  Trzy fizyczne negatywy publiczności, deduplikacji autorów i blokad wykryte;
  MD5/mtime przywrócone, końcowy wynik dodatni.
- Integracja stron oraz powiązane rodziny: 62 testy / 612 asercji PASS.
  Obejmuje render 30 promowanych kart, odnośniki do wpisów i formularza,
  pusty stan, brak zagnieżdżonych odnośników oraz escapowanie notatki.
  Jest to test HTTP/HTML, nie ogląd kompozycji w przeglądarce.
- PHPStan domeny tagów i obu kontrolerów: bez błędów kodu. Konfiguracja
  ograniczonego przebiegu wyłącza zgłaszanie nieużytych wykluczeń baseline,
  ponieważ standardowy baseline obejmuje również testy spoza tego zakresu.

## Pomiar dużego zbioru

Izolowana baza `kuking_370_collage_tests`, port 55439, UTC, poczta `array`.
Transakcja: 10 000 wpisów, 30 000 zdjęć, 1000 autorów, 30 tagów; dodatkowo
nieprzypięte rekordy wzorcowe fabryk. Po `ANALYZE`, cztery próbki:

| Zestaw | Czas | Liczba zapytań |
|---|---:|---:|
| 2 tagi | 11,4–15,7 ms | 5 |
| 30 tagów | 58,7–62,5 ms | 5 |

Sprawdzono po pięć różnych autorów na każdy tag. EXPLAIN zwrócił 150 wierszy
dla 30 tagów; końcowe sortowanie około 59 ms. Pomiar obejmuje selekcję i
hydratację domeny, bez renderu strony, pobierania zdjęć i ruchu równoległego.
Nie stanowi gwarancji czasu na produkcji. Dane wycofano; osobny odczyt po
pomiarze potwierdził zero wpisów, mediów i tagów w tej bazie.

Surowe lokalne dowody: `output/collage370/` w worktree #370 oraz
`kuking.pl/output/370-form-tests/`. Trwały wybór dowodów należy dołączyć po
odbiorze końcowych źródeł; katalogi `output` nie są publikacją w repo.

## Do ukończenia

Pierwszy ogląd indeksu ujawnił rozpychanie siatki przez wewnętrzne wymiary
zdjęć: kolaże trzech i pięciu zdjęć nie zachowywały proporcji 4:3, a karty
sąsiednie rozciągały się do ich wysokości. Obrazy pozycjonujemy wewnątrz
komórek, aby rozmiar wyznaczał kontener; karty wyrównujemy do początku.
Pomiar końcowego CSS: 168 konfiguracji PASS (indeks i stany 0–5 zdjęć,
320/360/390/414/768/1440 px, oba motywy, tekst 100/140%). Sprawdzono
ładowanie zdjęć, proporcje 4:3, brak overflow i cele linków minimum 48 px.
Fizyczny negatyw position: static wykryty; kopia poza repo, MD5/mtime
przywrócone, dodatni pomiar PASS. Dowody: output/browser370/ui-results.json
i negative-css.json. Obejrzano jasny indeks desktop i 320 px / 140% / 5 zdjęć.
Ilustracje lokalne oznaczono jako dane testowe; nie są zdjęciami użytkowników.
Pozostaje kontrola fokusu przy nakładaniu przycisku Wygląd na część kolażu.

Do dostarczenia: końcowe zebranie dowodów, zwykły hook, wymagane CI, merge
oraz odbiór produkcji. Nie wykonano testu na fizycznym telefonie.
Pełna marka nadal ma status **CZĘŚCIOWO**.

## Dodatkowy odbiór przeglądarkowy

- Prawdziwy zoom Chromium 200% przez chrome.tabs.setZoom, tekst aplikacji 140%:
  14 scen PASS (oba motywy, indeks i 0–5 zdjęć). Odczytano getZoom=2,
  devicePixelRatio=2 oraz innerWidth=320 przy oknie 640; bez poziomego overflow.
- Klawiatura: 4 sceny (320/1440, oba motywy, tekst 140%), łącznie 20 linków
  zdjęć osiągniętych Tab. Obrys widoczny, środek celu niezasłonięty, cały
  prostokąt w oknie. Enter otworzył przypisany wpis z treścią i zdjęciem.
- Pierwsza wersja pomiaru wymagała h1 na stronie wpisu, którego ten widok
  nie używa. Poprawiono test na rzeczywistą treść i zdjęcie docelowego wpisu;
  nie zmieniano aplikacji w celu spełnienia błędnej asercji.
- Dowody lokalne: output/browser370/zoom-results.json i actions-results.json.
  Nie stanowi to testu fizycznego telefonu ani czytnika ekranu.

Wybrane dowody geometrii, zoomu, klawiatury i negatywu CSS zapisano
w `docs/design/evidence/tags370/`. Zrzuty zawierają oznaczone ilustracje testowe.

## Końcowe review i negatyw Blade

Niezależny przegląd kodu i dokumentacji: brak znalezionych blokerów.
Nie był to dodatkowy przebieg testów ani ogląd zrzutów przez recenzenta.
Usunięcie parametru tag z rzeczywistego Blade wykrył pomiar przeglądarkowy.
MD5/mtime przywrócone; po wyczyszczeniu skompilowanych widoków wynik dodatni PASS.
Gość po kliknięciu trafia do /login. W pierwszej wersji harness błędnie
oczekiwał /logowanie; poprawiono oczekiwanie według routes/web.php.

## Korekta regresji historycznego spisu

Pełny hook wykrył jedną porażkę SpisTematowTest: selektor .chip nie obejmował
nowych kart promowanych. Test sprawdza teraz osobno kolejność href, nazwę
i licznik na każdej karcie. Bezpośredni PHPUnit: 10 testów / 52 asercje PASS.
Fizyczne odwrócenie foreach w Blade wykryte, MD5/mtime przywrócone, wynik
dodatni PASS. Artefakty negative-order.json i targeted.py w katalogu dowodów.
