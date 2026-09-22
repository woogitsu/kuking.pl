# Stan sesji, część 3 — raporty stanowisk z 20 września 2026 (wieczór)

Ciąg dalszy `STAN_SESJI.md`.

## `gpt/widget-wyglad` — #684

Źródło: `docs/design/WIDGET_WYGLAD_684_POMIAR_I_WARIANT.md`.

Stan zgłoszenia: **potwierdzona usterka; propozycja do decyzji właściciela, bez poprawki produkcyjnej**.

Pomiar własny na czystej gałęzi `gpt/widget-wyglad`, baza kodu
`4c811cc7bff365fb8f86d87eabac93b7738a45cd`. Lokalny runtime
`/home/mateusz/flota/gpt-widget-wyglad-run`, HTTP `127.0.0.1:8684`. PostgreSQL
`127.0.0.1:55439`, użytkownik `kuking`, baza `kuking_flota_gpt-widget-wyglad`.
Pięć testowych osób i pięć jawnie testowych obrazów z istniejącej fixture
`docs/design/evidence/tags370/fixture-kolaz5.php`. Nie dotykano produkcji ani
danych użytkowników, nie zmieniono źródeł aplikacji.

Zgłoszenie odczytano przez `gh issue view 684 --repo woogitsu/kuking.pl`.
Historyczne liczby 8351 px² i 9/9 zasłoniętych punktów odnoszą się do SHA
`55877e2b`, nie do bieżącego pomiaru (zastrzeżenie: to pomiar cudzy).
Podwójna rezerwa stopki była już naprawiana na innej gałęzi (commit
`9a9c3db2`, opis przeczytany, commit nie przeniesiony tutaj — też pomiar cudzy).

### Pomiar własny przed zmianą

Tekst serwisu na maksimum 140%, faktyczny tekst body 25,2 px. Kolaż
przewinięty tak, że jego dół kończy się 8 px nad dołem okna. Podpowiedź
pierwszej wizyty ukryta celowo (izolacja problemu samego przycisku).
Sprawdzono prostokąty, siatkę 3×3 przez `elementFromPoint` oraz faktyczne
kliknięcie i dotknięcie środka piątego kafla. Zoom to `chrome.tabs.setZoom(2)`,
potwierdzony przez DPR=2 i zmniejszenie obszaru CSS do połowy (nie
powiększenie fontu).

| Warunek | Okno CSS | Piąty kafel | Przykrycie szer. × wys. | Pole wspólne | Dostępne punkty | Mysz / dotyk otwierają wpis |
|---|---|---|---|---|---|---|
| Tekst 140%, zoom 100% | 390×740 | 179×86,16 | 128,09×65,06 | 8334,10 px² | 5/9 | nie / nie |
| Zoom 200%, okno początkowe 1440×1480 | 720×740 | 344×168,67 | 181,91×65,06 | 11835,28 px² | 7/9 | tak / tak |
| Szerokość 320, zoom 100% | 320×740 | 144×68,66 | 128,09×64,42 | 8252,04 px² | 0/9 | nie / nie |
| Szerokość 320 CSS i zoom 200% | 320×740 | 144×68,66 | 128,12×64,43 | 8254,55 px² | 0/9 | nie / nie |

Zastrzeżenie granicy pomiaru: zero dostępnych punktów nie oznacza
geometrycznie 100% zasłoniętej powierzchni — wąski fragment kafla pozostaje
odkryty; oznacza, że żaden z dziewięciu punktów próbnych, w tym środek, nie
dociera do kafla. Przy 720 CSS px środek pozostaje dostępny mimo częściowego
przykrycia.

Dowody: surowe liczby (`../../output/playwright/widget684/before.json`),
skrypt pomiaru (`../../output/playwright/widget684/measure.mjs`), cztery
zrzuty przed zmianą (390 px tekst 140%; zoom 200% 720 CSS px; 320 px tekst
140%; zoom 200% 320 CSS px).

### Rezerwa istnieje już dziś

`body.paddingBottom = 326,4 px`, `footer.paddingBottom = 270,4 px`.
`--rezerwa-pod-belka` wynosi 246,4 px przy 140%; body dodaje do niej 80 px,
stopka 24 px. Rezerwa belki występuje więc dwa razy, także u gościa bez
dolnej nawigacji — potwierdza przyczynę opisaną w `9a9c3db2` na bieżącym
stanie kodu. Nie dodano marginesu ani paddingu; rezerwa na końcu dokumentu nie
chroni kafla przewijanego w środku strony.

Zastrzeżenie: w kodzie bieżącej gałęzi podpowiedź ma już obsługę
wheel/pointerdown/touchstart — to odczyt kodu, nie własny pełny odbiór
podpowiedzi. Mechanizm nie usuwa zasłaniania przez sam przycisk.

### Wariant wybrany do przedstawienia: własny dolny pasek

**DECYZJA WŁAŚCICIELA:** odrzucił propozycję przycisku w przepływie z
odnośnikiem w nagłówku i polecił zachować stały dostęp oraz przedstawić
wydzielony pasek.

Proponowany układ: dwa wiersze o łącznej wysokości okna. Górny zawiera
przewijaną stronę; dolny zawiera „Wygląd" i nie nakłada się na górny.
Wysokość dolnego wiersza wynika z treści, nie ze stałej kopii liczby w
paddingu. Natywne `details/summary` pozwala otwierać i zamykać ustawienia bez
JavaScriptu. Zwykły POST zachowuje zapis ustawień bez skryptu.

Własny pomiar prototypu 320×740, tekst 140%: pasek 82,0625 px, obszar treści
657,9375 px, koszt 11,09% wysokości okna. Przycisk 129×65,06 px. Kafel kończy
się na y=650,23, przycisk zaczyna na y=666,94: brak nakładania, 9/9
dostępnych punktów. Faktyczne dotknięcie otworzyło wpis zarówno z JS, jak i
przy `javaScriptEnabled:false`.

Dowody: zrzut propozycji (`.../proposal-320-nojs.png`), wyniki prototypu
(`.../proposal.json`), kod prototypu (`.../proposal.mjs`).

To demonstracja geometrii w DOM przeglądarki, NIE implementacja w Blade. W
prototypie użyto `bypassCSP:true` wyłącznie do wstrzyknięcia próbnego CSS;
polityka aplikacji nie została zmieniona. Wersja wdrażana wymaga zwykłego
arkusza budowanego przez Vite. Podpowiedź w prototypie jest ukryta; w
wariancie docelowym proponuje się przenieść ją do rozwiniętego panelu, aby
nie tworzyć drugiej nakładki.

### Czego stanowisko NIE zrobiło i dlaczego (koszt i ryzyka do rozstrzygnięcia)

- Stały pasek zabiera wysokość na każdym ekranie; przy 200% zoom jego
  fizyczny rozmiar rośnie razem z tekstem. Dla niskich okien konieczny jest
  pomiar panelu otwartego i klawiatury ekranowej — NIE wykonano.
- Strona przewijałaby się w osobnym kontenerze. `resources/js/pasek-przewijany.js`
  czyta `window.scrollY`; widget sam nasłuchuje `window.scroll` i używa
  `window.scrollBy`. Trzeba dostosować te mechanizmy i testy, nie tylko
  dopisać CSS — NIE wykonano.
- Zalogowany użytkownik ma dodatkowo pięć pozycji dolnej nawigacji. Należy
  umieścić ją w tej samej wydzielonej strefie, bez dodawania szóstej pozycji
  i bez drugiej rezerwy. Wysokość tej wersji NIE została jeszcze zmierzona.
- Nagłówek, kotwice, powrót przeglądarką, automatyczne przewijanie do
  błędów, podpowiedzi tagów i PWA wymagają odbioru po zmianie obszaru
  przewijania — NIE wykonano.
- Podpowiedź pierwszej wizyty nie może pozostać osobnym pływającym blokiem;
  jej przeniesienie do panelu zmniejsza widoczność informacji na pierwszej
  wizycie.
- Alternatywa z mniejszą zmianą ramy (osobna boczna kolumna na szerokim
  ekranie) odrzucona jako wspólne rozwiązanie: przy 320 px przycisk o
  szerokości ok. 129 px zostawiłby mniej niż 191 px treści przed odstępami.

**DECYZJA WŁAŚCICIELA (otwarta):** czy wdrożyć dolny pasek z osobnym obszarem
przewijania, akceptując stały koszt wysokości i przeniesienie podpowiedzi do
panelu? Sam wybór „przedstaw wariant" nie został potraktowany jako wybór
tych szczegółów produktu.

### Weryfikacja i granice

- Własny pomiar na nietkniętych źródłach: cztery geometrie, rzeczywisty
  zoom, mysz, dotyk, zrzuty.
- `SzybkiWygladTest`: 6 testów, 29 asercji — zielone przed zmianami.
- `npm run build`: sukces; 20 testów JS i 72 pary kontrastu.
- `vendor/bin/pint --test`: PASS, 1156 plików.
- Nie dodano testu cementującego proponowany układ przed decyzją
  właściciela. Przy wdrażaniu najpierw test regresji w przeglądarce i jego
  czerwień na bazie.
- Nie wykonano pełnego zestawu PHP ani pełnego odbioru dostępności: nie ma
  jeszcze poprawki aplikacji do zatwierdzenia. Nie ogłoszono #684 naprawionym.
- Nie pushowano, nie otwierano PR, nie wysyłano wiadomości ani nie zmieniano
  kanonicznego repozytorium.

### Pułapka przy scalaniu/wdrażaniu

Rollback bieżącej dostawy: usunięcie dokumentacji i dowodów; brak zmian
schematu lub działania aplikacji (czyli nic do wdrożenia jeszcze nie ma —
uwaga na próbę scalenia samej propozycji jako gotowej poprawki).

Commit SHA: w raporcie nie podano SHA commitu(-ów) tego stanowiska —
**BRAK DOWODU** co do numeru commita gałęzi `gpt/widget-wyglad` (podana jest
tylko baza kodu `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, na której
wykonano pomiar).

---

## `gpt/ai-piloty` — #815 #813 #814 (granica danych #912)

Źródło: `docs/research/ai-pilots/RAPORT.md` i `docs/research/ai-pilots/WERYFIKACJA.md`.
Katalog `docs/research/ai-pilots/evidence/` obejrzany: zawiera wyłącznie pliki
JSON dowodowe (`control-budget.json`, `control-bytes.json`,
`control-coverage.json`, `control-fields.json`, `controls-output.txt`,
`input-sizes.json`, `sql-assessment.json`, `sql-baseline.json`,
`sql-summary.json`) — bez dodatkowych plików `.md` z liczbami poza tymi już
przepisanymi z `RAPORT.md`.

Punkt wyjścia: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, gałąź `gpt/ai-piloty`.

### Wynik i zakres decyzji

**Nie ma jeszcze podstaw do wdrożenia AI.** Nie wykonano żadnego żądania do
modelu. Właściwy proces WSL nie ma klucza API; po wyjaśnieniu tego
ograniczenia praca była kontynuowana bez klucza. Wcześniejsze sprawdzenie
jego obecności było błędne wskutek cytowania polecenia powłoki. Uruchomienie
właściwego przyrządu zatrzymało się przed połączeniem, nie zużyło środków.

Jest podstawa do dalszego porównania: w #815 kilka prostych reguł bez AI daje
trafny wynik dla 40/60 pytań obsługiwalnych przez obecne filtry, wobec 31/60
dla tekstu przekazanego bez przekształcenia. To wynik na przygotowanych
danych, nie ocena produkcji ani dowód przewagi nad modelem. Pozostałe 20
pytań nadal nie daje wyników. Nie wdrożono nawet tych reguł — są wariantem
eksperymentu.

Powstały lokalne przyrządy CLI, zbiory syntetyczne, testy HTTP na atrapach i
dowody pomiarów. Nie dodano tras, przycisków, zależności ani migracji. Żaden
szkic nie jest zapisywany do bazy. Zgłoszenia pozostają eksperymentami
nieukończonymi w części wymagającej odpowiedzi modelu i oceny ludzi.

### Werdykt co do trzech granic (na wyraźne żądanie sprawdzenia)

1. **Model nigdy nie dopisuje treści przepisu (#814):** POTWIERDZONE wprost.
   Model w eksperymencie może zwrócić wyłącznie końce kolejnych fragmentów i
   etykietę (składnik, krok, uwaga, do sprawdzenia); nie ma pola na
   wygenerowany tekst, ilość ani liczbę porcji. PHP odtwarza fragmenty z
   oryginału w tej samej kolejności i z pełnym pokryciem; obce pola,
   pominięcia i indeksy poza tekstem są odrzucane. Brak ilości pozostaje
   brakiem. Na 50 tekstach syntetycznych i 15 publicznych ręczna atrapa
   zachowała 65/65 oryginałów; cztery celowo wadliwe propozycje na tekst
   zostały odrzucone: 260/260, w tym 60/60 na tekstach publicznych.
   Zastrzeżenie wprost z raportu: **to test integralności, nie wynik jakości
   AI** — atrapa oznacza cały tekst jako „do sprawdzenia"; nie sprawdzono,
   czy prawdziwy model właściwie dzieli zdania, zachowuje znaczenie negacji i
   alternatyw ani czy jego szkic oszczędza autorowi pracę. Pełne pokrycie
   tekstu nie dowodzi poprawnej interpretacji.
2. **Wynik jest szkicem do zatwierdzenia przez człowieka, nigdy zapisem do
   bazy bez kliknięcia:** POTWIERDZONE wprost. Wynik zawiera oryginał i
   znacznik wymaganego zatwierdzenia; przyrząd nie posiada drogi zapisu
   przepisu. Zabezpieczenie nie obiecuje automatycznego przepisania tekstu do
   istniejącego kreatora z jego autosave (to zastrzeżenie z raportu — droga
   do kreatora NIE jest gotowa/dowiedziona).
3. **W testach nie ma żądań do prawdziwego dostawcy:** POTWIERDZONE wprost i
   zmierzone. Żądania do dostawcy wysłane: **0** (tabela kosztu #912: dla
   wszystkich czterech zbiorów — pytania do wyszukiwarki, pytania o pomoc,
   syntetyczne opisy, publiczne teksty — kolumna „żądania wysłane" = 0 dla
   każdego). Testy pilota: 32 przypadki, 93 asercje na PostgreSQL, HTTP
   wyłącznie na atrapach z zakazem przypadkowych połączeń. Sprawdzono także
   brak zapisu szkicu i odcięcie prywatnych, zablokowanych oraz
   nieopublikowanych przepisów.

### Koszt i jakość (#912)

| Zbiór | Liczba | Bajty kompletnego żądania, min–max | Żądania wysłane |
|---|---:|---:|---:|
| Pytania do wyszukiwarki | 100 | 1434–1484 | 0 |
| Pytania o pomoc | 100 | 1360–1398 | 0 |
| Syntetyczne opisy | 50 | 1723–2020 | 0 |
| Publiczne teksty | 15 | 1723–11646 | 0 |

Rozmiary zmierzono na rzeczywistym serializowanym JSON-ie z instrukcją,
schematem i numeracją tokenów (dowód: `evidence/input-sizes.json`, 265
wejść). Faktyczny koszt dostawcy w tej sesji: **0 USD przy 0 żądaniach**.
**Koszt odpowiedzi, opóźnienie sieci/modelu i zużycie tokenów: NIEZMIERZONE**
(BRAK DOWODU — raport wprost pisze „niezmierzone"). Koszt infrastruktury
lokalnej nie był wyceniany.

Przygotowany klient ma stały model `gpt-5.4-nano-2026-03-17`, stały adres
Responses API, `store: false`, bez narzędzi, zdjęć, profili ani adresów
źródeł. Limit wejścia to 600 znaków pytania lub 4000 znaków opisu oraz
24 000 bajtów całego żądania; przekroczenie zatrzymuje wysyłkę, tekst nie
jest obcinany. Wyjście: maks. 256 tokenów dla pytań, 1600 dla opisu.
Połączenie ma limit 3 s, całe żądanie 10 s; brak przekierowań i ponowień.
Limit odpowiedzi 64 KB sprawdzany po pobraniu, nie jest limitem
strumieniowania.

Trwały, blokowany licznik rezerwuje 0,01 USD na próbę, najwyżej 500 prób
(koperta 5 USD przy wskazanym cenniku i limitach). Nie zwraca rezerwacji po
błędzie ani braku danych rozliczeniowych. Rzeczywisty koszt jest liczony z
`usage`; brak `usage` daje brak kosztu, nie zero. To lokalny hamulec
eksperymentu, nie limit konta u dostawcy ani rozwiązanie produkcyjne.

Cennik modelu odczytany przy przygotowaniu: 0,20 USD / milion tokenów
wejścia, 0,02 USD dla wejścia z cache, 1,25 USD dla wyjścia. Zastrzeżenie z
raportu: przed przyszłym pomiarem trzeba ponownie sprawdzić cennik;
`store: false` nie stanowi obietnicy zerowej retencji u dostawcy.

**Werdykt „warto / nie warto" (dotrzymany werdykt z raportu):** nie da się
rzetelnie orzec „AI warto / nie warto" bez odpowiedzi modelu — brak klucza
API nie blokuje dostarczonych pomiarów, ale nie pozwala ocenić jakości ani
kosztu odpowiedzi.

### #815 — punkt odniesienia bez AI (jakość zmierzona)

Zbiór: 100 syntetycznych pytań — 60 z ręcznie określonym oczekiwanym
wynikiem w istniejących możliwościach, 30 poza tym zakresem, 10 niejasnych.
Baza pomiarowa: 105 przepisów (35 tytułów w trzech wariantach czasu: 20
minut, 60 minut, brak czasu) i profile testowe. Użyto prawdziwego
`SearchQuery`, z autoryzacją i filtrowaniem widoczności. Każde pytanie
uruchomiono raz na wariant; brak rozgrzewki i wielokrotnych powtórzeń
oznacza, że różnic czasowych nie należy interpretować jako trwałego
przyspieszenia.

| Miara | A: oryginalny tekst, wszystko | B: proste reguły |
|---|---:|---:|
| Pytania wykonane | 100 | 100 |
| Pytania obsługiwalne z co najmniej jednym trafnym wynikiem | 31/60 | 40/60 |
| Puste wyniki w tej grupie | 29/60 | 20/60 |
| Średni udział trafnych wyników w niepustych odpowiedziach | 85,81% | 87,00% |
| Mediana czasu lokalnego wyszukiwania | 21,198 ms | 19,142 ms |
| P95 czasu lokalnego wyszukiwania | 34,314 ms | 32,144 ms |
| Zapytania SQL łącznie | 456 | 278 |
| Żądania do dostawcy | 0 | 0 |

Oceniono maksymalnie pięć przepisów i pięć profili, jako osobne listy.
Trafność zdefiniowana w `search-relevance.json`; nie jest oceną ludzi
(zastrzeżenie granicy pomiaru wprost z raportu). Przykładowo etykieta dla
„pyry" uznaje ziemniaki z piekarnika, ale nie placki ziemniaczane. Wynik
zależy od takich jawnych, zawężających wyborów oceniającego — nie wolno
zamienić ich w wymagania produktu bez decyzji właściciela.

Reguły B ustalono przed pomiarem: początkowe „@" wybiera ludzi, dosłowne „do
30 minut" wybiera istniejącą sekcję szybkich przepisów, „pyry" i „kartofle"
zamieniane są na „ziemniaki", „kabaczek" na „cukinia"; domyślnie przepisy. To
nie jest rozumienie języka ani obsługa dowolnego limitu czasu.

Przykłady, które przestają dawać pusty wynik: „pyry", „kabaczek", „naleśniki
do 30 minut", „@basia". Nadal zawodzą w obu wariantach: „szukam osoby Basia"
oraz „szukom modrej kapusty". Zapytania o alergeny nie otrzymują gwarancji
bezpieczeństwa z żadnego z tych wariantów.

Dowody: surowe 200 pomiarów (`evidence/sql-baseline.json`), podsumowanie
(`evidence/sql-summary.json`), ocena każdego pytania
(`evidence/sql-assessment.json`).

### #814 — prawdziwe teksty i zakaz dopisywania (szczegóły źródła)

Źródło wskazane przez właściciela: Odkryj (kuking.pl/odkryj). Pobrano 15
publicznych tekstów z ich stron wpisów, bez zdjęć i danych profili, bez
podążania za linkami do cudzych blogów. Same kopie tekstów pozostają
lokalnie, poza gitem; dowód zawiera adres źródła i SHA-256 tekstu po
usunięciu odnośników. Zbiór nie jest reprezentatywną próbą społeczności.

To **nie jest 15 pełnych przepisów**: 11 tekstów to głównie podpisy, jeden
jest krótką uwagą o zamianie składnika, trzy zawierają rozbudowany opis.
Zakres długości: 13–2051 znaków. „Rolada kawowa" nie wystarcza do
odtworzenia składników. W opisie kaszy manny jest alternatywa „szklanka
cukru(dalam pol szkl. stevii)"; nie wolno rozstrzygać jej za autora. W
długim opisie ciasta świątecznego występuje znak zastępczy Unicode —
zachowano go bez zgadywania.

### #813 — katalog pomocy (co NIE zmierzono)

Przygotowano 100 syntetycznych pytań i katalog 10 intencji. Kod wiąże
intencję z istniejącą trasą i stałym tekstem; model nie może podać własnego
URL-a ani HTML-a. Test sprawdza istnienie tras. **BRAK DOWODU / NIEZMIERZONE
wprost:** trafność klasyfikacji modelu, czas znalezienia funkcji i badanie z
5–8 osobami pozostają niezmierzone.

### Decyzje właściciela (tabela wariantów do rozstrzygnięcia)

| Wariant | Co daje | Koszt / czego jeszcze potrzeba |
|---|---|---|
| Zostać przy obecnych funkcjach | Brak nowego kosztu i wysyłania tekstów | AI pozostaje niewdrożone |
| Rozważyć proste reguły #815 | Zmierzona poprawa na małym zbiorze | Osobna decyzja o zachowaniu wyszukiwarki, szersze dane i testy UX |
| Później uruchomić porównanie modelu | Pomiar trafności, tokenów, czasu i błędów | Dostęp API; maks. 500 prób w lokalnej kopercie 5 USD; ręczna ocena i powtórzenia |
| Kontynuować #814 jako wybór fragmentów | Mechaniczny zakaz dopisywania słów | Ocena sensu podziału, zgoda na taki zakres produktu, projekt jawnego przyjęcia szkicu |
| Kontynuować #813 | Potencjalnie łatwiejsze znalezienie funkcji | Pomiar modelu i badanie 5–8 osób; katalog pomocy sam nie dowodzi użyteczności |

Nie wykonano badania ludzi, porównania dostawców, pomiaru produkcyjnego SQL,
wdrożenia UI ani testu prawdziwego modelu. Nie otwarto PR-a i nie wykonano
push. Brak klucza nie blokuje tych dostarczonych pomiarów, ale bez odpowiedzi
modelu nie da się rzetelnie orzec „AI warto / nie warto".

### Weryfikacja (WERYFIKACJA.md) i pułapki przy scalaniu

Własne uruchomienia na izolowanym runtime `/home/mateusz/flota/gpt-ai-piloty-run`,
z kopii gałęzi `gpt/ai-piloty`. Przed testami uruchomiono wskazany przez
właściciela helper przygotowania. PostgreSQL: `127.0.0.1:55439`, baza
`kuking_flota_gpt-ai-piloty`.

| Kontrola | Wynik |
|---|---|
| Nietknięte drzewo: SearchTest | 12 testów, 34 asercje, PASS |
| Nietknięte drzewo: SzukajWidocznoscTest | 9 testów, 24 asercje, PASS |
| AiPilotContractTest | 30 przypadków, 86 asercji, PASS |
| AiPilotSearchBenchmarkTest | 2 testy, 7 asercji, PASS |
| Cały zestaw z jawnym wyłączeniem poniżej | **4425 testów, 83 785 asercji, PASS**, 412,40 s |
| Kontrole ujemne: pokrycie, pola, bajty, budżet | Każda PASS → oczekiwany FAIL → PASS |
| Formatowanie nowych plików PHP: vendor/bin/pint | 7 plików, wykonano; poprawiono styl w 3 |
| Końcowe vendor/bin/pint --test w całym runtime | 1162 pliki, PASS |

Z całego zestawu wyłączono **ProbaOdtworzeniaTest**, zgodnie z wyraźną
instrukcją właściciela dotyczącą wspólnej bazy `kuking_zrodlo_proby_glowny`.
Nie uruchamiano tego testu i nie przypisuje się mu własnego wyniku. Nie było
innych pominięć ani porażek w wykonanym zestawie.

Polecenie helpera: `testuj.sh gpt-ai-piloty --exclude-filter ProbaOdtworzeniaTest
--compact --log-junit /mnt/c/Users/matma/Documents/kuking-flota/gpt-ai-piloty/storage/ai-pilots/full-suite.xml`.
JUnit potwierdza: tests=4425, assertions=83785, errors=0, failures=0,
skipped=0. Wyłączony filtrem test nie jest liczony jako skipped przez JUnit.
Czas sumowany w XML: 410,608649 s, czas końcowego raportu konsoli: 412,40 s.

**Pułapka przy scalaniu:** pliki `control-*.json` zapisane przez istniejący
pomocnik mają pole `przywrocenie` sprzed jego końcowej pułapki EXIT — nie
należy odczytywać tego pola jako wyniku końcowego przywrócenia; pomocnik
dodatkowo sprawdza MD5 i czas modyfikacji po odtworzeniu.

Surowy JUnit pozostaje lokalnie w `storage/ai-pilots/full-suite.xml`.
SHA-256: `fd952ea5e3e20cb18e1159ee981977c13f76375e473a9359a2dd5001a31a05b0`.

Brak zmian schematu, aplikacji i assetów; nie wykonywano wdrożenia,
produkcyjnej migracji ani pomiaru w produkcyjnej bazie. Żądania do modelu:
zero. Weryfikacja nie rozstrzyga jakości modelu ani użyteczności dla ludzi.

Commit SHA: w obu plikach (`RAPORT.md`, `WERYFIKACJA.md`) nie podano SHA
commitu(-ów) gałęzi `gpt/ai-piloty` — **BRAK DOWODU** co do numeru commita
(podany jest tylko punkt wyjścia `4c811cc7bff365fb8f86d87eabac93b7738a45cd`,
wspólny z bazą kodu).
