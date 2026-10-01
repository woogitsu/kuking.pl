## D-177 · Rząd akcji zawija się dopiero, gdy naprawdę nie ma miejsca — a `.field:first-child` nie trafia w formularzu POST

**Data:** 12 września 2026 · PR #446 · issue #433 · Status: **obowiązuje**

### Kontekst

Dwa zgłoszenia właściciela ze zrzutów: „Odpowiedz i popraw jest jedno pod drugim,
gdzie jest jednak miejsce by dać obok siebie" oraz „patrz ile miejsca nad «napisz
komentarz» à żadnego między «też jest w porządku» polem do pisania".

### Trzy rzeczy warte zapamiętania

**1. `.field:first-child` nie trafia w formularzu POST.** `@csrf` i `@method()`
renderują **ukryte pola**, a ukryte pole jest elementem. Każda reguła oparta na
`:first-child` wewnątrz formularza pilnuje czegoś, czego tam nie ma — sprawdzone
pomiarem na ~50 formularzach serwisu. Naprawiona jest **przyczyna w `tokens.css`**,
nie objaw w komentarzach: kopia reguły byłaby drugim źródłem prawdy o tej samej rzeczy.

**2. Selektor sąsiedztwa naprawiający jeden układ potrafi zepsuć inny.**
`input[type="hidden"] + .field` jest poprawne wszędzie **poza** ekranami odtwarzającymi
cudzy formularz w pętli (419, 429), gdzie ukryte pole rozdziela dwa widoczne. Reguła
z `+` potrzebuje więc pary z `~`, która odwraca ją tam, gdzie pole nie jest pierwsze.

**3. Podział akcji idzie po odwracalności, nie po autorstwie.** „Zgłoś" wróciło do
rzędu akcji zwykłych: renderowało się **po** `.danger-zone`, więc w jedynym stanie,
w którym obie akcje są naraz (autor treści ogląda cudzy komentarz), kreska nie
oddzielała już niczego.

### Zmierzone

Blok akcji pod komentarzem: 360 / 390 / 414 px **196,5 → 138 px** (3 → 2 wiersze),
cudzy komentarz 117 → 66,5 px (2 → 1 wiersz). Pustka nad „Napisz komentarz"
**24 → 0 px**, odstęp podpis → pole **0 → 12 px**.

### Czego świadomie nie zrobiono

**Przy 320 px akcje muszą się zawijać** — „Odpowiedz" + „Popraw" = 252,97 px przy
wnętrzu karty 246 px. Zmieszczenie ich wymagałoby zwężenia przycisków, czego
zgłoszenie zabrania wprost. **Parytet, nie poprawa — i to jest wynik, nie przeoczenie.**

**„Wyślij komentarz" zostaje po lewej.** Pomiar jest lustrem: przesunięcie w prawo
zyskuje na prawej dokładnie tyle, ile traci na lewej. Przy czcionce 200% różnica
wynosi **0 px**, bo przycisk wypełnia panel.

`row-gap` 8 px przy `column-gap` 12 px: gdyby oba były 12 px, blok przy 320 px byłby
o 4 px **wyższy** niż przed poprawką — zgłoszenie o zmarnowanym miejscu załatwione
dołożeniem miejsca.

📄 `resources/css/tokens.css` · `resources/views/components/comment-thread.blade.php` ·
`scripts/uklad-komentarzy.mjs` · `AkcjeKomentarzaWJednymRzedzieTest` ·
`RytmFormularzaKomentarzaTest` · D-154 · D-158 · issue #444 · issue #445
