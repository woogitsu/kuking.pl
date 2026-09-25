## D-107 · Minima dolnej belki są FIZYCZNE, nie typograficzne — przy czcionce przeglądarki 200% belka układa się poziomo i schodzi z 51% ekranu na 25%

**Data:** 11 września 2026 · Ciąg dalszy **D-082** (issue #295, PR #269) ·
Status: **obowiązuje**

### Co zostało po D-082

D-082 zamknęło naruszenie **WCAG 2.2 AA 2.4.11**: rezerwa
`--rezerwa-pod-belka` wyprowadza treść spod przypiętej belki, więc fokus
nigdzie nie ginie. Zostało to, czego rezerwa nie dotyka — **przypięta belka
przy czcionce przeglądarki 200% zajmuje połowę telefonu**. Zmierzone na
`/home` jako zalogowany, okno 320 × 740 px, korzeń 32 px, Chromium 153:
**376,2 px, czyli 50,8% ekranu.** Na treść zostawało 364 px z 740.

Rezerwa działa na tym, GDZIE LĄDUJE FOKUS. Nie zmienia tego, ile ekranu
belka zabiera na stałe — a przy 51% problemem nie jest już przewijanie,
tylko to, że nawigacja jest większa niż treść.

### Przyczyna: to nie była wysokość tekstu

Pozycja belki miała tam **120 px**, a jej zawartość — ikona 26 px, odstęp
2 px, wiersz podpisu 43,2 px — potrzebuje **71,2 px**. Różnicę robiły dwie
liczby zapisane w `rem`, przez co podwajały się razem z korzeniem:

| co | zapis | przy korzeniu 16 px | przy 32 px |
|---|---|---|---|
| minimum pozycji | `var(--control-height-touch)` | 60 px | **120 px** |
| kółko przy „Dodaj" | `3rem` | 48 px | **96 px** |

**Obie te liczby są fizyczne, nie typograficzne.** 60 px pozycji i 48 px
kółka istnieją dla palca i dla ekranu, a palec nie rośnie, gdy ktoś powiększy
czcionkę w przeglądarce — piksel CSS przy powiększeniu SAMEJ czcionki zostaje
tej samej wielkości (to nie jest zoom strony; różnicę opisuje komentarz przy
`Page.setFontSizes` w `scripts/dostepnosc.mjs`). Podwojone nie dawały ani
jednego czytelnego piksela w zamian.

Druga połowa przyczyny to układ kolumnowy: podpis POD ikoną sumuje w pionie
ikonę i wiersz tekstu, choć ikona w poziomie nic nie kosztuje — pozycja ma
i tak 160 px szerokości, bo tyle dyktuje sam podpis.

### Decyzja

Za progiem `max-width: 15rem` (ten sam, co rezerwa i odpięcie `.topbar` —
porównuje okno z KORZENIEM, więc znaczy „tekst jest duży w stosunku do
ekranu"):

* `.bottom-nav-item` układa się **poziomo** — ikona obok podpisu;
* minimum pozycji to **60 px w pikselach ekranu**, czyli dokładnie tyle, ile
  `--control-height-touch` daje na zwykłym telefonie — nie mniej, tylko bez
  podwajania;
* kółko przy „Dodaj" to **48 px**, czyli minimum celu dla palca z `AGENTS.md`
  §5 (cel kliknięcia jest większy: odnośnikiem jest cała pozycja, zmierzone
  160 × 60 px);
* **główna akcja dostaje własny wiersz** (`flex-basis: 100%`), bo przy dwóch
  pozycjach na wiersz zostaje jej 96 px na podpis, a „Dodaj" potrzebuje 105 —
  i reguła bazowa `overflow-wrap: anywhere` łamała to słowo gdziekolwiek. Na
  zrzucie sprzed tej linijki stało **„Doda / j"**.

Zmierzone po poprawce (`/home`, korzeń 32 px): **181 px, czyli 24,5% okna**,
pięć pozycji w trzech wierszach 2 + 1 + 2, każdy podpis w całości.

| wariant | przed | po |
|---|---|---|
| bez powiększania | 66,6 px (9,0%) | 66,6 px (9,0%) |
| nasze „tekst 140%" | 105,5 px (14,3%) | 105,5 px (14,3%) |
| czcionka przeglądarki 200% | 376,2 px (50,8%) | **181 px (24,5%)** |

### Czego ta decyzja NIE robi — to są zakazy z issue #295

Pismo podpisu zostaje przy `--text-body` (36 px przy tym korzeniu; minimum
produktowe 18 px, issue #110), pięć pozycji zostaje pięcioma, każda ikona
zostaje z podpisem, `flex-wrap: wrap` zostaje włączone (issue #80), a **próg
w `scripts/dostepnosc.mjs` nie został podniesiony ani jeden ekran nie wszedł
na listę wyjątków.** Dolna belka zostaje przypięta — jej odpięcie to zmiana
kierunku produktu (zabiera główną akcję z zasięgu kciuka) i wymaga osobnej
decyzji właściciela.

**Zwykły telefon nie zmienia się ani o piksel** — i to jest kontrola dodatnia
wpisana w sam mechanizm, a nie dołożona obok: próg `15rem` przy korzeniu
16 px odpowiada oknu 240 px, węższemu niż jakikolwiek telefon.

### Czym to jest pilnowane

Wysokości belki nie da się stwierdzić z CSS-a, więc mierzy ją
`scripts/dostepnosc.mjs` — nowa sekcja „Dolna belka", próg **1/3 okna**,
liczona przy tych samych trzech szerokościach i trzech skalach co fokus.
Przekroczenie zatrzymuje przebieg kodem 1; pomiar oblewa też wtedy, gdy belka
ma inną liczbę pozycji niż pięć, żeby „naprawa" przez okrojenie nawigacji nie
mogła zazielenić tej liczby. Wszystkie pomiary (nie tylko przekroczenia) idą
do `storage/dostepnosc.json` pod `wysokoscBelki`.

Job `dostepnosc` chodzi w CI warunkowo (tylko przy zmianie w `resources/`,
`public/`, `scripts/dostepnosc.mjs` albo w plikach npm), więc niezmienniki
widoczne w źródle pilnuje dodatkowo
`tests/Feature/BelkaPrzyDuzymTekscieTest.php` w jobie `test`, czyli zawsze:
układ poziomy za progiem, minimum w pikselach **zgodne z tokenem**
`--control-height-touch` (żeby te dwie liczby nie rozjechały się po cichu),
kółko 48 px, własny wiersz głównej akcji oraz — jako kontrola dodatnia —
belka bazowa bez zmian, pięć pozycji w HTML-u i podpis przy każdej ikonie.

### Jak to wycofać

Skreślenie dwóch bloków `@media (max-width: 15rem)` przy `.bottom-nav-item`
i `.bottom-nav-kolko` wraca do stanu sprzed poprawki. Oblewa wtedy
`BelkaPrzyDuzymTekscieTest` i sekcję „Dolna belka" w automacie. Nie dotyka
danych, schematu ani niczego, co widzi zwykły telefon.

**Zmiana wymaga:** zmierzenia belki tym automatem przy 320 px i czcionce
przeglądarki 200% — i podania liczby, a nie zrzutu z domyślnej czcionki.

📄 `resources/css/app.css` · `scripts/dostepnosc.mjs` ·
`tests/Feature/BelkaPrzyDuzymTekscieTest.php` · D-082 · D-099 · D-051
