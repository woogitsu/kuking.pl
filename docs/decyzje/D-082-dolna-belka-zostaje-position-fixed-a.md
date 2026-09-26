## D-082 · Dolna belka zostaje `position: fixed`, a rezerwa miejsca pod nią jest LICZONA — 2.4.11 nie kupujemy kosztem 2.5.8

**Data:** 10 września 2026 · **Decyzja techniczna** (audyt 60+, PR #269) · Status: **obowiązuje**

### Problem: dwa kryteria WCAG, które ciągną w przeciwne strony

Audyt 60+ (`docs/research/AUDYT_60_PLUS.md`, ranking napraw pkt 1) wskazał
naruszenie **WCAG 2.2 AA 2.4.11 — Focus Not Obscured (Minimum)**: `.bottom-nav`
jest `position: fixed`, więc nie ma jej w przepływie dokumentu i wysokość
strony NIE rośnie o jej wysokość. Rezerwa na końcu dokumentu (dolne wypełnienie
`.site-footer`) była stałą wartością `--spacing-20` (80 px), a belka ma
`flex-wrap: wrap` i przy dużym tekście rozpada się na kilka wierszy. Gdy belka
urośnie ponad rezerwę, treść przewija się POD nią — a fokus klawiaturowy ląduje
w całości za paskiem.

Zmierzone maksima wysokości belki (`scripts/dostepnosc.mjs`, sześć ekranów,
320/360/414 px):

| wariant | wysokość belki |
|---|---|
| bez powiększania tekstu | 66,6 px (korzeń 16 px) |
| nasze ustawienie „tekst 140%" | 105,5 px (korzeń 16 px) |
| czcionka przeglądarki 200% | 376,2 px (korzeń 32 px) |

### Droga odrzucona: `position: sticky`

Pierwsze podejście wiązało rezerwę z rzeczywistą wysokością belki, wstawiając ją
w przepływ (`sticky` zamiast `fixed`). **Naprawiało 2.4.11 i łamało 2.5.8
(Target Size Minimum).**

Powód leży w axe, nie w naszym układzie: reguła `target-size` liczy sąsiadów
przez `findNearbyElms`, a ta funkcja porównuje kandydatów warunkiem
`selfIsFixed === isFixedPosition(vNeighbor)`. Nakładka `fixed` nie jest więc
zestawiana z treścią nie-`fixed` — i słusznie, bo przypięty pasek stoi nad inną
treścią przy KAŻDYM położeniu przewijania. `sticky` do tego wyjątku nie należy:
staje się zwykłym sąsiadem w przepływie i przycina „bezpieczne pole kliknięcia"
tego, co akurat widać za nim.

Zmierzone przy 320 px, przewinięcie 0, **jednakowe prostokąty belki** dla obu
wariantów (833,4…900 px):

| element | wolne pole przy `fixed` | przy `sticky` |
|---|---|---|
| profil (własny) | 48 px | 14,5 px |
| dodaj przepis | 55,9 px | 10,1 px |
| twoje tagi | 40 px | 4,5 px |

Nakładanie istniało więc także przed zmianą — zmieniło się tylko to, czy axe je
widzi. Kluczowa obserwacja: **te trzy naruszenia nie są usterkami tych trzech
elementów.** Każdy z nich ma prostokąt większy niż wymagane 24 × 24 px
i przechodzi 2.5.8 z samego rozmiaru. To jedna cena `sticky`, płacona przez ten
element, który akurat wpadnie w pasek przy przewinięciu 0 — a więc zależna od
DŁUGOŚCI STRONY, nie od tych elementów. „Naprawa u każdego z trzech" byłaby
przesuwaniem treści do czasu, aż w pasek wpadnie czwarty.

### Droga odrzucona: sam `scroll-padding` przy obu przypiętych paskach

`scroll-padding` przesuwa fokus spod belki, ale belka zostaje na ekranie. Przy
320 × 740 px i czcionce przeglądarki 200% górny pasek ma 263 px, dolny 376,2 px —
razem 639,2 z 740 px, czyli 86% widoku. Żeby fokus wyjechał spod OBU, suma
wartości musiałaby pokryć te 639,2 px, zostawiając pasmo 100,8 px przy **zerowym**
zapasie na obu — a to pasmo musi zmieścić naszą najmniejszą kontrolkę, czyli
48 px. Taka para liczb nie jest poprawką, tylko zakładem.

Dlatego **górny** pasek poniżej progu `15rem` odpina się (`position: relative`):
przy tej wielkości tekstu problemem nie jest margines przy przewijaniu, tylko to,
że przypięty pasek zabiera trzecią część ekranu na stałe. Górny pasek można
odpiąć — jest nad treścią i przewija się z nią. Dolna belka to główna nawigacja
produktu i odpięcie jej zabrałoby jedyną drogę do „Co dziś ugotowałeś?".

### Decyzja

Belka zostaje `fixed`, a rezerwa jest **liczona jawnie** tokenem
`--rezerwa-pod-belka` i **wydawana w dwóch miejscach**, bo to dwie różne rzeczy:

* **dolne wypełnienie `.site-footer`** — stopka jest ostatnia w dokumencie, więc
  to ona decyduje, czy treść da się wyprowadzić spod belki na końcu strony;
* **`scroll-padding-bottom` na `:root`** — przewijanie fokusu w widok, które
  przeglądarka robi sama po Tab, liczy się do krawędzi okna i nie wie, że stoi
  tam nakładka. Nic nie rysuje, działa wyłącznie przy celowanym przewijaniu.

**Trzy stopnie, nie jedna wartość**, bo dwa powiększenia działają inaczej:
czcionka przeglądarki podwaja KORZEŃ (16 → 32 px), więc `rem` rośnie razem
z belką; nasze `data-text-scale` korzenia nie rusza (`--user-text-scale` mnoży
tylko tokeny `--text-*`), więc `rem` stoi, a belka rośnie.

| zakres | rezerwa | pod co liczona |
|---|---|---|
| domyślnie | `calc(8rem * var(--user-text-scale, 1))` | 128 px przy tekście 100%, 179,2 px przy 140% |
| `max-width: 15rem` | `calc(15rem * var(--user-text-scale, 1))` | 480 px przy korzeniu 32 px (czcionka przeglądarki 200%) |
| `min-width: 64rem` | `0rem` | belki nie ma — rezerwa nie ma czego chronić |

### Poprawka po CI: liczy się LUZ, nie sama rezerwa

Pierwsza wersja tej decyzji miała stopnie `8rem` i `13rem` — bez mnożnika.
Rezerwa była wtedy WIĘKSZA od belki w każdym wariancie, więc sprawdzenie
„czy rezerwa pokrywa belkę" świeciło na zielono przez cały czas trwania
usterki. CI (job „Dostępność" na `c492ed1`) zgłosiło mimo to 2.4.11 FAIL
w trzech miejscach i wyłącznie przy „tekst 140%": `szukaj / 320 px`
(a „Wszystko"), `szukaj / 360 px` (a „Do 30 minut") i `wpis / 360 px`
(a „Napisz komentarz").

Zawodziła nie rezerwa, tylko **luz** — to, co z rezerwy zostaje POWYŻEJ
belki, bo tylko w tym pasku przeglądarka ma gdzie postawić element, który
dostał fokus. Zmierzone (Chromium 141, okno 740 px, rezerwa stała 128 px):

| szerokość | belka przy 140% | luz | wynik na CI |
|---|---|---|---|
| 320 px | 105,5 px | 22,5 px | ✗ |
| 360 px | 89,5 px | 38,5 px | ✗ |
| 414 px | 75,2 px | 52,8 px | ✓ |

Oblewały dokładnie te szerokości, na których luz zszedł **poniżej 48 px**,
czyli poniżej jednej naszej kontrolki. Element wyższy od luzu nie ma jak
stanąć nad belką w całości — i dlatego usterka wychodziła losowo (raz jeden
element, raz trzy, w obrazie deweloperskim wcale): trafiała w ten, który
akurat wpadł w ten pasek. To wyjaśnia też, czemu na `65daf90` CI zgłaszało
jedno naruszenie, a na `c492ed1` trzy, przy tej samej regule CSS.

Przyczyną są dwie jednostki, które miały iść razem, a szły osobno:
`--user-text-scale` mnoży tokeny `--text-*`, ale **korzenia nie rusza**.
Belka rośnie więc z tekstem, a rezerwa w `rem` stoi w miejscu — luz zapada
się dokładnie wtedy, gdy tekst jest największy, czyli u osoby, dla której
ten produkt jest robiony. Mnożnik w `calc()` wiąże rezerwę z tą samą
wielkością, która rozpycha belkę. Luz po poprawce: **73,7 / 89,7 / 104 px**
przy 320 / 360 / 414 px.

Stopień dla bardzo dużego tekstu idzie z `13rem` na `15rem` z tego samego
powodu, liczonego przy korzeniu 32 px: belka 376,2 px kontra 416 px rezerwy
to 39,8 px luzu przy kontrolce 96 px (48 px × podwojony korzeń). `15rem`
to 480 px, czyli 103,8 px luzu. Ten wariant przechodził na CI mimo cienkiego
luzu — poprawiony razem z tamtym, bo to jedna usterka tej samej klasy.

Próg w `rem`, nie w pikselach, bo porównuje okno z KORZENIEM i mówi dokładnie to,
o co chodzi: „tekst jest tak duży w stosunku do ekranu, że przypięty pasek
zabiera jego znaczną część". Telefon 320 px przy korzeniu 16 px to 20rem (próg
nie łapie), ten sam telefon przy 200% to 10rem (łapie). Przy zwykłym korzeniu
próg odpowiadałby oknu 240 px — węższemu niż jakikolwiek telefon, więc nie
zadziała przez pomyłkę.

Rezerwa jest JEDNA DLA WSZYSTKICH, także dla gościa, który dolnej belki nie ma
(`@auth` w `layout.blade.php`). Warunkowanie jej klasą układu gościa
rozdzieliłoby jeden token na dwie wartości dla dwóch jego zastosowań
(wypełnienie stopki dziedziczy po `body`, a `scroll-padding-bottom` rozwiązuje
się na `:root`) — czyli zamieniłoby 48 px pustego miejsca na pułapkę do
nadepnięcia.

### Czego ta decyzja NIE robi

**Nie podnosi progu tolerancji w `scripts/dostepnosc.mjs`** i nie wyłącza żadnej
reguły. Obie — 2.4.11 i 2.5.8 — chodzą i obie zatrzymują CI kodem 1. Podniesienie
progu byłoby zamianą usterki na kłamstwo w pomiarze (`AGENTS.md`, zakaz
„naprawiania" przez rozluźnianie automatu).

### Czym to jest pilnowane

Właściwym automatem jest `scripts/dostepnosc.mjs` — układu strony nie da się
stwierdzić z CSS-a. Ale job `dostepnosc` w CI chodzi WARUNKOWO: czyta `git diff`
i startuje tylko wtedy, gdy zmiana dotyka `resources/`, `public/`,
`scripts/dostepnosc.mjs` albo plików npm. Zmiana w samym `app/` przechodzi obok
niego. Dlatego niezmienniki widoczne w źródle pilnuje dodatkowo
`tests/Feature/RezerwaPodDolnaBelkaTest.php`, który chodzi w jobie `test`, czyli
zawsze: belka dalej `fixed` (nie `sticky`), token wydany w obu miejscach oraz
— po poprawce opisanej wyżej — każdy niezerowy stopień rezerwy mnożony przez
`--user-text-scale`, a największy nie niższy niż `15rem`.

**Kontrola ujemna poprawki** (pełny przebieg `scripts/dostepnosc.mjs` po
przywróceniu stałych 80 px): **109 kontrolek zasłoniętych w 100%**, wśród nich
odnośnik stopki „Prywatność" na wszystkich czterech badanych ekranach przy 320 px
i „tekst 140%". Po poprawce: 0.

**Pliki:** `resources/css/app.css` · `scripts/dostepnosc.mjs` ·
`tests/Feature/RezerwaPodDolnaBelkaTest.php`
