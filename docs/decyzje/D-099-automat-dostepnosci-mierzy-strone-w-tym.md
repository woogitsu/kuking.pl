## D-099 · Automat dostępności mierzy stronę W TYM STANIE, W KTÓRYM WYDAJE JĄ PRODUKT — czerwone 2.4.11 na `main` było usterką POMIARU, nie belki

**Data:** 11 września 2026 · **Naprawa automatu** (job „Dostępność" oblewający
na `main`, blokujący #306 i #213) · Status: **obowiązuje**

### Objaw: ten sam kod, pięć przebiegów, pięć różnych wyników

Job „Dostępność (axe-core) i wydajność (Lighthouse)" oblewał na `main`
i na każdym PR-ze z niego zbudowanym. Zawsze **WCAG 2.2 AA — 2.4.11 Focus Not
Obscured**, zawsze wariant **`tekst 140%`**, zawsze pod `.bottom-nav` —
a za każdym razem inny ekran, inny element i inna szerokość:

| przebieg | wynik |
|---|---|
| #303 (`142e645`) | 2: `szukaj / 320 px` „Rosół babci Zofii”, `ustawienia profilu / 360 px` `input#f-region` |
| #307 (`8372d10`) | 1: `tablica / 360 px`, odnośnik z datą |
| #308 (`d62bee1`) | 1: `tablica / 414 px`, odnośnik „Ania” |
| #306 (`84f67f3`) | 0 |
| #306 (`4f045d9`) | 1, w powtórce 2 |

Wynik zależny od przebiegu, łącznie z zerem, przy niezmienionym kodzie.

### Co się okazało: strona była mierzona W TRAKCIE PRZELICZANIA UKŁADU

Produkt wydaje `data-text-scale` na `<html>` **po stronie serwera**
(`layout.blade.php` w. 165), więc strona osoby, która włączyła większy tekst,
jest ułożona dużym pismem od pierwszego ułożenia i nigdy się z tego powodu
nie przelicza. Automat robił odwrotnie: wczytywał stronę w rozmiarze
domyślnym, dokładał atrybut po wczytaniu — i **od razu zaczynał chodzić
Tabem**.

Przeliczenie układu po zmianie atrybutu na korzeniu nie jest natychmiastowe.
Zmierzone w kontenerze deweloperskim (Chromium 153, `/tagi` i `/szukaj` przy
320 px), pomiar wykonany zaraz po `setAttribute` potrafił jeszcze zobaczyć
układ SPRZED skalowania — i to w stanie mieszanym, który sam w sobie jest
dowodem:

```text
--user-text-scale (styl policzony)   1.4      ← już nowe
scroll-padding-bottom na :root       179,2px  ← już nowe
.bottom-nav, wysokość ułożona         66,6px  ← jeszcze stare (skala 1)
.site-footer, wypełnienie dolne      128px    ← jeszcze stare (skala 1)
```

Strona przy tekście 140% jest o mniej więcej jedną trzecią wyższa (zmierzone
na `/szukaj` przy 320 px: `scrollHeight` 2796 → 3744 px). Jeżeli przeliczenie
wypadło **w trakcie** chodzenia Tabem, to element, który przeglądarka przed
chwilą przewinęła nad belkę, zjeżdżał razem z rosnącą stroną w dół — a drugi
raz nikt go już nie przewija, bo fokus się nie zmienił. Element lądował pod
belką i automat notował FAIL.

**Kontrola dodatnia mechanizmu.** Przy przeliczeniu opóźnionym o sześć kroków
Taba wychodzą dokładnie te elementy, które zgłaszało CI — odnośnik „Ania” na
tablicy przy 360 i 414 px (por. #308); przy opóźnieniu o trzy i o dziesięć
kroków — zero. To jest cała zmienność wyniku, łącznie z przebiegami zielonymi.

### Hipoteza, którą to OBALA: „`scroll-padding` nie ma na czym zadziałać”

Naturalne wyjaśnienie brzmiało: `scroll-padding-bottom` działa tylko wtedy,
gdy przeglądarka coś przewija, a element leżący już w oknie — wizualnie pod
belką, formalnie „widoczny” — przewijania nie wywoła. Gdyby to była prawda,
D-082 byłoby naprawą pozorną, a jedynym wyjściem `position: sticky`.

**Zmierzone i nieprawdziwe** (Chromium 153, okno 740 px, tekst 140%).
Chromium liczy „czy element jest widoczny” względem *scroll snapport*, czyli
okna POMNIEJSZONEGO o `scroll-padding` — więc element leżący w pasie pod
belką jest dla niego niewidoczny i zostaje przewinięty. Bezpośredni pomiar:
odnośnik „Ania” na `/home` przy 414 px, przy przewinięciu 0, ramka
676…706 px (czyli w całości pod belką zaczynającą się na 664,8 px), po
nadaniu fokusu — `scrollY` 0 → 411 i ramka 265…295 px.

Pomiar wyczerpujący, bez loterii kolejności: **każdy** element ogniskowalny na
`/home`, `/szukaj`, `/ustawienia/profil` i `/tagi`, przy 320/360/414 px
i tekście 140%, ogniskowany osobno po wyzerowaniu przewinięcia (167 kontrolek
na szerokość):

| stan arkusza | kontrolek kończących w 100% pod belką |
|---|---|
| `main` (z `scroll-padding-bottom`) | **0** |
| bez `scroll-padding-bottom` (kontrola ujemna) | **13** |

Lista tych trzynastu zawiera „Ludzie” (`szukaj / 414 px`), „Ania”
(`tablica / 414 px`), odnośnik z datą, `input#f-username`
(`ustawienia profilu / 360 px`) i „Do 30 minut” — czyli **dokładnie te
elementy, które zgłaszało CI**. To jest ostatni brakujący dowód: CI zgłaszało
te elementy, które `scroll-padding-bottom` ratuje, w przebiegach, w których
nie zdążyło ono zadziałać na właściwym układzie.

### Decyzja

**1. Wariant skali tekstu czeka na PRZELICZONY układ i sprawdza, że wszedł.**
`wlaczSkaleTekstu()` w `scripts/dostepnosc.mjs` nadaje atrybut, po czym czeka
na STAN, a nie na zegar: aż ułożona strona pokaże wielkość pisma
podstawowego odpowiadającą żądanej skali (`body` ma `font-size:
var(--text-body)`, czyli `1.125rem × --user-text-scale`). Czekanie na czas
byłoby zakładem o szybkość maszyny — czyli tym samym błędem z innym progiem,
bo runner GitHuba bywa wolniejszy od tego kontenera.

Sprawdzana jest wielkość UŁOŻONA, nie sama wartość zmiennej: to właśnie
zmienna była już nowa wtedy, gdy układ był jeszcze stary.

**Niepowodzenie jest BŁĘDEM, nie pominięciem.** Ekran, którego nie udało się
przeliczyć, nie zostaje zapisany jako zbadany — skrypt kończy kodem 1. Pomiar
w nieznanej skali jest gorszy niż jego brak; to ten sam wzorzec, co
istniejące sprawdzenie korzenia przy wariancie „czcionka przeglądarki 200%”.

**2. Pierwszeństwo ma przeglądarka, którą mierzy CI.** `znajdzChromium()`
brało ścieżkę z obrazu deweloperskiego zawsze, gdy tylko istniała — a to
rewizja 1194 (Chromium 141), podczas gdy `playwright` 1.63 przypina 1243
(Chrome 153) i to ją pobiera runner. Dopóki tak było, „u mnie zielone,
na CI czerwone” mogło znaczyć wyłącznie tyle, że to były dwie różne
przeglądarki — i nie dało się tego rozstrzygnąć bez ręcznego ustawiania
zmiennej. Teraz bierzemy tę, którą weźmie CI; ścieżka z obrazu zostaje
zapasem. `CHROMIUM_PATH` dalej przebija wszystko.

### Czego ta decyzja NIE robi — i to jest w niej najważniejsze

**Nie zmienia ani jednej linijki CSS-a i nie rusza D-082.** Belka zostaje
`position: fixed`, rezerwa zostaje liczona. Sprzeczności 2.4.11 z 2.5.8 nie
trzeba było rozstrzygać, bo nie było czego kupować: pomiar wyczerpujący mówi
0 naruszeń 2.4.11 przy nietkniętym arkuszu. Zamiana `fixed` na `sticky`
kosztowałaby trzy naruszenia 2.5.8 (D-082 ma je zmierzone) w zamian za
naprawę usterki, której nie ma.

**Dowód, że jedno naruszenie nie zostało wymienione na drugie**, jest w tym
samym przebiegu, nie w osobnym: axe chodzi tu z `wcag22aa`, czyli
z regułą `target-size` (2.5.8), na wariantach 1280 px i 320 px. Pełny
przebieg po zmianie: `naruszeń: 0, blokujących: 0, przepełnień w poziomie: 0,
focus zasłonięty w 100%: 0`.

**Nie podnosi żadnego progu, nie wyłącza żadnej reguły i nie przenosi
niczego do „ostrzeżeń produktowych".** 23 ostrzeżenia „focus częściowo
zasłonięty" (25–75%) zostają tam, gdzie były — nie są naruszeniem 2.4.11
i nadal nie liczą się do kodu wyjścia. Zestaw kontrol zatrzymujących CI jest
niezmieniony i pilnowany testem.

**Nie usuwa zależności od dat zalążkowych.** `DemoSeeder` liczy daty od
`now()`, więc treść (a przez nią wysokość strony) różni się między
przebiegami. Po tej poprawce ta zmienność przestaje mieć skutek: przy
ułożonym układzie miejsce elementu nie decyduje o wyniku, bo przewijanie
fokusu każdy element wyprowadza spod belki, a rezerwa na końcu dokumentu
(179,2 px przy 140%) jest większa od belki (105,5 px) na każdej mierzonej
szerokości. Zamrożenie zegara siewu wymagałoby zmiany w `DemoSeeder` i jest
osobną pracą.

### Przy okazji, w tym samym pliku — dwie zaległości z §14.6 przekazania

* **`/tagi` wchodzi na listę `EKRANY`** (jako gość, bo taka to strona).
  Powstało w #303 (D-087) i przez dwie doby było jedyną stroną publiczną
  serwisu, której automat nie oglądał — nie z decyzji, tylko dlatego, że plik
  trzymały wtedy trzy gałęzie naraz. Brak ekranu na liście niczego nie psuje
  i dlatego jest groźny: raport wygląda na kompletny.
* **`liczbyProfilu` wchodzą do `storage/dostepnosc.json`** — kontrola
  `rozjazdyLiczb` (D-091) była w linii podsumowania i w warunku wyjścia,
  a w artefakcie jej nie było. Artefakt jest jedynym miejscem, z którego
  da się odczytać przyczynę po skończonym przebiegu.

### Czym to jest pilnowane

Dwa nowe testy chodzą w jobie `test`, czyli ZAWSZE — także wtedy, gdy job
`dostepnosc` się nie odpali, bo ten startuje warunkowo (`git diff`).

`PomiarDostepnosciKonczyKodemJedenTest` sprawdza wszystkie **trzy miejsca
zbiorcze** tego pliku (§14.5 przekazania) osobno: linię podsumowania, warunek
wyjścia kodem 1 i artefakt JSON. Lista siedmiu kontrol jest w teście wpisana
z ręki, a nie czytana ze źródła — kontrola wyprowadzona z badanego pliku
znika razem z nim. Ten sam test pilnuje, że skala tekstu wchodzi wyłącznie
przez `wlaczSkaleTekstu` we wszystkich trzech pomiarach; powrót do gołego
`setAttribute` przywraca wyścig, i to po cichu, bo objawia się on dopiero na
obciążonej maszynie i raz na kilka przebiegów.

`PomiarDostepnosciObejmujeStronyPubliczneTest` porównuje tablicę tras z listą
`EKRANY` i wymaga, żeby każda strona publiczna była mierzona albo stała na
wypisanej liście świadomych wyjątków z powodem. Skan ujawnił przy okazji
**siedem stron publicznych, których automat nie ogląda** — `o-kuking`,
`pomoc`, `odwolanie` (gość), `nie-pamietam-hasla`, `logowanie/link`,
`logowanie/kod`, `cofnij-usuniecie-konta`. Stoją na tej liście jako
**dług nazwany**, nie jako wyjątek merytoryczny: dopisanie ich to osobna
praca, bo każdy nowy ekran może przynieść własne znaleziska, a tego nie robi
się w PR-ze, który ma odblokować `main`.

### Jak to wycofać

Trzy niezależne kawałki, każdy osobno. Przywrócenie gołego `setAttribute`
w trzech miejscach wraca do stanu sprzed poprawki (i do losowego czerwonego
CI). Przywrócenie starej kolejności w `znajdzChromium()` wraca do mierzenia
lokalnie inną przeglądarką niż na CI. Skreślenie `/tagi` i `liczbyProfilu`
wraca do niepełnego pomiaru i niepełnego artefaktu. Żadne z nich nie dotyka
danych, schematu ani wyglądu serwisu.

**Zmiana wymaga:** rezygnacji z zasady „automat mierzy stronę w tym stanie,
w którym wydaje ją produkt". Gdyby produkt kiedyś zaczął zmieniać skalę
tekstu bez przeładowania strony, ta decyzja przestaje być tylko o pomiarze
i trzeba ją napisać od nowa — razem z odpowiedzią na pytanie, co wtedy dzieje
się z fokusem, który już stoi na elemencie.

**Pliki:** `scripts/dostepnosc.mjs` ·
`tests/Feature/PomiarDostepnosciKonczyKodemJedenTest.php` ·
`tests/Feature/PomiarDostepnosciObejmujeStronyPubliczneTest.php` ·
D-082 · D-087 · D-091
