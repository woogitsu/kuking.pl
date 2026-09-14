# Kontynuacja autonomiczna KuKing

## Najnowsze zgłoszenie: szerokość menu Konto — #555

15.09.2026: właściciel pokazał ciasne menu moderatora. Przygotowana Alfa0.31
poszerza listę i zachowuje jej dostępność po zawinięciu belki. Raport i jawne
granice: [MENU_KONTA_555.md](MENU_KONTA_555.md). Wykonano48 konfiguracji
oraz24 niskiego okna, fizyczny negatyw CSS, przywrócenie MD5/mtime i ponowny
wynik dodatni. Rola lokalnego konta testowego została przywrócona.
Odczytaj PR powiązany z #555, jego hook/CI i wdrożenie przed kolejną pracą.

Poprzedni #551 jest scalony w PR #553 jako
`05f57175d50ab20fc1cfaa935c6da3e9b23f21ec`; wszystkie10 zadań CI PR przeszło.
Ostatni odczyt nadal wskazywał CI main34902008768 queued i Railway6447341870
in_progress (runnery zajęte). HTTP pozostawał na Alfa0.29/46b322f. Końcowy
odbiór zdjęć profilu i menu konta można wykonać na późniejszym wdrożeniu,
które zawiera obie zmiany. Nie uznawać samego scalenia za odbiór produkcji.

## Sposób pracy

Użytkownik zatwierdził plan 14.09.2026. Automatyzacja `kuking-kontynuacja-prac` jest aktywna co godzinę w tej samej rozmowie. Przed wznowieniem sprawdzić procesy, agentów, repo, PR, CI i Railway; nie dublować pracy. Polecenie zatrzymania ma pierwszeństwo. Jeden pakiet: odtworzenie → poprawka → fizyczne negatywy → ogląd → review → zwykły hook/push → CI → merge → Railway → odbiór. Bez obchodzenia zabezpieczeń i fikcyjnych danych produkcyjnych.

Kolejność: publikowanie i przepisy → wyszukiwanie i zeszyty → konto/komunikaty → pozostałe ekrany → poczta. Ulepszenia wynikają z odtworzonych problemów. Pełny port marki nadal **CZĘŚCIOWO**; aktualna tabela jest na początku MACIERZ_KOMPLETNOSCI_517.md, niżej pozostają historyczne dowody.

## Aktualizacja 15 września — publiczna tablica #557

Pierwszeństwo ma ten punkt, nie historyczna kolejka poniżej. Profil #551
jest wdrożony: PR #553, main05f57175d50ab20fc1cfaa935c6da3e9b23f21ec,
CI34902008768 success, Railway6447341870 success, Deploy34905037101
success. HTTP potwierdziło Alfa0.30/05f5717; zalogowany Chrome pokazał
trzy zdjęcia w profilu, a kliknięcie drugiego prowadziło do wpisu.
Odbiór zapisano również w #551, #553 i #492.

Menu #555 scalono jako PR #556, main1b43b69668cce731be52d270479264d315b92a24,
po CI34903826583 (10/10 success). Odbiór zakończony: mainCI34905715490,
Railway6447950025 i Deploy34907646494 success. HTTP0.31/1b43b69;
zalogowany Chrome pokazał cztery pozycje w szerokim menu, także panel
moderacji z niezerowym licznikiem. Potwierdzenie w #555, #556 i #492.

Aktywny pakiet: fix/557-publiczna-tablica, Alfa0.32. Włączono już main
z menu0.31, zachowując oba wpisy changeloga. Raport TABLICA_PUBLICZNA_557.md
opisuje duże karty dań, osobne wizytówki i jedno CTA. Pomiar lokalny,
regresje i fizyczne negatywy mają osobne dowody; końcowy PR/CI i Railway
muszą zostać potwierdzone. Nie uruchamiać kolejnego pakietu przed odbiorem.
PR #558 jest już otwarty. Pierwszy CI34907625262 wykrył419px przy oknie320
i foncie bazowym32px z tekstem140%. Naprawiono kurczenie i zawijanie
podtytułu; 12 wariantów i szósta fizyczna kontrola ujemna przeszły.
Sprawdzić najnowszy head i CI przed scaleniem, nie opierać się na starym
wyniku pierwszego commita8e95796.
Pełny port marki pozostaje CZĘŚCIOWO. Następne małe zgłoszenia to #548
(odmiana minut) oraz #549 (wyjaśnienie419), potem pozostała macierz #492.

## Historyczny punkt pracy — pakiet Alfa0.29

Późniejsze zlecenia właściciela: audyt zapisów względem kodu został scalony
przez PR #552 (`46b322f41cedfc6527a77ab35388f1705d41adb2`), z CI main
34897601641 success, Railway6446580739 success, Deploy34897715283 success
i HTTP Alfa0.29/46b322f. Raport AUDYT_ZAPISOW_A_KOD_2026_09_14.md potwierdza
odczytany zakres implementacji i naprawia nieaktualne listy dalszych zadań.

Aktywny pakiet to zgłoszone następnie przez właściciela #551: pusta prawa
kolumna cudzego profilu. Gałąź fix/551-zdjecia-w-szynie, Alfa0.30,
konstytucja1.11/D-214. SZYNA_ZDJEC_PROFILU_551.md opisuje lokalne dowody,
zakres i ograniczenia. Przed dalszą pracą sprawdzić aktualny PR tej gałęzi,
CI i wdrożenie; nie ponawiać setupu konta profil551 w bazie przeglądarkowej.
Po dostarczeniu wrócić do #548/#549 i poniższej kolejki.

PR #546, head `8e4da2f2ff51178df57d7dd118978e441b8c5130`, scalony jako `443da38c763f5ee2c26e8610b516c95fa4e42b94`. #545/#547 zamknięte przez scalenie. Zwykły końcowy hook PASS244,09s. CI PR34889241332:10/10success, log PHP3807/76412. Fizyczne negatywy i review udokumentowane w PIERWSZY_WPIS_545.md i KOMUNIKAT_UGOTOWALEM_547.md.

MainCI34891635127 zakończone10/10success, PHP3807/76412 potwierdzone w osobnym logu. Railway6445551937 success20:45:52UTC i Deploy34894880938success. HTTP oraz zalogowany Chrome potwierdziły Alfa0.29/443da38 i poprawioną podpowiedź pierwszego wpisu. Pełny odbiór: ODBIOR_PRODUKCJI_ALFA_029.md. Gałąź dokumentacji docs/492-odbior-alfa029 zbiera końcowe raporty; po wysyłce odczytać aktualny PR i SHA, nie dublować push.

Późniejszy stan dostarczenia: raporty scalono przez PR #550 jako `3f415a44d8ce917f688ec313bd9ec9bcde0a5298`. CI dokumentacji i main zakończone success w zakresie dokumentacyjnym. Panel Railway potwierdził aktywne udane wdrożenie `3b103797-1543-49b4-a803-15426ba45944`; HTTP pokazało Alfa0.29/3f415a4. [Dowód odbioru](https://github.com/woogitsu/kuking.pl/pull/550#issuecomment-5670773483). Nie ponawiać wysyłki gałęzi #550. [Audyt zapisów względem kodu](AUDYT_ZAPISOW_A_KOD_2026_09_14.md) rozdziela istniejące funkcje od brakujących odbiorów.

Ukończone dodatkowe odbiory: ODBIOR_GOTOWANIA_UZUPELNIENIE_492.md (składnik, zdjęcie, fokus, minutnik); ODBIOR_EDYCJI_WYSZLO_492.md (edycja, przywrócenie wartości,37Tab,radio,3TabWyszło,10wyborówpliku). Zmiana UUID kroku przy zwykłym zapisie wynika z istniejącego syncSteps delete/create; nie przywracać go ręcznie. Dokumenty jawnie ograniczają brak pełnego logu HTTP walidacji, brak podziękowania i fizycznego sprzętu.

## Następne małe pakiety

1. #548 i #549 (wyjaśnienie419 nie może zawsze zgadywać upływu czasu). #548: poprawić odmianę „na1minuta” w istniejącym minutniku, bez nowej funkcji. Uwzględnić RecipeStep::timerLabel, Blade, JS, istniejące testy i eksport; nie zmieniać jednostek ani odliczania. timerLabel jest też używany jako samodzielna etykieta w kreatorze, więc nie zamieniać bezwarunkowo wszystkich etykiet na biernik. Fizyczne negatywy, wersja/changelog, lokalny ogląd i zwykła ścieżka dostarczenia.
2. ODBIOR_ZDJEC_492.md zamyka tekst udający JPG i dwa obrazy z zapisem kolejności/karuzeli. Pozostają inne błędne pliki/tryby zdjęć oraz pełny fokus po aktywacji karuzeli. Nie ma funkcji podmiany pliku wpisu.
3. Podziękowanie z Wyszło i trwały log HTTP walidacji edycji. Ograniczony odbiór store419/429 jest już zakończony w ODBIOR_ODZYSKIWANIA_492.md; pozostają update/PUT, media, maksymalne dane oraz pełny fokus. Nie powtarzać zakończonego store ani nazywać wcześniejszego błędnego założenia skryptu potwierdzoną usterką429.
4. Odnajdywanie: zapis/ponowne znalezienie, wyniki/filtry, paginacja zeszytu/niedostępna treść, listy obserwujących. Korzystać z późniejszych raportów #512/#515/#517/#519 zamiast audytu od zera; szczegóły w KOLEJKA_ODNAJDYWANIA_492.md.
5. Scenariusze #15: docs/product/SCENARIUSZE_UZUPELNIAJACE_15.md. To plan do rzeczywistych sesji, nie badanie modelu ani zgoda na kontakt z uczestnikami.

## Środowisko i dane lokalne

Repo kanoniczne `C:\Users\matma\Documents\Codex\kuking.pl`, wykonawcze WSL `/tmp/kuking-final-20260913`. PHP `/opt/kuking-php-8.4-avif/bin/php`, Chromium `/tmp/kuking431-browsers/chromium-1243/chrome-linux64/chrome`. PostgreSQL **55439**, nigdy5432. Pełny PHP: kuking_final_20260913; przeglądarka: kuking_publikacja492, serwer8033. Nie uruchamiać pełnego PHP równolegle z odbiorem wspólnych mediów. Nie kopiować .git z kopii wykonawczej do kanonicznej.

Sesja `/tmp/kuking-publikacja492-state.json` poza repo. Zachowane prywatne lokalne przepisy `lokalna-zupa-odbioru-publikacji` i `lokalny-szkic-odbioru-publikacji`; drugi ma składnik, zdjęcie kroku i minutę. Sześć własnych wykonań, zero powiadomień po odbiorze547. Nie powtarzać setupu ani skryptów tworzących bez odczytu. output/edycja-wyszlo492/run.mjs zawiera stary warunek identyczności UUID i nie nadaje się do ślepego ponowienia. Zwykły zapis zmienia UUID kroków i updated_at, chociaż treść zostaje przywrócona.

Prawdziwy zoom przez chrome.tabs.setZoom/getZoom; przy celu CSS320/zoom2 fizyczne okno640. Do zrzutów viewport:null, rzeczywiste window-size i CDP captureBeyondViewport:false. Nie używać przyciętego pełnostronicowego artefaktu jako dowodu błędu aplikacji. Widoczny fragment pierścienia i działający Enter wysokiej etykiety nie są równoznaczne z trafieniem w jej środek; szczegóły w raporcie edycji.

Helpery GitHub w output używają istniejącego dostępu bez wypisywania sekretów. Po błędzie API zawsze odczytać stan przed ponowieniem. PR#456 pozostawić; #493 nie scalać ponownie. Zgłoszenie ramki#518 użytkownik przestał widzieć w0.19; nie przywracać starej hipotezy jako potwierdzonego błędu.
Lokalny wpis z dwoma obrazami:01a0a196-9771-710e-8732-79da2a8ff877, private/carousel. Powstał tylko jeden; całkowita liczba wpisów w bazie wzrosła3→4. Drugi obraz to jawny lokalny zrzut testowy. Nie ponawiać output/zdjecia492/run.mjs.

Odzyskiwanie: ODBIOR_ODZYSKIWANIA_492.md potwierdza store419 przez prawdziwy serwer i kontrolowany transport starszego klienta oraz store429 po naturalnym oczekiwaniu576281ms. Natywne ponowienia302 i GETedycji zachowały pola. Brak update/mediów/maksymalnych danych. Własny8534 zatrzymany; oddzielna baza kuking_recovery492_1789417626 pozostawiona z2kontami/5przepisami/15krokami, w tym3wcześniejsze udane próby. Surowe dane/helpery są poza repo w /tmp/kuking-recovery492-evidence-1789417626. Nie kopiować ich doGitHub. Nowe zgłoszenie tekstu419:#549.
