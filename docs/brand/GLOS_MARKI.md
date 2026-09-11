# Głos marki Kuking — czym ten głos JEST

> **Dokument towarzyszy [`COPY_STYLE.md`](COPY_STYLE.md), nie zastępuje go.**
>
> | dokument | odpowiada na pytanie |
> |---|---|
> | [`COPY_STYLE.md`](COPY_STYLE.md) | jak napisać TO ZDANIE (rejestr, gotowe teksty, czego nie wolno) |
> | ten plik | czym ten głos JEST i GDZIE mówi (co wolno, po co, do jakiej granicy) |
>
> Przy sprzeczności między nimi wygrywa `COPY_STYLE.md` w sprawach brzmienia
> zdania i ten plik w sprawach zasięgu marki (gdzie wolno, jak często, na czym).
> Sprzeczność wykrytą i nierozstrzygniętą wpisuje się do `../DECISIONS.md`,
> a nie rozstrzyga w locie.

## 0. Po co ten dokument istnieje

`COPY_STYLE.md` jest dobrym dokumentem i zostaje w mocy. Ma jednak jedną
dziurę, przez którą dało się przejść: **jest listą zakazów.** Lista
kontrolna w §7 ma dziesięć pozycji i siedem z nich pyta, czego w zdaniu
NIE MA. Nie ma w tym dokumencie strony pozytywnej — zdania, które mówi,
czym ten głos JEST.

To nie jest zarzut akademicki. Audyt copy z 11 września 2026
(`../research/audyt-copy-2026-09-11/`) przeczytał ten dokument uczciwie
i wyciągnął z niego wniosek, że marka jest **kosztem, który trzeba racjonować**:
„maksymalnie jedna linia charakteru na ekran", „ograniczyć `kuKING` w CTA",
„interfejs ma być przezroczysty". Każde z tych zdań da się wyprowadzić
z listy zakazów. Żadnego nie da się wyprowadzić z odpowiedzi na pytanie,
czym ta marka jest — bo tej odpowiedzi nigdzie w repozytorium nie było.

Właściciel po przeczytaniu audytu powiedział: *„audyt zbyt restrykcyjny jest,
nie czuje vibe... trzeba ten vibe rozwinąć i zapisać tam na stałe"*.
Ten plik jest tym zapisem.

Rozstrzygnięcia z 11 września 2026 są **decyzjami właściciela**, nie
propozycjami: B1 (napis przycisku), B2 (dwukolorowy zapis wszędzie),
B3 (404 zostaje), B4 (podpowiedź pod komentarzem), grupa C (rejestr tekstów),
grupa D (trzy zdania nietykalne). Wpisy do `../DECISIONS.md` są w raporcie
z wdrożenia.

---

## 1. `kuKING` to nazwa człowieka, nie żart

### Różnica kategorii, na której stoi cały ten dokument

Audyt traktuje `kuKING` jak **dowcip**, a dowcip da się przedawkować — więc
proponuje dawkowanie. To jest poprawne rozumowanie o niepoprawnym
przedmiocie. `kuKING` nie jest dowcipem. Jest **nazwą mieszkańca**.

```text
Facebook nie racjonuje słowa „znajomy".
Strava nie racjonuje słowa „atleta".
Wikipedia nie racjonuje słowa „wikipedysta".
```

Nikt nie pisze w wytycznych, że „znajomy" może wystąpić najwyżej raz na
ekran, bo „znajomy" nie jest żartem do dawkowania — jest nazwą relacji,
której ten serwis jest miejscem. `kuKING` jest dokładnie tym: nazwą kogoś,
kim się tutaj jest.

**„Zostań kuKINGiem" nie jest dowcipem. Jest nazwaniem kogoś, kim ma się
stać.** Dlatego stoi w przycisku, a nie w przypisie.

To rozstrzygnięcie **nie znosi** zakazu z `MASCOT_CONCEPT.md` i z
`COPY_STYLE.md` §2: żart jest o NAZWIE SERWISU, nigdy o użytkowniku. Granica
biegnie tam, gdzie biegła:

| | O czym jest zdanie | Ocena |
|---|---|---|
| „Zostań kuKINGiem" | o nazwie | ✅ |
| „Witaj w gronie kuKINGów" | o przynależności | ✅ |
| „2 431 kuKINGów" | o liczbie ludzi tutaj | ✅ |
| „Jesteś prawdziwym kuKINGiem!" | o użytkowniku, komplementem | ❌ |
| „Top kuKINGi tygodnia" | o hierarchii | ❌ |
| „Zdobądź poziom kuKING" | o nagrodzie za coś | ❌ |

Powód ostatnich trzech jest produktowy, nie estetyczny, i jest zmierzony:
własne zdjęcie lub film zamieściło w ostatnim miesiącu **17% internautów
55–64 i 13% z 65+** (`../research/AUDIENCE_50_PLUS.md` §1), a `COPY_STYLE.md`
§2 dokłada do tego, że **ponad połowa osób 50+ w mediach społecznościowych
nigdy nic nie publikuje**. Komplement za publikację podnosi poprzeczkę.
Nazwa przynależności ją obniża — wystarczy tu być.

### Odmiana

Formy, które brzmią naturalnie i których używamy (bez zmian wobec
`COPY_STYLE.md` §2):

- **kuKING** — „Zostań kuKINGiem", „nowy kuKING", „Świeżo z kuKING"
- **kuKINGiem** — „Zostań kuKINGiem"
- **kuKINGów** — „2 431 kuKINGów", „Dziś u kuKINGów"
- **kuKINGi** — tylko w znaczeniu **rzeczy**: „kuKINGi na dziś" (D-013)

Formy, których nie używamy: `kuKINGowi`, `kuKINGu`, `kuKINGowie`, `kuKINGce`,
`kuKINGówka`. Zdanie wymagające takiej formy przepisujemy — nie odmieniamy
słowa na siłę.

**Formy żeńskiej nie tworzymy** i to się nie zmienia. `kuKING` jest nazwą
rodzaju wspólnego (jak „gość" w „mamy gościa"); zamiast szukać żeńskiej formy,
zmieniamy konstrukcję zdania. Powód jest w `COPY_STYLE.md` §2 i pilnuje go
`tests/Feature/TekstyNiePrzypisujaPlciTest.php`.

### Czasownik: wolno, ale tylko tam, gdzie kontekst go tłumaczy

**To jest zmiana wobec D-009**, która odrzuciła `kuKINGujesz` z uzasadnieniem
„nowy czasownik trzeba zrozumieć, a nasz odbiorca nie lubi zgadywać".
Właściciel tę część odwrócił: czasownika **wolno używać**.

Odrzucony argument nie był jednak błędny i nie znika — tylko przestaje być
zakazem, a staje się warunkiem. **W grupie 65–74 lata tylko `12,3%` osób ma
według unijnej metodologii podstawowe umiejętności cyfrowe**
(`../research/AUDIENCE_50_PLUS.md` §1) — czyli potrafi przejść ścieżkę
wyuczoną, a nie poradzić sobie z nową. Taki czytelnik nie ma zgadywać,
co znaczy słowo stojące na JEDYNEJ drodze do celu. Stąd granica:

| Gdzie | Czasownik | Dlaczego |
|---|---|---|
| hasło, nagłówek sekcji, digest, zaproszenie | ✅ „Dziś kuKINGujemy z resztek" | obok stoi zdanie, które tłumaczy; nic nie zależy od zrozumienia słowa |
| nawigacja | ❌ | nawigacja ma być przewidywalna, nie dowcipna (D-009, ta część zostaje) |
| jedyny przycisk realizujący akcję | ❌ „kuKINGuj to" zamiast „Opublikuj" | przycisk mówi, co robi — `AGENTS.md` §5 |
| błąd, moderacja, prawo, bezpieczeństwo | ❌ | patrz §3 |

### Przykłady, które brzmią dobrze — i dlaczego

```text
✅ Zostań kuKINGiem — bez opłat i bez reklam
   nazwanie kogoś, kim ma się stać, plus fakt. Nic tu nie jest o użytkowniku.

✅ Świeżo z kuKING
   nazwa miejsca. Krócej niż „Najnowsze wpisy od użytkowników" i mówi więcej.

✅ 2 431 kuKINGów
   liczba ludzi, nie liczba punktów. Nie sortuje, nie porównuje, nie promuje.

✅ kuKINGi na dziś
   nazwa tablicy. `kuKING` w znaczeniu rzeczy — dania i ludzie obok siebie
   (D-013; do sprawdzenia na ludziach w #15, patrz §9).

✅ Znasz już kogoś w kuKING?
   nazwa miejsca w pytaniu, na które odpowiada pole obok.
```

### Przykłady, które brzmią źle — i dlaczego

```text
❌ Jesteś prawdziwym kuKINGiem!
   komplement za publikację. Podnosi poprzeczkę u ludzi, którzy jej nie
   przeskakują — a to jest większość naszej grupy.

❌ Twoje konto kuKINGa wygasło
   nazwa człowieka wsadzona do komunikatu o problemie. Człowiek ma wtedy
   kłopot, nie ochotę na markę (§3).

❌ Zaloguj się, kuKINGu!
   forma, której nie używamy, plus zwrot do czytelnika przez nazwę.
   Brzmi jak zawołanie z reklamy.

❌ Administracja kuKING informuje, że Twoja treść została ukryta
   moderacja. Zakaz bezwarunkowy — patrz §3.

❌ kuKINGuj dalej!
   czasownik bez kontekstu, w funkcji zachęty bez treści. Nie mówi,
   co zrobić.

❌ Top kuKINGi tygodnia
   ranking. `AGENTS.md` §12 zabrania publicznych rankingów, a nazwa
   przynależności użyta jako wyróżnienie dzieli ludzi na dwie klasy.
```

---

## 2. Dwukolorowy zapis — wszędzie, i pięć miejsc, gdzie go nie ma

**Decyzja właściciela B2 (11 września 2026).** `kuKING` w zapisie
dwukolorowym — **„ku" w kolorze tekstu, „KING" w kolorze marki** — pojawia się
wszędzie, **także jako nazwa serwisu w tekście bieżącym**.

To jest jawne odwrócenie dwóch rzeczy zapisanych wcześniej:

1. reguły „gra słowem najwyżej raz na ekran" (`AGENTS.md` §11,
   `COPY_STYLE.md` §2, D-009);
2. podziału na trzy rejestry zapisu z **D-015** — do tej pory tekst ciągły
   pisał `Kuking`, a `kuKING` był zarezerwowany dla człowieka.

Właściciel wybrał to, znając ostrzeżenie o czytelności. Koszt został
**zmierzony, nie oszacowany** — patrz §4.

Logotyp `KuKing.pl` zostaje bez zmian: logotyp jest znakiem i rządzi się
swoim prawem (D-015, ta część obowiązuje dalej).

### Jedno miejsce w kodzie

Zapis żyje w komponencie `resources/views/components/kuking-word.blade.php`
i **nigdzie indziej**. Nie pisze się go ręcznie znacznikami — komponent razem
ze swoją klasą w arkuszu robi trzy rzeczy, o których łatwo zapomnieć:

```blade
<h1>O <x-kuking-word /></h1>
<h1>Zostań <x-kuking-word forma="iem" /></h1>
<p>{{ $liczba }} <x-kuking-word forma="ów" /></p>
```

1. **wersja dla czytnika ekranu.** Część czytników literuje wersaliki
   w środku wyrazu („ku-ka-i-en-gie"). Komponent podaje im obok zapis małymi
   literami. `aria-label` na `<span>` tego NIE robi — specyfikacja „ARIA in
   HTML" zakazuje go na elementach o roli `generic`, więc czytniki go ignorują;
2. **odmiana przez atrybut**, żeby formy nie rozjechały się po widokach;
3. **`.kuking-word { overflow-wrap: anywhere }`.** Słowo łamie się między „ku"
   i „KING" **wyłącznie wtedy, gdy inaczej wyszłoby poza wiersz**: w zdaniu nie
   ma spacji, więc dopóki się mieści, nic go nie dzieli i nośnik tej gry
   (wersaliki w ŚRODKU wyrazu) zostaje cały.

   Zakaz łamania na zawsze — `white-space: nowrap` — był tu **pierwszą wersją
   i został odrzucony po pomiarze**: na `/register` przy oknie 320 px
   i czcionce przeglądarki 200% samo słowo brało 315 px zaczynając od x = 32,
   czyli strona przewijała się w bok o 27 px. Przewijanie w poziomie jest
   naruszeniem WCAG 2.2 AA (1.4.10 Reflow), a gra słowem nie jest. Pełne
   uzasadnienie stoi przy regule w `resources/css/app.css`; zgodność tego
   punktu z arkuszem pilnuje
   `tests/Feature/GlosMarkiOpisujeArkuszPrawdziwieTest.php`.

### Pięć miejsc, w których nazwa zostaje zwykłym „Kuking"

„Wszędzie" ma granicę i to nie jest cofanie decyzji. Trzy z pięciu wyjątków
to ta sama lista, która stała w `COPY_STYLE.md` §2 na długo przed B2 (błąd,
moderacja, prawo; powiadomienie o cudzej aktywności; formularz w trakcie
wypełniania) — właściciel jej nie zdjął, bo mówi o czymś innym niż zasięg
nazwy. Pozostałe dwa biorą się z tego, że dwukolorowości tam fizycznie nie
ma: bez znacznika nie ma koloru, a na tle w kolorze marki nie ma kontrastu.

| # | Gdzie | Dlaczego |
|---|---|---|
| 1 | `alt`, `title`, `aria-label`, `<title>`, `meta`, JSON-LD, temat listu, pliki eksportu, tekst tylko dla czytnika | **koloru tam nie ma**, a znacznik w atrybucie wypisze się dosłownie. Wersaliki w środku wyrazu bez koloru czytają się jak literówka |
| 2 | błąd, moderacja, tekst prawny, ekran bezpieczeństwa, list techniczny | hierarchia tonu (§3). Zakaz jest bezwarunkowy i starszy niż B2 |
| 3 | powiadomienie o cudzej aktywności | „Halina — ugotowane z Twojego przepisu" jest doskonałe. To zdanie należy do Haliny, nie do marki |
| 4 | pole formularza, który ktoś właśnie wypełnia (etykieta, podpowiedź, walidacja) | tam marka konkuruje z zadaniem (§4). Nagłówek TEGO SAMEGO ekranu wolno — „Zostań kuKINGiem" nad `/register` zostaje |
| 5 | tło w kolorze marki (przycisk podstawowy) | czerwień na czerwieni ma kontrast **1,00:1**. Nie ma odcienia, który to naprawia — „KING" bierze wtedy kolor otoczenia, a nośnikiem zostają same wersaliki (`kuking-word--bez-koloru`) |

Punkty 1, 2 i 5 pilnuje `tests/Feature/TekstyWedlugCopyStyleTest.php`.

### Kontrast: policzone, nie założone

„KING" jest pisane kolorem, więc jest **tekstem**, nie dekoracją — obowiązuje
go WCAG 2.2 AA, kryterium **1.4.3 (4,5:1)**. Zmierzone 11 września 2026 dla
`--color-brand` (`#B3401F` w motywie jasnym, `#F2986A` w ciemnym):

| Tło | Jasny | Ciemny |
|---|---:|---:|
| `--color-surface` (strona) | **5,31** | **7,80** |
| `--color-surface-raised` (karta, stopka) | **5,72** | **6,92** |
| `--color-surface-sunken` (ramka, pole) | **4,83** | **8,49** |
| `--color-surface-brand-wash` (ciepły pas) | **4,83** | **7,03** |
| `--color-brand-tint` (podkład marki) | **4,64** | **6,55** |
| tło W KOLORZE marki (przycisk podstawowy) | **1,00** | **1,00** |

Wszystkie pary poza ostatnią przechodzą. **Najciaśniej jest w motywie jasnym
na `brand-tint`: 4,64 przy progu 4,50 — zapas 0,14.** Rozjaśnienie
`--color-brand` choćby o jeden krok ten zapas zabiera, i dlatego liczby nie
stoją tylko tutaj, ale też w teście, który obleje w tej samej sekundzie.

Ostatni wiersz jest **jedyną granicą, której nie da się przesunąć odcieniem**
i dlatego jest wyjątkiem nr 5 wyżej.

**Kolor nie jest jedynym nośnikiem znaczenia** (WCAG 1.4.1): słowo czyta się
identycznie bez koloru, bo grę niosą wersaliki. Nigdzie nie robimy z koloru
instrukcji — pilnuje tego osobny, starszy test
(`test_instrukcja_nie_wskazuje_elementu_kolorem`).

---

## 3. Gdzie marka mówi głośno, a gdzie milczy

Ta tabela jest **wzięta z audytu** (`COPY_STYLE_V2.md` §2) i jest jego
najlepszą częścią. Bierzemy ją bez zmian, bo mówi rzecz oczywistą, której
nigdzie u nas nie było zapisanej w jednym miejscu.

| Typ ekranu | Charakter marki | Co jest priorytetem |
|---|---|---|
| Strona powitalna, kampania | wysoki | idea produktu |
| `/o-kuking` | średni | historia i wartości |
| Feed, profil | niski | treść użytkownika |
| Onboarding | niski | decyzja i postęp |
| Formularz | bardzo niski | wykonanie zadania |
| Ustawienia | minimalny | skutek działania |
| Błąd | **żartu nie ma** | stan + naprawa |
| Bezpieczeństwo | **żartu nie ma** | precyzja |
| Moderacja, prawo | **żartu nie ma** | fakt + skutek + prawo użytkownika |
| List techniczny | **żartu nie ma** | bezpieczeństwo i działanie |

**Cztery dolne wiersze to zakaz, nie preferencja.** Uzasadnienie jest jedno
i wystarcza: człowiek ma wtedy **problem**, nie ochotę na dowcip. Dokładnie
tak samo brzmi to w `COPY_STYLE.md` §2 („Dawkowanie") i tak samo pilnuje tego
`TekstyWedlugCopyStyleTest`. B2 tego nie rusza.

Ruch po tabeli odbywa się **tylko w dół**: ekran z dolnego wiersza nigdy nie
dostaje charakteru z górnego. Ekran z górnego może być rzeczowy, jeśli tak
wyjdzie lepiej.

**Nazwa serwisu nie jest „charakterem marki" w sensie tej tabeli.** Na ekranie
o niskim charakterze wolno napisać „Świeżo z kuKING", bo to jest nazwa
miejsca, a nie żart dołożony do zadania. Nie wolno napisać „kuKINGuj dalej!",
bo to jest charakter zamiast informacji.

---

## 4. Ile razy: kryterium, nie liczba

Audyt proponuje: **„na ekranie funkcjonalnym maksymalnie jedno zdanie może być
wyraźnie »Kukingowe«"**. Tej reguły NIE przyjmujemy w tym brzmieniu i powód
jest ten sam, który unieważnił „raz na ekran": **liczba nie jest tym, co tu
chroni czytanie.** Ekran z jednym żartem w złym miejscu (w błędzie) jest
gorszy niż ekran z trzema w dobrych.

Obowiązuje kryterium:

> **Charakter marki wolno tam, gdzie nie konkuruje z zadaniem.**
>
> Konkuruje, jeśli: stoi między człowiekiem a przyciskiem, którego szuka;
> wydłuża zdanie, które ma być wykonane, nie przeczytane; opisuje ton
> zamiast podać informację; albo trzeba go zrozumieć, żeby pójść dalej.

Kryterium ma jedną część mierzalną i ona jest pilnowana testem:

> **W jednym akapicie, nagłówku albo punkcie listy nazwa pojawia się raz.**

Nie dlatego, że dwa to „za dużo" — dlatego, że dwa dwukolorowe słowa w polu
jednego spojrzenia **migoczą**, a to jest już koszt czytania, nie charakter.

### Koszt B2, zmierzony

Stan po wdrożeniu, liczony w wyrenderowanym HTML-u (11 września 2026):

| Ekran | Wystąpień na stronę | z tego w stopce | Na 1000 znaków tekstu | Maks. w jednym akapicie |
|---|---:|---:|---:|---:|
| `/` (strona powitalna) | **6** | 2 | 2,6 | **1** |
| `/o-kuking` | **6** | 2 | 2,9 | **1** |
| `/odkryj` | 4 | 2 | 5,4 | **1** |
| `/home` | 4 | 2 | 3,4 | **1** |
| `/szukaj` | 4 | 2 | 4,0 | **1** |
| `/register`, `/pomoc`, `/tagi` | 3 | 2 | 1,4–3,7 | **1** |

Dwa wystąpienia w stopce to **hasło marki** i **licznik społeczności**
(„2 431 kuKINGów") — stopka jest obudową każdej strony, więc liczba „na
stronę" nigdy nie spada poniżej dwóch.

Uczciwe zastrzeżenie do kolumny „na 1000 znaków": licznik znaków bierze też
wersję dla czytnika ekranu, więc każde wystąpienie dokłada do mianownika
około siedmiu znaków, których oko nie widzi. Na stronie powitalnej to 42
znaki z 2336, czyli **1,8%** — gęstość dla osoby czytającej wzrokiem jest
o tyle wyższa, niż mówi tabela. Na wniosek to nie wpływa.

**Wniosek: gęstość nie utrudnia czytania.** Najgęstsza strona ma sześć
wystąpień rozłożonych na pięć–sześć osobnych bloków tekstu; ani jeden akapit
nie ma dwóch. Najwyższa gęstość liczona na tekst wypada na `/odkryj` (5,4 na
1000 znaków) i bierze się z tego, że ta strona jest krótka, a nie z tego, że
nazwa się tam kumuluje.

Gdyby przy kolejnych zmianach któraś liczba w kolumnie „maks. w jednym
akapicie" wyszła 2 — **to nie jest decyzja do podjęcia w locie.** Test oblewa,
a rozstrzyga właściciel.

---

## 5. Jak brzmi zdanie, które zdejmuje presję

To jest pozytywna strona, której w `COPY_STYLE.md` brakowało najbardziej.
Grupa C rozstrzygnięć właściciela (11 września 2026) daje jej kształt.

### Wzór: dwa zdania pod polem komentarza

```text
Choćby jedno zdanie. Pytanie do autora też jest w porządku.
```

Właściciel o drugim zdaniu: *„zostaw tę wartość w zdaniu, to dobry styl"*.
**To jest wzór, nie wyjątek od zakazu.** Dlaczego działa:

- **pierwsze zdanie zdejmuje presję OBJĘTOŚCI** („tyle wystarczy");
- **drugie zdejmuje presję TREŚCI** — komuś, kto nie ma nic mądrego do
  powiedzenia o daniu, a chciałby zapytać o zamiennik mąki;
- **żadne nie mówi, JAK pisać.** Poprzednia wersja zaczynała się od „Napisz
  normalnie, po ludzku" — to jest metajęzyk: instrukcja o tonie. Do tego
  etykieta pola brzmi już „Napisz komentarz", więc to samo słowo stało dwa
  razy pod rząd.

Różnica, którą warto zapamiętać:

```text
❌ Napisz normalnie, po ludzku.        mówi, JAK pisać
✅ Choćby jedno zdanie.                mówi, ILE wystarczy
```

### Siedem reguł z grupy C

**C1 — zapraszaj, nie uspokajaj.** Właściciel: *„mniej uspokajania, a więcej
zachęt, trzeba to robić marketingowo, zbyt restrykcyjny też jesteś (w sensie
to co jest w bazie repo, trzeba to zmienić)"*. Audyt chciał te zdania
**wycinać**; rozstrzygnięcie jest inne — **zamieniamy rejestr z tłumaczącego
się na zapraszający**. To ta sama myśl, co przy `kuKING`: marka nie jest
kosztem do zmniejszenia.

```text
❌ Wrzucasz zdjęcie i kilka słów. Nic więcej nie musisz.
✅ Wrzuć zdjęcie i kilka słów, a pokażesz je komuś, kto dziś też gotował.
```

Uwaga na drugą stronę tego samego kija: **zapraszający to nie sprzedażowy.**
Patrz C3.

**C2 — nie zapewniaj, że po drugiej stronie jest człowiek.** Właściciel:
*„nie ma co naciskać że to człowiek, bo wtedy ludzie będą mieć odwrotne
odczucie"*.

To jest spostrzeżenie ważniejsze niż zarzut o powtórzenia, od którego
zaczynał audyt. **Zapewnianie czterokrotnie, że nie rozmawia się z automatem,
brzmi jak zaprzeczanie zarzutowi, którego nikt nie postawił** — i uruchamia
dokładnie to podejrzenie, które miało uśpić. Ten sam mechanizm co w zdaniu
„to naprawdę nie jest oszustwo". Raz powiedziane jest ciepłe; cztery razy jest
tłumaczeniem się.

Zostaje **jedno** miejsce — to, w którym niesie konkret: jedna osoba, brak
całodobowego dyżuru, odpowiedź czasem po weekendzie.

**C3 — instrukcja zamiast stylu autora.** Właściciel o tonie sprzedażowym:
*„zbyt ejajowe"*.

```text
❌ Wyślemy Ci wiadomość z jednym przyciskiem. Klikasz — i jesteś w środku.
✅ Wyślemy Ci wiadomość z jednym przyciskiem. Kliknij go, żeby wejść na konto.
```

To jest też powód odrzucenia „to darmowe" z przycisku na stronie powitalnej
(§6): sprzedaż wychodzi z tekstu szybciej, niż się ją tam wkłada.

**C4 — nie tłumacz, czym ten krok NIE jest.** Właściciel: *„zluzuj to, bo to
jest zbyt poważne zdanie"*. Kierunek jest ten sam co przy B4: zdejmij presję,
nie opowiadaj o intencji projektowej. Jeśli krok jest opcjonalny, **postaw
„Pomiń"** — przycisk powie to lepiej niż zdanie o przycisku.

```text
❌ …to pomoc w odnalezieniu kogoś, kogo już znasz, a nie kolejny obowiązkowy krok.
✅ Wpisz imię albo nazwę użytkownika, żeby ją tu znaleźć.     [ Pomiń ten krok ]

❌ …albo najpierw się rozejrzeć. Jedno i drugie jest w porządku.
✅ …albo najpierw się rozejrzeć.        (pod spodem dwa równorzędne przyciski)
```

Konstrukcja „jedno i drugie jest w porządku" / „to też jest w porządku" stała
w serwisie **trzy razy** (`/pomoc`, `/dodaj`, koniec onboardingu). Po
ujednoliceniu zostaje w **jednym** miejscu — tym z B4, wskazanym przez
właściciela jako wzór.

**C5 — mniej szczegółu, więcej luzu.** Właściciel: *„jeszcze mniej
szczegółowo bym dał, bardziej na luzie"*.

```text
❌ …po kolei, od najnowszego. Bez żadnego układania przez komputer.
✅ …po kolei, od najnowszego.

❌ Bez rankingów, bez wyścigu, bez liczników w twarz. Bez algorytmu, który układa Ci stronę główną.
✅ Bez rankingów i bez algorytmu, który układałby Ci stronę główną.
```

Antytechnologiczny wtręt („bez żadnego układania przez komputer") tłumaczy
technologię komuś, kto o nią nie pytał, i przy okazji sugeruje, że gdzieś
indziej jest wróg.

**C6 — pisz o realnym życiu tej grupy, nie o abstrakcji.** Audyt proponował
zamienić „u wnuka, w bibliotece albo u znajomych" na „na wspólnym albo cudzym
urządzeniu". Właściciel to **odrzucił** i jego brzmienie jest lepsze:

```text
❌ na wspólnym albo cudzym urządzeniu     abstrakcja, brzmi podejrzliwie
✅ u rodziny czy znajomych                realny scenariusz tej grupy
```

Uzasadnienie warte zapisania: **„cudze urządzenie" to język regulaminu.**
Rodzina jest głównym przewodnikiem po technologii w tej grupie
(`../research/AUDIENCE_50_PLUS.md` §3 i §6 punkt 6) — nazwanie tego po imieniu
nie jest protekcjonalne. Protekcjonalne jest pisanie *o* starszej osobie
zamiast *do* niej.

**C7 — „nic nie" zostaje, jeśli niesie informację.** Audyt policzył
**36 wystąpień w 27 widokach ze 137** — to jest maniera, nie przypadek.
Ale kasowanie hurtem jest błędem w drugą stronę:

| Zostaje | Idzie |
|---|---|
| „nic nie zginie" przy autozapisie — mówi, co robi mechanizm | „Nic nie musisz robić dalej" — nie mówi nic |
| „jeśli nic nie zaznaczysz" — opisuje skutek wyboru | „nic nie zostało zamknięte na stałe" obok zdania, które to już powiedziało |
| „nigdy nic nie napiszemy na Twojej tablicy" — konkretne zobowiązanie | „albo nic nie pisz, to też jest w porządku" — trzecia kopia tej samej konstrukcji |

---

## 6. Zobowiązanie wolno napisać na przycisku, chwyt — nie

**Decyzja właściciela B1.** Przycisk na stronie powitalnej brzmi:

```text
Zostań kuKINGiem — bez opłat i bez reklam
```

Trzy odrzucone warianty i powody, bo one są tu treścią:

| Odrzucone | Dlaczego |
|---|---|
| „Zostań kuKINGiem — to darmowe" | brzmi sprzedażowo (C3, „zbyt ejajowe") |
| „Zostań kuKINGiem — za darmo, na zawsze" | obietnica na przyszłość bez gwarancji |
| „Załóż darmowe konto" (propozycja audytu) | zdejmuje nazwę mieszkańca z jedynego miejsca, w którym się ona zaprasza (§1) |

**„Bez reklam" jest zobowiązaniem, nie chwytem** — i dlatego wolno je tam
napisać. Ma pokrycie w decyzji właściciela („w przyszłości jak bd chciał
zarabiać to bardziej założę patreon albo coś żeby zbiórki robić na hosting")
i niezależnie w `../research/MONETYZACJA.md`: §2.2 pokazuje, że reklama
display żyje ze skali odsłon, której Kuking nie ma (i że Garnek.pl zamknął
się właśnie dlatego, że przychody reklamowe przestały pokrywać koszty),
a §6 rekomenduje „nic poza opcjonalnym linkiem do dobrowolnego wsparcia
kosztów hostingu".

Trzecie źródło jest po stronie odbiorcy i jest w tym samym badaniu:
**„strach o pieniądze i oszustwa" to dominująca obawa tej grupy**, a zalecenie
brzmi wprost — „w MVP nic nie kosztuje i produkt to mówi wprost"
(`../research/AUDIENCE_50_PLUS.md` §3). Napis na przycisku nie jest więc
ozdobą: odpowiada na pierwsze pytanie, które ta osoba sobie zadaje.

**Granica obietnicy — bez niej zdanie zostanie odczytane za wąsko albo za
szeroko:**

- „bez opłat" znaczy **„za korzystanie z Kuking nikt nigdy nie płaci"**,
  a nie „Kuking nigdy niczego nie sprzeda";
- **zgodne** z obietnicą: dobrowolna zbiórka albo Patreon na koszty hostingu
  (nikomu nic nie odbiera) oraz wydrukowana książka rodzinna
  (`../product/SOUL.md` §4.3) — to jest produkt, nie opłata za wejście;
- **zakazane na stałe**: reklamy, płatny dostęp do cudzych przepisów,
  funkcje odbierane za brak subskrypcji.

Reguła ogólna, która z tego wynika i obowiązuje każdy następny przycisk:

> **Na przycisku wolno napisać zobowiązanie, którego złamanie byłoby
> widoczne. Nie wolno napisać zalety, której nikt nie umie sprawdzić.**

---

## 7. Co z audytu bierzemy, a co odrzucamy

### Bierzemy

| Co | Gdzie w tym dokumencie |
|---|---|
| hierarchia tonu (żartu nie ma w błędzie, bezpieczeństwie, moderacji, prawie, liście technicznym) | §3, bez zmian |
| „nie obiecuj bez gwarancji" — copy jest kontraktem z produktem | §6, plus istniejąca lista kontrolna `COPY_STYLE.md` §7 |
| „nie tłumacz intencji UX" | §5, C4 |
| „bez metajęzyka »po ludzku«" | §5, B4 |
| „brak odniesień do koloru i pozycji" | wdrożone wcześniej, PR #325; pilnuje test |
| „nie oceniaj danych, podaj regułę" w walidacji | `COPY_STYLE.md` §6, wzór błędu |
| lista słów alarmowych | §8, ale jako pomoc przy przeglądzie |
| **„Nie musi być ładne" stoi w pięciu miejscach** | punkt 4 niżej — to była prawda i została naprawiona |

### Odrzucamy

**1. Racjonowanie `kuKING` („maksymalnie jedna linia charakteru na ekran").**
Odrzucone, bo liczy złą rzecz. `kuKING` jest nazwą mieszkańca, a nie żartem
(§1); to, co trzeba chronić, to niekonkurowanie z zadaniem, i to jest
kryterium, nie limit (§4). W miejsce liczby wchodzi jedna mierzalna reguła:
raz na akapit.

**2. „Ograniczyć `kuKING` w CTA".** Odrzucone, i to jest odrzucenie
najważniejsze. `Zostań kuKINGiem` jest JEDNYM miejscem, w którym serwis nazywa
kogoś, kim ten ktoś ma się stać. Wyjęcie nazwy z CTA zostawia „Załóż darmowe
konto" — zdanie, które mógłby napisać każdy serwis na świecie. Argument audytu
(„dwie informacje naraz w funkcjonalnym CTA") jest realny i został
zaadresowany inaczej: druga informacja jest teraz **zobowiązaniem**, nie
zaletą (§6), a napis jest jednym elementem, więc nie rozpada się na telefonie
(D-131).

**3. Złagodzenie „Przepis jest dobry wtedy, kiedy ktoś go ugotował".**
Odrzucone. To zdanie niesie **całą tezę produktu** — „Ugotowałem" jest
ważniejsze niż lajk (`AGENTS.md` §1, D-004). Zarzut audytu („logicznie zbyt
absolutne") dotyczy hasła, a od hasła absolutność się oczekuje. Grupa D
potwierdzona przez właściciela: tego zdania, „Pokaż, co dziś ugotowałeś"
i „Ugotowałem" **nie rusza nikt**.

**4. Usunięcie „Nie musi być ładne — ma być prawdziwe".** Odrzucone, bo to
zdanie zdejmuje lęk, **który jest zmierzony**:

> Własne zdjęcie lub film zamieściło w ostatnim miesiącu **17% internautów
> 55–64 i 13% z 65+** — przy **70% i 61%** korzystających z komunikatorów
> (`../research/AUDIENCE_50_PLUS.md` §1).

Ci ludzie zdjęcia **wysyłają**, tylko ich nie **publikują**. Zamiana
„wysyłam mamie" na „wstawiam" jest jedynym nawykiem, który ten produkt ma
realnie zmienić — a to zdanie jest narzędziem tej zamiany.

**Audyt ma jednak rację, że stało w pięciu miejscach.** Zdanie powtórzone
pięć razy przestaje zdejmować lęk i zaczyna go sugerować. Ograniczone do
**dwóch**:

| Zostaje | Dlaczego to |
|---|---|
| pole publikacji na `/home` (`composer-help`) | moment, w którym lęk jest realny: zdjęcie jest już wybrane, palec nad „Opublikuj" |
| pusty stan „Świeżo z kuKING" (`/odkryj`) | pierwszy ekran, na którym ktoś widzi, że nic tu jeszcze nie ma — i zaraz zdecyduje, czy to on ma być pierwszy |

| Zdjęte | Dlaczego |
|---|---|
| pusty feed na `/home` | ten sam człowiek widzi to zdanie dwa razy na jednym ekranie razem z polem publikacji |
| powiadomienie powitalne | powiadomienie ma powiedzieć, co zrobić, nie uspokajać |

Osobne zdanie „Zdjęcie nie musi być ładne" przy formularzu „Ugotowałem"
**zostaje** — mówi o innym zdjęciu, w innym momencie, i nie jest kopią tego.

**5. 404: „To nie jest Twoja wina i nic się nie zepsuło" → sam fakt.**
**Odrzucone — ekran 404 zostaje jak jest (decyzja właściciela B3).** Powód:
`AGENTS.md` §5 wymaga, żeby błąd mówił, **co zrobić**, a skrócenie do samego
faktu ten wymóg podkopuje. Do tego zdanie robi tu konkretną robotę u osoby,
która z założenia podejrzewa, że zepsuła coś sama — a to nie jest domysł:
**„lęk przed »zepsuciem« — jedno kliknięcie zepsuje coś na zawsze"** stoi
w `../research/AUDIENCE_50_PLUS.md` §3 jako jedna z głównych barier wejścia
tej grupy, z objawem „porzucenie formularza, brak pierwszego wpisu".

**6. `COPY_STYLE_V2.md` jako dokument wiążący.** Odrzucone (decyzja
właściciela B5). Części twarde są rozdzielone po tym dokumencie z
uzasadnieniami; części, które markę spłaszczają, nie wchodzą wcale. Dokument
audytu zostaje w `../research/audyt-copy-2026-09-11/` jako **materiał**, nie
jako źródło zasad.

---

## 8. Słowa alarmowe — pomoc przy przeglądzie, nie zakaz

Lista z audytu (`COPY_STYLE_V2.md` §15). **Bierzemy ją i mówimy wprost, czym
jest: sygnałem, a nie zakazem.**

```text
naprawdę · po ludzku · spokojnie · po prostu · nic nie · zawsze · minutę
od razu · najważniejszy · najmilszy · normalnie · człowiek · kuKING · w porządku
```

> **Każde wystąpienie wymaga świadomej decyzji, a nie usunięcia.**
> Słowo z tej listy znaczy „sprawdź, czy to zdanie niesie informację, czy
> tylko grę tonem". Odpowiedź „niesie" jest pełnoprawna i zamyka sprawę.

Trzy przykłady z tego repozytorium, żeby lista nie zamieniła się w skrypt:

- **„nic nie zginie"** przy autozapisie szkicu — **zostaje**. Opisuje
  mechanizm, który istnieje;
- **„w porządku"** w „Pytanie do autora też jest w porządku" — **zostaje**,
  i jest wzorem (§5). To samo słowo w „Jedno i drugie jest w porządku" —
  **wypadło**, bo tam nie niosło nic;
- **„kuKING"** na tej liście — to jest dokładnie ten wpis, przez który audyt
  doszedł do racjonowania nazwy. Zostaje jako przypomnienie, że nazwa ma swoje
  miejsca (§3), a nie jako powód do jej ograniczania.

Automatycznego skanu z tej listy **nie stawiamy**. Zostałby wyłączony
pierwszego dnia, bo połowa trafień jest poprawna — a test, który się wycisza,
jest gorszy niż brak testu (`../PULAPKI_TESTOW.md`).

---

## 9. Czego ten dokument NIE rozstrzyga

1. **Czy `kuKING` bawi, czy męczy realnych ludzi.** Cały §1 i §4 to
   rozumowanie zza biurka. Rozstrzygają testy z osobami 50+ (**issue #15**),
   a nie ten plik. Pytanie do zadania jest w D-013: *„o czym jest ta sekcja?"*
2. **Czy `kuKINGi` w nazwie tablicy nie czyta się deprecjatywnie.** Forma
   `-ingi` dla rzeczownika OSOBOWEGO jest w polszczyźnie ta sama, która daje
   „profesory". Warunek i gotowe alternatywy stoją w **D-013**; ten dokument
   ich nie zmienia.
3. **Brzmienia `AGENTS.md` §11 i `COPY_STYLE.md` §2/§6/§8.** B2 i B1 są
   z nimi sprzeczne w literze. Dokładne brzmienia do podmiany są w raporcie
   z wdrożenia — **pliki właściciela zmienia właściciel.**
4. **Numerów decyzji.** Wpisy do `../DECISIONS.md` są zaproponowane
   w raporcie jako `D-???`; nadanie numeru i wpisanie należy do właściciela.
5. **Czy czasownik `kuKINGować` wejdzie do produktu.** §1 mówi tylko, że
   wolno go użyć i gdzie nie wolno. Żadne miejsce w interfejsie go dziś nie
   ma i ten dokument żadnego nie zamawia.
6. **Reszty tekstów zapewniających, że odpisuje człowiek.** Mechanizm z C2
   („naciskanie, że to człowiek, daje odwrotne odczucie") dotyczy ich
   wszystkich, ale samo „odpisuje człowiek" stoi jeszcze w **16 miejscach**
   — w tym w trzech szablonach listów i w napisach składanych w PHP — a cała
   rodzina tych zwrotów w **25**. Wdrożenie z 11 września zdjęło trzy z nich
   na `/napisz-do-nas` i tam się zatrzymało. Reszta to osobne przejście,
   nie dopisek tutaj.
7. **Tonu w panelu gospodarza.** Panel jest narzędziem pracy i powierzchnią
   moderacji, więc bierze dolny wiersz tabeli z §3 — ale osobnego przeglądu
   tekstów panelu nikt jeszcze nie zrobił.

---

## Referencje

[`COPY_STYLE.md`](COPY_STYLE.md) (jak napisać zdanie · gotowe teksty) ·
[`BRAND_EXTENDED.md`](BRAND_EXTENDED.md) (słownik i słowa zakazane) ·
[`MASCOT_CONCEPT.md`](MASCOT_CONCEPT.md) (zakaz komplementowania koroną) ·
[`../DECISIONS.md`](../DECISIONS.md) (D-009 dawka · D-013 `kuKINGi` ·
D-015 trzy zapisy · D-131 napis przycisku) ·
[`../product/SOUL.md`](../product/SOUL.md) ·
[`../research/AUDIENCE_50_PLUS.md`](../research/AUDIENCE_50_PLUS.md) ·
[`../research/MONETYZACJA.md`](../research/MONETYZACJA.md) ·
`../research/audyt-copy-2026-09-11/` (materiał audytu, nie źródło zasad) ·
`tests/Feature/TekstyWedlugCopyStyleTest.php` (co z tego jest pilnowane maszynowo)
