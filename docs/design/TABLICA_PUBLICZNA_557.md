# Publiczna tablica z dużymi zdjęciami — #557, Alfa 0.32

## Problem i zakres

Właściciel pokazał stronę dla gościa: dwie nierówne listy, małe zdjęcia
dań, dużo pustej przestrzeni i powtarzane zaproszenie do rejestracji przy
każdej osobie. To nie był tylko problem koloru ani nieaktualnego cache.
Komponent nadal renderował historyczny układ z #365.

Wariant strony powitalnej zaczyna się teraz od dużych kart dań. Dalej są
wizytówki osób i jedno wspólne zaproszenie do obserwowania po rejestracji.
Kolejność jest w HTML. Grid dopasowuje liczbę kart do miejsca i skali tekstu;
nie dodaje pustych kart. Zdjęcia korzystają z gotowego wariantu feed,
zamiast powiększać mały thumb. Decyzja D-215 rozstrzyga zmianę względem #365.

Fragmenty people/posts wydzielono do podwidoków. Poza landing zachowano
kolejność, miniatury, formularze obserwowania i CSRF. Nadal obowiązują filtry
widoczności, gotowość mediów, fotografia i tytuł przepisu jako fallback,
notatki redakcyjne oraz stopka wyjaśniająca brak rankingu. Treści użytkowników
nie są zmieniane. Dłuższy podgląd prowadzi do pełnego wpisu. Pojedynczy link
na nazwie autora zachowuje rozszerzenie kliknięcia na kartę przez pseudo-element.

## Regresje i pomiar

- PublicznaTablicaKompozycjaTest: dwa testy, 23 asercje. Rzeczywisty GET
  landing i odkrywania, kolejność obu wariantów, feed/thumb, pojedyncze
  zaproszenie przy dwóch osobach, link wpisu, stopka, prywatny wpis i pusty stan.
- Dotychczasowe rodziny DailyBoardTest, SzynaTablicaDniaUkladTest oraz
  DwieKolumnyTamGdzieSieMieszczaTest: 37 testów, 135 asercji. Zmieniono
  historyczne objaśnienie ostatniego testu, zachowując jego asercje.
- Skrypt scripts/tablica-publiczna.mjs wymaga lokalnej strony z dokładnie
  trzema publicznymi wpisami ze zdjęciami oraz osobami. BASE_URL wskazuje
  stronę, CHROMIUM_PATH przeglądarkę; OUTPUT_DIR katalog wyników. HEIGHT
  ustawia wysokość CSS, QUICK ogranicza szerokość do1440 i zoom do100%.
  Pełna macierz to320/360/390/414/768/1440, oba motywy, tekst100/140%,
  rzeczywisty zoom100/200% ustawiany i odczytywany przez chrome.tabs.
- Każdy wariant sprawdza kolejność, brak poziomego przewijania, trzy duże
  załadowane obrazy, trzy rzeczywiste przejścia Tab do linków dań, widoczny
  fokus i trafienie jego środka oraz trzy kliknięcia zdjęć z odczytem URL.
- Przy wysokości1400 i tekście140% odtworzono fokus przy dolnej krawędzi
  okna. Lokalny scroll-margin zostawia miejsce na obrys. Kontrola jego
  usunięcia wymaga wysokości1400; przy900 ta mutacja nie powodowała błędu.
- Pięć kontroli ujemnych modyfikuje prawdziwe Blade/CSS: kolejność list,
  wariant zdjęcia, powtórzone CTA, usunięta kompozycja i odstęp fokusu.
  Kopie są poza repo; po każdej zmianie przywracane są bajty i mtime,
  sprawdzany MD5 oraz powtarzany pomiar dodatni. Wyniki końcowe w evidence.

## Uczciwe granice dowodów

### Dodatkowy błąd wykryty przez CI

Pierwszy head PR #558 (`8e957963678fd415a0cf177c9c1e81c8c1266800`),
CI34907625262: PHP3814 testów /76612 asercji przeszło, ale istniejący
pomiar kompozycji zatrzymał wydanie. Przy CSS320, bazowej czcionce32px
i tekście140% szerokość dokumentu wynosiła419px. Lokalnie odtworzono
dokładnie419px: nagłówek „Poznaj ich kuchnie” miał379px, ponieważ jako
element flex nie mógł zejść poniżej szerokości słowa.

Podtytuł publicznej tablicy ma teraz min-width:0 i overflow-wrap:anywhere.
Tekst nie jest zmniejszany ani ukrywany. Skrypt tablica-publiczna-font.mjs
sprawdza12 wariantów: sześć szerokości i oba motywy, font bazowy32px,
tekst140%. Wszystkie przechodzą. Obejrzano zrzut320/jasny; duże słowa
zawijają się i wymagają przewijania pionowego. To nadal nie jest zoom.

Szósta fizyczna kontrola ujemna usuwa obie deklaracje prawdziwego CSS:
powraca419px i kod1. Kopia poza repo, przywrócenie MD5/mtime, build
i ponowny wynik12/12. [Wyniki](evidence/landing557/font.json),
[kontrola ujemna](evidence/landing557/font-negatyw.json).
Pierwsze pięć negatywów opisuje poprzedzający tę poprawkę stan CSS;
nowy dowód zawiera hash arkusza z poprawionym nagłówkiem. Nie zmieniono
progów ani scenariuszy istniejącego testu CI.

Przebieg wysokości CSS900, przed dodatkową poprawką fontu z CI, zakończył się 48/48: 144 odwiedzone
linki dań przez Tab i144 kliknięcia fotografii. Wszystkie pięć ówczesnych
negatywów: kod1, przywrócenie MD5/mtime, potem kod0. Obejrzano końcowe
zrzuty320/zoom200/ciemny/tekst140,1440/zoom100/ciemny/tekst100 oraz
1440/zoom200/jasny/tekst140; dodatkowo wizytówki320/ciemny i1440/jasny.
Dowody: [pomiar](evidence/landing557/wyniki.json),
[negatywy](evidence/landing557/negatywy.json),
[lokalne dania](evidence/landing557/dania-lokalne.png) i
[lokalne wizytówki](evidence/landing557/osoby-lokalne.png).

Pierwszy skrypt tracił ustawienia motywu i skali po powrocie z klikniętego
wpisu. Jego zrzuty nie były dowodem odbioru obu motywów. Końcowy pomiar
przywraca ustawienia po nawigacji, czeka na fonty i obrazy oraz zapisuje
faktyczny motyw, skalę, rozmiar tekstu i kolor tła. Nie sumujemy kolejnych
uruchomień jako liczby różnych scenariuszy.

Fixture jest wyłącznie lokalna: trzy jawnie testowe kuchnie i powtórzone
zdjęcie demonstracyjne. Nie jest dowodem zróżnicowania produkcyjnych treści.
Nie tworzono kont ani wpisów na produkcji. PostgreSQL55439, baza odbioru
kuking_publikacja492; osobna baza pełnych testów kuking_final_20260913.
Brak odbioru fizycznego telefonu, czytnika ekranu i wszystkich kombinacji
notatek oraz brakujących mediów. Ich reguły sprawdzają testy istniejącej
tablicy, co nie zastępuje osobnego oglądu.

Niezależne review Blade/CSS i testów nie wykazało blokera; było odczytem
kodu, bez uruchamiania testów przez recenzenta. Na końcowych źródłach po
poprawce fontu ponowiono również cztery konfiguracje kompozycji (QUICK).

## Dostarczenie kodu

PR #558: końcowy head `55ba96f77c89c6d1f635661256c712f0d0cf9491`,
CI34908849825: wszystkie10 zadań success, PHP3814 testów /76612 asercji
odczytane z logu104191632718. Zwykły push z obowiązkowym hookiem przeszedł.
Merge `181b93f6f1f06c437b4bd93413a5bed23c136960` zawiera Alfa0.32.
Wdrożenie i odbiór produkcji potwierdzono osobno poniżej.
Pełny port marki nadal
**CZĘŚCIOWO**.

## Odbiór produkcji — 15 września 2026

Kod `181b93f6f1f06c437b4bd93413a5bed23c136960` działa jako Alfa0.32. MainCI34910661018:
10/10 success; PHP3814/76612 z rzeczywistego logu104197225378.
Railway6448719034: success (2026-09-15T00:21:29Z); Deploy34912846146: success.
[Odczyt wersji, CSS, JS i obu plików Inter](evidence/landing557/produkcja-http.txt)
oraz [statusy wdrożenia](evidence/landing557/wdrozenie.json) są odrębne od CI.

Czysta niezalogowana przeglądarka Chromium: szerokości390 i1440, oba motywy,
zwykły tekst i zoom100%. Cztery konfiguracje mają załadowane duże zdjęcia,
kolejność dania → osoby, jedno zaproszenie oraz brak poziomego przewijania.
Wykonano12 rzeczywistych kliknięć zdjęć i sprawdzono adresy docelowych wpisów lub powiązanego przepisu.
Nie tworzono ani nie zmieniano danych. [Pomiar produkcji](evidence/landing557/produkcja.json).
Obejrzano zrzuty obu układów; przykłady:
[dania desktop](evidence/landing557/produkcja-dania-1440-light.png),
[osoby desktop](evidence/landing557/produkcja-osoby-1440-light.png),
[dania mobile, ciemny](evidence/landing557/produkcja-dania-390-dark.png).
To odbiór tej sekcji, nie całej strony powitalnej, fizycznych urządzeń ani
produkcyjnego zoomu200%. Szersza macierz pozostaje dowodem lokalnym.

Pierwszy odbiornik oczekiwał zawsze końcowego adresu `/wpisy/`, więc zatrzymał
się na poprawnym przekierowaniu do przepisu. PostController::show zachowuje
tę istniejącą ścieżkę. Odbiornik odczytuje teraz rzeczywisty cel HTTP linku,
a następnie wymaga tego samego adresu po kliknięciu; zapisuje obydwa adresy
w `visits`. Ponowiony pełny odbiór4/4 przeszedł. Nie był to błąd aplikacji.
