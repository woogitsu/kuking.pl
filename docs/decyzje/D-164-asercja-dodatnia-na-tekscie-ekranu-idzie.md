## D-164 · Asercja dodatnia na tekście ekranu idzie po `<main>`, nie po całym dokumencie

**Data:** 12 września 2026 · PR #415 · Status: **obowiązuje** · rozwinięcie D-132

### Reguła

Asercja „człowiek widzi na tym ekranie napis X" (`assertSee`,
`assertStringContainsString`) sprawdza się na **wyciętej treści** ekranu
(`Tests\Support\WycinaObudoweEkranu::trescEkranu()`), nie na całej odpowiedzi.
Asercje „X nie ma" **zostają na całym dokumencie** — szersze spojrzenie jest tam
ostrożniejsze, nie słabsze.

### Dlaczego

`<title>` ekranu jest zwykle tym samym zdaniem co jego `<h1>` i powtarza się
w `<meta>` (description, og:title, og:image:alt). Stopka niesie „Napisz do nas",
„O kuKING" i licznik „{n} kuKINGów" na **każdym** ekranie; belka gościa niesie
„Zaloguj się" i „Załóż konto" na każdym ekranie. Jedno zdanie stoi więc
w dokumencie 2–5 razy, zanim ktokolwiek spojrzy na treść.

**Zmierzone:** dziesięć asercji w dziesięciu plikach przechodziło po skasowaniu
tego, czego pilnowały. Skrajny przypadek — `/o-kuking`, gdzie po D-145 napisu
„O Kuking" **nie ma w treści ani razu** (nagłówek brzmi „O kuKING", nazwa jest
rozbita na znaczniki), a asercja i tak była zielona z `<title>` i dwóch `<meta>`.

Metoda szukania reszty: 47 wyrenderowanych ekranów (gość i zalogowany), z każdego
wycięty `<main>`, z reszty zbudowany korpus obudowy, potem triaż 106 trafień po
DOM-owej lokalizacji każdego napisu na jego własnej stronie.

### Granica

Gdy ten sam napis stoi w treści **i** w prawej szynie (np. „Dodaj zdjęcie
profilowe"), `<main>` nie wystarcza — plik musi wybrać stronę, o którą mu chodzi.
Dwa pliki mierzyły tam nie to, o czym są.

**`bezStopki()` nie jest zamiennikiem:** zdejmuje stopkę i tylko stopkę. Po jego
zastosowaniu na `/o-kuking` napis „O Kuking" nadal jest w dokumencie trzy razy.
Pułapka 1 w `docs/PULAPKI_TESTOW.md` ostrzegała przed stopką i belką — dopisany
**§1b** mówi, że najczęstszym winowajcą jest `<head>`.

### Zgłoszone, nietknięte

`SpisTematowTest:90` i `JednoSlowoNaTagiTest:93` sprawdzają „Wszystkie tagi" na
całym dokumencie. Napis stoi też trzy razy w treści, więc dowód sabotażem
wymagałby usunięcia `aria-label`, co wywala **inną** asercję w tym samym pliku —
czerwień pochodziłaby nie z tego, co się mierzy. Te dwie asercje pełnią dziś rolę
„strona się wyrenderowała, to nie ekran błędu" i tę rolę pełnią poprawnie.

### Koszt cofnięcia

Cofnięcie przywraca dziesięć zielonych testów, które nie umieją zaświecić się
na czerwono.

📄 `tests/Support/WycinaObudoweEkranu.php` · `docs/PULAPKI_TESTOW.md` §1b ·
D-132 · D-145
