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

Końcowy przebieg wysokości CSS900 zakończył się 48/48: 144 odwiedzone
linki dań przez Tab i144 kliknięcia fotografii. Wszystkie pięć końcowych
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
kodu, bez uruchamiania testów przez recenzenta. CI, scalenie i faktyczny
commit Railway wymagają osobnego potwierdzenia. Pełny port marki nadal
**CZĘŚCIOWO**.
