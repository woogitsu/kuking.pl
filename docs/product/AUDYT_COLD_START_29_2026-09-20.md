# Pierwsza osoba, pierwsza dwudziestka i Bramka A — audyt #29

Data: 20 września 2026. Gałąź: `gpt/cold-start`. Źródło pomiaru: nietknięty commit `4c811cc7bff365fb8f86d87eabac93b7738a45cd`.

**Wniosek:** pierwsza osoba może założyć konto, przejść onboarding, opublikować zdjęcie i utworzyć zeszyt. Nie ma jednak czego oglądać ani z czego gotować. Największa luka to brak prawdziwych treści i odzewu; dodatkowo strona główna obiecuje treści „poniżej”, których na pustej instalacji nie ma. Bramka A nie jest warunkiem wpuszczenia pierwszej osoby: jest zgodą na rozszerzenie działającej dwudziestki do kolejnych osób. Nie ma podstaw, by uznać ją za zaliczoną na podstawie tego audytu.

Rekomendacja do decyzji właściciela: zacząć od **6 prawdziwych, podpisanych redakcyjnie przepisów i 9 zwykłych wpisów ze zdjęciem**, skupionych wokół **3 promowanych tagów**, oraz wyznaczyć gospodarza i zastępstwo. To minimum operacyjne do pierwszych zaproszeń, nie nowy próg Bramki A ani gwarancja retencji. Nie utworzono takich treści w ramach audytu.

## 1. Zakres i jakość dowodów

- **Pomiar własny — przeglądarka:** Chromium, lokalna instancja `http://127.0.0.1:8629`, nowa baza `kuking_flota_gpt-cold-start_browser`, PostgreSQL `127.0.0.1:55439`, właściciel `kuking`. Najpierw wyłącznie migracje, bez `DatabaseSeeder`, `DemoSeeder` i `TrescZalazkowaSeeder`: zero ludzi, wpisów, przepisów i tagów.
- Potem dodano wyłącznie słowniki: `UnitSeeder`, `TagSeeder`, `TagPromotionSeeder`. Wynik własny: **1446 tagów, 2542 aliasy, 12 promowanych tagów, nadal zero treści**. Pozwoliło to oddzielić brak konfiguracji od braku społeczności.
- Jedyne konto ręcznej próby: jednoznacznie nazwane `TEST lokalny 29` / `test_lokalny_29`, adres w domenie `.invalid`. Jedyny obraz to plansza „TEST LOKALNY #29 — NIE JEST DANIEM”. Wpis również mówił, że jest próbą przesłania obrazu. To izolowane dane techniczne, nie zalążek społeczności. Konto wyłączono z pomiaru WAC przez `KUKING_TEST_USERNAMES`.
- Poczta lokalna trafiała do logu; Turnstile nie był skonfigurowany. Nie zmierzono prawdziwej dostarczalności poczty, produkcyjnego R2, zewnętrznej moderacji ani ochrony antybotowej. Nie dotykano produkcji.
- **Odczyt własny:** kod i dokumentacja z podanego SHA, bieżące zgłoszenia [#29](https://github.com/woogitsu/kuking.pl/issues/29) i [#15](https://github.com/woogitsu/kuking.pl/issues/15), odczytane bez modyfikacji. Otwarte checkboxy nie są dowodem zerowej aktywności na produkcji.
- **[pomiar cudzy: docs/OTWARCIE.md i komentarze #15]** Historyczne informacje o sesjach z użytkownikami w `docs/OTWARCIE.md` i komentarzach #15 nie zostały powtórzone. Nie doliczam ich do sesji wykonanych w tym audycie ani nie uznaję za aktualny komplet dowodów.
- Wybrane oryginalne zrzuty i drzewa dostępności: dowody przeglądarkowe (`output/playwright/cold-start-29/` — zrzuty sesji przeglądarki, pominięte przy odzysku dokumentów na decyzję właściciela). Czas w nazwie pliku to UTC (czas warszawski +2 h). Hasła, cookies i plik `.env` nie wchodzą do repozytorium.

## 2. Co naprawdę widzi pierwsza osoba

| Krok wykonany w przeglądarce | Obserwacja na pustej bazie | Ocena i następny krok |
|---|---|---|
| Gość: `/` | „Pokaż, co dziś ugotowałeś”, „Ktoś odpowiada”, a niżej „Jeszcze nic tu nie ma”. Tablica: „Dziś jeszcze nikogo nie wybraliśmy”. Brak fikcyjnych zdjęć i liczników. | Stan pusty uczciwie ujawnia początek. Obietnica odpowiedzi wymaga dyżuru człowieka; nie zapewnia jej samo konto gospodarza. |
| „Najpierw się rozejrzę” → `/odkryj` | Pusto; można przejść do rejestracji. Tablica odsyła do „Świeżo z Kuking”, czyli ponownie `/odkryj`. | **Odtworzona pętla nawigacji:** odnośnik nie prowadzi do innej zawartości. Nie blokuje rejestracji, ale nie pomaga się rozejrzeć. |
| Rejestracja | Widoczne etykiety, wymagane potwierdzenia. Wysłanie poprawnych danych tworzy konto i prowadzi do zainteresowań. | Przejście działa lokalnie. Nie jest to pomiar produkcyjnego Turnstile ani poczty. |
| `/witaj/zainteresowania`, bez słowników | „Nie mamy jeszcze listy tagów do zaproponowania”. Można przejść dalej. | Uczciwe i drożne; słowniki są elementem przygotowania instalacji. |
| `/witaj/ludzie` | Nagłówek „To są ludzie, którzy tu gotują”, ale niżej „Nie mamy jeszcze kogo Ci pokazać”. Dalej i pominięcie działają. | Nagłówek nie odpowiada stanowi zerowemu. Nie pojawiają się wymyślone osoby. |
| Koniec onboardingu | Konto gotowe; „Dodaj pierwsze zdjęcie” oraz „Na razie tylko pooglądam”. | Druga droga prowadzi do kolejnego pustego ekranu. To decyzja o dostępnej treści, nie problem liczby kroków formularza. |
| `/home`, pusto u obserwowanych | „Poniżej pokazujemy to, co ostatnio ugotowali inni”, następnie „Jeszcze nic tu nie ma”. | **Potwierdzona sprzeczność komunikatu z zawartością.** Do osobnej poprawki z testem czerwonym przed zmianą. |
| `/zeszyt` | „Zeszyt jest jeszcze pusty”; wyjaśnienie działania „Zapisuję”; „Poszukaj przepisów”. | Mówi do człowieka, nie do bazy. Pustka jest tu prawidłowa: nie wolno zapisywać za niego. |
| Zeszyt → wyszukiwarka | Formularz oczekuje zapytania; nie prezentuje automatycznie całej bazy. Po „zupa”: brak przepisu i zachęta „Dodaj taki przepis”. | Tekst prawdziwy, ale osoba chcąca oglądać znów dostaje zadanie publikacji. Sama liczba przepisów nie zapewni wyników dla dowolnego zapytania. |
| `/tagi`, bez słowników | „Tagi jeszcze się nie pojawiły”; można dodać własny tag przy wpisie. | Instrukcja zrozumiała, lecz normalna instalacja powinna mieć przygotowane słowniki. |
| `/tagi`, po słownikach | 12 promowanych tagów; wszystkie mają „0 wpisów” i „Zobacz wpisy”. | Konfiguracja jest gotowa, zawartości nadal nie ma. Promowanie pustych tematów rozprasza pierwszych ludzi. |
| `/tag/przetwory` | „Tu jeszcze nikt nic nie ugotował”; odnośnik do dodania zdjęcia z tagiem `przetwory`. | Dobra konkretna droga działania. Pole tagów jest faktycznie wstępnie wypełnione. |
| Ponowny wybór zainteresowań po słownikach | Tagi można wybrać. Wybrano `przetwory`; `/home` nadal puste. | Obserwowanie pustego tagu nie wytwarza zawartości. |
| Dodanie obrazu testowego | Formularz przyjął obraz i tekst, utworzył wpis, zachował tag. Po wykonaniu kolejki `media` obraz osiągnął `ready`. | Jeden udany upload i przetworzenie; **nie dowód awaryjności <2%**. Lokalny brak pracownika kolejki na początku próby nie został przypisany aplikacji jako błąd. |
| Strona główna i tag po wpisie | Własny wpis pojawił się w „Najnowsze z Twoich tagów” i na stronie tagu. | Droga od publikacji do widocznej zawartości działa. Jeden autor nie dowodzi istnienia społeczności. |
| Nowy pusty zeszyt | Rozwinięto formularz, utworzono `TEST pustego zeszytu`; komunikat powodzenia, prywatny zeszyt i droga do szukania przepisów. | Tworzenie działa; nowy zeszyt pozostaje pusty zgodnie z intencją człowieka. |
| `/dodaj/przepis` | Wyświetlił się prosty formularz z tytułem, zdjęciem, składnikami, przygotowaniem i widocznością. | Sprawdzono dostępność formularza; **nie opublikowano przepisu w przeglądarce**. |
| `/powiadomienia` | Powitalne powiadomienie z drogą do pierwszego zdjęcia. | Działa komunikat systemowy. To nie odpowiedź drugiego człowieka i nie wolno liczyć go do odzewu. |

Przy szerokości **320 px** na stronie tagu zmierzono `scrollWidth = innerWidth = 320`, tekst podstawowy `18 px`. Nie wykonano pełnego audytu WCAG, powiększenia 200% ani pomiaru wszystkich celów dotknięcia. Na zrzucie pustego zeszytu wskazówka „Dopasuj rozmiar tekstu” częściowo zasłania objaśnienie, dopóki człowiek jej nie zamknie. Nie zbadano całego zachowania tej wskazówki; jest to punkt do sesji #15, nie ogłoszony bloker.

Odtworzenie: świeża izolowana baza → migracje → `/` → „Najpierw się rozejrzę” → rejestracja → pomijanie opcjonalnego onboardingu → `/home` → `/zeszyt` → „Poszukaj przepisów” → `zupa` → `/tagi`. Następnie załadować tylko trzy słowniki wymienione wyżej i powtórzyć zainteresowania oraz tag `przetwory`. Nie uruchamiać seedera person, aby „naprawić” wynik próby.

## 3. Bramka A: dowody potrzebne do przejścia

Źródło progów: [COLD_START §9](COLD_START.md#9-metryki-bramkowe), zgodne z treścią #29 odczytaną 20 września. **Żaden z ośmiu warunków nie został tutaj potwierdzony dla prawdziwej społeczności.** Audyt lokalny sprawdza możliwość działania i narzędzia, nie zastępuje danych z pierwszej grupy.

| Warunek | Stan dowodu w tym zadaniu | Czego konkretnie brakuje |
|---|---|---|
| ≥20 realnych osób z ≥1 wpisem | Niepotwierdzony; lokalnie tylko konto TEST. | Zestawienie 20 różnych prawdziwych autorów i ich opublikowanych wpisów, z wyłączeniem testów i zalążkowych person. |
| WAC / zarejestrowani ≥50% | Komenda WAC działa; po wykluczeniu TEST brak kwalifikowanej aktywności. | Zamknięty tydzień, licznik WAC i jawny mianownik tej samej grupy. Dla 20 kwalifikowanych osób: minimum 10 aktywnych. Zero dzielone przez zero nie jest wynikiem 100%. |
| ≥10 osób z ≥3 wpisami | Niepotwierdzony. | Zestawienie minimum 10 realnych autorów z co najmniej trzema wpisami każdego; 30 wpisów jednego gospodarza nie spełnia progu. |
| ≥15 „Ugotowałem”, z tego ≥8 nie od gospodarza | Istnieją testy akcji i powiadomień; nie ma dowodu realnego gotowania w badanej grupie. | 15 rzeczywistych zdarzeń wykonania z oznaczeniem autora wykonania; minimum 8 poza gospodarzem. Nie zastępować liczby zdarzeń liczbą przepisów. |
| 100% wpisów z ≥1 odpowiedzią | Pusta grupa nie dowodzi odzewu. Jest panel gospodarza bez odpowiedzi. | Lista wpisów objętych pomiarem, widoczna odpowiedź innej osoby do każdego, licznik bez odpowiedzi równy 0. Odpowiedź automatyczna i komentarz autora do siebie nie wystarczą. |
| Mediana pierwszej odpowiedzi ≤3 h | Jest istniejący pomiar w panelu; brak realnej kohorty. | Czasy publikacji i pierwszych kwalifikowanych odpowiedzi w ustalonym oknie, mediana oraz osobno liczba wpisów nadal bez odpowiedzi. |
| Awarie uploadu <2% próbek | Jeden własny poprawny upload JPEG i zakończone przetworzenie. | Rejestr prób z pierwszych sesji i alfy, liczba prób oraz niepowodzeń, formaty i urządzenia; także awarie przetwarzania. 1 sukces nie wystarcza do wniosku o niezawodności. |
| Blokery UX 50+ =0 | Przejście techniczne nie jest badaniem 50+. #15 pozostaje otwarte. | Komplet wymaganych w #15 raportów z 13 sesji oraz zamknięte i ponownie sprawdzone blokery. Nie można wywnioskować „0 blokerów” z braku nowych zgłoszeń. |

**STOP:** WAC/zarejestrowani <35% albo publikowanie wyłącznie po telefonicznym przypomnieniu gospodarza. Strefa 35–49% również nie otwiera Bramki A. Wtedy diagnozujemy powód niewracania, nie uruchamiamy szerszego naboru.

### Co już istnieje, a co wymaga doprecyzowania pomiaru

Odczyt kodu: `WeeklyActiveCooks`, `CookActivity`, `CookEligibility`, `RaportPowrotow`, panel `/admin/bez-odpowiedzi`. Nie proponuję drugiego panelu ani nowego systemu analitycznego.

- WAC uwzględnia wpis, przepis lub wykonanie; wyklucza m.in. testy, konta zalążkowe, zamknięte i skonfigurowanego gospodarza. Właściciel musi zatwierdzić zgodny mianownik „zarejestrowani” i okno pomiaru. Propozycja: zamknięty tydzień warszawski i ta sama kwalifikowana grupa po obu stronach ułamka.
- `kuking:raport` raportuje m.in. **różne ugotowane przepisy i różnych powiadomionych autorów**. To nie jest liczba zdarzeń wymagana przez próg 15/8. Nie przepisywać tych liczb do kratki Bramki A bez sprawdzenia definicji i wykluczeń.
- Panel odzewu ma własny zakres widoczności i pomiar odpowiedzi z okresu 30 dni. Mediana odpowiadających wpisów nie mówi nic o tych, które odpowiedzi nie dostały. Wartość zaokrąglona na ekranie również nie zastępuje dokładnego sprawdzenia granicy 3 h.
- Sygnały błędów zdjęć nie są pełnym mianownikiem wszystkich prób: część prób nie dochodzi do serwera, część pada dopiero w kolejce. Przed pomiarem trzeba uzgodnić, czy liczymy każde wysłanie, ponowienia i pliki niespełniające jawnych wymagań. Nie zmieniono tej definicji testem automatycznym.

## 4. Minimalna zawartość startowa — liczby i ograniczenia

**Minimum techniczne dla jednego tematu:** jeden publiczny, opublikowany przepis daje wynik dla pasującego zapytania oraz własną kartę w strumieniu; jeden zwykły publiczny wpis z odpowiednim tagiem wypełnia stronę tego tagu. To usuwa zera, ale dwa elementy nadal wyglądają jak próba instalacji.

**Proponowane minimum operacyjne: 6 przepisów + 9 wpisów ze zdjęciem + 3 promowane tagi.**

| Miejsce | Dlaczego ta liczba wystarcza do pierwszego oglądania | Warunek |
|---|---|---|
| Strumień | 6 przepisów tworzy 6 wpisów wskazujących; wraz z 9 zwykłymi wpisami daje 15 kart, czyli domyślną stronę zalogowanego strumienia. Gość ma krótszy wybór. | Wszystkie treści opublikowane, publiczne i widoczne; daty prawdziwe. Nie liczymy przepisu i jego karty jako dwóch niezależnych dzieł. |
| Wyszukiwarka | 2 przepisy dla każdego z 3 tematów dają sensowny wybór dla ustalonych zapytań tematycznych. | Nazwy i treść rzeczywiście pasują do zapytań. Nie obiecujemy wyników dla całej polskiej kuchni. |
| Strony tagów | 3 zwykłe wpisy na każdy z 3 tagów pozwalają coś obejrzeć zamiast jednego przykładu. | Tag musi być przypięty do wpisu. Publikacja przepisu tworzy kartę, ale **nie kopiuje tagów** do tej karty (`WpisWskazujacyPrzepis`). Same przepisy nie wypełnią automatycznie katalogu tagów. |
| Zeszyt | Zawartość dostępna do samodzielnego zapisania. | Zeszyt nadal może być pusty; człowiek sam wybiera „Zapisuję”. To oczekiwany stan, nie brak do usunięcia seedem. |
| Tablica osób i dań | Prawdziwe materiały można pokazać, ale jeden autor nie wypełni wszystkich miejsc. | Automatyczny wybór tablicy ogranicza powtórzenia autora. Nie zakładamy dodatkowych kont, żeby zapełnić makietę. |

To **propozycja**, nie udowodniona eksperymentem granica „żywego miejsca”. Nie wprowadzono asercji wymuszającej te liczby. Koszt to przygotowanie i sprawdzenie 6 własnych receptur oraz 9 autentycznych relacji ze zdjęciami i odpowiednimi prawami, nie napisanie 15 tekstów przez model. Można opublikować relację z gotowania własnego przepisu, ale należy unikać dziewięciu niemal identycznych kart.

Warianty dla właściciela:

1. **3 wypełnione tagi (zalecane na początek):** 6 przepisów i 9 wpisów; mniejsza powierzchnia do obsłużenia, węższy wybór tematów. Zmiana listy promowanych tematów jest świadomą decyzją, nie została wykonana.
2. **Zachować 12 promowanych tagów:** co najmniej 12 trafnie otagowanych zwykłych wpisów, by uniknąć zer przy założeniu jednego tematu na wpis; **36**, by utrzymać proponowane 3 wpisy na temat. Jeden prawdziwy wpis może sensownie pokryć kilka tagów, ale nie wolno dopisywać niepasujących tagów dla licznika. Sześć przepisów nie daje wtedy dwóch propozycji dla każdego z 12 tematów; taka szerokość wymagałaby 24 przepisów.
3. **Start bez treści redakcyjnych:** koszt tworzenia mniejszy, ale pierwsza grupa musi wejść w umówionym czasie z własnym gotowaniem i pomocą gospodarza. W przeciwnym razie powtarza dokładnie pustą drogę z audytu. To świadomie większe ryzyko odejścia pierwszych osób.

Treść redakcyjna musi mieć jawnego autora redakcyjnego, rzeczywiste zdjęcia i sprawdzone receptury. Nie udaje dorobku dwudziestu osób i nie zalicza za nie Bramki A. Historyczne persony zalążkowe z D-025 i polecenia seedera w starszych materiałach nie są upoważnieniem do ich utworzenia w tym zadaniu: bieżące polecenie właściciela tego zabrania.

Nie mylić tego minimum z większym planem treści na pierwsze tygodnie w `COLD_START.md` ani progiem otwarcia kampanii z `OTWARCIE.md`. Kalendarz cold startu zaczyna się w listopadzie; obecna lista promowanych tagów ma kontekst wrześniowy. Właściciel powinien wskazać rzeczywistą datę startu, zamiast mechanicznie kopiować kalendarz.

## 5. Pierwszych dwudziestu — plan wykonania przez gospodarza

To plan operacyjny; w tym zadaniu nikogo nie zaproszono ani nie skontaktowano się z żadną osobą.

1. Właściciel wskazuje gospodarza i zastępstwo, potwierdza prawdziwe konto oraz zgodność ustawienia `host_username` z nim. Domyślna nazwa konta i wyświetlana nazwa gospodarza to różne pola. Bez istniejącego właściwego konta onboarding nie zapewni obserwowania gospodarza.
2. Przygotować wybraną zawartość startową i dyżury. `COLD_START.md` zakłada około 2 h pracy gospodarza dziennie; to około 14 h tygodniowo. To nie gwarantuje odpowiedzi w trzy godziny bez rozłożenia dyżurów i zastępstwa. Obietnica powinna być operacyjnie wykonalna.
3. Właściciel przygotowuje wskazaną w #29 listę 40–60 prawdziwych kontaktów, z której zaprasza pierwszą dwudziestkę. Asystent nie przejmuje kontaktu. Pomoc 1:1 prowadzi do własnego zdjęcia i kilku słów uczestnika; nie do przepisywania mu sztucznej aktywności.
4. Uruchomić małe partie, np. 5 → 10 → 20, dopiero po sprawdzeniu pierwszej publikacji i odzewu w poprzedniej. To propozycja organizacji, nie dodatkowa bramka produktu. Concierge onboarding przewidziany w dokumentacji trwa do pierwszych 50 osób.
5. Każdego dnia gospodarz przegląda istniejący panel bez odpowiedzi, awarie zdjęć, zgłoszenia i powiadomienia. Odpowiada na wpisy, a wykonanie „Ugotowałem” oznacza faktyczne ugotowanie, nigdy uprzejmościowe kliknięcie dla progu.
6. Po pełnym tygodniu zapisać osiem dowodów Bramki A i sygnały STOP. Zapisywać również, czy kolejna publikacja była samodzielna, czy dopiero po przypomnieniu. Same rekordy w bazie tego nie wyjaśnią.
7. Badania #15 przeprowadzić w wymaganym przekroju: 5 osób 50–59, 5 osób 60–69, 3 osoby 70+, różne urządzenia i co najmniej 2 osoby, które wcześniej nic nie publikowały. Przy 45–60 min sesji i 15 min notatek daje to **13–16,25 h**, poza rekrutacją i ponownym sprawdzeniem poprawek. Właściciel może zdecydować o częściowym pokryciu tej grupy z pierwszą dwudziestką; nie utożsamiamy badania z retencją.

## 6. Co musi działać od pierwszego dnia

| Niezbędne przed wpuszczeniem prawdziwych ludzi | Dowód lub brak w tym audycie |
|---|---|
| Rejestracja, logowanie, odzyskanie dostępu i prawdziwe dostarczanie wiadomości | Rejestracja przeszła w przeglądarce; zewnętrzna poczta i Turnstile wymagają oddzielnej próby właściciela na środowisku przeznaczonym do odbioru. |
| Zdjęcie z telefonu: wybór, wysłanie, bezpieczne przetworzenie, widoczny wynik lub instrukcja naprawy; brak utraty tekstu po błędzie | Jeden poprawny JPEG przeszedł całą lokalną drogę. Potrzebne realne telefony, HEIC/duże pliki/słaby zasięg i sesje z ludźmi. |
| Publikacja przepisu i dotarcie do niego; „Ugotowałem” oraz powiadomienie autora | Istnieją testy HTTP i domenowe, w tym `UgotowalemZawszePowiadamiaAutoraTest`. Nie wykonano w przeglądarce próby między dwoma prawdziwymi użytkownikami. |
| Komentarz, odzew i zauważalne powiadomienie | Powitanie lokalnie działa. Obietnica odzewu wymaga człowieka i sprawdzenia całej drogi przez drugą osobę. |
| Widoczność publiczna/prywatna, Policy, blokady, zgłoszenie, moderacja i usuwanie konta | Nie wolno odkładać ochrony danych pierwszych 20 osób na etap wzrostu. Testy nie zastępują dyżuru moderacyjnego. |
| Czytelność 50+, zachowanie wpisanych danych, drożne puste stany | Zmierzono wybrane ekrany i 320 px. Sprzeczny komunikat `/home` oraz pętla `/odkryj` zostały opisane; pełny odbiór pozostaje w #15. |
| Działająca kolejka zdjęć i powiadomień, sygnał awarii, kopia i odtwarzanie danych | Lokalny worker przetworzył obraz. Nie badano infrastruktury produkcyjnej. Aktualne tropy operacyjne z #29: #594 (PostgreSQL/RPO/RTO), #193 (kopia poza środowiskiem), #617 (obiekty R2), nie historyczne zamknięte #9. |

Próba odtwarzania występuje także w Bramce B, ale roadmapa wymaga ochrony danych już dla zamkniętej alfy. Nie traktować późniejszej bramki jako zgody na ryzykowanie zdjęć pierwszych ludzi.

**Może poczekać:** szeroki katalog promowanych tematów, pełna tablica wszystkich miejsc, duża biblioteka przepisów, automatyzacja ręcznej pracy gospodarza i kampanie wzrostu. Wiadomości prywatne, planer, OCR, generowanie przepisów, rankingi i inne funkcje spoza MVP nie rozwiązują obecnej pustki. Niczego z tej listy nie dodano.

## 7. Zakres zmian, walidacja i decyzje

Zmiana obejmuje wyłącznie ten raport i dowody. Nie poprawiano aplikacji ani jej komunikatów; nie dodawano testów utrwalających decyzję o liczbie treści. Potwierdzone rozbieżności `/home` i `/odkryj` mają materiał do osobnych poprawek, które muszą najpierw dostać czerwony test. Zgłoszenia #29 nie zamykano, bo zależy od działań z prawdziwymi ludźmi.

Wyniki kontroli technicznych są zapisane w walidacji (`output/playwright/cold-start-29/WALIDACJA.md` — zrzut sesji przeglądarki, pominięty przy odzysku dokumentów na decyzję właściciela). Testy wykonywano na oddzielnej bazie testowej `kuking_flota_gpt-cold-start` na porcie 55439, po zakończeniu oglądu przeglądarkowego. Wyłączono `ProbaOdtworzeniaTest` zgodnie z poleceniem właściciela: korzysta ze wspólnej bazy źródłowej. Skrypt floty domyślnie wyłącza grupę `dwa-polaczenia`; nie jest to deklaracja wykonania tej grupy.

**Do decyzji właściciela:** wariant zawartości i liczba promowanych tagów; prawdziwa tożsamość gospodarza i zastępstwo; data startu i dyżury; okno oraz mianowniki pomiarów Bramki A; organizacja 13 sesji #15. Progi samej Bramki A pozostają bez zmian.

**Niewykonane celowo:** push, PR, zmiany produkcji, kontakt z ludźmi, tworzenie kont zewnętrznych, publikacja treści startowych, seedowanie udawanych mieszkańców, zmiany schematu i migracje aplikacji. Raport nie potwierdza produkcyjnej gotowości ani spełnienia bramek na podstawie lokalnych danych.

Wycofanie tej zmiany: odwrócić commit dokumentacyjny. Brak wpływu na schemat bazy i zachowanie serwisu.
