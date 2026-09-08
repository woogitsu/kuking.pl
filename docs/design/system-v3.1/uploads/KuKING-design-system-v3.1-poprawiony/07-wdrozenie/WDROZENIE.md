> **Aktualizacja v3.1 · 7 września 2026.** Zmiany z audytu opisuje [AUDYT-V3.1.md](../AUDYT-V3.1.md). W sprawach motywu, stopki, pozycjonowania nagłówka i skali 200% ten dokument oraz `07-wdrozenie/MOTYW-V3.1.md` mają pierwszeństwo przed poniższym opisem v3.0. Paleta i znak pozostają bez zmian.

# Wdrożenie — ile to naprawdę kosztuje

Kit v2 nie zadał tego pytania i dlatego stanął. `DLACZEGO-NIE-WYSTARCZYL.md`
kończy się zdaniem, które jest zadaniem tego dokumentu:

> **Wniosek: jeśli projekt wymaga nowego wariantu komponentu, powiedz to
> wprost i policz, ile ekranów on dotyka.**

Ten plik liczy. Nie mam dostępu do repozytorium serwisu — mam kopię arkuszy
i listę komponentów z 7 września 2026. Wszędzie, gdzie liczba zależy od kodu,
którego w paczce nie ma, piszę **„do sprawdzenia w repozytorium”** i podaję
polecenie, które to rozstrzyga. Nie zgaduję: poprzednia próba kosztowała ten
projekt dokładnie tym, że ktoś napisał brief na podstawie streszczenia zamiast
pliku.

---

## 1. Rozmiar sprawy w liczbach

Policzone, nie oszacowane (polecenia w sekcji 9).

| Co | Ile |
|---|---|
| komponentów Blade | **25** |
| wierszy w nich razem | **3 444** |
| z tego w jednym `x-recipe-wizard.blade` | **1 349** (39 % całości) |
| z tego w `x-layout.blade` | **508** (15 %) |
| pozostałe 23 komponenty razem | **1 587** (46 %) |
| klas w dzisiejszych arkuszach serwisu | **236** |
| klas w `02-komponenty/komponenty.css` | **163** |
| **nazw wspólnych — zmienia się wygląd, nie kod widoku** | **59** |
| **nazw nowych — trzeba je dopisać w widokach** | **104** |
| klas z dzisiejszych arkuszy, których nowa warstwa NIE opisuje | **177** |
| własnych reguł CSS w makietach `03-szablony/` i `04-strona-www/` | **0** |
| sięgnięć `var(--…)` w arkuszach serwisu | **580** |
| tokenów, które zmieniają wartość | **0** |

Dwie liczby z tej tabeli warto przeczytać razem: **104 nowe nazwy klas
i 177 nazw, których nowa warstwa nie dotyka.** To znaczy, że
`komponenty.css` nie jest zamiennikiem `app.css`, tylko jego częścią —
i że po wdrożeniu w serwisie będą **dwie warstwy komponentów naraz**.
To jest ryzyko R-2 w sekcji 6.

Trzecia liczba jest nowa i jest najlepszą wiadomością w tym dokumencie:
**zero.** Osiem makiet w `03-szablony/` i `04-strona-www/` nie ma dziś ani
jednej własnej reguły CSS — cała ich treść wizualna pochodzi z `tokens.css`
i `komponenty.css`. Wcześniej każda z pięciu makiet `03-szablony/` niosła ten
sam blok około dwudziestu pięciu reguł we własnym `<style>`. To jest dowód,
że to jest system, a nie zbiór ładnych stron: makieta, która nie potrzebuje
własnego CSS, da się przepisać na Blade jeden do jednego.

> **Uwaga o liczbach w nagłówku `komponenty.css`.** Ten nagłówek niesie
> zestaw z pierwszego pomiaru (139 / 50 / 89 / 186), zrobionego przed
> dopisaniem sekcji 16. Po niej jest **163 / 59 / 104 / 177**. Nie jest to
> błąd — jest to zwykłe starzenie się liczby przepisanej zamiast policzonej,
> i dlatego w §9 stoją polecenia, a nie wyniki.

---

## 2. Tabela: 25 komponentów Blade × co się z nim dzieje

Kolumna „ekranów” odnosi się do inwentarza z
`01-stan-obecny/inwentarz-ekranow.md` (15 ekranów, numery 01–15).

Godziny są dla jednej osoby, która zna to repozytorium, i **obejmują test
oraz przegląd kodu**, bo bez nich zmiana nie jest zrobiona. Nie obejmują
czekania na decyzję właściciela — to jest osobna kolumna.

### 2.1 Zostaje bez zmian — 6 komponentów, 0 h

| Komponent | Wierszy | Dlaczego nic nie robimy |
|---|---|---|
| `x-json-ld.blade` | 17 | Nie ma nic wspólnego z wyglądem. Wypisuje dane dla wyszukiwarki. Makieta `04-strona-www/przepis-publiczny.html` używa go dokładnie tak, jak jest. |
| `x-karuzela-zdjec.blade` | 73 | `komponenty.css` nie definiuje ani jednej klasy `.karuzela-*` — wymienia je tylko w komentarzu jako przykład polskiego nazewnictwa. Karuzela zostaje na `app.css`. |
| `x-kolaz-zdjec.blade` | 32 | Jak wyżej. |
| `x-ustawienia-nawigacja.blade` | 58 | Pięć klas `.ustawienia-nawigacja-*`; nowa warstwa nie dotyka żadnej. |
| `x-show-more.blade` | 26 | Używa `.btn`. Wygląd przycisku się zmienia, znacznik nie. |
| `x-comment-thread.blade` | 192 | Nowa warstwa nie ma ani jednej klasy komentarza. Uwaga: komponent prawdopodobnie używa `.card` i `.btn`, więc **wygląda inaczej, choć nie jest zmieniany** — do obejrzenia w Etapie 2, nie do przepisania. |

**Razem: 0 h.** To jest 398 wierszy komponentów, których nikt nie dotyka —
i to jest dobra wiadomość, którą warto zapisać, bo kit v2 sprawiał wrażenie,
że wszystko trzeba zrobić od nowa.

### 2.2 Zmienia się tylko arkusz — 5 komponentów, 2,5–4 h

Znacznik zostaje, zmienia się to, co robi z nim CSS. Praca polega na
obejrzeniu ekranu i naprawieniu tego, co się rozjechało.

| Komponent | Wierszy | Co się zmienia | Ekranów | Godziny |
|---|---|---|---|---|
| `x-avatar.blade` | 21 | `.avatar` dostaje nowe rozmiary: 48 / 40 (`-sm`) / 80 px (`-lg`). Dziś `app.css` ma rozmiary do **88 px** (`STAN_WDROZENIA_KITU.md`, etap D, profil). Duży awatar zmaleje o 8 px. | 05, 07, 08, 09, 02, 11 → **6** | 0,5 |
| `x-confirm-button.blade` | 43 | `.btn-danger` i `.danger-zone` — nowa warstwa dokłada `.danger-zone-tytul`. Potwierdzenie bez JavaScriptu zostaje nietknięte. | wszędzie, gdzie da się coś usunąć → **do sprawdzenia w repozytorium** | 0,5 |
| `x-cooked-card.blade` | 67 | `.badge-cooked` bez zmiany wartości kolorów; zdjęcie zostaje pełnowymiarowe (to już jest zrobione, etap C). | 07 → **1** | 0,5 |
| `x-tagi-formularz.blade` | 96 | `.chip` ma teraz `min-height: var(--control-height-min)`, czyli **48 px zamiast dzisiejszej wysokości**. Chip jest przyciskiem, więc musi mieć cel dotyku. Formularz tagów zrobi się wyższy. | 13, 09, 06 → **3** | 1 |
| `x-wybor-wygladu.blade` | 63 | `.choice` / `.choice-grid` zostają, dochodzi `.choice-naglowek` (wiersz z polem wyboru i etykietą). Jedna klasa do dopisania. | 12, 13 → **2** | 0,5–1 |

### 2.3 Potrzebuje nowego parametru — 5 komponentów, 12–17 h

| Komponent | Wierszy | Nowy parametr i po co | Ekranów | Godziny |
|---|---|---|---|---|
| `x-field.blade` | 101 | **`szerokosc`** (`rok` / `liczba` / `krotkie` / `srednie` / brak = 100 %) — D-109. Do tego trzy zmiany renderowania: (a) podpowiedź przenosi się **nad** pole (`.field-podpowiedz`), a `.field-help` zostaje pod polem dla treści technicznych; (b) `required` przestaje rysować gwiazdkę i rysuje słowo `wymagane` (D-106) — dzisiejszy wzorzec z `COMPONENTS_BLADE.md` §2 wypisuje `<span aria-hidden="true">*</span>`; (c) opakowanie dostaje `id`, żeby `:target` z podsumowania błędów podświetlał pole bez JavaScriptu. | 03, 04, 12, 13, 14 + ustawienia + zgłoszenia → **≥ 8** | **5–7** |
| `x-empty-state.blade` | 20 | **`opis`** — nowa warstwa rozdziela tytuł (`.empty-state-title`) i zdanie wyjaśniające (`.empty-state-opis`), a `COPY_STYLE.md` §6 ma dla każdego pustego stanu **dwa** teksty: nagłówek i „+ wyjaśnienie”. Dziś komponent ma `title`, `action`, `href`, `mark` — wyjaśnienia nie ma jako parametru. | 02, 05, 06, 09, 10, 11 → **6** | 1–2 |
| `x-szyna-startowa.blade` | 49 | **`pusta`** albo jawna decyzja, co pokazać. D-102 mówi, że trzecia kolumna istnieje **zawsze**, także pusta — więc szyna przestaje być czymś, co się dodaje na wybranych ekranach, i staje się częścią szkieletu. | 07, 08, 09, 10, 11, 12, 13, 14 → **8** | 2–3 |
| `x-konto-przykladowe.blade` | 30 | **`waga`** (`glosna` / `cicha`) — D-103: w strumieniu plakietka jest cicha i siedzi w wierszu metadanych po kropce, na profilu konta przykładowego jest głośna i stoi raz u góry. **Plus rozstrzygnięcie treści — patrz P-3 w sekcji 5.** | 02, 05, 07, 08, 09, 10, 11 → **7** | 2 |
| `x-recipe-card.blade` | 25 | **`dane`** (czas / porcje / poziom) — `.karta-przepisu-dane` i `.dana-przepisu`. `STAN_WDROZENIA_KITU.md` zapisuje to jako otwarte: „`recipe-card` bez czasu i porcji obok tytułu, jak w kicie”, z uwagą, że wymaga zmiany komponentu współdzielonego. | 05, 06, 09, 10 → **4** | 2–3 |

### 2.4 Potrzebuje nowego wariantu — 3 komponenty, 12–17 h

| Komponent | Wierszy | Nowy wariant | Ekranów | Godziny |
|---|---|---|---|---|
| `x-post-card.blade` | 200 | **Cztery rzeczy naraz.** (1) `.karta-wpisu` zamiast `.post-card` — 8 podklas, **14 wystąpień w `app.css`**. (2) Wariant `zwarta` (D-104): 16:9, bez treści i bez stopki. (3) `.karta-bez-zdjecia` (D-105). (4) **`.karta-tytul` — element, którego karta dziś nie ma**; patrz P-1 w sekcji 5, bo to jest pytanie o bazę danych, nie o CSS. | 02, 05, 06, 08, 09, 10 → **6** | **6–8** (bez migracji) |
| `x-photo.blade` | 195 | **`zwarta`** (16:9 dla siatki kart) i **`z-napisem`** (`.zdjecie-z-napisem` + `.zdjecie-podklad` — tekst na zdjęciu zawsze na podkładzie). Komponent ma już `variant`, więc to rozbudowa, nie przepisanie. | 02, 05, 06, 07, 08, 09, 10, 12, 13 → **9** | 3–4 |
| `x-ikona.blade` | 69 | Zestaw ikon rośnie z dzisiejszego do **18 symboli** z `podglad/ikony-sprite.html`: dom, lupa, plus, zeszyt, osoba, dzwonek, zegar, porcje, czapka, zakładka, komentarz, garnek, aparat, ostrzeżenie, ptaszek, strzałka, ustawienia, znak. Nowe są przynajmniej: zegar, porcje, czapka, aparat, ostrzeżenie, ptaszek, strzałka. Sprite musi być wklejony w `<body>` (`.sprite-ukryty`), bo `<use href="plik.svg#…">` nie działa przez granicę dokumentu. | wszystkie 15 | 3–5 |

### 2.5 Przebudowa — 2 komponenty, 22–32 h

| Komponent | Wierszy | Co się dzieje | Ekranów | Godziny |
|---|---|---|---|---|
| `x-layout.blade` | 508 | **Szkielet strony.** Trzecia kolumna zawsze (D-102), `.topbar-wnetrze` zamiast `.topbar-inner` (7 wystąpień), `.stopka` / `.stopka-wnetrze` zamiast `.site-footer` / `.site-footer-inner` (13 + 8 wystąpień), `.tylko-dla-czytnika` zamiast `.skip-link` i `.visually-hidden`, wklejony sprite ikon, `data-text-scale` z krokami 90 i 140. **Plus przełączniki `.tylko-szerokie` / `.tylko-waskie` z sekcji 16 arkusza — bez nich treść szyny nie ma gdzie stanąć poniżej 80rem.** | **wszystkie 15** | **6–8** |
| `x-recipe-wizard.blade` | 1 349 | **Znika w dzisiejszej postaci.** D-108 rozbija go na trzy strony pod trzema adresami, plus czwarty adres z wersją jednostronicową jako drogą awaryjną. To jest praca w trasach, kontrolerze i zapisie szkicu, nie w CSS. Makiety trzech kroków są gotowe: `03-szablony/dodaj-przepis-krok-{1,2,3}.html`. **Wymaga decyzji o adresach — P-5.** | 13 → **1 ekran, ale najdroższy w serwisie** | **16–24** |

### 2.6 Znika — 0 komponentów

Żaden komponent nie znika. `x-recipe-wizard.blade` zmienia postać, ale jego
zawartość (pola, walidacja, minutnik, tożsamość kroku) przenosi się do trzech
widoków — nie jest wyrzucana. **To jest ważne zdanie: nowy wygląd nie kasuje
ani jednego klocka, z którego serwis jest zbudowany.**

### 2.7 Podsumowanie kosztu

| Grupa | Komponentów | Godziny |
|---|---|---|
| zostaje bez zmian | 6 | 0 |
| zmienia się tylko arkusz | 5 | 2,5–4 |
| nowy parametr | 5 | 12–17 |
| nowy wariant | 3 | 12–17 |
| przebudowa | 2 | 22–32 |
| **komponenty razem** | **21 zmienianych z 25** | **49–70 h** |
| podmiana tokenów i wpięcie arkusza (`ZMIANY-TOKENOW.md`) | — | 2–4 |
| **sekcja 16 arkusza: 22 reguły, 15 nowych nazw klas do wstawienia w widokach** | — | **4–6** |
| **sekcja 16: 9 nazw wspólnych więcej do obejrzenia w Etapie 2** | — | **1** |
| przejście `LISTA-KONTROLNA-A11Y.md` na 15 ekranach × 4 warianty | — | 8–12 |
| poprawki po tym przejściu (doświadczenie: 10–20 % pracy głównej) | — | 6–12 |
| **razem** | | **70–105 h** |

Czyli **dwa do trzech tygodni pracy jednej osoby**, przy założeniu, że
decyzje z sekcji 5 są podjęte, zanim praca się zacznie. Bez nich dwie
największe pozycje (karta wpisu, kreator przepisu) stoją — dokładnie tak
jak stanął kit v2.

**Skąd wzięły się dwa nowe wiersze.** Sekcja 16 `komponenty.css` powstała
po pierwszym pomiarze kosztu — to jest te 22 reguły, które wcześniej stały
w makietach lokalnie i których arkusz nie miał (opis w sekcji 3). Dla paczki
jest to praca zrobiona, ale **dla serwisu nie**: arkusz urósł ze 139 do 163
nazw klas, z czego **15 to nazwy nowe, które ktoś musi wstawić w widokach**
(`.kroki`, `.lista-skladnikow`, `.pochodzenie`, `.pochodzenie-tytul`,
`.przepis-zdjecie`, `.wiersz-autora`, `.szyna-danie`, `.szyna-danie-tytul`,
`.szyna-danie-zdjecie`, `.rzad-pol`, `.topbar-szukaj-kolumna`,
`.tylko-szerokie`, `.tylko-waskie`, `.tabela-otoczka`, `.tabela`),
a **9 to nazwy, które serwis już ma i które nowa warstwa teraz przestyluje**.
Bez tych dwóch wierszy koszt byłby zaniżony o pół dnia — a to jest dokładnie
ten rodzaj przemilczenia, przez który kit v2 stanął.

---

## 3. Dziura w warstwie komponentów — **zamknięta, i warto wiedzieć jak**

Ta sekcja opisywała usterkę. Usterka jest naprawiona, ale opis zostaje
w formie **było / jest / jak sprawdzone**, bo cenniejszy od czystego
dokumentu jest zapis, na czym ta paczka się prawie potknęła.

### 3.1 Belka górna na telefonie

**Było.** Każda z pięciu makiet w `03-szablony/` niosła ten sam blok około
dwudziestu pięciu reguł we własnym `<style>`, opisany w niej jako
„PROPOZYCJA DO SYSTEMU — reguły, których nie ma w `komponenty.css`”. Wśród
nich było **12 nazw klas**, których arkusz nie znał w ogóle, i dwie poprawki
do klas istniejących. `komponenty.css` nie miał belki górnej na telefon:
`.topbar-wnetrze` poniżej 64rem był wierszem `flex` bez zawijania,
a `.topbar-akcje` nie mógł się ani zawinąć, ani zwęzić.

Zmierzone w Chromium przy oknie 320 px: `.topbar-akcje` żądał **363 px**,
a cała belka kończyła się na **613 px**. `node 07-wdrozenie/sprawdz-uklad.mjs`
zgłaszał wtedy **12 problemów, wszystkie w `02-komponenty/galeria.html`** —
w jedynej stronie, która lokalnej łatki nie miała:

```
02-komponenty/galeria.html @ 320px, tekst 100%: strona przewija się w poziomie (613 > 320)
02-komponenty/galeria.html @ 1024px, tekst 150%: strona przewija się w poziomie (1177 > 1024)
```

**Jest.** `komponenty.css` ma sekcję 16 („Uzupełnienia z budowy makiet
i galerii”), a w niej:

```css
.topbar-wnetrze { flex-wrap: wrap; }
.topbar-akcje   { flex-wrap: wrap; min-width: 0; }
@media (max-width: 63.99rem) { .topbar-szukaj, .topbar-szukaj-kolumna { display: none; } }
```

Pole wyszukiwania znika z belki poniżej 64rem, bo na telefonie „Szukaj” jest
pozycją dolnego paska i nie musi być w dwóch miejscach naraz.

**Jak sprawdzone.** Zmierzone niezależnie, w Chromium, **na prawdziwej
belce w `03-szablony/tablica.html`**, a nie w galerii:

| Warunki | `.topbar-akcje` | belka | dokument | okno | przewijanie |
|---|---|---|---|---|---|
| 320 px, tekst 100 % | 207 px | 320 px | 320 px | 320 px | brak |
| 320 px, tekst 150 % | 273 px | 320 px | 320 px | 320 px | brak |
| 390 px, tekst 100 % | 207 px | 390 px | 390 px | 390 px | brak |
| 768 px, tekst 100 % | 207 px | 768 px | 768 px | 768 px | brak |

Poprzednio te same warunki dawały 363 px i 613 px.

### 3.2 Makiety nie mają już własnego CSS — i to jest miara systemu

Dwadzieścia dwie reguły z bloków „PROPOZYCJA DO SYSTEMU” są w `komponenty.css`
jako sekcja 16, każda z pomiarem przy sobie. **Z pięciu makiet w
`03-szablony/` zniknęły wszystkie bloki `<style>`.** Policzone:

```bash
for f in 03-szablony/*.html 04-strona-www/*.html; do grep -c '<style' "$f"; done   # osiem zer
```

Osiem makiet, zero własnych reguł, i oba automaty dalej zielone.
To jest jedyna miara, która odróżnia system projektowy od zbioru ładnych
stron: **makieta, która nie potrzebuje własnego CSS, przepisuje się na Blade
jeden do jednego.** Kit v2 tej miary nie przeszedł i to jest jego czwarty
powód porażki, wypisany w `DLACZEGO-NIE-WYSTARCZYL.md`.

Przy okazji sekcji 16 zapadła **D-112**: `.choice-grid` nie ma już zapytania
medialnego, tylko `repeat(auto-fit, minmax(min(13rem, 100%), 1fr))` — grupa
wyboru pyta o miejsce, które ma, a nie o szerokość okna.

### 3.3 Co z tego zostaje jako trwałe ostrzeżenie

**Automat pilnował strony, a nie komponentu, i dlatego przez chwilę był
zielony przy niesprawnej belce.** `sprawdz-uklad.mjs` mierzy
`document.scrollWidth`, więc podgląd zamknięty w kontenerze z
`overflow-x: auto` (a tak galeria pokazuje szerokie komponenty i słusznie)
**nigdy nie zgłosi przepełnienia samego komponentu**. Zielony wynik znaczy
„żadna strona się nie przewija”, nie „każdy komponent mieści się na 320 px”.

Wniosek dla wdrożenia: komponent sprawdza się na stronie, która go używa bez
otoczki. Wpisane jako stała uwaga w `LISTA-KONTROLNA-A11Y.md`, sekcja A.

I drugi, dla widoków: **widoku Blade nie da się owinąć w poziomo przewijane
okno**, bo twarde ograniczenie nr 7 zakazuje przewijania strony w poziomie,
a jedynym świadomym wyjątkiem jest karuzela zdjęć. Jedyne miejsce, w którym
własne przewijane okno jest na miejscu, to szeroka tabela w dokumencie
prawnym — arkusz ma na to `.tabela-otoczka`.

---

## 4. Kolejność wdrożenia — pięć etapów, każdy do wypuszczenia i cofnięcia osobno

Zasada: **każdy etap kończy się stanem, który da się wypuścić i cofnąć jednym
`git revert`, bez czekania na następny.** Etap 1 poprawia serwis sam w sobie,
nawet gdyby reszta nigdy nie powstała.

### Etap 1 — Tokeny i znak. Nie dotyka ani jednego widoku

**Co wchodzi:** podmiana `resources/css/tokens.css` (sekwencja w
`ZMIANY-TOKENOW.md` §7, kroki 0–2 i 4), zamiana nazwy
`--container-strona-z-szyna` na `--container-strona-szeroka` w 6 miejscach,
podmiana pliku znaku na `06-marka/znak/kuking-znak-wyciety.svg`.

**Czym poprawia serwis sam w sobie — trzy rzeczy, każda widoczna:**

1. **Skala tekstu 90 % i 140 % zaczyna działać.** Dzisiejszy arkusz zna
   `data-text-scale` o wartościach `112`, `125` i `150` (wiersze 249–251).
   D-111 i `STAN_WDROZENIA_KITU.md` mówią, że baza pozwala na **90–140**.
   Jeżeli oba są prawdziwe, człowiek, który wybrał 140 %, dostaje dziś 100 %,
   a automat dostępności mierzy wariant „tekst 140 %”, który nie istnieje.
   **Do sprawdzenia w repozytorium:**
   `grep -rn 'text_scale' app/ database/migrations/ resources/views/components/layout.blade.php`.
   Jeżeli to się potwierdzi, sam Etap 1 naprawia ustawienie dostępności,
   które nie działa, i jednocześnie unieważnia część dotychczasowych pomiarów.
2. **`.prose { max-width: 38rem }` przestaje być wartością z ręki** — dostaje
   nazwę `--container-czytanie`. Punkt 8 twardych ograniczeń z
   `CZYTAJ-MNIE.md` mówi „żadnej wartości na sztywno”; dziś jest złamany
   w `app.css:1383`.
3. **Znak przestaje być białą plamą na kolorowym tle.**
   `STAN_WDROZENIA_KITU.md`, etap C, zapisuje to jako świadome ustępstwo:
   „znak rysuje garnek kolorem bieżącym, a uśmiech kolorem powierzchni — na
   tle marki wychodzi biała plama bez uśmiechu”. Nowy plik wycina uśmiech
   z kształtu maską, więc znak działa na każdym tle. Szczegóły: `06-marka/ZNAK.md`.

**Czego NIE wchodzi:** `komponenty.css`. Wpięcie go tutaj zmieniłoby wygląd
50 klas naraz i zabrałoby Etapowi 1 jego jedyną zaletę — że jest niewidoczny.

**Sprawdzenie przed wypuszczeniem:** `ZMIANY-TOKENOW.md` §8, wiersze 1–10.
**Cofnięcie:** jeden commit, dwa pliki.

### Etap 2 — Warstwa komponentów, bez zmiany znaczników

**Co wchodzi:** `komponenty.css` wpięty **po** `app.css`, w całości —
razem z sekcją 16, bo te 22 reguły są już w arkuszu (sekcja 3). Żaden widok
Blade nie jest zmieniany.

**Co się dzieje:** **59 wspólnych nazw klas** dostaje nowy wygląd. Przyciski
zyskują `:active` (jeden piksel w dół), pola formularza — obwódkę reagującą na
najechanie, karty — mocniejszy cień przy najechaniu, chipy — 48 px, bieżąca
pozycja dolnego paska — kreskę 3 px u góry, wiersz nieprzeczytanego
powiadomienia — pionową kreskę przy krawędzi. Dwie ostatnie to nie kosmetyka:
to jest reguła „kolor nigdy nie jest jedynym sygnałem”.

**Czego to jeszcze nie robi:** 15 nowych nazw klas z sekcji 16 nie ma jeszcze
gdzie zadziałać, bo żaden widok ich nie używa. Wchodzą w Etapach 4 i 6.

**Dlaczego to jest osobny etap:** bo to jest jedyna zmiana w całym wdrożeniu,
którą da się cofnąć **jedną linijką** (usunięcie importu), a która dotyka
każdego ekranu. Jeśli coś się rozjedzie, wiadomo dokładnie od czego.

**Sprawdzenie:** `LISTA-KONTROLNA-A11Y.md` w całości, na 15 ekranach.
Do tego różnica wizualna: zrzuty przed i po (`scripts/zrzuty-wygladu.mjs`,
60 zrzutów), oglądane parami.

### Etap 3 — Formularze

**Co wchodzi:** `x-field.blade` (nowy parametr `szerokosc`, `wymagane`
zamiast gwiazdki, podpowiedź nad polem, `id` na opakowaniu),
`x-error-summary.blade` (linki `#id` działające przez `:target`),
wybór zdjęcia przez `<label for>` (D-107), `x-wybor-wygladu.blade`,
`x-empty-state.blade`.

**Ekrany:** 03, 04, 12, 14 i formularze ustawień. **Nie** ekran 13 — kreator
przepisu ma własny etap.

**Dlaczego tu, a nie później:** bo to jedyna duża grupa zmian, która nie
wymaga ani jednej decyzji właściciela i nie dotyka bazy danych.

**Uwaga do D-107, której nie ma w decyzji:** po schowaniu natywnego
`<input type="file">` **znika też jedyna informacja zwrotna, jaką dawał** —
napis z nazwą wybranego pliku. Bez JavaScriptu, po wybraniu zdjęcia, na
ekranie nie zmienia się nic aż do wysłania formularza. Dzisiejszy
`ekran-dodawania.css` trzyma input widocznym właśnie dlatego (wiersze 30–36:
„Prawdziwy `<input type="file">` zostaje więc w pełni widoczny i klikalny
wewnątrz tego obszaru”). To jest wymiana jednej wady na drugą, nie czysta
poprawa — i trzeba to powiedzieć właścicielowi. Patrz P-6.

### Etap 4 — Szkielet strony

**Co wchodzi:** `x-layout.blade` (trzecia kolumna zawsze, nowe nazwy klas
belki i stopki, sprite ikon, `.tylko-dla-czytnika`), `x-szyna-startowa.blade`,
`x-ikona.blade`.

**Ekrany:** wszystkie 15 naraz. To jest najbardziej ryzykowny etap i dlatego
stoi po Etapie 2 — kiedy wygląd komponentów jest już obejrzany i wiadomo, że
to, co się rozjeżdża, rozjeżdża się od układu, a nie od koloru.

### Etap 5 — Karta wpisu i karta przepisu

**Co wchodzi:** `x-post-card.blade` (nazwa klas, wariant zwarty, karta bez
zdjęcia, cicha plakietka), `x-photo.blade`, `x-recipe-card.blade`,
`x-konto-przykladowe.blade`, `x-avatar.blade`, `x-cooked-card.blade`.

**Ekrany:** 02, 05, 06, 07, 08, 09, 10.

**Blokada:** ten etap **nie może się zacząć** bez odpowiedzi na P-1
(tytuł wpisu), P-2 („Zapisz” na wpisie) i P-3 (treść plakietki).

### Etap 6 — Kreator przepisu na trzy strony

**Co wchodzi:** D-108 w całości. Trzy adresy, trzy widoki, zapis szkicu na
serwerze, wersja jednostronicowa jako droga awaryjna.

**Ekran:** 13. **Blokada:** P-4 (kolumna ilości) i P-5 (adresy).

**Dlaczego na końcu:** bo to jedyny etap, który zmienia adresy stron.
Wypuszczony jako ostatni, ma za sobą działający `x-field` z Etapu 3
i działający szkielet z Etapu 4 — czyli buduje na sprawdzonym, a nie na
trzech niesprawdzonych rzeczach naraz.

---

## 5. Czego nie da się zrobić bez decyzji właściciela

Każde pytanie sformułowane tak, żeby dało się odpowiedzieć „tak” albo „nie”.
Przy każdym: co się dzieje przy każdej z odpowiedzi.

### P-1. Czy wpis (danie) dostaje w bazie pole **tytuł**?

`komponenty.css` (wiersz 536) wprowadza `.karta-tytul` i opisuje go wprost
jako **nowy element**: „Tytuł wpisu — nowy element. Dziś karta ma tylko treść,
przez co nazwa autora jest największym napisem w karcie”. D-110 daje mu
24 px i wagę 800, czyli szczyt hierarchii. Makieta `03-szablony/tablica.html`
używa go trzy razy („Pierogi ruskie na niedzielę”, „Zakwas po dziesięciu
dniach”, „Pizza z blachy, jak co piątek”).

**Ale wpis w tym serwisie to „zdjęcie + kilka słów”** (`BRAND_EXTENDED.md`
§1.1), a formularz dodania zdjęcia ma pola „Napisz kilka słów” i „Kto to
widzi” — nie ma pola na tytuł (`UX_50_PLUS.md`, „Dodanie wpisu”).

- **TAK** → migracja (`posts.title`), nowe pole w formularzu dodawania wpisu,
  decyzja co zrobić z wpisami istniejącymi. **+6–10 h i jedna migracja.**
- **NIE** → `.karta-tytul` zostaje wyłącznie dla wpisów będących przepisami,
  a zwykły wpis pokazuje `.karta-tresc` jako pierwszy element. Wtedy hierarchia
  z D-110 („tu siada oko”) działa na przepisach, a na daniach nie — i trzeba
  to opisać, żeby nikt nie „naprawiał” tego później.

**To jest najważniejsze pytanie w całym dokumencie**, bo od niego zależy
wygląd najczęściej powtarzanego elementu w serwisie.

### P-2. Czy do Zeszytu wolno zapisać **wpis**, a nie tylko przepis?

Makieta `03-szablony/tablica.html` (wiersze 289 i 310) daje każdej karcie
wpisu przycisk **„Zapisz”**. `STAN_WDROZENIA_KITU.md` pisze o tym samym
przycisku z kitu v2:

> „Zapisz” na wpisie to **nowa funkcja produktowa**, nie brakujący przycisk:
> zeszyt przyjmuje dziś wyłącznie przepisy. Wymaga decyzji właściciela.

Czyli makieta rysuje po raz drugi funkcję, o której już raz powiedziano, że
jej nie ma. To jest dokładnie powód nr 2, dla którego kit v2 nie wystarczył.

- **TAK** → migracja (polimorficzna relacja Zeszytu), zmiany w `/zeszyt`,
  w eksporcie danych i w tekście pustego stanu Zeszytu. **+8–12 h.**
- **NIE** → przycisk znika z karty wpisu, a zostaje na karcie przepisu.
  Wtedy karta wpisu ma w stopce „Napisz komentarz” i liczbę komentarzy —
  i **trzeba zdecydować, gdzie podziało się „Ugotowałem” i „Zgłoś”**, które
  karta ma dziś (`STAN_WDROZENIA_KITU.md`: „Ugotowałem · Komentarze · Zgłoś”),
  a których w makiecie nie ma. Zniknięcie „Zgłoś” z karty jest zmianą
  w drodze zgłaszania treści, więc nie jest kosmetyczne.

### P-3. Czy plakietka konta przykładowego może mówić mniej, niż mówi dziś?

Dziś, wedle `CZYTAJ-MNIE.md`, brzmi: **„Konto przykładowe — nie prawdziwa
osoba”**. W tej paczce występuje w **pięciu różnych postaciach**:

| Gdzie | Napis |
|---|---|
| serwis dziś (`CZYTAJ-MNIE.md`, problem nr 2) | `Konto przykładowe — nie prawdziwa osoba` |
| D-103 (`DECYZJE.md`) | `konto przykładowe` |
| `03-szablony/tablica.html`, `przepis.html` | `konto przykładowe` |
| `04-strona-www/powitalna.html` | `przykład` |
| `04-strona-www/przepis-publiczny.html` | `przepis przykładowy` |

D-103 twierdzi, że „treść zostaje ta sama”. **Nie zostaje**: znika człon
„— nie prawdziwa osoba”, czyli dokładnie to, co plakietka miała powiedzieć.
Do tego `BRAND_EXTENDED.md` §3 mówi: „Nazwa funkcji jest jedna i nie ma
synonimów”, a tu są cztery.

- **TAK, wolno skrócić** → jedna postać dla całego serwisu, do wpisania
  w słowniku `BRAND_EXTENDED.md` §1.1, i wtedy pełne zdanie musi stać gdzieś
  raz (na profilu konta przykładowego, plakietka głośna).
- **NIE** → plakietka cicha nosi pełne zdanie, a to jest 33 znaki w wierszu
  metadanych przy 15 px — do sprawdzenia przy 320 px i skali 140 %.

W obu przypadkach **wybór jednego brzmienia jest obowiązkowy**, bo dziś jest
ich pięć.

### P-4. Czy składnik ma **osobne pole na ilość**?

Makieta `03-szablony/dodaj-przepis-krok-2.html` ma dwa pola w wierszu: „Ile”
i „Składnik”, a jej pomoc mówi: „Ilość i składnik są w osobnych polach, żeby
dało się je potem przeliczyć na inną liczbę porcji”.

`STAN_WDROZENIA_KITU.md`, etap C, zapisuje przeciwnie:
**„składniki z kolumną ilości — nie i nie będzie — D-017”.**

Do tego `04-strona-www/przepis-publiczny.html` grupuje składniki pod
nagłówkami („Ciasto”, „Farsz”, „Do podania”), czyli wprowadza trzeci wymiar,
którego dziś nie ma.

- **TAK** → D-017 zostaje odwrócona, migracja (`ingredients.amount`,
  `ingredients.group`), przeliczanie porcji jako nowa funkcja.
  **+10–16 h i dwie kolumny w bazie.**
- **NIE** → krok 2 wraca do jednego pola na wiersz, a pomoc przy nim mówi to,
  co mówi `COPY_STYLE.md` §6: „Pisz tak, jak mówisz: »szklanka mąki«, »2 duże
  cebule«, »mleko — ile weźmie«. Nie musisz nic przeliczać na gramy.”
  Zdanie o przeliczaniu na porcje znika, bo obiecuje funkcję, której nie ma.

### P-5. Czy formularz przepisu dostaje **trzy nowe adresy**?

D-108 proponuje `/dodaj/przepis`, `/dodaj/przepis/skladniki`,
`/dodaj/przepis/kroki` plus `/dodaj/przepis/wszystko`. Dziś istnieje
`/dodaj/przepis` i `/dodaj/przepis/jedna-strona`
(`STAN_WDROZENIA_KITU.md`, menu mobilne).

- **TAK** → trzy trasy, zapis szkicu na serwerze po każdym kroku,
  przekierowanie ze starego adresu jednostronicowego
  (`/dodaj/przepis/jedna-strona` → `/dodaj/przepis/wszystko`), sprawdzenie
  podświetlenia „Dodaj” w nawigacji na wszystkich czterech adresach — to już
  raz było zepsute i zostało naprawione w etapie D.
- **NIE** → zostaje jedna strona, a problem nr 10 z `CZYTAJ-MNIE.md`
  (nagłówek „Krok 1 z 3” nad formularzem, który pokazuje wszystko naraz)
  trzeba rozwiązać inaczej — na przykład usuwając nagłówek, bo dziś kłamie.

### P-6. Czy wolno schować natywny wybór pliku, tracąc potwierdzenie wyboru?

D-107 chowa `<input type="file">` i klikalna zostaje etykieta. Zysk: znika
angielskie „Choose File / No file chosen”. Strata: **bez JavaScriptu po
wybraniu pliku na ekranie nie zmienia się nic** — nazwa pliku była jedynym
potwierdzeniem, że wybór się udał.

- **TAK** → trzeba dołożyć potwierdzenie inną drogą: po wysłaniu formularza
  serwer pokazuje miniaturę i napis „Zmień zdjęcie” (to już działa,
  `STAN_WDROZENIA_KITU.md`, etap D). Między kliknięciem a wysłaniem
  człowiek nie dostaje nic.
- **NIE** → zostaje dzisiejsze rozwiązanie z widocznym inputem i angielskim
  napisem w środku, czyli problem nr 7 z `CZYTAJ-MNIE.md` zostaje otwarty.

### P-7. „Zapisz” czy „Zapisuję”?

`BRAND_EXTENDED.md` §1.2 mówi, że akcja zapisania do Zeszytu nazywa się
**„Zapisz”**. `COPY_STYLE.md` §3 i §6 dwa razy używają formy **„Zapisuję”**
(rejestr „ciepły bez żartu” oraz tekst pustego Zeszytu: „kliknij przy nim
»Zapisuję«”). Ta paczka powtarza obie: `03-szablony/przepis.html` ma przycisk
„Zapisuję”, `03-szablony/tablica.html` i galeria mają „Zapisz”, a pusty stan
Zeszytu w galerii odsyła do „Zapisuję”.

Sprzeczność jest w dokumentach właściciela, nie w tej paczce — ale ktoś musi
ją przeciąć, bo `BRAND_EXTENDED.md` §3 zabrania synonimów wprost.

- **TAK dla „Zapisuję”** → poprawka w `BRAND_EXTENDED.md` §1.2 i w każdym
  przycisku zapisu.
- **NIE (czyli „Zapisz”)** → poprawka w `COPY_STYLE.md` §3 i §6, w tekście
  pustego Zeszytu i w makiecie przepisu.

### P-8. Kto jest gospodarzem, którego imię stoi pod e-mailami?

To jest `DECISIONS.md` D-012, wciąż otwarte (`COPY_STYLE.md` §8, „Zostały”).
Blokuje jedno zdanie na stronie powitalnej — patrz sekcja 8, wiersz
oznaczony **NIEUDOWODNIONE**.

---

## 6. Ryzyka i sposób sprawdzenia, czy się zmaterializowały

| # | Ryzyko | Jak sprawdzić, czy się zdarzyło |
|---|---|---|
| **R-1** | **Podmiana `tokens.css` bez wpięcia `komponenty.css`** zdejmuje 25 selektorów naraz (przyciski, pola, karty, puste stany, podsumowanie błędów, kroki kreatora). | Otwórz `/login`. Przycisk ma tło marki i 48 px wysokości, pole ma obwódkę? Jeśli widzisz goły `<button>` — zdarzyło się. Automat: `node scripts/dostepnosc.mjs` zgłosi lawinę naruszeń kontrastu na wszystkich ekranach naraz, a nie na jednym. |
| **R-2** | **Dwie warstwy komponentów naraz.** `komponenty.css` opisuje 59 nazw, które `app.css` już ma, i nadpisuje tylko te właściwości, które sam ustawia. Reszta zostaje ze starej reguły. Powstaje wygląd, którego nie zaprojektował nikt. | Dla każdej z 59 nazw: w narzędziach przeglądarki, zakładka „Computed”, sprawdź, czy któraś właściwość przychodzi z `app.css`, a któraś z `komponenty.css`. Szybciej: `grep -n '^\.btn\b' resources/css/app.css resources/css/komponenty.css` dla każdej z 59 nazw — jeśli obie odpowiadają, ta klasa jest w stanie mieszanym. |
| **R-3** | **Automat pilnuje strony, nie komponentu.** `sprawdz-uklad.mjs` mierzy `document.scrollWidth`, więc komponent szerszy niż okno **nie zgłosi się**, jeśli strona zamknęła go w kontenerze z `overflow-x: auto`. Tak się już raz stało: belka górna nie mieściła się na 320 px (363 px na `.topbar-akcje`, 613 px na całej belce), automat zgłosił 12 problemów w galerii, a potem zzieleniał, kiedy galeria owinęła podgląd w przewijane okno — **zanim przyczyna została naprawiona**. | Nie ufaj samemu kolorowi wyniku. Zmierz komponent na stronie, która go używa **bez otoczki**: `03-szablony/tablica.html` przy 320 px i skali 150 %. Dziś: `.topbar-akcje` 273 px, dokument 320 px, brak przewijania. W serwisie: `node scripts/dostepnosc.mjs`, pomiar przewijania przy 320 px. |
| **R-4** | **Skala tekstu 140 % nie działa**, więc wszystkie dotychczasowe pomiary „przy 140 %” mierzyły 100 %. | W przeglądarce na `/home` ustaw `data-text-scale="140"` na `<html>` i zmierz `getComputedStyle(document.body).fontSize`. Ma wyjść **25.2 px**. Jeśli wychodzi 18 px — zdarzyło się, i to od dawna. |
| **R-5** | **Trzecia kolumna zawsze (D-102) zabiera 352 px na ekranach, które nie mają czym jej wypełnić.** Przy oknie 1280 px kolumna czytania przestaje być na środku. | Otwórz `/powiadomienia` i `/ustawienia/czytelnosc` przy 1280 i 1440 px. Zmierz odległość lewej krawędzi kolumny czytania od lewej krawędzi okna na obu — ma być ta sama liczba co na `/home`. Jeśli pusta kolumna wygląda na usterkę, a nie na margines — to jest sygnał do rozmowy z właścicielem, nie do cichego wyłączenia. |
| **R-6** | **Zmiana nazw klas gubi regułę, która została w `app.css`.** Przykład: `.post-card-menu` (`app.css:2131`) i `.post-card-report` (`:576`) zostają przy starej nazwie, kiedy karta staje się `.karta-wpisu`. | `grep -rn 'post-card' resources/` po Etapie 5 — ma dać zero. To samo dla `topbar-inner` (7 wystąpień), `site-footer` (13), `skip-link` (2), `visually-hidden` (1), `pole-zdjecia` (8), `stack` (4). |
| **R-7** | **Wybór pliku przestaje działać bez JavaScriptu**, jeśli input zostanie schowany przez `display: none` zamiast przez `position: absolute; opacity: 0`. `display: none` wyjmuje pole z formularza i plik nigdy nie dojedzie. | Playwright z `javaScriptEnabled: false`: wejdź na `/dodaj/zdjecie`, ustaw plik przez `setInputFiles`, wyślij formularz, sprawdź, czy wpis ma zdjęcie. To jest ten sam sposób, którym zmierzono menu w etapie D. |
| **R-8** | **Makiety rysują funkcję, której produkt nie ma** (P-1, P-2, P-4). Programista wdraża wygląd i odkrywa brak kolumny w bazie w połowie etapu. | Przed Etapem 5 i 6: dla każdej liczby i każdego pola w makiecie zadaj pytanie „z czego to liczę?”. Konkretnie sprawdź w repozytorium: `posts.title`, polimorficzność Zeszytu, `ingredients.amount`, `recipes.difficulty`. Cztery zapytania, kwadrans. |
| **R-9** | **Wykrzyknik, emoji albo słowo zakazane wchodzi tylnymi drzwiami** przy pisaniu nowych tekstów. | `node 07-wdrozenie/sprawdz-paczke.mjs` — sprawdza tekst po usunięciu znaczników, więc `--container-content` przechodzi, a napis „content” nie. Dziś: 60 plików, **0 błędów twardych**; uwagi wyłącznie w dokumentacji cytującej listę zakazaną, w tym w tym pliku. |
| **R-10** | **Podgląd mierzy co innego niż przeglądarka.** `podglad/zbuduj-podglad.mjs` usuwa `@import "tailwindcss"`, a razem z nim reset, który Tailwind wnosi. Bez `box-sizing: border-box` każda strona przewija się w poziomie o sumę wcięć. | To już się zdarzyło i zostało naprawione: `sprawdz-uklad.mjs` przy pierwszym uruchomieniu pokazał **118 problemów**, z których po dodaniu emulacji resetu zostało **7**. Sposób na przyszłość: po każdej zmianie w `zbuduj-podglad.mjs` uruchom `sprawdz-uklad.mjs` i porównaj liczbę problemów — skok o kilkadziesiąt naraz znaczy, że zepsuł się podgląd, nie makiety. |

---

## 7. Czego ten projekt wymaga poza kodem

### 7.1 Prawdziwe zdjęcia w danych demonstracyjnych — **warunek, nie ozdoba**

D-105 mówi to wprost: ten projekt stoi na zdjęciach, a karta bez zdjęcia to
karta bez powodu, żeby ją zobaczyć. Dziś w bazie demonstracyjnej **każde
„zdjęcie” to znak marki na beżowym tle** (`CZYTAJ-MNIE.md`, „Uwaga
o zdjęciach”), więc każda ocena strumienia jest oceną czegoś innego niż to,
co zobaczy człowiek.

**Co konkretnie trzeba:**

- co najmniej **15 zdjęć potraw** w proporcji 4:3, minimum 720×540 px
  (kolumna treści ma 720 px), plus 6 zdjęć w 16:9 dla wariantu zwartego;
- co najmniej **5 awatarów** 1:1;
- jedno **zdjęcie kartki z przepisem pisanej ręcznie** — makieta kroku 3
  obiecuje „Jeśli masz przepis zapisany ręcznie — zrób mu zdjęcie”,
  a `03-szablony/przepis.html` pokazuje sekcję „Skąd ten przepis”; bez takiego
  zdjęcia nie da się sprawdzić, jak ta sekcja wygląda naprawdę;
- **prawa do nich.** To nie jest formalność: zdjęcia demonstracyjne trafiają
  do zrzutów, do materiałów prasowych i do sklepu PWA.

Paczka ma dziś 8 plików w `podglad/zdjecia/` (5 potraw, 3 awatary) — to
wystarcza na makiety, nie wystarcza na zasianie bazy.

**Kto to robi:** właściciel albo ktoś, kto gotuje. Nie programista.
**Kiedy:** przed Etapem 5, bo bez tego nie da się ocenić karty wpisu.

### 7.2 Test z ludźmi — 13 osób

`UX_50_PLUS.md` i `A11Y_CHECKLIST.md` §D: 5 osób 50–59, 5 osób 60–69,
3 osoby 70+, na Androidzie, iPhonie i komputerze. Automaty tego nie zastąpią.

Trzy rzeczy w tej paczce **czekają na ten test i nie da się ich rozstrzygnąć
inaczej**:

1. **„kuKINGi na dziś”** — `COPY_STYLE.md` §5 ma zastrzeżenie: forma
   `kuKINGi` dla rzeczownika osobowego jest w polszczyźnie deprecjatywna,
   a rozumowanie, że tu chodzi o rzeczy, jest „zza biurka”. Pytanie do
   testu jest gotowe: **„o czym jest ta sekcja?”**. Alternatywy gotowe:
   „Dziś u kuKINGów”, „Co się dziś gotuje”.
2. **„półka” kontra „rozdział”** w Zeszycie — `BRAND_EXTENDED.md` §1.1,
   oznaczone `[do weryfikacji]`.
3. **`aria-label="kuking"` na zapisie `kuKING`** — `COPY_STYLE.md` §2 mówi
   `[do potwierdzenia testem NVDA i VoiceOver]`, bo część czytników literuje
   wersaliki w środku wyrazu. Makiety już tego używają
   (`03-szablony/tablica.html`, wiersz 361). To akurat sprawdza się w pół
   godziny na NVDA i VoiceOverze, bez zwoływania trzynastu osób.

### 7.3 Font

`--font-sans` zaczyna się od `"Inter Variable"` wgranego lokalnie.
`DESIGN_SYSTEM.md` §2.2 i `DECYZJE.md` zostawiają otwartą opcję
**Atkinson Hyperlegible** jako trybu zwiększonej czytelności — do testu
z ludźmi po becie, nie teraz. Nic tu nie trzeba robić przed wdrożeniem,
ale trzeba wiedzieć, że plik fontu musi być w repozytorium; makiety
otwierane z dysku pokazują font systemowy i **to nie jest błąd makiety**.

### 7.4 Decyzja o gospodarzu (P-8)

Bez imienia gospodarza jedno zdanie na stronie powitalnej jest nieprawdziwe
(sekcja 8). To nie jest praca programistyczna — to jest jedna decyzja.

---

## 8. Wszystkie teksty widoczne dla użytkownika, dosłownie, z dowodem prawdziwości

Wymóg właściciela: *„każdy napis w interfejsie cytujemy dosłownie obok kodu,
który dowodzi, że zdanie jest prawdziwe”.*

**Zakres.** Paczka proponuje razem około **1 175 napisów** w dziewięciu
plikach HTML. Dzielą się na trzy grupy i tylko pierwsza jest obietnicą
produktu:

| Grupa | Ile | Co to jest |
|---|---|---|
| **teksty interfejsu** — obietnice produktu | ~120 | etykiety, nagłówki, pomoc, puste stany, komunikaty. **Cytuję wszystkie, poniżej.** |
| **treść demonstracyjna** — dane, nie interfejs | ~480 | nazwy przepisów, składniki, kroki, komentarze, imiona. Nie są obietnicą produktu; są przykładem treści, którą doda człowiek. Ich prawdziwość zabezpiecza plakietka „konto przykładowe” (P-3) i zdanie „To są przykłady. Prawdziwe dania i przepisy dodają tu ludzie, a Kuking jest przed startem.” |
| **dokumentacja galerii** | ~575 | `02-komponenty/galeria.html` to katalog komponentów dla programisty, nie ekran serwisu. Jej opisy nie trafiają do produktu. |

Kolumna „co dowodzi, że jest prawdziwe” podaje mechanizm albo plik. Gdzie
dowodu nie ma, wiersz jest oznaczony **NIEUDOWODNIONE** i wraca do sekcji 5.

### 8.1 Nawigacja i szkielet (na każdym ekranie)

| Napis | Skąd | Co dowodzi, że jest prawdziwy |
|---|---|---|
| `Przejdź do treści` | `tokens.css`, `.tylko-dla-czytnika:focus-visible` | Link istnieje w drzewie i wraca na ekran po fokusie — reguła w `tokens.css` wierszach 389–396. Sprawdzalne Tabem: `LISTA-KONTROLNA-A11Y.md`, K-1. |
| `Start` · `Szukaj` · `Dodaj` · `Zeszyt` · `Profil` | `UX_50_PLUS.md`, „Nawigacja mobile” | Pięć pozycji, każda z podpisem — `komponenty.css` `.bottom-nav-item`. Nazwy z `BRAND_EXTENDED.md` §1.1 (Start, Szukaj, Zeszyt). |
| `Powiadomienia` | `BRAND_EXTENDED.md` §1.1 | Pełny podpis przy ikonie dzwonka; wdrożone w etapie D („świadomie nie” dla samej ikony). |
| `Moje` · `Ustawienia` · `Profil` | `BRAND_EXTENDED.md` §1.1 | Słownik marki. |
| `KuKing.pl` (logotyp) | `COPY_STYLE.md` §2 | Zapis logotypowy z wersalikiem w środku, dopuszczony **wyłącznie w logotypie**. Kod: `.wordmark` + `.wordmark-koncowka`. |
| `Kuking — gotujemy po swojemu.` | `BRAND.md`, drugi claim | Cytat dosłowny z dokumentu marki. |
| `O Kuking` · `Pomoc` · `Zasady` · `Regulamin` · `Prywatność` · `Zgłoś nielegalną treść` | stopka makiet | Każdy z tych adresów istnieje albo jest prawnie wymagany (`inwentarz-ekranow.md`: regulamin, zasady, zgłoszenie DSA art. 16). **Do sprawdzenia w repozytorium**, czy wszystkie sześć odpowiada realnym trasom. |
| `Włącz ciemny wygląd` | przełącznik w stopce | Przełącznik istnieje i działa bez JavaScriptu (`ThemeController`, `WyborMotywuTest`). |
| `Motyw zmienia się tylko wtedy, kiedy sam go zmienisz.` | `04-strona-www/*` | **Dowód jest w arkuszu**: `01-fundamenty/tokens.css` nie zawiera ani jednej reguły `@media (prefers-color-scheme: …)` i ustawia `color-scheme: light` jawnie (wiersze 271–276). Pilnuje tego `tests/Feature/WyborMotywuTest.php`, który sprawdza sam arkusz. To jest najlepiej udowodnione zdanie w całej paczce. |
| `Jasny` · `Ciemny` (wybór motywu) | stopka | Dwie wartości `data-theme`, nic więcej. |
| `Kuking.pl — polski serwis domowego gotowania. Strona jest w budowie.` | stopka stron www | Prawdziwe: zamknięta beta (`CZYTAJ-MNIE.md`). |

### 8.2 Tablica (ekran 08)

| Napis | Co dowodzi |
|---|---|
| `Dobry wieczór, Basia. Pokaż, co dziś wyszło.` | `COPY_STYLE.md` §6, „pytanie dnia, wieczór”, dosłownie. Wymaga imienia z konta — jest. |
| `Dodaj zdjęcie tego, co ugotowałeś` | `COPY_STYLE.md` §6, „przycisk główny”, dosłownie. **Uwaga:** 33 znaki, a `BRAND_EXTENDED.md` §3 test 4 mówi „maks. 2 słowa, maks. 16 znaków”. Sprzeczność w dokumentach właściciela; historycznie wygrywa `COPY_STYLE.md`. |
| `Nie musi być ładne — ma być prawdziwe.` | `COPY_STYLE.md` §6, pusty strumień, dosłownie. |
| `Obserwowani` / `Świeżo z Kuking` | `inwentarz-ekranow.md` (ekran 02 nazywa się „Świeżo z Kuking”). Zakładki są linkami, nie skryptem. |
| `publicznie` | Widoczność wpisu. Prawdziwe, jeśli wpis naprawdę jest publiczny — pole widoczności istnieje (trzy karty, etap D). |
| `konto przykładowe` | **DO ROZSTRZYGNIĘCIA — P-3.** |
| `Napisz komentarz` | `BRAND_EXTENDED.md` §1.2, dopuszczona forma dłuższa. |
| `Zapisz` (na karcie wpisu) | **NIEUDOWODNIONE — P-2.** Zeszyt przyjmuje dziś wyłącznie przepisy. |
| `4 komentarze` / `2 komentarze` / `1 komentarz` | Liczba policzalna (`comments_count`). Odmiana przez `trans_choice`. |
| `Pokaż więcej wpisów` | `BRAND_EXTENDED.md` §1.2 („Pokaż więcej”) + `x-show-more.blade`. Paginacja realna, nie `->limit()`. |
| `kuKINGi na dziś` | `COPY_STYLE.md` §5, dosłownie. **Z zastrzeżeniem do testu z ludźmi** (sekcja 7.2). |
| `Kilka osób i kilka dań, które dziś warto zobaczyć.` | `COPY_STYLE.md` §5, „Podtytuł”, dosłownie. |
| `Obserwuj` / `Zobacz` | `COPY_STYLE.md` §5, „Przycisk osoby” / „Przycisk wpisu”, dosłownie. |
| `Jutro będzie tu ktoś inny.` | `COPY_STYLE.md` §5, „Stopka sekcji”, dosłownie. **Prawdziwe tylko wtedy, gdy tablica naprawdę się codziennie zmienia** — do sprawdzenia w repozytorium, czy `x-kuking-board` losuje/rotuje dziennie. |
| `ostatnio: pierogi ruskie` | Dane z ostatniego wpisu osoby. Policzalne. |
| `Dania` | `BRAND_EXTENDED.md` §1.1: „danie” w interfejsie. |
| `Halina z Mazowsza · 40 minut` | Czas przygotowania z przepisu; pokazywany tylko wtedy, gdy autor go podał (etap C: „kafel »—« nie jest informacją”). |
| `Twój szkic czeka` | Prawdziwe, jeśli szkic istnieje. Warunek: sekcja pokazuje się tylko przy zapisanym szkicu. |
| `Przepis „Pierogi ruskie po babci Zofii”, zapisany wczoraj o 21:10.` | Nazwa i data z rekordu szkicu. |
| `Dokończ przepis` | Link do kroku, na którym szkic stanął. |
| `Nic nie zginie i wrócisz do tego, kiedy zechcesz.` | `COPY_STYLE.md` §6, „zachęta do zapisu szkicu”, druga połowa zdania, dosłownie. Prawdziwe, bo szkic jest zapisany na serwerze (D-108). |
| `Za mały tekst?` / `Możesz go powiększyć na stałe — na wszystkich stronach Kuking.` / `Ustaw rozmiar tekstu` | **Nowa propozycja, poza `COPY_STYLE.md`.** Prawdziwe, bo skala tekstu jest ustawieniem konta, a nie zoomem przeglądarki — pod warunkiem R-4. |

### 8.3 Przepis (ekran 07)

| Napis | Co dowodzi |
|---|---|
| `Przepisy` (okruszek) | Trasa istnieje; okruszki wdrożone w etapie C. |
| `przepis z 1 września 2026` | `datePublished`. |
| `Obserwuj` | `BRAND_EXTENDED.md` §1.2. Liczby obserwujących **nie ma i nie wolno jej dodać** (`AGENTS.md` §12). |
| `Około 90 minut` · `4 porcje` · `Średni` | Trzy kafle danych. **Pole „poziom” — do sprawdzenia w repozytorium**, czy `recipes` ma kolumnę trudności; formularz krok 1 proponuje trzy wartości (Łatwy/Średni/Wymagający). |
| `Składniki` / `Na 4 porcje, czyli około 40 pierogów.` | Nagłówek i podpis. Liczba pierogów jest treścią autora, nie wyliczeniem serwisu. |
| `Ugotowałem` | `BRAND_EXTENDED.md` §1.2, nazwa własna funkcji, wielka litera. Funkcja istnieje. |
| `Zapisuję` | **DO ROZSTRZYGNIĘCIA — P-7** (kontra `Zapisz`). |
| `Gotuję — pokaż kroki na cały ekran` | Tryb gotowania istnieje (`inwentarz-ekranow.md`, „czego na tej liście nie ma”). **Uwaga:** 34 znaki, łamie `BRAND_EXTENDED.md` §3 test 4. Krótsza forma z etapu C to samo `Gotuję`. |
| `Skąd ten przepis` | `COPY_STYLE.md` §6, „sekcja pochodzenia”, dosłownie. |
| `W rodzinie od 1961 roku.` | Pole podane przez autora. |
| `Przygotowanie` | Nagłówek kroków. **Nie są to „kroki z tytułami”**, których zabrania D-017 — tytuł ma sekcja, nie pojedynczy krok. |
| `Komu wyszło` | `COPY_STYLE.md` §6, dosłownie. Rozstrzygnięte przeciw kitowi 7 IX 2026. |
| `Zdjęcia od ludzi, którzy naprawdę to zrobili u siebie.` | `COPY_STYLE.md` §6, dosłownie. Prawdziwe, bo „Ugotowałem” wymaga wykonania, a fałszywe są usuwane (`BRAND_EXTENDED.md` §6, „Fałszywe »Ugotowałem«”). |
| `Ugotowałeś z tego przepisu?` | `COPY_STYLE.md` §6, „nagłówek sekcji”, dosłownie. |
| `Kasia naprawdę chce o tym wiedzieć.` | `COPY_STYLE.md` §6: „{autor} naprawdę chce o tym wiedzieć. Wystarczy jedno kliknięcie.” **Druga połowa jest w makiecie pominięta i dobrze**, bo `BRAND_EXTENDED.md` §2.1 zakazuje „wystarczy jedno kliknięcie” („bo nigdy nie wystarcza”). To jest sprzeczność wewnątrz dokumentów właściciela, rozstrzygnięta tu na rzecz zakazu. |
| `Dodaj swoje Ugotowałem` | Nazwa własna nieodmieniana — zgodnie z `BRAND_EXTENDED.md` §3. |
| `Komentarze` / `Wyślij komentarz` | `BRAND_EXTENDED.md` §1.2. |
| `wymagane` | D-106. Bez gwiazdki, bo czytnik czyta ją jako „gwiazdka”. |
| `Napisz normalnie, po ludzku. Pytanie do autora też jest w porządku.` | **Nowa propozycja, poza `COPY_STYLE.md`.** Nie obiecuje niczego, więc nie ma czego dowodzić. |
| `Zgłoś ten przepis` | `BRAND_EXTENDED.md` §1.2 („Zgłoś”). Droga zgłoszenia istnieje. |

### 8.4 Dodawanie przepisu, kroki 1–3 (ekran 13)

| Napis | Co dowodzi |
|---|---|
| `Krok 1 z 3 — co to jest` / `Krok 2 z 3 — składniki` / `Krok 3 z 3 — kroki i publikacja` | **Prawdziwe dopiero po D-108.** Dziś ten nagłówek stoi nad jedną stroną, która pokazuje wszystko naraz — problem nr 10 z `CZYTAJ-MNIE.md`. Zależy od P-5. |
| `Wymagane są trzy pola — resztę wypełnij, jeśli chcesz.` | D-106, dosłownie. **Prawdziwość jest policzalna i trzeba ją policzyć**: w kroku 1 pole `wymagane` stoi przy „Nazwa przepisu”, w kroku 2 przy „Składniki”, w kroku 3 przy „Kroki”. To są trzy. Jeśli walidacja serwera wymaga czegokolwiek więcej — zdanie kłamie. **Do sprawdzenia w repozytorium**: reguły `required` w `StoreRecipeRequest`. |
| `Tak, jak mówisz o nim w domu.` | Nowa propozycja. Zachęta, nie obietnica. |
| `Dodaj zdjęcie` | `BRAND_EXTENDED.md` §1.2, dosłownie. |
| `Na telefonie kliknij tutaj, a potem wybierz „Galeria” albo „Zrób zdjęcie”.` | `COPY_STYLE.md` §6, dosłownie. **Uwaga redakcyjna:** to samo zdanie ma w paczce trzy różne cudzysłowy — `COPY_STYLE.md` „Galeria", D-107 »Galeria«, makieta „Galeria”. Do ujednolicenia; treść jest ta sama. Prawdziwe, bo `<label for>` naprawdę otwiera wybór pliku (D-107) — z zastrzeżeniem P-6. |
| `Jeśli nie mierzysz czasu — zostaw puste. Nikt tego nie sprawdza.` | Nowa propozycja. Prawdziwe, bo pola czasu są nieobowiązkowe (D-106). |
| `Łatwy` / `Wychodzi za pierwszym razem.` · `Średni` / `Trzeba trochę wprawy przy zlepianiu.` · `Wymagający` / `Zajmuje pół dnia i nie znosi pośpiechu.` | **Uwaga:** opis przy „Średni” mówi o zlepianiu, czyli o pierogach — jest treścią przykładu, nie opisem poziomu. W produkcie musi być ogólny. Do poprawy przed wdrożeniem. |
| `Kto ma widzieć ten przepis?` · `Wszyscy` / `Także osoby bez konta. Przepis może pojawić się w Google.` · `Tylko osoby, które mnie obserwują` / `Zobaczą go tylko one.` · `Tylko ja` / `Twój prywatny zeszyt. Nikt inny tego nie zobaczy.` | `BRAND_EXTENDED.md` §1.3: „Kto to widzi: wszyscy / tylko obserwujący / tylko ja”. Trzy karty, nie lista rozwijana (etap D). **„Przepis może pojawić się w Google” jest prawdziwe** — `04-strona-www/przepis-publiczny.html` pokazuje stronę publiczną z JSON-LD. **„Nikt inny tego nie zobaczy” to obietnica prywatności** i musi ją potwierdzać kod, nie makieta: **do sprawdzenia w repozytorium**, czy zapytania listujące filtrują po widoczności także dla moderatora i w wyszukiwarce. |
| `Dalej — składniki` / `Dalej — kroki` / `Wstecz` | `BRAND_EXTENDED.md` §1.2 („Dalej” / „Wstecz”). |
| `Zapisz szkic` / `Szkic zapisany.` | `BRAND_EXTENDED.md` §1.2 i `COPY_STYLE.md` §6, dosłownie. Kropka, bez wykrzyknika. |
| `Nie teraz` | Wyjście z formularza bez utraty szkicu. |
| `Wolisz wypełnić wszystko na jednej stronie?` / `Otwórz formularz na jednej stronie` | D-108, dosłownie. Prawdziwe, jeśli adres jednostronicowy zostaje — P-5. |
| `Pisz tak, jak mówisz: „szklanka mąki”, „2 duże cebule”, „mleko — ile weźmie”. Nie musisz nic przeliczać na gramy.` | `COPY_STYLE.md` §6, dosłownie. **Stoi w sprzeczności z sąsiednim zdaniem makiety** — patrz wiersz niżej. |
| `Ilość i składnik są w osobnych polach, żeby dało się je potem przeliczyć na inną liczbę porcji.` | **NIEUDOWODNIONE — P-4.** Obiecuje przeliczanie porcji, którego nie ma, i wprowadza kolumnę ilości, którą D-017 odrzuciła. |
| `Nie znasz dokładnej ilości? Napisz „ile weźmie”. To jest pełnoprawna odpowiedź.` | Zgodne z `COPY_STYLE.md` §4. |
| `Pusty wiersz na końcu zostanie pominięty.` | Zachowanie do zaimplementowania; dziś **do sprawdzenia w repozytorium**. |
| `Jeden krok to jedna czynność. Krótkie kroki łatwiej czytać przy garnku.` | `COPY_STYLE.md` §6, dosłownie. |
| `Ten przepis jest… Mój własny / Rodzinny / Moja wersja czyjegoś przepisu` | **Nowa propozycja, poza `COPY_STYLE.md`.** Wymaga kolumny w bazie — **do sprawdzenia w repozytorium**. |
| `Po kim ten przepis` / `po mamie, Halinie` | `COPY_STYLE.md` §6, dosłownie (pole i podpowiedź). |
| `Zostanie podpisany tak: „przepis Kasi, spisany przez Ciebie”.` | **Nowa propozycja.** Prawdziwa tylko wtedy, gdy podpis naprawdę tak wygląda na stronie przepisu. Dziś makieta przepisu pokazuje samo imię autora — **niespójność do usunięcia albo do wdrożenia.** |
| `Historia tego przepisu` / `Skąd go znasz, kiedy się go gotuje, co Ci się z nim wiąże. To zostaje w rodzinie.` | `COPY_STYLE.md` §6, dosłownie. |
| `Skan kartki z przepisem` / `Jeśli masz przepis zapisany ręcznie — zrób mu zdjęcie. Zostanie przy przepisie.` | `COPY_STYLE.md` §6, dosłownie. |
| `Opublikuj przepis` | `BRAND_EXTENDED.md` §1.2 („Opublikuj”). |
| `Po opublikowaniu dalej możesz wszystko poprawić.` | Prawdziwe, jeśli edycja przepisu istnieje — **do sprawdzenia w repozytorium**. |
| `Pomoc do tego kroku` + cztery zdania na krok | Nowa propozycja; treść pomocnicza, bez obietnic. |

### 8.5 Strona powitalna i „O Kuking” (ekran 01 i strona www)

| Napis | Co dowodzi |
|---|---|
| `Pokaż, co dziś ugotowałeś.` | `BRAND.md`, claim główny, dosłownie. |
| `Gotujemy po swojemu.` | `BRAND.md`, drugi claim, dosłownie. |
| `Kuking to twój zeszyt z przepisami i ludzie, którzy naprawdę gotują.` | `BRAND_EXTENDED.md` §7, hasło H2, w formie zdania. |
| `Załóż konto` (w pasku) i `Zostań kuKINGiem — to darmowe` (w bloku głównym) | `COPY_STYLE.md` §6 i §8, dosłownie i we właściwych miejscach. **Było odwrotnie i zostało poprawione:** §8 mówi, że „Zostań kuKINGiem” wchodzi tam, gdzie jest miejsce na kontekst (strona główna, nagłówek `/register`), a w wąskim pasku zostaje krótkie „Załóż konto”. Sprawdzone: gra słowem pada na tej stronie **dokładnie raz** — policzone w tekście widocznym, po usunięciu znaczników i komentarzy |
| `Najpierw się rozejrzę` | `COPY_STYLE.md` §6, dosłownie. |
| `Cztery rzeczy i nic więcej.` / `Kuking robi cztery rzeczy porządnie i nie próbuje robić trzynastej.` | Nowa propozycja. **Policzalne i policzone: pod spodem są cztery bloki.** Prawdziwe. |
| `Pokazujesz, co ugotowałeś` · `Trzymasz przepisy w Zeszycie` · `Mówisz, że ugotowałeś` · `Obserwujesz, kogo chcesz` | Cztery funkcje, wszystkie istnieją (`PRODUCT.md` wg `BRAND_EXTENDED.md` §Źródła). |
| `Widzisz to, co gotują osoby, które obserwujesz. W kolejności, w jakiej to dodali.` | **Twarda obietnica techniczna.** Dowodem jest kod strumienia: sortowanie po dacie, zero rankingu. **Do sprawdzenia w repozytorium**: `FeedController` / zapytanie strumienia — musi być `orderByDesc('created_at')` i nic więcej. |
| `To są przykłady. Prawdziwe dania i przepisy dodają tu ludzie, a Kuking jest przed startem.` | `CZYTAJ-MNIE.md`: „Nie ma ani jednego prawdziwego użytkownika”. Prawdziwe. |
| `przykład` (plakietka na karcie) | **DO ROZSTRZYGNIĘCIA — P-3**, czwarta postać tej samej plakietki. |
| `Przepis jest dobry, kiedy ktoś go ugotował.` | `BRAND_EXTENDED.md` §7, hasło H1, dosłownie. |
| `Pod każdym przepisem jest przycisk „Ugotowałem”.` | Prawdziwe: przycisk jest w panelu przepisu (etap C). **„Pod każdym” — do sprawdzenia**, czy także pod przepisem prywatnym i cudzym. |
| `Dlatego przy przepisie widać nie liczbę serduszek, tylko zdjęcia od ludzi, którym wyszedł.` | Prawdziwe: sekcja „Komu wyszło” pokazuje zdjęcia, a polubień w tym serwisie nie ma. |
| `Tu nie ma rankingów. Nie ma kogo wyprzedzać.` | `COPY_STYLE.md` §4, dosłownie. Prawdziwe: `AGENTS.md` §8 zakazuje rankingu bez pomiaru, §12 zakazuje liczby obserwujących, a `kuKINGi na dziś` są redakcyjne, nie algorytmiczne (`COPY_STYLE.md` §5). |
| `Halina ugotowała Twój rosół.` | `COPY_STYLE.md` §6, „powiadomienie autora”, dosłownie. Oznaczone w makiecie jako przykład: `Tak wygląda powiadomienie, na które się tutaj czeka.` |
| `Zabierzesz stąd wszystko, co dodasz.` / `W każdej chwili możesz pobrać paczkę ze swoimi zdjęciami, wpisami i przepisami.` | **Twarda obietnica.** `BRAND_EXTENDED.md` §1.2 ma akcję „Pobierz swoje dane”, `COPY_STYLE.md` §6 ma e-mail „Twoje dane są gotowe do pobrania”. **Do sprawdzenia w repozytorium**: czy eksport działa, czy zawiera zdjęcia w oryginale i czy „w każdej chwili” nie ma limitu częstotliwości. |
| `Otworzysz ją na swoim komputerze — także wtedy, gdyby Kuking kiedyś przestał istnieć.` | `COPY_STYLE.md` §6, dosłownie. **Prawdziwe tylko przy otwartym formacie paczki** — zdjęcia jako pliki, teksty jako HTML albo Markdown, nie jako zrzut bazy. **Do sprawdzenia w repozytorium.** |
| `Przy każdym wpisie sam decydujesz, kto go widzi: wszyscy, tylko obserwujący albo tylko Ty.` | Prawdziwe: trzy karty widoczności, wdrożone (etap D). |
| `Zeszyt jest prywatny, dopóki sam nie postanowisz inaczej.` | **Do sprawdzenia w repozytorium**: czy Zeszyt jest domyślnie prywatny i czy w ogóle da się go upublicznić. Jeśli nie da się — druga połowa zdania obiecuje ustawienie, którego nie ma, i trzeba ją skreślić. |
| `Prowadzimy to na własną rękę. Nie ma tu reklam między daniami ani firmy, która czeka na Twoje dane — jest strona, konto i przepisy.` | `BRAND_EXTENDED.md` §4, „Strona o nas”, dosłownie. **Sprawdzalne**: zero skryptów firm trzecich. Polecenie: `grep -rniE 'googletagmanager\|google-analytics\|facebook\.net\|hotjar\|clarity\.ms' resources/ public/` — ma dać zero. Do tego nagłówek CSP nie może dopuszczać obcych źródeł skryptu. |
| `Odpisujemy na wiadomości sami i pod każdym e-mailem od nas jest widoczny link „Wypisz się z tych e-maili”.` | **Dwie obietnice.** Druga jest dowodliwa (`BRAND_EXTENDED.md` §5.1: link „Wypisz się z tych e-maili” tym samym rozmiarem co reszta stopki) — **do sprawdzenia w szablonach e-maili**. Pierwsza — **NIEUDOWODNIONE, P-8**: `COPY_STYLE.md` §8 mówi, że imię gospodarza czeka na rozstrzygnięcie (D-012), a `BRAND_EXTENDED.md` §5.1 wymaga nadawcy z imieniem i nazwiskiem oraz adresu, na który da się odpisać. Dopóki nadawcą jest `noreply@`, zdanie jest nieprawdziwe. |
| `Kuking robi jedna osoba, która gotuje w domu…` (cały akapit o-kuking) | **Do potwierdzenia przez właściciela**, bo to jest zdanie o nim, nie o kodzie. Jeśli prawdziwe — jest najlepszym zdaniem na tej stronie. |
| `Rankingów` · `Punktów, poziomów i odznak` · `Algorytmu, który wybiera za Ciebie` · `Reklam między daniami` | Cztery rzeczy, których nie ma. Każda ma pokrycie w `BRAND_EXTENDED.md` §2.1 (lista słów zakazanych zawiera `punkty`, `poziom`, `odznaka`, `ranking użytkowników`) i w `AGENTS.md` §8 i §12. **Uwaga:** te słowa są tu użyte w zdaniu „nie mamy tego” i automat `sprawdz-paczke.mjs` przepuszcza je świadomie — sprawdza tekst widoczny, a zaprzeczenie jest dopuszczalnym użyciem. Warto o tym wiedzieć, żeby nikt tego nie „naprawiał”. |
| `Nic nie jest podbijane i nic nie znika, bo miało za mało reakcji.` | Jak wyżej — obietnica o strumieniu, dowodzi jej kod sortowania. |
| `Nie zarabiamy na tym, ile czasu tu spędzisz, więc nic tu nie miga i nic nie przewija się samo.` | **Sprawdzalne w arkuszu**: `komponenty.css` nie ma ani jednej `animation`, a jedyne `transition` dotyczą koloru, cienia i przesunięcia o 1 px przy wciśnięciu. Do tego `@media (prefers-reduced-motion: reduce)` w `tokens.css`. |
| `Tekst możesz powiększyć w ustawieniach konta i strona nadal się nie rozjedzie.` | **Dowodzi tego automat**: `node 07-wdrozenie/sprawdz-uklad.mjs` — 12 stron × 5 szerokości × 3 skale, 240 pomiarów przewijania w poziomie, zero problemów. Zdanie jest prawdziwe dokładnie w tym zakresie, który automat mierzy — i fałszywe, dopóki nie zamknie się R-4. |
| `Motyw jasny albo ciemny wybierasz sam i nic go potem nie zmienia.` | Jak w 8.1: brak `prefers-color-scheme` w arkuszu. |
| `Kuking jest w zamkniętej becie. Zapraszamy po kilka osób naraz, zaczynając od dwudziestu…` | `CZYTAJ-MNIE.md`: „zamknięta beta, planowo 20 → 200 → 2000 osób”. Prawdziwe co do liczby. |
| `strona nie chwali się liczbami. Nie ma czym: jeżeli kiedyś napiszemy, ile osób coś ugotowało, będzie to liczba, którą da się policzyć.` | Prawdziwe i samosprawdzalne: na obu stronach www nie ma ani jednej liczby użytkowników. |
| `Załóż konto. Zajmie minutę.` | `BRAND_EXTENDED.md` §4, dosłownie. |
| `Cztery pola i gotowe. Nie pytamy o numer telefonu ani o datę urodzenia.` | `COPY_STYLE.md` §6, dosłownie. **Policzalne i trzeba policzyć**: `/register` musi mieć dokładnie cztery pola. **Do sprawdzenia w repozytorium**: `grep -c '<x-field' resources/views/pages/auth/register.blade.php`. Jeśli wyjdzie pięć, zdanie kłamie i kłamie w miejscu, w którym człowiek je sprawdzi wzrokiem w dwie sekundy. |
| `Masz już konto?` / `Zaloguj się` | Bez obietnic. |
| `Skąd ta nazwa.` + akapit o koronie | `COPY_STYLE.md` §2 i `MASCOT_CONCEPT.md`: „korona jest żartem o garnku, nie komplementem dla użytkownika”. Makieta powtarza to dosłownie: `korona siedzi na garnku, nie na niczyjej głowie`. **To jest jedyne miejsce w produkcie, w którym wolno o tym mówić** (`BRAND_EXTENDED.md` §7, H4 warunkowe). |
| `To nazwa przynależności, nie tytuł, na który trzeba zasłużyć. Wystarczy tu być.` | `COPY_STYLE.md` §2, dosłownie w sensie. Nie jest komplementem, więc nie łamie zakazu. |

### 8.6 Przepis publiczny (dla gościa i wyszukiwarki)

| Napis | Co dowodzi |
|---|---|
| `Czytasz przepis w Kuking. Do czytania nie trzeba konta.` | Prawdziwe: strona jest dostępna bez logowania. Sprawdzalne jednym `curl` bez ciasteczka. |
| `przepis przykładowy` | **DO ROZSTRZYGNIĘCIA — P-3**, piąta postać. |
| `Nikt jeszcze nie pokazał, jak mu wyszło` | Pusty stan, uczciwy. |
| `Kiedy ugotujesz z tego przepisu, możesz kliknąć „Ugotowałem” i dodać zdjęcie. Marek dowie się, że ktoś naprawdę to zrobił. Zdjęcie nie musi być ładne.` | Złożone z `COPY_STYLE.md` §6 („{autor} naprawdę chce o tym wiedzieć”, „Zdjęcie nie musi być ładne”). Prawdziwe, jeśli powiadomienie autora naprawdę idzie — **do sprawdzenia w repozytorium**. |
| `Załóż konto, żeby dodać Ugotowałem` | Prawdziwe: „Ugotowałem” wymaga konta. |
| `Zapisz na później` / `Zeszyt to Twoje miejsce na przepisy, które chcesz zachować. Zawsze je tam znajdziesz, a pobrać możesz je stąd w każdej chwili.` | Zgodne z `COPY_STYLE.md` §6 (pusty zeszyt). Druga połowa to znowu obietnica eksportu — ten sam dowód co w 8.5. |
| `Zapisz przepis w Zeszycie` | **Uwaga:** `BRAND_EXTENDED.md` §3 zabrania synonimów — jeśli akcja nazywa się „Zapisz”, to nie ma „Zapisz przepis w Zeszycie”. Do ujednolicenia razem z P-7. |
| `Konto jest darmowe. Nie pytamy o numer telefonu.` | Jak w 8.5. |
| `Czego potrzebujesz` (skrócona lista składników w szynie) | Skrót listy głównej. Bezpieczne, o ile jest skrótem, a nie osobną listą do utrzymania. |

### 8.7 Podsumowanie: co jest nieudowodnione

| Napis | Dlaczego | Gdzie wraca |
|---|---|---|
| `Zapisz` na karcie wpisu | Zeszyt przyjmuje dziś wyłącznie przepisy | P-2 |
| `Ilość i składnik są w osobnych polach, żeby dało się je potem przeliczyć na inną liczbę porcji.` | obiecuje funkcję odrzuconą decyzją D-017 | P-4 |
| `Odpisujemy na wiadomości sami` | nadawca e-maili nierozstrzygnięty (D-012) | P-8 |
| `konto przykładowe` / `przykład` / `przepis przykładowy` | pięć postaci jednej plakietki, jedna z nich gubi „nie prawdziwa osoba” | P-3 |
| `Krok 1 z 3` nad jedną stroną | prawdziwe dopiero po D-108 | P-5 |
| `Zapisuję` kontra `Zapisz` | dwie nazwy jednej akcji | P-7 |
| `Zeszyt jest prywatny, dopóki sam nie postanowisz inaczej.` | druga połowa obiecuje ustawienie, którego może nie być | do sprawdzenia w repozytorium |
| `Cztery pola i gotowe.` | liczba do policzenia w formularzu rejestracji | do sprawdzenia w repozytorium |
| `Jutro będzie tu ktoś inny.` | tablica musi się naprawdę zmieniać codziennie | do sprawdzenia w repozytorium |
| `Zostanie podpisany tak: „przepis Kasi, spisany przez Ciebie”.` | makieta przepisu nie pokazuje takiego podpisu | niespójność wewnątrz paczki |

---

## 9. Czym liczyłem

```bash
# komponenty i ich wielkość
grep -c '^## `x-' 03-kod-wygladu/lista-komponentow.md                    # 25
grep -oE 'Wierszy: [0-9]+' 03-kod-wygladu/lista-komponentow.md \
  | grep -oE '[0-9]+' | paste -sd+ | bc                                  # 3444

# klasy: dziś kontra nowa warstwa
cat app.css ekran-*.css karta-ugotowania.css tokens.css \
  | grep -oE '\.[a-zA-Z][A-Za-z0-9_-]*' | sort -u > /tmp/dzis.txt        # 236
grep -oE '\.[a-zA-Z][A-Za-z0-9_-]*' komponenty.css | sort -u > /tmp/nowe.txt   # 163
comm -12 /tmp/dzis.txt /tmp/nowe.txt | wc -l                             # 59 wspólnych
comm -13 /tmp/dzis.txt /tmp/nowe.txt | wc -l                             # 104 nowych
comm -23 /tmp/dzis.txt /tmp/nowe.txt | wc -l                             # 177 nietkniętych

# ile miejsc dotyka zmiana nazwy klasy
grep -oE '\.post-card\b'   app.css | wc -l                               # 14
grep -oE '\.topbar-inner\b' app.css | wc -l                              # 7
grep -oE '\.site-footer\b' app.css | wc -l                               # 13
grep -oE '\.pole-zdjecia\b' ekran-dodawania.css | wc -l                  # 8

# czy makiety mają jeszcze własny CSS
for f in 03-szablony/*.html 04-strona-www/*.html; do grep -c '<style' "$f"; done   # osiem zer

# belka górna na najwęższym ekranie — mierzone na makiecie, nie w galerii
#   03-szablony/tablica.html @ 320px, tekst 100%: .topbar-akcje 207px, dokument 320px
#   03-szablony/tablica.html @ 320px, tekst 150%: .topbar-akcje 273px, dokument 320px

# automaty paczki
node 01-fundamenty/kontrast.mjs        # kod 0 — 70 par, 0 nie przechodzi
node 07-wdrozenie/sprawdz-paczke.mjs   # kod 0 — 60 plików, 0 błędów twardych
node 07-wdrozenie/sprawdz-uklad.mjs    # kod 0 — 13 stron, 260 pomiarów, 0 problemów
```

Bramką jest **kod wyjścia**, nie liczba stron: ta rośnie z każdą makietą.

Liczba stron i plików rośnie, bo paczka jest w robocie. **Liczby w tym
dokumencie są z chwili jego napisania i mają być przeliczone przed
wdrożeniem, a nie przepisane.** To jest ta sama zasada, przez którą kit v2
się przewrócił: streszczenie starzeje się szybciej niż plik.
