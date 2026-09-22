# Kuking.pl — audyt wielodyscyplinarny GPT

**Data:** 8 września 2026. **Badany kod:** `2fe302b6534d4d233a45271769a5d0c62f83bbe7`. Ocena dotyczy tej migawki, nie nieznanej konfiguracji produkcji. **Werdykt:** nie otwierać szerzej przed zamknięciem trzech bramek poniżej; nie potrzeba następnego modułu produktu ani zmiany stosu.

## 1. Pierwsza strona: dziesięć rzeczy w kolejności wykonania

1. **G01:** ukryj wykonanie i jego zdjęcia przed obcymi na czas usuwania konta — dziś lista je ukrywa, ale bezpośredni adres nadal oddaje notatkę.
2. **G02:** zamknij właścicielską bramkę rzeczywistych danych: tożsamość administratora, dostawcy i regiony, prywatność bucketów, granica proxy, działający reset hasła oraz konto mogące rozpatrywać odwołania.
3. **G03:** odtwórz rzeczywistą kopię w odizolowanym środowisku i zapisz wynik — instrukcja już istnieje, dowodu wykonania nie otrzymałem.
4. **G04:** połącz zapis „Ugotowałem” i powiadomienia jedną transakcją — po zasymulowanej awarii ponowienie zostawia autora bez wiadomości.
5. **G05:** usuń relacje i sekrety 2FA przy wymazaniu konta — obecna anonimizacja ich nie dotyka.
6. **G06:** zachowaj poprawne dane po odmowie limitera i przestań obiecywać, że nic nie przepadło, gdy serwer niczego nie zachował.
7. **G07:** dołóż jeden dzienny sygnał wizyty i jeden raport, aby decyzja o V1 opierała się na prawdziwym D30, nie na formule mogącej dać 200%.
8. **G09:** sprawdź, czy kontrolowany błąd dociera do właściciela — sam zielony `/health` i log nie są powiadomieniem o awarii.
9. **G08:** wycofaj aktywną obietnicę tygodniowego e-maila do czasu istnienia jego wysyłki, zamiast uznawać sam wybór operatora poczty za gotowy digest.
10. **Produkt:** przeprowadź pilotaż dwudziestu osób wokół wzajemnego gotowania i odpowiedzi gospodarza, wykorzystując istniejące ekrany, zamiast budować planner, grupy albo forki przed pomiarem powrotów.

Pierwsze trzy pozycje są bramkami dopuszczenia realnych danych do kontrolowanej alfy, nie trzema odkrytymi lukami aplikacji: G02 i G03 są znanymi, nadal nieudokumentowanymi warunkami operacyjnymi. Publiczna beta wymaga również badań z ludźmi opisanych w `docs/UX_50_PLUS.md:145–164`.

## 2. Tabela wszystkich znalezisk

**ZMIERZONE** oznacza odtworzenie zachowania albo bezpośredni odczyt wskazanego artefaktu; zawsze dopisuję, który rodzaj. **WYWNIOSKOWANE** oznacza wniosek z kodu bez odtworzonego skutku. **NIEROZSTRZYGNIĘTE** oznacza brak dostępu lub dowodu, nie stwierdzenie, że konfiguracja jest błędna. Godziny są szacunkiem pracy nad najmniejszą poprawką wraz z testem, nie wynikiem pomiaru czasu implementacji.

| ID | Waga | Dyscyplina | Jedno zdanie | Koszt |
|---|---|---|---|---|
| G01 | P0 | Prywatność / kod | Wykonanie konta w karencji jest publiczne pod bezpośrednim adresem mimo ukrycia na listach. | 3–5 h |
| G02 | P0, znana bramka | Prawo / infrastruktura | Repozytorium nie zamyka tożsamości administratora ani produkcyjnej granicy danych i obsługi kont. | 4–8 h właściciela, plus ewentualna konsultacja prawna |
| G03 | P0, znana bramka | Odporność | Instrukcja restore istnieje, ale nie ma udostępnionego wyniku odtworzenia rzeczywistej kopii. | 2–4 h |
| G04 | P1 | Kod / pętla produktu | Awaria zapisu powiadomienia trwale rozdziela wykonanie od wiadomości do autora. | 3–6 h |
| G05 | P1 | Dane / RODO | Wymazanie konta zostawia obserwowania, blokady i sekrety 2FA. | 2–4 h |
| G06 | P1 | UX 50+ / odporność | Odpowiedź 429 nie zachowuje poprawnego tekstu i zdjęcia, choć mówi, że nic nie przepadło. | 4–8 h |
| G07 | P1 | Mierzalność | WAC istnieje, ale D30 wizyt nie; dokumentowana formuła kohort myli retencję z liczbą aktywnych. | 8–12 h |
| G08 | P1 | Produkt / treść | Ustawienia pozwalają zamówić tygodniowy e-mail bez implementacji jego wysyłania. | 0,5–1 h za uczciwe wycofanie obietnicy |
| G09 | P1, znane | Infrastruktura | W repozytorium brak kompletnego kanału alarmowania o błędach, a zewnętrznego nie zweryfikowano. | 2–4 h |
| G10 | P2 | Zapytania | Dwie kolejki moderacyjne nadal sortują strony wyłącznie po sekundowym czasie; duplikatów w próbie nie odtworzono. | 1–2 h |
| G11 | P2 | Testy | Jeden nazwany test dostępu do zdjęć przeżywa otwarcie dostępu wszystkim, choć inny zestaw tę mutację zabija. | 0,5–1 h |
| G12 | P2 | Autoryzacja | Bezpośrednie wywołanie akcji domenowej zapisuje wykonanie prywatnego przepisu mimo odmowy Policy. | 1–2 h |
| G13 | P2 | Schemat / dokumentacja | `DATABASE.md` myli obecny klucz `collection_items` i okres retencji powiadomień. | 1–2 h |
| G14 | P2 | Instrukcje wdrożenia | Wycofana instrukcja publicznego CDN pozostawiła sprzeczne odwołania w runbooku. | 1–2 h |
| G15 | P2 | Zarządzanie pracą | Lista „do założenia” przedstawia istniejące zabezpieczenia jako brakujące. | 1–2 h |
| G16 | P2 | Zakres produktu | Cztery istniejące funkcje pozostają opisane jako V1 bez jednoznacznego uporządkowania zakresu. | 0,5–1 h |
| G17 | P2 | Prawo / decyzje | Uzasadnienie D-038 pomija wyjątek DSA dla mikro- i małych przedsiębiorstw. | 0,5–1 h na korektę zakresu i uzasadnienia |

**Łącznie: 17 pozycji — 3 P0, 6 P1 i 8 P2.** Nie sumuję godzin jako terminu projektu: czynności właścicielskie i konsultacja prawna mają inne zależności niż poprawki kodu.

## 3. Co rzeczywiście uruchomiono

### 3.1. Punkt odniesienia i dowody

Lokalny `git clone` nie przeszedł z powodu niedziałającego DNS. Nie przedstawiam lokalnego `composer install` ani lokalnego PHP jako udanego środowiska testowego. Pełną migawkę, instalację zależności i wykonanie pomiarów uzyskano przez izolowane GitHub Actions, na osobnej gałęzi audytowej. Każdy job miał własny PostgreSQL; testy w jobie wykonywano sekwencyjnie.

| Pomiar | Wynik | Trwały identyfikator przebiegu |
|---|---|---|
| Instalacja, build, świeża migracja, rollback, pełny zestaw | **1721 testów, 56 687 asercji, 171,43 s, exit 0**; Pint i PHPStan bez błędów; PostgreSQL **18.6**, PHP 8.4 | [B0: run 34272363472](https://github.com/woogitsu/kuking.pl/actions/runs/34272363472), commit `855ce7fc45a15aa057805ee9736876d8612838eb` |
| Nowe sondy niezmienników i mutacje | Zdrowa kontrola 1/1; sześć celowo postawionych oczekiwań ujawniło naruszenia; pliki po mutacjach przywrócone bajt w bajt | [B1: run 34273395722](https://github.com/woogitsu/kuking.pl/actions/runs/34273395722), commit `4dd5309225f2ca7394605b61f26ffa6ff8d01bd5` |
| Przeglądarka, CSS, powrót autora, paginacja | Ścieżki przeszły; skala działa; w próbie paginacji brak duplikatów | [B2: run 34274108261](https://github.com/woogitsu/kuking.pl/actions/runs/34274108261), commit `9f7937292480c51dad0e8499fde01519acabb0e2` |

Do raportu dołączono paczkę **`AUDYT_GPT_2026-09_DOWODY.zip`**: katalogi `baseline/`, `probes/`, `final-probes/`, logi, JUnit, schemat z `psql`, JSON pomiarów, źródła sond i zrzuty ekranu. Źródła sond są również dostępne w historii pod commitami B1/B2; nie są poprawkami aplikacji. Artefakty Actions mają krótką retencję, dlatego same odnośniki do runów nie zastępują paczki.

Polecenia kontrolne: `composer install --no-interaction --prefer-dist`, `npm ci`, `npm run build`, `php artisan migrate --force`, `psql -c '\d+ public.*'`, `pg_dump --schema-only --no-owner --no-privileges`, `php artisan migrate:refresh --force`, `php artisan test --log-junit=...`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`. Migracje i rollback wykonywano **wyłącznie w jednorazowej bazie CI**.

Własny workflow miał na początku błąd kontekstu zmiennej portu; pierwsza wersja sondy przeglądarkowej szukała `value=1.4` zamiast `140`. Oba błędy narzędzia poprawiono i powtórzono pomiar. Nie są znaleziskami w Kukingu. Zielony status joba sond oznacza zachowanie dowodów, a nie zielone wszystkie oczekiwania: właściwy wynik jest w `*.exit`, JUnit i logach.

### 3.2. Testy zepsute naprawdę, nie ocenione po nazwie

| Zmiana wykonana w izolowanym checkoucie | Test przed → po mutacji → po przywróceniu | Wniosek |
|---|---|---|
| Dopisanie `status` i `role` do `User::$fillable` | `SecurityTest`: zielony → **czerwony** → zielony | Dzisiejszy test wykrywa naruszenie. |
| `return true` na wejściu `DostepDoZdjecia::moze()` | `DostepDoZdjeciaBezPolicyRodzicaTest`: zielony → **zielony** → zielony | Ten konkretny test nie dowodzi kontroli dostępu. |
| Ta sama mutacja zdjęć | `ZdjeciaChronioneNieWyciekajaTest`: zielony → **czerwony** → zielony | Nie wolno z poprzedniego wiersza wyprowadzić braku ochrony całego zestawu. |
| Usunięcie wyjątku `_csp` z konfiguracji CSRF | `PolitykaBezpieczenstwaTest`: zielony → **czerwony** → zielony | Zarzut o niewykrywaniu tej regresji jest nieaktualny. |

Dowód: `probes/mutations.json`, osobne logi `before`, `mutated`, `after`, poprawna składnia mutantów, sumy SHA-256 i pusty `production-diff.txt`. Nie mutowano wszystkich 1721 testów; zielony baseline nie jest dowodem ich kompletności.

## 4. Pełne opisy P0 i P1

### G01 · P0 · Prywatność / kod

**Co jest nie tak:** wykonanie osoby w stanie `pending_delete` pozostaje dostępne anonimowo pod bezpośrednim adresem, mimo wycofania go z list.

**Dowód — ZMIERZONE uruchomieniem:** `app/Policies/CookedEventPolicy.php:18–97` nie odcina tego statusu; `app/Models/CookedEvent.php` robi to w `scopeWidoczneDla`. `resources/views/pages/settings/data.blade.php:52–58` obiecuje natychmiastowe zniknięcie konta i treści, a `EraseAccountData.php:153–166` opisuje karencję jako stan schowanych treści. B1, sonda `test_pending_delete_nie_pozostawia_wykonania_i_zdjecia_publicznego`: lista **false**, anonimowy GET **200**, notatka w HTML **true**, `DostepDoZdjecia::moze(null, $media)` **true**. Ostatnia wartość jest pomiarem autoryzacji zdjęcia, nie osobnym pobraniem jego bajtów z produkcyjnego R2.

**Jak odtworzyć:** A publikuje przepis; B dodaje wykonanie ze zdjęciem; zapamiętaj adres wykonania; B zgłasza usunięcie konta; otwórz adres bez logowania i porównaj z galerią wykonań pod przepisem. Polecenie sondy: `php artisan test audit-probes/AuditProbeTest.php --filter=pending_delete`.

**Skutek dla człowieka:** osoba wycofująca konto ma podstawy sądzić, że treść zniknęła, ale posiadacz starego odnośnika nadal czyta notatkę i może przejść kontrolę dostępu do zdjęcia.

**Propozycja:** odciąć cudzy dostęp do wykonania i mediów w czasie `pending_delete`, z testem GET/lista/media oraz odzyskania konta. **Nie zmieniać przy okazji** świadomej reguły dla kont zbanowanych ani dostępności zanonimizowanych tekstów po `erased`: to odrębne przypadki, a D-018/D-022 mają zostać zachowane. Komentarz w Policy rozszerza doktrynę bana na karencję; właśnie to rozszerzenie jest sprzeczne z komunikatem dla usuwającego konto.

**Koszt:** 3–5 h. **Ryzyko niezrobienia:** dalsze udostępnianie osobistej treści wbrew przedstawionej użytkownikowi obietnicy. **Kto może to zrobić:** repozytorium; mały PR z regresją, bez zmiany D-022.

### G02 · P0, znana bramka · Prawo / granica produkcji

**Co jest nie tak:** repozytorium nadal nie daje podstaw do stwierdzenia, że można bezpiecznie rozpocząć przetwarzanie danych pierwszych realnych osób.

**Dowód — ZMIERZONE odczytem / NIEROZSTRZYGNIĘTE operacyjnie:** `resources/legal/polityka-prywatnosci.md:15–17` odkłada ujawnienie administratora do otwarcia rejestracji „dla wszystkich”; `:44–51` deklaruje regiony, brak wybranego operatora poczty i niezamkniętą kwestię powierzenia. `docs/legal/BRAMKA_BETY.md:56–57` pozostawia warunkowe bramki proxy i storage. `docs/DECISIONS.md:1743–1757` wymaga rzeczywistego nadania roli administratora po wdrożeniu. Nie uzyskano odczytu paneli ani potwierdzeń tych czynności. RODO art. 13 wymaga informacji przy pozyskaniu danych — zamknięte zaproszenia nie są wyjątkiem [S9].

**Jak odtworzyć / zamknąć bramkę:** przeczytać te fragmenty i wykonać protokół dopuszczenia na rzeczywistej konfiguracji: wskazana osoba i kanał kontaktu; dowód warunków powierzenia oraz regionów zgodnych z polityką; brak anonimowego dostępu do oryginału, wariantu i eksportu przez adres bucketu; brak obejścia zaufania proxy przez bezpośrednią domenę Railway; działający reset hasła na skrzynce zewnętrznej; decyzja moderacyjna i odwołanie obsłużone przez właściwe konto. Nie wypisywać sekretów w protokole. Testy aplikacji nie zastępują żadnego z tych dowodów.

**Skutek dla człowieka:** użytkownik nie zna odpowiedzialnego administratora albo nie ma działającej drogi odzyskania konta i odwołania; niewłaściwy bucket może ominąć poprawne Policy.

**Propozycja:** jeden podpisany datą protokół właścicielski powiązany z istniejącą bramką, bez nowego panelu. Brak odręcznego podpisu pod DPA nie dowodzi braku skutecznej umowy — trzeba sprawdzić standardowe warunki konkretnych dostawców, zamiast przepisywać niezweryfikowane zdanie. Poczta jest gotowa do konfiguracji; nie zgłaszam ponownie #136 jako braku kodu.

**Koszt:** 4–8 h właściciela plus ewentualna konsultacja. **Ryzyko niezrobienia:** przetwarzanie realnych danych przy niezamkniętej granicy prawnej i technicznej. **Kto może to zrobić:** decyzja właściciela oraz panele Railway/Cloudflare/operatora poczty; repozytorium tylko odzwierciedla ustalone fakty.

### G03 · P0, znana bramka · Odporność

**Co jest nie tak:** nie ma udostępnionego dowodu, że odtworzono rzeczywistą kopię wraz z działającą aplikacją i zdjęciami.

**Dowód — ZMIERZONE odczytem / NIEROZSTRZYGNIĘTE wykonanie:** `docs/infra/DEPLOYMENT_RUNBOOK.md:1019–1060` zawiera pełny rozdział restore i pustą tabelę wyniku `:1056–1058`; `docs/ROADMAP.md:103–108` wymaga przetestowanego restore. Teza „w docs/infra nie ma ani słowa” jest **obalona**. Nie jest natomiast ustalone, czy ktoś wykonał próbę poza repozytorium. Udane migracje i rollback B0 nie są odtworzeniem kopii.

**Jak odtworzyć / zamknąć bramkę:** pobrać kopię z faktycznie używanego mechanizmu backupu, odtworzyć do odizolowanego środowiska, sprawdzić dokładne liczby kontrolnych rekordów i powiązań, otworzyć reprezentatywne zdjęcie przez aplikację, sprawdzić logowanie i eksport; zapisać wiek kopii oraz czas do działającej usługi. Nie odtwarzać na produkcyjnej bazie. Nie ograniczać się do świeżego dumpa pustej bazy.

**Skutek dla człowieka:** po awarii mogą zniknąć rodzinne zdjęcia i przepisy; właściciel dowie się wtedy, że „backup skonfigurowany” nie oznacza odzyskiwalności.

**Propozycja:** wykonać i uzupełnić istniejącą procedurę, zamiast pisać nową. Zastąpić jej pozorną kontrolę liczby wierszy przez dokładny pomiar: `:1045–1047` korzysta z `pg_stat_user_tables.n_live_tup`, czyli statystyki, nie równoważnika `COUNT(*)`. Czas samego `pg_restore` nie jest całym RTO usługi. Osobno uwzględnić storage mediów i niezbędne sekrety odzyskania, przechowywane poza raportem.

**Koszt:** 2–4 h na pierwszą próbę. **Ryzyko niezrobienia:** nieznana możliwość i czas odzyskania danych. **Kto może to zrobić:** panel Railway/Cloudflare i właściciel; wynik do repozytorium.

### G04 · P1 · Kod / pętla „Ugotowałem”

**Co jest nie tak:** trwały zapis wykonania i zapis wiadomości do autora nie są atomowe, a ścieżka ponowienia kończy się przed odtworzeniem brakującej wiadomości.

**Dowód — ZMIERZONE uruchomieniem:** `app/Domain/Recipes/Actions/RecordCookedEvent.php:83–110` kończy transakcję, `:138–149` dopiero powiadamia, `:123–129` zwraca istniejące wykonanie po kolizji klucza. W B1 `cooked_healthy_control` daje **1 wykonanie / 1 wiadomość**. Jednorazowy wyjątek tuż przed INSERT do `notifications`, a potem ponowienie z tym samym kluczem daje **1 wykonanie / 0 wiadomości**. Wyjątek wstrzyknięto przed SQL, więc wynik nie jest artefaktem zatrutej transakcji PostgreSQL.

**Jak odtworzyć:** uruchomić `php artisan test audit-probes/AuditProbeTest.php --filter=powtorzenie_po_awarii`; sonda uzbraja błąd tylko na pierwszy zapis powiadomienia, po czym go wyłącza i ponawia tę samą akcję.

**Skutek dla człowieka:** kucharz wykonał najważniejszą akcję w produkcie, ale autor nie ma powodu wrócić; ponowne kliknięcie nie pomaga.

**Propozycja:** zapisywać wykonanie i jego powiadomienie bazodanowe w tej samej transakcji, zachowując idempotencję jednego wysłania; powtórne świadome ugotowanie z nowym kluczem nadal musi tworzyć nowy wpis. Nie potrzeba kolejnego brokera ani mikroserwisu. W tej samej analizie klasy błędu sprawdzić `PublishComment.php:87–120`, gdzie komentarz również poprzedza powiadomienie; jego skutków awarii nie odtwarzałem, więc nie liczę jako drugiej potwierdzonej usterki.

**Koszt:** 3–6 h. **Ryzyko niezrobienia:** okazjonalne, trwałe przerwanie głównej pętli bez widocznego sposobu naprawy. **Kto może to zrobić:** repozytorium, jeden PR z testem awarii i ponowienia.

### G05 · P1 · Dane / RODO

**Co jest nie tak:** końcowe wymazanie konta nie usuwa jego relacji społecznych ani sekretów drugiego składnika logowania.

**Dowód — ZMIERZONE uruchomieniem:** `app/Domain/Users/Actions/EraseAccountData.php:130–167` anonimizuje profil i część pól użytkownika, ale nie czyści wskazanych danych. Polityka `resources/legal/polityka-prywatnosci.md:26` obiecuje koniec retencji relacji wraz z ich usunięciem lub usunięciem konta. B1 `account_erasure_residue`: **status erased, follows=1, blocks=1, sekret 2FA obecny, kody zapasowe obecne**, także przy zakresie `everything`.

**Jak odtworzyć:** utworzyć obserwowanie i blokadę dwóch różnych kont, skonfigurować 2FA, zgłosić usunięcie wszystkiego, wykonać `EraseAccountData`, odczytać relacje i pola z bazy; sonda `--filter=usuniecie_konta_usuwa_relacje` robi to automatycznie.

**Skutek dla człowieka:** w bazie pozostaje niepotrzebna historia relacji i materiał uwierzytelniający konta, które miało zostać wymazane. To **nie jest** dowód możliwości zalogowania się usuniętym kontem; sekret jest przechowywany przez szyfrujący cast.

**Propozycja:** w istniejącej transakcji usunąć relacje w obie strony i wyzerować dane 2FA; sprawdzić także analogiczne relacje preferencji. Test ma odczytać stan po wymazaniu, nie tylko sprawdzić zmianę e-maila. Zachować uzasadnione wyjątki retencji dokumentacji moderacyjnej, nie kasować bezmyślnie całego audytu.

**Koszt:** 2–4 h. **Ryzyko niezrobienia:** obietnica retencji pozostaje nieprawdziwa, a potencjalny incydent obejmuje więcej danych, niż potrzeba. **Kto może to zrobić:** repozytorium.

### G06 · P1 · UX 50+ / odporność formularzy

**Co jest nie tak:** poprawny formularz odrzucony limitem 429 nie dostaje serwerowej ścieżki odzyskania, a komunikat twierdzi, że nic nie przepadło.

**Dowód — ZMIERZONE uruchomieniem:** `bootstrap/app.php:209–228` obsługuje limit bez zachowania formularza; `resources/views/errors/429.blade.php:35–42` zapewnia o braku straty i odsyła na stronę główną. B1 `throttle_preserves_input`: **HTTP 429, old_body=null, tekstu brak w odpowiedzi, 0 zapisanych zdjęć i 0 wpisów z tekstem sondy**.

**Jak odtworzyć:** wypełnić limit `posts.store` z konfiguracji, wysłać kolejny prawidłowy formularz ze zdjęciem i unikalnym tekstem, sprawdzić odpowiedź oraz dane sesji; `--filter=429_przechowuje`. Nie twierdzę, że każdy przycisk „Wstecz” w każdej przeglądarce wyczyści tekst — stwierdzam brak gwarantowanego odzyskania po stronie aplikacji, szczególnie pliku.

**Skutek dla człowieka:** po pracy nad opisem lub wyborze zdjęcia dostaje uspokajające zdanie, którego system nie umie dotrzymać; ponowne wejście do formularza nie ma skąd odtworzyć danych.

**Propozycja:** wykorzystać istniejącą białą listę odzyskiwalnych danych dla tras treści, zachować bezpieczny tekst i zapewnić jawny powrót do formularza; obsługę pliku oprzeć na ograniczonym mechanizmie tymczasowym albo powiedzieć wprost o konieczności ponownego wyboru. Nie zapisywać haseł, kodów 2FA ani nie obchodzić limitów przez nieograniczone odkładanie uploadów.

**Koszt:** 4–8 h. **Ryzyko niezrobienia:** strata pracy i zaufania akurat podczas odrzucenia, które użytkownik może potraktować jako własny błąd. **Kto może to zrobić:** repozytorium.

### G07 · P1 · Mierzalność

**Co jest nie tak:** warunek V1 nie ma miernika powrotu D30, a wskazana w dokumentacji metoda procentowania tygodniowej aktywności daje błędny mianownik.

**Dowód — ZMIERZONE odczytem i uruchomieniem:** `docs/ROADMAP.md:110–111` wiąże V1 z WAC i D30. **WAC już istnieje**: `app/Console/Commands/ReportWeeklyActiveCooks.php:23–35` i `WeeklyActiveCooks`. `CookRetentionCohorts.php:23–26` zaleca dzielenie liczby aktywnych w tygodniu 4 przez aktywnych w tygodniu 0, choć zapytanie `:49–75` nie wymaga, by byli to ci sami ludzie. B1: trzech zarejestrowanych, jeden aktywny w tygodniu 0, dwóch innych w tygodniu 4 — dokumentowana formuła daje **200%**. Klasa nie renderuje takiego procentu w gotowym raporcie; błędna jest instrukcja interpretacji wyników. `ZapiszSygnal.php:15–19` zbiera dwa rodzaje zdarzeń, a odczyt ich retencji nie jest raportem produktowym.

**Jak odtworzyć:** `--filter=retencja_wedlug_zadeklarowanego_wzoru`; porównać wynik z trzema osobami kohorty. Osobno `rg -n 'ProductSignal|product_signals' app routes` i odczyt świeżego schematu pokazują brak dziennej historii wizyt.

**Skutek dla właściciela:** może dopisać V1 na podstawie liczby publikacji lub wzrostu aktywności, nie wiedząc, czy pierwsze osoby w ogóle wracają.

**Propozycja:** jeden dzienny sygnał obecności i jedna komenda opisana w §5.3; istniejący WAC pozostaje, a komentarza „D30-ish” nie wolno traktować jako D30 wizyt.

**Koszt:** 8–12 h z migracją, regresją, `DATABASE.md` i rollbackiem. **Ryzyko niezrobienia:** bramka V1 pozostanie hasłem, które da się interpretować na dowolny sposób. **Kto może to zrobić:** repozytorium; właściciel zatwierdza definicję sukcesu, zanim zobaczy wynik.

### G08 · P1 · Produkt / obietnica e-maila

**Co jest nie tak:** można włączyć cotygodniowy przegląd, którego kod nie wysyła.

**Dowód — ZMIERZONE odczytem:** `resources/views/pages/settings/privacy.blade.php:6–10` obiecuje jeden e-mail tygodniowo z wykonaniami i wydarzeniami; `PrivacySettingsController.php:24–29` zapisuje preferencję. Wyszukanie `wants_weekly_digest` w `app`, `routes`, `config` prowadzi do modelu, ustawień, eksportu i usuwania konta, nie do zadania wysyłki. W harmonogramie nie ma digestu. Brak wybranego operatora poczty jest problemem osobnym i znanym.

**Jak odtworzyć:** zapisać preferencję na `/ustawienia/prywatnosc`, sprawdzić flagę, przejść wszystkie jej odwołania i harmonogram; nie ma wywoływanego procesu, który skonsumuje tę zgodę i zbuduje przegląd.

**Skutek dla człowieka:** czeka na zapowiedziany powód powrotu, a ciszę interpretuje jako brak wydarzeń lub martwy serwis.

**Propozycja:** do czasu rzeczywistej wysyłki ukryć aktywny przełącznik albo uczciwie oznaczyć nieaktywną funkcję, bez kasowania istniejących preferencji. Nie budować teraz platformy marketingowej i nie przedstawiać konfiguracji SMTP jako naprawy tego problemu.

**Koszt:** 0,5–1 h za korektę obietnicy z testem widoku. **Ryzyko niezrobienia:** system milczy tam, gdzie obiecał odezwać się sam. **Kto może to zrobić:** repozytorium; decyzja właściciela, czy wycofuje obietnicę, czy osobno zleca digest.

### G09 · P1, znane · Monitoring błędów

**Co jest nie tak:** nie wykazano działającego kanału, którym właściciel dowie się o błędzie bez zaglądania do logów.

**Dowód — ZMIERZONE odczytem / NIEROZSTRZYGNIĘTE zewnętrznie:** sekcja `require` w `composer.json` nie zawiera klienta zewnętrznego monitoringu; konfiguracja wyjątków w `bootstrap/app.php` nie tworzy kompletnego kanału alarmowego. `resources/legal/polityka-prywatnosci.md:31,47` mówi wprost o braku zewnętrznej usługi. Nie odczytano alertów panelu Railway ani niezależnego monitoringu; brak pakietu Composer sam w sobie nie dowodzi ich nieistnienia.

**Jak odtworzyć / zamknąć:** w stagingu wywołać kontrolowany 500 i błąd zadania, ustalić, gdzie pojawi się powiadomienie oraz po jakim czasie; sprawdzić też brak działania całej aplikacji, gdy jej własna obsługa wyjątku nie może już wysłać wiadomości.

**Skutek dla człowieka:** pierwsza osoba zgłaszająca nieudany upload staje się monitoringiem serwisu; właściciel nie odróżnia braku aktywności od awarii.

**Propozycja:** jeden kanał alertów o wyjątkach i nieudanych zadaniach oraz niezależna kontrola dostępności; wybrać najprostsze rozwiązanie dostępne w obecnej infrastrukturze. Bez treści formularzy, prywatnych zdjęć, sekretów i pełnych danych żądania w raportach błędów. Dodanie zewnętrznego odbiorcy wymaga aktualizacji informacji o danych, nie tylko instalacji SDK.

**Koszt:** 2–4 h. **Ryzyko niezrobienia:** długie, ciche awarie podważające zaufanie niewielkiej pierwszej społeczności. **Kto może to zrobić:** repozytorium i panel Railway; właściciel wskazuje odbiorcę i wykonuje próbę alarmu.

## 5. Dane, ścieżki 50+ i najmniejszy pomiar produktu

### 5.1. Schemat i bezpieczeństwo: czego nie trzeba wymyślać ponownie

**ZMIERZONE:** świeży PostgreSQL 18.6 ma 40 tabel, 62 ograniczenia CHECK i 67 jawnych definicji `CREATE INDEX` w zrzucie; ostatnia liczba nie obejmuje wszystkich indeksów tworzonych przez PK/UNIQUE. Dowód: `baseline/psql-schema.txt`, `baseline/schema.sql`. Istnieją częściowe unikalności `collection_items` dla przepisu i wpisu, PK pary `post_media`, CHECK dla składnika bez ilości i ograniczenie terminu kary. Nie ma podstaw do ponownego zgłaszania „braku CHECK-ów” ani braku unikalności tych par.

Kasowanie przepisu może usuwać cudze komentarze i wykonania. To **nie jest ukryte, nowe znalezisko**: faktyczne FK mają kaskady, a `resources/views/pages/settings/data.blade.php:114–120` ostrzega o cudzych komentarzach i wykonaniach wprost. D-022 daje świadomy wybór zakresu. Nie proponuję zmieniać tej decyzji pod pozorem poprawiania schematu.

Pełny baseline obejmuje m.in. macierze `tests/Feature/Visibility/`, testy blokad, ochrony zdjęć, statusów, eksportu i moderacji. Nie oznacza to dowodu autoryzacji każdego przyszłego wejścia — G01 i G12 pokazują dwa różne rodzaje rozjazdu między warstwami. W kodzie list sprawdzono również paginację i eager loading; bez pomiaru planów na reprezentatywnych danych nie zgłaszam N+1 ani „brakującego indeksu” na podstawie samego wyglądu metody.

### 5.2. Rzeczywista ścieżka bez JavaScriptu i pierwsze 60 sekund

Przeglądarka Chromium, JavaScript **wyłączony**, viewport 390×844, rzeczywiste formularze HTML i zbudowany arkusz `public/build/assets/app-XeUFumrQ.css`. Dane były testowe, nie pochodziły z produkcji.

| Krok | Wynik wykonania | Co pozostaje do sprawdzenia z człowiekiem |
|---|---|---|
| Rejestracja | Formularz utworzył nowe konto, przejście do onboardingu. | Zrozumienie pól, wybór nazwy, czytelność błędów przy własnych danych. |
| Pierwszy ekran | Można pominąć zainteresowania i obserwowanie; finał prowadzi do zdjęcia albo oglądania; `/home` pokazuje treści przygotowanych kont. | To dowód działania pustego stanu z fixture, nie dowód atrakcyjnego obsadzenia produkcji. |
| Pierwsze zdjęcie | Plik i opis wysłane, zapisany jeden wpis. | Zdjęcie z prawdziwego telefonu, wolne łącze i przerwanie wysyłki; 429 opisuje G06. |
| Pierwszy przepis | Kreator udostępnia alternatywny formularz bez JS; minimalny przepis opublikowany. | Widoczność i zrozumiałość przejścia do alternatywy, szczególnie przy większym tekście. |
| „Ugotowałem” | Wykonanie zapisane i powiadomienie autora obecne. | Czy osoba odróżnia wykonanie od komentarza i lajkowania. |
| Komentarz | Zapisany przez zwykły POST bez JS. | Czy komunikaty i odpowiedzi są naturalne, nie tylko poprawne technicznie. |
| Powrót autora | Autor otworzył powiadomienie, zobaczył „Komuś wyszło”, wysłał „Podziękuj”; powstał komentarz. | Czy wiadomość ma dla niego znaczenie i skłania do powrotu następnego dnia. |

Dowody: `probes/browser/01–09*.html` i PNG, `final-probes/browser/results.json`, `author-loop.json`. Powiadomienie kucharza o podziękowaniu sprawdza też wykonany w B0 `tests/Feature/KomusWyszloTest.php:83–105`. W pomocniczym odczycie przeglądarkowej sondy licznik tego powiadomienia wyniósł 0, ponieważ szukałem nieistniejącego dla komentarzy klucza `data.cooked_event_id`; `PublishComment.php:97–105` zapisuje `comment_id` i `url`. Tego zera **nie uznaję za usterkę**.

**CSS zmierzony w przeglądarce po buildzie:** tekst podstawowy 18 → **25,2 px** po wyborze 140%; metadane 16 → **22,4 px**; zmierzone przyciski `.btn` w głównej części `/home` co najmniej **50,5 px** przy skali podstawowej; przy 320 px i skali 140% brak poziomego overflow. Metadane 16 px nie są dowodem tekstu podstawowego poniżej reguły z `AGENTS.md:135`. Nie uogólniam pomiaru przycisków na każdy element wszystkich ekranów.

Istniejący skrypt `scripts/dostepnosc.mjs` przeszedł warianty jasny, ciemny, powiększony i 320 px bez wykrytych naruszeń. To nie zastępuje badań 13 osób z `UX_50_PLUS.md:147–151`, pełnego audytu przy 200% ani VoiceOver/TalkBack. Nie mam dowodu takich sesji w tym audycie.

**WYWNIOSKOWANE produktowo:** w pierwszej minucie problemem nie jest brak kolejnej funkcji, tylko brak pewności, że za kafelkami stoi ktoś, kto odpowie. Obietnica odpowiedzi gospodarza już jest w `docs/product/SOUL.md:204`; nie wymaga nowego modułu. W pilotażu przypisać konkretną osobę do odpowiedzi, zmierzyć odsetek pierwszych wpisów bez odpowiedzi i czas oczekiwania, a następnie sprawdzić, czy ludzie zaczynają odpowiadać sobie nawzajem. Nie przedstawiać przykładowych kont jako dowodu żywej społeczności.

### 5.3. Minimalny zestaw danych i jeden raport zamiast systemu analitycznego

**Propozycja do G07, nie opis istniejącego kodu:** zachować obecny WAC i zdarzenia `photo_upload_failed` / `search_performed`; dodać tylko tabelę `user_active_days(user_id, activity_date)` z unikalnością tej pary oraz FK do użytkownika. Jeden zapis `ON CONFLICT DO NOTHING` na aktywny dzień zalogowanego człowieka, z żądania strony HTML — nie z pollingów Livewire, assetów, zadań ani healthchecków. Bez IP, user-agenta, frazy wyszukiwania i ścieżki URL. Sama kolumna `last_seen_at` nie wystarczy do późniejszego odtworzenia historii D30.

Dzień liczony konsekwentnie w `Europe/Warsaw`. Retencja robocza 90 dni, usunięcie wierszy przy wymazaniu konta, informacja i obsługa sprzeciwu zgodna z przyjętą podstawą przetwarzania. Migracja + test PostgreSQL + `DATABASE.md` + rollback. Błąd zapisu statystyki nie może blokować czytania ani publikacji.

**Jedna komenda: `php artisan kuking:puls`**, wynik tekstowy lub Markdown, pięć wierszy:

| Wiersz raportu | Definicja |
|---|---|
| WAC | Istniejąca definicja autorów wpisu/przepisu/wykonania, ostatnie cztery pełne tygodnie, także tygodnie z zerem. |
| D7 i D30 wizyt | Licznik: członkowie kohorty z wizytą w dokładnie 7. lub 30. dniu kalendarzowym po rejestracji; mianownik: wszyscy kwalifikujący się członkowie, dla których ten dzień już minął, także nigdy nieaktywni. |
| Pierwsza publikacja | Udział nowych osób, które w pierwszych siedmiu dniach dodały wpis, przepis albo wykonanie, z istniejących tabel. |
| Domknięcie „Ugotowałem” | Liczba wykonań cudzego przepisu, odsetek z powiadomieniem, odczytem autora i jego komentarzem pod wykonaniem w ciągu siedmiu dni; komentarz jest wskaźnikiem odpowiedzi, nie dowodem konkretnej treści podziękowania. |
| Tarcie | Liczba odrzuceń zdjęć według przyczyny i wyniki wyszukiwania możliwe do ustalenia z obecnych sygnałów; bez udawania procentu skuteczności uploadu, jeżeli nie ma mianownika wszystkich prób. |

Raport pokazuje zawsze **licznik/mianownik i procent**, datę odcięcia oraz reguły wyłączenia kont przykładowych, testowych i gospodarza. Te reguły trzeba ustalić przed pilotażem; późniejszy ban nie powinien po cichu poprawiać historycznej retencji przez usunięcie osoby z mianownika. Po prawidłowym usunięciu konta nie utrzymywać jej identyfikowalnej historii tylko po to, żeby nie ruszyć wykresu; zachować wyłącznie nieidentyfikujące agregaty i opisać metodę.

**Proponowana decyzja właścicielska, nie benchmark rynkowy:** dla pilotażu 20 realnych osób nie otwierać pracy nad V1 przed dojrzeniem D30 całej kohorty; jako pierwsze kryterium do rozmowy przyjąć co najmniej 5/20 powrotów w D30 oraz WAC co najmniej 5 w każdym z czterech pełnych tygodni. Liczby 5 i 20 są jawnym roboczym progiem zarządczym, nie odkryciem badawczym. Właściciel może zatwierdzić inny próg **przed** obejrzeniem danych. Przekroczenie go pozwala rozważyć V1, nie dowodzi dopasowania produktu do rynku. Do tego czasu wystarczą naprawy MVP i rozmowy z ludźmi.

## 6. P2 — lista bez rozbudowywania

- **G10 · P2 · Zapytania — WYWNIOSKOWANE ryzyko:** `app/Http/Controllers/Admin/ModerationController.php:45–49` i `Admin/AppealController.php:58–65` nie mają rozstrzygającego UUID w sortowaniu stron. B2: 100 zgłoszeń z identyczną sekundą, dwie strony, plan domyślny i wymuszony sekwencyjny — **0 duplikatów**, kontrola z ID również 0 (`final-probes/pagination.json`). Nie twierdzę, że moderator już gubi sprawy. Najmniejsza zmiana: odpowiednio `created_at DESC, id DESC` i `created_at ASC, id ASC`, test deterministycznego porządku remisów. Koszt 1–2 h; ryzyko: kolejność zależna od planu. **Kto: repozytorium.**
- **G11 · P2 · Testy — ZMIERZONE:** `tests/Feature/DostepDoZdjeciaBezPolicyRodzicaTest.php` przeżywa mutację otwierającą media wszystkim, podczas gdy `ZdjeciaChronioneNieWyciekajaTest` pada. Odtworzenie: skrypt `mutations.py` z B1. Dodać rzeczywiste wywołanie usługi i przypadek odmowy, nie osłabiać testu towarzyszącego. Koszt 0,5–1 h; ryzyko: fałszywe poczucie pokrycia w tym konkretnym teście. **Kto: repozytorium.**
- **G12 · P2 · Autoryzacja — ZMIERZONE, tylko granica wewnętrzna:** `RecordCookedEvent.php:70–78` sprawdza blokadę i własność mediów, ale bezpośrednie `handle()` nie respektuje odmowy `RecipePolicy::cook`. B1 `domain_private_recipe`: Policy=false, created=1. Kontroler autoryzuje żądanie; **nie wykazano publicznego IDOR**. Dodać kontrolę uprawnienia na wejściu domenowym i test odmowy; przejrzeć analogiczne akcje przed dodaniem nowych wywołujących. Koszt 1–2 h; ryzyko: nowy endpoint albo zadanie ominie niejawny warunek. **Kto: repozytorium.**
- **G13 · P2 · Dokumentacja danych — ZMIERZONE:** `docs/DATABASE.md:870–874` deklaruje nieistniejący już PK `(collection_id, recipe_id)`; rzeczywisty schemat ma częściowe unikalne indeksy dla przepisu i wpisu. `:918–924` podaje 24 miesiące powiadomień, choć D-038 i polityka wskazują 3. Odtworzenie: `psql \d+ collection_items`, schema B0, porównanie z konfiguracją. Poprawić dokument, **nie dodawać z powrotem niewłaściwego PK**. Koszt 1–2 h; ryzyko: następna „naprawa” popsuje obsługę wpisów w zeszycie. **Kto: repozytorium.**
- **G14 · P2 · Runbook — ZMIERZONE odczytem:** `DEPLOYMENT_RUNBOOK.md:208–229` wyraźnie wycofuje publiczny CDN, ale inne fragmenty nadal zawierają stary URL i próby CDN (`:155`, okolice `:465` i `:919`). Wycofane kroki przenieść do jednoznacznie historycznej części i oczyścić bieżącą checklistę. Samo pole `url` nie nadaje bucketowi publicznego ACL. Koszt 1–2 h; ryzyko: pomyłka operatora pod presją. **Kto: repozytorium; nie zmieniać publiczności bucketu według starej instrukcji.**
- **G15 · P2 · Backlog — ZMIERZONE:** `docs/DO_ZALOZENIA_JAKO_ISSUES.md:9–103` nadal proponuje sześć istniejących zabezpieczeń. Dowody: `AccountStatusTest`, `tests/Feature/Visibility/`, `ZastrzezoneNazwyTest`, `SkladnikBezIlosciTest`, `UnikalnoscZeszytowTest`, świeży schemat i B0. Zastąpić listę wynikami weryfikacji i odnośnikami, nie zakładać duplikatów. Koszt 1–2 h; ryzyko: kolejna sesja wyda czas na ponowne budowanie i regresje. **Kto: repozytorium; zamykanie issues pozostawić właścicielowi po kryteriach odbioru.**
- **G16 · P2 · Produkt — ZMIERZONE rozbieżności, WYWNIOSKOWANY koszt utrzymania:** `FEATURES.md:75–86`, `product/SOUL.md:97,161,202,271`, `product/RETENTION_LOOPS.md:400` odkładają istniejące funkcje. Kod: `CookingModeController`, `StepTimer`, `Domain/Wspomnienia`, pole `recipes.source_url`; testy B0 potwierdzają implementacje, nie ich wartość. Uzgodnić status czterech wyjątków w decyzjach; nie kasować działających funkcji ani nie otwierać kolejnych z V1. Koszt 0,5–1 h; ryzyko: granica MVP przestaje kierować pracą. **Kto: decyzja właściciela + repozytorium.**
- **G17 · P2 · Prawo — ZMIERZONE brzmienie, kwalifikacja podmiotu NIEROZSTRZYGNIĘTA:** `DECISIONS.md:1696–1701` przedstawia sześciomiesięczny termin jako bezwyjątkowy wymóg DSA art. 20, pomijając art. 19 dla mikro- i małych przedsiębiorstw [S10]. Argumentuję przeciw temu **uzasadnieniu D-038**, nie przeciw przyjętym sześciu miesiącom ani D-039. Ustalić kwalifikację operatora, poprawić podstawę; nie skracać istniejącej obietnicy. Koszt 0,5–1 h; ryzyko: niepotrzebne obowiązki wdrożeniowe wywiedzione z błędnego zakresu prawa. **Kto: właściciel, w razie potrzeby prawnik; repozytorium dokumentuje rozstrzygnięcie.**

## 7. Research zewnętrzny: co wynika dla tego produktu

Odczyt źródeł: **8 września 2026**. Daty publikacji podano osobno. Publiczne strony produktowe bywają serwowane z indeksu sprzed kilku tygodni; nie przedstawiam ich jako badania zalogowanego interfejsu ani bieżących udziałów rynkowych.

### 7.1. Cookpad: precedens pętli, nie dowód jej rentowności

**Fakt:** oficjalny opis Cooksnap z **4 stycznia 2019** łączy zdjęcie i komentarz pod cudzym przepisem, poinformowanie autora oraz widoczny dla kolejnych kucharzy dowód wykonania [S1]. To zarazem podziękowanie i pokazanie własnej wersji potrawy, a nie abstrakcyjny licznik uznania. **Nie ustalono** publicznego, przyczynowego wpływu Cooksnap na D30 ani współczynnika powrotów, który można przenieść do Kukinga. **Co to znaczy dla Kukinga:** właściwym obiektem pomiaru jest zamknięta relacja wykonanie–autor–odpowiedź, a naprawa G04 ma pierwszeństwo przed dodawaniem kolejnych reakcji.

### 7.2. Cookpad: świadomy model i rzeczywista trudność biznesowa

**Fakt:** na oficjalnej stronie kariery Cookpad deklaruje preferencję modelu subskrypcyjnego zamiast reklamowego i znaczenie zaufania oraz zespołów społecznościowych; strona nie podaje daty publikacji [S2]. To deklaracja kierunku, nie dowód braku reklam we wszystkich krajach i okresach. W sprawozdaniu opublikowanym **8 maja 2026**, za I kwartał 2026, przychody wynoszą **1267 mln jenów, spadek 7,7% rok do roku**; spółka wskazuje spadek liczby subskrypcji premium i **182 mln jenów** jednorazowych kosztów redukcji zatrudnienia za granicą [S3, s. 2 numeracji wewnętrznej, sprawdzona również wizualnie]. Nie jest to ocena wyników całego 2026 ani dowód porażki Cooksnap. **Co to znaczy dla Kukinga:** sprawdzona mechanika społeczna nie rozwiązuje automatycznie monetyzacji i kosztu opieki nad ludźmi; na razie mierzyć powroty i czas gospodarza, nie kopiować skali organizacyjnej Cookpada.

### 7.3. Polski rynek: luka jest w jakości relacji, nie w samym istnieniu UGC

**Fakt z publicznych stron:** Przepisy.pl oferuje logowanie, dodanie przepisu, książkę kulinarną, „Wasze zdjęcia” i „Ostatnio ugotowaliście”; w odczytanej wersji menu dnia nosi datę **10 sierpnia 2026** [S4]. Kwestia Smaku pokazuje komentarze i zachęca do zdjęć własnych dań w komentarzach [S5]. AniaGotuje eksponuje przepisy i markę autorki [S6]. Zatem zdanie „to media, nie społeczności” jest zbyt kategoryczne jako opis funkcji. **Nie ustalono** jakości więzi, D30 ani skali aktywnych autorów tych serwisów. **Hipoteza:** niewielka, spokojna sieć ludzi pamiętających siebie może mieć wartość odmienną od kontaktu z marką przepisu. **Co to znaczy dla Kukinga:** wyróżnikiem do sprawdzenia jest „ktoś mnie zna i odpowiada”, a nie „też mamy zdjęcia wykonania”.

### 7.4. Facebook: ludzie i gospodarz są kosztem zmiany platformy

**Badanie:** raport GovLab/NYU opublikowany **23 lutego 2021** opiera się na rozmowach z **50 liderami w 17 krajach**, 26 ekspertami oraz badaniu YouGov **15 tys. osób w 15 krajach**; opisuje znaczenie przynależności, widocznej komunikacji liderów i ich nieopłacanej pracy [S7]. Badanie korzystało z dostępu do danych i badań Facebooka; nie jest niezależnym pomiarem polskich grup kulinarnych 50+. **Wniosek, nie pomiar migracji:** nowy produkt konkuruje również z istniejącą siecią odpowiedzi i przyzwyczajeniem, nie tylko z funkcjami. **Co to znaczy dla Kukinga:** pierwszych ludzi zapraszać z realnymi znajomymi i konkretnym gospodarzem, zamiast oczekiwać, że same zalety interfejsu skłonią ich do opuszczenia dotychczasowej grupy.

### 7.5. Czego brakuje na Facebooku: nie dopisuję wyników, których nie mam

**Nie ustalono:** nie wykonano reprezentatywnej obserwacji polskich grup kulinarnych 50+ ani wywiadów z ich członkami; zamknięte grupy nie były dostępne do tego badania. Nie podaję fikcyjnych liczebności, cytatów ani rankingu narzekań. **Hipotezy do sprawdzenia:** trudność odnalezienia własnej historii gotowania, zależność porządku treści od platformy, rozdzielenie przepisu i jego późniejszych wykonań. Pytania do pilotażu: „pokaż ostatni przepis, który zgubiłaś”, „pokaż odpowiedź, po którą wróciłaś”, „co musiałoby się stać, żebyś zamieściła następne zdjęcie tutaj”. **Co to znaczy dla Kukinga:** istniejące archiwum, chronologiczny feed i powiązane wykonania trzeba przetestować jako rozwiązanie konkretnych sytuacji, nie reklamować jako udowodnione lekarstwo na Facebooka.

### 7.6. Grupa 50+ nie jest jednolitą grupą osób bojących się internetu

**Badanie:** Anter, Fischer i Kümpel, publikacja **14 maja 2025**, przebadali **1100 niemieckich użytkowników Facebooka/Instagrama w wieku 60+**, z próbą kwotową i zbieraniem danych w sierpniu–wrześniu 2024; potrzeba więzi oraz FOMO wiązały się z korzystaniem z informacji społecznościowych [S8]. To badanie przekrojowe aktywnych użytkowników tych platform, nie dowód przyczynowy ani reprezentacja wszystkich Polaków 50+. Nie uzasadnia manipulowania lękiem przed pominięciem. **Co to znaczy dla Kukinga:** projektować dla relacji i samodzielności, a segmentować pilotaż według doświadczenia i potrzeb, nie według etykiety „senior”.

### 7.7. Strata danych i potrzeba wsparcia są ważniejsze niż ozdobna prostota

**Badanie jakościowe:** Money i współautorzy, **28 lutego 2024**, rozmowy z **24 osobami powyżej 75 lat i dwoma pracownikami wsparcia** w Wielkiej Brytanii; autorzy opisują zróżnicowane bariery, obawy i znaczenie indywidualnej pomocy [S11]. Wynik nie uprawnia do przypisywania tych samych problemów całej grupie 50–74. **Wniosek projektowy:** spokojny komunikat bez możliwości odzyskania pracy nie rozwiązuje problemu, a pomoc człowieka może być częścią wdrożenia. **Co to znaczy dla Kukinga:** naprawić G06, pokazać drogę odzyskania konta i obserwować momenty „co teraz kliknąć”, zamiast ograniczyć odbiór UX do koloru i wielkości fontu.

## 8. Prawo i zgodność: zakres oceny, nie certyfikat

**RODO:** konkretne rozbieżności z kodem to G01 i G05; konkretna znana bramka to G02. Dokumenty nie mogą odkładać identyfikacji administratora do „publicznej” rejestracji, gdy wcześniej zbierają dane zaproszonych osób. Informacje o regionach i dostawcach trzeba porównać z kontami usług, nie z domyślną konfiguracją [S9]. Eksport i usunięcie mają implementację i wykonane testy; nie zgłaszam ich jako brakujących funkcji. Nie oceniono indywidualnej podstawy przetwarzania każdej możliwej wrażliwej treści wpisanej przez użytkownika.

**DSA:** obowiązki hostingowe dotyczące zgłoszeń, uzasadnienia decyzji i reakcji na podejrzenie określonych przestępstw trzeba odróżnić od dodatkowych obowiązków platform. Art. 19 przewiduje wyjątek dla mikro- i małych przedsiębiorstw w zakresie sekcji 3, z wyjątkiem art. 24 ust. 3; prowadzenie serwisu samemu nie zastępuje ustalenia tej kwalifikacji [S10]. Nie nakładam automatycznie na Kukinga obowiązków dużej platformy. Przyjęte sześć miesięcy i rozdzielenie ról D-039 pozostają obowiązującą obietnicą produktu niezależnie od wyniku tej kwalifikacji.

W kodzie są formularz zgłoszenia prawnego, decyzja, uzasadnienie i droga odwołania, a B0 uruchomił `ZgloszenieNielegalnejTresciTest`, `UzasadnienieDecyzjiTest` i `OdwolanieOdDecyzjiTest`. `docs/decyzje/DSA_POMIAR.md:3–21` ostrzega, że starsza część dokumentu opisuje stan sprzed poprawki; nie powtarzam jej dawnych braków jako nowych. `ModerationController.php:100–113` wymaga wiadomości przy nielegalności, lecz sam tekst nie dowodzi poprawnego wskazania przepisu — właściciel musi to wykonać w procedurze.

**Małoletni i treści:** `resources/legal/regulamin.md:36` oraz formularz rejestracji wymagają deklaracji ukończonych 16 lat; deklaracja nie jest ustaleniem rzeczywistego wieku. Nie proponuję zbierania dowodów osobistych bez uzasadnienia. Procedura reakcji na zgłoszenie dotyczące dziecka lub treści nielegalnej wymaga rzeczywistego kanału kontaktu i osoby odpowiedzialnej; audyt nie wysyłał fikcyjnych zawiadomień do organów ani nie testował procedury na takich treściach.

## 9. Czego nie udało się sprawdzić i dlaczego

| NIEROZSTRZYGNIĘTE | Dlaczego / czego potrzeba |
|---|---|
| Rzeczywiste ustawienia Railway, Cloudflare, bucketów, tokenu krawędziowego, regionów i kopii | Dostęp do GitHuba nie daje dostępu do paneli; zaproponowano połączenie Railway, nie otrzymano z niego danych. Potrzebny protokół G02/G03. |
| Restore produkcyjnej kopii, realne RPO/RTO, utrata mediów po awarii | Przeprowadzono migracje/rollback świeżej bazy, nie operacje na produkcji. Nie otrzymano kopii ani wcześniejszego wyniku drill. |
| Dostarczalność poczty, odbiór alertu, produkcyjny administrator | Brak wybranego i udostępnionego kanału oraz odczytu konfiguracji. Obecność komendy lub testu nie jest dowodem działania konta. |
| Pełna ergonomia 50+, Android/iPhone, 200%, czytniki ekranu, wolne sieci | Użyto Chromium i automatyzacji, nie uczestników ani rzeczywistych urządzeń. To nie spełnia całego protokołu UX. |
| Obciążenie, indeksy przy skali produkcji, miesięczne rachunki i maksymalna obsługiwana społeczność | Brak reprezentatywnego wolumenu, ruchu i faktur; brak podstaw do liczby użytkowników na sekundę lub kosztu miesięcznego. |
| Wszystkie wyścigi i każda ścieżka autoryzacji | Wykonano pełne istniejące testy oraz celowane sondy; nie dowodzi to nieistnienia innych usterek. Mutowano trzy wybrane mechanizmy, nie wszystkie testy. |
| Powroty prawdziwych ludzi, obsadzenie produkcji i skuteczność gospodarza | Sondy korzystały z fixture; WAC/D30 nie zostały odczytane z realnej społeczności. |
| Reprezentatywne potrzeby polskich grup kulinarnych 50+ | Brak wywiadów i dostępu badawczego do zamkniętych grup; zagraniczne badania podano z ograniczeniami. |
| Pełny stan krajowego wdrożenia i nadzoru DSA na dzień audytu | Nie rozstrzygnięto całej ścieżki legislacyjnej ani statusu prawnego operatora; nie wydaję opinii prawnej o wszystkich obowiązkach. |
| Pozostałe 227 pozycji starego audytu | Zakres weryfikacji obejmował jego dziesięć najważniejszych, nie certyfikację całej listy. |

Kosztów nie zastępuję tabelą cen katalogowych: właściciel powinien odczytać faktyczne pozycje aplikacja/worker/Postgres/storage/transfer/poczta, limit wydatków i powiadomienie o jego przekroczeniu. Bez faktur i konfiguracji stwierdzenie „ten serwis kosztuje X miesięcznie” byłoby zgadywaniem.

## 10. Dziesięć najważniejszych pozycji poprzedniego audytu — rozstrzygnięcia

Źródło kolejności: `docs/AUDYT_2026-09.md:71–108`. **NIEAKTUALNE** oznacza, że zarzut nie opisuje badanego commita; nie oznacza, że nigdy nie był prawdziwy. **OBALONE** odnosi się do zakresu twierdzenia, nie intencji poprzedniego autora.

| Nr | Zarzut | Werdykt | Dowód i granica wniosku |
|---|---|---|---|
| 1 | Polityka zaprzecza automatycznej retencji. | **NIEAKTUALNE** | `polityka-prywatnosci.md:27–30` opisuje 36/12/3 miesiące i 90 dni; D-038 oraz wykonany `DokumentyPrawneNieKlamiaTest`. Pozostają inne, osobno wykazane rozbieżności G05/G13. |
| 2 | Skala 140% nie zmienia tekstu. | **NIEAKTUALNE** | B2, zbudowany CSS: body 18→25,2 px, metadane 16→22,4 px, zapisane zrzuty i JSON. Nie oceniano tego z samego źródła CSS. |
| 3 | Test fillable przechodzi po dodaniu status/role. | **NIEAKTUALNE** | B1 rzeczywiście dodał te pola: `SecurityTest` czerwony, po przywróceniu zielony, składnia mutanta poprawna. |
| 4 | Test dostępu do zdjęć przechodzi po `return true`. | **POTWIERDZONE** | Wskazany `DostepDoZdjeciaBezPolicyRodzicaTest` przeżył tę mutację. Drugi test ochrony zdjęć ją wykrył; zarzut dotyczy jednego testu, nie całej ochrony. G11. |
| 5 | Cztery funkcje oznaczone V1 już istnieją. | **POTWIERDZONE** | `FEATURES.md:79–80`, `SOUL.md:97,161,202`, `RETENTION_LOOPS.md:400` wobec kodu trybu gotowania, minutnika, wspomnień i źródła; baseline uruchomił ich testy. Uporządkowanie zakresu G16, nie kasowanie funkcji. |
| 6 | Piętnaście spośród 41 otwartych issues jest zrobionych. | **OBALONE jako bezwarunkowa kwalifikacja piętnastu do zamknięcia** | API w dniu audytu rzeczywiście zwróciło 41 otwartych issues, ale #1 wymaga powrotu do szkicu także z profilu; `ProfileController.php:71–78` wybiera tylko `published`, a `profile/show.blade.php:209–220` nie daje listy szkiców. To konkretny niespełniony punkt, mimo gotowego kreatora. Nie wynika z tego, że pozostałe czternaście jest niedokończonych. |
| 7 | Sześć rzeczy „do założenia” już jest zrobionych. | **POTWIERDZONE** | `AccountStatusTest`, macierze `Visibility/`, `ZastrzezoneNazwyTest`, `SkladnikBezIlosciTest`, `UnikalnoscZeszytowTest`, `users.status_expires_at` i rzeczywiste ograniczenia w `schema.sql`; wszystko objęte B0. G15. |
| 8 | Runbook nakazuje ponownie otworzyć zamkniętą lukę CDN. | **OBALONE w tej kategorycznej postaci** | `DEPLOYMENT_RUNBOOK.md:208–229` wyraźnie zabrania wykonania kroku i nazywa go wycofanym. Samo `'url'` nie otwiera bucketu. Pozostawione sprzeczne odwołania są prawdziwym, węższym problemem G14. |
| 9 | isAdmin nieużywane, a dokumentacja obiecuje 14 dni. | **NIEAKTUALNE** | D-039, `UserPolicy::resolveAppeals`, komenda nadania roli oraz D-038; testy odwołań wykonane w B0. Nie stwierdzam na tej podstawie, że administrator na produkcji już istnieje. |
| 10 | Żaden test HTTP nie sprawdza CSRF; usunięcie wyjątku CSP nie wywołuje błędu testu. | **NIEAKTUALNE** | B1 usuwa wyjątek `_csp`; `PolitykaBezpieczenstwaTest` pada, po cofnięciu przechodzi. To obala obecny brak wykrywania wskazanej regresji, nie certyfikuje każdego endpointu CSRF. |

**Bilans: 3 potwierdzone, 2 obalone w podanym zakresie, 5 nieaktualnych.** Najważniejsza korekta narracji z poprzedniego dokumentu: jego zdanie o „zerze prawdziwych usterek w app/” (`:67–69`) nie jest podstawą oceny gotowości — G01, G04 i G05 zostały odtworzone na badanym kodzie.

## 11. Źródła zewnętrzne

- **[S1] Cookpad Team, 4.01.2019**, [All About Cooksnaps: Cook it! Snap it! Share it!](https://blog.cookpad.com/uk/all-about-cooksnaps-cook-it-snap-it-share-it/).
- **[S2] Cookpad, bez daty publikacji**, [Cookpad Careers](https://careers.cookpad.com/), odczyt 8.09.2026; deklaracja organizacji, nie niezależny wynik badania.
- **[S3] Cookpad Inc., 8.05.2026**, [Consolidated Earnings Results for the Three Months ended 31 March 2026](https://cf.cpcdn.com/info/assets/wp-content/uploads/20260508104624/FY2026-Q1_Consolidated-Earnings-Results.pdf), strona 2 numeracji dokumentu, fizyczna strona 4 PDF; tekst i zrzut strony.
- **[S4] Przepisy.pl**, [strona główna](https://www.przepisy.pl/), odczyt 8.09.2026, dostępna wersja z menu datowanym 10.08.2026.
- **[S5] Kwestia Smaku**, [strona główna — komentarze i zdjęcia](https://www.kwestiasmaku.com/), odczyt 8.09.2026.
- **[S6] AniaGotuje**, [strona główna](https://aniagotuje.pl/), odczyt 8.09.2026.
- **[S7] NYU Tandon / GovLab, 23.02.2021**, [The impact of online communities and role of their leaders](https://engineering.nyu.edu/news/govlab-nyu-tandon-releases-report-impact-online-communities-and-role-their-leaders).
- **[S8] Anter, Fischer, Kümpel, 14.05.2025**, [Older Adults’ Information Use on Social Media: The Role of Psychological Needs and Personality Traits](https://journals.sagepub.com/doi/10.1177/01640275251341447), DOI 10.1177/01640275251341447.
- **[S9] UE, 27.04.2016**, [RODO — rozporządzenie 2016/679](https://eur-lex.europa.eu/legal-content/PL/TXT/?uri=CELEX:32016R0679), szczególnie art. 5, 13, 17, 28 i 32; odczyt 8.09.2026.
- **[S10] UE, 19.10.2022**, [DSA — rozporządzenie 2022/2065](https://eur-lex.europa.eu/legal-content/PL/TXT/?uri=CELEX:32022R2065), szczególnie art. 14, 16–20 i wyjątek art. 19; odczyt 8.09.2026.
- **[S11] Money i współautorzy, 28.02.2024**, [Barriers to and Facilitators of Older People’s Engagement With Web-Based Services](https://aging.jmir.org/2024/1/e46522/), JMIR Aging.

## 12. Trzy pytania kontrolne

**1. Co było niewygodne?** Nie tylko dokumentacja zaniża jakość kodu. W samym kodzie wykazałem nieatomowe powiadomienie, pozostawione dane po usunięciu i publiczne wykonanie w karencji. Jednocześnie założenie rynkowe, że inni mają wyłącznie przepisy bez społecznościowych funkcji, jest za mocne. To przesuwa pracę z „więcej funkcji” na dotrzymywanie obietnic i relacje między ludźmi.

**2. Co uruchomiono, a co przeczytano?** Uruchomiono pełny baseline, świeży schemat i rollback, sondy awarii/retencji/wymazywania, trzy rzeczywiste mutacje z przywróceniem, krytyczne formularze bez JS, powrót autora, pomiar zbudowanego CSS i próbę paginacji. Brak digestu i rozjazdy dokumentów ustalono odczytem i wyszukaniem wywołań. Produkcja, rzeczywiste restore, rachunki i badania ludzi pozostają jawnie nierozstrzygnięte.

**3. Czy wyłącznie pierwsze trzy działania wystarczą na dwadzieścia realnych osób?** **Tak — do kontrolowanej, nadzorowanej alfy, pod warunkiem faktycznego zaliczenia opisanych prób G02/G03, a nie tylko odhaczenia dokumentów.** G01 usuwa odtworzony problem prywatności, G02 dopuszcza rzeczywiste dane i zapewnia obsługę kont/moderacji, G03 potwierdza odzyskiwalność. Pozostałe P1 nadal psują doświadczenie lub zaufanie, ale mają obejścia i nie są tu przedstawiane jako P0. **Nie jest to zgoda na publiczną betę ani twierdzenie, że dwadzieścia osób będzie wracać** — te wyniki trzeba dopiero zmierzyć. W aktualnym stanie audytu G02/G03 nie mają dowodu zamknięcia, więc odpowiedź na pytanie „czy otwierać dziś?” brzmi: **jeszcze nie**.
