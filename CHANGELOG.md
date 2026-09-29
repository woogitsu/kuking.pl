# Co się zmieniło w Kuking

## Nieopublikowane

- Import przepisu z adresu strony, z pliku PDF i odczyt zdjęcia kartki są teraz domyślnie wyłączone (#2214): bez zmiennych `KUKING_IMPORT_URL`, `KUKING_IMPORT_PDF` i `KUKING_IMPORT_ZDJECIE` ustawionych na `true` przycisków nie ma, nawet gdy jest klucz OpenAI. `php artisan kuking:sprawdz-import` pokazuje stan każdego przełącznika. Tam, gdzie import ma działać, trzeba te zmienne ustawić jawnie przed wdrożeniem.
- Pytanie „Jak mamy do Ciebie pisać?” (#1751, #1752, #1753, D-332; decyzja właściciela z 29.09.2026). W ustawieniach profilu i na ostatnim kroku po założeniu konta (krok można pominąć) wybierasz formę żeńską, męską albo neutralną; neutralna jest zaznaczona od początku i nic się nie zmienia, dopóki ktoś sam nie wybierze inaczej. Nie zgadujemy formy z imienia ani z Google czy Facebooka. Wybraną formę widzą też inni, bo tak piszemy o Tobie. Pytanie jest dostępne od razu: zmiana polityki prywatności, która je opisuje, jest drobna i obowiązuje od dnia publikacji (decyzja właściciela z 29.09.2026 — serwis nie ma jeszcze prawdziwych kont; bez okresu przejściowego i bez paska). Forma działa w kilku miejscach: na końcu zakładania konta, w pustym „Świeżo z Kuking”, na przycisku kończącym tryb gotowania (przy formie żeńskiej „Ugotowałam”), w stopce tygodniowego podsumowania i listu z paczką danych oraz na stronie „ugotowane z Twojego przepisu”. Pod spodem: kolumna `profiles.form_of_address` (CHECK, `NULL` = neutralnie; migracja odmawia cofnięcia, gdy ktoś wybrał formę — D-088), pole w paczce danych (`forma_zwracania_sie`), wymazanie przy usunięciu konta, wiersz i sekcja „Co się zmieniło” w polityce prywatności (nowa wersja 2026-09-30, drobna, obowiązuje od dnia publikacji; wersja 2026-09-29 z Alfa 0.76 zostaje nietknięta), pasek o zmianie polityki na przyszłe zmiany istotne — przy drobnej się nie pokazuje (`ZmianaPolityki`, kolumna `users.policy_notice_dismissed_version`, rollback odmawia przy zamkniętych paskach; eksport i wymazanie konta obejmują pole) oraz helper `App\Support\Forma::dla()` z obowiązkowym wariantem neutralnym; `TekstyNiePrzypisujaPlciTest` przepuszcza rodzaj tylko w jego wywołaniu. [nowa funkcja]
- Wyszukiwarka przepisów ma wiersz „Ile masz czasu?”: Bez limitu czasu, Do 15 minut, Do 30 minut i Do godziny (#1997). Wybór jest zwykłym odnośnikiem (bez JavaScriptu), widać go zaznaczonego i zdejmuje się go przyciskiem „Bez limitu czasu”, a próg siedzi w adresie (`?czas=15|30|60`), więc przeżywa odświeżenie, „Pokaż więcej” i wysłanie komuś. Liczymy przygotowanie i gotowanie razem, a przepis bez podanego czasu nie trafia do żadnego progu. Nieznana wartość w adresie nie filtruje po cichu — ekran mówi, że pokazuje wyniki bez limitu. Stary adres `sekcja=szybkie` dalej działa jako „Przepisy” + „Do 30 minut”. Bez zmian w bazie. [nowa funkcja]
- Publiczny zeszyt ma przycisk „Podziel się” (#2000): WhatsApp, e-mail, Facebook, widoczny adres do skopiowania i arkusz udostępniania w telefonie — ten sam komponent i te same zasady co przy przepisie i wpisie, adres bez dodatkowych parametrów. Przycisk pojawia się tylko przy zeszycie widocznym dla „wszystkich”, także dla gościa — również przy wspólnym zeszycie rodzinnym (D-302), jeśli ma widoczność „wszyscy” (decyzja właściciela z 29.09.2026). Zeszyt „Tylko ja” i domyślne „Zapisane” nie dostają go nawet u właściciela — zamiast niego właściciel czyta zdanie, co zmienić. Widoczność przepisów w środku zeszytu działa jak dotąd, więc odbiorca nie zobaczy pozycji, do których nie ma dostępu. [nowa funkcja]
- Dane dla wyszukiwarek na stronie przepisu zawierają teraz kalorie na jedną porcję (#1996, `Recipe.nutrition.calories`, np. „480 kcal”) — tylko wtedy, gdy ta sama liczba jest widoczna w sekcji „Szacunkowe wartości odżywcze (na porcję)”: autor nie ukrył sekcji, składniki z tabel to co najmniej 90% masy przepisu i autor podał liczbę porcji. Zaokrąglenie (do 10 kcal) jest to samo co na stronie. Przy ukrytej sekcji, niepełnym pokryciu albo braku liczby porcji pola nie ma, a wartości na cały przepis nigdy nie podajemy jako wartości porcji. [nowa funkcja]
- Pod przepisem, który ma co najmniej dwie zapisane wersje, jest przycisk „Historia zmian” (#2024). Prowadzi do listy wersji z datami (po 20, z przyciskiem „Pokaż starsze wersje”), do podglądu jednej wersji — tekstu i danych przepisu, bez zdjęć — oraz do porównania z wersją poprzednią: składniki i kroki są opisane słowami „Dodano”, „Usunięto” i „Zmieniono” (kolor tylko je podkreśla), a przy zmianach widać „Było” i „Jest”. Widzi to każdy, kto widzi sam przepis (gość, obserwujący albo tylko autor — według widoczności przepisu, blokad i stanu konta autora); przepis ukryty lub zdjęty przez moderację, szkic i przepis prywatny dla obcych nie mają historii, a ekrany nie pokazują, kto zapisał wersję. Pole, którego starsza wersja jeszcze nie zapisywała, jest opisane jako „Brak danych”, a nie uzupełniane dzisiejszą treścią. Bez zmiany schematu bazy. [nowa funkcja]
- Tryb gotowania: zalogowana osoba może świadomie włączyć zapamiętywanie postępu przepisu na koncie (#2016). Przycisk „Zapamiętuj postęp na moim koncie” pod krokami zapamiętuje odhaczone kroki tego jednego przepisu, więc po otwarciu go na innym telefonie czy tablecie widać je od razu; wcześniej stały tylko w przeglądarce, w której je zaznaczono. Domyślnie nic się nie zmienia — postęp zostaje w tej przeglądarce, tak samo jak dla osób bez konta. Zapis wygasa po 24 godzinach od ostatniej zmiany, „Zacznij od początku” go czyści, a „Wyłącz zapamiętywanie na koncie i usuń zapis” kasuje go z konta (odhaczenia zostają do końca sesji na tym urządzeniu). Zmiany dokonane na drugim urządzeniu strona zgłasza komunikatem, a przy włączonym skrypcie sama sprawdza co pół minuty i podpowiada odnośnik „Pokaż aktualny postęp”; bez skryptu wszystko działa zwykłymi formularzami. Zapamiętywany jest tylko postęp kroków — składniki „przygotowane”, porcje i minutniki zostają w przeglądarce. Cudzego postępu nie da się zobaczyć ani zmienić, a konto, które straciło dostęp do przepisu, nie odtworzy jego postępu. Postęp jest w paczce danych („postep_gotowania”), znika przy wymazaniu konta, a nocne sprzątanie (`kuking:sprzataj-postep-gotowania`, 03:00) kasuje wygasłe wpisy. Pod spodem: nowa tabela `cooking_progress`, której cofnięcie migracji odmawia, gdy są w niej niewygasłe wiersze (D-088). [nowa funkcja]
- Panel moderacji: ekran „Zdejmij z urzędu” czyta się pismem podstawowym także w zdaniach o skutku decyzji — że komentarz wróci, jeśli autor wygra odwołanie, i że treść zniknie z serwisu od razu. Były drobnym szarym pismem 16 px, choć to od nich zależy, czy moderator kliknie przycisk. Odbiór panelu w przeglądarce obejmuje teraz i ten ekran (#581).
- Wewnętrzne: `kuking:raport` liczy teraz, czy przepis wpisany w planerze kończy się „Ugotowałem” w dniu planu albo do 3 dni po nim (#27). Pomiar korzysta z istniejących tabel, bez nowych zdarzeń i danych osobowych, pomija konta testowe i gospodarza, a poniżej 20 pozycji pokazuje same liczby bez procentu. To liczby, na których właściciel oprze decyzję o liście zakupów; definicja jest w `docs/research/ANALITYKA.md` §1.6.
- Import przepisu z adresu strony czyta teraz także starsze blogi, które nie mają danych JSON-LD, tylko oznaczenia „mikrodane” schema.org w samej stronie (#28). Kuking bierze tytuł, opis, składniki, kroki, porcje i czasy przygotowania oraz gotowania wprost ze strony — lokalnie, bez wysyłania czegokolwiek do modelu i bez kosztu — a gdy strona ma oba zapisy, pierwszeństwo ma JSON-LD. Nazwiska autora, ocen ani danych odżywczych ze strony nie przenosimy, zdjęć nie pobieramy, a wynik jak zawsze trafia do prywatnego szkicu do sprawdzenia. Dopiero strona bez żadnych takich danych idzie dalej starą drogą (odczyt przez komputer firmy OpenAI, tylko za zgodą z formularza). Wewnętrznie: w zapisie szkicu droga odczytu z mikrodanych nadal nazywa się `json_ld` (dane strukturalne), więc bez zmiany schematu.
- W „Twoich danych” można teraz wczytać własną paczkę z danymi, pobraną wcześniej z Kuking (#1985). Wybierasz plik ZIP, a Kuking najpierw pokazuje, co w nim jest: przepisy, własne wpisy i zeszyty do wczytania, te, które już masz na koncie albo które powtarzają się w paczce, oraz pozycje, których wczytać się nie da — z powodem po polsku i z informacją, czego nie wczytamy (zdjęć, pytań z Poradźcie, konta, zgód, komentarzy innych osób). Niczego nie zapisujemy, dopóki nie zaznaczysz pozycji i nie klikniesz „Wczytaj zaznaczone”. Wszystko, co wczytamy, jest prywatne: przepisy trafiają do szkiców, wpisy widzisz tylko Ty, zeszyty są „Tylko ja” — o publikacji zdecydujesz później. Pytań z Poradźcie nie wczytujemy, bo pytanie jest zawsze publiczne, a wczytane treści mają być prywatne — podgląd mówi o tym wprost. Jedno kliknięcie wczytuje najwyżej 50 pozycji, a to samo wczytanie drugi raz niczego nie podwaja. Wybrany plik czeka na decyzję najwyżej 2 godziny w prywatnym magazynie i znika po wczytaniu, przy wymazaniu konta i — gdy go porzucisz — w nocnym sprzątaniu (`kuking:sprzataj-paczki-importu`, 03:30), a w dzienniku zostają tylko liczby. Pod spodem: nowa tabela `wczytane_z_paczki` (odcisk treści, bez samej treści), której cofnięcie migracji odmawia, gdy są w niej wiersze (D-088). [nowa funkcja]
- Strony ładują się szybciej przy pierwszym wejściu: font Inter ma teraz 53 kB zamiast 133 kB (−60%). Zostały polskie litery ze znakami diakrytycznymi, „cudzysłowy”, półpauza i znaki europejskich nazwisk; znak spoza zestawu wyświetla się czcionką systemową, a nie znika. Test pilnuje pokrycia glifów, a podzbiór generuje skrypt `scripts/fonty-podzbior.py` (#1000).
- Naprawione: usunięcie konta po 30 dniach czeka teraz na trwały zapis w dzienniku wymazań poza bazą. Gdy magazyn dziennika chwilowo nie odpowiada, konto nie jest wymazywane bez śladu — zostaje do ponowienia przy następnym przebiegu, więc odtworzenie kopii bazy nie przywróci wymazanego konta bez możliwości ponownego wymazania (#2038).
- Wewnętrzne: gdy wymazania kont stoją przez kilka nocy z rzędu, bo dziennik wymazań poza bazą nie przyjmuje wpisów (domyślnie 3 noce), właściciel dostaje jedną wiadomość na istniejący kanał alarmowy, a po powrocie do normy jedno odwołanie. Konta w tym czasie zostają nietknięte i są ponawiane co noc; wiadomość zawiera tylko liczby, bez danych kont (#2038).
- Wewnętrzne: w kreatorze przepisu (#1387) odnośnik z podsumowania błędów zawsze prowadzi na ekran, na którym naprawdę stoi pole. Błąd dotyczący całej listy składników (bez numeru wiersza) odsyłał na krok „o przepisie” zamiast na „składniki”, a błąd pola „Sprawdziłem odczytany tekst” — też na pierwszy krok zamiast na podgląd. Dziś kreator żadnego z tych błędów nie zgłasza, więc nikt tego nie zobaczył. Wewnętrznie: nawigacja kreatora (dokąd prowadzą „Dalej” i „Wstecz” i który krok pokazuje dany błąd) jest osobną klasą z tabelą testów dla wszystkich pól.
- Naprawione (#1387): jeśli przepis zniknął w innej karcie (na przykład został usunięty), kliknięcie „Opublikuj przepis” na podglądzie zostawia Cię na podglądzie, gdzie stoi komunikat „Ten przepis nie jest już dostępny. Skopiuj wpisany tekst, zanim opuścisz formularz.”. Wcześniej kreator cofał Cię na krok „przygotowanie”, którego ten błąd nie dotyczy, a komunikat był widoczny tylko w podsumowaniu błędów. Powtórzony zapisany krok nadal odsyła na krok „przygotowanie”. Wewnętrznie: autozapis i liczniki rewizji kreatora (ochrona przed nadpisaniem nowszej wersji z innej karty, sprawdzenie pól przed zapisem, teksty plakietki zapisu) są osobnymi klasami z testami jednostkowymi; zachowanie bez zmian.
- Wewnętrzne: w kreatorze przepisu (#1387) kroki „o przepisie”, „składniki” i „przygotowanie” są osobnymi komponentami widoku (`x-kreator.krok-o-przepisie`, `x-kreator.krok-skladniki`, `x-kreator.krok-przygotowanie`), tak jak wcześniej podgląd. Ekran wygląda i działa tak samo; wyrenderowany HTML każdego kroku porównano przed i po zmianie.
- Wewnętrzne: w kreatorze przepisu (#1387) składanie wyników sprawdzenia kroków — które błędy trafiają przy którym polu, w jakiej kolejności i na który krok kreator wraca — jest osobną klasą z tabelą testów, bez renderowania całego kreatora. Komunikaty, kolejność błędów i przechodzenie do kroku z błędem bez zmian.
- Import przepisu z pliku PDF też nie każe czekać przy formularzu (#28). Po „Zapisz jako szkic” od razu widzisz ekran postępu — „Odczytujemy plik” — a odczyt tekstu, a przy skanie (tylko za zgodą z tego formularza) odczyt przez komputer firmy OpenAI, odbywają się w tle; możesz zamknąć kartę, gotowy szkic czeka w „Moich szkicach”. PDF z warstwą tekstu odczytujemy u siebie i nigdzie go nie wysyłamy. Wysłany plik leży do czasu odczytu w prywatnym magazynie i znika zaraz po nim — także gdy odczyt się nie uda, przy usunięciu konta, a najpóźniej po kilku godzinach (#2051). Plik, który nie jest PDF-em albo jest za duży, odrzucamy od razu, bez zajmowania miejsca w limicie 5 dziennie / 30 miesięcznie. Ponowne wysłanie tego samego formularza nie zajmuje drugiego miejsca i nie kosztuje drugiego odczytu.
- Naprawione (#1731): błędy zgłaszane z workera kolejki uruchomionego poza produkcją (środowisko z pakietami deweloperskimi) tracą identyfikatory zadania i próby w kontekście dziennika — hook je dopisujący nie powstawał, gdy handler wyjątków był owinięty przez narzędzie deweloperskie. Hook jest teraz rejestrowany przy tworzeniu handlera. Produkcja nie była dotknięta.

## Alfa 0.77 — wspólny zeszyt dla rodziny i import przepisu w tle

- Rodzinny zeszyt: zeszyt (poza domyślnym) można udostępnić bliskim — po nazwie konta albo jednorazowym linkiem. Zaproszone osoby dopisują i wyjmują przepisy i wpisy, a przy każdej pozycji widać, kto ją dodał. Zeszyt nadal ma jednego właściciela; najwyżej 5 osób z dostępem; blokada albo usunięcie konta kończy wspólne zapisywanie (#1743, D-302). [nowa funkcja]
- Panel moderacji: ekran „Metryki doboru” (widzi go tylko administrator) ma układ zgodny z resztą panelu. Uwagi pod kartami — na przykład wyjaśnienie, że rozmowa o rankingu ma sens dopiero przy wszystkich trzech progach naraz — czyta się pismem podstawowym (18 px, rośnie ze skalą tekstu), nie drobnym szarym. Tabela „Różnych autorów dziennie” ma obwódki, wyróżniony nagłówek i podpis dla czytnika ekranu, a przy wąskim ekranie i dużym piśmie łamie datę do drugiego wiersza zamiast wypychać stronę w bok. Odbiór panelu w przeglądarce obejmuje teraz i ten ekran, z danymi i bez nich (#492).
- Panel moderacji: ekran „Kolejka zadań” (widzi go tylko administrator) ma układ zgodny z resztą panelu. Lista nieudanych zadań jest jedną grupą z własnym nagłówkiem i odstępem od karty nad nią, a nazwa zadania, wyjątek, kolejka i daty czyta się pismem podstawowym (18 px, rośnie ze skalą tekstu), nie drobnym szarym. Na telefonie podpis stoi nad wartością. Odbiór panelu w przeglądarce obejmuje teraz i ten ekran, w stanie pustym i z danymi (#581).
- Naprawione: „Wyjmij niedostępne zapisy” w zeszycie wycofuje teraz Wasz udział w jeszcze nieprzeczytanym powiadomieniu autora „ktoś zapisał Twój przepis”, gdy było to ostatnie zapisanie tego przepisu w Waszych zeszytach — tak jak zwykłe wyjęcie przepisu z zeszytu. Jeśli ten sam przepis leży w innym Waszym zeszycie, powiadomienie zostaje. Przeczytane powiadomienia się nie zmieniają (#2205).
- Wewnętrzne: raz na dobę (03:30 UTC) komenda `kuking:sprzataj-porzucone-uploady` kasuje z katalogu tymczasowego Livewire w R2 surowe zdjęcia i towarzyszące im pliki z nazwą oryginału, które leżą tam dłużej niż 24 godziny, a nikt ich nie zapisał (zły format, zamknięta karta, nieudane usunięcie po zapisie). Dotyka wyłącznie tego katalogu; porażka kończy przebieg kodem błędu, a dziennik nie zawiera nazw plików. Reguła wygasania w panelu R2 (#2051) zostaje drugą linią obrony (#2178).
- Przy imporcie przepisu ze strony internetowej i z pliku PDF pole zgody na odczyt przez komputer firmy OpenAI ma teraz pod formularzem pełną informację: komu wysyłamy (OpenAI, USA), co dokładnie (sam tekst strony bez adresu i komentarzy albo obrazy stron skanu PDF — bez e-maila, nazwy konta i adresu IP), że wynik trafia tylko do prywatnego szkicu, że zgoda dotyczy tylko tego jednego wysłania i nie zastępuje zgody na zdjęcia kartek, oraz że bez zaznaczenia nic nie wychodzi. Informacja ma numer wersji: formularz otwarty przed jej zmianą albo sprzed tej zmiany nie wyśle niczego do modelu, tylko powie, co zrobić. Dopisano też opis tych źródeł do rejestru czynności przetwarzania i do projektu polityki prywatności; sama polityka i jej wersja bez zmian (#2031).
- Naprawione (#988): w całym serwisie komunikat po akcji ma teraz jawny rodzaj, a nie tylko zieloną ramkę. Odmowy i błędy — m.in. brak poczty przy wysyłaniu potwierdzenia, wyłączony import ze zdjęcia, przepis zmieniony w trakcie gotowania krok po kroku, niedostępny komentarz albo wpis w powiadomieniach, wygasłe zapisane imię przy zakładaniu konta, zamknięta rejestracja, kody zapasowe zmienione w innej karcie — mają czerwoną ramkę i napis „Nie udało się”. Sytuacje, w których nic się nie zmieniło, bo jest już tak, jak chcesz (drugie kliknięcie, „Już obserwujesz tę osobę”, pozycja już w planie), mają napis „Informacja”. Zielone „Gotowe” zostaje tylko dla wykonanej czynności. Wewnętrznie: nie da się już zapisać komunikatu bez rodzaju — pilnuje tego test `StraznikKomunikatuTest`.
- Naprawione: numer wersji z końcówką (np. „Alfa 0.69.006") i dopisek „od Alfa …” przy funkcjach na stronie „Co nowego” zapisują się dopiero wtedy, gdy nowa wersja serwisu naprawdę wstała i odpowiada na `/health`. Wcześniej numer padał już na początku wdrożenia, więc nieudane wdrożenie zostawiało w stopce zużyty numer, a funkcja była opisana jako dostępna, choć nikt jej nie zobaczył (#1932).
- Pod spodem, pierwszy etap wczytywania własnej paczki z danymi (#1985), jeszcze bez przycisku na ekranie: paczka z Twoimi danymi ma w pliku „dane.json” numer wersji formatu, a serwis potrafi sprawdzić taką paczkę i powiedzieć, ile przepisów, wpisów i zeszytów w niej jest oraz co już masz na koncie — niczego przy tym nie zapisuje. Ekran wczytywania i sam zapis (zawsze jako prywatne, bez zdjęć) to następne etapy.
- Import przepisu z adresu strony nie każe już czekać przy formularzu (#28). Po „Zapisz jako szkic” od razu widzisz ekran postępu — „Pobieramy stronę” — a pobranie strony, sprawdzenie robots.txt i ewentualny odczyt przez komputer firmy OpenAI (tylko za zgodą z tego formularza) odbywają się w tle; możesz zamknąć kartę, gotowy szkic czeka w „Moich szkicach”. Zerwane połączenie albo odświeżenie strony nie gubi importu, a ponowne wysłanie tego samego formularza nie zajmuje drugiego miejsca w limicie 5 dziennie / 30 miesięcznie i nie kosztuje drugiego odczytu. Adres podany wprost jako numer IP (np. 127.0.0.1) odrzucamy od razu, a resztę odmów (strona niedostępna, za duża, nie dla robotów, adres niepubliczny) czytasz na ekranie postępu, z odnośnikiem „Wklej adres jeszcze raz”. Adres znika z zapisu zlecenia, gdy import się kończy. Import z pliku PDF nadal działa w żądaniu (osobny etap).
- Naprawione: mapa strony dla wyszukiwarek nie podaje już starej daty zmiany po edycji publicznego przepisu, wpisu albo pytania. Zmiana tytułu, opisu, czasów, zdjęcia czy składników odświeża mapę od razu, a nie po kilku godzinach; edycja szkicu i treści prywatnych nadal jej nie rusza. Data zmiany przepisu w mapie to teraz data zmiany jego treści (ta sama, którą widzą wyszukiwarki na stronie przepisu), a nie data dowolnego zapisu, np. po moderacji; gdy tej daty nie znamy (starsze przepisy), mapa jej nie podaje (#1280).

## Alfa 0.76 — planer z wyszukiwarką, „Co mam w domu” i zeszyt bez konta

- Moderacja: automat nie gubi już własnych sygnałów, gdy zewnętrzny model odpowiada wolno. Sygnały wykryte na miejscu trafiają do kolejki od razu, wynik modelu dopisuje się do tego samego oznaczenia, a cała ocena mieści się w czasie zadania — gdy zabraknie czasu na część zdjęć, moderator widzi przy oznaczeniu, że ocena była niepełna. Zdjęcie, które było jeszcze przygotowywane w chwili publikacji wpisu, jest oceniane, gdy tylko będzie gotowe (o ile wpis nadal jest publiczny) (#829, #830).
- Polityka prywatności ma nową datę stanu — 29 września 2026 — i mówi wprost to, co serwis już robił. Paczka z Twoimi danymi zawiera też obserwowane tagi, ukryte wpisy i osoby, reakcje „Smakowicie wygląda”, listę „Co mam w domu”, Twoje zgłoszenia i korespondencję z nami; tylko na prośbę wydajemy dziennik bezpieczeństwa, zgłoszenia Twoich treści złożone przez inne osoby i notatki moderacji. Ukrycia (domyślnie na 30 dni, prywatne, nie służą do moderacji ani statystyk), reakcja „Smakowicie wygląda” (pod wpisem widać nazwę osoby, która zareagowała) i lista „Co mam w domu” (widzisz ją tylko Ty) mają w polityce własne wiersze. Po usunięciu konta zdarzenia z analizy działania serwisu przestają być z nim powiązane i znikają same po 90 dniach (#1324). Polityka podaje też, że zdjęcia leżą w części Cloudflare R2 zastrzeżonej dla Unii Europejskiej, z datą sprawdzenia 25 września 2026 (#619). W regulaminie i polityce zwroty z „sam” zastąpiliśmy neutralnymi. Zgody udzielone wcześniej zostają ważne i dalej wskazują brzmienie, na które je dano (#1816).
- W Planerze przy każdym dniu tygodnia jest pole „Nazwa przepisu” i przycisk „Szukaj przepisu”. Znalezione przepisy dodajesz do tego dnia przyciskiem „Dodaj do planu”, bez wchodzenia na stronę przepisu; po dodaniu wracasz do tego samego dnia z tą samą frazą. Widok tygodnia ma własny limit odczytu (60 żądań na minutę), a błąd dodania pojawia się przy dniu, z którego przyszedł, nie przy dniu z adresu strony (#2037). [nowa funkcja]
- Naprawione: zapis przepisu (szkic, publikacja albo edycja) rozpoczęty przed zawieszeniem, zablokowaniem lub usunięciem konta nie zapisze już niczego po zatwierdzeniu tej decyzji. Serwis sprawdza aktualny stan autora pod blokadą przed zapisem i wycofuje zdjęcia, składniki, wersję i wpis w strumieniu odrzuconej próby; zapis, który uzyskał blokadę pierwszy, kończy się normalnie. Odczyt przepisu ze zdjęcia przyjęty przed sankcją nie dopisze już treści do szkicu (#2189).
- Kod zapasowy przepisany z kartki przechodzi także ze spacją zamiast myślnika, bez myślnika albo małymi literami. Dotyczy to nowych i wydanych wcześniej kodów — żaden komplet nie został unieważniony. Litery i cyfry nadal muszą się zgadzać: O w miejscu 0 to inny kod.
- Mapa strony dla wyszukiwarek podaje teraz wszystkie publiczne strony wejściowe: Poradźcie (gdy dział pytań jest włączony), Tagi, O Kuking, Regulamin, Prywatność i „Napisz do nas” (#1032). Data zmiany profilu w mapie uwzględnia też publikację, edycję, ukrycie i usunięcie publicznych wpisów i przepisów autora (#1280).
- Usunięcie komentarza sprawdza teraz stan konta osoby usuwającej dopiero w chwili zapisu. Jeśli zawieszenie, ban albo żądanie usunięcia konta zostanie zatwierdzone w trakcie takiego żądania, komentarz zostaje nietknięty: bez śladu usunięcia, bez zastąpienia treści komunikatem i bez powiadomienia dla jego autora (#2190).
- Wewnętrzne: błędny kod 2FA lub kod zapasowy przy logowaniu przez API aplikacji zostawia w dzienniku audytu ten sam wpis `account.two_factor_login_failed` co na stronie, z kanałem `api` w metadanych (na stronie: `www`). Zapis jest teraz w jednej akcji sprawdzającej kod, więc żaden kanał go nie pominie; odpowiedzi odcięte limitem prób nadal nie dopisują wierszy, a w dzienniku nie ma kodu, sekretu ani surowego adresu IP (#2199).
- Gość może kliknąć „Zapisz do zeszytu” przy przepisie, założyć konto (hasłem, przez Google lub Facebooka) albo się zalogować i wrócić na ten sam przepis z rozwiniętym wyborem zeszytu. Niczego nie zapisujemy za człowieka — przepis trafia do zeszytu dopiero po jego własnym wyborze, a przepis, który w międzyczasie przestał być dostępny, nie otwiera wyboru (#2028). [nowa funkcja]
- Naprawione: rozstrzygnięcie zgłoszenia i przywrócenie ukrytej treści (także przyciskiem „Przywróć” w kolejce zgłoszeń) sprawdzają rolę moderatora pod tą samą blokadą co zmiana roli. Moderator zdegradowany w trakcie operacji nie zapisze już decyzji, a administrator zdegradowany do moderatora nie ukarze konta moderatora ani nie cofnie decyzji administratora (#2086).
- Nowe w zeszycie: „Co mam w domu” i „Co ugotuję z tego, co mam?”. Wpisujecie, co macie w kuchni — jeden produkt naraz, z podpowiedziami ze składników z przepisów — a Kuking pokazuje przepisy, do których brakuje Wam najmniej, z dopiskiem „Masz 5 z 7 składników. Brakuje: …”. Kolejność jest jedna i napisana na ekranie: najpierw przepisy, w których brakuje najmniej składników, przy remisie te krótsze w przygotowaniu. Popularność przepisu nie ma na nią wpływu. Listę widzicie tylko Wy, jest w paczce z danymi i znika razem z kontem (D-285). [nowa funkcja]
- Naprawione: w „Co ugotuję z tego, co mam” mąka z Waszej listy nie zalicza już przepisu z makiem, a mak — przepisu z mąką (oba słowa były traktowane jak jeden produkt). Krótkie nazwy produktów porównujemy teraz według słownika odmian (mąka, mąki, mąkę; mak, maku; ser, sera …), a te, których słownik nie zna, pasują tylko w tej samej formie — lepiej pokazać składnik jako brakujący niż podsunąć przepis, do którego czegoś nie macie (#1969).
- Naprawione: limit 150 produktów na liście „Co mam w domu” dało się przekroczyć dwoma równoległymi żądaniami (np. dwie karty naraz) — sprawdzenie liczby produktów i zapis nie były atomowe. Dodanie produktu bierze teraz blokadę wiersza właściciela listy, więc równoległe żądania stają w kolejce: dokładnie jedno z dwóch dodań przy koncie na 149 produktach się udaje, drugie dostaje dotychczasowy polski komunikat o limicie. Zmierzone testem na dwóch prawdziwych połączeniach do PostgreSQL (#1958).
- „Szukaj w moich zeszytach” znajduje teraz zapisane przepisy także po składniku, nie tylko po tytule: fraza „cukinia” pokaże przepisy z Waszych zeszytów, które mają cukinię na liście składników. Gdy tytuł nie zawiera frazy, wynik podpisuje „Pasuje przez składnik”. Widoczność bez zmian — wynik nie zdradza przepisów, których nie możecie dziś otworzyć; szukanie nie zależy od liczby składników (jedno zapytanie) (#2068). [nowa funkcja]
- Kreator przepisu: gdy podsumowanie błędów wskazuje wybór „Jak trudny jest ten przepis?”, „Kto ma widzieć ten przepis?” albo „Ten przepis jest…”, kliknięcie komunikatu przenosi teraz do tej grupy i ustawia na niej fokus, a komunikat jest odczytywany razem z grupą. Wcześniej odnośnik nie prowadził nigdzie. Karta z kreatorem otwarta przed tą zmianą poprosi o odświeżenie strony, zamiast zapisać przepis ze zgubionymi ustawieniami; wszystko, co zapisało się wcześniej, zostaje (#1387).
- Zeszyt ustawiony na „Wszyscy” otwiera się teraz także bez konta — można wysłać link rodzinie. Osoba niezalogowana widzi w nim tylko publiczne przepisy i wpisy, a zamiast przycisków zapisu dostaje „Zaloguj się” albo „Załóż konto”. Zeszyt ustawiony na „Tylko ja” (także domyślny, dopóki nie zmienisz go na „Wszyscy”) nadal widzi tylko właściciel. [nowa funkcja]
- Wyniki wyszukiwania nie są już blokowane w robots.txt, dzięki czemu wyszukiwarka może odczytać, że nie należy ich indeksować.
- Jeśli jeszcze nikogo nie obserwujecie, na Starcie widzicie teraz także własne opublikowane wpisy — również te „tylko dla obserwujących” — razem z wpisami z Waszych tagów albo ze „Świeżo z Kuking”, w kolejności od najnowszych. W „Świeżo z Kuking” Wasze wpisy podlegają tej samej regule co wpisy każdej innej osoby — najpierw stoi Wasz najnowszy wpis, obok najnowszych wpisów innych. Nagłówek mówi wtedy, że to Wasze wpisy i wpisy innych. Inne osoby nadal nie widzą wpisu „tylko dla obserwujących”, jeśli Was nie obserwują (#1318).
- Na szerokim ekranie pierwsze, największe zdjęcie kolażu na stronie powitalnej zaczyna się wczytywać od razu, a nie dopiero po ułożeniu strony. Pozostałe zdjęcia kolażu wczytują się jak dotąd (#957).
- Automat wydajności oblewa ekran, na którym największy element wczytuje się dłużej niż 4 sekundy, nawet gdy łączny wynik wydajności jest wysoki. Raport pokazuje przy każdym ekranie także odległość do celu 2,5 s (#1029).
- Na telefonach z wycięciem ekranu (np. iPhone) i w aplikacji dodanej do ekranu głównego tło strony sięga do krawędzi, a górny pasek, dolna nawigacja i przycisk wyglądu nie wchodzą pod pasek stanu, wycięcie ani wskaźnik Home — także w poziomie (#987).
- Czytnik ekranu przy polu wyboru zdjęcia odczytuje teraz także komunikat błędu, a nie tylko podpowiedź — we wpisie, pytaniu, „Ugotowałem”, formularzach przepisu i w kreatorze. Pole ze złym plikiem jest oznaczone jako wymagające poprawy (#1572).
- Pod spodem, bez zmian na ekranie: na serwerze próbnym (staging) przeoczenia w dostępie do danych — dociąganie powiązanych danych po jednym, odczyt niepobranej kolumny i pole odrzucone przy zapisie — trafiają do dziennika jako ostrzeżenie, zamiast przechodzić bez śladu. Strona działa przy tym tak samo jak dotąd; na serwerze produkcyjnym nic się nie zmienia (#976).
- Przepis wydrukowany z przeglądarki (Ctrl+P) mieści się czytelnie na kartkach A4: zostają tytuł, autor, adres przepisu, porcje, składniki z grupami i uwagami, wszystkie kroki i „Skąd ten przepis”. Na papier nie idą już menu, górna i dolna belka, przyciski ani komentarze, motyw ciemny drukuje się czarnym na białym, a krok nie przełamuje się między stronami. Długi przepis zajmuje 4 strony zamiast 8–9, a żaden napis na kartce nie jest mniejszy niż 12 punktów. Przy przepisie jest też przycisk „Drukuj przepis”, który od razu otwiera okno drukowania; gdy przeglądarka nie wczyta skryptu, ten sam przycisk pokazuje, jakie klawisze nacisnąć albo co wybrać w menu telefonu (#765). [nowa funkcja]
- Polityka prywatności opisuje teraz sesję logowania: zapisujemy zgrubny adres IP i to, jak przedstawia się przeglądarka, do 30 dni od ostatniej aktywności. Mówi też, że w magazynie Cloudflare R2 oprócz zdjęć leżą paczki z Waszymi danymi (najwyżej 7 dni) i zaszyfrowane kopie bazy (najwyżej 30 dni). Z wpisów, które na stałe dokumentują usunięcie konta, skrót adresu IP znika po 12 miesiącach.
- Dziennik serwera zapisuje przy każdym wejściu ten sam adres, którego serwis używa do limitów prób i śladu w dzienniku zdarzeń. Wcześniej serwer brał adres z części nagłówka, którą może wpisać sam odwiedzający, więc ślad po nadużyciu mógł prowadzić pod cudzy adres (#1306).
- „Czeka na odpowiedź (N)” na `/pytania` nie jest już liczone od zera przy każdym wejściu. Liczba dla gości jest przeliczana w tle, po każdej odpowiedzi i zmianie pytania oraz co 5 minut, a zalogowanej osobie doliczamy dokładną poprawkę: pytania i odpowiedzi osób w blokadzie oraz pytania „dla obserwujących”. Licznik pokazuje dokładnie tyle, ile jest na liście „Czeka na odpowiedź”. W pomiarze na 200 000 wpisów koszt odsłony spadł ze 95–150 ms do ułamka milisekundy dla gościa i do ok. 13 ms dla zalogowanej osoby (#372).
- Dopisek autora pod własnym pytaniem nie liczy się już jako odpowiedź na liście „Poradźcie”, w liczbie odpowiedzi ani w „Czeka na odpowiedź”. Pytanie zostaje w oczekujących, dopóki nie odpowie ktoś inny — tak jak w kolejce gospodarza (#372).
- Dane strukturalne `QAPage` na stronie pytania (`answerCount`, `suggestedAnswer`) też już nie liczą dopisku autora pod własnym pytaniem jako odpowiedzi — te same reguły, co na liście „Poradźcie” i w kolejce gospodarza (#372).
- Lista „Poradźcie” i licznik „Czeka na odpowiedź” mają indeks częściowy na opublikowanych pytaniach (`posts_questions_published_idx`). Pomiar przed włączeniem działu (200 000 wpisów, 5% pytań) pokazał pełny przegląd tabeli wpisów przy każdym wejściu na `/pytania`; z indeksem licznik dla zalogowanej osoby spadł z ok. 450 do ok. 120 ms. Skrypt pomiaru (`scripts/pomiar-pytan-372.py`), wyniki i instrukcja włączenia oraz wyłączenia działu: `docs/product/WLACZENIE_PYTAN_372.md`. (#372).
- Panel „Bez odpowiedzi”: mediana czasu oczekiwania na odpowiedź w zakładce „Wpisy” liczy już tylko dania. Wcześniej wliczały się do niej pytania, więc szybko obsłużone pytanie zaniżało czas reakcji na wpisy. Zakładka „Pytania” pokazuje własną medianę — liczoną do pierwszej głównej odpowiedzi innej osoby, bez dopisków pod cudzym komentarzem (#372).
- Panel „Bez odpowiedzi”: pytanie bez odpowiedzi stoi już tylko w zakładce „Pytania”, a nie jednocześnie w „Wpisach”. Powiadomienie gospodarza o pierwszej publikacji nowej osoby nadal przychodzi raz na osobę, ale gdy tą publikacją jest pytanie, mówi „pierwsze pytanie w Kuking” i prowadzi do zakładki „Pytania”. Gdy dział pytań jest wyłączony, takie powiadomienie nie ma przycisku „Zobacz”, zamiast prowadzić do nieistniejącej strony (#372).
- Pod pytaniem w „Poradźcie” jest teraz sekcja „Inne pytania na ten temat” z odnośnikami „Pytania: <tag>”. Prowadzą do listy pytań z tym tagiem (`/pytania?tag=…`), a nie do ogólnej strony tagu z daniami. Na liście tag zostaje widoczny, można go zdjąć odnośnikiem „Pokaż wszystkie tagi”, a „Czeka na odpowiedź” zawęża pytania bez gubienia tagu (#372). [nowa funkcja]
- W panelu (tylko dla admina) jest ekran „Metryki doboru”: ile pierwszych wpisów dostało odpowiedź w ciągu doby, ile osób publikuje ponownie, ilu różnych autorów pisze dziennie, jaką część wpisów piszą najaktywniejsi, ile wpisów ma tag i ilu autorów praktycznie nie trafia na pierwszą stronę „Świeżo z Kuking”. Same liczby — bez nazw osób i bez śledzenia, kto co oglądał (#1814).
- Nowa strona „Jak dobieramy wpisy” opisuje każdą listę w serwisie: Start, „Świeżo z Kuking”, tablicę na dziś, wyszukiwarkę i tygodniowy e-mail — oraz czego nie robimy (nie układamy wpisów według popularności ani reakcji i nie uczymy się Waszego gustu z tego, co oglądacie). Pod nagłówkiem „Świeżo z Kuking” stoi odnośnik „Skąd te wpisy i jak to zmienić”, a gdy coś ukrywacie — „Ukrywasz wpisy N osób. Zmień”. Pozycje na tablicy wybrane przez gospodarza mają napis „Wybór gospodarza” (#1811). [nowa funkcja]
- Gdy zmieniamy politykę prywatności albo regulamin w sposób istotny, nowa wersja obowiązuje 14 dni po opublikowaniu, a do tego dnia obowiązuje poprzednia. Pasek o zmianie regulaminu mówi wtedy, od kiedy obowiązuje nowa wersja. Drobne poprawki, które nie zmieniają Waszych praw ani obowiązków, obowiązują od razu. Zgoda na e-maile zapisuje wersję polityki, która obowiązuje w chwili zgody (D-327).
- Zmieniliśmy regulamin: w punkcie 2 jest opis doboru wpisów i odnośnik do nowej strony. Po zalogowaniu zobaczycie raz pasek „Zmieniliśmy regulamin” z odnośnikiem do tego, co się zmieniło; przycisk „Zamknij” chowa go na dobre. Nie wysyłamy o tym e-maili (#1811).

## Alfa 0.75 — szukanie w wykonaniach i pewniejsze „Ugotowałem”

- Po zalogowaniu albo założeniu konta z linku pod komentarzami (także przez Google lub Facebooka) wracacie do tej samej rozmowy, zamiast szukać jej od nowa (#2027).
- Pilne zgłoszenie od człowieka ma trwały ślad wysyłki alarmu. Potwierdzoną odmowę dostawcy dla nadal otwartej sprawy z ostatnich 72 godzin system może bezpiecznie ponowić w granicach budżetu poczty; niepewny wynik lub przerwany worker wymaga ręcznego sprawdzenia, bez ryzyka automatycznego duplikatu (#2169).
- Podgląd przepisu bez składników wyjaśnia teraz spokojnie, że można je dopisać później; nie pokazuje braku składników jako błędu, bo nie blokuje on publikacji (#1991).
- Wewnętrzne: każda rzeczywiście sprawdzona i błędna próba kodu 2FA lub kodu zapasowego przy logowaniu zostawia w dzienniku audytu wpis `account.two_factor_login_failed` (rodzaj: `totp`, `zapasowy` lub `oba`; adres IP tylko w postaci skrótu). Wpis nie zawiera kodu ani sekretu, a odpowiedzi odcięte limitem prób nie dopisują wierszy (#2042).
- Wewnętrzne: rezerwacja budżetu AI bierze blokadę miesiąca przed wierszem dnia, a suma miesiąca obejmuje cały miesiąc kalendarzowy. Dwa odczyty z różnych dni tego samego miesiąca (np. tuż przed i tuż po północy) nie przekroczą już razem miesięcznego limitu kosztu (#2013).
- Paczka Twoich danych zachowuje przy każdym przepisie wybór, czy pokazywać szacunkowe wartości odżywcze. Po przeniesieniu danych można odróżnić celowo ukrytą sekcję od domyślnie widocznej (#1993).
- Na własnej zakładce „Ugotowane” w profilu jest pole „Szukaj w moich wykonaniach”. Wpisujecie kawałek tytułu przepisu i widzicie tylko pasujące wykonania; polskie znaki nie mają znaczenia („zurek” znajdzie „Żurek”). Pole działa bez JavaScriptu, ma podpowiedź i wyraźny komunikat, gdy nic nie znaleziono, a „Pokaż więcej” zachowuje wpisaną frazę. Pole widać tylko na własnym profilu, gdy macie już jakieś wykonania (#2070). [nowa funkcja]
- Komunikaty błędów w formularzu zgłoszenia treści niezgodnej z prawem, w „Napisz do nas” i przy zdjęciu kroku przepisu nazywają pole tak, jak stoi na ekranie — zamiast „target url” albo „steps.0.photo”. Źle wpisane obecne hasło przy zmianie adresu e-mail dostaje wskazówkę: „Wpisz swoje obecne hasło — to, którym logujesz się dziś” (#1711).
- Przycisk „Wygląd” w rogu ekranu nie zasłania już linku ani przycisku, w który chcecie kliknąć myszą albo dotknąć palcem. Gdy leży na środku takiego miejsca, przesuwa się na dół strony, a po dalszym przewinięciu wraca do rogu (#684).
- Formularz zgłoszenia treści niezgodnej z prawem mówi wprost, co zrobić bez adresu strony: w pole adresu można wpisać, gdzie widać tę treść — na przykład tytuł przepisu i nazwę autora. Wcześniej podpowiedź kazała opisać to „poniżej”, a puste pole kończyło się błędem (#1710).
- Panel moderacji, „Tagi promowane”: „Zdejmij z promowanych” i „Usuń to wyróżnienie” (tag tygodnia) najpierw pytają i mówią, co zniknie — tag i jego wpisy zostają. Dopiero „Tak, …” wykonuje akcję; wcześniej jedno kliknięcie usuwało od razu (#1827).
- Zdjęcia dań w tablicy „kuKINGi na dziś” i zdjęcia na kartach w spisie tagów wczytują się w rozmiarze dopasowanym do miejsca i ekranu. Telefon o zwykłej gęstości pikseli nie pobiera już pliku 960 px do małej karty, a ekran o wysokiej gęstości nadal dostaje ostre zdjęcie (#1310, #1326).
- „Ugotowałem” nie zapisuje już wykonania ani nie powiadamia autora, jeśli w trakcie wysyłania przepis stał się prywatny, został ukryty albo zdjęty przez moderację, konto autora zbanowano lub oznaczono do usunięcia, konto kucharza zawieszono albo między nimi pojawiła się blokada (#2017).
- W formularzu „Ugotowałem” zdanie „Kto to zobaczy?” stoi teraz nad polem zdjęcia i notatką, a nie tuż nad przyciskiem „Wyślij”. Dowiadujecie się, kto zobaczy wykonanie, zdjęcia i odpowiedzi, zanim napiszecie osobistą uwagę. Zdanie mówi teraz także, że wgląd mogą mieć moderatorzy; zasady widoczności bez zmian (#2071).
- Pusta lista powiadomień wymienia teraz także przypomnienia o urodzinach obserwowanych osób, bo takie powiadomienia naprawdę do Was przychodzą (#2060).
- Strona główna ma limit zapytań dla gościa (120 na minutę z jednego adresu), tak jak „Odkryj”, żeby automaty nie obciążały serwisu. Zwykłe przeglądanie go nie osiąga. Gdy ktoś go przekroczy, strona z komunikatem o zbyt wielu zapytaniach ma przycisk „Zobacz dania i przepisy”, który prowadzi do „Odkryj” (#1952).
- Po jednoczesnym kliknięciu „Wygeneruj nowe kody” w dwóch oknach pokaże się tylko jeden komplet kodów zapasowych — ten, który naprawdę działa. Drugie okno dostaje wyjaśnienie, co zrobić, zamiast kodów, które już nie działają (#2057).
- Ekran „Twój zeszyt” otwiera się szybciej przy wielu zeszytach i długiej historii zapisów. Liczniki na kartach zeszytów i pasek „Ostatnio zapisane” pokazują to samo co dotąd; prywatność i kolejność bez zmian (#2030).
- Wewnętrzne: sprzątanie starych eksportów danych nie usuwa już paczki eksportu, który w tej samej chwili został ponowiony. Sprzątanie i ponowienie biorą wspólną blokadę rekordu, więc eksport oznaczony jako gotowy nie zostaje bez pliku (#2073).
- Wewnętrzne: zapis do zewnętrznego dziennika wymazań kont próbuje 3 razy, a po ostatniej nieudanej próbie zostawia w logu komplet danych wpisu. Komenda `kuking:dziennik-wymazan --dopisz=<uuid> --zakres=… --kiedy=…` odtwarza wpis ręcznie przed odtworzeniem kopii bazy; instrukcja jest w `docs/infra/KOPIE_I_ODTWORZENIE.md`. To zawęża okno, w którym konto mogło wrócić po odtworzeniu kopii, ale nie zamyka go całkowicie (#2038).
- Wewnętrzne: po udanym zapisie zdjęcia z formularza z dysku tymczasowego Livewire znika plik źródłowy i towarzyszący mu plik z nazwą oryginału. Przy błędzie formularza zdjęcie zostaje do ponowienia. Błąd usuwania nie psuje zapisu zdjęcia; zostaje ostrzeżenie w logu bez nazwy pliku (#2178).
- Wewnętrzne: dane dla wyszukiwarek (JSON-LD `Recipe`) zawierają `dateModified` — datę ostatniej zmiany treści przepisu, nie ostatniego zapisu wiersza. Nowa kolumna `recipes.tresc_zmieniona_at` przesuwa się tylko po realnej zmianie treści lub zdjęć, nie po moderacji, zmianie widoczności ani zapisie bez zmian. Przepisy sprzed migracji nie mają tej daty, więc pole się dla nich nie pojawia (#2014).
- Wewnętrzne: w podziale na osobne usługi worker i scheduler przed startem czekają (do 900 s), aż usługa web wykona oczekujące migracje, zamiast ruszać na starym schemacie. Po przekroczeniu limitu proces kończy się błędem i Railway go ponawia; `MIGRACJE_BRAMKA=0` wyłącza bramkę awaryjnie (#2044).
- Wewnętrzne: Dependabot proponuje aktualizacje major dla Composera i npm osobnymi pull requestami zamiast je ignorować. Obraz bazy danych w bloku `docker` nadal celowo pomija major, bo musi zgadzać się z serwerem (#2009).

## Alfa 0.74 — zdjęcia w formularzu i porcje przy gotowaniu

- Po rejestracji z końca trybu gotowania i pominięciu pierwszych kroków wrócisz do formularza „Ugotowałem” właściwego przepisu. Samo wykonanie nadal wymaga Twojego wysłania (#2058).
- Zdjęcia wybrane przy dodawaniu lub edycji przepisu zostają w formularzu po błędzie innego pola. Nie trzeba ich wybierać ponownie po poprawieniu tytułu czy składników (#2050).
- Wybrana liczba porcji nie znika po wejściu w tryb gotowania: składniki przy krokach są przeliczone tak samo jak na stronie przepisu, a wybór zostaje przy zmianie kroku, odhaczaniu, rozpoczęciu od początku i powrocie do przepisu (#1984). [nowa funkcja]
- Rozpatrzenie odwołania z blokadą konta nie zakleszcza się już z równoczesną edycją przepisu. Obie czynności kończą się bez błędu serwera (#2165).
- Bramka wdrożenia nie ponawia automatycznie niejednoznacznego żądania do Railway; wymaga uzgodnienia stanu, żeby nie uruchomić drugiego wdrożenia (#2048).

## Alfa 0.73 — import przepisu i wygodniejsze gotowanie

- Dalsze strony wpisów pod tagiem mają własny adres dla wyszukiwarek i udostępniania. Adres wskazuje tę samą porcję wpisów, którą oglądacie, zamiast wracać do pierwszej strony (#2135).
- Bezpieczeństwo importu przepisu z adresu strony: adres z kropką na końcu nazwy (`przepisy.example.pl./`), wielkimi literami, z polskimi znakami albo z adresem IP zapisanym liczbą, ósemkowo lub szesnastkowo trafia teraz do pobierania w jednej, sprawdzonej postaci. Wcześniej końcowa kropka omijała przypięcie sprawdzonego adresu IP i serwer mógł drugi raz zapytać DNS, czyli połączyć się z adresem, którego nikt nie sprawdził. Dodatkowo pobieranie przerywa połączenie z adresem innym niż sprawdzony, zanim wyśle zapytanie, liczy limit 2 MB w trakcie pobierania i naprawdę idzie przez cURL — wcześniej opcja strumieniowania wyłączała przypięcie całkowicie (#1978).
- Przepis ze strony internetowej albo z pliku PDF można zapisać jako szkic: na ekranie „Dodaj przepis” są przyciski „Wklej adres strony” i „Dodaj plik PDF”. Szkic widzisz tylko Ty, adres strony zostaje przy przepisie jako źródło i nie da się go zmienić, a zdjęć ze strony nie pobieramy. Przed publikacją trzeba zaznaczyć „Sprawdziłem odczytany tekst”; gdy opis przygotowania jest prawie taki sam jak na stronie, pokazujemy ostrzeżenie, żeby napisać go własnymi słowami. Strony, które nie pozwalają pobierać przepisów, szanujemy — wtedy zapisujemy sam adres i mówimy, co zrobić. Dziennie można odczytać 5 przepisów, miesięcznie 30. PDF-y z tekstem odczytujemy u siebie; skanowane strony może odczytać komputer OpenAI po osobnej zgodzie. Tekst strony bez danych przepisu może trafić do modelu tylko za zgodą. Wszystkie drogi mają wspólny limit 5 dziennie i 30 miesięcznie oraz budżet AI (D-300). [nowa funkcja]
- W trybie „Gotuję” możesz odhaczać składniki, które już masz odmierzone. Zaznaczenie zostaje w tej karcie przeglądarki, także po przejściu do kolejnego kroku, i nie rusza odhaczonych kroków (#2069). [nowa funkcja]
- Ekrany logowania i zakładania konta wykorzystują szerokość komputera: obok formularza widać od razu wejście przez Google lub Facebooka, a przy logowaniu także drogę przez link wysłany e-mailem. Na telefonie wszystko układa się w jedną kolumnę; żadne pole ani komunikat nie znika.

## Alfa 0.72 — spokojniejsze zdjęcia i powiadomienia

- Zdjęcie, którego przygotowanie chwilowo się nie udało, dalej pokazuje „Twoje zdjęcie się jeszcze przygotowuje”, dopóki serwis próbuje ponownie. Komunikat „Nie udało się przygotować tego zdjęcia” z radą, żeby usunąć i dodać wpis, pojawia się dopiero po ostatniej nieudanej próbie — nie trzeba już kasować wpisu ze zdjęciem, które za chwilę by się pokazało (#1349).
- Powiadomienie o wpisie usuniętym po „Smakowicie wygląda” pokazuje informację zamiast prowadzić do strony 404 (#1994).
- Kopiowanie tygodnia w Planerze pomija pozycje spoza dozwolonego zakresu dat i wyjaśnia, ile ich było, dlaczego oraz co zrobić dalej (#2036).

## Alfa 0.71 — przepis z kartki i koszt dania

- Na stronie „Co nowego” spis wydań prowadzi do właściwych nagłówków, a znaczniki kotwic nie pojawiają się już jako tekst. W stopce jest też czytelna spacja między „O” a nazwą serwisu.
- API dla aplikacji: lista komentarzy wpisu i przepisu niesie przy każdym wątku najwyżej trzy pierwsze odpowiedzi, liczbę wszystkich widocznych odpowiedzi (`replies_count`) i adres dalszych (`more_replies_url`). Nowy adres `GET /api/v1/komentarze/{id}/odpowiedzi` oddaje odpowiedzi wątku stronami, od najstarszej, z tymi samymi blokadami i tą samą kontrolą dostępu co komentarz. Wcześniej jeden popularny wątek przychodził w całości, niezależnie od stronicowania (#1970).
- API dla aplikacji: feed pokazuje na kartach wpisów przepisy, które widz może zobaczyć — wcześniej sprawdzenie dostępu w API odrzucało każdy przepis feedu. To sprawdzenie nie pyta już bazy osobno dla każdego wpisu: liczba zapytań na stronę feedu jest stała (#1971).
- API dla aplikacji (logowanie z weryfikacją dwuetapową): wyzwanie z pierwszego kroku działa tylko raz. Po wydaniu tokenu ponowne wysłanie tego samego wyzwania — także równolegle, z innym ważnym kodem — kończy się prośbą o zalogowanie od nowa i nie wydaje drugiego tokenu, więc nie wypycha innych urządzeń z listy. Powtórzone wyzwanie nie zużywa też kodu zapasowego (#1972).
- W ustawieniach jest nowy ekran „Urządzenia z dostępem”. Widać na nim telefony i tablety zalogowane na Wasze konto w aplikacji Kuking — z nazwą, datą zalogowania i tym, kiedy ostatnio były używane — i można każde z nich odciąć jednym przyciskiem (z potwierdzeniem) albo odciąć wszystkie naraz. Zmiana hasła i „Wyloguj mnie z innych urządzeń” odcinają teraz także aplikację. Samo API dla aplikacji jest przygotowane, ale do czasu jej wydania pozostaje wyłączone. [nowa funkcja]
- Pod cudzym wpisem jest przycisk „Smakowicie wygląda” — lżejszy niż „Ugotowałem”. Nikt nie widzi, ile osób go nacisnęło; na stronie wpisu każdy widzi, kto to napisał (bez osób, z którymi jest blokada). Autor dostaje raz dziennie jedno powiadomienie, np. „3 osoby napisały: Smakowicie wygląda”, żeby nie zagłuszało „Ugotowałem”, które przychodzi od razu. Reakcję cofacie tym samym przyciskiem (#1813). [nowa funkcja]
- Przepis można przepisać ze zdjęcia kartki albo zeszytu (V2). „Cały przepis” prowadzi do wyboru: „Przepisz z kartki lub zeszytu” albo „Wpiszę sam”. Zdjęcie odczytuje komputer firmy OpenAI — dopiero po osobnej zgodzie, którą można wycofać w ustawieniach prywatności. Wynik trafia zawsze do prywatnego szkicu, ze zdjęciem kartki obok tekstu; niepewne słowa są oznaczone znakiem [?] i przed publikacją trzeba je poprawić i zaznaczyć „Odczytany tekst jest sprawdzony ze zdjęciem”. Postęp odczytu widać słowami na osobnym ekranie, także bez JavaScriptu. Jest limit 5 odczytów dziennie i 30 miesięcznie na osobę oraz dzienny i miesięczny budżet serwisu; przy limicie albo awarii zdjęcie zostaje zapisane w szkicu. Bez klucza OpenAI do odczytu przycisku po prostu nie ma (D-296, D-297, D-298). Dla osób rozwijających serwis: ekran postępu ma teraz własny limit zapytań (40/min na konto, osobny od zlecenia i ponowienia odczytu), więc zwykłe odpytywanie co 5 s działa bez ograniczeń, a pętla czy przejęta sesja nie generują nieograniczonej liczby zapytań do bazy (#1959). [nowa funkcja]
- Odczyt przepisu ze zdjęcia kartki jest odporny na awarie w połowie drogi: przerwany odczyt nie blokuje już dziennego budżetu serwisu, jedno wywołanie modelu nie jest liczone dwa razy, ponowienie po zapisanej odpowiedzi nie płaci drugi raz, a zlecenie, którego nikt już nie wykona, po dwóch godzinach mówi „Spróbuj jeszcze raz” zamiast bez końca „trwa”. Dla osób rozwijających serwis: księga rezerwacji `ai_rezerwacje` z kluczem (zlecenie, próba), zadanie zapisywane w kolejce w jednej transakcji ze zleceniem i polecenie `kuking:odzyskaj-importy` co kwadrans (D-298, #1973, #1974, #1977, #1980).
- Przy przepisie można podać przybliżony koszt całego dania w złotych — w „Dopisz szczegóły” i w kreatorze, pole nie jest obowiązkowe. Wystarczy wpisać „24” albo „24,50”. Na stronie przepisu widać wtedy „Szacunkowy koszt: ok. 24 zł (wg autora)”, a w wyszukiwarce jest nowy zakres „Do 20 zł”, który pokazuje przepisy z kosztem podanym przez autora do 20 zł. Koszt trafia też do paczki z danymi (D-286). [nowa funkcja]
- Gdy autor nie podał kosztu, strona przepisu może pokazać orientacyjny przedział, np. „Orientacyjny koszt: ok. 5–7 zł za całość (średnie ceny detaliczne GUS z 2025 r.). W Twoim sklepie może być inaczej.” Liczymy go tylko wtedy, gdy znamy ceny składników stanowiących co najmniej 90% masy przepisu; inaczej strona mówi krótko, dlaczego kwoty nie ma. Dla osób prowadzących serwis: cennik wczytuje komenda `php artisan kuking:ceny-skladnikow` z pliku w repozytorium — trzeba ją uruchomić raz po wdrożeniu i po każdej aktualizacji cen (D-286).
- Cennik kosztu dania ma teraz też pięć warzyw — ziemniaki, cebulę, marchew, paprykę czerwoną i pomidory — których GUS dziś nie podaje (jego seria z cenami warzyw kończy się w 2019 r.). Ceny pochodzą z cotygodniowego biuletynu MRiRW/ZSRIR (dane.gov.pl, otwarte dane), a zdanie na stronie przepisu wymienia oba źródła, gdy w daniu jest i mięso, i warzywa: „(średnie ceny detaliczne GUS i MRiRW/ZSRIR z 2025, 2026 r.)”. Dla osób prowadzących serwis: warzywa odświeża osobny skrypt `scripts/ceny-warzyw-zsrir-pobierz.py`, uruchamiany tak samo jak `ceny-gus-pobierz.py` (D-286).
- Cennik kosztu dania ma teraz też siedem warzyw, których ZSRIR notuje wyłącznie hurtowo — kapustę, buraki, por, seler, pietruszkę, sałatę i ogórek. Cena to średnia hurtowa z 5 rynków razy jawnie nazwany przelicznik ×1,6 (decyzja właściciela, D-286), a zdanie na stronie przepisu mówi to wprost: „(…; część cen to szacunek z cen hurtowych MRiRW/ZSRIR)”. Wszystkie 12 warzyw (5 detalicznych + 7 hurtowych) odświeżają się teraz **automatycznie, co tydzień, w jednym PR-ze**, przez workflow GitHub Actions (`ceny-warzyw-auto.yml`), który otwiera zwykły PR do przeglądu — nic nie trafia do `main` bez scalenia przez człowieka, a produkcja nadal niczego nie pobiera z sieci. Dla osób prowadzących serwis: ten workflow wymaga osobistego tokenu (`CENY_WARZYW_PAT`, fine-grained PAT) zapisanego jako sekret repozytorium — bez niego zatrzymuje się jasnym komunikatem, zamiast po cichu otworzyć PR bez działającego CI; minimalne uprawnienia i miejsce do wpisania: `docs/infra/DEPLOYMENT_RUNBOOK.md`, sekcja „Co tydzień” (D-286).
- Naprawione: workflow cen warzyw (`ceny-warzyw-auto.yml`) trzymał osobisty token zapisu (`CENY_WARZYW_PAT`) w tym samym checkoucie, który zaraz potem uruchamiał kod z repozytorium (`scripts/ceny-warzyw-zsrir-pobierz.py`) — skompromitowany skrypt mógł odczytać token z konfiguracji gita i wynieść go poza kontrolę joba. Workflow ma teraz dwa joby: `pobierz` uruchamia skrypt bez tokenu (`persist-credentials: false`), `publikuj` ma token, ale wykonuje wyłącznie polecenia `git`/`gh` zapisane wprost w pliku workflow — żaden kod repozytorium. Nowy strażnik `tests/Feature/WorkflowCenNieUruchamiaKoduZTokenemZapisuTest.php` (#1957).
- Naprawione: przepis z „2 kotletami schabowymi” nie dostawał orientacyjnego kosztu, choć cennik ma cenę schabu za kilogram — „kotlet” był liczony jak „sztuka” (a sztuki schabu nie da się zważyć), a słowo „schabowe” nie trafiało w wiersz schabu. Kotlet jest teraz osobną miarą o masie 120 g, ale tylko dla schabu — to ta sama gramatura, co `schab,kotlet,120` w miarach domowych wartości odżywczych (PR #1900); gdy wspólna tabela miar wejdzie na `main`, koszt ma czytać z niej (D-286), a do tego czasu test pilnuje, żeby liczby się nie rozjechały. „Kotlet z kurczaka” czy „kotlet mielony” nadal nie dostają zgadywanej masy — strona mówi wtedy, czego nie wiemy (#1964).
- Poprawione wskazówki, które prowadziły do nieistniejących przycisków albo obiecywały coś, czego serwis nie robi: szkic przepisu wskazuje przycisk, który naprawdę stoi na stronie („Dopisz szczegóły” albo „Edytuj przepis”); pomoc i strony zgłoszeń mówią, że przy wpisie „Zgłoś ten wpis” jest w menu z trzema kropkami; pomoc nie obiecuje już autorowi przepisu wiadomości, tylko powiadomienie; kreator przepisu mówi, że szkic zapisuje się dopiero po podaniu nazwy; instrukcja hasła przy weryfikacji dwuetapowej obejmuje też osoby logujące się przez Facebooka.
- Na ekranach awarii (500 i 503) przycisk, który prowadzi na stronę główną, nazywa się „Strona główna”, a nie „Spróbuj jeszcze raz”. W pliku „Czytaj to najpierw” w paczce danych poprawiona literówka „weszedł”.
- Ekran „Czytelność” pokazuje podsumowanie błędów na górze, tak jak pozostałe ustawienia. Linki do poprzedniego i następnego wpisu rosną razem z wybranym rozmiarem tekstu.
- Odnośniki na stronie powitalnej, które zapowiadają obejrzenie funkcji, nie odsyłają już gościa do logowania. „Zobacz, jak dodać zdjęcie” i „Zobacz, kto może widzieć wpis” prowadzą do odpowiednich pytań w Pomocy, „Zobacz przepisy innych” do publicznych przepisów, a karta o zeszycie nie udaje już, że można do niego zajrzeć bez konta.
- Zdjęcie, którego nie udało się zapisać na dysku, nie udaje już gotowego. Wcześniej przy takiej awarii serwis potrafił pokazać wpis z pustą ramką zamiast obiadu — teraz zdjęcie dostaje uczciwy stan „przygotowuje się” albo zostaje odrzucone i przetwarzanie próbuje jeszcze raz.
- Menu nie podpowiada już, że cudza rzecz jest Wasza. „Mój zeszyt” i „Moje” świecą tylko przy Waszych zeszytach, a „Profil” — na Waszym profilu i na liście Waszych obserwujących i obserwowanych. Cudzy publiczny zeszyt i cudzy profil nie podświetlają żadnej z tych pozycji.
- Komunikat po akcji mówi od razu, czy się udało. Potwierdzenie ma zieloną ramkę i napis „Gotowe”, zwykła informacja — żółtą i napis „Informacja”, a odmowa albo błąd — czerwoną ramkę i napis „Nie udało się”. Dotyczy to na początek wejścia kontem Google i Facebooka, logowania linkiem, zmiany adresu e-mail, usuwania zdjęcia profilowego i obserwowania tagu.
- Na ekranie „Komuś wyszło” zdjęcie wykonania ma opis dla czytnika ekranu. Gdy przy zdjęciu nie ma opisu od osoby, która gotowała, czytnik mówi „Zdjęcie wykonania”, także przy przycisku powiększenia — wcześniej zdjęcie było dla niego puste.

## Alfa 0.70 — spokojniejsze wpisy i gotowanie

- Formularz kontaktowy nie zapisuje już sekretów z adresu strony w kontekście wiadomości. Dla starszych wpisów przygotowano osobną, świadomie uruchamianą komendę czyszczenia (#836).
- Wewnętrzne: cofnięcie migracji wersji przepisu blokuje nowe zapisy przed sprawdzeniem istniejących wersji, aby nie zgubić ich pochodzenia podczas rollbacku (#2059).
- Po chwilowym błędzie Web Push ponowienie sprawdza, czy powiadomienie nadal jest nieprzeczytane i widoczne. Przeczytane lub ukryte nie wychodzi ponownie, a pozostałe dostają neutralny komunikat bez starego imienia i tytułu przepisu (#2052).
- Przed wysłaniem „Ugotowałem” widać, kto może zobaczyć wykonanie, zdjęcia i odpowiedzi. Ich widoczność wynika z widoczności przepisu (#2071).
- Wewnętrzne: instrukcja wdrożenia opisuje czas zamykania usług zależnie od topologii IaC i oddziela go od niepotwierdzonych ustawień panelu Railway (#2056).
- Pusta lista powiadomień wyjaśnia teraz, że znajdziecie tu wiadomości o przepisach, wpisach, obserwujących i ważnych sprawach konta. Wcześniejszy opis wymieniał tylko ugotowanie i komentarze (#2060).
- Niepoprawny identyfikator w adresie przycisku „Zobacz” powiadomienie kończy się teraz zwykłą stroną 404, zanim trafi do bazy danych; poprawny identyfikator nieistniejącego powiadomienia nadal daje 404 (#1880).
- Cofnięcie zgłoszonego usunięcia konta, odblokowanie kogoś oraz zamówienie, potwierdzenie i anulowanie zmiany adresu e-mail nie kończą się już błędem serwera, gdy zawiedzie tylko zapis w wewnętrznym dzienniku audytu — operacja, którą naprawdę wykonaliście, zostaje wykonana, a brak wpisu trafia do monitoringu zamiast do Was (#1893, #1896, #1897). Automatyczne przywracanie kont po wygasłej karze i trwałe usuwanie danych po karencji (#1894) w takiej samej sytuacji cofają całą zmianę i podejmują ją same przy najbliższym uruchomieniu, a jeden nieudany rekord nie zatrzymuje już obsługi pozostałych kont w tym samym przebiegu.
- Naprawione: po częściowym odtworzeniu bazy import wartości odżywczych odbudowuje brakujące składniki, aliasy i miary. Kalkulator nie zostaje z niepełnym słownikiem tylko dlatego, że pamięć przechowała hash poprzedniego importu (#2130).
- Wewnętrzne: słownik wartości odżywczych ma rosnący numer wersji danych (`database/data/odzywcze/WERSJA`). Starszy import, na przykład z wycofanego wdrożenia, nie nadpisuje nowszych danych — ostrzega w logu i kończy, o ile baza jest kompletna; niekompletną odbudowuje zawsze. Test oblewa, gdy CSV zmieniono bez podbicia wersji (#2130, D-330).
- Wewnętrzne: pilny alarm o zgłoszeniu od człowieka odzyskuje próbę po awarii zapisu do kolejki bez utraty blokady celu i budżetu poczty. Ponowienie tej samej sprawy tworzy najwyżej jedno zadanie, także gdy odpowiedź kolejki była niepewna (#2066).
- Zdjęcia wyświetlane „Zwykle” stoją jedno pod drugim także na tablecie. Dwa zdjęcia o różnych proporcjach nie zostawiają już szarego pasa, a trzecie nie zostawia pustej prawej połowy. Kolaż i karuzela pozostają osobnymi wyborami (#2126).
- Przełącznik wartości odżywczych sprawdza aktualny stan przepisu pod blokadą. Żądanie rozpoczęte przed zdjęciem lub usunięciem przepisu nie zapisze ustawienia po decyzji moderatora i pokaże czytelną odmowę (#2112).
- Eksport HTML przepisu zachowuje dokładną liczbę porcji, także przy dwóch cyfrach po przecinku (#2035).
- Wewnętrzne: kontrola negatywna testów zachowuje dowód niezaliczonej asercji, nawet gdy log zawiera błędne bajty UTF-8; sama awaria procesu nadal nie zalicza kontroli (Refs #1011).
- Naprawione: pusta zakładka „Ugotowane” na własnym profilu pokazuje teraz przycisk „Znajdź przepis” prowadzący do wyszukiwarki. Na profilu innej osoby ten przycisk się nie pojawia (#2054).
- Strona tagu pokazuje każdemu wyłącznie publiczne wpisy. Własne wpisy „tylko dla mnie” i „tylko dla obserwujących” nie pojawiają się tam nawet autorowi; strona wyjaśnia to i prowadzi do „Moje wpisy”, gdzie nadal można je znaleźć (#1338, D-307).

## Alfa 0.69 — czytelniejsze powiadomienia i wygodniejsze gotowanie

- Wewnętrzne: kontrola zdrowia obrazu Docker sprawdza `/health` dla ról HTTP (`web` i `all`), a dla `worker` i `scheduler` nie oczekuje serwera WWW, którego te role nie uruchamiają. Odczytuje rzeczywistą rolę przekazaną entrypointowi, także gdy ma ona pierwszeństwo przed `APP_ROLE` (#2079).
- Wewnętrzne: strażnik bezpiecznych migracji rozpoznaje historię po zamrożonej liście plików, więc nowa migracja z cofniętym datownikiem nadal przechodzi kontrolę DDL (§6); dotychczasowe migracje pozostają bez zmian (#2080).
- Wewnętrzne: obraz aplikacji nie obiecuje już awaryjnego `pg_dump` w wersji 17, który odmawiał zrzutu PostgreSQL 18. Jednorazowy zrzut prowadzi teraz runbook przez osobny obraz kopii z klientem 18, a CI sprawdza prawdziwy zrzut testowej bazy 18 (#2078).
- Przy „Jak to liczymy” na stronie przepisu znów widać trójkąt pokazujący, że wyjaśnienie można rozwinąć. Po otwarciu trójkąt zmienia kierunek; kontrolka nadal działa bez JavaScriptu (#2109).
- Naprawione: powtarzające się przyciski „Zobacz” na liście powiadomień mają teraz dostępne nazwy powiązane z treścią własnej karty, aby można było odróżnić ich cele przy przechodzeniu po kontrolkach. Widoczny napis i działanie przycisków nie zmieniły się (#2062).
- Wewnętrzne: ręczne migracje i migracje przed wdrożeniem Railway korzystają z tej samej blokady PostgreSQL. Gdy jeden przebieg już migruje bazę, drugi kończy się błędem zamiast działać równolegle albo zgłosić pozorny sukces (#2082).
- Obserwowanie osoby albo tagu rozpoczęte przed sankcją nie dopisze relacji po zawieszeniu, zablokowaniu lub zamknięciu konta. Można nadal przestać obserwować istniejące tagi (#2091).
- W wyszukiwarce każda lista znalezionych przepisów lub osób ma własny widoczny nagłówek także po wybraniu zakresu „Przepisy”, „Do 30 minut” albo „Ludzie”. Ułatwia to znalezienie wyników przez nawigację po nagłówkach (#2081).
- Naprawione: zdjęcia w zwykłej siatce wpisu bez opisu autora dostają odróżnialne teksty alternatywne, np. „Zdjęcie 1 z 3 w tym wpisie”. Ten sam opis jest dostępny przy powiększaniu zdjęcia; własny opis autora pozostaje bez zmian (#2065).
- Naprawione: miniatura w wierszowej karcie przepisu ma teraz nazwany link „Zobacz przepis: …”, nawet gdy zdjęcie nie ma opisu. Osobny link tytułu i widok kafelkowy zachowują dotychczasowe działanie (#2063).
- Naprawione: „Przywróć do zeszytu” sprawdza aktualny stan konta, treści i zeszytu w chwili zapisu. Zawieszenie nie pozwala już przywrócić wpisu ani przepisu do publicznego zeszytu z wcześniej otwartej strony; przywracanie wielu pozycji jest jedną operacją. Notatki, daty zapisania i dotychczasowe powiadomienia o zapisaniu przepisu pozostają zachowane. Także wyjęcie niedostępnych pozycji sprawdza świeże uprawnienia właściciela (#2094).
- Połączenie lub ponowne uaktywnienie Facebooka wymaga teraz potwierdzenia obecnego konta Kuking: hasłem albo jednorazowym linkiem na potwierdzony adres. Gdy konto ma włączoną weryfikację dwuetapową, potrzebny jest także dotychczasowy kod. Samo zalogowanie i zgoda na Facebooku już nie dodają trwałej drogi wejścia (#2085).
- Poprawienie komentarza rozpoczęte przed zawieszeniem, zablokowaniem albo zamknięciem konta nie zapisze już nowej treści po zatwierdzeniu tej decyzji. Edycja i sankcja są sprawdzane w jednej kolejności na świeżym stanie konta (#2090).
- Naprawione: operacja moderacyjna lub odpowiedź e-mail rozpoczęta przed odebraniem roli nie zapisze skutku po zatwierdzeniu degradacji. Rozstrzygnięcie odwołania, zdjęcie treści z urzędu i przyjęcie odpowiedzi sprawdzają świeżą rolę pod wspólną blokadą; operacja przyjęta wcześniej może się dokończyć (#2086).
- Naprawione: wpis rozpoczęty przed zawieszeniem, zablokowaniem lub usunięciem konta nie może dokończyć publikacji po zatwierdzeniu tej decyzji. Serwis sprawdza aktualny stan autora pod blokadą przed zapisem i wycofuje także tagi oraz inne skutki odrzuconej próby; publikacja, która uzyskała blokadę pierwsza, kończy się normalnie (#2088).
- Po zmianie lub resecie hasła, wylogowaniu innych urządzeń, blokadzie konta albo zgłoszeniu jego usunięcia odwołana sesja nie wraca już przez zapis wcześniej rozpoczętego żądania. Bieżąca sesja osoby wykonującej operację pozostaje aktywna tam, gdzie powinna (#1046).
- Kolaż kilku zdjęć we wpisie pokazuje teraz fotografie w ich naturalnych proporcjach, bez szarych pustych pasów. Przy nieparzystej liczbie zdjęć ostatnie zajmuje cały wiersz zamiast zostawiać obok pusty kafel. Zdjęcia nadal można powiększyć i żadne nie jest przycinane.
- Wewnętrzne: walidator paczki design systemu v3.1 (`07-wdrozenie/sprawdz-paczke.mjs`) rozpoznaje teraz każdy wariant zamknięcia `<script>` dopuszczony przez parser HTML, nie tylko goły `</script>` — `</script foo="bar">` przechodził wcześniej niezauważony i zostawiał treść skryptu w puli tekstu widocznego dla skanu słów zakazanych (CodeQL js/bad-tag-filter #3, #1910). Funkcja odpowiedzialna za to wydzielona do osobnego, testowalnego pliku (`tekst-widoczny.mjs`) z testem regresyjnym.
- List z życzeniami urodzinowymi jest teraz oznaczany jako wysłany dopiero po tym, jak transport pocztowy przyjął wiadomość — nie w chwili zakolejkowania. Wcześniej awaria wysyłki albo trwała porażka po trzech próbach zostawiały znacznik ustawiony mimo braku listu, a to jest jedyny list w roku dla tej osoby; drugiej szansy tego samego dnia nie było. Nowa kolumna `users.birthday_email_queued_on` pilnuje wyłącznie bariery przed podwójnym zakolejkowaniem tego samego dnia; nieudane zakolejkowanie (rzadkie) zwalnia ją od razu, więc ponowienie komendy tego samego dnia wysyła dokładnie jeden list (#1956).
- Wewnętrzne: cofnięcie migracji `add_no_amount_to_recipe_ingredients` (kolumna `recipe_ingredients.no_amount`, issue #44) odmawia teraz, gdy w bazie są już składniki oznaczone jako „bez wymiernej ilości” — komunikat po polsku mówi ile ich jest i jak wymusić cofnięcie świadomie (`KUKING_ROLLBACK_KASUJE_SKLADNIKI_BEZ_ILOSCI=1`). Na świeżej bazie cofnięcie przechodzi bez pytania (D-088).

- `php artisan kuking:sprzataj-eksporty --dry-run` pokazuje teraz też osierocone obiekty po nieudanych eksportach (paczka wgrana do storage, po której proces padł przed zapisaniem klucza w wierszu) — wcześniej ta część sprzątania działała wyłącznie poza trybem podglądu, więc raport przed operacją destrukcyjną był niekompletny. Górna granica 7 dni w tym sprzątaniu zniknęła: po przerwie harmonogramu albo awarii workera dłuższej niż tydzień rekord wcześniej wypadał bezpowrotnie z jedynego zapytania, które umiało wyliczyć jego klucz, a kopia całego konta mogła zostać w magazynie bezterminowo (#1840, #1842).
- Bezpieczeństwo/infrastruktura: pakiety APT instalowane w Dockerfile-ach (`postgresql-client`, `tini` w obrazie aplikacji; `openssl`, `curl`, `ca-certificates` w obrazie kopii bazy) są teraz przypięte do zamrożonej migawki `snapshot.debian.org`, zamiast schodzić z ruchomego, bieżącego mirrora Debiana — ten sam commit daje więc zawsze ten sam zestaw bajtów, tak jak już gwarantują to przypięte digesty obrazów bazowych (D-316, issue #1868).
- Bezpieczeństwo: instalacja Railway CLI w workflowach wdrożenia i preview (`deploy.yml`, `preview.yml`) idzie teraz wprost z GitHub Releases, z przypiętym numerem wersji i sprawdzaną sumą SHA-256, zamiast przez `npm install -g @railway/cli` — sam pakiet npm i tak tylko pobierał tę samą binarkę bez żadnej weryfikacji integralności. Kroki „Konfiguracja Node” zniknęły, bo nie są już potrzebne (#1865).
- Wewnętrzne/bezpieczeństwo: token API aplikacji mobilnej (trasy z prefiksem API v1) nie jest już wydawany z domyślnym uprawnieniem `*` (wszystko, także trasy, które dopiero powstaną). Zakres tokenu jest teraz zawsze jawną listą z zamkniętego słownika (`App\Http\Api\ZakresyTokenu`: odczyt profilu, odczyt treści, zapis treści) — dodanie nowego zakresu w przyszłości nie rozszerza już wydanych tokenów. Etap 1 API nie miał jeszcze tras produkcyjnych ani wydanych tokenów, więc zmiana nie wymaga migracji danych (#1928, D-320).
- Dla osób rozwijających serwis: publiczna trasa „Odkryj" (`/odkryj`) ma teraz limit zapytań per adres IP gościa, tak samo jak wyszukiwarka i pytania. Zapytanie za tą stroną liczy funkcję okna po wszystkich publicznych wpisach przed odcięciem strony, więc powtarzalne, automatyczne odpytywanie było droższą, niechronioną drogą do obciążenia bazy niż jakakolwiek inna publiczna trasa serwisu. Zwykłe przeglądanie i klikanie „Pokaż więcej" mieści się w budżecie bez zmian (#1952).
- Wewnętrzne: bramka migracji zdjęć (`kuking:zaleznosc-od-starego-bucketu`, `kuking:przenies-zdjecia` i `kuking:sprawdz-zdjecia-po-przenosinach`) wykrywa teraz pustą, `null` albo uszkodzoną `metadata.variants` przy zdjęciach `ready` jako błąd danych — wcześniej taki wiersz wyglądał jak komplet poprawnych wariantów, migrator mógł przestawić `disk` bez potwierdzonych plików, a raport zależności od starego bucketu melduł „każdy sprawdzony wiersz ma swoje pliki", choć nie miał czego sprawdzić (#1905).
- W „Moje” jest nowy przycisk „Moje wpisy”: lista wszystkich Waszych wpisów od najnowszego — także tych tylko dla Was, tylko dla obserwujących, szkiców i wpisów ukrytych przez moderację. Przy każdym wpisie jest napisane, kto go widzi i w jakim jest stanie. Tę listę widzi tylko jej właściciel (D-328). [nowa funkcja]
- Nowa, dobrowolna półka „Mój stół” (adres `/moj-stol`, skrót na Starcie). Jest wyłączona, dopóki jej nie włączycie. Pokazuje najnowsze przepisy z tagów, które obserwujecie, z jednego tagu polecanego przez gospodarza oraz przepisy, które gospodarz wybrał na dziś („kuKINGi na dziś”) — po jednym od osoby, bez tego, co ukrywacie. Przy każdym przepisie jest napisane, dlaczego go widzicie, i przycisk „Nie pokazuj mi tego”. Półka nie układa niczego według liczby polubień ani „Ugotowałem” i nie uczy się z Waszych kliknięć; wyłączycie ją jednym przyciskiem na tej samej stronie. Start i „Świeżo z Kuking” działają jak dotąd (#1749).
- Półka „Mój stół” wykonuje przy każdym wejściu tyle samo zapytań do bazy, niezależnie od tego, ile tagów poleca gospodarz: temat od gospodarza wybiera jedno zbiorcze zapytanie zamiast osobnego zapytania na każdy tag. Półka rozpatruje najwyżej 20 pierwszych tagów z listy gospodarza, a „kuKINGi na dziś” pobiera z bazy najwyżej trzy przepisy zamiast wszystkich wyborów dnia. Zasady doboru się nie zmieniły (#1968).
- Dla osób rozwijających serwis: strona „Mój stół” (`GET /moj-stol`) nie miała limitu zapytań na poziomie trasy. Nowy koszyk `moj_stol` w `config/kuking.php` (ten sam wzorzec i ta sama liczba co `search`, sześćdziesiąt na minutę, osobny prefiks licznika); przełącznik (`PUT /moj-stol`) zostaje przy swoim dotychczasowym koszyku zapisu `ustawienia` (dokończenie #1749, #1968).
- Kuking zainstalowany jako aplikacja na telefonie albo komputerze otwiera się teraz na stronie głównej. Kto zainstalował go przed założeniem konta, widzi stronę powitalną z „Załóż konto” i „Zaloguj się” zamiast samego ekranu logowania; osoba zalogowana od razu widzi swój Start (#1975).
- „Wyloguj się” wyłącza powiadomienia poza Kuking (Web Push) na urządzeniu, na którym się wylogowujecie. Na wspólnym komputerze nikt po Was nie zobaczy już na ekranie „ktoś ugotował z Twojego przepisu” ani „nowa odpowiedź”. Na innych urządzeniach powiadomienia działają dalej, a samo wygaśnięcie sesji na telefonie ich nie wyłącza — tylko świadome wylogowanie (#1979).
- Czytnik ekranu ogłasza teraz w „Ustawienia → Powiadomienia”, czy powiadomienia na tym urządzeniu są włączone, wyłączone, zablokowane w przeglądarce, czy przeglądarka ich nie obsługuje. Wcześniej to zdanie zmieniało się tylko na ekranie i osoba niewidoma nie wiedziała, które przyciski się pojawiły (#1976).
- W menu z trzema kropkami przy cudzym wpisie są „Ukryj ten wpis” i „Ukryj tę osobę”. Ukrycie działa tylko dla Was, domyślnie przez 30 dni — potem wpis albo osoba wracają same. Ukryty wpis znika ze Startu, „Świeżo z Kuking”, tablicy na dziś i tygodniowego e-maila, a na profilu i pod linkiem zwija się do „Ten wpis ukrywasz tylko dla siebie. Pokaż”. Ukryta osoba znika z „Świeżo z Kuking”, tablicy i propozycji osób. Nikogo o tym nie powiadamiamy. Listę z datą końca, „Zostaw ukryte” i „Przywróć” znajdziecie w Ustawieniach → Ukryte (#1810).

- Pod składnikami przepisu pojawiły się szacunkowe wartości odżywcze na porcję: energia, białko, tłuszcz i węglowodany. Liczymy je z otwartych tabel składu żywności CIQUAL i USDA, bez sztucznej inteligencji, i tylko wtedy, gdy znamy skład co najmniej 90% masy przepisu. Gdy się nie da — bo np. jest „olej do smażenia” albo „trochę śmietany” — piszemy wprost dlaczego, zamiast zgadywać. Pod „Jak to liczymy” jest opis źródeł i miar. Autor może ukryć tę sekcję przy swoim przepisie jednym przyciskiem i przywrócić ją w każdej chwili (D-299). [nowa funkcja]
- Naprawione: tabele wartości odżywczych zostawały puste po wdrożeniu — migracja z PR #1900 przechodziła, ale nikt nie wywoływał komendy importu. `kuking:importuj-wartosci-odzywcze` stoi teraz w `preDeployCommand` obok migracji i treści zalążkowej i leci przy każdym wdrożeniu; drugie i kolejne uruchomienie na niezmienionych plikach CSV jest pomijane (hash plików), więc deploy bez zmiany danych nie przepisuje ~600 wierszy za każdym razem (#1961).
- Naprawione: przepis z „2 kotletami schabowymi” (i innymi formami tego słowa) nie dostawał wartości odżywczych, mimo że `miary.csv` ma dla schabu miarę „kotlet” — parser po prostu nie znał tego słowa. Dodano też test danych, który wykrywa, gdy w `miary.csv` pojawi się jednostka, której żadna forma słowna nie jest w parserze (#1963).
- Gdy ktoś z obserwowanych opublikuje kilka wpisów pod rząd, na Starcie widać dwa, a resztę pod przyciskiem „{imię}: jeszcze N wpisów — Pokaż”. Tak samo zwija się seria wpisów z jednego tagu. Kolejność się nie zmienia i nic nie znika. W tygodniowym e-mailu od każdej obserwowanej osoby jest najwyżej jeden wpis, ten najnowszy (#1812).
- Dla osób rozwijających serwis: sześć skryptów pomiarowych (`scripts/bez-javascriptu.mjs`, `fokus-karty-dania.mjs`, `glowka-karty-wpisu.mjs`, `hero-nad-zgieciem.mjs`, `kafel-dodawania.mjs`, `port-projektu.mjs`) nie wypisuje już w logu wartości `DB_DATABASE` — tylko fakt, że nazwa jest ustawiona. Zamyka dwa alerty CodeQL `js/clear-text-logging`. Nowy strażnik `tests/Feature/SkryptyNieLogujaWartosciZmiennychWrazliwychTest.php` skanuje `scripts/` i `.github/workflows/` pod kątem logowania wartości zmiennych o nazwach TOKEN/SECRET/KEY/PASSWORD/DSN (#1949).
- Dla osób rozwijających serwis: nowy skrypt `scripts/railway/zmienne-spoza-iac.mjs` wypisuje nazwy zmiennych serwisu ustawionych tylko w panelu Railway, a nie w `railway.ts` (bez wartości), a `docs/infra/ZMIENNE_SPOZA_IAC.md` opisuje, jak przed pierwszym `railway config apply` sprawdzić, czy apply by je usunął — samym odczytem, bez stagingu. Komentarz w `railway-iac.yml` nie mówi już o apply „po merge'u” (audyt po fali 25.09, znaleziska 11 i 12).
- „Zrób swoją wersję” pod cudzym przepisem, który widzicie (także „dla obserwujących”): jednym przyciskiem dostajecie kopię tego przepisu do zmiany, widoczną tylko dla Was — składniki i kroki, bez cudzych zdjęć. Nad tytułem wersji zawsze stoi podpis „Na podstawie przepisu: „…” · autor”, którego nie da się usunąć. Wersji bez żadnej zmiany w składnikach, krokach, czasie albo porcjach nie da się opublikować („To jest ten sam przepis. Może wystarczy »Ugotowałem«?”). Autor oryginału dostaje jedno powiadomienie, gdy wersja po raz pierwszy zostanie pokazana innym (nie „tylko ja”) i może ją zobaczyć, a pod jego przepisem pojawia się lista „Wersje innych osób” — bez liczników. Usunięcie oryginału nie usuwa wersji: podpis zostaje z dopiskiem, że oryginał jest niedostępny — tak samo widzi go każdy, kto oryginału nie może zobaczyć. Wersja bardzo podobna do oryginału nie trafia do wyszukiwarek, a żadna wersja nie trafia do mapy strony. Paczka z danymi mówi przy przepisie, od kiedy jest Waszą wersją i czego (#23, D-301). [nowa funkcja]
- Bezpieczeństwo CI: `.github/workflows/preview.yml` już nie uruchamia kodu z checkoutu PR-a (test dymny) w tym samym jobie, który ma prawo zapisu do komentarzy PR-a (`pull-requests: write`). Publikację komentarza z wynikiem testu dymnego przejął osobny job `smoke-komentarz`, uruchamiany po `smoke` przez `needs`, który nie checkoutuje repozytorium ani nie wykonuje żadnego skryptu z gałęzi PR-a — działa tylko na wartościach przekazanych z `smoke` jako `needs.smoke.outputs`. Job `smoke` zachowuje jedynie `contents: read` i `deployments: read` (#1941).
- Wewnętrzne: `.codex/heic-119` (nagrania i zdjęcia z telefonu, wygenerowany log przebiegu testów, lokalne pomiary urządzenia, zapytania badawcze) usunięte z indeksu repozytorium — `.gitignore` deklarował cały katalog jako ignorowany, a te pliki i tak były śledzone (7,2 MB). Dwa narzędzia poprawione i dodane świadomie w #1863 (`phone-proxy.py` i jego test) zostają, z jawnym wyjątkiem `!` w `.gitignore`. Strażnik `tests/Feature/ArtefaktyCodexPozaRepozytoriumTest.php` pilnuje, żeby nowy artefakt tego rodzaju znów nie wjechał przez `git add -A` (#1911).
- Piksel śledzący otwarcia w listach transakcyjnych z EmailLabs (#204):
  ponownie potwierdzone w oficjalnej dokumentacji dostawcy, że śledzenia
  otwarć nie da się wyłączyć per wiadomość przez API — to wyłącznie
  ustawienie konta wysyłkowego w panelu, w przeciwieństwie do śledzenia
  odnośników (`X-TRACKING-OFF`), które serwis wyłącza już dla każdego listu.
  `docs/infra/POCZTA_URUCHOMIENIE.md` (Krok 6) ma zaktualizowaną, niezależną
  weryfikację; polityka prywatności nadal mówi prawdę o tym, co robi
  EmailLabs. Do zrobienia zostaje wyłącznie krok po stronie właściciela:
  przełącznik w panelu EmailLabs.
- `/health` wykrywa teraz częściowo dokończone wdrożenie: kontrola `migrations` porównuje pliki migracji z bieżącego obrazu aplikacji z wierszami wykonanymi w bazie (tak jak `migrate:status`), zamiast sprawdzać tylko, czy tabela `migrations` jest niepusta. Wcześniej baza z choćby jedną starą migracją przechodziła kontrolę, mimo że kod korzystał już z nowej kolumny albo tabeli, których deploy nie zdążył wykonać — Railway kierował ruch na instancję z niezgodnym schematem (#1844).
- Bezpieczeństwo: callback odebrania dostępu z Facebooka (`/wejdz/facebook/odebranie-dostepu`) odrzuca teraz zbyt duże żądania i pole `signed_request` przed dekodowaniem, oraz ma własny, celowo hojny limit żądań na adres IP (60 na minutę, ten sam wzorzec co zgłoszenia CSP) — bez zmiany zachowania dla prawdziwych powiadomień Facebooka (#1869).
- Potwierdzenie pierwszej publikacji pokazuje teraz datę wpisu i jawny przycisk „Zobacz swój wpis” — tak jak obiecuje `docs/product/COLD_START.md` i `docs/product/SOUL.md`. Wcześniej komunikat mówił tylko „od teraz masz swoje archiwum”, bez daty i bez żadnego linku; przy dwóch i więcej zdjęciach, gdzie publikacja ląduje na ekranie doboru układu, przycisku nie było wcale (#1881).
- Powiadomienia na telefonie i komputerze (Web Push). W „Ustawienia → Powiadomienia” można je włączyć przyciskiem „Włącz powiadomienia na tym urządzeniu” — przeglądarka pyta o zgodę dopiero wtedy, nigdy sama przy wejściu na stronę. Przychodzą tylko dwie rzeczy: że ktoś ugotował z Twojego przepisu i że ktoś Ci odpowiedział (na komentarz albo pytanie). W ciszy nocnej (domyślnie 21:00–8:00, godziny do zmiany) nic nie przychodzi, a to, co się wydarzy, dostaniesz rano jednym powiadomieniem; tak samo po wyczerpaniu dziennego limitu (domyślnie 1, do zmiany). Wyłączyć można na jednym urządzeniu albo na wszystkich naraz, także bez JavaScriptu. Powiadomienia w Kuking (lista pod dzwonkiem) działają jak dotąd i nie mają przełącznika. Paczka z danymi ma nową sekcję `powiadomienia_poza_serwisem` (ustawienia i lista urządzeń, bez adresów i kluczy), a każde powiadomienie pole `wyslane_poza_serwis`. Funkcja działa dopiero po wpisaniu kluczy VAPID na serwerze (#35, D-303). Błąd usługi push nie kasuje subskrypcji, a ponawia dostarczenie wyłącznie do urządzeń, które go jeszcze nie dostały — znacznik „wysłano” stawia dopiero udana wysyłka, nigdy sama próba (#1960).

- Planer tygodnia: przy przepisie jest „Dodaj do planera”, a w „Moje” — plan na siedem dni. Można dopisać też coś własnego, np. „obiad u mamy”, i jednym przyciskiem skopiować poprzedni tydzień. Plan widzisz tylko Ty; przepis, którego autor już nie pokazuje, zostaje w planie bez tytułu, a nic z planu nie znika samo (#27).
- Link potwierdzający adres e-mail otwiera teraz ekran pośredni z przyciskiem „Potwierdź adres e-mail” — samo otwarcie linku (np. przez skaner odnośników w bramce antywirusowej albo prefetch klienta pocztowego) już nie potwierdza adresu. Kliknięcie przycisku działa tak jak dotąd, bez dodatkowego maila (#1862).
- Dla osób rozwijających serwis: sonda PostgreSQL w `./scripts/check.sh` dopisuje do `pg_isready` także bazę i użytkownika z `DB_DATABASE` i `DB_USERNAME`, jeśli są ustawione, a przy gotowości serwera mówi wprost, że hasła ani istnienia bazy nie sprawdza — to robią testy i migracje (#732).
- Numer wersji w stopce jest teraz odnośnikiem do strony „Co nowego” (`/co-nowego`): nowe funkcje rozpisane wydanie po wydaniu, poprawki zebrane w jedno zdanie. Kliknięcie otwiera stronę od razu przy opisie bieżącego wydania. Strona jest widoczna dla wszystkich, także dla gości; skrót commita i data w stopce zostają na miejscu (#1909). [nowa funkcja]
- Numer wersji w stopce ma teraz KOŃCÓWKĘ wdrożenia, np. „Alfa 0.68.005” zamiast samego „Alfa 0.68” — dwa różne wdrożenia tego samego dnia dają się teraz odróżnić na pierwszy rzut oka. Końcówka rośnie sama, przy każdym wdrożeniu, i wraca do „.001” przy podbiciu dużego numeru. Strona „Co nowego” pokazuje przy opisie nowej funkcji, od kiedy działa: „od Alfa 0.68.005” — NA STAŁE, także po tym, jak opis przejdzie z „Najnowsze zmiany” do sekcji nazwanego wydania (#1932, D-318). [nowa funkcja]

- Na stronie przepisu można wybrać, na ile porcji gotujecie: przyciski „Mniej” i „Więcej” nad listą składników przeliczają ilości, np. z 4 na 6 porcji „200 g mąki” staje się „300 g mąki”, a „1 łyżka masła” — „1½ łyżki masła”. Ilości są zaokrąglone po kuchennemu (gramy do okrągłych liczb, łyżki i szklanki do ½, ¼, ⅓). Szczypta, „do smaku” i składniki bez liczby zostają bez zmian. Nad listą widać „Przeliczone na 6 porcji”, a link „Pokaż ilości z przepisu” wraca do ilości autora. Przepis zapisany w bazie się nie zmienia (D-284).
- Przy składniku można podać, czym go zastąpić — pole „Czym można to zastąpić” w formularzu przepisu. Na stronie przepisu i w trybie gotowania pod składnikiem pojawia się wtedy „Zamiast tego: margaryna albo olej kokosowy”. Zamiennik jest też w paczce z danymi (`zamienniki` przy składniku) (D-284).


- Mapa strony dla wyszukiwarek nie podaje już profilu osoby, która ma na nim tylko zapowiedź przepisu „tylko dla obserwujących”, prywatnego albo ukrytego przez moderację. Taki profil jest dla gościa pusty, więc wyszukiwarka nie ma tam czego szukać. Profile z publicznym wpisem albo publicznym przepisem zostają w mapie jak dotąd (#1805).
- Wewnętrzne: `.railway/railway.ts` opisuje teraz także `KUKING_TAG_TYGODNIA` i `KUKING_HEALTH_TOKEN` (obie przez `ctx.shared`) — stały tylko w panelu Railwaya i pierwszy `railway config apply` by je usunął (audyt 25.09.2026, pkt 12; `KUKING_EDGE_TRYB` i `KUKING_HTML_EDGE_CACHE_SECONDS` dodał wcześniej #1775).
- Zgłoszenie nielegalnej treści: sprawa jest oznaczana jako „poinformowaliśmy o decyzji” dopiero wtedy, gdy e-mail z decyzją naprawdę wyszedł, a nie w chwili, gdy trafił do kolejki. Gdy wysyłka ostatecznie się nie uda, sprawa zostaje bez tego znacznika, a w dzienniku serwera jest jej numer. Ponowienie tego samego listu po udanej wysyłce nie wysyła go drugi raz (#1838, D-293).
- Wewnętrzne: nowy strażnik (`tests/Feature/NoweMigracjeTrzymajaSieParagrafu6Test.php`) pilnuje AGENTS.md §6 (DDL na gorącej bazie: indeks przez `CONCURRENTLY`, klucz obcy/CHECK przez `NOT VALID` + `VALIDATE CONSTRAINT`) w migracjach nowszych niż `2026_09_24_120000_add_appeal_id_to_moderation_actions.php` — tej migracji, już na produkcji, świadomie nie poprawiamy, ale ten sam błąd nie ma się powtórzyć w kolejnych (#989).
- „Zobacz” przy powiadomieniu o odpowiedzi przewija stronę dokładnie do tej odpowiedzi, także gdy rozmowa ma kilka stron. Gdy komentarz zniknął, zanim kliknęliście „Zobacz”, strona mówi o tym wprost zamiast pokazywać błąd (#759).
- Tag, który moderacja ukryła, nie podsuwa już wpisów na stronie głównej osobom, które obserwowały go wcześniej. Jeśli był jedynym obserwowanym tagiem, strona główna pokazuje „Świeżo z Kuking”. Ukryty tag można nadal zdjąć w „Twoich tagach”, a wpisy z pozostałych tagów zostają (#1824).
- W ustawieniach urodzin można włączyć „Pokaż moje urodziny obserwującym” (domyślnie wyłączone). Wtedy w dniu urodzin osoby, które Was obserwują, dostają w powiadomieniach „Dziś urodziny: …” — bez roku, najwyżej kilka takich powiadomień dziennie, nigdy w nocy i nigdy jako wpis w feedzie (#1755).

- W ustawieniach urodzin można osobno zgodzić się na e-mail z życzeniami. List wychodzi raz w roku, rano, tylko do osób, które zaznaczyły tę zgodę, i mieści się w dobowych limitach poczty. Na dole listu jest odnośnik do strony, na której można się wypisać bez logowania — samo otwarcie odnośnika niczego nie zmienia, a po wypisaniu przycisk „Jednak chcę go dostawać” cofa decyzję. Wysyłka jest włączona na produkcji (`KUKING_URODZINY_MAIL_WLACZONY` w harmonogramie); staging i podglądy PR-ów listów nie wysyłają (#1755).

- W dniu urodzin na stronie głównej pojawia się jedno zdanie z życzeniami od gospodarza. Widzi je tylko solenizant, bez maila i bez powiadomienia. Urodziny 29 lutego obchodzimy 28 lutego w latach nieprzestępnych. Życzenia można wyłączyć przy dacie, w Ustawieniach → Urodziny (#1755).

- W ustawieniach jest nowy ekran „Urodziny”: można podać dzień i miesiąc urodzin — bez roku — i w każdej chwili usunąć datę przyciskiem „Usuń datę”. Data jest prywatna: nie ma jej na profilu. Trafia do paczki z danymi (jako DD-MM) i znika przy usunięciu konta. Polityka prywatności opisuje to w nowym wierszu (#1755).
- Raport `kuking:raport` pokazuje, jaka część zapisanych w Zeszycie cudzych przepisów została ugotowana w ciągu 30 dni od zapisu. Liczy każdą parę osoba–przepis raz, tylko z zapisów, które miały już pełne 30 dni, i mówi wprost, gdy danych jest za mało albo są za świeże (#1015).
- Alarm serwisu `kopia-bazy` (`docker/kopia/kopia-bazy.sh`) odróżnia teraz dostarczone powiadomienie od takiego, które webhook odrzucił: `curl` dostał `--fail`, więc odpowiedź 4xx/5xx (np. skasowany webhook, padła usługa) trafia do logu jako OSTRZEŻENIE, zamiast liczyć się cicho jako wysłana (#193).
- Infrastruktura: `.railway/railway.ts` opisuje teraz zmienne, które stały tylko w panelu Railwaya albo nie było ich nigdzie — `KUKING_QUESTIONS_ENABLED` i `KUKING_MEDIA_DISK` (wszystkie role), `KUKING_EDGE_TRYB`, `KUKING_HTML_EDGE_CACHE_SECONDS` i `KUKING_R2_PUBLICZNE_ADRESY` (tylko serwis WWW, jako Shared Variables). Pierwsze `railway config apply` nie wyłączy już po cichu działu „Poradźcie”. Martwe `TRUSTED_PROXIES` świadomie zostaje poza plikiem (SEC-01).
- Podpowiedzi tagów przy wpisywaniu nie proponują już przypadkowych tagów, gdy wpiszecie same emoji albo znaki „%” czy „_” — czytają wpisany tekst tak samo jak wyszukiwarka i w takiej sytuacji po prostu nic nie podpowiadają.

- „Ugotowałem” nie gubi już wybranych zdjęć, gdy błąd jest w innym polu — na przykład „1h 30” zamiast liczby minut. Zdjęcia wracają do formularza z podglądem i przyciskiem „Usuń to zdjęcie”, a po poprawce zapisują się razem z wykonaniem. Wpisany czas w minutach nie kończy się już ekranem błędu (#872).
- Uszkodzony identyfikator zachowanego zdjęcia w formularzu wpisu, pytania albo „Ugotowałem” nie kończy się już ekranem błędu: formularz wraca z wpisanym tekstem i zdaniem, co zrobić (#871).
- Gdy odrzucone jest jedno z wybranych zdjęć, odnośnik w podsumowaniu błędów prowadzi do pola wyboru zdjęć, a nie donikąd (#874).
- Zdjęcia zachowane po błędzie w formularzu wpisu albo pytania wracają w tej samej kolejności, w jakiej je wybraliście. Wcześniej błąd w innym polu, na przykład za długi opis, mógł po cichu zmienić, które zdjęcie jest pierwsze. Formularz wpisu pokazuje też jako zachowane tylko te zdjęcia, które naprawdę trafią do publikacji. (#934)
- Usunięty jednorazowy workflow audytu A7 (`audyt-a7-final-check.yml`) z 09.09. Uruchamiał się tylko po pushu na nieistniejącą już gałąź audytu, a miał prawo zapisu do repozytorium — martwy kod z uprawnieniami to niepotrzebne ryzyko (decyzja właściciela z 25.09, #1742).

- „Oznacz wszystkie jako przeczytane” oznacza tylko powiadomienia widoczne na liście. Powiadomienia od osoby zablokowanej zostają nieprzeczytane i po odblokowaniu wracają jako nowe (#1401).
- W menu z trzema kropkami przy cudzym wpisie są teraz „Obserwuj tę osobę” i „Obserwuj tag: …” (najwyżej dwa tagi wpisu, tylko te, których jeszcze nie obserwujecie). Po kliknięciu strona mówi, co się stało i co z tego wyniknie, a pod komunikatem jest przycisk „Cofnij” (#1809).
- Na Starcie wpisy z obserwowanych tagów stoją razem z wpisami osób, które obserwujecie, od najnowszych. Wcześniej wystarczyło obserwować jedną osobę, żeby wpisy z tagów przestały się pokazywać w ogóle. Przy wpisie, który trafił do Was przez tag, jest napisane „Z tagu: …”. Wpisy tylko dla obserwujących i prywatne nie trafiają na Start przez tag, a blokady działają jak wszędzie (#1808).
- „Świeżo z Kuking” nie kończy się już po jednym wpisie od każdej osoby. Najpierw widzicie najnowszy wpis każdego, potem drugi każdego i tak dalej — przy „Pokaż więcej” wracają też starsze dania, a osoba publikująca często nie stoi zawsze na górze. Kolejne strony nie powtarzają wpisów, nawet gdy ktoś w tym czasie coś doda. Gdy lista jest pusta, strona mówi, czy po prostu nie ma nic nowego, czy część osób sami ukrywacie, i prowadzi do listy ukrytych, tablicy na dziś i „Dodaj wpis” (#1807).
- „Świeżo z Kuking” nie kończy się już po jednym wpisie od każdej osoby. Najpierw widzicie najnowszy wpis każdego (także własny), potem drugi każdego i tak dalej — przy „Pokaż więcej” wracają też starsze dania, a osoba publikująca często nie stoi zawsze na górze. Kolejne strony nie powtarzają wpisów, nawet gdy ktoś w tym czasie coś doda. Gdy lista jest pusta, strona mówi, czy po prostu nie ma nic nowego, czy część osób sami ukrywacie, i prowadzi do listy ukrytych, tablicy na dziś i „Dodaj wpis” (#1807).
- Paczka z danymi (`dane.json`) nazywa pole „Na czym się znasz” kluczem `na_czym_sie_znam` zamiast `w_czym_jestem_dobra`, który każdemu czytelnikowi przypisywał formę żeńską. **Zgodność wstecz:** program czytający starsze paczki powinien przyjmować oba klucze — w paczkach wygenerowanych przed tą zmianą to samo pole nazywa się `w_czym_jestem_dobra`, a jego treść się nie zmieniła. Innych kluczy zmiana nie dotyczy (#1750).
- Raport właściciela (`php artisan kuking:raport`) ma nową sekcję „Historie przepisów”: ile opublikowanych w ostatnich 90 dniach przepisów ma wpisane, od kogo pochodzą, historię, rok „w rodzinie od”, gotowy skan kartki i rodzaj „Rodzinny” — same liczby i procenty, bez treści przepisów i bez nazwisk. Przy mniej niż 20 przepisach raport mówi „za mało danych” zamiast pokazywać procent (#1045).
- `php artisan kuking:raport` pokazuje „Drugi wpis w 7 dni”: ile osób, które niedawno opublikowały pierwszy wpis, dodało drugi w ciągu tygodnia. Same liczby, bez nazw i treści; przy mniej niż 10 osobach bez procentu (#29).
- Ekran łączenia konta Google nie mówi już „jesteś zalogowany” każdemu czytelnikowi — pyta, jakie konto Google jest zalogowane na tym urządzeniu. Test tekstów łapie teraz także „jestem/jesteś” z formą rodzajową bez „ł” („jesteś zalogowany”, „jestem gotowa”).
- W rocznicę założenia konta na stronie głównej pojawia się jedno zdanie od gospodarza, na przykład „Gotujesz z nami od roku — dziękuję, że jesteś.”. Widzi je tylko właściciel konta, bez maila i bez powiadomienia. Wyłącza się je tym samym przełącznikiem co wspomnienia (Ustawienia → Prywatność) (#1754). [nowa funkcja]

- Powiadomienie o komentarzu pod wpisem, który tylko zapowiada przepis, znika z listy, licznika i eksportu danych, gdy autor ukryje ten przepis, zawęzi jego widoczność albo go usunie — tak jak sam wpis, który wtedy przestaje się otwierać. Gdy przepis znów jest dla Was widoczny, powiadomienie wraca (#1747).
- Gdy osoba, która ugotowała z przepisu, poprosi o usunięcie konta, powiadomienia o komentarzach pod jej „Ugotowałem” znikają z listy, licznika i eksportu danych u innych osób — tak jak samo „Ugotowałem”, które od tej chwili nie otwiera się nikomu. Po cofnięciu usunięcia wszystko wraca (#1746).
- Porządek za kulisami listy powiadomień: to, które powiadomienia o komentarzach widzicie, liczy teraz jedna wspólna reguła widoczności wpisów, przepisów i „Ugotowałem”, sprawdzana testem zgodności z tym, co pokazuje sama strona treści. Dla Was nic się nie zmienia — lista, licznik i eksport danych pokazują to samo co wcześniej (#1687).


- Panel moderacji: przy treści, którą schował administrator, moderator nie widzi już formularza „Przywróć treść”, który i tak kończył się odmową. W jego miejscu stoi informacja, że przywrócić może tylko administrator (#1479, audyt B2-01).
- Licznik „Komentarze” pod wpisem liczy teraz całą rozmowę: komentarze razem z odpowiedziami, czyli dokładnie to, co przeczytasz po wejściu we wpis. Wcześniej wpis z jednym komentarzem i trzema odpowiedziami pokazywał „Komentarze (1)”. Tak samo liczy nagłówek rozmowy pod przepisem i pod „Ugotowałem”. Pod pytaniami nic się nie zmienia — liczba „Odpowiedzi” dalej nie obejmuje rozmowy pod odpowiedzią (#1801).
- Panel kolażu na powitanie: lista „Zdjęcia do wyboru” nie jest już pusta tylko dlatego, że najnowsze wpisy nie mają jeszcze gotowych zdjęć (np. zdjęcia są w obróbce). Lista pokazuje najnowsze wpisy, które naprawdę mają gotowe zdjęcie, nawet jeśli są starsze (#1802).
- Wyszukiwarka nie pokazuje już przypadkowych wyników dla frazy, która po usunięciu polskich znaków i emoji staje się pusta (np. samo emoji) — zamiast tego prosi o wpisanie co najmniej dwóch znaków, tak samo jak przy zwykłej za krótkiej frazie, i nie zapisuje wtedy pozornie skutecznego wyszukiwania (#1050).
- `docs/DEPLOYMENT.md` nazywał czwarty proces „docelowej" topologii `cron`, choć `.railway/railway.ts` i `docs/infra/INFRA_DECISION.md` znają tylko rolę `scheduler` — długo działający `schedule:work`, nie Railway Cron (granulacja 5 minut nie wystarcza harmonogramowi Laravela). Dokument nazywa go teraz tak samo jak IaC (#1740).
- `.env.example` nie wymieniał `CLOUDFLARE_ZONE_ID` ani `CLOUDFLARE_PURGE_TOKEN`, mimo że `config/kuking.php` (czyszczenie cache CDN po usunięciu zdjęcia, #959) je czyta, a runbook opisuje jako wymagane. Szablon środowiska ma teraz obie zmienne, puste, z komentarzem o zakresie tokenu i skutku ich braku (#1741).
- Tablica „kuKINGi na dziś” pokazuje najwyżej jedno danie od osoby także wtedy, gdy wybiera je gospodarz. Panel mówi po polsku, czyje drugie danie trzeba odznaczyć, i zostawia zaznaczenia oraz notatki na miejscu. Dwa jednoczesne zapisy tablicy albo kolażu na stronie powitalnej nie sklejają się już w jeden za długi wybór, a każda zmiana i każde wyczyszczenie zostawia ślad w dzienniku panelu.
- Nieudane wysłanie alarmu na webhook błędów (`App\Logging\WebhookBleduHandler`) trafia teraz do kanału `stderr` zamiast `single`. Kanał `single` pisał do pliku na dysku kontenera, którego Railway nie pokazuje w panelu — jedyny widoczny tam strumień to `stdout`/`stderr` procesu, więc wpis o niedodzwonieniu się nikomu nie był widoczny (#599).
- Klient S3 kopii bazy (`docker/kopia/s3.sh`) liczy teraz podpis HMAC-SHA256 ręcznie (dwa wywołania SHA-256, zgodnie z RFC 2104), zamiast przekazywać klucz `openssl -macopt hexkey:…` jako argument procesu. Argumenty procesu są na Linuksie widoczne dla każdego użytkownika maszyny (`ps` i plik `cmdline` procesu w systemie plików `proc`) przez cały czas trwania procesu — klucz podpisujący R2 już tam nie trafia (#594).
- Monitoring (dla prowadzących serwis): seria identycznych błędów 500 daje na kanale alarmowym jedną wiadomość na kwadrans z liczbą powtórzeń zamiast wiadomości przy każdym wystąpieniu; nowy alarm o żądaniach, w których zapytania do bazy trwały łącznie dłużej niż próg; alarm, gdy Cloudflare odrzuca nasz sekret Turnstile i formularze przechodzą bez weryfikacji (#599).
- „Usuń z zeszytu” przy przepisie, który leży w kilku Waszych zeszytach, najpierw pyta: pokazuje, z ilu zeszytów przepis zniknie i ile notatek przepadnie, i pozwala zamiast tego usunąć go tylko z jednego wybranego zeszytu. Ze wszystkich zeszytów przepis schodzi dopiero po naciśnięciu „Tak, usuń ze wszystkich”, a zaraz potem można go przywrócić razem z notatkami (#775).
- Przy przepisie albo wpisie we własnym zeszycie można teraz dopisać notatkę dla siebie, np. „na urodziny taty — mniej soli”. Notatkę widzisz tylko Ty: nie zobaczy jej autor ani nikt, kto ogląda zeszyt, także publiczny. Ta sama rzecz w dwóch zeszytach może mieć dwie różne notatki. Żeby notatkę usunąć, wystarczy wyczyścić pole i zapisać. Zapisywanie jednym kliknięciem działa jak dotąd (#978). [nowa funkcja]
- Na ekranie „Twój zeszyt” jest pole „Szukaj w moich zeszytach”: wystarczy wpisać kawałek tytułu przepisu, żeby znaleźć go wśród swoich zapisów, bez pamiętania, do którego zeszytu trafił. Polskie znaki nie mają znaczenia, a przy każdym wyniku stoją zeszyty, w których leży. Wyniki pokazują tylko to, co możecie dziś otworzyć (#779). [nowa funkcja]

- Panel wiadomości do nas: zapis stanu sprawy nie gubi już rozpoczętej, niewysłanej odpowiedzi — po kliknięciu „Zapisz” tekst wraca w polu „Treść odpowiedzi”, a list nie wychodzi. Tak samo wysłanie odpowiedzi zostawia w polu niezapisaną notatkę i zaznaczony stan, niczego z nich nie zapisując. Działa w przeglądarce z JavaScriptem (#845).
- Powiadomienie „ma Twój przepis w swoim zeszycie” po usunięciu tego przepisu nie prowadzi już do strony „nie znaleziono”. Zostaje na liście z dopiskiem „Ten przepis został usunięty.” i przyciskiem „Oznacz jako przeczytane”. Po zmianie tytułu przepisu „Zobacz” prowadzi od razu pod nowy adres.
- Na ekranie „Dopisz szczegóły” przejście do kreatora w trzech krokach najpierw pyta, jeśli coś zostało zmienione i nie jest zapisane. Kreator otwiera ostatnią zapisaną wersję przepisu, więc można zostać i zapisać albo świadomie przejść bez tych zmian. Bez zmian przejście działa od razu, jak dotąd. Klawisz Esc zamyka pytanie tak jak „Zostań na tej stronie”.
- Na telefonie z Chrome otwarta klawiatura ekranowa zmniejsza teraz układ strony, więc przy niskim oknie górna i dolna belka przestają być przypięte i nie zasłaniają wpisywanego pola — także na telefonie trzymanym poziomo i na tablecie. Telefon w poziomie ma belki odpięte również bez klawiatury. Powiększanie strony działa jak dotąd.
- Paczka z danymi konta nie zawiera już komentarzy, których nie widać na ekranie: od osób zablokowanych (w obie strony) oraz od kont zablokowanych przez moderację albo zamykanych. Dotyczy komentarzy i odpowiedzi pod przepisami, wpisami i wykonaniami; Twoje własne komentarze zostają w paczce jak dotąd (#1245).
- „Zobacz” przy powiadomieniu o pierwszym wpisie nowej osoby otwiera ten właśnie wpis, a nie ogólną listę „Bez odpowiedzi”. Gdy wpisu nie da się już otworzyć, lista mówi to wprost (#1371).
- „Pokaż więcej” naprawdę pokazuje więcej. Kolejne wpisy, komentarze, wykonania, przepisy, osoby, powiadomienia, pytania i tagi dokładają się pod tymi, które już widać — wcześniejsze nie znikają, a strona nie skacze na początek. Odświeżenie strony albo powrót z otwartego wpisu przywraca całą rozwiniętą listę w tym samym miejscu. Gdy pobranie się nie uda, lista zostaje, a pod przyciskiem pojawia się informacja, co zrobić. Bez JavaScriptu przycisk nazywa się „Następna strona …” i otwiera kolejną stronę, tak jak do tej pory.
- Metryczka sprawy na ekranie odwołań (`/admin/odwolania`) miała 16 px i powoływała się na wyjątek D-051, który jej nie obejmuje — ten wyjątek dotyczy wyłącznie metryczki wersji w stopce. Metryczka wraca do 18 px.
- Na stronie powiadomień przycisk „Oznacz wszystkie jako przeczytane” pojawia się tylko wtedy, gdy są nieprzeczytane powiadomienia. Jeśli między wczytaniem strony a kliknięciem wszystko zostało już przeczytane (np. w drugiej karcie), strona mówi, że nie było nic do oznaczenia, zamiast potwierdzać zmianę, której nie było (#1402).
- Szkic przepisu widzi już tylko jego autor — moderacja też nie. Moderacja zagląda wyłącznie do przepisów, które sama ukryła albo zdjęła. Zdjęcia moderacja widzi tak samo jak treść, do której są przypięte; wyjątkiem jest zdjęcie, które samo jest przedmiotem zgłoszenia w panelu, oraz zdjęcie wpisu (także „tylko dla obserwujących”), który czeka na rozpatrzenie jako otwarte zgłoszenie albo oznaczenie automatu. Nieprzypięte jeszcze zdjęcie, szkic i skan rodzinnej kartki z prywatnego przepisu nie otwierają się już nikomu poza autorem.
- Listy „Rozmiar tekstu” i „Wygląd strony” w panelu „Aa · Wygląd” mają wyraźną ramkę i lekko przyciemnione tło, tak jak pola formularzy. Wcześniej ich granica była prawie niewidoczna i wyglądały jak zwykły tekst.
- „Przywróć treść” w panelu moderacji cofa już tylko ukrycie albo usunięcie zdecydowane przy tym zgłoszeniu. Komentarz, który usunął właściciel wpisu, i wpis usunięty przez samego autora nie wracają decyzją moderatora, a decyzję administratora cofa tylko administrator — panel mówi wprost, dlaczego odmówił (audyt B2-01).
- Przy treści ukrytej przez administratora zwykły moderator nie widzi już przycisku „Przywróć treść” — i tak dostałby odmowę. W jego miejscu jest krótka informacja „Ukrył administrator”; administrator, a także moderator przy treści ukrytej przez innego moderatora, nadal widzą przycisk jak dotąd (#1748).
- W ustawieniach profilu pola „Jak mamy Cię nazywać?” i „Nazwa użytkownika” są oznaczone dla przeglądarki tak samo jak przy zakładaniu konta, więc przeglądarka i narzędzia wspomagające rozpoznają je i mogą podpowiedzieć zapisane dane (#949).
- Stare powiadomienie Facebooka o odebraniu dostępu nie usypia już połączenia z Facebookiem, jeśli po nim ponownie weszłaś lub wszedłeś kontem Facebooka. Uszkodzone powiadomienia (np. pole w złym kształcie) są spokojnie odrzucane zamiast wywoływać błąd serwera. Przycisk „Połącz konto Facebooka jeszcze raz” w ustawieniach bezpieczeństwa naprawdę przywraca uśpione połączenie i mówi „Połączenie z Facebookiem znów działa”.
- Odpowiedź ukryta przez moderację nie znika już na dobre, gdy autor usunie komentarz, pod którym stoi. Zamiast niego zostaje napis „Komentarz usunięty.”, a po przywróceniu odpowiedź znów widać pod tym napisem — pod wpisem, przepisem i wykonaniem. Usunięty tekst komentarza nie wraca (#1317).
- Zgłoszenie nielegalnej treści z adresem profilu (`kuking.pl/@nazwa`) albo z adresem konkretnego komentarza trafia teraz do tego konta albo tego komentarza, a nie do „nierozpoznanej strony” czy wpisu nad komentarzem. Takiej sprawy nie rozstrzyga moderator, którego ona dotyczy (audyt B2-02).
- Usunięte konto nie wraca, gdyby serwis trzeba było przywrócić z kopii zapasowej sprzed jego usunięcia: przed ponownym uruchomieniem serwisu usuwamy je jeszcze raz, z tym samym wybranym zakresem. Polityka prywatności mówi o tym w części o usunięciu konta.
- Panel moderacji: kolejka „Bez odpowiedzi” pokazuje wpisy, przepisy, pytania i wykonania z ostatnich 14 dni. Starsze treści bez odzewu nie zasłaniają już na górze listy tego, na co odpowiedź dziś jeszcze coś zmienia. Zamknięcie dużej grupy oznaczeń automatu („To nic takiego”) działa szybciej.
- „Usuń wpis”, „Usuń przepis” i „Usuń komentarz” usuwają teraz naprawdę. Treść znika z serwisu od razu, jak dotąd, a najpóźniej 30 dni później także z naszej bazy — razem ze zdjęciami w pełnym rozmiarze. Wcześniej usunięta treść i jej zdjęcia zostawały u nas bez końca. Jeśli Wasz przepis ugotował ktoś inny, jego „Ugotowałem” zostaje, a z samego przepisu nie zostaje ani tytuł, ani składniki, ani zdjęcia. Polityka prywatności mówi o tym w tabeli przy „Publikowaniu treści”.
- Gdy autor wpisu albo przepisu usunie komentarz pod swoją treścią, powiadomienie mówi teraz „Twój komentarz został usunięty przez autora wpisu” (albo przepisu), a nie „Wiadomość od moderacji Kuking”. Moderacja nie podejmowała tej decyzji. Starsze takie powiadomienia mają nagłówek „Wiadomość od Kuking.” (audyt B9).
- Osoba z zawieszonym kontem może zmienić hasło, wylogować się z innych urządzeń oraz włączyć, wyłączyć albo odnowić weryfikację dwuetapową. Przejęte konto da się zabezpieczyć od razu, a nie dopiero po końcu zawieszenia (audyt B2-04).
- Osoba z zawieszonym kontem może zablokować kogoś, kto ją nęka, zdjąć blokadę i zgłosić treść — także formularzem zgłoszenia nielegalnej treści. Przyciski „Zablokuj” i „Zgłoś” działają podczas zawieszenia; publikowanie, komentowanie i obserwowanie nadal są wstrzymane (audyt B2-03).
- Panel moderacji: pod kolejką zgłoszeń, odwołań, oznaczeń automatu, listą kont i wiadomości widać teraz na komputerze przyciski „Poprzednia strona” i „Następna strona” oraz napis „Strona 2 z 4”. Wcześniej na szerokim ekranie pod listą nie było niczego, więc sprawy spoza pierwszych 25 pozostawały niewidoczne, a na telefonie przyciski były małe i miały angielski opis.
- Komunikat błędu pod polem formularza ma teraz ten sam rozmiar co reszta tekstu (18 px zamiast 16 px, a przy powiększonym tekście odpowiednio więcej). To zdanie mówi, co poprawić, więc ma być czytelne bez przybliżania.
- Gdy zakładanie nowych kont jest wstrzymane, formularz rejestracji — także przez Google i Facebooka — nie pokazuje już ekranu „Robimy przerwę techniczną”. Przenosi na logowanie ze zdaniem „Nowych kont chwilowo nie zakładamy. Jeśli masz już konto, zaloguj się.”
- Gdy nieprzeczytanych powiadomień jest więcej niż 99, plakietka przy „Powiadomienia” pokazuje „99+”, a czytnik ekranu mówi „ponad 99 nieprzeczytanych”. Strony dla osób z dużą liczbą zaległych powiadomień wczytują się przez to szybciej. Lista powiadomień pokazuje wszystkie, jak dotąd.
- Paczka z danymi pokazuje cudze rzeczy tylko wtedy, gdy widać je też w serwisie. Na listach obserwowanych i obserwujących nie ma kont zablokowanych przez moderację ani zamykanych — paczka podaje tylko, ile ich jest. Przy Waszych komentarzach i „Ugotowałem” nie ma już fragmentu ani tytułu wpisu czy przepisu, którego autor przestał Wam go pokazywać — stoi tam „treść niedostępna”, a Wasz komentarz i notatka zostają. Komentarze innych pod Waszą treścią przechodzą przez tę samą granicę co na ekranie (#1245).
- Ustawienia prywatności otwarte dawno temu nie zapiszą Was z powrotem na tygodniowy e-mail. Jeśli w międzyczasie wypisaliście się odnośnikiem z e-maila albo w innej karcie, zapis starego formularza nic nie zmienia, a przy polu pojawia się wyjaśnienie i odnośnik „Otwórz aktualne ustawienia” — wybory z formularza zostają na miejscu.
- Chwilowa awaria przy przygotowaniu paczki z danymi nie pokazuje już od razu „nie udało się przygotować”. Paczka czeka w kolejce na ponowienie, a ponowne kliknięcie „Przygotuj paczkę z moimi danymi” nie zamawia drugiej. Porażkę widać dopiero wtedy, gdy wszystkie próby zawiodą — i wtedy można od razu zamówić nową paczkę. Gdy nie uda się nawet przyjąć prośby o paczkę, zamiast strony błędu jest zdanie, że nic nie zostało zapisane i że można spróbować za kilka minut.
- Strona główna nie zostaje pusta, gdy tuż przed jej otwarciem przestaliście kogoś obserwować, zablokowaliście kogoś albo zniknął ostatni wpis z Waszych tagów. Zamiast pustego ekranu pokazuje wtedy wpisy z tagów albo „Świeżo z Kuking” (#983).
- Dokumentacja moderacji i bazy danych nie opisuje już automatycznej oceny zdjęć profilowych jako działającej. Od decyzji D-240 zdjęcie profilowe nie trafia do żadnego modelu; nadal można je zgłosić przyciskiem „Zgłoś” na profilu. Test pilnuje, żeby dokumenty mówiły to samo co kod.
- Wpis „Ugotowałem” osoby, której konto moderacja zablokowała na stałe (ban), nie otwiera się już pod bezpośrednim adresem — tak samo jak jej profil i przepisy. Nic nie jest kasowane: po zdjęciu blokady wpis wraca (audyt A5-07, D-261).
- „Świeżo z Kuking” i strona powitalna pokazują najwyżej jeden wpis od każdej osoby — jej najnowszy, który możecie zobaczyć. Jedna bardzo aktywna osoba nie zasłania już całej reszty, a kolejność nadal jest po prostu od najnowszych (#940).
- Chwilowa usterka po naszej stronie nie psuje już trzech rzeczy: jednorazowy link do logowania nie przepada, gdy wejście się nie udało — ekran mówi, że link nadal działa i wystarczy kliknąć „Zaloguj mnie” jeszcze raz (#1530); prośba o paczkę z danymi, która została przyjęta, kończy się potwierdzeniem zamiast komunikatu o błędzie (#1429); zablokowanie osoby kończy się komunikatem o blokadzie, a nie o błędzie, gdy blokada naprawdę zadziałała (#1573).
- Na ekranie „Nie ma teraz połączenia z internetem” przycisk „Spróbuj ponownie” otwiera ponownie tę samą stronę, która się nie wczytała — przepis albo wyszukiwanie z wpisaną frazą — zamiast przenosić na stronę główną. Do strony głównej prowadzi osobny przycisk „Przejdź na stronę główną” (#749).

- Wiadomość z „Napisz do nas” nie zapisuje już tokenu z adresu strony, z której przyszliście — np. z linku do ustawienia nowego hasła, logowania, zaproszenia czy potwierdzenia adresu e-mail. W zgłoszeniu zostaje tylko nazwa ekranu, a zwykłe strony, jak przepis, są zapisywane jak dotąd.
- Wyszukiwarka nie kończy się już błędem dla zalogowanej osoby, gdy adres zawiera nietypowo zapisaną frazę (np. z innego programu); pokazuje wtedy zwykły, pusty ekran „Szukaj” (#738).
- Na krótkim wpisie menu „…” i pytanie „Na pewno usunąć ten wpis?” nie są już ucinane przez dolną krawędź karty. Przycisk „Tak, usuń wpis” da się trafić myszą i dojść do niego klawiszem Tab, także przy powiększonym tekście (#1082).
- Poprawka opublikowanego przepisu zapisana przyciskiem „Zapisz zmiany” albo przy wyjściu z edycji zostaje w historii jako nowa wersja przepisu. Zapis samoczynny w trakcie pisania wersji nie tworzy, a wcześniejsze wersje nigdy się nie zmieniają (#1316).
- Formularze „Dodaj zdjęcie” i „Ugotowałem” mówią przy polu zdjęć, ile zdjęć można wybrać naraz, zanim klikniesz „Opublikuj”. Gdy część zdjęć została zachowana po błędzie w innym polu, pomoc podaje, ile można dodać jeszcze. „Ugotowałem” podaje też największy dopuszczalny rozmiar pliku (#883).
- Kto przerwał pierwsze kroki po założeniu konta, zobaczy na Starcie spokojny odnośnik „Dokończ pierwsze kroki”. Prowadzi od razu do miejsca przerwania — zapisanych zainteresowań nie trzeba wybierać drugi raz. Przypomnienie znika po dojściu do końca, po „Pomiń ten krok” albo po „Nie przypominaj” (samo otwarcie strony końcowej go nie zdejmuje), i nie przerywa logowania na stronę, którą ktoś chciał otworzyć (#985).
- Przy dodawaniu wpisu i „Ugotowałem” każde wybrane zdjęcie ma pod miniaturą przycisk „Usuń”. Pomyłkowe zdjęcie znika z tego, co naprawdę zostanie wysłane — pozostałe zdjęcia, ich kolejność i opis zostają. Licznik mówi teraz poprawnie „Wybrano 5 zdjęć”. W przeglądarce, która nie pozwala zmienić wybranych plików, przycisku nie ma, a formularz działa jak dotąd (#884).
- Dla osób rozwijających serwis: kontrola `./scripts/check.sh` sprawdza bazę na porcie z `DB_PORT` (bez niej — na 5432, jak dotąd), więc przy własnej bazie testowej na innym porcie nie melduje już gotowości cudzego serwera. Niczego nie trzeba eksportować, a lokalny klaster na porcie domyślnym skrypt nadal sam uruchamia (#732).
- W zeszycie, w którym są zapisy niedostępne dla Ciebie (usunięte, ukryte przez autora albo od osoby zablokowanej), jest teraz przycisk „Wyjmij niedostępne zapisy”. Po potwierdzeniu wyjmuje z tego jednego zeszytu tylko te zapisy — zeszyt, widoczne zapisy, inne zeszyty i sama treść zostają. Jeśli w międzyczasie coś wróciło albo zniknęło, niczego nie wyjmujemy i prosimy o ponowne potwierdzenie (#773).
- Do własnego wpisu ze zdjęciem dania można dopisać przepis: w menu „…” przy wpisie jest „Dopisz przepis”. Otwiera się zwykły formularz przepisu ze zdjęciem z wpisu, więc nie trzeba go wgrywać drugi raz. Sam wpis zostaje taki, jaki był — z treścią, komentarzami i tym, kto go widzi. Kto ma widzieć przepis, wybieracie osobno; gdy wpis nie był publiczny, formularz proponuje „Tylko ja” (#1334). [nowa funkcja]
- Panel moderacji: ekran „Zdejmij z urzędu” ma ten sam układ co reszta panelu — pola decyzji stoją w jednym wyraźnym formularzu, przycisk „Zdejmij tę treść” jest odsunięty kreską pod polami, a „Wróć do treści” stoi osobno, poza formularzem (#581).
- Zdjęcie dodane w kreatorze przepisu (zdjęcie gotowego dania i zdjęcia kroków) nie powinno być już odrzucane komunikatem „Ten plik nie wygląda na zdjęcie”, gdy wgrany plik czeka w zdalnym magazynie plików. Sprawdzenie zdjęcia działa teraz na kopii pliku na serwerze, a kopia jest od razu kasowana (audyt A5-08, do potwierdzenia na stagingu).
- Formularz zgłoszenia nie pokazuje już pustego cudzysłowu „…”, gdy zgłaszana treść zaczyna się od bardzo długiego ciągu znaków bez spacji (np. długiego linku). Widać wtedy samą nazwę celu, np. „wpis Basi”, tak jak przy treści bez tekstu (#794).
- Kreator przepisu przyjmuje przy wgrywaniu wyłącznie zdjęcia JPG, PNG, WebP i AVIF — rozpoznane po zawartości pliku, nie po nazwie. Gdy wysyłka zdjęcia się nie uda, komunikat mówi teraz o formacie i o rozmiarze. Wgrywanie zdjęć w kreatorze ma własny, rozsądny limit liczby plików (audyt A5-09).

- Długa rozmowa pod komentarzem nie ładuje się już w całości. Pod wpisem, przepisem i wykonaniem widać najpierw dwanaście najstarszych odpowiedzi, a przycisk „Pokaż dalsze odpowiedzi” mówi, ile ich jeszcze jest, i działa bez JavaScriptu. Powiadomienie o odpowiedzi otwiera tę część rozmowy, w której ją widać.
- Karta „Ugotowałem” nie prowadzi już do przepisu, którego nie możecie otworzyć — bo autor zmienił go na prywatny albo ukryła go moderacja. Tytuł przepisu zostaje jako zwykły tekst, bez odnośnika kończącego się odmową, a obok stoi zdanie „Ten przepis nie jest dla Ciebie dostępny.”. Własne zdjęcie, notatka i przycisk usunięcia zostają.
- Na własnym profilu przycisk przy zdjęciu profilowym i skrót w prawej kolumnie mówią „Zmień”, gdy zdjęcie już widać, a „Dodaj”, gdy widać pierwszą literę imienia — tak samo jak w ustawieniach profilu.
- Strona główna nie pokazuje już wpisów z tagu ukrytego przez gospodarza, nawet jeśli ktoś obserwował go wcześniej. Obserwowanie tagu, który połączono z innym, prowadzi do wpisów tagu, z którym go połączono. „Przestań obserwować” na starej stronie połączonego tagu nie udaje już sukcesu: przenosi na stronę aktualnego tagu i mówi, czy nadal go obserwujecie.
- Długa rozmowa pod czyimś „Ugotowałem” otwiera się szybciej: komentarze pokazują się porcjami, tak jak pod wpisem i przepisem, a dalsze są pod przyciskiem „Pokaż więcej komentarzy”. Nagłówek nadal podaje liczbę całej rozmowy, a „Zobacz” w powiadomieniu o komentarzu prowadzi od razu do porcji, w której ten komentarz stoi.
- Strona wpisu ma własny tytuł z początku jego treści — w karcie przeglądarki, w wynikach wyszukiwania i w linku wysłanym w komunikatorze widać, czego dotyczy wpis, a nie tylko imię autora. Wpis bez tekstu nazywa się uczciwie: od kogo i z którego dnia jest zdjęcie. Główne zdjęcie wpisu wczytuje się od razu, bez czekania na resztę strony.
- Ponowne usunięcie komentarza, który już jest usunięty (np. ze starej karty albo po drugim kliknięciu), niczego nie zmienia: autor komentarza nie dostaje drugiego powiadomienia z innym powodem, a osoba usuwająca widzi komunikat, że komentarz był już usunięty — bez prośby o ponowne uzasadnienie.
- Czas przepisu liczy się tak samo na stronie przepisu i w filtrze „Do 30 minut”. Łączny czas pokazujemy tylko wtedy, gdy podano oba czasy — przygotowania i gotowania. Puste pole znaczy „nie wiem”, a 0 znaczy „tego etapu nie ma”. Wcześniej przepis z samym czasem przygotowania pokazywał na stronie „Około 10 min”, a w szybkich przepisach się nie pojawiał (#1090).
- Moderator nie przywraca już własnego wpisu ani komentarza ukrytego przez moderację — ani przyciskiem „Przywróć treść”, ani cofnięciem decyzji po odwołaniu. Przywraca go ktoś inny z zespołu, a autor, jeśli się nie zgadza, składa odwołanie jak każdy. Odmowa mówi to wprost i niczego nie zmienia.
- Karty wpisów w zeszycie pokazują teraz tematy wpisu, tak jak w feedzie, na profilu i na stronie tagu. Wszystkie listy wpisów wczytują dane karty w jeden sposób, więc karta wygląda wszędzie tak samo, a strona nie wczytuje niczego osobno dla każdej karty (#1037).
- Gdy przyznajemy rację osobie, która odwołała się od decyzji o pozostawieniu zgłoszonej treści, od razu podejmujemy nową decyzję — na przykład usuwamy treść albo blokujemy konto. Odpowiedź „Zmieniamy naszą decyzję” mówi też, co stało się ze zgłoszoną treścią, a autor dostaje uzasadnienie i może się od tej decyzji odwołać.
- Cofnięcie starego zawieszenia po odwołaniu nie zdejmuje już późniejszej, osobnej blokady konta. Osoba dostaje w odpowiedzi jasne zdanie, że decyzję cofnęliśmy, ale konto pozostaje zablokowane na podstawie innej decyzji, od której może się odwołać osobno. Cofnięta blokada nie wraca też, gdy osoba zgłosiła usunięcie konta, a potem z niego zrezygnowała.
- Dwie osoby rozpatrujące to samo odwołanie w tej samej chwili nie wydadzą już dwóch sprzecznych odpowiedzi. Druga dostaje komunikat, że sprawa jest już rozpatrzona, a skutek i odpowiedź zapisują się razem albo wcale.
- Wyłączenie weryfikacji dwuetapowej wylogowuje inne urządzenia, tak jak jej włączenie. Panel moderacji otwiera się tylko w przeglądarce, w której przy logowaniu (albo przy włączaniu weryfikacji) podano kod z aplikacji — w innym wypadku pokazuje prośbę o ponowne zalogowanie z kodem.
- Kreator przepisu otwarty przed wdrożeniem nowej wersji nie wysyła już po cichu danych do kodu, który ich nie rozumie. Pierwsza czynność w takiej karcie nie zostaje wykonana, a na górze strony pojawia się po polsku „Ta strona jest nieaktualna” z przyciskiem „Odśwież stronę” — zamiast angielskiego okienka przeglądarki. Komunikat mówi wprost, co z danymi: szkic zapisany wcześniej zostaje, ale ostatnia zmiana w formularzu mogła się nie zapisać; przepis, który jeszcze się nie zapisał, po odświeżeniu będzie pusty; zdjęcie wybrane przed chwilą trzeba dodać jeszcze raz.
- Moderator nie zamyka już przyciskiem „To nic takiego” automatycznych oznaczeń własnych wpisów i komentarzy — zamyka je ktoś inny z moderacji. W miejscu przycisku panel mówi to wprost (audyt A5-11).
- Adres z linku do ustawienia hasła, linku do logowania albo zaproszenia (razem z adresem e-mail) nie trafia już do następnej odwiedzanej strony ani do statystyk odwiedzin. Serwer nie nadpisuje już ustawienia, które to blokowało (audyt A5-01, #1052).
- Zgłoszenie nielegalnej treści z adresem innej strony niż Kuking nie jest już łączone z przepisem lub wpisem Kuking o podobnym adresie — trafia do kolejki jako zgłoszenie z adresem do sprawdzenia ręcznie, a panel moderacji mówi, czy adres jest z Kuking, spoza niego, czy to tylko opis. Adres wpisany w formularzu wraca w listach do zgłaszającego jako zwykły tekst, a nie klikalny odnośnik (#1636).
- Drugie kliknięcie „Opublikuj”, „Zadaj pytanie” albo „Wyślij” przy „Ugotowałem” (albo ponowione wysłanie po słabym zasięgu) nie wgrywa i nie przetwarza zdjęć jeszcze raz, gdy wpis, pytanie lub wykonanie już się zapisało — od razu otwiera zapisany wpis. Nowe gotowanie z nowego formularza nadal zapisuje się osobno (#873).
- Zgłoszenie konta do moderacji trafia zawsze do osoby, której profil był widoczny przy otwarciu formularza — nawet jeśli ta osoba w międzyczasie zmieniła nazwę użytkownika, a zwolnioną nazwę zajął ktoś inny. Stare odnośniki z nazwą nadal otwierają formularz (#1599).
- Na kroku „Kogo chcesz obserwować?” wybór mieszczący się w limicie 20 osób daje się zapisać także po kilku wyszukiwaniach z zachowanymi wcześniejszymi zaznaczeniami. Niezaznaczone propozycje nie powodują już błędu (#1600).
- W wyszukiwarce na zakładce „Wszystko” przycisk „Pokaż więcej przepisów” wydłuża tylko listę przepisów, a „Pokaż więcej osób” — tylko listę osób. Druga lista zostaje taka, jaka była (#984).
- Moderator nie rozstrzyga już zgłoszenia, które dotyczy jego własnego wpisu, przepisu, komentarza albo profilu — taką sprawę zamyka ktoś inny z moderacji. Panel mówi wprost, dlaczego decyzji nie zapisał.

- List z linkiem do zalogowania, który utknął w kolejce dłużej niż ważność linku, już nie wychodzi — zamiast martwego linku wystarczy poprosić o nowy. List, który wychodzi z opóźnieniem, mówi, ile minut naprawdę zostało, zamiast obiecywać pełne pół godziny (#889). Gdy formularz logowania linkiem prosi „Kliknij „Wyślij mi link” jeszcze raz”, wpisany adres e-mail zostaje w polu (#890).
- Gdy dwie osoby w tej samej chwili zmieniają nazwę użytkownika na tę samą wolną nazwę, osoba, która zapisze drugą, nie widzi już błędu serwera. Wraca do formularza z komunikatem „Ta nazwa jest już zajęta — wybierz inną”, a imię, opis, region i specjalność zostają tak, jak je wpisała (#887).
- Wiadomość „ustaw hasło”, którą dostaje konto z niepotwierdzonym adresem zamiast linku do logowania, nie przychodzi już z nieważnym linkiem. Jeśli w międzyczasie poproszono o nową wiadomość albo link wygasł, stara nie wychodzi — działa ta najnowsza.
- Przy każdym polu hasła jest przycisk „Pokaż hasło”, który odsłania wpisane hasło, żeby przed wysłaniem sprawdzić literówkę albo włączony Caps Lock. Drugie naciśnięcie („Ukryj hasło”) znów je zasłania, a przy wysyłaniu formularza hasło zasłania się samo. Menedżer haseł i wklejanie działają jak dotąd (#948).
- Kreator przepisu przyjmuje liczbę porcji z dokładnością do setnych, tak samo jak formularz „Dopisz szczegóły”: szkic z 1,25 porcji otwiera się i zapisuje bez błędu. Liczba z trzema miejscami po przecinku, na przykład 1,255, nie jest już po cichu zaokrąglana — pod polem pojawia się prośba o wpisanie 1,25 albo 1,26 (#750).

- Przy wydzielonym serwisie pracy w tle zdjęcia, paczki z danymi i sprawdzanie treści nie czekają już w kolejce za mailami: każdy rodzaj pracy ma własny proces. W obecnym układzie z jednym kontenerem praca w tle zostaje w jednym procesie, żeby nie zabrakło pamięci dla strony. Przy wdrożeniu praca w tle dokańcza bieżące zadanie, zamiast przerywać je w połowie (#1030).
- Style i skrypty strony zostają zapamiętane w przeglądarce już po pierwszej wizycie, więc kolejne wejście nie pobiera ich ponownie, a przy braku sieci wygląd strony się nie rozsypuje. Gdy w pamięci przeglądarki brakuje miejsca, strona nadal działa normalnie (#1348).
- Wyszukiwanie w zakresie „Wszystko” bez wyników mówi teraz, że nie znaleźliśmy ani przepisu, ani osoby — wcześniej wspominało tylko o przepisie, choć szukało też ludzi. Podpowiada, żeby sprawdzić pisownię albo wpisać krócej (#944).

- Ekran „Potwierdź swój adres e-mail” nie obiecuje już wiadomości, gdy serwis nie wysyła poczty. Zamiast „Wysłaliśmy wiadomość” i rady o folderze „Spam” mówi, że wiadomość nie przyjdzie, i podaje adres, pod którym człowiek pomoże potwierdzić adres inaczej. Przycisk „Wyślij wiadomość jeszcze raz” pokazuje się tylko wtedy, gdy list naprawdę może wyjść (#1335).
- Kontrola poczty (`/health`, `kuking:sprawdz-poczte` i formularze wysyłające listy) nie uznaje już za działającą pocztę łańcucha `failover` ani `roundrobin`, w którym jest zapis do dziennika (`log`) albo do pamięci (`array`) — także pod własną nazwą mailera. Domyślny wpis `failover` wysyła teraz przez EmailLabs, a w zapasie przez SMTP, zamiast kończyć się dziennikiem (#1084).

- Ustawienia danych nie pokazują już „Pobierz” przy paczce, której zapis jest niepełny (brak pliku, rozmiaru albo daty przygotowania) — takie kliknięcie kończyło się błędem „nie znaleziono”. Baza nie przyjmie już gotowej paczki bez kompletu tych informacji (#1365).
- Archiwum profilu otwarte na roku, w którym nie ma już widocznych wpisów, mówi „Nie ma wpisów z tego roku” i prowadzi przyciskiem „Pokaż całe archiwum”, a lista lat zostaje na miejscu. Wcześniej taki link — na przykład zapisany przed usunięciem wpisu — twierdził, że cała osoba jeszcze nic nie pokazała (#1380).
- Panel moderacji: na liście oznaczeń automatu liczby przy zakładkach „Nowe”, „W trakcie” i „Rozpatrzone” liczą oznaczenia automatu, a nie zgłoszenia od ludzi. Liczba w zakładce zgadza się z liczbą pozycji, które pokaże jej kliknięcie (#990).
- Powiadomienie o pierwszym wpisie nowej osoby widzi tylko ktoś, kto teraz ma prawo moderacji. Po odebraniu roli albo przy zawieszeniu znika z listy i z licznika (wraca po przywróceniu), zamiast prowadzić na stronę bez dostępu (#1351).
- Komenda `kuking:przenies-zdjecia` przyjmuje `--po=<id>` i po każdej partii podpowiada, od czego zacząć następną. Zdjęcia z brakującym plikiem nie blokują już przenoszenia późniejszych; przebieg bez `--po` wraca do nich od początku (#1031).
- Na kroku „Kogo chcesz obserwować?” osoba znaleziona wyszukiwarką nie powtarza się już w „Osobach, które polecamy” — jej miejsce na liście polecanych zajmuje następna osoba (#1299). Gdy po błędzie w formularzu wracają Wasze zaznaczenia, nazwa, która w międzyczasie przeszła na inne konto, nie wraca zaznaczona przy nowej osobie; ekran mówi o tym wprost, a reszta zaznaczeń zostaje (#1340).
- Na Starcie, wśród wpisów z obserwowanych tagów, nie pojawia się już Wasz własny wpis ukryty przez moderację ani szkic. Taki wpis nie zastępuje też bloku „Świeżo z Kuking”. Nadal znajdziecie go pod jego adresem i we własnym archiwum (#1338).
- Lista zablokowanych osób w ustawieniach prywatności pokazuje po 20 osób, a dalsze wyświetla przycisk „Pokaż więcej osób”. Strona otwiera się szybko także przy długiej liście, a zgody na e-mail i wspomnienia są na miejscu jak dotąd (#1366).
- Listy „Obserwujący” i „Obserwowani” nie przewijają się już w bok na wąskim telefonie przy powiększonej czcionce przeglądarki. Długa nazwa łamie się w karcie zamiast wychodzić poza ekran; pismo i przyciski mają ten sam rozmiar co wcześniej (#1341).
- Konto zawieszone może złożyć żądanie usunięcia konta z „Twoich danych” — tak jak wcześniej mogło pobrać kopię danych. Formularz nie kończy się już odmową „konto jest zawieszone”. Zawieszenie nie znika: po cofnięciu usunięcia wraca razem ze swoim terminem (#1364).
- Gdy przyjęcie żądania usunięcia konta się nie uda, nic się nie zmienia: konto działa dalej, zostajecie zalogowani i widzicie komunikat, żeby spróbować jeszcze raz. Wcześniej konto mogło zostać oznaczone do usunięcia mimo ekranu błędu (#1347).
- Zakładka „Przepisy” na profilu wczytuje się szybciej: podpis autora na kartach przepisów jest pobierany raz dla całej strony, a nie osobno dla każdej karty. Kolejność i liczba przepisów na stronie się nie zmieniają (#1374).
- Gdy autor skutecznie odwoła się od zdjęcia treści i treść wraca do serwisu, osoba, która ją zgłosiła, dostaje nową wiadomość: „Po ponownym sprawdzeniu zmieniliśmy decyzję. Zgłoszona treść wróciła do serwisu.” Lista „Twoje zgłoszenia” pokazuje aktualny wynik, a karta sprawy — pierwszą decyzję i pod nią zmianę. Przy zgłoszeniu nielegalnej treści z adresem e-mail ta sama informacja idzie listem. Nie podajemy, kto się odwołał ani co napisał (#1024).
- W kolejce sygnałów automatu „To nic takiego — zamknij wszystkie” zamyka grupę taką, jaką moderator widział. Jeśli po otwarciu strony doszło do niej nowe oznaczenie, nic nie zostaje zamknięte, a ekran mówi: „Doszły nowe zgłoszenia — odśwież listę i sprawdź je” (#1059).
- Zgłoszenie nielegalnej treści wysłane ponownie po błędzie strony dosyła potwierdzenie odbioru z numerem sprawy, jeśli za pierwszym razem nie zostało zlecone — i nigdy nie wysyła go drugi raz. Ponowne wysłanie formularza z innym imieniem albo adresem e-mail zakłada nową sprawę z nowym numerem, zamiast po cichu pokazywać numer pierwszej (#1323, #1325).
- Powtórne wysłanie komentarza, który w międzyczasie przestał być widoczny w rozmowie, nie pokazuje już „Komentarz dodany.”. Zamiast tego komunikat mówi, że komentarz jest zapisany, ale nie jest teraz widoczny, i że nie trzeba go wysyłać ponownie. Drugi taki sam komentarz nie powstaje — także wtedy, gdy moderacja komentarz usunęła. Kto usunął komentarz sam, może napisać go od nowa (#1094).
- Wyszukiwarka znajduje teraz przepisy, które możecie otworzyć: osoba obserwująca autora znajdzie jego przepis „dla obserwujących”, a autor — własny przepis „tylko dla mnie”. Takie wyniki mają na karcie plakietkę „Tylko dla obserwujących” albo „Tylko dla mnie”. Gość i osoba nieobserwująca nadal widzą w wynikach wyłącznie przepisy publiczne, a szkice nie trafiają do wyników (#1320).
- Publiczny zeszyt nie mówi już innym osobom, ile jest w nim zapisów, których nie mogą zobaczyć. Tę liczbę widzi tylko właściciel zeszytu; inni widzą wyłącznie to, co mogą otworzyć, a gdy nie ma nic takiego — zwykły pusty zeszyt (#1297).
- Karta zeszytu i lista „Ostatnio zapisane” nie liczą już zapowiedzi przepisu, którego nie można otworzyć — bo przepis stał się prywatny, został usunięty albo konto jego autora jest zamknięte. Liczba na karcie zgadza się z tym, co widać w środku zeszytu, a zapis zostaje i wróci, gdy przepis znów będzie dostępny. Wpis z własnym tekstem albo zdjęciem, który wskazuje taki przepis, zostaje w zeszycie, na karcie i w „Ostatnio zapisane” — tylko bez tytułu, zdjęcia i odnośnika do przepisu (#1319, #1377).
- Wpis z własnym tekstem albo zdjęciem, który wskazuje przepis, nie znika już z odkrywania, feedu obserwowanych, profilu, strony tagu i feedu obserwowanych tagów, tablicy „kuKINGi na dziś” ani z nawigacji „poprzedni / następny wpis”, a liczba wpisów przy tagu znowu go liczy, gdy autor przepisu zmieni go na prywatny albo tylko dla obserwujących, gdy przepis zostanie ukryty lub usunięty. Wpis zostaje według własnej widoczności, a na karcie nie ma wtedy tytułu, zdjęcia ani odnośnika do przepisu — tak samo jak na stronie samego wpisu. Sama zapowiedź przepisu, bez własnej treści, dalej znika razem z przepisem (#1377).
- Powiadomienie o odpowiedzi znika z listy i z licznika, gdy nie widać już komentarza, pod którym ta odpowiedź stoi — na przykład po zablokowaniu jego autora albo ukryciu go przez moderację. Po zdjęciu blokady rozmowa i powiadomienie wracają (#1378).
- Pytanie ma teraz jeden adres — stronę w dziale pytań. Stary odnośnik do pytania jako zwykłego wpisu przenosi na właściwą stronę, a mapa strony dla wyszukiwarek podaje pytania pod ich własnym adresem — także te, które mają sam tytuł (#968).
- Strona przepisu bez gotowego zdjęcia nie wysyła już wyszukiwarkom niepełnych danych o przepisie. Gdy zdjęcie się przygotuje, dane pojawią się same (#1005).
- Zdjęcia przy krokach przepisu trafiają teraz także do danych dla wyszukiwarek — ale tylko te, które widać na stronie (#1370).
- Strona główna podaje wyszukiwarkom nazwę serwisu „Kuking” (#1008).
- Nad wpisem, pytaniem i profilem stoją okruszki, które prowadzą z powrotem: z wpisu do profilu autora, z pytania do „Poradźcie”, z profilu na stronę główną. Tę samą ścieżkę dostają wyszukiwarki (#1033).
- Gdy ktoś już odpowiedział na Wasz komentarz, nie da się go poprawić — odpowiedź mogłaby stracić sens, na przykład „Tak” pod pytaniem zmienionym z „Czy dodać sól?” na „Czy pominąć sól?”. Przed pierwszą odpowiedzią literówkę nadal można poprawić przez piętnaście minut. Jeśli poprawka nie przejdzie, wpisany tekst wraca do skopiowania razem z podpowiedzią, żeby dopisać sprostowanie jako odpowiedź. Odpowiedź ukryta przez moderację albo usunięta nie blokuje poprawki (#1337).
- Po potwierdzeniu nowego adresu e-mail komunikat mówi wprost, że inne urządzenia zostały wylogowane, a stare odnośniki do logowania i do ustawienia hasła przestały działać. Link do ustawienia hasła wysłany wcześniej na stary adres jest teraz kasowany, a bieżąca przeglądarka dostaje nowy identyfikator sesji (#979).
- Strona tagu, który nie ma jeszcze żadnego wpisu widocznego dla wszystkich, nie jest już indeksowana przez wyszukiwarki (`noindex, follow`), a link do niej w spisie tagów ma `rel="nofollow"`. Dla ludzi nic się nie zmienia: strona działa, pokazuje prawdziwe zero i zaprasza do dodania pierwszego wpisu. Po pierwszym publicznym wpisie strona wraca do indeksu sama.
- Polityka prywatności nie twierdzi już, że dziennik serwera znika razem z instancją serwisu. Mówi teraz, że dziennik przechowuje nasz dostawca hostingu, Railway, obecnie do 7 dni, i że kraju przechowywania dziennika jeszcze nie potwierdziliśmy.
- Polityka prywatności mówi teraz jednym głosem, że zdjęcia przechowujemy w Cloudflare R2, w części tej usługi zastrzeżonej dla Unii Europejskiej — w streszczeniu, w tabeli dostawców i w akapicie o przekazywaniu danych poza EOG. Nowa wersja polityki ma datę 24 września 2026.
- Strona tagu nie mówi już „widzisz tylko Ty” o Twoim wpisie tylko dla obserwujących. Wpis prywatny, wpis dla obserwujących i grupa mieszana mają teraz osobne, prawdziwe wyjaśnienie, dlaczego nie liczą się w spisie tagów.
- Gdy serwis nie zdoła zlecić przygotowania wgranego zdjęcia, nie zostawia już zdjęcia, które na zawsze „przygotowuje się”. Nic się wtedy nie zapisuje, a przy polu zdjęć pojawia się komunikat, żeby wysłać je jeszcze raz — wpisany tekst zostaje na miejscu.

- Po usunięciu własnego przepisu na Waszym profilu nie zostaje już pusta karta prowadząca do strony „Brak dostępu”. Znika z listy wpisów, z lat i z licznika naraz. Wpis i komentarze pod nim nie są kasowane; wpis z własnym opisem albo zdjęciem zostaje widoczny.
- Po blokadzie między Tobą a autorem przepisu Twoje „Ugotowałem” z tego przepisu dalej się otwiera — ze zdjęciem i notatką, także z zakładki „Ugotowane” na Twoim profilu. Przycisk „Zobacz i skomentuj” nie kończy się już odmową. Tytułu i adresu przepisu w tym wykonaniu nie widać, a sam przepis zostaje zamknięty.
- Paczka z Twoimi danymi zawiera teraz także wcześniejsze wersje Twoich przepisów, obserwowane tagi, historię zgód, połączone konta Google i Facebooka, zalogowane urządzenia (adres IP i przeglądarka), Twoje wiadomości do nas, zgłoszenia, odwołania, decyzje moderacji i pozostałe ustawienia konta. Czego w niej nie ma — na przykład dziennika bezpieczeństwa konta — paczka wymienia z powodami i mówi, jak dostać te dane na prośbę. Hasła ani kodów do logowania nie wydajemy nikomu.
- Edycja głównego zeszytu mówi wprost, że przycisk „Zapisuję” wkłada tam kolejne przepisy i wpisy, więc przy widoczności „Wszyscy” inne zalogowane osoby zobaczą także to, co zapiszesz później. Gdy główny zeszyt jest widoczny dla innych, przy szybkim „Zapisuję” stoi nazwa zeszytu i informacja, kto go widzi. Powrót do „Tylko ja” zamyka zeszyt dla innych i zostawia wszystkie zapisy.
- Liczba wpisów przy tagu w spisie tagów nie liczy już zapowiedzi przepisu, którego nie widać publicznie — przepisu tylko dla obserwujących, prywatnego, ukrytego albo usuniętego. Liczba zgadza się teraz z tym, co gość zobaczy na stronie tagu.
- Adres strony, z której pochodzi przepis, musi zaczynać się od http:// albo https:// — w kreatorze i w formularzu „Dopisz szczegóły”. Adres zapisany wcześniej w innej postaci nie blokuje poprawiania przepisu, ale nie jest już pokazywany jako odnośnik.
- Zaproszenie „Ugotowałem” pokazuje się tylko tam, gdzie da się z niego skorzystać. Gość i konto zawieszone nie widzą już przycisku, który i tak skończyłby się odmową — ani przy przepisie, ani w trybie gotowania.
- W trybie gotowania „Oznacz krok jako zrobiony” odhacza zawsze ten krok, który był na ekranie — także wtedy, gdy autor w międzyczasie zmienił kolejność kroków. Jeśli tego kroku już w przepisie nie ma, nic nie zostaje oznaczone, a ekran mówi, żeby przeczytać bieżący krok i oznaczyć go jeszcze raz.
- W trybie gotowania jest „Zacznij od początku”. Odhaczone kroki znikają dopiero po potwierdzeniu: wyjście z ekranu i anulowanie zostawiają postęp, a inne przepisy oraz historia wykonań zostają nietknięte.
- Poprawka własnego komentarza odrzucona wyłącznie dlatego, że minęło piętnaście minut, nie zabiera już napisanego tekstu. Tekst wraca w polu do skopiowania, razem z drogą powrotu do rozmowy, i działa bez JavaScriptu. Okno edycji nie jest przez to przedłużane, a cudzy ani usunięty komentarz tą drogą nie wraca.
- Stopka jest wyraźnie niższa, a pod nią nie ma już pustego szarego pasa — strona kończy się tam, gdzie stopka, a miejsce na dolną belkę nawigacji zostaje tylko wtedy, gdy belka naprawdę jest na ekranie.
- Przy bardzo dużym tekście w przeglądarce przycisk „Wygląd” nie zasłania już na końcu strony przełącznika jasnego i ciemnego wyglądu ani numeru wersji w stopce — stopka zostawia mu miejsce pod spodem.
- Ostrzeżenie o prośbie zmiany adresu e-mail zawsze trafia na adres, który konto miało w chwili prośby, także gdy list wyjdzie już po potwierdzeniu zmiany.
- Paczka z danymi nie zawiera już cudzego przepisu z zeszytu, którego autor przestał go Wam pokazywać — bo zmienił go na prywatny, ukryła go moderacja, jest blokada albo konto autora jest zamknięte. Taki przepis nie ma w paczce tytułu, autora ani Waszej notatki; każdy zeszyt podaje tylko, ile takich pozycji jest. Zapis w zeszycie zostaje, więc gdy autor znów udostępni przepis, wróci on w kolejnej paczce.
- Panel gospodarza: tagi promowane nie dostają już tej samej pozycji, gdy dwie osoby zmieniają listę jednocześnie, a przesunięcie nie nadpisuje równoległej zmiany. Lista ma stałą kolejność także przy starych remisach, pierwsze przesunięcie je porządkuje, a komunikat „Kolejność zmieniona” i wpis w dzienniku pojawiają się tylko wtedy, gdy kolejność naprawdę się zmieniła (#1308).
- Zmiana nazwy zeszytu na nazwę, którą w tej samej chwili zajął inny Wasz zeszyt (np. w drugiej karcie), nie kończy się już błędem serwera. Formularz wraca z komunikatem „Masz już zeszyt o tej nazwie. Wybierz inną.”, a wpisana nazwa zostaje w polu (#1339).
- Stary adres tagu połączonego z innym tagiem przekierowuje teraz na stałe (kod 301), więc wyszukiwarki przenoszą go na adres tagu docelowego (#1350).
- Na ekranie logowania i zakładania konta zdanie nad przyciskami wymienia tylko te serwisy, których przyciski naprawdę tam są. Gdy działa tylko wejście kontem Google, zdanie nie obiecuje już Facebooka — i odwrotnie (#1300).
- Formularz zgłoszenia treści mówi przy polu „Chcesz coś dopisać?”, że zmieści się najwyżej 2000 znaków — jeszcze przed wysłaniem. Wklejony dłuższy tekst nie jest obcinany; licznik pokazuje, o ile trzeba go skrócić (#1381).
- Panel wiadomości do nas pokazuje przy sprawie zamkniętej pod koniec miesiąca prawdziwy termin automatycznego usunięcia. Wcześniej przy niektórych datach (np. 31 stycznia) ekran podawał termin o dzień wcześniejszy niż ten, w którym sprzątanie naprawdę usuwa sprawę (#1345).
- „Oznacz wszystkie jako przeczytane” działa tylko na powiadomieniach, które widać na liście. Powiadomienie od osoby, z którą jest blokada, zostaje nieprzeczytane — po odblokowaniu wraca jako nowe, a nie jako coś, co już przeczytaliście.
- Szukanie znajomych w pierwszych krokach po rejestracji nie traci miejsca na Wasz własny profil. Gdy pasuje więcej niż pięć osób, widać pięć z nich i podpowiedź, żeby wpisać dokładniejsze imię.
- Zmiana hasła i ustawienie nowego hasła linkiem z listu są teraz jednym krokiem z anulowaniem zamówionej zmiany adresu e-mail: link potwierdzający nowy adres nie zdąży już wejść pomiędzy i przestawić adresu konta, a przerwana operacja nie zostawia nowego hasła obok nadal ważnej zmiany adresu. Po zmianie hasła w ustawieniach link „Nie pamiętam hasła” wysłany wcześniej przestaje działać, a bieżąca przeglądarka dostaje nowy identyfikator sesji (#1358).
- Moderator z włączonym logowaniem dwuetapowym może otworzyć wpis ukryty przez moderację — ze zdjęciami i komentarzami — zanim go przywróci albo rozstrzygnie odwołanie. Strona wyraźnie pisze „Ukryte przez moderację”, jest tylko do odczytu (bez komentowania, zapisu do zeszytu i zgłaszania), a każde takie otwarcie zostaje zapisane w dzienniku. Autor widzi przy swoim ukrytym wpisie, jak się odwołać. Inne osoby i goście nadal go nie widzą, a wpis nie wraca do strumienia, profilu ani mapy strony.
- Gdy do wpisu wybierzesz więcej zdjęć, niż się mieści, nowe zdjęcia nie są już po cichu zapisywane na serwerze. Formularz mówi, że nowych zdjęć nie dodano, a wcześniej zachowane zdjęcia zostają na swoim miejscu.
- „Pokaż więcej” na stronie głównej nie gubi już najnowszych wpisów, gdy w międzyczasie zmieni się to, co obserwujecie. Jeśli po zaobserwowaniu albo odobserwowaniu osoby lub tematu strona pokazuje już inny zestaw wpisów, lista zaczyna się od nowa od góry, zamiast doklejać dalszy ciąg z poprzedniego zestawu.
- Dwie otwarte karty poprawki tego samego komentarza albo odpowiedzi nie gubią już wcześniejszej zmiany. Gdy komentarz poprawiono w międzyczasie w innej karcie, druga poprawka nie nadpisuje go po cichu: nad polem widać, jak komentarz jest zapisany teraz, a Wasz tekst zostaje w polu, gotowy do porównania i ponownego zapisu. Ponowne wysłanie tej samej poprawki nie pokazuje fałszywego ostrzeżenia.
- Termin pobrania paczki z danymi na stronie „Twoje dane” podaje teraz także godzinę — tę samą co e-mail o gotowej paczce. Od tej właśnie minuty paczka jest opisana jako niedostępna, z podpowiedzią, jak przygotować nową.
- W trybie gotowania wybór „Nie usypiaj ekranu podczas gotowania” zostaje przy przejściu do kolejnego kroku i po oznaczeniu kroku jako zrobiony — w tej samej karcie i dla tego samego przepisu. Jeśli przeglądarka odmówi, przełącznik pokazuje, że ekran może zgasnąć. „Zakończ gotowanie” i ręczne odznaczenie kończą ten wybór (#1302).
- Zdjęcie bieżącego kroku w trybie gotowania wczytuje się od razu, bez czekania, aż przeglądarka ułoży stronę (#1368).
- Zdjęcia kroków mają opis dla czytnika ekranu na stronie przepisu, w trybie gotowania i w kopii przepisu w paczce z danymi: własny opis zdjęcia, a gdy go nie ma — „Zdjęcie do kroku N” zamiast pustego opisu albo samego numeru kroku (#1304).
- Powiadomienie o komentarzu pod Waszym „Ugotowałem” nie znika już z listy ani z licznika, gdy przepis, z którego gotowaliście, zostanie ukryty przez moderację albo usunięty. Zdjęcie, notatka i komentarze pod nimi były wtedy dalej dostępne — teraz prowadzi do nich także powiadomienie. Powiadomienie zostaje także po blokadzie z autorem przepisu, tak jak samo „Ugotowałem” (D-259). Inne osoby w rozmowie go nie widzą (#1385).
- Gdy dwie osoby w tej samej chwili potwierdzają zmianę na ten sam nowy adres e-mail, druga dostaje zwykły komunikat, że na ten adres jest już konto, zamiast strony błędu. Jej konto, adres i zalogowane urządzenia zostają bez zmian.
- „Usuń ze wszystkich moich zeszytów” działa w całości albo wcale. Jeśli coś przerwie wyjmowanie w połowie, przepis albo wpis zostaje we wszystkich zeszytach razem z Waszymi notatkami — wystarczy spróbować jeszcze raz. Komunikat podaje liczbę zeszytów, z których naprawdę wyjęliśmy zapis (#1384).
- Złożone odwołanie od decyzji moderacji zawsze trafia do administratorów. Jeśli zapis zawiadomienia albo zlecenia listu z potwierdzeniem się nie uda, odwołanie nie zostaje złożone w połowie i można je wysłać jeszcze raz — bez komunikatu „już do nas trafiło”. List z potwierdzeniem dla osoby zgłaszającej jest zapisywany razem z odwołaniem, więc chwilowa awaria poczty nie wymaga składania odwołania od nowa (#1305).
- Powtórzony zapisany krok przepisu nie nadpisuje już po cichu wcześniejszej instrukcji. Przy edycji i w kreatorze formularz pokazuje błąd przy powtórzonym kroku, a cały wpisany tekst zostaje na miejscu.
- Paczka z Waszymi danymi nie jest już oznaczana jako gotowa, gdy magazyn plików po cichu odmówił jej zapisania. Zamiast pustego „gotowe” widać, że przygotowanie się nie udało i można spróbować ponownie.
- Gdy wybrane zdjęcie zniknęło, zanim wpis zdążył się zapisać, a wpis nie ma tekstu, serwis nie publikuje już pustej karty i nie mówi „opublikowano”. Wracacie do formularza z informacją, że zdjęcie nie jest już dostępne i trzeba je wybrać ponownie albo napisać kilka słów.
- Dalsze strony profilu, spisu tagów, Odkrywaj i Poradźcie oraz zakładki profilu i filtry Poradźcie mają własny adres kanoniczny i własny adres w karcie udostępniania. Wyszukiwarka nie traktuje już ich jako kopii pierwszej strony, a dopiski śledzące (np. utm) dalej są z adresu usuwane.
- Profil otwarty z inną wielkością liter w nazwie (np. `/@basia_1971` zamiast `/@Basia_1971`) i każda strona otwarta przez `www` wskazują wyszukiwarce i karcie udostępniania jeden adres: zapisaną nazwę na `kuking.pl`. Stare linki dalej działają. Linki z przycisku „Podziel się” (WhatsApp, e-mail, Facebook) też prowadzą zawsze na `kuking.pl`, nawet gdy strona była otwarta przez `www`.

## Alfa 0.68 — minutnik przy gotowaniu i formularze, które nie gubią wpisanego tekstu

- Formularze ze zdjęciami przestały gubić to, co już wpisaliście. Wcześniej jedno źle wypełnione pole potrafiło zabrać całą resztę formularza razem z poprawnie wypełnionymi polami — teraz błąd zostaje przy swoim polu, a Wasz tekst czeka na miejscu. Podsumowanie błędów w kreatorze przepisu prowadzi do kroku, na którym to pole naprawdę jest.
- Postęp w trybie gotowania przeżywa poprawkę przepisu. Do tej pory każdy zapis przepisu po cichu odznaczał wszystkie odhaczone kroki, nawet gdy ich treść się nie zmieniła.
- Przy kroku przepisu jest minutnik, a ekran nie gaśnie, gdy gotujecie. Minutnik odlicza rzeczywisty czas — zmiana godziny w telefonie już go nie skróci ani nie przedłuży.
- Powiększone zdjęcie pokazuje swój opis. Gdy się nie wczyta, zamiast pustego miejsca pojawia się krótka informacja i przycisk „Spróbuj ponownie”.
- Wpis da się wyjąć z zeszytu. Karta wpisu ma przycisk „Usuń z zeszytu” obok odnośnika „Masz to w zeszycie”, a po kliknięciu od razu można zapisać ponownie.
- Stopka, okruszki nad przepisem i filtr na stronie pytań mają większe pismo i większe pola do kliknięcia — takie same, jakich wymagamy na pozostałych ekranach.
- Wyszukiwarka czyta frazę dosłownie: „100%” szuka teraz „100%”, a nie wszystkiego, co zawiera „100”. Wklejony adres z polskimi znakami staje się działającym odnośnikiem, a nie zwykłym tekstem.
- Pod komentarzem widać, ile znaków jeszcze zostało. Powiadomienie o komentarzu prowadzi wprost do właściwego wątku, a z komentarza usuniętego mimo odpowiedzi nie wystaje już jego dawna treść — ani w powiadomieniu, ani w pobranych danych konta.
- Ekran odwołania dla osoby zgłaszającej mówi, co się stało ze zgłoszoną treścią, ale nie ujawnia, jaką karę dostał jej autor. Potwierdzenie zgłoszenia i odwołanie po terminie przestały obiecywać rzeczy, które się nie wydarzą.
- „Zablokuj” i „Zdejmij blokadę” trafiają w tę osobę, którą widzieliście na ekranie. Jeśli w międzyczasie zmieniła nazwę użytkownika, serwis odmawia i mówi o tym po polsku, zamiast po cichu zablokować kogoś innego.
- Odwołanie i cofnięcie usunięcia konta są chronione przed zgadywaniem hasła tak samo jak logowanie. Komunikat po zablokowaniu podpowiada logowanie linkiem z poczty, a udana zmiana hasła blokadę zdejmuje.

## Alfa 0.67 — tagi ze zdjęciami z Waszych kuchni

- Wszystkie tagi mają większe kafelki, a katalog wykorzystuje szerokość ekranu komputera.
- Kafel pokazuje dostępne publiczne zdjęcie wpisu i jego autora. Gdy zdjęcia brakuje, pozostaje czytelna nazwa, licznik wpisów i znak Kuking.
- Kontrola przed wysłaniem zmian (`scripts/check.sh`) umie puścić baterię testów na kilku rdzeniach — `KUKING_TESTY_ROWNOLEGLE=6` skróciło ją z 310 do 105 sekund przy tych samych 4402 testach. Domyślnie nadal chodzi szeregowo: w pomiarze z 21 września równoległy przebieg wykonał jedną asercję mniej (83721 wobec 83722) i przyczyna pozostaje niewyjaśniona, więc zrównoleglenie jest świadomym wyborem, a nie zachowaniem domyślnym. Nic z tego nie zmienia działania serwisu.

## Alfa 0.66 — porządek w rozmowach i przygotowanie Poradźcie

- Przy błędzie odpowiedzi lub poprawki komentarza formularz zachowuje tekst i wskazuje właściwe pole.
- Przygotowaliśmy „Poradźcie”: pytania o gotowanie, odpowiedzi i kolejkę dla gospodarza. Dział pozostaje wyłączony do zakończenia odbioru i stopniowego uruchomienia.
- Usunięta odpowiedź z dalszą rozmową pozostawia miejsce dla tej rozmowy, ale nie zwiększa liczby odpowiedzi na pytanie.

## Alfa 0.65 — odnośniki prowadzą tam, gdzie obiecują

- „Poszukaj przepisów” w pustym zeszycie otwiera wyszukiwarkę przepisów. Odnośnik nad przepisem prowadzący do tablicy wpisów ma teraz zgodną z nią nazwę „Świeżo z Kuking”.

## Alfa 0.64 — wykonania i odpowiedzi liczone uczciwie

- Przy przepisie liczba wykonań obejmuje także kolejne gotowania tej samej osoby. Podpisy mówią teraz o wykonaniach i odpowiedziach, zamiast przedstawiać je jako liczbę osób.

## Alfa 0.63 — zobacz, co gotują inni pod tym tagiem

- Strony tagów pokazują kolaż najnowszych publicznych zdjęć od różnych osób. Zdjęcie prowadzi do wpisu, a przycisk dodawania otwiera formularz z wybranym tagiem.
- Promowane tagi mają karty ze zdjęciami i krótkim zaproszeniem. Pozostałe tagi nadal znajdziesz na liście alfabetycznej.

## Alfa 0.62 — zdjęcia i osoby przy tagach

- Przy tagach z co najmniej pięcioma publicznymi zdjęciami od trzech osób zobaczysz liczbę zdjęć i ich autorów. Przy mniejszym zbiorze strona tagu zaprasza do dodania własnego wpisu. Kolejność tagów pozostaje bez zmian.

## Alfa 0.61 — jasna instrukcja po zbyt dużym zdjęciu

- Jeśli jedno z nowych zdjęć wpisu przekracza limit, formularz prosi o ponowny wybór wszystkich nowych zdjęć. Opis i ustawienie widoczności pozostają zachowane.

## Alfa 0.60 — komentarze i wykonania bez cofania

- Na stronie przepisu przejście do kolejnych komentarzy zachowuje wybraną stronę wykonań — i odwrotnie. Możesz przeglądać obie listy bez wracania do początku.

## Alfa 0.59 — obie listy zostają na swoim miejscu

- W zeszycie przejście do kolejnej strony wpisów zachowuje wybraną stronę przepisów — i odwrotnie. Możesz przeglądać obie listy bez ciągłego wracania do początku.

## Alfa 0.58 — prawidłowy stan obserwowania

- Na listach obserwujących i obserwowanych przyciski pokazują, kogo obserwujesz. Po kliknięciu „Obserwuj” zobaczysz „Przestań obserwować”, także po ponownym otwarciu listy.
- Cofnięcie obserwowania nie wymaga już przejścia na profil.

## Alfa 0.57 — tagi podczas pisania wpisu

- W opisie wpisu możesz wpisać `#sernik` i wybrać tag z podpowiedzi. Przy istniejących tagach zobaczysz liczbę publicznych wpisów dostępnych dla Ciebie.
- Tagi wpisane lub wklejone do opisu zapisują się przy publikacji. Usunięcie hashtagu podczas edycji nie usuwa taga dodanego osobno ręcznie.
- Ręczne dodawanie tagów nadal działa. Wybrany wcześniej tag nie jest jednocześnie proponowany jako nowy, a ukryte tagi nie pojawiają się w odnośnikach pod wpisami.

## Alfa 0.56 — wybór zeszytu przy zapisie

- Przy przepisie i wpisie możesz wybrać własny zeszyt, również gdy treść jest już zapisana w innym miejscu. Szybki zapis do „Zapisanych” nadal jest dostępny.
- Pełne nazwy zeszytów zawijają się na małym ekranie. Jeśli wybrany zeszyt został usunięty przed zapisem, formularz pokazuje błąd przy właściwym wyborze.

## Alfa 0.55 — wskazanie błędnego stanu wiadomości

- Jeśli zapis stanu wiadomości w panelu zostanie odrzucony, odnośnik w podsumowaniu błędów prowadzi do wyboru stanu.
- Komunikat przy polu jest powiązany ze wszystkimi opcjami. Wpisana notatka pozostaje w formularzu.

## Alfa 0.54 — osobne notatki promowanych tagów

- Po błędnym zapisie notatki tekst i komunikat pozostają przy wybranym tagu. Pozostałe formularze zachowują własne wartości.
- Odnośnik w podsumowaniu błędów prowadzi do właściwej notatki. Usunięto powtórzony dopisek o nieobowiązkowym polu.

## Alfa 0.53 — czytelny błąd wyboru decyzji w odwołaniu

- Jeśli przy rozpatrywaniu odwołania nie wybrano wyniku, komunikat u góry prowadzi do właściwego pola.
- Wyjaśnienie pojawia się również przy wyborze decyzji, wyłącznie w wysłanym formularzu. Pozostałe odwołania zachowują własne wartości.

## Alfa 0.52 — widoczny fokus potwierdzeń w panelu

- Przy przechodzeniu klawiaturą do czyszczenia tablicy i kolażu panel pozostawia miejsce na cały obrys aktywnego przycisku, także przy zwiększonym tekście.
- Dodano regresje rozwijanych potwierdzeń i pomocy pocztowej, rzeczywistego zoomu 200% oraz fizyczne kontrole ujemne. Nie oznacza to zakończenia całego odbioru panelu #581.

## Alfa 0.51 — pełne nazwy dolnej nawigacji

- Skróty Start, Szukaj, Dodaj, Moje i Profil zachowują pełne nazwy na wąskim ekranie (#638).
- Przy większym tekście przyciski przechodzą do kolejnego rzędu; nie zmniejszamy pisma ani obszaru dotyku.

## Alfa 0.50 — krótsza nawigacja panelu na telefonie

- Narzędzia moderacji można rozwinąć przyciskiem „Nawigacja panelu”, dzięki czemu szybciej dociera się do treści (#581).
- Powrót do Kuking pozostaje widoczny. Na komputerze oraz bez JavaScriptu spis narzędzi jest rozwinięty.
- Menu obsługuje dotyk, mysz i klawiaturę; przy zmianie szerokości nie chowa aktywnego linku.

## Alfa 0.49 — propozycja instalacji po powrocie

- Po powrocie zalogowanej osoby serwis może raz zaproponować instalację, jeśli przeglądarka ją udostępnia (#278).
- Propozycja nie zasłania strony. Zamknięcie jest zapamiętywane na koncie; bez obsługi instalacji panel pozostaje ukryty.
- Wybranie instalacji nie jest liczone jako jej ukończenie.

## Alfa 0.48 — linki we wpisach i komentarzach

- Adresy HTTP, HTTPS i www we wpisach, komentarzach i odpowiedziach są klikalne (#634).
- Przed przejściem do innej witryny pokazujemy jej domenę, pełny adres i ostrzeżenie. Nie jest to skan antywirusowy ani zapewnienie o bezpieczeństwie strony.

## Alfa 0.47 — kolaż po wyczyszczeniu wyboru

- Kolaż powitalny pokazuje również jeden, dwa lub trzy dostępne zdjęcia.
- Nowsze wpisy bez gotowych zdjęć nie wypychają zdjęć z automatycznego doboru.
- Gdy nie ma dostępnych zdjęć, powitanie zajmuje jedną kolumnę bez pustego miejsca po prawej.

## Alfa 0.45 — przycisk rejestracji przy dużym tekście

- Na wąskim ekranie przycisk rejestracji zostawia więcej miejsca na pełny napis, zachowując wybrany rozmiar tekstu (#621).
- Przejście klawiaturą pozostawia zapas na obrys przycisku przy krawędzi okna.

## Alfa 0.44 — powiększanie zdjęć

- Zdjęcie można nadal otworzyć kliknięciem lub dotykiem, a link „Powiększ zdjęcie” pozostaje widoczny przy obsłudze klawiaturą także pod wysokimi zdjęciami (#561).
- Szybkie zamknięcie i ponowne otwarcie podglądu nie usuwa już wyświetlanego zdjęcia.
- Fokus linków w komunikatach ma czytelniejszy kontrast w ciemnym motywie.

## Alfa 0.43 — dokładniejsze odliczanie minutnika

- Minutnik uwzględnia czas, który minął podczas wstrzymania karty przez przeglądarkę (#571). Po wznowieniu nie odlicza pominiętych sekund od nowa.
- Bieżący czas można odczytać czytnikiem ekranu bez automatycznego ogłaszania każdej sekundy (#569).

## Alfa 0.42 — dalsze wyniki wyszukiwania

- „Pokaż więcej” pozwala dotrzeć do przepisów i osób poza pierwszymi 200 wynikami (#568).
- Dalsze strony pokazują zakres wyników i pozwalają wrócić do początku, również gdy wyniki w międzyczasie znikną.
- Przeglądanie dalszych przepisów zachowuje pozycję listy osób i odwrotnie. Filtry czasu, prywatność i blokady nadal obowiązują.

## Alfa 0.41 — proporcje mniejszej skali

- Przy rozmiarze tekstu poniżej 100% odstępy i zapas wewnątrz kontrolek zmniejszają się razem z tekstem; cele dotykowe zachowują minimum 48 px (#589).
- Domyślne odstępy przy 100% i 140% pozostają bez zmian. Wybór skali opisuje też zagęszczenie układu.

## Przygotowane — bezpieczeństwo logowania (#584)

- Wylogowanie innych urządzeń unieważnia również ich zapamiętane logowanie. Bieżąca sesja pozostaje aktywna; po jej utracie trzeba zalogować się ponownie. Ta sama ochrona obejmuje zmianę i reset hasła oraz decyzje o zamknięciu lub zawieszeniu konta.

## Alfa 0.40 — wygląd panelu moderacji

- Panel moderacji korzysta ze wspólnej identyfikacji: neutralnej nawigacji, czytelnych kart, formularzy i filtrów. Dłuższe nazwy narzędzi zawijają się obok ikon (#581).
- Zachowane są oznaczenie trybu moderacji, pełna szerokość pracy i dotychczasowe działania.
- Filtry dat mają więcej miejsca przy powiększonym tekście. Obrys klawiatury pozostaje widoczny także na ikonie kalendarza.
- Tabelę użytkowników można przewijać w dostępnym obszarze ekranu; przejście klawiszem Tab odsłania jej kolejne linki.
- „Sygnały automatu” pokazują podsumowanie błędów formularza, także gdy brakuje identyfikatora grupy. Notatka pozostaje do poprawienia.

## Alfa 0.39 — kolejka gospodarza

- Panel „Bez odpowiedzi” obejmuje również przepisy i wykonania „Ugotowałem”, z przejściem do komentarzy. Licznik uwzględnia dostęp gospodarza, a własne dopiski autora nie udają odpowiedzi innej osoby (#579).
- Odpowiedź z panelu ponownie sprawdza dostępność wpisu i zachowuje tekst po błędzie.

## Alfa 0.38 — szybkie ustawienia wyglądu

- Panel Aa · Wygląd pozwala od pierwszej wizyty zmienić rozmiar tekstu i motyw, także bez konta. Zapamiętuje wybór i pozwala wrócić do ustawień domyślnych (#574).

## Alfa 0.37 — pasek podczas przewijania

- Pasek z logo, logowaniem i rejestracją chowa się podczas przewijania w dół i wraca przy przewijaniu w górę. Fokus klawiatury przywraca pasek.

## Alfa 0.36 — niedostępne zapisy w zeszycie

- Zeszyt informuje o zapisach, których nie możesz teraz zobaczyć — także przepisach. Nie pokazuje mylącego pustego stanu ani prywatnych treści; komunikat pasuje również do cudzego publicznego zeszytu (#567).

## Alfa 0.35 — ponowne wysłanie formularza

- Ekran odzyskiwania formularza nie przypisuje każdego błędu potwierdzenia zbyt długiemu otwarciu strony. Wskazuje ponowienie wysłania i zachowuje osobne informacje o odzyskanej treści, zdjęciach oraz logowaniu (#549).

## Alfa 0.34 — odmiana czasu minutnika

- Instrukcja, podgląd przepisu i komunikat po uruchomieniu minutnika używają poprawnej formy „na 1 minutę” oraz „na 1 sekundę”. Czas i działanie odliczania pozostają bez zmian (#548).

## Alfa 0.33 — 15 września 2026

- Strona powitalna: kolejne wpisy zaczynają się pod poprzednią kartą we własnej kolumnie, bez pustych przerw wynikających z wysokości sąsiedniej karty (#560). Układ reaguje na rozwijanie treści, zdjęcia i zmianę szerokości; kolejność DOM oraz pojedyncza kolumna na telefonie pozostają.

## Alfa 0.32 — fotografie na stronie powitalnej

- Sekcja „Co się dziś gotuje” pokazuje najpierw duże fotografie dań, potem zwarte wizytówki osób. Gość dostaje jedno wspólne zaproszenie do założenia konta. Boczne tablice zachowują dotychczasowy układ, a dobór treści i zasady widoczności pozostają bez zmian (#557).

## Alfa 0.31 — szersze menu konta

- Menu „Konto” ma więcej miejsca na nazwy pozycji, w tym panel moderacji i wylogowanie. Przy zawijaniu belki pozostaje przy prawej krawędzi, a w wąskim i niskim oknie rozwija się w dostępnym miejscu pod przyciskiem (#555).

## Alfa 0.30 — zdjęcia w pustej kolumnie profilu

- Na cudzym profilu bez tagów i zeszytów pokazujemy trzy ostatnie widoczne wpisy ze zdjęciami. Zdjęcie i data prowadzą do wpisu; treści prywatne pozostają chronione (#551).

## Alfa 0.29 — prawdziwe komunikaty po publikacji

- Po pierwszym wpisie wskazujemy formularz kolejnego zdjęcia, bez niezmierzonej obietnicy szybszego dodawania (#545).
- „Ugotowałem” potwierdza zapis wykonania, bez obietnicy powiadomienia o własnym gotowaniu lub dla wymazanego autora. Ponowne wysłanie nadal zapisuje jedno wykonanie (#547).

## Alfa 0.28 — sprawdzone ekrany wejścia

- Łączenie konta z Facebookiem opisuje przycisk wejścia i możliwe potwierdzenie u dostawcy, bez obietnicy jednego kliknięcia (#542).
- Ekran braku adresu z Facebooka kieruje do pełnego formularza i nie obiecuje e-maila po każdym wykonaniu przepisu (#542).
- Automat dostępności obejmuje pięć stanów po powrocie z Google/Facebooka i zachowuje częściowy raport po błędzie pomiaru (#345).

## Alfa 0.27 — czytelność na małych ekranach

- Na wąskim, niskim ekranie i przy bardzo dużej czcionce obie belki nawigacji przewijają się ze stroną, aby nie zasłaniać formularzy ani zaznaczenia klawiatury (#434, #492).
- Na telefonie o szerokości 390 px powiadomienia z licznikiem i dostęp do konta mieszczą się w jednym rzędzie bez zmniejszania tekstu.
- Komunikat zbyt długiego imienia podaje rzeczywisty limit w rejestracji i ustawieniach profilu (#538).
- Usuwanie komentarza i odpowiedzi ma systemowy odstęp od zwykłych działań (#444).

- Fokus klawiatury na przyciskach w podpowiedziach pozostaje czytelny również przy najechaniu w ciemnym motywie (#539).

## Alfa 0.26 — czytelne przyciski w podpowiedziach

- Przyciski dodania kolejnego zdjęcia, zmiany kolejności zdjęć i dokończenia szkicu zachowują czytelny napis również wewnątrz podpowiedzi, w obu motywach (#534).
- Zwykłe linki w podpowiedziach zachowują kolor dobrany do ich tła.

## Alfa 0.25 — poprawny zapis i jasne komunikaty przepisów

- Składnik o nazwie do 240 znaków zapisuje się w całości przy tworzeniu i edycji przepisu (#526).
- Automatyczny zapis sprawdza długość i poprawność pól przed zmianą przepisu. Błędny tekst pozostaje w formularzu, a poprzednia zapisana wersja jest zachowana (#528).
- Po cofnięciu można wrócić do błędnego składnika lub kroku przygotowania i go poprawić.

- Potwierdzenie zapisu i opis udostępniania rozróżniają treści prywatne, dla obserwujących i publiczne (#530).

## Alfa 0.24 — pełny tekst po odrzuceniu formularza

- Odzyskiwanie po wygaśnięciu sesji lub przekroczeniu limitu zapytań mieści długie przepisy dopuszczone przez formularz. Ponowienie utworzenia i edycji zachowuje wszystkie kroki (#524).
- Podsumowanie walidacji mówi „Sprawdź formularz” również przy zbyt długiej lub nieprawidłowej wartości. Instrukcje rejestracji nie nazywają każdego błędu brakiem danych (#527).
- Zdjęcia nadal trzeba wybrać ponownie; ograniczenia rozmiaru odzyskiwania i ochrona danych wrażliwych pozostają.

## Alfa 0.23 — uczciwe informacje o odzyskiwaniu formularza

- Ekran limitu zapytań rozróżnia pełne i częściowe odzyskanie tekstu; pomoc zdjęcia nie obiecuje zachowania brakujących pól (#523).
- Ekrany wygaśniętej sesji i limitu informują, gdy zbyt duży formularz uniemożliwił odzyskanie tekstu. Nie uznają tego za pusty formularz.
- Publiczny formularz zgłoszenia treści po odmowie CSRF nie nakazuje zakładania konta ani ponownego logowania.

## Alfa 0.22 — polecane tagi w wyszukiwaniu

- Przed wpisaniem zapytania wyszukiwarka pokazuje kafle rzeczywistych polecanych tagów, z ich opisami i odnośnikami.
- Zarówno przy pełnej, jak i pustej liście można przejść do wszystkich tagów; pozostaje też droga do aktualności.

## Alfa 0.21 — precyzyjne komunikaty

- Instrukcje logowania przez Google i Facebooka opisują sposób wejścia bez obietnicy liczby kliknięć.
- Powiadomienie o pierwszym wpisie zachęca do odpowiedzi bez nieudokumentowanego twierdzenia o zachowaniu nowych osób.
- Podsumowanie automatu podaje rzeczywisty okres oznaczeń i nie obiecuje aktualnej widoczności treści po działaniach moderatorów.

## Alfa 0.20 — zainteresowania i powiadomienia

- Wybór zainteresowań ma większe kafle rzeczywistych tematów; wszystkie trzy kroki pozostają opcjonalne.
- Nagłówek zainteresowań jest spójny z pozostałymi stronami, a instrukcja mieści się także przy dużym powiększeniu tekstu.
- Instrukcja nie obiecuje wypełnienia strony głównej przy braku treści.
- Zwykłe powiadomienia ustawiają akcję obok treści na szerokim ekranie; pełne decyzje moderacyjne zachowują dotychczasową strukturę.
- Powiadomienia bez dostępnego autora mają pełne zdanie zamiast brakującej nazwy.

## Alfa 0.19 — zeszyty zgodne z wizualizacją

- Zeszyty mają ciemne karty, a ostatnie zapisy znajdują się pod nimi w głównej części strony.
- Przepisy w zeszycie mają większe zdjęcia nad pełnymi tytułami i układają się w siatkę dopasowaną do dostępnego miejsca.
- Klawiatura zaznacza krótkie „Zobacz przepis”, dzięki czemu długi tytuł nie wypycha fokusu pod nawigację.
- Instrukcja pustego zeszytu wyjaśnia zapisywanie bez obietnicy stałego dostępu do każdej treści.

## Alfa 0.18 — kolejne ekrany zgodne z wizualizacją

- Logowanie i rejestracja mają osobną kartę formularza obok zaproszenia.
- Na szerokim ekranie tytuł, opis i dane przepisu stoją obok zdjęcia; wszystkie akcje są pod nimi.
- Profil ma większy awatar oraz jeden zestaw czytelnych statystyk pod ciemnym nagłówkiem.
- Strona publiczna wyjaśnia zeszyty, widoczność i pobieranie własnych treści w trzech kartach.
- Przy jednoczesnym dużym powiększeniu pisma i tekstu aplikacji wąski nagłówek mieści znak garnka bez poziomego przewijania.
- Obrys zaznaczonej klawiaturą zakładki mieści się w jej ramie także przy powiększeniu strony.

## Alfa 0.17 — publiczna strona zgodna z wizualizacją

- Trzy otwarte, numerowane kroki z odnośnikami zastępują białe kafle „Jak działa”.
- Duży blok „Twój przepis. Czyjś dobry obiad.” stoi bezpośrednio pod krokami, z publicznym zdjęciem i podpisem autora.
- Tablica osób, dania i najnowsze wpisy pozostają dostępne niżej.

## Alfa 0.16 — spójne karty osób

- Karty proponowanych osób na Odkrywaj i w wyszukiwaniu mają te same proporcje co na Start.

## Alfa 0.15 — kompozycja strony głównej zgodna z wizualizacją

- Krótkie powitanie, odrębny nagłówek aktualności i duży tytuł w ciemnym kaflu dodawania z pierścieniem.
- Na komputerze Start / Odkrywaj / Mój zeszyt oraz osobne Szukaj. Mobilne pięć pozycji bez zmian.
- Ciemny wstęp „Co dobrego u innych?” i osobne karty osób oraz dań; zachowane rzeczywiste propozycje, notatki, podglądy i obserwowanie.
- Nowy wygląd obejmuje także zalogowane wejście przez `/`, wyszukiwanie i stronę publicznych wpisów. Pomoc wyjaśnia kolejność strumienia.

## Alfa 0.14 — czytelne wiadomości i dokładniejsze instrukcje

- Powiększyliśmy drobne teksty w e-mailach oraz linki w tygodniowym podsumowaniu.
- Wiadomość o pobraniu danych podaje także godzinę wygaśnięcia linku.
- Instrukcje logowania opisują dodatkowe potwierdzenie na stronie. Ustawienia adresu e-mail nie sugerują już wysyłania hasła pocztą.
- Instrukcje zabezpieczenia konta i edycji przepisu dokładniej opisują wymagane kroki.
- Przy edycji opublikowanego przepisu komunikaty mówią o zapisanych zmianach, a nie o szkicu.
- Pobrana paczka danych obsługuje ciemny wygląd systemu. Ostrzeżenie o przygotowywanych zdjęciach nie obiecuje już terminu ich gotowości.

## Alfa 0.13 — aktualizacja zainstalowanej aplikacji

Poprawiliśmy pobieranie aktualizacji w aplikacji zapisanej na telefonie, aby nowy ekran braku połączenia docierał także do osób korzystających ze starszej wersji. Aktualizacja nie przeładowuje otwartego formularza.

## Alfa 0.12 — spójny wygląd także poza głównymi ekranami

- Ekrany awarii i braku internetu oraz pobrane dane mają nową oprawę i czytelny krój pisma.
- Pozostałe wiadomości systemowe mają spójny wygląd. Długie adresy nie rozpychają wiadomości na telefonie.
- Zainstalowana aplikacja odświeża ikony po zmianie marki i zachowuje dostępny ekran bez internetu.
- Komunikaty awarii podają, co zrobić, bez niepotwierdzonych zapewnień o stanie danych lub terminie powrotu.
- Sprawdzenie antyspamowe mieści się na wąskim telefonie i korzysta z wybranego jasnego lub ciemnego wyglądu.
- Podpowiedzi składników i przygotowania mają poprawne nowe linie. Wskaźnik kroków przepisu mieści się także przy dużym tekście na wąskim ekranie.

## Alfa 0.11 — klawiatura i duży tekst

Karty dań można nadal otwierać po kliknięciu zdjęcia lub opisu. Przy poruszaniu się klawiaturą fokus obejmuje czytelną nazwę, dzięki czemu wysoka karta nie chowa go pod nawigacją.

## Alfa 0.10 — spójne podstrony i wiadomości

- Nagłówek każdego profilu ma tę samą grafitową oprawę. Tytuły przepisów korzystają z nowej typografii.
- Opisy w ustawieniach i przy wyborach formularza są czytelniejsze.
- Wiadomości e-mail otrzymały nową paletę, prosty krój pisma i spójne przyciski.
- Instrukcje logowania i pomocy są krótsze i precyzyjniejsze.

## Alfa 0.9 — pełny układ nowej marki

Pływająca nawigacja, ciemny blok publikacji, nowe karty i typografia. Spójny wygląd profilu, zeszytów, wyszukiwarki, przepisów i formularzy. Funkcje korzystają z dotychczasowych danych i ustawień konta.

Ten plik jest dla **ludzi**, nie dla programistów. Piszemy tu, co widać
na ekranie — nie jak się nazywa klasa, którą przy okazji przeniesiono.

Każde podbicie numeru wersji (`config/kuking.php`, klucz `kuking.wersja.etykieta`)
ma tu swój wpis. Jedno pilnuje drugiego: wersja bez wpisu jest numerem bez
treści, a wpis bez wersji nie da się z niczym powiązać.

Numer rośnie przy każdej zmianie, którą **człowiek zobaczy**: nowy ekran,
zmieniony układ, nowa funkcja, inne zachowanie formularza. Poprawki bez śladu
w interfejsie — testy, refaktor, dokumentacja — numeru nie ruszają, więc i tu
ich nie ma.

---

## Alfa 0.8 — 12 września 2026

### Wygląd i strona główna

- Jasne neutralne tło, białe karty i grafitowe pismo. Czerwone akcenty mają
  osobne odcienie do jasnego i ciemnego motywu.
- Powitanie na stronie głównej dostało wyraźniejszą oprawę.
- Podpis przy dodawaniu zdjęcia ma teraz 18 px zamiast 16 px przy domyślnym
  rozmiarze tekstu. Przy dużej czcionce opis nadal przechodzi do własnego wiersza.

### Formularze i zeszyt

- Błędy formularzy mówią, co poprawić, i prowadzą do odpowiedniego pola.
- Nieprawidłowy wybór zeszytu daje komunikat z prośbą o odświeżenie strony
  i ponowny wybór, zamiast błędu serwera.

### Wpisy

- Szkic ma podpis „Szkic — jeszcze nieopublikowany” zamiast pustego odnośnika daty.

---

## Alfa 0.7 — 12 września 2026

### Zdjęcia

- **Po dodaniu zdjęcia widać zdjęcie, a nie napis o nim.** Do dziś po
  opublikowaniu wpisu każdy — zawsze, nie od czasu do czasu — dostawał zdanie
  „Twoje zdjęcie się jeszcze przygotowuje". Przyczyna nie leżała w obciążeniu,
  tylko w kolejności: wgranie zdjęcia i publikacja wpisu to jedno żądanie, więc
  w chwili rysowania strony nie było jeszcze ani jednej gotowej wersji zdjęcia.
  Teraz jedna powstaje od razu. Waży **61,5 kB zamiast 6,14 MB** oryginału
  i zostaje potem w serwisie, więc telefon pobiera 66,8 kB tam, gdzie wcześniej
  178,6 kB.
- **Zdjęcie widać już w trakcie wysyłania**, jeszcze zanim dojedzie na serwer —
  prosto z pamięci telefonu. Wcześniej pokazywało się jako znaczek zajmujący
  **niecałą połowę** szerokości; teraz bierze całą.
- **Wpis z kilkoma zdjęciami, z których część się jeszcze przygotowuje, nie
  rozjeżdża się już w bok.** Blok z komunikatem brał pół szerokości karty
  i pięć wierszy — teraz całą i dwa.

### Karta wpisu i strumień

- **Menu przy wpisie to trzy kropki, bez podpisu.** Tak samo jak w miejscach,
  które nasi ludzie znają od lat. Sam przycisk zszedł ze **125 px na 48 px**,
  a główka karty na wąskim telefonie z osiemnastu wierszy na osiem.
- **Data przy wpisie z tego roku nie powtarza roku** — „12 września, 10:04"
  zamiast „12 września 2026, 10:04". Przy starszych wpisach rok zostaje, bo
  bez niego data przestaje być prawdą.
- **Wpis, który jest tylko wskazaniem przepisu, prowadzi wprost do przepisu.**
  Wcześniej otwierał pustą stronę — bez zdjęcia i bez przepisu — z której
  trzeba było kliknąć jeszcze raz. Przy okazji: taka strona pokazywała
  „publicznie" także pod przepisem widocznym wyłącznie dla obserwujących.
- **Zakładka „Świeżo z kuKING" odzyskała zjedzoną spację**, a awatary przy
  wpisach przestały być ściskane w owal.

### Komentarze

- **„Odpowiedz" i „Popraw" stoją obok siebie**, a nie jedno pod drugim — blok
  akcji zszedł ze 196 px na 138 px. Przy najwęższych telefonach nadal się
  zawijają, bo naprawdę się nie mieszczą.
- **Zniknęła pustka nad „Napisz komentarz"**, a między podpisem pola a samym
  polem pojawił się odstęp, którego tam nie było wcale.

### Profil i ustawienia

- **`@nazwa` stoi obok imienia**, a nie w osobnym wierszu. Rząd przycisków
  profilu wjechał dzięki temu **nad zgięcie ekranu** — na telefonie pierwszy
  raz widać go bez przewijania.
- **„Ustawienia" prowadzą na stronę o nazwie „Ustawienia".** Do dziś ten napis
  otwierał ekran zatytułowany „Czytelność". Nowa strona jest spisem wszystkich
  dziewięciu ekranów ustawień.
- **Menu konta przy awatarze** — „Mój profil", „Ustawienia", „Wyloguj się" —
  i „Powiadomienia" w pasku górnym na telefonie. Wcześniej własny profil był
  jedyną drogą do obsługi konta z telefonu.

### Strona główna

- **Powitanie przestało zmyślać porę dnia.** „Dobry wieczór" witało od 15:00,
  a godzinę serwis liczył w strefie serwera — latem o 11:50 uważał, że jest
  9:50, a po 23:00 mówił „Dzień dobry". Teraz wita „Witaj" i pyta, co dziś
  gotujesz; to jest prawdą o każdej porze i w każdym kraju.

### Pod spodem (bez zmian na ekranie, ale warto wiedzieć)

- Komenda pokazująca, do kogo nie doszedł list z serwisu — bez pokazywania
  żetonu z takiego listu.
- Dziennik decyzji urósł o dziesięć wpisów (D-172 … D-181), w tym o trzy
  REGUŁY, a nie pojedyncze poprawki: o tym, że czerwień w testach nie musi
  pochodzić ze zmiany, którą właśnie oglądasz; o tym, że reguła CSS oparta na
  „pierwszym elemencie" nie trafia w żadne widoczne pole formularza; i o tym,
  że skrócenie listy kolumn w zapytaniu potrafi po cichu zgasić zdjęcie.

---

## Alfa 0.6 — 11 września 2026

### Dla wszystkich

- **Nazwa serwisu wygląda wszędzie tak samo: kuKING, dwukolorowo.** Do tej pory
  w zdaniach pisaliśmy „Kuking", a dwukolorowy zapis trzymaliśmy na jedno
  miejsce na ekranie. Teraz nazwa jest pisana tak samo w nagłówkach, w tekście
  i w stopce — bo to nazwa mieszkańca tego serwisu, nie żart, który trzeba
  racjonować. Nazwa zostaje zwykłym „Kuking" tam, gdzie kolory nie działają
  (tytuł okna, temat listu, opis zdjęcia) oraz w komunikatach o błędach,
  w sprawach moderacyjnych i w dokumentach — tam nie ma miejsca na charakter.
- **Przycisk na stronie powitalnej mówi teraz, co obiecujemy:** „Zostań
  kuKINGiem — bez opłat i bez reklam". Zamiast „to darmowe", które brzmiało
  jak sprzedaż.
- **Podpowiedź pod komentarzem mówi, ILE wystarczy, a nie JAK pisać:**
  „Choćby jedno zdanie. Pytanie do autora też jest w porządku."
- **Mniej tłumaczenia się w tekstach.** Na stronie „Napisz do nas" cztery różne
  miejsca zapewniały, że odpisuje człowiek, a nie automat — zostało jedno, to,
  za którym stoi konkret. Powtarzane cztery razy budziło dokładnie to
  podejrzenie, które miało uspokoić.
- **Ekran „Dopisz szczegóły" przestał obiecywać, że nie trzeba przewijać.**
  Stało tam „nie musisz nic przewijać ani szukać", a zmierzona wysokość tej
  strony to od **10 249 px** (sam tytuł i puste wiersze) do **16 586 px**
  (osiem składników i sześć kroków) — czyli od 16 do 26 ekranów telefonu. Cała
  informacja została: wszystko jest na jednej stronie, nic nie jest
  obowiązkowe, wypełnij tyle, ile chcesz, a poprawnie wpisane dane nie zginą.
- **„Świeżo z Kuking" nie ma już pustej prawej kolumny.** U zalogowanych trzecia
  kolumna była zarezerwowana i puściusieńka — **656 px pustki** przy szerokim
  oknie, **452 px** przy węższym — a gość dostawał całą stronę zwiniętą do
  wąskiej szpalty. Teraz stoi tam tablica „kuKINGi na dziś", ta sama, która na
  tym ekranie już była, tylko niżej. Strona skróciła się z 10 557 do 8 898 px,
  a kolumna z tekstem ma tyle samo miejsca co przedtem.
- **Odstępy na stronie przepisu.** Pięć par bloków tekstu stało dosłownie na
  zero pikseli — tytuł kleił się do wiersza z autorem, nagłówek „Składniki" do
  listy, „Skąd ten przepis" do pierwszego akapitu. Teraz każda para ma odstęp,
  ten sam na telefonie i na komputerze, i rośnie razem z tekstem, gdy ktoś
  powiększy czcionkę w przeglądarce.
- **Źródło przepisu pokazuje się dokładnie tak, jak je wpisałeś.** Widok
  doklejał z przodu „Po", więc wpisane „Nasze smaki" wychodziło jako „Po Nasze
  smaki.", a „po mamie" jako „Po po mamie.". Samo pytanie w formularzu też się
  zmieniło — pyta teraz „od kogo albo skąd", bo o to właśnie chodzi.
- **Regulamin i polityka prywatności mówią o usłudze, a nie o sobie.** Zniknęły
  zdania o tym, jak dokument był pisany i co sobie o nim myślimy. **Wszystkie
  niewygodne fakty zostały** — również te o braku podpisanych umów powierzenia,
  braku inspektora ochrony danych i nieustalonym okresie życia danych
  w kopiach zapasowych. Poprawił się przy tym błąd merytoryczny: §8 mówił
  „przez pierwsze 24 godziny", a termin liczy się od pierwotnej decyzji.
- **W logotypie kolor marki został tylko na „King".** „.pl" jest ciemne, tak jak
  „ku" — jeden akcent w znaku zamiast dwóch.
- **Awatar bez zdjęcia przestał się zwijać do rozmiaru litery.** Konto, które nie
  dodało zdjęcia profilowego, pokazuje inicjał w kółku o właściwej wielkości.
- **Wyłączony przycisk w karuzeli nie drga przy naciśnięciu**, a główne pole
  wyszukiwania ma tę samą wysokość co pozostałe pola w serwisie.

### Pod spodem (bez zmian na ekranie, ale warto wiedzieć)

- **Komendy konsolowe odmieniają rzeczownik przez liczbę.** „Dopisano 1 wpisów"
  zniknęło z czterech komend. Reguła odmiany była w projekcie od dawna — po
  prostu nie była tam użyta.

---

## Alfa 0.5 — 11 września 2026

### Dla wszystkich

- **Prośba o link do zalogowania nie wpuszcza już na cudze konto.** Jeśli ktoś
  założył konto na Twój adres, a ten adres nigdy nie został u nas
  potwierdzony, formularz „Wyślij mi link do zalogowania" przysyła
  teraz wiadomość z **ustawieniem nowego hasła**, a nie przycisk wchodzący
  prosto na to konto. Po ustawieniu hasła stare hasło przestaje działać, a
  wszystkie otwarte sesje na tym koncie zostają zamknięte — nawet jeśli ktoś
  obcy z nich korzystał.
- **Kliknięcie odnośnika z tej wiadomości potwierdza adres.** Od tej chwili
  konto wraca do zwykłego logowania jednym przyciskiem.
- Ekran po wysłaniu formularza wygląda **dokładnie tak samo** jak dotąd i
  dokładnie tak samo dla adresu, który konta u nas nie ma — żeby nie dało się
  z niego wyczytać, kto ma tu konto, a kto nie.

---

## Alfa 0.4 — 11 września 2026

### Dla wszystkich

- **Dodany przepis jest wreszcie widoczny tam, gdzie ludzie patrzą.** Do tej
  pory opublikowany przepis stał wyłącznie na profilu autora i w wyszukiwarce
  — czyli tam, gdzie trzeba go było już szukać. Teraz pokazuje się w „Świeżo
  z Kuking", w feedzie osób, które autora obserwują, i na tablicy „kuKINGi na
  dziś", z tytułem, zdjęciem i przyciskiem „Ugotowałem" od razu pod ręką.
- **To nie jest kopia przepisu, tylko droga do niego.** Poprawiony tytuł albo
  wymienione zdjęcie widać w strumieniu natychmiast, bez czekania i bez
  drugiego kliknięcia.
- **Przepis schowany, usunięty albo zawężony do obserwujących znika ze
  strumieni razem z przepisem** — nie zostaje po nim żadna karta.
- **Dwa kliknięcia „Opublikuj" dają jedną pozycję w strumieniu, nie dwie.**

---

## Alfa 0.3 — 11 września 2026

### Dodawanie przepisu przestało odstraszać

- **Ekran dodawania przepisu pyta o sześć rzeczy zamiast prawie stu.** Zdjęcie,
  tytuł, składniki, przygotowanie, kto to zobaczy, „Opublikuj". Porcje, czasy,
  trudność, pochodzenie przepisu i skan starej kartki przeniosły się na osobny
  ekran „Dopisz szczegóły" — wypełniasz je **po** opublikowaniu albo wcale.
- **Składniki i przygotowanie wpisuje się zwykłym tekstem.** Można wkleić listę
  z kartki albo z maila — każdy wiersz stanie się składnikiem, a pusta linia
  rozdzieli kroki. Nie trzeba już dodawać pól po jednym.
- **Przepis bez listy składników też da się opublikować.** Jeśli znasz danie
  z głowy i wolisz opisać je zdaniem, nic Cię nie zatrzyma. Składniki możesz
  dopisać później.
- **Zaproszenie „dopisz szczegóły" pojawia się tylko wtedy, gdy naprawdę jest
  co dopisać.** Przepis wypełniony do końca go nie dostaje.
- **Nad każdym formularzem dodawania widać obie drogi** — „Zdjęcie i kilka
  słów" oraz „Cały przepis". Wcześniej w większości miejsc w ogóle nie było
  widać, że istnieje ta druga.

### Więcej treści na ekranie, mniej przewijania

- **Strona przepisu ma drugą kolumnę.** „Ugotowałem", „Zapisuję", „Gotuję"
  i „Podziel się" stoją obok treści, a nie nad nią — strona zrobiła się
  o kilkaset pikseli krótsza, a „Ugotowałem" widać wyżej.
- **„Świeżo z Kuking" i „Co się dziś gotuje" układają się w dwie kolumny**
  tam, gdzie jest na nie miejsce. Lista skróciła się prawie o połowę.
- **Pola do wpisywania są większe** — jednowierszowe 64 px zamiast 56,
  wielowierszowe 176 px zamiast 128.

Przy powiększonej czcionce wszędzie wraca jedna kolumna. Nic się nie chowa.

### Dla moderatorów i administratorów

- **Panel bierze całą szerokość okna.** Tabela kont na szerokim monitorze
  (od około 1600 px) mieści się bez przewijania w bok. Na węższym ekranie
  tabela dalej się przewija — ale w swoim polu, nie całą stroną.
- **Puste kolejki mówią pełnym zdaniem**, zamiast jednej linijki tekstu.

### Dokumenty

- **Regulamin i polityka prywatności nie mówią już o sobie, że nie były
  sprawdzone przez prawnika.** Wszystkie zdania o tym, jak działa serwis,
  zostały bez zmian — zniknęła tylko uwaga o tym, kto tych dokumentów nie
  czytał.
---

## Alfa 0.2 — 11 września 2026

### Dla wszystkich

- **Długie wpisy nie zajmują już całego ekranu.** Wpis dłuższy niż osiem
  wierszy albo czterysta znaków pokazuje początek i odnośnik „Czytaj dalej",
  który prowadzi na stronę wpisu. Lista składników liczy się po wierszach,
  a nie po znakach — bo to wiersze zjadają ekran.
- **Przycisk „Zostań kuKINGiem" nie rozpada się już na telefonie.** Wcześniej
  napis łamał się w środku wyrazów („Zost / ań kuKINGi / em"); teraz mieści
  się w dwóch wierszach łamanych na spacjach.
- **Gość widzi stronę w pełnej szerokości**, z prawą szyną, tak samo jak
  osoba zalogowana. Wcześniej strona zwężała się bez powodu.
- **Logotyp:** człon „King" wrócił do koloru marki.
- **Wejście kontem Google i Facebooka stoi nad formularzem**, a nie pod nim.
  Wcześniej widziała je tylko osoba, która i tak wpisała już hasło.
- **„Co się dziś gotuje" pokazuje więcej.** Wybór gospodarza jest teraz
  uzupełniany automatycznie do pełnej tablicy — wcześniej zaznaczenie choćby
  jednej pozycji w panelu wyłączało dobieranie i strona zostawała w połowie
  pusta.
- **Karty wpisów i sekcje stron przestały wyglądać identycznie.** Sześć
  różnych rzeczy — karta wpisu, formularz, sekcja strony, blok szyny, ramka
  z wyjaśnieniem, kafel do kliknięcia — miało do tej pory ten sam wygląd.
  Teraz widać, co jest treścią, co trzeba wypełnić, a co tylko wyjaśnia.

### W formularzach

- **Pola w jednym rzędzie stoją równo.** „Na ile porcji" wisiało wyżej niż
  „Przygotowanie" i „Gotowanie", bo ma krótszy podpis.
- **Rozmiar tekstu schodzi niżej niż dotąd** — doszły trzy mniejsze rozmiary
  dla osób, którym domyślny jest za duży.

### Dla moderatorów

- **Menu poza panelem pokazuje jedno wejście, nie dziewięć pozycji.**
  Przy wejściu stoi liczba rzeczy czekających we wszystkich kolejkach razem;
  rozbicie na kolejki jest w panelu.
- **Zdjęcia w kolażu na stronie powitalnej wybiera się w panelu**, spośród
  zdjęć z wpisów publicznych. Wcześniej były wpisane na sztywno.
- **Tablica dnia i karty wpisów mają równy rytm**, a rzadsze akcje schowały
  się do menu „Więcej".

### Pod spodem (bez zmian na ekranie, ale warto wiedzieć)

- Kopię bazy i próbę jej odtworzenia robi się dwoma komendami, a próba
  sprawdza **wynik**, nie kod wyjścia.
- Cofnięcie migracji, które skasowałoby dowód zgody z RODO art. 7, **odmawia**
  i mówi, co zrobić zamiast tego.

---

## Alfa 0.1 — pierwsze wydanie

Wersja, od której zaczęliśmy. Historia sprzed 11 września 2026 jest
w historii repozytorium — ten plik zakładamy dziś i nie odtwarzamy go wstecz,
bo wpisy pisane z pamięci po fakcie są gorsze niż ich brak.
