# Jedenaście problemów — co z każdym robi nowy projekt

Właściciel wypisał w `CZYTAJ-MNIE.md` sześć problemów widocznych na komputerze
i pięć na telefonie, i poprosił o jedno zdanie odpowiedzi na każdy. Zdanie
odpowiedzi jest **pogrubione**; pod nim stoi tyle, ile trzeba, żeby dało się to
sprawdzić w kodzie.

Numeracja jest ta sama co w źródle.

---

## Komputer

### 1. Kolumna treści ma sufit 45rem, a okno 1440 px — po bokach zostają szerokie puste marginesy, prawa szyna jest wąska i ciasna

**Kolumna czytania zostaje 45rem, bo to jest próg czytelności, a nie ciasnota —
zmienia się to, że trzecia kolumna istnieje na KAŻDYM ekranie zalogowanego, więc
strona przestaje mieć trzy różne szerokości zależnie od podstrony.**

Decyzja D-102. Szerokość strony to jedna liczba dla belki, siatki i stopki:
1424 px (240 + 720 + 352 plus odstępy). Na ekranach bez szyny trzecia kolumna
zostaje pusta albo dostaje treść, której tam naprawdę brakuje — na ekranie
przepisu przyklejony panel ze składnikami i akcjami. Zmierzone dziś: 1424 px
z szyną, 1040 px bez, 768 px u gościa; po zmianie: jedna liczba.

### 2. Karta wpisu powtarza w każdym z piętnastu wpisów autora, głośną plakietkę, datę i dwa przyciski

**Plakietka „konto przykładowe” schodzi do wagi cichej, karta dostaje tytuł, na
który patrzy oko, a w stopce tylko jedna akcja ma wagę.**

Decyzje D-103 i D-110. Plakietka traci tło i kolor marki, zostaje 15 px
`ink-muted` przy dacie — czytelna dokładnie wtedy, gdy ktoś patrzy na autora.
Reguła ogólna: plakietka głośna wolno raz na ekran; jeśli coś powtarza się
w każdym elemencie listy, to z definicji jest ciche.

### 3. Wszystkie przyciski mają tę samą wagę — „Napisz komentarz” i „Zapisz” wyglądają identycznie

**Na jednej powierzchni dokładnie jedna akcja jest przyciskiem głównym, reszta
jest cicha — a hierarchia buduje się rozmiarem i wagą, nie kolorem.**

Decyzja D-110. W stopce karty „Ugotowałem” jest akcją z wagą, „Komentuj”
i „Zapisuję” są ciche. Na ekranie przepisu przyciskiem głównym jest „Ugotowałem”
i tylko on.

### 4. Prawa szyna powtarza treść z kolumny głównej — sekcja „DANIA” pokazuje te same wpisy, co obok

**Szyna nigdy nie powtarza kolumny głównej: dostaje treść innego rodzaju —
„kuKINGi na dziś” (kilka osób i kilka dań, bez rankingu), przypomnienie o
dodaniu wpisu, pomoc do bieżącego kroku.**

Nazwa, podtytuł i stopka tej sekcji są wzięte dosłownie z `COPY_STYLE.md` §5,
łącznie ze zdaniem „Jutro będzie tu ktoś inny.”, które mówi wprost, że to się
zmienia i nie jest tabelą wyników.

### 5. Typografia jest prawie jednolita — nazwa autora, plakietka, data i treść mają podobny rozmiar i grubość

**Karta dostaje cztery wyraźnie różne poziomy: tytuł 24 px/800, treść 20 px/400,
autor 18 px/700, metadane 15 px/600 w ink-muted — i kolor marki nie bierze
w tym udziału w ogóle.**

Tytuł wpisu jest elementem nowym. Dziś karta go nie ma, więc największym napisem
jest nazwa autora — i strumień wygląda na listę ludzi zamiast na listę dań.

### 6. Pole wyszukiwania w belce jest szersze niż kolumna treści pod nim — dwie różne siatki na jednym ekranie

**Belka używa dokładnie tej samej siatki co treść, więc pole wyszukiwania zaczyna
się i kończy tam, gdzie kolumna pod nim.**

Klasa `.topbar-wnetrze` ma te same `grid-template-columns` i tę samą szerokość
maksymalną co `.app-body`, na wszystkich trzech progach.

---

## Telefon

### 7. Natywny wybór pliku pokazuje „Choose File / No file chosen” — po angielsku

**Natywny przycisk znika: input jest schowany dla oka (ale nie dla klawiatury
i czytnika), a klika się duży obszar „Dodaj zdjęcie” zrobiony z `<label for>` —
to natywne zachowanie HTML, więc działa bez JavaScriptu i bez atrybutu `style=`.**

Decyzja D-107. Tekstu w natywnym przycisku nie da się zmienić żadnym atrybutem,
więc jedynym rozwiązaniem jest go nie pokazywać. Teksty z `COPY_STYLE.md` §6:
„Dodaj zdjęcie” + „Na telefonie kliknij tutaj, a potem wybierz »Galeria« albo
»Zrób zdjęcie«.”

### 8. Etykiety pól zlewają się z podpowiedzią: „WszyscyTakże osoby bez konta”, „Tylko jaTwój prywatny zeszyt”

**Podpowiedź jest osobnym blokiem, nie ciągiem dalszym etykiety — `.choice-label`
i `.choice-help` mają `display: block` i własny odstęp.**

To był brak jednej deklaracji, nie wybór projektowy. Przy okazji trzy opcje
widoczności zostają trzema dużymi kartami, zawsze widocznymi — kit v2 chciał tu
listy rozwijanej i to był jeden z powodów, dla których się nie przyjął.

### 9. „(nieobowiązkowe)” powtarza się kilkanaście razy w jednej kolumnie, tworząc szarą, migającą kolumnę

**Odwracamy oznaczenie: zaznaczamy trzy pola wymagane zamiast kilkunastu
nieobowiązkowych, a nad formularzem stoi jedno zdanie „Wymagane są trzy pola —
resztę wypełnij, jeśli chcesz.”**

Decyzja D-106. Bez gwiazdki: gwiazdka jest umową, której ten odbiorca nie
podpisywał, a czytnik ekranu czyta ją jako „gwiazdka”.

### 10. Formularz przepisu ma na telefonie ponad cztery tysiące pikseli wysokości i pokazuje wszystko naraz, mimo nagłówka „Krok 1 z 3”

**Trzy kroki to trzy osobne adresy i trzy osobne strony, każda kończąca się
zapisem szkicu — nagłówek przestaje kłamać, a strona mieści się na dwóch
ekranach zamiast na dziesięciu.**

Decyzja D-108. Wstecz to zwykły link, Dalej to `POST`, „Zapisz szkic” to `POST`
wracający na ten sam krok. Wersja jednostronicowa zostaje pod
`/dodaj/przepis/wszystko` jako droga awaryjna dla osób, które wolą widzieć całość.

### 11. Pola tekstowe są jednakowymi beżowymi prostokątami tej samej wysokości, niezależnie od tego, czy wpisuje się do nich rok, czy historię przepisu

**Pole ma szerokość swojej treści: cztery klasy szerokości liczone w `ch`
(8/10/22/40 znaków) i dwie wysokości obszaru tekstowego — prostokąt na 40 znaków
pod pytaniem „Ile porcji?” mówił człowiekowi, że oczekujemy zdania.**

Decyzja D-109. Pole „Historia tego przepisu” jest za to wyraźnie wyższe od
pozostałych, bo jego wysokość jest zaproszeniem — a to jest pole, w którym
naprawdę chcemy dużo tekstu.

---

## Rzecz dwunasta, której właściciel nie wypisał jako problemu, a która jest największa

**W danych demonstracyjnych nie ma ani jednego prawdziwego zdjęcia** — każde jest
znakiem marki na beżowym tle. Właściciel sam o tym uprzedza i prosi, żeby to
sobie „odmyśleć”.

Nie da się tego odmyśleć, bo **ten projekt stoi na zdjęciach** (decyzja D-105)
i mówię to wprost, tak jak paczka o to prosi. Karta bez zdjęcia to karta bez
powodu, żeby ją zobaczyć. Wynikają z tego trzy rzeczy, które są warunkiem
działania projektu, a nie jego ozdobą: zdjęcie na pełną szerokość karty w 4:3,
prawdziwe zdjęcia w danych demonstracyjnych przed betą, i osobny, celowy wygląd
wpisu bez zdjęcia — żeby nie wyglądał na uszkodzony, tylko na wpis pisany.
