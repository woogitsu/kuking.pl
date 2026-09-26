# „Więcej / mniej takich treści” — research i propozycja decyzji (#1781)

Data: 25 września 2026. Punkt odniesienia: `origin/main` @ `9dddf0f01`.
Charakter: **research i materiał do decyzji właściciela**. Ten dokument nie
zmienia kodu aplikacji, `AGENTS.md` ani `docs/DECISIONS.md`. Nie zakłada issues.

> **Układ dokumentu.** Rozdział 1 (§0–§9) to pierwsza runda researchu.
> Właściciel go przeczytał i **nie wybrał żadnej opcji**. Zakres, zasadę
> „bez algorytmicznego feedu”, to, co można ukryć, i nazwy w menu kazał
> przeanalizować głębiej. Druga runda jest w **rozdziale 2** (§2.0–§2.6,
> na końcu pliku). Rekomendacje rozdziału 1 nie są decyzjami. Tam, gdzie
> rozdział 2 zmienia zdanie, mówi to wprost (§2.6.2).

Pomysł właściciela (25.09): przy wpisie dwa przyciski — „Chcę widzieć więcej
takich treści” i „Chcę widzieć mniej takich treści” — żeby Kuking wiedział,
co dana osoba lubi.

---

## 0. Wynik w pięciu zdaniach

1. **„Więcej takich” da się zrobić już w MVP bez żadnej nowej zasady i bez
   nowych danych:** w menu trzech kropek to skrót do tego, co już istnieje —
   „Obserwuj Annę” i „Obserwuj temat »Ciasta«”.
2. **„Mniej takich” warto zrobić w V1 (po bramce zamkniętej alfy) jako jawne,
   odwracalne wyciszenie autora albo tagu, i to wyłącznie w Odkrywaniu
   („Świeżo z Kuking”) i w automatycznej części tablicy „kuKINGi na dziś”.**
   Działa jak filtr, nie jak waga. Kolejność zostaje chronologiczna.
3. **Wagi per tag i uczenie się z zachowania odrzucamy.** Łamią sens zasady
   „bez algorytmicznego feedu”. Na starcie nie ma z czego się uczyć. Wymagają
   profilowania w rozumieniu RODO i zmieniają to, kto jest „widziany”.
4. Żeby V1 nie było furtką, proponuję decyzję **D-xxx** (§7). Doprecyzowuje
   ona zakaz: feed obserwowanych i feed tagów są zawsze czysto chronologiczne,
   a w Odkrywaniu wolno tylko **wykluczać** — jawnie, na prośbę widza,
   bez agregowania sygnałów między ludźmi.
5. Szacunek: „więcej” jako skrót to **0,5–1 dnia**. Pełne „mniej” (schemat,
   filtr, dwa ekrany, ustawienia, eksport, polityka) to **4–5,5 dnia**.
   Wagi: **+5–8 dni** i stałe strojenie. Uczenie: **tygodnie** — odrzucić.

---

## 1. Stan dzisiejszy — co już jest w kodzie i w zasadach

### 1.1 Zasady

- `AGENTS.md` §8: feed obserwowanych jest **chronologiczny**
  (`ORDER BY published_at DESC, id DESC`). „Nie projektuj skomplikowanego
  rankingu bez danych — algorytmiczny feed natychmiast dzieli użytkowników na
  »widzianych« i »niewidzianych« i wyłącza publikowanie u większości.”
- `AGENTS.md` §12: „algorytmiczny feed” jest na liście anty-wzorców, których
  „nie wprowadzamy nigdy”. Ta sama lista zakazuje publicznych rankingów
  i eksponowania liczników.
- `AGENTS.md` §5 i **D-172**: menu trzech kropek na karcie wpisu
  (`components/post-card.blade.php`, `<details class="post-card-menu">`) to
  **jedyny** nazwany wyjątek od reguły „ikona nigdy sama”. Pozycje **w** menu
  mają pełne napisy, a sam przycisk ma 48 × 48 px i `aria-label`.
  Nowe pozycje w menu nie rozszerzają wyjątku. Wyjątek dotyczy samego przycisku.
- **D-081**: jedyna liczba pod wpisem („ile osób zapisało”) „nigdzie nie
  sortuje, nie promuje i nie tworzy zestawień”. To precedens: sygnał może
  istnieć, jeśli ma nazwaną, wąską rolę i jawnie wymienione miejsca, w których
  go NIE ma.
- **D-087, D-123, D-124**: listy tagów i tablica dnia są „zero rankingu”.
  Kolejność jest redakcyjna, alfabetyczna albo chronologiczna.
- `docs/INSPIRATION_DECISIONS.md` 2.12: „Wyciszenie konta (»Ukryj wpisy tej
  osoby«) obok blokady” ma ocenę **ADAPT** („w małej społeczności blokada jest
  zbyt mocnym gestem społecznym”). **W kodzie tego nie ma.** Są tylko blokada
  i obserwowanie.
- `docs/FEATURES.md` (MVP → Wspomnienia): „pojedyncze wspomnienie chowa
  przycisk przy nim”. To precedens dla prostego „schowaj” przy konkretnej
  treści.

### 1.2 Strumienie treści (`app/Domain/Feed/*`)

| Strumień | Kto go widzi | Kolejność | Co dziś wyklucza |
|---|---|---|---|
| `FollowingFeed` | zalogowany, który kogoś obserwuje | chronologia | widoczność, blokady |
| `TagFeed` (D-021) | zalogowany z obserwowanymi tagami, gdy feed obserwowanych jest pusty | chronologia | widoczność wpisu i przepisu (D-193), nieaktywni autorzy |
| `DiscoverFeed` — „Świeżo z Kuking”, `/odkryj`, pusty Start, strona powitalna gościa | wszyscy | chronologia, **najwyżej jeden wpis na autora** w całej sekwencji (`DISTINCT ON`, #940) | blokady w obie strony, nieaktywne konta, widoczność przepisu |
| `DailyBoard` — „kuKINGi na dziś” | wszyscy | 1) wybór gospodarza, 2) automat: chronologia, jedna pozycja na osobę (D-123) | blokady, osoby już obserwowane (`wykluczeniOsob()`), kandydaci z cache 5 min odsiewani w PHP |

`FeedController::home()` wybiera **jedno** źródło: obserwowani → tagi →
odkrywanie (#859). Wniosek: „Świeżo z Kuking” to jedyne miejsce, gdzie
nieznajomy wpis trafia do widza bez jego wcześniejszej decyzji (obserwowania
osoby albo tagu). Tylko tam „mniej takich” ma sens i nie dotyka zasady
chronologicznego feedu obserwowanych.

Ważne dla wydajności: `DiscoverFeed` ma już punkt zaczepienia na wykluczenia
widza (`hiddenAuthorIdsFor()` → `whereNotIn('author_id', …)` **w podzapytaniu,
przed wyborem reprezentanta autora**). `DailyBoard` ma go również
(`wykluczeniOsob()` plus odsiew kandydatów z cache i powrót do pełnego zapytania,
gdy po odsianiu zabraknie pozycji). Wyciszenia pasują do obu mechanizmów bez
zmiany ich kształtu.

### 1.3 Dane i prawa osoby

- `tag_follows (user_id, tag_id, created_at)` jest ustawiane w
  `/ustawienia/tagi` i przez `POST /tag/{tag}/obserwuj`.
- **Nieaktualne od #953 (26 września 2026, issue #1815):** ten akapit
  twierdził, że eksport danych (`app/Domain/Users/Exports/CollectUserExportData.php`)
  **nie zawiera obserwowanych tagów**. Od #953 zawiera — klucz `obserwowane_tagi`
  (metoda `followedTags()`, czyta `tag_follows` przez `join` z `tags`) jest
  w eksporcie i pilnuje go `EksportObejmujeKazdaTabeleKontaTest`. P-6 niżej
  jest więc zrobione; zostaje tu jako zapis, że luka istniała i jak ją znaleziono
  — nie jako aktualny stan.
- `EraseAccountData` czyści `tag_follows`. Nowe tabele muszą tam dołączyć.
- `resources/legal/polityka-prywatnosci.md` nie ma dziś ani słowa
  o profilowaniu ani o tym, jak dobieramy treści. Nie było takiej potrzeby.
- `docs/legal/COMPLIANCE.md` zakładał (przed sprostowaniem z 25 września 2026,
  commit `88d7e85bc`, issue #1815): „feed jest chronologiczny (brak systemu
  rekomendacji w rozumieniu DSA na MVP)”. **To założenie było za wąskie** —
  patrz §4.1 niżej i `docs/legal/COMPLIANCE.md` §1.2a, który już to sprostowanie
  niesie.

---

## 2. Gdzie sygnały mogą działać bez łamania zasad

| Miejsce | Werdykt | Uzasadnienie |
|---|---|---|
| Feed obserwowanych | **NIE** | Rdzeń zasady §8. Kto chce mniej od osoby, którą obserwuje, ma „Przestań obserwować”. Ukryte filtrowanie obserwowanych tworzyłoby właśnie „niewidzianych” wśród znajomych. |
| Feed tagów | **NIE (na razie)** | Widz sam wybrał tag. Wyciszenie tagu, który się obserwuje, to sprzeczność — rozwiązanie w §5.3 (jak D-080: blokada i obserwowanie nie współistnieją). Wyciszenie *autora* w feedzie tagów to pytanie do właściciela (§9, pyt. 2). |
| „Świeżo z Kuking” / `/odkryj` | **TAK** | Treść od nieznajomych, bez decyzji widza. Tu „nie chcę tego widzieć” jest najbardziej zasadne. |
| „kuKINGi na dziś” — część automatyczna | **TAK** | Ten sam charakter co Odkrywanie. Wykluczenia już są odsiewane per widz. |
| „kuKINGi na dziś” — wybór gospodarza | **do decyzji** | Gospodarz wyróżnia świadomie. Rekomenduję, żeby wyciszenie **autora** działało też tu (to życzenie widza), a wyciszenie **tagu** — nie. |
| Propozycje osób („kogo obserwować”) | **TAK** (autor) | Wyciszony autor nie powinien wracać jako propozycja. |
| Wyszukiwarka, strona tagu, profil, wpis pod bezpośrednim linkiem | **NIE** | Widz sam o to pyta. Filtr po cichu ukrywałby wyniki i wyglądałby na błąd. |
| Strona powitalna gościa | nie dotyczy | Gość nie ma ustawień. |

---

## 3. Warianty — od najprostszego

### A. Jawna kontrola zamiast uczenia (**rekomendowany**)

- **„Chcę widzieć więcej takich”** otwiera krótki ekran z propozycjami, które
  już istnieją: „Obserwuj Annę Kowalską” oraz „Obserwuj temat »Ciasta«”
  (przy każdym tagu wpisu, którego widz jeszcze nie obserwuje).
  **Zero nowych danych, zero nowych zasad.** Wynik widać od razu na Starcie
  (feed obserwowanych albo tagów).
- **„Chcę widzieć mniej takich”** otwiera krótki ekran z wyborem:
  „Nie pokazuj mi wpisów Anny w »Świeżo z Kuking«” oraz „Nie pokazuj mi tematu
  »Ciasta« w »Świeżo z Kuking«”. Zapis trafia do dwóch małych tabel. Filtr
  działa **tylko** w miejscach z §2 oznaczonych „TAK”.
- Wszystko jest widoczne i odwracalne w `/ustawienia/odkrywanie`
  („Czego nie pokazujemy Ci w »Świeżo z Kuking«”) — lista z przyciskiem
  „Przywróć” przy każdej pozycji.
- Kolejność w Odkrywaniu **dalej jest czysta chronologia** z jednym wpisem na
  autora. Wyciszenie nie przesuwa nikogo w górę — wyłącznie usuwa z widoku
  jednego widza.
- **Sygnał nigdy nie wychodzi poza konto widza.** Nie sumujemy go, nie wpływa
  na to, co widzą inni, nie trafia do moderacji ani do autora. Autor się nie
  dowie. To zamyka ryzyko nadużycia (§6).

Koszt: patrz §8 — 4–5,5 dnia na całość, 0,5–1 dnia na samo „więcej”.

### B. Lekkie preferencje per tag (waga w Odkrywaniu, bez modelu)

„Więcej takich” podbija wagę tagu, „mniej” ją obniża. Odkrywanie sortuje po
`waga × świeżość` zamiast po samej dacie.

- To **jest** ranking. Wpisy z tagów o niskiej wadze nie znikają, tylko
  schodzą w dół — czyli dokładnie do strefy „niewidzianych”. Nowy autor
  z tagiem, którego nikt nie „podbił”, ląduje na dnie u wszystkich.
- Trzeba zaprojektować wzór, wygaszanie wag w czasie i kursorową paginację po
  wyliczanej wartości (dziś kursor idzie po `published_at, id`). Przy kilkuset
  wpisach dziennie dane na strojenie to szum. „Nie projektuj rankingu bez
  danych” obowiązuje wprost.
- Dla osoby 50+ skutek jest nieczytelny: „kliknęłam »mniej«, a to dalej jest,
  tylko niżej”. Facebook przyznaje, że jego „Pokaż mniej” tylko obniża ranking
  i to jest częsta skarga ([Popular Science][popsci]).
- RODO: to już jest profilowanie (przewidywanie zainteresowań) — §4.2.

Koszt: **+5–8 dni** ponad wariant A, a potem stałe strojenie.
**Rekomendacja: nie teraz.** Wrócić do tego najwcześniej po bramce V1, jeśli
dane pokażą, że wykluczenia nie wystarczają.

### C. Personalizacja z uczeniem (dla porównania)

Sygnały niejawne (co ktoś ogląda, jak długo, co pomija) plus model.

- Sprzeczne z §8/§12 AGENTS.md w samej istocie.
- Na starcie serwisu (bramka alfy: 20+ osób) nie ma danych. Model uczy się
  aktywności kilku najaktywniejszych osób i je wzmacnia.
- Wymaga zbierania nowych danych o zachowaniu (minimalizacja, art. 5 ust. 1
  lit. c RODO), informacji o profilowaniu, prawa sprzeciwu (art. 21)
  i prawdopodobnie oceny skutków (DPIA).
- Wymaga infrastruktury, której zasady zabraniają (osobny silnik, kolejki
  cech, Redis).

Koszt: **tygodnie** plus stała obsługa. **Rekomendacja: odrzucić.**

---

## 4. Prawo

> Ocena poniżej jest wynikiem researchu, nie opinią prawną. Miejsca
> oznaczone **[do weryfikacji prawnej]** warto dopisać do
> `docs/prawo/DO_WERYFIKACJI_PRAWNEJ.md`.

### 4.1 DSA (rozporządzenie 2022/2065)

> **Adnotacja, 26 września 2026 (issue #1815).** Zdanie niżej „Zdanie
> w `COMPLIANCE.md` («brak systemu rekomendacji») warto poprawić” zostało
> zrobione: commit `88d7e85bc` (25 września 2026) przepisał `:5` i `:48`
> oraz dodał §1.2a z analizą powierzchni („Świeżo z Kuking”, automatyczna
> część „kuKINGi na dziś”, wyszukiwarka) i notą o rozjeździe PL/EN w art. 27
> ust. 1. Rekomendacja z akapitu niżej („dopisać do regulaminu «Jak
> dobieramy wpisy»”) jest w realizacji: issue #1811, decyzja D-305, PR #1879
> (jeszcze niescalony do main w chwili tej adnotacji).

- **Definicja jest szeroka.** Art. 3 lit. s: system rekomendacji to „w pełni
  lub częściowo zautomatyzowany system wykorzystywany przez platformę
  internetową do sugerowania (…) określonych informacji lub nadawania im
  priorytetu”, także przez „określanie względnej kolejności lub eksponowania
  wyświetlanych informacji” ([tekst art. 3][dsa-3], [EUR-Lex][dsa-eurlex]).
  Wynika z tego, że **już dziś** „Świeżo z Kuking” (jeden wpis na autora)
  i automat tablicy dnia prawdopodobnie *są* systemami rekomendacji w tym
  rozumieniu, mimo że nie są „algorytmicznym feedem” w sensie AGENTS.md.
  Zdanie w `COMPLIANCE.md` („brak systemu rekomendacji”) warto poprawić.
- **Art. 27** (przejrzystość): platforma korzystająca z systemów rekomendacji
  określa w regulaminie „prostym i zrozumiałym językiem” główne parametry
  i opcje, którymi odbiorca może je zmieniać lub na nie wpływać. Gdy opcji
  jest kilka, musi dać łatwo dostępną funkcję wyboru i zmiany w dowolnym
  momencie ([tekst art. 27][dsa-27]).
- **Sprostowanie do treści issue.** Issue mówi, że art. 27 „obowiązuje każdą
  platformę”. Art. 27 stoi jednak w sekcji 3 rozdziału III, a **art. 19
  wyłącza tę sekcję wobec mikro- i małych przedsiębiorstw** (poza art. 24
  ust. 3), chyba że platformę wyznaczono jako bardzo dużą
  ([tekst art. 19][dsa-19]; tak samo `docs/legal/COMPLIANCE.md` pkt „Art. 27 —
  niewymagany”). Przy założeniu z `COMPLIANCE.md`, że operator jest mikro albo
  małym przedsiębiorcą, **art. 27 formalnie nas nie wiąże**
  **[do weryfikacji prawnej: status operatora i art. 19 ust. 2]**.
- **Rekomendacja mimo zwolnienia:** dopisać do regulaminu krótką sekcję
  „Jak dobieramy wpisy”. To pół strony tekstu, zgodne z podejściem
  `COMPLIANCE.md` do art. 25 („stosować się tak, jakby obowiązywało”).
  Treść: „Start pokazuje wpisy osób, które obserwujesz, od najnowszych.
  »Świeżo z Kuking« pokazuje najnowszy wpis każdej osoby, od najnowszych.
  »kuKINGi na dziś« to wybór gospodarza uzupełniany najnowszymi wpisami.
  Nie liczymy polubień ani popularności. Możesz ukryć w »Świeżo z Kuking«
  wybrane osoby i tematy — w Ustawieniach”. Wariant A spełnia art. 27 ust. 1
  z nawiązką: parametry są dwa, a opcja zmiany jest jawna.

### 4.2 RODO

- **Profilowanie** (art. 4 pkt 4) to zautomatyzowane przetwarzanie służące
  ocenie czynników osobowych, w szczególności do analizy lub prognozy
  preferencji i zainteresowań ([art. 22 i definicje — gdpr.pl][rodo-22]).
- **Wariant A — jawne wykluczenie** („nie pokazuj mi tagu X”) jest wykonaniem
  polecenia osoby, a nie *prognozą* jej zainteresowań. Nie wnioskujemy
  niczego, czego nam nie powiedziała. Moim zdaniem to **nie jest
  profilowanie**, tylko ustawienie konta. Podstawa: art. 6 ust. 1 lit. b
  (funkcja, z której osoba świadomie korzysta — ta sama podstawa, co w polityce
  dla profilu) **[do weryfikacji prawnej]**.
- **Wariant B i C** to profilowanie. Wymagają: informacji w polityce (art. 13
  ust. 1 lit. c, cel i podstawa), prawa sprzeciwu (art. 21 ust. 1 przy
  art. 6 ust. 1 lit. f) i testu równowagi. Przy C dochodzi realne pytanie
  o DPIA.
- **Art. 22** (decyzje wywołujące skutki prawne lub w podobny sposób istotnie
  wpływające) — kolejność przepisów w serwisie kulinarnym z reguły nie spełnia
  progu „podobnie istotnego wpływu” ([wytyczne WP251 / EROD][edpb-251],
  [PDF PL na stronie UODO][uodo-251]). Nie jest to więc przeszkoda dla żadnego
  wariantu, ale nie zwalnia z art. 13 i 21.
- **Minimalizacja** (art. 5 ust. 1 lit. c): przechowujemy tylko parę
  (widz, autor) lub (widz, tag) i datę. Nie zapisujemy „kliknięć »mniej«”,
  zdarzeń analitycznych z identyfikatorem ani wpisu, przy którym to się stało.
  Wpis nie jest potrzebny do działania filtra.
- **Prawa osoby:** wyciszenia trafiają do eksportu (art. 15/20), znikają
  z kontem (`EraseAccountData`), można je usunąć pojedynczo w Ustawieniach
  (art. 16/17 w praktyce). Retencja: do odwołania przez osobę albo usunięcia
  konta. Przy okazji dopisać obserwowane tagi do eksportu (§1.3).
- **Polityka prywatności:** jeden wiersz w tabeli §2 („Twoje ustawienia
  Odkrywania: kogo i jakie tematy ukryłaś w »Świeżo z Kuking« — wykonanie
  umowy — do usunięcia przez Ciebie”) plus zdanie, że **nie profilujemy**
  i nie ustalamy zainteresowań na podstawie zachowania.

---

## 5. UX 50+

### 5.1 Jak to robią inni

| Serwis | Mechanizm | Gdzie | Czy działa jak filtr | Uwagi dla 50+ |
|---|---|---|---|---|
| Facebook | „Pokaż więcej / Pokaż mniej”, od 11.03.2025 „Zainteresowany / Niezainteresowany”. Obniża ranking wpisu i podobnych „tymczasowo”, efekt trwa ok. 60 dni. Obok są „Wstrzymaj na 30 dni” i „Przestań obserwować”. | menu trzech kropek, czasem przycisk pod wpisem | **nie** — waga | Nasza grupa zna miejsce (trzy kropki). Skarga: „kliknęłam, a dalej widzę” ([Meta, 2022][meta-2022], [Popular Science][popsci]) |
| Instagram | „Nie interesuje mnie” / „Interesuje mnie” na polecanych; w Ustawieniach „Preferencje treści” i „Resetuj sugerowane treści” | menu trzech kropek plus ustawienia | nie — waga; reset nieodwracalny | Dobra praktyka: lista preferencji do przejrzenia. Zła: nieodwracalny reset ([Instagram — reset][ig-reset], [Meta, 2024][meta-2024]) |
| YouTube | „Nie interesuje mnie” (film) i „Nie polecaj kanału” (autor); „Powiedz nam dlaczego”; możliwość wyczyszczenia opinii | menu „Więcej” przy filmie | kanał — prawie filtr; film — waga | Badanie Mozilli: „Nie polecaj kanału” działa wyraźnie lepiej niż „Nie interesuje mnie” ([Pomoc YouTube][yt-help], [TechCrunch][tc-yt]). **Wniosek: wykluczenie autora jest zrozumiałe i skuteczne.** |
| Pinterest | „Pokaż mniej / więcej”; „Dostosuj stronę główną” — przełączniki tematów, tablic i obserwowanych | menu „…” plus ekran ustawień | przełącznik tematu — filtr | Ekran z listą tematów i przełącznikami to wzór dla naszych Ustawień ([Pinterest — więcej/mniej][pin-see], [Pinterest — dostosuj][pin-tune]) |
| Mastodon | chronologiczna oś czasu plus wyciszenie konta (także czasowe), ukrycie podań dalej, filtry słów i hashtagów | menu przy wpisie plus ustawienia | **tak** — filtr, bez rankingu | Najbliższy Kukingowi model: chronologia i jawne wykluczenia ([Mastodon docs][masto]) |
| Cookpad | sieć obserwowanych: nowe przepisy i cooksnapy obserwowanych; wyszukiwarka z „popularnymi” (więcej przy Premium) | — | brak „mniej takich” w materiałach publicznych | Rdzeń relacyjny jak u nas; popularność tylko w wyszukiwarce ([Cookpad — społeczność][cookpad-net], [Cookpad — zmiany w aplikacji][cookpad-app]) |
| Garnek.pl (†25.11.2024) | fotoblogi i fotofora; w dostępnych materiałach nie znalazłem mechanizmu preferencji | — | — | Serwis nie żyje, archiwum jest niepełne. Znaczenie dla Kukinga: wygrał niskim progiem publikacji, nie personalizacją (`docs/research/COMPETITIVE_LANDSCAPE.md` §1, [Archiveteam][garnek]) |

Wnioski dla 50+:

1. **Miejsce:** menu trzech kropek. Nasza grupa zna je z Facebooka (D-172).
   Nie przycisk pod każdym wpisem — pasek akcji pod wpisem należy do
   „Ugotowałem” i komentarzy, a „Ugotowałem” ma być ważniejsze od wszystkiego.
2. **Skutek musi być widoczny i przewidywalny.** Filtr („nie pokazujemy”)
   jest zrozumiały. Waga („pokazujemy rzadziej”) nie jest — to najczęstsza
   skarga na Facebooka.
3. **Autor jest lepszą jednostką niż „podobne treści”** (YouTube). Tag
   dokładamy, bo u nas jest jawny i ma nazwę.
4. **Lista w Ustawieniach z „Przywróć”** (Instagram, Pinterest) — nigdy
   nieodwracalny „reset”.

### 5.2 Proponowany przebieg (wariant A)

Menu trzech kropek na **cudzym** wpisie, w sekcji zwykłych akcji (nad „Zgłoś
ten wpis”, z dala od akcji destrukcyjnych), dostaje dwie pozycje z pełnym
napisem:

- „Chcę widzieć więcej takich wpisów”
- „Chcę widzieć mniej takich wpisów”

(Nazwy w słowach właściciela. Alternatywy są w pytaniach, §9.)

Każda prowadzi do **osobnej, krótkiej strony** (zwykły GET plus formularz POST,
działa bez JavaScriptu, przyciski ≥ 48 px, tekst ≥ 18 px), a nie do podmenu
ani okna nad treścią:

**„Mniej takich wpisów”**
> Co mamy ukryć w »Świeżo z Kuking«?
> ☐ Wpisy Anny Kowalskiej
> ☐ Temat »Ciasta«
> ☐ Temat »Drożdżowe«
>
> Wpisy osób, które obserwujesz, zobaczysz jak dotąd. Anna nie dostanie
> żadnej wiadomości. Możesz to cofnąć w Ustawieniach.
> [ Ukryj zaznaczone ]   Wróć

**„Więcej takich wpisów”**
> Chcesz widzieć więcej takich wpisów na Starcie?
> [ Obserwuj Annę Kowalską ]
> [ Obserwuj temat »Ciasta« ]
> Wróć

Po zapisie powrót do miejsca, z którego ktoś przyszedł, z komunikatem:
„Ukryliśmy wpisy Anny w »Świeżo z Kuking«. [Cofnij] · Zobacz wszystko, co
ukryłaś”. „Cofnij” to zwykły formularz POST.

Brak hover, swipe i long-press. Brak zaznaczeń domyślnych — nic nie dzieje
się „przy okazji”. Błąd (np. nic nie zaznaczono) po polsku przy grupie pól
i w podsumowaniu, zaznaczenia zachowane (wzorzec z `/ustawienia/tagi`, #855).

**Gdzie pokazywać te pozycje:** na każdym cudzym wpisie (także w feedzie
obserwowanych — tam „więcej” proponuje tagi, a „mniej” uczciwie mówi, że
działa tylko w »Świeżo z Kuking«, i obok proponuje „Przestań obserwować”).
Alternatywa: tylko w Odkrywaniu i na tablicy — prościej, ale menu byłoby różne
zależnie od strony, co dla 50+ jest mylące.

### 5.3 Stany brzegowe

- **Wyciszenie tagu, który się obserwuje** — sprzeczność. Strona „mniej”
  proponuje wtedy „Przestań obserwować temat »Ciasta«” zamiast wyciszenia.
  To analogia do D-080 (blokada i obserwowanie nie współistnieją).
- **Wyciszenie osoby, którą się obserwuje** — nie ma sensu (nie jest
  w Odkrywaniu). Proponujemy „Przestań obserwować”.
- **Odkrywanie po wyciszeniach puste** — pusty stan: „Ukryłaś wszystkie osoby
  i tematy, które się teraz pojawiają. [Zobacz, co ukryłaś]”. Nigdy pusty ekran
  bez wyjścia (AGENTS.md §8).
- **Blokada a wyciszenie** — blokada jest silniejsza i obejmuje wszystko.
  Wyciszenie jej nie zastępuje ani nie wymaga.
- **Limit:** np. 200 pozycji na konto (ograniczony rozmiar `NOT IN`,
  kosztu i nadużyć). Komunikat po polsku, co zrobić.
- **Tag scalony lub ukryty** (`MergeTags`) — wyciszenie przenosi się na tag
  docelowy tak jak `tag_follows`.

---

## 6. Ryzyka

| Ryzyko | Wariant A | Wariant B / C |
|---|---|---|
| **Bańka treści** | Małe: wykluczenie jest świadome, widoczne na liście i obejmuje tylko Odkrywanie. Obserwowani nietknięci. | Duże: niejawne zawężanie, którego osoba nie widzi. |
| **Zniechęcenie nowych autorów** („nikt mnie nie widzi”) | Małe: pojedynczy widz ukrywa autora tylko u siebie. Nic nie jest sumowane, więc autor nie traci zasięgu u innych. Kolejność się nie zmienia. | Realne: nowy autor startuje z wagą zero, schodzi w dół u wszystkich — dokładnie mechanizm z §8 AGENTS.md. |
| **Nadużycia** (masowe „mniej” wobec osoby) | Brak skutku poza kontami klikających: sygnały nie są agregowane, nie wpływają na moderację ani widoczność. **To trzeba zapisać jako twardą regułę w D-xxx**, bo pokusa „użyjmy tego do jakości” przyjdzie. | Realne: skoordynowane „mniej” obniża globalną wagę osoby. |
| **Wydajność** | Mała: jeden `whereNotIn(author_id)` i jeden `whereNotExists` po `post_tags` w podzapytaniu `DiscoverFeed`. W `DailyBoard` odsiew w PHP na kandydatach z cache (wzorzec istnieje) i powrót do pełnego zapytania, gdy zabraknie pozycji. Test liczby zapytań obowiązkowy (wzorzec `test_podniesienie_sufitu_nie_doklada_ani_jednego_zapytania`). | B: ranking przy każdej odsłonie, trudna paginacja kursorowa, cache per widz. C: osobna infrastruktura. |
| **Mały serwis** (zimny start) | Przy kilkudziesięciu autorach kilka wyciszeń potrafi mocno przerzedzić Odkrywanie. Dlatego „mniej” proponuję dopiero w V1. | — |
| **Rozmycie zasady** | Istnieje, jeśli zabraknie decyzji. D-xxx nazywa granicę jak D-081. | Zasada przestaje obowiązywać. |

---

## 7. Proponowana decyzja D-xxx

> Numer nada sesja główna przy przyjęciu, według bieżącej puli numerów.
> Tekst poniżej to **propozycja do akceptacji właściciela**, nie wpis do
> `docs/DECISIONS.md`.

**D-xxx · „Bez algorytmicznego feedu” — doprecyzowanie: w »Świeżo z Kuking«
wolno wykluczać, nie wolno układać**

1. **Feed obserwowanych i feed tagów są zawsze czysto chronologiczne.** Działają
   w nich wyłącznie bramki widoczności i blokady — nic więcej, żadnych
   preferencji.
2. **W »Świeżo z Kuking«, w automatycznej części »kuKINGów na dziś«
   i w propozycjach osób widz może jawnie wykluczyć autora albo tag.**
   Wykluczenie działa jak filtr: niczego nie przesuwa w górę ani w dół.
   Kolejność pozostaje chronologiczna, z jednym wpisem na autora.
3. **Sygnał należy wyłącznie do widza.** Nie sumujemy wykluczeń między
   osobami. Nie wpływają na to, co widzą inni, na moderację, na propozycje
   dla innych ani na żadną liczbę. Autor nie jest o nich informowany.
4. **Żadnego wnioskowania z zachowania.** Nie ustalamy zainteresowań
   z oglądania, klikania, czasu ani „Ugotowałem”. Każda waga, ranking albo
   model wymaga nowej decyzji właściciela i nadpisania tej.
5. **Jawność i odwracalność:** każde wykluczenie jest widoczne w Ustawieniach
   z przyciskiem „Przywróć”, trafia do eksportu i znika z kontem.
6. **„Więcej takich” to wyłącznie skrót do obserwowania** osoby albo tagu.
7. **Regulamin ma sekcję „Jak dobieramy wpisy”** (DSA art. 27 stosowany
   dobrowolnie mimo art. 19).

Zmiana w `AGENTS.md` po przyjęciu (propozycja): w §12 „algorytmiczny feed
(ranking, wagi, uczenie z zachowania — patrz D-xxx; jawne wykluczenia w
Odkrywaniu nie są rankingiem)”, a w §8 jedno zdanie odsyłające do D-xxx.

---

## 8. Proponowane issues (do założenia po decyzji — **nie założone**)

Kolejność wykonania: P-1 → (P-2 niezależnie) → P-3 → P-4 → P-5 → P-6.
P-2 można zrobić w MVP bez reszty.

### P-1 · Decyzja D-xxx, `AGENTS.md` i sekcja „Jak dobieramy wpisy” w regulaminie — 0,5 dnia
- [ ] Wpis D-xxx w dzienniku decyzji w brzmieniu zaakceptowanym przez właściciela.
- [ ] `AGENTS.md` §8/§12 odsyła do D-xxx. `CLAUDE.md` bez zmian treści (jest wskaźnikiem).
- [ ] Regulamin: sekcja o głównych parametrach dobierania wpisów (Start, Świeżo z Kuking, kuKINGi na dziś, wykluczenia).
- [ ] `docs/legal/COMPLIANCE.md`: poprawione zdanie o „braku systemu rekomendacji” (art. 3 lit. s) i pozycja w `docs/prawo/DO_WERYFIKACJI_PRAWNEJ.md` (art. 19, profilowanie).

### P-2 · „Chcę widzieć więcej takich wpisów” — skrót do obserwowania (MVP) — 0,5–1 dnia
- [ ] Pozycja w menu trzech kropek na cudzym wpisie. Pełny napis, cel ≥ 48 px, bez zmiany samego przycisku menu (D-172).
- [ ] Strona z przyciskami „Obserwuj {osoba}” i „Obserwuj temat »{tag}«” wyłącznie dla nieobserwowanych i aktywnych tagów. Istniejące trasy `social.follow` i `tags.follow` z tym samym throttlingiem.
- [ ] Wejście przez Policy (UUID w adresie to nie autoryzacja). Wpis niewidoczny dla widza daje 404, blokada w którąkolwiek stronę — brak propozycji osoby.
- [ ] Działa bez JavaScriptu. Test HTTP i test widoku (napisy, rozmiary).
- [ ] Brak nowych tabel i kolumn.

### P-3 · Schemat wyciszeń w Odkrywaniu — 1–1,5 dnia
- [ ] Migracja: `wyciszeni_autorzy (user_id, author_id, created_at)` PK `(user_id, author_id)`, `CHECK (user_id <> author_id)`, FK z `ON DELETE CASCADE`; `wyciszone_tagi (user_id, tag_id, created_at)` PK `(user_id, tag_id)`. Indeksy pod zapytania z P-4. Nazwy do uzgodnienia z konwencją `docs/DATABASE.md`.
- [ ] Akcje domenowe „Wycisz”/„Przywróć” (idempotentne, `insertOrIgnore` jak #857, jedna transakcja, limit na konto). Pola bez `$fillable` dla kluczy sterujących.
- [ ] Niezmiennik: nie da się wyciszyć tagu, który się obserwuje, ani osoby, którą się obserwuje (rewalidacja pod blokadą jak D-080).
- [ ] `MergeTags` przenosi wyciszenia na tag docelowy.
- [ ] `EraseAccountData` czyści obie tabele. `CollectUserExportData` eksportuje je (sekcja `ukryte_w_odkrywaniu`).
- [ ] `docs/DATABASE.md` i plan rollbacku: `down()` **odmawia**, gdy tabele mają wiersze (dane użytkowników — D-088, AGENTS.md §6).
- [ ] Testy na PostgreSQL, w tym test regresyjny niezmiennika.

### P-4 · Filtr w `DiscoverFeed`, `DailyBoard` i propozycjach osób — 1 dzień
- [ ] `DiscoverFeed`: wykluczenia **w podzapytaniu przed `DISTINCT ON`**, żeby wyciszony autor lub tag oddawał miejsce, a nie dziurę (ta sama reguła co bramki widoczności, #940).
- [ ] Wyciszony tag wyklucza wpis, który ma ten tag (także wśród kilku tagów).
- [ ] `DailyBoard`: automat odsiewa wyciszonych autorów i wpisy z wyciszonymi tagami. Wybór gospodarza odsiewa tylko autorów (albo według decyzji z §9, pyt. 2). Działa powrót do pełnego zapytania, gdy po odsianiu brakuje pozycji.
- [ ] `FollowingFeed`, `TagFeed`, wyszukiwarka, strona tagu, profil: **bez zmian** — test to potwierdza (kontrola ujemna).
- [ ] Liczba zapytań na Starcie i `/odkryj` nie rośnie z liczbą wyciszeń (test równości par).
- [ ] Pusty stan Odkrywania z odnośnikiem do Ustawień.

### P-5 · „Chcę widzieć mniej takich wpisów” i `/ustawienia/odkrywanie` — 1,5–2 dni
- [ ] Pozycja w menu trzech kropek plus strona wyboru (autor, tagi wpisu) z tekstem o skutku: tylko »Świeżo z Kuking«, autor się nie dowie, można cofnąć.
- [ ] Po zapisie powrót z komunikatem i przyciskiem „Cofnij” (POST). Działa bez JavaScriptu.
- [ ] `/ustawienia/odkrywanie`: lista ukrytych osób i tematów, „Przywróć” przy każdej pozycji, wejście z `/ustawienia`.
- [ ] Błędy po polsku przy grupie pól i w podsumowaniu. Zaznaczenia zachowane po błędzie. Idempotencja formularza (ADR idempotencji).
- [ ] Brak zaznaczeń domyślnych. Stany brzegowe z §5.3 mają testy.
- [ ] Checklista `kuking-ekran` (18 px, 48 px, 320 px, 200%).

### P-6 · Polityka prywatności i eksport obserwowanych tagów — 0,5 dnia
- [ ] Polityka §2: wiersz „Ustawienia Odkrywania” (dane, cel, podstawa art. 6 ust. 1 lit. b, retencja do usunięcia). Zdanie: „Nie ustalamy Twoich zainteresowań na podstawie tego, co oglądasz”.
- [ ] Eksport: dołożyć `obserwowane_tagi` (luka z #492, niezależna od tej funkcji) i poprawić `co_zawiera`.

**Suma:** P-2 = 0,5–1 dnia (MVP). P-1 + P-3…P-6 = **4–5,5 dnia** (V1).

---

## 9. Pytania do właściciela

1. **Zakres i czas.** (a) „Więcej” teraz jako skrót do obserwowania,
   „mniej” w V1 — *rekomendacja*; (b) oba teraz; (c) nic — zostajemy przy
   obserwowaniu i blokadzie.
2. **Gdzie działa „mniej”.** (a) Odkrywanie plus automat tablicy plus
   propozycje osób — *rekomendacja*; (b) jak (a) plus wybór gospodarza na
   tablicy; (c) jak (a) plus wyciszony autor znika też z feedu tagów.
3. **Co można ukryć.** (a) Osobę albo temat do wyboru — *rekomendacja*;
   (b) tylko osobę (jak YouTube „Nie polecaj kanału”); (c) tylko temat.
4. **Jak długo.** (a) Do odwołania — *rekomendacja*; (b) 60 dni jak na
   Facebooku; (c) do wyboru przy ukrywaniu.
5. **Nazwy w menu.** (a) „Chcę widzieć więcej / mniej takich wpisów” (Twoje
   słowa); (b) „Pokaż więcej / mniej takich”; (c) „Interesuje mnie / Nie
   interesuje mnie” (jak dziś Facebook i Instagram).
6. **Zasada.** (a) Przyjąć D-xxx — wykluczenia tak, wagi i uczenie nie —
   *rekomendacja*; (b) zostawić zasadę bez zmian i odrzucić „mniej”;
   (c) otworzyć też drogę do wag (wariant B) po bramce V1.
7. **Przejrzystość (DSA art. 27).** (a) Sekcja „Jak dobieramy wpisy”
   w regulaminie od razu, mimo zwolnienia z art. 19 — *rekomendacja*;
   (b) dopiero, gdy przestaniemy być małym przedsiębiorcą.
8. **Wyciszenie osoby, którą obserwuję** (jak Facebookowe „Wstrzymaj na 30
   dni”). (a) Nie — wystarczy „Przestań obserwować” — *rekomendacja*;
   (b) osobne issue, bo to dotyka feedu obserwowanych i wymaga osobnej decyzji.

---

## Źródła (rozdział 1)

Repozytorium (`origin/main` @ `9dddf0f01`): `AGENTS.md` §5, §8, §12;
`docs/DECISIONS.md` D-021, D-080, D-081, D-087, D-123, D-124, D-172, D-193;
`docs/INSPIRATION_DECISIONS.md` 2.12; `docs/FEATURES.md`; `docs/ROADMAP.md`;
`app/Domain/Feed/{DiscoverFeed,TagFeed,FollowingFeed,DailyBoard}.php`;
`app/Http/Controllers/FeedController.php`;
`resources/views/components/post-card.blade.php`;
`app/Domain/Users/Exports/CollectUserExportData.php`;
`resources/legal/polityka-prywatnosci.md`; `docs/legal/COMPLIANCE.md`;
`docs/research/COMPETITIVE_LANDSCAPE.md`.

Zewnętrzne (odczyt 25.09.2026):

[dsa-eurlex]: https://eur-lex.europa.eu/legal-content/PL/TXT/?uri=CELEX:32022R2065
[dsa-3]: https://www.eu-digital-services-act.com/Digital_Services_Act_Article_3.html
[dsa-19]: https://www.eu-digital-services-act.com/Digital_Services_Act_Article_19.html
[dsa-27]: https://www.eu-digital-services-act.com/Digital_Services_Act_Article_27.html
[rodo-22]: https://gdpr.pl/baza-wiedzy/akty-prawne/interaktywny-tekst-gdpr/artykul-22-zautomatyzowane-podejmowanie-decyzji-w-indywidualnych-przypadkach-w-tym-profilowanie
[edpb-251]: https://www.edpb.europa.eu/our-work-tools/our-documents/guidelines/automated-decision-making-and-profiling_en
[uodo-251]: https://www.uodo.gov.pl/data/filemanager_pl/908.pdf
[meta-2022]: https://about.fb.com/news/2022/10/new-ways-to-customize-your-facebook-feed/
[popsci]: https://www.popsci.com/technology/facebook-feed-show-less-show-more/
[ig-reset]: https://about.instagram.com/blog/announcements/reset-instagram-content-suggestions
[meta-2024]: https://about.fb.com/news/2024/11/introducing-recommendations-reset-instagram/
[yt-help]: https://support.google.com/youtube/answer/6342839?hl=pl
[tc-yt]: https://techcrunch.com/?p=2402640
[pin-see]: https://help.pinterest.com/en/article/hide-a-pin-on-your-home-feed
[pin-tune]: https://help.pinterest.com/en/article/tune-your-home-feed
[masto]: https://docs.joinmastodon.org/user/moderating/
[cookpad-net]: https://blog.cookpad.com/uk/build-your-community-on-cookpad-followers-and-following/
[cookpad-app]: https://blog.cookpad.com/us/exciting-changes-in-the-cookpad-app/
[garnek]: https://wiki.archiveteam.org/index.php/Garnek.pl

- DSA, pełny tekst: [EUR-Lex 2022/2065][dsa-eurlex]; art. 3: [art. 3][dsa-3]; art. 19: [art. 19][dsa-19]; art. 27: [art. 27][dsa-27]
- RODO art. 4 pkt 4, art. 22: [gdpr.pl][rodo-22]; wytyczne WP251: [EROD][edpb-251], [PDF PL — UODO][uodo-251]
- Facebook: [Meta, 10.2022][meta-2022], [Popular Science][popsci]
- Instagram: [reset sugerowanych treści][ig-reset], [Meta, 11.2024][meta-2024]
- YouTube: [Pomoc — zarządzanie rekomendacjami][yt-help], [TechCrunch o badaniu Mozilli][tc-yt]
- Pinterest: [więcej/mniej przypinek][pin-see], [dostosuj rekomendacje][pin-tune]
- Mastodon: [radzenie sobie z niechcianą treścią][masto]
- Cookpad: [obserwowani i obserwujący][cookpad-net], [zmiany w aplikacji][cookpad-app]
- Garnek.pl: [Archiveteam][garnek]

---

# Rozdział 2 — druga runda: zasada, zakres, co ukrywać, nazwy

Data: 25 września 2026. Punkt odniesienia: ta sama gałąź, kod `origin/main`
@ `9dddf0f01`. Charakter bez zmian: **research i materiał do decyzji**.
Ten rozdział nie zmienia kodu, `AGENTS.md` ani dziennika decyzji. Nie
zakłada issues. Nie wymaga budowania niczego przed decyzją.

Punkt wyjścia: właściciel nie wybrał żadnej z opcji z §9. Odpowiedział, że
cztery sprawy trzeba przemyśleć głębiej, zanim cokolwiek wybierze:

1. sama zasada „bez algorytmicznego feedu” (§2.1),
2. zakres i kolejność prac, czyli co zmierzyć przed decyzją (§2.2),
3. co w ogóle można ukryć (§2.3),
4. nazwy w menu (§2.4).

Na końcu jest macierz decyzji (§2.5) i lista zmian względem rozdziału 1
(§2.6).

---

## 2.0 Wynik drugiej rundy w dziesięciu zdaniach

1. Zakaz „algorytmicznego feedu” to w dokumentach **trzy różne argumenty
   sklejone w jedno zdanie**: sprawiedliwość widoczności („widziani
   i niewidziani”), przewidywalność dla osób 50+ oraz „nie ma jeszcze danych”.
   Dwa pierwsze są wartościami. Trzeci jest tymczasowy.
2. Dowody na argument o przewidywalności są mocne: tylko **38% osób 50+**
   rozumie, dlaczego Facebook pokazuje im dane wpisy (Pew, 2018).
3. Dowody na „ranking wyłącza publikowanie” są **pośrednie, ale spójne**:
   odpowiedź na pierwszy wpis podnosi szansę kolejnego z 44% do 56%
   (Joyce i Kraut, 2006), a sygnał popularności zwiększa nierówność
   i losowość sukcesu (MusicLab, 2006). Bezpośredniego eksperymentu
   „ranking kontra chronologia a liczba publikujących” nie znalazłem.
4. Są też **niewygodne dowody**. Chronologia na Facebooku i Instagramie
   skróciła czas w serwisie mniej więcej o połowę (Guess i in., *Science*
   2023), a przy dużej liczbie wpisów także w chronologii ludzie przegapiają
   większość treści (Instagram przed 2016 r.: 70% wpisów).
5. Wniosek: zakaz jest dobrze uzasadniony **dla Startu** (obserwowani
   i tagi) w każdej skali. Wynika to też z publicznej obietnicy na `/o-nas`.
   W **Odkrywaniu** to wybór zależny od skali, który warto nazwać precyzyjnie,
   zamiast trzymać go w słowie „nigdy”.
6. Proponuję definicję roboczą: **algorytmiczny feed to kolejność albo dobór
   zależne od reakcji innych ludzi (popularności) albo od przewidywania
   zainteresowań z zachowania widza**. Czas, jawne wybory widza, zasady
   równości („jeden wpis na osobę”) i decyzja gospodarza do tej definicji
   nie należą.
7. Z pięciu modeli na osi „chronologia → uczenie” rekomenduję **zostać przy
   czystej chronologii w alfie i niczego nie budować**. Najpierw trzeba
   zmierzyć i zapytać 20 osób. Jeśli potrzeba się potwierdzi, następnym
   krokiem są wykluczenia. Ranking po popularności i uczenie odrzucam.
8. Przed decyzją wystarczą: `kuking:raport`, `kuking:wac`, trzy zapytania
   tylko do odczytu (§2.2.2) i **15-minutowa rozmowa z każdą z 20 osób alfy**
   według scenariuszy z §2.2.3. Nie trzeba do tego kodu.
9. Z czterech rzeczy, które można ukryć, najlepiej wypadają **osoba**
   i **pojedynczy wpis**. Ukrywanie **tematu** ma dwa problemy, których
   rozdział 1 nie widział: tagi są niepełne, więc ukrycie jest nieszczelne,
   a tematy typu „wieprzowina” czy „bezglutenowe” mogą ujawniać religię albo
   zdrowie (art. 9 RODO). Ukrywanie **rodzaju** („przepisy mięsne”) odrzucam,
   bo nie ma czym go rozpoznać.
10. W nazwach wygrywa zasada „**nazwa mówi, co się stanie**”: konkretne
    etykiety („Obserwuj Annę”, „Nie pokazuj mi wpisów Anny”) zamiast
    ogólnych („mniej takich treści”). Słowo „treści” kłóci się z hasłem
    marki „Ludzie, nie treści”, a „Pokaż mniej” obiecuje wagę, której
    w filtrze nie ma. Trzy warianty warto sprawdzić testem przewidywania
    z §2.4.4.

---

## 2.1 Zakaz algorytmicznego feedu — od podstaw

### 2.1.1 Skąd się wziął

Pierwsze sformułowania pochodzą z pierwszego commitu repozytorium
(`9d7717c47`, 5 września 2026). Kolejne dokumenty je powtarzają albo zawężają.

| Źródło | Co mówi | Jaki argument |
|---|---|---|
| `docs/product/SOUL.md`, wiersz „Feed wyłącznie chronologiczny” | „Osoba z 4 obserwującymi ma taką samą szansę być zobaczona jak osoba z 4000. To warunek, żeby ktoś nie przestał publikować.” Ryzyko: „słabszy »wow« przy dużej skali → problem na 2028”. | **A** — sprawiedliwość widoczności; świadomie odłożony koszt skali |
| `SOUL.md`, anty-wzorce | „Osoba z małą siecią przestaje być widziana, więc przestaje publikować. Dla 50+ dodatkowo: nieprzewidywalność = utrata zaufania.” Zamiennik: „Chronologia + tematy + Discover kuratorski”. | **A** i **B** — przewidywalność |
| `SOUL.md`, „Brak globalnych rankingów” | „Ranking tworzy dwie klasy użytkowników i zabija publikowanie u 90%.” | **A** (liczba 90% to echo reguły 90-9-1, nie pomiar) |
| `docs/research/AUDIENCE_50_PLUS.md` §4 | „Feed chronologiczny jest właściwy, bo jest przewidywalny — »wchodzę i widzę, co było od rana«, a nie »algorytm mi coś pokazał«.” | **B** |
| `AGENTS.md` §8 | „Nie projektuj skomplikowanego rankingu **bez danych** — algorytmiczny feed natychmiast dzieli użytkowników na »widzianych« i »niewidzianych«.” | **C** — brak danych (warunkowy) i **A** |
| `AGENTS.md` §12 | „algorytmiczny feed” na liście rzeczy, których „nie wprowadzamy **nigdy**”. | bezwarunkowy |
| D-081 pkt 1 | Właściciel wspomniał o „algorytmie, żeby pokazywał ciekawe tematy”. Droga ma prowadzić „przez jawne tematy (#273), nie przez popularność”. | precedens: jawne tematy tak, popularność nie |
| D-194 | „Ranking zamienia dzielenie się jedzeniem w konkurs, a w konkursie przegrywa ten, kto gotuje zwyczajnie.” | **D** — hierarchia sygnałów, antykonkurs |
| `resources/views/pages/static/about.blade.php` (`/o-nas`) | Publicznie: „Wpisy osób, które obserwujesz, stoją w kolejności, w jakiej je dodały. Bez rankingu popularności.” | **obietnica wobec ludzi** |
| `resources/views/pages/home.blade.php` (feed tagów) | Komentarz: „feed, którego pochodzenia nie da się wytłumaczyć, wygląda jak algorytm, a tego tu nie ma i nie będzie”. Widoczny napis: „To wpisy z tagów, które obserwujesz”. | **B** — źródło zawsze nazwane |
| `docs/MAPA_REGUL_DOWODY.md` (R73) | Po przejrzeniu 177 sortowań: kryterium to nie „agregat czy kolumna”, tylko „**co jest liczone**”. `MAX(published_at)` (czas) jest dozwolone, `COUNT(obserwujących)` (popularność) nie. | techniczna definicja granicy |

**Co z tego wynika:**

- Zasada ma **dwie siły**: warunkową w §8 („bez danych”) i bezwarunkową
  w §12 („nigdy”). Dopóki danych nie ma, obie mówią to samo. Kiedy dane się
  pojawią, zaczną się rozjeżdżać. To pierwsza sprawa do rozstrzygnięcia
  (§2.5, pytanie 1).
- **Obietnica publiczna dotyczy tylko obserwowanych.** Strona `/o-nas` nie
  obiecuje niczego o Odkrywaniu. `AUDIENCE_50_PLUS.md` §13 („nie zmieniaj reguł
  wstecz”, lekcja Naszej Klasy) sprawia, że zmiana kolejności na Starcie
  kosztowałaby zaufanie niezależnie od tego, co pokażą dane.
- „Discover kuratorski” był w `SOUL.md` **od początku** pomyślany jako
  miejsce inne niż chronologia obserwowanych. Tablica „kuKINGi na dziś” (wybór
  gospodarza) i reguła „jeden wpis na autora” w „Świeżo z Kuking” (#940) to
  już dziś **zasady doboru inne niż czysty czas**. Nikt nie nazwał ich
  algorytmicznym feedem, bo nie liczą reakcji innych ludzi.

### 2.1.2 Jak rozumieć słowo „algorytmiczny” — propozycja definicji

W sensie informatycznym algorytmem jest każde `ORDER BY`. W sensie DSA
(art. 3 lit. s, §4.1) systemem rekomendacji jest nawet „jeden wpis na
autora”. Zakaz w `AGENTS.md` dotyczy czegoś węższego. Proponuję nazwać to
wprost, po kryterium z R73:

> **Algorytmiczny feed** to strumień wpisów, w którym **kolejność albo
> dobór** zależy od:
> (a) **reakcji innych ludzi** — polubień, „Ugotowałem”, zapisów,
> komentarzy, obserwujących, odsłon (popularność), albo
> (b) **przewidywania zainteresowań widza na podstawie jego zachowania** —
> co oglądał, jak długo, co pominął, co kliknął (profilowanie).
>
> **Nie są** algorytmicznym feedem: kolejność po czasie; zasady równości
> (jeden wpis na osobę); wybór gospodarza; wykonanie **jawnego** polecenia
> widza (obserwuj, ukryj, pokaż tylko ten temat); bramki widoczności i blokady.

Ta definicja nie przesądza żadnego modelu z §2.1.4. Daje tylko słowa, którymi
da się je opisać bez sporu o to, co jest „algorytmem”.

### 2.1.3 Dowody z badań i praktyki

Siła dowodu: **mocny** — eksperyment albo duża próba z opublikowaną metodą;
**średni** — duża obserwacja, przyczynowość niepewna; **słaby** — relacja
prasowa, deklaracja firmy, anegdota.

#### Argument A: ranking sprawia, że część ludzi przestaje publikować

| Dowód | Co pokazuje | Siła | Znaczenie dla Kukinga |
|---|---|---|---|
| Joyce i Kraut, *Predicting Continued Participation in Newsgroups* (2006), 2777 nowych osób w 6 grupach | 61% nowych dostało odpowiedź na pierwszy wpis. Ci, którzy ją dostali, wracali z wpisem częściej: **44% → 56%**. Ton i jakość odpowiedzi nie miały znaczenia — liczyło się, że **ktoś odpowiedział**. | średni (obserwacja) | Mechanizm „niewidzianych”: czego nikt nie zobaczy, na to nikt nie odpowie. Ranking, który spycha nowych, obniża szansę odpowiedzi. |
| Burke, Marlow, Lento, *Feed Me* (CHI 2009), ok. 140 tys. nowych osób na Facebooku | Najsilniejszy czynnik: nowa osoba **widzi, że znajomi publikują** (uczenie społeczne). U osób skłonnych do publikowania liczą się też **odzew** i **szerokość widowni**. | średni (logi) | Zasięg pierwszych wpisów ma znaczenie. Odkrywanie „jeden wpis na autora” już dziś go wyrównuje. |
| Salganik, Dodds, Watts, MusicLab (*Science* 2006), 14 341 osób | Pokazywanie, co wybrali inni, **zwiększyło nierówność i nieprzewidywalność** sukcesu. Jakość tłumaczyła wynik tylko częściowo. | **mocny** (eksperyment) | Każdy sygnał popularności w kolejności uruchamia efekt „bogaci się bogacą”. To najmocniejszy argument przeciw rankingowi po reakcjach. |
| Facebook: spadek „oryginalnego dzielenia się” o 21% (połowa 2014 – połowa 2015; *The Information* za Fortune) | Firma sama nazwała to problemem („context collapse”) i uruchomiła zespół naprawczy. | słaby (przeciek, wiele przyczyn) | Ilustracja, nie dowód: sieć optymalizowana pod uwagę traci osobiste wpisy. |
| Zeznanie Zuckerberga przed FTC (kwiecień 2025) | W 2025 r. **17%** wyświetleń na Facebooku i **7%** na Instagramie dotyczyło wpisów znajomych. Dzielenie się ze znajomymi „spada”. | średni (zeznanie pod przysięgą, bez metodologii) | Kierunek wyraźny: ranking pod czas w serwisie wypiera treści znajomych treściami „z odkrywania”. Kuking jest z definicji o znajomych i sąsiadach. |

**Uczciwie:** nie znalazłem eksperymentu, który bezpośrednio porównuje ranking
i chronologię pod kątem **liczby publikujących osób**. Zdanie z `AGENTS.md`
(„natychmiast… wyłącza publikowanie u większości”) jest wnioskiem z mechanizmu,
nie pomiarem. Ten mechanizm jest jednak dobrze udokumentowany: widoczność daje
odzew, a odzew daje powrót. Słowo „natychmiast” jest przesadą. Kierunek się
zgadza.

#### Argument B: przewidywalność i zaufanie osób 50+

| Dowód | Co pokazuje | Siła |
|---|---|---|
| Pew Research, 2018, 3413 użytkowników Facebooka w USA | 53% nie rozumie, jak działa feed. Wśród osób **50+ rozumie tylko 38%** (18–29 lat: 59%). Starsi rzadziej próbują cokolwiek zmieniać i czują mniej kontroli. | **mocny** (duża próba) |
| `AUDIENCE_50_PLUS.md` §3 i §6 | Jedna z pięciu głównych barier to brak zaufania. Najsilniejszy lęk dotyczy oszustwa. Rodzina jest przewodnikiem po technologii. | średni (badania krajowe, wtórne) |
| Instagram, 2016 | Przejście na ranking wywołało natychmiastowy sprzeciw (88% z 1671 osób w sondzie woli stary układ). Skargi dotyczyły **niezrozumiałej kolejności i utraty kontroli**. | słaby (sonda) |
| Nextdoor | Jest sortowanie „Najnowsze”, ale nie da się go ustawić na stałe, a użytkownicy skarżą się, że i tak nie jest ściśle chronologiczne. | słaby (pomoc serwisu, wpisy użytkowników) |

Wniosek: **przewidywalność to najlepiej udokumentowany argument** i dotyczy
akurat naszej grupy. Najgorszy wariant to „prawie chronologia” — obietnica,
której system nie dotrzymuje (Nextdoor).

#### Argument C: „bez danych”

W zamkniętej alfie (20+ osób, `docs/ROADMAP.md`) wpisów jest kilka do
kilkudziesięciu dziennie. Chronologiczne Odkrywanie pokazuje wtedy **cały
dzień** na jednym–dwóch ekranach. Każdy ranking uczyłby się na zachowaniu
kilku najaktywniejszych osób. Argument jest prawdziwy **teraz** i słabnie
z każdym tysiącem wpisów.

#### Argument „bańki”

Dla treści kulinarnych jest **najsłabszy**. Duży eksperyment Guess i in.
(2023) nie wykazał, żeby zamiana rankingu na chronologię zmieniła poglądy
w ciągu trzech miesięcy. Realne ryzyko w Kukingu jest inne: **monokultura**.
Ranking po reakcjach wypycha schabowego i sernik, a zupę z babcinego zeszytu
z Podlasia zakopuje na dnie. To wariant argumentu A (MusicLab), nie osobny
argument.

#### Dowody przeciw zasadzie — trzeba je znać

| Dowód | Co pokazuje | Co to znaczy dla Kukinga |
|---|---|---|
| Guess i in., *Science* 2023, ok. 40 tys. osób, Facebook i Instagram | Chronologia **mniej więcej o połowę skróciła czas** w serwisie. Zwiększyła udział treści politycznych i niewiarygodnych, zmniejszyła udział treści obraźliwych. | Czas w serwisie świadomie **nie jest** naszą miarą (`docs/product/RETENTION_LOOPS.md` §6.1). D30 jest. Ryzyko, że chronologia obniży powroty, istnieje i trzeba je mierzyć (§2.2). |
| Instagram, 2016 | Firma uzasadniała zmianę tym, że w chronologii ludzie przegapiali **70% wszystkich wpisów i 50% wpisów znajomych**. | Chronologia też tworzy „niewidzianych” — to loteria **po czasie** zamiast po popularności. Loteria po czasie jest równa dla wszystkich (każdy czasem trafi na górę), więc zgadza się z argumentem A. Przy dużej skali traci jednak sens. |
| BeReal | Chronologia działała w małej, gęstej sieci znajomych. Dodanie strumienia „znajomi znajomych” i Odkrywania oddaliło serwis od jego celu. | Chronologia jest naturalna, dopóki sieć jest mała i bliska. Kuking w alfie taki jest. |

#### Jak robią to serwisy, które reklamują się brakiem algorytmu

| Serwis | Start / obserwowani | Odkrywanie | Lekcja |
|---|---|---|---|
| **Mastodon** | Chronologia, bez rankingu. Wyciszenia i filtry słów. | „Na czasie”: punkty z podań dalej i polubień, **wygaszane w czasie**, **najwyżej jeden wpis na konto**. Hasztagi trafiają tam dopiero po **akceptacji moderatora**. | Nawet sieć „bez algorytmu” ma ranking w Odkrywaniu, ale obwarowany: jeden na osobę i człowiek na bramce. To blisko naszej tablicy dnia. |
| **Bluesky** | „Obserwowani” chronologicznie, domyślnie przypięci. | Algorytmiczne „Odkrywaj” plus tysiące feedów do wyboru, także tworzonych przez innych. | Wybór feedu działa u osób zaangażowanych technicznie. Dla 50+ sama decyzja „który feed?” jest nowym zadaniem. |
| **Facebook** (od lipca 2022) | „Home”: ranking. Zakładka „Kanały”: chronologia, ulubieni, grupy — bez „Proponowanych”. | ranking | Chronologia schowana w drugiej zakładce. Sąd w Amsterdamie (Bits of Freedom przeciw Meta, 2.10.2025) uznał **resetowanie wyboru** chronologii za zwodniczy interfejs (art. 25 DSA). Jeśli dajemy wybór, musi być trwały. |
| **Instagram** (od marca 2022) | „Obserwowani” i „Ulubieni” chronologicznie, ale **nie domyślnie**. | ranking | Opcja, której trzeba szukać, nie chroni przewidywalności. |
| **Grupy kulinarne na Facebooku** | Ranking grupy. „Twój post widzi ułamek grupy” (`COMPETITIVE_LANDSCAPE.md` §5). | — | To słabość konkurencji, z której ludzie mogą chcieć odejść. |
| **Fora i Garnek.pl** | Fora: kolejność po ostatniej aktywności (wątek z nowym komentarzem wraca na górę). Garnek: fotoblogi po czasie. | ręcznie | Ruch wątku po odpowiedzi to prosty ranking po reakcjach, ale **zrozumiały** dla każdego. Garnek wygrał niskim progiem publikacji, nie doborem treści (§5.1). |

### 2.1.4 Pięć modeli na osi „chronologia → uczenie”

Punkt zero to stan dzisiejszy. Każdy model opisuję w tym samym układzie.
Koszty to dni pracy jednej osoby. Model 1 ma koszt z rozdziału 1 (§8).
Pozostałe to szacunki rzędu wielkości.

**M0 · Czysta chronologia (stan dzisiejszy).** Start: obserwowani albo tagi,
po czasie. „Świeżo z Kuking”: po czasie, jeden wpis na autora. Tablica dnia:
gospodarz plus automat po czasie. Jedyne narzędzia widza to obserwowanie
i blokada.

**M1 · Chronologia plus jawne wykluczenia w Odkrywaniu.** Wariant A
z rozdziału 1. Widz ukrywa u siebie osobę (i ewentualnie temat albo wpis,
§2.3). Kolejność się nie zmienia, znikają tylko wybrane pozycje.

**M2 · Chronologia plus jawne preferencje tematów.** Widz sam włącza
w Odkrywaniu „Najpierw moje tematy” albo „Tylko moje tematy”. To filtr albo
podział na dwie sekcje (moje tematy, reszta), zawsze po czasie. Bez wag. Feed
tagów (D-021) już istnieje, więc to raczej przełącznik niż nowy mechanizm.

**M3 · Ranking regułowy tylko w Odkrywaniu.** Jawny, stały wzór. Dwa bardzo
różne warianty:
- **M3a — równościowy:** np. „pierwsze wpisy nowych osób wyżej przez
  24 godziny”, „osoba, która od tygodnia nie dostała odpowiedzi, wyżej”.
  Wzór **nie liczy reakcji** na wpis, tylko ich **brak** albo staż autora.
  To ranking **na rzecz** niewidzianych.
- **M3b — popularnościowy:** np. `Ugotowałem × świeżość`. Klasyczny ranking.

**M4 · Feedy do wyboru.** Widz wybiera strumień Startu albo Odkrywania:
„Od najnowszych”, „Moje tematy”, „Wybór gospodarza”, może „Najpierw nowe
osoby”. Wybór jest trwały (wyrok z Amsterdamu).

**M5 · Ranking z uczeniem.** Sygnały niejawne plus model. Rozdział 1, wariant C.

| | M0 | M1 | M2 | M3a | M3b | M4 | M5 |
|---|---|---|---|---|---|---|---|
| **Zgodny z definicją z §2.1.2** | tak | tak | tak | tak (nie liczy reakcji ani zachowania) | **nie** (reakcje) | tak, jeśli żaden feed nie liczy reakcji | **nie** (zachowanie) |
| **Zgodny z literą §12 („nigdy”)** | tak | tak | tak | spór: to ranking | nie | zależy od feedów | nie |
| **Przewidywalność dla 50+** | najwyższa | wysoka: „ukryłam, więc nie ma” | wysoka, jeśli przełącznik jest nazwany na ekranie | średnia: kolejność „prawie po czasie” trzeba wytłumaczyć | niska | średnia: nowa decyzja do podjęcia i do zapamiętania | najniższa |
| **Wpływ na autorów** | równa loteria po czasie | brak (sygnał nie wychodzi poza widza) | autor bez tagów wypada z „moich tematów” — zachęta do tagowania, ale i nowy „niewidziany” | **pomaga nowym** — wprost na argument A | szkodzi nowym (MusicLab) | zależy od domyślnego | szkodzi nowym i niszowym |
| **Nadużycia** | brak | brak (bez agregacji) | brak | można udawać „nowego” — limit na konto | zmowa na „Ugotowałem” | jak składowe | manipulacja sygnałami |
| **RODO** | nic nowego | ustawienie konta, nie profilowanie; uwaga na art. 9 przy tematach (§2.3) | jak M1 | nic nowego (staż i brak odpowiedzi to dane serwisu) | nic nowego o widzu | ustawienie konta | **profilowanie**: art. 13, 21, możliwe DPIA |
| **DSA** | art. 27 dobrowolnie (§4.1) | jw. plus opis opcji | jw. | jw. plus opis wzoru w regulaminie | jw. | art. 25 (trwałość wyboru), jeśli nas obowiązuje | art. 27, a przy skali VLOP art. 38 (opcja bez profilowania) |
| **Koszt budowy** | 0 | 4–5,5 dnia | 1,5–3 dni | 2–4 dni plus test równości par zapytań | 5–8 dni plus strojenie | 4–7 dni (UI wyboru, trwałe ustawienie, każdy feed osobno) | tygodnie plus stała obsługa i infrastruktura zakazana przez §12 |
| **Koszt utrzymania** | 0 | mały | mały | średni: wzór trzeba bronić przed „ulepszaniem” | wysoki | średni: kilka ścieżek do testowania | wysoki |
| **Sens przy 20–200 osobach** | pełny | ograniczony: przy kilkudziesięciu autorach kilka ukryć mocno przerzedza | mały: mało wpisów na temat | mały: prawie każdy jest „nowy” | żaden: brak danych | żaden | żaden |
| **Sens przy 5000+ osobach** | maleje: dzień się nie mieści | rośnie | rośnie | rośnie | — | rośnie | — |

### 2.1.5 Rekomendacja

1. **Rozdzielić zasadę na dwie warstwy** zamiast trzymać jedno słowo „nigdy”:
   - **Start** (obserwowani i feed tagów): **zawsze** czysta chronologia,
     tylko bramki widoczności i blokady. Tak mówi publiczna obietnica
     z `/o-nas`, najmocniejszy argument B (Pew) i doświadczenie Instagrama
     oraz Nextdoora. Tu „nigdy” jest uzasadnione.
   - **Odkrywanie** (Świeżo z Kuking, automat tablicy, propozycje osób):
     **zakaz rankingu po reakcjach innych i zakaz uczenia z zachowania**
     (definicja z §2.1.2). Dozwolone są czas, zasady równości, wybór
     gospodarza i jawne polecenia widza.
2. **W alfie: M0, nic nie budować.** Zasada nie przeszkadza w niczym, co dziś
   jest potrzebne. „Więcej takich” jako skrót do obserwowania (P-2
   z rozdziału 1) nie jest zmianą zasady i może poczekać na wynik rozmów
   (§2.2) tak samo jak reszta.
3. **Po badaniu, jeśli potrzeba się potwierdzi: M1** z zakresem z §2.3
   (osoba i pojedynczy wpis).
4. **M3a zapisać jako kierunek na skalę**, nie jako plan. To jedyny ranking
   zgodny z duchem argumentu A. Wraca, gdy chronologiczne Odkrywanie przestanie
   mieścić dzień (próg w §2.2.2).
5. **M3b i M5 odrzucić** — sprzeczne z definicją. **M4 nie teraz**: dla
   osób 50+ wybór feedu to nowe zadanie i nowa rzecz do zapamiętania. Wraca
   najwcześniej przy skali, przy której M3a przestaje wystarczać.

Zmiana rozdziału 1: proponowana tam decyzja D-xxx (§7) pozostaje dobrym
szkieletem, ale powinna zaczynać się od **definicji** z §2.1.2 i od podziału
na dwie warstwy. Obecna wersja zaczyna od wykluczeń, więc wygląda jak
furtka, a nie jak doprecyzowanie.

---

## 2.2 Zakres i kolejność: co zmierzyć przed decyzją

### 2.2.1 Pytania, na które pomiar ma odpowiedzieć

1. **Czy potrzeba istnieje?** Czy ludzie w Odkrywaniu trafiają na coś, czego
   nie chcą widzieć, i czy mają dziś na to zły sposób (blokada, zgłoszenie,
   odejście)?
2. **Czy chronologia działa dla autorów?** Czy nowe osoby dostają odzew na
   pierwszy wpis i publikują drugi?
3. **Czy Odkrywanie mieści dzień?** Ile wpisów dziennie trafia do „Świeżo
   z Kuking” w porównaniu z tym, ile ktoś może obejrzeć?
4. **Czy ludzie rozumieją, skąd są wpisy?** (argument B, §2.1.3)

Na pierwsze i czwarte pytanie przy 20 osobach odpowie tylko **rozmowa**
(§2.2.3). Liczby z 20 kont to anegdoty. Drugie i trzecie da się policzyć.

### 2.2.2 Wskaźniki i progi

**Już istniejące komendy** (bez nowego kodu):

| Komenda | Co daje | Do którego pytania |
|---|---|---|
| `kuking:raport` | aktywni w ostatnich 7 dniach; powrót po 7 i 30 dniach (D7, D30); przepisy z choć jednym „Ugotowałem”; autorzy powiadomieni o „Ugotowałem”; „Zrobię ponownie”; kohorty tygodniowe z prawdziwej aktywności | 2 (powroty autorów); tło bramki V1 |
| `kuking:wac` | tygodniowo aktywni **publikujący** (bez gospodarza i kont testowych) | 2 |
| `kuking:raport-sygnalow` | ile treści oznaczył automat moderacji | tło (czy „nie chcę tego widzieć” to w praktyce sprawa moderacji) |

**Trzy zapytania tylko do odczytu**, które właściciel może uruchomić na
kopii bazy albo na produkcji w trybie odczytu. To **szkice**: nie
uruchamiałem ich na żadnej bazie. Przed użyciem trzeba sprawdzić nazwy kolumn
z `docs/DATABASE.md` i wykluczyć gospodarza oraz konta testowe tak jak robi to
`WeeklyActiveCooks`.

```sql
-- Q1. Odzew na pierwszy wpis w 48 godzin (pytanie 2).
-- first_post_events trzyma pierwszy wpis autora (migracja z 21.09.2026).
SELECT count(*) AS nowi_autorzy,
       count(*) FILTER (WHERE EXISTS (
           SELECT 1 FROM comments c
           WHERE c.post_id = f.post_id
             AND c.author_id <> f.author_id
             AND c.created_at < p.published_at + interval '48 hours'
       )) AS z_komentarzem_w_48h
FROM first_post_events f
JOIN posts p ON p.id = f.post_id
WHERE p.published_at > now() - interval '60 days';

-- Q2. Drugi wpis w ciągu 14 dni od pierwszego (pytanie 2).
SELECT count(*) AS nowi_autorzy,
       count(*) FILTER (WHERE EXISTS (
           SELECT 1 FROM posts p2
           WHERE p2.author_id = f.author_id
             AND p2.id <> f.post_id
             AND p2.status = 'published'
             AND p2.published_at <= p.published_at + interval '14 days'
       )) AS z_drugim_wpisem
FROM first_post_events f
JOIN posts p ON p.id = f.post_id
WHERE p.published_at < now() - interval '14 days';

-- Q3. Publiczne wpisy dziennie i liczba różnych autorów (pytanie 3).
SELECT date_trunc('day', published_at) AS dzien,
       count(*) AS wpisy, count(DISTINCT author_id) AS autorzy
FROM posts
WHERE status = 'published' AND visibility = 'public'
  AND published_at > now() - interval '30 days'
GROUP BY 1 ORDER BY 1;
```

Do tego dwie liczby, które da się odczytać ręcznie w panelu: **blokady**
(tabela `blocks`), przy których nie ma zgłoszenia naruszenia, oraz
**zgłoszenia** z uzasadnieniem w rodzaju „nie chcę tego widzieć”. To
sygnały, że ludzie używają za mocnego narzędzia, bo słabszego nie ma.

**Progi — propozycja do akceptacji, nie norma z badań.** Progi są wybrane
jako sygnały ostrzegawcze w skali 20 osób. Po 200 osobach warto je
przeliczyć.

| Wskaźnik | Próg „wszystko w porządku” | Próg „wracamy do tematu” | Uzasadnienie |
|---|---|---|---|
| Q1: odzew na pierwszy wpis w 48 h | ≥ 90% | < 75% | Joyce i Kraut: 61% w grupach bez gospodarza. Gospodarz w alfie odpowiada na każdy wpis (#29), więc oczekujemy dużo więcej. Spadek oznacza problem z widocznością albo z dyżurem, nie z brakiem preferencji. |
| Q2: drugi wpis w 14 dni | ≥ 50% | < 30% | Poziom z badania Joyce i Kraut (44–56%) jako punkt odniesienia. |
| D30 (`kuking:raport`) | według bramki V1 | spadek przez dwa kolejne miesiące | Jedyna miara, która mogłaby pokazać koszt chronologii z badania Guess i in. |
| Q3: publiczne wpisy dziennie | < 50 | ≥ 100 przez dwa tygodnie | Przy „jednym wpisie na autora” i ok. 20 kartach na ekranie powyżej ~100 wpisów przeciętna wizyta nie widzi dnia. Wtedy wraca M3a (§2.1.5). |
| Blokady bez naruszenia plus zgłoszenia „nie chcę widzieć” | 0–1 | ≥ 3 w miesiącu | Znak, że brakuje narzędzia słabszego niż blokada — argument za M1. |
| Rozmowy (§2.2.3): osoby, które **same** szukały sposobu na ukrycie czegoś w Odkrywaniu | ≤ 2 z 20 | ≥ 5 z 20 | Pomiędzy: M1 w wersji minimalnej (tylko osoba). |

### 2.2.3 Badanie jakościowe z 20 osobami alfy (bez budowania kodu)

**Cel:** sprawdzić, czy potrzeba istnieje, jak ludzie rozumieją źródło
wpisów i które nazwy w menu są zrozumiałe. **Nie** sprawdzamy gotowej
funkcji, bo jej nie ma.

**Kto i kiedy.** 20 osób z zamkniętej alfy (#29), po co najmniej dwóch
tygodniach korzystania — wtedy mają co wspominać. Protokół #15
(`docs/product/TESTY_Z_UZYTKOWNIKAMI.md`) ma osiem zadań i porównywalność
między rundami. Doklejanie do niego nowych zadań ją psuje. Dlatego proponuję
**osobną, krótką rozmowę (15–20 minut)**: przy kawie, przez telefon z ekranem
albo przy okazji wizyty concierge. Zasady bez zmian: zgoda przeczytana na
głos (§2 protokołu), bez nagrań, kod rozmowy zamiast nazwiska, bez zapisu
wieku (tylko przedział).

**Uwaga na stronniczość.** Pierwsza dwudziestka to w dużej części rodzina
i znajomi właściciela. Będą uprzejmi. Dlatego pytamy o **zachowanie**
(„co pani zrobiła, kiedy…”), a nie o opinię („czy podobałoby się pani…”),
i nie pokazujemy, który wariant jest „nasz”.

**Materiały:** telefon uczestnika z jego kontem oraz **drukowane karty**
(albo zrzuty w PDF) z trzema wersjami otwartego menu trzech kropek (§2.4.4)
i jedną makietą ekranu „Ukryte osoby”. Karty robi się w edytorze obrazów,
nie w aplikacji.

**Scenariusze i pytania** (prowadzący czyta dosłownie; pytania są neutralne):

| # | Scenariusz | Co mówi prowadzący | Co notujemy |
|---|---|---|---|
| R1 | Źródło wpisów (argument B) | „Proszę otworzyć Kuking. Skąd się wzięły te wpisy na górze? Dlaczego te, a nie inne?” | Czy osoba wie, że to obserwowani albo najnowsze. Słowa, których używa („komputer wybrał”, „od najnowszych”, „nie wiem”). |
| R2 | Niechciany wpis — pamięć | „Czy w ostatnich tygodniach trafiła pani na wpis, którego wolałaby pani nie widzieć? Co pani wtedy zrobiła?” | Tak/nie. Strategia: przewinęła, zablokowała, zgłosiła, przestała obserwować, zamknęła aplikację. **Kluczowy wskaźnik progu z §2.2.2.** |
| R3 | Niechciany wpis — na żywo | „Proszę znaleźć w »Świeżo z Kuking« coś, co panią najmniej interesuje. Co by pani chciała z tym zrobić?” | Czy sięga po menu trzech kropek. Czego tam szuka. Czy wybiera osobę, temat czy wpis. |
| R4 | Przewidywanie nazw (§2.4.4) | Karta z menu: „Co się stanie, jeśli pani to naciśnie?” — po kolei przy każdej pozycji. | Poprawne przewidzenie bez podpowiedzi (S/H/N jak w protokole #15). |
| R5 | Skutek i cofnięcie | Makieta: „Ukryła pani wpisy Anny. Gdzie pani sprawdzi, co jest ukryte? Jak to cofnąć?” | Czy szuka w Ustawieniach, w menu, na profilu Anny. |
| R6 | Autor — widoczność | „Dodaje pani pierwszy wpis. Kto go zobaczy?” | Model widoczności: wszyscy, obserwujący, „nie wiem”. |
| R7 | Autor — ukrycie | „Ktoś ukrył u siebie pani wpisy. Chciałaby pani o tym wiedzieć?” | Tak/nie i powód. Sprawdza regułę „autor się nie dowie” z rozdziału 1. |
| R8 | Układanie przez serwis | „Niektóre serwisy same układają wpisy według tego, co ktoś ogląda. Co pani o tym myśli?” — dopiero na końcu, żeby nie sugerować wcześniejszych odpowiedzi. | Postawa: zaufanie, obojętność, niechęć. Cytaty. |

**Jak czytać wyniki** (reguły ustalone przed badaniem):

- Pytanie o potrzebę rozstrzyga R2 z R3 według progu z §2.2.2
  (≤ 2 z 20: nie budować; ≥ 5 z 20: M1; pomiędzy: M1 tylko z osobą).
- Zakres ukrywania rozstrzyga R3: co ludzie **sami** chcieli ukryć.
- Nazwę rozstrzyga R4 (próg w §2.4.4).
- Jeśli w R1 więcej niż 5 z 20 osób nie wie, skąd są wpisy, to jest
  **ważniejszy problem niż preferencje**: napisy na Starcie trzeba poprawić
  przed jakąkolwiek nową funkcją.
- Wynik R7 może zmienić regułę „autor się nie dowie”. Wtedy wraca to jako
  osobne pytanie do właściciela.

**Koszt po stronie właściciela:** ok. 20 × 20 minut rozmów plus 2–3 godziny
na karty i podsumowanie. Kod: zero.

---

## 2.3 Co można ukryć: cztery warianty

Kontekst techniczny, który zmienia ocenę względem rozdziału 1:

- **Tagi są wolnymi słowami autora** (słownik: 1446 tagów i 2542 aliasy,
  `docs/product/AUDYT_COLD_START_29_2026-09-20.md`). Wpis może mieć zero
  tagów. Ukrycie tematu „Ciasta” nie ukryje sernika bez tagu.
- **Rodzaj wpisu to dziś tylko** `dish` albo `question` (`Post::KIND_*`),
  a pytania są za flagą `kuking.questions.enabled`. Nie ma klasyfikacji
  diet ani składników „mięsnych” (`TagSeeder` świadomie odrzucił nawet tagi
  typu „fit”).
- Istnieje precedens pojedynczego ukrycia: przy wspomnieniu na Starcie stoi
  przycisk „**Nie pokazuj mi tego więcej**” (`home.blade.php`, #34).

### 2.3.1 Osoba

| Kryterium | Ocena |
|---|---|
| UX 50+ | **Najbardziej zrozumiałe.** Jednostką jest człowiek z imieniem i zdjęciem. Skutek jest szczelny i przewidywalny: „Anny nie widzę”. Badanie Mozilli (rozdział 1, §5.1): „Nie polecaj kanału” działa wyraźnie lepiej niż „Nie interesuje mnie”. |
| Nadużycia | Brak, dopóki sygnał nie jest sumowany między widzami (rozdział 1, §6). |
| Prywatność | Ujawnia relację widza z konkretną osobą. To zwykła dana osobowa, nie szczególna kategoria. Trafia do eksportu i znika z kontem. |
| Społecznie | Łagodniejsze od blokady (`INSPIRATION_DECISIONS.md` 2.12: „w małej społeczności blokada jest zbyt mocnym gestem”). W małej alfie ukrycie znajomej może jednak boleć, jeśli się wyda. Reguła „autor się nie dowie” jest tu ważna (R7 w §2.2.3). |
| Koszt | Ok. 60% kosztu M1: jedna tabela, jeden `whereNotIn`, ekran listy. |
| Werdykt | **Tak** — pierwszy kandydat. |

### 2.3.2 Temat (tag)

| Kryterium | Ocena |
|---|---|
| UX 50+ | **Nieszczelne**, więc nieprzewidywalne: „ukryłam ciasta, a widzę sernik” (brak tagu albo inny tag: „wypieki”, „sernik”). Dla osoby 50+ to wygląda na błąd serwisu — dokładnie ta skarga, którą Facebook zbiera przy „Pokaż mniej” (§5.1). Aliasy pomagają tylko częściowo. |
| Nadużycia | Brak. |
| Prywatność | **Nowe ryzyko.** Ukrycie tematów „wieprzowina”, „halal”, „koszerne”, „bezglutenowe” czy „cukrzyca” może ujawniać religię albo zdrowie — **szczególne kategorie danych z art. 9 RODO**. Przechowywanie takiego ustawienia wymaga albo wyraźnej zgody, albo przynajmniej analizy, czy dane „ujawniają” te cechy (**[do weryfikacji prawnej]**). Rozdział 1 tego nie zauważył. |
| Koszt | Ok. 40% kosztu M1 plus analiza art. 9 i przenoszenie przy scalaniu tagów (`MergeTags`). |
| Werdykt | **Nie w pierwszej wersji.** Wraca, jeśli rozmowy (R3) pokażą, że ludzie chcą ukrywać tematy, a nie osoby. Lepsza droga do tego samego celu to strona pozytywna: „Obserwuj temat »Wegetariańskie«” (M2). Obserwowanie niczego nie ukrywa i nie ujawnia niechęci. |

### 2.3.3 Pojedynczy wpis

| Kryterium | Ocena |
|---|---|
| UX 50+ | Najprostszy skutek („ten wpis zniknął”) i jest precedens w serwisie. **Mała wartość** w chronologicznym Odkrywaniu: wpis i tak zjedzie w dół w ciągu godzin. **Duża wartość** tam, gdzie wpis stoi długo: na tablicy „kuKINGi na dziś” (cały dzień) i we wspomnieniach (już jest). |
| Nadużycia | Brak. |
| Prywatność | Minimalna. Można przechowywać krótko (np. 30 dni), bo wpis i tak się zestarzeje. |
| Koszt | Najniższy: jedna tabela (widz, wpis, data) i jeden warunek. Wzorzec istnieje przy wspomnieniach. |
| Werdykt | **Tak, razem z osobą.** Daje odpowiedź w chwili „nie chcę na to patrzeć”, bez decyzji o całej osobie. |

### 2.3.4 Rodzaj treści

| Wariant | Ocena |
|---|---|
| **Pytania** (`kind = question`) | Jednoznaczne, szczelne i nie ujawnia niczego wrażliwego. Ma sens dopiero, gdy pytania zostaną włączone (dziś flaga). Wtedy lepiej jako **ustawienie** („Pokazuj pytania w »Świeżo z Kuking«: tak/nie”) niż pozycja w menu. Koszt: mały. **Do rozważenia razem z włączeniem pytań**, nie teraz. |
| **Przepisy albo same zdjęcia dań** | Szczelne (to widać w danych). Potrzeby nie widać: Kuking chce, żeby zdjęcie dania było pełnoprawnym wpisem. Ukrywanie zdjęć dań uderzałoby w główną akcję „Co dziś ugotowałeś?”. **Nie.** |
| **„Przepisy mięsne” i inne diety** | **Nie da się zrobić uczciwie.** Brak klasyfikacji. Tagi są niepełne. Rozpoznawanie ze zdjęcia albo składników przez AI byłoby omylne, a każda pomyłka („ukryłam mięso, a widzę schabowego”) niszczy zaufanie bardziej niż brak funkcji. Do tego art. 9 (wegetarianizm bywa religijny). **Odrzucić.** Zamiast tego: obserwowanie tematów po stronie pozytywnej. |
| **Słowa** (filtry jak w Mastodonie) | Precyzyjne dla wprawnych, nieprzewidywalne dla reszty („ukryłam »wątróbka«, a zniknął też przepis na pasztet z wątróbką kurczaka, który lubię”). Poza MVP i V1. |

### 2.3.5 Podsumowanie

| Wariant | UX 50+ | Nadużycia | Prywatność | Koszt | Werdykt |
|---|---|---|---|---|---|
| Osoba | ★★★ szczelne | brak | zwykła dana | średni | **tak** |
| Pojedynczy wpis | ★★★ | brak | minimalna | niski | **tak** (z osobą) |
| Temat | ★ nieszczelne | brak | **ryzyko art. 9** | średni | później, po badaniu |
| Pytania | ★★★ | brak | brak | niski | razem z włączeniem pytań |
| Dieta, rodzaj dania | ✗ | brak | art. 9 | wysoki i omylny | **nie** |

---

## 2.4 Nazwy w menu

### 2.4.1 Kryteria

Z `docs/brand/BRAND_EXTENDED.md` §3 (pięć testów nazwy) i
`docs/brand/COPY_STYLE.md` §7 (lista kontrolna), zastosowane do pozycji menu:

1. **Test babci:** czy osoba, która nie zna funkcji, zgadnie z samej nazwy,
   co się stanie?
2. **Prawda:** czy nazwa opisuje to, co **naprawdę** robi kod? („Czy to
   zdanie jest prawdziwe przy kodzie, który dziś stoi w repozytorium?”)
3. **Słownik i kuchnia:** zwykłe polskie słowa, bez kalek i żargonu. Zakazane
   są m.in. `content`, `feed`, „platforma” (`BRAND_EXTENDED.md` §2.1).
4. **Spójność:** jedna funkcja ma jedną nazwę. W serwisie już są „Obserwuj”,
   „Przestań obserwować”, „Zgłoś ten wpis” i „Nie pokazuj mi tego więcej”
   (wspomnienia).
5. **Długość i forma:** limit 16 znaków dotyczy przycisków. Pozycja w menu ma
   pełny napis, ale krótszy jest lepszy na ekranie 320 px przy tekście 18 px.
   Bez zakładania płci.

Dwie obserwacje, które przesądzają wiele wariantów:

- **Słowo „treści”** jest abstrakcyjne i urzędowe. Na `/o-nas` pierwsze
  zobowiązanie brzmi: „**Ludzie, nie treści.**” Menu mówiące o „treściach”
  zaprzecza marce w jej własnych słowach.
- **„Pokaż mniej”** obiecuje wagę (rzadziej, niżej), a model M1 to filtr
  (wcale). Nazwa byłaby nieprawdziwa. Odwrotnie: „Więcej takich” obiecuje, że
  serwis coś dobierze, a w M1 to skrót do obserwowania. **Nazwa zależy od
  modelu** — to trzeba rozstrzygnąć razem.
- **„Wycisz”** (Mastodon, Instagram) dla osoby 50+ znaczy „wyłącz dźwięk”
  w telefonie. To żargon serwisów, nie słowo z kuchni. Odpada jako etykieta.

### 2.4.2 Osiem wariantów

| # | „Więcej” | „Mniej” | Uwagi |
|---|---|---|---|
| N1 | Chcę widzieć więcej takich wpisów | Chcę widzieć mniej takich wpisów | Słowa właściciela po poprawce z „treści” na „wpisów”. |
| N2 | Chcę widzieć więcej takich treści | Chcę widzieć mniej takich treści | Brzmienie z issue. |
| N3 | Pokaż więcej takich | Pokaż mniej takich | Jak dawny Facebook. |
| N4 | Interesuje mnie | Nie interesuje mnie | Jak dzisiejszy Facebook i Instagram. |
| N5 | Lubię takie wpisy | Nie dla mnie | Potoczne, krótkie. |
| N6 | Więcej takich wpisów | Nie pokazuj mi takich wpisów | Mieszane: ogólne „więcej” i konkretne „nie pokazuj”. |
| N7 | — (tylko „Obserwuj…”, patrz N8) | Nie pokazuj mi tego więcej | Spójne ze wspomnieniami. Dotyczy pojedynczego wpisu. |
| N8 | Obserwuj Annę · Obserwuj temat »Ciasta« | Nie pokazuj mi wpisów Anny · Nie pokazuj mi tego wpisu | **Konkretne akcje z imieniem.** Nie ma ogólnych „więcej/mniej”. Menu mówi dokładnie, co się stanie. |

### 2.4.3 Ocena

Skala 1–5 (5 najlepiej). „Prawda” oceniam względem M1 z zakresem z §2.3
(osoba i wpis). To ocena zza biurka, hipoteza do testu z §2.4.4.

| # | Test babci | Prawda przy M1 | Słownik i marka | Spójność | Długość | Suma | Główny problem |
|---|---|---|---|---|---|---|---|
| N1 | 3 | 2 | 4 | 2 | 2 | 13 | „Mniej” obiecuje „rzadziej”, a filtr ukrywa całkiem. Nie mówi, czego mniej: osoby, tematu czy wpisu. Długie. |
| N2 | 3 | 2 | **1** | 2 | 2 | 10 | „Treści” przeciw „Ludzie, nie treści”. |
| N3 | 3 | 1 | 3 | 2 | 4 | 13 | Obiecuje wagę, której nie ma. Niejasne, co „pokaż”. |
| N4 | 4 | 2 | 4 | 2 | 4 | 16 | Znane z Facebooka, więc zrozumiałe. Ale przenosi facebookowe oczekiwanie („nauczy się”), którego nie spełnimy. „Interesuje mnie” przy wpisie znajomej brzmi dziwnie. |
| N5 | 3 | 2 | 3 | 1 | 5 | 14 | „Lubię” miesza się z polubieniem („Ładne!”). „Nie dla mnie” jest ciepłe, ale nie mówi, co się stanie. |
| N6 | 3 | 3 | 4 | 3 | 3 | 16 | Uczciwe po stronie „mniej”. „Więcej” wciąż ogólne. |
| N7 | 5 | 5 | 5 | **5** | 4 | 24 | Tylko pojedynczy wpis. Samo nie wystarczy na osobę. |
| N8 | **5** | **5** | 5 | 4 | 3 | 22 | Etykiety zmienne (imię w napisie). Dłuższe przy długich imionach. Dwie–cztery pozycje w menu zamiast dwóch. |

**Rekomendacja nazw (hipoteza):** **N8 plus N7**, czyli w menu cudzego wpisu:

```text
Otwórz wpis
Obserwuj Annę Kowalską            (jeśli nie obserwuje)
Nie pokazuj mi tego wpisu         (= N7, spójne ze wspomnieniami)
Nie pokazuj mi wpisów Anny        (jeśli nie obserwuje; tylko Odkrywanie)
Zgłoś ten wpis
```

Nagłówek w Ustawieniach: „**Ukryte osoby i wpisy**”. Pod spodem jedno zdanie
o zasięgu: „Nie pokazujemy ich w »Świeżo z Kuking« i na tablicy »kuKINGi na
dziś«. Osoby, które obserwujesz, widzisz jak dotąd.” Rejestr: rzeczowy
(`COPY_STYLE.md` §3), bez żartu i bez „kuKING” w etykietach menu.

Dlaczego nie słowa właściciela (N1)? Bo menu z konkretnymi akcjami robi to,
czego chciał właściciel — ludzie mówią, co chcą widzieć — **i przy okazji
mówi, co się stanie**. Pomysł zostaje, zmienia się tylko etykieta. Jeśli
test pokaże, że N1 jest rozumiane równie dobrze, można wrócić do N1 jako
etykiety, która otwiera ekran wyboru z rozdziału 1 (§5.2).

### 2.4.4 Test nazw z użytkownikami

**Metoda:** test przewidywania na drukowanych kartach, w ramach rozmowy
z §2.2.3 (scenariusz R4). Bez kodu.

1. **Karty.** Trzy wersje tego samego wpisu z otwartym menu trzech kropek:
   **N1**, **N4**, **N8+N7**. Ten sam wpis, ta sama osoba, ta sama
   wielkość tekstu (18 px w skali telefonu).
2. **Kolejność rotowana** (kwadrat łaciński: A-B-C, B-C-A, C-A-B), żeby
   pierwsza karta nie wygrywała tylko dlatego, że była pierwsza. Najwyżej
   trzy karty na osobę — więcej męczy.
3. **Pytania przy każdej pozycji menu:** „Co się stanie, jeśli pani to
   naciśnie?”, potem „Gdzie jeszcze pani by to potem zobaczyła albo nie
   zobaczyła?”.
4. **Zadanie odwrotne** (po wszystkich kartach): „Chce pani nie widzieć
   więcej wpisów tej osoby, ale bez blokowania. Co pani naciśnie?” — na
   każdej karcie.
5. **Pytanie o wybór** na samym końcu: „Która wersja jest dla pani
   najjaśniejsza?” (preferencja zapisywana osobno, nie liczona jako wynik).

**Kodowanie:** S — poprawnie bez pomocy; H — po wskazówce; N — źle.
„Poprawnie” znaczy, że osoba opisała skutek zgodny z modelem (np. „nie
zobaczę jej wpisów w nowościach”, a nie „będzie ich mniej”).

**Próg:** wariant przechodzi, jeśli **co najmniej 16 z 20 osób (80%)**
poprawnie przewidzi skutek pozycji „mniej/nie pokazuj” **bez pomocy**. Jeśli
przechodzą dwa, wygrywa krótszy. Jeśli nie przechodzi żaden, nazwy wracają
do pracowni i funkcja nie idzie do budowy. Wynik zapisujemy przy protokole
#15 jako osobną kartę, bez danych osobowych.

---

## 2.5 Macierz decyzji dla właściciela

Każde pytanie ma 2–4 opcje i rekomendację. Pytania są ułożone w kolejności
zależności: odpowiedź na 1 zmienia sens 3–5.

**Pytanie 1. Jak brzmi zasada „bez algorytmicznego feedu”?**

| Opcja | Co znaczy |
|---|---|
| (a) | Bez zmian: „nigdy” obowiązuje wszędzie, dosłownie. Każda zmiana kolejności w Odkrywaniu wymaga nowej decyzji. |
| **(b) — rekomendacja** | **Dwie warstwy i definicja z §2.1.2.** Start zawsze chronologicznie. W Odkrywaniu zakaz rankingu po reakcjach innych i uczenia z zachowania. Jawne wybory widza, zasady równości i wybór gospodarza są dozwolone. |
| (c) | Jak (b), a do tego od razu zgoda na ranking równościowy M3a w Odkrywaniu („nowe osoby wyżej”), gdy przekroczony zostanie próg Q3. |
| (d) | Otworzyć drogę do rankingu po popularności albo uczenia po bramce V1. |

Dlaczego (b): chroni to, co ma najmocniejsze dowody i publiczną obietnicę
(Start), a Odkrywaniu daje słowa zamiast słowa „nigdy”, które przy skali
zacznie się rozjeżdżać z §8. Opcja (c) jest rozsądna, ale przedwczesna:
przy 20 osobach każdy jest „nowy”.

**Pytanie 2. Kiedy zapada decyzja o budowie „więcej / mniej”?**

| Opcja | Co znaczy |
|---|---|
| (a) | Teraz: budować M1 według rozdziału 1. |
| **(b) — rekomendacja** | **Po rozmowach z 20 osobami alfy (§2.2.3) i odczycie wskaźników (§2.2.2)**, według progów ustalonych przed badaniem. |
| (c) | Dopiero po bramce V1 (WAC i D30 pokazują powroty). |
| (d) | Nigdy: zostają obserwowanie i blokada. |

Dlaczego (b): koszt rozmów to kilka godzin właściciela i zero kodu, a wynik
rozstrzyga jednocześnie potrzebę, zakres i nazwy. Opcja (c) opóźnia bez
potrzeby, bo potrzeba może wyjść wcześniej (blokady używane jako „mniej”).

**Pytanie 3. Co będzie można ukryć, jeśli budujemy?**

| Opcja | Co znaczy |
|---|---|
| **(a) — rekomendacja** | **Osobę i pojedynczy wpis.** Temat dopiero po badaniu. |
| (b) | Osobę i temat (jak w rozdziale 1). |
| (c) | Osobę, temat i pojedynczy wpis. |
| (d) | Tylko osobę. |

Dlaczego (a): oba warianty są szczelne i przewidywalne. Temat jest
nieszczelny (niepełne tagi) i niesie ryzyko art. 9 RODO (§2.3.2). Ukrywanie
diet i „przepisów mięsnych” odrzucam w każdej opcji. Pytania — jako
ustawienie, gdy zostaną włączone.

**Pytanie 4. Jak nazywamy to w menu?**

| Opcja | Co znaczy |
|---|---|
| (a) | N1: „Chcę widzieć więcej / mniej takich wpisów” (słowa właściciela, „wpisów” zamiast „treści”). |
| (b) | N4: „Interesuje mnie / Nie interesuje mnie” (jak Facebook). |
| **(c) — rekomendacja** | **N8 + N7: konkretne akcje** — „Obserwuj Annę”, „Nie pokazuj mi tego wpisu”, „Nie pokazuj mi wpisów Anny”. |
| (d) | Nie wybierać teraz: zdecyduje test z §2.4.4 między (a), (b) i (c). |

Dlaczego (c): jedyny wariant, który przechodzi test prawdy przy filtrze
i nie zderza się z marką. Właściciel może też wybrać (d) i przyjąć (c) jako
domyślną, jeśli test nie wskaże zwycięzcy.

**Pytanie 5. Jak przeprowadzamy badanie?**

| Opcja | Co znaczy |
|---|---|
| **(a) — rekomendacja** | **Osobne rozmowy po 15–20 minut z 20 osobami alfy**, na ich telefonach i drukowanych kartach, według §2.2.3. |
| (b) | Dokleić scenariusze do sesji z protokołu #15. |
| (c) | Ankieta internetowa. |

Dlaczego (a): (b) psuje porównywalność rund #15 i wydłuża sesję ponad 45
minut. (c) nie sprawdzi przewidywania skutku i zbierze odpowiedzi osób
najbardziej wprawnych.

**Pytanie 6. Czy przyjmujemy progi z §2.2.2?**

| Opcja | Co znaczy |
|---|---|
| **(a) — rekomendacja** | **Tak, jako progi ostrzegawcze**, z przeglądem po 200 osobach. |
| (b) | Tak, ale z innymi liczbami (właściciel wskazuje). |
| (c) | Bez progów: decyzja po lekturze wyników. |

Dlaczego (a): progi zapisane **przed** badaniem chronią przed dopasowaniem
wniosku do chęci. Liczby są hipotezami i mają datę przeglądu.

---

## 2.6 Co z tego rozdziału wynika dla rozdziału 1

### 2.6.1 Co zostaje

- „Więcej takich” to skrót do obserwowania, nie nowy mechanizm.
- Sygnał należy wyłącznie do widza: nie jest sumowany, nie trafia do
  moderacji, autor się nie dowie (do sprawdzenia w R7).
- Żadnego uczenia z zachowania. Przejrzystość według art. 27 DSA
  dobrowolnie (§4.1).
- Miejsce w menu trzech kropek i osobna prosta strona zamiast okna (§5.2).

### 2.6.2 Co się zmienia

| Rozdział 1 | Rozdział 2 | Powód |
|---|---|---|
| „Więcej” już w MVP (P-2) | Wszystko po rozmowach z alfą | Rozmowy są tanie i rozstrzygają także nazwy, więc nie warto budować etykiety, która może zniknąć. |
| Ukrywanie osoby albo tematu | Osoba i pojedynczy wpis; temat później | Nieszczelne tagi i art. 9 RODO (§2.3.2). |
| Nazwy: słowa właściciela jako punkt wyjścia | Konkretne akcje (N8 + N7) jako hipoteza, test przewidywania | „Treści” przeciw marce, „mniej” nieprawdziwe przy filtrze (§2.4.1). |
| D-xxx zaczyna od wykluczeń | D-xxx zaczyna od definicji i dwóch warstw | Bez definicji decyzja wygląda jak furtka (§2.1.5). |
| „Wagi i uczenie odrzucić” | Uczenie i popularność odrzucić; ranking równościowy M3a zapisać jako kierunek na skalę | Tylko on działa na rzecz „niewidzianych” (§2.1.4). |

### 2.6.3 Pozycje do `docs/prawo/DO_WERYFIKACJI_PRAWNEJ.md` (po decyzji)

- Czy przechowywanie ukrytych **tematów** o charakterze religijnym albo
  zdrowotnym to dane szczególnej kategorii (art. 9 RODO) i jaka podstawa
  jest wtedy potrzebna.
- Czy art. 25 DSA (trwałość wyboru, wyrok z Amsterdamu) dotyczy nas mimo
  art. 19. Ma to znaczenie tylko przy modelu M4.

---

## Źródła (rozdział 2)

Repozytorium (`origin/main` @ `9dddf0f01`): `AGENTS.md` §8, §12;
`docs/DECISIONS.md` D-021, D-081, D-194; `docs/product/SOUL.md`;
`docs/research/AUDIENCE_50_PLUS.md` §3, §4, §6, §13;
`docs/research/COMPETITIVE_LANDSCAPE.md` §1, §5; `docs/MAPA_REGUL_DOWODY.md`
(R73); `docs/product/RETENTION_LOOPS.md` §6.1;
`docs/product/TESTY_Z_UZYTKOWNIKAMI.md`; `docs/product/KARTA_BADANIA_15.md`;
`docs/product/AUDYT_COLD_START_29_2026-09-20.md`; `docs/ROADMAP.md`;
`docs/brand/COPY_STYLE.md` §1, §3, §7; `docs/brand/GLOS_MARKI.md` §5, §8;
`docs/brand/BRAND_EXTENDED.md` §2, §3; `docs/INSPIRATION_DECISIONS.md` 2.12;
`resources/views/pages/static/about.blade.php`;
`resources/views/pages/home.blade.php`;
`resources/views/components/post-card.blade.php`;
`app/Console/Commands/{RaportPowrotow,ReportWeeklyActiveCooks,RaportSygnalow}.php`;
`app/Models/Post.php`; `database/seeders/TagSeeder.php`;
`database/migrations/2026_09_21_100900_create_first_post_events.php`;
issues #15, #29, #1781.

Zewnętrzne (odczyt 25.09.2026):

- Joyce E., Kraut R. E., *Predicting Continued Participation in Newsgroups*,
  Journal of Computer-Mediated Communication 11(3), 2006:
  https://academic.oup.com/jcmc/article-abstract/11/3/723/4617705
- Burke M., Marlow C., Lento T., *Feed Me: Motivating Newcomer Contribution
  in Social Network Sites*, CHI 2009:
  https://dl.acm.org/citation.cfm?id=1518847
- Salganik M., Dodds P., Watts D., *Experimental Study of Inequality and
  Unpredictability in an Artificial Cultural Market*, Science 311, 2006:
  https://www.science.org/doi/abs/10.1126/science.1121066
- Guess A. i in., *How do social media feed algorithms affect attitudes and
  behavior in an election campaign?*, Science 2023:
  https://www.science.org/doi/10.1126/science.abp9364 ; omówienie:
  https://techxplore.com/news/2023-07-facebook-algorithm-doesnt-people-beliefs.html
- Pew Research Center, *Many Facebook users don't understand how the site's
  news feed works*, 5.09.2018:
  https://www.pewresearch.org/short-reads/2018/09/05/many-facebook-users-dont-understand-how-the-sites-news-feed-works/ ;
  wiek 50+: https://phys.org/news/2018-09-facebook-users-dont-news.html
- Facebook, spadek oryginalnego dzielenia się (2016):
  https://fortune.com/2016/04/07/facebook-sharing-decline/
- Zeznanie M. Zuckerberga przed FTC, kwiecień 2025:
  https://www.cnn.com/2025/04/16/tech/mark-zuckerberg-testimony-meta-ftc-trial
- Instagram 2016: https://money.cnn.com/2016/03/16/technology/instagram-feed-algorithm/index.html ,
  https://www.cbsnews.com/news/instagram-jumps-on-the-algorithm-bandwagon-let-the-user-backlash-begin/ ;
  powrót chronologii 2022: https://petapixel.com/2022/03/23/instagrams-chronological-feed-is-finally-back/
- Facebook „Kanały” (Feeds), lipiec 2022:
  https://about.fb.com/news/2022/07/home-and-feeds-on-facebook/
- Bits of Freedom przeciw Meta, sąd w Amsterdamie, 2.10.2025:
  https://www.dlapiper.com/en-us/insights/blogs/mse-today/2025/the-amsterdam-court-putting-the-dsa-into-practice
- DSA art. 38 i systemy rekomendacji:
  https://dsa-observatory.eu/2024/11/22/the-regulation-of-recommender-systems-under-the-dsa-a-transition-from-default-to-multiple-and-dynamic-controls/
- Bluesky, feedy do wyboru: https://bsky.social/about/blog/7-27-2023-custom-feeds
- Mastodon, „Na czasie” i moderacja trendów:
  https://fedi.tips/where-are-the-trending-posts-and-hashtags-on-mastodon/ ,
  https://fedi.tips/how-do-admins-moderate-trends-on-their-mastodon-server/
- BeReal: https://gizmodo.com/bereal-adds-friends-of-friends-tab-1850757592
- Nextdoor, sortowanie: https://help.nextdoor.com/s/article/How-to-sort-your-newsfeed?language=en_US
