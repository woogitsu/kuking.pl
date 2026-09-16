# Proporcje mniejszej skali — #589

Przygotowana Alfa 0.41 na gałęzi `fix/skala-proporcje`, z bazy `3dc2b33f7c760c4feeb78581382c5ef6c7c9aebb`. Poniższe wyniki dotyczą lokalnego kodu i końcowego CSS `app-DPAmbRUP.css`; nie stanowią potwierdzenia CI ani wdrożenia.

## Zmiana

Mniejsze wartości rozmiaru tekstu zagęszczają także układ. Współczynnik `min(1, var(--user-text-scale))` obejmuje tokeny odstępów, utility spacing, minimalne wysokości pól i większych kontrolek oraz odstępy wspólnej ramy: nagłówka, formularza publikacji, kart wpisów, zakładek, dolnej nawigacji i sekcji powitalnych. Przy 100% i 140% współczynnik przestrzeni wynosi 1; większy tekst nadal naturalnie zwiększa wysokość elementu.

Ważne przyciski i pola zachowują minimum 48 px. Nie zmieniono szerokości kontenerów, breakpointów, promieni, proporcji zdjęć ani równych wysokości kart tablicy. Nie zastosowano globalnego zoomu, transform ani ukrywania overflow. Nie obiecujemy liniowego zmniejszenia całej geometrii. W zasobach nie znaleziono użyć utility `h-12`, `w-12`, `min-h-12` ani `size-12`, które wymagałyby dodatkowych zabezpieczeń celów dotykowych; nie dodano martwych reguł dla tych klas.

Obejrzano cztery zdjęcia zgłoszenia, od `1-Photo-1.jpg` do `4-Photo-4.jpg`. Stałe odstępy przy mniejszym tekście odpowiadały problemowi. Sam różny poziom wypełnienia kart treścią nie uzasadniał usunięcia ich równych wysokości.

## Weryfikacja automatyczna

- Build Vite: 72 pary kontrastu PASS, końcowy CSS `app-DPAmbRUP.css`.
- `scripts/skala-proporcje.mjs`: 72 pomiary PASS — szerokości 320, 360, 390, 414, 768 i 1440, oba motywy, przejścia skali 100 → 70 → 80 → 90 → 140 → 100. Chromium używa rzeczywistego zbudowanego CSS i lokalnego fontu Inter. Test mierzy padding, gap, wysokości przycisku i pola, minimum dotyku, brak poziomego overflow, wspólne klasy ramy oraz powrót do 100%. HTML jest reprezentatywną fixture, nie renderem Laravel. To nie jest test zoomu przeglądarki ani zapisu preferencji przez HTTP.
- Fizyczny negatyw rzeczywistego `tokens.css`: zastąpienie współczynnika wartością 1, build i oczekiwany FAIL „padding przycisku”; następnie odtworzenie MD5 i mtime, build i ponownie 72 pomiary PASS. Kopia źródła pozostała poza repozytorium.
- Celowane PHP: 15 testów / 399 asercji PASS. Testy `BelkaPrzyDuzymTekscieTest`, `PolaDoWpisywaniaSaWiekszeTest` i `KafelDodawaniaPrzyDuzymTekscieTest` rozpoznają dokładną nową składnię tokenów, zachowując kontrolę minimum 48 px i wartości bazowych. Nie zastąpiono tych kontroli dowolnym dopasowaniem liczby.
- Trzy fizyczne negatywy kontraktów PHP: obniżenie podłogi dotyku, podmiana jednostki tokenu pola oraz zmiana bazowego odstępu. Każdy wywołał FAIL; po przywróceniu bajtów i mtime właściwa rodzina testów przeszła. Testy działały w osobnej kopii, z bazą `kuking_589_tests` i własnymi mediami.
- Pint: 4 pliki PASS. Pełny zestaw PHP uruchomiono później podczas obowiązkowego hooka — wynik i korekty opisano poniżej.
- CI ma obowiązkowe wywołanie pomiaru Chromium po buildzie w istniejącym zadaniu assetów. Zmiana samego skryptu również uruchamia zadanie. Wyniku CI tego pakietu jeszcze nie ma.

Dowody: `evidence/skala589/wyniki.json`, `negative.json`, `negative-php.json` i `php.txt`.

## Odczyt produkcji i ręczny odbiór lokalny

Odczyt produkcji Alfa 0.39 / `108bc93` przez przeglądarkę: przycisk „Najpierw się rozejrzę” przy 100% miał font 18 px, wysokość 50,5 px i padding 12 / 20 px. Przy 70% font wynosił 12,6 px, interlinia 15,75 px, wysokość 48 px, lecz padding pozostał 12 / 20 px, a spacing-6 wynosił nadal 1,5 rem. Potwierdza to problem odstępów, nie interlinii.

Końcowy lokalny render Laravel na porcie 8059, z CSS `app-DPAmbRUP.css`, obejrzano przy 1440 px / 100% oraz 390 px / 70%. Przy 70% padding publikacji wynosił 14 px. Po wejściu przez „Dodaj zdjęcie” pole miało 48 px wysokości, textarea 123,95 px, padding 11,2 px i font 12,6 px; nie wystąpił poziomy overflow. Pusty POST „Opublikuj” pokazał aktywny alert i błąd przy polu, bez utworzenia publikacji.

Formularz z błędem obejrzano także przy 320 px / 140% w ciemnym motywie, bez poziomego overflow. Panel Wygląd przy tych ustawieniach miał wewnętrzne przewijanie, dostępne kontrolki i działające „Zamknij”. Wybranie 70% oraz ciemnego motywu przez interfejs, a następnie przeładowanie, zachowało obie preferencje konta przez HTTP. Ten odbiór jest oddzielny od 72 pomiarów syntetycznej fixture. Ogląd wykonano w przeglądarce bez utrwalonych plików PNG; lokalne zdjęcia demonstracyjne były placeholderami, więc nie jest to odbiór zdjęć.

## Ograniczenia i integracja

Nie wykonano pełnego odbioru wszystkich stron, całej ścieżki klawiatury ani rzeczywistego zoomu 200%. Wysokie belki przy 140% zachowują dotychczasową kompozycję. Minimalne cele 48 px, logo, obrysy i proporcje zdjęć celowo nie skalują się liniowo.

Integracja main `9a44ccc455232fa3cf56e115b5764695c16e2592` została wykonana w `e669173`. Zachowano panel #587, wpisy changelogu 0.40 i 0.41, D-218 oraz D-219, konstytucję 1.16 i jego CI. Po integracji ponownie wykonano build (72 pary kontrastu PASS), 72 pomiary skali, celowane PHP 15/399 i Pint 4 pliki — PASS. Nowe assety to `app-BbC4zqzk.css` i `app-BfcwSyV6.js`. Wcześniejszy ręczny odbiór oraz negatywy dotyczą historycznego CSS DPAmbRUP; nie przedstawiamy ich jako powtórzonych po merge. Wyniki po integracji zapisano oddzielnie jako `merged-wyniki.json`, `merged-build.txt` i `merged-php.txt`.

## Krótkie uruchomienie regresji

### Kontrola ujemna nawigacji po zmianie składni CSS

CI `35026661619`, zadanie `104680326817`, zakończyło się 16 września błędem
`N492_MUTACJA_NIE_ZMIENIA`. Test szukał literalnych marginesów 8 px oraz
paddingu 4 px, podczas gdy źródło używa już mnożnika skali. Dopasowania
zaktualizowano do rzeczywistych reguł; mutacje nadal przypinają dolną belkę
lub zwiększają padding do 16 px. Progi i wymóg wykrycia błędu pozostają.
Pełna lokalna kontrola nawigacji w osobnej bazie `kuking_port589_nav` na 55439
przeszła: 20 konfiguracji, 7 fizycznych negatywów, 160,941 s. Każda mutacja
wywołała oczekiwany błąd, a przywrócenie potwierdziło MD5
`c9bcdeb03dd17093c8c5f2abb53da2ad` i mtime. Niezależny review dopasowań nie
znalazł blokerów. Ponowny hook i CI nadal są wymagane przed scaleniem.

W katalogu z zależnościami: `npm run build`, następnie `CHROMIUM_PATH="$CHROME_BINARY" node scripts/skala-proporcje.mjs`. Bez `CHROMIUM_PATH` używany jest Chromium Playwright. Wynik trafia do `output/skala589/wyniki.json`. Ten pomiar nie wymaga PHP ani bazy. Lokalny runtime ma własne `.env`, vendor i storage; synchronizacja źródeł nie może ich nadpisywać.

## Korekty ujawnione przez pełny hook

Pierwszy zwykły push został prawidłowo zatrzymany przez pełne PHP. Zalogowane powtórzenie wykazało dwa dodatkowe testy wymagające obsługi nowej składni: `OdstepPodNaglowkiemStronyTest` oraz `RytmPionowyStronyPrzepisuTest`. Zmieniono wyłącznie ścisłe parsowanie `calc(... * var(--user-layout-scale, 1))`: nagłówek Start nadal wymaga bazowego odstępu co najmniej 24 px, a tokeny rytmu nadal muszą używać rem, aby rosły przy powiększeniu czcionki przeglądarki. Nie zmieniono CSS ani progów.

Diagnostyczne pełne powtórzenie miało 3850 PASS i 4 FAIL: oprócz tych dwóch parserów jego helper miał inne APP_URL i brak regionu dysku niż poprawny zestaw zmiennych zwykłego hooka. Po ujednoliceniu środowiska i korektach parserów cztery rodziny przeszły: 26 testów / 78 asercji PASS. Dwa fizyczne negatywy rzeczywistych źródeł (odstęp Start poniżej 24 px oraz rem zastąpione px) wywołały FAIL; odtworzenie MD5/mtime i dodatnie testy PASS. Pint 2 pliki PASS. Dowód: `negative-merged-contracts.json`. Kolejny zwykły hook jest wymagany przed publikacją; ten raport nie deklaruje jego przyszłego wyniku.

Po integracji wykonano również ogląd rzeczywistego Laravel z CSS BbC4zqzk: formularz 320 px / ciemny / 70% oraz landing 390 px / jasny / 70%, 1440 px / ciemny / 70% i 320 px / ciemny / 140%, bez poziomego overflow. Stopka wskazywała Alfa 0.41. Kontrolki i etykiety formularza pozostawały widoczne podczas przewijania. Zrzuty zachowano lokalnie w `output/skala589-real`. To odbiór przeglądarki, nie fizycznego urządzenia ani pełnej ścieżki fokusu.

## Dalsza diagnoza CI — 16 września

CI 35064876515, job 104692882230 zakończył rodzinę ekranów błędem K511_IMAGE. Pierwotny komunikat nie zawierał statusu HTTP. Dodano ograniczoną diagnostykę odpowiedzi i przekierowań obrazów, bez query, cookies i treści odpowiedzi. Zachowano dotychczasowe asercje oraz czas oczekiwania.

Lokalne odtworzenie w osobnej bazie kuking_port591_diagnostic na porcie 55439 przeszło K509 (432 warianty) i K511 (96 konfiguracji), wraz z pięcioma kontrolami ujemnymi zeszytów oraz przywróceniem MD5 35b47482c05ab1dfa5e209fa579c3276 i mtime. Ten przebieg następnie zakończył się błędem K513_FIXTURE_RESTORE. Błędu obrazu nie odtworzono; 429 jest hipotezą, nie diagnozą. Log: /home/mateusz/port591-diagnostic.log.

Osobna próba kodu diagnostyki potwierdziła przypisanie 302 → 403 do tego samego obrazu, zapis kodu awarii sieci, pomijanie zasobów innych niż obrazy i brak sekretów z query w raporcie. Nie dowodzi to statusu rzeczywistej awarii CI.

Odtworzono przyczynę K513_FIXTURE_RESTORE na izolowanej bazie: zapis daty ISO przez cast Eloquent usuwał offset przed zapisem do timestamptz; strefa sesji PostgreSQL przesuwała chwilę o dwie godziny. Fixture przywraca teraz oryginalny ciąg ISO bezpośrednio przez Query Builder. Próba dodatnia zachowała cały stan (8 powiadomień i tagi). Fizyczne przywrócenie starego kodu PHP wywołało różnicę dat; po odtworzeniu kopii spoza repo MD5 b82bbfffe18cc8f1b84b857c25701ca8 oraz mtime były identyczne, a próba dodatnia przeszła. Pint PASS. Ponowny pełny przebieg grupy uruchomiono; jego wyniku jeszcze nie potwierdzono.
