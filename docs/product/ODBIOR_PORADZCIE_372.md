# Poradźcie — odbiór roboczy #372

Status: **W TRAKCIE**, lokalna gałąź `feat/372-poradzcie`, baza kodu
`55877e2b5c0aff04d93e6db75f079c7e61d4df5d`. Ten raport nie potwierdza
wdrożenia funkcji ani zakończenia portu marki.

## Aktualny stan odbioru — 18 września 2026

Ten blok ma pierwszeństwo przed historycznymi zapisami sesji poniżej.
Kod `3c93027488a6de6c8a16b38b122e1c5e29f2ca22` jest na GitHub w draft PR #680.
Pełny port marki nadal **CZĘŚCIOWO**. Funkcja jest domyślnie wyłączona.

| Zakres | Potwierdzone | Pozostało |
|---|---|---|
| Kod i kontrola wysyłki | Zwykły hook dla 3c93027 PASS; CI35344659078: wszystkie12zadań success, SHA zgodne z PR680 | Ponowne CI po ewentualnej zmianie źródeł |
| Publikowanie | Prawdziwe POST, walidacja i old(), hashtagi, publikacja bez JS, zachowanie uploadu; typowe zdjęcie po pełnym workerze i odczyt czterech wariantów | Odbiór produkcyjny po wdrożeniu |
| Odpowiedzi | Odpowiedź drugiej osoby, powiadomienie autora i przejście do pytania; walidacja rozmowy zagnieżdżonej | Całościowy ogląd pozostałych stanów i paginacji |
| Gospodarz | Logowanie i włączenie 2FA przez formularze, odpowiedź usuwa pytanie z kolejki 2→1 | Pozostałe stany moderacji i uprawnień w przeglądarce |
| Formularz przy powiększeniu | Rzeczywisty zoom 200%, tekst140%, szerokość treści320/720, oba motywy; błąd odsłonięty i opis zachowany | Pozostałe konfiguracje i kontrolki; nie tylko brak overflow |
| Wspólny widget | Regresja zasłaniania błędów, fizyczny negatyw JS i przywrócenie MD5/mtime; niezależne review | Zwykłe teksty są nadal miejscowo przykrywane na zrzutach listy/szczegółu |
| Szersze regresje | Część baza 335,67 s; rozszerzenia 1281,84 s PASS na kuking_port_372, w tym 88 wariantów rzeczywistego zoomu 200% | Wcześniejsze dwa błędy konfiguracji bazy zachowane w historii poniżej |
| Uruchomienie | Brak potwierdzonego wdrożenia tego pakietu | Merge po bramkach, beta, odbiór SHA i kontrolowane publiczne włączenie |

Przykład powiadomienia w #372 doprecyzowano na „Anna — odpowiedź na Twoje
pytanie.” zgodnie z bezrodzajowym stylem wspólnych powiadomień po #38.
Nie rozpoznajemy płci po nazwie konta. Zachowane są wymagania dotyczące autora,
zdarzenia i właściwego odnośnika. Ta korekta nie stanowi odbioru wdrożenia.

Poniższe sekcje są historią dowodów: dawne określenia „trwa” albo „brakuje”
opisują moment danego zapisu, a nie stan wszystkich późniejszych poprawek.

## Przeprowadzone scenariusze

18 września 2026, Chromium, lokalna aplikacja Laravel na porcie 8372,
oddzielna baza `kuking_372_browser` na PostgreSQL 55439:

- Pusty publiczny ekran „Poradźcie”, szerokość 390 px, jasny motyw.
- Gość wybiera „Zadaj pytanie”; aplikacja kieruje do logowania.
- Logowanie lokalnym kontem testowym i powrót do formularza pytania.
- Publikacja pytania z opisem i tagiem `#zupa` przez rzeczywisty formularz.
- Przejście przyciskiem „Napisz odpowiedź” do sekcji odpowiedzi.
- Dodanie odpowiedzi przez formularz i odczyt zapisanej treści.
- Wpisanie `#zu`, otrzymanie sugestii `#zupa — 1 publiczny wpis` i wybór
  kliknięciem. Liczba odpowiada jedynemu publicznemu wpisowi w lokalnej bazie.
- Odrzucenie pięcioznakowego tytułu: opis z wybranym hashtagiem zachowany;
  błąd przy tytule i w podsumowaniu. Kliknięcie błędu ustawia fokus na `f-title`.
- Formularz błędu przy 320 px: szerokość dokumentu 320 px, bez poziomego
  przewijania. To samo przy ciemnym motywie i tekście 140% (25,2 px).
  Ustawienia zmieniono rzeczywistym panelem wyglądu; tekst roboczy nie zniknął.
  Po kliknięciu błędu pole zajmowało pionowo 278–353 px w oknie 844 px.
- Obejrzano również formularz błędu w ciemnym motywie, tekst 140%, 1440 px.
  Te próby nie zastępują pełnej macierzy ani rzeczywistego zoomu przeglądarki.
- Odpowiedź pod istniejącą odpowiedzią, 4001 znaków: prawdziwy POST z przeglądarki
  odrzucony, podsumowanie wskazuje konkretne pole, formularz pozostaje otwarty,
  a tekst jest zachowany. Dodano otwieranie właściwego formularza i podsumowanie
  błędów do wspólnego komponentu komentarzy.
- Otwarcie panelu udostępniania pytania: pole kopiowania zawiera adres
  `/pytania/{id}`. Kanały udostępniania korzystają z tytułu pytania.
  Nie wysyłano wiadomości ani nie publikowano treści w serwisach zewnętrznych.

Obejrzano zrzuty lokalne `output/poradzcie-mobile.png` oraz
`output/question-published390.png`. Konto i treści są wyłącznie testowe.
Nie przeprowadzono tych operacji na produkcji.

## Problemy znalezione i poprawione podczas odbioru

- Przycisk odpowiedzi otwierał początek pytania; wskazuje teraz `#komentarze`.
- Karta pytania nie dostawała licznika odpowiedzi. Dostaje sumę widocznych
  głównych odpowiedzi z tego samego zapytania co sekcja odpowiedzi.
- Formularze odpowiedzi i poprawek powielały identyfikatory pól. Wspólny
  komponent korzysta teraz z istniejącego `WierszFormularza`, który rozdziela
  identyfikatory, błędy oraz odzyskiwany tekst poszczególnych formularzy.
- Przekroczenie limitu trzech tagów przy edycji wskazywało pole opisu.
  Komunikat jest przypisany do tagów; wpisane dane zostają w formularzu.
- Nowe pytanie nie miało podpowiedzi hashtagów w opisie. Korzysta teraz
  z tego samego mechanizmu co zwykły wpis. Odbiór istniejącej sugestii
  kliknięciem w przeglądarce przeszedł; pozostałe scenariusze nadal wymagają prób.
- Udostępnianie pytania używało adresu zwykłego wpisu i nazwy autora zamiast
  tytułu. Korzysta teraz z kanonicznego adresu pytania i jego tytułu.
  Bramka publicznej widoczności nadal działa przez Policy.
- Dział był dostępny wyłącznie po adresie. Dodano wejście z „Odkrywaj”
  oraz „Zadaj pytanie” na ekranie „Dodaj”; oba są ukryte przy wyłączonej fladze.
- Nazwy czterech pól formularza dopasowano do bieżącej specyfikacji #372.

## Wykonane kontrole

- Formularze pytań, edycja i tagi: 32 testy, 164 asercje.
- Grupa komentarzy, edycji i widoku pytania: 115 testów, 2081 asercji.
- Po poprawce licznika: widok pytania, paginacja i liczniki — 14 testów,
  1586 asercji.
- Pint trzech ostatnio zmienianych plików PHP: PASS.
- PHPStan całego projektu po ostatniej poprawce: bez błędów.
- Po dodaniu otwierania błędnego formularza: 29 testów, 182 asercje.
  Test HTML korzysta z błędów rzeczywistego POST, ale jawnie utrwala flash
  przez `keep` i `save` przed GET. Bez tego w procesie testowym błędy znikały,
  chociaż old input pozostał. Przyczyna różnicy nie została ustalona;
  nie przedstawiamy tego testu jako dowodu naturalnego cyklu sesji.
  Naturalny cykl POST–przekierowanie–GET potwierdzono osobno w przeglądarce
  z sesją plikową, bez ręcznego przenoszenia błędów.
- Po poprawce udostępniania: 23 testy, 153 asercje, obejmujące także dotychczasowe
  przepisy i wpisy. PHPStan całego projektu ponownie bez błędów.
- Po dodaniu wejść i nazw pól: 37 testów, 191 asercji (formularz, lista,
  edycja pytań oraz istniejące tagi wpisów).

Są to osobne przebiegi o częściowo wspólnym zakresie, nie suma unikalnych testów.

Fizyczna kontrola ujemna wspólnego widoku komentarzy usunęła parametr
`wiersz` z pola odpowiedzi. Test HTTP wykrył powielone identyfikatory.
Plik przywrócono z kopii poza repo; MD5 przed i po:
`1902c1288cf1216f3662340883df763f`. Przywrócono również mtime.
Po wyczyszczeniu pamięci skompilowanych widoków ponowny test przeszedł.
Logi i kopia: `/home/mateusz/kuking-negative372/1789728340466085348/`.
Pierwsza próba dodatnia przed czyszczeniem widoków korzystała ze starej
kompilacji Blade i nie przeszła; nie był to udany wynik kontroli.

## Pozostaje przed dostarczeniem

- Przeglądarkowy odbiór kolejki gospodarza; odpowiedź innej osoby i powiadomienie autora sprawdzone poniżej;
  publikacja, zachowanie zdjęcia po błędzie i publikacja bez JS już sprawdzone.
- Rozszerzenie macierzy pustego formularza na listę, szczegół i pozostałe stany;
  rzeczywisty zoom 200% nadal niezmierzony. Oba motywy i sześć szerokości
  pustego formularza potwierdzono, szczegóły kolejnych zmian niżej.
- Odbiór myszy, dotyku, Tab/Enter, modali i menu; brak poziomego przewijania.
- Sprawdzenie pozostałych widoków wspólnego komponentu w przeglądarce.
- Pozostałe wymagane kontrole ujemne, niezależne review i pełny hook.
- Dokumentacja decyzji, wersja i changelog, PR, wymagane CI oraz odbiór wdrożenia.
- Uruchomienie funkcji zgodnie z etapami opisanymi w #372. Domyślna flaga
  produkcyjna nadal jest wyłączona.

Wybrane dowody przeglądarkowe zapisano w `docs/design/evidence/questions372/`:
`form-matrix.json`, końcowy formularz desktop dark140 oraz walidacja i publikacja
bez JavaScriptu na 390 px. Macierz poprzedza ostatnią korektę odstępu opisu;
nie stanowi dowodu wszystkich ekranów i stanów działu.

### Review integracji — 18 września, dalsza praca

Niezależny reviewer znalazł ominięcie flagi pytań w FollowingFeed, profilu oraz filtrze powiadomień. Dodano enabledKinds do obu zapytań feedu, do wspólnego filtra profilu dla modelu Post (przed wyjątkiem właściciela) i równoważny warunek posts.kind w surowym zapytaniu powiadomień. Regresja sprawdza przełączenie flagi, profil gościa i autora, feed oraz zachowanie istniejącego powiadomienia. Wynik lokalny: 14 testów / 56 asercji (QuestionsFeatureGateTest|FeedTest), PASS. Zmiany nadal lokalne, bez push/CI/wdrożenia.

Pozostaje drugie ustalenie review: usunięty komentarz z dziećmi jest śladem, ale nadal liczony jako odpowiedź i trafia do QAPage. Trzeba rozwiązać bez gubienia rozmowy. Odbiór 24 konfiguracji formularza wymaga powtórzenia z odczytem rzeczywistego motywu: część screenshotów nazwanych dark pokazuje light, więc nie dowodzi odbioru ciemnego motywu.

### Usunięta odpowiedź z dalszą rozmową — 18 września

Dodano nullable comments.body_removed_at oraz zapis znacznika przy usuwaniu treści komentarza z dziećmi. Rozmowa pozostaje widoczna, ale ślad nie jest odpowiedzią w QuestionList, kolejce gospodarza, liczniku szczegółu ani QAPage. Polityka zabrania ponownej edycji usuniętej treści. Nie odgadujemy historycznych usunięć na podstawie tekstu: stary tekst placeholdera jest nieodróżnialny od ręcznie wpisanego zdania. Nowy dział jest jeszcze wyłączony produkcyjnie. Migracja przeszła tylko w izolowanych bazach lokalnych, wymaga pełnej kontroli odwracalności przed dostarczeniem.

Regresja wykonuje rzeczywisty DELETE odpowiedzi z dzieckiem, sprawdza zachowanie dziecka, brak oryginalnej treści, licznik 0, puste suggestedAnswer, powrót do kolejki i zakaz ponownej edycji. Kontrola ujemna fizycznie usunęła zapis body_removed_at z kontrolera: test nie przeszedł; po przywróceniu 42 testy / 189 asercji PASS. Kopia poza repo: /home/mateusz/kuking-negative372/1789729799233856559; MD5 przywrócony 05755bb8e0545d0eaf124fbb9c585645; mtime_ns 1789729741641516687. Logi negative.log i positive.log. Bez push/CI/produkcji.

### Korekta odbioru motywów i odstępu formularza

Ustalona przyczyna błędnych zrzutów dark: 429 na /motyw po nadmiarowych zapisach automatu; aplikacja przywracała zapisane ustawienie. Poprawiono output/matrix372.js: zapis tylko zmienionego wyboru, oczekiwanie na rzeczywistą odpowiedź 2xx oraz potwierdzenie dataset theme/textScale. Wyczyszczono wyłącznie lokalny cache izolowanej instancji przeglądarkowej, bez zmiany limitu aplikacji. Nowy przebieg output/matrix372-verified.log: 24 konfiguracje, oba faktyczne motywy, 100/140%, 320/360/390/414/768/1440, zero poziomego overflow. Obejrzano m.in. dark140 320/1440 oraz light100 768. To pomiar pustego formularza, nie kompletna macierz wszystkich stanów ani prawdziwy zoom 200%.

Ogląd wykazał brak odstępu między tytułem a opisem: wrapper autouzupełniania odbierał wewnętrznemu pierwszemu polu margines. Wrapper formularza pytania otrzymał istniejącą klasę field. Pomiar końcowy dark140/1440: 24 px odstępu; zrzut form372-final-dark140-desktop.png. QuestionFormTest 9 testów / 79 asercji PASS. Macierz 24 wykonano przed tą ostatnią jednowierszową korektą; pełnej powtórki po niej jeszcze nie wykonano.

### Pełna suita i dalsze review — aktywna sesja

PHPStan całego runtime PASS. Lokalna próba migracji na kuking_372_tests: kolumna jest -> rollback step1 -> brak -> migrate -> jest, PASS. Pełna suita 4151 testów trwa w sesji exec 69993; odczyt wskazał ponad 2300 testów i kilka porażek, szczegóły na końcu. NIE uruchamiać drugiej suity ani synchronizować runtime do zakończenia procesu.

Ponowne review wskazało potrzebę strażnika rollbacku przy nie-NULL body_removed_at oraz spójnego licznika na kartach wszystkich feedów. W Windows przygotowano te poprawki, jeszcze NIE w runtime: migracja odmawia utraty znaczników (LOCK+transaction), Post::scopeWithVisibleCommentCount wyklucza ślady tylko dla pytań; użyty w FollowingFeed, DiscoverFeed, TagFeed, DailyBoard, ProfileController, CollectionController i TagController. Nowy CommentRemovalMarkerMigrationTest oraz rozszerzenie testu usunięcia odpowiedzi. docs/DATABASE.md uaktualniono. Te ostatnie zmiany czekają na synchronizację i testy po zakończeniu sesji69993. Nie traktować wyniku tej suity jako dowodu ostatnich poprawek Windows.

### Wynik pełnej suity i naprawa pokrycia — 18 września

Sesja69993 zakończona (nie trwa): 4151 testów, 81835 asercji, 4 porażki, 3 notices, 6:25. Porażki: rejestr autoryzacji questions.index, brak mapowania nowych stron w pomiarze meta, brak questions.show w macierzy pięciu ról, brak /pytania w automacie dostępności. Nie były to dowody gotowości pakietu. Poprawiono: uzasadniony publiczny filtr aktywnego tagu w rejestrze; rzeczywisty prywatny model pytania i pięć ról; mapę meta z flagą true i opis listy; listę ekranów dostępności oraz flagę true tylko dla serwera uruchamianego przez pomiar. Gotowy zewnętrzny serwer ADRES wymaga włączenia flagi pytań; pusty ekran nie zastępuje pomiaru szczegółu i formularza.

Po zakończeniu suity zsynchronizowano bieżące zmiany do runtime (bez .git). Celowany przebieg wszystkich czterech rodzin plus migracja, szczegół i feed: 38 testów / 960 asercji PASS. Dwie fizyczne kontrole ujemne: wyłączenie strażnika rollbacku oraz błędne wliczanie śladów do kart. Obie FAIL zgodnie z oczekiwaniem; przywrócone MD5+mtime, potem 20 testów /109 asercji PASS. Kopie/logi: /home/mateusz/kuking-negative372/1789730617879716943. Migracja MD5 16dc29226ae47a546d7ec944e7a2cb7e; Post MD5 9fadde0994b414e817ae335d3ee7263f. Sesja45173 również zakończona. Pełnej suity po ostatnich poprawkach jeszcze nie powtórzono.

### Publikowanie w przeglądarce: brak JS i zdjęcie

Na izolowanym serwerze8372 i DBkuking_372_browser: nowy kontekst Chromium z javaScriptEnabled=false i rzeczywistą sesją lokalnego konta. Błąd krótkiego tytułu zachował opis z #zupa; po poprawieniu tytułu prawdziwy formularz opublikował pytanie01a0b443-55ac-708e-a4fa-39b355c1f6c5. Odczyt SQL potwierdził kind=question,status=published. Szerokość390, bez poziomego overflow. Zrzuty nojs372-validation390.png i nojs372-published390.png, drugi obejrzano.

Próba uploadu przez inputfile: lokalny PNG będący zrzutem testowego formularza (nie zdjęcie jedzenia ani dane produkcyjne). Błąd krótkiego tytułu zachował dokładnie jeden media_ids i komunikat o zachowaniu pliku. Po poprawieniu tytułu pytanie01a0b444-094e-715b-a5d3-07271c36c2a8 zapisane, obraz podglądu miał complete=true,naturalWidth>0; SQL potwierdził published. Media01a0b443-c57f-7299-9d9b-8d35be09ac8f. Zrzut photo372-published390.png obejrzano; bardzo długi plik testowy daje bardzo wysoką kartę, nie jest reprezentatywnym odbiorem kompozycji fotografii jedzenia. Oba scenariusze lokalne, żadnych zmian na produkcji.

### Pełna suita po poprawkach: zakończona

Sesja19354 zakończona kodem0: 4153 testy / 81915 asercji, brak błędów i porażek, 3 PHPUnit Notices, czas7:06.675. Nie jest to wynik CI ani hooka. Testowano runtime z poprawkami funkcjonalnymi; późniejsze pliki wersji0.66/changelogu/D-221 i końcowe raporty wymagają jeszcze celowanej weryfikacji dokumentów oraz zwykłego hooka. Review372 zamknęło oba dodatkowe P2, ocena statyczna. Pełny port marki nadal CZĘŚCIOWO, etap uruchomienia Poradźcie nie zakończony.

## Odpowiedź drugiej osoby i kontrola wysyłki

Na tej samej izolowanej bazie wykonano rzeczywiste logowanie drugiego konta,
publikację odpowiedzi, odczyt powiadomienia na koncie autora oraz kliknięcie
przycisku „Zobacz”. Prowadzi on do właściwego pytania, gdzie odpowiedź jest
widoczna. Przy szerokości 390 px szczegół nie przewijał się poziomo.
Obejrzano `second-answer372.png` (jasny motyw) i `answer-notification372.png`
(ciemny motyw, tekst 140%). Zrzuty pełnej strony zawierają przyklejoną dolną
nawigację i podpowiedź wyglądu; nie zastępują sprawdzenia zasłaniania kontrolek
podczas przewijania. Nie wysłano żadnej wiadomości do rzeczywistych użytkowników.

Pierwszy zwykły push został zatrzymany przez test domyślnej flagi pytań.
Helper błędnie dziedziczył `KUKING_QUESTIONS_ENABLED=true` ze środowiska
przeglądarkowego. Usunięcie tej zmiennej z helpera przywróciło domyślną
konfigurację testów, bez zmiany testu ani kodu aplikacji. Osobno usunięto
ostrzeżenia phpdotenv przez utworzenie brakującego lokalnego `.env` zawierającego
wyłącznie komentarz (ustawienia nadal przekazywane jawnie przez środowisko).
Celowana próba `artisan test`: 21 testów / 153 asercje, bez porażek i tych
ostrzeżeń. To nie zastępuje ponownego pełnego hooka ani CI.
Druga próba hooka wskazała także test zaufanych hostów: odziedziczony
przeglądarkowy `APP_URL` ustawiał host 127.0.0.1 zamiast testowego localhost.
Helpery odłączono całkowicie od konfiguracji przeglądarkowej; korzystają
z jawnej konfiguracji odrębnej bazy testowej na 55439. Ponownie sprawdzono
wszystkie rodziny testów odnotowane jako wadliwe w lokalnej pamięci PHPUnit:
83 testy / 1256 asercji przeszło. Żadnej asercji nie osłabiono. Nadal potrzebny
jest pełny zwykły hook na końcowym commicie.

## Aktualizacja: wysyłka i rzeczywisty zoom 200%

Zwykły hook zakończył się sukcesem: Pint, składnia, kontrole skryptów,
PHPStan, testy PHP i odwracalność migracji. Zdalny commit
8d9145763f3ebbf5a7cc41906a707fb09d612bd0 potwierdzono przez GitHub API.
Utworzono draft PR #680. Nie oznacza to przejścia CI ani wdrożenia.

Rzeczywisty zoom ustawiono przez chrome.tabs.setZoom(2), potwierdzając
getZoom=2, DPR=2 i zmianę szerokości treści z 640 do 320 oraz z 1440 do 720 px.
Oba motywy, tekst aplikacji 140%. Formularz po rzeczywistym POST odrzucał
zbyt krótki tytuł, zachowywał opis, a kliknięcie podsumowania błędów skupiało
pole tytułu. Brak poziomego overflow; środek przycisku publikacji dostępny
według elementFromPoint po przewinięciu. Wyniki: zoom372-results-*.json.

Obejrzano osiem zrzutów walidacji i przycisku w obu szerokościach i motywach.
Playwright page.screenshot zwracał samo tło również w trybie okienkowym;
poprawny obraz uzyskano przez CDP Page.captureScreenshot z fromSurface=false
w okienkowym Chromium pod Xvfb. Puste obrazy nie są dowodem odbioru.
Poprzedni timeout zamknięcia panelu nie występuje po wejściu na świeży
formularz dla każdego wariantu oraz skupieniu wybieranego selecta.
Nie rozstrzyga to osobno przyczyny wcześniejszego timeoutu.

Ograniczenie oglądu: pływający przycisk Wygląd nadal przykrywa fragmenty
tekstu pomocniczego / komunikatu walidacji w uchwyconych pozycjach przewijania.
Pole z fokusem i przycisk publikacji są odsłonięte. Wynik NIE oznacza pełnego
odbioru zasłaniania wszystkich treści, listy pytań ani szczegółu.

### Poprawka zasłaniania błędów — lokalnie, jeszcze nie w PR

Wspólny szybki-wyglad.js przenosi zwinięty widget do zwykłego przepływu,
gdy jego prostokąt przecina widoczny fragment .field-error. Działa również
przy przewijaniu myszą bez przenoszenia fokusu na błąd. Nie dotyczy to jeszcze
wszelkich tekstów pomocniczych. Powtórka rzeczywistego zoomu 200%, tekst140%,
320/720px i oba motywy przeszła wraz z nową kontrolą braku kolizji z błędami.
Obejrzano końcowy zrzut ciemny720px: cały komunikat błędu jest odsłonięty.

Fizyczna kontrola ujemna prawdziwego JS: wyłączenie warunku obscuresError,
build, ten sam scenariusz przy720px — oczekiwany FAIL na braku kolizji.
Przywrócono bajty i mtime, ponowiono build i scenariusz — PASS.
Kopia poza repo i logi: /home/mateusz/kuking-negative372/1789733656216203912.
MD5 przywróconego JS: 3cc4e6398841fa2b1905d0e02d216a7a;
mtime_ns:1789733604097062864. Build obejmuje72kontrolekontrastu i7testówJS.
Pozostaje włączenie regresji do trwałego zestawu, review wspólnego komponentu
oraz ponowny hook/CI po wysłaniu tej poprawki. Obecny zdalny PR jej nie zawiera.

Regresję utrwalono w scripts/szybki-wyglad.mjs jako sprawdzBladPodWygladem,
wywoływaną przez istniejący odbiór port-projektu. Wysyła prawdziwy błędny
formularz logowania, przewija komunikat pod widget, sprawdza odsłonięcie oraz
możliwość otwarcia panelu. Lokalnie PASS. Osobna fizyczna kontrola ujemna tej
regresji: /home/mateusz/kuking-negative372/1789733873280398889 — FAIL po
wyłączeniu warunku w JS, zgodne przywrócenie MD5 i mtime jak wyżej, ponowny
build i PASS. Nie jest to wynik całego port-projektu. Readonly review372 nie
znalazło pewnego blokera w interakcjach z fokusowaniem, przewijaniem i
otwieraniem panelu. Żaden test ani push nie trwa po zakończeniu tej próby.

## Kolejka gospodarza — rzeczywisty odbiór lokalny

Po zakończeniu hooka dla 3c93027 (PASS, zdalny SHA potwierdzony w PR680)
przygotowano odrębne lokalne konto moderatora. Hasło i formularz włączenia
2FA sprawdzono rzeczywistymi POST; kod TOTP obliczono z sekretu pokazanego
przez aplikację, bez wyłączenia middleware i bez wpisania potwierdzenia do DB.
Sesję zapisano wyłącznie poza repo, nie do dowodów.

GET /admin/bez-odpowiedzi?typ=pytania zwrócił200, właściwy nagłówek i
„Czeka na odpowiedź (2)”. Dwie karty, brak poziomego overflow przy390px.
Pytanie wcześniej obsłużone przez drugą osobę nie występowało w kolejce.
Kliknięcie „Otwórz i odpowiedz”, wpisanie odpowiedzi i rzeczywisty POST
usunęły wybrane pytanie z kolejki; liczba kart spadła2→1.
Dowód: host372-results.json oraz dwa obejrzane zrzuty host372-*.png.
Scenariusz obejmuje jedno pytanie, nie paginację ani wszystkie stany moderacji.
Zrzuty pełnej strony zawierają podpowiedź wyglądu oraz nawigację; nie są
dowodem braku zasłaniania każdego fragmentu podczas przewijania.
Nie dotykano kont ani danych produkcyjnych.

## Korekta konfiguracji pełnego odbioru

Drugi przebieg rozszerzeń potwierdził K509 (432 konfiguracje), K511 (96),
K513 (96), NOTICE (24) i zwarte kolumny (24). Zatrzymał się na strażniku
PWA: wymagany prefiks kuking_port_ z podkreśleniem. Nie jest to dowód
usterki produktu ani wynik PASS całej grupy. Zabezpieczenia pozostawiono
bez zmian. Runtime po zakończeniu miał pusty diff śledzonych źródeł.

Helper lokalny poprawiono na kuking_port_372; ponowny przebieg rozpoczęty.
Log poprzedniej próby: /home/mateusz/kuking-port372-attempt2.log.
Wynik nowej próby pozostaje nieznany.

Na poprawnej bazie kuking_port_372 scenariusz PWA przeszedł24/24, w tym
HTTP/DB/reload i cleanup; samo API instalacji jest syntetyczne. Przeszły
też24sceny paska (z reduced motion),48prób przycisku rejestracji oraz
36geometrii ustawień wyglądu i ścieżka bez JS. Końcowy rzeczywisty zoom
całej grupy nadal trwa — nie jest to jeszcze PASS całego port-projektu.


Ogląd dwóch zrzutów bieżącej nawigacji492: blad-true-true oraz
home-false-true. Podsumowanie błędu na pierwszym pozostaje czytelne, ale
pływający Wygląd przykrywa część zwykłej treści sprawy niżej. Potwierdza to
już wskazane ograniczenie widgetu, nie nową pełną akceptację zasłaniania.
Na drugim zrzucie oba przyciski dodawania są czytelne; zrzut obejmuje
jedną pozycję przewijania, nie całą ścieżkę. Kopie w lokalnym output/port372-*.


### Odbiór zdjęcia i zakończenie rozszerzeń — 18.09.2026

Na źródłach 3c93027 wykonano rzeczywistą publikację przez formularz na izolowanej bazie kuking_372_browser (55439). Zwykłe zdjęcie WEBP 1448×1086 zostało zachowane po odrzuceniu tytułu „Zupa”; formularz zachował jedno media_ids[] i poinformował o zachowaniu pliku. Po poprawieniu tytułu, bez ponownego wybierania zdjęcia, powstało pytanie 01a0b4a9-4fbd-70d6-abd6-82902cc05dec z medium 01a0b4a9-10c3-706f-a058-b1852710ac5f. Rzeczywisty worker kolejki media zakończył dwa zadania ProcessUploadedImage i zatrzymał się przy pustej kolejce. Po przewinięciu do lazy-loaded zdjęcia przeglądarka zdekodowała wariant feed: complete=true, naturalWidth=720, naturalHeight=540. Zrzut photo372-published.png obejrzany: fotografia jest widoczna. Pływająca nawigacja i podpowiedź wyglądu znajdują się na zrzucie całej strony po przewinięciu; nie jest to dowód pełnego odbioru zasłaniania. Nie sprawdzono jeszcze kompletu wariantów magazynu.

Końcowy log /home/mateusz/kuking-port372.log potwierdza PORT_CZAS grupa=rozszerzenia sekundy=1281.84, ZOOM200_OK 88 wariantów oraz PORT_OK. Wcześniejsze nieudane uruchomienia z błędnym prefiksem bazy pozostają opisane powyżej; nie są ukrywane. Jest to dowód lokalny, nie odbiór produkcyjny.

### Uzupełnienie odczytu magazynu i odpowiedzi 320 px

photo372-storage.json: stan ready; pliki odczytano z faktycznego dysku i zdekodowano: feed960×720, large1448×1086, thumb320×240, podglad640×480. Rozmiary zgadzają się z metadanymi; exif_stripped=true jest zapisaną deklaracją procesu (nie osobnym skanem EXIF). Wcześniejszy odczyt naturalWidth720 w przeglądarce nie jest wymiarem pliku feed — powyższy odczyt dotyczy bajtów magazynu.

Przy viewport320×780 opublikowano odpowiedź główną i odpowiedź zagnieżdżoną rzeczywistymi formularzami. Akcja summary „Odpowiedz” dała się nacisnąć, formularz wypełnić i wysłać; jeden zapisany akapit odpowiedzi potwierdzono po POST. Brak overflow poziomego. Obejrzany answer372-320.png pokazuje ciasny układ przy dużym tekście i obecność stałych pasków. Tekst głównej odpowiedzi odsłonięto przewijaniem (elementFromPoint w środku trafia w akapit). Nie stanowi to globalnego pomiaru wszystkich kontrolek. Dwa błędy pomocników były błędami lokalizatorów: summary nie występuje jako button w tym silniku, a getByText wskazał jednocześnie akapit i textarea edycji. Zawężenie lokalizatorów potwierdziło działanie; nie powtarzano udanego POST.

### Paginacja i niezależny review końcowy

18.09, rzeczywista przeglądarka, lokalne /pytania?filtr=bez-odpowiedzi: pierwsza strona15pytań, kliknięcie „Pokaż więcej pytań” prowadzi do4kolejnych. Brak wspólnych identyfikatorów między stronami; filtr i cursor zachowane. Scena zawiera18jawnie testowych pytań utworzonych wyłącznie w kuking_372_browser; nie dodawano treści na produkcji. Pierwszy pomocnik nie zapisał wyniku, ponieważ URL nie istnieje w jego sandboxie; ponowny odczyt przez zwykłe linki potwierdził rezultat.

Niezależny agent review372_final odczytał diff55877e2..3c93027, testy i raport: nie znalazł nowego blokera merge w widoczności, blokadach, flagach, licznikach/QAPage, zachowaniu dzieci usuniętej odpowiedzi, strażniku rollbacku ani formularzach. Nie wykonywał testów ani przeglądarki. To review statyczne, nie niezależne powtórzenie moich wyników. Dalsze stany moderacji w przeglądarce i etapowe uruchomienie pozostają niepotwierdzone.

## Uzupełnienie odbioru — 20 września 2026

Na bazie `4c811cc7` ponownie wykonano testy istniejącej mechaniki, następnie
uzupełniono widoczne wejście „Czeka na odpowiedź (N)” na `/pytania` oraz akcję
odpowiedzi na kartach bez odpowiedzi. Publiczna lista zachowuje chronologię
malejącą; najstarsze pytania na początku kolejki gospodarza to kolejność
obsługi próśb o pomoc, nie ranking feedu. Licznik respektuje widoczność i tag.

Usunięto też odtworzone obejście flagi we Wspomnieniach (#881), zachowując
własne prywatne zwykłe wpisy. Nie podjęto decyzji produktowej o pytaniach
jako wspomnieniach przy włączonym dziale.

Własne pomiary, czerwienie przed poprawką, kontrole ujemne, 4397 zielonych
testów, 24 konfiguracje przeglądarki i granice dowodu są w
[raporcie stanowiska](../qa/pytania-poradzcie/RAPORT.md).
To nadal nie jest odbiór produkcyjny ani włączenie funkcji.
