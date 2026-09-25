## D-170 · Dokumenty w `docs/brand/` podlegają własnym regułom tam, gdzie podają tekst do wklejenia

**Data:** 12 września 2026 · PR #421 · issue #38 (część) · Status: **obowiązuje**

### Co było

§6 `docs/brand/COPY_STYLE.md` przez pół roku **zalecał** frazy, które ten sam
dokument uznaje za błąd — bo żaden test nie czytał `docs/`.
`TekstyWedlugCopyStyleTest` skanuje `resources/views`, `resources/legal/*.md`,
`lang/` i PHP. Przewodnik był jedynym miejscem w repozytorium, gdzie własne zasady
wolno było łamać bezkarnie, i to akurat tam, **skąd ludzie kopiują**.

Zgłoszono dwie frazy. Skan wzorów do wklejenia w całym `docs/brand/` dał
**jedenaście trafień w czterech plikach** — pięć w §6 `COPY_STYLE.md`, dwa
w `BRAND_EXTENDED.md`, trzy w `MASCOT_CONCEPT.md` §6.4 (sekcja, która sama nazywa
swoje teksty „gotowymi do wklejenia").

Dwa z nich są szczególnie wymowne: `mail/data-export-ready.blade.php:34` ma nad
sobą komentarz „Gotowy napis z COPY_STYLE.md §6. Nie zmieniamy go" — a napis od
dawna różnił się od §6. `BRAND_EXTENDED.md:137` przeczył **słowniczkowi w tym
samym pliku** (`:46`) i produktowi.

### Zasada

> Gotowy napis w przewodniku jest traktowany jak napis w produkcie.

**Granicą jest znacznik w samym dokumencie:** wiersz `❌`, komórka skreślona
i cała proza zostają wolne — o błędach trzeba móc pisać. Obie zgłoszone frazy
dalej stoją w dokumencie jako cytaty odrzucone i strażnik ich nie rusza; że je
odróżnia, jest **zmierzone**, nie założone (sabotaż samego rozróżnienia oblewa
cztery testy).

**Gdy napis żyje już na ekranie, wiążące jest brzmienie z kodu** — dokument idzie
za produktem, nie odwrotnie.

Wszystkie poprawki to **przebudowa zdania**, nigdy dopisanie drugiej formy.

### Dlaczego osobny plik, a nie rozszerzenie istniejącego testu

`TekstyWedlugCopyStyleTest` bierze **powierzchnię produktu** i dokument jest tam
**źródłem reguły**, nie przedmiotem badania. Dołożenie `docs/brand/` wymagałoby
wniesienia do niego całego mechanizmu „wzór kontra cytat odrzucony" — wiedzy
o tym, co znaczy `❌` w bloku ```text — której tamten plik nie ma powodu mieć.

Jedno wspólne zostało uwspólnione **naprawdę**: wzorce rodzaju mieszkają
w `tests/Support/WzorceRodzaju.php` i używają ich oba testy, więc poprawka wzorca
nie może uczynić jednego z nich ślepym.

### Co wyszło w kontroli ujemnej

Przy cofnięciu całej poprawki wyszła słabość **samej frazy kontrolnej**: „Możesz
być pierwsza albo pierwszy" stała przed poprawką jednocześnie jako `❌` **i** jako
wzór, więc czerwień nie mówiłaby, czy zepsuty jest parser, czy dokument. Fraza
podmieniona na występującą wyłącznie jako cytat odrzucony, kontrola powtórzona.

Reguły „wykrzyknik" i „nazwa w rejestrze poważnym" nie mają dziś w przewodniku ani
jednego trafienia — dlatego mają **własną kontrolę na podstawionych usterkach**,
inaczej byłyby zielone bez znaczenia.

📄 `docs/brand/COPY_STYLE.md` §6 · `docs/brand/BRAND_EXTENDED.md` ·
`docs/brand/MASCOT_CONCEPT.md` §6.4 ·
`tests/Feature/PrzewodnikTrzymaSieWlasnychZasadTest.php` ·
`tests/Support/WzorceRodzaju.php` · D-104 · D-132 · issue #38
