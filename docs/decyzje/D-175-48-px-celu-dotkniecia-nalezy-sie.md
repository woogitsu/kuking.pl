## D-175 · 48 px celu dotknięcia należy się rzeczom, w które da się kliknąć — nie każdemu wierszowi tekstu

**Data:** 12 września 2026 · PR #442 · issue #435 · Status: **obowiązuje**

### Kontekst

Zgłoszenie właściciela brzmiało: „Mój profil jest miejsce by dać @woogitsu obok
Mateusz". Za tym jednym zdaniem stała rzecz mierzalna: główka własnego profilu przy
390 px miała **952 px** wysokości, więc rząd akcji stał na `y ≈ 851` przy oknie
844 px — **pod pierwszym ekranem**. Do tej samej główki doszło wcześniej **D-168**,
od zupełnie innej strony.

Rozbiórka na klocki pokazała, skąd ta wysokość: **liczniki 269 px**, kolumna awatara
213 px, bio 84 px.

### Decyzja

`min-height: 3rem` (48 px) dostają **tylko te liczniki, które są odnośnikami** — dwa
z pięciu prowadzą do listy osób. Trzy pozostałe to zwykły tekst.

Reguła z `AGENTS.md` §5 mówi o **celu dotknięcia**, a nie o wysokości każdego wiersza.
Zastosowana do tekstu, w który nie da się kliknąć, kosztuje 33 px na wiersz i nie
kupuje niczego.

### Przy okazji: `@nazwa` wchodzi do wiersza z nazwą

`.profil-tozsamosc` (flex z `flex-wrap` i `align-items: baseline`). Przy długiej nazwie
(`display_name` ma `max:100`) i przy czcionce 200% `@nazwa` **schodzi pod spód** —
schodzi, a nie wypycha strony w bok.

### Zmierzone

Rząd akcji, czcionka 100%: 390 px **850,77 → 767,03 px** (po raz pierwszy nad
zgięciem przy oknie 844 px), 414 px 822,88 → 739,14, 320 px 887,95 → 826,72,
768 px 593,88 → 532,64, 1280 px 380,66 → 351,86. Przy czcionce 200%: 768 px
1562,58 → 1395,16, 1280 px 1506,78 → 1339,36.

### Czego świadomie nie zrobiono

**Liczniki zostają po jednym w wierszu.** Dwie kolumny były już raz próbowane
i zmierzone jako gorsze: kolumna treści ma 229–373 px w całym zakresie okien, po
podziale zostaje ~112 px i wyrazy łamią się w środku („obserwując / ych").

### Przy okazji zapisana pułapka kaskady

Pierwsza wersja tej poprawki postawiła `@media` z progiem **przed** regułą bazową.
Obie mają tę samą specyficzność, więc wygrała ta niżej w pliku i **próg przestał
działać także przy 1280 px**. Wyglądało to na zmianę ograniczoną do telefonów, a nie
było nią — złapał to dopiero pomiar. Stąd osobny test na **kolejność reguł w pliku**.

📄 `resources/css/ekran-profilu.css` · `scripts/glowka-profilu.mjs` ·
`GlowkaProfiluScalaNazweZNazwaUzytkownikaTest` · D-168 · issue #440
