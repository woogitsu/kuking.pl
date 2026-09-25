## D-236 — Kolejka moderacji czyta się od rzeczy, która nie może czekać (22 września 2026)

Kolejka `/admin/zgloszenia` sortowała `created_at DESC, id DESC`. Zmierzone:
zgłoszenie „Dotyczy dziecka" sprzed dwóch dni leży pod trzydziestoma
zgłoszeniami spamu z ostatniej godziny, czyli na DRUGIEJ stronie — a spam
jest jedyną kategorią przychodzącą falami, więc im gorszy dzień, tym głębiej
schodzi rzecz najcięższa. Osobno: alarm mailowy istniał WYŁĄCZNIE po stronie
automatu (D-055), więc model podejrzewający treść seksualną z udziałem
dziecka budził moderatora listem, a człowiek, który to samo zgłosił
przyciskiem, nie budził nikogo. Cichsza była droga, na której ktoś to już
zobaczył.

**Priorytet liczy się z danych, nie jest wpisywany.** `PriorytetSprawy`
czyta `reason` (kategoria wybrana przez zgłaszającego) i `source`
(zgłoszenie prawne ma podłogę na P1, bo niesie termin z DSA art. 16 ust. 5).
Lista P0 to DOKŁADNIE ta sama para, którą za pilną uznaje automat
(`KategorieModeracji::PILNE`): treść seksualna i wszystko, co dotyczy
dziecka. Jedna definicja „pilnego" na cały serwis.

**Bez kolumny i bez migracji.** Wartość idzie do `ORDER BY CASE`, tak samo
jak `Report::WAGA` w kolejce automatu: to jest reguła produktu, nie fakt
o wierszu. Zmiana listy kategorii ma być jedną linijką i jednym czerwonym
testem, a nie migracją przepisującą historyczne wiersze na nową skalę.

**Sprzeczność z `KolejkiModeracjiMajaStabilnyPorzadekTest` rozstrzygnięta
świadomie.** Gwarancja „najnowsze na górze" upada W CAŁEJ KOLEJCE, bo
obiecywała porządek nie do obronienia przy falach spamu. Zastępują ją dwie
węższe: wewnątrz jednego priorytetu porządek jest NIETKNIĘTY (najnowsze na
górze, remis po `id`), a stabilne stronicowanie zostaje bez osłabienia —
mierzy to dopisany test przy mieszanych priorytetach. Kierunku wewnątrz wagi
NIE odwracamy, choć odrzucona gałąź `claude/priorytet-w-kolejce-moderacji`
to proponowała: to osobna decyzja, bez dowodu i z ceną (góra kolejki
przestaje się odświeżać). Kolejki odwołań zmiana nie dotyczy.

**Alarm zostaje pocztą i nie dokłada kanału.** `AlarmujOPilnymZgloszeniu`
woła obie drogi zgłoszenia (społecznościową i prawną, bo ta druga działa bez
konta) i wysyła list tylko przy P0, na ten sam `alarm_email` co alarm
automatu; pusty adres znaczy „bez poczty" i jest normalnym stanem lokalnie.
List NIE niesie treści zgłoszonej ani pola `details` — to niesprawdzony tekst
od dowolnej osoby, a poczta idzie przez zewnętrznego dostawcę.

**Czego świadomie NIE zbudowano.** Kolumny `priorytet` z ręczną zmianą przez
moderatora, oznaczenia „P0 nieprzejrzane" w pasku panelu i historii sankcji
autora — wszystkie trzy były w odrzuconej gałęzi. Historia sankcji nie wchodzi
do priorytetu, bo na pytanie „czy to może poczekać do jutra" odpowiada rodzaj
szkody, a nie kartoteka osoby; recydywa jest argumentem przy DECYZJI.
Kosztowałaby przy tym dwa zapytania na pozycję ekranu, bo `reports` nie ma
kolumny z autorem zgłoszonej treści.

**Czego priorytet nie twierdzi.** Że sprawa jest tym, czym nazwał ją
zgłaszający — nikt tej treści jeszcze nie obejrzał. Zmienia wyłącznie
kolejność czytania i wysyła jeden list: nie ukrywa treści, nie ogranicza jej
zasięgu i nie powiadamia autora.

**Uzgodnione z podręcznikiem moderacji, nie obok niego.**
`docs/legal/MODERATION_PLAYBOOK.md` ma własną tabelę SLA P0–P3 i wchodziła na
`main` równolegle z tą pracą. Zdanie „priorytetu nie ma w narzędziu… sprawa P0
sprzed dwóch dni leży niżej niż spam sprzed godziny" przestało być prawdziwe
i zostało w podręczniku poprawione razem z mapowaniem kategorii na to, co robi
kod. Jedna rzecz zmieniła się PO MOJEJ STRONIE: `dangerous_advice` miał u mnie
P1, a podręcznik stawia „niebezpieczne porady" w P2 — zostaje P2, bo podręcznik
jest dokumentem operacyjnym właściciela, a mój argument („zła rada o weku
kończy się szpitalem") jest opinią, nie pomiarem. `scam` nie ma pozycji
w tabeli SLA i kładę go w P1 własnym osądem; to jedna linijka do zmiany.

Dwie rzeczy, których z samej kategorii odczytać się NIE DA i które zostają
przy człowieku: „groźby zagrażające życiu" (P0 w tabeli) wchodzą jako
`harassment`, czyli P1, bo formularz nie ma takiej pozycji; „aktywny doxxing"
(P0) wchodzi jako `personal_data`, czyli P1, z tego samego powodu. Oba są
nazwane wprost w podręczniku, zamiast udawać, że kod je rozpoznaje.

**Obok D-244, D-249 i #1446 (scalenie 23 września).** Priorytet zmienia
wyłącznie kolejność czytania, więc nie dotyka reguł, KTO rozstrzyga i CO się
wtedy zapisuje: własnej sprawy nadal nie rozstrzyga nikt (D-244), decyzja
i jej wpis w dzienniku audytu powstają razem albo wcale (D-249), a usunięcie
treści idzie wyłącznie przez formularz decyzji w panelu (#1446). Alarm nie
zapisuje niczego do audytu i nie prowadzi do żadnej akcji poza kolejką.
Jedno zdanie listu trzeba było zmienić: „Decyzja należy do Ciebie" byłoby
nieprawdą, gdy zgłosił sam moderator, a list trafia na wspólny adres
alarmowy — list mówi teraz, że rozstrzyga moderator, który zgłoszenia nie
wniósł.

### Poprawki z przeglądu PR #1284 (23 września 2026)

**Ryzyko: zalanie alarmu.** Pierwsza wersja wysyłała list na KAŻDE
zgłoszenie P0 i zakładała, że nadużyciu wystarczy `reports_one_open_per_pair`.
Nie wystarczało: ten indeks pilnuje pary osoba–treść, a kategorię wybiera
zgłaszający — także w formularzu DSA bez konta. 40 zgłoszeń jednego wpisu
dawało 40 listów, jedno konto zaznaczające „Dotyczy dziecka" przy kolejnych
celach — do 60 listów na godzinę. Listy szły poza wspólnym licznikiem poczty
(wbrew D-239: jeden licznik dla wszystkich dróg) i zjadały pulę EmailLabs
300/dobę, wypychając listy logowania i rejestracji.

**Rozwiązanie — trzy zamki.** (1) Najwyżej jeden list o danym celu
(`target_type` + `target_id`, a przy zgłoszeniu prawnym bez rozpoznanego celu —
`target_url` bez `?`/`#` i końcowego ukośnika) w oknie
`moderation.alarm_czlowieka.okno_celu_godzin` (6 h), atomowo przez
`Cache::add()`; kolejne zgłoszenia tego celu i tak stoją na górze kolejki.
(2) Dobowy sufit `moderation.alarm_czlowieka.dzienny_sufit` (10) dla
wszystkich celów razem; ostatni list doby mówi wprost, że kolejnych nie
będzie, powyżej zostaje wpis w dzienniku i sprawa w kolejce z plakietką.
(3) Sufit jest licznikiem `DziennyBudzetListow::dlaAlarmuModeracji()`
zagnieżdżonym we wspólnym liczniku — jedna atomowa rezerwacja na oba, tak
jak przy podsumowaniu. Gdy list nie wychodzi po zajęciu klucza celu, klucz
wraca.

**Klasa `wejscie`, nie `zwykla` — i dlatego własny sufit.** Klasę `zwykla`
wypala zalanie `/nie-pamietam-hasla` z jednego łącza (D-239); alarm w tej
klasie dawałby sprawcy przepis na to, żeby zgłoszenie o dziecku przeleżało
noc bez listu. W klasie `wejscie` alarm sięga po ostatnie listy doby, a sufit
ogranicza, ile z nich może zabrać: najwyżej 10 ze 100 zostawianych wejściu.
Suma z `PodzialLimituPocztyTest` się nie zmienia, bo te listy leżą w rezerwie.

**Plakietka i priorytet tylko dla spraw otwartych.** Rozstrzygnięte P0
z napisem „Nie może czekać" było nieprawdą, a sortowanie po priorytecie
obejmowało wszystkie stany — w „Wszystkie" archiwum P0/P1 stało nad
dzisiejszym otwartym P2. Teraz karta czyta `PriorytetSprawy::wKolejce()`
(`null` poza `open`), a `ORDER BY` — `wyrazenieSqlKolejki()`: sprawy
nieotwarte dostają `NIE_CZEKA` i idą za otwartymi, po dacie. Test pilnuje,
że PHP i SQL liczą to samo dla każdego stanu.

**Wydajność.** `ORDER BY CASE` nie ma indeksu i liczy się na każdym wierszu
po filtrze stanu i źródła. W MVP to jest akceptowalne: filtr domyślny to
`open` od ludzi, czyli dziesiątki wierszy, a priorytet dotyczy tylko
otwartych. Kolumna z indeksem wraca do rozmowy, gdy kolejka otwartych
urośnie do tysięcy.

**`scam` jako P1 — DO POTWIERDZENIA PRZEZ WŁAŚCICIELA.** Tabela SLA
w podręczniku nie ma tej pozycji; P1 to mój osąd (oszustwo trwa i dotyka
kolejnych ludzi, dopóki wisi), nie decyzja. Zmiana to jedna linijka
w `PriorytetSprawy::MAPOWANIE`.

Dowody: `tests/Feature/KolejkaModeracjiStawiaPilneNaGorzeTest.php`
i `tests/Feature/KolejkiModeracjiMajaStabilnyPorzadekTest.php`.
