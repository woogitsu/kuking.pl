# System projektowy Kuking.pl

Kuking.pl to polski serwis do domowego gotowania. Pokazuje się w nim, co się
dziś ugotowało, i zapisuje przepisy, żeby nie ginęły. Przy każdym przepisie
jest napisane, po kim on jest — bo to najczęściej czytana część każdego
przepisu i zwykle jej nie ma.

Serwis stoi na Laravelu, Blade i Tailwindzie 4 w konfiguracji CSS-first.
Jest przed startem: zamknięta beta, docelowo 20 → 200 → 2000 osób, ani jednego
prawdziwego konta w dniu spisania tego dokumentu. Prowadzi go jedna osoba,
na własną rękę.

**Odbiorca ma 50+ lat i mieszka w Polsce.** To decyduje w tym systemie o więcej
rzeczach niż cokolwiek innego: o rozmiarze podstawowym 18 px, o zakazie ikon bez
podpisu, o tym, że motyw zmienia się wyłącznie po jawnym wyborze człowieka,
i o tym, że nie ma tu rankingów. **Nigdzie w produkcie ani w materiałach nie
piszemy, dla kogo wiekowo jest ten serwis.**

---

## Skąd to pochodzi

Cały system jest przepisany z paczki **`KuKING-design-system-v3.1-poprawiony`**
(wersja 3.1 z 7 września 2026), dostarczonej przez właściciela jako archiwum ZIP.
Nic tu nie jest nowym projektem: wartości tokenów, klasy, teksty i decyzje są
przeniesione co do znaku. Oryginał leży w `uploads/` i jest rozstrzygający.

| Plik źródłowy | Co z niego wzięliśmy |
|---|---|
| `01-fundamenty/tokens.css` | wszystkie tokeny (`@theme` zamienione na `:root`, wartości bez zmian) |
| `01-fundamenty/TYPOGRAFIA.md`, `KOLOR.md`, `SIATKA.md`, `RUCH-I-STANY.md` | fundamenty i karty specyfikacji |
| `02-komponenty/KOMPONENTY.md` + `komponenty.css` | 28 komponentów ze stanami, arkusz warstwy komponentów |
| `03-szablony/` | trzy kształty treści: strumień kart, długi dokument z panelem, długi formularz |
| `04-strona-www/` + `strona-www.css` | trzy strony publiczne i ich arkusz |
| `05-social-media/` + `szablony.css` | plansze społecznościowe i materiały drukowane, 69 gotowych wpisów |
| `06-marka/ZNAK.md` + `znak/*.svg` | zasady znaku i pliki znaku |
| `00-decyzje/` | 12 rozstrzygnięć D-101…D-112 i lista rzeczy nietykanych |
| `07-wdrozenie/`, `08-audyt/` | lista kontrolna dostępności, zrzuty odniesienia |

Dokumenty, do których paczka się odwołuje, ale których w niej **nie ma**:
`COPY_STYLE.md`, `BRAND_EXTENDED.md`, `BRAND_IDENTITY.md`, `BRAND.md`,
`UX_50_PLUS.md`, `MASCOT_CONCEPT.md`, `DESIGN_SYSTEM.md`,
`STAN_WDROZENIA_KITU.md`, `01-stan-obecny/inwentarz-ekranow.md`. Wszystko, co
z nich cytujemy, jest cytatem z drugiej ręki — przez paczkę v3.1.

---

## Zawartość tego systemu

| Ścieżka | Co to jest |
|---|---|
| `styles.css` | jedyne wejście dla consumera — lista `@import` |
| `tokens/` | `colors.css`, `typography.css`, `spacing.css`, `radii-shadows.css`, `layout.css`, `motion.css`, `fonts.css`, `base.css` |
| `components.css` | warstwa komponentów (159 klas, przepisana z paczki) |
| `site.css` | arkusz stron publicznych (pasy, hero, panel przepisu) |
| `boards.css` | plansze społecznościowe i arkusze do druku |
| `components/` | komponenty Reacta, pogrupowane po zastosowaniu |
| `guidelines/` | karty specyfikacji: kolor, typografia, rytm, marka |
| `ui_kits/` | trzy odtworzone powierzchnie produktu |
| `assets/` | znak, ikony, zdjęcia demonstracyjne |
| `SKILL.md` | opis do użycia tego systemu jako Agent Skill |
| `AUDYT.md` | co sprawdzono przy przenoszeniu paczki, co się nie zgadzało i co z tym zrobiono |

### Komponenty

**`components/actions/`** — `Button`, `ButtonRow`, `Chip`, `ChipRow`,
`ConfirmDestructive`, `ShowMore`.

**`components/forms/`** — `Field`, `FieldRow`, `Checkbox`, `ChoiceGroup`
(+ stała `WIDOCZNOSC_DOMYSLNA`), `PhotoPicker`.

**`components/cards/`** — `PostCard`, `RecipeCard`, `CookedCard`,
`PhotoWithCaption`.

**`components/feedback/`** — `Badge`, `Alert`, `ErrorSummary`, `AutosaveBadge`,
`EmptyState`, `WizardSteps`.

**`components/navigation/`** — `TopBar`, `SideNav` (+ `POZYCJE_NAWIGACJI`),
`BottomNav` (+ `POZYCJE_DOLNE`), `SiteFooter`.

**`components/content/`** — `Avatar`, `NotificationRow`, `RowList`,
`CommentThread`, `RailModule`, `RailPerson`, `RailDish`, `AuthorRow`,
`DataTable`.

**`components/recipe/`** — `RecipePhoto`, `RecipeOrigin`, `IngredientList`,
`StepList`.

**`components/brand/`** — `Icon` (+ `NAZWY_IKON`), `Wordmark`.

**`components/layout/`** — `AppShell`, `Section`.

Przy każdym komponencie leży `.d.ts` z kontraktem właściwości i `.prompt.md`
z jednym zdaniem „co i kiedy”, przykładem oraz listą rzeczy, których przy nim
nie wolno zrobić.

### Dodatki świadome (nie ma ich w spisie 28 komponentów paczki)

- **`Icon`** — opakowanie na sprite `podglad/ikony-sprite.html`. Geometria jest
  wklejona w komponent, bo zewnętrzny `<use href="plik.svg#id">` nie działa przez
  granicę dokumentu. Wersja `name="znak"` naprawia usterkę zapowiedzianą
  w `ZNAK.md` §8.1: uśmiech jest **wycięty maską**, a nie malowany na biało.
- **`Wordmark`** — zapis nazwy z `ZNAK.md` §1.4 wyjęty z belki, żeby nie
  przepisywać go ręcznie w każdym widoku.
- **`AppShell` i `Section`** — szkielet z `komponenty.css` §1 i `SIATKA.md`;
  klasy istnieją w paczce, komponentu nie było.
- **`RowList`, `ButtonRow`, `ChipRow`, `FieldRow`** — otoczki dla klas
  `.lista-wierszy`, `.rzad-przyciskow`, `.chipsy`, `.rzad-pol`.
- **`RailPerson`, `RailDish`** — wiersze modułu szyny, opisane w `KOMPONENTY.md`
  §20 jako części tego samego komponentu.

### Reguły CSS dopisane poza paczką

Cztery miejsca, w których arkusz musiał dostać coś, czego w paczce nie było.
Wszystkie są oznaczone w kodzie:

1. **Odpowiednik resetu Tailwinda** (`tokens/base.css`). Arkusze źródłowe były
   pisane pod preflight Tailwinda 4 i milcząco na nim polegają. Bez
   `box-sizing: border-box` `padding` dolicza się do szerokości i **belka górna
   wystaje poza stronę o 48 px**. Zakres jest dokładnie taki, na jakim opierają
   się arkusze: `box-sizing`, wyzerowane marginesy `blockquote`/`figure`/
   `fieldset`, listy bez punktora, dziedziczenie kroju w kontrolkach,
   `svg { display: block }`.
2. **`.karta-wpisu > .karta-tytul:first-child`** — karta bez główki zaczyna się
   tytułem, a `.karta-tytul` ma margines tylko z dołu; tytuł dotykał krawędzi.
3. **`.choice-help { font-weight: 400 }`** — `.choice` jest etykietą, a warstwa
   base daje każdej etykiecie wagę 700. `.pole-zaznaczenia` zeruje to u siebie,
   `.choice-help` nie zerowało, więc wszystkie trzy opisy widoczności były
   pogrubione.
4. **`.rzad-pol .field { min-width: 0 }`** — zabezpieczenie na wąskie kolumny,
   żeby element rzędu mógł zejść poniżej swojej zawartości.

5. **`@media (max-width: 39.999rem)`: ikony akcji konta w belce górnej znikają.**
   Przy 380 px okna „Powiadomienia” (205 px) i „Konto” (143 px) mają razem
   348 px, a miejsca jest 332 px — druga akcja schodziła do drugiego wiersza
   i belka rosła do **209 px**. Ikona jest ozdobą, więc ustępuje pierwsza:
   bez ikon obie akcje mają 280 px, wchodzą w jeden wiersz, belka ma 128 px.
   Napisy zostają w całości. Karta „Telefon — 380 px” pokazuje wynik.

Jest też **jedno odjęcie**, jedyne w całym przeniesieniu: z reguły`.field-input` w §17 arkusza usunięto `max-width: 100%`. Ta deklaracja miała tę
samą wagę co cztery klasy szerokości pól, ale stała później w kaskadzie — więc
kasowała sufity 8ch, 10ch, 22ch i 40ch i **cała reguła D-109 była martwa**.
Szczegóły i pomiary w `AUDYT.md` §1.11.

### UI kity

| Kit | Co odtwarza |
|---|---|
| `ui_kits/serwis/` | jedenaście ekranów zalogowanego i gościa: logowanie, rejestracja, tablica, dodawanie, przepis, powiadomienia, zeszyt, profil, szukanie, czytelność, konto |
| `ui_kits/strona-www/` | strona powitalna, „O Kuking”, przepis dla gościa z wyszukiwarki |
| `ui_kits/dokumenty/` | trzeci kształt treści: polityka prywatności, regulamin, zasady |
| `ui_kits/materialy/` | plansze społecznościowe i materiały drukowane |

### Szablony startowe (`templates/`)

Pięć gotowych punktów wyjścia, które projekt korzystający z tego systemu kopiuje
zamiast składać ekran od zera:

| Szablon | Co daje |
|---|---|
| **Ekran serwisu** | belka, nawigacja boczna, strumień kart, szyna, dolny pasek, stopka |
| **Strona powitalna** | pasy na pełną szerokość, hero, cztery rzeczy, ciemny pas, wezwanie |
| **Dokument prawny** | kolumna czytania 38rem, spis treści na kotwicach, tabela |
| **Długi formularz** | sekcje, podsumowanie błędów, autozapis, kroki kreatora |
| **Plansza społecznościowa** | kwadrat 1080 i podgląd linku 1200×630 w prawdziwych wymiarach |

---

## FUNDAMENTY TREŚCI

### Jak pisze Kuking

Zdaniami, po polsku, bez marketingu. Mówimy **„my”** o serwisie („prowadzimy”,
„dodaliśmy”, „pytamy”) i **„Ty”** o człowieku po drugiej stronie („Twój Zeszyt”,
„Pokaż, co dziś ugotowałeś”). Nigdy nie mówimy o użytkowniku w trzeciej osobie.

Zdanie ma być takie, jakie powiedziałby ktoś przy kuchennym stole:

> „Rosół nie znosi pośpiechu. Ta strona zresztą też nie.”
>
> „Wyszło. I to się liczy.”
>
> „Zdjęcie nie musi być ładne. Ma być prawdziwe.”

### Cztery rejestry

**Ciepły z żartem** (wpis, podpis pod zdjęciem) · **ciepły bez żartu**
(podziękowanie, zwykły wpis) · **rzeczowy** (jak działa serwis, konto,
prywatność) · **poważny** (krytyka, spór, sprawa prywatności).
**Ruch odbywa się tylko w dół.** Wątek, który zszedł do rejestru poważnego, nigdy
nie wraca do żartu — także wtedy, gdy druga strona już się uspokoiła.

### Trzy zapisy nazwy — najczęściej mylona rzecz w tym projekcie

| Zapis | Gdzie | Przykład |
|---|---|---|
| `KuKing.pl` | **wyłącznie logotyp** | belka górna, favicon, materiały marki |
| `Kuking` | tekst ciągły, nagłówki, komunikaty, `alt`, e-maile | „Kuking to twój zeszyt z przepisami” |
| `kuKING` | **o człowieku, który tu gotuje** | „Zostań kuKINGiem”, „kuKINGi na dziś” |

Wolno odmieniać: `kuKINGiem`, `kuKINGów`, `kuKINGi` (w znaczeniu rzeczy).
Nie wolno: `kuKINGowi`, `kuKINGu`, `kuKINGowie`, `kuKINGujesz`, `KUKING`,
`Zostań Kukingiem`. **Formy żeńskiej nie tworzymy** — zamiast szukać formy,
przepisujemy zdanie („Gotujesz z nami od 2 lat”, nie „Jesteś kuKINGiem od 2 lat”).

Najwyżej **jedna gra słowem `kuKING` na ekran** i nigdy: w komunikacie błędu,
w wiadomości moderacyjnej, w regulaminie, w polityce prywatności,
w powiadomieniu o cudzej aktywności i w formularzu, który ktoś właśnie wypełnia.

**Korona siedzi na garnku, nie na niczyjej głowie.** To żart o garnku, nigdy
komplement dla człowieka. Zakazane wprost: „Jesteś królem kuchni”, „Zostań
królem kuchni”, „Twoja kulinarna korona”, „królowa kuchni”, „mistrzyni”.
Komplement podnosi poprzeczkę u kogoś, kto i tak nic nie publikuje, a cały ten
produkt ją obniża.

### Wielkość liter, znaki, emoji

- Nagłówek: **jedno zdanie, maksimum 8 słów**, kropka albo znak zapytania.
  Czasownik na początku, jeśli to wezwanie do działania.
- **Zero wykrzykników.** Zero wielkich liter w środku zdania. Zero CAPS LOCK-a
  poza wordmarkiem.
- **Zero emoji** — w produkcie i poza nim. Powód jest praktyczny, nie
  estetyczny: emoji w tym rejestrze wygląda jak próba mówienia cudzym językiem,
  a to jedna z rzeczy, które ten odbiorca wychwytuje natychmiast. Zdjęcie
  potrawy niesie ciepło lepiej niż ikonka ognia.
- Liczby **cyframi**, jednostki **pełnym słowem**: „90 minut”, „6 porcji”.
- Nagłówek nigdy nie jest jedynym nośnikiem informacji — pod nim stoi zdanie
  wyjaśniające i akcja z tekstem.

### Komunikaty i puste stany

Błąd trzyma schemat **co się stało → dlaczego → co zrobić**. Zdanie bez trzeciej
części jest niedokończone. Nigdy kodu HTTP, nigdy „Błąd walidacji”, nigdy żartu.

> „Nie udało się zalogować. Sprawdź, czy nazwa i hasło są wpisane poprawnie.
> Jeśli nie pamiętasz hasła, kliknij »Nie pamiętam hasła«.”

Pusty stan mówi, **co się tu pojawi i co zrobić**, żeby się pojawiło — nigdy
„Brak danych”:

> „Zeszyt jest jeszcze pusty. Kiedy znajdziesz przepis, który chcesz zachować,
> kliknij przy nim »Zapisuję«. Trafi tutaj i zawsze go znajdziesz.”

### Nazwy własne funkcji

**Zeszyt** (miejsce na zachowane przepisy) · **Ugotowałem** (przycisk i dowód,
że przepis komuś wyszedł) · **Skąd ten przepis** (po kim jest przepis) ·
**Komu wyszło** (sekcja z cudzymi wykonaniami) · **Świeżo z Kuking** ·
**Kto to widzi** (wybór widoczności). Nazwy funkcji się nie odmienia.
Słowo **„odkrywaj”** jest na liście słów zakazanych.

### Czego w treści nie ma

Rankingów, punktów, poziomów, odznak, liczby obserwujących, „popularne teraz”,
„dołącz do tysięcy”, budowania pilności („zostały dwa dni”, „ostatnia szansa”),
konkursów z nagrodą za publikowanie, chwalenia kogoś za to, że opublikował,
i **żadnej liczby, której nie ma z czego policzyć**.

---

## FUNDAMENTY WIZUALNE

### Charakter

Ciepła kuchnia, drewniany stół, pomidorowa, poranne światło. Terakota nawiązuje
do pomidorów i przypraw, a nie do aplikacji bankowej. To nie jest „beż dla
starszych” — szarawy, bezpłciowy — ani infantylny pastel.

### Kolor

Paleta jest **policzona**: 70 par kontrastu (35 w każdym motywie), wszystkie
przechodzą, `node 01-fundamenty/kontrast.mjs` kończy się kodem błędu, jeśli
któraś nie przejdzie. Progi: 4.5:1 dla tekstu, 3:1 dla tekstu dużego i elementów
interfejsu — przy tej grupie odbiorców 4.5:1 to minimum, nie cel, więc większość
par ma 6:1 i więcej.

Token nazywa **rolę**, nie barwę. Tryb ciemny nie jest odwróceniem jasnego, tylko
osobną paletą o tej samej strukturze ról — dlatego zmiana motywu nigdy nie wymaga
dotknięcia ani jednej reguły komponentu.

| Rola | Jasny | Ciemny |
|---|---|---|
| tło strony | `#FAF6F0` | `#1E1A16` |
| tło uniesione (karta) | `#FFFFFF` | `#2A241E` |
| tło wgłębione (pole) | `#F1EBE1` | `#14110E` |
| tło marki (hero, „Skąd ten przepis”) | `#F7E9E2` | `#2E211A` |
| atrament | `#2B241D` | `#F5EFE6` |
| atrament cichy | `#5C5347` | `#C9BEB0` |
| marka (tekst, link, znak) | `#B3401F` | `#F2986A` |
| marka pełna (tło przycisku) | `#B3401F` | `#C1502A` |
| akcent („Ugotowałem”) | `#7A5C10` | `#E3B341` |
| błąd / sukces | `#B3261E` / `#1E7B3E` | `#FF8A80` / `#7BD79A` |
| fokus | `#155EEF` | `#6EA8FF` |

**Kolor tekstu i kolor tła to nigdy nie jest ten sam token** — nawet gdy
w jednym motywie mają tę samą wartość. Stąd para `brand` / `brand-solid`
i `danger` / `danger-solid`.

**Jeden akcent na powierzchnię (D-110).** Na jednej karcie dokładnie jedna rzecz
ma kolor marki; na jednym ekranie dokładnie jedna akcja jest przyciskiem głównym.
Kolor marki wolno użyć na: przycisk główny, bieżącą pozycję nawigacji, kółko
„Dodaj”, link w tekście ciągłym, znak marki. **Nigdzie indziej.**

**Kolor nigdy nie jest jedynym nośnikiem informacji.** Link ma podkreślenie,
błąd ma ikonę i zdanie, sukces ma słowo, bieżąca pozycja ma `aria-current`
i grubszą wagę, „Obserwujesz” zmienia napis.

**Jak obejrzeć motyw ciemny.** Wszędzie tam, gdzie jest stopka, wystarczy
kliknąć „Ciemny” — `SiteFooter` bez właściwości `motyw` sam przestawia
`data-theme` na `<html>`. Poza tym są trzy karty pokazujące ciemny wprost:
„Powierzchnie — motyw ciemny”, „Ta sama karta w obu motywach” i „Komponenty
w motywie ciemnym”. W kodzie: `document.documentElement.dataset.theme = "dark"`.

Czego w palecie nie ma: fioletu, granatu, chłodnego szarego (psują zdjęcia
jedzenia), drugiego koloru marki, **gradientów jako tła** i przezroczystości
jako sposobu na jaśniejszy odcień (`opacity` na tekście zmienia kontrast
w sposób, którego skrypt nie policzy).

### Typografia

`"Inter Variable"` z pełnym stosem systemowym w zapasie. Rozmiar podstawowy to
**18 px, nie 16** — decyzja podjęta dla tego odbiorcy. Dziewięć stopni skali:
15 · 16 · **18** · 20 · 22 · 24 · 28 · 36 · 48 px. Skala nie jest geometryczna
celowo: krok 15 → 16 → 18 jest mały, bo te trzy rozmiary występują obok siebie
w jednej karcie; krok 24 → 28 → 36 jest duży, bo nigdy nie występują razem.

**15 px (`--text-meta`) to jedyny rozmiar poniżej 16 px** i wolno go użyć
wyłącznie w plakietce cichej, przy trzech warunkach naraz: tekst obok ma co
najmniej 18 px, informacja jest powtarzalna i drugorzędna, a jej utrata nie
blokuje żadnej czynności.

Trzy wagi, nie siedem: 400 tekst ciągły · 600–700 etykiety, autor, metadane ·
800 tytuły, przycisk główny, bieżąca pozycja. Bez kursywy w interfejsie, bez
wersalików poza wordmarkiem, bez rozstrzelenia liter.

Hierarchia karty, od najmocniejszego: **tytuł 24/800 → treść 20/400 →
autor 18/700 → metadane 15/600 w atramencie stonowanym.** Cztery poziomy,
cztery rozmiary, dwie wagi — i kolor marki nie bierze w tym udziału w ogóle.

Skala tekstu użytkownika: `data-text-scale` na `<html>`, kroki 90, 100, 112, 125,
140, 150, 200. Skaluje się **wyłącznie tekst**: nie odstępy, nie promienie, nie
szerokości kontenerów, nie wysokości kontrolek. Stąd bezwzględna reguła:
**żaden element zawierający tekst nie ma `height:` na sztywno** — tylko
`min-height` i padding.

Długość wiersza: 45rem / 720 px w strumieniu (65–75 znaków), 38rem / 608 px
w długim dokumencie prawnym (~65 znaków).

### Siatka i układ

Jedna zasada nadrzędna: **belka górna, siatka treści i stopka mają tę samą
szerokość i te same kolumny — zawsze, na każdej podstronie.**

Trzy progi, nie pięć: 320 px jedna kolumna z dolnym paskiem · 64rem dochodzi
nawigacja boczna · 80rem dochodzi szyna. Kolumny: 240 / 720 / 352 = 1424 px;
1040 px bez szyny; 768 px u gościa. **Trzecia kolumna istnieje od 80rem zawsze,
także wtedy, gdy nie ma czym jej wypełnić** (D-102): pusty margines wygląda
spokojnie, a przeskakująca nawigacja wygląda na usterkę.

Rytm pionowy jest wielokrotnością 4 px; w praktyce używa się sześciu wartości:
8 (ikona a napis) · 12 (wnętrze kontrolki) · 20 (wcięcie karty) · 24 (między
kartami) · 40 (między sekcjami) · 64 px (między blokami strony powitalnej).

Strona publiczna nie używa tej siatki — tam rytm robią **pasy na pełną szerokość
okna** z treścią wyśrodkowaną w środku, w tej samej szerokości 1040 px.

Co się przykleja: belka górna (`z-index: 30`), dolny pasek (`z-index: 40`, tylko
poniżej 64rem), nawigacja boczna, szyna, panel przepisu. Belka na stronie
publicznej **nie jest przyklejona**.

Nigdy przewijania w poziomie od 320 px. Wyjątki są dwa i świadome: karuzela
zdjęć we wpisie oraz tabela we własnym pudełku.

### Tło, zdjęcia, przezroczystość

**Ten projekt stoi na zdjęciach i mówimy to wprost (D-105): zdjęcie potrawy jest
treścią, nie ilustracją.** Cztery tła w całym systemie: `surface`,
`surface-raised`, `surface-sunken`, `surface-brand-wash` — plus ciemny pas przez
klasę `.blok-ciemny`. Żadnych obrazków tła, żadnych tekstur, żadnych wzorów,
żadnych gradientów dekoracyjnych.

Zdjęcia: w karcie wpisu pełna szerokość karty w proporcji **4:3**, w karcie
zwartej **16:9**, na ekranie przepisu **3:2**, w hero strony powitalnej 5:3
(natywna proporcja pliku). **Maksimum dwie kolumny.** Nigdy ściana miniaturek —
zdjęcie cudzego wykonania jest jedynym dowodem, że przepis działa u zwykłego
człowieka, a miniatura odbiera mu tę funkcję.

Kolor zdjęć jest ciepły, domowy i nieustawiony: talerz w kwiatki, kuchenny stół,
światło z okna. **Nie ustawiamy talerzy na deskach ze ścierką w paski.** Bez
filtrów, bez ziarna, bez czerni i bieli.

Jedyny gradient w systemie to `--scrim-gradient` — podkład pod tekstem na
zdjęciu, i on ma funkcję, nie ozdobę. Pełny u dołu, przechodzi w przezroczystość
ku górze, więc działa także na białym talerzu. **Podkład jest zawsze**, także
gdy zdjęcie akurat jest ciemne (reguła „nigdy” nr 7). Rozmycia (`backdrop-filter`)
nie ma nigdzie.

### Karty, obwódki, cienie, promienie

Karta wpisu: tło `surface-raised`, obwódka 1 px `border`, promień **24 px**
(największy prostokąt na ekranie, przy 16 px wygląda kanciasto), cień
`--shadow-card`, zdjęcie na pełną szerokość bez wcięcia i bez zaokrągleń
wewnątrz. Reszta kart zostaje na 16 px.

Promienie: 8 px plakietka i dana przepisu · 12 px przycisk, pole, komunikat ·
16 px karta · 24 px karta wpisu, zdjęcie, panel · pill chip, awatar, kółko.

Cienie są barwione w stronę atramentu, nie czystą czernią — trzy stopnie:
`--shadow-card`, `--shadow-card-hover`, `--shadow-popover`.

Obwódki: 1 px `--color-border` do podziału dekoracyjnego (nie niesie informacji,
więc nie ma progu kontrastu), 2 px `--color-border-strong` na polu formularza,
przycisku wtórnym i chipie (element interfejsu, próg 3:1), 3 px
`--color-danger` przy błędzie.

### Ruch, najechanie, wciśnięcie, fokus

Ruch jest minimalny, funkcjonalny, **nigdy dekoracyjny**: 150 ms na tło
przycisku i obwódkę pola, 200 ms na komunikat i cień karty, zawsze `ease-out`.

- **Najechanie** — tło ciemnieje o jeden stopień; **nic się nie przesuwa i nic
  nie zmienia rozmiaru**. Karta unosi sam cień. Pole zmienia tylko obwódkę: zmiana
  tła pod kursorem wygląda jak zmiana stanu, a nie jak wskazanie.
- **Wciśnięcie** — jeden piksel w dół. Tyle, żeby palec dostał potwierdzenie,
  za mało, żeby układ drgnął. Bez zmniejszania.
- **Fokus** — pierścień 3 px `--color-focus` z offsetem 2 px, zawsze
  `:focus-visible`, nigdy gołe `:focus`. Na kolorowym tle **technika halo**:
  2 px tła między przyciskiem a pierścieniem, bo niebieski na terakocie daje
  1.0–1.2:1 (to fizyka, nie zły dobór koloru). Przycisk na karcie ma własną
  regułę z kolorem karty. `outline: none` bez pełnoprawnego zamiennika jest
  zakazane bez wyjątku.
- **Wyłączony** — 55% krycia, kursor `not-allowed` i **zawsze zdanie obok**.
- `prefers-reduced-motion: reduce` wyłącza wszystkie przejścia globalnie.

Czego nie ma i nie będzie: karuzel automatycznych, parallaksu, animowanych wejść
treści przy przewijaniu, efektów najechania zmieniających rozmiar elementu,
„skeletonów”, które pulsują dłużej niż sekundę, i animowanego logo.

### Bez JavaScriptu

Cała nawigacja i formularz przepisu działają przy wyłączonym skrypcie. To nie
jest ograniczenie do obejścia, tylko rama, która wymusiła lepsze rozwiązania:
osobna strona zamiast panelu rozwijanego, `<details>` zamiast modalu
potwierdzenia, trzy adresy zamiast kreatora z zakładkami, trzy duże karty zamiast
listy rozwijanej, `<label for>` na ukrytym polu zamiast natywnego przycisku pliku,
„Pokaż więcej” zamiast przewijania bez końca. Jedyny `<script>` w systemie to
blok `application/ld+json` na stronie przepisu — to dane dla wyszukiwarki, nie kod.

---

## IKONOGRAFIA

**Zestaw jest własny i zamknięty: 18 ikon konturowych plus znak marki.** Nie ma
tu Lucide, Heroicons ani żadnego innego zestawu z CDN-u — geometria pochodzi
z `podglad/ikony-sprite.html` paczki źródłowej i jest przepisana co do punktu.

- **Format i styl.** SVG na siatce 24 × 24, kreska **2 px** (2.5 px w `plus`
  i `ptaszek`), zaokrąglone końce i łączenia, `fill: none`, `stroke:
  currentColor`. Ikona bierze kolor po tekście, więc tryb ciemny działa bez
  przełączania czegokolwiek.
- **Nazwy** (po polsku, tak jak w sprite): `dom`, `lupa`, `plus`, `zeszyt`,
  `osoba`, `dzwonek`, `zegar`, `porcje`, `czapka`, `zakladka`, `komentarz`,
  `garnek`, `aparat`, `ostrzezenie`, `ptaszek`, `strzalka`, `ustawienia` —
  plus `znak`.
- **Rozmiary** biorą się z klasy arkusza, nie z atrybutu: `side-nav-ikona`
  i `bottom-nav-ikona` 24 px, `dana-przepisu-ikona` 20 px, `alert-ikona` 24 px,
  `wordmark-znak` 32 px, `wybor-zdjecia-ikona` 40 px, `empty-state-znak` 64 px.
- **Reguła „nigdy” nr 1: ikona nie jest opisem akcji, jest jej ozdobą.**
  Każda ikona ma w miejscu użycia `aria-hidden="true"` i **zawsze stoi obok
  tekstu**. Nie ma w tym systemie przycisku z samą ikoną — ani w karcie, ani
  w belce, ani w dolnym pasku. Jeśli coś się nie mieści, ma zniknąć całe, a nie
  stracić napis.
- **Emoji nie są używane nigdzie.** Znaki Unicode też nie zastępują ikon;
  jedyny znak interpunkcyjny w roli separatora to kropka `·` w wierszu
  metadanych, i ma `aria-hidden="true"`, żeby czytnik nie czytał „kropka”.
- **Znak marki nie jest ikoną ogólnego przeznaczenia.** Garnek w koronie to znak
  produktu; do „gotowania” służy osobna, konturowa ikona `garnek`.
- **Dostarczanie.** W widokach Blade sprite `assets/icons/kuking-ikony.svg`
  wkleja się **na początku `<body>`**, nie ładuje z osobnego pliku:
  `<use href="ikony.svg#…">` nie działa przez granicę dokumentu ani przy otwarciu
  strony z dysku. W Reakcie służy do tego komponent `Icon`, który ma geometrię
  w środku.

### Znak marki

Garnek w koronie, z uśmiechem. Korona uzasadnia **KING** w nazwie, uśmiech
zmienia znak z katalogu kuchennego w znak miejsca, w którym są ludzie.

- **Uśmiech jest dziurą, nie białą kreską.** Malowany uśmiech działa tylko na
  tle, na którym się to sprawdziło, i psuje się na następnym. Wersja w tym
  systemie wycina go maską.
- Pole ochronne: **ćwierć wysokości znaku** z każdej strony, liczone od czubków
  pereł korony do dna garnka. W polu nie stoi nic.
- Minima: sam garnek 32 × 32 px, garnek z wordmarkiem 150 px szerokości,
  w druku 8 mm wysokości. Wyjątek: favicon 16 × 16 z grubszym wycięciem.
- Trzy dopuszczone kolory: `--color-brand` (obu motywów), biel na tle marki
  i na zdjęciu (zawsze na podkładzie), jeden kolor w druku jednokolorowym.
- Nie rozciągać, nie obracać, nie przebarwiać, nie dokładać cienia, obrysu ani
  poświaty, nie rozdzielać garnka od korony, nie animować.

### Pliki w `assets/`

| Plik | Stan |
|---|---|
| `logo/kuking-znak-wyciety.svg` | **zalecany** — garnek jednokolorowy, `currentColor`, uśmiech wycięty maską. Do wklejania inline |
| `logo/kuking-znak-jasny.svg` | **zalecany** — garnek biały, do `<img>` na tło ciemne, na kolor marki i na zdjęcie |
| `logo/kuking-favicon.svg` | pole marki, biały garnek, grubsze wycięcie |
| `logo/kuking-pole-ochronne.svg` | rysunek pomocniczy. **Nie jest logotypem** |
| `logo/legacy/*.svg` | **zastane, tylko do odczytu.** Wersje `-inverse` są uszkodzone (biały uśmiech na białym garnku). Nie wolno ich użyć nigdzie: ani w Blade, ani w e-mailu, ani w materiale prasowym |
| `icons/kuking-ikony.svg` | sprite z 18 ikonami i znakiem |
| `photos/*.png` | zdjęcia demonstracyjne z paczki |

**Znaku o wordmarku w krzywych nie ma.** `kuking-logo-smile.svg` ma napis jako
żywy `<text>` w foncie Inter — na komputerze bez Intera logotyp zmienia kształt
liter. Zanim cokolwiek pójdzie do drukarni albo do prasy, trzeba zamienić
`<text>` na ścieżki.

---

## Znane braki i rzeczy do rozstrzygnięcia

1. **Pliki fontu (`.woff2`).** `.woff2` to skompresowany plik z krojem pisma —
   jedyny format, jakiego dziś potrzebuje przeglądarka; wariabilny zawiera
   wszystkie grubości w jednym pliku. Paczka nie zawierała binariów dla
   „Inter Variable”, więc `tokens/fonts.css` ładuje **dokładnie ten sam krój**
   z serwera Google.

   Wygląda identycznie, ale ma trzy koszty: to zapytanie do obcego serwera przy
   pierwszym wejściu (a sekcja „Prywatność” obiecuje, że nie ma tu narzędzi
   z zewnątrz), font dojedżdża jedno połączenie później niż arkusz, a przy
   zablokowanym Google zostaje sam stos zapasowy. **Podmiana na wersję lokalną
   to trzy kroki opisane w nagłówku `tokens/fonts.css`** — wrzucić
   `InterVariable.woff2` do `assets/fonts/`, usunąć `@import`, podmienić `src`.
   Nic poza tym się nie zmienia.

   Do czasu podmiany działa `@font-face` o nazwie `"Inter Fallback"`: krój
   systemowy dopasowany metrycznie (`size-adjust: 107%`), żeby wymiana fontu po
   dojechaniu webfontu nie przesunęła układu.

   **Inter zostaje** — potwierdzone przez właściciela.
   Karta **„Kandydaci na font”** trzyma cztery kroje warte testu A/B po becie:

   | Krój | Dlaczego akurat ten |
   |---|---|
   | **Atkinson Hyperlegible Next** | zaprojektowany pod niską ostrość wzroku, siedem wag, wersja wariabilna, ponad 150 języków. `TYPOGRAFIA.md` §6 wskazuje go wprost jako kandydata do testu A/B po becie |
   | **Lato** | Łukasz Dziedzic, projektowany z polskimi znakami od pierwszego dnia. Cieplejszy i mniej „interfejsowy” niż Inter |
   | **Source Sans 3** | humanistyczny, wyraźne `l`/`I`/`1`, rytm najbliższy Interowi — podmiana nie ruszy układu |
   | **IBM Plex Sans** | ciepły grotesk z charakterem; litery szersze, więc tytuł karty łamie się wcześniej |

   **Inter zostaje do czasu testu z ludźmi** (`CZEGO-NIE-ZMIENIAM.md` §5).
   Podmiana kroju to zmiana, której nie wolno zrobić na oko — przy tym odbiorcy
   rozstrzyga ją test czytelności, nie gust.

   Wszystkie cztery są na Google Fonts, wszystkie mają komplet polskich znaków,
   każdy ma na karcie test `l I 1` i `O 0`.
2. **Pięć pozycji dolnego paska.** `KOMPONENTY.md` §19 podaje Start · Szukaj ·
   Dodaj · Zeszyt · Moje; `CZEGO-NIE-ZMIENIAM.md` §7 — Start · Szukaj · Dodaj ·
   Moje · Profil. Komponent trzyma się pierwszej wersji.
3. **Zdjęcia demonstracyjne.** `soup`, `cake` i `pasta` mają po 92 px szerokości
   i w karcie są rozmyte. Przed betą trzeba zasiać dane demonstracyjne
   prawdziwymi zdjęciami (D-105 punkt 2).
4. **Sześć zdań wymagających zgody właściciela** przed publikacją strony
   publicznej — wypisane co do jednego w `STRONA-WWW.md` §8.
5. **Zachowanie akcji konta w belce na telefonie** (`AUDYT.md` §1.12) — poniżej
   40rem znikają ikony, napisy zostają. Paczka mówi tylko o polu szukania,
   więc to rozstrzygnięcie jest moje i wymaga potwierdzenia.
6. **Kolizja arkuszy.** W paczce `komponenty.css` i `strona-www.css` nigdy nie
   były wczytywane razem; tutaj consumer dostaje jeden `styles.css`, więc
   kolidujące reguły strony publicznej (`.kroki`, `.lista-skladnikow`,
   `.pochodzenie`, `.przepis-zdjecie`) są zawężone do korzenia `.strona`.
