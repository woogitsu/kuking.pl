# #858 — filtr tekstowy i „Pokaż kolejne" na liście „Twoje tagi"

Data: 20 września 2026. Gałąź `gpt/tagi-filtr`, odgałęziona od `gpt/tagi`
(`02beaa3c`), czyli od stanu PO poprawce #854. Stanowisko floty `tagi-filtr`.

Decyzja właściciela z 20.09.2026 w zgłoszeniu #858: budujemy filtr tekstowy
**i** doładowywanie przyciskiem, bez czekania na badanie.

## Pochodzenie liczb

Wszystkie liczby w tym dokumencie **zmierzyłem sam**, w Chromium, na własnej
instancji (`php artisan serve`, baza `kuking_flota_tagi_filtr_a11y`,
127.0.0.1:55439, fixture `scripts/fixtures/obserwowanie-tagow.php`).
Adnotacja `[pomiar cudzy: …]` stoi przy każdym zdaniu przejętym.

Metodę sprawdziłem na cudzym wyniku, zanim jej użyłem do własnego: przy
liście otwartej w całości mój pomiar daje **8377 px / 27 515 px / 3789 px**
dla stu tagów — czyli **co do piksela** te same wartości, co
`[pomiar cudzy: docs/research/OBSERWOWANIE_TAGOW_2026-09-20.md]`. To samo dla
czterdziestu (3344 / 10 992 / 1553) i dziesięciu (827 / 2730 / 435). Dopiero
ta zgodność pozwala zestawić obie tabele obok siebie jako jeden pomiar,
a nie dwa różne.

Mierzona wielkość to **wysokość elementu `.choice-grid`** — samej listy pól
wyboru, bez nagłówka strony, filtra i przycisków. Tak liczy raport bazowy
i tylko tak obie kolumny znaczą to samo. Wysokość całego formularza podaję
osobno i **tylko w obrębie stanu po zmianie**: formularza sprzed zmiany nie
zmierzyłem, bo nie zawierał ani pola filtra, ani licznika.

„Tekst 200%" to **ustawienie rozmiaru czcionki w przeglądarce**
(`html { font-size: 32px }`, tekst bieżący 18 → 36 px), nie skala tekstu
serwisu z `/ustawienia/czytelnosc` — ta kończy się na 140%.

## Wysokość listy PRZED zmianą

Stan sprzed zmiany, czyli cała lista naraz. Odtworzony na tej gałęzi przez
`?ile=` i potwierdzony zgodnością z raportem bazowym (wyżej).

| Tagów | 320 px | 360 px | 390 px | 414 px | 1440 px |
|---|---:|---:|---:|---:|---:|
| 10 | 827 | 827 | 827 | 827 | 435 (3 kol.) |
| 10, tekst 200% | 2 730 | 2 730 | 2 730 | 2 172 | 1 614 |
| 40 | 3 344 | 3 344 | 3 344 | 3 344 | 1 553 (3 kol.) |
| 40, tekst 200% | 10 992 | 10 992 | 10 992 | 8 760 | 6 528 |
| 100 | 8 377 | 8 377 | 8 377 | 8 377 | 3 789 (3 kol.) |
| 100, tekst 200% | 27 515 | 27 515 | 27 515 | 21 935 | 16 356 |

## Wysokość listy PO zmianie (pierwsze otwarcie)

| Tagów | 320 px | 360 px | 390 px | 414 px | 1440 px |
|---|---:|---:|---:|---:|---:|
| 10 | 827 | 827 | 827 | 827 | 435 (3 kol.) |
| 10, tekst 200% | 2 730 | 2 730 | 2 730 | 2 172 | 1 614 |
| 40 | 1 666 | 1 666 | 1 666 | 1 666 | 770 (3 kol.) |
| 40, tekst 200% | 5 484 | 5 484 | 5 484 | 4 368 | 3 252 |
| 100 | 1 666 | 1 666 | 1 666 | 1 666 | 770 (3 kol.) |
| 100, tekst 200% | 5 484 | 5 484 | 5 484 | 4 368 | 3 252 |

Trzydzieści przypadków w każdej tabeli: 5 szerokości × 2 rozmiary tekstu ×
3 długości listy. **Nigdzie nie ma przepełnienia poziomego**
(`scrollWidth > innerWidth` fałszywe we wszystkich 60 przypadkach).

### Co z tego wynika

- **Sto tagów, 320 px: 8 377 → 1 666 px, czyli −80,1%.**
  Przy tekście 200%: **27 515 → 5 484 px, −80,1%.** Na 1440 px: 3789 → 770 px.
- Wiersze „40" i „100" są teraz **identyczne**, i to jest sedno zmiany:
  pierwszy ekran przestał zależeć od długości listy. Czterdzieści i sto tagów
  kosztują tyle samo, bo kosztują jedną porcję.
- **Dziesięć tagów nie zmieniło się o ani jeden piksel.** Lista krótsza od
  porcji nie dostaje ani okna, ani przycisku doładowania — nie ma czego
  doładowywać, a przycisk, po którym nic nie dochodzi, byłby dokładnie tym
  martwym przyciskiem, którego zabrania D-053.
- Cały formularz (z filtrem, licznikiem i przyciskami) przy stu tagach
  i 320 px ma **2 212 px** zamiast 8 852 px, a przy tekście 200% —
  **7 189 px** zamiast 28 994 px. Obie wartości zmierzone na kodzie PO
  zmianie; drugą liczbę daje lista otwarta do końca, nie kod sprzed zmiany.

### Waga HTML (z `PomiarTagow858Test`, uruchomione przeze mnie)

| Tagów | pierwsze otwarcie | lista otwarta do końca |
|---|---:|---:|
| 10 | 31,2 KB / 10 pól | 31,2 KB / 10 pól |
| 40 | 36,8 KB / 20 pól | 45,1 KB / 40 pól |
| 100 | 37,6 KB / 20 pól | 73,0 KB / 100 pól |

Sto tagów: **73,0 → 37,6 KB, −48,5%** na pierwszym otwarciu.
Raport bazowy podawał po #854 69,4 KB
`[pomiar cudzy: OBSERWOWANIE_TAGOW_2026-09-20.md]`; różnica 73,0 − 69,4 to
koszt pola filtra, licznika i przycisku, obecnych także przy pełnej liście.

## Jak rozwiązane jest rozszerzanie tokenu

To jest warunek z decyzji właściciela: **każde doładowanie ma ROZSZERZYĆ
listę w tokenie, nie zastąpić jej.**

Token `form_scope` z #854 niesie identyfikatory pokazanych tagów (`shown`)
i daty istniejących relacji (`followed`). Całość liczy `App\Domain\Tags\TagFollowWindow`:

1. **`shown` jest sumą, nigdy przypisaniem.** Nowe `shown` = stare `shown`
   ∪ identyfikatory właśnie pokazane. W kodzie nie ma miejsca, w którym
   `shown` dostaje wartość zamiast się powiększać.
2. **`followed` rośnie razem z nim**, o daty relacji istniejących dla tagów
   pokazanych właśnie teraz. Wpisów już obecnych nie nadpisuje.
3. **Tag pokazany dopiero teraz, a już obserwowany, wraca ZAZNACZONY.**
   Bez tego token mówiłby „obserwowany", formularz „niezaznaczony",
   a różnica — „usuń": doładowanie odobserwowywałoby po cichu co trzeci tag
   z nowej porcji. To była najgroźniejsza pułapka tej pracy i ma własną
   asercję w teście obowiązkowym.
4. **Zakres rośnie WYŁĄCZNIE po naciśnięciu „Pokaż…".** Powrót z odmową
   walidacji rysuje dokładnie to, co było widać przed wysłaniem — inaczej
   tag obserwowany w drugiej karcie wchodziłby do punktu odniesienia
   formularza, czyli odwracalibyśmy #854. Rozróżnia to znacznik `przeglada`
   przekazywany przez `withInput()`, którego nie ma w formularzu.
5. **Wybór ukryty przez filtr albo stojący za oknem jedzie w polach
   ukrytych `tags[]`** — ale tylko dla tagów, które token już zna. Tag spoza
   `shown` wywróciłby zapis na kontroli „Wybierz tagi z tej listy", a tag,
   który zniknął z listy (ukryty, scalony), celowo nie wraca jako wybór.

Zapis (`UpdateTagFollows::save`) nie zmienił się ani o linię. Cała zmiana
polega na tym, CO trafia do tokenu i co formularz odsyła.

## Test obowiązkowy — i dlaczego udaje przeglądarkę

`tests/Feature/TagiFiltrIDoladowanieTest.php`, napisany **przed** kodem,
**widziany na czerwono**: 8 porażek na 11 testów przed poprawką
(`docs/research/tagi-filtr-2026-09-20/czerwien-przed-poprawka.txt`, commit
`3d6e98c4` zawiera sam test). Trzy przechodzące to te, które kod sprzed
zmiany spełniał trywialnie, bo nie miał ani filtra, ani okna.

Usterka, o którą tu chodzi, nie mieszka w kontrolerze — mieszka w RÓŻNICY
między tym, co widok narysował, a tym, co przeglądarka odeśle. Test, który
sam układa tablicę `tags`, sprawdza własne wyobrażenie o formularzu. Dlatego
pomocnik `wyslij()` czyta z HTML-u wszystkie pola `tags[]` — zaznaczone
checkboxy oraz pola ukryte — i odsyła dokładnie to, co odesłałaby
przeglądarka.

Scenariusz wymagany przez właściciela: otwórz, doładuj drugą porcję, zaznacz
po jednym z każdej porcji, zapisz → obie zmiany wchodzą, nic poza nimi się
nie rusza, a daty relacji sprzed otwarcia zostają bez zmian.

Plik ma dziś 13 testów; dwa doszły po pierwszym przebiegu, gdy zobaczyłem
skutki własnych decyzji: wyjście z filtra jednym naciśnięciem i to, że odmowa
walidacji nie może zabrać przycisku „Pokaż kolejne…".

Drugi osobny test pilnuje, że **tag ukryty przez filtr nie traci
zaznaczenia**, w trzech wariantach: obserwowany przed otwarciem, zaznaczony
przed zmianą filtra, i powrót do całej listy.

Sprawdzone też w prawdziwej przeglądarce, przy 320 px, na stu tagach: konto
obserwujące 33 tagi, zaznaczenie „Temat kulinarny 001" z pierwszej porcji,
doładowanie, filtr „07", zaznaczenie „Temat kulinarny 070" widocznego tylko
przez filtr, zapis. Po zapisie: **35 obserwowanych**, dokładnie te dwa nowe,
żadnego ubytku.

## Wybór wariantu bez JavaScriptu — i uzasadnienie

**Wybrałem paginację po stronie serwera, i jest to wariant jedyny: ten ekran
nie ma ani linii własnego JavaScriptu.** Filtr i doładowanie są zwykłymi
przyciskami `submit` tego samego formularza.

Drugą dopuszczalną drogą było „filtrowanie i doładowywanie skryptem,
a bez skryptu pełna lista". Odrzuciłem ją, bo **zostawia 27 515 px dokładnie
tym osobom, którym skrypt się nie dociągnął** — czyli tym na jednej kresce
zasięgu, dla których ten ekran jest najdroższy. D-053 mówi wprost, że
uzasadnieniem starej reguły nigdy nie było „telefon nie ma JavaScriptu",
tylko „przy słabym zasięgu skrypt się nie dociąga". Wariant zapasowy
w kształcie pełnej listy odwracałby więc poprawkę tam, gdzie boli najbardziej.

Pozostałe skutki tego wyboru:

- **martwego przycisku nie da się tu zrobić** — nie ma skryptu, który mógłby
  się nie dociągnąć; D-053 jest spełniona przez nieobecność, nie przez obietnicę;
- **jedna droga zamiast dwóch**, więc nie ma wersji zapasowej, której nikt nie
  używa, nikt nie testuje i która cicho gnije;
- **każde naciśnięcie zabiera ze sobą cały wybór**, bo wysyła formularz.
  Odnośnik („Pokaż więcej" jako `<a href>`, jak w `x-show-more`) zabrałby
  tylko adres i skasował zaznaczenia zrobione przed nim — czyli spełniłby
  literę zgłoszenia, łamiąc zasadę, że poprawne dane nigdy nie znikają.

Cena: jedno przeładowanie strony na filtrowanie i jedno na doładowanie.
Przy grupie 50+ uważam ją za właściwą: przeładowanie jest widoczne
i przewidywalne, a lista przestawiająca się sama pod palcami nie jest.

**Kolejność przycisków nie jest kosmetyką.** Enter w polu tekstowym wyzwala
pierwszy przycisk `submit` formularza — „Pokaż pasujące" stoi więc przed
„Zapisz". Enter po wpisaniu frazy filtruje, a nie zapisuje; filtrowanie
niczego nie zmienia i wraca z pełnym wyborem, więc pomyłka nic nie kosztuje.

## UX 50+ — co sprawdzone

- **Etykieta filtra jest widoczna** (`<label for="f-szukaj">Szukaj wśród
  tagów</label>`), nie sam placeholder. Pole nie ma placeholdera w ogóle;
  przykład stoi w tekście pomocy powiązanym przez `aria-describedby`.
- **Zero wyników mówi, co zrobić**: „Wpisz krótszy fragment nazwy i naciśnij
  »Pokaż pasujące« — albo naciśnij »Pokaż wszystkie tagi«, żeby wrócić do
  całej listy. Twoje zaznaczenia zostają." Przycisk wyjścia z filtra stoi
  obok pola zawsze, gdy filtr jest włączony — wyjście jednym naciśnięciem,
  a nie „wyczyść pole I naciśnij drugi przycisk".
- **Przycisk doładowania mówi, ile pozycji dojdzie** („Pokaż kolejne 20
  tagów", a na końcu listy „Pokaż kolejne 5 tagów"), z odmianą liczebnika
  przez `App\Support\Odmiana`. Znika, gdy nie ma czego dołożyć.
- **Cele dotknięcia**: wszystkie trzy przyciski formularza zmierzone
  w przeglądarce przy 320 px mają **51 px** wysokości (wymagane ≥ 48).
- **Licznik `role="status"`** („Widzisz 20 z 100 tagów", „Do »07« pasuje
  11 tagów; widzisz 11") — po przeładowaniu czytnik ekranu mówi, ile z czego
  widać, zamiast kazać liczyć pozycje.
- **Bez hover i bez swipe.** Żadnej nowej reguły `:hover`, żadnego gestu.
- **Filtr ignoruje polskie znaki** tą samą regułą co wyszukiwarka
  (`mb_strtolower(Str::ascii(...))`, jak `SearchQuery::normalize()`):
  „zurek" znajduje „Żurek". Dopasowanie jest podciągiem, nie podobieństwem —
  wyszukiwarka wolno zgaduje literówki, bo szuka w nieznanym zbiorze,
  a tu człowiek patrzy na własną listę.

Przełącznika „tylko obserwowane" **nie ma** — właściciel go odrzucił.

## Kontrole

- **Pint: 1163 pliki, PASS.** **PHPStan: bez błędów.**
- **Testy ukierunkowane** (`Tagi|Tag|Onboarding|PomiarTagow`):
  **308 przejść, 50 330 asercji**
  (`docs/research/tagi-filtr-2026-09-20/testy-ukierunkowane.txt`).
- **Pełny zestaw: 4414 przejść, 83 720 asercji, 328,00 s**
  (`docs/research/tagi-filtr-2026-09-20/pelne-testy.txt`).
  Pominięty `ProbaOdtworzeniaTest` — zgodnie z wyjątkiem floty używa
  współdzielonej bazy próby odtworzenia. Żadnej porażki nie uznałem
  za „zastaną" — nie było żadnej.
- **Budowanie assetów: PASS** (`app-*.css`, 148,6 KB).
- Przeglądarka: pełny przebieg filtr → doładowanie → zapis przy 320 px,
  opisany wyżej, z weryfikacją stanu w bazie.

### Co zmieniłem w cudzych testach i dlaczego

Trzy testy z gałęzi bazowej wymagały poprawki, bo mierzyły stan, który ta
zmiana świadomie znosi. **Nie osłabiłem ich, zmieniłem mechanizm:**

- `PomiarTagow858Test` sprawdzał, że setka tagów rysuje sto pól wyboru.
  Teraz sprawdza **oba** stany: pierwsze otwarcie (jedna porcja) i listę
  otwartą do końca (nadal 10/40/100 pól). Sama asercja „krótko" dałaby się
  spełnić gubieniem tagów; para asercji nie.
- Dwa testy `861` (limit z 50 i z 251 tagów) wysyłały całą listę przeciwko
  formularzowi, który pokazuje dwadzieścia. Otwierają teraz najpierw całą
  listę (`?ile=`), bo mówią o limicie obserwowań konta, a nie o limicie okna.
  Limit `count($scope['shown'])` nadal wynika z **rzeczywiście pokazanych**
  opcji i to się nie zmieniło.
- `855 usunięty wybór…` porównywał token znak po znaku. Token jest szyfrowany
  losowym wektorem, więc po odmowie wychodzi inny ciąg niosący ten sam punkt
  odniesienia; test porównuje teraz **odszyfrowaną treść**, czyli to, o co mu
  chodziło, zamiast pilnować szyfrowania.

### Zmiana w fixture

`scripts/fixtures/obserwowanie-tagow.php` przypinał się do bazy jednego
stanowiska (`kuking_flota_gpt_tagi_a11y`), więc kolejne stanowisko mogło albo
nie powtórzyć pomiaru, albo wejść na cudzą bazę. Nazwa jest teraz wzorcem
`kuking_flota_<stanowisko>_a11y`. **Sufiks `_a11y` zostaje obowiązkowy**,
razem z `local`/`testing` i portem 55439 — to nadal nie jest baza testowa
stanowiska ani tym bardziej produkcyjna.

### Arkusz stylów w osobnym pliku

Reguły ekranu stoją w nowym `resources/css/ekran-tagi.css`, wpiętym importem
— tak jak `ekran-wyszukiwania.css`. Drugi powód jest zmierzony:
`GlosMarkiOpisujeArkuszPrawdziwieTest` czyta `app.css` wyrażeniem, które
dopasowuje reguły **na przemian** (każda potrzebuje `}` poprzedniej, a ta
ten `}` już zjadła). Dołożenie nieparzystej liczby reguł nad `.kuking-word`
przestawia parzystość i test pada na regule, której nikt nie ruszał —
zobaczyłem to na własnym kodzie. Strażnik jest kruchy i zasługuje na osobną
poprawkę; nie naprawiam go przy okazji tej pracy, żeby nie mieszać dwóch
spraw w jednym zgłoszeniu.

## Czego NIE zmierzyłem

- **Czy ludzie szybciej znajdują temat.** To jest cel zgłoszenia i nie
  zmierzyłem go ani razu. Zmierzyłem wysokość ekranu i wagę HTML, czyli
  koszt, a nie skutek. Raport bazowy mówił to samo o sobie i miał rację.
- **Skąd dwadzieścia.** Porcja to wybór, nie wynik. Nie zmierzyłem, po ilu
  pozycjach ludzie przestają przewijać ani ile doładowań są skłonni zrobić.
- **Ile tagów naprawdę widzą konta produkcyjne.** Nadal nie wiadomo, więc
  nie wiadomo, jaka część ludzi w ogóle zobaczy przycisk doładowania.
- **Obciążenie limitera.** Filtrowanie i doładowywanie idą tą samą trasą
  `PUT /ustawienia/tagi` co zapis, czyli dzielą limit `ustawienia`
  (30 żądań / 10 minut, liczone na konto). Nie zmierzyłem, ile żądań zbiera
  realna sesja przeglądania. Przekroczenie limitu oznaczałoby stronę 429
  i utratę niezapisanych zaznaczeń — to jest ryzyko nazwane, nie zamierzone.
- **Czasy odpowiedzi.** `PomiarTagow858Test` wypisuje pojedyncze próby
  (12–17 ms); to nie jest benchmark ani statystyka produkcji.
- **Pełny audyt WCAG i badania z uczestnikami.** Pomiar układu i sprawdzenie
  wysokości przycisków nie są dowodem dostępności.
- **Wyścig dwóch procesów.** Jak w raporcie bazowym: testy są sekwencyjne.
  Doładowanie nie dokłada nowej blokady ani nowego zapisu, ale też niczego
  o wyścigach nie dowodzi.
- **Zoom przeglądarki** (`chrome.tabs.setZoom`) i **skala tekstu serwisu**
  (70–140%) — zmierzyłem wyłącznie rozmiar czcionki przeglądarki 100/200%.
  Raport bazowy sprawdzał tamte dwie osobno; nie powtarzałem tego.

## Co wymaga decyzji właściciela

1. **Porcja: dwadzieścia.** Liczba wzięta z pomiaru wysokości (≈84 px na
   pozycję przy 320 px, czyli porcja to około dwóch i pół ekranu telefonu),
   ale nie z obserwacji ludzi. Jeśli ma być inna, to jest jedna stała
   `TagFollowWindow::PORCJA`.
2. **Wspólny limiter z zapisem.** Czy przeglądanie własnej listy ma dzielić
   koszyk `ustawienia` z zapisem, czy dostać własny, luźniejszy. Nie
   rozstrzygam tego asercją — asercja zabetonowałaby próg, którego nikt nie
   wybrał.
3. **Enter filtruje, nie zapisuje.** Wynika z kolejności przycisków. Uważam
   to za bezpieczniejsze, ale to jest zachowanie widoczne dla człowieka
   i warto, żeby było wybrane, a nie odziedziczone po układzie.

## Odtworzenie

```bash
# testy (WSL; z Git Basha obowiązuje MSYS_NO_PATHCONV=1)
bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh tagi-filtr
bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh tagi-filtr --filter TagiFiltrIDoladowanieTest
bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh tagi-filtr --filter PomiarTagow858Test

# pomiar w przeglądarce
createdb kuking_flota_tagi_filtr_a11y        # PGPORT=55439, PGUSER=kuking
# w .env runtime: APP_ENV=local, DB_DATABASE=kuking_flota_tagi_filtr_a11y
php artisan migrate --force && npm run build
php scripts/fixtures/obserwowanie-tagow.php 100   # albo 10 / 40
php artisan serve --host 127.0.0.1 --port 8858
```

Wysokości czytane w Chromium z `.choice-grid`:
`getBoundingClientRect().height`, przy `document.documentElement.style.fontSize`
ustawionym na `16px` i `32px`. Szerokości emulowane w przeglądarce.

Bez pushowania, bez PR, bez wdrożenia i bez zmian produkcyjnych. Zmiany
schematu bazy **nie ma** — token stanu formularza nadal nie potrzebuje
tabeli. Wycofanie: odwrócić lokalne commity; powrót do starego kodu przywraca
listę stu pozycji naraz, nie przywraca żadnej usterki zapisu.
