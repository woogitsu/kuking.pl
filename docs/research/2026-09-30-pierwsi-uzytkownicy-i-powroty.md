# Pierwsi użytkownicy i powroty: research przed Bramką A

Data: 30 września 2026. Baza: `origin/claude/paczka-i-kandydat` (`5548c7e16`).
Powiązania: [#29](https://github.com/woogitsu/kuking.pl/issues/29) (cold start,
Bramka A), [#15](https://github.com/woogitsu/kuking.pl/issues/15) (testy z ludźmi 50+),
[#1015](https://github.com/woogitsu/kuking.pl/issues/1015) (pętla Zapisuję → Ugotowałem).

To jest **research, nie implementacja i nie audyt**. Żadnej osoby nie zaproszono
ani o nic nie zapytano. Nie dotykano produkcji. Nie zmieniono kodu ani progów
Bramki A.

Oznaczenia dowodów:

- **[kod]**: odczyt z bazy gałęzi, z plikiem i linią;
- **[pomiar własny]**: uruchomione tutaj (test PHPUnit na lokalnej bazie testowej);
- **[źródło]**: zewnętrzny dokument z linkiem;
- **[pomiar cudzy]**: wynik z innego dokumentu repozytorium, nie powtórzony;
- **[niepotwierdzone]**: szacunek albo wniosek bez twardego źródła;
- **[propozycja]**: rekomendacja do decyzji właściciela.

## 0. Wniosek w dziewięciu zdaniach

1. Plan jest już napisany ([COLD_START](../product/COLD_START.md),
   [AUDIENCE_50_PLUS](AUDIENCE_50_PLUS.md),
   [AUDYT_COLD_START_29](../product/AUDYT_COLD_START_29_2026-09-20.md)).
   Nie brakuje pomysłów, brakuje **wykonania z ludźmi**. Ten dokument niczego
   tam nie zastępuje. Dokłada kolejność, koszt, ryzyka prawne i luki w kodzie.
2. Serwis nie ma prawdziwych użytkowników (D-333). Pierwsza prawdziwa osoba
   uruchamia więc pytania do prawnika, które D-333 odłożyło właśnie „do pierwszych
   prawdziwych użytkowników” (#8).
3. **Największe ryzyko prawne przy zapraszaniu to nie RODO, tylko art. 398
   Prawa komunikacji elektronicznej.** Zimny e-mail albo telefon do koła
   gospodyń, UTW czy biblioteki z propozycją darmowego serwisu może być
   marketingiem bezpośrednim bez zgody. Od 10.11.2024 dotyczy to także osób
   prawnych. Bezpieczne drogi to rozmowa twarzą w twarz, list papierowy
   i polecenie przez osobę, która zna obie strony.
4. Kolejność fal: **5 → 10 → 20** osób z kręgu właściciela i jednego koła albo
   UTW. Potem 20 → 100 dopiero po Bramce A. W złotówkach koszt jest bliski
   zera. Prawdziwy koszt to 10–14 godzin tygodniowo gospodarza.
5. Jesienią 2026 rusza **ogólnopolskie skalowanie Klubów Rozwoju Cyfrowego**
   przy bibliotekach, domach kultury i UTW (ok. 1 mld zł na lata 2026–2028).
   Ich prowadzący potrzebują materiału ćwiczeniowego, więc to najlepiej
   dopasowany nowy kanał instytucjonalny w tym roku.
6. W kodzie jest większość narzędzi powrotu bez manipulacji: powiadomienia
   o „Ugotowałem”, Web Push z ciszą nocną, tygodniowe podsumowanie, wspólny
   zeszyt, alert gospodarza, raport powrotów. **Brakuje trzech rzeczy:**
   przypomnienia o zapisanym przepisie (Pętla 3, w dokumentacji oznaczonej
   jako MVP), powitania po dłuższej nieobecności i drożnego zaproszenia rodziny
   do wspólnego zeszytu.
7. **Znalezisko odtworzone testem:** osoba bez konta, która dostała link do
   wspólnego zeszytu, po rejestracji i pierwszych krokach nie wraca do
   zaproszenia. Link zostaje w sesji nieużyty (§3.3, R-01). To dokładnie droga
   „córka dołącza do zeszytu mamy”.
8. Badanie #15 ma gotowy protokół R1 (8 zadań na kontach ćwiczeniowych).
   Nie obejmuje rejestracji, komentarza, usuwania, blokowania, eksportu ani
   **powrotu po tygodniu**. Proponuję rundę R2 „Pierwszy tydzień” na
   prawdziwych kontach z drugą wizytą po 7 dniach (§4).
9. Przy n = 20 procent jest bardzo niepewny: 50% w próbie 20 osób to
   w przybliżeniu 30–70% w populacji (przedział Wilsona, 95%). Bramkę A
   czytamy więc **w liczbach osób**, nie w procentach, i z dziennikiem
   „samodzielnie czy po przypomnieniu”, którego baza nie zna (§5).

## 1. Od czego zaczynamy

| Fakt | Dowód |
|---|---|
| Brak prawdziwych użytkowników i kont | D-333, wiersz „Pytania do prawnika…”: „Serwis nie ma jeszcze prawdziwych użytkowników ani kont” (`docs/DECISIONS.md`, sekcja D-333) |
| Bramka A: 8 warunków, STOP przy WAC/zarejestrowani <35% albo publikacji tylko po telefonie | [COLD_START](../product/COLD_START.md) §9; treść #29 |
| Żaden warunek Bramki A nie jest potwierdzony | [AUDYT_COLD_START_29](../product/AUDYT_COLD_START_29_2026-09-20.md) §3 **[pomiar cudzy]** |
| Minimum treści przed pierwszym zaproszeniem: 6 przepisów, 9 wpisów, 3 promowane tagi (propozycja, nie próg) | tamże §4 **[pomiar cudzy]** |
| Treść zalążkowa z personami nie jest dozwolona w obecnym zadaniu | tamże §4, ostatni akapit |
| #15: protokół R1 gotowy, zero odbytych sesji | [TESTY_Z_UZYTKOWNIKAMI](../product/TESTY_Z_UZYTKOWNIKAMI.md) §1, §7; komentarze #15 z 9, 10 i 24 IX |
| Monetyzacja nie jest celem; reklamy wykluczone | [MONETIZATION](../MONETIZATION.md) |
| Newslettera nie ma i nie będzie; tygodniowe podsumowanie to jedyny list nietransakcyjny | D-059, D-057 |

Zasady, które obowiązują każdy punkt niżej: bez algorytmu i bez rankingów
(`AGENTS.md` §8, §12), bez streaków i punktów, bez sztucznych kont, bez
masowego importu i bez „pilności” w powiadomieniach
([RETENTION_LOOPS](../product/RETENTION_LOOPS.md) §3.3). Prawnie te same
granice wyznacza art. 25 DSA: platforma nie może projektować interfejsu,
który wprowadza w błąd, manipuluje albo istotnie ogranicza zdolność do
podejmowania wolnych i świadomych decyzji
([DSA art. 25](https://dsa-library.com/article/25/)) **[źródło]**.

## 2. Plan pozyskania pierwszych 20–100 osób

### 2.1 Fale i warunki przejścia

| Fala | Kto | Ile | Warunek wejścia | Warunek przejścia dalej |
|---|---|---|---|---|
| 0 | Uczestnicy #15 R1 (konta ćwiczeniowe, instancja odizolowana) | 5 | Protokół R1 i karta badania gotowe | Blokery R1 naprawione albo opisane w issues |
| 1 | Rodzina i znajomi właściciela 50+, którzy już fotografują obiady | 5 | Gospodarz i zastępstwo wyznaczeni; minimum treści (6 + 9 + 3); poczta i upload sprawdzone na środowisku startowym; #8 przejrzane pod kątem pierwszych kont | Każda z 5 osób opublikowała sama co najmniej raz; 100% wpisów z odpowiedzią |
| 2 | Fala 1 zaprasza po jednej osobie (eksperyment z komentarza w #29 z 21 IX) + pierwsze koło gospodyń albo grupa UTW | do 10 | Fala 1 bez blokera przez 7 dni | Jak wyżej, plus ≥1 „Ugotowałem” nie od gospodarza |
| 3 | Drugie koło albo UTW, grupa FB tylko za zgodą administratora | do 20 | Fala 2 przez 14 dni bez naruszenia obietnicy odzewu | **Bramka A** po pełnym tygodniu pomiaru |
| 4 | 20 → 100: kolejne koła i UTW, Klub Rozwoju Cyfrowego, gazeta gminna albo parafialna | do 100 | **Bramka A spełniona** | Tempo nie większe niż zdolność odpowiadania ([COLD_START](../product/COLD_START.md) §2) |

Fale małe i po kolei **[propozycja]**. Uzasadnienie: obietnica „ktoś
odpowie” jest produktem ([COLD_START](../product/COLD_START.md) §2), a jedno
złamanie u nowej osoby w tej grupie kończy relację
([AUDIENCE_50_PLUS](AUDIENCE_50_PLUS.md) §6 pkt 7). Audyt #29 proponował
to samo tempo 5 → 10 → 20 (§5 pkt 4).

### 2.2 Kanały: co realnie działa, koszt, ryzyka

| Kanał | Skala w Polsce | Co działa | Koszt | Ryzyko prawne | Ryzyko produktowe |
|---|---|---|---|---|---|
| **Rodzina i znajomi** | — | Rozmowa telefoniczna albo przy stole, skrypt z [COLD_START](../product/COLD_START.md) §3.3; concierge 15 min | 0 zł; ok. 1 wieczór na listę + 30–45 min na osobę | Niskie. Notatka „do kogo dzwonię” to dane osobowe: trzymać poza repozytorium i serwisem, skasować po kampanii. Jeśli numer dał ktoś trzeci, przy pierwszym kontakcie powiedzieć, skąd go mam (art. 14 RODO) | Uprzejmość zamiast nawyku: rodzina publikuje, bo ją poproszono. To jest sygnał STOP z Bramki A, więc trzeba go mierzyć (§5.3) |
| **Koła Gospodyń Wiejskich** | ~18–19 tys. kół w rejestrze ARiMR ([COIG, wykaz 2026](https://www.coig.com.pl/wykaz_lista_kola-gospodyn-wiejskich_w_polsce.php); [AUDIENCE_50_PLUS](AUDIENCE_50_PLUS.md) §5.3) **[źródło]** | Wizyta na spotkaniu koła, dożynkach, kiermaszu; polecenie przez znajomą członkinię; list papierowy do przewodniczącej z jedną prośbą | 0–100 zł (dojazd, wydruki) **[niepotwierdzone]**; tygodnie oczekiwania | **Średnie.** Rejestr jest publiczny i zawiera dane kontaktowe przedstawiciela ([gov.pl, KRKGW](https://www.gov.pl/web/arimr/przegladaj-rejestr-krkgw)) **[źródło]**. Pobranie ich i zapisanie to przetwarzanie danych osoby: obowiązek z art. 14 RODO najpóźniej przy pierwszym kontakcie ([UODO](https://uodo.gov.pl/pl/676/4255)) **[źródło]**. Zimny e-mail albo telefon z propozycją: art. 398 PKE (niżej) | Jedna przewodnicząca = kilka osób naraz. Dobrze, bo gęstość relacji. Źle, jeśli koło oczekuje „konta koła”: w serwisie nie ma grup (V1, #22 obniżone do P3 w D-333). Mówić wprost: każda osoba ma własne konto |
| **UTW** | 747 UTW, 125,9 tys. słuchaczy w r. ak. 2024/2025 ([GUS](https://stat.gov.pl/obszary-tematyczne/edukacja/edukacja/uniwersytety-trzeciego-wieku-w-roku-akademickim-20242025,11,5.html)) **[źródło]** | Prowadzący zajęcia komputerowe: jeden warsztat 90 min „pokaż, co ugotowałaś”, efekt: pierwszy wpis i kartka „jak wejść” | 0 zł + 2–3 h przygotowania; ewentualnie poczęstunek | Jak KGW (PKE przy pierwszym kontakcie). Na warsztacie **każdy zakłada konto na własny adres i sam zaznacza zgody** (§2.4) | Profil UTW (kobieta, 60–79, emerytka) pokrywa się z personą ([AUDIENCE_50_PLUS](AUDIENCE_50_PLUS.md) §5.4). Ryzyko: zajęcia bez ciągu dalszego. Gospodarz musi odpowiedzieć każdemu jeszcze tego samego dnia |
| **Biblioteki, domy kultury, Kluby Rozwoju Cyfrowego (KRC)** | Konkurs CPPC z 18.03.2026; do 717 KRC typu 1 i 1349 typu 2; szersze wdrożenie od jesieni 2026; ok. 1 mld zł na lata 2026–2028, grupy 50/60/70+ ([Opolskie dla Rodziny](https://dlarodziny.opolskie.pl/2026/03/18/w-calym-kraju-powstana-kluby-rozwoju-cyfrowego/); [MC, gov.pl](https://www.gov.pl/web/cyfryzacja/kluby-rozwoju-cyfrowego-odpowiedzia-na-wykluczenie-cyfrowe)) **[źródło]** | Kuking jako gotowe ćwiczenie „zrób zdjęcie i opublikuj”: jedna strona A4 dla prowadzącego + karta uczestnika | 0 zł + przygotowanie materiału (projekt treści jest w [MATERIALY_OFFLINE_OBIETNICA_30](../product/MATERIALY_OFFLINE_OBIETNICA_30.md) §2.4, bez hasła „nie zginą” — D-333) | Jak wyżej. Nie tworzyć kont „klubowych” na wspólny adres: łamie to „jedno konto = jedna osoba” i eksport RODO | Uczestnicy KRC dopiero uczą się telefonu, więc to dobry sprawdzian UX. Mogą jednak nie gotować regularnie. W tej fali mierzyć osobno od KGW |
| **Parafie, kluby seniora przy parafiach** | — | Klub seniora albo gazetka parafialna, **za zgodą proboszcza**; plakat A4 z jednym adresem | 0–50 zł (wydruk) **[niepotwierdzone]** | Niskie (plakat nie jest komunikacją elektroniczną do konkretnej osoby) | Wiarygodny kanał ([AUDIENCE_50_PLUS](AUDIENCE_50_PLUS.md) §5.6), ale marka ma być neutralna: nie „portal parafialny”. Skuteczności nie zmierzono **[niepotwierdzone]** |
| **Grupy FB** (zakwas, przetwory, przepisy babci) | Najgęstsze skupisko grupy docelowej ([AUDIENCE_50_PLUS](AUDIENCE_50_PLUS.md) §5.2) | 2–3 tygodnie normalnego udziału własnym imieniem, potem jeden post za zgodą administratora ([COLD_START](../product/COLD_START.md) §3.2) | 15 min/dzień × 3 tyg. | **Wiadomości prywatne do członków grupy z zaproszeniem = zimny marketing** (art. 398 PKE). Nie zbierać list członków (RODO). Post w grupie za zgodą administratora nie jest skierowany do urządzenia konkretnej osoby **[niepotwierdzone, do #8]** | Ten sam kanał to start kampanii „Garnek” ([KAMPANIA_GARNEK](../marketing/KAMPANIA_GARNEK.md)). Użyty przed Bramką A spala pierwsze wrażenie (#15, pytanie o rekrutację). **Dopiero fala 3–4** |
| **Prasa lokalna i radio** | Moduł ogłoszenia w gazecie gminnej albo powiatowej od ok. 20–40 zł, silniejsze tytuły regionalne 72–149 zł ([AdForm](https://www.adform.pl/ile-kosztuje-reklama-w-gazecie-lokalnej-i-ogolnopolskiej/)) **[źródło]** | Nie ogłoszenie, tylko **historia**: „koło z X spisuje przepisy babć”. Wymaga 3–5 prawdziwych historii za zgodą ([COLD_START](../product/COLD_START.md) §5) | Ogłoszenie: dziesiątki zł (niezalecane); artykuł: 0 zł | Zgoda osób z historii na publikację imienia, zdjęcia i przepisu, **na piśmie** | Ruch nie do obsłużenia przed Bramką A. **Fala 4, nie wcześniej** |

Czego **nie** robić (bez zmian względem
[COLD_START](../product/COLD_START.md) §11): influencerzy, Product Hunt
i Wykop, konkursy z nagrodami, punkty za zaproszenia, płatne reklamy na pusty
serwis, fałszywe konta i treści AI udające ludzi.

### 2.3 Art. 398 PKE: jedyne twarde ryzyko prawne naboru

Prawo komunikacji elektronicznej obowiązuje od 10.11.2024. Art. 398 zakazuje
używania telefonu, SMS-a i e-maila do marketingu bezpośredniego bez
uprzedniej zgody abonenta lub użytkownika końcowego. **Obejmuje także osoby
prawne (B2B)**. Kara UKE: do 3% przychodu albo do 1 mln zł. Komentatorzy
wskazują, że **nawet pierwszy kontakt z pytaniem o zgodę** może być uznany
za marketing ([prawo.pl](https://www.prawo.pl/biznes/prawo-komunikacji-elektronicznej-zgoda-na-dzialania-marketingowe,534839.html);
[prawo.pl, kontakty handlowe](https://www.prawo.pl/biznes/zgody-marketingowe-i-kontakty-handlowe-co-mowi-prawo-komunikacji-elektronicznej,530981.html))
**[źródło]**.

Kuking prowadzi spółka, więc zaproszenie do darmowego serwisu to wciąż
promocja usługi. Wniosek **[propozycja, do potwierdzenia przez prawnika w #8]**:

| Droga | Ocena |
|---|---|
| Rozmowa twarzą w twarz (spotkanie koła, zajęcia UTW, klub seniora) | Poza art. 398: to nie telekomunikacja |
| List papierowy do koła, UTW, biblioteki | Poza art. 398 (dotyczy środków elektronicznych) **[niepotwierdzone, do #8]**; RODO art. 14 w liście, jeśli adresat to osoba z rejestru |
| Polecenie: znajoma sama pyta przewodniczącą, a ta sama dzwoni lub pisze | Inicjatywa po stronie adresata; najbezpieczniejsza |
| Telefon lub e-mail do osoby, która wcześniej **sama** zostawiła kontakt i zgodę (np. na zajęciach) | Dozwolone; zgodę zapisać: kto, kiedy, na co |
| Zimny e-mail, formularz kontaktowy albo telefon do instytucji | **Ryzykowne.** Nie robić bez opinii prawnika |
| Masowa wysyłka do wielu kół z rejestru | **Nie robić** (też sprzeczne z #29: „nie masową wysyłką”) |
| Wiadomość prywatna na Messengerze do nieznajomej z grupy FB | **Nie robić** |

Ta sama reguła dotyczy późniejszego kontaktu z uczestnikiem. Checklista
gospodarza z [COLD_START](../product/COLD_START.md) §4.5 (dzień 7: „jedna
wiadomość, potem cisza”) powinna iść **komentarzem w serwisie**, a telefonem
tylko wtedy, gdy przy concierge osoba powiedziała „tak, może Pan zadzwonić”
i gospodarz to zanotował.

### 2.4 RODO i zgody przy concierge onboardingu

[COLD_START](../product/COLD_START.md) §3.4 pkt 1 mówi: „konto (my wpisujemy
dane, jeśli tak jest łatwiej)”. Od #2217 i #2219 rejestracja ma dowód
akceptacji regulaminu i informację z art. 14 RODO. Wniosek **[propozycja]**:

1. Gospodarz może podyktować albo pokazać, **ale regulamin, politykę i zgodę
   na podsumowanie zaznacza sama osoba, na swoim telefonie**. Inaczej dowód
   akceptacji dokumentuje czyn gospodarza, nie jej.
2. **Hasło wpisuje osoba i gospodarz go nie zapisuje.** Jeśli to bariera,
   lepsza jest droga „logowanie linkiem” (D-056) niż hasło na karteczce
   u gospodarza.
3. Konto zawsze na **jej** adres e-mail. Nie zakładać „na adres córki dla
   wygody”, bo wtedy eksport, usuwanie i powiadomienia trafiają do kogoś innego.
4. Włączenie tygodniowego podsumowania (opt-in, D-057) i Web Push tylko po
   zadaniu pytania na głos i odpowiedzi „tak”. Nigdy „przy okazji”.
5. Zdjęcia z galerii (COLD_START §3.4 pkt 3): zapytać, czy na zdjęciu są
   inne osoby albo wnętrze domu, które ma zostać prywatne. EXIF i GPS są
   czyszczone (warunek w D-333, #602), ale twarze i treść zdjęcia już nie.
6. Lista „kogo zaprosiłem” (imię, kanał, data, czy zgoda na kontakt) jest
   poza repozytorium, bez adresów i kasowana po zakończeniu fali. Tak mówi
   też komentarz w #29 z 9 IX. W repozytorium są tylko pseudonimy (U-01…).

## 3. Funkcje, które pomagają wrócić, bez manipulacji

### 3.1 Co już jest w kodzie

| Funkcja | Stan | Dowód **[kod]** | Granica anty-manipulacyjna, która już jest |
|---|---|---|---|
| „Ugotowałem” zawsze powiadamia autora | działa | `AGENTS.md` §1; `tests/Feature/UgotowalemZawszePowiadamiaAutoraTest.php` | Trzy wyjątki z nazwy; bez przełącznika wyciszającego |
| Web Push, cisza nocna, dzienny limit | działa po wpisaniu kluczy VAPID | `app/Domain/Notifications/TerminPowiadomieniaZewnetrznego.php`; `app/Console/Commands/GenerujKluczeVapid.php`; D-303 | Zgoda przeglądarki tylko po kliknięciu w ustawieniach; odkładanie zamiast kasowania |
| Tygodniowe podsumowanie | zbudowane, **wyłączone domyślnie** | `config/kuking.php:2690` (`KUKING_DIGEST_WLACZONY`, domyślnie `false`); `app/Console/Commands/WyslijPodsumowaniaTygodnia.php`; D-057 | Opt-in; bez rankingów, bez „warto poznać”, bez śledzenia otwarć; pusty list nie wychodzi |
| Zgoda na podsumowanie | tylko w `/ustawienia/prywatnosc` | `app/Http/Controllers/Settings/PrivacySettingsController.php:52`; domyślnie wyłączona od migracji `2026_09_07_400000_default_weekly_digest_to_off.php` | Domyślnie „nie” |
| Wspólny (rodzinny) zeszyt | działa: zaproszenie po nazwie konta albo jednorazowy link | `app/Http/Controllers/CollectionSharingController.php:55-93`; D-302 | **Serwis nie wysyła maila do osoby trzeciej**: link kopiuje i wysyła człowiek. Dobre także pod PKE i RODO |
| „Podziel się” (wpis, przepis, publiczny zeszyt) | działa | `resources/views/components/podziel-sie.blade.php`; D-331 (#2000) | Systemowy arkusz udostępniania; bez kontaktów z książki adresowej |
| Alert gospodarza o pierwszym wpisie nowej osoby | działa | `app/Domain/Posts/Actions/PublishPost.php:291-310` | Powiadamia człowieka, który odpowiada; nie automat udający odpowiedź |
| Panel „wpisy bez odpowiedzi” | działa | `/admin/bez-odpowiedzi` (komentarz w #29 z 9 IX) | — |
| Wspomnienia „rok temu” | działa | `app/Domain/Wspomnienia`; wyłącznik w ustawieniach (#34) | Tylko na `/home`, nigdy e-mail ani push |
| Urodziny | działa | `app/Console/Commands/PrzypomnijOUrodzinach.php`, `WyslijZyczeniaUrodzinowe.php` (#1755) | Dane podaje sama osoba |
| Pomiary pętli | działa | `app/Console/Commands/RaportPowrotow.php:253` (Zapis → „Ugotowałem” w 30 dni, #1015); `app/Domain/Analytics/DrugiWpisW7Dni.php` | Niedomknięte okna są „jeszcze w oknie”, nie zerem |

### 3.2 Czego brakuje

| # | Funkcja | Dowód braku | Propozycja bez manipulacji | Rozmiar | Kiedy |
|---|---|---|---|---|---|
| B-1 | **Przypomnienie o zapisanym przepisie** (Pętla 3) | [RETENTION_LOOPS](../product/RETENTION_LOOPS.md):443 oznacza je jako MVP „**tak**”. W `app/Console/Commands` nie ma takiej komendy, a `grep -rli "weekend\|sobot" app` trafia tylko w moderację **[kod]** | **Nie nowy list.** Czwarta, ostatnia sekcja istniejącego podsumowania (D-057): „Masz w zeszycie: sernik Marka, ok. 1,5 h”. Jeden przepis, najstarszy nieugotowany zapis, z czasem przygotowania. Bez nowej zgody, nowego kanału i budżetu poczty (D-059: newsletter wymagałby osobnej zgody). Nie liczy się do „czy list ma treść”, tak jak pytanie gospodarza | S | Po Bramce A, gdy `save → cooked` ma pierwszą pełną kohortę do porównania |
| B-2 | **Zaproszenie rodziny linkiem dla osoby bez konta** | R-01 niżej, odtworzone testem **[pomiar własny]** | Zapamiętać zamiar dołączenia do zeszytu przez rejestrację, jak `ZamiarZapisu` (#2028), `ZamiarUgotowania` (#2058) i `PowrotDoRozmowy` (#2027). Gościowi pokazać kontekst („Halina zaprasza Cię do zeszytu »Obiady rodzinne«”) przed ekranem logowania | S–M | **Przed falą 2** (eksperyment „zaproś jedną osobę”) |
| B-3 | **Powitanie po dłuższej nieobecności** („Dobrze, że wracasz” + 3 rzeczy, które Cię dotyczą) | [COLD_START](../product/COLD_START.md):200 opisuje blok dla wracających po >14 dniach. `users.ostatnio_widziany_at` czytają tylko analityka, moderacja, eksport i wymazanie; nie ma go na `/home` **[kod]** | Jeden kafel na `/home`: kto ugotował z Twojego przepisu, kto odpowiedział. Tylko fakty, bez „tęskniliśmy” i bez licznika dni nieobecności. Znika po obejrzeniu | M | Po Bramce A. Przy 20 osobach gospodarz robi to ręcznie komentarzem |
| B-4 | **Zgoda na podsumowanie przy końcu pierwszych kroków** | Pole jest tylko w ustawieniach prywatności (tabela 3.1). Widok `pages.onboarding.done` go nie ma **[kod]** | Na `/witaj/gotowe` pytanie „Czy chcesz raz w tygodniu dostać list od gospodarza z tym, co Cię dotyczy?”, **domyślnie odznaczone**, z przykładem treści. Bez tego podsumowanie dotrze tylko do osób, którym ktoś pokazał ustawienia | S | Przed falą 2, jeśli właściciel włączy podsumowanie |
| B-5 | **Kartka „jak wejść” dla pomocnika** | [AUDIENCE_50_PLUS](AUDIENCE_50_PLUS.md) §6 pkt 6. Brak takiego ekranu w `resources/views` (grep „do wydruku”: tylko eksport i kody 2FA) **[kod]** | **Najpierw papier, nie kod**: karta A5 przy concierge (adres, nazwa konta, „zapomniałam hasła → link na e-mail”, bez hasła). Ekran budować dopiero, gdy dziennik gospodarza pokaże ≥3 przypadki „nie wiem, jak wejść” | 0 / S | Fala 1 (papier) |

Świadomie **nie** proponuję: licznika serii dni, „nie przegap”, odliczania,
czerwonych liczników zachęcających do kliknięcia, maili „dawno Cię nie było”,
automatycznego obserwowania bez możliwości cofnięcia, „confirmshamingu”
(przycisk w stylu „Nie, nie chcę ocalić przepisów babci”), importu kontaktów,
wysyłki maili do osób trzecich z konta użytkownika ani punktów za zaproszenia.
Wszystkie łamią `AGENTS.md` §12, [RETENTION_LOOPS](../product/RETENTION_LOOPS.md)
§3.3 albo art. 25 DSA.

### 3.3 R-01: link do wspólnego zeszytu gubi się przy rejestracji

**Droga:** Halina tworzy link-zaproszenie i wysyła go córce. Córka nie ma
konta. Otwiera link, trafia na logowanie, wybiera rejestrację, przechodzi
pierwsze kroki i ląduje na „Konto gotowe”. Zaproszenia nie widzi, a link
jest jednorazowy i ważny 7 dni (D-302).

**Dowód [kod]:**

- trasa linku jest w grupie `auth` (`routes/web.php:695` otwiera grupę,
  `routes/web.php:1030-1032` to `collections.link.show`), więc gość dostaje
  przekierowanie na logowanie, a `url.intended` = link
  (`tests/Feature/WspolnyZeszytTest.php:168` sprawdza tylko to przekierowanie);
- `RegisterController::store()` przekazuje dalej cztery zamiary (obserwowanie,
  ugotowanie, rozmowa, zapis), ale nie `url.intended`, i zawsze kieruje na
  `onboarding.interests` (`app/Http/Controllers/Auth/RegisterController.php:261-266`);
- `OnboardingController::done()` sprawdza te same cztery zamiary i w innym
  przypadku renderuje widok (`app/Http/Controllers/OnboardingController.php:118-142`).
  O zaproszeniu do zeszytu nic nie wie.

**Odtworzenie [pomiar własny]:** tymczasowy test PHPUnit na lokalnej bazie
testowej (`kuking_test_agent_…`, PostgreSQL 18, 127.0.0.1:5432), usunięty po
uruchomieniu i niecommitowany. Gość otwiera link → przekierowanie na
logowanie, w sesji `url.intended` = link. Nowe konto otwiera
`onboarding.done` → **200 z widokiem „gotowe”**, a `url.intended` nadal leży
w sesji nieużyty. Wynik: `1 passed (5 assertions)`. Sama rejestracja przez
formularz nie była symulowana (Turnstile, zgody), ale jej przekierowanie jest
bezwarunkowe (linia 266).

**Wpływ:** drogę „zaproś córkę do rodzinnego zeszytu” pierwsza nowa osoba
przejdzie bez błędu, a mimo to skończy bez zeszytu. W grupie 50+ zaprasza
zwykle osoba starsza młodszą, więc problem trafia w najważniejszą pętlę
rodzinną (Pętla 6).

**Poprawka [propozycja]:** `ZamiarDolaczeniaDoZeszytu` na wzór `ZamiarZapisu`
(#2028): zapamiętać token w sesji przy `showLink` dla gościa, przekazać przez
rejestrację i obsłużyć w `done()` jako przekierowanie na stronę zaproszenia
z przyciskiem „Dołączam”. Samo dołączenie robi dopiero klik człowieka.
**Test regresyjny:** gość → link → rejestracja → `onboarding.done` →
przekierowanie na `collections.link.show`. Kontrola ujemna: bez zapamiętania
test oblewa. Duplikatów nie znalazłem: w wyszukiwaniu issues trafiają
#2006, #2027, #2028, #2058 (inne zamiary, zamknięte) i #1743 (zamknięte).

## 4. Plan testów z ludźmi (#15)

### 4.1 Dwie rundy zamiast jednej

| | R1 (istnieje) | R2 „Pierwszy tydzień” **[propozycja]** |
|---|---|---|
| Protokół | [TESTY_Z_UZYTKOWNIKAMI](../product/TESTY_Z_UZYTKOWNIKAMI.md), karta [KARTA_BADANIA_15](../product/KARTA_BADANIA_15.md), dodatek [SCENARIUSZE_UZUPELNIAJACE_15](../product/SCENARIUSZE_UZUPELNIAJACE_15.md) | Nowy, na wzór dwóch wizyt z [PROTOKOL_BADANIA_1818](../product/PROTOKOL_BADANIA_1818.md) (Z5 po 24–72 h) |
| Konta | Ćwiczeniowe, instancja odizolowana | **Prawdziwe**, własny adres, środowisko startowe |
| Zadania | 8 (T1–T8): danie, przepis, szukanie, zapis i odnalezienie, „Ugotowałem”, wiadomość, zainteresowania, czytelność | Brakujące w R1 zadania #15: **rejestracja**, **komentarz**, **usunięcie wpisu**, **zablokowanie**, **pobranie danych**, + „kto to teraz widzi?” przy własnym wpisie |
| Druga wizyta | brak | **Po 7 dniach**: czy wróciła sama, czy zauważyła odpowiedź i powiadomienie, czy potrafi wejść (hasło lub link) |
| Uczestnicy | 5 rozpoznawczych albo 13 | Pozostali do 13, z przekrojem z #15: 5 × 50–59, 5 × 60–69, 3 × 70+, Android, iPhone, komputer, ≥2 osoby bez doświadczenia publikowania |
| Czas | ok. 6 h 15 min (5 sesji) do 16 h 15 min (13) ([TESTY](../product/TESTY_Z_UZYTKOWNIKAMI.md) §7) | 45–60 min + 20 min drugiej wizyty + 15 min notatek na osobę ≈ 1 h 40 min × 8 ≈ 13 h **[niepotwierdzone]** |

Uzasadnienie liczby: pięć osób w rundzie wykrywa większość problemów
użyteczności, a więcej daje kolejna runda po poprawkach niż więcej osób
w jednej ([NN/g, „Why You Only Need to Test with 5 Users”](https://www.nngroup.com/articles/why-you-only-need-to-test-with-5-users/))
**[źródło]**. Starszych uczestników szybciej męczy sesja: mniej zadań,
testy na ich urządzeniu i w ich ustawieniach, wydrukowana treść zadania,
a przed startem wyraźne „testujemy stronę, nie Panią”
([NN/g, Usability Testing With Older Adults](https://www.nngroup.com/articles/usability-testing-older-adults/))
**[źródło]**. R1 ma to zapisane. R2 musi to powtórzyć.

**Pytanie do właściciela:** czy uczestnicy R2 to jednocześnie fala 1 z §2.1
(wtedy konta zostają i są pierwszymi prawdziwymi kontami), czy konta po
badaniu usuwamy. Pierwsza droga zużywa pierwsze wrażenie, ale mówi prawdę
o powrocie. Uczciwie: powiedzieć uczestnikom, że to próba przed otwarciem,
i wrócić do nich po poprawkach (#15, pytanie o rekrutację).

### 4.2 Co mierzyć

Z #15, bez zmian: gdzie pada pytanie „co mam teraz kliknąć?”, czas do
pierwszej publikacji, liczba momentów pomocy (poziomy P1–P3 z R1), wycofania
z ekranu, odpowiedź na „kto to widzi?”, powiększenie tekstu bez podpowiedzi.

Nowe w R2:

| Miara | Jak | Próg (zapisany przed badaniem) **[propozycja]** |
|---|---|---|
| Rejestracja bez pomocy | Karta sesji | ≥ 6 z 8 bez P3; każda porażka przez Turnstile, pocztę albo nazwę użytkownika = bloker |
| Zgody zaznaczone świadomie | Pytanie po zadaniu: „Na co się Pani zgodziła?” | Brak pomyłki co do „kto widzi” i „czy dostanę listy” |
| Powrót bez przypomnienia w 7 dni | `users.ostatnio_widziany_at` (raport) + pytanie na drugiej wizycie | Tylko opis, bez progu: n = 8 nie wystarczy do wniosku o retencji ([TESTY](../product/TESTY_Z_UZYTKOWNIKAMI.md) §7) |
| Zauważenie odpowiedzi | Gospodarz komentuje wpis z sesji w ciągu 2 h; na drugiej wizycie: „Czy ktoś coś Pani odpowiedział?” | ≥ 6 z 8 wie o odpowiedzi; inaczej problem powiadomień ([COLD_START](../product/COLD_START.md) §10, wiersz 2) |
| Wejście po tygodniu | Druga wizyta: „Proszę wejść na swoje konto” | Każda porażka bez P3 = bloker (zapomniane hasło, brak linku) |
| iPhone i HEIC | Zadanie ze zdjęciem na iPhonie | Dosłowny zapis reakcji na komunikat (#119) |

### 4.3 Zgoda i dane

Formuła zgody do przeczytania na głos jest w R1. Do R2 dochodzą trzy zdania
**[propozycja, do #8]**:

1. „Konto jest prawdziwe i Pani. Po badaniu może je Pani zostawić albo
   usunąć, a ja pokażę jak.”
2. „Za tydzień zapytam, czy mogę przyjść albo zadzwonić jeszcze raz. To jedyny
   kontakt, o który proszę.” Zapisać odpowiedź: to zgoda na telefon (§2.3).
3. „Nie nagrywam twarzy. Notuję bez imienia, jako U-05.” Nagrywanie ekranu
   tylko za osobną zgodą, retencja 3 miesiące, poza chmurą i repozytorium
   (issue #15).

Podziękowanie **[propozycja]**: drobny upominek rzeczowy albo poczęstunek,
nie gotówka. Uczestnik nie ma czuć, że „zarabia” na dobrej opinii. Kwoty nie
podaję; to decyzja właściciela **[niepotwierdzone]**.

## 5. Metryki i progi decyzji

### 5.1 Bramka A: skąd brać liczby

Progi są w [COLD_START](../product/COLD_START.md) §9. Nie zmieniam ich.
Kolumna „źródło” wskazuje, czy istniejące narzędzie odpowiada wprost.

| Warunek | Próg | Źródło dziś | Uwaga |
|---|---|---|---|
| Osoby z ≥1 wpisem | ≥ 20 | `kuking:wac`, `CookEligibility` | Wyklucza gospodarza, konta testowe i zalążkowe |
| WAC / zarejestrowani | ≥ 50% (**≥ 10 z 20**) | `kuking:wac` | Mianownik i okno do ustalenia przez właściciela ([AUDYT_COLD_START](../product/AUDYT_COLD_START_29_2026-09-20.md) §3) |
| Osoby z ≥3 wpisami | ≥ 10 | brak wprost; SQL albo drobne rozszerzenie raportu | — |
| „Ugotowałem” ≥ 15, z tego ≥ 8 nie od gospodarza | 15 / 8 | **Brak wprost.** `ZasiegUgotowalem` liczy różne przepisy i różnych autorów, świadomie **bez** `CookEligibility` (`app/Domain/Analytics/ZasiegUgotowalem.php:15-29`) **[kod]** | R-02: potrzebna liczba **zdarzeń** z podziałem gospodarz / reszta |
| % wpisów z ≥1 odpowiedzią | 100% | `/admin/bez-odpowiedzi` | Liczyć tylko odpowiedź innej osoby, nie autora |
| Mediana czasu do 1. odpowiedzi | ≤ 3 h | panel gospodarza | Obok mediany podać liczbę wpisów nadal bez odpowiedzi |
| Awarie uploadu | < 2% prób | brak pełnego mianownika ([AUDYT_COLD_START](../product/AUDYT_COLD_START_29_2026-09-20.md) §3) | Przy 20 osobach jedna awaria to już >2%: liczyć próby w dzienniku gospodarza |
| Blokery UX z #15 | 0 | Karta badania R1 i R2 | — |

### 5.2 Metryki drugiego poziomu (20 → 100)

| Metryka | Źródło | Kontynuuj | Diagnozuj | Źródło progu |
|---|---|---|---|---|
| Drugi wpis w 7 dni od pierwszego | `DrugiWpisW7Dni` w `kuking:raport` | ≥ 55% (cel przy 200) | < 35% | [RETENTION_LOOPS](../product/RETENTION_LOOPS.md) §5.3, komentarz #29 z 21 IX |
| Zapis → „Ugotowałem” w 30 dni | `ZapisDoUgotowania` w `kuking:raport` (#1015) | ≥ 15% | < 5% **[propozycja]** | [RETENTION_LOOPS](../product/RETENTION_LOOPS.md) Pętla 3 |
| Powrót D7 / D30 osób publikujących | `kuking:raport` | D30 ≥ 25% | D30 < 15% | Bramka B |
| Zaproszenie jednej osoby: zgodzili się / założyli konto / publikacja lub „Ugotowałem” w 14 dni / powrót w kolejnym tygodniu | **dziennik gospodarza**, konto w bazie | ≥ 1 aktywna zaproszona osoba na 3 zapraszające **[propozycja]** | 0 aktywnych po 10 zaproszeniach | Komentarz #29 z 21 IX |
| Publikacja samodzielna / po przypomnieniu | **dziennik gospodarza** | Większość samodzielnie od 3. tygodnia | Tylko po telefonie = **STOP** | Bramka A |

### 5.3 Dziennik gospodarza: czego baza nie wie

Trzy liczby z Bramki A i eksperymentu z zaproszeniem nie wynikają z danych
w bazie: czy wpis był samodzielny, czy padło przypomnienie i kto kogo
zaprosił. **[propozycja]** Arkusz poza repozytorium, jeden wiersz na
zdarzenie: pseudonim (U-07), data, „samodzielnie / po przypomnieniu
(jakim kanałem)”, „zaproszony przez U-03”, próba uploadu (urządzenie, format,
wynik). Bez imion, adresów i treści wpisów. Kasowany po Bramce B.

### 5.4 Mała próba: czytać liczby, nie procenty

Przy 20 osobach 50% (10 z 20) daje 95% przedział Wilsona ok. 30–70%.
Obliczenie: `1,96 · √(0,25/20 + 1,96²/1600) / (1 + 1,96²/20) ≈ 0,20` wokół
0,5 **[pomiar własny, rachunek]**. Konsekwencje **[propozycja]**:

- progi Bramki A zapisywać w liczbach osób („≥ 10 z 20”), jak robi audyt #29;
- jeden tydzień nie rozstrzyga: decyzja „dalej” po **dwóch kolejnych**
  zamkniętych tygodniach powyżej progu;
- strefa 35–49% to „diagnozuj 2 tygodnie”, nie „dalej” (zgodnie z audytem #29 §3);
- STOP (< 35% albo tylko po przypomnieniu) → [COLD_START](../product/COLD_START.md)
  §10, zanim ktokolwiek zaprosi kolejną osobę.

### 5.5 Drzewo decyzji po fali 3

```text
Dwa zamknięte tygodnie z 20 osobami
├─ Bramka A spełniona w obu → fala 4 (do 100), tempo = zdolność odpowiadania
├─ WAC ≥ 10 z 20, ale inny warunek nie spełniony → naprawić ten warunek, bez nowych osób
├─ WAC 7–9 z 20 (35–49%) → 2 tygodnie diagnozy: dziennik + rozmowy z 5 osobami
└─ WAC < 7 z 20 albo publikacje tylko po telefonie → STOP, COLD_START §10
```

## 6. Znaleziska i luki do założenia jako issues

Koordynator zakłada issues z tej tabeli. Duplikaty sprawdzone przez
wyszukiwanie issues w repozytorium 30 IX.

| ID | Waga | Tytuł | Dowód | Poprawka i test | Rozmiar | Duplikat? |
|---|---|---|---|---|---|---|
| R-01 | P1 | Link do wspólnego zeszytu gubi się, gdy zaproszona osoba dopiero zakłada konto | §3.3; `RegisterController.php:261-266`, `OnboardingController.php:118-142`, `routes/web.php:1030-1032`; test tymczasowy `1 passed` | Zamiar dołączenia przez rejestrację + kontekst zaproszenia dla gościa; test gość → rejestracja → zaproszenie, kontrola ujemna | S–M | Nie (pokrewne zamknięte #2006, #2027, #2028, #2058) |
| R-02 | P2 | Raport nie podaje liczby „Ugotowałem” z Bramki A (zdarzenia, podział gospodarz / reszta) | `ZasiegUgotowalem.php:15-29` (różne przepisy i autorzy, bez `CookEligibility`); [AUDYT_COLD_START](../product/AUDYT_COLD_START_29_2026-09-20.md) §3 | Wiersz w `kuking:raport`: zdarzenia w oknie, w tym nie od gospodarza; test z gospodarzem i kontem testowym | S | Nie znalazłem |
| R-03 | P2 | Pętla 3 oznaczona jako MVP, a nie ma jej w kodzie | [RETENTION_LOOPS](../product/RETENTION_LOOPS.md):443 vs brak komendy w `app/Console/Commands` | Najpierw poprawić dokument („nie zbudowane”); budowa jako sekcja podsumowania (B-1) po Bramce A | S (dok.) / S (kod) | Nie znalazłem |
| R-04 | P2 | Concierge onboarding: „my wpisujemy dane” kłóci się z dowodem akceptacji regulaminu | [COLD_START](../product/COLD_START.md):86; #2217 | Poprawić §3.4: zgody i hasło wpisuje osoba (§2.4 tu) | S (dok.) | Nie |
| R-05 | P2 | Zgoda na tygodniowe podsumowanie tylko w ustawieniach | `PrivacySettingsController.php:52`; brak w `pages.onboarding.done` | Pytanie na `/witaj/gotowe`, domyślnie odznaczone (B-4); test: domyślnie `false`, zapis tylko po zaznaczeniu | S | Nie znalazłem |
| R-06 | P3 | Brak powitania wracającego po >14 dniach z COLD_START §6.1 | [COLD_START](../product/COLD_START.md):200; `ostatnio_widziany_at` poza `/home` | Oznaczyć w dokumencie jako niezbudowane; budować po Bramce A (B-3) | S (dok.) / M | Nie (#749 dotyczy czego innego) |
| R-07 | P2 | #15 w treści issue wymaga `TrescZalazkowaSeeder` i 10 zadań; nowsze dokumenty mówią co innego | Treść #15 („Uruchomić TrescZalazkowaSeeder”); [AUDYT_COLD_START](../product/AUDYT_COLD_START_29_2026-09-20.md) §4 (persony niedozwolone); [TESTY](../product/TESTY_Z_UZYTKOWNIKAMI.md) §1 (8 zadań, reszta osobno) | Decyzja właściciela: R1 + R2 (§4.1) jako zakres #15; zaktualizować treść issue | — (decyzja) | — |

## 7. Decyzje właściciela, których ten plan potrzebuje

1. Kto jest gospodarzem i kto go zastępuje; czy jest 10–14 h/tydzień
   ([COLD_START](../product/COLD_START.md) §12).
2. Czy przed falą 1 prawnik (#8) patrzy na art. 398 PKE i wzór listu do kół
   i UTW (§2.3). D-333 odłożyło #8 „do pierwszych prawdziwych użytkowników”.
   Fala 1 jest tym momentem.
3. Czy uczestnicy R2 są falą 1 (§4.1).
4. Czy włączyć tygodniowe podsumowanie (`KUKING_DIGEST_WLACZONY=true`) przed
   falą 2. Bez tego B-1 i B-4 nie mają sensu.
5. Wariant treści startowej (6 + 9 + 3) z audytu #29.
6. Okno i mianownik WAC dla Bramki A.

## 8. Czego nie sprawdzono

- Nie rozmawiano z żadnym kołem, UTW, biblioteką ani parafią. Skuteczność
  kanałów to wnioski z danych o skali i z dokumentów repozytorium
  **[niepotwierdzone]**.
- Ocena art. 398 PKE opiera się na komentarzach prawniczych, nie na opinii
  prawnika ani orzeczeniu. Wyłączenie listu papierowego i posta w grupie to
  interpretacja **[niepotwierdzone, do #8]**.
- Cen druku i znaczków nie sprawdzono **[niepotwierdzone]**.
- R-01 odtworzono na poziomie sesji i trasy `onboarding.done`, bez
  przeglądarki i bez wysłania formularza rejestracji.
- Nie uruchamiano `kuking:raport` na żadnych danych: brak realnej kohorty.
- Nie weryfikowano, czy klucze VAPID i podsumowanie są włączone na produkcji.

## Źródła

- CPPC / Opolskie dla Rodziny, „W całym kraju powstaną Kluby Rozwoju Cyfrowego” (18.03.2026): https://dlarodziny.opolskie.pl/2026/03/18/w-calym-kraju-powstana-kluby-rozwoju-cyfrowego/
- Ministerstwo Cyfryzacji, „Kluby rozwoju cyfrowego odpowiedzią na wykluczenie cyfrowe”: https://www.gov.pl/web/cyfryzacja/kluby-rozwoju-cyfrowego-odpowiedzia-na-wykluczenie-cyfrowe
- GUS, „Uniwersytety trzeciego wieku w roku akademickim 2024/2025”: https://stat.gov.pl/obszary-tematyczne/edukacja/edukacja/uniwersytety-trzeciego-wieku-w-roku-akademickim-20242025,11,5.html
- ARiMR, przegląd Krajowego Rejestru KGW: https://www.gov.pl/web/arimr/przegladaj-rejestr-krkgw
- COIG, wykaz kół gospodyń wiejskich 2026: https://www.coig.com.pl/wykaz_lista_kola-gospodyn-wiejskich_w_polsce.php
- prawo.pl, „Prawo komunikacji elektronicznej: zgoda na działania marketingowe”: https://www.prawo.pl/biznes/prawo-komunikacji-elektronicznej-zgoda-na-dzialania-marketingowe,534839.html
- prawo.pl, „Zgody marketingowe i kontakty handlowe…”: https://www.prawo.pl/biznes/zgody-marketingowe-i-kontakty-handlowe-co-mowi-prawo-komunikacji-elektronicznej,530981.html
- UODO, obowiązek informacyjny przy danych z innych źródeł: https://uodo.gov.pl/pl/676/4255
- DSA, art. 25 (projektowanie interfejsu): https://dsa-library.com/article/25/
- AdForm, „Ile kosztuje reklama w gazecie”: https://www.adform.pl/ile-kosztuje-reklama-w-gazecie-lokalnej-i-ogolnopolskiej/
- NN/g, „Why You Only Need to Test with 5 Users”: https://www.nngroup.com/articles/why-you-only-need-to-test-with-5-users/
- NN/g, „Usability Testing With Older Adults”: https://www.nngroup.com/articles/usability-testing-older-adults/
- Źródła o grupie 50+ w Polsce (CBOS, GUS, KGW, UTW): [AUDIENCE_50_PLUS](AUDIENCE_50_PLUS.md), sekcja „Źródła”.

Wycofanie: usunąć ten plik. Nie zmienia kodu, schematu ani decyzji.
