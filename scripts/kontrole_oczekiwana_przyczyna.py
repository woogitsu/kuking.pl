"""Wzorce oczekiwanej przyczyny kontroli negatywnych Alfa 0.8 (#1011).

Klucz to nazwa kontroli z `checks` w `scripts/kontrole-negatywne-alfa08.py`
(literówka wywraca preflight z nazwą kontroli). Wartość to wyrażenie regularne
(`re.search`, `re.DOTALL`) dopasowywane do komunikatu KAŻDEJ porażki po mutacji,
z białymi znakami zwiniętymi do jednej spacji. Jedna kontrola może mieć kilka
porażek (szeroki filtr, testy z data providerem) — wtedy wzorzec jest alternatywą
`a|b`, a KAŻDA porażka musi pasować do którejś jej gałęzi.

JAK PISAĆ WZORZEC. Wskaż regułę, którą mutacja ma naruszyć: fragment WŁASNEGO
komunikatu asercji (`assertTrue($x, 'Bramka pomija job …')`) albo tekst, którego
brakuje w wyniku (`contains "1 aktywnych ukryć"`). Nie wystarczy ogólne
`Failed asserting that` — pasuje do każdej cudzej asercji, czyli do niczego.
Nie wpisuj ścieżek, identyfikatorów, znaczników czasu ani numerów linii: wzorzec
ma przeżyć przeformatowanie testu.

`Wyjatek(...)` dopuszcza wyjątek (`<error>`) jako objaw tej mutacji — tylko tam,
gdzie sama mutacja go powoduje (przerwana transakcja, niespełniona atrapa);
wzorzec ma wtedy wskazać klasę wyjątku i treść.

Kontrola bez wpisu przechodzi jako BEZ_WZORCA i jest wymieniona z nazwy w
podsumowaniu: to dowód niepełny (docs/PULAPKI_TESTOW.md §5b).

Ten moduł nie zawiera nazw żadnych testów projektu w cudzysłowie: strażnik
`StraznikTekstuMaKontroleDodatniaTest` czyta pliki `scripts/*.py` i uznałby
taką nazwę za pokrycie.
"""

from kontrola_przyczyny import Wyjatek


# #2167: brak wymaganego CSV ma oblać test własnym komunikatem, nie skipem.
OCZEKUJ_MIARY = r'Brak wymaganego pliku database/data/odzywcze/miary\.csv'

OCZEKUJ = {
    # Mutacja wysyła 181-znakowy tytuł prosto do bazy — objawem jest odmowa
    # kolumny varchar(180), a nie asercja testu.
    'Autozapis kreatora zapisuje bez walidacji pól': Wyjatek(r'value too long for type character varying\(180\).*insert into "recipes"'),
    'Sufit paczki importu wraca do 32 MB': r'Failed asserting that 33554432 is identical to 12582912',
    'Nowe konto Google gubi powrót do rozmowy': r'Expected response status code \[201, 301, 302, 303, 307, 308\] but received 200\.',
    'Nowe konto Facebook gubi powrót do rozmowy': r'Expected response status code \[201, 301, 302, 303, 307, 308\] but received 200\.',
    'APT install bez migawki': r'Instalacja pakietów APT bez przypięcia do migawki \(audyt, issue #',
    'Rollback aktywnych ukryć przestaje odmawiać': r'contains "1 aktywnych ukryć"',
    'Composer błędnie deklaruje MIT': r"@@ @@ -'proprietary' \+'MIT'",
    'LICENSE traci zastrzeżenie praw': r'contains "Wszelkie prawa zastrzeżone"',
    'Obraz błędnie deklaruje MIT': r'LABEL org.{1,2}opencontainers.{1,2}image.{1,2}licenses="proprietary"',
    'Format UUID': r'Expected response status code \[201, 301, 302, 303, 307, 308\] but received 500\.',
    'Kolejka zgłoszeń wraca do links()': r'Pod kolejką 26 zgłoszeń nie ma przycisku do strony 2\.|pages/admin/reports\.blade\.php: `links\(\)` renderuje widok Tailwinda',
    'Własność zeszytu': r'Expected response status code \[201, 301, 302, 303, 307, 308\] but received 404\.',
    'Komunikat po powrocie': r'Po powrocie na stronę główną błąd musi być widoczny\.',
    'Podpis co najmniej 18 px': r'Podpis kafla zszedł z minimum 18 px \(`--text-body`, issue #478\)\.',
    'Obwódka listy wyglądu poniżej 3:1': r'jasny: obwódka --color-border na tle panelu \(--color-surface-raised\)',
    'Piąty selektor powołuje się na D-262': r'Na D-262 powołuje się inny zbiór reguł CSS niż cztery selektory z',
    'Akcja GitHuba na ruchomym tagu': r'Zewnętrzna akcja bez pełnego SHA — tag jest ruchomy, więc ten sam',
    'Licznik w widocznym menu konta': r'Wejście do panelu nie pokazuje sumy wszystkich kolejek\.',
    'Strażnik tekstu bez własnego wpisu': r'Nowy test czyta źródła i asertuje na ich treści, więc może kiedyś',
    'Odstępstwo bez znacznika': r'Nowy test czyta źródła i asertuje na ich treści, więc może kiedyś',
    'Plik z node --test nieskopiowany do etapu assets': r'Etap `assets` w Dockerfile nie kopiuje',
    'Zdjęcie CHECK-a 2FA przed strażnikiem cofnięcia': r'Sprawdzenie stoi PO zdjęciu CHECK-a — schemat przestaje pilnować',
    'Pierwszy ekran opłacony mniejszym pismem': r'Blok pierwszego ekranu rusza `font-size` — a miał ruszać wyłącznie',
    'Strażnik sekretów osłabiony do samego https': r'Failed asserting that true is false\.',
    'Strażnik sekretów bez sprawdzenia ścieżki': r'Failed asserting that true is false\.',
    'Komunikat bazy z wartościami w logu serwera': r'does not contain "basia\.kowalska@example\.com"',
    'Błąd PCRE w filtrze logu po cichu zeruje treść': r'starts with "\[treść usunięta z logu: filtr danych osobowych nie dał rady\]"',
    'Filtr logu na loggerze zabiera webhookowi obiekt wyjątku': r'contains "Illuminate\\\\Database\\\\UniqueConstraintViolationException"',
    'Surowy komunikat wyjątku w logu kasowania zdjęcia': r'does not contain "basia@example\.com"|Surowe getMessage\(\) w logu \(użyj BezpiecznyBlad::kontekst\(\)\)',
    'Wzorzec e-maila z katastrofalnym nawracaniem': r'does not contain "\[treść usunięta z logu: filtr danych osobowych nie dał rady\]"',
    'Klucz tablicy z adresem przechodzi do logu': r'does not contain "basia\.kowalska@example\.com"',
    'Obiekt w kontekście logu idzie do formatera w całości': r'does not contain "basia\.kowalska@example\.com"',
    'Za głęboka tablica przechodzi surowa': 'contains "\\[pominięte: zagnieżdżenie głębsze niż filtr sprawdza\\]"|@@ @@ Array &0 \\[ - 0 => \'poziom\', \\+ 0 => \'e\', \\]',
    'Połknięty wyjątek bez miejsca przyczyny': r"@@ @@ Array &0 \[ - 0 => 'app/Support/PhpIniRozmiar\.php:\d+', \+ 0 => null, \]",
    'Komunikat wyjątku w kontekście budowanym przez metodę pomocniczą': r'Surowe getMessage\(\) w logu \(użyj BezpiecznyBlad::kontekst\(\)\)',
    'Komunikat transportu przez BezpiecznyKomunikat w logu listu o paczce': r'does not contain "insert into"|does not contain "\$2y\$"|does not contain "X-Amz-Signature"|Surowe getMessage\(\) w logu \(użyj BezpiecznyBlad::kontekst\(\)\)',
    'Komunikat bazy przez BezpiecznyKomunikat w logu śladu listu': r'does not contain "insert into"|does not contain "\$2y\$"|does not contain "X-Amz-Signature"|Surowe getMessage\(\) w logu \(użyj BezpiecznyBlad::kontekst\(\)\)',
    'Skaner logów ślepy na report()': r'Skaner nie złapał wszystkich form: Naruszenia\.php:6 Naruszenia\.php:7',
    'Kolor z niezdefiniowanej zmiennej': r'Te zmienne kolorów są użyte, ale nigdzie nie zdefiniowane',
    'Linki sąsiednich wpisów bez skali tekstu': r'contains "font-size: var\(--text-body\)"',
    'Cofnięcie CHECK-a kontaktu bez odmowy przy sierotach': r"contains 'Nie można cofnąć migracji'",
    'Cofnięcie znaczników odpowiedzi bez odmowy': r'exception of type "RuntimeException" is thrown',
    'Jedna sprawa RODO w toku bez odmowy przy duplikatach': r'contains "Liczba kont z więcej niż jedną otwartą sprawą RODO',
    'Lokalne akcje poza filtrem widoku': r'Bramka pomija job `\w+` przy zmianie `\.github/actions/[^`]+`, a ten job tej akcji UŻYWA',
    'Dockerfile poza wzorcem builda obrazu': r'Bramka pomija job `docker-build` na PR-ze przy zmianie `Dockerfile`',
    'Pliki grupy wyścigów poza wzorcem joba': r'Bramka pomija job `dwa-polaczenia` na PR-ze przy zmianie',
    'Ciężkie joby zawężane także poza PR-em': r'Job `przyrzad_605` jest pomijany poza PR-em \(zdarzenie: push\)\. Na',
    'Wzorzec przyrządu #605 łapie każdą zmianę': r'Bramka uruchamia `przyrzad_605` na PR-ze przy zmianie',
    'Filtr widoku zawężany także poza PR-em': r'zakres: filtr widoku zawęża poza PR-em \(zdarzenie: push\)\.',
    'Podział wierszy przez \\R bez u': r'Wzorzec z `\\R` bez modyfikatora `u` tnie „ą',
    'Test dymny przepuszcza każde przekierowanie': r'contains "sonda_https "',
    'Test dymny bez sondy wydania przed sprawdzeniami': r'Test dymny nie potwierdza, że pod adresem działa wdrażany commit \(#',
    'Końcowa sonda wydania z jedną próbą': r'Po sprawdzeniach wydanie trzeba potwierdzić jeszcze raz — mogło się',
    'Akcja rollback, która nic nie cofa': r"contains 'instrukcja-cofniecia'",
    'Preview gotowe po samym adresie': r'Krok czekania nie woła czekaj_na_preview dla SHA z PR-a\.',
    'Preview bez sondy wydania przed sprawdzeniami': r'Test dymny preview nie pyta /wydanie o commit PR-a \(#',
    'Job smoke znów dostaje prawo zapisu do PR-ów (#1941)': r'Job „smoke” uruchamia kod z PR-a i NIE MOŻE dostać `pull-requests',
    'Apply IaC bez przekazanej bramki CI': r'Job „apply” nie przekazuje KUKING_WAIT_FOR_CI — railway\.ts policzy',
    'Brak KUKING_WAIT_FOR_CI jako wyłączenie': r'railway\.ts ma odmówić przy braku albo złej wartości',
    '/wydanie z sesją i ciasteczkami': r'`/wydanie` stawia ciasteczka\.',
    'Obraz bazowy bez digestu': r'Obraz bazowy bez digestu — tag jest ruchomy, więc build nie jest',
    'Oryginał zdjęcia z nietkniętym XMP': r'W xmp-gps\.jpg został pakiet XMP\.|W xmp-gps\.png został pakiet XMP\.|W xmp-gps-skompresowany\.png został pakiet XMP\.|W xmp-gps\.webp został pakiet XMP\.|W xmp-gps\.avif został pakiet XMP\.|Failed asserting that|Oryginał leży w buckecie z pakietem XMP\.',
    'Sekret OAuth w workerze': r'Rola `worker` dostaje w `railway\.ts` sekrety spoza swojej macierzy|`worker` nie obsługuje logowania ani formularzy, a dostaje',
    'Worker bez klucza moderacji modelem': r'`config/\*\.php` czyta te zmienne bez wartości domyślnej, a|Rola `worker` nie dostaje w `railway\.ts`: OPENAI_MODERATION_KEY\. Kod|Worker wykonuje `PrzeanalizujTresc` i bez klucza moderacja modelem|Nie znalazłem w żadnej roli którejś ze zmiennych TYLKO_PRODUKCJA —',
    'Scheduler bez adresu alarmów moderacji': r'Rola `scheduler` nie dostaje w `railway\.ts`|Scheduler uruchamia podsumowanie automatu i pilnowanie terminów',
    'Web bez adresu alarmów moderacji': r'Rola `web` nie dostaje w `railway\.ts`: KUKING_MODEL_ALARM_EMAIL\. Kod|Web woła `AlarmujOPilnymZgloszeniu` synchronicznie w żądaniu',
    'Klucz modelu bez warunku produkcji': r'Rola `all` dostaje OPENAI_MODERATION_KEY bez warunku `isProduction \?',
    'Adres alarmu bez warunku produkcji': r'Rola `web` dostaje KUKING_MODEL_ALARM_EMAIL bez warunku `isProduction',
    'Scheduler bez kluczy poczty przy digeście': r'Scheduler uruchamia w swoim procesie komendy, które budują mailer',
    'Kopia bazy ze spreadem zestawu aplikacji': r'Serwis `kopia-bazy` ma zamkniętą listę zmiennych \(#193\)\. Żaden zestaw',
    'Polityka znowu obiecuje pełną kopię': r'Polityka znowu obiecuje „pełną kopię',
    'Polityka przestaje wymieniać obserwowane tagi': r'contains "jakie tagi obserwujesz"',
    'Polityka gubi prywatność listy „Co mam w domu”': r'contains "widzisz ją tylko Ty"',
    'Godzina w widoku z pominięciem Czas': r'Widok formatuje datę z pominięciem `App\\Support\\Czas` — pokaże czas',
    'Nieudane wgranie bez kompensacji plików': Wyjatek(r'Oryginał został w buckecie bez wiersza `media`|InvalidCountException: Method error\('),
    'Decyzja moderacyjna tworzona poza listą': r'Powstało nowe miejsce tworzące decyzję moderacyjną\. Sprawdź, czy',
    'Polityka bez nazwy ciasteczka motywu': r'Serwis stawia ciasteczko „motyw” \(ważne rok\), a polityka nie podaje',
    'Polityka wiąże dziennik z instancją': r'Polityka znowu wiąże dziennik serwera z życiem instancji\. Railway',
    'Polityka bez liczby dni dziennika': r'Wiersz o błędach technicznych nie podaje, ile dni Railway trzyma',
    'Manifest Vite z rocznym cache assetów': r'contains "@viteAssets path /build/assets/\*"',
    'Trasa ze zdjęciem pod progiem 2 MB w Caddy': r"Trasa `settings\.avatar\.update` \(/ustawienia/zdjecie\) przyjmuje|@@ @@ 1 => '/pytania', 2 => '/przepisy/\*', 3 => '/wpisy/\*', - 4 => '/livewir",
    'Rejestr wyjątków z nieistniejącą klasą': r'REJESTR\[App\\Models\\Comment\]\[author_id\] wskazuje klasę AddComment',
    'Rejestr wyjątków z nieistniejącą stałą': r'REJESTR\[App\\Models\\Report\]\[status\] wskazuje stałą STATUS_NEW, której',
    'Zlecenie zdjęcia poza transakcją wiersza': Wyjatek(r'QueryException: SQLSTATE\[25P02\]: In failed sql transaction|Expected response status code \[201, 301, 302, 303, 307, 308\] but received 500'),
    'Referrer-Policy w Caddy nadpisuje decyzję aplikacji': r'Nagłówek Referrer-Policy ma w aplikacji różne wartości zależnie od',
    'Strażnik R2 bez segmentu eu': r'Przepuszczony: https://',
    'Strażnik R2 bez kotwicy końca': r'Przepuszczony: https://',
    'Ostrzeżenie o zmianie adresu czyta adres przy wysyłce': r'Ostrzeżenie musi trafić do starej skrzynki także po potwierdzeniu',
    'Awans roli bez odwołania sesji': r'Sesja sprzed awansu na moderator weszła do panelu\.|Sesja sprzed awansu przetrwała\.',
    'Wybór kolażu bez kaskady przy odpięciu zdjęcia': r"@@ @@ -'FOREIGN KEY \(post_id, media_id\) REFERENCES post_media\(post_id, media",
    'Landing: podgląd prowadzi do trasy auth': r'Odnośnik „Zobacz, kto może widzieć wpis” \(.*\) nie pokazuje gościowi treści — status 302',
    'Autozapis kreatora #892 bez kroku CI': r'Autozapis #892 nie chodzi w CI: autosave Failed asserting that 0 is',
    'Stały token wydania Livewire': r"@@ @@ -'[0-9a-f]{7,40}' \+'a'|@@ @@ -'lokalnie' \+'a'|livewire\.release_token nie czyta RAILWAY_GIT_COMMIT_SHA\.|Migawka ze starego wydania przeszła weryfikację tokenu\.",
    'Kreator obiecuje szkic przed zapisem': r'contains "data-kreator-zapis="',
    'Polityka obiecuje UE przy strażniku bez eu': r'Wiersz Cloudflare R2 obiecuje „Unia Europejska”, ale strażnik',
    'Zapis weryfikacji R2 z inną datą niż polityka': r'Ostatni zapis weryfikacji jurysdykcji R2 jest z',
    'Kontroler znów zleca analizę awatara': r'AvatarSettingsController znów odwołuje się do PrzeanalizujAwatar\.|Dokumenty rozjechały się z kodem awatarów \(D-240, #',
    'DATABASE.md znów mówi, że model ocenia awatar': r'Dokumenty rozjechały się z kodem awatarów \(D-240, #',
    'Wyjęcie przepisu ze wszystkich zeszytów bez transakcji': r'Po awarii w środku wyjęcia część zapisów zniknęła — wyjęcie nie jest',
    'Wyjęcie wpisu ze wszystkich zeszytów bez transakcji': r'Po awarii w środku wyjęcia część zapisów zniknęła — wyjęcie nie jest',
    'Odwołanie autora bez wspólnej transakcji z zawiadomieniami': r'Pismo zostało złożone mimo niepełnego zawiadomienia zespołu \(#',
    'Odwołanie zgłaszającego bez wspólnej transakcji z zawiadomieniami': r'Pismo zostało złożone mimo niepełnego zawiadomienia zespołu \(#',
    'Reguła zdjęć Cloudflare bez warunku ciasteczka': r'Reguła 0 bez warunku and http\.cookie eq',
    'Timeout blokady funkcji nie oddaje miejsca wspólnej puli': r'Timeout blokady funkcji zostawił zajęte miejsce we wspólnej puli —',
    'Domena importuje Illuminate\\Http\\Request': r'Plik w app/Domain importuje Illuminate.Http\. Wejście HTTP idzie do adaptera|Domena nie zna Request \(#970\)',
    'Zapis przepisu do cudzego zeszytu': r'Akcja zapisała przepis do cudzego zeszytu\.',
    'Zapis wpisu do cudzego zeszytu': r'Akcja zapisała wpis do cudzego zeszytu\.',
    'Podział testów gubi plik': r"Plik tests/\S+ nie trafił do żadnej części — nie uruchamia się NIGDZIE\.",
    'Macierz testów krótsza niż podział': r"@@ @@ -Array &0 \[\] \+Array &0 \[ \+ 0 => 'Skrypt dzieli na 4 części, a macierz ",
    'Runbook znów instaluje Sentry': r'Runbook poza blokiem `<details>` zawiera instalację pakietu Sentry w',
    'Runbook znów wymaga klucza PostHog': r'Runbook zawiera klucz PostHog w tabeli zmiennych lub sekretów \(`\|',
    'Jeden worker ze ścisłym priorytetem kolejek': r'Zadanie z kolejki `media` nie ruszyło w 3 obrotach przy stałej',
    'Rola all z procesem na kolejkę (OOM w 1024 MB)': r"@@ @@ Array &0 \[ 0 => Array &1 \[ 0 => 'high', - 1 => 'default', \], 1 => Arra",
    'Tryb ścisły Eloquent niewłączony': r'Leniwe ładowanie relacji nie jest zablokowane poza produkcją\.|Staging nie wykrywa leniwego ładowania|Failed asserting that exception of type "Illuminate.Database.(Eloquent.)?(LazyLoadingViolationException|MissingAttributeException|MassAssignmentException)" is thrown',
    'Awaria eksportu bez przekazania wyjątku kolejce': r'Job powinien przekazać wyjątek kolejce, żeby zapisała porażkę i mogła',
    'Klucz eksportu z rodzajem': r"is identical to 'zupy i kiszonki'|contains 'na czym sie znam'",
    'Eksport gubi wybór widoczności wartości odżywczych': r"an array has the key 'pokazuj_wartosci_odzywcze'",
    'Rodzaj po „jesteś” na ekranie Google': r'Tekst przypisuje czytelnikowi płeć',
    'Entrypoint bez klucza preview': r'Entrypoint nie woła kuking_klucz_preview\.',
    'Dalsze okno wyszukiwania bez kursora rankingu': r'Dalsze okno powtórzyło już pokazany przepis\.|Dalsze okno pominęło przepis, który nie był jeszcze pokazany\.|Dalsze okno powtórzyło osobę\.',
    'Nieudany dzwonek kupuje ciszę epizodu': r'Failed asserting that false is true\.|Failed asserting that \d+ is identical to 0\.|Zamknięty epizod nie daje ciszy nawrotowi\.|Nieudana próba WYCISZYŁA czujkę|Analog dla kolejki ma tę samą usterkę|actual size 1 matches expected size 2|Odwołanie przepadło razem z pamięcią alarmu',
    'Podzbiór fontu bez „ą"': r'Poza podzbiorem Inter: ą \(U\+|unicode-range w fonts\.css różni się od kontraktu dla inter-',
    'Viewport bez viewport-fit=cover': r'Wspólny meta viewport musi zawierać viewport-fit=cover \(D-260\)\.',
    'Dolna belka bez lewego insetu': r'contains "left: calc\(8px \+ var\(--safe-left\)\);"',
    'Podpowiedź wyglądu bez dolnego insetu': r'Podpowiedź szybkiego wyglądu musi omijać wskaźnik Home\.',
    'Edycja domyślnego zeszytu bez skutku dla przyszłych zapisów': r'contains "i wszystko, co zapiszesz tu później"',
    'Wydruk przepisu z pismem poniżej 12 pt': r'Reguła druku ustawia pismo poniżej 12 pt\.',
    'Offline: „Spróbuj ponownie” znów prowadzi na /home (#749)': r"@@ @@ -'' \+'/home'",
    'Kontrakt karty bez zdjęcia przepisu': r'Karta dociąga relacje leniwie na powierzchni „obserwowani”\.|Karta dociąga relacje leniwie na powierzchni „tagi”\.|Karta dociąga relacje leniwie na powierzchni „odkrywanie”\.|Karta dociąga relacje leniwie na powierzchni „tablica”\.|Karta dociąga relacje leniwie na powierzchni „profil”\.|Karta dociąga relacje leniwie na powierzchni „tag”\.|Karta dociąga relacje leniwie na powierzchni „zeszyt”\.|Expected response status code \[200\] but received 500\.',
    'Kontrakt karty bez tematów': r'Brak tematów na „obserwowani”\.|Karta dociąga relacje leniwie na powierzchni „tagi”\.|Brak tematów na „odkrywanie”\.|Brak tematów na „profil”\.|Brak tematów na „tag”\.|Brak tematów na „zeszyt”\.',
    'Zamknięcie grupy sygnałów bez porównania liczby': r'Session is missing expected key \[errors\]\.',
    'Zamknięcie grupy sygnałów bez porównania kolejności': r'Session is missing expected key \[errors\]\.',
    'Migracja pierwszych kroków bez backfillu': r'Konto sprzed migracji dostałoby nagle przypomnienie „Dokończ pierwsze|Failed asserting that 2 is identical to 0\.',
    'Koniec pierwszych kroków zapisywany w GET': r'Carbon Object.* is null\.',
    'Turnstile bez porównania hosta': r'WynikTurnstile Enum #\d+ \(Odrzucony\) \+App',
    'Turnstile bez porównania akcji': r'WynikTurnstile Enum #\d+ \(Odrzucony\) \+App',
    'Kolaż hero z lazy na pierwszym kaflu': r'contains "fetchpriority="|kafli: 1 Failed asserting that 0 is identical to 1\.',
    'Polityka z innym terminem usunięcia treści niż konfiguracja': r'contains "najpóźniej \*\*\d+ dni\*\* po usunięciu"',
    'Users znowu importuje Social': r'Graf zależności modułów app/Domain zmienił swoje cykle\. Nowy cykl|app/Domain/Users importuje App\\Domain\\Social',
    'DemoSeeder wypisuje hasło z KUKING_DEMO_HASLO': r'DemoSeeder wypisał hasło z KUKING_DEMO_HASLO\. W CI to wyjście jest',
    'Polityka z okresem sesji innym niż życie sesji na produkcji': r'contains "Do \*\*\d+ dni\*\* od ostatniej aktywności"',
    'Sprzątanie audytu zostawia skrót IP we wpisach dowodowych': r'Failed asserting that 0 is identical to 1\.',
    'Kontroler Google z własną kopią wejścia na konto': r'GoogleLoginController ma własną kopię: wejście na konto \(wpusc /',
    'Instalacja Railway CLI bez sprawdzenia sumy kontrolnej': r'\.github/workflows/deploy\.yml: krok „Instalacja Railway CLI” ma',
    'Bramka tokenu krawędzi przepuszcza żądanie bez tokenu': r'Expected response status code \[403\] but received 200\.|Expected response status code \[403\] but received 405\.',
    'Caddy czyta X-Forwarded-For od lewej': r'Dla X-Forwarded-For „198\.51\.100\.66, 203\.0\.113\.7” Caddy zapisze w logu|Dla X-Forwarded-For „192\.0\.2\.1, 198\.51\.100\.66, 203\.0\.113\.7” Caddy|Dla X-Forwarded-For „10\.0\.0\.1, 203\.0\.113\.7” Caddy zapisze w logu',
    'Caddy ufa każdemu peerowi': r'Dla X-Forwarded-For „198\.51\.100\.66, 203\.0\.113\.7” Caddy zapisze w logu|Dla X-Forwarded-For „192\.0\.2\.1, 198\.51\.100\.66, 203\.0\.113\.7” Caddy|Dla X-Forwarded-For „10\.0\.0\.1, 203\.0\.113\.7” Caddy zapisze w logu|Caddy ufa nagłówkowi od peera z adresu publicznego — log opisze adres',
    'Entrypoint czyści cache aplikacji': r'docker/entrypoint\.sh czyści cache aplikacji — to zeruje RateLimiter|cache:clear wyzerował RateLimiter\.',
    'Alarm automatu bez znacznika rezerwacji': r'List z rezerwacją policzony drugi raz\.|Znacznik ListZarezerwowany ma klasa spoza rejestru \(list wypadnie z',
    'Życzenia urodzinowe bez znacznika rezerwacji': r'Życzenia urodzinowe policzone drugi raz: komenda już zajęła miejsce\.|Znacznik ListZarezerwowany ma klasa spoza rejestru \(list wypadnie z',
    'Trasa API bez wiersza w dokumentacji': r"Trasy API bez wiersza w tabeli „Lista endpointów|contains 'GET /api/v1/feed'",
    'Powiadomienie o wykonaniu kucharza w karencji usunięcia': r'Filtr powiadomień \(`WidocznoscTresciSql`\) i Policy odpowiadają',
    'Powiadomienie o komentarzu pod zapowiedzią ukrytego przepisu': r'Filtr powiadomień \(`WidocznoscTresciSql`\) i Policy odpowiadają',
    'Tygodniowy list układa wpisy po liczbie „Ugotowałem”': r'Sortowania niezgodne z §12: - app/Domain/Digest/ZbierzTresciDigestu\.php:\d+ — `orderByDesc\(.cooked_events_count.\)` układa publiczne treści po MIERZE',
    'Mój stół układa przepisy po liczbie „Ugotowałem”': r'Sortowania niezgodne z §12: - app/Domain/Feed/MojStol\.php:\d+ —',
    'Mój stół układa „kuKINGi na dziś” po liczbie „Ugotowałem”': r'Sortowania niezgodne z §12: - app/Domain/Feed/MojStol\.php:\d+ —',
    'Moderacja czyta prywatne ukrycia widzów': r'Ukrycia widza czytane w',
    'Metryki doboru czytają reakcje „Smakowicie wygląda”': r'ukrycia i reakcje nie są źródłem analityki \(D-278, D-280\)',
    'Strona doboru opisuje rotację, której kod nie robi': r'odkrywanie\.rotacja: w app/Domain/Feed/DiscoverFeed\.php nie ma już',
    'Wersja regulaminu podbita bez nagłówka dokumentu': r'Nagłówek regulaminu nie mówi „\d+ \S+',
    'Zmiana istotna bez okresu przejściowego': '@@ @@ -\'\\d{4}-\\d\\d-\\d\\d\' \\+\'\\d{4}-\\d\\d-\\d\\d\'|contains \\"Nowa wersja obowiązuje od \\d+ \\S+ \\d{4}\\. Do tego dnia|@@ @@ Array &0 \\[ - 0 => \'\\d{4}-\\d\\d-\\d\\d\', \\+ 0 => \'\\d{4}-\\d\\d-\\d\\d\'',
    'Zmiana istotna wchodzi w dniu publikacji zamiast po 14 dniach': r'Failed asserting that false is true\.|contains "Nowa wersja obowiązuje od \d+ \S+ \d{4}\.|\d{4}-\d\d-\d\d.*\d{4}-\d\d-\d\d|exception of type "RuntimeException" is thrown',
    'Zgoda zapisuje wersję opublikowaną zamiast obowiązującej': r"@@ @@ Array &0 \[ - 0 => '\d{4}-\d\d-\d\d', \+ 0 => '\d{4}-\d\d-\d\d', 1 => '\d{4}-\d\d-\d\d'",
    'Nagłówek polityki z inną datą niż dziennik zgód': r'Nagłówek polityki prywatności i `kuking\.zgody\.wersja_polityki` mówią',
    'IaC: plan produkcji bez filtra gałęzi docelowej': r'`pull_request` musi mieć `branches: \[main\]` — filtr gałęzi DOCELOWEJ',
    'IaC: plan produkcji bez base.ref == main': r'Job `plan` musi wymagać PR-a do main \(#',
    'Mediana wpisów bez warunku rodzaju': r'Failed asserting that 2\.5 is identical to 4\.0\.',
    'Mediana pytań liczy dopiski jako odpowiedź': r'Failed asserting that 1\.5 is identical to 1\.0\.',
    'Indeks pytań z predykatem na daniach': r'Licznik gościa nie trafia w indeks pytań\.',
    'Licznik widza bez poprawki na blokady': r"@@ @@ -'Czeka na odpowiedź \(1\)' \+'Czeka na odpowiedź \(2\)'",
    'Dopisek autora liczony jako odpowiedź': r'Failed asserting that 1 is identical to 0\.',
    'Odpowiedź nie odświeża licznika pytań': r'Failed asserting that 1 is identical to 0\.',
    'Odczyt kartki publikuje przepis': r'publikuje przepis\.',
    'Wspólny limit ignoruje nowe próby importu': r"is identical to 'dzien'|is identical to 'miesiac'",
    'Odczyt: rezerwacja bez śladu po awarii zapisu': r'Rezerwacja bez śladu w zleceniu została w budżecie — kolejne importy',
    'Odczyt: porzucona rezerwacja nie wygasa': r"@@ @@ Array &0 \[ - 'zarezerwowano' => 0, \+ 'zarezerwowano' => \d+",
    'Odczyt: rozliczenie bez strażnika stanu': r'Drugie rozliczenie tej samej próby musi być niczym\.',
    'Odczyt: zadanie za commitem zlecenia': r'Zlecenie `oczekuje` zostało zatwierdzone bez zadania w kolejce — nikt',
    'Odczyt: ponowienie woła model mimo zapisanej odpowiedzi': r'Failed asserting that actual size 2 matches expected size 1\.',
    'Wartości odżywcze liczone poniżej 90% pokrycia': r'Failed asserting that true is false\.|„Chleb pszenno-żytni na zakwasie”: Failed asserting that two strings',
    'Źródła wartości odżywczych bez identyfikatora wersji CIQUAL': r'Wersja CIQUAL ma być wskazana identyfikatorem, nie tylko nazwą\.',
    'Job plan IaC bez bramki produkcji': r'Job `plan` w \.github/workflows/railway-iac\.yml wykonuje',
    'unserialize ładunku kolejki bez allowed_classes': r'unserialize\( bez `allowed_classes` \(albo z `true`\) odtwarza KAŻDĄ',
    'Ślad listu odtwarza obcą klasę z failed_jobs': r'Klasa powiadomienia spoza `PolecenieZadania::WOLNO_ODTWORZYC`|Obca klasa z `failed_jobs` została odtworzona — `unserialize\(\)` bez|Failed asserting that an instance of class',
    'Planer bez filtra widoczności przepisu': 'does not contain "Sekretny bigos"|@@ @@ -\'Skopiowane z poprzedniego tygodnia: 2 pozycje\\. Pominięte: 1 pozycja |@@ @@ Array &0 \\[ - 0 => null, - 1 => true, \\+ 0 => \'Schowany sernik\', \\+ 1 => ',
    'Planer bez dziennego limitu pozycji': r'Session is missing expected key \[errors\]\.',
    'Wymazanie konta nie kasuje planu tygodnia': r"@@ @@ Array &0 \[ - 0 => 'Plan zostaje', \+ 0 => 'Plan do skasowania', \+ 1 => ",
    'Limit push nie liczy rezerwacji w transporcie': r'Drugi worker przekroczył limit 1\.',
    'Recover alarmu pomija potwierdzona odmowe': r'Failed asserting that true is false\.',
    'Preview wkleja ręczny pr_number w run:': r'Dane od użytkownika wklejone w treść `run:` — GitHub podstawia je',
    'IaC wkleja inputs.* w podsumowanie': r'Dane od użytkownika wklejone w treść `run:` — GitHub podstawia je',
    'DEPLOYMENT.md nazywa scheduler „cron”': r'Blok topologii w docs/DEPLOYMENT\.md znowu nazywa czwarty proces',
    'Obraz runtime bez poppler-utils': r'Etap `runtime` nie instaluje `poppler-utils` — import PDF na',
    'Cofnięcie importu bez odmowy przy niesprawdzonym szkicu': r'contains "Liczba: 1\."',
    'Szablon .env bez tokenu czyszczenia CDN': r'\.env\.example ma 0 wystąpień `CLOUDFLARE_PURGE_TOKEN` — oczekiwane',
    'README: „Dopóki ich nie ma” wraca': r'README mówi, że GitHub Actions nie działają, a ci\.yml mówi, że CI',
    'CHANGELOG z tym samym wpisem dwa razy': r'CHANGELOG\.md ma zdublowane wpisy — najczęściej skutek rozwiązania',
    'Nowa funkcja bez akapitu na stronie Co nowego': r'CHANGELOG\.md ma w sekcji „## Nieopublikowane” \d+ wpis\(ów\) oznaczonych',
    'Dziennik wdrożeń: down() bez warunku odmowy': r'Cofnięcie przeszło, mimo że w dzienniku jest wiersz\.',
    'Co nowego bez dopisku „od numeru”': r'contains "od Alfa 0\.\d+\.\d+"|Strona „Co nowego” nie pokazuje „od Alfa 0\.\d+\.\d+” przy nagłówku',
    'Co nowego: brak nagłówka w fixture integracyjnej': r'contains "od Alfa 0\.\d+\.\d+"',
    'Cofnięcie no_amount bez odmowy przy oznaczonych składnikach': r'Cofnięcie przeszło i skasowało kolumnę `no_amount`\.',
    'Wycofanie Web Push bez odmowy przy wybranej ciszy nocnej': r'contains "D-088"',
    'Martwe odwołanie D-NNN w AGENTS.md': r'Odwołania do decyzji, których nie ma w dzienniku, albo identyfikatory',
    'Roboczy identyfikator w AGENTS.md': r'Odwołania do decyzji, których nie ma w dzienniku, albo identyfikatory',
    'Roboczy nagłówek w dzienniku decyzji': r'Nagłówki dziennika, które nie są ostatecznym, trzycyfrowym numerem|Odwołania do decyzji, których nie ma w dzienniku, albo identyfikatory',
    'Strażnik dziennika ślepy na dopisek ROBOCZA': r'Failed asserting that actual size 0 matches expected size 1\.|contains "D.1000.ROBOCZA"',
    'Strażnik dziennika ślepy na identyfikator czterocyfrowy': r'Failed asserting that actual size 0 matches expected size 1\.',
    'Runbook: railway config apply bez KUKING_WAIT_FOR_CI': r'`railway\.ts` odmawia bez KUKING_WAIT_FOR_CI \(#',
    'Workflow cen: checkout z kodem bez persist-credentials: false': r'Job „pobierz” uruchamia kod repozytorium i nie ma dostępu do',
    'Deploy: dane zdarzenia wklejone do Basha': r'Blok `run:` w deploy\.yml zawiera wyrażenie GitHuba — podstawiane do|Zła nazwa środowiska dla',
    'Strażnik migracji ślepy na FK dodany przez Blueprint': r'Strażnik nie zauważył FK dodanego przez Blueprint na istniejącej',
    'Strażnik migracji ślepy na indeks bez CONCURRENTLY': r'Strażnik nie zauważył indeksu bez CONCURRENTLY na istniejącej tabeli\.',
    'Strażnik migracji ślepy na CHECK/FK bez NOT VALID': r'Strażnik nie zauważył CHECK bez NOT VALID na istniejącej tabeli\.',
    'Job lint bez wspólnych kontroli powłoki': r'contains "run: bash scripts/kontrole-powloki\.sh"|ci\.yml \(job lint\) uruchamia test z tests/skrypty/ bezpośrednio\.',
    'Test powłoki wypada z listy wspólnego skryptu': r'tests/skrypty/bramka-migracji\.sh wypadł z listy\.|Test powłoki poza listą w scripts/kontrole-powloki\.sh — dopisz go do',
    # #1751 (D-332): forma zwracania się i nowa wersja polityki. Wzorce dopisane
    # w recenzji paczki H z komunikatów porażek po mutacji (lokalnie, PG 55439).
    'Cofnięcie formy zwracania się bez odmowy': r'Rollback przeszedł mimo zapisanego wyboru formy',
    'Anonimizacja zostawia formę zwracania się': r"Failed asserting that 'feminine' is null\.",
    'Helper formy ignoruje formę żeńską': (
        r"-'ugotowałaś' \+'gotujesz'"
        r'|contains "Możesz od razu pokazać, co dziś ugotowałaś"'
        r'|contains "Zacznij od zdjęcia tego, co dziś ugotowałaś\."'
        r'|matches PCRE pattern "~data-minutniki-koniec>Ugotowałam</a>~u"'
        r'|contains "Halina ugotowała Twój przepis'
        r'|contains "Kuking\.pl - pokaż, co dziś ugotowałaś\."'
    ),
    'Wariant neutralny helpera z rodzajem': r'wariant neutralny z rodzajem|contains "Możesz od razu pokazać, co dziś gotujesz"',
    'Goły rodzaj obok wywołania helpera': r'Tekst przypisuje czytelnikowi płeć:.*ugotowałaś',
    'Rollback paska polityki bez odmowy': r'Rollback przeszedł, choć ktoś zamknął pasek',
    # Decyzja właściciela z 29.09.2026 (wieczór): zmiana drobna od razu.
    'Polityka z terminem wejścia niezgodnym z konfiguracją': r'contains "obowiązuje od dnia publikacji"',
    'Pasek polityki przy drobnej zmianie': r'does not contain "data-pasek-zmiany-polityki"',
    'Wybór formy widoczny w okresie przejściowym polityki': r'does not contain "Jak mamy do Ciebie pisać\?"',
    'Wybór formy czeka na dzień wersji przy drobnej zmianie': r'Wybór formy ukryty w chwili 2026-09-29 20:00',
    # #2000: wspólny zeszyt „wszyscy” ma „Podziel się”.
    'Wspólny publiczny zeszyt bez „Podziel się”': r'Wspólny zeszyt „wszyscy” nie ma przycisku „Podziel się”|Wspólny zeszyt nie może dokładać zapytań o członków',
}


def kontrole_mechanizmu(plik, test, mutacja_z_asercja, replace_once):
    """Kontrole dodatnie SAMEGO MECHANIZMU: czerwień z niewłaściwego powodu ma nie zaliczać.

    Dwie mutacje na tym samym strażniku; przebieg wymaga werdyktu ZLA_PRZYCZYNA
    (gdyby dostały POTWIERDZONA, cały krok pada — mechanizm sam jest zepsuty):

      1. Fatal zamiast asercji: średnik zdjęty z zamknięcia instrukcji, PHP nie
         załaduje klasy (ParseError). Wzorzec `.` pasuje do wszystkiego, więc
         odrzucić go może tylko rozpoznanie wyjątku albo brak raportu — dokładnie
         to, co mechanizm przestałby umieć, gdyby wrócił do liczenia samego
         słowa `FAILED`.
      2. Właściwa asercja, obcy komunikat: ta sama mutacja co w kontroli
         strażnika, ale ze wzorcem, którego test nigdy nie wypisze. Dowód, że
         wzorzec jest naprawdę porównywany z prawdziwym wyjściem PHPUnita.
    """
    return [
        ("Mechanizm: fatal zamiast asercji", plik, test,
         lambda s: replace_once(s, "return 'ścieżka spoza API dostawcy';", "return 'ścieżka spoza API dostawcy'"),
         "."),
        ("Mechanizm: asercja z obcym komunikatem", plik, test,
         mutacja_z_asercja,
         "Komunikat, którego ten test nigdy nie wypisze"),
    ]
