# Cztery rozbieżne pary — opis do decyzji

21.09.2026, pomiar własny wobec `origin/main` = `cd966aae`.

## Uwaga metodologiczna — czytaj, zanim spojrzysz na liczby

Pierwszy pomiar dał „68 plików różnych, 8 057 wierszy" dla jednej pary i podobne
liczby dla innych. **To był dryf wobec `main`, nie spór między gałęziami.** Poznać
go po tym, że `resources/css/strony-publiczne.css +173/−27` wychodziło jako
największa różnica w **dwóch niezwiązanych parach** — czyli mierzyłem odległość
gałęzi od `main`, a nie to, w czym się nie zgadzają.

Poprawna metoda, użyta niżej: wyznaczyć pliki, które **obie** gałęzie zmieniają
wobec `main`, i z nich zostawić te o **różnym haszu**. Liczby spadły
kilkunastokrotnie. **To jest ten sam błąd, który popełniłem rano przy `scal-786`**
(117 plików zamiast 42) — wygląda na to, że to główna pułapka tej kolejki.

---

## 1. `naprawa/baza-proby-per-runtime` vs `robota/bazy-stanowisk` — ROZSTRZYGNIĘTE, obie przestarzałe

Wspólnych plików: **3** (nie 54). Wszystkie trzy się różnią:
`tests/bootstrap.php`, `tests/Unit/NazwaTestowejBazyTest.php`, `scripts/cleanup-test-dbs.sh`.

**SPROSTOWANIE 21.09, po południu — poniższy akapit był BŁĘDNY.**

Napisałem, że obie gałęzie wyprowadzają nazwę z `.git` i obie mają tę samą wadę.
**Nieprawda.** Przeczytałem osiem wierszy funkcji i wyciągnąłem wniosek z fragmentu,
w którym stoi *pierwszy* przypadek (główny checkout). Dalej, w przypadku trzecim,
obie gałęzie **obsługują drzewo bez `.git`**:

| gałąź | schemat nazwy dla kopii bez `.git` |
|---|---|
| `robota/bazy-stanowisk` (#920) | `kuking_test_kat_<katalog>_<sha256, 8 znaków>` |
| `naprawa/baza-proby-per-runtime` | `kuking_test_kopia_<katalog>_<sha1, 8 znaków>` |
| `flota/kontrakt-nazw-baz` (#966) | `kuking_test` + `kuking_sufiks_kopii()` |

`#920` dokumentuje przy tym pułapkę, której pozostałe nie wymieniają: **Postgres
obcina identyfikator do 63 bajtów BEZ OSTRZEŻENIA**, więc dwie za długie nazwy
potrafią po cichu zejść się w jedną bazę — czyli wrócić dokładnie do błędu, który
ten plik naprawia. Stąd limit 43 znaków na sufiks, a nie 50, „które stało tu
wcześniej i dawało 70 znaków, czyli ciche obcięcie".

**Czyli to nie jest wybór między dwiema wersjami tej samej wady, tylko między
TRZEMA poprawnymi, niezgodnymi schematami nazw.** Wygrać może jeden; dwa scalone
naraz dadzą dwie reguły nazywania tej samej rzeczy.

Stary, błędny akapit zostaje niżej jako zapis tego, co twierdziłem:

~~**Ale spór jest bezprzedmiotowy.**~~ Obie gałęzie wyprowadzają nazwę bazy z obecności
`.git`:

```php
$domyslna = 'kuking_test';
$wskaznikGit = $katalogRepo.'/.git';
```

**To jest dokładnie ta wada, która wywraca runtime'y floty** — `przygotuj-runtime.sh`
kopiuje z `--exclude '.git'`, więc wszystkie stanowiska dostają tę samą nazwę
`kuking_test` na współdzielonym klastrze, a `proba-odtworzenia.sh` robi na niej
`DROP DATABASE ... WITH (FORCE)`. Stąd chwiejność `ProbaOdtworzeniaTest`.

Kontrakt z **PR #966** robi to inaczej — liczy skrót pełnej ścieżki:
```php
return 'kuking_test'.kuking_sufiks_kopii($katalogRepo);
```

**Rekomendacja: scalić #966, obie gałęzie tej pary wycofać albo przepisać na kontrakt.**
Wybieranie między nimi to wybór między dwiema wersjami tej samej wady.

---

## 2. `zeszyty` vs `jedna-droga` — prawdziwy spór, 6 plików

Obie ruszają 77 tych samych plików, **różni się 6**:

| plik | różnica |
|---|---|
| `resources/views/components/post-card.blade.php` | +84 / −23 |
| `app/Http/Controllers/CollectionController.php` | +79 / −8 |
| `tests/Feature/UsuniecieZZeszytuMaZakresTest.php` | +74 / −8 |
| `resources/views/pages/recipes/show.blade.php` | +21 / −11 |
| `app/Domain/Collections/Actions/SavePostToCollection.php` | +16 / −7 |
| `app/Domain/Collections/Actions/SaveRecipeToCollection.php` | +16 / −7 |

Commity różnią się o **jedną sekundę** (22:41:34 i 22:41:35) — obie powstały przy tym
samym odzysku po awarii, więc „nowsza" nic tu nie rozstrzyga.

To dotyka `#775` z triażu, podniesionego do **P0**: „Usuń z zeszytu" kasuje przepis
ze **wszystkich** zeszytów razem z notatkami z pivotu, jednym kliknięciem, bez
potwierdzenia. Nazwa testu `UsuniecieZZeszytuMaZakresTest` mówi, że **obie gałęzie
próbują to naprawić** — pytanie brzmi, która robi to lepiej, a nie która jest świeższa.

**Wymaga przeczytania obu wersji `CollectionController` i porównania zachowania.**
Nie rozstrzygam, bo to decyzja o tym, co ma się dziać z cudzymi notatkami.

---

## 3. `flota/prywatnosc-formularz` vs `gemini-794` — ledwie się stykają

`prywatnosc-formularz` rusza **5** plików, `gemini-794` **70**. Wspólnych: **5**,
różnią się **3**:

| plik | różnica |
|---|---|
| `app/Http/Controllers/ReportController.php` | +2 / −69 |
| `resources/views/pages/report.blade.php` | +11 / −2 |
| `resources/views/pages/settings/privacy.blade.php` | 0 / −6 |

Kierunek istotny: idąc od `prywatnosc-formularz` do `gemini-794` **ubywa 69 wierszy**
w `ReportController` — czyli mniejsza gałąź niesie tam **więcej** kodu. `gemini-794`
jest o dzień starsza (20.09 22:41 wobec 21.09 08:05).

**To nie wygląda na parę konkurencyjną**, tylko na dwie prace o różnym zakresie,
które przypadkiem dotykają trzech tych samych plików. Prawdopodobnie da się scalić
obie — ale `ReportController` wymaga obejrzenia, bo 69 wierszy to nie jest drobiazg.

---

## 4. `gpt-zdjecia-limity` vs `gpt-zdjecia-publikacja` — jeden plik naprawdę sporny

Wspólnych plików: **74**, różnią się **3**:

| plik | różnica |
|---|---|
| `resources/js/app.js` | +49 / −109 |
| `resources/views/pages/posts/create.blade.php` | +3 / −3 |
| `resources/views/pages/cooked/create.blade.php` | +1 / −2 |

Dwa widoki to drobiazg. **Cały spór siedzi w `app.js`** i jest asymetryczny:
`gpt-zdjecia-limity` ma tam **109 wierszy, których nie ma** `gpt-zdjecia-publikacja`.

Uwaga na znany kontekst: `app.js` ma dziś **strażnika kompletności importów**
(dołożonego po tym, jak brakujący import przeszedł niezauważony). Scalenie tych
dwóch wersji „obie strony" może dać plik, który przechodzi strażnika i nie działa.

**Rekomendacja: obejrzeć `app.js` w obu wersjach przed scaleniem czegokolwiek.**

---

## Wspólny wniosek

Trzy z czterech par nie są sporami o to samo — są gałęziami o różnym zakresie,
które przypadkiem stykają się w kilku plikach. Jedyny prawdziwy spór merytoryczny
to para nr 2 (**zakres usuwania z zeszytu**), i akurat ona dotyczy zachowania,
które triaż uznał za **P0**.
