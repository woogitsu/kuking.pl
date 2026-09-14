# Błędny upload i dwa obrazy — lokalny odbiór #492

Stan końcowy, 14.09.2026. Serwer 8033, baza kuking_publikacja492 na porcie 55439, istniejąca sesja. Bez testów PHP, nowych kont i zmian aplikacji. Utworzono dokładnie jeden prywatny wpis: `01a0a196-9771-710e-8732-79da2a8ff877`. Pozostawiono go do dalszych odbiorów.

Błędny plik był tekstem nazwanym `nie-zdjecie.jpg`, MIME image/jpeg. Formularz odrzucił go komunikatem „Ten plik nie wygląda na zdjęcie. Wybierz plik JPG, PNG, WebP albo AVIF.” Odczyt PostgreSQL: 3 wpisy przed i 3 po (`invalid.json`). Następnie opublikowano dwa istniejące lokalne pliki: `fotografia-testowa.webp` oraz `blad-wejscia.png`; liczba wpisów wzrosła do 4 (`created.json`). **Drugi obraz to zrzut wcześniejszego lokalnego ekranu z kontem testowym, nie fotografia potrawy.** Dowód dotyczy dwóch poprawnych, rozróżnialnych obrazów technicznych. Prywatność potwierdzono niezależnie w PostgreSQL.

Publikacja skierowała na `/wpisy/{post}/zdjecia`. Prawdziwym przyciskiem przeniesiono pierwszy obraz w dół, wybrano Karuzela, zapisano i ponownie otwarto formularz. Dwa identyfikatory zamieniły miejsca i zachowały nową kolejność; `carousel` nadal zaznaczone. `order.json` dokumentuje przed/po/ponowne otwarcie. PostgreSQL potwierdził `private/carousel`. Nie wymieniano plików ani nie usuwano obrazów.

Końcowy ogląd istniejącego wpisu odbył się bez kolejnego zapisu lub uploadu: CSS 320 px, tekst 140%, rzeczywisty zoom 200%, oba motywy. `carousel-final.json` i `keyboard.json` zapisują niezależnie `chrome.tabs.getZoom() = 2`, `innerWidth = 320` oraz font main `25.2px`. Użyto rzeczywistego okna 640×1887, `viewport:null`; szerokość dokumentu wyniosła 320 px. Początkowe etapy invalid/two/reopened dokumentuje `results.json`; kontrolny desktop 1440 px z tekstem 140%, oba motywy, dokumentuje `desktop.json`.

Na rzeczywistej stronie wpisu kliknięcie „Następne zdjęcie” zmieniło przesunięcie karuzeli z 0 na 270 px, a „Poprzednie zdjęcie” przywróciło 0, w obu motywach. Obejrzane `carousel-next-light.png` i `carousel-next-dark.png` pokazują drugi obraz, licznik „Zdjęcie 2 z 2” oraz kontrolki końca zestawu. Obejrzane `radio-light-light.png` i `radio-dark-dark.png` pokazują rzeczywiście wybrany tryb Karuzela wraz z opisem, bez zapisywania formularza.

Osobny odczytowy przebieg klawiatury (`keyboard.mjs`, `keyboard.json`) od świeżego wejścia przeszedł rzeczywistymi Tab do linku „Następne zdjęcie” (`:focus-visible = true`), następnie Enter zmienił obraz z pozycji 0 na 270 px w obu motywach. Obejrzano `keyboard-light.png` i `keyboard-dark.png`: po aktywacji widoczny jest drugi obraz i licznik 2 z 2. W tym kadrze dolna nawigacja przykrywa część kontrolek pod licznikiem; wcześniejsze kadry po przewinięciu pokazują je w dostępnej części ekranu. Ten wąski przebieg nie stanowi pełnego oracle niezasłaniania fokusu po aktywacji ani pełnego audytu WCAG.

Obejrzano również `invalid-dark.png` (początek czytelnego komunikatu, dalszy tekst wymaga przewinięcia), `reopened-light.png` (wstęp strony) i `desktop-dark.png` (obrazy i przyciski kolejności). Nie wykonano pełnego Tab wszystkich formularzy, axe ani wszystkich wariantów wyglądu. Nie potwierdzono nowego błędu aplikacji w zbadanym przepływie publikacji i zapisu.

Nie ponawiać `run.mjs`: `created.json` chroni przed drugą publikacją. Końcowe `final.mjs`, `keyboard.mjs` i `desktop.mjs` odczytywały istniejący wpis; zmiany karuzeli były wyłącznie stanem przeglądarki. Przeglądarki zamknięto; media zwolnione dla roota. Serwer pozostawiono bez zmian.

## Dowody w repo

Kod aplikacji odpowiada 8e4da2f2ff51178df57d7dd118978e441b8c5130 (scalony jako443da38). [Dane pomiarowe](evidence/zdjecia492/) zapisano w repo; PNG i reproduktory są lokalnie w output/zdjecia492.
