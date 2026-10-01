## D-160 · Wcięcie boczne karty wpisu niesie każdy blok osobno, a klasa współdzielona z innym ekranem go nie dostaje

**Data:** 12 września 2026 · PR #412 · Status: **obowiązuje**

Karta wpisu nie ma własnego `padding` (`.post-card { padding: 0 }`), bo zdjęcie
idzie od krawędzi do krawędzi. Wcięcie 20 px (`--spacing-5`) deklaruje więc
**każdy blok karty u siebie**. Blok tagów był jedynym wyjątkiem: widok wziął dla
niego gotowe `.chipsy` — świadomie, pod hasłem „żadnego nowego CSS" — a `.chipsy`
powstało dla chipsów stojących wprost w kolumnie strony, czyli tam, gdzie wcięcie
daje kolumna.

**Zmierzone** (`scripts/wciecia-boczne-karty-wpisu.mjs`, Chromium,
`getBoundingClientRect()`): 0 px z lewej i 0 px z prawej, przy 1512 px i przy
390 px, podczas gdy każdy inny blok tej samej karty miał 20 px. Po poprawce
20 px na obu krawędziach, na obu szerokościach.

### Reguła

**Klasa używana na więcej niż jednym ekranie nie dostaje odstępów kontekstu,
w którym akurat stoi.** Odstęp idzie na klasę kontekstową (`.post-card-tagi`),
nie na współdzieloną (`.chipsy`) — inaczej naprawa jednego ekranu psuje dwa
inne. Tu konkretnie: wyszukiwanie i szyna profilu, gdzie 20 px doszłoby **do**
wcięcia kolumny.

Wartość zawsze z tokenu `--spacing-*`, nigdy liczbą: wcięcie ma rosnąć razem
z pismem przy czcionce przeglądarki 200% (druga strona D-082 i D-107). Pilnuje
tego osobna asercja w `tests/Feature/PorzadkiWArkuszuKartyTest.php`.

### Druga połowa tego wpisu: martwy kod wychodzi RAZEM ze swoim komentarzem

`.landing-wpisy` przeżyła przejście strony powitalnej na jedną kolumnę,
a komentarz nad nią opisywał ją jak żywą siatkę — czyli **martwy kod bronił się
własną dokumentacją**. Komentarz historyczny (ten, który tłumaczy, co było
i dlaczego tego już nie ma) zostaje tam, gdzie stoi decyzja — u nas
w `strony-publiczne.css` przy `.landing-wpisy-kolumna`.

Użycie klasy sprawdza się **po tokenach w atrybucie `class`, nigdy po podciągu**:
`landing-wpisy` „znajduje się" w `landing-wpisy-kolumna` i martwy kod zostałby
w arkuszu na zawsze, broniony przez własną nazwę. Strażnik ma na to własny test
kontrolny (`test_szukanie_uzyc_liczy_tokeny_a_nie_podciagi`).

📄 `resources/css/app.css` · `tests/Feature/PorzadkiWArkuszuKartyTest.php` ·
`scripts/wciecia-boczne-karty-wpisu.mjs` · D-082 · D-107 · D-132 · D-158
