# Newsletter redakcyjny — prawo, EmailLabs, sens marketingowy

> Research na pytanie właściciela: czy polityka prywatności i EmailLabs
> pozwalają wysyłać cotygodniowy newsletter z wyróżnionymi przepisami, i jak
> to rozwiązać marketingowo. **To jest wejście do rozmowy z prawnikiem
> (issue #8), nie jej zastąpienie** — każde twierdzenie prawne ma odnośnik
> albo `[do weryfikacji]`. Stan na 10 września 2026.

---

## Rekomendacja

**Nie buduj newslettera redakcyjnego teraz.** Tygodniowy digest z PR #236
(D-057) już robi to, po co właściciel pyta o newsletter — przypomina o
serwisie — i robi to lepiej, bo jest spersonalizowany do konkretnej osoby,
podczas gdy redakcyjny wybór cudzych przepisów ląduje w najsłabszej
kategorii powodów powrotu z `docs/product/RETENTION_LOOPS.md`. Jeśli
właściciel chce mimo to *wyróżniać* przepisy publicznie, tańszym i
bezpieczniejszym rozwiązaniem jest **cotygodniowa kolekcja redakcyjna na
stronie** (bez rankingu, bez nowego kanału pocztowego, bez nowej zgody
marketingowej) linkowana z istniejącego digestu — dokładnie ten mechanizm
jest już opisany jako Pętla 4 w `RETENTION_LOOPS.md`. Prawnie newsletter
redakcyjny to informacja handlowa wymagająca **osobnej, aktywnej zgody**
(art. 398 Prawa komunikacji elektronicznej) — inna podstawa niż digest — a
technicznie darmowy budżet EmailLabs (300 listów/dobę) jest **już w całości
rozdysponowany** przez digest, logowanie linkiem i rezerwę transakcyjną,
więc jakikolwiek newsletter wymaga dodatkowego kosztu (EmailLabs Essential
30, ok. 99–129 zł/mies.) od pierwszego dnia. Warunkiem, gdyby temat mimo to
wrócił, jest zgoda prawnika na brzmienie zgody i akceptacja właściciela, że
redakcja to trwały, cotygodniowy obowiązek człowieka, którego przerwanie po
kilku tygodniach kosztuje więcej zaufania niż brak newslettera od początku.

---

## 1. Prawo — stan faktyczny

### 1.1 Co dziś mówi polityka prywatności

Tabela w `resources/legal/polityka-prywatnosci.md` (sekcja 2) ma dziś jeden
wiersz obejmujący pocztę:

> „Wiadomości e-mail (reset hasła, powiadomienia) | adres e-mail, treść
> wiadomości | **Wykonanie umowy (wiadomości niezbędne do działania
> konta)** | Do usunięcia konta"

Newsletter z wyróżnionymi przepisami **nie jest niezbędny do działania
konta** — konto działa identycznie bez niego. Nie mieści się więc w tym
wierszu i nie mieści się w podstawie „wykonanie umowy”. To samo zauważa już
`docs/legal/COMPLIANCE.md` (tabela RODO): „Art. 6(1)(b) dla e-maili
transakcyjnych; **Art. 6(1)(a) zgoda dla e-maili marketingowych, jeśli
takie się pojawią**”. Ta rezerwa została już zrobiona — trzeba ją tylko
wypełnić.

Gałąź `claude/tygodniowy-digest` (D-057, PR #236) już to zrobiło **dla
digestu**: „Poczta produktowa, nie transakcyjna — podstawą jest **zgoda**
(art. 6 ust. 1 lit. a RODO)”, z polem `users.wants_weekly_digest` (opt-in),
wypisaniem bez logowania przez podpisany link i nagłówkami
`List-Unsubscribe`/`List-Unsubscribe-Post` (RFC 8058). To jest wzorzec
techniczny i prawny, który newsletter — gdyby powstał — powinien
skopiować, nie wymyślać od nowa.

### 1.2 Co obowiązuje dziś w prawie polskim (stan na 2026)

Zadanie słusznie zwraca uwagę, że art. 172 Prawa telekomunikacyjnego już
nie obowiązuje. Ustalone:

- **Ustawa Prawo komunikacji elektronicznej** z 12 lipca 2024 r. weszła w
  życie **10 listopada 2024 r.** Jej **art. 398** przejął treść dawnego
  art. 172 ust. 1 Prawa telekomunikacyjnego niemal w całości i skonsolidował
  wymóg zgody na marketing bezpośredni we wszystkich kanałach elektronicznych
  (e-mail, SMS, telefon) w jednym przepisie.
  ([prawo.pl](https://www.prawo.pl/biznes/prawo-komunikacji-elektronicznej-zgoda-na-dzialania-marketingowe,534839.html),
  [outreachpilot.pl](https://outreachpilot.pl/poradnik/art-398-pke),
  [infor.pl](https://mojafirma.infor.pl/biznes/prawo/rodo-w-firmie/7518377,zgody-marketingowe-po-10-listopada-2024-r-co-zmienia-prawo-komunikacji-elektronicznej.html))
- **Wymogi zgody z art. 398 PKE**, spójne we wszystkich sprawdzonych
  źródłach: **uprzednia** (przed pierwszym kontaktem), **aktywna** (nie
  domyślnie zaznaczony checkbox), **konkretna** (wskazuje kanał, cel i
  podmiot), **dokumentowana** (nadawca musi umieć wykazać, kto, kiedy i na
  co się zgodził — ciężar dowodu leży po jego stronie), **odwoływalna
  równie łatwo, jak udzielona**, i **osobna dla każdego kanału** (zgoda na
  e-mail nie jest zgodą na SMS ani telefon).
- **Brak wyjątku „soft opt-in” dla dotychczasowych klientów.** Sprawdzone w
  dwóch niezależnych źródłach: art. 398 PKE nie przewiduje mechanizmu
  znanego z niektórych innych krajów UE (kontakt marketingowy z istniejącym
  klientem bez odrębnej zgody, jeśli oferuje się podobne produkty i przy
  każdej wiadomości można się wypisać). W Polsce trzeba mieć zgodę zawsze,
  niezależnie od tego, czy odbiorca jest już użytkownikiem serwisu.
  `[do weryfikacji z prawnikiem — interpretacje kancelarii nie są w 100%
  zgodne, a to jest zdanie, na którym stoi cała reszta tej sekcji]`
- **Sankcje**: kara od Prezesa UKE do 3% przychodu z poprzedniego roku albo
  do 1 mln zł, dodatkowo wysyłka bez zgody może być wykroczeniem zagrożonym
  grzywną.
  ([prawo.pl](https://www.prawo.pl/biznes/prawo-komunikacji-elektronicznej-zgoda-na-dzialania-marketingowe,534839.html))

**Status art. 10 ustawy o świadczeniu usług drogą elektroniczną** (zakaz
niezamówionej informacji handlowej do osoby fizycznej) jest **niepewny i
nie udało się go tu jednoznacznie potwierdzić**. Część źródeł wtórnych
sugeruje uchylenie tego przepisu w 2026 r. w ramach nowelizacji wdrażającej
DSA (rozdział ustawy o odpowiedzialności hostingodawców jest dziś zastępowany
przez wprost obowiązujące rozporządzenie DSA), ale dostęp do pierwotnego
tekstu jednolitego na `isap.sejm.gov.pl` był w tej sesji zablokowany
weryfikacją człowieka, więc **nie mogę tego potwierdzić z pierwotnego
źródła**. `[do weryfikacji — sprawdzić aktualny tekst jednolity ustawy o
świadczeniu usług drogą elektroniczną na isap.sejm.gov.pl albo w LEX, i
ustalić wprost z prawnikiem, czy art. 10 dziś obowiązuje]`. To nie zmienia
praktycznego wniosku: **niezależnie od losu art. 10 UŚUDE, art. 398 PKE
samodzielnie i bezspornie wymaga uprzedniej zgody na e-mail marketingowy**
— obowiązek zgody istnieje tak czy inaczej, dwoma niezależnymi drogami albo
jedną.

### 1.3 Digest (#236) kontra newsletter redakcyjny — gdzie leży granica

To jest sedno pytania właściciela, i te dwie rzeczy różnią się prawnie, nie
tylko kosmetycznie:

| | Tygodniowy digest (#236, D-057) | Newsletter redakcyjny |
|---|---|---|
| **Treść** | Wyłącznie własna aktywność odbiorcy: kto ugotował z jego przepisu, kto go zaczął obserwować, co pokazali ludzie, których on sam obserwuje | Redakcyjny wybór cudzych przepisów, ten sam dla wszystkich odbiorców |
| **Zależy od relacji odbiorcy w serwisie?** | Tak — treść jest inna dla każdej osoby i zależy od jej grafu społecznego | Nie — każdy dostaje to samo, niezależnie od tego, kogo obserwuje albo czy ma choć jeden przepis |
| **Test**: czy osoba bez żadnej relacji w serwisie (0 obserwowanych, 0 przepisów) dostałaby ten list? | Nie (list pusty się nie wysyła) | Tak zawsze — treść nie zależy od niej |
| **Kwalifikacja prawna** | Broniona jako funkcja produktu (rozszerzenie konta), ale i tak zbierana na zgodzie z ostrożności (D-057 świadomie wybrał art. 6 ust. 1 lit. a RODO, nie „wykonanie umowy”) | Podręcznikowa informacja handlowa / marketing bezpośredni w rozumieniu art. 398 PKE — promuje serwis i cudze treści, niezależnie od tego, że nic nie sprzedaje wprost |
| **Wniosek** | Da się bronić jako funkcja produktu | Jest treścią promocyjną i wymaga **osobnej** zgody marketingowej, nie da się jej „donaklejać” do zgody na digest |

Krótko: digest mówi *o Tobie*, newsletter redakcyjny mówi *o innych, do
Ciebie*. Pierwsze można oprzeć na relacji z użytkownikiem, drugie zawsze
wymaga jego osobnej, jednoznacznej zgody.

### 1.4 Co dopisać — gotowe brzmienia

Jeśli newsletter mimo rekomendacji powstanie, potrzebne są trzy zmiany
(oprócz migracji dodającej pole zgody — poza zakresem tego dokumentu):

**A. Nowy wiersz w tabeli `resources/legal/polityka-prywatnosci.md` sekcja 2**
(obok istniejącego wiersza o poczcie), analogiczny do tego, jaki digest
powinien dostać przy scaleniu #236:

> | Newsletter redakcyjny (wyróżnione przepisy, raz w tygodniu) | adres e-mail | **Zgoda** (art. 6 ust. 1 lit. a RODO oraz art. 398 Prawa komunikacji elektronicznej) — osobna od zgody na tygodniowe podsumowanie i niezależna od założenia konta | Do wycofania zgody |

Do tego akapit wyjaśniający (pod tabelą, w stylu reszty dokumentu):

> „Newsletter redakcyjny wysyłamy tylko osobom, które same o to poprosiły —
> to nie jest wiadomość niezbędna do działania konta, tak jak potwierdzenie
> rejestracji czy reset hasła, dlatego pytamy o zgodę osobno i nie zaznaczamy
> jej za Ciebie. W każdej chwili możesz się wypisać — link jest w każdej
> wiadomości i nie wymaga logowania. To jest inna zgoda niż zgoda na
> tygodniowe podsumowanie Twojej własnej aktywności — możesz mieć jedną, obie
> albo żadną.”

**B. Paragraf w `resources/legal/regulamin.md`** (np. jako rozszerzenie
sekcji 2 „Czym jest Kuking”, albo nowa krótka sekcja):

> „Newsletter redakcyjny jest usługą opcjonalną — nie jest wymagany do
> korzystania z Kuking i nie wpływa na żadną inną funkcję konta. Zapisujesz
> się do niego dobrowolnie, osobnym oświadczeniem zgody, i wypisujesz się w
> każdej chwili, bez podawania powodu.”

**C. Pole zgody w ustawieniach** (`/ustawienia/prywatnosc`, tam gdzie już
jest przełącznik digestu), z osobnym, niezaznaczonym domyślnie
checkboxem:

> „☐ Chcę też dostawać newsletter Kuking — raz w tygodniu e-mail z
> wyróżnionymi przepisami od innych osób, wybranymi przez [imię gospodarza].
> To jest coś innego niż Twoje tygodniowe podsumowanie: to jest wybór
> redakcyjny, nie coś, co dotyczy tylko Ciebie.”

Techniczny odpowiednik: nowa kolumna `users.wants_editorial_newsletter`
(boolean, domyślnie `false`, poza `$fillable` tak jak `wants_weekly_digest`)
plus `consented_at` i `consent_source` (np. `"ustawienia"`,
`"formularz_migracji_garnek"`) — art. 398 PKE wymaga móc **wykazać**, kto,
kiedy i skąd wyraził zgodę, więc sam boolean bez znacznika czasu i źródła
nie wystarczy jako dokumentacja.

---

## 2. EmailLabs — czy wolno i czy się zmieści

### 2.1 Marketing czy tylko transakcyjne

EmailLabs (Vercom S.A.) **obsługuje oba typy wysyłki na tym samym koncie** —
transakcyjną i marketingową — ale jego własna dokumentacja **rekomenduje
rozdzielenie ruchu** na dwa osobne konta SMTP w ramach jednego konta
głównego, właśnie po to, żeby wysyłka newslettera nie blokowała kolejki
wiadomości transakcyjnych. Rozdzielenie ustawia się przez kontakt z obsługą
klienta, nie samodzielnie w panelu.
([EmailLabs FAQ, za pośrednictwem bazy wiedzy Selly](https://www.selly.pl/baza-wiedzy/integracje/integracja-newslettera-emaillabs/))

**Nie udało się potwierdzić** w publicznie dostępnej dokumentacji, czy
limit dobowy darmowego planu STARTUP (300/dobę) jest **wspólny dla obu**
kont SMTP na tym samym koncie głównym, czy liczony osobno dla każdego, ani
czy EmailLabs pozwala jednemu podmiotowi założyć **dwa niezależne** konta
STARTUP (co dawałoby dwie osobne pule 300/dobę). `[do weryfikacji
bezpośrednio z supportem EmailLabs, zanim ktokolwiek zaplanuje newsletter na
tej podstawie — wiele dostawców zabrania wielu darmowych kont jednemu
podmiotowi właśnie po to, żeby nie dało się obejść limitu w ten sposób]`

### 2.2 Reputacja nadawcy — najważniejszy punkt techniczny

To jest realne ryzyko, nie teoretyczne: poczta marketingowa zbiera
nieporównywalnie więcej zgłoszeń spamu niż transakcyjna, a WP.pl (razem z
o2.pl na tej samej infrastrukturze) wprost ostrzega, że brak polityki DMARC
albo zła reputacja nadawcy kończy się lądowaniem w spamie
(`docs/decyzje/POCZTA.md` §3, cytat z pomocy WP). Jeśli newsletter idzie z
tej samej subdomeny co potwierdzenia rejestracji i resety haseł, jedna
nieudana kampania — źle dobrana treść, zbyt agresywna częstotliwość, wysoki
odsetek „to jest spam” zamiast wypisania — psuje dostarczalność **całej**
poczty, łącznie z listami, bez których nikt nie wejdzie do serwisu.

Ustalenia:

- `docs/infra/POCZTA_URUCHOMIENIE.md` §1 pkt 3 już nakazuje **osobną
  subdomenę wysyłkową** (np. `poczta.kuking.pl`) dla całej poczty
  transakcyjnej, właśnie po to, żeby awaria reputacji nie zabiła poczty
  firmowej z gołego `kuking.pl`. Ten sam mechanizm trzeba zastosować **raz
  jeszcze**, żeby oddzielić newsletter od transakcyjnych: newsletter na
  **swojej własnej** subdomenie (np. `newsletter.kuking.pl`), z własnymi
  rekordami SPF i DKIM.
- **Zastrzeżenie, które trzeba znać**: nawet przy osobnej subdomenie, jeśli
  oba strumienie idą z tego samego konta EmailLabs pod tą samą domeną
  nadrzędną w polityce DMARC, część reputacji — zwłaszcza przy ocenie
  „organizational domain”, którą stosuje część dużych skrzynek — może się
  częściowo przenosić między subdomenami. Efektywna izolacja reputacji
  między subdomenami tej samej domeny różni się między dostawcami pocztowymi
  i **nie jest gwarantowana w 100%**. `[do weryfikacji — nie ma publicznego,
  niezależnego testu tego zjawiska dla wp.pl/o2.pl/interia.pl/onet.pl;
  `docs/decyzje/POCZTA.md` §3 już rekomenduje własny test 20 skrzynek przed
  betą — ten sam test powinien objąć docelowo też newsletter, gdyby powstał]`
- Najbezpieczniejsze rozdzielenie łączy trzy rzeczy naraz: osobna subdomena
  + osobne konto/strumień SMTP u dostawcy (§2.1) + osobne monitorowanie
  bounce/complaint dla każdego strumienia.

### 2.3 Arytmetyka — rachunek, nie życzenie

To jest punkt, w którym pytanie właściciela samo już zawiera odpowiedź:
**„Newsletter do 500 osób to 500 listów — czyli więcej niż cała doba.”**
Sprawdzone liczbowo:

- Plan STARTUP: **300 listów na dobę, wspólne dla całego konta**
  (`docs/decyzje/POCZTA.md` §1,
  [emaillabs.io/cennik-v2](https://emaillabs.io/cennik-v2/), sprawdzone
  10.09.2026 — zgodne z ustaleniem `POCZTA.md` z 08.09.2026).
- Gałąź `claude/tygodniowy-digest` (D-057) **już rozdysponowała cały ten
  budżet** w `config/kuking.php` (sekcja `poczta`/`digest`):

  ```
  120  logowanie linkiem e-mail   (issue #25)
   60  tygodniowe podsumowanie    (issue #11, D-057)
  100  rezerwa transakcyjna       (rejestracja, reset hasła)
   20  zapas
  ---
  300  = cały dobowy limit dostawcy
  ```

  **Zero wolnego miejsca.** Newsletter redakcyjny nie ma dziś ani jednego
  listu budżetu, do którego mógłby sięgnąć bez naruszenia budżetu czegoś
  innego.
- Nawet gdyby ktoś rozłożył newsletter na cały tydzień (jak digest, D-057:
  „420 osób = 60 × 7”) i korzystał **wyłącznie** z 20-listowego zapasu, da
  to maksymalnie **20 × 7 = 140 odbiorców tygodniowo**, zanim zacznie
  zjadać budżet chroniący logowanie linkiem albo rejestracje — czyli
  dokładnie ten kompromis, przed którym `config/kuking.php` explicite
  ostrzega („kierunek pomyłki jest tu wybrany świadomie”).
- Sam przykład właściciela — **500 odbiorców** — przekracza **całą** dobową
  pulę konta (300), zanim jeszcze uwzględni się cokolwiek innego. Jednorazowa
  wysyłka do 500 osób w jeden dzień jest niemożliwa na planie STARTUP w
  ogóle, niezależnie od tego, ile innej poczty tego dnia idzie.

**Wniosek: plan darmowy przestaje wystarczać nie „przy jakiejś przyszłej
skali” — przestaje wystarczać już dziś**, w chwili, gdy digest (#236) w
ogóle wejdzie na produkcję, bo cały budżet jest już rozdany.

**Pierwszy sensowny płatny plan**: EmailLabs **Essential 30** — 99 zł/mies.
(129 zł/mies. po okresie promocyjnym), do 100 000 e-maili/miesiąc, **bez
limitu dobowego**
([emaillabs.io/cennik-v2](https://emaillabs.io/cennik-v2/), sprawdzone
10.09.2026).

- Przy 500 subskrybentach, wysyłka raz w tygodniu: 500 × 4,33 tygodnia/mies.
  ≈ **2 165 e-maili/miesiąc** — ok. 2% limitu Essential 30, z ogromnym
  zapasem.
- Limit miesięczny Essential 30 (100 000) staje się wiążący dopiero przy ok.
  **23 000 subskrybentach** wysyłanych co tydzień (100 000 ÷ 4,33) — poziom
  daleko poza jakąkolwiek prognozą tego serwisu w przewidywalnej
  przyszłości.
- **Koszt wobec dzisiejszego budżetu**: `docs/MONETIZATION.md` liczy koszt
  utrzymania alfy na „poniżej 50 USD/mies.” Essential 30 (ok. 25–35
  USD/mies. po kursie z `docs/decyzje/POCZTA.md`) to praktycznie
  **podwojenie** tego budżetu dla funkcji, którą sam właściciel nazywa
  pomysłem do zbadania, nie priorytetem. Kwota bezwzględnie jest mała, ale
  to jest **nowy koszt stały, którego dziś nie ma** — a digest (#236),
  który realizuje ten sam cel przypominania, mieści się w całości w planie
  darmowym.

**Alternatywa tańsza**: Amazon SES w `eu-central-1`, ok. 0,10 USD za 1000
e-maili → 500/tydzień ≈ 2 165/mies. ≈ **0,22 USD/miesiąc**, praktycznie
darmowe. Ale `docs/decyzje/POCZTA.md` już policzył koszt wdrożenia SES
(wniosek o production access, własna obsługa bounce/complaint przez SNS,
„~1–3 dni” pracy) i już zarekomendował trzymanie go jako plan na
„eksplozję wolumenu”, nie na dzisiejszą skalę. Przy objętości newslettera
liczonej w tysiącach maili miesięcznie różnica ok. 100 zł/mies. nie
uzasadnia dodatkowej złożoności utrzymaniowej dla jednoosobowego zespołu —
**jeśli newsletter w ogóle powstanie, powinien zostać u tego samego
dostawcy** (Essential 30), zgodnie z już podjętą decyzją o SES.

---

## 3. Marketingowo — czy to w ogóle ma sens

### 3.1 Po co komu ten newsletter — i czy dokłada cokolwiek do #236

`docs/product/RETENTION_LOOPS.md` §1 układa powody powrotu w hierarchię
siły:

| Siła | Powód powrotu |
|---|---|
| 🔥🔥🔥 | Ktoś zwrócił się do mnie |
| 🔥🔥 | Mam coś do zrobienia |
| 🔥 | Ciekawość |

Digest (#236) trafia w pierwszą kategorię wprost: „Marek ugotował Twoje
pierogi” to zdarzenie osobiste, dotyczące konkretnie tej osoby. Newsletter
redakcyjny — „zobacz, co inni ugotowali w tym tygodniu” — ląduje w
najsłabszej kategorii, „ciekawość”, i **dubluje sekcję 3 samego digestu**
(„Co pokazali ludzie, których obserwujesz”), tylko z gorszym
targetowaniem: redakcyjny wybór gospodarza zamiast własnego grafu
obserwowanych osób adresata.

Do tego dochodzi twardy limit już zapisany w `RETENTION_LOOPS.md` §3.2:

> „E-maile nietransakcyjne (digest, przypomnienie o zapisanych) maks. **1
> tygodniowo** każdy typ, **łącznie maks. 2**”

Newsletter redakcyjny byłby **trzecim** typem nietransakcyjnej poczty
tygodniowej — koliduje z tym limitem wprost, nie tylko w duchu.

**Werdykt**: jeśli celem jest przypominanie o serwisie (retencja) —
newsletter redakcyjny nie dokłada nic ponad #236, jest jego słabszą,
droższą w utrzymaniu wersją.

Jeśli celem jest **pozyskiwanie nowych osób** (fala z Garnka) — newsletter
do zapisanych subskrybentów jest do tego z definicji bezużyteczny: dociera
tylko do ludzi, którzy już mają konto i już wyrazili zgodę. Nie dotrze do
nikogo, kto jeszcze nie założył konta w Kuking. Jedyny scenariusz, w którym
„newsletter z wyróżnionymi przepisami” realnie pomaga w pozyskiwaniu, to
**publicznie dostępna strona/kolekcja bez loginu** jako materiał do
udostępniania i SEO — ale to jest inny projekt (redakcyjny content
marketing na stronie), nie „newsletter wysyłany do zapisanych userów”, o
który pyta właściciel.

### 3.2 Kto redaguje — i co się dzieje, gdy ta osoba ma grypę

Serwis prowadzi jedna osoba (potwierdzone wielokrotnie: `AGENTS.md`,
`docs/MONETIZATION.md`, `docs/DECISIONS.md`). Szczery szacunek nakładu na
newsletter z ręcznie wybranymi przepisami, bez rankingu po lajkach (zakaz z
`AGENTS.md` §12 — trzeba czytać jakościowo, nie sortować po liczbie):

- Przejrzenie wpisów z tygodnia i wybór ok. 5–8 pozycji: **1–2 godziny**,
  rosnące wraz z liczbą wpisów przy fali z Garnka.
- Napisanie krótkiego komentarza redakcyjnego do każdej pozycji (inaczej to
  jest goła lista linków, nie „wyróżnienie”): **ok. godzina**.
- Złożenie e-maila, sprawdzenie na własnej skrzynce, wysyłka, obserwacja
  bounce/spam: **pół godziny do godziny**.

Łącznie realistycznie **3–4 godziny tygodniowo, bez przerwy** — to wchodzi
w bezpośredni konflikt z resztą obowiązków jednoosobowego zespołu
(moderacja, rozwój produktu, odpowiadanie na zgłoszenia).

Co się dzieje, gdy ta osoba ma grypę: newsletter nr 4 nie wychodzi. To nie
jest neutralne — `docs/research/AUDIENCE_50_PLUS.md` odnotowuje, że grupa
50+ **sama deklaruje strach przed niechcianymi e-mailami**. Nieregularna
wysyłka, a potem nagły powrót po przerwie, wygląda jak spam albo przejęte
konto — podnosi odsetek wypisów i zgłoszeń spamu, co uderza bezpośrednio w
reputację **całej domeny wysyłkowej** (sekcja 2.2) i przez to w pocztę
transakcyjną, bez której nikt nie wejdzie do serwisu. Digest (#236) nie ma
tego problemu z definicji: jest zautomatyzowany, dane bierze z samego
systemu, może „chorować” razem z właścicielem bez przerwy w wysyłce.

### 3.3 Jak zdobyć zgody na starcie, bez ciemnych wzorców

- **Domyślnie zaznaczony checkbox przy rejestracji odpada** — jest prawnie
  wątpliwy (art. 398 PKE wymaga zgody „aktywnej”, nie domyślnej) i sprzeczny
  z tonem `AGENTS.md`/`docs/UX_50_PLUS.md`.
- Zgoda na newsletter **nie powinna być łączona z żadnym krokiem
  rejestracji** — osobny, jawnie opisany, domyślnie odznaczony checkbox w
  `/ustawienia/prywatnosc`, obok już istniejącego przełącznika digestu, z
  wyjaśnieniem różnicy między nimi (patrz brzmienie w §1.4).
- Można zapytać **raz, po pozytywnym doświadczeniu** — np. po pierwszym
  „Ugotowałem” albo po pierwszym otrzymanym digest: „Podobał Ci się ten
  mail? Możesz też dostawać nasz tygodniowy przegląd wyróżnionych
  przepisów.” Ale to zakłada, że newsletter **już istnieje i jest
  stabilny** — więc kolejność musi być: najpierw zbudować i utrzymać
  digest, dopiero potem ewentualnie dopytać o coś dodatkowego.
- Kuking nie ma i nie miał dostępu do listy e-mail Garnka — nie ma tu
  ryzyka zimnego mailingu do cudzej bazy, ale warto zapisać wprost: taki
  mailing byłby czystym naruszeniem art. 398 PKE, gdyby ktokolwiek to
  kiedyś rozważał.

### 3.4 Czego NIE robić

| Pomysł | Dlaczego odpada |
|---|---|
| „Najlepsze przepisy tygodnia” wybrane automatycznie po liczbie polubień | Ranking pod inną nazwą — zakazany wprost `AGENTS.md` §12 i `docs/ROADMAP.md` („nie projektuj skomplikowanego rankingu bez danych”) |
| Sprzedaż albo udostępnianie listy adresów mailowych | Narusza politykę prywatności („nie sprzedajemy danych”) i fundament zaufania, na którym stoi cała ta grupa odbiorców |
| „Polecani użytkownicy” wybierani automatem | Explicite odrzucone już przy digeście (D-057): „każde automatyczne »warto poznać« jest rankingiem pod inną nazwą” |
| Przypominajki wysyłane w kółko osobom nieaktywnym | `RETENTION_LOOPS.md` §3.2 ma twardy limit: „nieaktywna >60 dni: maks. 1 e-mail miesięcznie, po 6 miesiącach — zero”. Newsletter wysyłany masowo do wszystkich zgadzających się złamie ten limit, jeśli nie wyklucza jawnie dawno nieaktywnych |
| Traktowanie open rate/CTR jako celu samego w sobie | Sprzeczne z jawnym stanowiskiem właściciela: „zarabianie/wzrost nie jest celem” (`docs/MONETIZATION.md`) |

### 3.5 Alternatywy tańsze w utrzymaniu

1. **Nic dodatkowego — polegać wyłącznie na #236.** Digest już jest
   budowany, już mieści się w darmowym budżecie EmailLabs, już ma poprawną
   podstawę prawną (zgoda), już jest zautomatyzowany. Robi dokładnie to, co
   właściciel chce od newslettera — przypomina o serwisie — bez
   dodatkowego kosztu redakcyjnego ani finansowego. To jest najsilniejsza
   rekomendacja tego dokumentu.
2. **„Temat tygodnia” w produkcie** (Pętla 4, `RETENTION_LOOPS.md`) —
   gospodarz publikuje pierwszy wpis na dany temat, społeczność dołącza,
   bez rankingu. To już jest zaplanowana redakcyjna rola właściciela — tyle
   że w samym serwisie, nie w mailu.
3. **Kolekcja redakcyjna na stronie** („Kolekcja tygodnia”, bez rankingu,
   ręcznie wybrana, bez metryk polubień) zamiast osobnego kanału
   pocztowego. To jest **dokładnie ten sam wysiłek redakcyjny** co
   newsletter (trzeba przejrzeć i wybrać), ale: (a) nie wymaga
   infrastruktury pocztowej ani nowej zgody marketingowej, bo to strona, nie
   e-mail; (b) nie ryzykuje reputacji domeny wysyłkowej; (c) może być
   linkowana **z już istniejącego digestu** („Zobacz kolekcję tygodnia”),
   łącząc korzyść bez podwajania kanału i bez łamania limitu „maks. 2
   maile tygodniowo”. Ten mechanizm jest już opisany w `RETENTION_LOOPS.md`
   Pętla 4 („Wasze pierogi — 23 dania od 14 osób”) — brakuje mu tylko
   wystawienia jako osobnej, przeglądalnej strony.
4. **Lekcja z Garnka i Durszlaka** (`docs/research/COMPETITIVE_LANDSCAPE.md`):
   oba padły na **utrzymaniu**, nie na braku treści — Durszlak „został
   zaniedbany”, nie „nie miał co pokazać”. Dokładanie jednoosobowemu
   zespołowi kolejnego stałego, cotygodniowego, ręcznego obowiązku
   zwiększa dokładnie to ryzyko, które pośrednio zabiło poprzednika.

---

## Źródła

**Prawo**
- [prawo.pl — Prawo komunikacji elektronicznej: Zgoda na działania marketingowe](https://www.prawo.pl/biznes/prawo-komunikacji-elektronicznej-zgoda-na-dzialania-marketingowe,534839.html)
- [prawo.pl — Zgody marketingowe i kontakty handlowe zgodne z nowym prawem komunikacji elektronicznej](https://www.prawo.pl/biznes/zgody-marketingowe-i-kontakty-handlowe-co-mowi-prawo-komunikacji-elektronicznej,530981.html)
- [outreachpilot.pl — Art. 398 PKE: zgoda na marketing i kary UKE](https://outreachpilot.pl/poradnik/art-398-pke)
- [infor.pl — Zgody marketingowe po 10 listopada 2024 r.](https://mojafirma.infor.pl/biznes/prawo/rodo-w-firmie/7518377,zgody-marketingowe-po-10-listopada-2024-r-co-zmienia-prawo-komunikacji-elektronicznej.html)
- `resources/legal/polityka-prywatnosci.md`, `resources/legal/regulamin.md`
- `docs/legal/COMPLIANCE.md` (tabela RODO, wiersz „Newsletter/e-mail transakcyjny”)
- `docs/DECISIONS.md` D-057, commit `1023b5b` na `claude/tygodniowy-digest` (treść digestu, zgoda, wypisanie)

**EmailLabs**
- [EmailLabs — integracja z Selly (rozdzielenie ruchu transakcyjnego i marketingowego)](https://www.selly.pl/baza-wiedzy/integracje/integracja-newslettera-emaillabs/)
- [emaillabs.io/cennik-v2](https://emaillabs.io/cennik-v2/) — sprawdzone 10.09.2026
- `docs/decyzje/POCZTA.md`, `docs/infra/POCZTA_URUCHOMIENIE.md`

**Marketingowo / produktowo**
- `AGENTS.md` §1, §12
- `docs/product/RETENTION_LOOPS.md` §1, §3.2, §4 (Pętla 4, Pętla 9)
- `docs/MONETIZATION.md`, `docs/research/MONETYZACJA.md` §2.3 (Substack)
- `docs/research/COMPETITIVE_LANDSCAPE.md` (Garnek.pl, Durszlak.pl)
- `docs/research/AUDIENCE_50_PLUS.md` (strach przed niechcianymi e-mailami)
- `docs/ROADMAP.md` (zakaz rankingu bez danych)

**Nieustalone / do weryfikacji przez prawnika lub support EmailLabs przed wdrożeniem**
- Aktualny status art. 10 ustawy o świadczeniu usług drogą elektroniczną (§1.2)
- Czy art. 398 PKE rzeczywiście nie przewiduje żadnego wyjątku „soft opt-in” (§1.2)
- Czy limit dobowy EmailLabs STARTUP jest wspólny czy osobny dla dwóch kont SMTP na jednym koncie głównym, i czy dostawca w ogóle pozwala jednemu podmiotowi na dwa darmowe konta (§2.1)
- Realna izolacja reputacji między subdomenami tej samej domeny nadrzędnej u polskich dostawców pocztowych (§2.2)
