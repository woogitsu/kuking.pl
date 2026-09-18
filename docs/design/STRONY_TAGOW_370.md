# Strony tagów — #370

Stan: **scalone i wdrożone**, odbiór produkcji niedomknięty.
PR #669 scalony 18.09.2026 jako `397a742` (Alfa 0.63); produkcja stoi dziś na
`55877e2` (Alfa 0.65), CI main 35331870558 i Deploy 35333641106: success.
Zdanie o „jeszcze niewdrożonej Alfie 0.63” i baza `e22b79d` poniżej to snapshot
sprzed scalenia; zostawiamy go jako zapis przebiegu prac.

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

Review wskazał wcześniejszą lukę fixture: Zupy(5), Barszcz(1) odpowiadały
również kolejności malejącej popularności. Dodano trzeci tag Salatki(8),
aby kolejność gospodarza różniła się od alfabetu i obu sortowań liczby wpisów.
10 testów / 55 asercji PASS. Fizyczny sortByDesc(posts_count) w Blade
wywołał właściwą porażkę href: oczekiwano Zupy, otrzymano Salatki.
Przywrócono MD5/mtime i potwierdzono wynik dodatni. Osobna baza form-tests;
nie zmieniano źródeł działającego pełnego hooka f26f727.


## Granica dowodu produkcji — 18 września 2026

Odbiór wyłącznie odczytowy, GET-y HTTP bez sesji, bez zapisu i bez danych
demonstracyjnych na produkcji. **Odczyt HTML, nie interakcja w przeglądarce.**

Publicznej strony tagu z danymi na produkcji nie ma, bo nie ma **żadnego**
publicznego tagu. Potwierdzone czterema drogami, nie samą listą: `/tagi` → 200
z `Tagi jeszcze się nie pojawiły`; `/szukaj?sekcja=przepisy` → 200 z
`Nie ma jeszcze polecanych tagów.`; pięć prób bezpośrednich adresów strony
pojedynczego tagu (`/tag/{tag}`) → 404; zero odnośników do strony pojedynczego
tagu na stronie głównej, `/odkryj`, trzech stronach wpisów
i trzech profilach publicznych. Szczegóły i cytaty: `STATYSTYKI_TAGOW_369.md`.

Nie są zatem potwierdzone na produkcji: kolaż z prawdziwymi zdjęciami, reguła
jednego zdjęcia od osoby na żywych danych, CTA „Dodaj wpis z tym tagiem” przy
istniejącym tagu ani bogate karty tagów promowanych. Potwierdzony jest wyłącznie
pusty stan obu powierzchni. Nie dodawaliśmy tagów ani wpisów, żeby to obejść.

## Mysz i dotyk na kolażu — pomiar lokalny 18 września 2026

Dotychczasowe dowody osiągalności kolażu były **klawiaturowe** (`actions.mjs`:
Tab, obrys, `elementFromPoint` w środku elementu, Enter). Brakowało wskaźnika,
a to on jest scenariuszem podstawowym. Uzupełniamy ten pomiar.

Runtime: własny klon natywny WSL `kuking-DA-tagi`, serwer `127.0.0.1:8074`,
osobna baza `kuking_d_a_tests` na `127.0.0.1:55439` (kopia fixture kolażu
z odbioru #370), poczta `array`. Bez dotykania produkcji.

48 konfiguracji: 6 szerokości (320/360/390/414/768/1440) × oba motywy ×
tekst 100/140% × dwa sposoby wskazywania (**mysz** i **dotyk**, osobne konteksty
przeglądarki, dotyk z `hasTouch`). Trzy stany strony:

| Stan | Co mierzy | Wynik |
|---|---|---|
| A — stały bywalec, podpowiedź wyglądu zamknięta, kafel przewinięty do środka okna | scenariusz podstawowy | **0 zasłoniętych środków, 0 nieosiągalnych, 0 poziomego przewijania** |
| B — kolaż doprowadzony dolną krawędzią do dolnej krawędzi okna | skrajne przewinięcie | 4 konfiguracje z jednym kaflem nieosiągalnym |
| C — pierwsza wizyta, widoczna podpowiedź „Dopasuj rozmiar tekstu i wygląd strony” | stan powitalny | 4 konfiguracje z jednym kaflem nieosiągalnym |

W stanie A wykonano **80 rzeczywistych interakcji** — realne kliknięcie myszą
i realne dotknięcie każdego z pięciu kafli, przy 320 i 1440 px, obu motywach
i obu skalach. Wszystkie 80 otworzyły dokładnie przypisany wpis (`80/80 OK`).
CTA „Dodaj wpis z tym tagiem” otwiera dla gościa `/login` myszą i dotykiem,
a jego wysokość nie schodzi poniżej 48 px.

Wyniki: `evidence/tags370/pointer-results.json`, skrypt `evidence/tags370/pointer.mjs`.
Obejrzano `pointer/A-light-100-1440.png` (układ prawidłowy: nagłówek, zdanie
`Publicznie: 5 zdjęć od 5 osób.`, CTA, kolaż 5 kafli, podpis), `pointer/B-light-140-320.png`
oraz `pointer/C-dark-140-390.png`. Ilustracje w kolażu są oznaczone jako dane
testowe, nie są zdjęciami użytkowników. To nie jest test na fizycznym telefonie
ani rzeczywisty zoom 200% — te zakresy pokrywają wcześniejsze sekcje tego raportu.

### Dwa zasłonięcia pochodzące ze wspólnego widgetu „Wygląd”

Oba przypadki B i C pochodzą z globalnego widgetu `szybki-wyglad`, nie z kodu
tagów, dlatego **nie ruszamy tu ani CSS, ani JS tego widgetu** — sprawa jest
zgłoszona osobno.

- **B.** Przy 320 px i tekście 140%, gdy kolaż zostanie przewinięty dolną
  krawędzią do dołu okna, pływający przycisk `Wygląd` przykrywa piąty kafel
  w całości: wszystkie dziewięć punktów próbnych trafia w przycisk, pole
  wspólne 8351 px² przy kaflu 144×69 px. Dotyczy obu motywów, myszy i dotyku.
  Doprzewinięcie o 120 px w górę przywraca dostęp we wszystkich przypadkach
  (`Bpo`: 0 nieosiągalnych), więc kafel nie jest trwale utracony.
- **C.** Przy 360 i 390 px oraz tekście 100%, na pierwszej wizycie stała
  podpowiedź `aside.szybki-wyglad-podpowiedz` (szerokość `min(360px, 100vw−24px)`,
  `position: fixed`, `z-index: 26`) przykrywa jeden kafel w całości — pole
  wspólne 25 862 px² przy 336×79 px oraz 27 709 px² przy 366×86 px. Znika po
  „Rozumiem” albo po dowolnej zmianie wyglądu.

Przyczyna jest wspólna i widoczna w źródle: cała logika odsłaniania w
`resources/js/szybki-wyglad.js` wisi na `focusin` (atrybut
`data-wyglad-w-przeplywie`, `window.scrollBy`). Klawiatura ma więc mitygację,
wskaźnik nie ma żadnej. Nie jest to regresja wprowadzona przez #370 — widget
zachowuje się tak nad każdą treścią — ale przy kolażu skutek jest widoczny,
bo kafel bywa jedynym wejściem do wpisu w tym miejscu strony.
