> **Aktualizacja v3.1 · 7 września 2026.** Zmiany z audytu opisuje [AUDYT-V3.1.md](../AUDYT-V3.1.md). W sprawach motywu, stopki, pozycjonowania nagłówka i skali 200% ten dokument oraz `07-wdrozenie/MOTYW-V3.1.md` mają pierwszeństwo przed poniższym opisem v3.0. Paleta i znak pozostają bez zmian.

# Strona publiczna Kuking — jak jest zbudowana i dlaczego

Trzy pliki i jeden arkusz:

| plik | co to jest |
|---|---|
| `powitalna.html` | strona główna dla gościa — jedyne miejsce, które ma kogoś przekonać do założenia konta |
| `o-kuking.html` | kto to prowadzi, po co i czego tu nie ma |
| `przepis-publiczny.html` | przepis widziany przez kogoś, kto trafił tu z wyszukiwarki i nie ma konta |
| `strona-www.css` | reguły potrzebne wyłącznie tym trzem stronom, dokładane do `podglad/podglad.css` |

Wszystkie trzy są jednym responsywnym plikiem każda: ten sam kod działa przy
390 px i przy 1440 px. Zero JavaScriptu, zero atrybutów `style=`, zero wartości
koloru, rozmiaru tekstu i odstępu napisanej z ręki. Jedyny `type="application/ld+json"`
siedzi na stronie przepisu i jest opisany niżej.

---

## 1. Kompozycja: pasy, nie siatka

Ekran zalogowanego jest siatką z trzema kolumnami (`.app-body`, D-102). Strona
publiczna **nie używa tej siatki**, bo nie ma nawigacji bocznej ani szyny — i to
nie jest uproszczenie, tylko inny kształt treści. Tutaj rytm robią **pasy na
pełną szerokość okna** z treścią wyśrodkowaną w środku:

```
.pas              → pełnoszeroki blok tła, jednakowy oddech w pionie
  .pas-wnetrze    → treść, sufit szerokości --www-pas, wcięcie po bokach
```

`--www-pas` to `--container-strona`, czyli **1040 px — dokładnie ta sama
szerokość, jaką ma ekran zalogowanego bez szyny**. Dzięki temu przejście ze
strony powitalnej do serwisu nie jest zmianą szerokości strony. Strona przepisu
ma własną szerokość pasa: `--container-content + --spacing-8 + --container-rail`
= 1104 px, czyli dokładnie kolumna czytania plus panel obok niej.

Cztery tła pasa i nic więcej:

| tło | skąd | gdzie |
|---|---|---|
| `surface` | domyślne | sekcja główna, żywy przykład, wezwanie do działania |
| `surface-sunken` | `.pas--wglebiony` | „Cztery rzeczy i nic więcej." |
| `surface-brand-wash` | `.pas--cieply` | „Zabierzesz stąd wszystko, co dodasz." |
| ciemny | `.blok-ciemny` z `tokens.css` | „Przepis jest dobry, kiedy ktoś go ugotował." |

`.blok-ciemny` przestawia całą paletę na kontenerze, więc ciemny pas dostaje
poprawne kolory tekstu, obwódek i przycisków **bez ani jednej wartości hex
napisanej z ręki**. Kiedy człowiek włączy motyw ciemny, ten pas ma ten sam
kolor co strona wokół — dlatego dostał kreskę na górze i na dole, wziętą
z jego własnej palety. W motywie jasnym ta kreska jest ciemna na ciemnym
i nie widać jej wcale.

### Rytm sekcji na stronie powitalnej

```
belka górna            biała, kreska pod spodem, NIE przyklejona
1. sekcja główna       jasna     · claim + zdanie + akcja + zdjęcie
2. cztery rzeczy       wgłębiona · dwie kolumny kart, ikona + podpis
3. żywy przykład       jasna     · danie obok przepisu
4. Ugotowałem          CIEMNA    · jedyna rzecz, której nie ma baza przepisów
5. prywatność          ciepła    · dwie kolumny tekstu
6. wezwanie do działania jasna, kreska u góry, wyśrodkowane
stopka                 podniesiona, linki i przełącznik motywu
```

Jasne–wgłębione–jasne–ciemne–ciepłe–jasne. Ciemny pas wypada mniej więcej
w połowie i jest jedynym mocnym akcentem na całej stronie: dostaje go ta rzecz,
której nie ma nikt inny. Belka górna **nie jest przyklejona** — na stronie
sprzedażowej przyklejona belka zabiera wiersz tekstu przy każdym przewinięciu,
a przy skali 150% dwa. To także trzyma regułę „jedna gra słowem kuKING na
ekran": gdyby belka jechała za człowiekiem, „Zostań kuKINGiem" byłoby na
każdym ekranie.

**Jeden akcent na sekcję** (D-110): w sekcji 1 kolor ma tylko przycisk główny,
w sekcji 2 tylko ikony kart (po jednej na kartę), w sekcji 3 tylko ciepła
główka karty przepisu, w sekcji 4 tylko kreska przy cytacie, w sekcji 6 tylko
przycisk. Hierarchia jest w rozmiarze i wadze, nie w kolorze.

---

## 2. Rola zdjęć

D-105 mówi wprost: **ten projekt stoi na zdjęciach**, a zdjęcie potrawy jest
treścią, nie ilustracją. Na stronie publicznej znaczy to trzy rzeczy.

1. **W sekcji głównej jest prawdziwe zdjęcie jedzenia, a nie znak marki na
   beżowym tle.** `pierogi.png`, kadr 5:3 — bo to jest natywna proporcja tego
   pliku (520×312) i dzięki temu nic nie jest przycinane.
2. **Zdjęcie nigdy nie jest rozciągane ponad rozmiar pliku.** Sufit szerokości
   zdjęcia w sekcji głównej to `--www-zdjecie-hero: 30rem` (480 px) — mniej niż
   520 px, które ma plik. To jedyny powód, dla którego ta liczba ma taką
   wartość, i jest to napisane w komentarzu przy niej.
3. **Żadnej ściany miniaturek.** W sekcji „Tak to wygląda w środku" karta dania
   ma zdjęcie na pełną szerokość karty w proporcji 4:3, dokładnie tak jak
   `.karta-zdjecie` w systemie. Dwie karty w wierszu, nigdy cztery.

**Świadome ograniczenie:** w paczce są tylko dwa zdjęcia w użytecznym rozmiarze
(`pierogi.png` 520×312 i `pizza.png` 690×316). `soup.png`, `cake.png`
i `pasta.png` mają po 92 px szerokości — każde użycie ich w rozmiarze, który
cokolwiek pokazuje, byłoby rozmyte. Dlatego **nie ma ich tu wcale**, a karta
przepisu w sekcji 3 nie ma zdjęcia i dostała zamiast niego ciepłą główkę
i spis składników. D-105 punkt 3 mówi dokładnie o tym: wpis bez zdjęcia nie
może wyglądać na uszkodzony.

---

## 3. Jak to się zachowuje na telefonie

Wszystko schodzi do jednej kolumny w tej samej kolejności, w jakiej stoi
w kodzie. Konkretnie:

- **Belka górna** przy 320 px nie mieści wordmarku i dwóch przycisków w jednym
  wierszu, więc przyciski schodzą do drugiego i dzielą szerokość po połowie
  (`flex: 1 1 0`). Oba mają tekst, oba mają min. 48 px wysokości. „Zostań
  kuKINGiem" łamie się przy 320 px na dwa wiersze i przycisk robi się wyższy —
  nic nie jest ucięte, bo w całym arkuszu nie ma ani jednej `height` na
  elemencie z tekstem.
- **Sekcja główna**: tekst nad zdjęciem. Kolejność jest celowa — hasło i akcja
  mają być widoczne bez przewijania, zdjęcie zaraz pod nimi.
- **Cztery rzeczy**: jedna kolumna do 48rem, dwie powyżej. Nigdy cztery —
  przy czterech kolumnach zdanie wyjaśniające ma przy skali 150% po dwa słowa
  w wierszu.
- **Żywy przykład**: karty jedna pod drugą, obie zachowują pełny rozmiar
  zdjęcia.
- **Prywatność**: dwie kolumny tekstu łączą się w jedną.
- **Strona przepisu**: panel ze składnikami jedzie **pod** przepisem, a nie
  nad nim — człowiek przyszedł tu po przepis. Od 80rem panel staje obok kroków
  i jest przyklejony przy przewijaniu (D-102: „treść, której ktoś przy garnku
  naprawdę potrzebuje obok kroków, a nie nad nimi").

Wynik pomiaru w Chromium: brak przewijania w poziomie przy 320, 390, 768, 1024
i 1440 px, przy skali tekstu 100%, 140% i 150%, na wszystkich trzech stronach.

---

## 4. Jedno wezwanie do działania

Na całej stronie jest **jedna rzecz do zrobienia: założyć konto.** Nie ma
zapisu na powiadomienia, nie ma pobierania aplikacji, nie ma drugiego
formularza. Przycisk główny występuje dwa razy — u góry i na końcu — i za
każdym razem prowadzi w to samo miejsce; wszystko inne na tej stronie jest
zwykłym linkiem w tekście.

Powód jest z `UX_50_PLUS.md`: metryką jest liczba momentów, w których pada
pytanie „Co mam teraz kliknąć?". Dwie równorzędne akcje na jednym ekranie to
jest dokładnie ten moment. Dlatego obok przycisku stoi link „Najpierw się
rozejrzę" — jest cichy, jest tekstem, i prowadzi do przykładowego przepisu,
czyli daje trzecią drogę bez robienia z niej drugiej akcji głównej.

Na stronie przepisu akcja główna jest jedna i inna: **„Zapisz przepis
w Zeszycie"**. Gość dostał to, po co przyszedł — przepis w całości, bez
zasłaniania, bez okna z zapisem — a zaproszenie stoi obok, nie na drodze.

---

## 5. Jak to się ma do reguł nagłówków z `BRAND_EXTENDED.md` §4

Reguły: jedno zdanie, maks. 8 słów, kropka albo znak zapytania; czasownik na
początku, jeśli to wezwanie do działania; zero wykrzykników; zero wielkich
liter w środku zdania; nagłówek nigdy nie jest jedynym nośnikiem informacji —
pod nim jest zdanie wyjaśniające i akcja z tekstem.

| nagłówek | słów | pod spodem zdanie | pod spodem akcja z tekstem |
|---|---|---|---|
| Pokaż, co dziś ugotowałeś. | 4 | tak | „Załóż konto — to darmowe" |
| Cztery rzeczy i nic więcej. | 5 | tak | „Czego tu nie ma" |
| Tak to wygląda w środku. | 5 | tak | „Zobacz cały przepis" |
| Przepis jest dobry, kiedy ktoś go ugotował. | 7 | tak | „Zobacz to na przepisie" |
| Zabierzesz stąd wszystko, co dodasz. | 5 | tak | „Kto to prowadzi" |
| Załóż konto. Zajmie minutę. | 4 | tak | „Załóż konto" |
| Prowadzimy to na własną rękę. | 5 | tak | belka górna: „Załóż konto" |
| Przepisy giną razem z zeszytami. | 5 | tak | „Czego tu nie ma" (sekcja niżej) |
| Czego tu nie ma. | 4 | tak | „Co jest zamiast tego" |
| Co jest zamiast tego. | 4 | tak (w kartach) | — |
| Skąd ta nazwa. | 3 | tak | — |
| Jesteśmy przed startem. | 3 | tak | „Napisz do nas" |

Dwa odstępstwa, oba świadome i oba wzięte z dokumentów:

1. **„Załóż konto. Zajmie minutę." to dwa zdania.** Jest zacytowane dosłownie
   z `BRAND_EXTENDED.md` §4, gdzie stoi jako WZÓR dobrego nagłówka rejestracji.
   Dokument bije regułę wyprowadzoną z tego samego dokumentu.
2. **Tytuł przepisu „Pierogi ruskie po babci Halinie" nie ma kropki.** To nazwa
   dania, nie zdanie — tak samo jak wzorcowy nagłówek „Pomidorowa z własnych
   pomidorów" z tej samej tabeli §4.

Sekcje „Co jest zamiast tego." i „Skąd ta nazwa." nie mają osobnej akcji, bo
akcją jest treść pod nimi (cztery karty z nazwami funkcji, dwa akapity
wyjaśnienia) — dokładanie tam przycisku byłoby robieniem akcji dla reguły.

---

## 6. Wszystkie teksty ze strony, dosłownie, z podaniem źródła

Legenda źródeł:
**§6** = `COPY_STYLE.md` §6 „Gotowe teksty — do wklejenia" ·
**§4/§7** = `BRAND_EXTENDED.md` §4 „Nagłówki" i §7 „Hasła dodatkowe" ·
**BRAND** = `BRAND.md` · **słownik** = `BRAND_EXTENDED.md` §1 ·
**moje** = napisane przeze mnie, z podanym rejestrem z `COPY_STYLE.md` §3.

### 6.1 `powitalna.html`

| tekst | skąd |
|---|---|
| „Pokaż, co dziś ugotowałeś." | BRAND, claim główny — dosłownie |
| „Gotujemy po swojemu." | BRAND, claim drugi — dosłownie |
| „Zaloguj" | słownik §1.2 |
| „Zostań kuKINGiem" | §6, nagłówek rejestracji — dosłownie |
| „Kuking to twój zeszyt z przepisami i ludzie, którzy naprawdę gotują. Zdjęcie i kilka słów — tyle wystarczy, żeby zacząć." | moje, rejestr „ciepły bez żartu"; pierwsze zdanie jest przepisaniem hasła H2 „Twój zeszyt i ludzie, którzy gotują." (§7) na zdanie |
| „Załóż konto — to darmowe" | moje, z §6 „Zostań kuKINGiem — to darmowe" po zdjęciu gry słowem (patrz 7.1) |
| „Najpierw się rozejrzę" | §6, przycisk obok rejestracji — dosłownie |
| „Cztery rzeczy i nic więcej." | moje, rejestr „ciepły bez żartu" |
| „Kuking robi cztery rzeczy porządnie i nie próbuje robić trzynastej." | moje, rejestr „ciepły z żartem"; żart jest w tle, zgodnie z §4 „niedopowiedzenie" |
| „Pokazujesz, co ugotowałeś" | moje, z claimu głównego |
| „Zdjęcie i kilka słów. Nie musi być ładne — ma być prawdziwe." | §6, pusty stan Startu — druga część dosłownie |
| „Trzymasz przepisy w Zeszycie" | moje; „Zeszyt" ze słownika §1.1 |
| „Przepis, który chcesz zachować, trafia do Twojego Zeszytu i zawsze go tam znajdziesz." | moje, przepisane z §6 „pusty zeszyt" |
| „Mówisz, że ugotowałeś" | moje; „Ugotowałem" ze słownika §1.1 |
| „Kiedy ugotujesz z czyjegoś przepisu, autor się o tym dowie. To jest tutaj najmilsza rzecz." | moje, z §6 „To jest najmilsza część dla autora przepisu." |
| „Obserwujesz, kogo chcesz" | moje; „Obserwuj" ze słownika §1.2 |
| „Widzisz to, co gotują osoby, które obserwujesz. W kolejności, w jakiej to dodali." | §2.2 — „co gotują osoby, które obserwujesz" jest tam podane jako opis Startu zamiast angielskiej nazwy strumienia |
| „Czego tu nie ma" | moje, rejestr rzeczowy |
| „Tak to wygląda w środku." | moje, rejestr „ciepły bez żartu" |
| „Po zalogowaniu widzisz dania i przepisy. Dokładnie takie jak te dwa poniżej." | moje; „danie" i „przepis" ze słownika §1.1 |
| „Kasia", „wczoraj, 19:40", „przykład" | moje, dane przykładowe; plakietka cicha wg D-103 |
| „Pizza w piątek, bo tak wyszło" | moje, tytuł dania przykładowego |
| „Ciasto stało od rana pod ścierką. Bazylia z parapetu, reszta z lodówki." | moje, rejestr „rozpoznanie realnego życia w kuchni" (§4) |
| „Pierogi ruskie po babci Halinie" | moje, nazwa przepisu przykładowego |
| „90 minut", „6 porcji" | §2.4 — liczby cyframi, jednostki słowem |
| „Składniki pisze się tak, jak się mówi w kuchni." | moje, z §6 „Pisz tak, jak mówisz: »szklanka mąki«, »2 duże cebule«" |
| „3 szklanki mąki", „szklanka gorącej wody, może trochę więcej", „1 kg ziemniaków, ugotowanych dzień wcześniej", „40 dag twarogu półtłustego", „2 duże cebule" | moje, pisane wprost według wzoru z §6 |
| „Zobacz cały przepis" | moje, rejestr rzeczowy |
| „To są przykłady. Prawdziwe dania i przepisy dodają tu ludzie, a Kuking jest przed startem." | moje; wymuszone regułą „nie rysuj liczby ani treści, której nie ma" |
| „Przepis jest dobry, kiedy ktoś go ugotował." | §7 hasło H1 — dosłownie |
| „Pod każdym przepisem jest przycisk »Ugotowałem«. Kiedy go klikniesz i dodasz zdjęcie, autor przepisu dowie się, że ktoś naprawdę zrobił to u siebie w kuchni." | moje, rejestr „ciepły bez żartu" |
| „Dlatego przy przepisie widać nie liczbę serduszek, tylko zdjęcia od ludzi, którym wyszedł. Tu nie ma rankingów. Nie ma kogo wyprzedzać." | dwa ostatnie zdania dosłownie z §4 „ciepła autoironia serwisu wobec siebie"; pierwsze moje |
| „Zobacz to na przepisie" | moje, rejestr rzeczowy |
| „Halina ugotowała Twój rosół." | §6, powiadomienie autora — dosłownie, z wstawionym imieniem |
| „Tak wygląda powiadomienie, na które się tutaj czeka." | moje, rejestr „ciepły bez żartu" |
| „Zabierzesz stąd wszystko, co dodasz." | moje; przepisane z §4 „Pobierz wszystko, co dodałeś." na zdanie o stronie |
| „W każdej chwili możesz pobrać paczkę ze swoimi zdjęciami, wpisami i przepisami. Otworzysz ją na swoim komputerze — także wtedy, gdyby Kuking kiedyś przestał istnieć." | §6, e-mail „eksport gotowy" — druga połowa niemal dosłownie |
| „Przy każdym wpisie sam decydujesz, kto go widzi: wszyscy, tylko obserwujący albo tylko Ty." | §1.3, formuła „Kto to widzi: wszyscy / tylko obserwujący / tylko ja" |
| „Zeszyt jest prywatny, dopóki sam nie postanowisz inaczej." | moje |
| „Prowadzimy to na własną rękę." | §4, wiersz „Strona o nas" — dosłownie |
| „Nie ma tu reklam między daniami ani firmy, która czeka na Twoje dane — jest strona, konto i przepisy." | moje ⚠ patrz 8. |
| „Odpisujemy na wiadomości sami i pod każdym e-mailem od nas jest widoczny link »Wypisz się z tych e-maili«." | §5.1 i §6, stopka wypisania — nazwa linku dosłownie ⚠ patrz 8. |
| „Kto to prowadzi" | moje, rejestr rzeczowy |
| „Załóż konto. Zajmie minutę." | §4, wiersz „Rejestracja" — dosłownie |
| „Cztery pola i gotowe. Nie pytamy o numer telefonu ani o datę urodzenia." | §6, pod nagłówkiem rejestracji — dosłownie |
| „Załóż konto" | §8 — krótka forma zamiast gry słowem |
| „Jeśli masz już konto — zaloguj się." | moje, rejestr rzeczowy; zdanie oznajmujące, a nie pytanie — jedyne pytanie, jakie ten produkt zadaje, to „Co dziś ugotowałeś?" |
| „Motyw strony", „Jasny", „Ciemny" | moje, rejestr rzeczowy |
| „Motyw zmienia się tylko wtedy, kiedy sam go zmienisz." | moje; wypowiada decyzję D-019 (motyw wyłącznie z wyboru człowieka) |
| „Kuking.pl — polski serwis domowego gotowania. Strona jest w budowie." | moje; drugie zdanie jest prawdą o stanie serwisu i tu musi paść |

### 6.2 `o-kuking.html`

| tekst | skąd |
|---|---|
| „Prowadzimy to na własną rękę." | §4 — dosłownie, jako nagłówek strony |
| „Kuking robi jedna osoba, która gotuje w domu i miała dość przepisów, do których trzeba się przekopać przez pół strony cudzych wspomnień i trzy reklamy." | moje ⚠ patrz 8. |
| „Nie stoi za tym żadna większa firma. […] odpowiedź czasem przychodzi następnego dnia, i to jest uczciwsze, niż udawać całodobową obsługę." | moje, rejestr „ciepły bez żartu" ⚠ patrz 8. |
| „Przepisy giną razem z zeszytami." | moje |
| „Najlepsze przepisy w Polsce leżą w kuchennych szufladach: w zeszytach w kratkę, na kartkach z kalendarza, na odwrocie rachunku. Kiedy nie ma ich kto przepisać, znikają." | moje, rejestr „ciepły bez żartu"; „zeszyt" ze słownika §1.1 |
| „…dołożyć do niego zdjęcie kartki pisanej ręką babci i nazwisko osoby, po której się go ma." | moje, z §6 „Jeśli masz przepis zapisany ręcznie — zrób mu zdjęcie." |
| „Przepis, którego nikt nigdy nie ugotował, jest tylko listą składników." | moje; parafraza hasła H1 z §7 |
| „Czego tu nie ma." | moje |
| „Rankingów" + wyjaśnienie | moje; wprost z uzasadnienia w `COPY_STYLE.md` §5 („publiczne rankingi natychmiast dzielą ludzi na dwie klasy”) |
| „Punktów, poziomów i odznak" + wyjaśnienie | moje; z §2.1 (mechaniki grywalizacyjne produkują wstyd) |
| „Algorytmu, który wybiera za Ciebie" + wyjaśnienie | moje; z §2.3 („nigdy jako maska dla algorytmu”) |
| „Reklam między daniami" + wyjaśnienie | moje ⚠ patrz 8. |
| „Co jest zamiast tego." | moje |
| „Zeszyt", „Ugotowałem", „Skąd ten przepis" | słownik §1.1 i §6 — nazwy własne funkcji |
| „Jedno zdjęcie od kogoś, komu przepis wyszedł, mówi więcej niż tysiąc serduszek pod cudzym zdjęciem z internetu." | moje; z reguły „Ugotowałem jest silniejszym sygnałem jakości niż like" (§7 H1) |
| „To najczęściej czytana część przepisu." | §6 — dosłownie |
| „Wielkość tekstu" + wyjaśnienie | moje; wypowiada D-111 i D-019 |
| „Skąd ta nazwa." | moje |
| „…korona siedzi na garnku, nie na niczyjej głowie. To żart o garnku, nie komplement dla kogokolwiek." | §2 `COPY_STYLE.md` — zakaz „Jesteś królem kuchni" jest tam uzasadniony dokładnie tym zdaniem |
| „To nazwa przynależności, nie tytuł, na który trzeba zasłużyć. Wystarczy tu być." | §2 — „Nazwa przynależności ją obniża — wystarczy tu być." |
| „Jesteśmy przed startem." | moje; fakt z `CZYTAJ-MNIE.md` |
| „Zapraszamy po kilka osób naraz, zaczynając od dwudziestu…" | moje; liczba 20 jest planem z `CZYTAJ-MNIE.md` (20 → 200 → 2000), a nie liczbą kont |
| „…jeżeli kiedyś napiszemy, ile osób coś ugotowało, będzie to liczba, którą da się policzyć." | moje; wypowiada wprost zakaz rysowania liczb, których nie ma z czego policzyć |
| „Napisz do nas" | moje, rejestr rzeczowy |

### 6.3 `przepis-publiczny.html`

| tekst | skąd |
|---|---|
| „Czytasz przepis w Kuking. Do czytania nie trzeba konta." | moje, rejestr rzeczowy |
| „Czym jest Kuking" | moje, rejestr rzeczowy |
| „Pierogi ruskie po babci Halinie" | moje, nazwa przepisu przykładowego |
| „przepis przykładowy" | moje; plakietka cicha (D-103), bo to nie jest przepis prawdziwej osoby |
| „Ciasto na gorącej wodzie, farsz z ziemniaków ugotowanych dzień wcześniej i tyle pieprzu, żeby było czuć. U babci stały na stole w każdą niedzielę." | moje, rejestr „rozpoznanie realnego życia w kuchni" |
| „90 minut", „6 porcji", „Kuchnia domowa" | §2.4 |
| „Składniki" | §6 |
| „Na 6 porcji, czyli mniej więcej 50 pierogów." | moje |
| całe składniki („szklanka gorącej wody, może trochę więcej", „sól i sporo pieprzu"…) | moje, pisane wprost według §6: „Pisz tak, jak mówisz: »szklanka mąki«, »2 duże cebule«, »mleko — ile weźmie«" |
| „Jak to zrobić" | moje; §6 mówi o krokach, nie daje nazwy sekcji |
| „Jeden krok to jedna czynność — łatwiej to czytać przy garnku." | §6, podpowiedź przy krokach — niemal dosłownie |
| dziewięć kroków | moje, rejestr rzeczowy, jedna czynność na krok |
| „Skąd ten przepis" | §6 — dosłownie |
| „To najczęściej czytana część przepisu. Ludzie chcą wiedzieć, po kim on jest." | §6 — dosłownie |
| „Po babci Halinie, z Rzeszowszczyzny." | moje; pole „Po kim ten przepis" z §6 |
| historia przepisu (dwa akapity) | moje, rejestr „ciepły bez żartu"; §6 mówi, czego się w tym polu oczekuje |
| „Komu wyszło" | §6 — dosłownie |
| „Zdjęcia od ludzi, którzy naprawdę to zrobili u siebie." | §6 — dosłownie |
| „Nikt jeszcze nie pokazał, jak mu wyszło" | moje, wzór pustego stanu z §1.3 |
| „Kiedy ugotujesz z tego przepisu, możesz kliknąć »Ugotowałem« i dodać zdjęcie. Marek dowie się, że ktoś naprawdę to zrobił. Zdjęcie nie musi być ładne." | moje + §6 („Zdjęcie nie musi być ładne." dosłownie) |
| „Załóż konto, żeby dodać Ugotowałem" | moje; nazwa funkcji nieodmieniona, zgodnie z §3 |
| „Zapisz na później" | moje, rejestr rzeczowy |
| „Zeszyt to Twoje miejsce na przepisy, które chcesz zachować…" | moje, z §6 „pusty zeszyt" |
| „Zapisz przepis w Zeszycie" | „Zapisz" ze słownika §1.2, „Zeszyt" z §1.1 |
| „Konto jest darmowe. Nie pytamy o numer telefonu." | §6, skrót zdania „Cztery pola i gotowe…" |
| „Czego potrzebujesz" | moje, rejestr rzeczowy |

---

## 7. Co świadomie odrzuciłem

### 7.1 „Zostań kuKINGiem" jako przycisk główny w sekcji głównej

Gra słowem pada na stronie powitalnej **raz** — w belce górnej, dokładnie tam,
gdzie prosił o nią właściciel. Przycisk główny w sekcji głównej i na końcu
strony mówi więc „Załóż konto", a nie „Zostań kuKINGiem — to darmowe" (§6).
Powód jest twardy: „maksymalnie jedna gra słowem kuKING na ekran"
(`COPY_STYLE.md` §2), a przy przewijaniu strony belka i sekcja główna są na
jednym ekranie. Z tego samego powodu belka górna **nie jest przyklejona** —
przyklejona pokazywałaby tę grę słowem na każdym ekranie strony.

Na `o-kuking.html` gra słowem pada raz, w sekcji „Skąd ta nazwa.", gdzie jest
wyjaśnieniem, a nie żartem. Na `przepis-publiczny.html` nie pada wcale —
człowiek przyszedł tam po przepis.

### 7.2 Hasło H4 „Każdy jest królem swojej kuchni."

`BRAND_EXTENDED.md` §7 dopuszcza je w jednym akapicie strony „O nas"
wyjaśniającym koronę w znaku. **Nie użyłem go.** To zdanie jest komplementem
dla czytającego, a cały mechanizm opisany w `COPY_STYLE.md` §2 mówi, że
komplement podnosi poprzeczkę u osoby, która i tak nic nie publikuje. Koronę
wyjaśnia zamiast niego zdanie o tym, że siedzi na garnku, a nie na niczyjej
głowie — ta sama treść, bez komplementu.

### 7.3 Liczby

Nie ma nigdzie „2000 przepisów", „dołącz do tysięcy", liczby obserwujących ani
liczby wykonań przepisu. Serwis nie ma ani jednego prawdziwego konta.
Na stronie przepisu sekcja „Komu wyszło" pokazuje **pusty stan z zaproszeniem**
zamiast wymyślonych wykonań, a `o-kuking.html` mówi wprost, że jeśli kiedyś
padnie tu liczba, będzie to liczba policzalna. Jedyne liczby na stronie to
90 minut, 6 porcji, 4 rzeczy i składniki przepisu.

### 7.4 Trzy małe zdjęcia z paczki

`soup.png`, `cake.png` i `pasta.png` mają po 92 px szerokości. Użycie ich
w rozmiarze, w którym cokolwiek widać, dałoby rozmyty obraz, a użycie
w rozmiarze, w którym są ostre, byłoby ścianą miniaturek — zakazaną wprost.
Nie ma ich tu wcale, a strona jest zbudowana tak, że po podmianie na prawdziwe
zdjęcia (D-105 punkt 2) wystarczy dołożyć karty w sekcji 3.

### 7.5 Karuzela, parallaks, animowane wejścia, wyskakujące okno z zapisem

Nie ma ich, bo nie ma JavaScriptu i bo nie ma po co. Jedyne przejścia
w arkuszu to te, które przychodzą z komponentów systemu (tło przycisku, cień
karty), i wszystkie respektują `prefers-reduced-motion` przez regułę z
`tokens.css`.

### 7.6 Prawdziwe imię gospodarza

`o-kuking.html` mówi „jedna osoba", ale nie podaje imienia — D-012 (kto jest
gospodarzem) czeka na właściciela i nie jest moją decyzją.

---

## 8. ⚠ Zdania, które musi potwierdzić właściciel przed publikacją

Kilka zdań na tej stronie to **obietnice o tym, jak serwis jest prowadzony** —
nie da się ich sprawdzić w kodzie i nie ja mam prawo ich składać. Wypisuję je
co do jednego, dosłownie, żeby dało się je zaakceptować albo skreślić w minutę:

1. „Kuking robi jedna osoba, która gotuje w domu i miała dość przepisów, do
   których trzeba się przekopać przez pół strony cudzych wspomnień i trzy
   reklamy." (`o-kuking.html`)
2. „Nie stoi za tym żadna większa firma. Nie ma tu działu, do którego można
   napisać — wiadomość czyta ten sam człowiek, który to pisze i utrzymuje."
   (`o-kuking.html`)
3. „Nie zarabiamy na tym, ile czasu tu spędzisz, więc nic tu nie miga i nic nie
   przewija się samo." (`o-kuking.html`)
4. „Nie ma tu reklam między daniami ani firmy, która czeka na Twoje dane — jest
   strona, konto i przepisy." (`powitalna.html`)
5. „Odpisujemy na wiadomości sami i pod każdym e-mailem od nas jest widoczny
   link »Wypisz się z tych e-maili«." (`powitalna.html`) — druga połowa wynika
   z `BRAND_EXTENDED.md` §5.1, pierwsza jest deklaracją właściciela.
6. „W każdej chwili możesz pobrać paczkę ze swoimi zdjęciami, wpisami
   i przepisami." (`powitalna.html`) — obietnica działającego eksportu danych.
   Jeśli eksport nie działa w dniu startu, to zdanie musi zniknąć albo zmienić
   czas na przyszły.

Reszta tekstu opisuje rzeczy, które są rozstrzygnięte w dokumentach systemu
(Zeszyt, Ugotowałem, brak rankingów, wybór widoczności wpisu, motyw wyłącznie
z wyboru człowieka) i nie wymaga niczyjej zgody poza tą, która już padła.

---

## 9. Dane strukturalne przepisu (JSON-LD)

`przepis-publiczny.html` ma w `<head>` blok
`type="application/ld+json"` ze schematem `Recipe`. To **jedyny wyjątek od
zakazu skryptów na tej stronie i jest to wyjątek na dane, nie na kod**:
przeglądarka nie wykonuje tej zawartości ani jej nie rysuje — czyta ją
wyszukiwarka, żeby pokazać przy wyniku zdjęcie, czas i liczbę porcji. Powód,
dla którego to jest tam potrzebne: strona przepisu jest jednym z niewielu wejść
z zewnątrz, a bez tego bloku wygląda w wynikach jak akapit tekstu.

Zasada, według której jest napisany: **w bloku nie ma ani jednej rzeczy, której
nie ma na stronie widocznej dla człowieka.** Wszystkie składniki i wszystkie
dziewięć kroków są tam w tym samym brzmieniu co w treści. Nie ma
`aggregateRating` ani `interactionStatistic` — nie mamy czego policzyć,
a wpisanie tam liczby byłoby okłamaniem wyszukiwarki. W wersji produkcyjnej
`image` i `url` są adresami bezwzględnymi; w makiecie są względne, bo strona
otwiera się z dysku.

---

## 10. Czym to sprawdziłem

```
node podglad/zbuduj-podglad.mjs        # arkusz podglądu z tokenów i komponentów
node 07-wdrozenie/sprawdz-uklad.mjs    # 320/390/768/1024/1440 px × tekst 100/140/150%
node 07-wdrozenie/sprawdz-paczke.mjs   # skrypt, style=, wartości spoza tokenów, słowa zakazane
```

Wynik dla tych trzech stron: **zero przewijania w poziomie w piętnastu
kombinacjach szerokości i skali tekstu na każdej ze stron, zero złamanych
reguł twardych.** Do tego obejrzane zrzuty przy 390 i 1440 px, w motywie
jasnym i ciemnym oraz przy skali tekstu 150% — i poprawione to, co na nich
wyglądało źle: odstęp w wordmarku („KuKing .pl" zamiast „KuKing.pl"), drugi
claim zsunięty pod hasło jak podpis pod zdjęciem, za długi wiersz w liście
„czego tu nie ma" i zbyt rozgadany pasek dla gościa nad przepisem.
