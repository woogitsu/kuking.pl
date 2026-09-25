# „Więcej / mniej takich treści” — research i propozycja decyzji (#1781)

Data: 25 września 2026. Punkt odniesienia: `origin/main` @ `9dddf0f01`.
Charakter: **research i materiał do decyzji właściciela**. Ten dokument nie
zmienia kodu aplikacji, `AGENTS.md` ani `docs/DECISIONS.md`. Nie zakłada issues.

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
- **Luka znaleziona przy okazji:** eksport danych
  (`app/Domain/Users/Exports/CollectUserExportData.php`) **nie zawiera
  obserwowanych tagów**. Komentarz przy `co_zawiera` mówi to wprost (#492).
  Każda nowa „preferencja” powinna trafić do eksportu. Najlepiej załatwić tagi
  przy tej samej okazji (issue P-6 niżej).
- `EraseAccountData` czyści `tag_follows`. Nowe tabele muszą tam dołączyć.
- `resources/legal/polityka-prywatnosci.md` nie ma dziś ani słowa
  o profilowaniu ani o tym, jak dobieramy treści. Nie było takiej potrzeby.
- `docs/legal/COMPLIANCE.md` zakłada: „feed jest chronologiczny (brak systemu
  rekomendacji w rozumieniu DSA na MVP)”. **To założenie jest za wąskie** —
  patrz §4.1.

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

## Źródła

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
