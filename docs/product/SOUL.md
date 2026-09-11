# SOUL.md — co sprawi, że Kuking ma duszę

> Dokument strategii produktu. Nie modyfikuje blueprintu — rozwija `docs/PRODUCT.md`, `docs/BRAND.md`, `docs/UX_50_PLUS.md`.

## 1. Teza

Baza przepisów odpowiada na pytanie **„jak to zrobić”**. Kuking odpowiada na pytanie **„kto to zrobił i dlaczego”**.

Dusza nie jest warstwą wizualną (ciepłe kolory, drewniana deska w tle). Dusza to **konkretne mechaniki, które przechowują ludzkie ślady**: kto podał przepis, komu wyszło, skąd się wziął, w którym roku, po kim. CRUD na przepisy przechowuje dane. Kuking przechowuje **czyjeś życie w kuchni**.

Trzy zdania, które muszą być prawdziwe po roku:

1. „Wrzuciłam zdjęcie, a ktoś odpowiedział w ciągu godziny.”
2. „Ktoś ugotował z mojego przepisu i pokazał mi zdjęcie — popłakałam się.”
3. „Mam tu przepisy po mamie i nie stracę ich.”

## 2. Cztery filary duszy

| Filar | Co znaczy w produkcie | Czym NIE jest |
|---|---|---|
| **Ślad człowieka** | Przy każdej treści widać autora, jego historię, jego kuchnię | Anonimowa karta przepisu z SEO-opisem |
| **Dowód wykonania** | `Ugotowałem` ze zdjęciem jest ważniejsze niż ocena gwiazdkowa | Licznik lajków |
| **Pamięć** | Archiwum, daty, „rok temu”, rodzinne pochodzenie przepisu | Nieskończony feed bez przeszłości |
| **Bezpieczna izba** | Nikogo nie zawstydzamy, nikt nie wygrywa, nie ma rankingu | Konkurs na najlepszego twórcę |

## 3. Zasada nadrzędna: rytuał > funkcja

Funkcja to coś, co użytkownik robi, gdy ma potrzebę. Rytuał to coś, co robi, bo dziś jest ten dzień.

Kuking ma jeden rytuał główny (**„Co dziś ugotowałeś?”**), jeden rytuał tygodniowy (**temat tygodnia + digest**) i jeden rytuał roczny (**sezon polskiej kuchni**). Wszystko inne jest funkcją i nie ma prawa krzyczeć.

---

## 4. Katalog mechanik

Format każdej pozycji: `Nazwa | Co widzi użytkownik | Dlaczego to działa emocjonalnie | Koszt (S/M/L) | Kiedy | Ryzyka`

Koszt: **S** = do ~1 dnia pracy, **M** = 2–5 dni, **L** = powyżej tygodnia lub wymaga nowej domeny/modelu danych.

### 4.1 Rytuał dnia — „Co dziś ugotowałeś?”

| Nazwa | Co widzi użytkownik | Dlaczego to działa emocjonalnie | Koszt | Kiedy | Ryzyka |
|---|---|---|---|---|---|
| **Pytanie dnia jako pierwszy element ekranu** | Na górze `/home`, nad feedem: duże, ciepłe „Co dziś ugotowałeś, Basiu?” + jeden przycisk `[ Dodaj zdjęcie ]`. Nie pole tekstowe, nie „utwórz post” | Pytanie zwrócone do mnie po imieniu zobowiązuje bardziej niż puste pole. Jest jak zapytanie sąsiadki przez płot | S | MVP | Może zmęczyć przy codziennym wejściu — potrzebny wariant „już dodałaś dziś, dziękujemy” zamiast powtarzania pytania |
| **Wariant pytania zależny od pory dnia i sezonu** | Rano: „Co dziś na śniadanie?”. Wieczorem: „Co było na obiad?”. W październiku: „Kisisz coś w tym tygodniu?” | Produkt wydaje się przytomny, a nie automatyczny. Buduje wrażenie, że ktoś tam jest | S | MVP | Zbyt sprytne warianty brzmią jak bot. Maks. 8–10 zdań w rotacji, wszystkie napisane ręcznie |
| **Potwierdzenie zamiast pustki po publikacji** | Po `Opublikuj`: „Gotowe. Twoje danie jest w Kuking — 3 września 2026.” + link `Zobacz swój wpis` | Data od pierwszej sekundy komunikuje: to archiwum, nie strumień. Buduje poczucie odkładania czegoś na półkę | S | MVP | Brak |
| **Licznik „dni w kuchni” zamiast streaka** | Na profilu: „Basia gotuje z nami od 14 miesięcy · 212 dań” | Podliczamy dorobek, a nie ciągłość. Nie da się „stracić” dorobku, więc nie ma lęku | S | V1 | Osoba z 3 wpisami może czuć się mała — dlatego nie pokazujemy tego w feedzie, tylko na własnym profilu |
| **„Dziś w kuchniach Kuking”** | Pasek pod pytaniem dnia: „Dziś ugotowano 47 dań · najczęściej: placki ziemniaczane” | Dowód, że ktoś jest po drugiej stronie, bez pokazywania rankingu osób | S | MVP | Przy 5 aktywnych osobach liczba żenuje — pokazywać tylko powyżej progu (np. ≥15 dań/dobę), niżej ukryć element |

### 4.2 `Ugotowałem` — najważniejszy sygnał w produkcie

Cel: autor przepisu ma się **wzruszyć**, nie „dostać notyfikację”.

| Nazwa | Co widzi użytkownik | Dlaczego to działa emocjonalnie | Koszt | Kiedy | Ryzyka |
|---|---|---|---|---|---|
| **Ekran „Komuś wyszło”** | Zamiast szarej notyfikacji: pełnoekranowa karta ze zdjęciem cudzego wykonania i tekstem „**Marek ugotował Twoje pierogi z kaszą.**” + jego uwaga + `[ Podziękuj ]` | To jest moment „ktoś wziął mój przepis do swojego domu”. Zdjęcie na pełnym ekranie robi tu 90% roboty — miniatura tego nie zrobi | M | **JEST** (issue #17: `app/Http/Controllers/CookedEventController.php` `celebrate`/`thank`, `resources/views/pages/cooked/celebrate.blade.php`) | Wersja bez zdjęcia obsłużona — notatka dostaje wagę akapitu wiodącego (`.notatka-wyrozniona`) zamiast pustego miejsca. Przycisk nazywa się „Podziękuj”, nie „Odpowiedz” (wysyła zwykły komentarz z gotowym tekstem podziękowania) — audyt z 8 września 2026 (`docs/AUDYT_2026-09.md`, poz. 25) prosił o ujednolicenie tego zdania z kodem |
| **Licznik wykonań pod przepisem, nie licznik lajków** | Pod tytułem: „**Ugotowało 12 osób**” + rządek ich zdjęć rezultatu | Liczba wykonań to komplement, którego nie da się kupić. Rządek prawdziwych, nierównych zdjęć uwiarygadnia bardziej niż jedno ładne | S | MVP | Przepis z 0 wykonań wygląda odrzucony → przy 0 pokazujemy „Jeszcze nikt nie gotował — będziesz pierwsza?” |
| **Galeria „Jak wyszło innym”** | Osobna sekcja na stronie przepisu: siatka zdjęć wykonań, każde z podpisem autora i jego uwagą | Początkujący widzi, że u innych też nie wygląda jak w gazecie. To zdejmuje wstyd — kluczowy hamulec przy publikacji | M | MVP | Zdjęcia słabej jakości mogą zniechęcić autora przepisu → nie sortujemy „od najlepszych”, sortujemy chronologicznie, bez ocen |
| **Odpowiedź autora wyróżniona** | Odpowiedź autora przepisu pod wykonaniem ma plakietkę „autorka przepisu” i ciepłe tło | Domyka pętlę: ugotowałeś → autor Ci odpowiedział → chcesz ugotować kolejny jego przepis | S | MVP | Plakietka nie może wyglądać jak „VIP” — słowo „autorka przepisu”, nie „PRO” |
| **„Ugotowane z przepisu…” w każdym miejscu** | Każde wykonanie w feedzie ma pod zdjęciem linijkę: „ugotowane z przepisu **Basi Kowalskiej**” — klikalną | Autorstwo krąży po całym serwisie samo. Autor dostaje uznanie także od ludzi, którzy go nie obserwują | S | MVP | Brak |
| **Podziękowanie po pierwszym wykonaniu przepisu** | Autor po pierwszym w życiu `Ugotowałem` dostaje krótki, ludzki komunikat: „To pierwszy raz, kiedy ktoś ugotował z Twojego przepisu. To się liczy.” | Pierwszy raz jest jedyny — trzeba go nazwać, bo inaczej minie niezauważony | S | MVP | Musi zadziałać dokładnie raz. Powtórzone brzmi fałszywie |
| **Rocznica wykonania** | Rok po wykonaniu: „Rok temu ugotowałeś żurek Marka. Znowu?” + `[ Ugotowałem jeszcze raz ]` | Kuking wie, że ten sam przepis gotuje się wielokrotnie — to najbardziej domowa rzecz, jaka istnieje | S | V1 | Wymaga dobrej higieny częstotliwości powiadomień (patrz `RETENTION_LOOPS.md`) |
| **„Zrobię ponownie” jako zdanie, nie gwiazdki** | Pod przepisem: „**10 z 12 osób zrobi to ponownie**” | Zdanie jest zrozumiałe dla każdego, gwiazdki są abstrakcją i zapraszają do hejtu ocenami | S | MVP | Przy małej liczbie danych statystyka jest myląca → pokazywać od ≥3 wykonań |

### 4.3 Rodzinne receptury — największa przewaga Kuking

To jest rzecz, której nie zrobi ani AI, ani portal SEO, ani TikTok.

| Nazwa | Co widzi użytkownik | Dlaczego to działa emocjonalnie | Koszt | Kiedy | Ryzyka |
|---|---|---|---|---|---|
| **Pole „Od kogo albo skąd masz ten przepis”** | W kreatorze przepisu, krok 1, opcjonalne pole: „Od kogo albo skąd masz ten przepis” → np. „od mamy”, „z gazety Przyjaciółka”. Do 11 września 2026 pole pytało „Po kim ten przepis”, a widok doklejał przed odpowiedź własne „Po” — z „Nasze smaki” robiło się „Po Nasze smaki.”. Pytamy o frazę, która stoi samodzielnie, i pokazujemy ją dosłownie | Jedno pole zmienia obiekt z receptury w dziedzictwo. Koszt: jedna kolumna w bazie. Zysk: cała tożsamość produktu | S | **MVP** | Musi być opcjonalne i bez presji. Dla wielu osób to pole będzie bolesne (osoba nie żyje) — copy musi być spokojne, nigdy „Opowiedz nam!” |
| **Podpis oryginalnego autora na karcie przepisu** | Nad tytułem, mniejszym drukiem: „Basia · skąd ten przepis: od mamy, Haliny”. Obie wartości w mianowniku, w osobnych członach — nigdy „przepis {wpisany tekst}” ani „spisany przez {nazwa konta}”, bo polskiej odmiany nie da się policzyć z dowolnego ciągu znaków | Nazwisko babci wydrukowane w internecie jest formą pamięci. Ludzie płaczą przy takich rzeczach — a to darmowe | S | **MVP** | Brak weryfikacji → ktoś może wpisać nazwisko celebryty. Moderacja reaktywna wystarczy |
| **„W rodzinie od…”** | Opcjonalny rok: „ten przepis jest w rodzinie od **1974**” — wyświetlany jako mały znacznik | Rok robi z przepisu zabytek. Ustawia Kuking jako archiwum, nie aplikację | S | **MVP** | Brak |
| **Skan zeszytu jako zdjęcie źródłowe** | Przy przepisie sekcja „Oryginał” — zdjęcie kartki z zeszytu, ręczne pismo, plamy po tłuszczu. Bez OCR | Ręczne pismo zmarłej osoby to najsilniejsza treść, jaką ten produkt może pokazać. Zero AI, zero przetwarzania — sam skan | S (samo zdjęcie) / L (OCR) | **MVP** dla skanu, **V2** dla OCR | Ryzyko: ludzie oczekują, że przepis się „sam przepisze”. Copy musi jasno mówić: „Zdjęcie kartki. Treść przepiszesz sama albo zostawisz tak.” |
| **Rodzinna książka** | Kolekcja specjalnego typu: okładka, tytuł „Kuchnia babci Haliny”, spis treści, tryb do druku/PDF | Cel: wydruk na Święta dla rodziny. To zamienia darmowy portal w rzecz, którą trzyma się w ręku | M | V1 (PDF: V1/V2) | Nie sprzedawać tego zbyt szybko jako produktu płatnego — najpierw musi być powód emocjonalny, potem monetyzacja |
| **Współautorzy przepisu rodzinnego** | „Ten przepis prowadzi Basia z siostrą Ewą” — 2–3 osoby mogą dopisywać uwagi do jednego przepisu | Rodzeństwo kłóci się o proporcje w bigosie. Danie im wspólnego miejsca to gotowa treść i gotowa emocja | M | V1 | Konflikty edycji, potrzebna prosta zasada „właściciel + dopisujący” |
| **Odziedziczenie przepisu** | Pod przepisem rodzinnym: `[ Zabieram do siebie ]` → kopia w moich przepisach z widocznym „z przepisu Haliny, przez Basię” | Łańcuch pokoleń widoczny w danych. Kradzież treści zamieniona w rytuał przekazania | M | V1 (razem z „Moja wersja”) | Musi być odróżnialne od `Ugotowałem` — inny przycisk, inne miejsce |

### 4.4 Historia i wspomnienie — pole, które robi różnicę

| Nazwa | Co widzi użytkownik | Dlaczego to działa emocjonalnie | Koszt | Kiedy | Ryzyka |
|---|---|---|---|---|---|
| **„Skąd ten przepis”** | Opcjonalne pole tekstowe w kroku 1 kreatora, z podpowiedzią: „Kilka zdań: skąd go masz, kiedy go pierwszy raz zrobiłeś, komu smakuje” | Ludzie nie potrafią pisać „opisu przepisu” (to zadanie dla copywritera), ale każdy potrafi opowiedzieć skąd coś ma. To odblokowuje pisanie | S | **MVP** | Puste w 70% przypadków na start — dlatego gospodarz musi wypełniać je we własnych przepisach jako wzór |
| **Historia jako pierwsza rzecz na stronie przepisu** | Kolejność na `/recipes/{slug}`: zdjęcie → tytuł → autor → **historia** → składniki → kroki | Kolejność to deklaracja wartości. W bazie przepisów pierwsze są składniki. U nas pierwszy jest człowiek | S | **MVP** | Dłuższa droga do składników → dodać `[ Przejdź do składników ]` na górze i tryb gotowania w V1 |
| **Cytat wyciągnięty z historii** | W kartach feedu i w search wyświetlamy 1 zdanie z historii zamiast obciętego opisu | Feed brzmi jak ludzie, nie jak katalog. „Robiła to babcia na wykopki” sprzedaje przepis lepiej niż „pyszne i szybkie” | S | V1 | Automatyczne wycinanie może uciąć w złym miejscu → brać pierwsze zdanie do 120 znaków, nigdy nie ucinać w połowie |
| **„Komu to smakuje”** | Krótkie pole: „u nas je to najchętniej…” → „mój wnuk Antek, bez cebuli” | Konkretny człowiek w przepisie. Buduje obraz domu, nie kuchni testowej | S | V1 | Dane o dzieciach → nie zachęcać do podawania danych wrażliwych, imię wystarczy |
| **Uwagi z wykonań wpięte do przepisu** | Pod krokiem: „Marek: u mnie potrzebowało 10 minut dłużej” — uwagi z `Ugotowałem` podpięte do kroków | Przepis żyje i mądrzeje. Wiedza zbiorowa zamiast komentarzy typu „wygląda pysznie” | M | V1 | Wymaga wskazania kroku przy wykonaniu → opcjonalnie, nie wymuszać |

### 4.5 Archiwum — nostalgia bez creepy vibe

Zasada bezpieczeństwa emocjonalnego: **przypominamy tylko to, co użytkownik sam opublikował, tylko jego własne treści, zawsze z możliwością „nie przypominaj mi tego”.**

| Nazwa | Co widzi użytkownik | Dlaczego to działa emocjonalnie | Koszt | Kiedy | Ryzyka |
|---|---|---|---|---|---|
| **Archiwum po miesiącach jak stary fotoblog** | Na profilu zakładka `Archiwum`: „2027 — wrzesień (12), sierpień (9)…”, klik → siatka zdjęć z tego miesiąca | To najmocniejsza rzecz w Garnku, o której nikt nie mówił: własne życie w kuchni ułożone chronologicznie. Powód, by nie odejść: tu jest moja przeszłość | M | **MVP** (prosta wersja) | Przy 3 wpisach wygląda pusto → do 10 wpisów pokazywać zwykłą listę bez podziału na miesiące |
| **„Twój wrzesień 2027”** | Raz w miesiącu, w profilu (nie mailem): kafel „Twój wrzesień — 12 dań, najczęściej: zupy” + `[ Zobacz ]` | Podsumowanie własnego dorobku bez porównania z kimkolwiek. Duma bez rywalizacji | M | V1 | Nie robić z tego „Spotify Wrapped” z animacjami — to nie pasuje do 50+ i do spokojnego tonu |
| **„Rok temu gotowałaś…”** | Delikatny kafel na `/home`, maks. 1 raz w tygodniu: zdjęcie sprzed roku + „12 września 2026 zrobiłaś powidła. Znowu sezon.” | Sezonowość plus własna pamięć = najlepszy trigger powrotu, jaki ten produkt ma. Nostalgia + praktyczna podpowiedź | M | **JEST** (`app/Domain/Wspomnienia`, wpięte w `FeedController`; wyłącznik w `/ustawienia/prywatnosc`) | **Ryzyko żałoby**: wspomnienie może dotyczyć zmarłej osoby lub trudnego okresu. Obowiązkowo: `Ukryj to wspomnienie` i globalny wyłącznik w `/settings/privacy` |
| **Kalendarz roku gotowania** | Widok roczny: 365 kropek, kropka = dzień z wpisem. Bez oceniania, bez „serii” | Widok całego roku pokazuje, ile się w życiu ugotowało. Bez presji, bo puste dni nie są czerwone — są po prostu jaśniejsze | M | V2 | Łatwo zamienia się w streak-mechanikę → nigdy nie liczyć „najdłuższej serii”, nie kolorować pustek na czerwono |
| **„Ten przepis robisz od 4 lat”** | Na przepisie własnym: „Gotujesz to od 2026 — 11 razy” | Konfrontacja z własną wiernością potrawie jest zaskakująco wzruszająca | S | V1 | Brak |
| **Archiwum jako obietnica przy rejestracji** | W onboardingu jedno zdanie: „Twoje zdjęcia zostaną tu ułożone po datach. Za rok je znajdziesz.” + `Pobierz swoje dane` od pierwszego dnia | Ludzie 50+ boją się utraty zdjęć bardziej niż braku funkcji. To jest realny motyw rejestracji, mocniejszy niż „społeczność” | S | **MVP** | To obietnica — trzeba mieć backupy i restore drill (jest w roadmapie, punkt 11) |

### 4.6 Sezon i kalendarz polskiej kuchni

Sposób wplecenia: **sezon nigdy nie jest pop-upem ani banerem.** Sezon zmienia (a) pytanie dnia, (b) temat tygodnia, (c) jeden pasek w Discover, (d) treść digestu.

| Nazwa | Co widzi użytkownik | Dlaczego to działa emocjonalnie | Koszt | Kiedy | Ryzyka |
|---|---|---|---|---|---|
| **Kalendarz sezonowy jako dane, nie kod** | Nie widzi nic wprost. Widzi trafne pytania dnia i tematy tygodnia | Redakcja może zmieniać rok kulinarny bez deploya. Warunek konieczny, żeby reszta była tania | S (tabela `seasonal_moments`: data od–do, hasło, teksty) | **MVP** | Trzeba to ręcznie napisać na 12 miesięcy — jednorazowo ~1 dzień pracy redakcyjnej |
| **Pasek „Teraz sezon na…”** | W `/discover`: „**Teraz sezon na śliwki**” + 6 przepisów ze śliwkami od użytkowników | Rozwiązuje realny problem: mam skrzynkę śliwek, co z tym zrobić. Praktyczne, nie dekoracyjne | S | **MVP** | Przy pustej bazie pasek będzie pusty → fallback: pokazać zamiast tego temat tygodnia |
| **Temat tygodnia z sezonu** | Na `/home`: „**Temat tygodnia: przetwory ze śliwek.** Pokaż, co zamykasz w słoikach.” + `[ Dodaj ]` + wpisy innych | Wspólne zadanie w tym samym tygodniu tworzy poczucie, że wszyscy siedzą w jednej kuchni | S (mechanika) | **MVP** | Temat bez uczestników jest gorszy niż brak tematu → gospodarz zawsze publikuje pierwszy (patrz `COLD_START.md`) |
| **Wielkie momenty roku** | Osobne, mocniejsze oprawy dla: Wigilia, Wielkanoc, tłusty czwartek (4 II 2027), Boże Ciało, dożynki/wykopki, św. Marcin (11 XI, gęsina), Andrzejki, Dzień Babci (21 I) | To dni, w których cała Polska gotuje jednocześnie. Naturalny szczyt aktywności — wystarczy nie przeszkadzać | S–M | **MVP** dla Wigilii i tłustego czwartku, resztę dokładać | Przeciążenie świętami = kicz. Maks. 8–10 „wielkich momentów” w roku, resztę traktować jako zwykłe tematy |
| **Sezonowy blok w kreatorze przepisu** | Krok 1, delikatna podpowiedź: „Wrzesień — dużo osób szuka teraz przepisów na powidła i grzyby” | Podpowiedź, co warto opisać teraz, żeby ktoś to znalazł. Autor czuje, że jego przepis trafi na czas | S | V1 | Nie może wyglądać jak SEO-instrukcja („optymalizuj pod frazę”) |
| **Sezonowe kolekcje redakcyjne z treści użytkowników** | „Grzyby 2026 — 34 dania od 19 osób”, kolekcja prowadzona przez gospodarza | Bycie wybraną do kolekcji jest formą docenienia bez rankingu. Zero kosztu, duża wartość dla wybranego | S | **MVP** | Poczucie faworyzowania → zasada: w każdej kolekcji maks. 2 wpisy tej samej osoby |
| **Przypomnienie sezonowe z własnego archiwum** | „W zeszłym roku kisiłaś w trzecim tygodniu października. Zaczynasz?” | Połączenie sezonu z osobistą pamięcią — najsilniejszy trigger w całym produkcie | M | V1 | Jak przy „rok temu” — musi mieć wyłącznik |

### 4.7 Koła / grupy tematyczne (V1) — jak zapowiedzieć wcześniej

| Nazwa | Co widzi użytkownik | Dlaczego to działa emocjonalnie | Koszt | Kiedy | Ryzyka |
|---|---|---|---|---|---|
| **Tagi tematyczne jako protoplasta koła** | Przy wpisie opcjonalny temat: „chleb na zakwasie”, „przetwory”, „kuchnia śląska”. Strona tematu = lista wpisów + „Obserwuj temat” | Tanie 80% wartości grupy bez budowania grup: wspólne miejsce i możliwość obserwowania tematu, a nie tylko osób. Ratuje też feed przy zerowych obserwowanych | M | **MVP** | Rozsypanie tagów (100 wariantów „zakwas”) → **zamknięta lista ~30 tematów** wybierana z listy, bez wpisywania własnych, do V1 |
| **Zapisy na koło przed jego istnieniem** | Na stronie tematu: „**Koła powstaną w przyszłym roku.** Chcesz być w kole »Chleb i zakwas«? `[ Zapisz mnie ]` — 34 osoby już się zapisały” | Lista oczekujących buduje poczucie, że coś się dzieje, i daje twarde dane: które koła otwierać pierwsze. Zero kosztu produktowego | S | **MVP** | Obietnica z terminem → nie podawać daty, mówić „przygotowujemy” |
| **Gospodarz koła wyznaczony z góry** | Przy starcie koła: „to koło prowadzi Marek” | Grupa bez gospodarza umiera lub gnije. Nazwanie opiekuna z góry ustawia normy | S | V1 | Wypalenie gospodarza → maks. 2 koła na osobę |
| **Koła regionalne** | „Kuchnia podlaska”, „Kuchnia śląska”, „Kresowa” | Tożsamość regionalna w Polsce jest silniejsza niż tożsamość kulinarna. Kartacze i kluski śląskie to spory, w które ludzie wchodzą z sercem | M | V1 | Spory regionalne mogą się zaostrzać → moderacja i zasada „u nas robi się różnie” w regulaminie kultury |

### 4.8 Docenianie zamiast lajkowania

Nie mamy jednego uniwersalnego „serduszka”. Mamy **cztery czynności, każda z nazwą** (zgodne z `docs/UX_50_PLUS.md`: ważna akcja ma tekst).

| Przycisk | Co znaczy | Co dostaje autor |
|---|---|---|
| `Ugotowałem` | Zrobiłem to naprawdę | Pełnoekranowa karta + notyfikacja (najmocniejszy sygnał) |
| `Zapisuję` | Chcę to zrobić | „Basia zapisała Twój przepis” — cicha, zbiorcza notyfikacja |
| `Ładne!` | Doceniam, ale nie gotowałem | Zbiorczo: „7 osób doceniło Twoje zdjęcie” |
| `Pytanie do autora` | Chcę wiedzieć więcej | Wyróżnione powiadomienie, bo wymaga odpowiedzi |

| Nazwa | Co widzi użytkownik | Dlaczego to działa emocjonalnie | Koszt | Kiedy | Ryzyka |
|---|---|---|---|---|---|
| **Cztery nazwane akcje zamiast serca** | Rządek przycisków z tekstem, nie ikonek | „Ładne!” jest zrozumiałe dla każdego, kciuk w górę jest zapożyczeniem z Facebooka i nic nie znaczy. Nazwa czynności = intencja | S | **MVP** | Cztery przyciski to więcej UI → w feedzie pokazywać 2 główne (`Ugotowałem` / `Zapisuję`), reszta na stronie treści |
| **Licznik „Ładne!” niewidoczny publicznie** | Autor widzi „7 osób doceniło”. Inni nie widzą żadnej liczby | Nie da się porównać cudzych wyników z własnymi, jeśli liczb nie ma. To jedna decyzja, która wyłącza cały wyścig | S | **MVP** | Utrata sygnału do sortowania → sortujemy chronologicznie, więc nie potrzebujemy |
| **„Pytanie do autora” jako osobny typ** | Komentarz oznaczony jako pytanie, na stronie przepisu w sekcji „Pytania” z licznikiem bez odpowiedzi | Pytania nie giną w komentarzach, autor czuje się ekspertem, pytający dostaje odpowiedź. Buduje autorytet zwykłych ludzi | M | V1 (MVP: zwykły komentarz) | Brak odpowiedzi = widoczna porażka → nie pokazywać publicznie „bez odpowiedzi od 30 dni” |
| **Podziękowanie w jednym kliknięciu** | Pod komentarzem: `[ Dziękuję ]` — autor kwituje uwagę bez pisania | 65-latek nie zawsze chce pisać. Jedno słowo utrzymuje kulturę uprzejmości | S | V1 | Może zastąpić prawdziwe odpowiedzi → nie liczyć tego do metryki reply rate |

### 4.9 Głos i copy

| Nazwa | Co widzi użytkownik | Dlaczego to działa emocjonalnie | Koszt | Kiedy | Ryzyka |
|---|---|---|---|---|---|
| **Zwracanie się per „Ty” i po imieniu** | „Basiu, co dziś ugotowałaś?” — z poprawną polską formą | Wołacz i rodzaj gramatyczny to sygnał, że produkt jest polski, a nie tłumaczony. Bardzo tanio kupuje zaufanie | S (wymaga pola „jak się do Ciebie zwracać” + formy męska/żeńska) | **MVP** | Wołacz polski jest trudny („Basiu”, ale „Agnieszko”, „Piotrze”) → w razie wątpliwości używać samego imienia w mianowniku, nie kaleczyć |
| **Jeden redaktor językowy na wszystkie teksty** | Spójny ton we wszystkich 200 komunikatach interfejsu | Dusza rozsypuje się na błędach: jeden ekran ciepły, drugi „Wystąpił błąd walidacji”. Spójność jest treścią | S (proces, nie kod: plik `lang/pl` przeglądany przez jedną osobę) | **MVP** | Brak dyscypliny przy rozwoju → zasada w AGENTS.md: nowy tekst UI wymaga wpisu do słownika |
| **Błędy, które nie obwiniają** | „Nie udało się dodać zdjęcia, bo plik ma ponad 15 MB. Wybierz mniejsze — pomogę Ci to zrobić.” | Osoba 50+ przy błędzie zakłada, że to jej wina, i wychodzi. Zdanie „nie udało się” zamiast „podałeś złe dane” ratuje sesję | S | **MVP** | Brak (już w `UX_50_PLUS.md`) |
| **Brak słów z korpo-świata** | Nigdzie: „content”, „engage”, „explore”, „creator”, „feed” w widocznym tekście (w kodzie może zostać) | Jedno angielskie słowo w interfejsie mówi 60-latkowi: „to nie dla mnie” | S | **MVP** | „Feed” trudno zastąpić → używać „Start”, „Co nowego” |
| **Podpowiedzi pisane głosem człowieka** | Placeholder: „Napisz kilka słów — np. »zupa jak u mamy, tylko więcej pieprzu«” | Przykład uczy tonu skuteczniej niż instrukcja. Ludzie kopiują styl przykładu | S | **MVP** | Wszyscy piszą to samo → 5–6 rotujących przykładów |

### 4.10 Widoczność autorstwa i szacunek do treści

| Nazwa | Co widzi użytkownik | Dlaczego to działa emocjonalnie | Koszt | Kiedy | Ryzyka |
|---|---|---|---|---|---|
| **Autor przy każdym wystąpieniu przepisu** | Avatar + imię autora w feedzie, w search, w kolekcji, w kafelku sezonowym, w mailu. Nigdy „karta przepisu bez twarzy” | Autorstwo widoczne wszędzie = powód, żeby publikować. Portale SEO ukrywają autora — to nasza dokładna różnica | S | **MVP** | Brak |
| **„Moja wersja” zamiast kopiowania** | Pod cudzym przepisem: `[ Robię po swojemu ]` → nowy przepis z trwałym odnośnikiem „na podstawie przepisu Basi” | Rozwiązuje realny konflikt: ludzie chcą zmieniać przepisy, ale kopiowanie kradnie. Fork z podpisem daje jedno i drugie | M | V1 | Rozmnożenie wariantów → na oryginale pokazać „5 wersji tego przepisu” jako wartość, nie śmieć |
| **Kolekcje nie ukrywają autora** | W kolekcji każdy przepis ma podpis autora, także w widoku do druku | Kolekcje to typowe miejsce, gdzie autorstwo znika. U nas nie znika | S | **MVP** | Brak |
| **Jasna reguła przeciw wklejaniu cudzych treści** | Przy publikacji: „Wklejasz przepis z książki lub z internetu? Napisz, skąd jest — to szanujemy.” | Norma kultury wypowiedziana wcześnie działa lepiej niż moderacja później | S | **MVP** | Nie da się tego wymusić technicznie → moderacja reaktywna + zgłoszenie „to nie jest jego przepis” |
| **Źródło zewnętrzne jako pole** | Opcjonalne: „skąd: Kuchnia Polska, PWN 1985” | Podanie źródła jest formą uczciwości, którą można pokazać jako zaletę autora | S | **JEST** (`recipes.source_url`, w kreatorze i w wydruku) | Brak |
| **Widoczne „ugotowane z przepisu X” w SEO** | W strukturze strony przepisu i w schema.org — autor jest osobą, nie marką | Google i AI-search cytują autora. Autor widzi swoje nazwisko w wyszukiwarce = duma i powrót | S | **MVP** | Brak |

### 4.11 Puste stany i mikro-copy, które nie zawstydzają

| Miejsce | Zły tekst | Tekst Kuking |
|---|---|---|
| Feed bez obserwowanych | „Brak treści. Zacznij obserwować.” | „Jeszcze nikogo nie obserwujesz — pokażemy Ci więc, co dziś gotują inni.” + realne wpisy (nie pustka) |
| Profil bez wpisów (własny) | „Nie masz postów.” | „Tu będą Twoje dania, ułożone po datach. Zacznij od jednego zdjęcia — nie musi być ładne.” |
| Profil bez wpisów (cudzy) | „Ten użytkownik nie ma postów.” | „Marek jeszcze nic nie pokazał. Możesz go obserwować — dowiesz się, gdy coś ugotuje.” |
| Przepis bez wykonań | „0 ocen” | „Jeszcze nikt nie gotował z tego przepisu. Będziesz pierwsza?” |
| Brak wyników search | „Brak wyników dla zapytania.” | „Nie znaleźliśmy »kartaczy«. Może Ty je znasz? `[ Dodaj przepis na kartacze ]`” |
| Kolekcje puste | „Brak kolekcji.” | „Kolekcje to Twoje półki. Np. »Na niedzielę«, »Ciasta mamy«. `[ Utwórz pierwszą ]`” |
| Brak powiadomień | „Brak powiadomień.” | „Cicho tu. Gdy ktoś ugotuje z Twojego przepisu, dowiesz się pierwsza.” |
| Pierwsze zdjęcie słabej jakości | (nic albo ostrzeżenie o jakości) | Nigdy nie oceniamy jakości zdjęcia. Zero komunikatów typu „zdjęcie jest niewyraźne” |

| Nazwa | Co widzi użytkownik | Dlaczego to działa emocjonalnie | Koszt | Kiedy | Ryzyka |
|---|---|---|---|---|---|
| **Zasada „pusty stan to zaproszenie, nie komunikat o błędzie”** | Każdy pusty ekran ma: zdanie po ludzku + jedną konkretną akcję + żadnego obwiniania | Pustka jest najczęstszym momentem rezygnacji nowego użytkownika, zwłaszcza przy zerowej sieci | S | **MVP** | Trzeba to zrobić dla wszystkich ~12 pustych stanów, nie tylko dla feedu |
| **Brak wymogu ładnego zdjęcia** | Zero filtrów, zero „popraw zdjęcie”, zero podpowiedzi o kadrze | Wstyd przed nieładnym zdjęciem jest głównym hamulcem publikacji u 50+. Produkt musi milczeć o estetyce | S | **MVP** | Feed będzie brzydszy niż Instagram. To jest zamierzone i to jest przewaga |

### 4.12 Bezpieczna przestrzeń

| Nazwa | Co widzi użytkownik | Dlaczego to działa emocjonalnie | Koszt | Kiedy | Ryzyka |
|---|---|---|---|---|---|
| **Feed wyłącznie chronologiczny** | Kolejność = czas. Nigdy „polecane dla Ciebie” | Osoba z 4 obserwującymi ma taką samą szansę być zobaczona jak osoba z 4000. To warunek, żeby ktoś nie przestał publikować | S (i taniej niż algorytm) | **MVP** | Słabszy „wow” przy dużej skali → problem na 2028, nie teraz |
| **Brak globalnych rankingów i topek** | Nigdzie „najpopularniejsze przepisy tygodnia”, „top twórcy” | Ranking tworzy dwie klasy użytkowników i zabija publikowanie u 90% | S | **MVP** | Utrata łatwego contentu na stronę główną → zastąpione kolekcjami redakcyjnymi (ręczny wybór, rotacja osób) |
| **Liczba obserwujących ukryta lub dyskretna** | Na profilu: „Obserwuje 23 osoby” — bez licznika obserwujących na pierwszym planie | Licznik obserwujących zamienia gotowanie w wskaźnik popularności | S | **MVP** | Brak sygnału zaufania dla nowych → zastąpione przez „gotuje z nami od…” i liczbę wykonań |
| **Komentarze bez publicznej punktacji** | Brak lajków pod komentarzami, brak sortowania „najlepsze” | Punktacja komentarzy premiuje żarty i ciętość, a nie życzliwość | S | **MVP** | Brak |
| **Autor zarządza swoim polem** | Autor może ukryć komentarz pod swoim wpisem i zablokować osobę — bez tłumaczenia się | Poczucie kontroli nad własną kuchnią. Kluczowe dla kobiet 50+, które doświadczyły hejtu na FB | M | **MVP** (blok jest, ukrycie komentarza dołożyć) | Nadużycia (ukrywanie krytyki) → moderacja widzi ukryte, użytkownik nie |
| **Zasady kultury napisane po ludzku** | Krótka strona „Jak tu rozmawiamy” — 6 zdań, nie regulamin: „U nas robi się różnie. Nie poprawiamy cudzych przepisów bez pytania.” | Normy trzeba wypowiedzieć raz i głośno. Później moderacja tylko przypomina | S | **MVP** | Regulamin prawny to osobny dokument — nie mieszać |
| **Brak influencerów na górze** | Zero kont wyróżnionych, zero „partnerów”, zero promowanych przepisów | Obecność influencera zmienia zachowanie zwykłych ludzi natychmiast: przestają publikować | S (decyzja) | **MVP → zawsze** | Presja wzrostu w 2027 („zaprośmy znaną blogerkę”) → zapisać tę decyzję jako regułę produktu, nie preferencję |

### 4.13 Drobiazgi z dużym zwrotem

| Nazwa | Co widzi użytkownik | Dlaczego to działa emocjonalnie | Koszt | Kiedy | Ryzyka |
|---|---|---|---|---|---|
| **Nazwy dań pisane po polsku, z gwarą** | Akceptujemy „kartacze”, „pyzy”, „bandżorki”, „prażuchy” — search rozumie warianty (pg_trgm) | Regionalna nazwa własna to tożsamość. Poprawianie jej byłoby przemocą | S | **MVP** | Rozjazd nazw w search → synonimy jako dane, dokładane ręcznie |
| **„Na ile osób” w ludzkich jednostkach** | „na 4 osoby, u nas na 2 dni” | Tak mówią ludzie w domu, a nie „yield: 4 servings” | S | **MVP** | Brak |
| **Miary domowe** | „szklanka”, „łyżka”, „garść”, „na oko” jako dozwolone jednostki | Wymuszanie gramów wyklucza połowę autorów rodzinnych przepisów | S | **MVP** | Trudniejsze skalowanie porcji (V2) → świadomy kompromis na rzecz autentyczności |
| **Wydruk przepisu na kartkę** | `[ Wydrukuj ]` — czysty, duży druk, bez menu | Osoby 50+ gotują z wydruku albo z tabletu opartego o cukiernicę. Wydruk to realny sposób użycia | S (arkusz CSS do druku) | **MVP** | Brak |
| **Tryb gotowania (duże kroki, ekran nie gaśnie)** | Krok 1 z 6 na całym ekranie, wielka czcionka | Telefon gaśnie przy zabrudzonych rękach — najbardziej fizyczny problem w tym produkcie | M | **JEST** (`/przepisy/{przepis}/gotuj`, `CookingModeController`) | Wake Lock API — wsparcie przeglądarek nierówne, zrobić degradację |
| **Zaproszenie kogoś z rodziny** | „Zaproś córkę do Kuking” — link, bez wymuszania kontaktów | Rodzina jest naturalnym pierwszym gronem. Zaproszenie jednej osoby daje natychmiastową publiczność | S | **MVP** | Nie skanować książki adresowej — to zabija zaufanie u 50+ |
| **Odpowiedź gospodarza w pierwszej godzinie** | Nowy użytkownik po pierwszym wpisie dostaje komentarz od realnej osoby z redakcji | To nie jest funkcja, to obietnica operacyjna (patrz `COLD_START.md`), ale to ona buduje duszę mocniej niż cała reszta tej listy | S produktowo / **L operacyjnie** | **MVP** | Nieskalowalne powyżej ~500 osób → wtedy przejmują ambasadorzy kół |

---

## 5. Słownik języka Kuking

| Nie mówimy | Mówimy |
|---|---|
| Content, treści | dania, przepisy, zdjęcia |
| Feed | Start / Co nowego |
| Explore, Discover (w UI) | Zobacz, co się dzieje |
| Creator, twórca | autor, autorka |
| Polub, kciuk w górę | Ładne! |
| Dodaj do ulubionych | Zapisuję |
| Oceń przepis | Ugotowałem |
| Engagement, interakcje | rozmowa, odzew |
| Powiadomienie push | wiadomość od nas |
| Onboarding | pierwsze kroki |
| Wystąpił błąd (422) | Nie udało się — bo… |
| Twój profil jest niekompletny | Możesz dodać zdjęcie, jeśli chcesz |
| Zaproś znajomych | Zaproś kogoś z rodziny |
| Wyzwanie, challenge | temat tygodnia |
| Ranking, topka | kolekcja / co dziś gotowano |

---

## 6. Anty-wzorce — czego świadomie NIE robimy

| Nie robimy | Dlaczego to zabija duszę | Co robimy zamiast |
|---|---|---|
| **Streaki („gotujesz 7 dni z rzędu!”)** | Zamieniają gotowanie w obowiązek i karzą za chorobę, wyjazd, żałobę. Utrata serii = utrata użytkownika | Licznik dorobku („212 dań”), który nigdy nie spada |
| **Punkty, poziomy, badge za liczbę postów** | Premiują ilość, produkują spam, tworzą klasy użytkowników | Jednorazowe, nierywalizacyjne wyróżnienia: „Founding Cook”, „autorka przepisu” |
| **Algorytmiczny feed** | Osoba z małą siecią przestaje być widziana, więc przestaje publikować. Dla 50+ dodatkowo: nieprzewidywalność = utrata zaufania | Chronologia + tematy + Discover kuratorski |
| **Publiczne liczniki lajków** | Uruchamiają porównywanie się i wyścig. Najkrótsza droga od społeczności do Instagrama | Liczba wykonań (`Ugotowałem`) — jedyna widoczna liczba, bo mierzy przydatność, nie popularność |
| **Masowy import cudzych przepisów dla SEO** | Zabija powód, żeby pisać własne, i zamienia nas w Smakera bez społeczności | Wolny wzrost z prawdziwych przepisów, SEO jako skutek uboczny |
| **Przepisy generowane przez AI** | Jedna rzecz, której nie da się cofnąć: gdy zniknie zaufanie, że „to napisał człowiek”, dusza znika na zawsze | AI może pomagać wewnętrznie (moderacja, deduplikacja) — nigdy nie tworzy treści widocznej jako czyjaś |
| **Influencerzy / partnerzy na górze feedu** | Zwykli ludzie natychmiast przestają publikować, gdy obok stoi profesjonalne zdjęcie | Rotacyjne kolekcje redakcyjne z treści zwykłych użytkowników |
| **Nagabywanie o dokończenie profilu** | Poczucie, że jest się niekompletnym, wypycha ludzi 50+ z produktu | Profil działa pusty; podpowiedzi tylko w jednym miejscu, raz |
| **Powiadomienia o cudzej aktywności bez związku ze mną** | „Marek dodał zdjęcie” × 30 dziennie = wyłączenie powiadomień na zawsze | Powiadomienia tylko o rzeczach dotyczących mnie + tygodniowy digest (patrz `RETENTION_LOOPS.md`) |
| **Nieskończone przewijanie bez końca strony** | Brak poczucia zakończenia = zmęczenie i poczucie straconego czasu | Feed z jawnym końcem: „To wszystko z dzisiaj. `[ Zobacz starsze ]`” |
| **Oznaczanie produktu jako „dla seniorów”** | Nikt nie chce być seniorem. Basia ma 61 lat i uważa, że seniorzy to jej mama | Po prostu bardzo czytelny interfejs (zgodnie z `UX_50_PLUS.md`) |
| **Wymuszanie pełnego przepisu, by pokazać zdjęcie** | Podnosi próg publikacji z 60 sekund do 20 minut; większość odpada | Wpis = zdjęcie + kilka słów. Przepis to osobne, dobrowolne flow |
| **Ocena gwiazdkowa przepisów** | Zaprasza do karania autora za własną pomyłkę w kuchni | „Zrobię ponownie: 10 z 12 osób” + uwagi z wykonań |
| **Automatyczne „wspomnienia” bez wyłącznika** | Wspomnienie może dotyczyć osoby, która umarła. Bez wyłącznika to okrucieństwo | Wspomnienia z `Ukryj to wspomnienie` i globalnym przełącznikiem w ustawieniach |
| **Sortowanie zdjęć wykonań „od najlepszych”** | Wstyd początkującego = brak drugiego wykonania | Chronologicznie, zawsze |

---

## 7. „Soul-pack MVP” — minimum, które daje duszę

Rzeczy z tej listy, które **muszą** być w MVP, bo bez nich Kuking jest CRUD-em. Łącznie ok. 8–11 dni pracy poza tym, co i tak jest w backlogu.

| # | Mechanika | Koszt | Bez tego… |
|---|---|---|---|
| 1 | Pytanie dnia „Co dziś ugotowałeś?” na górze `/home` | S | …feed jest biernym ekranem do przewijania |
| 2 | Ekran „Komuś wyszło” (pełnoekranowa karta wykonania) | M | …`Ugotowałem` jest zwykłą notyfikacją i nie wzrusza |
| 3 | Pole „Po kim ten przepis” + podpis oryginalnego autora | S | …nie mamy rodzinnych receptur, czyli głównej przewagi |
| 4 | Pole „Skąd ten przepis” pokazywane przed składnikami | S | …jesteśmy bazą przepisów |
| 5 | „W rodzinie od [rok]” + skan zeszytu jako zdjęcie | S | …tracimy najbardziej wzruszającą treść, jaką ludzie mają w szufladach |
| 6 | Archiwum profilu po miesiącach | M | …nie ma powodu, żeby zostać na dłużej niż tydzień |
| 7 | Cztery nazwane akcje (`Ugotowałem`/`Zapisuję`/`Ładne!`/`Pytanie`) + brak publicznych liczb | S | …stajemy się małym Instagramem |
| 8 | Tematy tygodnia + kalendarz sezonowy jako dane | S | …nie ma rytmu ani wspólnego zajęcia |
| 9 | Tematy tematyczne (zamknięta lista ~30) + zapisy na przyszłe koła | M | …feed nowego użytkownika jest pusty, a my nie wiemy, jakie koła otwierać |
| 10 | Wszystkie puste stany napisane po ludzku | S | …nowi odpadają w pierwszych 2 minutach |
| 11 | Wołacz/imię i spójny słownik języka w `lang/pl` | S | …produkt brzmi jak tłumaczenie z angielskiego |
| 12 | Wydruk przepisu | S | …ignorujemy sposób, w jaki 50+ naprawdę gotuje |

**Świadomie odsunięte:** planner, lista zakupów, koła, „Moja wersja”, OCR, spiżarnia, AI, native apps, PDF rodzinnej książki, kalendarz roku, uwagi wpięte do kroków.

> **Tryb gotowania wypadł z tej listy, bo go zrobiono** (audyt zewnętrzny, G16).
> Stoi pod `/przepisy/{przepis}/gotuj` razem z minutnikiem kroku. Lista
> „świadomie odsunięte” ma znaczyć „postanowiliśmy tego nie robić”, a nie
> „ktoś nie zaktualizował dokumentu” — inaczej następna osoba albo zbuduje to
> drugi raz, albo uzna, że skoro jedna pozycja jest nieprawdziwa, to cała lista
> jest nieaktualna.
