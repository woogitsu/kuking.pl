# Odbiór #369 — statystyki publiczne tagów

Źródło: Windows `kuking-369`, HEAD `27df93119051e76c03986bd14f270c691837720e` plus WIP z `source-hashes.json` (SHA256 manifestu `89f5ec7f235e5a0a3a91c8167d73acac0f169f3ee4ea07760e931dd09ec87f99`). To odbiór lokalny, nie potwierdzenie wdrożenia.

Własny runtime `/home/mateusz/kuking-369-browser`, własny fizyczny vendor z przegenerowanym autoload, własna baza `kuking_369_browser`, owner kuking, 127.0.0.1:55439, UTC. HTTP http://127.0.0.1:8070, sesja procesu 51744. MAIL=array, SESSION=file, QUEUE=sync; trwały klucz tylko w prywatnym .env. Migracje i build wykonano wyłącznie w tym środowisku. Nie użyto produkcji ani bazy testowej drugiego agenta. Przeglądarka blokowała zewnętrzne originy.

## Wynik wykonany

- 48 układów: oba rzeczywiste motywy aplikacji, tekst 140%, szerokości 320/360/390/414/768/1440, /tagi oraz tagi 5 zdjęć/3 osoby, 4/3 i 5/2. Zero poziomego overflow; właściwy komunikat publiczny wyłącznie dla 5/3. Poniżej progu strona taga pokazuje zaproszenie, katalog zachowuje licznik wpisów bez małych liczników zdjęć/osób.
- 4 kompletne scenariusze klawiatury (320/1440 × oba motywy): Tab do polecanego taga, focus-visible i outline 3px, Enter otwiera właściwy tag; rzeczywisty klik „Pokaż więcej tagów” otwiera page=2. Dowód `ui-results.json.keyboard`.
- 6 przypadków rzeczywistego zoomu 200% przez chrome.tabs.setZoom/getZoom, nie deviceScaleFactor: oba motywy × katalog/próg/podprogiem. getZoom=2, innerWidth=320, DPR=2, tekst nadal 140%. Dodatkowe 2 zrzuty przewiniętych polecanych tagów z tego samego zapisanego profilu i ustawień: `zoom-promoted-results.json`.
- Obejrzano 14 reprezentatywnych zrzutów głównej macierzy, wszystkie 6 głównych zrzutów zoomu, 2 przewinięte polecane w zoomie, 2 przywrócone polecane bez zoomu oraz zrzut negatywny. Nie zastępowano oglądu samym pomiarem scrollWidth.

## Znalezisko wizualne — poprawione i odebrane

Przy 320 px i tekście 140% wysoki `.tag-directory-link` dziedziczył promień pill. Obrys elipsy przechodził przez początek nazwy i dolny wiersz „osób.” — tekst mieścił się w prostokącie elementu, lecz nie w jego zaokrąglonym obrysie. Dowód historyczny: `restored-light-320-promoted.png`. Root poprawił promień na `var(--radius-md)`. Po synchronizacji dokładnego CSS i rebuild wykonano ponownie 48 pomiarów geometrii (nie powtarzano wszystkich akcji) oraz 2 rzeczywiste zrzuty zoomu 200% w obu motywach. `outline-final.json`: zero overflow i zero środków znaków poza zaokrąglonym obrysem. Obejrzano `outline-final-light.png`, `outline-final-dark.png` i oba aktualne `zoom-*-promoted.png`: obrys nie przecina tekstu. Końcowy CSS SHA256 `9100f295200444d74374ef0165f5439ac4650b0be89389557b91339a8eba4b68`, identyczny bajtowo w Windows i runtime. Brak pozostałego blokera tego odbioru.

## Fizyczny negatyw

`negative.py` wykonał pozytyw, następnie zmienił rzeczywisty plik Blade własnego runtime: pierwszy warunek komponentu wymuszony na false. Nie była to manipulacja DOM ani ukrycie CSS. `negative.mjs` wykrył `PUBLIC_STATS_MISSING`, exit 1. W finally przywrócono kopię poza repo i wyczyszczono wyłącznie skompilowane widoki własnego runtime. MD5 i mtime_ns przed/po są identyczne (`physical-negative.json`); przywrócony pozytyw przeszedł w obu motywach. Windows źródła nie były mutowane.

Drugi negatyw `outline-negative.py` fizycznie przywrócił radius-pill w CSS własnego runtime i przebudował assets. Przeglądarka wykryła 4 środki znaków poza rzeczywistym zaokrąglonym obrysem (`TEXT_OUTSIDE_ROUNDED_BORDER`, exit 1), mimo braku poziomego overflow. Zrzut `outline-negative-light.png` obejrzano. CSS przywrócono z kopii poza repo: MD5 `1511d218cd5d30e8538fd0943732263a`, mtime_ns `1789687707143003310` identyczne przed/po. Po rebuild pozytyw 48 przypadków przeszedł. Pierwsze uruchomienie pomocnika zatrzymało się na błędnej asercji motywu jasnego (brak data-theme oznacza light); poprawiono wyłącznie pomocnik i wykonano negatyw ponownie. Restore w finally zadziałał także przy tej pierwszej próbie.

## Historia przygotowania i ograniczenia

`ui-failure.json` jest starszym artefaktem: pierwsze 24 układy przeszły, następnie klik paginacji nie znalazł linku, ponieważ fixture miała 63 tagi przy domyślnym page_size=100. Dodano 50 lokalnych tagów (113 razem), bez zmiany konfiguracji. Następny pełny przebieg zakończył wszystkie 48 układów i 4 scenariusze działań. Nie jest to pozostawiony błąd aplikacji.

Pierwsza dodatkowa próba zrzutu przewiniętego zoomu nie uzyskała oczekiwanego ustawienia wyglądu w nowym profilu (timeout, przyczyna nieustalona). Zamiast ponawiać zapisy ustawień użyto istniejących profili ukończonego odbioru zoomu; zachowany rzeczywisty motyw, skala 140% i getZoom=2 zostały ponownie zmierzone. Żadnego resetowania limitów.

Fixture ma syntetycznych autorów, publiczne wpisy i techniczne jednobarwne zdjęcia. Odbiór nie ocenia jakości zdjęć ani całego feedu. Nie wykonano pełnej suity PHP ani pełnego audytu WCAG.

## Niezależny przegląd implementacji

Przeczytano TagPublicStats, TagController, konfigurację progów, oba widoki, komponent i CSS oraz użyte zakresy Post/Recipe. Nie stwierdzono blokera funkcjonalnego lub prywatności w tej implementacji. Statystyki używają publicznych opublikowanych wpisów aktywnych autorów, publicznego powiązanego przepisu, statusu READY mediów i ACTIVE taga; zakres modelu zachowuje SoftDeletes. COUNT DISTINCT zapobiega mnożeniu zdjęć/autorów. Union promowanych i bieżącej strony jest deduplikowany po ID przed jednym zapytaniem agregującym. Oba progi muszą być spełnione przed wyświetleniem obu liczb. To publiczny agregat niezależny od widza, oznaczony „Publicznie”, a nie lista osób. Dotychczasowy porządek alfabetyczny/redakcyjny i paginacja pozostały.

`final-source-comparison.json`: domena, kontroler, komponent, show i testy identyczne bajtowo z końcowym WIP; config/index różnią się wyłącznie końcami linii. Końcowy CSS po poprawce obrysu jest identyczny bajtowo. Nie przypisano temu odbiorowi wykonania testów domeny przez drugiego agenta ani Pint/PHPStan wykonanych przez root.
