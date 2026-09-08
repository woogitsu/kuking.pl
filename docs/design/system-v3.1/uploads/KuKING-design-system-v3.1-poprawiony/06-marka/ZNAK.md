# Znak Kuking — jak go używać

Garnek w koronie, z uśmiechem. Korona uzasadnia **KING** w nazwie, uśmiech
zmienia znak z katalogu kuchennego w znak miejsca, w którym są ludzie.

**Jedno zdanie, które trzeba zapamiętać przed resztą:**

> Korona siedzi na garnku, nie na niczyjej głowie. To jest żart o garnku,
> nigdy komplement dla człowieka.

Ten zakaz jest twardy i pochodzi z `MASCOT_CONCEPT.md`, powtarza go
`COPY_STYLE.md` §2. Nie wolno napisać „Jesteś królem kuchni”, „Zostań królem
kuchni”, „Twoja kulinarna korona”. Wolno o tym opowiedzieć w dokładnie jednym
miejscu w produkcie — na stronie „O Kuking”, w sekcji „Skąd ta nazwa” — i na
gadżetach („Każdy jest królem swojej kuchni”, `BRAND_EXTENDED.md` §7, H4).

---

## 1. Trzy zapisy nazwy — najczęściej mylona rzecz w tym projekcie

`COPY_STYLE.md` §2, decyzja D-015. Przepisane tu dosłownie, bo mylone jest
częściej niż cokolwiek innego w tej marce.

| Zapis | Gdzie obowiązuje | Przykład |
|---|---|---|
| **`KuKing.pl`** | **wyłącznie logotyp** — belka górna, znak, materiały marki, favicon | logotyp w belce, `kuking-logo-smile.svg` |
| **`Kuking`** | **tekst ciągły**, nagłówki, komunikaty, tytuł strony, `alt`, e-maile tekstowe | „Świeżo z Kuking”, „Kuking to twój zeszyt z przepisami” |
| **`kuKING`** | **o człowieku, który tu gotuje** | „Zostań kuKINGiem”, „kuKINGi na dziś” |

**Wersalik w środku jest częścią znaku, a nie zasadą ortograficzną.**
W zdaniu piszemy `Kuking` — `KuKing` w środku akapitu wygląda na literówkę,
a `KUKING` wielkimi literami nie występuje nigdzie poza wordmarkiem.

### 1.1 Trzy błędy, które zdarzają się naprawdę

| Źle | Dlaczego | Dobrze |
|---|---|---|
| `KuKing to twój zeszyt z przepisami.` | zapis logotypowy w zdaniu | `Kuking to twój zeszyt z przepisami.` |
| `Zostań Kukingiem` | forma o człowieku bez wersalików — ginie cały żart | `Zostań kuKINGiem` |
| `kuKING jest w zamkniętej becie` | serwis nie jest kuKINGiem; kuKING to człowiek | `Kuking jest w zamkniętej becie` |

### 1.2 Formy odmiany, których używamy — i tych, których nie

**Wolno:** `kuKING` (mianownik), `kuKINGiem` (narzędnik), `kuKINGów`
(dopełniacz mnogi), `kuKINGi` (mianownik mnogi **w znaczeniu rzeczy**, jak
w „kuKINGi na dziś”).

**Nie wolno:** `kuKINGowi`, `kuKINGu`, `kuKINGowie`, `kuKINGce`,
`kuKINGówka`, `kuKINGujesz`. Jeśli zdanie wymaga takiej formy — przepisz
zdanie, nie odmieniaj słowa na siłę.

**Formy żeńskiej nie tworzymy.** Żadna nie brzmi po polsku dobrze, a główną
grupą są kobiety 60+. Zamiast szukać formy, zmieniamy konstrukcję:
zamiast „Jesteś kuKINGiem od 2 lat” piszemy „Gotujesz z nami od 2 lat”.

### 1.3 Gdzie `kuKING` nie pada nigdy

W komunikacie błędu, w wiadomości moderacyjnej, w regulaminie, w polityce
prywatności, w powiadomieniu o cudzej aktywności i w formularzu, który ktoś
właśnie wypełnia. Człowiek ma wtedy problem albo pracę do zrobienia, a nie
nastrój na żarty.

**Najwyżej jedna gra słowem `kuKING` na ekran.** Sprawdza się to na
wyrenderowanej stronie, nie w kodzie źródłowym — tablica ma dwie kopie szyny
i tylko jedna jest widoczna naraz.

### 1.4 Wordmark w kodzie: gdzie kończy się atrament, a zaczyna kolor marki

```html
<a class="wordmark" href="/">
  <svg class="wordmark-znak" aria-hidden="true"><use href="#ikona-znak"></use></svg>
  <span>KuKing<span class="wordmark-koncowka">.pl</span></span>
</a>
```

**`KuKing` jest w kolorze atramentu, kolor marki dostaje wyłącznie `.pl`
i znak.** Tak mówi `BRAND_IDENTITY.md` §2 i tak jest w plikach SVG.

Podświetlenie samego `King` kolorem marki jest **błędem** — nie estetycznym,
tylko znaczeniowym: ogłasza żart, który ma być odkrywany. W tej paczce zdarza
się to dwa razy, oba razy w `02-komponenty/galeria.html`
(`Ku<span class="wordmark-koncowka">King</span>.pl`), przy ośmiu wystąpieniach
formy poprawnej w pozostałych plikach. Do poprawy w galerii.

---

## 2. Kiedy sam garnek, a kiedy garnek z napisem

| Sytuacja | Wariant | Dlaczego |
|---|---|---|
| Belka górna serwisu | **garnek + wordmark** | Belka jest jedynym miejscem, w którym marka się przedstawia. Znak sam nie wystarczy, bo nikt jeszcze nie zna tego garnka |
| Stopka | **garnek + wordmark** albo sam napis `Kuking` | Zależnie od miejsca; stopka i tak niesie pełną nazwę w zdaniu |
| Favicon, ikona aplikacji, awatar w mediach społecznościowych | **sam garnek** | Napis w 32 px jest plamą — patrz sekcja 7 |
| Pusty stan (`.empty-state-znak`, 64 px) | **sam garnek** | Wordmark stoi już w belce nad nim; drugi raz na jednym ekranie to za dużo |
| Znak wodny na zdjęciu | **sam garnek**, wersja biała, na podkładzie | Napis na zdjęciu jest nieczytelny przy każdej wielkości, przy której nie zasłania potrawy |
| Materiały drukowane, prasa, prezentacja | **garnek + wordmark** | Odbiorca widzi markę pierwszy raz |
| Przycisk „Ugotowałem” | **żadnego znaku** | To był pomysł z kitu v2 i został odrzucony (`STAN_WDROZENIA_KITU.md`, etap C): garnek w przycisku w kolorze marki robił się białą plamą. Nowa wersja z wyciętym uśmiechem tego problemu nie ma, ale decyzja o nieużywaniu znaku w przycisku zostaje — przycisk ma tekst i to wystarczy |

**Nigdy dwa razy na jednym ekranie w tym samym rozmiarze.** Znak w belce
i znak w pustym stanie są w porządku, bo różnią się dziesięciokrotnie
wielkością i rolą.

---

## 3. Pole ochronne

> **Pole ochronne = ¼ wysokości znaku, z każdej strony.**

Wysokość znaku liczy się **od czubków pereł korony do dna garnka**. Uchwyty
wystają w bok i nie liczą się do wysokości.

Rysunek: `06-marka/znak/kuking-pole-ochronne.svg`.

**Skąd ta liczba.** `BRAND_IDENTITY.md` §2 mówi „co najmniej 1/2 wysokości
garnka”, co jest niepoliczalne, dopóki nie wiadomo, czy „garnek” znaczy garnek
z koroną, czy bez. Przeliczone na siatce 64 jednostek: pół garnka bez korony to
**12,5**, a ćwierć wysokości całego znaku to **12,1**. Różnica jest mniejsza
niż grubość kreski, więc nowa reguła nie zmienia niczego wizualnie — tylko daje
się sprawdzić linijką.

**W kodzie** nie liczy się tego drugi raz. Znak dostaje `padding: 0.25em`
przy `font-size` równym swojej wysokości, i pole ochronne skaluje się razem
z nim:

```css
.wordmark-znak { width: 2rem; height: 2rem; }   /* 32 px */
.wordmark { gap: var(--spacing-2); }             /* 8 px = ¼ z 32 px */
```

W polu ochronnym nie stoi **nic**: ani tekst, ani ikona, ani krawędź kontenera,
ani inna grafika.

---

## 4. Minimalne rozmiary

| Wariant | Minimum | Co się psuje poniżej |
|---|---|---|
| **Sam garnek** | **32 × 32 px** | Poniżej 24 px wycięty uśmiech ma mniej niż 1 px i przestaje się rysować — zostaje garnek bez uśmiechu, czyli inny znak |
| **Garnek + wordmark, poziomo** | **150 px szerokości** | Poniżej tego litery `.pl` schodzą pod 8 px i zlewają się w kreskę |
| **Znak w druku** | **8 mm wysokości** | Farba wypełnia wycięcie uśmiechu i garnek robi się plamą |

Powyżej minimum znak jest wektorem i skaluje się bez ograniczenia.

**Jedyny wyjątek od reguły 32 px:** favicon 16 × 16, który przeglądarka
skaluje sama z pliku SVG. Dlatego wersja faviconowa ma grubsze wycięcie
(4,5 i 4 zamiast 3,5 i 3) — patrz sekcja 7.

---

## 5. Tła

| Tło | Wariant znaku | Kolor |
|---|---|---|
| Jasne tło serwisu `--color-surface` | garnek w kolorze marki | `--color-brand` `#B3401F` |
| Karta `--color-surface-raised` | jw. | `--color-brand` |
| Ciemny motyw `--color-surface` `#1E1A16` | garnek w jasnej terakocie | `--color-brand` `#F2986A` |
| Tło w kolorze marki (przycisk, pole ikony, awatar w mediach społecznościowych) | garnek biały | `--color-ink-inverse` `#FFFFFF` |
| Zdjęcie | garnek biały, **zawsze na podkładzie** | `--color-ink-inverse` na `--scrim-gradient` |
| Druk jednokolorowy | garnek w jednym kolorze, uśmiech jako dziura | dowolny, byle kontrast wobec podłoża ≥ 3:1 |

**Kolor znaku bierze się z tokenu, nie z pliku.** Znak wstawiony inline
w stronę używa `currentColor`, więc wystarczy ustawić `color` na jego
kontenerze i tryb ciemny działa sam:

```css
.wordmark-znak { color: var(--color-brand); }
```

To jest cała obsługa obu motywów. Nie ma drugiego pliku, nie ma przełącznika,
nie ma reguły `@media`.

**Na zdjęciu — nigdy bez podkładu.** To jest reguła „nigdy” nr 7 z tego
systemu. Znak biały na zdjęciu białego talerza jest niewidzialny, a następne
zdjęcie zawsze będzie inne niż to, na którym się sprawdzało. Podkład
`--scrim-gradient` daje bieli 18.37:1 w motywie jasnym i 21.00:1 w ciemnym.

---

## 6. Czego nie wolno

| Zakaz | Dlaczego |
|---|---|
| **Rozciągać ani ściskać** | Garnek przestaje być garnkiem. Skalowanie tylko proporcjonalne |
| **Obracać** | Garnek stoi na dnie. Przechylony wygląda na wylany |
| **Przebarwiać** | Znak ma dokładnie trzy dopuszczone kolory: `--color-brand` (obu motywów), `--color-ink-inverse` i jeden kolor w druku jednokolorowym. Zielony, niebieski, tęczowy, gradientowy — nie |
| **Dokładać cienia, obrysu, poświaty** | `BRAND_IDENTITY.md` §2 wprost: „nie dodawać gradientu, cienia ani obrysu do logo”. Cień pod znakiem w belce robi z płaskiego znaku naklejkę |
| **Malować uśmiech kolorem** | Uśmiech jest **dziurą**. Malowanie go kolorem tła działa tylko na tle, na którym się to sprawdziło — i psuje się na następnym. To jest dokładnie usterka, którą ma ten dokument zamknąć |
| **Rozdzielać garnka od korony** | Korona uzasadnia nazwę. Sam garnek bez korony to znak jakiegokolwiek serwisu kulinarnego |
| **Używać znaku jako ikony ogólnego przeznaczenia** | Garnek jest znakiem produktu, nie ikoną „gotowania”. Do ikon jest `podglad/ikony-sprite.html` — tam garnek ma osobną, konturową ikonę `ikona-garnek` |
| **Umieszczać w polu ochronnym czegokolwiek** | Patrz sekcja 3 |
| **Pisać `KUKING` wielkimi literami** | Wordmark to `KuKing.pl`. CAPS LOCK jest zakazany poza nim (`BRAND_EXTENDED.md` §4) |
| **Animować** | Nic w tym serwisie nie rusza się samo |

---

## 7. Favicon i ikona aplikacji

**W ikonie mieści się garnek i nic więcej.** Wordmark `KuKing.pl` ma
w 32 px około czterech pikseli na literę i zamienia się w szarą plamę.

### 7.1 Dlaczego ikona ma własne pole w kolorze marki

Karta zakładki bywa raz jasna, raz ciemna, a przeglądarka nie mówi arkuszowi,
który to przypadek. Znak na własnym polu wygląda tak samo w obu i nie znika
w ciemnej karcie. Pole jest w `--color-brand-solid`, garnek biały, uśmiech
wycięty, margines szczodry — jak w `BRAND_IDENTITY.md` §13: „tło `#B3401F`,
biały znak garnka, duży margines, znak nie dotyka krawędzi”.

### 7.2 Jakie pliki są potrzebne

| Plik | Rozmiar | Do czego | Co się w nim mieści |
|---|---|---|---|
| `favicon.svg` | wektor | zakładka we wszystkich współczesnych przeglądarkach | garnek na polu marki |
| `favicon.ico` | 32 × 32 (i 16 × 16 w środku) | starsze przeglądarki, które nie czytają SVG | to samo, z grubszym wycięciem |
| `apple-touch-icon.png` | 180 × 180 | ekran startowy iPhone'a | to samo, bez zaokrąglenia — iOS zaokrągla sam |
| ikona PWA | 192 × 192 i 512 × 512 | instalacja na telefonie | to samo |
| ikona PWA `maskable` | 512 × 512 | Android, który przycina ikonę do własnego kształtu | garnek w **wewnętrznych 80 %** obrazu, bo brzeg zostanie obcięty |
| obrazek do udostępniania (Open Graph) | 1200 × 630 | link wklejony w wiadomości | garnek **z** wordmarkiem — tu jest miejsce na napis |

**Do sprawdzenia w repozytorium**, które z tych plików już istnieją i czy
`<link rel="icon">` w `x-layout.blade` wskazuje na SVG jako pierwszy.

### 7.3 Czego w ikonie nie ma

Nie ma napisu, nie ma `.pl`, nie ma hasła, nie ma ramki, nie ma cienia.
Nie ma też wersji „na Boże Narodzenie” ani żadnej innej okolicznościowej —
ikona jest tym, po czym człowiek znajduje kartę wśród dwudziestu innych,
a zmieniona ikona to zgubiona karta.

---

## 8. Pliki w `06-marka/znak/`

| Plik | Stan | Do czego |
|---|---|---|
| **`kuking-znak-wyciety.svg`** | **nowy, zalecany** | Garnek jednokolorowy, `currentColor`, uśmiech wycięty maską. Działa na każdym tle. To jest plik do wklejania inline i do sprite'u |
| **`kuking-znak-jasny.svg`** | **nowy, zalecany** | Garnek biały z wyciętym uśmiechem, do wstawiania przez `<img>` na tło ciemne, na kolor marki i na zdjęcie |
| **`kuking-favicon.svg`** | **nowy** | Pole marki + biały garnek + grubsze wycięcie. Favicon i ikona aplikacji |
| **`kuking-pole-ochronne.svg`** | **nowy** | Rysunek pomocniczy do sekcji 3. Nie jest logotypem i nie wolno go używać jako znaku |
| **`podglad-znaku.html`** | **nowy** | Strona przeglądowa: ten sam znak na czterech tłach, w obu motywach, obok plików zastanych. Otwiera się podwójnym kliknięciem. Nie jest częścią produktu |
| `kuking-logo-smile.svg` | **zastany, tylko do odczytu** | Poziomy logotyp na jasne tło. **Dwie usterki:** kolory `#2B241D` i `#B3401F` wpisane z ręki zamiast tokenów, a napis jest żywym `<text>` w foncie Inter — na komputerze bez Intera logotyp zmienia kształt liter, a `letter-spacing: -3` przestaje pasować |
| `kuking-icon-smile.svg` | **zastany, tylko do odczytu** | Sam garnek. Uśmiech narysowany białą kreską na sztywno. Na jasnym tle działa przypadkiem, na kolorowym nie |
| `kuking-logo-smile-inverse.svg` | **zastany, USZKODZONY** | Logotyp na ciemne tło. Garnek biały, uśmiech też biały — uśmiech znika |
| `kuking-icon-smile-inverse.svg` | **zastany, USZKODZONY** | Sam garnek biały, uśmiech biały po białym. To jest plik, który daje białą plamę opisaną w `STAN_WDROZENIA_KITU.md`, etap C |

### 8.1 Cztery pliki zastane zostają — i czego nimi nie wolno robić

**Decyzja: nie kasuję ich i nie poprawiam.** Są kopią tego, co przyszło
z kitu v2, czyli zapisem tego, co projektant narysował. Ta paczka trzyma się
tu precedensu z `STAN_WDROZENIA_KITU.md`: *„Nie zmieniam samych plików
makiet, bo są zapisem tego, co projektant narysował”*. Poprawiony plik,
który wygląda jak oryginał, jest gorszy od oryginału obok poprawki — bo za
pół roku nikt nie wie, który jest który.

Zakres użycia jest za to zamknięty i wygląda tak:

| Wolno | Nie wolno |
|---|---|
| Otworzyć i porównać z wersją nową (`podglad-znaku.html` stawia je obok siebie) | Wstawić do widoku Blade, do e-maila, do materiału prasowego ani do favikony |
| Zacytować w dokumencie jako „tak było” | Podać drukarni, prasie ani komukolwiek na zewnątrz |
| Odziedziczyć z nich geometrię ścieżek — jest poprawna i nowe pliki jej używają | Użyć **którejkolwiek** wersji `-inverse` gdziekolwiek: to jest biała plama, nie znak |
| — | Kopiować z nich `stroke="white"` do nowego pliku |

**Zastępniki, jeden do jednego:**

| Zamiast | Użyj |
|---|---|
| `kuking-icon-smile.svg` | `kuking-znak-wyciety.svg` (inline) |
| `kuking-icon-smile-inverse.svg` | `kuking-znak-jasny.svg` |
| `kuking-logo-smile-inverse.svg` | `kuking-znak-jasny.svg` + wordmark z HTML (sekcja 1.4) |
| `kuking-logo-smile.svg` w interfejsie | znak inline + wordmark z HTML (sekcja 1.4) |
| `kuking-logo-smile.svg` poza serwisem | **nic gotowego nie ma** — trzeba najpierw zamienić `<text>` na krzywe, patrz sekcja 9 |

Sprawdzenie, że nic zastanego nie przecieka do produktu, jest jednym
poleceniem:

```bash
grep -rn 'icon-smile\|logo-smile' resources/ public/     # ma dać zero
```

Wariant znaku jest też wklejony jako `<symbol id="ikona-znak">`
w `podglad/ikony-sprite.html`. Ta wersja jest o krok lepsza od zastanych —
maluje uśmiech przez `var(--color-surface-raised, #fff)` zamiast na sztywno
biało — ale nadal maluje, a nie wycina. Na przycisku w kolorze marki
narysuje uśmiech w kolorze karty, czyli w kolorze, którego tam nie ma.
**Do podmiany na maskę z `kuking-znak-wyciety.svg`.**

### 8.2 Jak wstawić znak inline

```html
<svg class="wordmark-znak" aria-hidden="true" focusable="false"
     viewBox="0 0 64 64"><use href="#ikona-znak"></use></svg>
```

- `aria-hidden="true"` — **kiedy obok stoi napis** `KuKing.pl`. Znak jest
  wtedy ozdobą nazwy, nie drugą kopią nazwy.
- `role="img" aria-label="Kuking"` — **kiedy znak stoi sam** i jest jedynym
  nośnikiem nazwy (favicon, znak wodny, awatar w mediach społecznościowych).
- `focusable="false"` zawsze — bez tego starsze przeglądarki zatrzymują na
  SVG fokus klawiatury.

Sprite musi być wklejony w `<body>`, a nie ładowany z osobnego pliku:
`<use href="ikony.svg#…">` nie działa przez granicę dokumentu ani przy
otwarciu strony z dysku.

**Uwaga, która oszczędzi komuś kwadransa:** `kuking-znak-wyciety.svg`
otwarty jako osobny plik albo wstawiony przez `<img src>` jest **czarny**.
To nie jest usterka — `currentColor` nie ma wtedy po czym dziedziczyć koloru
i spada na wartość początkową. Ten plik jest do wstawiania inline. Do
`<img src>` służy `kuking-znak-jasny.svg` (na ciemne i kolorowe tło);
gdyby kiedyś był potrzebny terakotowy plik do `<img>`, robi się go przez
dopisanie `color="…"` na `<svg>`, a nie przez malowanie kształtów.

Wszystkie te przypadki obok siebie pokazuje
`06-marka/znak/podglad-znaku.html`.

---

## 9. Czego ten znak nie ma i co trzeba by zrobić, gdyby marka rosła

Uczciwa lista. Nic z tego nie jest potrzebne do startu zamkniętej bety
i nic z tego nie blokuje wdrożenia — ale jeśli marka wyjdzie poza serwis,
każde z nich będzie potrzebne.

| Czego nie ma | Kiedy zacznie przeszkadzać | Co trzeba zrobić |
|---|---|---|
| **Wordmark w krzywych** | Pierwszy plik wysłany komuś na zewnątrz: prasa, drukarnia, ktoś, kto nie ma Intera | Zamienić `<text>` w `kuking-logo-smile.svg` na ścieżki. Dziś logotyp zmienia kształt na każdym komputerze bez tego fontu, i nikt tego nie zauważy, dopóki nie zobaczy obok wersji poprawnej |
| **Wersja pionowa** (garnek nad napisem) | Pierwszy format, który jest wyższy niż szerszy: wizytówka, roll-up, oznaczenie stoiska | Ułożyć znak nad wordmarkiem, ustalić odstęp i osobne minimum wysokości |
| **Wersja jednoliterowa** | Awatar 24 px, znacznik na mapie, ikona w liście aplikacji | Dziś zastępuje ją garnek na polu. Litera `K` osobno by konkurowała ze znakiem, więc raczej nie robić |
| **Ikona `maskable` dla Androida** | Instalacja jako aplikacja | Wersja 512 × 512 z zapasem: garnek w wewnętrznych 80 % kwadratu |
| **Reguły dla tła fotograficznego bez podkładu** | Pierwsza kampania ze zdjęciami | Dziś odpowiedź brzmi: zawsze podkład. To wystarcza i lepiej tego nie luzować |
| **Znak w druku jednokolorowym z odbiciem** | Tłoczenie, hafty na fartuchu, pieczątka | Wycięcie uśmiechu nie zadziała w tłoczeniu (nie ma czego przez nie zobaczyć). Trzeba osobnej wersji, w której uśmiech jest kreską, a nie dziurą — i wtedy trzeba świadomie wybrać, w którą stronę |
| **Rejestracja znaku** | Przed jakimkolwiek większym wydatkiem marketingowym | `BRAND.md`, „Due diligence”: UPRP, EUIPO/TMview, Madrid Monitor, firmy i aplikacje, podobne domeny, konta w mediach społecznościowych. **Posiadanie domeny nie jest równoznaczne z prawem do znaku** |
| **Wersja dla partnera / współpracy** (dwa znaki obok siebie) | Pierwsza współpraca | Ustalić kreskę rozdzielającą i wspólną linię podstawy |
| **Dźwiękowe albo ruchome logo** | Nigdy, jeśli to zależy ode mnie | Nic w tym serwisie nie rusza się samo i to jest obietnica złożona człowiekowi na stronie „O Kuking” |

---

## 10. Sprawdzenie znaku przed wypuszczeniem

Trzy rzeczy, każda poniżej minuty:

1. **Na tle marki.** Postaw znak na przycisku `.btn-primary`. Uśmiech
   ma być widoczny jako terakotowa kreska w białym garnku. Biała plama znaczy,
   że to stary plik.
2. **W trybie ciemnym.** Przełącz motyw w stopce. Garnek ma zmienić kolor sam,
   bez przeładowania i bez podmiany pliku. Jeśli nie zmienił — ktoś wpisał
   kolor w `fill` zamiast używać `currentColor`.
3. **W 32 px i w trybie wysokiego kontrastu.** Zmniejsz do 32 px i włącz
   motyw kontrastowy Windowsa. Garnek ma zostać garnkiem z uśmiechem.
   Punkt W-4 w `07-wdrozenie/LISTA-KONTROLNA-A11Y.md`.
