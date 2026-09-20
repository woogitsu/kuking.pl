# Przycisk rejestracji przy dużym tekście — #621

Status: przygotowana Alfa 0.45; bez potwierdzonego wdrożenia.

## Problem i zakres

Przy szerokości 320 px, foncie Chromium 32 px i tekście aplikacji 140%
przycisk w hero miał 962 px wysokości. Boczne odstępy po 64 px pozostawiały
napisowi tylko 108 px. Fokus nie mieścił się w oknie 900 px.
To osobny wariant od rzeczywistego zoomu 200%.

Reguła w `resources/css/strony-publiczne.css` ogranicza boczny odstęp
dużego przycisku hero do mniejszej wartości: istniejącego tokena albo 5%
szerokości okna. Pełna etykieta zatwierdzona przez właściciela, font,
odnośnik rejestracji i pozostałe przyciski pozostają zachowane.

Rzeczywisty Tab ujawnił też dolną krawędź przycisku na wysokości 900,30 px
przy szerokim oknie i tekście 140%. `scroll-margin-block` z istniejącego
tokena pozostawia zapas na obrys; nie wymusza fokusu ani przewijania JS.

## Dotychczasowe dowody

- 48 konfiguracji: 320/360/390/414/768/1440 px, oba motywy,
  tekst 100/140%, osobno standardowy font Chromium 16/32 px.
- Pierwsza wersja regresji przeszła 48/48 po obu poprawkach, także
  kliknięcia oraz dotknięcia prowadzące do formularza rejestracji.
- Dwie fizyczne kontrole ujemne prawdziwego CSS: cofnięcie odstępów
  odtwarza 962 px; cofnięcie zapasu przewijania odtwarza dolną krawędź
  900,30 px. Obie kończą się `CTA_POZA_EKRANEM`. Kopia poza repo,
  przywrócenie MD5 i mtime oraz dodatni przebieg 48/48 zapisane w
  `evidence/cta621/negatywy.json` i odpowiadających logach.
- Build Vite oraz 72 pary kontrastu przeszły.
- Niezależny przegląd kodu nie znalazł blokera. Wskazane braki asercji
  etykiety i bocznego zapasu fokusu uzupełniono w końcowej wersji testu.
- Końcowa rozszerzona regresja: 48/48 PASS, także pełna etykieta,
  boczny zapas fokusu oraz obecność obrysu lub cienia. Nie jest to osobny
  pomiar liczbowego kontrastu pierścienia.
- Rzeczywisty zoom przez `chrome.tabs.setZoom(2)`, potwierdzony odczytem
  `getZoom` i szerokości okna: 12/12 PASS, efektywne szerokości
  320/360/390/414/768/1440 px, oba motywy i tekst 140%. Obejrzano zrzuty
  najwęższego wariantu w obu motywach: pełna etykieta i pierścień widoczne.
  Wyniki oraz zrzuty są w `evidence/cta621/zoom200*`.

Pierwszy pomiar lokalny korzystał z pełnego HTML i JS aplikacji Laravel `176a46e`
na porcie 8061, zastępując wyłącznie odpowiedź CSS nowym skompilowanym
arkuszem z izolowanej kopii.

Następnie uruchomiono wszystkie źródła poprawki w osobnej kopii
`/home/mateusz/kuking-cta621-runtime` na porcie 8081. Bez podmiany odpowiedzi
CSS ponownie przeszły 48/48 konfiguracji oraz 12/12 rzeczywistego zoomu.
Końcowe pliki `zoom200*` pochodzą z tego przebiegu. Wykorzystano lokalną
bazę przeglądarkową `kuking_561_browser` na `127.0.0.1:55439`, bez migracji,
seedowania ani równoległego pełnego PHP. Nie jest to dowód produkcyjny.

## Do domknięcia

Pełny hook, CI,
zwykłe scalenie po PR #622 i weryfikacja produkcji. Test jest podłączony
do grupy rozszerzeń w `scripts/port-projektu.mjs`.

Nie deklarujemy odsłuchu czytnika ekranu ani testu fizycznego telefonu.
