## D-223 — Martwe reguły CSS: strażnik pyta o wynik kaskady, nie o tekst arkusza (20 września 2026)

`resources/css/app.css` linia 1 ustawia `@layer theme, base, components, marka,
utilities;`. Warstwa późniejsza bije wcześniejszą niezależnie od szczegółowości
selektora i niezależnie od zapytania medialnego. W repozytorium żyją przez to
reguły z komentarzami uzasadniającymi konkretne wartości, których przeglądarka
nigdy nie widzi. Komentarz opisuje wtedy stan nieistniejący, a następny człowiek
czyta go jak prawdę i na nim buduje.

### Co zmierzono przy `.przepis-liczby` — i dlaczego wynik jest inny, niż zakładano

Zlecenie pytało, czy `10rem` z `marka-ekrany.css` zamiast `7rem` z `app.css`
psuje coś realnego przy 320 px i powiększonym piśmie. Odpowiedź: **nie psuje, bo
ŻADNA z tych dwóch wartości nie działa.** Jedyny nosiciel `.przepis-liczby`
(`pages/recipes/show.blade.php`) stoi wewnątrz `.marka-przepis-tekst`, a
`marka-przepis.css` robi z niego `display: flex`. Na kontenerze flex
`grid-template-columns` nie znaczy nic. Przykrycie `7rem` przez `10rem` było
prawdziwe i zarazem bez znaczenia — spór o wartość toczył się o własność, która
i tak nie dochodzi.

Zmierzone w przeglądarce, nie wyczytane z arkusza: wymuszenie `7rem` tam, gdzie
wartość naprawdę by obowiązywała, dało geometrię kafel-w-kafel **identyczną co do
piksela w 30 konfiguracjach na 30** (dwa przepisy × 320/360/1280 px × pięć
wariantów pisma). Zero przewijania w poziomie, zero ucięcia tekstu.
Dowód: `docs/design/evidence/kaskada223/`.

Dlatego **wartości nie ruszamy i reguł nie usuwamy** — poprawiono wyłącznie
komentarze, żeby przestały uzasadniać liczbę, której nie ma. Usunięcie martwej
reguły JEST zmianą zachowania na wypadek, gdyby `marka-przepis.css` zniknął,
i jest osobną decyzją.

### Trzy rzeczy, które ten pomiar ujawnił przy okazji

1. **Osiem arkuszy nie jest owiniętych w żadną warstwę** (`marka-przepis`,
   `marka-panel`, `marka-powiadomienia`, `marka-rama`, `marka-szukaj`,
   `marka-wejscie`, `marka-zeszyt`, `pasek-przewijany`, `szybki-wyglad`).
   Kod spoza warstw bije KAŻDĄ warstwę nazwaną, także `utilities` — istnieje
   więc faktyczna warstwa najwyższa, której instrukcja `@layer` nie wymienia.
   Komentarz przy imporcie twierdzi, że „każdy z nich dopisuje własne klasy do
   @layer components". Dla tych ośmiu to nieprawda.
2. **Instrukcja `@layer a, b, c;` NIE PRZEŻYWA BUDOWANIA.** W zbudowanym
   arkuszu zostają same bloki `@layer nazwa { … }`, a kolejność wynika z ich
   pierwszego wystąpienia. Dochodzi też wewnętrzna warstwa Tailwinda
   `properties`, PRZED `theme` — w źródle jej nie ma.
3. **Zapytania medialne są budowane w składni zakresowej** (`(width >= 48rem)`),
   nie `(min-width: 48rem)`. Narzędzie szukające `min-width` znajduje zero
   progów i wygląda wtedy na zielone.

Wszystkie trzy są argumentem za tym samym: **o CSS trzeba pytać przeglądarkę, nie
plik.** Strażnik czytający źródło mierzyłby tu co innego, niż widzi użytkownik.

### Strażnik

`scripts/kaskada-martwe-reguly.mjs` wykrywa deklarację z warstwy wcześniejszej
całkowicie przykrytą przez warstwę późniejszą na tej samej własności i tym samym
elemencie. Tekst arkusza służy wyłącznie do ZAWĘŻENIA listy kandydatów.
Rozstrzyga pomiar: deklarację zdejmujemy z żywej reguły na wyrenderowanej
stronie, porównujemy `getComputedStyle` każdego pasującego elementu przed i po,
i przywracamy. Brak różnicy we wszystkich mierzonych konfiguracjach znaczy, że
deklaracja nie zmienia nic.

Strażnik nie jest listą znanych przypadków: kolejność warstw czyta z przeglądarki
(pierwsze wystąpienie warstwy), reguły obchodzi rekurencyjnie przez `@layer`,
`@media` i `@supports`, a szerokości bierze z progów znalezionych w arkuszu —
więc czwarta warstwa i piąty arkusz wchodzą do pomiaru same. Jedyna lista nazw
w tym pliku to WYJĄTKI i każdy ma przy sobie powód.

Strażnik ma własną samokontrolę: brak wykrytych warstw albo zero przepytanych
deklaracji to BŁĄD PRZYRZĄDU (kod 2), nie wynik pozytywny. Nie jest to ozdoba —
pierwsza wersja tego strażnika czytała kolejność warstw z instrukcji `@layer`,
której zbudowany arkusz nie zawiera, i meldowała „✓ żadna reguła nie jest
przykryta", nie sprawdziwszy ani jednej. Samokontrola to złapała.

### Czego ten strażnik nie mierzy

Selektorów ze stanem interakcji (`:hover`, `:focus`), selektorów bez nosiciela na
mierzonych stronach i reguł o zasięgu masowym (ponad 300 elementów — wewnętrzne
reguły Tailwinda). Wszystkie trzy są RAPORTOWANE jako `niezmierzone`, nigdy
pomijane po cichu: cisza wyglądałaby jak wynik pozytywny.
