# Triaż audytu UI/UX z 11 września 2026

**Materiał:** `kuking-ui-ux-audit/AUDYT_UI_UX_KUKING.md` (1050 wierszy),
`PLAN_WDROZENIA.md`, `README.md`, sześć wizualizacji.
**Commit audytowany:** `343029b566b2a5bca812d3039ea22b88c3ece050`.
**Commit, na którym prowadzono triaż:** ten sam — `git rev-list --count 343029b..origin/main`
zwraca `0`, więc audyt opisuje dokładnie dzisiejszy `main`. Żadne znalezisko
nie może być „nieaktualne, bo minęło kilka commitów" — może być nieaktualne
wyłącznie wtedy, gdy poprawka weszła **przed** tym commitem, a audyt jej nie
zobaczył.

**Czym ten dokument NIE jest:** planem wdrożenia. Nie zmieniono tu ani jednego
pliku aplikacji. Każde znalezisko dostaje stan i dowód z kodu; propozycje
wpisów do dziennika decyzji stoją jako `D-???` i czekają na właściciela.

**Uwaga o kształcie audytu.** Audyt nie ma numerowanej listy znalezisk — jest
napisany jako kierunek redesignu z rozdziałami. Identyfikatory poniżej biorę
z jego własnej numeracji rozdziałów (`P0.1`…`P0.6`, `§4`, `§5.x`, `§8`, `§9`,
`§10`, `§13`, `§14`, `§17`). Tam, gdzie jeden rozdział niesie kilka
rozłącznych twierdzeń o różnych stanach, rozbijam go na litery (`P0.4a`…).

---

## P0.1 Jedno logo i jedna nazwa wizualna

**Stan:** ŚWIADOME
**Dowód:** `docs/DECISIONS.md:376` — „**D-015 · Logotyp brzmi „KuKing.pl", teksty
dalej piszą „Kuking"**", wybrany spośród trzech wariantów, w tym odrzuconego
wprost `Kuking.pl`; realizacja w `resources/views/components/layout.blade.php:282`
i tabela trzech zapisów w `docs/brand/COPY_STYLE.md:31-38`.
**Waga:** P2 — dla nikogo; audyt proponuje dokładnie ten wariant, który
właściciel odrzucił pięć dni wcześniej, i nie zna powodu odrzucenia.
**Co zrobić:** nic. Zmiana logotypu na `Kuking.pl` wymaga cofnięcia D-015 przez
właściciela, nie PR-a „brand cleanup".

## P0.1b Trzy zapisy nazwy żyją równolegle w produkcie

**Stan:** NIEPRAWDZIWE (jako usterka)
**Dowód:** trzy zapisy są rozdzielone funkcjami, nie rozjechane: logotyp
`layout.blade.php:282`, tekst ciągły „Świeżo z Kuking" `landing.blade.php:145`,
człowiek `x-kuking-word` — i dawkowanie pilnuje kod, a nie przypadek:
`landing.blade.php:104` woła tablicę z `:graSlowem="false"`, bo gra słowem jest
na tym ekranie zużyta przez przycisk wyżej.
**Waga:** P2 — dla nikogo.
**Co zrobić:** nic.

## P0.2 Hero landingu nie pokazuje jedzenia

**Stan:** PRAWDZIWE
**Dowód:** `resources/views/pages/landing.blade.php:46-58` — sekcja hero ma
jedno dziecko `.hero-tekst` (nadtytuł, H1, lead, dwa przyciski), zero elementów
`<img>`; `resources/css/strony-publiczne.css:99` „`.hero { display: grid; }`"
z jedną kolumną i `:116` „`.hero-tekst { max-width: 34rem; }`" — czyli 544 px
treści w paśmie szerokim na `--container-strona` (1040 px, `tokens.css:205`).
**Waga:** P1 — dla osoby, która widzi Kuking pierwszy raz na komputerze: w pół
ekranu produktu o gotowaniu nie ma ani jednego zdjęcia jedzenia, a ~500 px
z prawej strony pasa stoi puste.
**Co zrobić:** dołożyć w prawej części hero kolaż 4–5 zdjęć z gotowych
wariantów `x-photo` (`srcset` już jest, `components/photo.blade.php:98-102`) —
ale nie przywracać tam tablicy dnia, bo to jest dokładnie ta zmiana, którą
wymusił `docs/research/AUDYT_60_PLUS.md:109`.

## P0.3 „Jak działa" jest siatką 2+1, trzeci kafel stoi sam

**Stan:** PRAWDZIWE
**Dowód:** `resources/css/strony-publiczne.css:160-162` — „`grid-template-columns:
repeat(2, minmax(0, 1fr))`" od `48rem` i ani jednej reguły dla `64rem`, przy
trzech `<li class="rzecz">` w `landing.blade.php:71-84`. Komentarz nad regułą
(`:145-149`) nazywa to zamierzonym i podaje powód (przy skali 140% trzy kolumny
dają dwa słowa w wierszu) — ale **nie ma na to numeru decyzji**, więc według
własnej definicji projektu to nie jest jeszcze stan ŚWIADOMY.
**Waga:** P2 — dla gościa na desktopie: sekcja wyjaśniająca produkt wygląda na
niedokończoną, choć pomiar stojący za tym układem jest sensowny.
**Co zrobić:** albo dołożyć trzecią kolumnę **dopiero od `64rem`** (powód
z komentarza dotyczy `48rem`, nie `1024 px`), albo zapisać obecny układ jako
`D-???` z tym pomiarem — dziś uzasadnienie żyje wyłącznie w komentarzu CSS.

## P0.4a „Co się dziś gotuje" siedzi w jednym wielkim obramowaniu

**Stan:** PRAWDZIWE
**Dowód:** `resources/views/components/kuking-board.blade.php:27` —
„`<section class="card kuking-board mb-6" …>`", czyli cała sekcja strony bierze
powierzchnię karty treści z `resources/css/tokens.css:768`.
**Waga:** P1 — dla gościa na landingu: blok o randze sekcji strony wygląda jak
jeden wielki rekord listy, co jest tym samym zarzutem, który audyt stawia
w `§5.3`.
**Co zrobić:** zdjąć `card` z tablicy na landingu (hierarchia przez tło pasa,
jak w pozostałych sekcjach `landing.blade.php`), zostawiając `card` tam, gdzie
tablica stoi w szynie.

## P0.4b „Nie mieszać dań z osobami w jednym `ul`"

**Stan:** NIEPRAWDZIWE
**Dowód:** `kuking-board.blade.php:45-46` — „`<h3 …>Osoby</h3>`" +
`<ul class="kuking-board-people">`, i osobno `:121-122` — „`<h3 …>Dania</h3>`" +
`<ul class="kuking-board-posts">`. Dwa nagłówki, dwie listy, zero mieszania.
**Waga:** P2 — dla nikogo; to jest rekomendacja rzeczy już zrobionej.
**Co zrobić:** nic.

## P0.4c Przy każdym daniu stoi osobny przycisk „Zobacz"

**Stan:** PRAWDZIWE
**Dowód:** `kuking-board.blade.php:160` — „`<a class="btn btn-quiet …"
href="{{ $post->url() }}">Zobacz</a>`".
**Waga:** P2 — dla osoby skanującej tablicę wzrokiem: powtórzony przycisk dodaje
szumu.
**Co zrobić:** najpewniej nic. Rekomendacja audytu („całe pole klikalne") kasuje
jedyne widoczne wejście z tekstem, a zdjęcie obok jest już świadomie wyjęte
z kolejności czytania (`:137`, `tabindex="-1" aria-hidden="true"`); „ikona nigdy
nie jest jedynym opisem ważnej akcji" (`AGENTS.md:176`) stoi po stronie obecnego
rozwiązania. Karta-link objęłaby też odnośnik do autora w środku.

## P0.4d Zdjęcie dania w tablicy jest mniejsze niż przycisk

**Stan:** PRAWDZIWE
**Dowód:** `kuking-board.blade.php:138` — „`<img src="{{ $glowne->url('thumb') }}"
alt="" width="96" height="96" …>`", czyli wariant `thumb` 96×96 px przy
przycisku `.btn` o wysokości minimum 48 px i podpisie na całą szerokość.
**Waga:** P1 — dla gościa: w serwisie o gotowaniu dowodem ma być jedzenie,
a jest miniatura wielkości awatara.
**Co zrobić:** dać daniom wariant `feed` w proporcji 4:3 z `width`/`height`
(CLS), zostawiając `thumb` tam, gdzie tablica stoi w szynie 352 px.

## P0.4e „Nie robić rankingu popularności, nie pokazywać liczby followersów"

**Stan:** ŚWIADOME
**Dowód:** `AGENTS.md` §12 — „publiczne rankingi użytkowników" na liście
anty-wzorców, których „nie wprowadzamy nigdy"; w kodzie `kuking-board.blade.php:4-6`
„To NIE jest ranking — nigdzie nie pokazujemy liczby obserwujących".
**Waga:** P2 — dla nikogo; rekomendacja stanu już obowiązującego.
**Co zrobić:** nic.

## P0.4f Stopka „Jutro będzie tu ktoś inny." ma przejść do nagłówka sekcji

**Stan:** ŚWIADOME (i dopuszczalne)
**Dowód:** `kuking-board.blade.php:167` — „`<p class="meta kuking-board-footer">Jutro
będzie tu ktoś inny.</p>`"; `:8-9` nazywa ją „częścią funkcji, nie ozdobą".
**Waga:** P2 — dla czytelnika tablicy: zdanie ma powiedzieć, że to nie jest
tabela wyników.
**Co zrobić:** wolno przenieść, **nie wolno usunąć**. Audyt przenosi je do
nagłówka (`§P0.4`, „mały tekst po prawej") i tego zakaz nie dotyczy.

## P0.5 „Świeżo z Kuking" na landingu stoi w dwóch kolumnach

**Stan:** PRAWDZIWE
**Dowód:** `resources/css/app.css:4146-4148` — „`@media (min-width: 64rem) {
.landing-wpisy { grid-template-columns: repeat(2, minmax(0, 1fr)); } }`", przy
`align-items: start` (`:4142`), czyli krótsza karta w rzędzie zostawia pod sobą
pustkę do wysokości sąsiadki.
**Waga:** P2 — dla gościa na landingu: rząd kart o różnej wysokości czyta się
jako dziury w składzie.
**Co zrobić:** **nie zmieniać bez pytania właściciela.** Komentarz nad regułą
(`app.css:4118-4137`) mówi wprost, że dwie kolumny są odpowiedzią na zgłoszenie
właściciela („jedna kolumna na tak szerokim ekranie zostawiałaby pustkę, o którą
zgłosił się właściciel") i że trzeciej kolumny świadomie nie ma. To jest ten sam
kształt co P0.3: decyzja żyje w komentarzu, nie w dzienniku — należy jej się
`D-???` niezależnie od tego, w którą stronę właściciel rozstrzygnie.

## P0.6 „Jedna szerokość 45rem jest traktowana jak szerokość strony"

**Stan:** NIEPRAWDZIWE
**Dowód:** `resources/css/tokens.css:172` — `--container-content: 45rem` jest
opisane jako „centralna kolumna, ~65–75 znaków", a szerokość STRONY to osobne
trzy tokeny: `:205` `--container-strona` (1040 px), `:209`
`--container-strona-z-szyna` (1424 px), `:221` `--container-strona-solo`
(768 px). Landing nie ma nawet sufitu (`app.css:107` `.app-body-powitalny`).
**Waga:** P1 — nie jako usterka produktu, lecz jako usterka audytu: cały
rozdział `§6` (warianty layoutu) i `§2` tabela tokenów są zbudowane na tej
pomyłce, a dwa z trzech tokenów, które audyt proponuje wprowadzić, już istnieją
i leżą nieużywane (`tokens.css:180-181`, `--container-wide` 70rem,
`--container-ultra` 92rem, oznaczone „dziś nieużywane").
**Co zrobić:** odrzucić `§6` w obecnej formie; realny problem szerokości opisują
niżej `§4/A` i `§9a`, i tylko one.

## §4 „Napisz do nas": szyna gościa ląduje pod formularzem

**Stan:** PRAWDZIWE
**Dowód:** `resources/views/pages/napisz-do-nas.blade.php:34` podaje
`<x-slot:rail>`, a `resources/css/app.css:94-97` zwija układ gościa do jednej
kolumny: „`.app-body-solo { grid-template-columns: minmax(0, 1fr); max-width:
var(--container-strona-solo); }`" (768 px). Trasa jest publiczna
(`routes/web.php:113`), więc ten stan widzi każdy niezalogowany. Komentarz nad
tą regułą (`app.css:143-145`, w bloku `80rem`) uzasadnia ją zdaniem „Gość nie ma nawigacji bocznej
ani szyny" — i **druga połowa tego zdania jest nieprawdziwa**.
**Waga:** P0 — dla osoby, która nie może się zalogować i pisze do nas
z komputera: blok „Nie możesz się zalogować" (czyli najczęstsza odpowiedź na jej
problem) stoi POD całym formularzem, na 768-pikselowej stronie na monitorze
1920 px, zamiast obok niego.
**Co zrobić:** dopuścić trzecią kolumnę dla gościa na tych stronach, które
naprawdę podają slot `rail` — dziś są trzy: `napisz-do-nas`, `search`,
`profile/show`.

## §8a `/szukaj` i publiczny profil: ta sama szyna, ten sam skutek

**Stan:** PRAWDZIWE
**Dowód:** `resources/views/pages/search.blade.php` i
`resources/views/pages/profile/show.blade.php` podają `<x-slot:rail>`; trasa
`/szukaj` jest publiczna (`routes/web.php:83-85`); zwija je ta sama reguła
`app.css:94` i `:147`.
**Waga:** P1 — dla gościa: to samo co wyżej, na dwóch kolejnych ekranach.
**Co zrobić:** jedna poprawka razem z `§4`.

## §8b `/odkryj`: tablica dnia stoi nad strumieniem i nie ma szyny

**Stan:** PRAWDZIWE
**Dowód:** `resources/views/pages/discover.blade.php:8` — `<x-kuking-board …>`
przed `@if($posts->count() === 0)` i przed pętlą kart (`:37-41`); plik nie ma
slotu `rail` w ogóle.
**Waga:** P2 — dla gościa wchodzącego w „Świeżo z Kuking": pierwsze, co widzi,
to redakcyjna tablica, a nie chronologiczny strumień, po który przyszedł.
**Co zrobić:** ewentualnie przenieść tablicę do szyny — ale dopiero po poprawce
z `§4`, bo bez niej szyna gościa i tak ląduje pod treścią.

## §8c `/login` i `/register` są jedną kolumną kart jedna pod drugą

**Stan:** PRAWDZIWE
**Dowód:** `resources/views/auth/login.blade.php:6` (`<form class="card">`),
`:36` (`<x-wejdz-google />`), `:52` (`<div class="card mt-6">` — logowanie
linkiem) — trzy karty w pionie w kolumnie 768 px.
**Waga:** P2 — dla osoby logującej się z komputera: trzy równorzędne drogi
wejścia czyta się jako trzy kolejne próby.
**Co zrobić:** ewentualnie `§8` audytu (lewa kolumna = wejście, prawa =
alternatywy). Uwaga: kolejność bloków jest tu celowa i opisana
(`login.blade.php:24-33` i `:38-49`, D-069 i D-056) — wolno zmienić układ,
nie wolno przy okazji przestawić kolejności.

## §8d Nagłówek rejestracji „Zostań kuKINGiem" → „Załóż konto"

**Stan:** ŚWIADOME
**Dowód:** `docs/brand/COPY_STYLE.md:316-318` przypisuje oba teksty wprost
(„przycisk rejestracji | Zostań kuKINGiem — to darmowe", „nagłówek rejestracji |
Zostań kuKINGiem"), a `:465` tłumaczy, dlaczego zastępuje „Załóż konto"; podstawa
to D-009 i D-015.
**Waga:** P2 — dla nikogo.
**Co zrobić:** nic bez decyzji właściciela.

## §9a Ekran bez szyny rezerwuje pustą trzecią kolumnę

**Stan:** ŚWIADOME
**Dowód:** `docs/DECISIONS.md:7158` — D-089 §1: „Zwykłe ekrany serwisu
**zachowują** trzecią, zarezerwowaną kolumnę bez zmian; to nie jest cofnięcie
decyzji z 7 września"; realizacja i uzasadnienie w `resources/css/app.css:131-137`
(„kosztem jest wolne miejsce po prawej, zyskiem to, że kolumna czytania ma zawsze
45rem, a nawigacja zawsze ten sam x (decyzja właściciela)").
**Waga:** P2 — dla zalogowanego na szerokim monitorze; koszt policzony i przyjęty.
**Co zrobić:** nic. Propozycja audytu („main dostaje większy span") była już
rozważona i przyjęta wyłącznie dla panelu moderacji.

## §9b Panel moderacji ma pustą szynę i jest ściśnięty

**Stan:** NIEAKTUALNE
**Dowód:** poprawione 10 września, przed commitem audytowanym:
`resources/css/app.css:181-184` — „`.app-body[data-tryb-panelu] {
grid-template-columns: var(--container-sidenav) minmax(0, 1fr); max-width:
var(--container-strona); }`", decyzja `docs/DECISIONS.md:7158` (D-089 §1),
test `tests/Feature/PanelSzerokiTelefonTest.php`.
**Waga:** P1 — gdyby ktoś wdrożył to jako nowe, powtórzyłby pracę sprzed doby.
**Co zrobić:** nic.

## §9c „Admin nie jest ograniczony do 720px"

**Stan:** ŚWIADOME
**Dowód:** `docs/DECISIONS.md` D-089, akapit „Czego to NIE rozstrzyga": „kolumna
czytania (`--container-content`, 45rem) zostaje wszędzie, panel włącznie —
»szerokość strony ma być identyczna na każdej podstronie« to osobna,
wcześniejsza decyzja właściciela"; szeroka tabela ma własny kontener
`resources/css/ekran-uzytkownikow.css:150` (`.tabela-kont-przewijanie`).
**Waga:** P2 — dla moderatora; rozstrzygnięte dobę przed audytem.
**Co zrobić:** nic bez decyzji właściciela.

## §5a Zbyt wiele rzeczy jest po prostu „card"

**Stan:** PRAWDZIWE
**Dowód:** `grep -rno 'class="[^"]*\bcard\b[^"]*"' resources/views/` daje **130**
wystąpień — ta sama powierzchnia niesie formularz logowania
(`auth/login.blade.php:6`), kartę wpisu (`components/post-card.blade.php`,
9 wystąpień), sekcję strony (`components/kuking-board.blade.php:27`) i blok
szyny (`components/szyna-blok.blade.php:23`).
**Waga:** P1 — dla każdego użytkownika: kształt nie niesie informacji o roli
elementu.
**Co zrobić:** rozdzielić role zaczynając od jednego przypadku, który miesza
rangi najmocniej — sekcji strony w `card` (P0.4a).

## §5b „Supporting Panel" jako brakująca warstwa

**Stan:** NIEPRAWDZIWE
**Dowód:** warstwa istnieje i ma dokładnie to uzasadnienie, które audyt
proponuje: `resources/css/app.css:3625-3629` — `.szyna-blok` zdejmuje cień,
a komentarz nad nią (`:3619-3624`) mówi „szyna NIE powtarza kolumny głównej…
przestają tylko konkurować o pierwsze spojrzenie". Różnica jest jedna
i przeciwna do rekomendacji: promień jest **celowo ten sam** co karty wpisu
(`:3612-3618`), bo dwa różne promienie w jednym rzędzie czytają się jako
niedokończone.
**Waga:** P2 — dla nikogo.
**Co zrobić:** nic; w szczególności nie zmniejszać promienia szyny, bo to cofa
świadomą poprawkę.

## §5c „Page Section" bez zewnętrznej karty

**Stan:** PRAWDZIWE — ale tylko w jednym miejscu
**Dowód:** sekcje landingu są już bez karty (`landing.blade.php:45, 67, 98, 109,
143, 169, 200` — same `<section class="pas …">`); jedynym wyjątkiem jest tablica,
`kuking-board.blade.php:27`.
**Waga:** P1 — to jest to samo znalezisko co P0.4a, nie drugie.
**Co zrobić:** jak w P0.4a.

## §10a Karta wpisu: menu „···" bez widocznego opisu

**Stan:** PRAWDZIWE (znane, potwierdzone)
**Dowód:** `resources/views/components/post-card.blade.php:66-68` —
„`<summary aria-label="Więcej przy tym wpisie"><span aria-hidden="true">···</span></summary>`",
wbrew `AGENTS.md:176` („ikona nigdy nie jest jedynym opisem ważnej akcji").
**Waga:** P1 — dla osoby 50+ patrzącej na ekran bez czytnika: trzy kropki są
jedynym opisem wejścia do „Otwórz wpis / Edytuj / Zgłoś".
**Co zrobić:** dać widoczny tekst obok znaku. **Uwaga: audyt tego nie zgłosił** —
w `§10` wymienia „menu szczegółów" wśród rzeczy do ZACHOWANIA bez zastrzeżeń.
Liczę to jako potwierdzenie znanej usterki, nie jako trafienie audytu.

## §10b Karta wpisu: „ujednolicić padding, odstępy, promień, wysokości akcji"

**Stan:** BRAK STANU — nie rozstrzygnąłem (patrz sekcja „Czego nie sprawdziłem")
**Dowód:** brak. Audyt nie podaje ani jednej zmierzonej wartości ani nazwy klasy,
a twierdzenie „padding nagłówka nie jest stały" nie da się potwierdzić ani obalić
bez wyrenderowania karty w kilku stanach.
**Waga:** nieokreślona.
**Co zrobić:** nic, dopóki nie ma pomiaru.

## §13a „UI działa bez JavaScriptu" jako twarde wymaganie

**Stan:** NIEAKTUALNE
**Dowód:** `docs/DECISIONS.md:3113` — D-053 z 9 września 2026: „JavaScript jest
wymagany na formularzach chronionych captchą, a nigdzie nie wolno zostawić
martwego przycisku"; obowiązująca wersja reguły stoi w `AGENTS.md` §5,
podrozdział „JavaScript jest wymagany tam, gdzie chroni serwis".
**Waga:** P1 — gdyby przyjąć to wymaganie z audytu, blokowałoby rejestrację
i logowanie za Turnstile (D-050), czyli cofało decyzję sprzed dwóch dni.
**Co zrobić:** nic. Obowiązuje D-053. (To samo twierdzenie audyt powtarza
w `§17` pkt 3 i w `PLAN_WDROZENIA.md` „Definition of done" — trzy razy ten sam
nieaktualny wymóg.)

## §13b Pozostałe twarde wymagania dostępności (200%, 320 px, 48 px, reduced motion, focus)

**Stan:** ŚWIADOME (są celem, nie brakiem)
**Dowód:** `AGENTS.md` §5 i `docs/UX_50_PLUS.md:5-30` wymieniają je jako twardy
standard; w kodzie m.in. `resources/css/tokens.css:481`
(`@media (prefers-reduced-motion: reduce)`), `components/photo.blade.php:98-102`
(`srcset` z prawdziwych szerokości), a pomiar całości robi
`scripts/dostepnosc.mjs` (D-099, D-106).
**Waga:** P2 — dla nikogo; audyt sam nazywa je „czego redesign nie może zepsuć".
**Co zrobić:** nic. Duży tekst i duże przyciski są CELEM (`docs/UX_50_PLUS.md`),
a nie skutkiem przeoczenia.

## §14 `app.css` jest bardzo duży, warto go rozdzielić

**Stan:** PRAWDZIWE — z połową roboty już zrobioną
**Dowód:** `wc -l resources/css/*.css`: `app.css` ma **4285** wierszy przy 6635
w całym katalogu. Ale osobne arkusze ekranowe już istnieją i jest ich osiem
(`ekran-dodawania.css`, `ekran-odwolan.css`, `ekran-profilu.css`,
`ekran-uzytkownikow.css`, `ekran-wyszukiwania.css`, `karta-ugotowania.css`,
`strony-publiczne.css`, `wpis-nawigacja-sasiedzi.css`), więc proponowany podział
na `layouts/`, `components/`, `pages/` jest trzecią konwencją nazw, nie pierwszą.
**Waga:** P2 — dla osoby, która będzie tu pracować.
**Co zrobić:** jeśli dzielić, to **dalej według obecnej konwencji `ekran-*`/
`karta-*`**, a nie według katalogów z audytu — inaczej w jednym katalogu staną
dwa systemy nazw.

## §17/§18 „Co świadomie zostawić" i „Czego nie robić"

**Stan:** ŚWIADOME
**Dowód:** pokrywa się z `AGENTS.md` §8 (feed chronologiczny), §12 (brak
rankingów, gamifikacji, algorytmicznego feedu), `docs/UX_50_PLUS.md` (duży tekst
i duże przyciski, brak ukrytych gestów), D-004 („Ugotowałem" ponad lajkiem).
Jedyny punkt nieaktualny to `§17` pkt 3 — patrz `§13a`.
**Waga:** P2 — dla nikogo.
**Co zrobić:** nic.

---

## Tabela zbiorcza

| Stan | Ile | Które |
|---|---:|---|
| **PRAWDZIWE** | 12 | P0.2, P0.3, P0.4a, P0.4c, P0.4d, P0.5, §4, §8a, §8b, §8c, §5a, §5c, §10a, §14 — z czego §5c to ten sam problem co P0.4a, a §10a jest znane i niezgłoszone przez audyt |
| **NIEAKTUALNE** | 2 | §9b (D-089, 10 września), §13a (D-053, 9 września) |
| **NIEPRAWDZIWE** | 4 | P0.1b, P0.4b, P0.6, §5b |
| **ŚWIADOME** | 8 | P0.1 (D-015), P0.4e (AGENTS.md §12), P0.4f, §8d (COPY_STYLE §8 + D-009/D-015), §9a (D-089), §9c (D-089), §13b (UX_50_PLUS), §17/§18 |
| **BEZ STANU** | 1 | §10b — brak dowodu po obu stronach |

Uściślenie do wiersza „PRAWDZIWE": wierszy jest 14, ale **odrębnych usterek
jest 12** — `§5c` jest tym samym kodem co `P0.4a`, a `§8a` tą samą regułą CSS
co `§4`.

**Rozkład wagi znalezisk PRAWDZIWYCH:** P0 — 1 (`§4`), P1 — 6, P2 — 5.

---

## Ocena samego audytu

**Czy autor uruchomił aplikację: nie.** Mówi to sam, w pierwszym akapicie:
„produkcyjny `kuking.pl` nie był z tego środowiska dostępny do niezależnego
crawlowania. Warstwę produkcyjną oceniam więc na podstawie sześciu dostarczonych
zrzutów ekranu, a implementację i zachowanie widoków — z kodu repozytorium".
To jest uczciwe i postawione na początku, nie ukryte w przypisie — i jest
mocniejszą deklaracją niż ta z dzisiejszego audytu kolejności blokad, bo od razu
mówi, na czym ocena stoi.

**Czy podaje pomiary: nie, i przyznaje to wprost.** `§2`: „Poniższa punktacja
jest oceną heurystyczną, nie metryką laboratoryjną". W całym dokumencie nie ma
ani jednej zmierzonej liczby — wszystkie liczby (720 px, 900–1050 px, „<700–900
KB", 5/12 + 7/12) są PROPOZYCJAMI docelowymi, nie pomiarami stanu. Audyt
wielokrotnie zaleca pomiar innym („3 kolumny tylko jeśli po pomiarze karta nie
spada do 2–3 słów w wierszu", `§12`), sam go nie wykonując. Dla kontrastu: kod,
który ocenia, ma pomiary w komentarzach — `app.css:170-175` („576 px zamiast
swoich zwykłych 720", okno 1280 px), `strony-publiczne.css:185-187` („kafel miał
404 px, z czego sam tytuł 274 px"), `app.css:4119-4124` („karta ma ~484 px").

**Czy czytał kod: tak, i to uważnie.** Nazwy plików, klas i komponentów są
prawdziwe (`kuking-word`, `kuking-board`, `strony-publiczne.css`,
`landing.blade.php`); zna historię hero („Wcześniej w tym miejscu była tablica
dnia… Nie należy cofać tej decyzji 1:1"), która istnieje **wyłącznie**
w komentarzu `strony-publiczne.css:92-98`. To nie jest audyt ze zrzutów
podparty zgadywaniem.

**Czego nie czytał: `docs/DECISIONS.md`.** To jest jedyna poważna wada tego
audytu i tłumaczy wszystkie cztery znaleziska NIEPRAWDZIWE oraz sześć z ośmiu
ŚWIADOMYCH. Gdyby przeczytał D-015, nie proponowałby wariantu `Kuking.pl`
odrzuconego imiennie pięć dni wcześniej; gdyby przeczytał D-089, nie wpisywałby
do kryteriów akceptacji „admin nie jest ograniczony do 720px" dobę po tym, jak
właściciel rozstrzygnął to w drugą stronę; gdyby przeczytał D-053, nie stawiałby
trzy razy wymogu działania bez JavaScriptu. `AGENTS.md` §2 wymienia ten plik
jako obowiązkową lekturę — dokładnie po to.

**Dwa najcięższe błędy rzeczowe** mają ten sam kształt: wniosek z nazwy zamiast
z pliku. `P0.6` („jedna szerokość 45rem jest traktowana jak szerokość strony")
upada na `tokens.css:205-222`, gdzie stoją trzy osobne szerokości strony —
a razem z nim upada cały `§6` i tabela tokenów w `§2`, czyli „najważniejsza
poprawka architektoniczna" audytu. `P0.4b` („nie mieszać dań z osobami w jednym
`ul`") upada na `kuking-board.blade.php:45` i `:121`, gdzie stoją dwa nagłówki
`<h3>` i dwie osobne listy. Obie pomyłki to rzeczy, które widać przy przeczytaniu
pliku do końca, a nie przy wyszukaniu w nim frazy.

**Bilans.** Audyt ma trafienia, których nikt w tym repozytorium wcześniej nie
zapisał — puste prawe pół hero, szyna gościa pod treścią (jedyne P0 w tym
triażu), tablica dnia jako `card`, 130 wystąpień jednej powierzchni. Warstwa
kompozycyjna jest realnie jego najmocniejszym fragmentem i tego nie podważam.
Nie nadaje się natomiast do wdrożenia w kolejności z `PLAN_WDROZENIA.md`:
pierwsze dwa PR-y z tej listy (`ui/brand-canonical`, `ui/layout-modes`) cofają
D-015 i są zbudowane na pomyłce `P0.6`. Kolejność, która ma pokrycie w kodzie,
zaczyna się od `§4` (szyna gościa) i `P0.4a`/`§5c` (tablica bez karty).

---

## Czego nie sprawdziłem

Wymieniam wprost, bo „nie wiem" jest tu odpowiedzią dopuszczalną, a zgadywanie nie.

1. **Nie uruchomiłem aplikacji ani przeglądarki.** Cała ta triaż to lektura kodu
   na commicie `343029b`. Nie zmierzyłem ani jednej szerokości na renderze, nie
   sprawdziłem przy 200% czcionki i nie zrobiłem zrzutów. Wszystkie liczby, które
   podaję (1040 px, 768 px, 544 px, 96×96), pochodzą z tokenów i atrybutów
   w plikach, nie z pomiaru na ekranie. Tam, gdzie audyt myli się o geometrię,
   mogę się mylić tak samo — tyle że w drugą stronę.
2. **Nie uruchomiłem testów** (`php artisan test`) ani `scripts/dostepnosc.mjs`.
   Zadanie było triażem, nie zmianą kodu, a testy w tym kontenerze wymagają
   PostgreSQL i klucza aplikacji.
3. **Nie oceniłem sześciu zrzutów ekranu**, na których audyt opiera ocenę
   warstwy produkcyjnej — nie ma ich w paczce (są w niej wyłącznie trzy
   wizualizacje kierunku i cztery kadry jedzenia użyte w mockupie). Nie wiem
   więc, czy zrzuty pokazywały stan zgodny z `main`.
4. **Nie zweryfikowałem wizualizacji** (`01-landing-desktop-kierunek.png` i dwie
   pozostałe) pod kątem kontrastu, rozmiarów tekstu i celów dotykowych. Jeśli
   kierunek z tych obrazków wejdzie do produktu, wymagają osobnego sprawdzenia
   wobec `docs/UX_50_PLUS.md` — to nie jest to samo pytanie co triaż znalezisk.
5. **§10b (spójność karty wpisu)** zostawiam bez stanu, bo do rozstrzygnięcia
   trzeba wyrenderować kartę w wariantach (z jednym zdjęciem, z kilkoma, bez
   zdjęcia, z tagami, u autora i u gościa) i zmierzyć odstępy. Czytanie CSS-a
   tego nie zastąpi.
6. **Nie sprawdziłem wydajności** ani budżetu obrazów, o którym mówi `§P0.2`
   i kryteria `§16` (LCP, CLS). Dziś hero nie ma obrazów, więc nie ma czego
   mierzyć; po ewentualnym wdrożeniu kolażu ten pomiar trzeba zrobić od zera.
7. **Nie sprawdziłem, czy poza `resources/views/` i `resources/css/` nie ma
   innych wystąpień nazwy marki** (np. w `lang/`, `resources/legal/`, e-mailach
   transakcyjnych). Przy P0.1 nie było to potrzebne, bo stan jest ŚWIADOMY,
   ale gdyby właściciel kiedyś cofnął D-015 — zakres jest większy niż trzy pliki
   z `PLAN_WDROZENIA.md`.

---

## Wpisy do dziennika decyzji, których brakuje (do rozstrzygnięcia przez właściciela)

Nie dopisuję ich do `docs/DECISIONS.md`. Obie sprawy mają dziś uzasadnienie
wyłącznie w komentarzu w CSS, więc następny audyt — zewnętrzny albo nasz —
zgłosi je znowu dokładnie tak, jak zgłosił je ten.

- **D-???** — „Jak działa" zostaje w dwóch kolumnach także powyżej `64rem`
  (albo dostaje trzecią dopiero tam). Uzasadnienie i pomiar: `strony-publiczne.css:145-149`
  oraz `:185-187`. Patrz P0.3.
- **D-???** — „Świeżo z Kuking" na landingu stoi w dwóch kolumnach na życzenie
  właściciela, mimo nierównych wysokości kart. Uzasadnienie i przeliczenie:
  `app.css:4118-4137`. Patrz P0.5.
