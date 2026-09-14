# Głos Kuking — jak piszemy

Ten dokument jest **wiążący dla każdego tekstu widocznego dla użytkownika**:
przyciski, nagłówki, puste stany, błędy, e-maile, powiadomienia.

Jeśli piszesz cokolwiek, co przeczyta człowiek — piszesz według tego pliku.
Słownik funkcji i lista słów zakazanych: `BRAND_EXTENDED.md`.

Aktualizacja D-208, publiczna strona powitalna: „Od Twojej kuchni do
wspólnego stołu”, „Zdjęcie. Kilka słów. I rozmowa przy okazji.” oraz
„Twój przepis. Czyjś dobry obiad.” są brzmieniem wskazanego przez
właściciela wzorca. Kroki pozostają konkretnymi czynnościami: „Robisz
zdjęcie”, „Piszesz kilka słów”, „Ktoś odpowiada”. Podpis publicznej
fotografii mówi „Zdjęcie: {autor}”; nie udaje powiadomienia ani wykonania.

> **Ten plik mówi, JAK napisać zdanie. Czym ten głos JEST i GDZIE mówi —
> [`GLOS_MARKI.md`](GLOS_MARKI.md).** Tamten dokument rozstrzyga zasięg marki
> (dwukolorowy zapis `kuKING` wszędzie poza pięcioma miejscami, hierarchia
> tonu, kryterium „nie konkuruje z zadaniem" zamiast limitu na ekran) i zbiera
> decyzje właściciela z 11 września 2026. Gdzie oba pliki mówią co innego:
> brzmienie zdania rozstrzyga ten plik, zasięg marki — tamten.

---

## Precyzja opisu działania

Zachęta po pierwszym wpisie wskazuje formularz kolejnego zdjęcia. Nie zakłada
zawartości telefonu ani nie obiecuje, że następna publikacja pójdzie szybciej
(D-114, #545). Pierwszy i kolejny wpis są osobnymi stanami odbioru tekstu.

Komunikat przekroczenia limitu podaje wartość z rzeczywistej reguły
walidacji, np. `:max`, zamiast liczby wpisanej osobno w zdaniu. Zmiana
limitu nie może pozostawić sprzecznej instrukcji przy polu (#538).

Potwierdzenie zapisu przepisu nazywa faktycznie zapisaną widoczność: prywatny,
dla obserwujących lub publiczny. Nie sugerujemy odbiorców prywatnej treści.
Instrukcja dalszego działania używa widocznej nazwy przycisku, np. „Dopisz
szczegóły”, gdy przepis można uzupełnić (#530).

Automatyczny zapis zatrzymuje się przy niepoprawnych polach. Mówimy wtedy,
że te zmiany nie zostały zapisane, a tekst nadal jest w formularzu.
Komunikat „zapisano” pokazujemy po udanym zapisie, nie po samym wpisaniu nazwy
ani próbie wysłania formularza (#528).

Przy odzyskiwaniu formularza rozróżniamy zachowanie całego tekstu, części pól
i brak odzyskanej treści. Brak odzyskanych pól nie dowodzi, że formularz był
pusty. Zapewnienie o tekście nie obejmuje zdjęcia, które trzeba wybrać ponownie.
Nie obiecujemy zachowania danych przez przycisk „wstecz” przeglądarki (#523).

Instrukcja opisuje kroki, które aplikacja rzeczywiście zapewnia. Przy logowaniu
przez Google lub Facebooka podajemy nazwę przycisku i uprzedzamy o przekierowaniu;
nie gwarantujemy jednego kliknięcia, bo dostawca może wymagać potwierdzenia.
Ta sama zasada obejmuje ekran łączenia kont po powrocie od dostawcy (#542),
nie tylko przyciski na stronie logowania.
Podsumowanie podaje okres odpowiadający liczonym zdarzeniom. Nie wywodzimy
z działania automatu aktualnej widoczności treści ani wiedzy jej autora.
Zachęta do odpowiedzi na pierwszy wpis nie potrzebuje twierdzenia o retencji.
Przykłady i regresja: `../design/PRECYZJA_KOMUNIKATOW_514.md`.

## 1. Jeden akapit, który wystarczy zapamiętać

Kuking mówi jak **sąsiadka, która dobrze gotuje i nie ma potrzeby się popisywać**.
Ciepło, krótko, z lekkim uśmiechem. Żart jest **w tle**, nigdy na pierwszym planie —
ma być zauważony przy drugim czytaniu, nie wywalczyć sobie uwagę przy pierwszym.

Test, który przechodzi każdy nasz tekst:

> Czy 65-letnia Basia z Podkarpacia przeczytałaby to na głos córce
> **bez zażenowania** — ani swojego, ani jej?

Jeśli tekst brzmi jak reklama, jak aplikacja do medytacji albo jak wnuczek
tłumaczący coś babci — do przepisania.

---

## 2. kuKING — najważniejsza decyzja w całym systemie

> **Trzy zapisy, trzy zastosowania — nie myl ich (D-015):**
>
> | zapis | gdzie | przykład |
> |---|---|---|
> | `KuKing.pl` | **tylko logotyp** — belka u góry, znak, materiały marki | — |
> | `kuKING` (dwukolorowo, komponentem `<x-kuking-word/>`) | **tekst ciągły, nagłówki, nawigacja, stopka i o człowieku** | „Świeżo z kuKING", „Zostań kuKINGiem" |
> | `Kuking` | **tam, gdzie koloru nie ma** — `alt`, `title`, tytuł strony, temat listu, eksport — oraz w błędzie, moderacji i tekście prawnym | „Twój link do zalogowania w Kuking" |
>
> Wersalik w środku logotypu jest częścią znaku, a nie zasadą ortograficzną.
> Drugi wiersz tej tabeli to **zmiana z 11 września 2026** (decyzja właściciela
> B2): wcześniej tekst ciągły pisał `Kuking`, a `kuKING` był zarezerwowany dla
> człowieka. Uzasadnienie i pięć wyjątków: `GLOS_MARKI.md` §2.

W słowie **Ku-KING** siedzi **KING**. To jest cała zabawa i trzeba ją rozegrać
dokładnie w jeden sposób.

### kuKING to nazwa mieszkańca, nie tytuł za osiągnięcia

**kuKING** = ktoś, kto tu gotuje. Tak jak „forumowicz", „wikipedysta",
„nasz-klasowicz". Neutralne, ciepłe określenie **przynależności**.

To jest kluczowe rozstrzygnięcie, bo `MASCOT_CONCEPT.md` zawiera twardy zakaz:

> **Nigdy „Jesteś królem kuchni!"** — korona jest żartem o garnku, nie
> komplementem dla użytkownika.

Ten zakaz zostaje w mocy i **nie kłóci się** z „Zostań kuKINGiem". Różnica jest
w tym, o czym jest żart:

| | O czym jest żart | Ocena |
|---|---|---|
| „Zostań kuKINGiem" | o nazwie serwisu | ✅ wolno |
| „Witaj w gronie kuKINGów" | o przynależności | ✅ wolno |
| „Jesteś prawdziwym kuKINGiem!" | o użytkowniku | ❌ zakaz |
| „Top kuKINGi tygodnia" | o hierarchii | ❌ zakaz |

Założenie projektowe: nie oceniamy użytkownika za publikację. Komplement
może podnosić poprzeczkę („skoro to ma być królewskie, to ja nie mam czego pokazać”).
Nazwa przynależności ma ją **obniżać** — wystarczy tu być.

### Odmiana

Używamy tylko form, które brzmią naturalnie:

- **kuKING** — mianownik: „Zostań kuKINGiem", „nowy kuKING"
- **kuKINGiem** — narzędnik: „Zostań kuKINGiem"
- **kuKINGów** — dopełniacz mnogi: „2 431 kuKINGów"
- **kuKINGi** — mianownik mnogi w znaczeniu **rzeczy, nie osób**: „kuKINGi na dziś"

Form, których **nie używamy**, bo brzmią sztucznie albo dziwnie w mowie:
`kuKINGowi`, `kuKINGu`, `kuKINGowie`, `kuKINGce`, `kuKINGówka`.
Jeśli zdanie wymaga takiej formy — przepisz zdanie, nie odmieniaj słowa na siłę.

### Forma żeńska: nie tworzymy jej

W założeniach projektowych uwzględniamy między innymi kobiety 60+. To wybór
persony, nie pomiar składu społeczności Kuking. Żadna żeńska forma od „kuKING" nie brzmi po polsku dobrze —
każda próba wychodzi albo pretensjonalnie, albo śmiesznie w złym sensie.

Dlatego: **kuKING jest nazwą rodzaju wspólnego, jak „gość" w „mamy gościa"**,
i używamy go w liczbie mnogiej albo bezosobowo. Zamiast szukać żeńskiej formy,
zmieniamy konstrukcję zdania.

```text
❌ Jesteś kuKINGiem od 2 lat
❌ Jesteś kuKINGką od 2 lat
✅ Gotujesz z nami od 2 lat
✅ W Kuking od 2 lat
```

W pozostałych tekstach zwracamy się **bezpośrednio, przez „Ty"** i unikamy
rodzaju: „Napisz kilka słów", „Zapisz", „Pokaż, co dziś ugotowałeś".

> Uwaga na formy czasowników. „ugotowałeś" w haśle głównym jest już utrwalone
> i zostaje. W tekstach roboczych wolimy konstrukcje bez rodzaju: **„Co dziś
> gotujesz?"** działa dla wszystkich i jest krótsze.

### Tej reguły pilnuje test, nie czyjaś pamięć (issue #274)

Do września 2026 reguła stała tu sama i nie działała. Właściciel — mężczyzna,
który nigdy nie podawał płci, bo serwis o nią nie pyta — zobaczył w ustawieniach
prywatności „Przypominaj mi, co **gotowałam** w tym dniu". Przegląd znalazł
kilkanaście takich miejsc: profil („za rok będziesz **mogła**"), paczka RODO
(„co dziś **ugotowałam**"), bezpieczeństwo konta („**zostałaś/eś zalogowana/y**"),
pusta sekcja komentarzy („możesz być **pierwsza albo pierwszy**"), walidacja
nazwy konta („z tego, co **wpisałeś**"), a także regulamin i polityka
prywatności, gdzie obie płcie stały w jednym dokumencie.

Granica jest jedna i prosta:

> **Rodzaju wolno użyć, gdy wiemy, o kim mówimy. Nie wolno, gdy mówimy DO
> czytelnika albo w jego imieniu.**

Dlatego „Halina ugotowała Twój rosół" zostaje bez zmian, a „co gotowałam"
w ustawieniach jest usterką. Poprawka polega na **przebudowaniu zdania** —
nigdy na zamianie formy żeńskiej na męską (to przenosi ten sam błąd na drugą
połowę ludzi) i nigdy na wypisaniu obu form obok siebie:

```text
❌ Przypominaj mi, co gotowałam w tym dniu
❌ Przypominaj mi, co gotowałem/gotowałam w tym dniu
✅ Przypominaj mi moje wpisy z tego dnia

❌ Możesz być pierwsza albo pierwszy
✅ Napisz pierwszy komentarz

❌ Za rok będziesz mogła tu wrócić i zobaczyć, co wtedy gotowałaś
✅ Za rok zobaczysz tu, co gotujesz dzisiaj
```

Nowe brzmienie ma być **krótsze albo równie krótkie** jak stare. Przy grupie
50-75 lat długość zdania to nie estetyka.

Reguła jest sprawdzana maszynowo przez `tests/Feature/TekstyNiePrzypisujaPlciTest.php`:
skan widoków, tekstów prawnych, tłumaczeń i napisów składanych w PHP, z jawną
listą czterech wyjątków. Wyjątkiem jest **fraza**, nie słowo — hasło główne
(„co dziś ugotowałeś") i nazwa przycisku („Ugotowałem") przechodzą, ale nowe
zdanie z formą rodzajową oblewa, choćby użyło tego samego czasownika.

### Nie doklejaj przyimka do cudzych słów (11 września 2026)

> **Nigdy nie doklejaj przyimka ani słowa niosącego przypadek do tekstu
> wpisanego przez człowieka ani do nazwy konta.** Polskiej odmiany nie da się
> policzyć z dowolnego ciągu znaków, a każda próba kończy się zdaniem, które
> wygląda na zepsute oprogramowanie.

Właściciel zapytał: „czemu źródło przepisu ma «po» przed źródłem?". Widok
doklejał „Po" przed wolny tekst z pola, więc wpisane „Nasze smaki" dawało
**„Po Nasze smaki."**, a wpisane „po mamie" — **„Po po mamie."**. Podpis nad
tytułem miał ten sam błąd dwa razy: „przepis **Nasze smaki**, spisany przez
**Krzysztof**".

Poprawka ma dwie części i obie są obowiązkowe przy każdym następnym takim polu:

1. **Pytaj o frazę, która stoi samodzielnie** — „Od kogo albo skąd masz ten
   przepis" zamiast „Po kim ten przepis". Odpowiedź czyta się wtedy i sama
   („Od mamy."), i po innym słowie („przepis od mamy").
2. **Pokazuj wartość dosłownie.** Znaczenie niesie nagłówek sekcji albo
   etykieta z dwukropkiem („Skąd: Nasze smaki") — dwukropek zdejmuje wymaganie
   przypadku. Nazwa konta stoi w mianowniku, w osobnym członie po „·", nigdy
   po „przez".

Pierwszą literę podnosi `\Illuminate\Support\Str::ucfirst()` (wielobajtowe),
żeby wpisane małą literą „od mamy" wyglądało jak zdanie. Kropki na końcu nie
dokładamy — przy wpisanej kropce wyszłyby dwie.

### Dawkowanie: bez limitu na ekran, ale raz na akapit

**Limitu „jedna gra słowem na ekran" już nie ma** (decyzja właściciela,
11 września 2026 — `GLOS_MARKI.md` §2 i §4). Zapis dwukolorowy stoi wszędzie,
także jako nazwa serwisu w tekście bieżącym. Została jedna reguła liczbowa:
**w jednym akapicie, nagłówku albo punkcie listy nazwa pojawia się raz** —
dwa dwukolorowe słowa w polu jednego spojrzenia migoczą.

**kuKING nigdy nie pojawia się w:**

- komunikacie błędu — człowiek ma wtedy problem, nie nastrój na żarty,
- wiadomości moderacyjnej,
- regulaminie, polityce prywatności i zasadach,
- powiadomieniu o cudzej aktywności („Halina ugotowała Twój rosół" jest już
  doskonałe — dodanie „kuKING" tylko by je rozmyło),
- formularzu, który ktoś właśnie wypełnia.

### Zapis i dostępność

Piszemy `kuKING` — małe „ku", wersaliki „KING". W kodzie:

```blade
<x-kuking-word />             {{-- „kuKING" --}}
<x-kuking-word forma="iem" /> {{-- „kuKINGiem" --}}
```

Nie piszemy tego znacznikami z ręki. Powód, dla którego istnieje komponent:
część czytników ekranu literuje wersaliki wewnątrz wyrazu („ku-ka-i-en-gie"),
więc obok wersji wizualnej stoi zapis małymi literami czytany wyłącznie przez
czytnik. **`aria-label` na `<span>` tego NIE robi** — specyfikacja „ARIA in
HTML" zakazuje go na elementach o roli `generic`, więc czytniki ten atrybut
ignorują. Wcześniejszy przykład w tym miejscu pokazywał właśnie taki
`<span aria-label="kuking">` i był nieprawdziwy.

W tekstach niesformatowanych (e-maile tekstowe, alt, tytuł strony) piszemy
zwyczajnie: **Kuking**.

---

## 3. Rejestr — cztery poziomy i gdzie który obowiązuje

| Poziom | Gdzie | Jak brzmi |
|---|---|---|
| **Ciepły z żartem** | strona główna dla gości, puste stany, ekran po pierwszym wpisie, digest | „Zostań kuKINGiem", „kuKINGi na dziś" |
| **Ciepły bez żartu** | feed, profil, przepis, przyciski, powiadomienia | „Pokaż, co dziś ugotowałeś", „Zapisuję" |
| **Rzeczowy** | formularze, ustawienia, pomoc | „Wybierz zdjęcie z telefonu", „Rozmiar tekstu" |
| **Poważny** | błędy, moderacja, usuwanie konta, prawne | „To zdjęcie waży za dużo. Maksymalny rozmiar to 15 MB — wybierz mniejsze." |

Ruch odbywa się **tylko w dół**. Ekran z poziomu „poważny" nigdy nie dostaje
żartu z góry. Ekran „ciepły z żartem" może być rzeczowy, jeśli tak wyjdzie lepiej.

---

## 4. Skąd bierzemy humor (i skąd nie)

### Bierzemy

**Niedopowiedzenie.** Najlepszy nasz żart to zdanie, które nie próbuje być żartem.

```text
✅ Wyszło. I to się liczy.
✅ Nie musi być ładne. Ma być prawdziwe.
✅ Rosół nie znosi pośpiechu. Ta strona też nie.
```

**Rozpoznanie realnego życia w kuchni.** Śmieszne jest to, co prawdziwe.

```text
✅ „mleko — ile weźmie"
✅ Pisz tak, jak mówisz: „szklanka mąki", „2 duże cebule".
✅ Jeśli nie masz teraz czasu — zapisz szkic. Nic nie zginie.
```

**Ciepła autoironia serwisu wobec siebie.**

```text
✅ Garnuś nic nie mówi. Garnuś patrzy.
✅ Tu nie ma rankingów. Nie ma kogo wyprzedzać.
```

### Nie bierzemy

```text
❌ Hej! 🎉 Zróbmy to razem!
❌ Ups! Coś poszło nie tak 😅
❌ Twoja kulinarna przygoda właśnie się zaczyna!
❌ Odkryj świat smaków w naszej społeczności
❌ Tapnij, żeby kontynuować
❌ Jesteś na fali! Świetna robota!
```

Cztery reguły techniczne:

1. **Zero emoji w tekstach interfejsu.** Emoji w nawigacji są ikonami
   z podpisem, nie żartem, i to jest jedyne ich zastosowanie.
2. **Jeden wykrzyknik na ekran, najwyżej.** Zwykle zero.
3. **Bez wielokropków** zawieszających napięcie („Zaraz zobaczysz...").
4. **Bez pytań retorycznych** poza jednym: „Co dziś ugotowałeś?" — to jest
   pytanie prawdziwe, oczekujemy odpowiedzi.

---

## 5. kuKINGi na dziś — tablica polecanych

Na stronie powitalnej wariant D-215 ma nagłówek „Co się dziś gotuje”,
opis „Codzienne gotowanie, zdjęcia i pomysły od osób z Kuking.” oraz
podsekcję „Poznaj ich kuchnie”. Wspólne zaproszenie gościa:
„Obserwuj osoby, do których kuchni chcesz wracać.” i przycisk
„Załóż konto, żeby obserwować”. Nazwa autora dania jest odnośnikiem do
wpisu, którego kliknięcie obejmuje również zdjęcie; nie dokładamy
powtarzanego przycisku „Zobacz”. Pozostałe szyny zachowują swój wariant.

Sekcja z kilkoma osobami i kilkoma wpisami wartymi zobaczenia dzisiaj.
Odpowiednik „Dla Ciebie" z innych portali, tylko **bez algorytmu i bez rankingu**.

### Co to jest, a czym nie jest

| Jest | Nie jest |
|---|---|
| krótka, redakcyjna albo chronologiczna | rankingiem popularności |
| 3-4 osoby + 3-4 wpisy | ścianą kafelków |
| powodem, żeby kogoś zaobserwować | listą „najlepszych" |
| zmienna z dnia na dzień | miejscem, do którego się awansuje |

To rozstrzygnięcie wynika z `AGENTS.md`: publiczne rankingi natychmiast dzielą
ludzi na dwie klasy i wyłączają publikowanie u większości. „kuKINGi na dziś"
to **zaproszenie**, nie wyróżnienie.

### Teksty tej sekcji

```text
Nagłówek:        kuKINGi na dziś
Podtytuł:        Kilka osób i kilka dań, które dziś warto zobaczyć.
Przycisk osoby:  Obserwuj
Przycisk wpisu:  Zobacz
Stopka sekcji:   Tu nie ma rankingu. Pokazujemy różne osoby, nie najlepsze.
Pusty stan:      Dziś jeszcze nikogo nie wybraliśmy. Zajrzyj do „Świeżo z Kuking”.
```

Stopka jest ważna: mówi wprost, że to nie jest tabela wyników. Wolno ją
zastąpić innym zdaniem robiącym to samo — nie wolno jej usunąć.

> **Zmiana z 11 września 2026.** Stało tu „Jutro będzie tu ktoś inny." i było
> to obiecywanie pewności, której produkt nie daje. Zmierzone przy plikach:
> tablicę zmienia RĘCZNY wybór gospodarza w panelu `/admin/kuking-na-dzis`
> (`DailyBoardController::update()`), a gdy gospodarz nic nie wybierze, wchodzi
> wariant zapasowy sortujący po dacie publikacji. Żaden automat tej tablicy nie
> odświeża. W wolny dzień jutro stoją tam więc te same osoby co dziś — a przy
> zimnym starcie (`docs/product/COLD_START.md`) to jest reguła, nie wyjątek.

### ⚠️ Zastrzeżenie do sprawdzenia na ludziach

Dla **rzeczownika osobowego** forma `kuKINGi` jest w polszczyźnie
deprecjatywna — ta sama, która daje „profesory" i „chłopy".

Nazwa zostaje, bo `kuKING` jest tu użyty w znaczeniu **rzeczy**
(por. „mityng → mityngi"), a tablica pokazuje dania obok ludzi. Ale to jest
rozumowanie zza biurka i musi zostać sprawdzone na realnych osobach
w testach 50+ (issue #15), jednym pytaniem: **„o czym jest ta sekcja?"**

Gotowe alternatywy, gdyby test wypadł źle:

| Alternatywa | Dlaczego działa |
|---|---|
| **Dziś u kuKINGów** | dopełniacz mnogi nie jest formą deprecjatywną, gra słowem zostaje |
| **Co się dziś gotuje** | nie odmienia słowa wcale, problem znika u źródła |

Decyzja i uzasadnienie: `../DECISIONS.md` D-013.

### Nazwy odrzucone i dlaczego

| Nazwa | Dlaczego nie |
|---|---|
| Top kuKINGi | ranking — wprost zakazany |
| Polecane dla Ciebie | brzmi jak algorytm, którego nie mamy |
| Odkrywaj | D-207 dopuszcza tę etykietę w nawigacji komputerowej do publicznego strumienia; wyszukiwarka nadal nazywa się Szukaj |
| Trendy w Kuking | korpo-mowa, obca tej grupie |
| Gwiazdy Kuking | tworzy influencerów, czego świadomie nie chcemy |
| Warto zobaczyć | poprawne, ale nudne — a nazwa jest jednym z niewielu miejsc, gdzie wolno nam być zabawnymi |

---

## 6. Gotowe teksty — do wklejenia

Kolumna „miejsce" wskazuje realny plik albo ekran.

### Wejście i konto

| Miejsce | Tekst |
|---|---|
| hasło główne | Pokaż, co dziś ugotowałeś. |
| hasło drugie | Gotujemy po swojemu. |
| przycisk rejestracji | Zostań kuKINGiem — bez opłat i bez reklam |
| przycisk obok | Najpierw się rozejrzę |
| nagłówek rejestracji | Zostań kuKINGiem |
| pod nagłówkiem | Cztery pola i gotowe. Nie pytamy o numer telefonu ani o datę urodzenia. |
| po rejestracji | Konto gotowe. Miło Cię widzieć. |
| koniec onboardingu | Wszystko gotowe, {imię} |
| pod tym | Możesz od razu pokazać, co dziś ugotowałeś — albo najpierw się rozejrzeć. |
| logowanie, podpowiedź | Możesz wpisać e-mail albo swoją nazwę — obojętnie które. |

### Publikacja

| Miejsce | Tekst |
|---|---|
| powitanie na Start | Dzień dobry, {nazwa z profilu} |
| powitanie bez nazwy | Dzień dobry |
| tytuł kafla dodawania | Co dziś gotujesz? |
| opis kafla dodawania | Zdjęcie i kilka słów wystarczą. |
| przycisk główny na Start | Dodaj zdjęcie |
| pole tekstowe | Napisz kilka słów |
| podpowiedź pod polem | Na przykład: „Rosół na niedzielę, z kaczki od sąsiada. Wyszedł złoty." |
| wybór zdjęcia | Na telefonie kliknij tutaj, a potem wybierz „Galeria" albo „Zrób zdjęcie". |
| po pierwszym wpisie | Gotowe. To Twój pierwszy wpis — od teraz masz swoje archiwum. |
| po kolejnym | Opublikowane. Dziękujemy. |
| autosave szkicu | Szkic zapisany. |

**D-207, wzorzec wskazany przez właściciela 13 września 2026:** krótkie
„Dzień dobry” jest stałym zwrotem grzecznościowym. Nie dobieramy powitania
według zegara serwera ani nie zakładamy strefy czasowej lub płci odbiorcy.
Pokazujemy nazwę z profilu bez automatycznego zgadywania wołacza; przy pustej
nazwie nie podstawiamy „Użytkownika Kuking”. Pytanie „Co dziś gotujesz?”
stoi w kaflu, a przyciski nazywają działania. Zastępuje to poprzednie
„Witaj, {imię}. Co dziś gotujesz?” i rozdziela powitanie od publikacji.
Regresja: `tests/Feature/PytanieDniaTest.php`.

### Przepis

| Miejsce | Tekst |
|---|---|
| zachęta do zapisu szkicu (formularz na jednej stronie) | Zapisz szkic, jeśli chcesz dokończyć przepis później. |
| to samo w kreatorze, gdzie szkic zapisuje się sam | Wystarczy nazwa, żeby ruszyć dalej. Od niej zaczyna się też zapisywanie: szkic zapisuje się sam po każdym kroku i po chwili przerwy w pisaniu, a przycisk „Zapisz szkic" robi to od razu. |
| sekcja pochodzenia | Skąd ten przepis |
| pod nagłówkiem sekcji | Tu napiszesz, skąd masz ten przepis i co Cię z nim wiąże. |
| pole „skąd" | Od kogo albo skąd masz ten przepis |
| podpowiedź | od mamy · z gazety · z bloga Nasze smaki |
| pomoc pod polem | Napisz to tak, żeby dało się przeczytać samo: „od mamy", „z gazety", „od sąsiadki Haliny". Pokażemy to przy przepisie dokładnie tak, jak wpiszesz. |
| pole historii | Historia tego przepisu |
| podpowiedź | Skąd go znasz, kiedy się go gotuje, co Ci się z nim wiąże. Ta historia jest częścią przepisu — zobaczy ją każdy, kto zobaczy przepis. |
| skan kartki | Jeśli masz przepis zapisany ręcznie — zrób mu zdjęcie. Zostanie przy przepisie. |
| składniki, podpowiedź | Pisz tak, jak mówisz: „szklanka mąki", „2 duże cebule", „mleko — ile weźmie". Nie musisz nic przeliczać na gramy. |
| kroki, podpowiedź | Jeden krok to jedna czynność. Krótkie kroki łatwiej czytać przy garnku. |
| po publikacji dla wszystkich | Przepis opublikowany. Teraz ktoś może z niego ugotować. |
| po publikacji dla obserwujących | Przepis opublikowany dla osób, które Cię obserwują. |
| po zapisaniu prywatnego przepisu | Przepis zapisany. Widzisz go tylko Ty. |

### Ugotowałem

| Miejsce | Tekst |
|---|---|
| przycisk na przepisie | Ugotowałem |
| nagłówek sekcji | Gotujesz z tego przepisu? |
| pod nagłówkiem | Otwórz „Ugotowałem”, a potem wyślij formularz. |
| formularz, uspokojenie | Nie musisz wypełniać żadnego pola — wystarczy, że klikniesz „Wyślij". |
| zdjęcie efektu | To jest najmilsza część dla autora przepisu. Zdjęcie nie musi być ładne. |
| pole uwagi | Jak wyszło? |
| pole zmian | Coś po swojemu? |
| po wysłaniu | Wykonanie zapisane. |
| powiadomienie autora | {imię} ugotowała Twój rosół. |
| sekcja pod przepisem | Komu wyszło |
| pod nagłówkiem | Zdjęcia od ludzi, którzy naprawdę to zrobili u siebie. |

Instrukcja formularza może zapowiadać powiadomienie innego autora, który
może czytać serwis. Przy własnym przepisie i autorze wymazanym mówi:
„Zapisz wykonanie tego przepisu.” Zawieszony autor nadal może czytać
i otrzymuje powiadomienie (AGENTS.md §1). Potwierdzenie zapisu oraz
ponownego wysłania nie podaje liczby powiadomień: ich brak w tych
wyjątkach jest prawidłowy, a wcześniejsze powiadomienie może już nie istnieć.

### Puste stany

| Miejsce | Tekst |
|---|---|
| pusty feed | Jeszcze nic tu nie ma |
| + wyjaśnienie | Zacznij od zdjęcia tego, co dziś ugotowałeś. Nie musi być ładne — ma być prawdziwe. |
| pusty zeszyt | Zeszyt jest jeszcze pusty |
| + wyjaśnienie | Kiedy znajdziesz przepis, który chcesz zachować, kliknij przy nim „Zapisuję". Zapisany przepis pojawi się w Twoim zeszycie. |
| puste archiwum, własne | Twoje archiwum jest jeszcze puste |
| + wyjaśnienie | Od pierwszego zdjęcia zaczyna się Twoje archiwum. Za rok zobaczysz tu, co gotujesz dzisiaj. |
| brak powiadomień | Nie ma jeszcze żadnych powiadomień |
| + wyjaśnienie | Tu pojawi się informacja, kiedy ktoś ugotuje z Twojego przepisu albo napisze komentarz. |
| brak wyników szukania | Nic nie znaleźliśmy |
| + wyjaśnienie | Nie ma jeszcze przepisu, który by pasował do „{fraza}". Może to Ty go dodasz? |
| brak komentarzy | Jeszcze nikt tu nic nie napisał. Napisz pierwszy komentarz. |

### Błędy — poziom „poważny", zero żartów

Wzór: **co się stało → dlaczego → co zrobić.**

| Sytuacja | Tekst |
|---|---|
| zdjęcie za duże | To zdjęcie waży za dużo. Maksymalny rozmiar to 15 MB — wybierz mniejsze zdjęcie. |
| nie jest obrazem | Ten plik nie wygląda na zdjęcie. Wybierz plik JPG, PNG lub WebP. |
| pusty wpis | Dodaj zdjęcie albo napisz kilka słów — inaczej nie ma czego opublikować. |
| hasło za krótkie | Hasło musi mieć co najmniej 10 znaków. Najprościej połączyć myślnikami trzy swoje słowa, na przykład: parasol-wtorek-cebula. Wymyśl własne, nie przepisuj tych z przykładu. |
| hasło z wycieku | To hasło pojawiło się już w wyciekach danych z innych serwisów. Wybierz inne. |
| zła nazwa użytkownika | Nazwa użytkownika może zawierać tylko litery bez polskich znaków, cyfry i podkreślnik. Na przykład: basia_z_podkarpacia. |
| nazwa zajęta | Ta nazwa jest już zajęta. Spróbuj dodać coś na końcu. |
| błąd logowania | Nie udało się zalogować. Sprawdź, czy nazwa i hasło są wpisane poprawnie. Jeśli nie pamiętasz hasła, kliknij „Nie pamiętam hasła". |
| za dużo prób | Za dużo prób logowania. Spróbuj ponownie za {n} min. |
| podsumowanie błędów | Sprawdź formularz |
| brak internetu | Nie ma teraz połączenia z internetem |
| + wyjaśnienie | Kuking potrzebuje internetu, żeby pokazać nowe wpisy. Sprawdź Wi-Fi albo dane w telefonie i spróbuj jeszcze raz. |

Nagłówek podsumowania nie zgaduje przyczyny błędu. Wpisana wartość może być
za długa, nieprawidłowa albo już zajęta; nie każda walidacja oznacza brak
danych. Konkretna przyczyna i sposób poprawy pozostają przy polu oraz w
podsumowaniu. Odnośnik pod formularzem mówi „co trzeba poprawić”, nie
„czego jeszcze brakuje”. Dotyczy też kreatora i panelu moderacji (#527).

### Rzeczy nieodwracalne

Tu obowiązuje **pełna szczerość i zero łagodzenia**.

| Sytuacja | Tekst |
|---|---|
| usunięcie wpisu | Na pewno usunąć ten wpis? Tej operacji nie da się cofnąć samodzielnie. |
| usunięcie przepisu | Na pewno usunąć ten przepis? Wykonania i komentarze innych osób też przestaną być widoczne. |
| blokada osoby | Zablokować {imię}? Nie zobaczycie już wzajemnie swoich treści. |
| przed usunięciem konta | Zanim to zrobisz, warto najpierw pobrać swoje dane. |
| potwierdzenie | Rozumiem, że po 30 dniach moje wpisy, przepisy i zdjęcia zostaną usunięte na stałe |
| po zgłoszeniu | Konto zostało oznaczone do usunięcia. Masz 30 dni, żeby zmienić zdanie — wystarczy, że się zalogujesz i napiszesz do nas. |

### E-mail

| Miejsce | Tekst |
|---|---|
| temat digestu, gdy ktoś ugotował | {imię} ugotowała Twój rosół |
| temat digestu, ogólny | Co się działo w Kuking w tym tygodniu |
| nadawca | imię gospodarza, nigdy „Zespół Kuking" |
| stopka wypisania | Nie chcesz tych wiadomości? Wyłącz je jednym kliknięciem. Bez pytań. |
| eksport gotowy | Twoje dane są gotowe do pobrania |
| + treść | Przygotowaliśmy paczkę ze wszystkim, co tu masz. Otworzysz ją na swoim komputerze, także wtedy, gdyby Kuking kiedyś przestał istnieć. |

### Przegląd tej sekcji: 12 września 2026 (issue #38)

Pięć wierszy wyżej uczyło tekstów, które produkt odrzucił przy #274, a które
ten sam dokument zakazuje w §2 — i stały tu jako wzór do skopiowania:

| Było | Jest | Skąd nowe brzmienie |
|---|---|---|
| ❌ Ugotowałeś z tego przepisu? | Gotujesz z tego przepisu? | konstrukcja bez rodzaju z §2 |
| ❌ Zrobiłem coś po swojemu? | Coś po swojemu? | `pages/cooked/create.blade.php` |
| ❌ Za rok będziesz mogła tu wrócić i zobaczyć, co wtedy gotowałaś. | Za rok zobaczysz tu, co gotujesz dzisiaj. | `pages/profile/show.blade.php` |
| ❌ Możesz być pierwsza albo pierwszy. | Napisz pierwszy komentarz. | `components/comment-thread.blade.php` |
| ❌ …co tu wrzuciłaś. | …co tu masz. | `mail/data-export-ready.blade.php` |

Każdy z nich jest **przebudowany**, nie uzupełniony o drugą formę — §2 zakazuje
i jednego, i drugiego. Tam, gdzie napis żyje już na ekranie, przepisane jest
brzmienie **z kodu**: dokument idzie za produktem, nie odwrotnie.

Od tego przeglądu pilnuje tego test
`tests/Feature/PrzewodnikTrzymaSieWlasnychZasadTest.php`. Skanuje gotowe teksty
do wklejenia w całym `docs/brand/` i oblewa, gdy wzór łamie regułę z tego samego
dokumentu. **Cytatu oznaczonego „❌" i prozy nie rusza** — o błędach trzeba móc
pisać, a tabela wyżej jest tego najlepszym przykładem.

---

## 7. Lista kontrolna przed wysłaniem tekstu

- [ ] Da się to przeczytać na głos bez zażenowania?
- [ ] Zero emoji, najwyżej jeden wykrzyknik?
- [ ] Gra słowem kuKING występuje **najwyżej raz w tym akapicie** (na ekranie — bez limitu)?
- [ ] Na pewno nie ma jej w błędzie, moderacji ani tekście prawnym?
- [ ] Komunikat błędu mówi, **co zrobić**?
- [ ] Nie ma słów z listy zakazanych (`BRAND_EXTENDED.md`)?
- [ ] Nie ma komplementu za publikację ani śladu rankingu?
- [ ] Konstrukcja nie zakłada rodzaju tam, gdzie da się tego uniknąć?
- [ ] Zdanie nie jest dłuższe niż trzeba? (Skreśl trzy słowa. Zwykle da się.)
- [ ] **Czy to zdanie jest prawdziwe przy kodzie, który dziś stoi w repozytorium?**
      Obietnica harmonogramu, liczby albo cudzego zachowania („jutro", „zajmie
      minutę", „najczęściej czytana") wymaga mechanizmu albo pomiaru. Nie ma —
      nie piszemy.

---

## 8. Decyzje podjęte i te, które zostały

### Podjęte

**Dawka: bez limitu na ekran** (decyzja właściciela z 11 września 2026 zmienia
D-009 w tej części; wcześniej obowiązywała „dawka umiarkowana" z 5 września).

`kuKING` stoi wszędzie, gdzie jest czytany jako nazwa — w tekście ciągłym też.
Pełne uzasadnienie i granice: `GLOS_MARKI.md` §2 i §4.

Odrzucone świadomie:

| Odrzucone | Dlaczego |
|---|---|
| ~~`kuKINGujesz`~~ | **już nie obowiązuje** — 11 września 2026 właściciel dopuścił czasownik w haśle, nagłówku, digeście i zaproszeniu; dalej nie wolno go w nawigacji ani na jedynym przycisku akcji (`GLOS_MARKI.md` §1) |
| `Mój kuKING` w nawigacji | nawigacja ma być przewidywalna, nie dowcipna |
| forma żeńska | żadna nie brzmi po polsku dobrze |
| dawka minimalna | „kuKINGi na dziś" to jedna z mocniejszych rzeczy w tym pomyśle, szkoda jej |

**„Zostań kuKINGiem" zastępuje „Załóż konto"** tam, gdzie jest miejsce na
kontekst: strona główna i nagłówek `/register`. W wąskim pasku nawigacji
zostaje krótkie „Załóż konto".

**Imię gospodarza w e-mailach: Ula** (decyzja właściciela — patrz
`../DECISIONS.md` D-037). Bez prawdziwego imienia
digest tracił większość swojej wartości. Imię mieszka w jednym miejscu,
`config('kuking.community.host_name')`, i stamtąd składa nazwę nadawcy
poczty (`config/mail.php`) — nie jest wpisane osobno w żadnym szablonie.

**Akcja zapisania do Zeszytu: „Zapisuję", nie „Zapisz"** (decyzja
właściciela — patrz `../DECISIONS.md` D-036).
Rozstrzyga sprzeczność, która stała w produkcie: karta wpisu mówiła
„Zapisz", karta przepisu i pusty Zeszyt już wtedy mówiły „Zapisuję".
`BRAND_EXTENDED.md` §1.2 poprawione zgodnie z tym wyborem.

### Zostały

1. **Weryfikacja u realnych użytkowników.** Dawka jest wybrana rozsądnie, ale
   dopiero testy z osobami 50+ (#15) powiedzą, czy „kuKING" bawi, czy męczy.

## Referencje

[`GLOS_MARKI.md`](GLOS_MARKI.md) (czym ten głos JEST i gdzie mówi) ·
`BRAND_EXTENDED.md` (słownik i słowa zakazane) · `MASCOT_CONCEPT.md` (zakaz
komplementowania koroną) · `../UX_50_PLUS.md` (wzorce błędów) ·
`../product/SOUL.md` (mikro-copy pustych stanów) · `../research/AUDIENCE_50_PLUS.md`
