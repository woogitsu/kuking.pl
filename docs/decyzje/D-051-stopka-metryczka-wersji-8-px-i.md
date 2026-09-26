## D-051 · Stopka: metryczka wersji 8 px i przełącznik motywu bez widocznego napisu — świadomy wyjątek od AGENTS.md §5

**Data:** 9 września 2026 · Issue #205 · Decyzja właściciela · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Wyjątek obowiązuje i ma
> pokrycie (`resources/css/app.css:4404-4406`,
> `resources/views/components/layout.blade.php:1244`, `AGENTS.md:233`), ale
> nie jest tym, czym brzmi. Strażnik minimum 18 px
> (`tests/Feature/MinimalnyRozmiarTekstuTest.php:29-36`) chodzi po ZAMKNIĘTEJ
> BIAŁEJ LIŚCIE sześciu selektorów i nie skanuje CSS w poszukiwaniu małych
> rozmiarów. Reguła i wyjątek „nie kolidują" wyłącznie dlatego, że reguła do
> `.site-version` w ogóle nie dochodzi — a samej wartości 8 px nie asertuje
> żaden test, więc podniesienie jej do `--text-meta` (wariant tu odrzucony)
> nie obleje niczego. To ten sam kształt co w D-091 i D-163: gwarancja na
> papierze, mierzona przez coś, co jej nie obejmuje. Naprawa nie należy do
> tego audytu — zgłoszona osobno.

Przy przebudowie stopki na kilka poziomów (issue #205) właściciel poprosił
wprost o dwie rzeczy, które łamią `AGENTS.md` §5:

1. metryczkę wersji („Alfa 0.1 · data wydania · commit") **drukiem 5–8 px**,
   podczas gdy §5 mówi „tekst ≥ 18 px" (najmniejszy token w ogóle,
   `--text-meta`, to 15 px — 8 px jest poniżej NAJMNIEJSZEGO tokenu
   w systemie, nie tylko poniżej minimum produktowego);
2. przełącznik motywu jako **samą ikonę**, bez widocznego napisu obok,
   podczas gdy §5 mówi „ikona nigdy nie jest jedynym opisem ważnej akcji".

Właściciel dostał przed decyzją trzy warianty, w tym wariant zgodny z §5
(wersja na `--text-meta`, przełącznik jako ikona + krótki podpis „Ciemny" /
„Jasny"). **Wybrał świadomie wariant, który regułę łamie w tych dwóch
punktach** — bo w jego ocenie wynik wygląda lepiej i zajmuje mniej miejsca
w stopce niż jakikolwiek z wariantów zgodnych. To jest jego produkt i jego
decyzja o tym, jak ma wyglądać stopka — a nie pomyłka do poprawienia przy
najbliższej okazji.

### DLACZEGO TO JEST WYJĄTEK, NIE ZMIANA REGUŁY

`AGENTS.md` §5 zostaje **dokładnie taki, jaki jest, wszędzie indziej**.
Ten wpis nie obniża minimum 18 px ani nie znosi zakazu samej ikony dla
reszty serwisu — od jutra nowy ekran, który spróbuje 12-pikselowego tekstu
albo przycisku bez podpisu, dalej jest błędem, nie precedensem. D-051 jest
nazwaną, zapisaną dziurą w regule, nie furtką.

### ZAKRES WYJĄTKU — TYLKO TE DWA ELEMENTY

- `.site-version` w `resources/views/components/layout.blade.php`
  (metryczka wersji: etap produktu, data wydania, skrót commita) —
  **8 px**, górny kraniec przedziału 5–8 px, który podał właściciel: to
  najczytelniejszy wybór z tego, o co poprosił.
- `.site-footer-motyw` / `.site-footer-motyw-przycisk` (przełącznik
  motywu w stopce) — **sama ikona (`ksiezyc` przy jasnym motywie, `slonce`
  przy ciemnym — patrz „IKONA WŁASNA, NIE POŻYCZONA" niżej), bez
  widocznego napisu obok**.

Nigdzie indziej. W szczególności: nawigacja mobilna, przyciski akcji,
podpisy pod ikonami w innych miejscach serwisu i wszystkie pozostałe
teksty stopki (odnośniki, nagłówki grup, hasło marki) trzymają się §5 bez
zmian — odnośniki w stopce są zwykłymi linkami ≥16 px z widocznym tekstem,
tak jak przed przebudową.

### CO MIMO TO ZOSTAJE NIENARUSZONE

Złamanie §5 dotyczy WYŁĄCZNIE rozmiaru tekstu i widoczności napisu.
Cztery rzeczy nie są częścią tego kompromisu i zostały utrzymane wprost:

1. **Przycisk motywu ma nazwę dostępną.** `aria-label` i `title` niosą
   dokładnie ten sam tekst, co dawny widoczny napis („Włącz ciemny
   wygląd" / „Włącz jasny wygląd"), plus `<span class="visually-hidden">`
   jako drugie, tanie zabezpieczenie. Sama ikona bez nazwy dostępnej jest
   dla czytnika ekranu przyciskiem-widmem — tego właściciel nie prosił
   złamać, i to jest różnica między „mniej miejsca" a „zepsute".
2. **Pole kliknięcia zostaje ≥48×48 px.** To, co zajmowało miejsce
   w stopce, był NAPIS OBOK ikony, nie wysokość ani szerokość samego
   przycisku — `.btn` już dawało `min-height: 3rem` (48 px) i padding,
   który przy samej ikonie daje ~64 px szerokości. Zdjęcie napisu nie
   zmniejszyło obszaru dotyku ani o piksel.
3. **Kontrast metryczki wersji zostaje AA.** `--color-ink-muted` na
   `--color-surface-raised` liczy 7,54:1 (`docs/design/DESIGN_SYSTEM.md`),
   daleko od progu 4,5:1 — i to jest niezależne od rozmiaru czcionki.
   Rozmiar tekstu jest decyzją właściciela; nieczytelny kolor byłby
   dodatkową, nikim nie zamówioną usterką, i to jest granica, której ten
   wpis broni.
4. **Metryczka wersji jest widoczna zawsze, nie za `hover` ani za
   `title`.** Właściciel prosił o mały druk, nie o ukrycie — informacja
   dostępna tylko przez najazd kursorem jest dla części osób (telefon,
   dotyk) niedostępna w ogóle (`docs/UX_50_PLUS.md`). `.site-version`
   nie ma `display: none`, `hidden` ani odpowiednika schowanego za
   interakcją; stoi w HTML-u i na ekranie tak samo, jak dziś.

### IKONA WŁASNA, NIE POŻYCZONA

Pierwsza wersja tego wpisu i tego PR-a używała do przełącznika istniejącej
ikony `settings` (zębatka) jako „najbliższego sensownego zamiennika" — zestaw
`<x-ikona>` nie miał wtedy księżyca ani słońca. To był błąd, złapany przy
przeglądzie: `settings` to DOKŁADNIE ten sam kształt, którym w menu bocznym
oznaczona jest pozycja „Ustawienia" (`route('settings.*')`,
`resources/views/components/layout.blade.php`). Po zmianie w serwisie
istniałyby więc dwa różne przyciski o tym samym kształcie.

Przy zwykłym przycisku z podpisem dwie różne rzeczy pod tym samym kształtem
dałoby się wybaczyć — podpis rozstrzyga. Ale przełącznik motywu z tego
wpisu jest z definicji BEZ widocznego podpisu (punkt 2 wyżej), więc kształt
jest jedyną wskazówką, co przycisk robi. Pożyczony kształt zamieniał więc
oszczędność miejsca w gotową pomyłkę do kliknięcia — dokładnie tego typu
usterkę, przed którą ostrzega `docs/UX_50_PLUS.md`.

Naprawa: `resources/views/components/ikona.blade.php` dostał dwa nowe,
własne kształty — `ksiezyc` i `slonce`, tym samym stylem co reszta zestawu
(sam obrys, `stroke-width: 1.8`, bez wypełnień, ten sam `viewBox`). Ikona
pokazuje WYNIK kliknięcia, spójnie z tekstem, który już tam jest: jasny
motyw → napis „Włącz ciemny wygląd" → `ksiezyc`; ciemny motyw → napis
„Włącz jasny wygląd" → `slonce`. `WyborMotywuTest` sprawdza, że kształt
zmienia się razem z motywem, żeby ta sama pomyłka (jeden kształt na oba
stany) nie wróciła po cichu.

### DLACZEGO NIE „NAJMNIEJSZY TOKEN" (`--text-meta`, 15 px)

Rozważona i odrzucona: użycie istniejącego, udokumentowanego tokenu
zamiast nowej wartości `0.5rem`. 15 px jest wciąż wyraźnie większe niż to,
o co poprosił właściciel („małym druczkiem, np. 5–8 px") — użycie tokenu
zamiast liczby z jego przedziału byłoby po cichu cofnięciem decyzji, a nie
jej wykonaniem. Zamiast tego metryczka dostaje własną wartość
(`calc(0.5rem * var(--user-text-scale, 1))`), skalowaną tak samo jak reszta
typografii serwisu — patrz punkt niżej.

### SKALOWANIE Z USTAWIENIEM CZYTELNOŚCI

8 px to rozmiar BAZOWY, nie sztywny. `.site-version` mnoży go przez
`var(--user-text-scale, 1)`, dokładnie jak każdy inny token typografii
w `tokens.css`. Bez tego osoba, która celowo powiększyła sobie tekst na
`/ustawienia/czytelnosc`, dostałaby jedno miejsce w całym serwisie, którego
jej własne ustawienie nie dotyczy — czyli nowy, nikim nie zamówiony błąd
obok tego, na który właściciel świadomie się zgodził.

### DROGA WYCOFANIA

Właściciel zobaczy efekt na produkcji i może uznać, że jednak wolałby
jeden z odrzuconych wariantów (np. ikona z krótkim podpisem „Ciemny" /
„Jasny", albo wersja na `--text-meta`). To jest zwykła zmiana wizualna:
podnieść `font-size` `.site-version` do tokenu (np. `--text-meta`) i/lub
dopisać widoczny tekst obok `<x-ikona>` w `.site-footer-motyw`, usunąć ten
wpis albo oznaczyć go jako uchylony. Żadna z tych zmian nie rusza schematu
bazy, tras ani logiki `ThemeController` — cofnięcie jest kosmetyczne
i jednoplikowe (`resources/views/components/layout.blade.php` +
`resources/css/app.css`).

📄 `resources/views/components/layout.blade.php` (`.site-footer`) ·
`resources/css/app.css` (`.site-version`, `.site-footer-motyw*`) ·
`resources/views/components/ikona.blade.php` ·
`tests/Feature/WyborMotywuTest.php` ·
`tests/Feature/StopkaPoziomyTest.php` ·
`docs/design/DESIGN_SYSTEM.md` (kontrast `ink-muted`)
