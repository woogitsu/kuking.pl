# Stan sesji, część 2 — raporty stanowisk z 20 września 2026 (wieczór)

To jest ciąg dalszy `STAN_SESJI.md` — kolejne sześć raportów stanowisk zapisanych osobno, żeby nie przepadły.

## gpt/tagi-miejsce — #647, #369, #370, #681

Gałąź `gpt/tagi-miejsce`, czyste drzewo przy `4c811cc7bff365fb8f86d87eabac93b7738a45cd`. Zgłoszenia #647, #369, #370 i #681 odczytano wraz z komentarzami przez `gh issue view --repo woogitsu/kuking.pl`.

Pomiar własny przed zmianami: filtr `Tag|Tagi|Podpowiedzi|QuestionForm|PostForm` na PostgreSQL: 280 testów, 50 122 asercje, PASS (33,81 s). Wynik zapisany w historii narzędzi sesji; nie zachowano osobnego surowego pliku tego przebiegu. Podpowiedzi po `#`, rzeczywiste liczniki, statystyki, kolaż, akcje i fotograficzny katalog A–Z już istniały — nie wdrożono ich drugi raz.

Odczytano z gałęzi `gpt/tagi-filtr` raport `docs/research/TAGI_FILTR_2026-09-20.md` i dowody w `docs/research/tagi-filtr-2026-09-20/`. **[pomiar cudzy: ten raport]** opisuje filtr, porcję 20 i doładowanie obserwowanych tagów bez JavaScriptu. Tych plików ani ekranu obserwowanych tagów nie zmieniono; gałęzi nie scalano. **PRZEKAZANIE DLA INNEJ GAŁĘZI:** ekran obserwowanych tagów pozostaje w gestii `gpt/tagi-filtr`.

### #647 — wykonany wąski zakres

Ręcznie przypięte tagi, także wielowyrazowe, przeniesiono bezpośrednio pod opis w tworzeniu i edycji wpisu oraz pytania. Wcześniej lista była oddzielona kolejnymi polami. Zachowano limity 5/3, usuwanie zwykłym POST, odtwarzanie wpisanej treści i zamkniętą domyślnie wyszukiwarkę zapasową. Podsumowanie „Dodaj tag bezpośrednio” ma krótszą nazwę; komunikaty awarii/braku podpowiedzi wskazują dokładnie tę drogę. Nie zmieniono mechanizmu liczenia ani parsera.

Dowody własne:

- `647-czerwien.txt`: nowy `TagiPrzyOpisieTest` przed poprawką — 4 porażki, 16 asercji, lista poza obszarem opisu. Test czyta rzeczywiste cztery formularze.
- `647-zielen.txt`: po poprawce — 66 testów, 373 asercje, PASS.
- `pelne-testy.txt`: 4397 testów, 83 736 asercji, PASS, 509,76 s. Pominięto wyłącznie `ProbaOdtworzeniaTest`, zgodnie z ostrzeżeniem o wspólnej bazie źródłowej. Nie zgłoszono wykonania testu pominiętego.
- `647-przegladarka.txt`: klawiatura (strzałka/Enter) i kliknięcie wybierają podpowiedź bez wysłania formularza. 24 układy: szerokości 320, 360, 390, 414, 768, 1440, dwa motywy, tekst 100/140%. Brak przewijania w bok, lista mieści się w oknie, opcje ≥48 px, tekst pola ≥18 px.
- `647-bez-js.txt`: w edycji bez JavaScriptu usunięcie, wyszukanie i dodanie wielowyrazowego tagu zachowuje zmieniony opis; przycisk usuwania 73 px.
- Obejrzano zrzuty małego ekranu z podpowiedzią i formularza bez skryptu.
- `npm run build`: PASS (72 pary kontrastu, 20 testów JS, build Vite). To kontrola istniejącego zestawu, nie nowy audyt wszystkich kontrastów.

Testy: osobna baza `kuking_flota_gpt-tagi-miejsce`. Przeglądarka: `kuking_flota_gpt_tagi_miejsce_a11y`, właściciel `kuking`, host `127.0.0.1`, port **55439**. Runtime `/home/mateusz/flota/gpt-tagi-miejsce-run`, aplikacja lokalna na porcie 8647. `fixture.php` odmawia innej bazy i niepustych danych. Zdjęcia pomiarowe są syntetyczne, nie są treścią do publikacji.

### #369 — pomiar i decyzja właściciela

`369-pomiar.txt`: anonimowy odbiorca bez JavaScriptu mógł odczytać autorów z publicznych kart i paginacji:

| Osoby w fixture | Rozpoznawalni autorzy publicznych kart | Strony | Statystyka |
|---|---|---|---|
| 2 | 2 | 1 | zaproszenie zamiast liczby |
| 3 | 3 | 1 | 5 zdjęć od 3 osób |
| 5 | 5 | 1 | 5 zdjęć od 5 osób |
| 10 | 10 | 1 | 10 zdjęć od 10 osób |
| 42 | 42 | 3 | 42 zdjęcia od 42 osób |

Sam próg nie anonimizuje autorów, których publiczne wpisy nadal pokazujemy. Nie udawano, że ten eksperyment wyznaczył uniwersalną bezpieczną liczbę.

**DECYZJA WŁAŚCICIELA:** po otrzymaniu wyniku właściciel jawnie wybrał: zachować 5 zdjęć / 3 osoby wyłącznie jako próg prezentacji publicznej aktywności, bez obietnicy anonimowości. Zapisano to w `docs/DECISIONS.md`. Kod i dotychczasowe testy progów pozostają; nie dodano testu rzekomej anonimowości.

### #370 i #681 — istniejące zachowanie, bez przebudowy

`370-681-przegladarka.txt`: lokalnie, jako gość bez JavaScriptu, 16 układów na stronie tagu i w katalogu (320/390/768/1440 px, tekst 100/140%), bez przewijania w bok. Kolaż i fotograficzne karty obecne w DOM. Kliknięcie kafla otwiera tag, „Dodaj wpis z tym tagiem” prowadzi gościa do logowania. Testy istniejących mechanizmów objęto pomiarem bazowym i pełnym przebiegiem. Nie nazwano liczby elementów `img` dowodem jakości prawdziwych fotografii.

### Granice, wycofanie i dalszy odbiór

Nie wykonano push, PR, wdrożenia, zmian schematu, danych produkcji ani wysyłki wiadomości. Nie przeprowadzono odbioru na rzeczywistym publicznym zbiorze, telefonie z klawiaturą ekranową/IME ani rzeczywistego zoomu przeglądarki 200%. Skala tekstu 140% nie zastępuje takiego zoomu. Nie zamykano zgłoszeń na GitHubie. Prace nad szeroką przebudową #370/#681 nie są uzasadnione wynikiem tego pomiaru; pozostaje odbiór produkcyjny i ewentualny kolejny, konkretnie wskazany zakres. Historyczne uwagi z dyskusji nie są automatycznie uznane za usterki dzisiejszego kodu.

Decyzja wymagana do tego zakresu (#369) została podjęta. Dalsze rozszerzenie prywatności publicznego autorstwa byłoby oddzielną decyzją produktową. Wycofanie formularzy: revert lokalnego commita; brak migracji i zmiany danych.

Kontrola końcowa: `vendor/bin/pint` PASS (1156 plików runtime i osobno fixture), `koncowe-testy.txt` PASS (17 testów, 191 asercji) po usunięciu nadmiarowych pustych wierszy w widokach. Surowe wyniki tekstowe mają ujednolicone kodowanie UTF-8 i usunięte końcowe spacje. Zrzuty: `647-popup-320-dark140.png` oraz `647-nojs-320.png` (długi zrzut zawiera stałą dolną nawigację).

## gpt/ugotowalem-dostep — #902, #903, #910

Gałąź `gpt/ugotowalem-dostep`, baza źródła `4c811cc7bff365fb8f86d87eabac93b7738a45cd`. Pomiary własne: 20 września 2026, lokalny runtime WSL, PostgreSQL `127.0.0.1:55439`, baza `kuking_flota_gpt-ugotowalem-dostep`, właściciel połączenia `kuking`. Bez zmian produkcji.

### Pomiar przed poprawką

Na nietkniętym kodzie aplikacji istniejące `CommentEditTest` i `CookingModeTest`: 22 testy, 77 asercji, wszystkie poprawne. Nowe regresje, uruchomione PRZED zmianą implementacji:

- `UgotowalemDostepTest`: 2 porażki. Oba ekrany zwracają 200 i pokazują link do formularza, choć bezpośredni GET formularza zawieszonego konta daje 403. Gość nie ma linku, aktywne konto ma link.
- `OdzyskaniePoprawkiKomentarzaTest`: 4 porażki odzyskiwania tekstu, kontrola cudzej/usuniętej treści przechodzi. Komentarz i odpowiedź, bezpośrednio po 900 sekundach oraz błąd walidacji po 899 sekundach z GET-em po 900 sekundach.
- `GotowanieOdPoczatkuTest`: brak przycisku resetu przy odhaczeniu; POST na proponowaną trasę zwraca 404. Odhaczanie i zachowanie postępu działają.

To są własne żądania przez aplikację i PostgreSQL, nie przejęte wyniki z issues. Opisy źródłowe #902/#903 deklarowały brak pomiaru HTTP; #910 zawierało jedynie **[pomiar cudzy: issue #910]** granicę Policy 899/900/901 s z atrapami modeli. Wyników tych nie użyto jako dowodu HTTP.

### Wprowadzone zachowanie

**#902:** oba zaproszenia do „Ugotowałem” pytają `RecipePolicy::cook`, tak jak formularz. Autoryzacja serwera nie została osłabiona. Gość zachowuje zaproszenie do konta, zawieszona osoba nadal czyta przepis i kroki.

**#910:** odmowa zapisu po czasie pozostaje statusem HTTP 403, lecz własna poprawka wraca w czytelnym polu tylko do odczytu. Instrukcja mówi, żeby skopiować tekst, zachować go lub wkleić do nowego komentarza. Nic nie publikuje się automatycznie. Blade escapuje tekst, w tym znaczniki zamykające textarea. Oddzielna zdolność `CommentPolicy::recoverExpiredEdit` sprawdza autorstwo, aktywność konta, widoczność rodzica, stan komentarza i upływ czasu. Nie jest zgodą na zapis. Po walidacji identyfikator autoryzowanego komentarza jest przekazywany jednorazowo w sesji, a tekst pochodzi z `old('body')`. Dzięki temu wygaśnięcie czasu przed kolejnym GET-em nie chowa ostatniej poprawki razem z formularzem edycji. Blok jest nad paginowanym wątkiem, nie wewnątrz pętli.

**#903 — DECYZJA WŁAŚCICIELA:** właściciel jawnie zatwierdził w tej rozmowie proponowane zachowanie. Przycisk jest widoczny przy istniejących odhaczeniach. Natywne `details` pokazuje pytanie, „Zostaw odhaczenia” oraz osobny przycisk potwierdzenia. POST z CSRF, limitem `cooking_krok` i `RecipePolicy::view` czyści jeden klucz sesji i wraca na pierwszy krok. GET nie resetuje. Nie ma migracji. Zawieszone konto nie dostaje nowego przycisku resetu, ponieważ obecna globalna blokada zapisu odrzuciłaby tę akcję. Rozstrzygnięcie, czy zawieszenie powinno obejmować prywatny postęp gotowania, pozostaje osobnym pytaniem (patrz niżej).

### Osobne znalezisko: szerszy wzorzec zawieszenia

Próbnik `PomiarZawieszenia902Test.php` jest zachowany obok raportu, poza standardowym zestawem regresji: dokumentuje wadę, nie utrwala jej jako kontraktu.

| Akcja | Widok | Odmowa |
|---|---|---|
| Wyślij komentarz pod wpisem | 200, przycisk widoczny | `PostPolicy::comment` false; zapis zatrzymuje globalna blokada konta |
| Obserwuj na profilu aktywnej osoby | 200, formularz widoczny | `UserPolicy::follow` false; zapis zatrzymuje globalna blokada konta |
| Zapisuję na stronie przepisu | 200, formularz widoczny | POST 302 z błędem sesji `konto` |
| Oznacz krok jako zrobiony | 200, formularz widoczny | POST 302 z błędem sesji `konto` |

Odczyt kodu wskazuje ponadto nieosłonięte `follow` w liście znajomości i komponencie tablicy osób. Tych dwóch miejsc nie zaliczono do pomiaru HTTP. Nie jest to kompletny audyt wszystkich ekranów ustawień i publikacji. Nie poprawiono tych ścieżek w ramach #902.

**PRZEKAZANIE DLA WŁAŚCICIELA — warianty do decyzji:**

1. Ukryć pozostałe niedozwolone akcje, pozostawiając obecną blokadę zapisu. Koszt: zmiany wskazanych widoków i macierze aktywne/zawieszone/gość.
2. Osobno dopuścić prywatne czynności zawieszonej osoby (zeszyt, odhaczenia, reset), zachowując zakaz publikacji i obserwowania. Koszt większy: jawna decyzja o zakresie kary, zmiana wyjątków middleware, testy każdej trasy.

### Współdzielony plik i wycofanie

Przed zmianą przeczytano commity gałęzi `flota/gotowanie`: `62e9b777`, `c1421f82`, `593a7ab9`, `fc026237`, `47c89c11`, `77b6d71a`. Nie przenoszono ich do tej gałęzi. Wspólny plik to `resources/views/pages/recipes/cooking.blade.php`; nasze zmiany dotyczą końca widoku i zaproszenia „Ugotowałem”, a tamte składników i minutnika. Nie zmieniano JavaScriptu, Wake Locka, minutnika ani identyfikatorów kroków.

**PUŁAPKA SCALANIA:** wycofanie — odwrócić lokalne commity tego zadania w kolejce integracyjnej. Nie ma migracji ani trwałych zmian schematu. Odhaczeń już świadomie usuniętych przez użytkownika nie da się odtworzyć przez cofnięcie kodu. Wersja 0.68 jest lokalnym podbiciem z D-134; przy integracji wielu gałęzi koordynator musi rozstrzygnąć ewentualną kolizję numeracji.

### Granice

Nie wykonano pushu, nie otwarto PR-a, nie uruchomiono zdalnego CI ani nie zmieniono danych produkcyjnych. `ProbaOdtworzeniaTest` pominięto zgodnie z jawnym wyjątkiem właściciela dotyczącym współdzielonej bazy próby.

### Weryfikacja końcowa (wszystkie pomiary własne)

- Końcowy wspólny przebieg sześciu klas (trzy nowe regresje, `CommentEditTest`, `CookingModeTest`, `KazdaTrasaZIdentyfikatoremPodPolicyTest`): **44 testy, 896 asercji, PASS** na PostgreSQL 55439.
- Pełny zestaw z pominięciem wyłącznie `ProbaOdtworzeniaTest`: 4402 PASS, 1 FAIL, 83 767 asercji. Porażka dotyczyła nowej trasy `cooking.restart`, której brakowało w macierzy autoryzacji. Rejestr uzupełniono; jego wszystkie 9 testów przechodzi także w końcowym przebiegu. Po tej zmianie testów nie powtarzano całego zestawu. Nie zadeklarowano pełnego zielonego przebiegu po ostatniej zmianie.
- Cztery kontrole mutacyjne: oba zaproszenia #902, tekst #910, reset #903. Każda przeszła PASS → FAIL z oczekiwaną przyczyną → PASS. Pliki JSON są obok raportu. Narzędzie zapisuje JSON przed końcowym trapem, dlatego pole `przywrocenie` zawiera „nie wykonane”; terminal potwierdził następnie zgodność MD5 i mtime po przywróceniu. Nie poprawiano ręcznie tych JSON-ów.
- Próbnik szerszego zawieszenia: PASS, 22 asercje dokumentujące cztery niedostępne akcje. To pomiar obecnej usterki, nie test docelowego kontraktu.
- `vendor/bin/pint`: PASS, wszystkie 11 zmienionych/dodanych plików PHP.
- `npm run build`: PASS, w tym 20 testów Node i 72 sprawdzenia kontrastu.
- Ogląd lokalnego HTML otrzymanego z rzeczywistych odpowiedzi aplikacji: ekran odzyskiwania przy 320 px, bez JavaScriptu, z czytelnym polem tekstu. To statyczny ogląd z prawdziwym CSS, nie pełny przepływ E2E. Automatyzacja interakcji przeglądarki przekroczyła limit czasu; nie zaliczono kopiowania klawiaturą, otwarcia potwierdzenia resetu ani powiększenia 200%. Zachowanie resetu i anulowania zmierzono żądaniami HTTP w testach.

Oprzyrządowanie: wrapper kontroli ujemnej czyści skompilowane Blade przed pomiarem (przywrócenie mtime może pozostawić skompilowaną mutację) i ogranicza wydruk błędu. Omija to zaobserwowany SIGPIPE w obecnym skrypcie kontroli, który używa `printf | grep -q` pod `pipefail`. Nie zmieniano wspólnego skryptu.

## gpt/ustawienia-profilu — #801, #802, #803, #804

Stanowisko `gpt/ustawienia-profilu`, baza kodu `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, 20 września 2026. Wszystkie opisane niżej przebiegi wykonano lokalnie. Nie badano produkcji.

### #803 — formularz pamięta zdjęcie

Potwierdzenie usunięcia przesyła `avatar_media_id` z GET formularza przez slot istniejącego `confirm-button`. Kontroler nadal autoryzuje aktualny profil zalogowanej osoby. Identyfikator z formularza jest wyłącznie warunkiem zgodności; nie służy do wyszukiwania dowolnego cudzego zdjęcia. Brak pola, tablica lub inne ID nie powodują kasowania. Istniejący `PrzypnijAwatar::odepnij()` nadal wykonuje atomowy warunkowy UPDATE.

Przeszukano formularze ustawień i akcje aplikacji na tej bazie kodu: nie znaleziono gotowego mechanizmu przekazującego oczekiwany awatar. Wykorzystano istniejące odpięcie zamiast tworzyć drugie.

Pomiar przed poprawką: 15 istniejących testów zdjęcia przechodziło. Nowy test GET zdjęcia A → podmiana na B → POST pól starego formularza oblał asercję przypięcia B: w bazie było NULL. Próba bez ID także oblała. Świeży formularz usuwał poprawnie. Po poprawce: 3 testy / 73 asercje, z kontrolą profilu, wierszy oraz oryginałów i wariantów obu zdjęć. Istniejące 8 testów `CzteryDrogiZdjeciaPodBlokadaTest` / 30 asercji przeszło. To sekwencja HTTP oraz test starego modelu domenowego, nie pomiar dwóch równoległych połączeń PostgreSQL.

### #802 — wspólna odpowiedź dla zdjęcia, opisu i przycisku

Skrót w ustawieniach profilu używa `Profile::zdjecieDoPokazania()`, tak jak awatar. Stan przygotowania korzysta z istniejącej metody profilu. Nie zmieniono bramki serwowania zdjęć ani bezpieczeństwa oryginałów.

Pomiar własny: przed poprawką 3 z 6 przypadków oblewały, po poprawce 6 przeszło / 32 asercje. Test renderuje konkretną sekcję ustawień, tworzy plik WebP wariantu i rozróżnia pending z podglądem, ready, pending bez wariantu, ready bez wariantu, deleted oraz brak zdjęcia.

Osobno odtworzono `ready` z zachowanymi metadanymi i brakującymi plikami: test oblał się na obietnicy „Twoje zdjęcie widać”. Opis mówi teraz o możliwości zmiany lub usunięcia zdjęcia, bez zapewnienia o dostarczeniu bajtów. Brak wariantu nie daje też obietnicy, że zadanie nadal pracuje: tekst kieruje do ustawień zdjęcia. Ostatecznie 7 testów / 40 asercji. Istniejąca metoda rozpoznaje wariant po metadanych, bez odczytu magazynu; nie dodano kosztu sprawdzania pliku do każdego awatara. Odzyskiwanie utraconych plików nie jest częścią tej zmiany.

### #804 — niepotwierdzony zapis zatrzymuje oczekujące przejście

**DECYZJA WŁAŚCICIELA:** pozostać na stronie, pokazać błąd, pozwolić przejść po ponownym kliknięciu linku. Przenoszenie komunikatu na docelową stronę wymagałoby dodatkowego mechanizmu między dokumentami; nie zostało wybrane.

`save()` zwraca wynik. Oczekujący link przechodzi tylko po sukcesie. Porażka otwiera panel, przywraca lokalny podgląd i mówi o braku potwierdzenia, ponieważ utrata odpowiedzi nie dowodzi cofnięcia zapisu na serwerze. Nie wysyłamy automatycznej kompensacji starego ustawienia. Istniejący limit oczekiwania wynosi 10 sekund.

Pomiar Chromium wykonuje pełny moduł produkcyjny na kontrolowanym DOM: sukces, HTTP 500, offline, rzeczywisty timeout i błędny JSON. Przed poprawką sukces przeszedł, cztery błędy powodowały przejście do celu. Po poprawce wszystkie pięć przeszło. Sprawdzono widoczny komunikat, przywrócenie skali, odblokowanie kontrolki i ponowne przejście linkiem.

Dodatkowy pomiar własny: lokalny Laravel, PostgreSQL `127.0.0.1:55439`, baza `kuking_flota_gpt-ustawienia-profilu`, prawdziwe logowanie przez formularz i zbudowane assety. Przechwycenie odpowiedzi nastąpiło dopiero po wykonaniu POST na backendzie (200), potem przerwano jej dostarczenie do strony. Bieżący dokument pozostał na ustawieniach ze skalą 100 i widocznym komunikatem. Ponowne kliknięcie otworzyło profil ze skalą 125 odczytaną z konta. Potwierdza to rozdzielenie lokalnego podglądu od zapisu serwera. Zrzut sprawdzony wzrokowo: `evidence/ustawienia-profilu/804-brak-potwierdzenia.png`.

### #801 — instrukcja dotykowa wymaga odbioru na telefonie

Tekst awaryjny wskazuje przytrzymanie adresu palcem i polecenie „Kopiuj” z menu zaznaczenia, zachowując Ctrl+C / Cmd+C. Nie twierdzi, że aplikacja sama otworzyła menu systemowe. Pole i mechanizm kopiowania są bez zmian.

Pomiar własny wykonuje rzeczywisty handler wycięty z `app.js`, z kontrolą granic wycinka i adapterami schowka: udany schowek, udana stara metoda, odmowa obu. Przed poprawką 2 przeszły, instrukcja dotykowa oblała; po poprawce wszystkie 3 przeszły.

**Zastrzeżenie granicy pomiaru:** „Nie wykonano pomiaru na fizycznym Androidzie ani iPhonie, ani odbioru TalkBack / VoiceOver. #801 nie jest gotowe do zamknięcia.” Tekst jest kandydatem do odbioru; emulacja dotyku nie zastępuje menu systemowego.

### Kontrole i wycofanie

Dla każdego z czterech zgłoszeń wykonano kontrolę ujemną przez `scripts/kontrola-ujemna.sh`: PASS → celowa zmiana → oczekiwana porażka → PASS. Przywrócenie porównało MD5 i mtime. Dla Blade przed każdym przebiegiem czyszczono skompilowane widoki: przywrócenie starego mtime może pozostawić w pamięci podręcznej widok z mutacją.

Wyniki przyrządu: `evidence/ustawienia-profilu/803.json`, `802.json`, `804.json`, `801.json`. Uwaga o formacie przyrządu: pole `przywrocenie` w JSON jest zapisywane przed końcowym `trap` i pozostaje „nie wykonane”. Końcowe wyjście przyrządu potwierdziło porównanie MD5 i mtime; `kontrola_dodatnia_po_przywroceniu` we wszystkich czterech plikach wynosi PASS. Nie podmieniono tego pola ręcznie w dowodach.

Testy JS: `npm run test:ustawienia-profilu`; podłączone do zadania assetów CI po instalacji Chromium. Testy PHP są częścią zwykłego zestawu. Pint przeszedł na 1157 plikach; `npm run build` przeszedł.

Pełny zestaw PHP: **4402 testy / 83 797 asercji**, 436,71 s. Pominięto `ProbaOdtworzeniaTest` zgodnie z instrukcją właściciela (wspólna baza testu odtwarzania). Ten przebieg poprzedza ostatnie doprecyzowanie tekstu #802; po nim wykonano ponownie cały test tej sekcji i kontrolę ujemną.

Brak zmian schematu i migracji. Wycofanie przez `git revert` odpowiedniego commita. **PUŁAPKA SCALANIA:** cofnięcie #803 przywróci możliwość usunięcia nowszego zdjęcia starym formularzem, więc nie jest zalecanym sposobem naprawiania problemów wdrożenia. Stare otwarte formularze bez ID celowo odmawiają usunięcia.

Nie wykonano push, PR, wdrożenia ani zmiany produkcyjnych danych.

## gpt/zeszyt-droga — #904, #905, #906, #907, #908

Gałąź: `gpt/zeszyt-droga`, baza: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`. Kod zapisany lokalnie:

- `f9a3d41dcb7432b9a3d53cf967b1f91890b7bde3` — #907.
- `4b624936a29f70158fe0257684a2333cafbc0ccb` — #904, #905, #908 oraz dokument decyzji #906.

Przed poprawkami kod produkcyjny był nietknięty. Testy regresyjne dodawano najpierw, obserwowano czerwień, dopiero potem zmieniano zachowanie.

| Zgłoszenie | Pomiar przed poprawką | Wynik po poprawce |
|---|---|---|
| #907 | Prawdziwy INSERT powiadomienia odrzucony przez tymczasowy CHECK PostgreSQL: HTTP 500, zapis zostaje, ponowienie daje 0 wiadomości. | Wspólna transakcja wycofuje oba skutki. Po błędzie 0 zapisów, po ponowieniu 1 zapis i 1 wiadomość, kolejne ponowienie bez duplikatu. |
| #908 | Wyjęcie 13. wpisu na stronie 2 pokazuje pusty zeszyt mimo 12 pozostałych. | Przekierowanie na ostatnią istniejącą stronę, osobno dla przepisów i wpisów; komunikat pozostaje. Nierówne listy i naprawdę pusty zeszyt też sprawdzone. |
| #905 | Odnośnik tworzenia zeszytu nie przenosi celu zapisu; test oblewa na brakującym save_id. | Rzeczywisty odnośnik, formularz tworzenia i przekierowanie prowadzą do jawnego dokończenia zapisu. Testy obejmują przepis i wpis, walidację, dwie karty, prywatność, usuniętą treść i cudzy zeszyt. |
| #904 | Pytanie w formularzu usunięcia nie zawiera nazwy obiektu. | Pełna nazwa przepisu/zeszytu, poprawne escapowanie HTML, zachowane potwierdzenie i CSRF. |

#907 nie wymaga nowej kolejki: oba skutki są zapisami w tej samej bazie. Wspólna transakcja i istniejąca unikalna para zeszyt/przepis stanowią granicę zatwierdzenia oraz znacznik ukończenia. Savepoint chroni transakcję zewnętrzną przy konflikcie unikalności. Nie ma zmiany schematu.

### Kontrole

Wszystko poniżej wykonano samodzielnie, lokalnie:

- PostgreSQL `127.0.0.1:55439`, baza `kuking_flota_gpt-zeszyt-droga`, rola `kuking`.
- Pełny przebieg: **4401 PASS, 83 806 asercji, 391,72 s**, log `zeszyt-testy.txt`. Wyłączono wyłącznie `ProbaOdtworzeniaTest`, zgodnie z poleceniem właściciela dotyczącym wspólnej bazy odtwarzania. Polecenie: `pelne-testy.sh`.
- Podzbiór `--filter Zeszyt`: **126 PASS, 1259 asercji**.
- Pięć kontroli ujemnych: #907, #908, #905 i oba formularze #904. Każda: zielony → rzeczywista mutacja → czerwony z oczekiwaną przyczyną → zielony. `zeszyt-kontrole.json` potwierdza przywrócenie bajtów i mtime po zakończeniu skryptu. Pole `przywrocenie` w pojedynczych JSON pochodzi sprzed jego trap EXIT; rozstrzygający pomiar po zakończeniu procesu wykonał wrapper Python.
- Pint: **PASS, 1161 plików**, `zeszyt-pint.txt`; wcześniej wykonano formatowanie.
- PHPStan: **No errors**, `zeszyt-phpstan.txt`.
- `npm run build`: PASS (kontrola kontrastu 72 par, testy JS i Vite), `zeszyt-assets.txt`.
- `git diff --check`: PASS przed commitami.

Pierwszy pełny przebieg miał 9 porażek z powodu ustawienia `MAIL_MAILER=log`, które nadpisywało testowy transport `array`. To błąd uruchomienia, nie „zastane testy”: po poprawieniu transportu osobno wykonano pięć dotkniętych klas (41 PASS, 259 asercji, `transport-poprawiony.txt`), a potem cały zestaw ponownie z wynikiem podanym wyżej.

### Przeglądarka — pomiar własny

Chromium przez Playwright, lokalny serwer 127.0.0.1:8197. Osobna baza `kuking_flota_gpt-zeszyt-droga_ui`, ten sam jawny port 55439; żadnych danych produkcyjnych. Zalogowano syntetyczną osobę przez formularz.

Przy szerokości 320 px kliknięto: przepis → Wybierz zeszyt → Załóż nowy zeszyt → wpisanie nazwy → Załóż zeszyt → Dokończ zapis → Zapisuję w tym zeszycie. Ostatni adres prowadzi do zeszytu bez parametrów kontynuacji, ze zapisanym przepisem. Potwierdzenia przepisu i zeszytu pokazują pełne nazwy ze znakami `"A" & <b>zupa</b>` jako tekst. Długie ciągi zawijają się.

Ekrany dokończenia i usuwania: scrollWidth=320 przy viewport=320. Dodatkowo pomiar CSS zoom=2 przy viewport=640: scrollWidth=640, pytanie ma 18 px przed powiększeniem, przycisk 101 px po powiększeniu, brak dzieci HTML wewnątrz pytania (`browser-pomiar.txt`). **Zastrzeżenie granicy pomiaru:** to CSS zoom, nie pomiar natywnego powiększenia przeglądarki. Zrzuty w `playwright/`. Pierwszy zrzut kontynuacji zawiera zastaną podpowiedź wyglądu; zamknięto ją przy dalszym oglądzie. Nie wykonano pełnego audytu WCAG ani testu czytnika ekranu.

### #906 — decyzja właściciela (otwarta)

Jednorazowy próbnik SQL, bez asercji ustalających regułę produktu: `pomiar-906.php`, wynik `pomiar-906.json`, całość wycofana transakcją.

| Krok | Aktualne zapisy | Wiadomości łącznie |
|---|---:|---:|
| Zapis w A | 1 | 1 |
| Ponowienie A | 1 | 1 |
| Zapis w B | 2 | 2 |
| Wyjęcie z A, pozostaje B | 1 | 2 |
| Ponowny zapis w A | 2 | 3 |
| Wyjęcie ze wszystkich | 0 | 3 |
| Zapis w A po dwóch dniach | 1 | 4 |

Warianty i koszty: `docs/design/DECYZJA_POWIADOMIEN_ZESZYT_906.md`. A: każde nowe powiązanie (najniższy koszt, identyczne wiadomości). B: pierwszy aktualny zapis osoby (średni koszt, blokada i kontrola wyścigów). C: pierwszy zapis w historii (wyższy koszt, trwały znacznik i migracja). D: okno czasu (średni koszt, wybór okresu i atomowa deduplikacja). B jest propozycją do rozważenia, nie przyjętą decyzją.

**PRZEKAZANIE DLA WŁAŚCICIELA:** decyzja o wariancie (A/B/C/D) pozostaje do podjęcia. Właściciel musi też rozstrzygnąć ponowne zapisanie po wyjęciu ze wszystkich zeszytów.

### Źródła, granice i wycofanie

Odczytano `e89f28a5` i `gpt/eksport:output/EKSPORT-819-825-RAPORT.md` oraz rozwiązanie #748 (`643ba106814fb02e771a89b509b7800556611a93`). To źródła wzorca implementacji; **nie przejęto ich wyników testów jako własnych**. Wszystkie liczby w tym raporcie są pomiarami tej sesji.

Nie pushowano, nie otwierano PR ani nie zamykano issues — przekazanie przez kolejkę właściciela. Nie zmieniano produkcji, #774–#777 ani reguł liczników. Nie wykonywano pełnego `scripts/check.sh`; jego wykonanych części nie mylimy z zaliczeniem całego skryptu. Brak migracji oznacza brak nowego rollbacku bazy.

**PUŁAPKA SCALANIA:** wycofanie kodu przez revert dwóch commitów przywróci także opisane usterki.

Nie naprawiano historycznych zapisów bez wiadomości: brak powiadomienia może wynikać z retencji albo reguł odbiorcy, więc nie identyfikuje awarii. Taka naprawa wymaga osobnego audytu i decyzji. Mechanizm #907 chroni nowe próby. Natywny zoom 200%, produkcja i pełny audyt dostępności pozostają niezmierzone.

## gpt/zdjecia-limity — #883, #884, #891

Zakres: #883, #884 i #891. Stan początkowy: czyste drzewo `gpt/zdjecia-limity`, `4c811cc7bff365fb8f86d87eabac93b7738a45cd`.

### Zależność i kolejka

Przed edycją przeczytano `ZDJECIA_PUBLIKACJA_2026-09-20.md` z gałęzi `gpt/zdjecia-publikacja`. Tych zmian nie było jeszcze w podanej bazie. Lokalny commit `b03621e7` jest cherry-pickiem `6718d527`: zachowanie zdjęć, idempotencja i kotwice błędów z #871–#874. Nie kopiowano całej cudzej gałęzi ani nie cofano nowszych zmian bazy.

**PUŁAPKA SCALANIA:** przy przenoszeniu do kolejki najpierw musi znaleźć się implementacja #871–#874; jeśli już jest na main, nie trzeba ponownie przenosić `b03621e7`.

**[pomiar cudzy: `gpt/zdjecia-publikacja:docs/research/ZDJECIA_PUBLIKACJA_2026-09-20.md`]** Raport poprzedniego stanowiska opisuje 4428 testów i pomiary kosztu ponowień. Nie są to wyniki tej pracy. Własne ponowne uruchomienie jego regresji opisano niżej.

### Pomiar przed poprawką — własny

Przed zmianą kodu aplikacji uruchomiono nowe regresje na bazie PostgreSQL. Po poprawieniu błędu w przygotowaniu fixture (fabryka przepisu nie ma metody `published()`) uzyskano 3 oczekiwane porażki: dwa limity (1 i 6) oraz odrzucony awatar bez wariantów; 7 kontroli przeszło. Następnie rozszerzono macierz na osobne przypadki dla obu formularzy i walidację serwera.

Rzeczywisty Chromium wykonał istniejący handler podglądu na HTML formularza wyrenderowanym przez kernel HTTP: wybór A/B/C dał trzy obrazy i **zero** przycisków usuwania. Asercja oczekująca trzech przycisków oblała się przed implementacją. Nie był to model FileList w Node ani sam odczyt kodu.

### Zmiana zachowania

- Oba formularze pokazują „Łącznie najwyżej …”, rozmiar pojedynczego pliku i informację, że zachowane zdjęcia wliczają się do limitu. Liczby pochodzą z `LimityZdjec`, a odmiana z istniejącego `Odmiana`. Pomoc pozostaje powiązana przez `aria-describedby`, razem z błędami poprzedniej gałęzi.
- Nowe lokalne pliki mają nazwę i przycisk „Usuń zdjęcie”. Usunięcie zmienia rzeczywisty `input.files`, pozostawia kolejność pozostałych plików i tekst. Fokus przechodzi do następnego przycisku (albo poprzedniego przy końcu), po ostatnim usunięciu wraca do pola wyboru. Osobny region `role=status` ogłasza liczbę nowych zdjęć i nadmiar liczony wraz z zachowanymi.
- Usuwanie jest włączone tylko przy dwóch oznaczonych polach. Nie usuwa mediów serwera i nie włącza się w kreatorze Livewire. Brak DataTransfer daje podgląd oraz instrukcję ponownego wyboru bez niedziałających przycisków. Odmowa zmiany FileList nie udaje sukcesu i pozostawia widoczny wybór.
- Object URL są zwalniane po `load` i `error`, przy zastąpieniu wyboru, resecie i opuszczeniu strony. Nie trzeba czekać na wczytanie usuwanego obrazu.
- Awatar bez obrazu w `rejected` proponuje ponowny wybór i zapis. `pending`/`processing` bez wariantu nadal oznaczają oczekiwanie; `rejected` z bezpiecznym podglądem nadal pokazuje zdjęcie. Brak relacji i `deleted` pozostają stanami bez zdjęcia.

Nie zmieniono schematu bazy, limitów konfiguracji ani polityk dostępu. Serwer nadal egzekwuje limit niezależnie od JavaScriptu.

### Kontrole ujemne — własne

| Mutacja | Wynik |
|---|---|
| Usunięcie pomocy z formularza wpisu | 2 porażki renderu, pozostałe 26 przypadków przechodzi |
| Usunięcie pomocy z formularza wykonania | 2 porażki renderu, pozostałe 26 przypadków przechodzi |
| Przywrócenie dawnego warunku „status inny niż deleted” | 1 porażka dla rejected bez wariantów, 27 przypadków przechodzi |
| Usunięcie przypisania `input.files` | Chromium widzi A/B/C zamiast oczekiwanych A/C; proces kończy się kodem 1 |

Źródła przywracał `finally`; MD5 i dokładny `LastWriteTimeUtc` sprawdzono po przywróceniu. Pierwsza próba narzędzia w Bash została zatrzymana przed mutacją: kopiowanie z ext4 do NTFS obcinało ułamki sekundy w mtime. Przywrócono dokładny czas z pierwszych kopii i przeprowadzono kontrole przez PowerShell, który zachował go poprawnie. Nie używano stasha ani odtwarzania plików z commita.

### Przeglądarka — własny pomiar i granice

Skrypt: `scripts/zdjecia-limity.mjs`. HTML pochodzi z testowych żądań Laravel na PostgreSQL; arkusz jest z rzeczywistego buildu. Przeglądarka wykonuje rzeczywisty fragment podglądu z `app.js`. Kontrola ujemna izolowała ten fragment; końcowy dodatkowy przebieg z `PHOTO_FULL_BUNDLE=1` wykonał cały zbudowany `app.js` i też przeszedł. Żądania sieciowe są przechwycone — nie jest to odbiór zalogowanej produkcji.

Dla obu formularzy sprawdzono: A/B/C → usunięcie B przez Enter → FormData i multipart POST zawierają tylko A/C; opis pozostaje bez zmian; usunięcie wszystkich, fokus na polu, ponowny wybór i reset; nadmiar nowych + zachowanych zdjęć, cofnięcie ostrzeżenia po usunięciu, zachowanie ukrytego identyfikatora; brak DataTransfer i odmowę zapisu FileList: bez utraty plików; JavaScript wyłączony: zwykły POST wysyła tekst i trzy zdjęcia; 320 px: brak przewijania w bok, przyciski 50,5 px, tekst 18 px; nazwy dostępne rozróżniają pliki; testowano klawiaturę i drzewo dostępności, nie odsłuch sprzętowym czytnikiem ekranu.

Dodatkowe sprawdzenia: brak usuwania przy polu bez opt-in, zwalnianie URL uszkodzonego obrazu, wymiana przed `load`, `pagehide`, powiększenie CSS 200% przy efektywnej szerokości 320 px. **Zastrzeżenie granicy pomiaru:** nie jest to pomiar fizycznego telefonu, natywnej galerii ani systemowego powiększenia przeglądarki.

### Środowisko i odtworzenie

Runtime: `/home/mateusz/flota/gpt-zdjecia-limity-run`. Baza: `kuking_flota_gpt-zdjecia-limity`, użytkownik `kuking`, PostgreSQL `127.0.0.1:55439`. Zależności skopiowane, bez symlinków.

```bash
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh gpt-zdjecia-limity
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- env PHOTO_BROWSER_FIXTURES=1 bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-zdjecia-limity --filter LimityIPodgladZdjecTest
# W przygotowanym runtime:
npm run build
node scripts/zdjecia-limity.mjs
```

### Ryzyka, wycofanie i decyzje

W starych przeglądarkach, które nie pozwalają zmienić FileList, pozostaje ponowny wybór całego zestawu. Ostrzeżenie przeglądarkowe nie blokuje wysłania; nie zastępuje serwera. Bez skryptu nie ma podglądu ani usuwania pojedynczych nowych plików, ale publikacja działa.

Wycofanie: odwrócić własny commit implementacji, pozostawiając zależność #871–#874. Nie cofać bazy. Przywróci to brak limitów na ekranie, brak usuwania nowych plików i mylący komunikat odrzuconego awatara.

W tym zakresie nie pozostała decyzja produktowa właściciela. Nie wykonywano push, PR, zdalnego CI, zmian produkcji ani wysyłki wiadomości.

### Wynik końcowy — własny

- Pełny standardowy zestaw: **4466 testów, 84 166 asercji, 442,69 s**, bez porażek. Filtr: `^(?!.*ProbaOdtworzeniaTest)`. `ProbaOdtworzeniaTest` pominięto zgodnie z jawnym wyjątkiem zlecenia; grupa `dwa-polaczenia` pozostaje wyłączona zgodnie z domyślnym `phpunit.xml`. Nie zadeklarowano wykonania tej grupy.
- Zestaw celowany obejmujący także regresje #871–#874: **80 testów, 520 asercji**, bez porażek.
- `vendor/bin/pint` wykonany na zmienionych plikach PHP; końcowy `vendor/bin/pint --test`: **1159 plików, PASS**.
- PHPStan całego projektu: **bez błędów**. `npm run build`: **PASS**.
- Końcowa próba Chromium po kontrolach ujemnych: **PASS**, wraz z pustym wyborem, ponownym wyborem, resetem, oboma wariantami awaryjnymi, POST bez JavaScriptu, cyklem życia URL i powiększeniem CSS.

Dowody: `zdjecia-limity-dowody/kontrole.txt`, `zdjecia-limity-dowody/zdjecia-limity.json`, `zdjecia-limity-dowody/post-wybor.png` (podgląd wpisu przy 320 px), `zdjecia-limity-dowody/cooked-wybor.png` (podgląd wykonania przy 320 px). Zrzuty pokazują syntetyczne zdjęcia testowe, nie treści użytkowników.

## gpt/wspomnienia-prywatnosc — #879, #880, #881, #882

### Zakres i punkt wyjścia

Gałąź `gpt/wspomnienia-prywatnosc`, baza `4c811cc7bff365fb8f86d87eabac93b7738a45cd`. Pomiary własne w izolowanym runtime WSL, PostgreSQL `127.0.0.1:55439`, baza `kuking_flota_gpt-wspomnienia-prywatnosc`, właściciel `kuking`. Nie wykonano zmian produkcyjnych, wysyłki poczty, pushowania ani PR.

### #880 — konflikt stanu, bez pierwszeństwa którejkolwiek drogi

Na kodzie przed poprawką istniejący `DowodZgodyNaDigestTest` przeszedł: 19 testów, 78 asercji. Nowa regresja pokazała 4 porażki i 1 kontrolę dodatnią: stary formularz przywracał zgodę po wypisaniu odnośnikiem i formularzem, wyłączał nowszą zgodę oraz przywracał nowsze wyłączenie wspomnień. To własny pomiar HTTP i aktualnej bazy, nie przejęty wniosek ze zgłoszenia.

Formularz przesyła dwa stany początkowe. Akcja `UpdatePrivacySettings` odczytuje konto pod `FOR UPDATE`, porównuje oba stany i przy rozbieżności odrzuca cały zapis. Nie powstaje wtedy nowy dowód zgody. Wszystkie drogi `PrzestawZgodeNaDigest` odczytują bieżący stan pod tą samą blokadą. Pozostają: dziennik append-only, atomowe udzielenie oraz wypisanie mimo awarii zapisu dowodu. Nie ma migracji ani nowych zależności.

Po błędzie wybory i stany początkowe pozostają w formularzu. Odnośnik „Otwórz aktualne ustawienia” pozwala odczytać bieżący stan i wybrać ponownie. Stary formularz bez stanów początkowych także jest odrzucany; nie dostaje automatycznie nowych stanów pod stare zaznaczenia. Kontrola porównuje stan, nie historię wszystkich zmian: cykl A→B→A kończący się stanem początkowym nie jest konfliktem. Testy HTTP wykonują żądania kolejno. Osobny pomiar dwóch procesów PHP potwierdził blokowanie w PostgreSQL (opis poniżej).

### #881 — flaga Poradźcie

Przed poprawką własne pytanie z rocznicy zostało wybrane przy wyłączonej fladze. Zapytanie wspomnień korzysta teraz z `enabledKinds()`. Zachowuje własne prywatne wpisy, `hide_as_memory` i wyłącznik wszystkich wspomnień. Nie zastępujemy go zapytaniem treści publicznych.

**DECYZJA WŁAŚCICIELA pozostaje otwarta:** czy przy włączonym Poradźcie pytania mają być wspomnieniami? Wariant A: pozostawić dotychczasowy wybór wszystkich włączonych rodzajów — bez zmiany mechaniki, z możliwością powrotu dawnego pytania. Wariant B: przypominać tylko gotowanie — jeden filtr rodzaju, ale świadoma utrata wspomnień o dawnych pytaniach. Poprawka respektuje wyłączenie modułu i nie utrwala wariantu A ani B asercją na pytaniu przy włączonej fladze.

### #882 i #879 — tekst opisuje czynność

Przed zmianą 6 wariantów potwierdzenia układu zdjęć i 4 warianty zdjęcia blokady oblały regresję na rzeczywistej treści odpowiedzi po przekierowaniu.

„Układ zdjęć zapisany.” opisuje wyłącznie wykonaną czynność. Jest prawdziwe dla public/private/followers, także po zmianie widoczności w innej karcie. Obie końcowe ścieżki korzystają z tego zdania. Testy sprawdzają też zachowanie widoczności i kolejności oraz działanie przycisków przesunięcia.

„Blokada zdjęta. Zdjęcie blokady nie przywraca obserwowania. Jeśli na profilu tej osoby jest przycisk «Obserwuj», użyj go, aby zacząć ją obserwować.” Nie zakłada wcześniejszego obserwowania ani dostępności konta, nie ujawnia cudzej blokady. Nie dodajemy linku ani bramki profilu przed odblokowaniem. Regresja obejmuje wzajemne obserwowanie, brak obserwowania, wzajemną blokadę i konta zbanowane oraz zawieszone; oba kierunki follow pozostają usunięte.

### Pomiary końcowe — własne, 20 września 2026

**Dwa połączenia PostgreSQL:** każda próba utworzyła osobne konto testowe. Proces nadrzędny rozpoczął transakcję, wykonał zmianę zgody i pozostawił transakcję otwartą. Drugi proces uruchomił akcję na drugim połączeniu. Przed zatwierdzeniem pierwszej transakcji odczyt `pg_stat_activity` potwierdził `wait_event_type = Lock`, różne PID-y i PID rodzica w `pg_blocking_pids`. Dopiero wtedy nastąpił COMMIT.

| Pierwsza akcja | Druga akcja | PID rodzica / dziecka | Wynik drugiej / zgoda końcowa |
|---|---|---|---|
| Wypisanie odnośnikiem | Stary formularz z zaznaczoną zgodą | 1730987 / 1731123 | konflikt / false |
| Wypisanie formularzem | Stary formularz z zaznaczoną zgodą | 1730987 / 1731273 | konflikt / false |
| Udzielenie zgody formularzem | Wypisanie ze starym modelem konta | 1730987 / 1731291 | wypisano / false |

To pomiar współbieżności akcji domenowych, nie dwóch serwerów HTTP. Regresja HTTP dodatkowo sprawdza odwrotny konflikt: stary formularz z odznaczoną zgodą nie wyłącza nowszego zapisu do listu.

**Macierz wspomnień:** własny prywatny wpis, wspomnienia włączone, zegar 20.09.2026 w południe, wpis sprzed dokładnie roku. Wynik wyboru (pomiar, bez asercji rozstrzygającej produktowo włączone pytania):

| Rodzaj | Poradźcie wyłączone | Poradźcie włączone |
|---|---|---|
| Danie | wybrane | wybrane |
| Pytanie | pominięte | wybrane |

**Regresje i narzędzia:**

- Końcowy pełny przebieg: **4413 poprawnych, 83 961 asercji, 337,23 s**, kod zakończenia 0. Filtr pomija wyłącznie `ProbaOdtworzeniaTest`.
- Testy celowane przed ostatnim rozszerzeniem: 91 poprawnych, 513 asercji.
- Cztery kontrole ujemne przez `scripts/kontrola-ujemna.sh`: usunięcie ochrony konfliktu, filtra rodzaju, prawdziwego komunikatu zdjęć oraz objaśnienia odblokowania. Każda: PASS → FAIL z właściwej przyczyny → PASS; skrypt potwierdził przywrócenie MD5 i mtime źródła.
- Pierwszy pełny przebieg: 4412 poprawnych, 1 porażka. Porażka `KomunikatWyjatkuNieWchodziSurowyDoDziennikaTest` została odtworzona osobno: stary mock rzucał wyjątek z KAŻDEJ transakcji, także nowej blokady konta. Symulacja została ograniczona do tworzenia wpisu zgody; dodano odczyt faktycznie wycofanej zgody z bazy. Klasa: 2 poprawne, 17 asercji. Dodatkowa kontrola ujemna wstawiła surowy komunikat wyjątku do logowania: PASS → FAIL na testowym adresie e-mail → PASS po przywróceniu.
- Pint wykonany; PHPStan zmienionych sześciu plików aplikacji bez błędów.
- `npm run build`: poprawne, w tym 20 testów JavaScript i 72 pary kontrastu.
- `git diff --check`: poprawne.

**Przeglądarka lokalna:** Chromium, prawdziwe logowanie formularzem, dwie karty ustawień. Wypisanie w drugiej karcie, następnie wyłączenie wspomnień w starej pierwszej karcie: widoczny konflikt, zgoda w formularzu nadal zaznaczona, wspomnienia odznaczone. Kliknięcie „Otwórz aktualne ustawienia” pokazało rzeczywisty stan: list wyłączony, wspomnienia włączone (konflikt nie zapisał połowy).

Przy 320 × 900 px `scrollWidth = innerWidth = 320`, komunikat przy polu 18 px, przycisk „Zapisz” 50,5 px wysokości. Obejrzano zrzut `output/playwright/privacy-conflict-320.png` (lokalny artefakt ignorowany przez Git). Nie wykonano osobnej próby rzeczywistego zoomu przeglądarki 200%.

### Wycofanie i granice

**PUŁAPKA SCALANIA:** wycofanie kodu nie wymaga operacji na bazie, ale cofnięcie #880 przywraca ryzyko nadpisania nowszej decyzji — preferowana jest poprawka do przodu. Nie wykonano oglądu zalogowanej produkcji ani badania z użytkownikami. Nie uruchomiono `ProbaOdtworzeniaTest`: zgodnie z jawnym wyjątkiem zadania używa wspólnej bazy `kuking_zrodlo_proby_glowny`. Pozostałe pomiary wykonano samodzielnie; zgłoszenia służyły jako hipotezy, nie przejęte wyniki testów.
