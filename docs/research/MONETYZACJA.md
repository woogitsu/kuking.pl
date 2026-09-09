# Monetyzacja — co realnie sprzedać, i kiedy

> Agent 4, audyt repozytorium. Odpowiada na issue #36. Pogłębia
> `docs/MONETIZATION.md` i `docs/COSTS.md` — nie duplikuje ich, rozstrzyga
> tam, gdzie te dokumenty zostawiają hipotezy bez werdyktu, i mówi wprost,
> gdzie się z nimi nie zgadzam.

## 0. Zakres i uczciwe zastrzeżenie na start

`docs/MONETIZATION.md` proponował widełki cenowe (14,99–19,99 zł/mies.) i listę
funkcji premium **bez żadnego źródła** — to były liczby wyssane z powietrza,
nie z badania. *(Czas przeszły od 9 września 2026: tamten plik został
przepisany na stanowisko właściciela „zarabianie nie jest celem" i tych widełek
już nie zawiera. Ten akapit zostaje, bo opisuje punkt wyjścia tego researchu.)* Issue #36 już to zauważa i słusznie nazywa to zadaniem
decyzyjnym, nie implementacyjnym. Trzymam się instrukcji z briefu: **żadnej
liczby, przychodu, konwersji ani ceny, których nie mam ze źródła.** Tam,
gdzie cytuję cudze liczby (Cookpad, Strava, Duolingo, Nextdoor, Patreon,
Substack), podaję źródło i datę. Tam, gdzie nie mam liczby dla Kuking — piszę
„nie wiadomo", nie zgaduję.

**Najważniejsze zdanie tego dokumentu, z góry:** Kuking ma dziś (6 września
2026, tryb zamkniętej alfy, D-012) **zero potwierdzonych aktywnych
użytkowników** — `docs/ROADMAP.md` „Closed alpha gate" wymaga dopiero **20+
realnych userów**, a deploy jest odłożony (D-011). Każda dyskusja o cenach
i konwersji na tym etapie jest liczeniem na papierze. To nie jest wymówka —
to jest odpowiedź na pytanie „co sprzedać", tak samo wartościowa jak konkretna
cena, bo mówi, na co NIE warto teraz tracić czasu zespołu dwuosobowego.

---

## 1. Punkt wyjścia: Cookpad i premium za wyszukiwanie

**Teza z issue #36 jest potwierdzona źródłem, nie domysłem — i już
udokumentowana w `docs/research/COMPETITIVE_LANDSCAPE.md` §Cookpad, którego
nie duplikuję, tylko cytuję i potwierdzam drugim źródłem.**

Zweryfikowałem niezależnie (WebSearch, wrzesień 2026):

- Cookpad FY2025 (rok obrotowy): przychód **5,34 mld JPY, spadek 9,2% r/r**;
  zysk netto **741 mln JPY, spadek 44% r/r**; marża netto spadła z 23% do 14%.
  ([Simply Wall St, dane finansowe TSE:2193](https://simplywall.st/stocks/jp/media/tse-2193/cookpad-shares/past),
  zapytanie WebSearch wrzesień 2026 — te same liczby, co w
  `COMPETITIVE_LANDSCAPE.md`, potwierdzone niezależnie drugim przebiegiem
  wyszukiwania)
- Spółka sama wskazała **spadającą liczbę subskrypcji premium** jako
  bezpośrednią przyczynę spadku przychodów segmentu subskrypcyjnego w swoim
  raporcie za Q1 FY2025. (cytat i link do PDF raportu w
  `COMPETITIVE_LANDSCAPE.md` §Cookpad — pierwotne źródło, nie wtórne)
- Cookpad ma **polską wersję** (`cookpad.com/pl`) z pełnym Cooksnapem,
  dziennikiem gotowania i Premium — czyli dokładnie tę mechanikę, którą
  Kuking uważa za rdzeń produktu, **i mimo to kurczy się finansowo od lat**
  (przychody spadają średniorocznie, `COMPETITIVE_LANDSCAPE.md`
  cytuje trend −16,1%/rok z niezależnego źródła finansowego).

**Co jest wiedzą, a co domysłem — wprost:**

- **Wiedza (ze źródła):** Cookpad ma malejące przychody i malejącą liczbę
  subskrybentów premium, mimo posiadania Cooksnapu i polskiej wersji.
- **Domysł, nie potwierdzony wprost przez samego Cookpada:** że przyczyną
  jest KONKRETNIE „premium za szybsze wyszukiwanie" jako jedyna albo główna
  wada oferty. Raport Cookpada mówi o spadku liczby subskrypcji premium
  ogółem, nie rozbija tego na to, którą dokładnie funkcję ludzie uznali za
  niewartą pieniędzy. Wniosek „ludzie nie chcą płacić za wyszukiwanie" jest
  **wnioskowaniem z ofert konkurencji** (znane funkcje Cookpad Premium to
  m.in. zaawansowane wyszukiwanie i zapisywanie bez limitu), nie cytatem
  z raportu. **Oznaczam to jako wiarygodne wnioskowanie, nie fakt.**

**Konsekwencja dla Kuking, którą podzielam:** płatna wygoda wokół rdzenia
produktu (szybsze wyszukiwanie, więcej zapisów, brak limitu kolekcji) jest
słabym powodem do płacenia — bo jeśli nie zadziałało to u gracza ze skalą
40 mln globalnych użytkowników i wieloletnim doświadczeniem w monetyzacji
dokładnie tego produktu, nie ma podstaw sądzić, że zadziała przy skali
dziesiątek–tysięcy kont. Trzeba sprzedawać coś, czego wygoda nie zastępuje —
patrz §3.

---

## 2. Research porównywalnych społeczności

Metodologia: dla każdego serwisu — co sprzedaje, komu, czy działa (ze
źródłem), czy przenosi się na Kuking 50+ w Polsce.

### 2.1 Ravelry — społeczność robótek, rynek wzorów

**Co sprzedaje:** trzy strumienie jednocześnie — reklamy (ok. 1500 aktywnych
reklamodawców, głównie małe firmy z branży włókienniczej, serwowane
bezpośrednio, bez pośrednika reklamowego), **rynek wzorów** (projektanci
sprzedają PDF-y wzorów, Ravelry pobiera 20% prowizji, 80% zostaje u autora),
sklep z gadżetami marki. ([Ravelry — jak zarabia
Ravelry](https://blog.ravelry.com/how-does-ravelry-make-money/), WebSearch
wrzesień 2026)

**Czy działa:** tak — Ravelry działa nieprzerwanie od 2007 i jest
samofinansującym się, niezależnym serwisem bez inwestora, co samo w sobie
jest dowodem działającego modelu przy niszowej społeczności hobbystycznej.

**Przenosi się na Kuking?** **Częściowo, i nie teraz.** Model prowizji od
sprzedaży treści (autor sprzedaje coś, platforma bierze %) pasuje
koncepcyjnie do przyszłej „rodzinnej książki" jako produktu fizycznego
(platforma mogłaby pobierać marżę na druku), ale **nie pasuje** do
przepisów jako takich — `AGENTS.md` §9 i cały fundament produktu (patrz
issue #36: „nie zamykać za paywallem tego, co było darmowe") wykluczają
sprzedawanie dostępu do przepisów. Reklama bezpośrednia od małych firm
(producenci przetworów, sprzętu kuchennego) jest teoretycznie przenaszalna,
ale wymaga skali (1500 reklamodawców), której Kuking nie ma i długo nie
będzie mieć.

### 2.2 AllRecipes / Kitchen Stories / Serious Eats — reklama i treści sponsorowane

Duże platformy treści kulinarnych utrzymują się głównie z reklamy display,
programów partnerskich i (Serious Eats) subskrypcji za dodatkowe funkcje/
treść bez reklam. **Nie znalazłem wiarygodnego, aktualnego źródła z
konkretnymi liczbami przychodu dla żadnego z trzech serwisów w ramach tego
researchu — `[niepotwierdzone]`.** Nie podaję więc żadnej kwoty. Jakościowo:
to są duże platformy treści (miliony unikalnych użytkowników miesięcznie),
dla których reklama display ma sens dzięki **skali odsłon**, nie dzięki
głębi relacji z czytelnikiem — dokładnie odwrotny fundament niż Kuking,
gdzie produktem jest relacja, nie odsłona (`AGENTS.md` §1: „społeczność >
anonimowa baza treści"). **Nie przenosi się.** Garnek.pl — polski, bliższy
skalą serwis społecznościowy — jest lepszym ostrzeżeniem niż wzorem: zamknął
się 25 listopada 2024 z podanym powodem „przychody reklamowe nie pokrywały
kosztów utrzymania" ([Archiveteam, wiki
Garnek.pl](https://wiki.archiveteam.org/index.php/Garnek.pl), cytowane też
w `docs/research/COMPETITIVE_LANDSCAPE.md`).

### 2.3 Substack i newslettery kulinarne

**Co sprzedaje:** narzędzie do publikowania płatnego newslettera + rynek
uwagi czytelników, prowizja platformy na płatnych subskrypcjach autorów
(zwyczajowo ok. 10% w tego typu modelach, nie potwierdziłem dokładnej stawki
Substacka w tym researchu — `[do weryfikacji]`). Skala: **ponad 5 mln
płatnych subskrypcji, ok. 450 mln USD rocznego tempa przychodów autorów**
łącznie na platformie. (WebSearch wrzesień 2026, zagregowane źródła branżowe —
nie mam bezpośredniego linku do oficjalnego komunikatu Substacka, więc
oznaczam liczby jako `[z drugiej ręki, do weryfikacji u źródła]`)

**Czy działa:** tak, dla części autorów — ale rozkład jest silnie skośny
(patrz Patreon niżej, ten sam wzorzec). **Nie mam** źródła rozbijającego to
per kategoria (kulinarna vs inne), więc nie twierdzę nic konkretnego o
newsletterach kulinarnych specyficznie.

**Przenosi się?** **Nie jako model platformy Kuking.** To jest model „jeden
autor, płacący subskrybenci tego autora" — działa dla twórcy z rozpoznawalną
marką osobistą i regularną publikacją. Kuking to społeczność wielu zwykłych
domowych kucharzy, nie garstki twórców z zasięgiem. Mogłoby to mieć sens
jako **przyszła funkcja dla nielicznych autorów z dużym zasięgiem** (temat
„Creator Pro" z `docs/MONETIZATION.md`), ale to jest V2, wymaga najpierw
istnienia takich autorów — a dziś nie ma dwudziestu aktywnych kont w ogóle.

### 2.4 Patreon — bezpośrednie wsparcie twórców

**Co sprzedaje:** infrastrukturę do cyklicznych wpłat od fanów do twórcy,
prowizja platformy. Skala: **300 000 aktywnych twórców, 25+ mln płatnych
członkostw, 10 mld USD wypłaconych twórcom od 2013, ok. 2 mld USD rocznie**.
(WebSearch wrzesień 2026, zagregowane raporty branżowe —
`[z drugiej ręki, do weryfikacji u źródła Patreon]`)

**Czy działa — kluczowe zastrzeżenie:** **rozkład dochodów jest skrajnie
nierówny — tylko ok. 4% monetyzujących twórców zarabia powyżej 100 000 USD
rocznie**, reszta znacznie mniej, część praktycznie nic. (ta sama grupa
źródeł, WebSearch wrzesień 2026) To jest **najważniejsza liczba w całej tej
sekcji dla Kuking**: nawet na dojrzałej platformie z milionami użytkowników
większość twórców nie zarabia sensownych pieniędzy z bezpośredniego wsparcia.
Przy społeczności rzędu dziesiątek–setek kont w Polsce ten model nie da
NIKOMU realnego dochodu — będzie tylko infrastrukturą bez ruchu.

**Przenosi się?** **Nie teraz.** Koncepcyjnie bliskie „płatnościom między
użytkownikami / wsparciu autora" z listy w briefie — patrz werdykt w §3.

### 2.5 Discourse i płatne fora tematyczne

Forum oparte na Discourse z płatnym członkostwem to model głównie
**B2B/profesjonalny** (fora dla specjalistów, np. prawnicze, techniczne,
gdzie firma płaci za dostęp swojego zespołu do wiedzy branżowej) albo
crowdfundingowe wsparcie hostingu przez społeczność entuzjastów. **Nie
znalazłem** w tym researchu wiarygodnego źródła z liczbami dla forum
hobbystycznego finansowanego wyłącznie płatnym członkostwem konsumenckim —
`[niepotwierdzone]`. Jakościowo najbliższy Kukingowi wariant to model
„opłata tylko za koszt hostingu" (patrz §3.8) — payłbym-tyle-ile-kosztuje,
bez zysku — co część społeczności Discourse faktycznie stosuje (nieformalnie,
przez darowizny), ale to nie jest „monetyzacja" w sensie biznesowym, tylko
utrzymanie kosztów.

### 2.6 Strava — subskrypcja przy darmowym rdzeniu

**Co sprzedaje:** zaawansowaną analitykę, planowanie tras, funkcje
bezpieczeństwa — rdzeń (śledzenie aktywności, feed społecznościowy)
pozostaje darmowy. Skala: **ok. 490 mln USD rocznego przychodu w 2025 (wzrost
32% r/r), 195+ mln zarejestrowanych kont, ~50 mln aktywnych miesięcznie,
ale tylko ok. 2% zarejestrowanych kont płaci za Premium**. Cennik: 79,99
USD/rok, 11,99 USD/mies., plan rodzinny 139,99 USD/rok. ([Sacra — analiza
finansowa Strava](https://sacra.com/c/strava/), WebSearch wrzesień 2026)

**Czy działa:** tak, w sensie przychodu bezwzględnego (490 mln USD) — ale
**dzięki gigantycznej bazie (195 mln kont), nie dzięki wysokiej konwersji**.
2% z 195 mln to nadal ~4 mln płacących. 2% z potencjalnych kilkuset kont
Kukinga w zamkniętej alfie to zero osób — matematycznie zaokrągla się w dół.

**Przenosi się?** **Model tak (darmowy rdzeń + płatna wygoda), skala nie.**
To jest najbliższy strukturalnie przyszłej hipotezie premium z
`docs/MONETIZATION.md` (planer, filtry, tryb gotowania — wygoda, nie
treść) — ale liczba potwierdza dokładnie to, przed czym ostrzega sekcja 1:
subskrypcja za wygodę wymaga masy krytycznej, jakiej Kuking nie ma i nie
będzie mieć w najbliższej przyszłości.

### 2.7 Duolingo — subskrypcja usuwająca reklamy, przy ogromnej skali i latach optymalizacji

**Co sprzedaje:** brak reklam, życia/serca, dodatkowe funkcje nauki.
Skala: **ok. 9,2% aktywnych miesięcznie użytkowników płaci (stan na koniec
2025), po latach systematycznej optymalizacji konwersji — w 2020 r. ten
wskaźnik wynosił ok. 3%.** Ok. 10,9 mln płacących subskrybentów w połowie
2025. (dane spółki, SEC, WebSearch wrzesień 2026)

**Czy działa:** tak, przy dziesiątkach milionów MAU i dedykowanym zespole
wzrostu przez lata. **Przenosi się?** **Nie w tej fazie.** Nawet najlepszy
w branży wynik konwersji konsumenckiej subskrypcji (9%) osiągnięty został po
latach pracy przy ogromnej skali. To kolejny dowód na tę samą tezę: **model
subskrypcyjny wymaga milionów użytkowników, żeby pojedyncze procenty
konwersji dały cokolwiek — Kuking nie jest i długo nie będzie w tej lidze.**

### 2.8 Nextdoor i serwisy lokalne — reklama lokalna

**Co sprzedaje:** reklamę hiperlokalną firmom (posty sponsorowane w
sąsiedzkim feedzie), oparta na zweryfikowanej tożsamości i wysokim zaufaniu
lokalnym. Skala: **ok. 258 mln USD przychodu w 2025, reklama to ok. 80%
przychodu, ponad 100 mln zweryfikowanych „sąsiadów", 345 000+ sąsiedztw w 11
krajach, 46 mln WAU.** (raporty SEC spółki Nextdoor Holdings, WebSearch
wrzesień 2026 — źródło pierwotne, giełdowe)

**Czy działa:** tak, ale przy **gigantycznej gęstości lokalnej** — reklama
lokalna działa, gdy w danym sąsiedztwie jest wystarczająco dużo aktywnych
kont, żeby lokalny biznes zobaczył zwrot. Kuking nie ma geografii jako osi
organizującej (to nie jest serwis sąsiedzki, tylko ogólnopolska społeczność
gotowania) i nie ma skali. **Nie przenosi się w obecnym kształcie produktu.**
Gdyby Kuking rozwinął kiedyś lokalne koła (`docs/product/RETENTION_LOOPS.md`
wspomina koła gospodyń/UTW jako kanał akwizycji, nie monetyzacji), model
reklamy lokalnej mógłby wrócić jako temat — ale to wymagałoby zupełnie innej
architektury produktu niż dzisiejsza.

### 2.9 Print-on-demand — druk książek z treści użytkownika

**Co sprzedaje:** fizyczny produkt (fotoksiążka, książka kucharska) z
własnych treści użytkownika, jednorazowa transakcja, nie subskrypcja.
Rynek: **w Polsce działają od lat duzi gracze tego typu produktu — CEWE
Fotojoker (ponad 20 lat obecności w Polsce), Photobox (obecny w Polsce od
2010), oraz polski dostawca technologii white-label dla tej branży,
Printbox z Krakowa (założony 2012), obsługujący wielu operatorów fotoksiążek
w Europie.** ([CEWE Fotojoker](https://www.westfield.com/en/poland/arkadia/shops/cewe-fotojoker/69888),
[Photobox — Wikipedia](https://en.wikipedia.org/wiki/Photobox), [Printbox](https://www.getprintbox.com/),
WebSearch wrzesień 2026) **Nie znalazłem** konkretnej liczby przychodu rynku
fotoksiążek w Polsce w tym researchu — globalny rynek fotoksiążek jest
opisywany jako rosnący, ale to ogólnikowe źródła branżowe typu prognoz
rynkowych, nie twarde dane dla Polski. `[niepotwierdzone dla Polski]`.

**Czy działa:** obecność trzech niezależnych, działających od lat firm w
tym segmencie w Polsce jest **pośrednim, ale realnym dowodem**, że Polacy
kupują fizyczne, spersonalizowane produkty drukowane z własnych zdjęć —
inaczej te firmy by nie przetrwały dekady na tym rynku. To nie jest dowód
konkretnej kwoty, ale jest dowodem istnienia zachowania zakupowego.

**Przenosi się?** **Tak, i to jest najmocniejszy kandydat w całym tym
researchu** — patrz §3.3. Zbiega się z trzema niezależnymi liniami dowodowymi
z tego projektu: (1) `docs/product/SOUL.md` §4.3 planuje „Rodzinną książkę"
(okładka, spis treści, tryb do druku/PDF) jako V1, wprost nazywając ją
„rzeczą, którą trzyma się w ręku"; (2) `docs/research/AUDIENCE_50_PLUS.md`
wskazuje rodzinną książkę i OCR zeszytów jako „najbardziej prawdopodobne
źródło przychodu (druk, subskrypcja rodzinna)"; (3) issue #36 samo już
proponuje dokładnie to jako hipotezę do sprawdzenia. Zgadzam się z tą hipotezą
i nie muszę jej wymyślać — muszę tylko potwierdzić, że model przenaszalny
istnieje na realnym rynku (potwierdzone) i dodać zastrzeżenie z §3.3.

### 2.10 Notion / Obsidian — płacenie za narzędzie, nie za treść

**Co sprzedaje:** funkcje narzędziowe (synchronizacja, współpraca zespołowa,
publikowanie) wokół rdzenia, który zostaje darmowy dla użytku osobistego.
Nie znalazłem w tym researchu twardych liczb konwersji dla żadnego z dwóch
narzędzi — `[niepotwierdzone]`, nie podaję żadnej. Jakościowo: to jest model
„płać za narzędzie pracy", nie „płać za dostęp do społeczności/treści" —
działa, bo użytkownik i tak używa narzędzia codziennie do pracy zawodowej,
gdzie firma płaci koszt. **Nie przenosi się wprost** — Kuking nie jest
narzędziem pracy, jest miejscem życia towarzyskiego. Najbliższy odpowiednik
w liście z briefu to „płatne funkcje narzędziowe (eksport, kopia zapasowa,
tryb gotowania, planer)" — oceniam je osobno w §3.5, z tym samym
zastrzeżeniem co do skali.

---

## 3. Realistyczna ocena dla Kuking — werdykty

Format: **TAK teraz / TAK później (warunek) / NIE (powód)**.

### 3.1 Subskrypcja premium ogólna (funkcje wygody: filtry, brak reklam, więcej kolekcji)

**NIE teraz.** Trzy niezależne benchmarki (Cookpad §1, Strava §2.6, Duolingo
§2.7) pokazują zgodnie: subskrypcja konsumencka wymaga albo (a) milionów
użytkowników przy pojedynczych procentach konwersji, albo (b) unikalnego
powodu do płacenia, którego wygoda sama w sobie nie daje. Kuking ma dziś
zero potwierdzonych aktywnych kont. Budowanie systemu płatności, obsługi
faktur, rezygnacji z subskrypcji i wsparcia dla tego dla garstki osób jest
odwrotnością `AGENTS.md` §3 (zakaz overengineeringu bez zmierzonej potrzeby).

### 3.2 Reklama display

**NIE, i to bez warunku „później" — chyba że skala zmieni się drastycznie.**
Garnek.pl (polski, bezpośredni poprzednik kulturowy Kukinga) zamknął się z
podanym powodem „przychody reklamowe nie pokrywały kosztów" (§2.2). Do tego:
reklama wymaga zgody na profilowanie behawioralne pod RODO, jeśli jest
targetowana (`docs/SECURITY_PRIVACY_LEGAL.md` już to zaznacza), i psuje
dokładnie to, co `docs/UX_50_PLUS.md` chroni najmocniej — spokojny, czytelny,
nieprzeładowany interfejs dla grupy, która **boi się internetu bardziej niż
inne grupy wiekowe** (`docs/research/AUDIENCE_50_PLUS.md`: „strach o
pieniądze jest jej dominującą obawą online"). Reklama w feedzie między
zdjęciami rodzinnych obiadów jest sygnałem dokładnie takiej nieufności, jakiej
ta grupa unika.

### 3.3 Druk książki kucharskiej z własnego Zeszytu (print-on-demand)

**TAK później, warunek: przejście Bramki zamkniętej alfy I zbudowanie
funkcji „rodzinna książka" z `docs/product/SOUL.md` (V1).** To jest
najsilniejszy kandydat w całym researchu — potwierdzony rynek (§2.9),
potwierdzona zgodność z fundamentem produktu (§4.3 SOUL.md, rodzinne
receptury jako „największa przewaga Kuking"), i **explicit ostrzeżenie w
samym SOUL.md**, które w pełni podzielam: „Nie sprzedawać tego zbyt szybko
jako produktu płatnego — najpierw musi być powód emocjonalny, potem
monetyzacja." Warunek nie jest biurokratyczny — jest emocjonalny: książka,
za którą ktoś zapłaci, musi już istnieć jako coś, co ludzie **chcą mieć**,
zanim zapytamy, czy chcą za nią zapłacić.

**Zastrzeżenie, żeby nie zawyżać pewności:** teza brief-u „grupa 50+ ma
niską gotowość do subskrypcji, ale relatywnie wysoką do jednorazowego zakupu
fizycznego" jest **częściowo potwierdzona, częściowo domysł**. Potwierdzone:
GUS (`docs/research/AUDIENCE_50_PLUS.md`, dane z 21.10.2025) — 69,7% osób
16–74 lata robi zakupy internetowe, w grupie 55–64 to 60%, w 65+ to 33%; trzy
niezależne firmy działają od lat na polskim rynku fotoksiążek. **Niepotwierdzone
wprost:** że skłonność do jednorazowego zakupu jest WYŻSZA niż do
subskrypcji u TEJ SAMEJ osoby — nie znalazłem badania porównującego to 1:1
dla polskiej grupy 50+. To, co mam, to zgodność kierunkowa (istnieje
zachowanie zakupowe offline-podobne: rzecz w ręku, jednorazowo, na prezent),
nie liczbowe porównanie z subskrypcją. Cena musi być **sprawdzona na
realnych ludziach** (dokładnie to mówi issue #36) — pytanie „ile zapłaciłabyś
za wydrukowaną książkę ze swoimi przepisami", nie zgadywanie widełek.

### 3.4 Treści sponsorowane przez marki spożywcze

**NIE teraz, TAK później z twardym warunkiem skali i przejrzystości.**
`docs/MONETIZATION.md` już zakłada „wyraźne oznaczanie" — zgadzam się, i
dodaję: DSA wymaga oznaczenia reklamy jako reklamy w sposób widoczny
(`docs/SECURITY_PRIVACY_LEGAL.md` §DSA), a treść sponsorowana wśród
autentycznych, niewymyślonych rodzinnych przepisów jest dokładnie tym
ryzykiem, przed którym ostrzega `AGENTS.md` §9 (AI/marki nie generują
masowo treści pod SEO — zasada analogiczna dotyczy treści marketingowej
udającej autentyczny wpis). Warunek na „później": skala rzędu tysięcy
aktywnych kont (żeby marka w ogóle chciała rozmawiać) ORAZ jasna,
niewidzialna dla odbiorcy granica między „to jest reklama" a „to jest
przepis sąsiadki" — techniczne i redakcyjne oznaczenie, nie dopisek małym
druczkiem.

### 3.5 Płatne funkcje narzędziowe (eksport, kopia zapasowa, tryb gotowania, planer)

**Rozbijam na dwie różne odpowiedzi, bo `docs/FEATURES.md` już je rozdziela:**

- **Eksport i kopia zapasowa danych: NIE, nigdy za opłatą.** `docs/FEATURES.md`
  ma `eksport` w MVP, a `docs/SECURITY_PRIVACY_LEGAL.md` §GDPR czyni eksport
  danych **obowiązkiem prawnym** (prawo do przenoszenia danych, RODO
  art. 20). Płatny dostęp do własnych danych jest prawnie i etycznie
  niedopuszczalny, niezależnie od tego, czy dziś jest w `$fillable`, czy nie.
  Issue #36 już to nazywa „zasadą nienaruszalną": co jest darmowe dziś,
  zostaje darmowe.
- **Tryb gotowania i planer: TAK później, z tym samym warunkiem co
  subskrypcja ogólna (§3.1).** `docs/FEATURES.md` umieszcza oba w V1, a
  `docs/ROADMAP.md` „V1 gate" wprost zabrania budowania plannera przed
  potwierdzeniem WAC i D30. Innymi słowy: **produkt sam sobie już zablokował
  tę drogę do czasu potwierdzenia retencji** — nie muszę tego dodatkowo
  rekomendować, wystarczy nie łamać już podjętej decyzji. Issue #36 samo
  zadaje pytanie, czy planer ma być premium, czy darmowy — moja odpowiedź:
  **za wcześnie, żeby to pytanie miało sens, skoro plannera jeszcze nie ma.**

### 3.6 Afiliacja na produkty (sprzęt kuchenny, składniki)

**TAK później, niski priorytet, warunek: realny ruch na stronach przepisów
(SEO, po Bramce C z `docs/SEO_ANALYTICS_GROWTH.md`).** Afiliacja nie wymaga
budowania niczego w produkcie poza linkiem z parametrem śledzącym — to
najtańszy do wdrożenia z całej listy, ale też **generuje przychód
proporcjonalny do ruchu SEO**, którego dziś nie ma (deploy odłożony, D-011).
Zgodne z `AGENTS.md` §9 dopóki afiliacja dotyczy realnych składników/sprzętu
wymienionych w przepisie, nie ukrytego pod treścią reklamowego linku.
Ryzyko dla zaufania: link afiliacyjny musi być oznaczony, inaczej to jest
dokładnie ten sam problem zaufania co reklama nieoznaczona (§4).

### 3.7 Płatności między użytkownikami / wsparcie autora (model Patreon)

**NIE.** §2.4 pokazuje to wprost liczbą: nawet na platformie z 300 000
aktywnych twórców tylko 4% zarabia realne pieniądze. Przy skali Kukinga
(dziesiątki–setki kont w najbliższej przyszłości) ten model da **zero
realnego dochodu komukolwiek**, a zbuduje tylko infrastrukturę płatniczą,
obowiązki podatkowe/regulacyjne (usługi płatnicze, KYC) i presję na autorów,
żeby „zarabiali" na dzieleniu się przepisem babci — co jest sprzeczne z
`AGENTS.md` §1 („realne ugotowanie > lajki", nie „realne ugotowanie >
zarobek"). Zbudowanie tego przed potwierdzeniem, że jest choćby jeden autor
z realnym zasięgiem, jest podręcznikowym overengineeringiem.

### 3.8 Licencjonowanie danych (sprzedaż treści/danych stronom trzecim, np. do trenowania modeli AI)

**NIE, i to jest jedyny punkt w tej liście, który nazywam zamachem na
fundament produktu, nie tylko przedwczesnym pomysłem.** `AGENTS.md` §9
zabrania masowego generowania treści pod SEO przez AI, bo „skasowałoby
jedyny realny wyróżnik Kuking — autentyczność — i jest nieodwracalne."
Sprzedaż danych użytkowników (przepisów, zdjęć, historii „po kim ten
przepis") stronie trzeciej do dowolnego celu (w tym trenowania modeli) jest
tym samym ryzykiem z drugiej strony: ktoś publikuje przepis babci Haliny,
wierząc, że dzieli się nim ze społecznością ludzi, którzy gotują — nie
sprzedaje licencji do korpusu treningowego. To złamałoby dokładnie tę samą
zasadę zaufania, którą `docs/research/AUDIENCE_50_PLUS.md` cytuje jako
przyczynę upadku Naszej-Klasy: „regulamin z zgodą na przekazywanie danych
partnerom" był jednym z trzech wymienionych powodów utraty zaufania przed
zamknięciem serwisu. Nie ma warunku „później" dla tej opcji przy obecnym
rozumieniu produktu — zmiana wymagałaby jawnej, osobnej zgody każdego
użytkownika na każde użycie, nie zmiany regulaminu wstecz, co samo w sobie
czyni to nieopłacalnym operacyjnie przy zespole 1–2 osób.

### 3.9 Sprzedaż B2B (np. dla producentów spożywczych — dane rynkowe, insighty)

**NIE teraz, prawdopodobnie NIE nigdy w obecnym kształcie.** To jest wariant
łagodniejszy niż §3.8 (zagregowane, anonimowe insighty zamiast surowych
danych), ale ten sam problem skali co reklama: żaden producent nie kupi
raportu z rynku liczącego dziesiątki–setki użytkowników. Zostawiam furtkę
teoretyczną na „później" wyłącznie dla **w pełni zagregowanych, nieosobowych
trendów** (np. „wzrost zainteresowania przepisami na zakwas o X% r/r") przy
skali tysięcy aktywnych kont — ale to jest odległe „później", nie plan.

### 3.10 Zbiórka / darowizny

**TAK teraz, jako jedyna opcja bez kosztu zaufania, ale z zastrzeżeniem
skali przychodu: nie wiadomo, ile by to dało, i prawdopodobnie niewiele.**
To jest jedyny model z całej listy, który **nie wymaga** budowania niczego
w produkcie poza jednym linkiem/stroną (np. „Wesprzyj Kuking" z linkiem do
zewnętrznego serwisu zbiórkowego), nie tworzy obowiązku podatkowego
sprzedaży, nie psuje UX (jedna strona, nie w feedzie), i jest spójny z tym,
jak grupa 50+ już wspiera rzeczy, w które wierzy (np. wsparcie lokalnych
inicjatyw, kół gospodyń — `docs/research/AUDIENCE_50_PLUS.md` §kanały).
**Ale:** nie mam żadnego źródła szacującego, ile realnie dałaby taka
zbiórka przy tej społeczności — nie podaję liczby. Traktuję to jako
uzupełnienie kosztów hostingu (§5), nie jako model biznesowy.

### 3.11 Model „opłata tylko za koszt hostingu"

**TAK teraz, jako domyślne założenie do czasu decyzji o czymkolwiek innym.**
To nie jest osobna „opcja monetyzacji" w sensie biznesowym — to jest
**status quo**: `docs/COSTS.md` już pokazuje, że koszty na etapie alfy są
niskie (rząd $5–30/mies. łącznie Railway + R2, patrz §5), więc **nie trzeba
dziś żadnej monetyzacji, żeby produkt się utrzymał**. To jest najbezpieczniejszy
punkt wyjścia i pokrywa się z rekomendacją w §6.

---

## 4. Co monetyzacja psuje — dla każdej dopuszczonej opcji

Zasada z briefu, którą podzielam w całości: **opcja bez kosztu nie istnieje.**

| Opcja (z werdyktem TAK/TAK-później) | Zaufanie | UX 50+ | RODO | DSA | Motywacja autorów |
|---|---|---|---|---|---|
| **Druk książki (POD)** | Niskie ryzyko — to nowa, opcjonalna rzecz, nie zamknięcie czegoś darmowego za paywallem (zgodne z zasadą z issue #36) | Zero ryzyka — dzieje się poza głównym interfejsem, jako oddzielna ścieżka „Zamów wydruk" | Wymaga przekazania adresu do wysyłki (nowa kategoria danych — dokładniejsza niż to, co produkt dziś zbiera, `docs/SECURITY_PRIVACY_LEGAL.md` §Data minimization mówi wprost „nie zbierać bez potrzeby... dokładnego adresu" — to jest wyjątek, który trzeba świadomie uzasadnić celem zamówienia, nie zbierać z góry) | Niska ekspozycja — to nie reklama, nie dotyczy obowiązków DSA o reklamie | Żadnej — to produkt DLA autora, nie sprzedaż JEGO treści komuś innemu |
| **Treści sponsorowane** | Wysokie ryzyko, jeśli oznaczenie jest słabe — grupa, która „boi się internetu", najszybciej traci zaufanie do miejsca, gdzie reklama udaje przepis sąsiadki | Wysokie ryzyko — łamie „spokojny, czytelny interfejs" jeśli sponsorowany post wygląda jak zwykły wpis | Jeśli targetowana behawioralnie — wymaga zgody (RODO), nie tylko oznaczenia | Wymaga oznaczenia reklamy zgodnie z DSA — obowiązek informacyjny, koszt operacyjny dla zespołu 1–2 osób | Ryzykuje: prawdziwy autor traci widoczność na rzecz płatnej treści w tym samym formacie |
| **Afiliacja** | Średnie — link afiliacyjny nieoznaczony jest tym samym problemem zaufania co reklama nieoznaczona | Niskie — jeden link pod składnikiem, nie zasłania głównej akcji | Niska ekspozycja, jeśli nie łączy się z profilowaniem | Wymaga oznaczenia jako link afiliacyjny/reklamowy | Żadnej bezpośrednio |
| **Zbiórka/darowizny** | Bardzo niskie ryzyko — jawna prośba o wsparcie od małego zespołu jest inna kulturowo niż reklama | Zero, jeśli poza głównym interfejsem | Minimalna — dane płatności idą przez zewnętrznego operatora zbiórki | Brak obowiązków reklamowych (to nie reklama) | Żadnej |
| **Opłata za hosting (status quo)** | Zero ryzyka — nic się nie zmienia | Zero ryzyka | Zero nowych danych | Nie dotyczy | Nie dotyczy |

**Nigdy nie eksponowane jako motywacja do publikowania, niezależnie od
wybranej opcji:** żadna forma monetyzacji nie może zamienić się w licznik
zarobku widoczny obok wpisu — to jest to samo ryzyko co liczniki lajków
(`AGENTS.md` §12) w nowym przebraniu. Gdyby kiedyś powstało wsparcie autorów
(§3.7, dziś odrzucone), kwota wsparcia nie powinna być publiczna przy
profilu — dokładnie z tego samego powodu, dla którego dziś nie ma publicznego
licznika lajków.

---

## 5. Koszty po stronie serwisu

`docs/COSTS.md` podaje (wrzesień 2026, ceny orientacyjne, do sprawdzenia
przed zakupem):

- Railway, realistyczna alfa: **ok. $5–15/mies.**; mała produkcja: **ok.
  $10–30/mies.**, zależnie od RAM bazy, workerów i ruchu;
- Cloudflare R2: **$0,015/GB-mies.** storage (100 GB ≈ $1,50/mies., 1 TB ≈
  $15/mies.), operacje liczone osobno, egress standardowy bez opłaty.

**Łączny koszt miesięczny produktu na dziś: nie wiadomo precyzyjnie** —
`docs/COSTS.md` podaje tylko składowe (Railway + R2), nie sumę, i sam
zaznacza, że ceny są orientacyjne. Rząd wielkości dla alfy to **poniżej
$50/mies. łącznie**, jeśli zsumować górne widełki obu składowych — ale to
jest moje przybliżenie z podanych zakresów, nie liczba z dokumentu, więc
oznaczam ją jako `[oszacowanie z istniejących widełek, nie osobna liczba
źródłowa]`.

**Konsekwencja wprost, tak jak prosi brief:** przy koszcie rzędu
dziesiątek dolarów miesięcznie, budowanie systemu subskrypcji, obsługi
faktur VAT, rezygnacji i wsparcia klienta dla przychodu rzędu **40 zł
miesięcznie** (przykład z brief-u) jest ewidentnie nieopłacalne — koszt
operacyjny (czas zespołu 1–2 osób) przewyższyłby przychód wielokrotnie.
`docs/COSTS.md` samo to potwierdza pośrednio: lista rzeczy, które „naprawdę
mogą kosztować" przy wzroście (moderacja, support, storage, RAM, e-mail,
marketing) to koszty **operacyjne**, nie frameworkowe — i żaden z nich nie
jest dziś finansowany przez przychód, bo przychodu nie ma. To jest zgodne
z rekomendacją §3.11: **darmowy status quo jest dziś jedynym rozsądnym
stanem.**

---

## 6. Rekomendacja

**Zrobić najpierw: nic w sensie wdrożenia płatności — zbudować „rodzinną
książkę" (`docs/product/SOUL.md` §4.3, już zaplanowaną na V1) jako produkt
emocjonalny bez ceny, i dopiero gdy ludzie faktycznie z niej korzystają
(wypełniają „po kim ten przepis", zapisują przepisy do własnej książki),
zapytać garstkę realnych użytkowników, ile zapłaciliby za jej wydrukowaną
wersję.**

**Warunek, żeby w ogóle rozmawiać o monetyzacji poważnie:** przejście
`docs/ROADMAP.md` „Closed alpha gate" (20+ realnych userów, stabilny upload,
moderacja działa) — nie dlatego, że to formalność, ale dlatego, że **przed
tym progiem nie ma komu zadać pytania o cenę**. Druga, dalsza bramka: „V1
gate" (WAC i D30 pokazują powroty) przed jakąkolwiek subskrypcją funkcji
narzędziowych (§3.5) — to już jest zapisane w `docs/ROADMAP.md` i nie
wymaga nowej decyzji, tylko jej przestrzegania.

Uczciwa odpowiedź na pytanie „co monetyzować teraz" brzmi: **nic, poza
opcjonalnym linkiem do dobrowolnego wsparcia kosztów hostingu (§3.10-3.11) —
i to jest dopuszczalna, prawdopodobnie właściwa odpowiedź na tym etapie**,
nie porażka planowania.

---

## 7. Czego nie sprawdziłem

- **Nie mam ceny za żadną wersję „rodzinnej książki"** — ani kosztu druku
  u polskiego dostawcy (CEWE, Photobox, Printbox — nie zbierałem ofert
  cenowych), ani tego, ile zapłaciłby za nią realny użytkownik Kukinga.
  To jest dokładnie badanie, które issue #36 słusznie każe zrobić na
  żywych ludziach, nie zgadywaniem.
- **Nie sprawdziłem stawki prowizji Substacka** ani dokładnego źródła
  liczb Patreon/Substack poza zagregowanymi artykułami branżowymi — oznaczone
  wprost w tekście jako `[z drugiej ręki, do weryfikacji]`.
- **Nie sprawdziłem konkretnej wielkości polskiego rynku fotoksiążek** w
  złotych ani liczby zamówień rocznie — potwierdziłem tylko istnienie
  graczy, nie skalę rynku.
- **Nie sprawdziłem kosztów operatora płatności** (prowizje Stripe/Przelewy24/
  podobne) dla żadnego scenariusza, w tym druku — potrzebne, zanim ktokolwiek
  policzy realną marżę na zamówieniu książki.
- **Nie konsultowałem się z prawnikiem** w kwestii obowiązków podatkowych/
  VAT przy sprzedaży fizycznego produktu (książka) z Polski — inne niż
  obowiązki przy samej platformie cyfrowej, poza zakresem tego researchu.
- **Nie sprawdziłem, czy ktokolwiek w zespole Kuking ma dziś zdolność
  operacyjną do obsługi zamówień fizycznych** (magazyn, wysyłka, zwroty) —
  prawdopodobnie nie ma, bo zespół to 1–2 osoby (D-012), ale nie potwierdzam
  tego wprost, bo to pytanie organizacyjne, nie badawcze.
