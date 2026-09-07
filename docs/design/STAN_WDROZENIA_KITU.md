# UI kit v2 — co jest wdrożone, a co nie

Porównanie zrobione **przez zrzuty ekranu**: aplikacja lokalna i `kit-v2/html/`
otwarte w tej samej przeglądarce, przy tej samej szerokości okna (1280 px
i 390 px). Nie na oko z opisu.

Data: 6 września 2026. Aktualizacja tego samego dnia — etap C (ekran przepisu).

## Wniosek w jednym zdaniu

Kolory, typografia i logo **są wdrożone i zgadzają się co do wartości**.
Nie zgadza się **układ**: nawigacja, prawa szyna, zakładki feedu i część akcji
na kartach. Dlatego produkt „nie wygląda jak kit", mimo że paleta jest ta sama.

## Tokeny: 39 z 53 identycznych

Porównane maszynowo (`kit-v2/css/tokens.css` przeciw `resources/css/tokens.css`).

**Zgadza się co do znaku:** wszystkie kolory powierzchni, tekstu, marki, akcentu,
błędu, sukcesu i fokusu; cała skala typograficzna (16/18/20/22/24/28/36);
wszystkie odstępy; promienie; oba cienie; tryb ciemny.

**Różni się:**

| token | kit | repozytorium | co z tym |
|---|---|---|---|
| `--leading-title` | 1.25 | **1.4** | nagłówki w aplikacji są luźniejsze niż w kicie |
| `--radius-xl` | 1.5rem | brak | używany w kicie na dużych kartach |
| `--content-max` | 45rem (720 px) | brak | szerokość kolumny treści |
| `--sidenav-width` | 15rem (240 px) | brak | szerokość lewej nawigacji |
| `--control-min` / `--touch-min` | 3rem / 2.75rem | brak jako token | wartości są w kodzie, ale nie jako nazwana zmienna |
| `--font-readable` | Atkinson Hyperlegible | brak | tryb dostępności, nie podstawowa marka |

Nazewnictwo odstępów różni się celowo: kit ma `--space-*`, repozytorium
`--spacing-*` (tak wymaga Tailwind 4). `IMPLEMENTATION_GUIDE.md` §4 punkt 2
mówi wprost: nazwy z repozytorium są źródłem prawdy.

## Układ: to jest cała różnica

### `/home` — desktop

| element | kit | aplikacja |
|---|---|---|
| pole wyszukiwania w belce | **jest**, na środku, pełna szerokość | brak — wyszukiwarka tylko w lewej nawigacji |
| awatar w belce | jest, z rozwijanym menu | brak |
| lewa nawigacja | 5 pozycji: Start, Szukaj, Dodaj, **Moje**, Profil | 7 pozycji, inna kolejność, „Zeszyt" zamiast „Moje" |
| Powiadomienia i Ustawienia | **na dole** lewej kolumny, oddzielone | w jednym ciągu z resztą |
| prawa szyna | **„Mój zeszyt"** (3 zapisane przepisy z miniaturami) + **„Poznaj inspirujących ludzi"** | **brak — układ jednokolumnowy** |
| zakładki feedu | **„Obserwowani / Odkrywaj"** z podkreśleniem | brak; zamiast tego notka tekstowa |
| composer | „Co dziś gotujesz?" + awatar + ikona zdjęcia za pionową kreską | inny tekst, brak separatora |
| „kuKINGi na dziś" | **nie ma tego w kicie** | zajmuje główną kolumnę pod composerem |

### `/home` — telefon

| element | kit | aplikacja |
|---|---|---|
| dolna nawigacja | 5 pozycji z **dużym okrągłym „+" na środku** | 5 równych pozycji, bez wyróżnionego środka |
| podpisy | Start, Szukaj, **Dodaj (w kółku)**, Moje, Profil | Start, Szukaj, Dodaj, Zeszyt, Profil |

### Karta wpisu

| element | kit | aplikacja |
|---|---|---|
| nagłówek | nazwisko + „2 godz. temu **· publicznie**" + menu „…" | nazwisko + data, bez widoczności i bez menu |
| akcje | Ugotowałem · Komentarze (12) · **Zapisz** | Ugotowałem · Komentarze · Zgłoś |

„Zapisz" na wpisie to **nowa funkcja produktowa**, nie brakujący przycisk:
zeszyt przyjmuje dziś wyłącznie przepisy. Wymaga decyzji właściciela.

## Czego kit NIE ma, a produkt ma — i musi zachować

To jest lista rzeczy, których nie wolno zgubić przy przestylowaniu.
`IMPLEMENTATION_GUIDE.md` §4 punkt 4 mówi to samo, krócej:
„nie zmieniaj kontrastów bez ponownego przeliczenia".

- **55 policzonych par kontrastu** i tokeny `-tint-ink` / `-solid`, których kit
  nie rozróżnia. Bez nich bieżąca pozycja nawigacji miała w trybie ciemnym
  kontrast 2.32 przy wymaganych 4.5 (issue #26).
- **Skala tekstu użytkownika** (`--user-text-scale`): cała typografia jest przez
  nią mnożona, odstępy celowo nie. Kit ma wartości stałe.
- **Wszystko działa bez JavaScriptu.** Kit jest statycznym HTML-em, więc nie
  odpowiada na pytanie, jak zachowa się zakładka albo menu „…" przy wyłączonym
  skrypcie. W tym produkcie to warunek, nie ulepszenie (AGENTS.md §5).
- **Automat dostępności** przy każdej zmianie: axe na 14 ekranach w czterech
  wariantach plus pomiar przewijania w poziomie przy 320–768 px.

---

## Etap C — ekran przepisu (wdrożony 6 września 2026)

| element z kitu (ekrany 02 i 06) | co jest w aplikacji |
|---|---|
| okruszki „Start › Przepisy” | **jest** |
| hero: zdjęcie obok panelu | **jest**; bez zdjęcia panel bierze całą szerokość zamiast zostawiać pustą połowę |
| kafle: czas / porcje / poziom | **jest**, ale tylko te, które autor podał — kafel „—” nie jest informacją |
| akcje w panelu (Zapisz, Ugotowałem) | **jest**, w pionie i na pełną szerokość panelu; dochodzi trzecia, „Gotuję” |
| „Skąd ten przepis?” w panelu | **jest**, razem ze zdjęciem kartki |
| składniki obok kroków | **jest** (dwie kolumny od 60rem, składniki pierwsze niżej) |
| składniki z kolumną ilości | **nie i nie będzie** — D-017 |
| kroki z tytułami („Przygotuj ciasto”) | **nie i nie będzie** — D-017 |
| „Jak wyszło innym?” | jest jako „Komu wyszło”; sam napis zmieni #38 |
| znak „Uśmiech” w przycisku „Ugotowałem” | **świadomie nie**: znak rysuje garnek kolorem bieżącym, a uśmiech kolorem powierzchni — na tle marki wychodzi biała plama bez uśmiechu |

Główna akcja przeniosła się z **dołu strony** do panelu przy zdjęciu. To jest
największa zmiana produktowa w tym etapie, nie kosmetyczna: „Ugotowałem”
leżało pod krokami, czyli widział je tylko ten, kto przewinął cały przepis.

Nowe: `wide` w `<x-layout>` podnosi sufit kolumny z 45rem do całej szerokości
po nawigacji. Sufit czytelności nie znika — przenosi się na pojedyncze bloki
z ciągłym tekstem (`.kolumna-czytania`).

### Zostaje z etapu C do zrobienia

Nic — `CookedCard` w układzie z kitu wdrożony 7 września 2026: pasek liczb
plus realna paginacja `x-show-more` w miejsce `->limit(12)->get()`, które nie
dawało żadnej drogi do wykonań 13. i dalszych. Świadomie BEZ „jednego
reprezentatywnego wiersza ze zdjęciem-miniaturą" z kitu: zdjęcie cudzego
wykonania zostaje pełnowymiarowe (`docs/UX_50_PLUS.md`). Zmierzone
w `tests/Feature/KomuWyszloWydajnoscTest.php`.

### Nadal otwarte z listy tokenów

`--leading-title` (1.25 w kicie, 1.4 u nas) zostaje **1.4**. Luźniejszy
nagłówek jest tu decyzją dla grupy 50+, a nie rozjazdem — zmiana dotknęłaby
każdego ekranu i należy do osobnej decyzji, nie do przestylowania przepisu.

`DESIGN_SYSTEM.md` §9 pytał o domyślny limit `CookedCard` (sugerował 5) —
**rozstrzygnięte 7 IX 2026 na 12**, spójnie z `ProfileController` i
`comments.page_size`. Jeden krok „Pokaż więcej" ma być wszędzie podobny.

---

## Konflikt kitu z COPY_STYLE — rozstrzygnięty na rzecz COPY_STYLE (7 IX 2026)

`docs/HANDOVER.md` wymieniał to jako pracę do issue #38: „kit mówi »Jak wyszło
innym?«, a aplikacja »Komu wyszło«". **Zmierzone: to nie jest błąd aplikacji.**

- `docs/brand/COPY_STYLE.md` §6 („Ugotowałem", wiersz „sekcja pod przepisem")
  mówi **„Komu wyszło"** — i ten dokument jest wprost wiążący dla każdego
  tekstu widocznego dla użytkownika.
- `docs/design/kit-v2/html/02_desktop_recipe.html` i
  `docs/design/prototype/recipe.html` mówią **„Jak wyszło innym?"**.
- `resources/views/pages/recipes/show.blade.php` mówi **„Komu wyszło"**,
  czyli zgadza się z COPY_STYLE.

Do przepisania jest więc KIT, nie aplikacja — a dokładniej: makiety HTML
w tym katalogu są materiałem projektowym z wcześniejszego etapu i tam, gdzie
mówią coś innego niż COPY_STYLE, **wiąże COPY_STYLE**. Nie zmieniam samych
plików makiet, bo są zapisem tego, co projektant narysował; zmieniam status
tej rozbieżności z „zaległość w aplikacji" na „makieta jest starsza niż
decyzja o głosie marki".

### Przy okazji zmierzone: tekstów do przepisania po #38 jest mniej, niż zapisano

Przeskanowałem wszystkie widoki (`resources/views/**/*.blade.php`) pod listę
słów zakazanych z `docs/brand/BRAND_EXTENDED.md` §2.1 (`feed`, `explore`,
`content`, `onboarding`, `punkty`, `poziom`, `odznaka`, `senior`, `kolekcja`,
`swipe`, `mniam`…), pod wykrzykniki (twardy zakaz w produkcie) i pod emoji.

**W tekstach widocznych dla człowieka: zero trafień.** Wszystkie dwadzieścia
trafień siedzi w KOMENTARZACH w kodzie widoków — i to takich, które wprost
tłumaczą, dlaczego czegoś nie ma: `ikona.blade.php` wypisuje emoji, których
nawigacja już NIE używa; `home.blade.php` cytuje zakazane „wspaniały dzień?!"
jako przykład tekstu, którego nie piszemy; `karuzela-zdjec.blade.php` cytuje
zakaz swipe'a z `UX_50_PLUS.md`. To jest dokładnie ta klasa fałszywego
trafienia, przed którą trzeba się bronić przy pisaniu takiego pomiaru:
pierwsza wersja mojego skryptu zgłosiła 74 problemy, z czego wszystkie były
zmiennymi PHP (`$user`) i operatorem `!==` w blokach `@php`.

Wniosek dla #38: zostaje praca redakcyjna nad KONKRETNYMI ekranami (kit etap
D), a nie przegląd całego interfejsu pod kątem słów zakazanych — ten przegląd
jest zrobiony i wychodzi czysto.

---

## Etap D — cztery obszary ekranów (wdrożony 7 września 2026)

Etap D wdrażało pięć równoległych zleceń. Żadne nie mogło pisać w tym samym
pliku, więc każdy obszar dostał **własny arkusz CSS** wpięty importem
(`ekran-wyszukiwania.css`, `ekran-profilu.css`, `ekran-dodawania.css`,
`karta-ugotowania.css`), a `app.css` został przy szkielecie strony i menu.
Wspólny plik oznaczałby, że drugie zlecenie zapisuje stan sprzed pierwszego —
czyli cicha utrata pracy, nie konflikt, który cokolwiek zgłasza.

**Wniosek przekrojowy z całego etapu: kit przegrywa z COPY_STYLE za każdym
razem, gdy się różnią, i nie jest to wyjątek — to reguła.** Rozstrzygnięcie
z sekcji wyżej („Komu wyszło" zamiast „Jak wyszło innym?") powtórzyło się na
trzech kolejnych ekranach: „Szukaj" zamiast „Szukaj i odkrywaj", pełny podpis
„Powiadomienia" zamiast samej ikony dzwonka, trzy karty widoczności zamiast
listy rozwijanej. Makiety HTML w `kit-v2/` są materiałem projektowym
z wcześniejszego etapu; tam, gdzie mówią coś innego niż `COPY_STYLE.md`,
`BRAND_EXTENDED.md` albo `UX_50_PLUS.md`, wiążą te trzy.

### Wyszukiwarka i „odkrywaj" (ekrany 03, 07)

| element z kitu | co jest w aplikacji |
|---|---|
| nagłówek „Szukaj i odkrywaj" | **„Szukaj"** — `BRAND_EXTENDED.md` §1.1 ma „Odkrywaj"/„Discover" na liście słów, których nie używamy |
| duże pole z lupą | **jest**; etykieta „Czego szukasz?" ZOSTAJE widoczna nad polem — kit ma tam tylko placeholder, `UX_50_PLUS.md` wymaga etykiety |
| prawa szyna | **jest** — tablica „kuKINGi na dziś", ta sama usługa domenowa co na `/home` i `/odkryj`. Kolumna była zarezerwowana od etapu A/B i zawsze pusta; brakowało treści, nie mechanizmu |
| przycisk „Filtry" | **świadomie nie** — nie ma dziś wymiaru filtrowania poza chipami, byłby ozdobą prowadzącą w nikąd |
| „Smaki września" (tagi sezonowe z liczbą przepisów) | **nie i nie teraz** — `DECISIONS.md` świadomie odrzucił kolumnę `sezonowy`; zbudowanie tego przez czytanie słownika w locie byłoby cichą decyzją architektoniczną w widoku |
| liczba obserwujących przy osobie | **nigdy** — anty-wzorzec zakazany wprost w `AGENTS.md` §12 |
| „Popularne teraz" (wyniki bez frazy) | **nie** — ranking bez pomiaru, `AGENTS.md` §8 |
| karta wyniku z czasem i porcjami w wierszu | `recipe-card` tego nie ma; wymagałoby zmiany komponentu współdzielonego z feedem i profilem |
| ekran mobilny (07) | ten sam szablon Blade, różnica wyłącznie w CSS |

Bugfix przy okazji: pusty stan `/odkryj` łamał regułę zapisaną w komentarzu
własnego komponentu (brak przycisku, tekst mówiący o serwisie zamiast o tym,
co człowiek może zrobić). Naprawione, test regresyjny `OdkrywaniePustyStanTest`.

axe-core (WCAG 2a/2aa/21aa): 0 naruszeń na `/szukaj` i `/odkryj` przy 1280,
390 i 320 px. Bez przewijania w poziomie.

### Profil i archiwum (ekran 04)

| element z kitu | co jest w aplikacji |
|---|---|
| awatar + imię + `@login · region` + bio w jednej kolumnie | **jest** |
| liczniki pod opisem, w tej samej kolumnie co imię | **jest** — wcześniej stały jako osobny, pełnoszerokościowy wiersz pod całą główką |
| przycisk akcji w trzeciej kolumnie obok imienia | **świadomie nie** — kit rysuje zawsze jeden przycisk, a tutaj bywają trzy naraz, w tym destrukcyjny „Zablokuj"; wciśnięcie ich w wąską kolumnę przy 720 px złamałoby regułę odstępu akcja/destrukcja (`DESIGN_SYSTEM.md` §3.1) |
| siatka dwukolumnowa miniatur w archiwum | **nie i jeszcze nie** — wymaga wariantu „mini" we współdzielonym `x-post-card`; decyzja właściciela, nie techniczna |
| duży awatar „xl" | **nie** — `app.css` definiuje rozmiary awatara do 88 px, a to już maksimum |

Przełamanie układu główki (awatar obok tekstu) następuje od `--breakpoint-md`
(768 px), nie od progu bocznej nawigacji (1024 px): kolumna treści ma stałe
45rem powyżej 1024 px, więc szerokość dostępna dla główki jest ta sama od
768 px w górę i nie ma na co czekać.

Kit nie ma mobilnej makiety profilu — `09_mobile_menu_profile.html` to ekran
menu konta, nie profil z archiwum. Jedna kolumna na telefonie jest własną
interpretacją zgodną z resztą aplikacji, nie odwzorowaniem czegokolwiek
narysowanego.

### Ekran dodawania (ekran 08)

| element z kitu | co jest w aplikacji |
|---|---|
| duży obszar `.photo-picker` (ikona + „Dodaj zdjęcie" + pomoc) | **jest**, na wszystkich trzech drogach dodawania; natywny `<input type="file">` zostaje w pełni widoczny i klikalny w jego wnętrzu |
| napis zmienia się na „Zmień zdjęcie", gdy pole ma już plik | **jest** |
| `.m-info` pod przyciskiem publikacji | **jest** na `/dodaj/zdjecie`: „Możesz zmienić lub usunąć wpis później. Zdjęcia publikujemy bez danych EXIF i GPS." |
| `.select` „Kto może zobaczyć?" | **świadomie nie** — trzy duże, zawsze widoczne karty. Ten sam wzorzec co D-017: kit bywa uproszczony kosztem czytelności dla tej grupy |
| karty wyboru „Zdjęcie z gotowania" / „Pełny przepis" na jednym ekranie z formularzem | **nie i nie teraz** — `/dodaj` zostaje dwuetapowe; scalenie to zmiana produktowa, nie CSS |
| kolejność pól, etykiety, błędy przy polu plus podsumowanie, karta formularza | **już było zgodne** — zmierzone, nieprzestylowane na siłę |

`PhotoPicker` wdrożony **bez** wersji z `COMPONENTS_BLADE.md` §7 (Alpine
i `URL.createObjectURL`), bo dwie z trzech dróg dodawania istnieją właśnie po
to, żeby działać bez JavaScriptu. Mechanika minutnika i tożsamości kroku
(issue #21, wdrożone tego samego dnia) nietknięta: `MinutnikIZdjecieKrokuTest`
32/32 przed i po.

### Menu mobilne (ekran 09, część menu)

| element z kitu | co jest w aplikacji |
|---|---|
| logo i wordmark w lewym rogu, pasek dolny z pięcioma pozycjami, „Dodaj" w uniesionym kółku | **jest** od etapów A/B |
| bieżąca pozycja kolorem i pogrubieniem | **jest**, plus pasek 3px u góry — kolor nigdy nie jest jedynym sygnałem (WCAG 1.4.1) |
| „Dodaj" aktywne przez cały proces dodawania | **było brakujące, naprawione** — podświetlało się wyłącznie na `/dodaj`, nie na `/dodaj/zdjecie`, `/dodaj/przepis` ani `/dodaj/przepis/jedna-strona`. Menu przestawało pokazywać, gdzie jest użytkownik, dokładnie wtedy, gdy coś dodawał |
| dzwonek jako sama ikona | **świadomie nie** — pełny podpis „Powiadomienia" z licznikiem na każdej szerokości; ikona bez podpisu łamałaby `AGENTS.md` §5 |
| pasek dolny znika na desktopie, zastępuje go nawigacja boczna | **jest**, próg 64rem |

Zmierzone Playwrightem na własnej bazie z produkcyjnym buildem: 11 ekranów ×
3 szerokości (320/390/768 px) × gość i zalogowany, plus skala tekstu 140%
i motyw ciemny. Zero przewijania w bok, zero naruszeń axe-core na obu paskach.

**Menu działa bez JavaScriptu i to jest zmierzone, nie zadeklarowane**:
Playwright z `javaScriptEnabled: false` — logowanie zwykłym POST-em
i nawigacja obu pasków prowadzą na właściwe adresy. Żadnego „hamburgera" ani
`<details>` w menu dziś nie ma; kit też go tu nie ma, więc nie było czego
portować.

### Otwarte po etapie D — wymaga decyzji właściciela

- **Wariant „mini" `x-post-card`** dla dwukolumnowej siatki archiwum. Zdanie
  wykonawcy: pełne karty są lepsze dla 50+ (większe zdjęcie, czytelniejszy
  tekst, bez nauki nowego wzorca), ale to decyzja produktowa.
- **Scalenie `/dodaj`** z formularzem w jeden ekran, jak rysuje kit.
- **`text_scale` w bazie ograniczone do 90–140**, a `DESIGN_SYSTEM.md`
  dokumentuje krok 150%. Constraint siedzi w migracji.
- **Gość nie ma pola wyszukiwania** — pole w górnej belce jest `@auth`-owane.
  Realna luka nawigacyjna dla niezalogowanych, dotyczy globalnej belki.
- **`recipe-card` bez czasu i porcji** obok tytułu, jak w kicie.
- **Automat dostępności** (`scripts/dostepnosc.mjs`) na 23 ekranach ×
  4 warianty nie był uruchamiany w trakcie etapu D, bo pięć zleceń pisało
  równocześnie w tym samym repozytorium i wynik nie byłby miarodajny dla
  żadnej pojedynczej zmiany. Do uruchomienia teraz, na scalonym stanie.
