# System projektowy v3.1 — co to jest i jak się ma do reszty `docs/design/`

## Aktualne źródło stylu

Najpierw przeczytaj [AGENTS.md](../../../AGENTS.md), następnie
[aktualną konstytucję marki](../../brand/KONSTYTUCJA_MARKI.md)
i [decyzje właściciela](../../DECISIONS.md), w tym D-206–D-213.
Starsze paczki nie zastępują tych zasad. Aktualny materiał referencyjny
wskazuje [audyt paczki marki](../AUDYT_PACZKI_MARKI_508.md): zachowany ZIP
[KuKing-styl-wizualizacja-konstytucja.zip](../references/KuKing-styl-wizualizacja-konstytucja.zip). Nie zmieniamy jego oryginału
ani historycznych materiałów w uploads. Bieżący zakres odbioru opisuje
[macierz kompletności](../MACIERZ_KOMPLETNOSCI_517.md); sama obecność makiety nie dowodzi wdrożenia.

## Materiały historyczne

Poniższy opis dokumentuje wcześniejszy etap projektu. Dawne porównania
tokenów i stany wdrożenia odnoszą się do dat podanych w opisie, nie do
aktualnej aplikacji. Nie są poleceniem przywrócenia starej palety lub układu.

Paczka **„Kuking.pl — system projektowy"**, przysłana przez właściciela
8 września 2026, zrobiona w Claude Design. Wchodzi do repozytorium w całości,
razem z oryginałem w `uploads/`, bo istniała dotąd wyłącznie jako plik ZIP
u właściciela — a `docs/design/README.md` wymaga, żeby obowiązujący wygląd
leżał w repozytorium, nie obok niego.

## Czym to jest wobec `kit-v2/`

**Następcą, nie konkurentem.** Sama paczka mówi o sobie, że jest przepisana
z `KuKING-design-system-v3.1-poprawiony`, a ta z kolei wywodzi się z tego
samego materiału co `kit-v2/`. Wartości tokenów, klasy i decyzje są
przeniesione co do znaku — nic tu nie jest nowym projektem.

`kit-v2/` zostaje na miejscu. Nie kasujemy go, dopóki wdrożenie v3.1 nie jest
skończone: to jedyny materiał, na którym opisano etapy A–D i porównania
zrzutami ekranu, a `STAN_WDROZENIA_KITU.md` wciąż się do niego odwołuje.

## Co zmierzono przy pierwszym zetknięciu z kodem (8 września 2026)

Zanim cokolwiek zmieniono, porównano paczkę z arkuszami w `resources/css/`:

| Co | Wynik |
|---|---|
| Tokeny | **67 z 70 identycznych co do znaku** — `#FAF6F0`, `#2B241D`, `#7A5C10`. Brakowało jedenastu tokenów z v3.1; weszły do `resources/css/tokens.css` |
| `.card` | identyczna deklaracja po deklaracji |
| `.btn-primary`, `.btn-secondary`, `.btn-quiet` | identyczne |
| `.btn` (baza) | dwie realne różnice: wcięcie `--spacing-5`, interlinia 1.25, wyśrodkowanie napisu, reakcja na wciśnięcie |
| Klasy | 53 wspólne, **170 jest w systemie i nie ma ich w kodzie** |

Wniosek, który wyznacza kolejność prac: **paleta i komponenty podstawowe już
się zgadzają.** Różnica w wyglądzie siedzi w tych 170 klasach, a te grupują
się w układ i strony publiczne: `karta-*` (17), `pas-*` (11), `szyna-*` (10),
`stopka-*` (10), `hero-*` (8), `sekcja-*` (4), `topbar-*` (3).

Zgadza się to co do słowa z tym, co `STAN_WDROZENIA_KITU.md` zapisał przy
kicie v2: „Kolory, typografia i logo są wdrożone i zgadzają się co do
wartości. Nie zgadza się **układ**".

## Czego z tej paczki NIE bierzemy do aplikacji

1. **`tokens/fonts.css`** — ładuje Inter z serwera Google. Aplikacja hostuje
   Inter u siebie, w dwóch podzbiorach (`latin` 48 kB + `latin-ext` 85 kB,
   `resources/fonts/`), i tak zostaje. Bez `latin-ext` polskie znaki
   `ł ą ę ć ń ś ź ż` lecą z fontu zastępczego — słowo „żurek" ma wtedy trzy
   różne kroje. Zapytanie do obcego serwera przeczyłoby też sekcji
   „Prywatność”. Sama paczka wymienia to jako brak numer jeden.
2. **`boards.css`** — plansze społecznościowe i arkusze do druku. Do
   aplikacji się nie ładuje; byłby martwym kodem w każdym żądaniu. Zostaje
   tutaj jako materiał do plansz.
3. **`tokens/base.css` w części resetu** — to odpowiednik preflightu
   Tailwinda, dopisany dlatego, że paczka jedzie bez Tailwinda. Aplikacja ma
   Tailwind 4, więc preflight już jest; wciągnięcie drugiego dałoby dwie
   deklaracje tego samego.

## Do czego służą poszczególne katalogi

| Ścieżka | Do czego |
|---|---|
| `uploads/KuKING-design-system-v3.1-poprawiony/` | **oryginał historycznej paczki v3.1**; nie zastępuje aktualnej konstytucji — decyzje D-101…D-112, fundamenty, audyt ze zrzutami |
| `ui_kits/serwis/` | jedenaście ekranów będących wzorcem na etapie v3.1; aktualne wymagania wskazano powyżej |
| `components/` | komponenty referencyjne w JSX z typami i promptami; **nie wdrażamy Reacta**, czytamy je jak specyfikację |
| `components.css`, `site.css` | źródło reguł do przeniesienia do `resources/css/` |
| `guidelines/` | karty specyfikacji: kolor, typografia, rytm, marka |
| `templates/` | pięć punktów wyjścia dla nowych ekranów |
| `AUDYT.md` | co się nie zgadzało przy przenoszeniu paczki i co z tym zrobiono |

## Etapu 2 z `WDROZENIE.md` NIE DA SIĘ wykonać dosłownie — zmierzone

Instrukcja w `uploads/…/07-wdrozenie/WDROZENIE.md` §4 mówi, żeby w etapie 2
wpiąć `komponenty.css` **w całości, po `app.css`**, bez dotykania widoków:
59 wspólnych nazw dostaje wtedy nowy wygląd, a cofa się to usunięciem jednej
linijki. Dokument sam uprzedza, że powstał **bez dostępu do repozytorium**.

Sprawdzone 8 września 2026 na kodzie. Z 53 wspólnych nazw klas **43 się
różnią**, a część różnic jest strukturalna, nie kosmetyczna:

| Klasa | Aplikacja | System |
|---|---|---|
| `.app-body` | `display: grid` + kolumna nawigacji bocznej | `display: flex` + `--container-strona-solo` |
| `.app-rail` | `display: flex` | `display: none` (odsłaniana wyżej medią) |
| `.avatar` | `inline-flex`, `object-fit: cover`, rozmiar z parametru | `grid`, sztywne `3rem` |
| `.badge` | `--radius-pill`, tło wgłębione | `--radius-sm` |
| `.bottom-nav` | `z-index: 30`, `flex-wrap` | `z-index: 40` |

Wpięcie arkusza po `app.css` nałożyłoby właściwości flexa na siatkę grid
w szkielecie strony. To jest dokładnie ryzyko **R-2** z §6 tamtego dokumentu —
„powstaje wygląd, którego nie zaprojektował nikt" — tyle że nie w pojedynczym
komponencie, a w układzie każdej podstrony.

**Zamiast tego: uzgadnianie klasa po klasie**, w małych commitach, z decyzją
przy każdej, która wersja wygrywa i dlaczego. Wolniej, ale każdy krok da się
obejrzeć i cofnąć osobno, a żaden nie zostawia stanu mieszanego.

Aplikacja nie jest tu uboższym krewnym systemu: to druga, równie rozwinięta
implementacja tego samego projektu, miejscami z własnymi, świadomymi
odejściami od kitu (opisanymi w `STAN_WDROZENIA_KITU.md`). Import „na wierzch"
skasowałby je bez śladu.

## Stan wdrożenia

Prowadzony w `docs/HANDOVER.md`. Na 8 września 2026:

| Warstwa | Stan |
|---|---|
| Tokeny | podniesione do v3.1; doszło `.blok-ciemny` (paleta na dowolnym kontenerze) |
| `.btn` | zgodny z systemem, plus `.btn-duzy` |
| Typografia | `.text-title-lg` i `.text-title-xl` skalują się `clamp`-em — **i to jest poprawka błędu**, patrz niżej |
| **Pasy stron publicznych** | **zrobione** — `resources/css/strony-publiczne.css`, strona powitalna przebudowana |
| Belka, nawigacja boczna, szyna, stopka | przed nami |

### Co przyszło z pasami (8 września 2026)

Strona powitalna przestała być jedną kolumną w ramce ekranu zalogowanego
i stoi na sześciu pełnoszerokich pasach — dokładnie tak, jak §1 `site.css`.
Kolejność pasów jest argumentem: czym to jest → co robi → „Ugotowałem"
na ciemnym → cudze wpisy → dane i prywatność → załóż konto.

Trzy rzeczy zrobione INACZEJ niż w paczce, świadomie:

1. **Korzeń `.strona` nie wchodzi.** Paczka buduje strony publiczne na
   własnym szkielecie z własną belką (`.pas-gorny`) i własną stopką
   (`.stopka-www`), bo powstała bez dostępu do repozytorium. Aplikacja ma
   już belkę, stopkę, przełącznik motywu, skalę tekstu i nagłówki CSP —
   drugi komplet byłby wyłącznie okazją do rozjazdu. Pasy wpięto w istniejący
   `.app-body-powitalny`, któremu zdjęto sufit szerokości i wcięcia.
2. **W sekcji głównej stoi tablica dnia, nie zdjęcie potrawy.** Paczka daje
   tam fotografię 5:3; my mamy w tym miejscu prawdziwych ludzi i wpisy
   z dzisiaj. Zdjęcie z pliku byłoby dekoracją, tablica jest treścią.
3. **`.lead` nie wchodzi, zostaje `text-lead`.** Aplikacja ma już utility
   z tokenu o tej samej wartości. Dwie nazwy na jedną rzecz to pierwszy krok
   do dwóch różnych wartości.

### Uzgodnione komponenty (8 września 2026)

Klasa po klasie, każda z decyzją, która wersja wygrywa:

| Klasa | Kto wygrał | Dlaczego |
|---|---|---|
| `.empty-state` | **system** | dostaje kartę (tło podniesione, obwódka, `--radius-xl`). Szary tekst pośrodku pustej strony czytał się jak komunikat o awarii |
| `.empty-state-opis` | **system** | stonowany kolor schodzi z kontenera na sam opis — dotąd padał także na tytuł |
| `.side-nav-item` | **system** | wcięcie pionowe i zabezpieczenie zawijania: przy skali 140% „Bez odpowiedzi" zawija się w kolumnie 15 rem |
| `.field-input` | **system** | wcięcie poziome `--spacing-4`, interlinia z tokenu (najdłuższy tekst w serwisie to `textarea`), `min-width: 0` |
| `.bottom-nav-item` | **podział** | zabezpieczenie zawijania z systemu TAK, zejście na 16 px NIE — podpis pod ikoną jest całą treścią elementu, więc obowiązuje minimum 18 px (issue #110) |
| `.badge` | **system co do kształtu** | `--radius-sm` zamiast pigułki: pigułka to kształt rzeczy klikalnej i ma ją `.chip`. Tło zostaje w regule bazowej, bo wszystkie wywołania w serwisie są jednej wagi |
| `.avatar` | **aplikacja** | system ma sztywne `3rem`, aplikacja bierze rozmiar z parametru. Przyjęcie systemu zepsułoby każde wywołanie z rozmiarem |
| `.card` | **remis** | jedyna różnica to `forced-color-adjust: auto`, czyli wartość domyślna. Nic do zrobienia |

### Belka i karta wpisu (8 września 2026, dalszy ciąg)

| Klasa | Kto wygrał | Dlaczego |
|---|---|---|
| `.topbar-inner` | **system** | od 80rem ta sama siatka trzykolumnowa co treść. Krawędzie zewnętrzne zgadzały się i wcześniej, ale pole „Szukaj" pomiędzy nimi ustawiało sobie szerokość samo i stało nad tekstem, którego nie dotyka („problem nr 6" z paczki) |
| `.post-card` | **podział** | `--radius-xl` TAK. Uniesienie cienia przy najechaniu NIE — patrz akapit pod tabelą |
| `.post-card-body` | **system** (`.karta-tresc`) | treść wpisu po `--text-body-lg` (20 px). Do dziś nazwa autora była w karcie większa niż to, co ta osoba napisała |
| `.card .btn:focus-visible` | **system** | halo pierścienia fokusu w kolorze KARTY, nie strony. Na białej karcie beżowe halo rysowało widoczną obwódkę, a kontrast pierścienia był policzony względem tła, którego pod nim nie ma |
| `.meta` | **remis** | system ma `--text-meta` (15 px), ale sam nadpisuje to zaraz `--text-help` (16 px). Nasze 16 px zostaje — 15 px byłoby poniżej podłogi z `UX_50_PLUS.md` |
| `.kolumna-czytania` | **aplikacja** | system ma tu 38 rem, ale opisuje nią „długi dokument prawny". U nas ta klasa ogranicza tekst wewnątrz siatki 53 rem i 45 rem daje tam właściwe ~65–75 znaków. Ta sama nazwa, dwie różne rzeczy |

**Uniesienie karty wpisu weszło i zaraz wyszło, tego samego dnia.**
Uzasadniłem je zdaniem „karta jest w całości klikalna, a nic tego nie
zapowiada". Przegląd adwersaryjny pokazał, że oba człony są nieprawdziwe:
`post-card.blade.php` to zwykły `<article>` bez opakowującego odnośnika, a na
karcie stoją dwa pełnotekstowe przyciski, które mówią wprost, dokąd prowadzą.
Uniesienie obiecywało więc zachowanie, którego nie ma — i robiło to sygnałem
dostępnym wyłącznie po najechaniu kursorem, czyli niedostępnym na dotyku i z
klawiatury. W kicie ta reguła jest poprawna, bo tam cała karta jest jednym
odnośnikiem; u nas nie jest.

Automat dostępności mierzy od dziś także drugą regułę: krawędzie
`.topbar-szukaj` == krawędzie `.app-main` na ekranach zalogowanego od 1280 px.
Pierwsza reguła („belka ma tę samą szerokość co treść") mogła być spełniona
przy złamanej drugiej i przez pół roku była.

### Ekran powiadomień (§13 systemu)

| Klasa / rzecz | Kto wygrał | Dlaczego |
|---|---|---|
| Słowo przy nieprzeczytanym | **system** | do 8 września osoba na czytniku ekranu **nie miała skąd wiedzieć**, że powiadomienie jest nowe — kreska istnieje wyłącznie w CSS. WCAG 1.4.1 |
| Sposób rysowania kreski | **system** (`box-shadow: inset`) | `border-left: 4px` nadpisywał `border: 1px` z `.card` i przy `box-sizing: border-box` zjadał 3 px pola treści: w liście trzydziestu pozycji nieprzeczytane stały 3 px dalej w prawo niż przeczytane |
| Semantyka listy | **system** (`<ul>`) | trzydzieści luźnych `<article>` to dla czytnika trzydzieści niepowiązanych bloków, a nie „lista, 30 pozycji". Wzięta sama semantyka: `.lista-naga`, kształt bez zmian |
| Kolor kreski | **aplikacja** (`--color-brand`) | w motywie jasnym oba tokeny to ten sam `#B3401F`; w ciemnym `--color-brand-solid` jest przyciemniony pod TŁO przycisku i na ciemnej karcie ledwo odchodzi od tła |
| Tło `--color-brand-tint` | **aplikacja** (nie wchodzi) | system liczy na „krótkie, jednorodne wiersze". U nas powiadomienie od moderacji niesie uzasadnienie z DSA art. 17 na kilka akapitów — plama koloru na całej takiej karcie czyta się jak ostrzeżenie, nie jak wyróżnienie |
| Kształt: wiersz zamiast karty | **nierozstrzygnięte** | przesłanka systemu („to krótkie, jednorodne pozycje") jest u nas nieprawdziwa, a system zabrania wiersza z dwiema akcjami — karta moderacyjna ma „Zobacz" i „Odwołanie". To zmiana zachowania i treści prawnej, nie wyglądu; pytanie niżej |
| `--text-meta` na czasie | **aplikacja** | 15 px jest poniżej podłogi z `UX_50_PLUS.md`, tak samo jak przy `.meta` |

Zdjęta przy okazji martwa klasa `style-unread` — nie miała reguły w żadnym
arkuszu ani testu od pierwszego commita.

### Pytania do właściciela z ekranu powiadomień

1. **Wiersz czy karta?** Żeby zejść na `.wiersz`, trzeba rozstrzygnąć, co zrobić
   z powiadomieniem moderacyjnym: zostawić karty i uznać, że system się tu nie
   stosuje; zrobić wiersze dla wszystkiego poza moderacją (dwa kształty na
   jednej liście); albo wiersze wszędzie, a uzasadnienie DSA i odwołanie
   przenieść na osobną stronę sprawy.
2. **Czy cały wiersz ma być klikalny?** System tego chce. U nas znaczyłoby to
   drugi link nad linkiem („Odwołanie") — kolizja z zasadą jednej akcji na cel
   dotknięcia.
3. **„Nowe" czy „Nieprzeczytane"?** Plakietka mówi „Nowe" (za systemem, krócej),
   ale w belce ten sam stan nazywa się „nieprzeczytanych". `BRAND_EXTENDED.md`
   §3 zabrania synonimów — jedna nazwa na jeden stan.

### Pytanie do właściciela: jak głośna ma być odznaka „Konto przykładowe"

System (`components.css`) przewiduje dla niej odznakę **cichą** —
`.badge-cichy`, bez tła, mniejszą od metadanych. `docs/DECISIONS.md` D-025
mówi odwrotnie i `.badge-przykladowe` jest dziś celowo **głośniejsza** od
pozostałych: 18 px zamiast 16 px, z ramką, „żeby grupa 50+ zauważyła to bez
czytania drobnego druku".

Obie wersje mają argument i obie są Twoje. To decyzja produktowa — czy konto
przykładowe ma być widoczne od razu, czy ma nie rozpraszać w strumieniu —
więc nie rozstrzygam jej sam. Do czasu odpowiedzi zostaje wersja z D-025.

### Usterka złapana przy okazji: `clamp` na tytułach nigdy nie działał

`.text-title-lg` stała w `@layer base` z komentarzem „użyj tej klasy z tekstem
clamp". W zbudowanym arkuszu wygrywała jednak reguła ze sztywnym stopniem,
którą Tailwind 4 robi automatycznie z tokenu `--text-title-lg` — bo warstwa
`utilities` stoi w kaskadzie za `base`.

Pierwsza wersja tego akapitu podawała pozycje w bajtach. Były prawdziwe
w jednym buildzie i zależą od zestawu skanowanych plików, więc nikt ich nie
odtworzy — usunięte. Odtwarzalna jest relacja warstw i to sprawdza CI.

Skutek: hasło strony głównej miało zawsze 36 px, także przy oknie 320 px —
i przy największej skali tekstu też 36 px, co jest sednem, bo `clamp` miał
właśnie wtedy zejść niżej. Tekst się zawijał, więc nic nie „pękało" na tyle
głośno, żeby ktokolwiek to zgłosił.

Poprawka: obie reguły przeniesione na koniec `tokens.css`, do `@layer
utilities`. Pilnuje tego krok „Największe tytuły przetrwały build z clamp"
w jobie `Build assetów` — nie test PHPUnit, bo testy chodzą z `withoutVite()`
i nie mają zbudowanego arkusza, w którym ta usterka jako jedyna jest widoczna.
