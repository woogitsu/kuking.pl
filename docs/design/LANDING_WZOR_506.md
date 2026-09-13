# Publiczna strona według wizualizacji — Alfa 0.17

13 września 2026, issue #506, D-208. Baza kodu:
`94a7b436180d64480bb46feca9bc150af8ca116c`.
Stan: poprawka przygotowana do przekazania na prośbę właściciela z powodu
kończącego się limitu sesji. Nie jest to potwierdzenie wdrożenia.

## Źródło i różnice

Właściciel wskazał [fragment wizualizacji](references/landing-wzor-506.png)
z otwartymi trzema kolumnami oraz ciemnym blokiem bezpośrednio pod nimi.
Produkcja miała białe kafle, mały tytuł „Jak działa” i tablicę pomiędzy
krokami a blokiem „Ugotowałem”. Wspólna paleta nie oznaczała zgodności
kompozycji. Widok `pages/landing.blade.php` potwierdził tę różnicę.

Poprawka: duże nagłówki, kolumny 01–03 z rzeczywistymi linkami, ciemny
zaokrąglony blok z publicznym zdjęciem i podpisem, a następnie istniejąca
tablica i wpisy. Na małym ekranie kolumny układają się pionowo. Nie
przenosimy ucięcia trzeciej kolumny z przesłanego kadru do aplikacji.

Fotografia pochodzi z pierwszego elementu `HeroKolaz::doKolazu()` —
z dotychczasowym filtrem publiczności, aktywnego autora i stanu `ready`.
Podpis oznacza autora zdjęcia, nie wykonanie przepisu. Gdy kolekcja jest
pusta (również przy mniej niż czterech dopuszczonych zdjęciach), zostaje
blok tekstowy. Nie ma stocku, nowego konta ani fikcyjnego powiadomienia.

## Regresje

- PHP: **41 testów / 196 asercji** razem z istniejącymi regresjami kolażu.
  Kolejność sekcji, trzy akcje, puste zdjęcie, prawdziwe `HeroPick`, autorstwo,
  wycofanie po zmianie wpisu na prywatny i medium na `pending`.
- Trzy kontrole ujemne prawdziwego Blade: błędny link, `thumb` zamiast
  `feed`, fikcyjny autor. Każda wykryta; kopia poza repo, MD5 i mtime
  przywrócone, ponowne 41/196 poprawne.
- `scripts/port-projektu.mjs`: **224 warianty poprawne**, w tym 36 nowych
  pomiarów strony publicznej: 320, 360, 390, 414, 768 i 1440 px, oba motywy,
  tekst 100%, 140% i emulacja podwojenia bazowej czcionki.
  Pomiar obejmuje otwarte kolumny, wielkość nagłówka, ciemny blok
  i obecność obrazu. Trzy kontrole ujemne prawdziwego CSS wykryły każdy
  sabotaż; odtworzono MD5 `9dcf3b7a9d32e8bc8e3d8c4cce68646f`.
  MD5 przywróconego Blade: `80dd0e4ffb455911c61c2dbfc5562475`.
  Obejrzano lokalne zrzuty nowych sekcji; obraz logo w danych skanera
  jest atrapą testową, więc nie potwierdza wyglądu zdjęcia produkcyjnego.

## Odbiór i ograniczenia

Niezależny audyt: rzeczywiste dojście klawiszem Tab od początku strony
na 320×900 px, tekst 100% i 140%. Wszystkie trzy linki kroków oraz CTA
mają widoczny, niezasłonięty fokus (8/8). Przy 140% między nagłówkiem
a aktywnym linkiem pozostaje ponad 198 px. Zrzut całego długiego elementu
może zawierać nałożony nagłówek w miejscu przewinięcia; nie jest to dowód
zasłonięcia aktywnego celu. Obejrzano także zrzuty rzeczywistego Tab.

Pełny niezależny `dostepnosc.mjs` został przerwany przy przekazaniu:
część axe bez zgłoszeń, pomiary układu dotarły do 414 px / 140%.
Nie jest to pełny wynik pozytywny. Log lokalny: `output/landing506-a11y.log`.
Wcześniejszy przebieg ze starym buildem nie jest dowodem odbioru.
CI i Railway wymagają dalszego sprawdzenia po przekazaniu.
Podwojenie bazowej czcionki nie jest rzeczywistym zoomem przeglądarki.
Nie utożsamiamy tej poprawki z pełnym odbiorem wszystkich stanów portalu.
