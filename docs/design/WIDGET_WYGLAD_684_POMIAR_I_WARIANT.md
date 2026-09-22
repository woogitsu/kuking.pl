# Widget „Wygląd” — pomiar #684 i wariant wydzielonego paska

20 września 2026. Stan: **potwierdzona usterka; propozycja do decyzji właściciela, bez poprawki produkcyjnej**.

## Zakres i źródło

Pomiar własny na czystej gałęzi `gpt/widget-wyglad`, baza kodu `4c811cc7bff365fb8f86d87eabac93b7738a45cd`.
Lokalny runtime `/home/mateusz/flota/gpt-widget-wyglad-run`, HTTP `127.0.0.1:8684`.
PostgreSQL `127.0.0.1:55439`, użytkownik `kuking`, własna baza `kuking_flota_gpt-widget-wyglad`.
Pięć testowych osób i pięć jawnie testowych obrazów z istniejącej fixture `docs/design/evidence/tags370/fixture-kolaz5.php`.
Nie dotykano produkcji ani danych użytkowników. Nie zmieniono źródeł aplikacji.

Zgłoszenie odczytano przez `gh issue view 684 --repo woogitsu/kuking.pl`.
[pomiar cudzy: treść #684] Historyczne 8351 px² i 9/9 zasłoniętych punktów odnoszą się do SHA 55877e2b, nie do bieżącego pomiaru.
[pomiar cudzy: opis commita 9a9c3db2] Podwójna rezerwa stopki była już naprawiana na innej gałęzi. Commit przeczytano; nie przeniesiono go tutaj.

## Pomiar własny przed zmianą

Wszystkie liczby w CSS px. Tekst serwisu na maksimum 140%, faktyczny tekst body 25,2 px.
Kolaż przewinięty tak, że jego dół kończy się 8 px nad dołem okna. Podpowiedź pierwszej wizyty ukryta: izolujemy problem SAMEGO przycisku.
Sprawdzono prostokąty, siatkę 3×3 przez `elementFromPoint` oraz faktyczne kliknięcie i dotknięcie środka piątego kafla.
Zoom to `chrome.tabs.setZoom(2)`, potwierdzony przez DPR=2 i zmniejszenie obszaru CSS do połowy, nie powiększenie fontu.

| Warunek | Okno CSS | Piąty kafel | Przykrycie szer. × wys. | Pole wspólne | Dostępne punkty | Mysz / dotyk otwierają wpis |
|---|---|---|---|---|---|---|
| Tekst 140%, zoom 100% | 390×740 | 179×86,16 | 128,09×65,06 | 8334,10 px² | 5/9 | nie / nie |
| Zoom 200%, okno początkowe 1440×1480 | 720×740 | 344×168,67 | 181,91×65,06 | 11835,28 px² | 7/9 | tak / tak |
| Szerokość 320, zoom 100% | 320×740 | 144×68,66 | 128,09×64,42 | 8252,04 px² | 0/9 | nie / nie |
| Szerokość 320 CSS i zoom 200% | 320×740 | 144×68,66 | 128,12×64,43 | 8254,55 px² | 0/9 | nie / nie |

Zero dostępnych punktów nie oznacza geometrycznie 100% zasłoniętej powierzchni: wąski fragment kafla pozostaje odkryty. Oznacza, że żaden z dziewięciu punktów próbnych, w tym środek, nie dociera do kafla.
W przypadku 720 CSS px środek pozostaje dostępny mimo częściowego przykrycia.

Dowody: surowe liczby (`../../output/playwright/widget684/before.json` — dowód przeglądarkowy, pominięty przy odzysku dokumentów na decyzję właściciela), skrypt pomiaru (`../../output/playwright/widget684/measure.mjs` — dowód przeglądarkowy, pominięty przy odzysku dokumentów na decyzję właściciela).

### Zrzuty przed zmianą

[obraz: 390 px, tekst 140%] (`../../output/playwright/widget684/before-tekst140.png` — dowód przeglądarkowy, pominięty przy odzysku dokumentów na decyzję właściciela)

[obraz: Zoom 200%, 720 CSS px] (`../../output/playwright/widget684/before-zoom200.png` — dowód przeglądarkowy, pominięty przy odzysku dokumentów na decyzję właściciela)

[obraz: 320 px, tekst 140%] (`../../output/playwright/widget684/before-320.png` — dowód przeglądarkowy, pominięty przy odzysku dokumentów na decyzję właściciela)

[obraz: Zoom 200%, 320 CSS px] (`../../output/playwright/widget684/before-320-zoom200.png` — dowód przeglądarkowy, pominięty przy odzysku dokumentów na decyzję właściciela)

## Rezerwa istnieje już dziś

Odczyt kaskady na badanych stronach: `body.paddingBottom = 326,4 px`, `footer.paddingBottom = 270,4 px`.
`--rezerwa-pod-belka` wynosi 246,4 px przy 140%; body dodaje do niej 80 px, stopka 24 px.
Rezerwa belki występuje więc dwa razy, także u gościa bez dolnej nawigacji. To potwierdza przyczynę opisaną w 9a9c3db2 na naszym stanie kodu.
Nie dodano marginesu ani paddingu. Rezerwa na końcu dokumentu nie chroni kafla przewijanego w środku strony.

W kodzie bieżącej gałęzi podpowiedź ma już obsługę wheel/pointerdown/touchstart; to odczyt kodu, nie własny pełny odbiór podpowiedzi. Mechanizm nie usuwa zasłaniania przez sam przycisk.

## Wariant wybrany do przedstawienia: własny dolny pasek

Właściciel odrzucił propozycję przycisku w przepływie z odnośnikiem w nagłówku i polecił zachować stały dostęp oraz przedstawić wydzielony pasek.

Proponowany układ: dwa wiersze o łącznej wysokości okna. Górny zawiera przewijaną stronę; dolny zawiera „Wygląd” i nie nakłada się na górny. Wysokość dolnego wiersza wynika z treści, nie ze stałej kopii liczby w paddingu. Natywne `details/summary` pozwala otwierać i zamykać ustawienia bez JavaScriptu. Zwykły POST zachowuje zapis ustawień bez skryptu.

**Własny pomiar prototypu 320×740, tekst 140%:** pasek 82,0625 px, obszar treści 657,9375 px, koszt 11,09% wysokości okna. Przycisk 129×65,06 px. Kafel kończy się na y=650,23, przycisk zaczyna na y=666,94: brak nakładania, 9/9 dostępnych punktów. Faktyczne dotknięcie otworzyło wpis zarówno z JS, jak i przy `javaScriptEnabled:false`.

[obraz: Propozycja — wydzielony pasek bez JS] (`../../output/playwright/widget684/proposal-320-nojs.png` — dowód przeglądarkowy, pominięty przy odzysku dokumentów na decyzję właściciela)

Wyniki prototypu (`../../output/playwright/widget684/proposal.json` — dowód przeglądarkowy, pominięty przy odzysku dokumentów na decyzję właściciela) · Kod prototypu (`../../output/playwright/widget684/proposal.mjs` — dowód przeglądarkowy, pominięty przy odzysku dokumentów na decyzję właściciela)

To demonstracja geometrii w DOM przeglądarki, NIE implementacja w Blade. W prototypie użyto `bypassCSP:true` wyłącznie do wstrzyknięcia próbnego CSS; polityka aplikacji nie została zmieniona. Wersja wdrażana wymaga zwykłego arkusza budowanego przez Vite.
Podpowiedź w prototypie jest ukryta. W wariancie docelowym proponujemy przenieść ją do rozwiniętego panelu, aby nie tworzyć drugiej nakładki.

### Koszt i ryzyka do rozstrzygnięcia

- Stały pasek zabiera wysokość na każdym ekranie; przy 200% zoom jego fizyczny rozmiar rośnie razem z tekstem. Dla niskich okien konieczny jest pomiar panelu otwartego i klawiatury ekranowej.
- Strona przewijałaby się w osobnym kontenerze. `resources/js/pasek-przewijany.js` czyta `window.scrollY`; widget sam nasłuchuje `window.scroll` i używa `window.scrollBy`. Trzeba dostosować te mechanizmy i testy, nie tylko dopisać CSS.
- Zalogowany użytkownik ma dodatkowo pięć pozycji dolnej nawigacji. Należy umieścić ją w tej samej wydzielonej strefie, bez dodawania szóstej pozycji i bez drugiej rezerwy. Wysokość tej wersji NIE została jeszcze zmierzona.
- Nagłówek, kotwice, powrót przeglądarką, automatyczne przewijanie do błędów, podpowiedzi tagów i PWA wymagają odbioru po zmianie obszaru przewijania.
- Podpowiedź pierwszej wizyty nie może pozostać osobnym pływającym blokiem. Jej przeniesienie do panelu zmniejsza widoczność informacji na pierwszej wizycie.

Alternatywa z mniejszą zmianą ramy: osobna boczna kolumna na szerokim ekranie. Przy 320 px przycisk o szerokości około 129 px zostawiłby mniej niż 191 px treści przed odstępami, więc nie rekomendujemy jej jako wspólnego rozwiązania.

**Decyzja właściciela:** czy wdrożyć dolny pasek z osobnym obszarem przewijania, akceptując stały koszt wysokości i przeniesienie podpowiedzi do panelu? Sam wybór „przedstaw wariant” nie został potraktowany jako wybór tych szczegółów produktu.

## Weryfikacja i granice

- Własny pomiar na nietkniętych źródłach: cztery geometrie, rzeczywisty zoom, mysz, dotyk, zrzuty.
- `SzybkiWygladTest`: 6 testów, 29 asercji — zielone przed zmianami.
- `npm run build`: sukces; 20 testów JS i 72 pary kontrastu.
- `vendor/bin/pint --test`: PASS, 1156 plików.
- Nie dodano testu cementującego proponowany układ przed decyzją właściciela. Przy wdrażaniu najpierw test regresji w przeglądarce i jego czerwień na bazie.
- Nie wykonano pełnego zestawu PHP ani pełnego odbioru dostępności: nie ma jeszcze poprawki aplikacji do zatwierdzenia. Nie ogłaszamy #684 naprawionym.
- Nie pushowano, nie otwierano PR, nie wysyłano wiadomości ani nie zmieniano kanonicznego repozytorium.
- Rollback bieżącej dostawy: usunięcie dokumentacji i dowodów; brak zmian schematu lub działania aplikacji.