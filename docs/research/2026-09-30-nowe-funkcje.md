# Nowe funkcje — research produktowy (30 września 2026)

Research, nie implementacja. Baza: `origin/claude/paczka-i-kandydat` (5548c7e16).
Autor: sesja badawcza floty (Claude Opus 5.5) na zlecenie koordynatora.

**Oznaczenia źródeł.** `[web]` — sprawdzone wyszukiwaniem albo pobraniem
strony 29.09.2026 (link w „Źródła”). `[repo]` — dokument albo kod w tym
repozytorium. `[wiedza]` — wiedza modelu bez świeżej weryfikacji: traktować
jako hipotezę do sprawdzenia w badaniu z ludźmi (#15), nie jako fakt.

---

## 1. Podsumowanie

Kuking ma już zbudowane prawie wszystko, co konkurencja uważa za rdzeń:
„Ugotowałem” z czasem, trudnością i „zrobię ponownie”, tryb gotowania,
skalowanie porcji, zamienniki od autora, zeszyty prywatne i wspólne,
„Moja wersja”, planer, „Co mam w domu”, „Poradźcie”, wspomnienia, urodziny,
tygodniowy list, druk przepisu, import i odczyt kartki. **Luka nie leży więc
w liczbie funkcji, tylko w domykaniu pętli wokół „Ugotowałem”**: po trybie
gotowania nic nie wraca do osoby, która nie kliknęła od razu; autor nie ma
widocznego głosu pod własnym przepisem; nie ma wspólnego gotowania jednego
przepisu w tym samym tygodniu (Cookpad robi to co miesiąc); a pomocnik
z rodziny albo prowadząca zajęcia w KGW/UTW nie ma czego wydrukować.

Proponuję 14 funkcji. Żadna nie łamie listy „Nie wcześnie”, żadna nie
wymaga nowego stacku (zero Redisa, SPA, osobnego search engine, WebSocketów),
żadna nie uruchamia nowego AI (D-333: „AI — nie uruchamiamy nic nowego”).
Trzy pozycje ocierają się o decyzje i są tak oznaczone (F8 o #1906, F9
o #1015, F12 o „Poradźcie”).

**Na początek (wartość/koszt):**

1. **F1 „Jak wyszło?”** — jedno ciche zdanie na `/home` po trybie gotowania
   zakończonym bez „Ugotowałem”. Koszt S. Wprost podnosi liczbę wykonań,
   czyli North Star.
2. **F2 Plakietka „autorka przepisu”** przy komentarzach autora pod własnym
   przepisem i pod wykonaniami. Koszt S. Domyka pętlę 1 z `RETENTION_LOOPS.md`,
   która jest tam opisana jako „jeszcze nie zbudowana”.
3. **F3 „Ugotujmy razem”** — jeden przepis tygodnia wybrany przez gospodarza,
   galeria wykonań z tego tygodnia. Koszt M. Pozycja „wyzwania społecznościowe”
   z V1, w języku Kukinga i bez nagród.

---

## 2. Co przeczytałem i czego nie proponuję ponownie

Przeczytane: `AGENTS.md` (§1, §3, §8, §9, §12), `docs/FEATURES.md` (MVP, V1,
V2, „V2, ale nie teraz”, „Nie wcześnie”), `docs/ROADMAP.md`,
`docs/DECISIONS.md` (D-331, D-333, D-334 oraz wybiórczo D-021, D-026, D-057,
D-081, D-275, D-303, D-304), `docs/research/COMPETITIVE_LANDSCAPE.md`,
`docs/research/AUDIENCE_50_PLUS.md`, `docs/research/USPRAWNIENIA.md` (§3,
§5), `docs/product/SOUL.md`, `docs/product/RETENTION_LOOPS.md`, trasy
w `routes/web.php` i wybrane widoki. Issues: #1902, #1903, #1904, #1906,
#2067, #1999, #1983, #22, #1756, #1015, #1045 oraz tytuły wszystkich 2232
issues i PR-ów (pobrane przez API; wyszukiwarka GitHuba zwróciła 403, więc
duplikaty sprawdzałem po tytułach lokalnie).

**Już jest — nie proponuję** (sprawdzone w `routes/web.php` i widokach):
tryb gotowania z CTA „Ugotowałem” na ostatnim kroku
(`resources/views/pages/recipes/cooking.blade.php`, sekcja `cook-finish`),
minutniki, synchronizacja postępu (#2016), wydruk przepisu
(`resources/css/wydruk-przepisu.css`), „Podziękuj” przy wykonaniu
(`/ugotowane/{cookedEvent}/podziekuj`), „Smakowicie wygląda” (#1813),
powiadomienie autora o „Mojej wersji” (`Notification::TYPE_FORKED`),
pochodzenie przepisu i „w rodzinie od roku” (`recipes.source_person`,
`family_since_year`, `source_scan_media_id`), szacowany koszt
(`estimated_cost_pln`, D-286), zeszyt wspólny z zaproszeniem linkiem (D-302),
prywatna notatka przy pozycji zeszytu (#978), „Mój stół” (D-304), planer
(D-310), lista zakupów (w toku, #27), wspomnienia wpisów, urodziny, rocznica,
tygodniowy list z pytaniem gospodarza (D-057), panel „wpisy bez odpowiedzi”
(`/bez-odpowiedzi`), logowanie linkiem i kodem, Web Push (D-303).

**Świadomie odrzucone w tym researchu** (zakazy i decyzje):

| Pomysł | Dlaczego nie | Źródło |
|---|---|---|
| Wiadomości prywatne między użytkownikami | „Nie wcześnie”: DM | `docs/FEATURES.md` |
| Transmisja gotowania na żywo, czat | „Nie wcześnie”: live video, live chat | `docs/FEATURES.md` |
| Odznaki za liczbę wpisów, serie dni | „punkty za liczbę postów”; §12 „streaki i punkty… nie wprowadzamy nigdy” | `docs/FEATURES.md`, `AGENTS.md` §12 |
| Ranking przepisów tygodnia po wykonaniach | §8: żadna lista nie jest układana według „Ugotowałem” | `AGENTS.md` §8, D-275 |
| Podpowiedzi AI (zamienniki, podsumowanie uwag, asystent) | „AI — nie uruchamiamy nic nowego (#813, #814, #815, #1983 czekają)” | D-333 |
| Alergeny, spiżarnia z terminami, offline, sterowanie głosem, mediana czasu | „V2, ale nie teraz” — „zostają zakazane bez nowej decyzji” | D-331, #1902, #1903, #1904, #1906, #2067 |
| Grupy tematyczne | „Obniżyć do P3, dopiero po bramce WAC/D30” | D-333, #22 |
| Imieniny | „imieniny wchodzą, ale po testach z użytkownikami 50+ (#15)” | #1756 |
| Automatyczny pasek „Teraz sezon na…” | „nierozstrzygnięty pomysł, który wymaga osobnej decyzji właściciela” | `RETENTION_LOOPS.md` pętla 5, D-026 |
| Import z Facebooka / całych grup | „masowy import cudzych treści” | `docs/FEATURES.md` „Nie wcześnie”, D-300 |

---

## 3. Co robią inni — w skrócie

| Serwis | Co warto zauważyć | Czego nie brać | Źródło |
|---|---|---|---|
| **Cookpad** | Cooksnap = „komentarz ze zdjęciem” dla autora; przepisy z cooksnapami mają „pieczęć aprobaty”. Stała lista wyzwań: we wrześniu 2026 aktywne „Recipes Passed Down, Made Our Own”, „Fridge Clear-Out”, miesięczny „Calendar Challenge”; archiwum 122+ wyzwań | nagrody, masterclassy, hasztag jako mechanika | [web] Cookpad blog, cookpad.com/us/challenges |
| **Kwestia Smaku** | druk przepisu i galeria zdjęć czytelników pod przepisem — czytelnicy o to prosili | media autorskie, nie społeczność | [repo] `USPRAWNIENIA.md` §5 |
| **Paprika** | tryb gotowania: ekran nie gaśnie, odhaczanie składników, podświetlony krok, kilka minutników naraz, czasy wykrywane z treści; spiżarnia z terminami | płatna aplikacja, zero społeczności | [web] paprikaapp.com, App Store |
| **Mealie / Tandoor** | planer kalendarzowy → lista zakupów; „książki przepisów”; tryb gotowania | narzędzie domowe, bez ludzi; Tandoor ma model alergenów (już opisany w #1902) | [web] mealie.io, cooklang.org |
| **Samsung Food** | sekcje „From your Saved”, „Would you make it again?” — przywołanie jednej wcześniejszej intencji | rekomendacje z zachowania | [repo] #1015 |
| **Dishtory** | nagranie głosu bliskiej osoby czytającej i objaśniającej rodzinny przepis — „pamięć głosu, gdy jej już nie będzie” | aplikacja-archiwum bez gotowania | [web] Apartment Therapy, The Kitchn |
| **Storyworth** | co tydzień jedno pytanie mailem → odpowiedź → po roku drukowana książka (także przepisy) | model płatny z góry | [web] storyworth.com |
| **KGW i samorządy** | książki kucharskie z przepisów kół: „Hefcik kulinarny” (powiat olsztyński, 2026), „Dzika kuchnia” Lasów Państwowych jako darmowy PDF, książka „Mistrzów Agro” | druk jako jednorazowy projekt urzędu | [web] madeinwm.pl, lasy.gov.pl, Kurier Lubelski |
| **Grupy FB 50+** | jeden przycisk, natychmiastowa reakcja, brak progu estetycznego; brak archiwum i wyszukiwania | algorytm, spam, boty AI | [repo] `COMPETITIVE_LANDSCAPE.md` §2 |

**Potrzeby osób 50+ przy gotowaniu i dzieleniu się** (z `AUDIENCE_50_PLUS.md`
i nowych źródeł):

- lęk przed błędem i nieodwracalnością; rodzina jako przewodnik po technologii
  („ktoś mi pomógł założyć konto” jest normą) [repo];
- motyw „na tym się znam” — gotowanie to jedyny obszar, w którym ta osoba
  jest ekspertem [repo];
- pamięć po mamie i babci jako najsilniejszy motyw emocjonalny [repo];
- starsze osoby gotują z przepisu pisanego tylko w ok. 20% posiłków, resztę
  z pamięci — przepis rodzinny istnieje głównie w głowie i w głosie
  [web, badanie „Capturing family recipes…”, streszczenie z wyników
  wyszukiwania; pełny tekst zwrócił 403];
- osoby z pogorszonym wzrokiem używają czytania na głos do przepisów,
  wolniej niż domyślnie (0,8–0,9×); w badaniu partycypacyjnym starsi
  użytkownicy asystentów kuchennych mieli kłopot z potwierdzaniem, powtarzaniem
  i poprawianiem rozmowy głosowej [web, Springer 2023 i FreeTTS — streszczenia
  z wyników, pełne teksty niedostępne];
- wejście przez instytucje: ~19 000 KGW, UTW, Kluby Rozwoju Cyfrowego —
  z potrzebą materiałów drukowanych i dokumentowania działalności [repo].

---

## 4. Propozycje — ranking wartość/koszt

Wartość 1–5 (wpływ na „Ugotowałem”, powroty i zaufanie 50+), koszt S=1, M=2,
L=3 w obecnym stacku. Wskaźnik = wartość / koszt.

| # | Funkcja | Wartość | Koszt | Wskaźnik | Kolizja z decyzjami | Kolejność |
|---|---|---|---|---|---|---|
| F1 | „Jak wyszło?” po trybie gotowania | 5 | S | 5,0 | brak | **najpierw** |
| F2 | Plakietka „autorka przepisu” w rozmowie | 4 | S | 4,0 | brak | **najpierw** |
| F4 | Ściągawka do wydruku dla pomocnika | 4 | S | 4,0 | brak | zaraz potem |
| F6 | Wspomnienia z własnych wykonań | 3 | S | 3,0 | brak (ten sam wyłącznik) | zaraz potem |
| F9 | „Z Twojego zeszytu” w liście tygodniowym | 3 | S | 3,0 | **bramka #1015** | po pomiarze |
| F8 | „Przeczytaj krok na głos” | 3 | S | 3,0 | **graniczy z #1906** | po decyzji |
| F3 | „Ugotujmy razem” — przepis tygodnia | 5 | M | 2,5 | brak; V1 „wyzwania” | **najpierw** |
| F5 | „Wskazówka od gotujących” w przepisie | 4 | M | 2,0 | brak (nie AI, nie ranking) | druga fala |
| F7 | Zeszyt jako książka do druku | 4 | M | 2,0 | brak; V1 „rodzinna książka” | druga fala |
| F10 | Karta zaproszenia z QR na zajęcia | 4 | M | 2,0 | brak | przed akcją w KGW/UTW |
| F11 | „Dziękuję” jednym dotknięciem pod komentarzem | 2 | S | 2,0 | brak | drobiazg |
| F12 | Pytanie do autora pod przepisem | 3 | M | 1,5 | **nakłada się na „Poradźcie”** | po decyzji |
| F14 | Prywatny „Mój rok w kuchni” | 2 | S | 2,0* | ton: „Żadnych podsumowań roku z animacją” | ostrożnie, styczeń |
| F13 | Głos babci — nagranie przy przepisie rodzinnym | 4 | L | 1,3 | brak zakazu; wysoka moderacja | po bramce V1 |

\* F14 ma wysoki wskaźnik, ale niską pewność wartości i ryzyko porównywania —
dlatego w kolejności stoi niżej, niż wynikałoby z liczby.

F3 ma wskaźnik 2,5, a jest w trójce: to jedyna propozycja, która tworzy
**nowe** wykonania u wielu osób naraz (efekt „aktywności grupowej” ze Stravy
opisany w `COMPETITIVE_LANDSCAPE.md` §3: +95–121% reakcji), a nie tylko
podnosi odsetek istniejących.

---

## 5. Karty funkcji

### F1. „Jak wyszło?” — domknięcie trybu gotowania

- **Problem.** Na ostatnim kroku trybu gotowania stoi przycisk „Ugotowałem”
  (`cooking.blade.php`, sekcja `cook-finish`), ale w kuchni ręce są mokre,
  obiad trzeba podać, a telefon gaśnie po wyjściu z trybu. Kto nie kliknął
  wtedy, nie ma drugiej okazji — autor nie dostaje najcenniejszego
  powiadomienia w serwisie.
- **Dowód.** Cookpad definiuje cooksnap jako podziękowanie autorowi po
  ugotowaniu [web]; Samsung Food przywraca „Would you make it again?” jako
  jedną wcześniejszą intencję [repo, #1015]. Pętla 1 w `RETENTION_LOOPS.md`
  jest „⭐ główna”. Liczba „tryb gotowania doszedł do ostatniego kroku” vs
  „powstało wykonanie” nie jest dziś mierzona — **to trzeba zmierzyć jako
  pierwszy krok** (hipoteza [wiedza]: rozjazd jest duży).
- **Jak działa.** Gdy osoba zalogowana doszła do ostatniego kroku, a w ciągu
  doby nie zapisała wykonania tego przepisu, na `/home` raz pojawia się jedno
  zdanie: „Wczoraj gotowałaś sernik Marka. Jak wyszło?” i przycisk
  „Ugotowałam” (forma według D-332) oraz „Nie teraz”. Pokazuje się **raz**
  na przepis, znika po odpowiedzi albo po 3 dniach. Bez maila, bez pusha.
- **„Ugotowałem”.** Bezpośrednio: więcej wykonań = więcej powiadomień autora.
- **Koszt: S.** Dane już są: postęp kroków żyje w sesji
  (`CookingModeController::sessionKey()`) albo w `cooking_progress` (#2016).
  Wariant najtańszy: znacznik w sesji „doszła do końca” z datą. Wariant
  międzyurządzeniowy (tabela znaczników) to M i wymaga migracji z rollbackiem
  (D-088) — nie od razu.
- **Ryzyka.** Prywatność: tylko własne dane widza, nic nie widzi nikt inny.
  Złożoność 50+: jedno zdanie, dwa przyciski ≥ 48 px, „Nie teraz” bez
  wyrzutu. Ton: nigdy „Nie zapomnij!” (`RETENTION_LOOPS.md` §3.3 pkt 3).
  Współdzielony tablet: sesja jest per konto, więc cudzy przepis nie
  wyskoczy innej osobie.
- **Metryka.** `wykonania / sesje trybu gotowania doprowadzone do ostatniego
  kroku` — przed i po; cel: +30% względnie. Kontrola: odsetek „Nie teraz”
  (jeśli > 50%, zdanie jest nachalne).
- **Kolizje.** Brak. Nie jest dobieraniem treści (§8) — to przypomnienie
  o własnej czynności widza, jak wspomnienia.

### F2. Plakietka „autorka przepisu” w rozmowie pod przepisem

- **Problem.** Gdy autorka odpowiada pod swoim przepisem albo pod cudzym
  wykonaniem swojego przepisu, jej komentarz wygląda jak każdy inny. Osoba,
  która ugotowała, nie widzi, że odpisała jej *ta* osoba.
- **Dowód.** `RETENTION_LOOPS.md` pętla 1, wiersz „Nagroda”: „odpowiedź od
  autorki przepisu, wyróżniona plakietką” — i dopisek: „osobna, jeszcze nie
  zbudowana mechanika z `SOUL.md` §4.2”. `AUDIENCE_50_PLUS.md` §4.2:
  „»Marek napisał: wyszło idealnie« bije »37 polubień«”. Grep po
  `resources/views/components/comment-thread.blade.php` nie znalazł plakietki.
- **Jak działa.** Obok imienia w komentarzu jedno słowo tekstem (nie ikoną):
  „autorka przepisu” / „autor przepisu” (forma z D-332). Tylko pod
  przepisem i pod wykonaniami tego przepisu. Bez liczników, bez kolorów
  rywalizacji.
- **„Ugotowałem”.** Nagradza osobę, która ugotowała — to ona widzi plakietkę
  w odpowiedzi. Zwiększa szansę na drugie wykonanie tego autora.
- **Koszt: S.** Porównanie `comment.user_id` z `recipe.user_id`, jeden
  komponent Blade, test widoku. Bez migracji.
- **Ryzyka.** Konto zamknięte / wymazane autora: plakietka znika razem
  z imieniem (ta sama bramka co dziś). „Moja wersja”: plakietkę dostaje autor
  wersji, nie oryginału — trzeba to nazwać w teście.
- **Metryka.** `% wykonań z odpowiedzią autora w 24 h` (cel ≥ 70% z pętli 1)
  i `2. wykonanie tego samego autora w 30 dni` (cel ≥ 30%).
- **Kolizje.** Brak. To nie odznaka za wolumen (§12), tylko opis roli.

### F3. „Ugotujmy razem” — przepis tygodnia wybrany przez gospodarza

- **Problem.** Tag tygodnia zbiera *wpisy* wokół tematu, ale nie ma momentu,
  w którym wiele osób gotuje **ten sam przepis** i widzi nawzajem swoje
  wykonania. Tymczasem „Ugotowałem” jest silne dopiero, gdy ktoś inny je robi.
- **Dowód.** Cookpad prowadzi stałe wyzwania (we wrześniu 2026: „Recipes
  Passed Down, Made Our Own”, „Fridge Clear-Out”, miesięczny kalendarz;
  archiwum 122+) [web]. Strava: aktywności grupowe dostają 95–121% więcej
  reakcji, a reakcje zwiększają liczbę publikacji [repo,
  `COMPETITIVE_LANDSCAPE.md` §3]. V1 w `docs/FEATURES.md` wymienia
  „wyzwania społecznościowe”; słownik `SOUL.md` §5 zamienia „wyzwanie” na
  język Kukinga.
- **Jak działa.** Gospodarz raz w tygodniu wskazuje jeden publiczny przepis
  (panel jak `tag-tygodnia`). Na `/home` blok: „W tym tygodniu gotujemy razem:
  gołąbki Basi” + „Zobacz przepis” + galeria wykonań z tego tygodnia
  **chronologicznie**. Autor dostaje zwykłe powiadomienia „Ugotowałem” —
  nic więcej. Po tygodniu blok znika, a strona „Gotowaliśmy razem” zostaje
  jako archiwum (pętla roczna z `RETENTION_LOOPS.md` pętla 5).
- **„Ugotowałem”.** To jest funkcja *o* „Ugotowałem”: jedyną akcją udziału
  jest zapis wykonania.
- **Koszt: M.** Schemat podobny do `tag_highlights` (#18): tabela
  wyróżnień przepisu z datami od–do, panel admina, blok na `/home`, lista
  wykonań filtrowana po oknie dat. Migracja + rollback + `docs/DATABASE.md`.
  Za flagą, jak tag tygodnia.
- **Ryzyka.** Cold start: pusty blok zawstydza — ta sama reguła co przy tagu
  tygodnia: gospodarz gotuje pierwszy, a przy < 3 wykonaniach do środy prosi
  osobiście (`COLD_START.md`). Faworyzowanie autorów: jawnie „wybór
  gospodarza” (§8 dopuszcza), rotacja autorów zapisana jako zasada redakcji.
  Moderacja: bez zmian — wykonania już przechodzą bramki. 50+: jeden blok,
  jeden przycisk, bez odliczania czasu i bez nagród.
- **Metryka.** `wykonania przepisu tygodnia / WAC` (cel ≥ 15%), `osoby
  z ≥ 1 wykonaniem w 3 kolejnych tygodniach`, `nowe obserwowania autora
  w tygodniu wyróżnienia`.
- **Kolizje.** §8: dozwolone jako „wybór gospodarza, oznaczony
  w interfejsie”. Galeria nie może sortować po reakcjach (D-275). Bramka V1
  w `docs/ROADMAP.md` wymienia „Planner/groups/forks”, nie wyzwania — ale
  warto potwierdzić u właściciela, że to nie „grupa” w rozumieniu #22.

### F4. Ściągawka do wydruku dla pomocnika

- **Problem.** Córka albo wnuk zakłada konto, odjeżdża, a po tygodniu osoba
  nie wie, jak wejść. Prowadząca zajęcia w bibliotece nie ma kartki do
  rozdania.
- **Dowód.** `AUDIENCE_50_PLUS.md` §3 i wniosek 6: „Zaprojektuj ścieżkę »ktoś
  mi pomógł założyć konto« jako funkcję… podsumowanie do wydruku/wysłania”.
  Badanie małopolskie: preferowana forma nauki — stacjonarnie,
  z indywidualnym wsparciem [repo]. W kodzie brak takiego ekranu (grep
  „Pomóż komuś” bez trafień w widokach).
- **Jak działa.** W `/ustawienia` i na ekranie „Gotowe” po rejestracji:
  „Wydrukuj ściągawkę”. Jedna kartka A4 dużym drukiem: adres kukinga, nazwa
  użytkownika, adres e-mail konta (częściowo zasłonięty), „jak wejść bez
  hasła” (link na e-mail / kod), „jak dodać zdjęcie obiadu” w 3 krokach,
  gdzie są ustawienia czytelności. **Bez hasła i bez żadnego tokenu.**
- **„Ugotowałem”.** Pośrednio: osoba, która umie wrócić, może ugotować
  i odpowiedzieć.
- **Koszt: S.** Widok Blade + arkusz druku jak `wydruk-przepisu.css`.
- **Ryzyka.** Prywatność: kartka leży na stole — dlatego bez hasła, tokenu
  i pełnego e-maila. 50+: nie infantylizować (nie „jak trzymać telefon”).
- **Metryka.** `% kont z drugą sesją w 7 dni` dla kont, które wydrukowały
  ściągawkę, vs reszta; `prośby o link logowania w 14 dni` (powinny rosnąć,
  bo człowiek wie, że może).
- **Kolizje.** Brak.

### F5. „Wskazówka od gotujących” — autor dopisuje uwagę z wykonania

- **Problem.** `changes_note` („Co zmieniłem”) w wykonaniach to najcenniejsza
  wiedza o przepisie, a ginie w galerii. Autor chce powiedzieć „Marek dodawał
  chrzan — dobra myśl”, ale musi przepisywać to ręcznie.
- **Dowód.** `RETENTION_LOOPS.md` pętla 1, „Inwestycja”: „Autorka dopisuje
  uwagę do przepisu… → przepis mądrzeje”. `SOUL.md` §6: zamiast gwiazdek —
  „uwagi z wykonań”. #1999 chce to samo robić AI — D-333 to wstrzymało;
  ludzka wersja nie potrzebuje modelu.
- **Jak działa.** Na ekranie „Komuś wyszło” i przy karcie wykonania autor ma
  „Dodaj do przepisu jako wskazówkę”. Tekst trafia do przepisu w sekcji
  „Wskazówki od gotujących” z podpisem „— Marek, ugotował 12 marca” i jest
  częścią treści przepisu (wersjonowany jak reszta, #2024). Autor może go
  poprawić albo usunąć. Kucharz dostaje powiadomienie w serwisie: „Basia
  dodała Twoją wskazówkę do przepisu na pierogi”.
- **„Ugotowałem”.** Wykonanie staje się trwałym wkładem w przepis — najmocniejsza
  nagroda dla kucharza obok podziękowania.
- **Koszt: M.** Nowa tabela wskazówek (albo pole w przepisie) z odnośnikiem
  do wykonania, migawka wersji, eksport RODO obu stron, nowy typ powiadomienia.
- **Ryzyka.** Prawa do treści: tekst kucharza wchodzi do cudzego przepisu —
  potrzebna zgoda przy zapisie wykonania albo powiadomienie z „Usuń moją
  wskazówkę” (kucharz musi móc ją wycofać). Wymazanie konta kucharza: podpis
  zamienia się na „osoba, która ugotowała”, jak przy innych treściach. Moderacja:
  zgłoszenie wskazówki jak komentarza.
- **Metryka.** `% przepisów z ≥ 1 wskazówką` wśród tych z ≥ 3 wykonaniami;
  `ponowne wykonania przez kucharzy, których wskazówkę dodano`.
- **Kolizje.** Nie AI (D-333). Nie ranking (§8): kolejność wskazówek po
  dacie dodania, wybór należy do autora *własnego* przepisu.

### F6. Wspomnienia z własnych wykonań

- **Problem.** Wspomnienia pokazują tylko *wpisy* sprzed roku
  (`app/Domain/Wspomnienia/Wspomnienia.php`, grep po `cooked` bez trafień).
  „Rok temu ugotowałaś pierogi Basi” nie wraca nigdy.
- **Dowód.** `RETENTION_LOOPS.md` pętla 7 („Rok temu gotowałaś powidła.
  Znowu sezon.”) i `SOUL.md` §4.6 „Przypomnienie sezonowe z własnego
  archiwum… najsilniejszy trigger w całym produkcie”.
- **Jak działa.** Ten sam blok i te same reguły co dziś (jeden element, ten
  sam dzień, cichy podpis), ale źródłem może być też własne wykonanie. Pod
  nim „Ugotuj znowu” (prowadzi do przepisu) i „Ukryj to wspomnienie”.
- **„Ugotowałem”.** Powtórne wykonanie = drugie powiadomienie autora
  („zdarzenia, nie stan”, `AGENTS.md` §1).
- **Koszt: S.** Drugie zapytanie w klasie `Wspomnienia`, kolumna „ukryj
  jako wspomnienie” na wykonaniu (migracja z rollbackiem) albo wspólna tabela
  ukryć.
- **Ryzyka.** Wspomnienie może boleć (przepis po zmarłej osobie) — dlatego
  ten sam globalny wyłącznik i ukrywanie pojedyncze. Przepis autora, który
  zniknął lub zablokował: wspomnienie nie pokazuje się (bramki widoczności).
- **Metryka.** `CTR „Ugotuj znowu”`, `% ukrytych wspomnień` (> 10% = za
  nachalne, próg z pętli 7).
- **Kolizje.** Brak; nie jest to sezon z D-026, tylko własne archiwum.

### F7. Zeszyt jako książka do druku

- **Problem.** Rodzina chce na święta wydrukować „przepisy babci”; koło
  gospodyń potrzebuje zbioru do sprawozdania albo na konkurs. Dziś drukuje się
  przepis po przepisie.
- **Dowód.** KGW wydają książki z przepisów (powiat olsztyński 2026, Lasy
  Państwowe jako darmowy PDF, „Mistrzowie Agro”) [web]; Storyworth i Mixbook
  sprzedają drukowane rodzinne książki [web]. `COMPETITIVE_LANDSCAPE.md` §3:
  model przychodu „oparty na trwałości (rodzinna książka, eksport, druk)”.
  V1: „rodzinna książka”, pierwszy krok zrobiony (D-302).
- **Jak działa.** Przy zeszycie (własnym lub wspólnym): „Przygotuj do druku”.
  Strona HTML A4: okładka z tytułem zeszytu, spis treści, każdy przepis
  z historią („po kim”, „w rodzinie od”), autorem, składnikami, krokami
  i — opcjonalnie — jednym zdjęciem wykonania od kogoś z rodziny. Drukowanie
  przeglądarką („Zapisz jako PDF”), bez generatora PDF na serwerze.
- **„Ugotowałem”.** Książka pokazuje „ugotowało 7 osób z rodziny” przy
  przepisie — dowód, że przepis żyje.
- **Koszt: M.** Widok, arkusz druku (jest wzór: `resources/css/wydruk-przepisu.css`
  i `resources/views/exports/styles.blade.php`), bramki widoczności każdej
  pozycji dla widza.
- **Ryzyka.** Cudze przepisy w wydruku: tylko to, co widz i tak widzi,
  z podpisem autora (`SOUL.md` §4.10 „Kolekcje nie ukrywają autora”).
  Obietnica marki: nie pisać „nie zginie” (D-333: claim wstrzymany do
  przetestowanego odtworzenia). Monetyzacja druku to osobna decyzja —
  darmowy wydruk nie może później zniknąć (lekcja NK.pl).
- **Metryka.** `zeszyty wydrukowane`, `zaproszenia do zeszytu wspólnego
  w 14 dni po wydruku`.
- **Kolizje.** Brak.

### F8. „Przeczytaj krok na głos” (bez mikrofonu)

- **Problem.** Przy słabszym wzroku i zaparowanych okularach krok trudno
  przeczytać z odległości blatu.
- **Dowód.** Osoby z AMD używają czytania na głos do przepisów przy
  0,8–0,9× szybkości [web, FreeTTS — źródło popularne, słabe]. Badanie
  partycypacyjne 2023: starsi użytkownicy mieli trudność z potwierdzaniem
  i poprawianiem rozmowy głosowej [web, Springer] — **argument za samym
  czytaniem, bez rozpoznawania mowy**.
- **Jak działa.** W trybie gotowania przycisk „Przeczytaj ten krok”
  (`speechSynthesis` w przeglądarce, polski głos systemu, tempo nieco
  wolniejsze). Bez JavaScriptu albo bez polskiego głosu przycisk się **nie
  pokazuje** (D-053: bez martwych przycisków).
- **„Ugotowałem”.** Mniej porzuconych trybów gotowania → więcej dojść do
  ostatniego kroku (F1).
- **Koszt: S.** Kilkadziesiąt linii JS, test przeglądarkowy.
- **Ryzyka.** Brak nagrań, brak mikrofonu, nic nie wychodzi do serwera
  (synteza lokalna w większości przeglądarek; na części Androida głos
  sieciowy Google — to trzeba napisać przy przycisku). Jakość polskiego głosu
  bywa słaba [wiedza].
- **Metryka.** `% sesji trybu gotowania z użyciem czytania` i odsetek
  dojść do ostatniego kroku w tych sesjach.
- **Kolizje.** **Graniczy z #1906** („głosowy tryb gotowania”, „V2, ale nie
  teraz”), którego zakres obejmuje „powtórz instrukcję” jako *komendę*.
  Czytanie na przycisk nie używa mikrofonu ani rozpoznawania mowy, ale
  **wymaga potwierdzenia właściciela**, że nie jest częścią zakazu D-331.

### F9. „Z Twojego zeszytu” w tygodniowym liście

- **Problem.** Zeszyt zamienia się w cmentarz zakładek; list tygodniowy mówi
  o cudzych wykonaniach i obserwujących, ale nie o tym, co człowiek sam
  chciał ugotować.
- **Dowód.** `RETENTION_LOOPS.md` pętla 3; #1015 (Samsung Food „From your
  Saved”).
- **Jak działa.** Jedna pozycja w istniejącym liście (nie nowy mail):
  „Zapisałaś w zeszycie: sernik Marka — robi się 1,5 godziny”. Najstarszy
  jeszcze nieugotowany zapis, jeden na tydzień.
- **„Ugotowałem”.** Konwersja zapis → wykonanie.
- **Koszt: S** po pomiarze.
- **Ryzyka.** #1015 ostrzega: pozycja z zeszytu sprawiłaby, że list, który
  byłby pusty (i nie wyszedłby, D-057), przychodziłby co tydzień bez końca.
  Dlatego: pozycja z zeszytu **nie może sama uzasadnić wysłania listu**.
- **Metryka.** `save → cooked w 30 dni` (cel ≥ 15%) — przed i po.
- **Kolizje.** **Bramka #1015: najpierw pomiar pętli, potem decyzja.**

### F10. Karta zaproszenia z kodem QR na zajęcia KGW / UTW / bibliotek

- **Problem.** Wejście przez instytucje to najlepszy kanał
  (`AUDIENCE_50_PLUS.md` §5.3–5.5), a zaproszenie jest dziś mailowe
  i jednoosobowe.
- **Dowód.** ~19 000 KGW, 552 UTW / 86,6 tys. słuchaczy (GUS) [repo];
  zajęcia „zapisz swoje przepisy” jako scenariusz warsztatu [repo].
- **Jak działa.** Gospodarz (albo zaufana prowadząca, rola nadawana ręcznie)
  generuje pakiet kart A4/A6: każda z **osobnym** jednorazowym kodem QR do
  rejestracji i jednym zdaniem „Zrób zdjęcie obiadu i pokaż je nam”. Opcjonalnie
  wspólny zeszyt grupy zajęć, do którego karta od razu zaprasza.
- **„Ugotowałem”.** Cała grupa zna się na starcie — pierwsze wykonanie
  przepisu koleżanki z zajęć ma gwarantowaną publiczność.
- **Koszt: M.** QR jest już w zależnościach (`bacon/bacon-qr-code` w
  `composer.json`, używany przy 2FA); zaproszenia rejestracyjne istnieją
  (`RegistrationInvite`, `/zaproszenie/{token}`). Nowe: pakiet kodów,
  widok do druku, limit i wygasanie.
- **Ryzyka.** Nadużycia: kod wielorazowy krąży w internecie — dlatego kod
  jednorazowy na kartę, termin ważności, limit kart na pakiet. RODO: art. 14
  przy zaproszeniach jest otwarty jako #2219 (P1) — **najpierw #2219**.
  Moderacja: nagły napływ 20 nowych kont — gospodarz musi być na miejscu
  z „odpowiedzią w pierwszej godzinie” (`SOUL.md` §4.13).
- **Metryka.** `% kart zamienionych na konta`, `% tych kont z pierwszym
  wpisem w 7 dni`, `gęstość relacji` (obserwowania wewnątrz grupy).
- **Kolizje.** Zależność od #2219.

### F11. „Dziękuję” jednym dotknięciem pod komentarzem

- **Problem.** 65-latek nie zawsze chce pisać odpowiedź, a brak odpowiedzi
  wygląda na obojętność.
- **Dowód.** `SOUL.md` §4.8 „Podziękowanie w jednym kliknięciu” (V1).
- **Jak działa.** Pod komentarzem do swojej treści autor ma „Dziękuję”;
  komentujący widzi „Basia podziękowała”. Bez licznika.
- **„Ugotowałem”.** Słabo — dotyczy komentarzy; pod wykonaniem jest już
  „Podziękuj”.
- **Koszt: S.** Tabela reakcji na komentarz albo znacznik, powiadomienie
  zbiorcze.
- **Ryzyka.** Zastępuje prawdziwe odpowiedzi — `SOUL.md`: nie liczyć do
  wskaźnika odpowiedzi.
- **Metryka.** `% komentarzy z reakcją autora` i — kontrolnie — czy odsetek
  odpowiedzi tekstowych nie spada.
- **Kolizje.** Brak (bez licznika publicznego, §12).

### F12. Pytanie do autora pod przepisem

- **Problem.** Pytanie „ile mąki, jeśli jajka są małe?” ginie w komentarzach;
  autor nie wie, że na nie czeka ktoś przy kuchence.
- **Dowód.** `RETENTION_LOOPS.md` pętla 8 („osoba, której nikt o nic nie pytał
  od czasu emerytury”); `SOUL.md` §4.8.
- **Jak działa.** Komentarz pod przepisem można oznaczyć „To pytanie do
  autora”; autor dostaje wyróżnione powiadomienie; odpowiedź autora stoi przy
  pytaniu z plakietką (F2). Bez publicznego „bez odpowiedzi od 30 dni”.
- **„Ugotowałem”.** Odblokowuje wykonanie, które inaczej by nie powstało.
- **Koszt: M.** Znacznik na komentarzu (kolumna sterująca — **poza
  `$fillable`**, jak `kind` wpisu), powiadomienie, sekcja na stronie przepisu.
- **Ryzyka.** Dwa miejsca na pytania („Poradźcie” i pod przepisem) mylą 50+
  (`AUDIENCE_50_PLUS.md`: „jedna ścieżka, zero wariantów”).
- **Metryka.** `% pytań z odpowiedzią w 48 h` (cel ≥ 80%).
- **Kolizje.** **Nakłada się na „Poradźcie” (#372)** — wymaga decyzji, czy
  pytanie pod przepisem to osobny byt, czy „Poradźcie” z odnośnikiem do
  przepisu.

### F13. Głos babci — krótkie nagranie przy przepisie rodzinnym

- **Problem.** Rodzinny przepis istnieje głównie w pamięci i w głosie
  („na oko”, „aż się zrobi szkliste”). Zeszyt i skan kartki tego nie
  zachowują.
- **Dowód.** Dishtory buduje na tym cały produkt [web]; badanie „Capturing
  family recipes…”: starsi gotują z przepisu pisanego w ok. 20% posiłków
  [web, streszczenie]. `AUDIENCE_50_PLUS.md` §4.3: pamięć po mamie i babci
  jako najsilniejszy motyw.
- **Jak działa.** Autor przepisu może dołączyć jedno nagranie do 2 minut
  w sekcji „Skąd ten przepis”. Odtwarzanie przyciskiem z tekstem. Widoczność
  jak przepis; w zeszycie rodzinnym — tylko dla rodziny.
- **„Ugotowałem”.** Pośrednio: rodzina gotuje „tak jak mówiła mama”.
- **Koszt: L.** Nowy typ mediów w potoku (`docs/MEDIA_PIPELINE.md`),
  walidacja formatu, transkodowanie (ffmpeg w obrazie — nowa zależność
  systemowa), R2, eksport RODO, usuwanie.
- **Ryzyka.** Moderacja nagrań jest dużo droższa niż zdjęć (DSA — trzeba
  odsłuchać); dane biometryczne głosu osoby trzeciej (zmarłej lub żyjącej)
  — pytanie do prawnika (#8); transkrypcja AI zakazana teraz (D-333).
- **Metryka.** `% przepisów rodzinnych z nagraniem`, `zaproszenia do zeszytu
  po dodaniu nagrania`.
- **Kolizje.** Brak zakazu wprost, ale koszt moderacji to dokładnie argument
  z „Nie wcześnie” („Wysoki koszt moderacji… nie są potrzebne do udowodnienia
  wartości”). Proponuję po bramce V1 (WAC/D30).

### F14. Prywatny „Mój rok w kuchni”

- **Problem.** Dorobek („212 dań”) jest cenny, ale nigdzie nie jest
  opowiedziany.
- **Dowód.** `SOUL.md` §6: „Licznik dorobku („212 dań”), który nigdy nie
  spada” zamiast serii; Ravelry — archiwum projektów jako kotwica [repo].
- **Jak działa.** W styczniu, tylko dla właściciela konta, na `/home` jedno
  zdanie i link do prostej strony: ile dań pokazałaś, ile razy ktoś ugotował
  z Twoich przepisów i kto (imiona), trzy przepisy, które gotowałaś
  najczęściej. Bez animacji, bez porównań, bez udostępniania.
- **„Ugotowałem”.** Pokazuje autorowi, że jego przepisy żyją.
- **Koszt: S.** Zapytania na istniejących tabelach, jeden widok.
- **Ryzyka.** Komentarz w `Wspomnienia.php`: „Żadnych podsumowań roku
  z animacją” — trzymać się tego dosłownie. Mała liczba może zawstydzać —
  nie pokazywać, gdy rok był pusty (zasada pustych stanów z Wspomnień).
- **Metryka.** `CTR`, `wpis w ciągu 7 dni po obejrzeniu`.
- **Kolizje.** Brak zakazu; to nie ranking ani punkty (§12), ale granica
  tonu jest cienka — do oceny właściciela.

---

## 6. Co zrobić najpierw i dlaczego

1. **F1 „Jak wyszło?”** — najtańsza droga do większej liczby wykonań, bez
   migracji. Przed wdrożeniem dodać do `kuking:raport` licznik „dojścia do
   ostatniego kroku” vs „wykonania w 24 h”, żeby było z czym porównać.
2. **F2 plakietka autora** — pół dnia pracy, domyka opisaną, a niezbudowaną
   nagrodę głównej pętli.
3. **F3 „Ugotujmy razem”** — jedyna propozycja, która tworzy wspólne
   gotowanie; wymaga gospodarza przy pierwszych tygodniach.

Zaraz za nimi F4 (ściągawka) i F6 (wspomnienia z wykonań) — obie S, obie bez
decyzji. Do decyzji właściciela: F8 (czy czytanie na głos jest poza #1906),
F12 (pytania pod przepisem vs „Poradźcie”), F3 (czy to nie „grupa” z #22).

---

## 7. Czego ten research nie sprawdził

- Liczb z produkcji (serwis nie ma prawdziwych użytkowników — D-333) —
  wszystkie cele metryk to progi z `RETENTION_LOOPS.md` albo hipotezy.
- Grup na Facebooku 50+ wprost (logowanie wymagane) — opieram się na
  `COMPETITIVE_LANDSCAPE.md` §2.
- Pełnych tekstów badań Springer 2023 i „Capturing family recipes…” (403)
  — używam streszczeń z wyników wyszukiwania i tak je oznaczam.
- Jakości polskich głosów `speechSynthesis` na tanich Androidach (F8) —
  do sprawdzenia na urządzeniu.

---

## Źródła

Web (dostęp 29.09.2026):

- Cookpad, „A Cooksnap is worth 1,000 Words!” (27.07.2020):
  https://blog.cookpad.com/us/a-cooksnap-is-worth-1-000-words/
- Cookpad, lista wyzwań: https://cookpad.com/us/challenges
- Cookpad, wytyczne społeczności: https://cookpad.com/us/community
- Paprika — przewodnik: https://www.paprikaapp.com/help/ios/ ,
  App Store: https://apps.apple.com/us/app/paprika-recipe-manager-3/id1303222868
- Mealie — funkcje: https://mealie.io/documentation/getting-started/features/
- Cooklang, „Tandoor vs Mealie vs KitchenOwl” (2026):
  https://cooklang.org/blog/42-tandoor-vs-mealie-vs-kitchenowl/
- Apartment Therapy o Dishtory:
  https://www.apartmenttherapy.com/dishtory-family-recipe-app-37000810
- The Kitchn o Dishtory: https://www.thekitchn.com/family-recipe-app-23286123
- „Capturing family recipes for digital sharing across the generations”:
  https://www.academia.edu/4774036/Capturing_family_recipes_for_digital_sharing_across_the_generations
- „Designing Multi-Modal Conversational Agents for the Kitchen with Older
  Adults” (International Journal of Social Robotics, 2023):
  https://link.springer.com/article/10.1007/s12369-023-01055-4
- „A Recipe for Success? Exploring Strategies for Improving Non-Visual Access
  to Cooking Instructions” (ASSETS 2024): https://dx.doi.org/10.1145/3663548.3675662
- FreeTTS, czytanie na głos dla seniorów: https://freetts.org/text-to-speech-for-seniors
- Storyworth, rodzinna książka:
  https://welcome.storyworth.com/blog/how-to-create-a-family-history-book-with-celebrations
- Mixbook, książki z przepisami: https://www.mixbook.com/recipe-cookbook-photo-books
- „Hefcik kulinarny” (KGW, powiat olsztyński): https://madeinwm.pl/hefcik-kulinarny-kuchnia-warminska/
- Lasy Państwowe, KGW i „Dzika kuchnia”:
  https://www.lasy.gov.pl/pl/informacje/aktualnosci/kola-gospodyn-wiejskich-promuja-zdrowa-zywnosc-z-polskich-lasow
- Kurier Lubelski, książka „Mistrzów Agro 2024”:
  https://kurierlubelski.pl/mistrzowie-agro-2024-przepisy-kol-gospodyn-wiejskich-trafia-do-wyjatkowej-ksiazki-kucharskiej/ar/c8-18511331

Repozytorium: `AGENTS.md`, `docs/FEATURES.md`, `docs/ROADMAP.md`,
`docs/DECISIONS.md` (D-021, D-026, D-057, D-081, D-275, D-303, D-304,
D-331, D-333), `docs/research/COMPETITIVE_LANDSCAPE.md`,
`docs/research/AUDIENCE_50_PLUS.md`, `docs/research/USPRAWNIENIA.md`,
`docs/product/SOUL.md`, `docs/product/RETENTION_LOOPS.md`,
`docs/product/COLD_START.md`; issues #15, #22, #372, #1015, #1045, #1756,
#1902, #1903, #1904, #1906, #1983, #1999, #2067, #2219.
