# #2839 — rzeczywisty odbiór kopii HTML i PDF

Refs #2839. Uzupełnienie technicznego odbioru, bez poprawki aplikacji.
Źródła: zamrożone O `91e58bd07b719c27f12b7973c526f906cbefd2db`.
Gałąź: `codex/2839-odbior-eksportu`.

## Zakres i wcześniejsze dowody

Odczytano aktualne AGENTS, UX, dokument `KOPIA_HTML_WIERSZE_2839.md`,
odbiór P i świeże kryteria issue. Odebrany test HTTP sprawdza rzeczywisty
ZIP, teksty DOM i JSON oraz osadzony CSS. Odebrana mutacja widoku
`pre-line` → `normal` ma właściwy marker `KOPIA_2839_WIERSZE`.
Wcześniejszy Chromium obejmował ekran i emulowany print, bez PDF.
Nie ponawiano tych testów, mutacji źródła ani pełnej baterii.

Ten odbiór obejmuje prawdziwe eksporty własnego fixture, pomiar renderowania
offline, natywne powiększenie Chrome oraz PDF A4. Nie zmienia kodu, Policy,
widoków, schematu, importu, zależności ani rejestrów kontroli.

## Izolacja i powstanie paczek

- Worktree: `C:\Users\matma\.codex\worktrees\2839-odbior-eksportu\Portale`.
- Runtime: `/home/codex-admin/kuking-koordynacja-20261003-codex/repo_2839_odbior_eksportu`.
- Własna baza `kuking_test_2839_odbior_eksportu_20261003`, host `127.0.0.1`,
  port `55488`, rola i właściciel `kuking_pg18_owner`, PostgreSQL 18.6
  (`180006`). Parametry i brak bazy potwierdzono przed jej utworzeniem;
  po utworzeniu ponownie sprawdzono właściciela i rzeczywiste połączenie.
- Osobny fizyczny vendor: 136 pakietów, wszystkie wersje i referencje źródeł
  zgodne z lockiem O. Własne `.env`, klucz, magazyny i katalog tymczasowy
  eksportu; nie używano domyślnego `kuking_race` ani cudzych baz.
- Procesy Git otrzymały kopię środowiska bez `GIT_*`; środowiska sesji,
  konfiguracji globalnej i istniejących runtime nie zmieniano.
- Istniejące Chrome for Testing 153.0.8010.12, Playwright 1.63.0,
  PHP 8.4.26, Node 22.23.2 i Poppler 26.01.0. Bez instalacji przeglądarki
  lub biblioteki. Procesy przeglądarki wykonywano kolejno i zamknięto.

Fixture zapisano w rzeczywistej bazie: jeden własny przepis, trzy kroki,
dwa nazwane etapy, składniki z grupą i uwagą, czasy 5 i 10 minut oraz
własny wygenerowany WebP przy pierwszym kroku. Instrukcje obejmują LF,
CRLF, pusty wiersz, długi akapit i ciąg 210 znaków bez spacji. Historia
obejmuje CRLF i LF oraz puste wiersze. Próby `<script>`, `<img>` i `<b>`
oraz znak `&` pozostają tekstem.

`GenerateUserExport::handle()` zbudował pełną paczkę: stan `ready`,
19 361 bajtów, 6 plików, w tym rzeczywisty obraz kroku. Magazyn jest
rzeczywistym lokalnym dyskiem, bez `Storage::fake`. Powiadomienie pozostaje
w własnej kolejce database; nie wysyłano listu. `KopiaJednegoPrzepisu::zbuduj()`
zbudowała osobny ZIP: 5363 bajty, 3 pliki. Brak zdjęcia w tej drugiej kopii
jest istniejącym kontraktem #2531. To wykonanie domenowych generatorów,
nie ponowny test HTTP i nie ręcznie złożony HTML. Oba archiwa rozpakowano.

W obu `dane.json` porównano dokładne teksty instrukcji i historii z fixture;
bajty UTF-8 tych pól są identyczne. DOM normalizuje CRLF do LF, lecz osobno
JSON zachowuje pierwotne końce wierszy. Nie zmieniano zapisanych treści.

## Ekran offline i natywne 200%

Końcowy `acceptance.cjs`: exit 0, 12 dodatnich etapów dla obu eksportów.
Pliki otwarto przez `file://` przy offline context, bez przechwytywania tras,
podstawiania HTML lub zasobów sieciowych. Liczba żądań HTTP: 0.

| Pomiar obu eksportów | Viewport CSS | DPR | scrollWidth / clientWidth |
|---|---:|---:|---|
| Ekran 100%, szeroki | 1280 | 1 | 1280 / 1280 |
| Ekran 100%, wąski | 320 | 1 | 320 / 320 |
| Natywne 200%, szerokość żądana 1280 | 640 | 2 | 640 / 640 |
| Natywne 200%, szerokość żądana 640 | 320 | 2 | 320 / 320 |
| Natywne 200%, szerokość żądana 320 | 160 | 2 | 160 / 160 |
| Emulowany print przed PDF | 1280 | 1 | 1280 / 1280 |

200% ustanowiono przez rzeczywiste `chrome://settings/appearance`,
kontrolkę `#zoomLevel` ustawioną na `2`, we własnym profilu. Natywny efekt
potwierdzają DPR 2 i zmniejszenie viewportu do połowy. CSS `zoom` pozostaje
`1`, a `visualViewport.scale` pozostaje `1`; nie używano powiększenia
tekstu ani `Emulation.setPageScaleFactor` jako zamiennika browser zoom.
Przy żądaniu okna 320 Chrome raportuje minimum `outerWidth=500`; rzeczywisty
obszar renderowania ma 320 CSS px przy 100% i 160 CSS px przy 200%.
Te wielkości są zapisane oddzielnie, bez utożsamienia obramowania z viewportem.

Mierzono pozycje znaków przez DOM Range: trzy krótkie czynności mają trzy
różne wiersze, a puste wiersze historii i kroku CRLF mają odpowiedni odstęp.
Długi tekst i nieprzerwany ciąg zawijają się; poziome przewijanie nie powstaje.
Osobno zachowane są tytuł, opis, składniki, etapy, numeracja 1–3, czasy
i obraz. Całe listy pozostają przy `white-space: normal`. Zero aktywnych
skryptów, wstrzykniętych obrazów i handlerów; własne zdjęcie jest załadowane.

Pierwsze natywne zrzuty Playwright `fullPage` przycinały sam obraz pomimo
poprawnych metryk. Zachowano je jako `*-diagnostic-playwright-fullpage.png`,
nie jako dowód wizualny. Końcowe obrazy wykonano przez CDP
`Page.captureScreenshot` z rzeczywistego viewportu, bez jego przestawiania
na potrzeby zrzutu. Szerokość PNG musi równać się `innerWidth × DPR`.
Ogląd reprezentatywnego ekranu, instrukcji i historii potwierdza czytelność.

## Nowa kontrola rzeczywistego renderowania

W każdym z dwóch własnych, wygenerowanych HTML fizycznie zmieniono jedyną
kotwicę `white-space: pre-line;` na `white-space: normal;`. Nie zmieniano
widoku ani żadnego źródła aplikacji. Obie kontrole dały:
**PASS → właściwa AssertionError `KOPIA_2839_RENDER_LINIE` → PASS**.
Porażkę wyzwala pomiar pozycji tekstu, nie wyszukanie klasy lub reguły CSS.
Inny wyjątek, timeout albo przeżycie mutanta nie jest akceptowany.

Bajty i nanosekundowy mtime przywrócono w `finally` i sprawdzono:

| HTML | SHA-256 przed i po | mtime_ns przed i po |
|---|---|---|
| Pełna paczka | `de9806c5f6e8945af29018b0aa9681f2802ebe4f76bb178174be8b73310ec6fe` | `1791045241851012880` |
| Jeden przepis | `086192e33c447ed10ebe5135c9acbfa69aba6848cf60dc1188c0c37aeedd8859` | `1791045241851985785` |

## PDF A4 i ogląd każdej strony

Chrome wygenerował rzeczywiste PDF przy print CSS, marginesach 10 mm
i skali wydruku 100%. Następnie Poppler wykonał tekst, bbox i obrazy 110 dpi.
Wszystkie strony są niepuste, tekst mieści się w marginesach, a krótkie
instrukcje i pusty wiersz historii mają potwierdzone osobne współrzędne Y.
To dowód PDF, nie pomiar fizycznej drukarki.

| Plik i strona | Wynik oglądu |
|---|---|
| full-A4.pdf, 1 | Tytuł, opis, metryka i składniki czytelne, bez ucięcia. |
| full-A4.pdf, 2 | Osobne czynności LF, zdjęcie pierwszego kroku, pusty wiersz CRLF i czasy. |
| full-A4.pdf, 3 | Etap i numer 3 zachowane; długi akapit oraz ciąg bez spacji zawijają się. |
| full-A4.pdf, 4 | Historia zachowuje puste akapity; escapowane znaczniki i stopka czytelne. |
| single-A4.pdf, 1 | Tytuł, składniki, etapy, czynności LF/CRLF i czasy czytelne. |
| single-A4.pdf, 2 | Numer 3, długi tekst i nieprzerwany ciąg bez ucięcia. |
| single-A4.pdf, 3 | Puste akapity historii i zgodna z kontraktem stopka kopii bez zdjęć. |

Obejrzano wszystkie 7 stron: bez nakładania, ucięcia lub pustej kartki.
Po końcowym przebiegu PDF ponownie wyrenderowano; wszystkie 7 PNG jest
bajtowo identycznych z obejrzanymi obrazami (`final-pdf-render-equality.json`).

## Surowe dowody i granica odbioru

Remote: `/home/codex-admin/kuking-koordynacja-20261003-codex/transfer/2839-odbior-proof`.
Lokalnie: `C:\Users\matma\AppData\Local\Temp\kuking-koordynacja-20261003\2839-odbior-proof`.
Manifest `SHA256.json`: 99 artefaktów, lokalnie sprawdzone 99/99;
SHA-256 `9916d59ad03e8c368bc95fc70e32e203228d342d778cd3d6a09a33e91a9e0ed8`.
Końcowy przyrząd `acceptance.cjs`: SHA-256
`4323b171ff62436184737978708c2a5f2630f44203c6feed6c0fe3caf7e2ce4a`.
Profile Chrome i duży bundle bazowy są poza manifestem dowodów.

W katalogu są oba ZIP-y, rozpakowany HTML/JSON/obraz, fixture, logi i exit,
`browser-results.json`, `physical-render-controls.json`, natywne zrzuty,
dwa PDF, wszystkie PNG stron, bbox, teksty, `visual-review.json`, parametry
bazy, zależności oraz końcowe SHA źródeł. Historyczne błędy przygotowania
fixture i pomiaru pozostają oddzielnie; nie są właściwymi kontrolami ujemnymi.
W szczególności pierwszy błędny fixture nie wygenerował eksportu pomimo
zerowego kodu wyjścia handlera konsolowego; zaliczenie wymaga gotowych paczek
i osobnych kontroli danych, nie samego exit.

Osiem mierzonych plików aplikacji i locków jest bajtowo zgodnych z O91;
runtime pozostał czysty, bez aktywnego własnego Chrome. Commit dodaje wyłącznie
ten dokument. Lokalny techniczny odbiór #2839 jest kompletny dla opisanych
próbek i Chrome 153. Nie wykonano produkcji, fizycznej drukarki, pełnego hooka,
CI ani pusha/PR; zamknięcie issue i bramki wydania pozostają u koordynatora.
