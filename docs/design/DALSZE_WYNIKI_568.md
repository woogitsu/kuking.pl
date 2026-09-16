# Dalsze wyniki wyszukiwania — #568

Status: **w trakcie odbioru, niewdrożone**. Stan pomiarów: 16 września 2026.
Przygotowana wersja: Alfa 0.42, po oczekującej Alfie 0.41 z PR #591.
Przed publikacją trzeba dołączyć aktualny main i zachować wpis 0.41 w changelogu.

## Problem i zmiana

Po osiągnięciu 200 wyników odnośnik „Pokaż więcej” prowadził ponownie do
tego samego okna. Zachowano stopniowe zwiększanie listy od 20 do 200;
dalsze okna mają osobne przesunięcia przepisów i osób. Zapytania nadal
pobierają najwyżej 201 rekordów na listę, bez dodatkowego COUNT.
Remisy kolejności rozstrzyga identyfikator. Filtry dostępności pozostają
w zapytaniach przed przesunięciem. Widok podaje zakres pokazywanych wyników,
nie deklaruje całkowitej liczby dopasowań. Powrót do początku jest dostępny
również w pustym dalszym oknie.

## Wykonane kontrole lokalne

- PostgreSQL na 127.0.0.1:55439, osobna baza `kuking_568_tests`.
- `DalszeWynikiWyszukiwaniaTest` oraz `SearchTest`: 22 testy, 124 asercje.
  Obejmują 401 przepisów, 201 osób, rzeczywiste odnośniki z HTML,
  niezależność list, powrót, puste dalsze okno, błędne parametry,
  filtr czasu, prywatność i blokady w obu kierunkach. Sprawdzono również
  klikane przejście 180 → 200 → następne okno.
- Szerszy zestaw 12 rodzin wyszukiwania: 83 testy, 324 asercje. Obejmuje
  istniejące regresje trafności, literówek, indeksów, widoczności, układu
  oraz stałej liczby zapytań. Dowód: `evidence/search568/search-suite568.log`.
- Pint: trzy zmienione pliki PHP poprawne.
- PHPStan: zmienione SearchQuery i SearchController bez błędów.
- `npm ci` i build na Vite 8.3.0 zgodnie z lockfile; 72 pary kontrastu.
  Zapis buildu i wyniki przywrócenia źródeł: `evidence/search568/`.
- Fizyczna kontrola ujemna kontrolera w kopii wykonawczej: zastąpienie
  przesunięcia przepisów zerem powoduje porażkę testów (kod 1).
  Kopia poza repo: `/home/mateusz/search568-negative-1789538560927228659.php`.
  Przywrócone MD5 `4a0634d3409166be8ba47ea243befa84` i mtime;
  ponowny przebieg rodziny regresji: 7 testów, 68 asercji, kod 0.
- Osobna fizyczna kontrola ujemna przesunięcia osób: kod 1 po podstawieniu
  zera, przywrócenie tego samego MD5 i mtime, następnie 10 testów / 90 asercji
  z kodem 0. Kopia: `/home/mateusz/search568-negative-1789538783635914034.php`.

## Przeglądarka

Rzeczywista aplikacja Laravel, lokalna baza `kuking_568_browser` z 201
demonstracyjnymi przepisami, bez danych produkcyjnych. Sprawdzono kliknięcie
powrotu z wyniku 201 do pierwszego okna. Pomiar tej dalszej strony:
320, 360, 390, 414, 768 i 1440 px × dwa motywy × tekst 100% i 140%.
W 24 wariantach nie wystąpiło poziome przewijanie dokumentu; faktyczny font
wynosił odpowiednio 18 i 25,2 px. Skala była ustawiana atrybutem dokumentu,
więc ten pomiar nie dowodzi zapisu preferencji przez formularz.

Obejrzano zrzuty 390 px oraz 320 px/ciemny/140% i 1440 px/jasny/100%.
Wynik pojedynczy ma komunikat „Pokazujemy przepis 201.” zamiast zakresu
„201–201”. Zrzuty robocze znajdują się w lokalnym `output/search568-*.png`.

Przy 320 px, tekście 140% i ciemnym motywie wykonano rzeczywiste Tab od
początku dokumentu do odnośnika powrotu oraz Enter. Nawigacja przywróciła
`od_przepisu=0`. Obejrzano `output/search568-focus.png`: po zakończeniu
przejścia CSS pierścień fokusu jest widoczny i niezasłonięty. Pomiar samego
`outline` nie wystarcza dla przycisków używających `box-shadow`.

## Niezależny przegląd

Agent `review568` wykonał odczyt kodu i testów: brak blokerów. Nie uruchamiał
własnych testów ani przeglądarki. Wskazane przejście 180 → 200 zostało następnie
objęte regresją. Dodano również scenariusze obu offsetów jednocześnie
niezerowych oraz blokad osób w dalszym oknie. Paginacja offsetowa nie zapewnia
niezmiennej migawki: dodanie lub usunięcie wyników między żądaniami może
przesunąć pozycje, jak w pozostałych listach tego rodzaju.

## Pozostałe bramki

Pierwszy pełny przebieg PHP: 3864 testy, 4 błędy i 61 porażek. Kopia z
archiwum nie zawierała 13 śledzonych plików (m.in. workflowów), testowa baza
miała strefę Europe/Warsaw zamiast UTC, brakowało lokalnych nazw bucketów.
Po naprawie środowiska 252 testy z wcześniej czerwonych rodzin miały jedną
porażkę: historyczna trasa Livewire wymagała konfiguracji debug. Z
`APP_DEBUG=true` trzy testy dokumentacyjnych tras przeszły (41 asercji).
Nie zmieniano dla tego testów ani kodu aplikacji. Ponowny pełny przebieg
na poprawionym środowisku nie zmienia wyniku pierwszego przebiegu.

Drugi pełny przebieg: 3864 testy, 77164 asercje, 16 porażek dotyczących
kasowania mediów. Helper miał nadmiarowo ustawione fikcyjne endpoint i klucze
AWS, które aktywowały ścieżkę magazynu chmurowego. Po ich usunięciu i
pozostawieniu `AWS_BUCKET=kuking-local-test`, zgodnie z konfiguracją hooka,
73 testy tych rodzin i konfiguracji dysków przeszły (295 asercji).
Trzeci pełny przebieg zakończył się bez porażek: 3864 testy, 77206 asercji, trzy uwagi PHPUnit; czas 5:16.575. Nie zmieniano kodu kasowania mediów. Log: full568-final-env.log w kopii wykonawczej.

Nie zakończono odbioru wszystkich stanów i zrzutów ani całej macierzy klawiatury. Potrzebne są również aktualizacja
wersji/changeloga, pełny hook, push, CI, scalenie oraz odbiór produkcji.
Powyższe wyniki nie stanowią dowodu ukończenia całego portu marki.

### Pomiar zoomu 200% — 16 września

Cztery konfiguracje: szerokości CSS 320 i 768 px, oba motywy, tekst 140%. Rozszerzenie Chromium ustawiło zoom 2 i odczytało go przez API. Brak poziomego overflow; przejście Tab 8/8 w każdym wariancie. Wynik zapisano w zoom568.json. Ogląd zrzutu 320/dark po przejściu Tab ujawnił dużą pustą przestrzeń nad kontrolkami: wymaga odróżnienia usterki układu od artefaktu zrzutu. Ten pomiar nie oznacza pełnego odbioru wizualnego.


Wyjaśnienie zrzutu: w tym samym stanie strony bezpośrednie CDP Page.captureScreenshot z captureBeyondViewport=false nie zawiera pustej przestrzeni, pokazuje przepis 201 i pełny fokus. Artefakt występuje w page.screenshot przy połączeniu zoomu 200% i przewinięcia. Zapisano oba obrazy, skrypt i log geometrii; nie zmieniano CSS aplikacji. Obejrzano wariant 320/dark.

### Puste dalsze okno

Sprawdzono offset 400 przy 201 przepisach: rzeczywisty zoom 200%, tekst 140%, szerokości CSS 320 i 768 px, oba motywy. Cztery przejścia Tab bez naruszeń, brak poziomego overflow. Kliknięcie odnośnika powrotu faktycznie przywróciło od_przepisu=0. Obejrzano zrzut CDP 320/dark: komunikat pustego zakresu i fokus powrotu są widoczne. Dowody: empty568.log, empty568.json i empty568-320-dark.png; skrypt uruchamiany z output kopii wykonawczej.


### Odtworzenie pomiarów i końcowy przegląd

Skrypty zoom568-cdp.mjs i empty568.mjs są kopiami narzędzi z katalogu output kopii wykonawczej. Aby odtworzyć pomiar, skopiuj je do output/ w uruchomionej aplikacji z lokalną bazą demonstracyjną 201 przepisów i serwerem na 127.0.0.1:8068. Uruchom Node z katalogu głównego repo i ustaw CHROMIUM_PATH na lokalne Chromium obsługujące rozszerzenia. Względny import ../scripts/zoom-marki.mjs zakłada właśnie takie położenie. Nie uruchamiaj tych scenariuszy na produkcji.

Końcowy odczyt agenta review568: brak nowych blokerów implementacji; uzupełnione wcześniejsze luki testów. Agent obejrzał zrzut CDP, nie uruchamiał własnych testów. Nadal obowiązuje pełny hook na stanie po aktualizacji main oraz CI i odbiór produkcji.
