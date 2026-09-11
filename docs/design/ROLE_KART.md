# Role kart — inwentarz powierzchni Kuking.pl

> Dokument roboczy systemu designu. Odpowiada na jedno pytanie: **czym jest ten
> biały prostokąt na ekranie i czego wymaga od człowieka.**
> Powiązane: [`DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md) §3.5, `resources/css/tokens.css` sekcja 2.1.

## Skąd ten dokument

Do 11 września 2026 klasa `.card` niosła **126 różnych ról naraz**. Pomiar:

```bash
grep -rno 'class="[^"]*\bcard\b[^"]*"' resources/views/ | wc -l   # 135
```

135 to liczba surowa i **jest zawyżona o 9**: `\bcard\b` traktuje myślnik jako
granicę słowa, więc łapie także `post-card-head`, `post-card-body`,
`recipe-card-miniatura` i sześć innych nazw, które nie są klasą `.card`.
Rzeczywistych wystąpień klasy jest **126**:

```bash
grep -rno 'class="[^"]*"' resources/views/ \
  | grep -cE 'class="([^"]* )?card( [^"]*)?"'                     # 126
```

Ta sama powierzchnia — podniesione tło, cienka obwódka, promień, cień — była
jednocześnie kartą wpisu, sekcją strony, blokiem prawej szyny, panelem
formularza, ramką z wyjaśnieniem i kaflem, w który się klika.

Skutek dawał się zobaczyć na `/napisz-do-nas`: karta „Chodzi o czyjś wpis?",
formularz i karta „Co się stanie dalej" miały ten sam kolor, ten sam cień
i ten sam promień, choć tylko jedna z tych trzech rzeczy czegokolwiek wymagała.
Gdy wszystko na ekranie ma tę samą rangę, nic jej nie ma — a najbardziej traci
na tym ktoś, kto czyta wolniej albo powiększa tekst, bo skanowanie wzrokiem
przestaje być skrótem.

### Ta komenda ma DRUGĄ pułapkę, i wpadł w nią ten dokument

`grep` czyta plik jako tekst, więc **nie odróżnia markupu od komentarza
Blade**. `{{-- … --}}` bywa w tym repozytorium długie i opisowe, a opis
warstwy naturalnie cytuje klasę, o której mówi — na przykład
„DLACZEGO KOMPONENT, A NIE SKOPIOWANY `<section class="card">`"
(`components/szyna-blok.blade.php`). Takie zdanie liczy się do sumy
dokładnie tak samo jak prawdziwy `<section>`.

Nie jest to hipoteza: **dwa wystąpienia z inwentarza niżej były duchami** —
`components/szyna-blok.blade.php:6` i `pages/notifications.blade.php:32`.
Oba siedzą w komentarzu, oba opisują sąsiedni, prawdziwy znacznik kilkanaście
linii dalej, obu w przeglądarce nie ma.

**Metoda, która to odsiewa** (i którą należy powtórzyć przy następnym
pomiarze): wyciąć komentarze, ZANIM policzy się klasy. Jedna linijka:

```bash
php -r '$n=0; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("resources/views", FilesystemIterator::SKIP_DOTS)) as $f) {
  if (!$f->isFile()) continue;
  $t = preg_replace("/\{\{--.*?--\}\}/s", "", file_get_contents($f->getPathname()));
  $n += preg_match_all("/class=\"([^\"]* )?card( [^\"]*)?\"/", $t);
} echo $n, PHP_EOL;'                                                 # 20
```

`SKIP_DOTS` nie jest ozdobą — bez niego iterator wchodzi w `.` i nigdy
nie kończy.

Ta sama poprawka dotyczy liczb wyżej. Powtórzony pomiar surową komendą na
stanie sprzed rozdzielenia warstw (`4751a69~1`) daje dziś **127 trafień, z tego
2 w komentarzach, czyli 125 żywych** — a nie 126. Jednego trafienia różnicy nie
da się przypisać po fakcie; pomiar w nagłówku tego rozdziału zrobiono w trakcie
pracy, na innym stanie drzewa. Wniosek zostaje ten sam i to on jest tu ważny:
**każda liczba policzona surowym `grep`-em po `class="…"` jest górną granicą,
nie wynikiem.**

## Sześć ról

| # | Rola | Klasa | Tło | Obwódka | Promień | Cień | Wcięcie |
|---|---|---|---|---|---|---|---|
| 1 | **Karta treści** — wpis, przepis, wykonanie „Ugotowałem", komentarz, powiadomienie, osoba na liście | `.card` | podniesione | cienka | 24 px | **tak** | 20 px |
| 2 | **Panel formularza** — jedyna rzecz na ekranie do wypełnienia | `.panel-formularza` | podniesione | **mocna** | 24 px | **tak** | **24 px** |
| 3 | **Sekcja strony** — blok landingu, tablica dnia, kontener listy, ekran potwierdzenia | `.sekcja-strony` | podniesione | cienka | 24 px | nie | 20 px |
| 4 | **Blok prawej szyny** | `.card .szyna-blok` | podniesione | cienka | 24 px | nie | 20 px |
| 5 | **Ramka pomocnicza** — wyjaśnienie, ostrzeżenie, „co się stanie dalej" | `.ramka-pomocnicza` | **wgłębione** | cienka | **16 px** | nie | 20 px |
| 6 | **Kafel akcji** — cała powierzchnia jest odnośnikiem | `.kafel-akcji` | podniesione | **mocna** | 24 px | **tak** | 20 px |

Obwódka „mocna" to `--color-border-strong` — ta sama, którą mają pola
formularza. Panel i kafel są jedynymi warstwami, które czegoś od człowieka
CHCĄ, więc niosą obwódkę kontrolki, a nie linię dekoracyjną: obwódka kontrolki
musi mieć 3:1 względem tła (WCAG 1.4.11), a `--color-border` tego progu nie ma
i nie ma mieć.

Wszystkie wartości pochodzą z tokenów `--warstwa-*` (`resources/css/tokens.css`,
sekcja 2.1). W klasach nie ma ani jednej wartości na sztywno — inaczej tryb
ciemny i ciemny pas na stronie powitalnej (`.blok-ciemny`) wymagałyby sześciu
osobnych nadpisań.

### Ile osi dzieli którą parę — policzone

Nie jest prawdą, że każda para warstw rozchodzi się na dwóch osiach naraz,
i nie ma być. Liczby (liczone po pięciu tokenach każdej warstwy: tło, obwódka,
promień, cień, wcięcie):

| para | ile osi | które |
|---|---|---|
| panel formularza ↔ ramka pomocnicza | **5** | tło, obwódka, promień, cień, wcięcie |
| ramka pomocnicza ↔ kafel akcji | 4 | tło, obwódka, promień, cień |
| karta treści ↔ ramka pomocnicza | 3 | tło, promień, cień |
| panel formularza ↔ sekcja / ↔ szyna | 3 | obwódka, cień, wcięcie |
| sekcja ↔ ramka pomocnicza / szyna ↔ ramka | 2 | tło, promień |
| sekcja ↔ kafel akcji / szyna ↔ kafel | 2 | obwódka, cień |
| karta treści ↔ panel formularza | 2 | obwódka, wcięcie |
| karta treści ↔ sekcja / ↔ blok szyny | **1** | cień |
| karta treści ↔ kafel akcji | **1** | obwódka |
| panel formularza ↔ kafel akcji | **1** | wcięcie |
| sekcja strony ↔ blok szyny | **0** | — |

Trzy wnioski, bo same liczby niczego nie rozstrzygają:

1. **Największą odległość mają warstwy, które naprawdę stają obok siebie.**
   Panel formularza i ramka pomocnicza rozchodzą się na wszystkich pięciu
   osiach — i to jest ta różnica, dla której cała ta zmiana powstała.
2. **Warstwy 1, 3 i 4 mają identyczny kolor i różni je wyłącznie cień.**
   To jest świadome i rozstrzygnięte wcześniej przy szynie: karta unosi się, bo
   jest treścią, po którą ktoś przyszedł, a szyna i sekcja stoją płasko.
   Warstwy 3 i 4 są wręcz nierozróżnialne — nazwy są dwie, bo role są dwie,
   a nie dlatego, że coś ma wyglądać inaczej.
3. **Żadna z tych różnic nie niesie informacji potrzebnej do obsługi ekranu.**
   Co jest formularzem, mówi nagłówek i etykieta pola; warstwa tylko podkreśla.
   Dlatego zakaz „kolor jedynym nośnikiem informacji" (WCAG 1.4.1, `AGENTS.md`
   §5) jest tu spełniony niezależnie od tego, ile osi dzieli daną parę.

W motywie ciemnym, gdzie cienie są prawie niewidoczne, różnicę między warstwą 1
a 3 niesie jasność powierzchni (`#2A241E` karty na `#1E1A16` strony), a między
warstwą 5 a resztą — wgłębienie (`#14110E`).

### Czego w tej tabeli NIE MA i nie ma być

**Klas pomocniczych „bez cienia" i „tło wgłębione".** Rola jest nazwana
w jednym miejscu — w nazwie klasy — więc następna osoba czyta z widoku, CZYM
ta powierzchnia jest, a nie tylko jak wygląda. Klasa opisująca wygląd wróciłaby
dokładnie tam, skąd wyszliśmy: jedna nazwa na sześć znaczeń.

## Rzeczy rozstrzygnięte wcześniej, których ten dokument NIE cofa

- **Blok prawej szyny ma TEN SAM promień co karta wpisu** i celowo nie ma
  cienia. Dwa różne promienie w jednym rzędzie czyta się jako niedokończone,
  a nie jako hierarchię. Deklaracja w `app.css` zostaje jawna, choć `.card`
  daje dziś tę samą wartość: równość tych promieni jest DECYZJĄ i ma być
  widoczna w pliku.
- **Trzy szerokości strony** (`--container-strona`, `--container-strona-z-szyna`,
  `--container-strona-solo`) zostają rozdzielone tak, jak są.
- **Szerokość panelu moderacji** rozstrzyga D-089 — ten dokument jej nie dotyka.
- **Dolna belka** zostaje `position: fixed`, a rezerwa pod nią liczona
  (D-082, D-107).

## Przed i po — liczby, nie wrażenia

„Wszystko ma tę samą rangę" jest zdaniem o wrażeniu, a wrażenia nie da się
porównać przed zmianą i po. `scripts/warstwy-pomiar.mjs` zamienia je na trzy
liczby na ekran, czytane z `getComputedStyle` w przeglądarce przy 1280 px:

- **powierzchnie** — ile szerokich bloków (≥ 60% kolumny) z własnym tłem,
  obwódką albo cieniem stoi w `<main>`; kontrolki (pole, przycisk, etykieta)
  nie liczą się, bo nikt nie myli pola tekstowego z sekcją strony,
- **sygnatury** — na ile różnych wyglądów się dzielą (tło + obwódka + promień
  + cień),
- **największa grupa** — ile z nich wygląda dokładnie tak samo.

Ekran, na którym `powierzchnie == największa grupa`, nie ma hierarchii.

| ekran | przed (pow./sygn./grupa) | po | co się zmieniło |
|---|---|---|---|
| `/napisz-do-nas` (gość) | 3 / **1** / **3** | 3 / 2 / 2 | wyjaśnienie i „Co się stanie dalej" zeszły na wgłębioną ramkę, formularz został jedyną rzeczą z cieniem i mocną obwódką |
| `/zglos-nielegalna-tresc` (gość) | 3 / **1** / **3** | 3 / 2 / 2 | ten sam układ: dwie ramki, jeden panel |
| `/logowanie` (gość) | 2 / **1** / **2** | 2 / 2 / **1** | żadne dwie powierzchnie nie wyglądają już tak samo: panel hasła i sekcja z logowaniem linkiem |
| `/ustawienia/czytelnosc` | 3 / **1** / **3** | 3 / 2 / 2 | dwa formularze (rozmiar tekstu, kolory) zostały panelami, „Można jeszcze więcej" ramką |
| `/ustawienia/e-mail`, poczta działa | 2 / **1** / **2** | 2 / 2 / **1** | panel danych („Twój adres") i panel zmiany adresu przestały być tym samym |
| `/ustawienia/e-mail`, poczta nie działa | 2 / 1 / 2 | 2 / 1 / 2 | **bez zmiany — i tak ma być**: w tym stanie formularza nie ma wcale, więc obie powierzchnie to sekcje; warstwę wybiera `@class` |
| `/ustawienia/bezpieczenstwo` | 2 / 1 / 2 | 2 / 1 / 2 | **bez zmiany liczby**: obie powierzchnie to równorzędne czynności (zmiana hasła, wylogowanie innych urządzeń) — jedna rola, jeden wygląd |
| `/dodaj` | 2 / 1 / 2 | 2 / 1 / 2 | **bez zmiany liczby**: dwa kafle akcji to dwie równorzędne decyzje; zmienił się wygląd obu (mocna obwódka kontrolki, WCAG 1.4.11) |
| `/dodaj/przepis/jedna-strona` | 7 / 3 / 3 | 7 / 3 / 3 | **liczby stoją, hierarchia nie**: przed zmianą cztery sekcje formularza były białymi kartami z cieniem, nie do odróżnienia od wierszy składników w środku; teraz jest JEDEN panel (`<form>`), a w nim przezroczyste kreski działowe i wiersze — patrz niżej |
| `/home` (strumień) | 30 / 4 / 13 | 30 / 4 / 13 | **liczby stoją, znaczenie nie**: przed zmianą zachęta „Co dziś ugotowałeś?" odróżniała się od kart wpisu PRZYPADKIEM (16 px promienia z `.card` kontra 24 px z nadpisania `.post-card`); teraz odróżnia się celowo, jako kafel akcji, a wszystkie karty treści mają jeden promień |

Cztery ostatnie wiersze są tu specjalnie. Miara ma swoje granice: nie odróżnia
„dwie powierzchnie wyglądają tak samo, bo ktoś nie rozdzielił ról" od „dwie
powierzchnie wyglądają tak samo, bo mają tę samą rolę", i nie widzi, że
identyczna liczba może opisywać zupełnie inny układ. Tę różnicę rozstrzyga
człowiek, dlatego skrypt nie jest bramką i nie ma progu.

### Ekran, na którym liczba niczego nie pokazała — a defekt był

`/dodaj/przepis/jedna-strona` daje 7 / 3 / 3 przed i po. Sygnatury mówią, co
naprawdę się stało:

| | przed | po |
|---|---|---|
| `<form>` | brak powierzchni | **panel: biały, obwódka kontrolki `#8A7A63` 1 px, promień 24 px, cień** |
| sekcja „1. O przepisie" | biała karta, **obwódka 0 px**, promień 16 px, cień | przezroczysta, sama kreska działowa |
| sekcje 2–4 | białe karty, obwódka dekoracyjna 2 px, promień 16 px, cień | przezroczyste, same kreski działowe |
| wiersze składników | białe, obwódka 2 px, promień 16 px | bez zmian |

Dwie rzeczy widać dopiero w sygnaturach. Po pierwsze **cztery karty formularza
były nie do odróżnienia od wierszy składników w środku** — ta sama biel, ten
sam promień, ta sama obwódka; różnił je wyłącznie cień. Po drugie pierwsza
sekcja miała `border: 0px` i wcięcie 0: `.form-section:first-of-type`
(`app.css`) zerował `border-top` i `padding-top` regule `.card` z `tokens.css`,
bo `app.css` stoi w kaskadzie później w tym samym `@layer components`. Ta usterka
była w repozytorium PRZED tą zmianą i przeżyła pierwsze podejście do niej —
`form-section panel-formularza` odziedziczył ją co do piksela (zmierzone:
`border-top: 0px`, `padding-top: 0px` na pierwszej sekcji, obwódka dekoracyjna
2 px zamiast kontrolki 1 px na pozostałych).

Naprawa jest jedna dla obu rzeczy: **panel siedzi na `<form>`, nie na
sekcjach.** To są części jednego formularza, więc jedna rola i jedna
powierzchnia, a `.form-section` wraca do tego, do czego był pomyślany —
rozdzielania kreską i nagłówkiem. To samo zrobiono w `pages/admin/daily-board`
(gdzie obie sekcje mają jeszcze gałąź „lista pusta", czyli panel bez pól)
i w kreatorze przepisu, gdzie `.form-section` nie miał czego rozdzielać,
bo kroki są rozłączne.

### Tryb ciemny — zmierzony osobno, 21 ekranów

Cienie w motywie ciemnym są prawie niewidoczne, więc hierarchia musi tam
wynikać z jasności powierzchni. Zmierzone przez `getComputedStyle` na 21
ekranach (73 instancje warstw, 738 węzłów tekstowych na motyw):

- `ramka-pomocnicza` = `#14110E`, **ciemniejsza** od tła strony `#1E1A16` —
  11 z 11 instancji, ani jednej innej wartości;
- karta, panel, sekcja, szyna i kafel = `#2A241E`, **jaśniejsze** od tła —
  60 z 60 instancji;
- rozpiętość ramka ↔ karta: 1,22:1, kierunek nigdzie się nie odwraca;
- obwódka kontrolki `#8C7D68`: 4,32:1 wobec tła strony i 3,83:1 wobec
  wypełnienia panelu (próg 3:1 z obu stron krawędzi);
- **zero par tekst/tło poniżej progu** w obu motywach.

Przy okazji sprawdzono mechanizm dwóch selektorów (`:root, .blok-ciemny`):
wewnątrz `.blok-ciemny` na stronie powitalnej tokeny warstw rozwijają się
z palety CIEMNEJ (`--warstwa-tresc-tlo` = `#2a241e`, `--warstwa-pomocnicza-tlo`
= `#14110e`), a nie z jasnej. **Dziś w tym kontenerze nie ma ani jednej
warstwy powierzchni**, więc mechanizm jest sprawny, ale nieużywany — chroni
przed błędem, którego jeszcze nikt nie popełnił.

## Decyzje sporne — i co zostało rozstrzygnięte jak

| miejsce | spór | rozstrzygnięcie |
|---|---|---|
| `auth/login.blade.php` — „Nie pamiętasz hasła?" | ramka pomocnicza czy sekcja | **sekcja**. D-056 mówi, że logowanie linkiem jest drogą RÓWNORZĘDNĄ z hasłem, dla części naszych ludzi podstawową. Wgłębiona ramka byłaby cofnięciem tamtej decyzji w warstwie wizualnej. To samo dotyczy wejść przez Google i Facebooka. |
| `admin/wiadomosc.blade.php` — „Odpowiedz tej osobie" | panel zawsze czy warunkowo | **warunkowo (`@class`)**. Gdy osoba nie zostawiła adresu, w tym bloku nie ma ani jednego pola. Mocna obwódka obiecywałaby wtedy formularz, którego nie ma — to samo, przed czym broni zakaz martwego przycisku (D-053), tylko w warstwie powierzchni. |
| `components/recipe-wizard.blade.php` — krok podglądu | panel jak trzy poprzednie kroki czy sekcja | **sekcja**. Podgląd nie ma pól (przyciski stoją poza sekcją, w `.form-actions`), a kreator pokazuje jeden krok naraz — nie powstaje ekran, na którym trzy kroki mają jedną warstwę, a czwarty inną. |
| `pages/onboarding/people.blade.php` — wyszukiwarka osób | panel (jedyne pole ekranu) czy ramka | **ramka**. Akapit w tym bloku mówi wprost: „to pomoc w odnalezieniu kogoś, kogo już znasz, a nie kolejny obowiązkowy krok". Panel dałby krokowi nieobowiązkowemu najmocniejszą warstwę ekranu, czyli wizualnie zaprzeczyłby własnemu tekstowi. |
| `pages/cooked/celebrate.blade.php` — „Podziękuj" | jedna powierzchnia na cały ekran | **rozdzielone**: sekcja niesie wiadomość („komuś wyszło" + zdjęcie + notatka), a formularz podziękowania dostał własny panel. To jedyne miejsce, w którym zmieniono coś więcej niż sam token klasy. |
| `pages/settings/two_factor/codes.blade.php` — lista kodów zapasowych | karta treści, sekcja czy ramka | **karta treści**. Kody są przedmiotem, po który się na ten ekran przychodzi — dokładnie tym, co warstwa 1 nazywa „rzeczą, po którą ktoś tu przyszedł". Wyjaśnienie „Do czego służą?" zeszło do ramki. |
| `pages/settings/data.blade.php` — „Pobierz swoje dane" | panel czy sekcja | **sekcja**, i ten ekran ma ZERO paneli. Jedyne pola siedzą w „Strefie zagrożenia"; nadanie „Pobierz" mocnej obwódki zrównałoby wizualnie akcję zwykłą z destrukcyjną, czyli osłabiło odsunięcie wymagane przez `AGENTS.md` §5. |
| `admin/reports.blade.php`, `admin/sygnaly.blade.php`, `admin/bez-odpowiedzi.blade.php` | karta treści mimo formularza w środku | **karta treści**. Powierzchnia jest cudzą treścią, którą moderator ocenia; formularz decyzji jest dodatkiem do niej, nie jej rolą. Trzy pliki potraktowane jednakowo. |
| `components/ustawienia-nawigacja.blade.php` | sekcja czy blok szyny | **sekcja**. Komponent stoi zawsze w `<x-slot:rail>`, ale nie nosił klasy `szyna-blok` — a warstwy 3 i 4 są wizualnie identyczne, więc spis wygląda tak, jak wyglądał, i traci tylko cień, który kazał mu konkurować z kolumną główną. |
| `settings/security.blade.php` — wejście kontem Facebooka | ramka czy sekcja | **sekcja**. D-113: człowiek, który ma już konto, NIE wejdzie na nie kontem Facebooka, dopóki sam nie połączy kont z tego ekranu — a list kierujący go tutaj mówi wprost „połącz konta w Ustawienia → Bezpieczeństwo". Wgłębienie mówiłoby „to jest obok" o jedynej drodze do celu. |
| `settings/data.blade.php` — „Co zniknie, a co zostanie" | ramka czy sekcja | **sekcja**. Trzy listy są MATERIAŁEM do wyboru zakresu usunięcia (D-022), nie przypisem obok niego. Na warstwie wgłębionej sąsiednie „Pobierz swoje dane" — akcja zwykła i odwracalna — stało wizualnie wyżej niż opis skutków, których cofnąć się nie da. |
| `zgloszenia/szczegoly.blade.php` — pouczenie DSA art. 16 ust. 5 | ramka czy sekcja | **sekcja**. D-042 odbiera zgłaszającemu formularz skargi i stawia to pouczenie W JEGO MIEJSCE; to cały środek prawny, jaki mu zostaje. |
| `appeals/create.blade.php`, `appeals/reporter.blade.php` — „termin minął" | ramka czy sekcja | **sekcja**. W tej gałęzi to CAŁA treść ekranu; sąsiednie gałęzie tego samego `@if` mają sekcję i panel, więc akurat stan „przegrałeś termin" dostawał najsłabszą warstwę. |
| `settings/two_factor/enable.blade.php` — kod do ręcznego wpisania | ramka czy sekcja | **sekcja**. To druga droga do tego samego celu, równorzędna z kodem QR, który jest sekcją — nie wyjaśnienie obok niego. |
| `settings/email.blade.php` — „Zmień adres e-mail" | panel zawsze czy warunkowo | **warunkowo (`@class`)**. Gdy poczta nie działa, gałąź zastępcza nie ma ani pola, ani przycisku. Dwa inne ekrany (`forgot-password`, `login-link`) trzymają cały panel w `@if(Poczta::dziala())`; tutaj tak nie można, bo gałąź zastępcza musi coś powiedzieć. |
| `errors/419.blade.php`, `errors/429.blade.php` — odzyskany formularz | panel zawsze czy warunkowo | **warunkowo (`@class`)**. Wartości krótsze niż 60 znaków wracają jako pola UKRYTE, więc przy komentarzu „Wygląda pysznie!" bez zdjęcia cały blok to `@csrf`, pola ukryte i przycisk. Mocna obwódka obiecywałaby formularz, którego nie widać — a ekran mówi w tym samym czasie „Twój tekst jest na miejscu". Predykat: `OdzyskanyFormularz::maWidocznePola()`. |
| `zgloszenia/lista.blade.php` kontra `zgloszenia/szczegoly.blade.php` | to samo zgłoszenie ma dwie warstwy | **tak ma być, i to jest reguła ogólna**: element listy jest kartą treści, ekran szczegółów tej samej rzeczy jest sekcją. Rolę nadaje MIEJSCE, nie obiekt: na liście karta oddziela jedną sprawę od dwudziestu innych, na ekranie szczegółów nie ma czego oddzielać, a kartą treści na tym ekranie jest odpowiedź, nie własny tekst czytelnika. |


## Czego ten dokument nie rozstrzyga

- **`pages/cooked/celebrate.blade.php`** — cudze wykonanie „Ugotowałem"
  (zdjęcie plus notatka) stoi na sekcji, choć warstwa 1 wymienia wykonanie
  wprost. Argument za sekcją: to jest ekran POTWIERDZENIA, a rzeczą do
  zrobienia jest podziękowanie, które dostało własny panel w środku. Argument
  za kartą: „rzecz, po którą ktoś tu przyszedł". Zostawione jako sekcja, ale
  to jest spór, nie fakt.
- **`pages/admin/uzytkownicy.blade.php` i `pages/admin/wiadomosci.blade.php`** —
  puste stany są tam zwykłym `<p class="sekcja-strony">`, a repozytorium ma na
  to osobny komponent `<x-empty-state>` (użyty w `admin/tag-promotions`). Dwa
  sąsiednie ekrany panelu robią to samo dwoma mechanizmami. Nie ruszone: to
  zmiana struktury, nie warstwy.
- **`pages/collections/index.blade.php`** — „Załóż nowy zeszyt" to
  `<details class="panel-formularza">`. W stanie zwiniętym mocna obwódka
  otacza sam przycisk. Nie jest to martwa obietnica (pola naprawdę są
  w środku), ale przez większość czasu panel nie ma czego wypełniać.
- **Automat dostępności nie wchodzi na trzy ekrany z ramkami**: dwa ekrany
  panelu moderacji (403 na koncie demo) i drugi krok logowania (żadne konto
  demo nie ma włączonej weryfikacji dwuetapowej). Ich warstwy sprawdzono
  czytaniem kodu i testem, nie pomiarem w przeglądarce.

## Inwentarz — wszystkie 126 wystąpień

Wiersz na wystąpienie klasy warstwy w `resources/views/`. Wygenerowane ze
stanu kodu, nie przepisane z pamięci. Wiersze `@class([...])` opisują
JEDNO miejsce, które wybiera warstwę warunkiem — bo w jednym ze stanów ekranu
nie ma tam czego wypełnić (`admin/wiadomosc`, `settings/email`, `errors/419`,
`errors/429`) albo bo warstwy nie ma tam wcale (`components/kuking-board`
w pasie strony powitalnej).

| rola | ile |
|---|---|
| sekcja strony | 50 |
| panel formularza | 43 |
| karta treści | 18 |
| ramka pomocnicza | 10 |
| kafel akcji | 3 |
| blok szyny | 2 |

**Suma 126 stała tu wcześniej PRZYPADKIEM i trzeba to powiedzieć wprost**,
bo inaczej następna osoba uzna, że skoro liczba się nie zmieniła, to spis był
sprawdzony. Poprzednia wersja liczyła dwa duchy z komentarzy Blade
(`components/szyna-blok.blade.php:6`, `pages/notifications.blade.php:32` —
oba jako „karta treści") i **nie miała dwóch prawdziwych wystąpień** z ekranu
`pages/admin/kolaz-powitalny.blade.php`, który powstał po napisaniu tego
dokumentu. Minus dwa i plus dwa dało tę samą sumę przy trzech błędnych
wierszach tabeli ról. Dziś 126 jest wynikiem, a nie zbiegiem okoliczności.


### Wejście do serwisu — `auth/`, `components/wejscia-*`, `components/wejdz-*`

_17 wystąpień: sekcja strony — 8, panel formularza — 8, ramka pomocnicza — 1._

| plik:linia | klasa | rola |
|---|---|---|
| `auth/facebook-bez-adresu.blade.php:27` | `sekcja-strony` | sekcja strony |
| `auth/facebook-finish.blade.php:27` | `panel-formularza` | panel formularza |
| `auth/facebook-link.blade.php:27` | `sekcja-strony` | sekcja strony |
| `auth/forgot-password.blade.php:24` | `panel-formularza` | panel formularza |
| `auth/google-finish.blade.php:20` | `panel-formularza` | panel formularza |
| `auth/google-link.blade.php:17` | `sekcja-strony` | sekcja strony |
| `auth/login-link-confirm.blade.php:30` | `sekcja-strony` | sekcja strony |
| `auth/login-link.blade.php:48` | `panel-formularza` | panel formularza |
| `auth/login.blade.php:31` | `panel-formularza` | panel formularza |
| `auth/login.blade.php:70` | `sekcja-strony mt-6` | sekcja strony |
| `auth/register.blade.php:31` | `panel-formularza` | panel formularza |
| `auth/reset-password.blade.php:6` | `panel-formularza` | panel formularza |
| `auth/two_factor_challenge.blade.php:28` | `panel-formularza` | panel formularza |
| `auth/two_factor_challenge.blade.php:42` | `ramka-pomocnicza mt-5` | ramka pomocnicza |
| `auth/zaproszenie.blade.php:24` | `sekcja-strony` | sekcja strony |
| `components/wejdz-google.blade.php:35` | `sekcja-strony mt-6` | sekcja strony |
| `components/wejscia-zewnetrzne.blade.php:123` | `sekcja-strony mt-6` | sekcja strony |


### Ekrany błędów — `errors/`

_4 wystąpień: panel formularza — 2, sekcja strony — 2._

| plik:linia | klasa | rola |
|---|---|---|
| `errors/419.blade.php:92` | `@class([...]) — panel-formularza` | panel formularza |
| `errors/419.blade.php:93` | `@class([...]) — sekcja-strony` | sekcja strony |
| `errors/429.blade.php:77` | `@class([...]) — panel-formularza` | panel formularza |
| `errors/429.blade.php:78` | `@class([...]) — sekcja-strony` | sekcja strony |


### Treść: wpis, przepis, wykonanie, komentarz

_23 wystąpienia: panel formularza — 10, karta treści — 5, sekcja strony — 6, blok szyny — 2._

Dwie uwagi do tej tabeli. `components/kuking-board.blade.php` nosi warstwę
WARUNKOWO (`@class`), bo w ciemnym pasie strony powitalnej tło i wcięcie daje
sam pas — rola się nie zmieniła, zmienił się sposób jej nadania.
A `components/szyna-blok.blade.php:6` **wypadł ze spisu**: to był duch
z komentarza Blade, prawdziwy blok szyny stoi w tym pliku w linii 23 i jest
w tabeli niżej.

| plik:linia | klasa | rola |
|---|---|---|
| `components/comment-thread.blade.php:26` | `card` | karta treści |
| `components/comment-thread.blade.php:189` | `panel-formularza` | panel formularza |
| `components/cooked-card.blade.php:8` | `card` | karta treści |
| `components/kuking-board.blade.php:89` | `@class([...]) — sekcja-strony` | sekcja strony |
| `components/post-card.blade.php:23` | `card post-card` | karta treści |
| `components/recipe-card.blade.php:2` | `card` | karta treści |
| `components/recipe-wizard.blade.php:1007` | `panel-formularza` | panel formularza |
| `components/recipe-wizard.blade.php:1169` | `panel-formularza` | panel formularza |
| `components/recipe-wizard.blade.php:1241` | `panel-formularza` | panel formularza |
| `components/recipe-wizard.blade.php:1341` | `sekcja-strony` | sekcja strony |
| `components/szyna-blok.blade.php:23` | `card szyna-blok` | blok szyny |
| `components/szyna-startowa.blade.php:23` | `card szyna-blok` | blok szyny |
| `components/ustawienia-nawigacja.blade.php:72` | `sekcja-strony ustawienia-nawigacja` | sekcja strony |
| `pages/cooked/celebrate.blade.php:18` | `sekcja-strony stack text-center` | sekcja strony |
| `pages/cooked/celebrate.blade.php:64` | `panel-formularza` | panel formularza |
| `pages/cooked/create.blade.php:10` | `panel-formularza` | panel formularza |
| `pages/posts/create.blade.php:7` | `panel-formularza` | panel formularza |
| `pages/posts/edit.blade.php:17` | `panel-formularza` | panel formularza |
| `pages/posts/zdjecia.blade.php:54` | `panel-formularza` | panel formularza |
| `pages/recipes/create.blade.php:93` | `panel-formularza` | panel formularza |
| `pages/recipes/show.blade.php:186` | `card przepis-panel` | karta treści |
| `pages/recipes/show.blade.php:344` | `sekcja-strony` | sekcja strony |
| `pages/recipes/show.blade.php:400` | `sekcja-strony` | sekcja strony |


### Ustawienia konta — `pages/settings/`

_24 wystąpień: sekcja strony — 10, panel formularza — 9, ramka pomocnicza — 3, karta treści — 2._

| plik:linia | klasa | rola |
|---|---|---|
| `pages/settings/accessibility.blade.php:23` | `panel-formularza` | panel formularza |
| `pages/settings/accessibility.blade.php:74` | `panel-formularza` | panel formularza |
| `pages/settings/accessibility.blade.php:96` | `ramka-pomocnicza mt-8` | ramka pomocnicza |
| `pages/settings/avatar.blade.php:16` | `panel-formularza` | panel formularza |
| `pages/settings/data.blade.php:10` | `sekcja-strony` | sekcja strony |
| `pages/settings/data.blade.php:96` | `sekcja-strony mt-4` | sekcja strony |
| `pages/settings/email.blade.php:29` | `sekcja-strony` | sekcja strony |
| `pages/settings/email.blade.php:71` | `sekcja-strony mt-8` | sekcja strony |
| `pages/settings/email.blade.php:116` | `@class([...]) — panel-formularza` | panel formularza |
| `pages/settings/email.blade.php:117` | `@class([...]) — sekcja-strony` | sekcja strony |
| `pages/settings/privacy.blade.php:4` | `panel-formularza` | panel formularza |
| `pages/settings/privacy.blade.php:46` | `card flex items-center gap-3 flex-wrap` | karta treści |
| `pages/settings/profile.blade.php:7` | `panel-formularza` | panel formularza |
| `pages/settings/profile.blade.php:36` | `ramka-pomocnicza zdjecie-profilowe-skrot` | ramka pomocnicza |
| `pages/settings/security.blade.php:6` | `panel-formularza` | panel formularza |
| `pages/settings/security.blade.php:37` | `panel-formularza mt-8` | panel formularza |
| `pages/settings/security.blade.php:110` | `sekcja-strony mt-8` | sekcja strony |
| `pages/settings/two_factor/codes.blade.php:16` | `ramka-pomocnicza mb-5` | ramka pomocnicza |
| `pages/settings/two_factor/codes.blade.php:27` | `card lista-naga kod-do-przepisania p-5` | karta treści |
| `pages/settings/two_factor/enable.blade.php:16` | `sekcja-strony text-center` | sekcja strony |
| `pages/settings/two_factor/enable.blade.php:35` | `sekcja-strony mt-5` | sekcja strony |
| `pages/settings/two_factor/enable.blade.php:48` | `panel-formularza mt-5` | panel formularza |
| `pages/settings/two_factor/index.blade.php:23` | `sekcja-strony` | sekcja strony |
| `pages/settings/two_factor/index.blade.php:74` | `sekcja-strony` | sekcja strony |


### Panel moderacji — `pages/admin/`

_20 wystąpień: sekcja strony — 9, karta treści — 6, panel formularza — 5._

`pages/admin/kolaz-powitalny.blade.php` powstał PO napisaniu tego dokumentu
i trafił do inwentarza dopiero teraz. Nie było czego rozstrzygać: ekran
od pierwszego commita stosuje wzorzec panel/sekcja tak, jak opisują go
role 2 i 3 — podgląd „Co widzi teraz gość" jest sekcją, bo niczego nie
wymaga, a panel siedzi na `<form>`, nie na sekcjach w środku, jak
w `admin/daily-board`. Wpisanie go tutaj jest uzupełnieniem spisu,
nie decyzją o roli.

| plik:linia | klasa | rola |
|---|---|---|
| `pages/admin/appeals.blade.php:56` | `card odwolanie` | karta treści |
| `pages/admin/bez-odpowiedzi.blade.php:38` | `card czeka czeka-{{ $wpis->pilnosc }}` | karta treści |
| `pages/admin/daily-board.blade.php:26` | `panel-formularza` | panel formularza |
| `pages/admin/kolaz-powitalny.blade.php:60` | `sekcja-strony mb-6` | sekcja strony |
| `pages/admin/kolaz-powitalny.blade.php:117` | `panel-formularza` | panel formularza |
| `pages/admin/reports.blade.php:78` | `card mb-5` | karta treści |
| `pages/admin/sygnaly.blade.php:34` | `card mb-5` | karta treści |
| `pages/admin/tag-promotions.blade.php:27` | `panel-formularza mb-6` | panel formularza |
| `pages/admin/tag-promotions.blade.php:54` | `sekcja-strony` | sekcja strony |
| `pages/admin/uzytkownicy.blade.php:134` | `sekcja-strony` | sekcja strony |
| `pages/admin/uzytkownik.blade.php:42` | `sekcja-strony mb-5` | sekcja strony |
| `pages/admin/uzytkownik.blade.php:118` | `sekcja-strony mb-5` | sekcja strony |
| `pages/admin/uzytkownik.blade.php:140` | `sekcja-strony` | sekcja strony |
| `pages/admin/wiadomosc.blade.php:41` | `card` | karta treści |
| `pages/admin/wiadomosc.blade.php:45` | `sekcja-strony mt-5` | sekcja strony |
| `pages/admin/wiadomosc.blade.php:98` | `@class([...]) — panel-formularza` | panel formularza |
| `pages/admin/wiadomosc.blade.php:99` | `@class([...]) — sekcja-strony` | sekcja strony |
| `pages/admin/wiadomosc.blade.php:213` | `panel-formularza mt-5` | panel formularza |
| `pages/admin/wiadomosci.blade.php:37` | `card mb-5` | karta treści |
| `pages/admin/wiadomosci.blade.php:61` | `sekcja-strony` | sekcja strony |


### Zgłoszenia i odwołania

_14 wystąpień: sekcja strony — 10, panel formularza — 3, karta treści — 1._

| plik:linia | klasa | rola |
|---|---|---|
| `pages/appeals/create.blade.php:16` | `sekcja-strony` | sekcja strony |
| `pages/appeals/create.blade.php:33` | `sekcja-strony mt-5` | sekcja strony |
| `pages/appeals/create.blade.php:60` | `sekcja-strony mt-5` | sekcja strony |
| `pages/appeals/create.blade.php:72` | `panel-formularza mt-5` | panel formularza |
| `pages/appeals/guest.blade.php:21` | `panel-formularza` | panel formularza |
| `pages/appeals/reporter.blade.php:16` | `sekcja-strony` | sekcja strony |
| `pages/appeals/reporter.blade.php:26` | `sekcja-strony mt-5` | sekcja strony |
| `pages/appeals/reporter.blade.php:53` | `sekcja-strony mt-5` | sekcja strony |
| `pages/appeals/reporter.blade.php:65` | `panel-formularza mt-5` | panel formularza |
| `pages/zgloszenia/lista.blade.php:26` | `card mb-3` | karta treści |
| `pages/zgloszenia/szczegoly.blade.php:23` | `sekcja-strony` | sekcja strony |
| `pages/zgloszenia/szczegoly.blade.php:44` | `sekcja-strony mt-5` | sekcja strony |
| `pages/zgloszenia/szczegoly.blade.php:53` | `sekcja-strony mt-5` | sekcja strony |
| `pages/zgloszenia/szczegoly.blade.php:87` | `sekcja-strony mt-5` | sekcja strony |


### Strony publiczne i pozostałe ekrany

_24 wystąpienia: ramka pomocnicza — 6, panel formularza — 6, sekcja strony — 5, karta treści — 4, kafel akcji — 3._

`pages/notifications.blade.php:32` **wypadł ze spisu** — drugi duch
z komentarza Blade. Prawdziwa karta powiadomienia stoi w linii 82 i jest
w tabeli niżej.

| plik:linia | klasa | rola |
|---|---|---|
| `pages/account/cancel-deletion.blade.php:12` | `panel-formularza` | panel formularza |
| `pages/add.blade.php:62` | `kafel-akcji` | kafel akcji |
| `pages/add.blade.php:71` | `kafel-akcji` | kafel akcji |
| `pages/collections/index.blade.php:32` | `card` | karta treści |
| `pages/collections/index.blade.php:56` | `panel-formularza mt-8` | panel formularza |
| `pages/home.blade.php:29` | `kafel-akcji composer` | kafel akcji |
| `pages/napisz-do-nas-potwierdzenie.blade.php:17` | `sekcja-strony` | sekcja strony |
| `pages/napisz-do-nas.blade.php:76` | `ramka-pomocnicza mb-5` | ramka pomocnicza |
| `pages/napisz-do-nas.blade.php:93` | `panel-formularza` | panel formularza |
| `pages/napisz-do-nas.blade.php:146` | `ramka-pomocnicza mt-5` | ramka pomocnicza |
| `pages/notifications.blade.php:82` | `card mb-3 @if($notification->isUnread()) notification-nieprzeczytane @endif` | karta treści |
| `pages/onboarding/done.blade.php:34` | `ramka-pomocnicza mt-8` | ramka pomocnicza |
| `pages/onboarding/people.blade.php:36` | `ramka-pomocnicza mb-6` | ramka pomocnicza |
| `pages/podsumowanie-wrocono.blade.php:12` | `sekcja-strony` | sekcja strony |
| `pages/podsumowanie-wypisano.blade.php:17` | `sekcja-strony` | sekcja strony |
| `pages/profile/connections.blade.php:43` | `card flex gap-3 items-center justify-between flex-wrap` | karta treści |
| `pages/profile/show.blade.php:51` | `sekcja-strony mb-6` | sekcja strony |
| `pages/report.blade.php:10` | `panel-formularza` | panel formularza |
| `pages/search.blade.php:33` | `panel-formularza` | panel formularza |
| `pages/search.blade.php:173` | `card flex gap-3 items-center` | karta treści |
| `pages/zglos-nielegalna-tresc-potwierdzenie.blade.php:12` | `sekcja-strony` | sekcja strony |
| `pages/zglos-nielegalna-tresc.blade.php:43` | `ramka-pomocnicza mb-5` | ramka pomocnicza |
| `pages/zglos-nielegalna-tresc.blade.php:65` | `panel-formularza` | panel formularza |
| `pages/zglos-nielegalna-tresc.blade.php:132` | `ramka-pomocnicza mt-5` | ramka pomocnicza |

