# Audyt kuking.pl

Data: **11 września 2026**  
Repozytorium: **woogitsu/kuking.pl** (prywatne)  
Badany commit: `8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648`  
Zmiany w repozytorium: **brak**.

## Wniosek i zakres

Wyodrębniono **15 ustaleń: 2 P1, 10 P2 i 3 P3**. Najważniejsze są spójność zapisu, odzyskiwanie eksportów, poprawna sygnalizacja awarii i aktualność zgód mailingowych. Nie jest to lista 15 potwierdzonych luk bezpieczeństwa: obejmuje błędy konstrukcyjne, ryzyka, kompromisy projektowe, skalowanie oraz rozbieżności dokumentacji. A04 wymaga dodatkowego potwierdzenia osiągalności z HTTP.

P1 oznacza wysoki priorytet integralności i niezawodności; P2 istotny problem funkcjonalny lub operacyjny; P3 dokumentację, metadane i poprawki interfejsu. To ocena priorytetu napraw, nie CVSS.

Wykonano ukierunkowany przegląd kodu przez konektor GitHub, odczyt CI i jego artefaktów oraz dwie lokalne reprodukcje izolowane. Nie było narzędzia do uruchomienia subagentów: analizę wykonano samodzielnie. Nie uzyskano pełnego lokalnego checkoutu i środowiska aplikacji. Nie uruchomiono lokalnie całego Laravel/Pest, bazy ani przeglądarkowego E2E. Nie atakowano produkcji, nie modyfikowano danych, nie tworzono commitów i pull requestów.

**Nie jest to kompletny przegląd każdego pliku ani certyfikat bezpieczeństwa.** Nie zamknięto pełnej weryfikacji importów, powiadomień, usuwania kont, wszystkich polityk uprawnień, danych bazowych, poprawności merytorycznej danych kulinarnych i żywieniowych, aktualnych advisory zależności, konfiguracji produkcyjnej oraz odtwarzania kopii zapasowych. Brak ustalenia w danym obszarze nie oznacza braku błędów.

## Mapa ustaleń

| ID | Priorytet | Problem |
|---|---|---|
| A01 | P1 | Zapis przepisu i historii nie jest atomowy |
| A02 | P1 | Eksport może utknąć pomiędzy commitem a wysłaniem zadania |
| A03 | P2 | Scheduler może uznać niezerowy kod komendy za sukces |
| A04 | P2 | Niejasny kontrakt edycji publikacji przy publish=false |
| A05 | P2 | Snapshot nie obejmuje pełnego stanu przepisu |
| A06 | P2 | GET wypisania z newslettera zmienia zgodę |
| A07 | P2 | Stary link ponownego zapisu może odwrócić późniejsze wypisanie |
| A08 | P2 | Cache PWA zatrzymuje stary manifest i stare zasoby |
| A09 | P2 | Paginacja komentarzy nie ogranicza liczby odpowiedzi |
| A10 | P2 | Kontrola dostępu do obrazu powtarza kosztowne zapytania |
| A11 | P2 | Sprzątanie eksportów stale wraca do wyczyszczonych rekordów |
| A12 | P2 | Testy race nie są blokującą bramką jakości |
| A13 | P3 | README nie odpowiada rzeczywistemu trybowi CI |
| A14 | P3 | Sprzeczna deklaracja licencji projektu |
| A15 | P3 | 23 ostrzeżenia częściowego zasłaniania fokusu |

## A01 | P1 | Zapis przepisu i historii nie jest atomowy

**Miejsce:**
- [`app/Domain/Recipes/Actions/PublishRecipe.php`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/app/Domain/Recipes/Actions/PublishRecipe.php)
- [`app/Domain/Recipes/Actions/SnapshotRecipeVersion.php`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/app/Domain/Recipes/Actions/SnapshotRecipeVersion.php)

**Podstawa:** analiza kodu; bez testu awarii w pełnej aplikacji.

Transakcja zapisująca przepis kończy się przed wywołaniem snapshotu. Numer kolejnej wersji jest obliczany przez `max(version_number) + 1`, a składniki, kroki i tagi są pobierane osobnymi zapytaniami po zapisie.

Wyjątek podczas tworzenia historii nie wycofa już zapisanego przepisu. Użytkownik może otrzymać błąd, mimo że treść się zmieniła. Operacje po snapshotowaniu, w tym reindeksowanie, mogą nie zostać wykonane. Równoległe edycje mogą wyliczyć ten sam numer wersji lub zmienić relacje przed utrwaleniem historii pierwszej edycji.

**Naprawa:** wspólna transakcja zapisu i wersji; serializacja zmian konkretnego przepisu i alokacji numeru. Zachować jednolitą kolejność blokowania, również w operacjach na mediach. Zadania zewnętrzne emitować po zatwierdzeniu, z możliwością odzyskania.

**Regresja:** wyjątek przy zapisie snapshotu nie pozostawia częściowej zmiany. Dwie jednoczesne edycje dają dwie spójne wersje, bez kolizji i mieszania danych.

## A02 | P1 | Eksport może utknąć pomiędzy commitem a wysłaniem zadania

**Miejsce:**
- [`app/Http/Controllers/Settings/DataSettingsController.php`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/app/Http/Controllers/Settings/DataSettingsController.php)
- [`app/Console/Commands/CleanUpDataExports.php`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/app/Console/Commands/CleanUpDataExports.php)

**Podstawa:** analiza kodu; bez kontrolowanej awarii kolejki w pełnej aplikacji.

`requestExport()` zatwierdza rekord `QUEUED`, po czym wywołuje `GenerateUserExport::dispatch()`. Następne zgłoszenie jest blokowane przez istniejący eksport `QUEUED` lub `PROCESSING`.

Awaria wysyłania albo przerwanie procesu w tym oknie może pozostawić rekord bez gwarantowanego wykonawcy. Użytkownik nie może zastąpić go kolejnym eksportem. Przejrzana komenda czyszcząca nie naprawia tego stanu; nie wykluczono istnienia każdego innego mechanizmu odzyskiwania w repozytorium.

**Naprawa:** transactional outbox lub równoważny trwały mechanizm dostarczenia, idempotentny worker i reconciler osieroconych zleceń. Samo `afterCommit()` nie usuwa okna awarii pomiędzy commitem a dostarczeniem zadania.

**Regresja:** po zasymulowaniu niedostępnej kolejki rekord zostaje odzyskany albo przechodzi do jawnego błędu pozwalającego na ponowienie; nie blokuje eksportu bezterminowo.

## A03 | P2 | Scheduler może uznać niezerowy kod komendy za sukces

**Miejsce:**
- [`routes/console.php`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/routes/console.php)

**Podstawa:** analiza i lokalna reprodukcja kontraktu callbacku, nie uruchomienie całego schedulera.

`Schedule::call(fn () => Artisan::call(...))` zwraca liczbowy kod komendy. Sprawdzony kontrakt `CallbackEvent` rozpoznaje jako niepowodzenie logiczne `false`, a nie dowolny niezerowy kod.

Test lokalny: wartości callbacku `0`, `1`, `2` dają kod zdarzenia `0`; `false` daje `1`. Wyjątki to osobna ścieżka: nie oznacza to ukrywania wszystkich rodzajów awarii.

**Naprawa:** wrapper zwracający `Artisan::call(...) === 0` lub kontrolowany wyjątek. Zachować wywołanie w bieżącym procesie, gdy hosting ogranicza `proc_open`; nie zastępować go bezrefleksyjnie poleceniem wymagającym procesu potomnego.

**Regresja:** komenda zwracająca `FAILURE` uruchamia obsługę niepowodzenia i alert. Dowód: `evidence/repro-callback-exit.json`.

## A04 | P2 | Niejasny kontrakt edycji publikacji przy publish=false

**Miejsce:**
- [`app/Domain/Recipes/Actions/PublishRecipe.php`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/app/Domain/Recipes/Actions/PublishRecipe.php)
- [`app/Domain/Recipes/RecipeStatusTransitions.php`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/app/Domain/Recipes/RecipeStatusTransitions.php)
- [`app/Http/Controllers/RecipeController.php`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/app/Http/Controllers/RecipeController.php)

**Podstawa:** ryzyko domenowe; osiągalność z HTTP wymaga potwierdzenia. Nie jest to potwierdzony błąd konkretnego przycisku.

W przejrzanej logice istniejący opublikowany przepis może zachować status publikacji przy `publish=false`, a zapis pól nie zostaje przez to automatycznie wyłączony. Snapshot zależy natomiast od `publish=true`. Możliwa jest więc rozbieżność pomiędzy publiczną zmianą a historią.

**Naprawa:** rozdzielić tworzenie szkicu, publikowanie i aktualizację publikacji. Publiczna zmiana powinna mieć jednoznaczną politykę wersjonowania. Szkic zmian nie powinien nadpisywać wersji publicznej.

**Regresja:** dla opublikowanego przepisu sprawdzić wynik `publish=false`: widoczność, treść, historię i indeks. Następnie zweryfikować walidator i pełne żądanie HTTP. Ustalenie jest powiązane z A01 i A05, nie należy przedstawiać go jako niezależnego exploitu.

## A05 | P2 | Snapshot nie obejmuje pełnego stanu przepisu

**Miejsce:**
- [`app/Domain/Recipes/Actions/SnapshotRecipeVersion.php`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/app/Domain/Recipes/Actions/SnapshotRecipeVersion.php)
- [`app/Domain/Recipes/Actions/PublishRecipe.php`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/app/Domain/Recipes/Actions/PublishRecipe.php)

**Podstawa:** potwierdzone pominięcia w projekcji; przywracania nie uruchamiano end-to-end.

Projekcja kroków nie zachowuje identyfikatorów zdjęć. Snapshot nie obejmuje też pełnego stanu obrazu głównego i pochodzenia przepisu; w projekcji składnika brakuje między innymi `no_amount`.

Historia może odtwarzać tekst, ale nie całe znaczenie i wygląd wersji. Dopisanie identyfikatora zdjęcia nie wystarczy, gdy plik może zostać usunięty przez inną ścieżkę sprzątającą.

**Naprawa:** wersjonowany schemat snapshotu, komplet istotnych pól i jawna polityka retencji mediów. Dokumentacja powinna precyzyjnie określać granice przywracania.

**Regresja:** zapisz -> zmień -> przywróć, z porównaniem wszystkich pól, kolejności, ilości nieokreślonej, fotografii i informacji źródłowych, nie tylko tytułu.

## A06 | P2 | GET wypisania z newslettera zmienia zgodę

**Miejsce:**
- [`app/Http/Controllers/PodsumowanieTygodniaController.php`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/app/Http/Controllers/PodsumowanieTygodniaController.php)

**Podstawa:** potwierdzony i jawnie opisany w komentarzu kompromis projektowy.

`wypisz()` zapisuje cofnięcie zgody przed rozgałęzieniem GET/POST. Samo otwarcie podpisanego linku może zatem wypisać odbiorcę. Autorzy kodu świadomie akceptują ryzyko skanerów i przewidują cofnięcie operacji.

To nie umożliwia wypisania dowolnego adresu bez prawidłowego linku. Problem polega na utożsamieniu pobrania URL z aktualnym zamiarem odbiorcy. Obsługa POST one-click istnieje; nie należy ogólnie nazywać całej implementacji niezgodną z RFC 8058.

**Naprawa:** GET jako strona potwierdzenia, POST jako idempotentne cofnięcie zgody; zachować podpisany one-click bez obowiązku logowania.

**Regresja:** GET automatu nie zmienia zgody; POST zmienia, a powtórzenie pozostaje bezpieczne.

## A07 | P2 | Stary link ponownego zapisu może odwrócić późniejsze wypisanie

**Miejsce:**
- [`app/Domain/Digest/OdnosnikWypisania.php`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/app/Domain/Digest/OdnosnikWypisania.php)
- [`app/Http/Controllers/PodsumowanieTygodniaController.php`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/app/Http/Controllers/PodsumowanieTygodniaController.php)

**Podstawa:** analiza podpisanych URL i obsługi zgody; konieczne posiadanie prawidłowego odnośnika.

`ponownegoZapisuDla()` używa `URL::signedRoute`, bez TTL, jednorazowego nonce i powiązania z aktualną wersją zgody. `wracam()` ponownie zapisuje zgodę.

Stary poprawnie podpisany link może więc zostać wykorzystany po kolejnym wypisaniu. Podpis gwarantuje integralność URL, nie aktualny zamiar odbiorcy. Bezterminowość wypisania może być uzasadniona, ale ponowny zapis powinien mieć odrębną politykę.

**Naprawa:** nowe, jednoznaczne potwierdzenie ponownego zapisu przez POST; krótki TTL i jednorazowy token związany z konkretnym zdarzeniem lub wersją zgody.

**Regresja:** po późniejszym cofnięciu zgody użycie starego linku cofającego wcześniejsze wypisanie nie reaktywuje newslettera.

## A08 | P2 | Cache PWA zatrzymuje stary manifest i stare zasoby

**Miejsce:**
- [`public/sw.js`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/public/sw.js)

**Podstawa:** kod i trzy lokalne sprawdzenia na atrapach sieci oraz CacheStorage; bez przeglądarkowego E2E.

Cache-first obejmuje manifest i ikony bez hasha, a nazwa cache to stałe `kuking-v1`. Test pobrał manifest A, zmienił odpowiedź serwera na B i ponownie dostał A bez zapytania do sieci. Dotyczy to wdrożenia bez zmiany klucza cache, nie każdej możliwej procedury aktualizacji.

Stare zasoby z hashem pozostają w tym samym cache. Aktywacja usuwa wszystkie inne cache originu, zamiast tylko starszych cache własnej aplikacji. Ten drugi efekt jest problemem przy współdzieleniu originu z innym użytkownikiem CacheStorage.

**Naprawa:** cache-first dla niezmiennych assetów z hashem; network-first lub stale-while-revalidate dla manifestu i ikon; wersjonowanie per build, limit retencji i usuwanie wyłącznie własnego prefiksu. Nie rozszerzać cache na prywatny HTML.

**Regresja:** A -> B, ograniczenie starych assetów i zachowanie niezwiązanego cache. Dowód: `evidence/repro-service-worker.json`.

## A09 | P2 | Paginacja komentarzy nie ogranicza liczby odpowiedzi

**Miejsce:**
- [`app/Http/Controllers/RecipeController.php`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/app/Http/Controllers/RecipeController.php)

**Podstawa:** potwierdzona nieograniczona relacja; skutki zależą od liczby danych.

Paginowane są komentarze główne, natomiast eager-load `replies` pobiera wszystkie widoczne odpowiedzi, ich autorów, avatary, zdjęcia i liczniki polubień. Jeden popularny wątek może zdominować odpowiedź mimo małej liczby komentarzy na stronie.

Nie dowodzi to awarii przy obecnym ruchu ani skutecznego ataku DoS. Dowodzi braku ograniczenia rozmiaru ważnej relacji.

**Naprawa:** ograniczony podgląd odpowiedzi i odrębna paginacja lub cursor wątku. Licznik wszystkich odpowiedzi nie powinien zależeć od liczby wczytanych rekordów. Zapewnić dostęp do dalszej dyskusji bez JavaScript.

**Regresja:** komentarz z 10 000 odpowiedzi; pierwsza strona ma ograniczony rozmiar HTML i liczbę modeli; kolejne strony nie gubią i nie dublują wpisów.

## A10 | P2 | Kontrola dostępu do obrazu powtarza kosztowne zapytania

**Miejsce:**
- [`app/Domain/Media/DostepDoZdjecia.php`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/app/Domain/Media/DostepDoZdjecia.php)
- [`app/Http/Controllers/MediaController.php`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/app/Http/Controllers/MediaController.php)

**Podstawa:** struktura wywołań, bez pomiaru produkcyjnego.

`rodzice()` wykonuje pięć zapytań o obiekty powiązane. Dla zalogowanego odbiorcy niebędącego właścicielem ani moderatorem ta droga może zostać wykonana dwukrotnie: dla jego dostępu, potem dla określenia publiczności odpowiedzi i polityki cache.

Potencjalnie daje to dziesięć zapytań o samych rodziców obrazu, przed dodatkowymi politykami. Nie jest to liczba uniwersalna dla gościa, właściciela i moderatora. Kontrola dostępu istnieje: to ustalenie wydajnościowe, nie dowód wycieku fotografii.

**Naprawa:** rodzice pobierani raz na żądanie; wspólny wynik `canView` i `isPublic`, ewentualnie leniwe `exists`. Nie wprowadzać globalnego cache uprawnień bez pełnego unieważniania.

**Regresja:** liczba zapytań i macierz gość / autor / moderator / blokada / treść prywatna; optymalizacja nie zmienia autoryzacji.

## A11 | P2 | Sprzątanie eksportów stale wraca do wyczyszczonych rekordów

**Miejsce:**
- [`app/Console/Commands/CleanUpDataExports.php`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/app/Console/Commands/CleanUpDataExports.php)

**Podstawa:** warunek zapytania i zapis stanu po czyszczeniu.

Komenda pobiera przez `get()` wszystkie przeterminowane rekordy `READY` lub `EXPIRED`. Udane czyszczenie pozostawia `EXPIRED` i zeruje klucz archiwum oraz metadane. Rekord nadal spełnia warunki następnego przebiegu.

Praca rośnie wraz z całą historią eksportów. Licznik czyszczonych pozycji może obejmować pliki usunięte już wcześniej. Zachowanie klucza po nieudanym usunięciu jest natomiast potrzebne do ponowienia i powinno zostać zachowane.

**Naprawa:** filtr rzeczywiście wymagających czyszczenia, np. `cleaned_at` lub obecny klucz; przetwarzanie porcjami. Oddzielić liczniki sprawdzenia, usunięcia i błędu.

**Regresja:** drugi przebieg nie dotyka poprawnie wyczyszczonych rekordów, ale ponawia usunięcie archiwum, które faktycznie pozostało.

## A12 | P2 | Testy race nie są blokującą bramką jakości

**Miejsce:**
- [`.github/workflows/ci.yml`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/.github/workflows/ci.yml)

**Podstawa:** potwierdzona, celowa konfiguracja; bez weryfikacji całej historii stabilizacji i ochrony gałęzi.

Job `race-regressions` ma `continue-on-error: true` i opis non-blocking. Używa PostgreSQL i grupy testów `race`. Komentarz przewiduje stabilizację, ale audyt nie ustalił, czy spełniono już warunek wyjścia z tego okresu.

Istnienie testów współbieżności nie oznacza egzekwowania ich wyniku. Jest to istotne przy integralności wersji i eksportach. Nie ustalono wszystkich wymaganych statusów ani reguł każdego wdrożenia.

**Naprawa:** stabilne regresje integralności danych uczynić blokującymi. Niestabilne przypadki poddać jawnej, terminowej kwarantannie, zamiast bezterminowo ignorować całą grupę.

**Regresja:** kontrolowany błąd stabilnego testu race daje negatywny wymagany check w rzeczywistej konfiguracji CI.

## A13 | P3 | README nie odpowiada rzeczywistemu trybowi CI

**Miejsce:**
- [`README.md`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/README.md)
- [`.github/workflows/ci.yml`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/.github/workflows/ci.yml)

**Podstawa:** rozbieżność dokumentacji i odczytanego uruchomienia.

README opisuje ograniczenie Actions do uruchamiania ręcznego, podczas gdy dla badanego commitu odczytano workflow uruchomiony po push. Opis etapu stabilizacji i sposobu weryfikacji zmian jest więc nieaktualny.

**Naprawa:** ujednolicić opis triggerów, wymaganych jobów, ograniczeń hostingu i wdrożeń. Statystyki testów generować albo datować, nie podawać jako bezterminowych deklaracji.

**Regresja:** aktualizacja workflow wymaga kontroli zgodności dokumentacji, nie wyłącznie jej formatowania.

## A14 | P3 | Sprzeczna deklaracja licencji projektu

**Miejsce:**
- [`composer.json`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/composer.json)
- [`LICENSE`](https://github.com/woogitsu/kuking.pl/blob/8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648/LICENSE)

**Podstawa:** metadane; bez rozstrzygania kwestii prawnych.

`composer.json` deklaruje `MIT`, natomiast `LICENSE` zawiera zastrzeżenie praw i wymóg zgody. To dwie sprzeczne informacje dla współpracowników oraz narzędzi analizujących projekt.

**Naprawa:** ujednolicić zgodnie z decyzją właściciela. Dla projektu zamkniętego zastosować odpowiednią deklarację `proprietary`. Licencja frameworka nie oznacza automatycznie tej samej licencji całej aplikacji.

**Regresja:** kontrola spójności pliku licencji, metadanych i dokumentacji oraz `composer validate`. Nie jest to opinia prawna ani zarzut naruszenia cudzych praw.

## A15 | P3 | 23 ostrzeżenia częściowego zasłaniania fokusu

**Miejsce:**
- `evidence/dostepnosc.json`

**Podstawa:** odczyt rzeczywistego artefaktu CI, nie ręczna certyfikacja WCAG.

`evidence/dostepnosc.json` raportuje 23 ostrzeżenia `focusNotObscured`, przy zerowej liczbie naruszeń w tej sekcji. Nakładki `.topbar` i `.bottom-nav` zasłaniają fragmenty kontrolek m.in. na tablicy, stronie wpisu i w ustawieniach profilu. Raportowane pokrycie wynosi 0,25 lub 0,5.

Nie są to automatycznie 23 naruszenia poziomu AA: częściowe zasłonięcie należy odróżnić od całkowitego. Jest to jednak konkretna lista miejsc do poprawy przy małym ekranie i powiększonym tekście.

**Naprawa:** przestrzeń przewijania oraz `scroll-margin` i `scroll-padding` zgodne z rzeczywistą wysokością pasków. Sprawdzać przewijanie do fokusu, nie tylko statyczny viewport.

**Regresja:** klawiatura na szerokościach 320, 360 i 414 px, tekst 140% i powiększenie przeglądarki 200%; aktywne pola i linki pozostają widoczne.

## Wyniki wykonania i odczytane dowody

### Lokalne reprodukcje

`node repro/test-service-worker.cjs` sprawdza trzy zachowania service workera na atrapach CacheStorage i sieci. Nie bada pełnego cyklu aktualizacji w rzeczywistej przeglądarce.

`php repro/test-callback-exit.php` sprawdza kontrakt interpretacji wyniku callbacku. Nie uruchamia frameworka: nie jest testem integracyjnym całego schedulera.

### CI

Odczytano zakończone sukcesem uruchomienie `34569819251` dla badanego commitu. Zielony wynik nie dowodzi pokrycia wszystkich scenariuszy awarii, szczególnie przy nieblokującej grupie race.

Poniższe dane pochodzą bezpośrednio z dołączonych JSON. Są wynikami laboratoryjnymi CI, a nie pomiarami rzeczywistych użytkowników produkcji.

| Ścieżka | Performance | SEO | LCP |
|---|---:|---:|---|
| `/` | 94 | 100 | 2.5 s |
| `/odkryj` | 94 | 100 | 2.6 s |
| `/login` | 96 | 58 | 2.3 s |
| `/register` | 95 | 100 | 2.4 s |
| `/przepisy/rosol-babci-zofii` | 96 | 100 | 2.4 s |
| `/@basia` | 94 | 100 | 2.5 s |
| `/tag/zupy` | 96 | 100 | 2.3 s |
| `/regulamin` | 95 | 100 | 2.4 s |

Raport wydajności: 8 ekranów i 0 niezaliczonych. Wynik SEO logowania jest wyłączony z bramki; samo `noindex` takiej strony nie jest błędem.

Raport dostępności: 39 zbadanych ekranów, 0 raportowanych naruszeń i 0 blokujących. Kontrola układu obejmuje 44 ekranów i wykazuje 0 przepełnień. Sekcja fokusu zawiera 23 ostrzeżenia. Liczby różnych sekcji nie są liczbą unikalnych stron i nie powinny być sumowane.

## Kolejność napraw

1. **Integralność i odzyskiwanie:** A01-A02; równolegle sygnalizacja awarii A03 i egzekwowanie stabilnych testów A12.
2. **Zgody oraz historia:** A06-A07; rozstrzygnąć kontrakt A04 i kompletność A05.
3. **Aktualizacje oraz skalowanie:** A08-A11, z pomiarami na dużych danych.
4. **Spójność i dostępność:** A13-A15.

Każda poprawka powinna otrzymać test odtwarzający problem. W optymalizacjach mediów zachować macierz prywatności. Dla zadań dodać jawne stany błędu, odzyskiwanie i metryki, nie tylko komunikat w interfejsie.

## Pochodzenie źródeł

Odnośniki przy ustaleniach są przypięte do pełnego SHA i wymagają uprawnień do prywatnego repozytorium.

- CI: https://github.com/woogitsu/kuking.pl/actions/runs/34569819251
- Laravel CallbackEvent: https://github.com/laravel/framework/blob/13.x/src/Illuminate/Console/Scheduling/CallbackEvent.php
- RFC 8058: https://www.rfc-editor.org/rfc/rfc8058
- `evidence/wydajnosc.json` oraz `evidence/dostepnosc.json`: odczytane artefakty CI.
- `evidence/repro-*.json`: wyniki lokalnych testów izolowanych.

Link frameworka wskazuje ruchomą gałąź 13.x, a nie dodatkowo przypięty commit zależności; paczka zawiera sprawdzany kontrakt. Dowody projektu odnoszą się do wskazanego SHA, nie do przyszłych zmian na main.

## Paczka i uruchomienie

Paczka zawiera raport, indeks ustaleń JSON, artefakty CI, skrypty reprodukcji, lokalne wyniki i sumy SHA-256. Nie zawiera pełnego checkoutu, sekretów, danych produkcji ani logu nieudanego klonowania.

Po rozpakowaniu, z katalogu paczki:

```sh
node repro/test-service-worker.cjs
php repro/test-callback-exit.php
```

Skrypty nie łączą się z produkcją i nie zastępują testów integracyjnych wskazanych przy ustaleniach.
