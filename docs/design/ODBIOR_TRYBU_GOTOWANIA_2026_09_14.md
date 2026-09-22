# Lokalny odbiór trybu gotowania — 14.09.2026

Kod: `24afa9e4046da31143430fd930883908ca9f87a7`. Zakres wyłącznie `/przepisy/{recipe}/gotuj`; bez ponownego audytu innych ekranów i bez zmian implementacji.

## Wynik

Nie potwierdzono nowego błędu w sprawdzonym zakresie. Przejścia między krokami, podstawowa obsługa klawiaturą oraz odhaczanie i przywracanie postępu działają. Macierz 48 konfiguracji nie wykazała poziomego przewijania, utraty tekstu ani przycisków niższych niż 48 px. Obejrzano rzeczywiste zrzuty, w tym obie wersje kolorystyczne na małym i dużym ekranie oraz prawdziwy zoom 200%.

## Środowisko i dane

Lokalny serwer `http://localhost:8029`, PostgreSQL `kuking_audit429` na `127.0.0.1:55439`. Użyto istniejącej lokalnej sesji autora bez ujawniania jej zawartości. Brak publicznej lokalnej receptury spełniającej potrzebę kilku kroków; utworzono więc wyłącznie prywatny lokalny fixture:

- tytuł: Lokalny odbiór trybu gotowania 20260914;
- slug: `lokalny-odbior-gotowania-20260914-review429`;
- stan published, widoczność private;
- trzy instrukcje długości 56, 4000 i 45 znaków;
- bez zdjęć, minutników, składników i zewnętrznych działań.

Fixture utworzono bezpośrednio w lokalnej bazie wyłącznie do odbioru widoku. To nie jest dowód walidacji tworzenia przepisu. Wstępna długość środkowego tekstu 4001 została poprawiona do 4000 przed pierwszym otwarciem w przeglądarce. Nie zmieniono istniejących przepisów ani mediów. Fixture pozostaje w lokalnej bazie do ponowienia odbioru. Oznaczenie zrobionego kroku cofnięto po sprawdzeniu.

## Wykonane przejścia

`transitions.json` dokumentuje:

1. Początek: krok 1 z 3.
2. Kliknięcie „Następny krok”: krok 2, pełne 4000 znaków.
3. Kliknięcie „Poprzedni krok”: krok 1.
4. Dziewięć kolejnych naciśnięć Tab i Enter na „Następny krok”: krok 2.
5. Następny: krok 3, brak kolejnego linku „Następny krok”, obecne „Ugotowałem” (nie wysyłano wykonania).
6. Tab i Enter na „Poprzedni krok”: krok 2.
7. Rzeczywisty POST przyciskiem „Oznacz krok jako zrobiony”, odświeżenie strony i wyjście przez „Zakończ gotowanie” z powrotem do kroku: oznaczenie zostaje.
8. Cofnięcie oznaczenia przyciskiem przywraca stan początkowy.

Dodatkowo przy ciemnym motywie, tekście 140% i rzeczywistym zoomie 200% wykonano Tab/Enter do następnego kroku. Aktywny link miał 296×72 CSS px, jego środek był rzeczywistym celem trafienia (bez przykrycia przez stałą nawigację), a Enter otworzył krok 2. Dowód: `keyboard-zoom.json` i `keyboard-next-320-dark-text140-zoom2.png`.

## Macierz układu

Szerokości CSS: 320, 360, 390, 414, 768, 1440; motywy light/dark; tekst 100/140%; zoom przeglądarki 100/200% — razem 48 pomiarów. Każdy dotyczy rzeczywistego środkowego kroku z 4000 znaków. Sprawdzono szerokość dokumentu, rzeczywistą szerokość okna, długość i końcówkę tekstu, rozmiary wszystkich przycisków artykułu. Zero błędów JavaScript.

Zoom wykonano przez rozszerzenie Chromium i `chrome.tabs.setZoom`, sprawdzając `getZoom`, `innerWidth` i `devicePixelRatio`. Nie użyto CSS zoom ani transform. Przy zoomie 200% fizyczne okno miało podwojoną szerokość, tak aby docelowa szerokość układu CSS nadal wynosiła wskazane 320–1440 px (jak w lokalnym wzorcu zoom528). To nie jest pomiar okna fizycznie 320 px po zmniejszeniu obszaru CSS do 160 px.

Tekst powiększono istniejącym ustawieniem `data-text-scale=140`, bez zmiany treści instrukcji w DOM. Motywy przełączano istniejącym `data-theme`. W pomiarach font instrukcji rzeczywiście reaguje na skalę; dane są w `matrix.json`.

## Ogląd obrazów

Obejrzane reprezentatywne pliki w lokalnym `output/playwright/cooking-audit/`:

- `initial-390.png`: pierwszy, krótki krok w jasnym motywie;
- `long-bottom-320-dark-text140-zoom1.png`: koniec instrukcji i oba przyciski nawigacji;
- `long-top-1440-light-text140-zoom1.png`: duży tekst na szerokim ekranie;
- `long-top-320-light-text140-zoom2.png`: zawijanie długiej instrukcji przy prawdziwym zoomie;
- `long-bottom-320-dark-text140-zoom2.png` i `long-bottom-1440-dark-text140-zoom2.png`: przyciski po długim kroku;
- `keyboard-next-320-dark-text140-zoom2.png`: link dostępny klawiaturą.

Stały nagłówek i dolna nawigacja zabierają znaczną część małego ekranu przy powiększeniu. Treść wymaga pionowego przewijania; nie stwierdzono trwałego zasłonięcia, utraty tekstu ani niemożności kliknięcia nawigacji. Samo pojawienie się stałej nawigacji nad tekstem na zrzucie pełnej strony nie oznacza utraty treści — zrzut składa treść przewijaną z elementem przyklejonym do aktualnego okna.

Pierwsze zwykłe zrzuty Playwright przy zoomie 200% dawały pusty artefakt mimo obecnego DOM. Zostały zastąpione rzeczywistymi zrzutami CDP `Page.captureScreenshot` z `captureBeyondViewport:false`, według sprawdzonego wzorca zoom528. Nie zakwalifikowano tej usterki narzędzia jako błędu aplikacji.

## Granice

Nie przeprowadzono testu na fizycznym telefonie, blokowania ekranu / Wake Lock, dźwięku minutnika, czytnika ekranu ani wysłania „Ugotowałem”. Fixture nie zawiera zdjęć, składników ani minutnika. Test klawiatury dotyczy Tab/Enter, nie niezaimplementowanych skrótów strzałkowych. Macierz układu nie stanowi pełnego audytu WCAG ani pomiaru kontrastu. Nie uruchamiano zestawu PHP i nie zmieniano kodu.

Źródła przeczytane przed odbiorem: `CookingModeController`, routing `cooking.show/cooking.zaznacz`, `pages/recipes/cooking.blade.php`, istniejący `CookingModeTest` oraz reguły CSS `cook-*`. Nawigacja to GET; odhaczanie to POST z CSRF i stanem w sesji. Istniejących testów PHP nie uruchamiano.

Reproduktor lokalny: `output/cooking-audit.mjs`. Dowody: `matrix.json`, `transitions.json`, `keyboard-zoom.json`, `snapshot-*.txt`, PNG w tym katalogu.
