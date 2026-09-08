# Decyzje — co zostało rozstrzygnięte i dlaczego

Ten plik odpowiada na cztery pytania z `05-szablon-wyniku/tokens.json`
(`do_rozstrzygniecia`) i dokłada sześć rozstrzygnięć, bez których nie da się
narysować ani jednego ekranu. Każde ma numer, żeby dało się je cytować
w przeglądzie kodu.

Zasada, według której są podjęte: **decyzja jest tania, cofnięcie decyzji jest
tanie, brak decyzji jest drogi.** Gdzie miałem wątpliwość, wybrałem wariant,
który da się odkręcić jedną linijką w `tokens.css` — i napisałem, którą.

---

## D-101 · `--leading-title` zostaje 1.25, ale tytuł, który się zawija, dostaje 1.3

**Pytanie z paczki:** czy `--leading-title` zostaje 1.4 (dziś), czy wraca do 1.25
z kitu — dotyka każdego ekranu.

**Rozstrzygnięcie:** zostaje **1.25**, tak jak dziś w `03-kod-wygladu/tokens.css`
(komentarz w tamtym pliku mówi, że 1.4 już było i zostało cofnięte, bo nagłówki
były luźniejsze niż w wizualizacjach — nie wracam do wariantu, który właściciel
już raz odrzucił). Dokładam **nowy token `--leading-title-wiele: 1.3`**.

**Dlaczego dwa, a nie jeden.** 1.25 jest dobre dla tytułu, który mieści się
w jednym wierszu — a taki jest tytuł strony i tytuł sekcji. Tytuł karty wpisu
przy 320 px i skali 140% ma trzy wiersze, i przy 1.25 skleja się w prostokąt
tekstu. 1.3 to jest różnica dwóch pikseli na wiersz i widać ją tylko tam, gdzie
ma być widziana.

**Gdzie działa:** `.karta-tytul`, `.zdjecie-napis`, `.karta-wpisu--zwarta .karta-tytul`.
**Jak cofnąć:** ustawić `--leading-title-wiele: var(--leading-title)`.

---

## D-102 · Kolumna treści zostaje 45rem. Szeroki ekran dostaje trzecią kolumnę — zawsze, także pustą

**Pytanie z paczki:** czy sufit kolumny treści zostaje 45rem, czy szeroki ekran
dostaje trzecią kolumnę.

**Rozstrzygnięcie:** **jedno i drugie.** 45rem zostaje jako szerokość czytania
(720 px ≈ 65–75 znaków przy 18–20 px — to nie jest liczba do negocjacji, tylko
próg czytelności). Trzecia kolumna pojawia się od 80rem i **istnieje na każdym
ekranie zalogowanego, nawet gdy nie ma czym jej wypełnić.**

**Dlaczego pusta kolumna, a nie zwężenie strony.** Bo inaczej strona ma trzy
różne szerokości zależnie od podstrony — zmierzone przy oknie 1512 px: 1424 px
z szyną, 1040 px bez, 768 px u gościa. Nawigacja boczna przeskakiwała między
podstronami. Jedna szerokość znaczy: logotyp zawsze nad tym samym miejscem,
kolumna czytania zawsze ten sam x, stopka zaczyna się tam, gdzie treść.

**A puste miejsce po prawej?** Na ekranie przepisu wypełnia je **panel przepisu**
(składniki i akcje, przyklejony przy przewijaniu) — treść, której ktoś przy
garnku naprawdę potrzebuje obok kroków, a nie nad nimi. Na formularzu dodawania
wypełnia je **pomoc do bieżącego kroku**. Na ekranach, na których nie ma czego
tam włożyć, zostaje pusta — i to jest w porządku: pusty margines po prawej
wygląda spokojnie, a przeskakująca nawigacja wygląda na usterkę.

**Dodatkowo:** długi dokument prawny (polityka, regulamin) czyta się w
`--container-czytanie: 38rem`, nie 45rem. 720 px wiersza czystego tekstu bez
zdjęć to za dużo; przy kartach ze zdjęciem 720 px jest dobre, bo zdjęcie łamie
wiersz co kilka akapitów.

---

## D-103 · Plakietka „Konto przykładowe" schodzi do wagi cichej

**Pytanie z paczki:** czy plakietka zostaje tak głośna — powtarza się
kilkanaście razy w strumieniu.

**Rozstrzygnięcie:** **nie.** Dostaje wagę cichą: `.badge .badge-cichy` — bez tła,
bez ramki, 15 px, `ink-muted`, w wierszu metadanych obok daty, po kropce
oddzielającej. Treść zostaje ta sama: „konto przykładowe".

**Dlaczego to nie jest osłabienie ostrzeżenia.** Ostrzeżenie działa, kiedy
odróżnia się od otoczenia. Powtórzone piętnaście razy pod rząd w kolorze marki
przestaje cokolwiek znaczyć i staje się teksturą strony — a przy okazji zjada
cały budżet uwagi, którym miały dysponować zdjęcia potraw. Cicha plakietka przy
dacie jest **czytelna dokładnie wtedy, gdy ktoś patrzy na autora**, czyli wtedy,
gdy pytanie „czy to prawdziwa osoba?" w ogóle pada.

**Reguła ogólna, którą stąd wyprowadzam i która obowiązuje w całym systemie:**

> Plakietka głośna (z tłem akcentu) wolno użyć **raz na ekran**. Jeśli coś
> powtarza się w każdym elemencie listy, to z definicji jest ciche.

**Gdzie zostaje głośna:** na profilu konta przykładowego, raz, u góry — tam jest
jedna i mówi to, co ma powiedzieć.

---

## D-104 · Karta wpisu dostaje wariant zwarty. Dotyka czterech ekranów

**Pytanie z paczki:** czy karta wpisu dostaje wariant kompaktowy (potrzebny
w archiwum profilu i w zeszycie).

**Rozstrzygnięcie:** **tak**, jako `.karta-wpisu--zwarta`, i mówię wprost, ile
ekranów rusza — bo poprzednia próba stanęła dokładnie na tym, że tego nie
policzyła:

| ekran | co się zmienia |
|---|---|
| 05 profil (archiwum) | siatka dwukolumnowa od 48rem zamiast strumienia |
| 06 strona tagu | jw. |
| 09 szukaj (wyniki) | jw. |
| 10 zeszyt | jw. |

**Czym wariant zwarty NIE jest:** miniaturą. Zdjęcie zostaje duże — zmienia się
tylko proporcja z 4:3 na 16:9, żeby w wiersz weszły dwie karty. Znika treść
wpisu i pasek akcji, zostaje tytuł i jedna linia metadanych. Maksimum **dwie
kolumny**, nigdy cztery: przy czterech zdjęcie robi się ikonką, a wtedy przestaje
być dowodem, że przepis komuś wyszedł, i zaczyna być dekoracją.

**Koszt wdrożenia:** jeden wariant `x-post-card.blade` z parametrem `zwarta`,
plus po jednej klasie na kontener w czterech widokach. Bez zmiany modelu danych.

---

## D-105 · Ten projekt stoi na zdjęciach. Mówię to wprost i projektuję brak zdjęcia

Paczka pyta o to wprost: *„Jeśli nowy projekt ma stać na zdjęciach, to trzeba to
powiedzieć wprost, bo dziś serwis nie ma ani jednego."*

**Odpowiedź: tak, stoi.** Zdjęcie potrawy jest w tym serwisie treścią, a nie
ilustracją. Karta bez zdjęcia to karta bez powodu, żeby ją zobaczyć.

Z tego wynikają trzy rzeczy, które są **warunkiem** działania tego projektu, a nie
jego ozdobą:

1. **Zdjęcie zajmuje pełną szerokość karty** w proporcji 4:3 i nie jest
   przycinane do paska. Na 720 px kolumny to 720×540 — tyle, żeby było widać,
   co jest na talerzu.
2. **Przed betą trzeba zasiać dane demonstracyjne prawdziwymi zdjęciami.** Dziś
   każde „zdjęcie" w bazie demo to znak marki na beżowym tle, więc każda ocena
   strumienia jest oceną czegoś innego niż to, co zobaczy człowiek. To jest
   zadanie do zrobienia, nie uwaga na marginesie.
3. **Wpis bez zdjęcia nie może wyglądać na uszkodzony.** Dostaje własny wygląd:
   `.karta-bez-zdjecia` — ciepłe pole `surface-brand-wash`, tekst wpisu
   powiększony do 22 px. Wygląda na wpis pisany, nie na zdjęcie, którego nie
   udało się wczytać. Serwis, w którym połowa ludzi pisze bez zdjęcia, ma dzięki
   temu drugi porządny kształt karty, a nie dziurę.

---

## D-106 · Oznaczamy pola **wymagane**, nie „(nieobowiązkowe)"

W formularzu przepisu pól nieobowiązkowych jest kilkanaście, a wymaganych trzy.
Oznaczanie większości robi szarą, migającą kolumnę po prawej stronie etykiet
(problem nr 9 z `CZYTAJ-MNIE.md`) i przy okazji sugeruje, że reszta jest
obowiązkiem.

Nad formularzem stoi jedno zdanie: **„Wymagane są trzy pola — resztę wypełnij,
jeśli chcesz."** Przy tych trzech stoi słowo `wymagane` w `ink-muted`, 16 px.
Nigdzie nie ma gwiazdki: gwiazdka jest umową, której nasz odbiorca nie
podpisywał, a czytnik ekranu czyta ją jako „gwiazdka".

---

## D-107 · Wybór pliku przez `<label for>`, nigdy natywny przycisk

Natywny `<input type="file">` rysuje przycisk „Choose File / No file chosen" —
po angielsku, i **żaden atrybut tego nie zmienia**. W serwisie, w którym każde
słowo jest po polsku, a odbiorca po angielsku nie mówi, to jest usterka, nie
detal (problem nr 7).

Rozwiązanie: input schowany dla oka, ale obecny w drzewie (klawiatura i czytnik
ekranu widzą go normalnie), a klikalny jest `<label for>` w postaci dużego
obszaru „Dodaj zdjęcie". Kliknięcie etykiety wyzwala input — to natywne
zachowanie HTML, **działa bez JavaScriptu i bez atrybutu `style=`**.

Fokus na schowanym inpucie rysuje obwódkę na etykiecie (`:focus-visible + .wybor-zdjecia`),
więc osoba na klawiaturze widzi, gdzie jest.

Teksty: **„Dodaj zdjęcie"** + pod spodem **„Na telefonie kliknij tutaj, a potem
wybierz »Galeria« albo »Zrób zdjęcie«."** (`COPY_STYLE.md` §6).

---

## D-108 · Formularz przepisu to trzy osobne strony, nie jedna z nagłówkiem „Krok 1 z 3"

Dziś formularz ma na telefonie ponad cztery tysiące pikseli wysokości i pokazuje
wszystko naraz, mimo że nagłówek mówi „Krok 1 z 3" (problem nr 10). Nagłówek
kłamie, a człowiek nie wie, gdzie kończy się krok pierwszy.

**Rozstrzygnięcie:** trzy adresy, trzy strony, trzy zapisy szkicu na serwerze:

```
/dodaj/przepis            → krok 1: co to jest        (nazwa, zdjęcie, opis, skąd ten przepis)
/dodaj/przepis/skladniki  → krok 2: składniki
/dodaj/przepis/kroki      → krok 3: kroki i publikacja
```

Każdy krok kończy się `POST`-em, który zapisuje szkic i przenosi dalej. Przycisk
**Wstecz** jest zwykłym linkiem. **Zapisz szkic** to `POST` wracający na ten sam
krok z plakietką „Szkic zapisany.". Zero JavaScriptu, zero paneli rozwijanych.

**Uwaga o wymaganiu z paczki**, że formularz „w wersji jedna strona" istnieje po
to, żeby działał bez JavaScriptu: on dalej działa bez JavaScriptu — trzy strony
to jest *bardziej* bezskryptowe niż jedna, nie mniej. Wersja jednostronicowa
**zostaje pod adresem `/dodaj/przepis/wszystko`** jako droga awaryjna i dla osób,
które wolą widzieć całość naraz; link do niej stoi pod krokiem 1 („Wolisz
wypełnić wszystko na jednej stronie?").

---

## D-109 · Pole ma szerokość swojej treści

Dziś pola tekstowe są jednakowymi beżowymi prostokątami tej samej wysokości,
niezależnie od tego, czy wpisuje się do nich rok, czy historię przepisu
(problem nr 11). Prostokąt na 40 znaków pod pytaniem „Ile porcji?" mówi
człowiekowi, że oczekujemy zdania.

Cztery szerokości, wszystkie w `ch` (jednostka liczona od szerokości cyfry):

| klasa | szerokość | do czego |
|---|---|---|
| `.field-input-rok` | 8ch | rok, godzina |
| `.field-input-liczba` | 10ch | porcje, minuty, ilość |
| `.field-input-krotkie` | 22ch | nazwa składnika, „po kim ten przepis" |
| `.field-input-srednie` | 40ch | nazwa przepisu, tytuł |
| (bez klasy) | 100% | akapit, adres, opis |

`textarea` ma dwie wysokości: 7rem zwykła, 12rem `.field-input-dlugie` dla
historii przepisu — bo to jest pole, w którym naprawdę chcemy dużo tekstu i
wysokość pola jest zaproszeniem.

---

## D-110 · Jeden akcent na powierzchnię

To jest reguła, z której wynika większość wyglądu, więc stoi tu jako decyzja,
a nie jako uwaga estetyczna.

> **Na jednej karcie dokładnie jedna rzecz ma kolor marki. Na jednym ekranie
> dokładnie jedna akcja jest przyciskiem głównym.**

Dziś „Napisz komentarz" i „Zapisz" wyglądają identycznie, choć jedno jest akcją
główną, a drugie pomocniczą (problem nr 3), a plakietka konta przykładowego jest
najgłośniejszym elementem strony (problem nr 2). Efekt: wszystko, co pomarańczowe,
krzyczy jednakowo, więc nic nie krzyczy.

Hierarchia buduje się **rozmiarem i wagą, nie kolorem**:

```
tytuł karty        24 px / 800   ← tu siada oko
treść wpisu        20 px / 400
nazwa autora       18 px / 700
metadane, plakietki 15 px / 600 w ink-muted
```

Kolor marki zostaje dla: przycisku głównego, bieżącej pozycji nawigacji, kółka
„Dodaj", linków w tekście ciągłym. Nigdzie indziej.

---

## D-111 · Skala tekstu 90–140%, a układ trzymamy do 150%

Dokumentacja mówi o 150%, baza dziś pozwala na 90–140%. Tokeny mają zdefiniowane
**oba** progi (`data-text-scale="140"` i `="150"`), więc podniesienie limitu
w bazie nie wymaga dotknięcia arkusza. Każdy szablon w tej paczce jest sprawdzony
przy 140% i przy 150% na 320 px — bez ucinania i bez przewijania w poziomie.

`90%` istnieje dla osób, którym 18 px jest za duże na małym telefonie. Schodzi do
16.2 px, czyli nigdy poniżej progu, który dla tekstu podstawowego jest
powszechnie przyjęty.

---

## D-112 · Grupa wyboru pyta o miejsce, które ma, a nie o szerokość okna

Wyszło przy składaniu galerii, a nie przy projektowaniu, i dlatego stoi tu jako
osobna decyzja.

`.choice-grid` („Kto to widzi": trzy duże karty) przełączała się na trzy kolumny
**progiem liczonym od szerokości okna**. Komponent bywa w wąskiej kolumnie na
szerokim ekranie — i wtedy próg jest odpowiedzią na złe pytanie: trzy karty
wciskały się w kolumnę, w której mieści się jedna.

```css
.choice-grid { grid-template-columns: repeat(auto-fit, minmax(min(13rem, 100%), 1fr)); }
```

Zapytanie medialne znika w całości. `min(13rem, 100%)` chroni przed
przepełnieniem przy 320 px. To jest zmiana zachowania na czterech ekranach
(dodaj zdjęcie, dodaj przepis, konto, ustawienia), więc nie jest to poprawka
kosmetyczna i dlatego ma numer.

---

## Skąd wzięła się sekcja 16 w `komponenty.css`

Dwadzieścia dwie reguły, których w arkuszu nie było, a które okazały się
potrzebne dopiero przy składaniu prawdziwych ekranów i galerii. Osiem z nich to
**usterki zmierzone automatem na prawdziwej przeglądarce**, nie przewidziane
przy biurku — w tym pięć przypadków przewijania strony w poziomie, każdy
z tą samą przyczyną: element rzędu `flex` ma domyślnie `min-width: auto` i nie
zejdzie poniżej szerokości swojej zawartości, a `overflow-wrap: break-word`
łamie wiersz, ale nie zmienia tej minimalnej szerokości.

Napisane są tam wprost, z pomiarem przy każdej, bo poprzednia próba rozbiła
się między innymi o to, że makiety wymagały zmian w komponentach
współdzielonych, a nikt tego nie policzył ani nie nazwał.

**Sprawdzian, czy to naprawdę jest system:** po dopisaniu sekcji 16 z pięciu
makiet w `03-szablony/` usunięto wszystkie bloki `<style>`. Żadna z nich nie ma
dziś ani jednej własnej reguły CSS, a wszystkie dalej przechodzą oba automaty
(12 stron, 240 pomiarów, zero przewijania w poziomie, zero kontrolek poniżej
48 px).

---

## Czego świadomie NIE rozstrzygam, bo to nie moja decyzja

- **Webfont `Atkinson Hyperlegible`** zamiast „Inter Variable" — do testu z
  ludźmi po becie, jak mówi `DESIGN_SYSTEM.md` §2.2. Zostawiam „Inter Variable"
  z pełnym stosem systemowym w odwodzie.
- **Nazwa „półka" kontra „rozdział"** w Zeszycie — oznaczone `[do weryfikacji]`
  w `BRAND_EXTENDED.md`, czeka na testy z 13 osobami.
- **„kuKINGi na dziś" kontra „Dziś u kuKINGów"** — to samo, `COPY_STYLE.md` §5
  ma gotowe alternatywy. W makietach używam nazwy obowiązującej dziś.
- **Imię gospodarza w e-mailach** — `DECISIONS.md` D-012, czeka na właściciela.
- **Limit kart „Ugotowałem" na ekranie przepisu** — projektuję z „Pokaż więcej"
  po pięciu, ale liczba jest do ustawienia.
