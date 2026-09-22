# Decyzja 3 — forma prawna operatora Kuking.pl

Stan na **wrzesień 2026**. Właściciel w Polsce. Serwis: hosting treści użytkowników, na razie bez przychodów,
docelowo możliwa subskrypcja.

> **PRZECZYTAJ NAJPIERW: decyzja zapadła i ten dokument jej nie zmienia.**
> Serwis prowadzi **SAMSUFI sp. z o.o.**, ul. Jagiellońska 4A, 19-120 Knyszyn,
> KRS 0000901262 — decyzja **D-040** z 8 września 2026, dane w `config/kuking.php`.
> Całe poniższe porównanie form powstało PRZED tym rozstrzygnięciem i kończy się
> rekomendacją „alfa jako osoba fizyczna, beta jako JDG, spółka to na razie strata
> pieniędzy". **Ta rekomendacja jest nieaktualna.** Zostaje w repozytorium, bo
> rozpisuje realne różnice między formami i przyda się, gdyby kiedyś do tematu
> wracać — ale §5 i „Rekomendację" czytaj jak zapis rozważań z września 2026,
> nie jak polecenie do wykonania. **Co z tego wynika dziś dla DSA — §3.**

> **Ten dokument nie jest opinią prawną.** Wszystko, czego nie potwierdziłem źródłem, jest oznaczone
> `[do weryfikacji z prawnikiem]`. Obowiązki DSA i RODO co do treści są rozpisane w `docs/legal/COMPLIANCE.md` —
> tutaj interesuje nas wyłącznie **to, co zmienia forma prawna**.

---

## 0. Punkt wyjścia: co się **nie** zmienia niezależnie od formy

To jest najważniejsze ustalenie i warto je postawić na początku, bo obala częstą intuicję
„założę spółkę, to będę bezpieczny".

| Obowiązek | Czy zależy od formy prawnej? |
|---|---|
| **RODO — bycie administratorem** | **NIE.** Administratorem jest ten, kto ustala cele i sposoby przetwarzania. Osoba fizyczna prowadząca portal społecznościowy jest administratorem tak samo jak spółka. Wyłączenie „działalności czysto osobistej lub domowej" (art. 2 ust. 2 lit. c RODO) **nie obejmuje** publicznego serwisu z rejestracją i profilami. |
| **RODO — kary** | **NIE.** UODO może ukarać osobę fizyczną. Górny pułap (20 mln EUR / 4% obrotu) jest teoretyczny przy takiej skali, ale ekspozycja istnieje. |
| **DSA — art. 11, 12, 14, 16, 17, 18** (punkty kontaktowe, regulamin, notice-and-action, uzasadnienie decyzji, zgłaszanie przestępstw) | **NIE.** Sekcje 1 i 2 DSA nie mają zwolnienia dla małych podmiotów. |
| **Wyłączenie odpowiedzialności za cudze treści** (safe harbour, art. 6 DSA / dawny art. 14 UŚUDE) | **NIE.** Chroni *dostawcę usługi hostingu*, nie *spółkę*. Osoba fizyczna korzysta z niego tak samo, o ile nie ma wiedzy o nielegalnej treści i usuwa ją niezwłocznie po zgłoszeniu. |
| **UŚUDE art. 5 — obowiązek podania danych usługodawcy** | **NIE, ale ZAKRES DANYCH — TAK.** Patrz §4. To jest najkonkretniejsza różnica w całym dokumencie. |
| **Prawa konsumenta** (odstąpienie, reklamacje) — od momentu pierwszej subskrypcji | **NIE.** Nawet działalność nierejestrowana jest traktowana jak przedsiębiorca w relacjach z konsumentami. |

**Wniosek:** spółka nie zdejmuje ani jednego obowiązku compliance. Zmienia **wyłącznie to, czyim majątkiem
odpowiadasz, gdy coś pójdzie źle**, oraz ile Cię to kosztuje miesięcznie.

---

## 1. Cztery formy — tabela

| | **Osoba fizyczna bez działalności** | **Działalność nierejestrowana** | **JDG** | **Sp. z o.o.** |
|---|---|---|---|---|
| **Czy wolno tak prowadzić taki serwis?** | **TAK, o ile nie jest „zarobkowa"** — art. 3 Prawa przedsiębiorców definiuje działalność gospodarczą jako *zorganizowaną działalność **zarobkową**, wykonywaną we własnym imieniu i w sposób ciągły*. Bezpłatna beta bez reklam i bez płatnych funkcji nie spełnia przesłanki zarobkowej. `[do weryfikacji z prawnikiem — „zarobkowy" ocenia się przez pryzmat zamiaru i obiektywnej zdolności do zysku, nie faktycznego zysku; udokumentowany plan monetyzacji może być argumentem, że działalność jest zarobkowa od początku]` | **TAK** — to jest forma stworzona dokładnie na ten moment przejścia | **TAK** | **TAK** |
| **Limit przychodu** | **0 zł.** Każdy przychód = koniec tej formy | **10 813,50 zł / kwartał** (225% minimalnego wynagrodzenia 4 806 zł). **Uwaga: od 2026 limit jest KWARTALNY, nie miesięczny** — to zmiana z tego roku. Warunek: brak działalności gospodarczej przez ostatnie **60 miesięcy**. Przekroczenie → **7 dni na rejestrację w CEIDG** | brak | brak |
| **Koszt założenia** | **0 zł** | **0 zł** | **0 zł** (CEIDG online) | **S24: 250 zł opłata sądowa + 100 zł MSiG + PCC 0,5% od kapitału (~23 zł) ≈ 373 zł** + kapitał zakładowy **min. 5 000 zł** (zostaje w spółce). Akt notarialny zamiast S24: 500 zł opłaty + taksa notarialna |
| **Koszt prowadzenia / mies.** | **0 zł** | **0 zł** (uproszczona ewidencja sprzedaży, PIT-36 raz w roku) | **Ulga na start (6 mies.):** tylko zdrowotna, min. **432,54 zł**. **Preferencyjny ZUS (kolejne 24 mies.):** **456,18 zł** (ze składką chorobową) + zdrowotna. **Pełny ZUS:** **1 926,77 zł** społeczne + min. **432,54 zł** zdrowotna ≈ **2 359,31 zł/mies.** Księgowość (KPiR): 0–300 zł | **Pełna księgowość obowiązkowa: 349–1 500+ zł/mies.** (średnio 500–1 500 zł). Sprawozdanie finansowe do KRS co roku. **Pułapka: wspólnik jednoosobowej sp. z o.o. jest dla ZUS traktowany jak osoba prowadząca działalność → płaci pełny ZUS** (art. 8 ust. 6 pkt 4 ustawy o systemie ubezpieczeń społecznych) `[do weryfikacji z prawnikiem]` |
| **Podatek od przychodów z subskrypcji** | n/d | PIT wg skali, „inne źródła", **liczy się kasowo** (dopiero wpłata) | PIT: skala / liniowy 19% / ryczałt | **CIT 9%** dla małego podatnika i w pierwszym roku (limit 2 mln EUR przychodu), potem 19%. **Podwójne opodatkowanie przy wypłacie dywidendy (+19% PIT)** — chyba że estoński CIT |
| **VAT** | n/d | zwolnienie podmiotowe do **240 000 zł** (limit podniesiony z 200 000 zł od 1.01.2026) | jw. | jw. |
| **Odpowiedzialność osobista za treści użytkowników** | **całym majątkiem osobistym** — mieszkanie, oszczędności, wynagrodzenie | **całym majątkiem osobistym** | **całym majątkiem osobistym** (+ majątek wspólny małżeński, jeśli brak rozdzielności) | **majątkiem spółki**; członek zarządu odpowiada **subsydiarnie** (art. 299 KSH) dopiero, gdy egzekucja przeciw spółce jest bezskuteczna i nie zajdzie przesłanka egzoneracyjna |
| **Obowiązki RODO** | pełne (administrator) | pełne | pełne | pełne |
| **Obowiązki DSA** | pełne z Sekcji 1 i 2 — **ale patrz §3, jest tu realny problem** | pełne z Sekcji 1 i 2; zwolnienie z Sekcji 3 jako mikroprzedsiębiorstwo | jw. | jw. |
| **Dane publikowane wg UŚUDE art. 5** | **imię, nazwisko, miejsce zamieszkania i adres** | jw. | **nazwa firmy + adres** — może być **adres wirtualnego biura** | **firma spółki + siedziba + KRS + NIP + kapitał** — adres prywatny nigdzie się nie pojawia |

---

## 2. Moment, w którym trzeba przejść wyżej

| Zdarzenie | Wymagana forma |
|---|---|
| Publiczna beta, 0 zł przychodu, zero reklam, zero płatnych funkcji | osoba fizyczna wystarcza *(z zastrzeżeniami z §3 i §4)* |
| **Pierwsza dobrowolna wpłata / „postaw kawę"** | `[do weryfikacji z prawnikiem]` — darowizna prawdopodobnie nie czyni działalności zarobkową, ale regularne wpłaty powiązane z korzystaniem z usługi mogą być uznane za przychód z działalności |
| **Pierwsza reklama, afiliacja, płatne wyróżnienie** | **działalność nierejestrowana** (do 10 813,50 zł/kwartał) |
| **Pierwsza subskrypcja** | **działalność nierejestrowana**, o ile mieścisz się w limicie; wyższe — **JDG** |
| Przekroczenie 10 813,50 zł w kwartale | **JDG w ciągu 7 dni** |
| Subskrypcje sprzedawane konsumentom w innych krajach UE powyżej progu 10 000 EUR rocznie | JDG + rejestracja **VAT OSS** `[do weryfikacji]` |
| Wspólnik / inwestor / podział udziałów | **sp. z o.o.** |
| Realne ryzyko roszczeń (pierwszy pozew o naruszenie dóbr osobistych, pierwsze poważne zgłoszenie z zagranicy) | **sp. z o.o.** |
| Przychód, przy którym pełny ZUS w JDG (≈2 360 zł/mies.) przestaje być tańszy od pełnej księgowości sp. z o.o. (≈500–1 500 zł/mies.) | policz w tym momencie, nie wcześniej |

---

## 3. Kluczowe pytanie: czy da się uruchomić publiczną betę jako osoba fizyczna bez działalności?

### Odpowiedź wprost

**Tak — pod trzema warunkami łącznie, i z jednym ryzykiem, którego nie da się usunąć.**

**Warunki:**

1. **Zero przychodu w jakiejkolwiek postaci** — brak reklam, brak afiliacji, brak płatnych funkcji,
   brak sponsoringu, brak „postaw kawę". Pierwsza złotówka kończy tę formę.
2. **Kompletny zestaw DSA/RODO od pierwszego dnia** — regulamin (art. 14 DSA), dwa punkty kontaktowe
   (art. 11 i 12), działający formularz „Zgłoś" (art. 16), uzasadnienia decyzji moderacyjnych (art. 17),
   polityka prywatności, rejestr czynności przetwarzania, umowy powierzenia z Railway / Cloudflare /
   dostawcą poczty / Sentry / PostHog. **To jest to samo, co musiałaby zrobić spółka.**
3. **Świadoma zgoda na ujawnienie danych osobowych** — patrz §4.

**Ryzyko, którego nie da się usunąć: odpowiadasz całym majątkiem osobistym.**

### Jak duże jest to ryzyko naprawdę

Nie „katastrofalne", ale i nie zerowe. Konkretnie:

| Scenariusz | Prawdopodobieństwo | Ekspozycja |
|---|---|---|
| Zgłoszenie treści → usuwasz w 24 h → koniec sprawy | wysokie, rutyna | 0 zł, safe harbour działa |
| Ktoś wrzuca cudze zdjęcie przepisu, właściciel praw pisze wezwanie | średnie | 0 zł, jeśli usuniesz niezwłocznie; safe harbour |
| **Użytkownik obraża innego, poszkodowany pozywa Ciebie zamiast autora** | niskie, ale to **najczęstszy realny scenariusz w polskich sprawach o dobra osobiste** — pozywa się tego, kogo łatwiej znaleźć, a Ty masz adres w stopce | koszty procesu (kilka–kilkanaście tys. zł) nawet przy wygranej; przy przegranej zadośćuczynienie |
| **Naruszenie ochrony danych** (wyciek bazy z e-mailami 10 000 osób) | niskie przy poprawnym baseline | kara UODO + roszczenia z art. 82 RODO od poszkodowanych — **to jest scenariusz, który realnie może zjeść majątek osobisty** |
| Kara DSA | bardzo niskie przy tej skali | koordynatorem jest **Prezes UKE** (od 15.05 tymczasowo uchwałą RM, docelowo ustawowo); ustawa wdrażająca DSA przeszła Sejm i Senat `[do weryfikacji — czy weszła w życie i w jakim kształcie]` |

### Paradoks DSA, o którym trzeba wiedzieć

**Art. 19 DSA:** *„This Section, with the exception of Article 24(3) thereof, shall not apply to providers of
online platforms that qualify as **micro or small enterprises** as defined in Recommendation 2003/361/EC."*

„Enterprise" w rozumieniu Zalecenia 2003/361/WE to **podmiot prowadzący działalność gospodarczą**.
Osoba fizyczna prowadząca hobbystyczny, niezarobkowy serwis **prawdopodobnie nie jest przedsiębiorstwem** —
a więc **prawdopodobnie nie może powołać się na zwolnienie z art. 19** i formalnie podlegałaby Sekcji 3
(wewnętrzny system rozpatrywania skarg — art. 20, pozasądowe rozstrzyganie sporów — art. 21,
zaufani sygnaliści — art. 22, sprawozdawczość — art. 24).

To jest **odwrotność intuicji**: forma najbardziej „amatorska" może być pod DSA **bardziej** obciążona
niż JDG, bo JDG jednoznacznie jest mikroprzedsiębiorstwem i zwolnienie z art. 19 stosuje wprost.

`[do weryfikacji z prawnikiem — to jest luka interpretacyjna, nie ustalony stan prawny. Kontrargument:
DSA definiuje „dostawcę usług pośrednich" bez wymogu zarobkowości, a motyw 57 mówi o celu „promowania
innowacji i inwestycji", co przemawia za wykładnią celowościową obejmującą także podmioty niekomercyjne.
To jest dokładnie ten rodzaj pytania, na które trzeba wydać jedną godzinę u prawnika.]`

`docs/legal/COMPLIANCE.md` §1.2 zakłada, że Kuking **jest** zwolniony jako mikro/małe przedsiębiorstwo.
**To założenie jest prawdziwe dla JDG i sp. z o.o., a wątpliwe dla osoby fizycznej bez działalności.**

> **ROZSTRZYGNIĘTE — D-040, 8 września 2026.** Paradoks opisany wyżej dotyczył wyłącznie wariantu
> „osoba fizyczna bez działalności". Ten wariant odpadł: serwis prowadzi **SAMSUFI sp. z o.o.**
> Spółka z ograniczoną odpowiedzialnością jest przedsiębiorstwem w rozumieniu Zalecenia 2003/361/WE
> bez żadnej wykładni, więc **zwolnienie z art. 19 stosuje się wprost**, dopóki spółka mieści się
> w progach. Z trzech pytań do prawnika z „Rekomendacji" niżej **dwa są bezprzedmiotowe**: „czy
> niezarobkowy serwis jest przedsiębiorstwem" (spółka jest) i „od kiedy działalność staje się
> zarobkowa" (nie dotyczy spółki). Art. 5 UŚUDE spółka spełnia siedzibą z KRS, więc adres domowy
> z §4 też przestał być problemem.
>
> **Czego to NIE zdejmuje — dwa warunki, których nie sprawdzę z repozytorium:**
> 1. **Progi liczy się dla całego przedsiębiorstwa, nie dla serwisu.** Jeśli SAMSUFI ma
>    przedsiębiorstwa partnerskie lub powiązane, ich zatrudnienie i obrót dolicza się do progu
>    (Zalecenie 2003/361/WE art. 6). To wie właściciel, nie kod.
> 2. **Statusu nie traci się z dnia na dzień.** Przekroczenie progu w jednym roku obrotowym nic
>    nie zmienia — dopiero w dwóch kolejnych (art. 4 ust. 2 Zalecenia). Jest więc czas na
>    przygotowanie Sekcji 3, ale nie jest go nieskończenie wiele.

---

## 4. Rzecz, o której nikt nie myśli, a która decyduje: Twój adres domowy w stopce

**Art. 5 ustawy o świadczeniu usług drogą elektroniczną** wymaga, by usługodawca podał
w sposób *„wyraźny, jednoznaczny i bezpośrednio dostępny"*:

> 1) adresy elektroniczne;
> 2) **imię, nazwisko, miejsce zamieszkania i adres** albo nazwę lub firmę oraz siedzibę i adres.

| Forma | Co realnie ląduje w stopce i regulaminie |
|---|---|
| Osoba fizyczna bez działalności | **Jan Kowalski, ul. Kwiatowa 5/12, 00-001 Warszawa** |
| Działalność nierejestrowana | jw. — to nadal osoba fizyczna |
| JDG | nazwa firmy + adres — **może być adresem wirtualnego biura / biura coworkingowego** (~50–150 zł/mies.) |
| Sp. z o.o. | firma + siedziba + KRS + NIP — **adres prywatny nie pojawia się nigdzie** |

**Dlaczego to jest ważne akurat tutaj:** Kuking to serwis społecznościowy z moderacją.
Moderujesz — czyli komuś odmawiasz, coś komuś usuwasz. W polskich serwisach społecznościowych
osoba, której usunięto wpis, regularnie eskaluje. **Adres domowy w regulaminie zamienia spór o przepis na
rosół w wizytę.** Dla grupy 50+, gdzie sporo osób traktuje sprawę osobiście, to nie jest teoria.

`[do weryfikacji z prawnikiem — czy „adres do doręczeń" inny niż zamieszkania spełnia wymóg art. 5 UŚUDE.
Doktryna nie jest tu jednolita, a to jest pytanie, które realnie może zdecydować o wyborze formy.]`

**To jest najsilniejszy pojedynczy argument za JDG zamiast osoby fizycznej — i kosztuje 0 zł przez pierwsze 6 miesięcy
(ulga na start = tylko składka zdrowotna 432,54 zł/mies., a i ona jest odliczalna).**

---

## 5. Ścieżka, którą bym przeszedł *(nieaktualne od D-040 — patrz ramka na górze)*

| Etap | Forma | Koszt / mies. |
|---|---|---|
| **Alfa zamknięta** (~50 osób, na zaproszenia, nie „publicznie dostępna usługa") | osoba fizyczna | 0 zł |
| **Publiczna beta** | **JDG z ulgą na start** — 6 miesięcy tylko składka zdrowotna | **432,54 zł** |
| **Beta trwa dalej** | JDG na preferencyjnym ZUS — kolejne 24 mies. | **~890 zł** (456,18 + 432,54) |
| **Pierwsza subskrypcja** | JDG (VAT zwolniony do 240 000 zł) | jw. |
| **Pierwszy wspólnik / inwestor / pierwszy pozew** | sp. z o.o. | ~500–1 500 zł księgowość |

Ulga na start + preferencyjny ZUS to razem **30 miesięcy** obniżonych składek. Publiczna beta zmieści się w tym oknie
w całości. Cena „bycia legalnie przedsiębiorcą" na starcie to więc **432,54 zł miesięcznie** — mniej niż
wirtualne biuro plus jedna godzina prawnika, a kupuje: adres firmy zamiast domowego, jednoznaczny status
mikroprzedsiębiorstwa pod art. 19 DSA, możliwość wystawienia faktury w dniu, w którym pojawi się przychód,
i koniec z pytaniem „czy to już jest działalność gospodarcza".

---

## Rekomendacja *(z września 2026, sprzed D-040 — nieaktualna)*

**Alfę zamkniętą (~50 osób z zaproszenia) prowadź jako osoba fizyczna — to jest legalne i kosztuje 0 zł — ale publicznej bety nie otwieraj bez JDG.** Powód nie jest podatkowy, tylko dwojaki: **art. 5 UŚUDE każe osobie fizycznej opublikować imię, nazwisko i adres zamieszkania**, a serwis, w którym moderujesz cudze treści, to zły moment na podanie adresu domowego zmoderowanym użytkownikom; do tego **zwolnienie z art. 19 DSA jest przypisane „mikro- lub małemu przedsiębiorstwu"**, więc podmiot bez działalności może paradoksalnie podlegać *większej* liczbie obowiązków DSA niż JDG. **JDG z ulgą na start kosztuje 432,54 zł miesięcznie przez pierwsze 6 miesięcy** i rozwiązuje oba problemy naraz, a razem z preferencyjnym ZUS daje 30 miesięcy taniego okna — dokładnie tyle, ile potrwa beta. **Sp. z o.o. na tym etapie to strata pieniędzy** (pełna księgowość 500–1 500 zł/mies., pełny ZUS i tak przy jednym wspólniku, podwójne opodatkowanie): wraca do gry przy pierwszym wspólniku, inwestorze albo pierwszym realnym pozwie. **Zanim otworzysz betę, kup jedną godzinę u prawnika od e-commerce i zadaj dokładnie trzy pytania**: czy adres do doręczeń zastępuje adres zamieszkania w art. 5 UŚUDE, czy niezarobkowy serwis jest „przedsiębiorstwem" w rozumieniu art. 19 DSA, i od którego momentu Twoja działalność staje się „zarobkowa" w rozumieniu art. 3 Prawa przedsiębiorców.

---

## Źródła

- [Biznes.gov.pl — Działalność nierejestrowana (limit kwartalny 10 813,50 zł, 60 miesięcy, 7 dni na rejestrację)](https://www.biznes.gov.pl/pl/portal/00115)
- [GazetaPrawna — Działalność nierejestrowana 2026: nowy limit przychodów (przejście z limitu miesięcznego na kwartalny)](https://www.gazetaprawna.pl/firma/artykuly/11187531,dzialalnoscnierejestrowana2026nowylimitprzychodow.html)
- [PUP Sosnowiec — Działalność nierejestrowana, nowe zasady od 2026 roku](https://sosnowiec.praca.gov.pl/strona-glowna/-/asset_publisher/Qat7ebECUfDp/content/dzialalnosc-nierejestrowana-nowe-zasady-od-2026-roku-)
- [ZUS — Składki przedsiębiorców w 2026 roku (PDF)](https://www.zus.pl/documents/10182/13364587/Sk%C5%82adki+przedsi%C4%99biorc%C3%B3w+w+2026+roku_DFF.pdf)
- [ZUS — Minimalna składka zdrowotna w 2026 r.](https://www.zus.pl/en/-/przedsi%C4%99biorcy-opodatkowani-na-zasadach-og%C3%B3lnych-lub-w-formie-karty-podatkowej.-minimalna-sk%C5%82adka-na-ubezpieczenie-zdrowotne-w-2026-r.)
- [Poradnik Przedsiębiorcy — Preferencyjne składki ZUS w 2026 roku (podstawa 1 441,80 zł, 456,18 zł)](https://poradnikprzedsiebiorcy.pl/-wskazniki-preferencyjne-skladki-zus)
- [Kancelaria Gatner — Koszty założenia spółki z o.o. 2026 (S24: 250 zł + 100 zł MSiG + PCC)](https://www.kancelaria-gatner.pl/artykuly/koszty-zalozenia-spolki-z-o-o)
- [wgtax — Ile kosztuje prowadzenie spółki z o.o. w 2026 roku](https://wgtax.pl/strefa-wiedzy/ile-kosztuje-prowadzenie-spolki-zoo/)
- [Akademia LTCA — Kto może płacić 9% CIT w 2026 r.](https://akademialtca.pl/blog/kto-moze-placic-9-proc-cit-w-2026-r-znamy-juz-limity)
- [TPA Poland — Od 2026 r. wyższy limit zwolnienia podmiotowego z VAT (240 000 zł)](https://www.tpa-group.pl/pl/news/od-2026-r-wyzszy-limit-zwolnienia-podmiotowego-z-vat-kto-skorzysta-i-co-sie-zmieni/)
- [LexLege — Ustawa o świadczeniu usług drogą elektroniczną, art. 5](https://lexlege.pl/ustawa-o-swiadczeniu-uslug-droga-elektroniczna/art-5/)
- [ArsLege — Art. 5 UŚUDE, zakres danych wymaganych od usługodawcy](https://arslege.pl/zakres-danych-wymaganych-od-uslugodawcy/k879/a62681/)
- [EUR-Lex — Rozporządzenie (UE) 2022/2065 (DSA), tekst pełny](https://eur-lex.europa.eu/legal-content/PL/TXT/HTML/?uri=CELEX:32022R2065)
- [CMS DigitalLaws — Art. 19 DSA, Exclusion for micro and small enterprises](https://www.cms-digitallaws.com/en/dsa/article-19/)
- [Bird & Bird — Digital Services Act już obowiązuje (Polska)](https://www.twobirds.com/pl/insights/2024/poland/240301-digital-services-act-akt-o-uslugach-cyfrowych-juz-obowiazuje)
- [UKE — Prezes UKE koordynatorem ds. usług cyfrowych](https://www.uke.gov.pl/akt/prezes-uke-koordynatorem-ds-uslug-cyfrowych,581.html)
- [Cyberdefence24 — Senat przyjął ustawę wdrażającą DSA](https://cyberdefence24.pl/polityka-i-prawo/polska/senat-przyjal-ustawe-wdrazajaca-dsa-pojawila-sie-wazna-poprawka)
