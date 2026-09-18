# Poradźcie — odbiór roboczy #372

Status: **W TRAKCIE**, lokalna gałąź `feat/372-poradzcie`, baza kodu
`55877e2b5c0aff04d93e6db75f079c7e61d4df5d`. Ten raport nie potwierdza
wdrożenia funkcji ani zakończenia portu marki.

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

- Przeglądarkowy odbiór odpowiedzi innej osoby, powiadomień i kolejki gospodarza;
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
