# Kompozycje marki — issue #509

Źródło: oryginalny ZIP zachowany przy audycie #508, widoki `logowanie`,
`rejestracja`, `przepis`, `profil` oraz sekcja `home-ownership` na Start.
Aktualne decyzje zachowują garnek, Inter, prawdziwe dane i działające formularze.

## Zakres

| Ekran | Poprawka | Zachowane zachowanie |
|---|---|---|
| Logowanie / rejestracja | Zaproszenie obok karty formularza | Hasło, link e-mail, dostępni dostawcy, zaproszenia, CSRF, błędy |
| Przepis | Tekst i zdjęcie obok siebie, akcje poniżej | Podgląd zdjęcia, zapis, gotowanie, obserwowanie, moderacja i widoczność |
| Profil własny / cudzy | Awatar 170 px, liczby pod nagłówkiem | Jedna kopia prawdziwych liczb; filtrowanie i akcje według odbiorcy |
| Strona publiczna | Trzy karty własności | Zeszyty, wybór widoczności, uczciwa informacja o eksporcie |

## Odbiór lokalny

Pełny zestaw PHP lokalnie: **3703 testy, 74761 asercji**, sukces (252,70 s).
Larastan: sukces. Pint po poprawieniu formatowania dwóch testów: 992 pliki,
sukces. Wykonanie: natywna kopia WSL, izolowana baza PostgreSQL
`kuking_final_20260913`, port 55439; nie baza produkcyjna.

Pełny `scripts/port-projektu.mjs` zakończył się lokalnie `PORT_OK`, exit 0:
nowe pomiary kompozycji i zoomu przeszły w jednym wykonaniu wraz z wcześniejszymi
pomiarami oraz kontrolami ujemnymi ramy, tablicy i publicznej strony.
Skrypt przygotował oddzielną bazę `kuking_port509` i sam zamknął swój serwer.

Pełny pomiar kompozycji przeszedł **432 warianty i osiem rzeczywistych
kontroli ujemnych CSS** z odtworzeniem MD5. Po niezależnym przeglądzie
negatywy wymagają konkretnych kodów oraz jawnego wariantu także w pomiarze
przed mutacją i po przywróceniu. Obejmują kolumny wejścia, własności i hero,
awatar, szerokość profilu, jeden rząd pięciu liczników, akcje przepisu oraz
przepełnienie logo przy 390 px, podwojonym foncie i tekście 140%.
Ten zapis nie potwierdza wdrożenia Alfa 0.18.

Rzeczywisty zoom wykrył dodatkowo obcięcie górnej i lewej krawędzi fokusu
zakładki „Wszystko” przez przewijalną ramę `.tabs`. Potwierdzono je zrzutem
renderu, nie tylko hit-testem. Wspólna reguła `.tabs .tab:focus-visible`
rysuje teraz obrys 3 px wewnątrz celu (`outline-offset: -3px`), zachowując
rozmiar kontrolki i awaryjne przewijanie. Osobna mutacja przywracająca
zewnętrzny offset została wykryta jako `ZOOM_FOCUS_OCCLUDED`; po odtworzeniu
źródła przejście profilu przeszło w obu motywach.

Kontrole ujemne PHP: cztery rzeczywiste zmiany widoków (klasa karty wejścia,
klasa kart własności, usunięcie opisu z hero oraz liczników z profilu).
Każda wywołała oczekiwaną asercję DOM, nie błąd 500 ani kompilacji Blade.
Kopie znajdowały się poza repo; po `cp -p` sprawdzono MD5 i czas modyfikacji,
wyczyszczono cache Blade i ponowiono poprawne testy.

| Źródło | MD5 po przywróceniu | Test po przywróceniu |
|---|---|---|
| `components/marka-wejscie.blade.php` | `7b1535da8b44164849f7fc109433ec7c` | 2 / 33 |
| `pages/landing.blade.php` | `e5f6a1038cb68919d1f5d543b9d122ff` | 2 / 33 |
| `pages/recipes/show.blade.php` | `6aaff6cb3bf33fb57a581eb81b851ec3` | 2 / 19 |
| `pages/profile/show.blade.php` | `b70f7ab19d379cd0baca07e4c84d6ebe` | 3 / 47 |

Ścieżki w tabeli są względem `resources/views/`; ostatnia kolumna oznacza
liczbę testów / asercji wybranego zestawu, nie pełnej aplikacji.

`scripts/kompozycje-marki.mjs` jest uruchamiany przez istniejący pomiar portu:
6 szerokości, 2 motywy, skale 100/140 i emulacja podwojonej czcionki
przeglądarki, także w połączeniu z tekstem aplikacji 140%. Ostatni wariant
nie jest rzeczywistym zoomem strony 200%. Osobny `scripts/zoom-marki.mjs`
stosuje `chrome.tabs.setZoom(2)` i sprawdza odpowiedź API, DPR oraz połowę
szerokości obszaru strony. Przeszło **48 konfiguracji rzeczywistego zoomu**.
Kompletne przejścia Tab wykonano w obu motywach przy oknie fizycznym 640 px,
zoomie 200% (320 px CSS) i tekście aplikacji 140%: logowanie 7/7,
rejestracja 10/10, strona publiczna 50/50, przepis 11/11, własny profil
28/28, cudzy profil 13/13. Zamknięte menu nie włącza swoich ukrytych
potwierdzeń usunięcia do bieżącej sekwencji; nie jest to odbiór otwartych menu.

Pomiar rozpoznaje obrys i pierścień `box-shadow`, zakończenie przejścia CSS,
fragmenty wielowierszowych linków, kontrast, clipping i zasłonięcie. Przy
niejednoznacznym trafieniu w przezroczyste rodzeństwo sprawdza rzeczywisty
piksel pierścienia ze zrzutu Chromium. Sześć negatywów przeszło: obcięte
zakładki, overflow, przezroczysty obrys, obrys koloru tła, przezroczysty
przodek i zasłaniający nagłówek. Po każdym przywrócono MD5/mtime i ponowiono
poprawny pomiar obu motywów. MD5 `marka-rama.css` po przywróceniu:
`3cc986e4ab178df40358ce93060d8c4e`.
Kontrole ujemne zmieniają prawdziwe arkusze, odbudowują Vite i sprawdzają
odtworzenie MD5 oraz ponowny poprawny pomiar. Zrzuty danych demonstracyjnych
trafiają do artefaktu CI, nie zastępują oglądu produkcji.

Pełny audyt marki nadal obejmuje ograniczenia z AUDYT_PACZKI_MARKI_508.md.
Odkrywanie i zeszyty wymagają osobnego porównania gęstości na rzeczywistych
treściach. Nie zastępujemy strumienia statyczną siatką z fikcyjnymi przepisami.
Porównanie źródeł wskazało kolejny konkretny zakres:
[kompozycja zeszytów i siatka przepisów kolekcji, #511](https://github.com/woogitsu/kuking.pl/issues/511).
Rozdzielenie Odkrywania i Szukaj oraz pełne karty zapisanych wpisów zachowują
rzeczywiste funkcje. Symulowane kategorie, liczniki i przekrojowe filtry
prototypu nie stają się wymaganiem dodania fikcyjnych kontrolek.

Niezależny przegląd źródła `d0cf7b9 → 8d4bb05` nie znalazł blokujących
regresji w akcjach, filtrach prywatności, licznikach ani formularzach.
To odrębny dowód od wykonania testów i oglądu zrzutów. Bramka skryptu
`kafel-dodawania-bramka.test.mjs` została również uruchomiona lokalnie:
sukces, sześć kategorii i kontrole ujemne skryptu.

## Uzupełnienie lokalnego odbioru PR #512

Na kodzie `ec93196` Lighthouse zakończył się sukcesem dla 8/8 stron:
wynik wydajności 92–99, SEO 100 poza logowaniem z celowym `noindex`.
Pełny skrypt dostępności wykonał axe dla 44 ekranów bez naruszeń oraz
49 pomiarów układu bez przepełnień. Cały skrypt zakończył się jednak
**exit 1**: cztery historyczne asercje wymagały liczb profilu w prawej
szynie przy 1280 i 1512 px, wbrew nowej kompozycji D-210. Nie jest to
zielony wynik całego skryptu. Kontrolę zaktualizowano do pojedynczego
pasa pod nagłówkiem, z zachowaniem wykrywania braków i duplikacji.
Wąska regresja `scripts/regresja-liczb-profilu.mjs` wykonała te same funkcje
pomiaru: 9/9 konfiguracji (360/1280/1512 px, gość i dwa zalogowane profile)
oraz trzy rzeczywiste mutacje widoku: przeniesienie pasa do nagłówka,
usunięcie i podwojenie. Każda została wykryta właściwym kodem błędu;
po każdym przywróceniu ponownie przeszło 9/9. Kopia poza repo i `cp -p`
odtworzyły MD5 `b70f7ab19d379cd0baca07e4c84d6ebe` oraz czas modyfikacji.
Ten pomiar położenia jest jawnie w motywie jasnym; oba motywy sprawdzają
osobno pomiary kompozycji i zoomu opisane powyżej.

Po aktualizacji wykonano ponownie **cały** `scripts/dostepnosc.mjs`:
exit 0, axe 44/44 i układ 49/49, zero naruszeń oraz zero rozjazdów liczb
profilu. To lokalny odbiór poprawionego skryptu; ponowny CI i wdrożenie
pozostają osobnymi krokami. Niezależny przegląd zmian testów nie znalazł
blokującego osłabienia kontroli.

CI `34774367667`, zadanie PHP `103769656178`, zakończyło się wynikiem
3702 zaliczone / 1 błąd. Test onboardingu wymagał konkretnej osoby spośród
dziesięciu równie pasujących wyników, choć ekran pokazuje pięć i nie
gwarantuje kolejności remisów. Poprawiono kontrolę dodatnią w
`WynikiSzukaniaLudziBezWachlarzaZapytanTest`: dokładnie 2, potem 5 unikalnych
osób w sekcji wyników, z prawidłowymi nazwami i polami wyboru. Kolejność
wyszukiwania w aplikacji i wymóg stałej liczby zapytań pozostają bez zmian.

Lokalnie przeszły 3 testy / 43 asercje. Rzeczywista zamiana eagerload
`user.profile.avatar` na `user` w `SearchQuery.php` wywołała błąd wszystkich
trzech pomiarów, w onboardingu 14 → 20 zapytań. Kopia poza repo,
przywrócenie `cp -p`, MD5 `0077704b90c9ee671056eb63c2350ef4` i czasu
modyfikacji oraz ponowne 3/43 potwierdzają, że kontrola N+1 nadal działa.

W tym samym CI zadanie `103769656194` potwierdziło przejście portu:
432 warianty kompozycji i 48 rzeczywistego zoomu. Przeszły także kreator
24/24, aktualizacja Service Workera, kafel 15/15 z bramką oraz fokus
36 wariantów z trzema kontrolami ujemnymi i kliknięciami trzech obszarów.
Następnie skrypt dostępności zgłosił te same cztery stare asercje liczników,
bez naruszeń axe i układu. Zadanie zakończyło się błędem, nie timeoutem;
Lighthouse w tym CI został pominięty. Jego wynik 8/8 powyżej jest lokalny.

Niezależne porównanie źródeł wskazało również kompozycję wyboru
zainteresowań i zwykłych powiadomień do dalszego oglądu:
[issue #513](https://github.com/woogitsu/kuking.pl/issues/513).
To rozpoznanie kodu i prototypu, nie wykonany odbiór w przeglądarce.

## Produkcja przed tym pakietem — dowody

13 września odczytano GitHub main `d0cf7b9a51dd7b3ecc1cba6233110b8bfe9ef4cf`,
CI `34770672855` i Deploy `34771075299`: sukces. Railway deployment
`6424152940` ma status produkcyjny success. Odświeżony, zalogowany Start
w Chrome podaje **Alfa 0.17 / d0cf7b9**; obejrzano krótkie powitanie,
ciemny kafel dodawania i osobne karty tablicy.

Druga istniejąca karta przed odświeżeniem wciąż pokazywała Alfa 0.11 /
`dbd1cb9`, dawne powitanie i układ tablicy. Zwykłe odświeżenie zmieniło
metryczkę na Alfa 0.17 / `d0cf7b9`. To dowód starego dokumentu w tej karcie,
nie dowód problemu cache wszystkich użytkowników ani kompletności portu.
