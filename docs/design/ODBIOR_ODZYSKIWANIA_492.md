# Lokalny odbiór odzyskiwania pełnego formularza — 419/429

Oba docelowe ponowienia zakończone. Ograniczenia transportu i dodatkowe próby opisano jawnie poniżej.

## Izolacja i źródło

Osobna kopia `/tmp/kuking-recovery492-1789417626`, baza `kuking_recovery492_1789417626` na `127.0.0.1:55439`, serwer `8534`. Baza utworzona po sprawdzeniu nieistnienia. Osobne storage, pliki sesji i cache, APP_ENV local i mailer array. Nie korzystano z danych ani mediów serwera 8033. Dwa własne konta. Bez zestawu PHP i zmian aplikacji.

538 plików app/resources/routes/config zgodnych bajtowo z `443da38c763f5ee2c26e8610b516c95fa4e42b94`; `source-bytes.json` zawiera hashe i zero różnic. Dwa pominięte podczas kopiowania pliki resources/views/vendor/mail uzupełniono przed końcowym odbiorem. Roboczych dokumentów nie przypisujemy temu SHA. Zależności używane tylko do odczytu.

## Jawne próby wstępne

Helper fixture początkowo odczytał cached null relacji profile po factory. Wyjątek konsolowy zwrócił exit 0, lecz wynik nie był JSON. Błąd przyrządu wykryto, odczyt poprawiono przez refresh, stan dwóch użytkowników sprawdzono. Nie zaliczono samego exit 0 jako sukcesu.

Obecny Laravel PreventRequestForgery akceptuje Sec-Fetch-Site=same-origin. Rzeczywisty logout/login oraz stary token nie wystarczyły do 419. Pierwszy POST i dwie kolejne próby modyfikacji metadanych przez route.continue zapisały łącznie trzy prywatne przepisy z dziewięcioma krokami. Nie usunięto ich i nie zaliczono tych prób jako odbioru 419. Service Worker początkowo zacierał przekierowanie w obserwacji klienta; dalszy odbiór używa serviceWorkers=block.

Przy 429 dwie pierwsze autentyczne odmowy zatrzymały zbyt surowy comparator: recovery pomija puste pola, a middleware TrimStrings usuwa skrajne spacje. Dalsze porównanie uwzględnia tę istniejącą normalizację i zapisuje hashe obu reprezentacji. Wszystkie trzy odmowy 429 nie zapisały nowego przepisu. Przygotowano wyłącznie własny bucket użytkownika do 20 trafień z TTL 600 sekund; kolejne odczyty nie skracały ani nie resetowały TTL. To kontrolowany stan limitera, nie seria dwudziestu żądań HTTP.

## 419: bramka serwera i kontrolowany transport

Prawdziwy formularz `/dodaj/przepis/jedna-strona` wypełniono istniejącymi kontrolkami: prywatność, tytuł, opis z polskimi znakami, cudzysłowami i &, składnik, trzy różne instrukcje i czasy 1/2/3 min. Bez dodawania inputów do DOM i bez nowych plików.

Po rzeczywistym wylogowaniu/zalogowaniu w drugiej karcie porównanie w pamięci potwierdziło różne tokeny. Po uzgodnieniu wykorzystano route.fetch bez Sec-Fetch-Site i z maxRedirects=0. Serwer rzeczywiście zwrócił 419; dopiero po asercji przekazano odpowiedź bez zmian przez route.fulfill({response}). Nie tworzono własnego HTML ani statusu. To test fallbacku tokenowego starszego klienta, nie naturalne wygaśnięcie sesji we współczesnym Chromium.

Odzyskane niepuste pola są zgodne po TrimStrings. Zwykły przycisk „Wyślij jeszcze raz” wysłał natywny POST bez pośrednika route.fetch: 302, jeden prywatny przepis i trzy kroki. Przy odrzuconym żądaniu były 3 przepisy / 9 kroków; po ponowieniu 4 / 12. Nie publikujemy tokenów, ciasteczek ani danych logowania.

## 429

Po Retry-After=575 s odczekano rzeczywiście 576281 ms do ponowienia. Zwykły przycisk wysłał natywny POST, odpowiedź 302. Nie czyszczono bucketu ani nie skracano TTL. Jeden nowy prywatny przepis i trzy kroki. Końcowy stan całej odrębnej bazy: 2 użytkowników, 5 przepisów, 15 kroków, w tym trzy przepisy z wcześniejszych udanych prób. Wszystkie pozostawiono.

## Ogląd i granice

Oba błędy sprawdzono przy CSS 320, tekście 140% i rzeczywistym zoomie 2 w obu motywach. Przyrząd sprawdził innerWidth=320 oraz scrollWidth=320. Obejrzano cztery PNG początku błędu; porównanie odzyskanych danych jest osobnym dowodem od tych rastrów. Duży tekst i nawigacja wymagają przewijania. Nie zaliczamy pełnego oglądu wszystkich pól, całego Tab, axe, kontrastu, fizycznego telefonu ani utraty logowania.

Store dotyczy pełnego formularza z trzema widocznymi krokami, nie sondy 51×4000 ani maksymalnych danych. Nie badano update/PUT, UUID istniejących kroków ani odzyskiwania mediów. Starsze dowody #524 zachowują odrębny zakres. Nie potwierdzono nowego błędu odzyskiwania ani zapisu. Odrębny ogląd tekstu przez zadanie główne potwierdził nieprawdziwe przypisywanie każdego419 upływowi czasu: [#549](https://github.com/woogitsu/kuking.pl/issues/549). W tej próbie zmienił się token po ponownym logowaniu, nie upłynął czas ważności formularza.

## Ponowny odczyt i zakończenie

Po obu docelowych zapisach rzeczywiście otwarto edycję jako właściwy autor. Każdy niepusty element pierwotnego formularza poza kluczem wysłania porównano po TrimStrings: brak różnic, private zachowane, po trzy instrukcje. Kontrola obejmuje też składnik, czasy i opis. Helper odczytu początkowo próbował zmienić konto w tym samym jarze cookies; zatrzymał się na loginie. Po rozdzieleniu cookies wykonał oba odczyty; nie było dodatkowego POST przepisu.

Własne przeglądarki zamknięto. Własny serwer 8534 zatrzymano po sprawdzeniu PID, grupy procesów i katalogu pracy; serwera 8033 nie dotykano. Baza i własne dane pozostają do weryfikacji. Surowe JSON wartości oraz helpery z lokalnymi danymi kont przeniesiono poza repo do prywatnego katalogu `/tmp/kuking-recovery492-evidence-1789417626`; zmienne środowiska są osobno poza repo. Nie kopiować tych materiałów do PR.

Publiczny zestaw do integracji: `public-summary.json`, `public-field-hashes.json`, `public-readback.json`, `public-cleanup.json`, `source-bytes.json`, `results.json` oraz cztery PNG `419-{light,dark}.png`, `429-{light,dark}.png` i ten raport. JSON nie zawierają wartości CSRF, cookies, haseł, adresów kont ani surowych treści pól. `results.json` zawiera zapisane metryki dwóch motywów 429; dla 419 te same asercje wykonano w przyrządzie i zachowano PNG, ale osobnego JSON geometrii nie zapisano. To nie pełna macierz dostępności.
## Dowody w repo

[Zestaw JSON bez sekretów](evidence/odzyskiwanie492/). PNG zachowano lokalnie w output/recovery492; zadanie główne obejrzało dodatkowo419-dark i429-light. Nie kopiowano surowych formularzy, sesji ani helperów z danymi kont.
