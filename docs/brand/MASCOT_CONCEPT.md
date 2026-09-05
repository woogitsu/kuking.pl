# Kuking — maskotka, korona i wordmark

Dokument rozwija pomysł właściciela: w słowie **KU-KING** siedzi **KING**. Sprawdzamy, czy da się z tego zrobić
system wizualny, który zadziała na dorosłego, polskiego odbiorcę 50+ — i gdzie ten pomysł może się wywrócić.

Stan: propozycja strategiczna do decyzji. Nie modyfikuje `docs/BRAND.md`.

---

## 0. Wniosek w pięciu linijkach

1. Maskotka **ma sens**, ale nie jako „zabawny bohater portalu" — jako **stały gospodarz miejsca**, obecny głównie tam, gdzie użytkownik jest niepewny (pusty ekran, błąd, pierwszy raz).
2. Korona **ma sens tylko jako żart odkrywany**, nie jako komunikat. Wolno ją zobaczyć; nie wolno jej ogłaszać. „Jesteś królem kuchni!" to dokładnie ten pretensjonalny rejestr, który 50+ odrzuca.
3. Zwycięzca: **Garnuś** — garnek z koroną zamiast pokrywki. Rzecz, nie osoba: zero ryzyka stereotypizacji wieku, płci i regionu.
4. Zapasowy: **Warzecha** — drewniana łyżka z koroną. Najmocniejszy kulturowo, najsłabszy w 32 px.
5. Podstawą identyfikacji zostaje **wordmark `KUKING`** (zgodnie z `docs/BRAND.md`). Maskotka jest dodatkiem do niego, nigdy jego zamiennikiem.

---

## 1. Uzasadnienie strategiczne

### 1.1 Dlaczego maskotka pomaga właśnie tej grupie

| Mechanizm | Co daje w Kuking |
|---|---|
| **Antropomorfizacja obniża lęk przed technologią** | Badania nad asystentami głosowymi u osób starszych pokazują, że antropomorfizacja podnosi *postrzeganą użyteczność*, *postrzaganą łatwość użycia* i poczucie bliskości, a technofobia i zaufanie wzajemnie się znoszą — wyższe zaufanie realnie obniża technofobię. Stała, przyjazna figura na ekranie jest tanim nośnikiem tego zaufania. |
| **„Ktoś mnie tu wita"** | Persona Basia (61) ma wrzucić zdjęcie w minutę. Pusty ekran bez człowieka po drugiej stronie czyta się jak formularz urzędowy. Figura na pustym stanie zmienia „nic tu nie ma" w „jeszcze nie zacząłeś". |
| **Rozpoznawalność offline** | Grupa 50+ dowiaduje się o rzeczach z ulotki w bibliotece, z koła gospodyń, z gazetki parafialnej, od sąsiadki. Znak, który da się wydrukować na naklejce 3 cm i nadal jest rozpoznawalny, to realny kanał akwizycji. Wordmark sam tego nie robi tak dobrze jak wordmark + prosty sygnet. |
| **Ekonomia uwagi w małych rozmiarach** | Favicon, ikona PWA na pulpicie telefonu, avatar w powiadomieniu, miniatura w Google Discover. Wszędzie tam sześć liter „KUKING" jest nieczytelne. Sygnet jest. |
| **Sygnał „to nie korporacja"** | Cookpad ma 100 mln użytkowników i wygląda jak platforma. Kuking ma wyglądać jak miejsce. Ręcznie wyglądająca figura mówi „prowadzą to ludzie" bez pisania tego w copy. |

### 1.2 Kiedy maskotka **zaszkodzi** — warunki odrzucenia

Każdy z tych punktów to samodzielny powód, by projekt maskotki wstrzymać:

1. **Maskotka zaczyna mówić w pierwszej osobie.** Wtedy staje się asystentem, a asystent, którego się nie prosiło, u dorosłego czyta się jak opiekun. Badania nad interfejsami dla osób starszych wprost notują, że **zabawkowe („toy-like") asystenty były odbierane jako infantylizujące**.
2. **Maskotka wchodzi w rolę egzekutora nawyku.** Duolingo zbudowało na tym markę: powiadomienia od „gentle reminders" przeszły w pasywno-agresywne („Te przypomnienia chyba nie działają, przestaniemy je wysyłać"), smutną sowę i mem „evil Duo". To działa na 20-latka, który traktuje to jako grę. Na osobie 60+, dla której aplikacja to nowe i niepewne terytorium, ten sam ton produkuje wstyd i porzucenie konta. **Passy, serie, straszenie utratą — zakazane.**
3. **Maskotka jest „seniorem".** Postać z siwymi włosami, laską, okularami na czubku nosa albo „babcia w chustce" to komunikat „to portal dla starych". `docs/UX_50_PLUS.md` mówi wprost: produkt nie jest oznaczany jako „dla seniorów". Maskotka nie może tego oznaczenia przemycić.
4. **Maskotka staje się głównym znakiem.** `docs/BRAND.md`: na start wystarczy mocny wordmark. Jeśli sygnet zaczyna wypierać `KUKING`, tracimy nazwę, czyli najcenniejszy zasób.
5. **Przesyt.** Maskotka na każdym ekranie przestaje być gospodarzem, staje się tapetą, a potem irytacją. Reguła: **maskotka nie pojawia się na ekranach, na których użytkownik pracuje** (dodawanie wpisu, edycja przepisu, feed). Tylko tam, gdzie czeka, błądzi albo kończy.
6. **Koszt utrzymania rośnie ponad wartość.** Jedna postać w 12 pozach to 12 plików do utrzymania w spójnym stylu przy każdej zmianie palety. Limit MVP: **3 ilustracje**. Więcej dopiero, gdy dane pokażą, że pomagają.

### 1.3 Czego uczą maskotki, które działają na dorosłych

| Marka | Co przenosimy |
|---|---|
| **Michelin — Bibendum (od 1898)** | Przeżył 125 lat, bo z „człowieka z opon" (argument produktowy) stał się nośnikiem *wartości abstrakcyjnej* — jowialnej kompetencji i zaufania. Zmiany były ewolucyjne (zniknęły okulary, cygaro, część opon), sylwetka została. Wniosek dla nas: **projektuj sylwetkę, nie detal**, i planuj 10 lat, nie jeden sezon. |
| **GitHub — Octocat** | Sylwetka rozpoznawalna w 16 px; „jedna rzecz, która zawsze czyta" to tęgi obrys i macki. GitHub trzyma maskotki jako *element graficzny* w brand toolkicie, obok, a nie zamiast logo. Wniosek: **jeden nieusuwalny detal-podpis** (u nas: trzy zęby korony na naczyniu) + maskotka obok wordmarku. |
| **Reddit — Snoo** | Prostota umożliwia remiks: każdy subreddit ma swojego Snoo. Wniosek: jeśli kiedyś będą grupy/koła gospodyń, prosta forma pozwoli na lokalne warianty (Garnuś z liściem laurowym dla grupy zielarskiej) — ale **dopiero po MVP**. |
| **Mailchimp — Freddie** | Freddie żyje głównie w pustych stanach i momentach sukcesu, jako komponent systemu ilustracji (spójny „house style", themowalny). Wniosek: **maskotka to komponent design systemu, nie ozdoba**; obecna też w błędach, nie tylko w sukcesie. |
| **Duolingo — Duo** | Antywzorzec dla nas. Pokazuje, że maskotka może nieść *presję*. Świadomie z tego rezygnujemy. |
| **Cookpad** | Wybrał drogę bez maskotki: wordmark + mocny pomarańcz + zdjęcia użytkowników. Dowód, że da się bez. Dlatego w tym dokumencie jest ścieżka awaryjna (koncept K6/K7 — sam znak, bez postaci). |

### 1.4 Polski kontekst spożywczy — co czyta 50+

Obserwacje o polskim rynku (weryfikowalne wzrokowo na półce, część danych rynkowych oznaczona):

- **Kucharek** (Prymat, receptura z 1995) — „najpopularniejsza przyprawa uniwersalna w Polsce", kojarzona z polskością; średnio co druga osoba w Polsce ma z nią kontakt przynajmniej raz w roku [do weryfikacji — dane z materiałów własnych marki]. Marka niesie *figurę kucharza* i mimo tego nie jest odbierana jako dziecinna, bo postać jest **stylizowana i statyczna**, nie kreskówkowa, i nie „gada" do klienta. To najbliższy nam precedens.
- **Winiary, Delecta, Prymat, Łowicz** — dominuje mocny wordmark + zdjęcie produktu, bez postaci-bohatera [do weryfikacji na aktualnych opakowaniach]. Dla 50+ wiarygodność niosą tu **nazwa i ciągłość**, nie bohater.
- **Wedel** — tożsamość opiera się na *historycznym podpisie E. Wedel*, a nie na maskotce; Ptasie Mleczko dostaje serie ilustracji od zaproszonych ilustratorów. Wniosek: **ilustracja jako sezonowa warstwa nad stałym znakiem** to sprawdzony polski model.
- Co polski odbiorca 50+ czyta jako **dziecinne**: duże oczy z połyskiem, kreskówkowe rączki i nóżki w rękawiczkach, wykrzykniki, emoji w interfejsie, „kawaii" proporcje (głowa większa niż resztę), pastelowy róż/mięta, mowa w pierwszej osobie („Cześć, jestem Garnuś!"), Comic Sans i jego pochodne.
- Co czyta jako **swoje**: ceramika, drewno, emalia, kolor rosołu i pomidorówki, gruby czytelny krój, brak połysku, brak gradientów, jedno duże zdjęcie jedzenia.

### 1.5 Symbolika korony w Polsce — kiedy działa, kiedy uwiera

**Czytelna i sympatyczna, gdy:**
- korona jest **na rzeczy, nie na człowieku** („ten garnek jest tu królem") — czyta się jako żart o kuchni, nie o użytkowniku;
- jest **mała i pojedyncza** — trzy zęby, płasko, bez klejnotów, bez futra, bez purpury;
- ma **funkcję konstrukcyjną** — jest pokrywką garnka, grzebieniem kury, końcem trzonka. Korona, która czemuś służy, przestaje być ozdobą i staje się dowcipem.

**Pretensjonalna i ryzykowna, gdy:**
- jest **korona zamknięta / cesarska z krzyżem, z gronostajem, ze złotym gradientem** — natychmiast rejestr „premium/szlacheckie/aspiracyjne", czyli obcy dla „gotujemy po swojemu";
- towarzyszy **orłowi** lub układa się w **biało-czerwoną tarczę**. To nie jest kwestia gustu, a prawa: **Orzeł Biały w koronie na czerwonym polu jest godłem RP**, chronionym ustawą z 31 stycznia 1980 r. o godle, barwach i hymnie RP. Korona wróciła do godła 31 grudnia 1989 r. i jest w polskim odbiorze silnie upaństwowiona i upolityczniona. **Twarda reguła: żadnego orła, żadnej tarczy, żadnego zestawu biały-na-czerwonym.**
- jest użyta jako **komplement dla użytkownika** („Zostań królem kuchni", „Twoja korona"). W polskim uchu to marketingowe klepnięcie po plecach. Grupa 50+ jest na to wyczulona bardziej niż młodsza.

**Reguła projektowa:** korona Kuking jest **otwarta, trójzębna, płaska, jednokolorowa (miodowe złoto), bez klejnotów** i zawsze **wtopiona w przedmiot kuchenny**.

---

## 2. Siedem konceptów maskotki

Skala ocen: **1–5** (5 = najlepiej). Kryteria: *50+* (czy dorosły odbiorca 50+ przyjmie to bez zażenowania), *nie-dziecinne*, *rysowalność* (czy jeden ilustrator utrzyma to w wielu pozach), *unikalność*, *32 px* (czy sylwetka żyje w faviconie).

---

### K1. **Garnuś** — garnek z koroną zamiast pokrywki

- **Co to jest:** garnek dwuuszny, taki jak stoi na każdej polskiej kuchence. Zamiast pokrywki ma płaską trójzębną koronę. Twarz jest minimalna: dwie kropki oczu i krótki łuk uśmiechu, obecne tylko w rozmiarach ≥64 px.
- **Dlaczego korona ma sens:** to jedyny koncept, w którym korona jest **rozwiązaniem konstrukcyjnym, a nie dodatkiem** — zajmuje miejsce pokrywki, więc nie da się jej odjąć bez zrobienia dziury. Dodatkowo semantycznie: garnek jest w tej kuchni królem, bo w nim powstaje jedzenie. Żart jest o kuchni, nie o użytkowniku.
- **Osobowość (3 zdania):** Garnuś stoi, nie biega. Jest cierpliwy — czeka, aż coś się ugotuje, i tak samo czeka, aż użytkownik wrzuci pierwsze zdjęcie. Nie ocenia tego, co w nim wylądowało.
- **Jak wygląda:** korpus — prostokąt z mocno zaokrąglonymi dolnymi narożnikami, szerszy niż wysoki (proporcja ok. 3:2), kolor głęboki amber `#C8781E`. Dwa ucha: poziome zaokrąglone kapsułki po bokach, w ciemniejszej cegle `#A8452F`, wystające ok. 9% szerokości korpusu. Nad korpusem, po wąskiej przerwie, pozioma opaska w miodowym złocie `#E0A62B`, a z niej trzy trójkątne zęby korony (środkowy wyższy o ok. 20%), z opcjonalnymi kulkami-perłami na czubkach w cegle. Twarz: dwie okrągłe kropki w ciepłej czerni `#23201C` w górnej trzeciej korpusu, pod nimi łuk uśmiechu grubości 6% szerokości korpusu, bez zakończeń ostrych. Bez konturu, bez gradientu, bez cienia. Wariant „pełny" (do ilustracji ≥256 px) dostaje dwie cienkie smugi pary wychodzące spomiędzy zębów korony.
- **Gdzie występuje:** favicon i ikona PWA (wariant uproszczony), pusty feed, pusty zeszyt, ekran po pierwszej publikacji, 404, karta OG, stopka e-maila, naklejka.
- **Ryzyka:** (a) garnek z twarzą to popularny motyw w stockowej grafice kulinarnej — unikalność zapewnia dopiero korona-pokrywka i konkretna paleta, samo „garnek z oczami" jest generyczne; (b) przy złych proporcjach robi się „garnek-dziecko" — obrona: korpus szerszy niż wysoki, mała twarz, żadnych rączek i nóżek; (c) uszy giną poniżej 48 px — dlatego istnieje osobny wariant faviconowy z pogrubionymi uszami.
- **Oceny:**

| 50+ | nie-dziecinne | rysowalność | unikalność | 32 px | Σ |
|---|---|---|---|---|---|
| 5 | 4 | 5 | 4 | 5 | **23** |

---

### K2. **Kuka** — kura z koroną („kuking" ↔ „kurka")

- **Co to jest:** przysadzista kura, u której **grzebień jest koroną**. Gra słowna KU-KING / kur-ka / „ku-ku" jest dla polskiego ucha natychmiastowa.
- **Dlaczego korona ma sens:** grzebień kury *już jest* zębaty i czerwony — zamiana go w złotą trójzębną koronę to najmniejsza możliwa ingerencja o największym efekcie „aha".
- **Osobowość:** Kuka jest rzeczowa i trochę zaczepna. Wie, co robi w kuchni, i nie owija w bawełnę. Bardziej gospodyni na targu niż bohaterka bajki.
- **Jak wygląda:** korpus — elipsa leżąca (szerokość do wysokości ok. 5:4) w kremowym beżu `#E6DCCB`. Głowa: okrąg o promieniu ok. 45% wysokości korpusu, wsunięty w prawą górną część korpusu, bez szyi. Dziób: krótki trójkąt w amber, skierowany poziomo. Oko: jedna kropka w ciepłej czerni. Na głowie trójzębna złota korona zamiast grzebienia, szeroka na ok. 60% średnicy głowy. Ogon: trzy nachodzące na siebie ostre pióra w cegle, odchylone do tyłu pod 35°. Nogi: dwie krótkie kreski w amber, grubość 5% wysokości korpusu. Bez skrzydeł w wersji ikonowej.
- **Gdzie występuje:** ten sam zestaw co K1; dodatkowo dobrze działa jako naklejka i pieczątka.
- **Ryzyka:** (a) **kura ciąży w stronę „wiejskie/folk", a z folku do dziecinnego jest jeden krok** — łatwo wyląduje w estetyce przedszkolnej wyprawki; (b) kura to zwierzę, które w tej kategorii się **je** — dla części odbiorców maskotka-kura na portalu kulinarnym jest niesmaczna (analogiczny problem mają marki mięsne z uśmiechniętymi świnkami); (c) sylwetka z ogonem i nogami ma dużo cienkich elementów — w 32 px zostaje kleks z żółtym czubkiem.
- **Oceny:**

| 50+ | nie-dziecinne | rysowalność | unikalność | 32 px | Σ |
|---|---|---|---|---|---|
| 4 | 3 | 3 | 3 | 3 | **16** |

---

### K3. **Warzecha** — drewniana łyżka z koroną

- **Co to jest:** drewniana łyżka kuchenna (staropolskie *warzecha*) z małą koroną nad czerpakiem. Nazwa jest prawdziwym polskim słowem, brzmi dorośle i nie jest zdrobnieniem.
- **Dlaczego korona ma sens:** drewniana łyżka to **berło polskiej kuchni** — narzędzie władzy tego, kto stoi przy garnku. Korona nad nią jest dowcipem oczywistym i ciepłym, bez cienia pretensjonalności.
- **Osobowość:** Warzecha jest wysłużona i spokojna. Ma przypalony bok i nie wstydzi się tego. Mówi mało, bo była już przy tysiącu obiadów.
- **Jak wygląda:** trzonek — wąski pionowy prostokąt z zaokrąglonymi końcami, kolor drewna `#B07A42`, wysokość ok. 55% całości, szerokość 7% wysokości. Czerpak: elipsa (szerokość do wysokości 5:4) w jaśniejszym drewnie `#C08A50`, z wewnętrznym cieniem w postaci mniejszej elipsy `#A86F38` przesuniętej o 8% w dół. Nad czerpakiem, w odstępie równym 5% wysokości, trójzębna złota korona szeroka na 55% czerpaka. Twarz opcjonalna: dwie kropki na czerpaku. Charakterystyczny detal-podpis: jedna wyraźna, nieregularna szczerba na krawędzi czerpaka po prawej — znak używania.
- **Gdzie występuje:** świetny jako *pionowy* akcent — pasek boczny, bookmark, zakładka, sygnet na wizytówce, wzór na fartuchu. Słabszy jako favicon.
- **Ryzyka:** (a) **sylwetka pionowa i chuda — najgorszy możliwy kształt dla kwadratowej ikony**; w 32 px zostaje pionowa kreska; (b) „drewniana łyżka" ma w polskiej pamięci drugie znaczenie (narzędzie karcenia dzieci) — u części odbiorców 60+ może uruchomić skojarzenie z klapsem; (c) nazwa *Warzecha* jest też popularnym nazwiskiem — zderzenie z realną osobą publiczną wymaga sprawdzenia [do weryfikacji].
- **Oceny:**

| 50+ | nie-dziecinne | rysowalność | unikalność | 32 px | Σ |
|---|---|---|---|---|---|
| 5 | 5 | 4 | 4 | 3 | **21** |

---

### K4. **Majster Kuking** — kucharz-król (postać ludzka)

- **Co to jest:** popiersie kucharza, u którego czapka kucharska przechodzi w koronę (opaska korony u dołu czapki, zęby wychodzące z jej góry).
- **Dlaczego korona ma sens:** najbardziej dosłowna realizacja „KING" — kucharz, który jest królem. Precedens polski: Kucharek utrzymuje figurę kucharza w znaku od lat i nie jest odbierany jako dziecinny.
- **Osobowość:** Majster jest fachowy i oszczędny w słowach. Traktuje rozmówcę jak równego sobie, nie jak ucznia. Nie chwali na siłę.
- **Jak wygląda:** popiersie frontalne, bez rąk. Czapka: pękaty owal u góry, u dołu prosta opaska w miodowym złocie z trzema małymi zębami. Twarz: koło, rysy zredukowane do dwóch kropek i poziomej kreski wąsa. Kitel: trapez z dwoma guzikami. Kolory: kitel `#F7F4EE`, opaska `#E0A62B`, kontur i rysy `#23201C`, skóra — celowo **nie**naturalna, ciepły beż `#E6DCCB`, żeby nie deklarować rasy ani wieku.
- **Gdzie występuje:** nagłówki edukacyjne („Jak dodać przepis"), materiały drukowane, ewentualnie nadawca e-maili.
- **Ryzyka:** **najwyższe ryzyko całego zestawu.** (a) Każda postać ludzka natychmiast deklaruje **płeć, wiek, karnację i klasę** — czterokrotna okazja do wykluczenia części odbiorców; przy portalu, którego trzon to gotujące kobiety 50+, uśmiechnięty pan w czapce z koroną jest wprost nietrafiony. (b) Postać w czapce kucharskiej mówi „profesjonalna gastronomia", a Kuking jest o **domowym** gotowaniu — sprzeczność z pozycjonowaniem. (c) Twarze są najdroższe w utrzymaniu: każda nowa poza wymaga rysownika, który utrzyma tę samą twarz. (d) W 32 px twarz staje się plamą. (e) Kucharz + korona to najbardziej zatłoczona przestrzeń znaków w kategorii gastronomicznej — ryzyko kolizji rośnie.
- **Oceny:**

| 50+ | nie-dziecinne | rysowalność | unikalność | 32 px | Σ |
|---|---|---|---|---|---|
| 3 | 4 | 2 | 2 | 2 | **13** |

---

### K5. **Król Pieróg** — pieróg w koronie

- **Co to jest:** pojedynczy pieróg (półkole z falbanką zlepienia) z małą koroną na grzbiecie.
- **Dlaczego korona ma sens:** pieróg jest w polskiej kuchni potrawą-królem — pozycja bezdyskusyjna, dowcip zrozumiały bez tłumaczenia w każdym wieku i regionie.
- **Osobowość:** Król Pieróg jest samowystarczalny i dobroduszny. Nie musi nikomu niczego udowadniać, bo wszyscy i tak wiedzą. Ma poczucie humoru.
- **Jak wygląda:** półkole (płaska krawędź u dołu) w kremowym cieście `#EFDFC0`, z konturem `#23201C` o grubości 2,5% szerokości. Falbanka: pięć–sześć małych półkoli wzdłuż dolnej krawędzi. Na górnym łuku, wtopiona w kształt, trójzębna złota korona szeroka na 40% pieroga. Twarz: dwie kropki. Opcjonalnie kleks śmietany po lewej u dołu.
- **Gdzie występuje:** doskonały do **kampanii i offline'u** (naklejki, konkursy, „Tydzień pieroga"), słaby jako stały znak.
- **Ryzyka:** (a) **zawęża markę do jednej potrawy** — Kuking ma być o wszystkim, co się gotuje, a pieróg zamyka nas w kuchni tradycyjnej; (b) pieróg to potrawa regionalna sporna z Ukrainą, Słowacją, Rosją — niepotrzebna warstwa polityczna; (c) rejestr komediowy: pieróg z twarzą jest zabawny raz, przy piątym kontakcie robi się głupkowaty; (d) w 32 px półkole z falbanką czyta się jak nieokreślona plama.
- **Oceny:**

| 50+ | nie-dziecinne | rysowalność | unikalność | 32 px | Σ |
|---|---|---|---|---|---|
| 4 | 3 | 4 | 5 | 3 | **19** |

---

### K6. **Korona Kuking** — sam znak, bez postaci

- **Co to jest:** rezygnacja z maskotki. Trójzębna korona, w której **zęby są smugami pary**, a opaska jest krawędzią naczynia widzianą z profilu. Czysty znak graficzny.
- **Dlaczego korona ma sens:** korona zostaje jedynym nośnikiem żartu „KING" i nie musi już nikogo udawać. Zero ryzyka infantylizacji, bo nie ma na czym jej zawiesić.
- **Osobowość:** nie ma osobowości — i to jest cecha, nie brak. Znak jest neutralny, całą ciepłotę niosą copy i zdjęcia użytkowników. Model Cookpada.
- **Jak wygląda:** dolna pozioma opaska (prostokąt zaokrąglony) w amber, z niej trzy falujące, zwężające się ku górze smugi w miodowym złocie — środkowa najwyższa, boczne odchylone o 12° na zewnątrz. Całość wpisana w kwadrat, marginesy 10%. Wariant mono: te same kształty w jednym kolorze.
- **Gdzie występuje:** wszędzie, gdzie znak — favicon, PWA, OG, papier. Nie występuje w pustych stanach (nie ma czym mówić „jeszcze nie zacząłeś").
- **Ryzyka:** (a) tracimy funkcję „ktoś mnie tu wita", czyli główny argument z sekcji 1.1 — puste stany zostają zilustrowane ikoną, czyli chłodniej; (b) **korona to jeden z najbardziej wyeksploatowanych motywów w znakach towarowych** — unikalność niska, ryzyko kolizji najwyższe z całego zestawu (patrz sekcja 6); (c) sama korona bez kontekstu kuchennego bywa czytana jako „premium/luksus", czyli nie o nas.
- **Oceny:**

| 50+ | nie-dziecinne | rysowalność | unikalność | 32 px | Σ |
|---|---|---|---|---|---|
| 4 | 5 | 5 | 2 | 5 | **21** |

---

### K7. **K-korona** — litera K jako korona (monogram)

- **Co to jest:** litera `K` obrócona o 90° w lewo: pionowa belka staje się poziomą opaską, a dwa ramiona wychodzą do góry jako zęby korony. Trzeci, środkowy ząb domyka kształt. Powstaje znak, który jest jednocześnie `K` i koroną.
- **Dlaczego korona ma sens:** korona nie jest dodana do niczego — **jest zrobiona z pierwszej litery nazwy**. To najczystsza możliwa realizacja pomysłu „w KUKING siedzi KING", bo litera i korona to jeden obiekt.
- **Osobowość:** techniczna elegancja zamiast ciepła. Znak, nie bohater.
- **Jak wygląda:** pozioma belka o proporcji 5:1 (amber), z jej środka i końców wyprowadzone trzy zwężające się ku górze zęby o kątach 0°, ±18°, wysokość zęba środkowego 1,1× bocznych. Rogi zaokrąglone promieniem równym 25% grubości belki — żeby nie było ostro/heraldycznie. Wariant „dowód, że to K": w materiałach edukacyjnych pokazujemy animację/serię 3 klatek obracającą znak w literę.
- **Gdzie występuje:** favicon, PWA, avatar domyślny (K na kremowym kółku), pieczątka, znak wodny na zdjęciach — czyli wszystkie miejsca **znaku**, w komplecie z wordmarkiem.
- **Ryzyka:** (a) **to nie maskotka** — nie rozwiązuje zadania „ktoś mnie tu wita", więc nie może wygrać tej konkurencji; (b) monogram literowy jest tym, co robi każda marka — unikalność średnia; (c) obrót litery bywa nieczytelny bez podpowiedzi, a `docs/UX_50_PLUS.md` zabrania wymagania domyślania się znaczeń.
- **Oceny:**

| 50+ | nie-dziecinne | rysowalność | unikalność | 32 px | Σ |
|---|---|---|---|---|---|
| 4 | 5 | 5 | 3 | 5 | **22** |

---

### Kierunek odrzucony bez rozwijania: **kot kuchenny w koronie**

| Koncept | Powód odrzucenia | 50+ | nie-dziecinne | rysowalność | unikalność | 32 px | Σ |
|---|---|---|---|---|---|---|---|
| Kot w koronie („Mruczek") | Kot na blacie kuchennym to dla wielu osób gotujących kwestia **higieny**, nie sympatii — maskotka wywołuje odruch „zejdź ze stołu". Dodatkowo kot w koronie jest jednym z najbardziej wyeksploatowanych motywów internetowych i ciągnie estetykę wprost w rejestr memiczny/dziecinny. Nie wnosi też nic z pola *gotowania* — kot nie gotuje. | 2 | 2 | 3 | 2 | 4 | **13** |

---

### Zestawienie

| # | Koncept | 50+ | nie-dziec. | rysow. | unikal. | 32 px | Σ | Rola |
|---|---|---|---|---|---|---|---|---|
| K1 | **Garnuś** (garnek z koroną) | 5 | 4 | 5 | 4 | 5 | **23** | **maskotka — zwycięzca** |
| K7 | K-korona (monogram) | 4 | 5 | 5 | 3 | 5 | **22** | **sygnet w logo (inna rola)** |
| K3 | **Warzecha** (drewniana łyżka) | 5 | 5 | 4 | 4 | 3 | **21** | **maskotka — zapasowa** |
| K6 | Korona Kuking (bez postaci) | 4 | 5 | 5 | 2 | 5 | **21** | ścieżka awaryjna „bez maskotki" |
| K5 | Król Pieróg | 4 | 3 | 4 | 5 | 3 | **19** | postać kampanijna/sezonowa |
| K2 | Kuka (kura) | 4 | 3 | 3 | 3 | 3 | **16** | odrzucony |
| K4 | Majster Kuking (kucharz-król) | 3 | 4 | 2 | 2 | 2 | **13** | odrzucony |
| — | Mruczek (kot) | 2 | 2 | 3 | 2 | 4 | **13** | odrzucony |

---

## 3. Rekomendacja

### Zwycięzca: **Garnuś** — garnek z koroną zamiast pokrywki

Twarde uzasadnienie, punkt po punkcie:

1. **Rzecz, nie osoba.** To jedyny sposób, by mieć maskotkę i **nie ryzykować stereotypu wieku, płci, karnacji ani klasy**. Przy grupie docelowej, w której są i Basia (61), i Kuba (23), i Marek (54), każda postać ludzka kogoś wyklucza. Garnek nie wyklucza nikogo. To rozstrzyga sprawę wobec K4.
2. **Korona jest konstrukcyjna, nie dekoracyjna.** Jest pokrywką. Nie da się jej odjąć bez zepsucia obiektu, więc żart nie jest doklejony — jest wbudowany. Odwrotnie niż w K2 (grzebień można zamienić z powrotem) i K5 (korona leży na wierzchu).
3. **Sylwetka jest krępa i pozioma — najlepszy możliwy kształt do kwadratowej ikony.** Przy 32 px czyta się „naczynie z trzema zębami u góry", czyli cały komunikat. Warzecha (K3) przegrywa tu wprost geometrią, i to jest jedyny powód, dla którego jest zapasem, a nie zwycięzcą.
4. **Garnek to najbardziej neutralny znak *prawdziwego* gotowania.** Nie mówi o kuchni tradycyjnej (jak pieróg), profesjonalnej (jak czapka kucharska) ani wiejskiej (jak kura). Mówi o tym, co robisz codziennie o 15:00. Zgodnie z pozycjonowaniem: „ludzie, którzy naprawdę gotują".
5. **Metafora archiwum.** Garnek to naczynie, a Kuking jest naczyniem na to, co ugotowałeś — zeszyt, kolekcje, „znaleźć to za rok" (persona Basia). Nikt tego nie musi wypowiadać; kształt to niesie.
6. **Najniższy koszt utrzymania.** Emocje robi się **parą i przechyłem**, nie mimiką: para w górę = sukces, brak pary = pusto, garnek przechylony = błąd, para w bok = szukanie. Cztery stany bez rysowania twarzy. Ilustrator nie musi trafiać w charakter twarzy w każdym pliku — to realnie decyduje o tym, czy zestaw ilustracji przetrwa rok.
7. **Sylwetka odporna na 10 lat.** Lekcja Bibenduma: projektujemy sylwetkę, detale wolno gubić. Garnusiowi można odjąć twarz, perły, parę i nadal to jest on.

**Nazwa:** imię własne **Garnuś** jest ciepłe, ale jest zdrobnieniem. Dlatego:
- w **MVP maskotka nie ma publicznej nazwy** — jest po prostu znakiem Kuking. „Garnuś" zostaje nazwą wewnętrzną (repo, pliki, brief dla ilustratora);
- imię ujawniamy dopiero, gdy testy z użytkownikami (`docs/UX_50_PLUS.md`, 13 osób) potwierdzą, że nie brzmi dziecinnie. Alternatywa w wyższym rejestrze, gdyby „Garnuś" nie przeszedł: **Garnek** (bez zdrobnienia) albo **Król Garnek**.
- Nigdy nie stosujemy: „Garnusiek", „Garniusio", „nasz Garnuś".

### Zapasowy: **Warzecha** — drewniana łyżka z koroną

Wybieramy ją na zapas, bo jest **najmocniejsza kulturowo i najbardziej dorosła z całego zestawu** (oceny 5/5 w dwóch najważniejszych kryteriach) i bo jest pierwszym kandydatem, jeśli testy pokażą, że garnek z twarzą jest odbierany jako stockowy. Warunek jej wdrożenia: **osobny sygnet do faviconu** (K7 lub sam czerpak z koroną w kadrze kwadratowym), bo pionowa łyżka nie zmieści się w ikonie. To dodatkowy koszt i dodatkowy element do utrzymania — dlatego zapas, nie zwycięzca.

### Trzecia ścieżka, jeśli właściciel zdecyduje „bez maskotki"

**K6 + K7**: wordmark `KUKING` + monogram K-korona, puste stany na czystej typografii i ikonach kreskowych. Tanio, bezpiecznie, chłodniej. Model Cookpada. Zawsze zostaje otwarta — maskotkę można dodać w roku drugim, odwrotnie już nie (usunięcie maskotki, którą społeczność pokochała, jest kosztowne).

---

## 4. Wordmark i logotyp — jak zaznaczyć „KING" w „KUKING"

**Zasada nadrzędna:** `docs/BRAND.md` mówi „na start wystarczy mocny wordmark". Trzymamy to. „KING" jest **easter eggiem**, nie komunikatem. Odkrycie ma należeć do odbiorcy — w tym cała przyjemność. Wordmark, który krzyczy „KING", zamienia dowcip w przechwałkę i traci grupę 50+.

Rekomendowany krój: humanistyczny lub geometryczny sans o dużej wysokości x, otwartych światłach i mocnej grubości (700–800). Bezpieczne, licencyjnie czyste kandydatury open source: **Inter**, **Source Sans 3**, **Figtree**, **Work Sans**. Litery `KUKING` ustawiamy w wersalikach, z lekko dodanym światłem (tracking +2…+4%), bo `K`+`U` i `I`+`N` w wersalikach mają nierówne odstępy optyczne.

| # | Wariant | Opis wykonania | Zysk | Ryzyko przekombinowania |
|---|---|---|---|---|
| **W1** | **Czysty wordmark** | `KUKING` w jednym kolorze `#23201C`. Zero akcentów. | Maksymalna czytelność, zero kosztu, działa na fakturze, w mono, na haftowanym fartuchu i w faksie. | **Brak (0/5).** Żart nie jest widoczny — ale jest w nazwie, więc nie jest utracony. |
| **W2** | **Akcent tonalny na `KING`** | `KU` w `#23201C`, `KING` w ciemnym amber `#8F5310`. Różnica ma być **tonalna, nie kontrastowa** — czyta się jako cień, nie jako drugi wyraz. Kontrast obu kolorów do białego tła ≥ 4,5:1 [do weryfikacji miernikiem]. | Żart odkrywalny przy dłuższym spojrzeniu, koszt zerowy, jeden plik. | **Średnie (3/5).** Przy zbyt dużym kontraście rozpada się na `KU KING` i wygląda jak literówka albo jak dwa słowa. Wymaga twardego zakazu wersji dwukolorowej-kontrastowej. |
| **W3** | **Korona nad `I`** | Kropka nad `I` (w wersalikach jej nie ma, więc jest to dodatek) zamieniona na miniaturową trójzębną koronę o szerokości `I` × 2,2 i wysokości = 35% wysokości wersalika. | Najbardziej „logotypowy", jeden zapamiętywalny detal, bez zmiany kolorów. | **Wysokie (5/5).** Poniżej ~120 px szerokości wordmarku korona zamienia się w brudną plamkę i psuje linię wersalików. Wymaga **osobnego pliku „small"** bez korony i dyscypliny w stosowaniu — czyli dokładnie tego, czego mały zespół nie utrzyma. Do użycia wyłącznie w dużych zastosowaniach dekoracyjnych (nagłówek strony „O nas", koszulka, baner). |
| **W4** | **Lockup: sygnet + wordmark** | Garnuś (albo K-korona) po lewej, wysokość równa wysokości wersalika × 1,6, odstęp = szerokość litery `N`. `KUKING` w wariancie W1. | **Rekomendacja główna.** Korona żyje w sygnecie, wordmark zostaje nieruszony i czytelny. Sygnet pracuje osobno tam, gdzie nie ma miejsca na tekst. Jeden zestaw obsługuje wszystkie zastosowania. | **Niskie (2/5).** Ryzyko to nadużycie sygnetu bez wordmarku, zanim marka jest rozpoznawalna. Reguła: **sygnet solo tylko tam, gdzie tekst technicznie nie wchodzi** (favicon, ikona PWA, avatar). |

**Decyzja:**
- **W4 (lockup poziomy)** — logo podstawowe.
- **W1 (czysty wordmark)** — wariant obowiązkowy w mono, w małych rozmiarach, w druku jednokolorowym i w stopkach.
- **W2** — wariant drugorzędny, dopuszczony na stronie „O nas", w gadżetach i w komunikacji, gdzie chcemy pokazać żart. Nigdy w nawigacji.
- **W3** — nie w MVP. Ewentualnie po roku, jako wersja dekoracyjna.

**Zakazy dla wordmarku:** brak gradientów; brak cienia; brak obracania; brak wtapiania korony w literę `K` (koliduje z sygnetem K7); brak wersji, w której `KING` jest większy lub innym krojem; brak dopisków typu „KUKING — Twoja kulinarna korona".

---

## 5. System zastosowań

| Zastosowanie | Wariant | Wymagania techniczne | Uwagi |
|---|---|---|---|
| **Favicon** | Garnuś uproszczony (bez oczu, bez perł, uszy pogrubione ok. 1,3×) | `favicon.svg` + `favicon.ico` 32×32 i 16×16; SVG z `prefers-color-scheme` dla ciemnego paska kart | W 16 px zostaje sylwetka „naczynie + trzy zęby". Testować na jasnej i ciemnej karcie. |
| **Ikona PWA `any`** | Garnuś pełny na kremowym tle `#F7F4EE` | PNG 192×192 i 512×512, `purpose: "any"` | Tło pełne, nie przezroczyste — inaczej ginie na ciemnym pulpicie. |
| **Ikona PWA `maskable`** | Garnuś uproszczony, wyśrodkowany | Osobne pliki PNG 192/512, `purpose: "maskable"`; **cała istotna treść w kole o średnicy 80%** (dla 512 px: koło o promieniu 204,8 px w środku kafla), zewnętrzne 20% to samo tło. Uwaga: kwadrat 410×410 to zbyt optymistyczne uproszczenie — jego narożniki wypadają poza maskę okrągłą, która jest najagresywniejsza. Skala grafiki musi zmieścić **półprzekątną** jej prostokąta w promieniu koła | Nie stosować `purpose: "any maskable"` na jednym pliku — to skrót, który psuje jedno z dwóch zastosowań. |
| **Splash / apple-touch-icon** | jak `any` | `apple-touch-icon` 180×180 PNG, bez przezroczystości | — |
| **Avatar domyślny** | Nie Garnuś. **Inicjał użytkownika** na jednym z 6 tonów palety | 128×128, koło | Ważne: gdyby wszyscy bez zdjęcia mieli Garnusia, feed zamienia się w ścianę identycznych garnków i maskotka się dewaluuje. Prototyp już robi to dobrze (`.avatar` z literą). |
| **Puste stany** | 3 ilustracje: „pusty feed", „pusty zeszyt/kolekcje", „brak wyników" | SVG inline, maks. 8 KB każda, wysokość 96–140 px | Zawsze **ilustracja + jedno zdanie + jeden przycisk z tekstem**. Nigdy ilustracja bez akcji. |
| **Błędy** | Garnuś przechylony, bez pary | SVG, wysokość 80–96 px | Tylko dla błędów całostronicowych (404, 500, awaria). **Nigdy przy błędzie pola formularza** — tam obowiązuje reguła z `docs/UX_50_PLUS.md`: komunikat przy polu + podsumowanie, bez ozdób. |
| **Sukces** | Garnuś z parą w górę | SVG, wysokość 96 px | Po pierwszej publikacji i po pierwszym „Ugotowałem". Potem już nie — inaczej robi się gratulacyjny spam. |
| **E-mail** | Wordmark W1 w nagłówku, sygnet 24 px w stopce | PNG 2× + tekst alternatywny; **nie SVG** (klienty pocztowe go nie renderują), bez tła krytycznego dla odczytu | Maskotka w e-mailu maks. raz, w stopce. |
| **Karta OG / Twitter** | 1200×630: zdjęcie dania (jeśli jest) + pasek dolny z wordmarkiem i sygnetem | PNG/JPG; przy braku zdjęcia — kremowe tło, Garnuś po prawej, tytuł po lewej, tekst min. 48 px | Generowane serwerowo. Tekst musi być czytelny w miniaturze 300 px. |
| **Naklejki / pieczątka** | Sygnet mono, jeden kolor | Krzywe, min. 20 mm, bez elementów cieńszych niż 0,4 mm | Dla kół gospodyń, bibliotek, targów. Sitodruk lub pieczątka — dlatego wariant mono jest wymaganiem, nie dodatkiem. |
| **Ulotka A5 offline** | Lockup W4 + jedno duże zdjęcie + adres `kuking.pl` + jedno zdanie | Druk CMYK; tekst min. 12 pt, adres min. 18 pt | Odbiorca może być bez okularów. Adres większy niż logo. |
| **Fartuch / gadżet** | W2 (żart widoczny) albo sygnet solo | Haft: bez elementów < 1,5 mm; maks. 3 kolory | Miejsce, w którym „KING" wolno pokazać wprost. |

**Paleta (propozycja, spójna z istniejącym prototypem `#f7f7f5` / `#222` / `#e8e3db`):**

| Rola | Hex | Użycie |
|---|---|---|
| Ciepła czerń (ink) | `#23201C` | wordmark, tekst, oczy |
| Amber (główny) | `#C8781E` | korpus Garnusia, akcenty graficzne — **wypełnienia, nie tekst** |
| Amber ciemny | `#8F5310` | tekst akcentowany, linki, wariant W2 (kontrast do białego ≈ 5,8:1 [do weryfikacji]) |
| Miodowe złoto | `#E0A62B` | korona (**wyłącznie**) |
| Cegła | `#A8452F` | uszy garnka, akcenty drugorzędne, przyciski destrukcyjne |
| Zieleń koperkowa | `#4E6B3C` | stany „udało się", świeżość |
| Krem | `#F7F4EE` | tła ilustracji, tło ikon PWA |
| Beż | `#E6DCCB` | plamy w ilustracjach, placeholder zdjęcia |
| Fokus (bez zmian) | `#155EEF` | obrys `:focus-visible` — **nie ruszać**, jest z prototypu i jest poprawny |

Reguła: **złoto jest zarezerwowane dla korony.** Jeśli złoto pojawia się gdziekolwiek indziej, korona przestaje być wyróżnikiem.

---

## 6. Ton głosu

### 6.1 Decyzja fundamentalna: maskotka nie mówi w pierwszej osobie

To najważniejsze ustalenie tego rozdziału. Garnuś jest **widziany, nie słyszany**. Cały tekst pisany jest głosem marki: spokojnym, konkretnym, bez „ja". Powody:

- postać, która mówi „Cześć, jestem Garnuś, pomogę Ci!", jest asystentem — a asystent, którego się nie prosiło, u dorosłego czyta się jako opiekun. Badania nad interfejsami dla osób starszych notują odbiór „toy-like" asystentów jako infantylizujący;
- gadająca maskotka wymaga konsekwencji w setkach stringów; jeden nieudany żart zostaje na lata;
- rezygnacja z „ja" odcina nas jednym ruchem od całej rodziny problemów Duolingo (wyrzuty, presja, smutna mina jako narzędzie retencji).

**Jedyny wyjątek:** materiały offline (naklejka, ulotka) mogą mieć jeden dymek. Nigdy w produkcie.

### 6.2 Ton — co mówimy

- **Krótko i konkretnie.** Zdanie ma powiedzieć, co się stało i co można zrobić.
- **W stronie czynnej, ale bez rozkazywania.** „Wybierz mniejsze zdjęcie" — tak. „Musisz wybrać mniejsze zdjęcie" — nie.
- **Uspokajająco co do danych.** Jeśli coś się nie udało, mówimy wprost, że nic nie zginęło. To pierwszy lęk osoby niepewnej technologii.
- **O jedzeniu, nie o platformie.** „Twoje dania", nie „Twoje treści".
- **Formy neutralne płciowo, gdzie to możliwe.** „Co dziś ugotowałeś?" jest już w `docs/BRAND.md` i zostaje jako claim, ale w interfejsie preferujemy bezosobowe: „Dodaj danie", „Zapisano".

### 6.3 Czego NIGDY nie mówimy

| Zakaz | Dlaczego |
|---|---|
| „Hej!", „Cześć!", „Siema", wykrzykniki w powitaniach | Rejestr młodzieżowy, natychmiast obniża wiarygodność u 50+. |
| Emoji w interfejsie i w powiadomieniach | Czytnik ekranu odczytuje je jako opisy; wizualnie infantylizują. Wyjątek: brak wyjątków w MVP. |
| „Jesteś królem kuchni!", „Twoja korona czeka", „Zostań mistrzem" | Pretensjonalne klepnięcie po plecach. Korona jest żartem o garnku, nie komplementem dla użytkownika. **To najważniejszy zakaz w tym dokumencie**, bo jest najbardziej kuszący. |
| „Tęsknimy!", „Dawno Cię nie było — wracaj", „Nie przerywaj serii", „Twoja passa się kończy" | Wprost mechanika Duolingo. Produkuje wstyd, nie powrót. |
| „Ups!", „Ojej!", „Coś poszło nie tak" | Bezradne. Mówimy, **co** nie wyszło. |
| „content", „explore", „engage", „feed", „creator", „tapnij", „kliknij tutaj" | Żargon. Lista pełna w `BRAND_EXTENDED.md`. |
| Cokolwiek o wieku użytkownika: „prosto także dla seniorów", „duże litery dla wygody" | Produkt nie jest oznaczany jako „dla seniorów". |
| Żarty z niewiedzy technicznej: „nie wiesz, jak to działa? spokojnie" | Zakłada niekompetencję. |
| Kody błędów w tekście dla użytkownika (`422`, `Unprocessable Entity`) | Reguła z `docs/UX_50_PLUS.md`. |
| Zdrobnienia od nazw funkcji: „przepisik", „zdjątko", „daniedzko" | — |

### 6.4 Szesnaście mikro-tekstów (gotowych do wklejenia)

| # | Kontekst | Tekst |
|---|---|---|
| 1 | Powitanie po rejestracji | **Dobrze, że jesteś.** Zacznij od jednego zdjęcia — resztę dopiszesz, kiedy będziesz chciał. |
| 2 | Pusty feed (Start) | Tu będzie widać, co gotują osoby, które obserwujesz. Na razie nie obserwujesz nikogo. `[ Zobacz, co ugotowano dziś ]` |
| 3 | Pusty profil / Moje dania | Nie masz jeszcze żadnego dania. Pierwsze zwykle jest najprostsze: zdjęcie i dwa zdania. `[ Dodaj zdjęcie ]` |
| 4 | Pusty zeszyt / kolekcje | Zeszyt jest pusty. Kiedy zapiszesz przepis, znajdziesz go tutaj — także za rok. |
| 5 | Błąd uploadu: za duży plik | Nie udało się dodać zdjęcia, ponieważ plik ma ponad 15 MB. Wybierz mniejsze zdjęcie. Tekst, który napisałeś, jest zapisany. |
| 6 | Błąd uploadu: połączenie | Zdjęcie nie doszło do końca — to zwykle kwestia połączenia. Spróbuj jeszcze raz. Nic nie zginęło. |
| 7 | Po pierwszej publikacji | **Pierwsze danie jest na stronie.** Od teraz masz je u siebie na stałe. |
| 8 | Powiadomienie: Ugotowałem | Marek ugotował Twoją pomidorową i dodał zdjęcie. |
| 9 | Powiadomienie: komentarz | Basia napisała coś pod Twoim schabem. |
| 10 | Weekly digest (e-mail, temat) | Z Twoich przepisów gotowano w tym tygodniu 4 razy |
| 11 | Weekly digest (e-mail, treść) | W tym tygodniu cztery osoby ugotowały coś z Twoich przepisów. Najczęściej pomidorową. Zajrzyj, jak im wyszło. |
| 12 | 404 | **Tej strony nie ma.** Bywa, że przepis został usunięty albo adres się urwał po drodze. `[ Wróć na Start ]` `[ Szukaj ]` |
| 13 | Brak wyników szukania | Nic nie znaleźliśmy na „pomidorowa mojej mamy". Spróbuj krótszego hasła, na przykład „pomidorowa". |
| 14 | Autosave | Szkic zapisany. Możesz zamknąć stronę i wrócić później. |
| 15 | Potwierdzenie usunięcia | Usunąć to danie? Zniknie z Twojego profilu. Przez 30 dni można je przywrócić. `[ Usuń ]` `[ Zostaw ]` — *[do weryfikacji: 30 dni musi zgadzać się z `docs/SECURITY_PRIVACY_LEGAL.md`]* |
| 16 | E-mail po długiej przerwie | Dawno Cię tu nie było i to zupełnie w porządku. Twój zeszyt czeka tam, gdzie go zostawiłeś. `[ Zajrzyj do swoich dań ]` — *bez „tęsknimy", bez liczenia dni nieobecności* |

Dodatkowo, prace serwisowe: **Robimy porządki na zapleczu. Za kilka minut wszystko wróci. Nic z Twoich rzeczy nie ginie.**

---

## 7. Plan wdrożenia i koszt

### Etap 0 — decyzje (0 zł, ok. 3 h właściciela)
Wybór kierunku (Garnuś / Warzecha / bez maskotki), zatwierdzenie palety, decyzja o publicznej nazwie maskotki (rekomendacja: **odroczyć**).

### Etap 1 — MVP, minimum absolutne
Zakres, nic więcej:
1. Wordmark `KUKING` (W1) — SVG + PNG, wersje: pełna, mono, na ciemnym tle.
2. Lockup W4 (sygnet + wordmark), poziomy.
3. Sygnet Garnuś w dwóch wariantach: pełny i uproszczony (faviconowy).
4. Zestaw ikon: `favicon.svg`, `favicon.ico` (16/32), PWA `192`/`512` `any`, PWA `192`/`512` `maskable`, `apple-touch-icon` 180.
5. **Trzy** ilustracje pustych stanów: pusty feed, pusty zeszyt, 404/błąd.
6. Szablon karty OG (jeden layout, generowany).
7. Jednostronicowa notka: paleta, minimalne rozmiary, pola ochronne, lista zakazów.

### Etap 2 — po pierwszych testach z użytkownikami (13 osób wg `docs/UX_50_PLUS.md`)
Poprawka sylwetki na podstawie pytania kontrolnego: *„Co to jest?"* i *„Do kogo, Pani/Pana zdaniem, jest ten serwis?"*. Jeśli w odpowiedziach pojawi się „dla dzieci" albo „dla starszych" — koncept wraca do deski. Dopiero potem: 4. i 5. ilustracja (sukces po pierwszym „Ugotowałem", brak wyników), nagłówek e-maila, naklejka mono.

### Etap 3 — po MVP
Wariant W3 (korona nad `I`) do zastosowań dekoracyjnych; 2–3 warianty sezonowe (Boże Narodzenie, przetwory) na wzór ilustracyjnych serii Wedla; materiały offline dla kół gospodyń; ewentualne warianty grupowe (model Snoo) — **tylko jeśli powstaną grupy**.

### Koszt — widełki realne dla polskiego rynku

| Ścieżka | Zakres Etapu 1 | Widełki | Uwagi |
|---|---|---|---|
| **Freelancer PL (rekomendacja)** | całość Etapu 1 | **3 000 – 8 000 zł** | Punkt odniesienia: uczciwa cena samego logo dla MSP w PL to **1 500 – 5 000 zł**; „projekt logo od 750 zł", „identyfikacja od 600 zł", „księga znaku od 600 zł" u tańszych dostawców; stawka godzinowa przeciętnego grafika ok. **100 zł/h**. Zestaw ikon + 3 ilustracje to realnie +15–30 h. |
| **Freelancer PL, wariant oszczędny** | wordmark + sygnet + ikony, ilustracje później | 1 500 – 3 000 zł | Wystarcza, by ruszyć. Puste stany tymczasowo na typografii. |
| **Agencja / studio** | całość + księga znaku | 12 000 – 30 000 zł | Nieuzasadnione przed walidacją produktu. |
| **AI + własne dopracowanie w SVG** | całość Etapu 1 | 0 – 800 zł (subskrypcje) + 15–25 h własnej pracy | **Realne ryzyka:** brak spójności między ilustracjami; wyniki bywają nieoczyszczalnymi rastrami zamiast krzywych; **niepewny status praw autorskich do wygenerowanej grafiki** — w PL utwór wymaga twórczości człowieka, więc do znaku, który ma być rejestrowany, jest to ryzyko [do weryfikacji z prawnikiem]. Dopuszczalne do **szkiców i eksploracji**, nie do finalnego znaku. |
| **Własnymi siłami (kod + SVG ręcznie)** | jak wyżej | 0 zł + 20–40 h | Garnuś jest tak zaprojektowany, że **da się go zbudować z prostokątów, elips i trójkątów** — patrz `mascot-winner.svg`. To realna opcja dla MVP. |

**Rekomendacja kosztowa:** MVP z SVG budowanego własnymi siłami (dowód wykonalności w plikach obok), a **jeden zakup u freelancera dopiero na wordmark** — bo krój, kerning i optyczne wyrównanie wersalików to jedyna część, której nie da się zrobić „na oko", a jest najczęściej widoczna.

---

## 8. Due diligence znaku — konkretna lista

**Stan wyjściowy:** wyszukiwanie w otwartym internecie nie pokazało marki „Kuking" w kategorii kulinarnej. Znaleziono jedynie brzmieniowo bliskie znaki obce (`KAKING` — sprzęt kuchenny i domowy, `KUKE`, `KUK PRO`) oraz organizację „Kuking'a" bez związku z branżą. **To nie jest clearance.** Wyszukiwarka Google nie widzi rejestrów. `docs/BRAND.md` już to notuje: posiadanie domeny nie jest równoznaczne z clearance.

### 8.1 Rejestry do sprawdzenia

| Rejestr | Zakres | Link |
|---|---|---|
| **UPRP — e-Wyszukiwarka** | znaki krajowe PL + rozszerzenia na PL | https://ewyszukiwarka.pue.uprp.gov.pl/ |
| **EUIPO eSearch plus** | znaki UE (EUTM) i wzory wspólnotowe | https://euipo.europa.eu/eSearch/ |
| **TMview** | zbiorczo ~80 urzędów, w tym UPRP i EUIPO — **zacznij tutaj** | https://www.tmdn.org/tmview/ |
| **DesignView** | wzory przemysłowe (istotne dla figury maskotki) | https://www.tmdn.org/tmdsview-web/ |
| **Madrid Monitor (WIPO)** | rejestracje międzynarodowe wskazujące PL/EU | https://madridmonitor.wipo.int/ |
| **WIPO Global Brand Database** | globalnie, w tym **wyszukiwanie po obrazie** — kluczowe dla korony | https://branddb.wipo.int/ |
| **Klasyfikacja nicejska (NCL)** | dobór klas | https://www.wipo.int/classifications/nice/en/ |
| **Klasyfikacja wiedeńska** | kody elementów graficznych — dla korony istotna kategoria **24.9** (korony) oraz **11.x** (naczynia domowe/kuchenne); szukaj po kodzie, nie po słowie | https://www.wipo.int/classifications/vienna/en/ |
| **KRS / CEIDG** | firmy o nazwie kolidującej | https://wyszukiwarka-krs.ms.gov.pl/ , https://aplikacja.ceidg.gov.pl/ |
| **Rejestr domen NASK** | `.pl` — warianty i literówki | https://www.dns.pl/whois |

### 8.2 Klasy nicejskie — które i po co

| Klasa | Co obejmuje w naszym kontekście | Priorytet |
|---|---|---|
| **9** | oprogramowanie, aplikacje mobilne, pliki do pobrania | **rdzeń** |
| **42** | SaaS/PaaS, udostępnianie platformy internetowej, hosting treści użytkowników | **rdzeń** |
| **41** | publikowanie online, treści rozrywkowo-edukacyjne, kursy i warsztaty kulinarne | **rdzeń** |
| **35** | reklama, marketing, promowanie towarów osób trzecich, sklep online z gadżetami | ważna |
| **38** | usługi telekomunikacyjne: forum internetowe, przekazywanie wiadomości i danych | ważna (klasyczna klasa dla społeczności/forum) |
| **43** | usługi gastronomiczne, restauracje, catering | **obronna** — tu żyje Burger King i cała gastronomia; sprawdzić **obowiązkowo**, nawet jeśli nie zgłaszamy |
| **29 / 30** | żywność (29: mięso, nabiał, przetwory; 30: mąka, wypieki, przyprawy, sosy) | obronna / na przyszłość — tylko przy własnej linii produktów |
| **45** | usługi kojarzenia i sieci społecznościowych | do rozważenia z pełnomocnikiem |
| **16** | druki, książka kucharska, naklejki | opcjonalna, jeśli będzie druk |

### 8.3 Ryzyka wokół korony — co konkretnie sprawdzić

1. **Burger King.** Korona jest elementem znaków słowno-graficznych BK, a marka aktywnie i szeroko egzekwuje prawa — udokumentowane spory obejmują naśladownictwa nazwy w regionie CEE (sprawa „Burek King") oraz konflikty w Indiach, gdzie strona przeciwna używała korony *między* wyrazami jako argumentu o odmienności. **Wniosek: korona + kontekst gastronomiczny (kl. 43/29/30) to najbardziej ryzykowna ćwiartka.** Nasz rdzeń to kl. 9/41/42 (oprogramowanie i platforma), co ryzyko istotnie obniża — ale nie usuwa, bo domena „gotowanie" jest podobna, a znaki renomowane korzystają z szerszej ochrony.
2. **Znaki renomowane z koroną poza kategorią.** Rolex prowadził przed EUIPO spór o koronę wobec odzieży i **przegrał** w zakresie wykazania szkody dla renomy — co pokazuje jednocześnie, że (a) właściciele koron kwestionują znaki w odległych klasach, i (b) taki atak da się odeprzeć. Koszt obrony jest realny nawet przy zwycięstwie.
3. **Godło RP.** **Absolutny zakaz konstrukcyjny.** Orzeł Biały w koronie na czerwonym polu jest godłem RP chronionym ustawą z 31 stycznia 1980 r. o godle, barwach i hymnie RP; koronę przywrócono 31 grudnia 1989 r. Nie łączymy korony z orłem, z tarczą herbową ani z układem biały-na-czerwonym. Niezależnie od prawa: symbolika państwowa jest w PL upolityczniona i zderza się z „domowa, spokojna, po swojemu".
4. **Korona jako element słabo dystynktywny.** Urzędy traktują korony jako motyw powszechny. Praktyczny wniosek: **dystynktywność nieśmy nazwą `KUKING` i całością sylwetki (garnek + korona-pokrywka), nie samą koroną.** Zgłaszanie samej korony jako znaku graficznego byłoby najsłabszym i najdroższym wyborem.

### 8.4 Kolejność działań

1. Samodzielny przegląd w **TMview** (nazwa: `kuking`, `kuking*`, `cooking`, `kuk*`; klasy 9, 35, 38, 41, 42, 43, 29, 30) — 2–3 h, 0 zł.
2. Przegląd graficzny w **WIPO Global Brand Database** po kodzie wiedeńskim **24.9** w połączeniu z kl. 43/30/29 — na ile zatłoczona jest korona w gastronomii.
3. **UPRP** i **Madrid Monitor** — potwierdzenie dla PL.
4. Handles w social media i warianty domen (`kuking.com`, `kuking.eu`, literówki).
5. **Dopiero potem** rzecznik patentowy: opinia clearance + zgłoszenie. Orientacyjnie: opinia ok. 1 000 – 3 000 zł, zgłoszenie krajowe UPRP w jednej klasie 450 zł opłaty urzędowej + 400 zł za każdą kolejną klasę, plus ochrona 10-letnia 400 zł/klasa **[do weryfikacji — tabela opłat UPRP zmienia się; sprawdzić aktualną]**. EUTM: opłata podstawowa 850 EUR online za pierwszą klasę **[do weryfikacji w aktualnym cenniku EUIPO]**.
6. **Zanim wydamy pieniądze na druk, gadżety i kampanię offline** — mieć punkt 5. za sobą. To jest reguła z `docs/BRAND.md` i tu ją tylko potwierdzamy.

**Zastrzeżenie:** ten dokument nie jest opinią prawną. Rejestrów nie dało się w tym trybie sprawdzić bezpośrednio (bazy wymagają sesji interaktywnej i wyszukiwania po obrazie), więc **wszystkie stwierdzenia o dostępności znaku należy traktować jako niepotwierdzone**. Konieczne jest badanie w UPRP / EUIPO / TMview / Madrid Monitor, najlepiej przez rzecznika patentowego.

---

## 9. Pliki towarzyszące

| Plik | Zawartość |
|---|---|
| `mascot-sketches.svg` | poglądowe szkice wszystkich 7 konceptów + odrzucony kot, każdy z podglądem 32 px i testem monochromatycznym |
| `mascot-winner.svg` | dopracowany Garnuś: 512 / 128 / 64 px, wariant uproszczony 32 px, wariant mono, kafel PWA maskable ze strefą bezpieczną |
| `BRAND_EXTENDED.md` | słownik marki, słowa zakazane, zasady nazywania funkcji, ton e-maili i moderacji, trzy nowe hasła |

---

## Źródła

Maskotki i marki:
- Michelin — historia Bibenduma: https://business.michelinman.com/blog/articles/history-of-the-michelin-man , https://www.michelinman.com/why-michelin/michelin-man-origin
- Bibendum jako wzorzec tożsamości: https://www.smithersofstamford.com/blog/the-tires-michelin-man-as-an-enduring-masterclass/
- Maskotki od Bibenduma do Benny the Bull (Graphéine): https://grapheine.com/en/magazine/brand-mascots-from-bibendum-to-benny-the-bull/
- Siła maskotek marki (ThoughtLab): https://www.thoughtlab.com/blog/from-icons-to-avatars-the-power-of-brand-mascots/
- GitHub Brand Toolkit — maskotki jako element graficzny: https://brand.github.com/graphic-elements/mascots
- Octocat, historia projektu (Cameron McEfee): https://cameronmcefee.com/work/the-octocat/
- 160+ wariantów Octocata: https://blog.jakelee.co.uk/what-on-earth-are-octocats/
- Prostota sylwetki i czytelność w 16 px: https://ziggle.art/brand-mascot-guide , https://ziggle.art/how-to-create-a-mascot
- Maskotka obecna w błędach, nie tylko w sukcesie: https://svgapp.ai/blog/how-mascots-work-and-why/ , https://svgapp.ai/blog/mascot-design-trends-2026/
- Mailchimp — Freddie i puste stany: https://www.designsystems.one/design-systems/mailchimp-design , https://www.inspoai.io/blog/best-empty-state-design-examples
- Cookpad — „Organic", tożsamość korporacyjna bez maskotki: https://medium.com/cookpadteam/organic-a-new-corporate-brand-design-for-cookpad-2e502eb03273
- Cookpad — redesign logo (2014): https://cf.cpcdn.com/info/assets/wp-content/uploads/20150622195212/sep114.pdf

Ton, gamifikacja, antywzorce:
- Duolingo — pasywno-agresywna strategia retencji: https://www.universityxp.com/news/2025/7/25/we-havent-seen-you-in-a-while-duolingos-passive-aggressive-strategy-for-keeping-users-hooked
- Duolingo — sowa, dark patterns, cyfrowe poczucie winy: https://opinionsandconditions.substack.com/p/duolingo-owl-dark-patterns-digital-guilt
- Krytyka mechaniki passy: https://isomorphism.xyz/blog/2025/duolingo/

Odbiorca 50+ i antropomorfizacja:
- Antropomorfizm i asystenci głosowi u osób starszych (Int. J. Consumer Studies, 2026): https://onlinelibrary.wiley.com/doi/10.1111/ijcs.70195
- Stereotypy wieku i płci a zaufanie do technologii antropomorficznej: https://pubmed.ncbi.nlm.nih.gov/24935771/
- „Toy-like" asystenty odbierane jako infantylizujące — projektowanie (nie)zaufania w technologiach dla starzenia: https://arxiv.org/html/2608.02784
- Technofobia vs zaufanie w ocenie robotów asystujących: https://arxiv.org/pdf/2207.05387
- Styl komunikacji i ustawienia antropomorficzne u osób starszych: https://www.ncbi.nlm.nih.gov/pmc/articles/PMC9473738/

Polskie marki spożywcze:
- Kucharek — marka i pozycja rynkowa: https://kucharek.pl/ , https://sklep.prymat.pl/kucharek-polska-marka-w-dobrej-cenie
- Prymat — grupa i marki, zmiana logo 2006, ambasador: https://pl.wikipedia.org/wiki/Prymat , https://prymatgroup.pl/pl/nasze-spolki/prymat-sp-z-o-o/attachment/logo-kucharek-2/
- Winiary — o marce i historii nazwy: https://www.winiary.pl/o-nas-winiary/ , https://nazwane.pl/dobre-pomysly-dobry-smak-czyli-historia-marki-winiary
- Wedel — Ptasie Mleczko, serie ilustracji na opakowaniach: https://wedel.com/our-products/ptasie-mleczko , https://www.behance.net/gallery/80442371/Ptasie-Mleczko-by-Wedel-Package-Illustration , https://en.wikipedia.org/wiki/Ptasie_mleczko

Symbolika korony i godło:
- Godło Polski: https://pl.wikipedia.org/wiki/God%C5%82o_Polski
- Orzeł Biały — historia i symbolika korony: http://www.orzelbialy.org/historia.htm , https://histmag.org/Z-Orlem-Bialym-poprzez-wieki-Cz-5-Symbolika-panstwowa-PRL-i-III-RP-8326
- Symbole narodowe — materiał edukacyjny: https://koss.ceo.org.pl/sites/koss.ceo.org.pl/files/moja_polska_symbole_narodowe.pdf

Znaki towarowe:
- Burger King vs „Burek King" (CEE): https://www.hfgip.com/news/us-giant-burger-king-attacks-and-defeats-burek-king , https://www.lexology.com/library/detail.aspx?g=1ed79fe7-dfb5-4676-80f2-a00f9ad71f0f
- Burger King w Indiach — korona jako argument o odmienności: https://trademarklawyermagazine.com/indias-burger-king-battle-a-cautionary-tale-for-global-brands/
- Znaki Burger Kinga — przegląd: https://secureyourtrademark.com/blog/burger-king-trademarks/
- Rolex v EUIPO — korona i renoma poza kategorią: https://en.wikipedia.org/wiki/Rolex_v_EUIPO
- Prawo zwyczajowe kontra znak BK: https://harriganip.com/blog/common-law-trademark-burger-king/

Technika ikon:
- Maskable icons — strefa bezpieczna 80%, osobne pliki: https://logofoundry.app/blog/pwa-icon-requirements-safe-areas , https://favicon.now/guides/pwa-icons-maskable , https://favicon.live/maskable-icons-explained
- Checklist maskable: https://www.hypermatic.com/articles/favvy-maskable-icon-checklist-for-pwas/
- Rozmiary favikon i PWA: https://iconello.com/blog/favicon-sizes-guide , https://iconello.com/blog/pwa-icons-guide , https://usetoolsuite.com/blog/favicon-pwa-setup-guide/

Koszty na rynku polskim:
- Ile kosztuje logo — cennik 2025: https://studiokreatywnychstron.pl/blog/ile-kosztuje-logo/ , https://byblossom.pl/ile-kosztuje-logo-cennik-stworzenia-logo-2025/ , https://brandoholik.pl/cena-projektu-logo/
- Stawki grafika freelancera 2025: https://katarzynawisniewska.pl/ile-kosztuja-uslugi-grafika-freelancera-w-2025-roku-przykladowe-stawki/
- Cenniki usług graficznych: https://thenewlook.pl/projekt-logo-cennik/ , https://goodproject.pl/cennik-projektow-graficznych/ , https://paulagrafik.pl/cennik-grafika/

Rejestry (linki operacyjne): UPRP https://ewyszukiwarka.pue.uprp.gov.pl/ · EUIPO https://euipo.europa.eu/eSearch/ · TMview https://www.tmdn.org/tmview/ · DesignView https://www.tmdn.org/tmdsview-web/ · Madrid Monitor https://madridmonitor.wipo.int/ · WIPO Global Brand Database https://branddb.wipo.int/ · Nicea https://www.wipo.int/classifications/nice/en/ · Wiedeń https://www.wipo.int/classifications/vienna/en/
