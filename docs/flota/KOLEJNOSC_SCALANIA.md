# Plan scalania — 21 września 2026

> ## ⚠ CZYTAJ TEN PLIK OD DOŁU, NIE OD GÓRY
>
> Ten dokument narastał przez cały dzień: góra to stan z rana, a **przypisy na końcu
> (sekcje a)–o\)) prostują ją w kilku miejscach**. Kto przeczyta samą górę, podejmie
> decyzje na danych, które już nie obowiązują.
>
> **`main` NIE jest już `cd966aae`.** Dziś weszły trzy PR-y: `#913` → `d6f2a555`,
> `#920` → `f56f97f0`, `#923` → **`65327e69`**. Każda liczba porównana z `cd966aae`
> jest przeterminowana.
>
> **Co konkretnie na górze jest nieaktualne:**
>
> | miejsce | co mówi | co obowiązuje |
> |---|---|---|
> | §2, „D-230 → `jedna-droga` PRZENUMEROWAĆ" | że `jedna-droga` traci numer | **decyzja właściciela: D-225 ZOSTAJE przy `jedna-droga`**; przenumerowuje się `flota/scal-786` na D-228 — przypisy g) i n) |
> | §2, „D-229 → `gpt-cloudflare-cache`" | że D-229 jest zajęte | **D-229 jest pierwszym WOLNYM numerem** po przeglądzie 141 gałęzi — przypis n) i `MAPA_NUMEROW_DECYZJI.md` |
> | §3 A, `prog-postgresa` **albo** `straznik-r60` | że to wybór | **`straznik-r60` jest PRZODKIEM `prog-postgresa`** — nie ma wyboru, jest wchłonięcie; przypis „21.09, ranek" i przypis nr 2 e) |
> | §1 poz. 2, `flota/scal-915` jako osobna pozycja | że wchodzi osobno | **wchłonięta przez `flota/martwe-kaskady`**, PR #915 zamknięty — przypis nr 2 e) |
> | §1 poz. 3, rozmiar `flota/scal-786` | 117 plików / +12 962 | **42 pliki / +2 046** — mój pomiar starzał się w trakcie; przypis a) |
>
> Pełny spis dokumentów floty i ostrzeżenia o sprzecznościach: **`_wspolne/SPIS.md`**.

---

## Treść pierwotna z rana (stan `main` = `cd966aae`) — czytaj z powyższą tabelą w ręku

> **Ta sekcja zastępuje analizę z 20.09 niżej.** Stara sekcja zostaje jako zapis
> tego, co wtedy wiedziano — ale jej punkt nr 1 („#929 musi iść pierwsze")
> **jest dziś nieprawdziwy**, dowód w §0. Nie czytaj starej sekcji jako instrukcji.
>
> **Metoda:** wszystko poniżej zmierzone przez `git merge-tree --write-tree`
> (próbne scalenie bez dotykania drzewa), `git diff --numstat` i odczyt treści
> plików. Nic nie scalono, nie pchnięto, nie otwarto PR-a, nie zmieniono gałęzi.
> Kolejka = 61 gałęzi (`do-pchniecia.txt` minus `pchniete.txt` ze stanowiska
> pchania). Zlecenie mówiło o 58 — różnicę robią trzy pozycje dorzucone
> przez `wpusc-zaleglosci.sh` o 08:20 (`naprawa/795-powrot-ze-zgloszenia`,
> `naprawa/927-glos-marki-regex`, `naprawa/baza-proby-per-runtime`).

---

## 0. Zanim cokolwiek ruszysz: dwie gałęzie są PUSTE

`git merge-tree --write-tree origin/main <gałąź>` zwraca dla obu **dokładnie
drzewo `origin/main`** (`897dc6bc…` = `origin/main^{tree}`). Scalenie nie zmieni
ani jednego bajtu:

| Gałąź | Dlaczego pusta |
|---|---|
| `naprawa/klient-pg18-w-ci` (#929) | Oba jej commity są już w `main`: `postgresql-client-18` stoi w `.github/workflows/ci.yml` w. 345 i 640, a sonda `pg_isready -h "$BAZA_HOST" -p "$BAZA_PORT"` w `tests/skrypty/proba-odtworzenia.sh` w. 147. |
| `naprawa/proba-odtworzenia-w-ci` | Ta sama jedna linia, to samo drzewo wynikowe. |

**Skutek dla starego planu:** cała konstrukcja „#929 jest warunkiem wejścia dla
jedenastu pozycji" straciła podstawę — nie dlatego, że przestała być ważna, tylko
dlatego, że **`main` już to ma**. Kto będzie dziś czekał na #929, będzie czekał
na nic.

---

## 1. Pierwsze pięć pozycji

| # | Gałąź | Dlaczego tu, a nie niżej |
|---|---|---|
| **1** | `flota/martwe-kaskady` | Ma `flota/kaskada` w historii (`git merge-base --is-ancestor` = tak), więc **scala obie naraz i bezkonfliktowo**. Osobne scalanie `flota/kaskada` na `main`, a potem `flota/martwe-kaskady`, to praca za darmo. Bierze **D-223** — numer, który `flota/scal-786` jawnie dla tej rodziny zarezerwowała (cytat w §2). Wnosi też **najnowszą wersję strażnika kaskady** (`scripts/kaskada-martwe-reguly.mjs`, 37 408 B, samokontrola zawężenia `--tylko`) — kto wejdzie po niej z wersją starszą, musi przy konflikcie wybrać TĘ. |
| **2** | `flota/scal-915` | Musi iść **po** poz. 1, nie przed. Konflikt jest pewny (`docs/DECISIONS.md`, `scripts/kaskada-kontrola-polecenie.sh`, `scripts/kaskada-martwe-reguly.mjs`) i **rozwiązanie nie jest „wybierz stronę"**: jej strażnik jest starszy (35 098 B, 2 wystąpienia `--tylko` zamiast 8, domyślne zawężenie `.przepis-liczba` zamiast `.przepis-liczba svg`), ale **niesie treść, której poz. 1 nie ma w ogóle**: poprawkę SIGPIPE `PULAPKI_TESTOW.md §5c` oraz cały katalog `tests/mutacje/` ze `scripts/mutacje.sh`. Rozwiązanie: **skrypty z poz. 1, dokumentacja i `tests/mutacje/` z poz. 2**, `docs/DECISIONS.md` — porzuć jej kopię D-223 (już jest). `docs/PULAPKI_TESTOW.md` scala się automatycznie. |
| **3** | `flota/scal-786` | Największy węzeł kolejki: `tests/bootstrap.php`, `scripts/cleanup-test-dbs.sh`, `tests/Unit/NazwaTestowejBazyTest.php`, `tests/skrypty/proba-odtworzenia.sh`, `scripts/check.sh`, `.github/workflows/ci.yml` i **D-225 + D-226**. Koliduje z **czterema** innymi pozycjami kolejki; wszystkie cztery są od niej mniejsze. Wchodząc pierwsza, narzuca kontrakt nazw baz, do którego pozostałe się dostroją. Wchodząc później — trzeba ją przepisać w całości. |
| **4** | `naprawa/baza-proby-per-runtime` | Konflikt z poz. 3 w `tests/bootstrap.php`, `tests/Unit/NazwaTestowejBazyTest.php`, `scripts/cleanup-test-dbs.sh`. Po poz. 3 jest to konflikt **do dostrojenia**, przed poz. 3 — do przepisania. |
| **5** | `naprawa/jedna-regula-nazw-baz` | Trzecia reguła nazywania. Konflikt z poz. 4 w `tests/bootstrap.php` **potwierdzony pomiarem** — to nie jest zależność „na papierze". Dodatkowo koliduje z poz. 3 w `.claude/hooks/session-start.sh`. Musi iść jako ostatnia z trójki baz, inaczej reguła nr 3 opisuje stan, którego jeszcze nie ma. |

Dalej (kolejność mniej krytyczna, ale nie dowolna):
**6.** `flota/prog-postgresa` (`flota/straznik-r60` **wchłonięta** — patrz §3 A i przypis niżej) ·
**7.** `flota/zdjecia-formularze` · **8.** `naprawa-858` ·
**9.** `gpt-cloudflare-cache` · **10.** `jedna-droga` ·
**11.** klaster `Notification.php` (§6) · … ·
**PRZEDOSTATNIA:** `naprawa/kontrola-dodatnia-do-przodu` (§4 C7) ·
**OSTATNIA:** `naprawa/testy-js-wchodza-do-ci` (§5).

Dwie twarde zależności jednokierunkowe poza pierwszą piątką:
`odzysk/heic-119` **przed** `naprawa/119-obietnica-konwersji` (§4 C8);
`gpt-zalegle` **razem z** poprawką `CHANGELOG.md`, jeśli wchodzi
`naprawa/podbicie-wersji-wymaga-wpisu` (§4 C6).

---

## 2. Numery decyzji — kolizja jest szersza, niż mówiła lista zlecenia

`main` ma dziś D-222 i **D-224**; **D-223 jest dziurą**. O wolne numery walczy
**sześć różnych decyzji**, nie trzy:

| Numer | Kto go zajmuje | Co to jest |
|---|---|---|
| **D-223** | `flota/kaskada` = `flota/martwe-kaskady` = `flota/scal-915` | „Martwe reguły CSS: strażnik pyta o wynik kaskady, nie o tekst arkusza" — **jedna decyzja na trzech gałęziach**, nie trzy kolizje |
| **D-223** | `flota/prog-postgresa` | „PostgreSQL 18 jest wymaganiem, nie preferencją" |
| **D-223** | **`notyfikacja-zywa`** | „Powiadomienie śledzi treść komentarza (#758)" — **tego na liście zlecenia NIE BYŁO** |
| **D-225 + D-226** | `flota/scal-786` | „Nazwa bazy testowej wynika ze ścieżki katalogu" + „Przyrządy rozpoznają rodzinę baz" |
| **D-225** | **`gpt-cloudflare-cache`** | „Godzinny podpis zdjęcia publicznego, bez cache sesji (#597/#610)" — **nie było na liście** |
| **D-225** | **`jedna-droga`** | „Jedna droga wyjęcia wpisu z zeszytu (#775, #776)" — **nie było na liście** |

`flota/scal-786` faktycznie przenumerowała się świadomie; jej commit `ba69bf16`
mówi wprost: *„Numer D-223 zostawiam wolny dla PR #915, [decyzje] gałęzi
(bez ani jednego odwołania z zewnątrz) idą na D-225 i D-226"*. Zależność
**potwierdzona** i ukryta w treści, zgodnie z listą — ale samo to przenumerowanie
**stworzyło nową kolizję potrójną na D-225**, o której nikt nie wiedział.

**Zalecany rozdział numerów** (honoruje rezerwację `scal-786`, minimalizuje pracę):

```
D-223 → rodzina kaskady (poz. 1 i 2)
D-225 → flota/scal-786          (bez zmian)
D-226 → flota/scal-786          (bez zmian)
D-227 → flota/prog-postgresa    PRZENUMEROWAĆ
D-228 → notyfikacja-zywa        PRZENUMEROWAĆ
D-229 → gpt-cloudflare-cache    PRZENUMEROWAĆ
D-230 → jedna-droga             PRZENUMEROWAĆ
```

> **Pułapka odsyłacza.** `jedna-droga` nie tylko zakłada D-225 — ona **dopisuje
> do istniejącego w `main` D-224 zdanie „Sprostowane — patrz D-225"** i w treści
> kilkakrotnie odsyła do „D-225". Jeśli D-225 zostanie przy `flota/scal-786`
> (nazwy baz testowych), to zdanie w D-224 będzie **poprawnie zbudowane i
> całkowicie fałszywe** — odeśle czytelnika decyzji o zeszycie do decyzji
> o bazach. Git tego nie zgłosi: pliki się zgadzają, tylko sens nie.
> **Przy przenumerowaniu `jedna-droga` trzeba poprawić też odsyłacze w prozie.**

---

## 3. Pary „albo jedna, albo druga"

Wszystkie potwierdzone próbnym scaleniem i porównaniem treści.

### A. `flota/prog-postgresa` ⟂ `flota/straznik-r60` — DECYZJA WŁAŚCICIELA
Obie odzyskują ten sam, nieistniejący w `main`, plik
`tests/Feature/TestyChodzaNaPostgresieTest.php` — **289 z ~300 dodanych linii jest
identycznych**, różnica to stała: `MINIMALNY_MAJOR = 16` (r60) kontra `18` (próg).
Kolidują też w `docs/MAPA_REGUL_DOWODY.md`.
**To nie jest kolizja techniczna, tylko rozstrzygnięcie:** `flota/prog-postgresa`
zmienia przy okazji `AGENTS.md` z „*lokalnie i w CI wystarczy 16+*" na
„*lokalnie i w CI też 18+*" — czyli **zmienia regułę, którą stała uzasadnia**.
Wzięcie `straznik-r60` po `prog-postgresie` i rozwiązanie konfliktu „po swojemu"
(16) da `AGENTS.md` mówiący 18 i strażnika przepuszczającego 16. **Cicha
sprzeczność, zielone CI.** Nie domykaj tego sam.

### B. `flota/scal-786` ⟂ `robota/bazy-stanowisk`
Cztery wspólne pliki (`tests/bootstrap.php`, `tests/Unit/NazwaTestowejBazyTest.php`,
`scripts/cleanup-test-dbs.sh`, `tests/skrypty/proba-odtworzenia.sh`), to samo
zadanie #736 dwiema drogami. W `NazwaTestowejBazyTest.php` pokrywa się tylko
**8 ze 150 / 82 dodanych linii** — to są dwie różne implementacje, nie dwie kopie.
**Rekomendacja: `flota/scal-786`** (ma dodatkowo bramkę zakresu w `ci.yml`,
`scripts/bezpiecznik-bazy.mjs` i scalony już `main`).

### C. `naprawa-858` ⟂ `tagi-filtr`
Prawie identyczne (47 wspólnych plików, w tym pliki dowodowe zgodne **co do linii**:
`pelne-testy.txt` — 4909 identycznych wierszy). `naprawa-858` jest nadzbiorem:
`tagi-filtr` nie ma 366 linii, w tym `TagiEnterFiltrujeNieZapisujeTest`,
`TagiPrzegladanieOsobnyLimiterTest`, wpisu `tagi_przegladanie` w `config/kuking.php`
i trasy w `routes/web.php`. **Bierz `naprawa-858`, `tagi-filtr` odrzuć.**

### D. `powiadomienia` ⟂ `straznik-format`
`straznik-format` = `powiadomienia` **+ 90 linii `tests/Feature/StrefaCzasowaTest.php`**;
w drugą stronę różnica to 2 linie. Wszystkie pozostałe 11 plików zgadza się
**co do każdej dodanej linii**. **Bierz `straznik-format`.**

### E. `gpt-cloudflare-cache` ⟂ `gpt-sonda-wdrozenia` — patrz też §4 C1, to jest CICHE
Te same **cztery commity o identycznych tytułach**, odzyskane dwa razy pod różnymi
SHA; `gpt-cloudflare-cache` ma piąty commit ponadto. Dziesięć plików pokrywa się
co do linii. **Bierz `gpt-cloudflare-cache`.**

### F. `gpt-ci-architektura` ⟂ `gpt/widmo-zamkniec`
`docs/infra/PLAN_TECHNICZNY_614.md`: **97 ze 101 dodanych linii wspólnych**,
konflikt pewny. Ten sam dokument napisany dwa razy. Scalać jedną.

### G. `odzysk/dowody-martwe-kaskady` ⊂ `flota/martwe-kaskady`
Wszystkie cztery pliki dowodowe są w `flota/martwe-kaskady` **identyczne co do
linii**. Osobne scalenie nie wnosi nic.

---

## 4. Ciche zderzenia — git NIE zgłosi konfliktu

### C1. Funkcja zdefiniowana dwa razy w jednym pliku bash *(potwierdzone pomiarem)*
`gpt-cloudflare-cache` **+** `gpt-sonda-wdrozenia`. Próbne scalenie obu po kolei
kończy się **czysto** — a wynikowy `scripts/sprawdz-wdrozenie.sh` ma **dwie pełne
definicje `pobierz_naglowki()`** (20 linii ponad wariant z samym
`gpt-cloudflare-cache`). Bash po cichu bierze ostatnią. Żadnego konfliktu,
żadnej czerwieni, `shellcheck` też tego nie nazwie błędem.
**To jest dokładnie ten wzorzec, o który pytało zlecenie.**

### C2. `AGENTS.md` mówi 18, strażnik przepuszcza 16
Para A z §3. Sprzeczność siedzi w **dwóch różnych plikach**
(`AGENTS.md` kontra `tests/Feature/TestyChodzaNaPostgresieTest.php`) i ujawni się
tylko wtedy, gdy ktoś rozwiąże konflikt stałej na korzyść `straznik-r60`.

### C3. Odsyłacz „patrz D-225" w decyzji D-224
Para `jedna-droga` **+** (`flota/scal-786` albo `gpt-cloudflare-cache`).
Opisane w §2. Pliki mogą się nawet nie pokrywać — sprzeczność jest w treści.

### C4. Strażnik kaskady nie jest wpięty w CI — a wygląda na wpięty
`scripts/kaskada-martwe-reguly.mjs` i `scripts/kaskada-kontrola-polecenie.sh`
**nie są wołane ani z `.github/workflows/ci.yml`, ani ze `scripts/check.sh`** —
na żadnej z trzech gałęzi rodziny ani na `main`. Dowody w
`docs/design/evidence/kaskada223/` nie są czytane przez żaden test.
Scalenie poz. 1 i 2 doda więc narzędzie, które **nigdy nie zapali**, a raport
będzie mówił „strażnik jest". **Do decyzji właściciela: wpiąć czy nie** — nie
dopisuj kroku CI samodzielnie, bo to zabetonuje zachowanie, którego nikt nie wybrał.

### C5. Dowody `pomiar-liczby-kolizja3.json` starzeją się bez ostrzeżenia
Rodzina kaskady wnosi zmierzone liczby układu, a `naprawa-858`, `tagi-filtr`,
`gpt-zalegle` i `flota/zdjecia-formularze` **przepisują `resources/css/app.css`
(137 usunięć / 20 wstawek)** i `marka-ekrany.css`. Wszystkie te pary scalają się
**czysto**. Po scaleniu liczby w dowodach opisują arkusz, którego już nie ma —
i nic tego nie zgłosi, bo **żaden test ich nie czyta** (patrz C4).

### C6. Wersja podbita, lista zmian nie *(potwierdzone pomiarem)*
`gpt-zalegle` **+** `naprawa/podbicie-wersji-wymaga-wpisu`. Pierwsza zmienia
w `config/kuking.php` `'etykieta' => 'Alfa 0.67'` na `'Alfa 0.68'` i **nie tyka
`CHANGELOG.md`** (pliku nie ma w jej diffie). Druga wnosi
`tests/Feature/PodbicieWersjiWymagaWpisuWChangelogTest.php`, który wymaga, żeby
najświeższy nagłówek `CHANGELOG.md` zgadzał się z etykietą. Najświeższy nagłówek
na `main` to „Alfa 0.67". Pliki rozłączne, scalenie czyste, produkt po scaleniu
mówi „Alfa 0.68", a lista zmian kończy się na 0.67.
**Poprawka: dopisz wpis „Alfa 0.68" do `CHANGELOG.md` przy scalaniu `gpt-zalegle`.**

### C7. Nowi strażnicy tekstu bez kontroli dodatniej *(potwierdzone pomiarem)*
`naprawa/kontrola-dodatnia-do-przodu` wnosi
`tests/Feature/StraznikTekstuMaKontroleDodatniaTest.php` + `tests/straznicy-tekstu-zastane.txt`
(173 wiersze, **lista zamknięta na `4c811cc7`, „TYLKO SIĘ SKRACA"**). Reguła:
każdy nowy `tests/**/*Test.php`, który czyta źródła i asertuje na ich treści,
musi mieć kontrolę dodatnią albo jawne `@bez-kontroli-dodatniej <powód>`.
**Dziewięć gałęzi kolejki dokłada dokładnie taki plik i żadna nie ma dowodu:**

| Gałąź | Nowy strażnik tekstu |
|---|---|
| `flota/prog-postgresa` / `flota/straznik-r60` | `tests/Feature/TestyChodzaNaPostgresieTest.php` |
| `flota/scal-786` | `BezpiecznikBazyPomiarowejTest`, `SkryptyPytajaOWlasciwyPortTest`, `SprzatanieBazTestowychTest` |
| `flota/zdjecia-formularze` | `PowiekszenieMaStanBleduIPonowienieTest` |
| `gpt-cloudflare-cache` | `tests/Unit/CloudflareCacheGateTest.php`, `tests/Unit/SondaWdrozeniaTest.php` |
| `gpt-sonda-wdrozenia` | `tests/Unit/SondaWdrozeniaTest.php` |
| `hero-ekran` | `PierwszyEkranMiesciPrzyciskTest` |
| `naprawa/podbicie-wersji-wymaga-wpisu` | `PodbicieWersjiWymagaWpisuWChangelogTest` |
| `odzysk/testy-regresyjne` | `PolitykaNieObiecujePelnejKopiiTest` |

Każda z tych par scala się **czysto** — sprzeczność siedzi w rozłącznych plikach.
**Wniosek dla kolejności: `naprawa/kontrola-dodatnia-do-przodu` scalaj PO tych
dziewięciu** (jak `naprawa/testy-js-wchodza-do-ci` z §5 — ta sama mechanika:
strażnik zamykający listę idzie na końcu, nie na początku). Inaczej każda
kolejna pozycja zapala czerwień, której nikt się nie spodziewa.

### C8. Odsyłacz do raportu, który jest na innej gałęzi *(potwierdzone pomiarem)*
`naprawa/119-obietnica-konwersji` powołuje się **dwa razy** — w `docs/DECISIONS.md`
i w `app/Support/RozpoznanieZdjecia.php` — na `docs/research/heic-119/RAPORT.md`.
Ten plik istnieje **wyłącznie** na `odzysk/heic-119`. Scalenie pierwszej bez
drugiej zostawia martwy odsyłacz w dzienniku decyzji; git milczy.
**Scalaj `odzysk/heic-119` przed `naprawa/119-obietnica-konwersji`.**

### Czego szukano i NIE znaleziono
Wzorzec z dzisiejszego „— do smaku" (ta sama fraza wchodząca z dwóch gałęzi
do dwóch różnych ekranów) przeszukano maszynowo: 5-wyrazowe shingle ze wszystkich
1939 dodanych polskich linii w `resources/views/**`, `lang/**`, `resources/legal/**`
i `app/**`. **Ani jednego nowego przypadku.** Wszystkie trafienia to pary
gałęzi-bliźniaków na tym samym pliku (§3 C, D). Progi liczbowe w `config/kuking.php`
też sprawdzono — cztery nowe klucze, każdy na jednej gałęzi, żadnej wartości
zapisanej inaczej gdzie indziej.
*(Ten akapit i C6–C8 pochodzą z osobnego przebiegu wyszukiwania; punktowo
zweryfikowałem C6, C7 i C8 na drzewie sam.)*

---

## 5. `naprawa/testy-js-wchodza-do-ci` — SCALAĆ JAKO OSTATNIĄ

Gałąź zamienia listę `node --test` w `package.json` z 4 plików na 11 i dokłada
`scripts/straznik-testow-js.test.mjs`, który zapala, gdy w `resources/js`,
`scripts` lub `scripts/fixtures` leży `*.test.mjs` spoza listy (a także gdy
moduł z `resources/js` nie jest importowany w `app.js`).

**Lista zlecenia wymagała korekty.** Zmierzone na drzewach gałęzi:

| Gałąź | Plik spoza listy | Zapali? |
|---|---|---|
| `flota/zdjecia-formularze` | `scripts/podglad-object-url.test.mjs` | **TAK** |
| `flota/scal-786` | `scripts/bezpiecznik-bazy.test.mjs` | **TAK** — nie było na liście |
| `gpt-zalegle` | `scripts/offline-ponowienie.test.mjs` | **TAK** — nie było na liście |
| `gpt-obciazenie` | `scripts/korpus-605.test.mjs`, `scripts/probnik-605.test.mjs` | **TAK** (`przyrzad-605.test.mjs` jest już na nowej liście) |
| `gpt-rozbicie-uslug` | `scripts/railway-roles.test.mjs` | **TAK** |
| `gpt-ustawienia-profilu` | `scripts/kopiowanie-adresu.test.mjs`, `scripts/wyglad-nawigacja.test.mjs` | **TAK** |
| `gpt-skladniki` | `scripts/przegladarka/tagi-potwierdzenie.test.mjs` | **NIE** — `scripts/przegladarka` nie jest skanowane |

Uwaga: `gpt-obciazenie`, `gpt-rozbicie-uslug`, `gpt-skladniki`
i `gpt-ustawienia-profilu` **nie są w tej kolejce** (stoją w `pchniete.txt`), ale
wciąż nie ma ich w `main` — dotkną następnej tury.

**Dlaczego ostatnia:** scalona wcześnie, zapala czerwień na każdej kolejnej
pozycji wnoszącej test JS, aż ktoś dopisze plik do `build`. Scalona na końcu —
poprawiasz linię `build` **raz**, znając komplet.

**Ślepy punkt strażnika (do decyzji, nie do cichego łatania):** `scripts/przegladarka`
jest poza `SKANOWANE`, więc test z `gpt-skladniki` przejdzie niezauważony —
dokładnie ta dziura, którą ta gałąź miała zasypać. Asercja `naDysku.length >= 10`
przy równo dziesięciu plikach `*.test.mjs` w `main` też stoi na styk.

---

## 6. Klaster `app/Models/Notification.php` — pięć gałęzi, konflikt już z `main`

`main` ruszył ten plik commitem `ee80a8b2` (#924, komentarze). Wszystkie gałęzie
kolejki wyszły z `4c811cc7`, więc **konfliktują z `main` zanim spotkają się
nawzajem**:

`naprawa/906-zbiorcze-zapisy` · `notyfikacja-zywa` · `powiadomienia` ·
`straznik-format` · `zaleglosci-zgloszen` (ta ostatnia w
`app/Domain/Moderation/Actions/NotifyReporterReceipt.php`).

Do tego dochodzą z kolejki: `jedna-droga`, `naprawa/750-porcje`,
`naprawa/119-obietnica-konwersji`, `gpt/zeszyt-zapisy`,
`gemini/dziennik-wgladow-moderatora`, `flota/zdjecia-formularze`,
`flota/scal-786`, `flota/scal-915`, `flota/prog-postgresa`,
`flota/martwe-kaskady`, `gpt-cloudflare-cache` — każda koliduje
z `notyfikacja-zywa` w `Notification.php` i `tests/Feature/PowiadomieniaWidocznoscTest.php`.

**Kolejność wewnątrz klastra:** `straznik-format` (§3 D) → `notyfikacja-zywa`
(największa, 221 linii) → `naprawa/906-zbiorcze-zapisy` → `jedna-droga` →
reszta. Każda kolejna dostraja się do poprzedniej; **nie ma tu drogi bez
ręcznego rozwiązywania konfliktu.**

Drugi, mniejszy węzeł na `NotifyReporterReceipt.php`:
`zaleglosci-zgloszen` ⟂ `odwolanie-link` ⟂ `naprawa/795-powrot-ze-zgloszenia`
⟂ `gpt/zeszyt-zapisy` ⟂ `flota/prywatnosc-formularz`.
Oraz `flota/prywatnosc-formularz` ⟂ `naprawa/795-powrot-ze-zgloszenia`
w `app/Http/Controllers/ReportController.php` i `resources/views/pages/report.blade.php`.

---

## 7. NIE SCALAĆ W OGÓLE

| Gałąź | Powód | Dowód |
|---|---|---|
| `naprawa/klient-pg18-w-ci` | treść już w `main` | drzewo scalenia == `origin/main^{tree}` |
| `naprawa/proba-odtworzenia-w-ci` | j.w. | to samo drzewo |
| `tagi-filtr` | uboższa kopia `naprawa-858` | §3 C |
| `powiadomienia` | ściśle zawarta w `straznik-format` | §3 D |
| `gpt-sonda-wdrozenia` | ta sama praca co `gpt-cloudflare-cache`; scalenie obu daje **cichy dublet funkcji** | §3 E, §4 C1 |
| `odzysk/dowody-martwe-kaskady` | podzbiór `flota/martwe-kaskady` | §3 G |
| `flota/kaskada` — **nie osobno** | jest przodkiem `flota/martwe-kaskady`; wchodzi razem z poz. 1 | `merge-base --is-ancestor` |

Oraz **jedna z pary** w każdym przypadku §3 A, B, F — po rozstrzygnięciu.

---

## 8. Ogon bez kolizji (28 gałęzi, dowolna kolejność)

Nie dzielą ani jednego pliku z żadną inną pozycją kolejki i scalają się
z `main` czysto:

`flota/nazwa-a-relacje` · `flota/r49-trasy` · `flota/retencja-wyjatkow-audytu` ·
`gemini/adr-warstwa-merytoryczna` · `gpt/haslo-konto` · `gpt/turnstile-pomoc` ·
`hero-ekran` · `naprawa/619-polityka-prawdziwa` · `naprawa/847-termin` ·
`naprawa/927-glos-marki-regex` · `naprawa/kontrola-dodatnia-do-przodu` ·
`naprawa/kruchy-pomiar-wygladu` · `naprawa/podbicie-wersji-wymaga-wpisu` ·
`odzysk/dokumenty-design-infra` · `odzysk/dokumenty-produktowe` ·
`odzysk/dowody-ai-piloty` · `odzysk/dowody-cache-kreator-wersje` ·
`odzysk/dowody-turnstile-848` · `odzysk/dsa-odwolania` · `odzysk/harmonogram-php` ·
`odzysk/heic-119` · `odzysk/qa-granice` · `odzysk/testy-regresyjne` ·
`odzysk/zapas-narzedzia` · `robota/plan-scalania` · `robota/poswiadczenia-decyzje` ·
`robota/stopka-pusty-pas` · `stan-sesji`

Trzy pary z tego ogona dzielą plik z inną pozycją, ale scalają się czysto — i **właśnie
dlatego warto na nie spojrzeć okiem, nie tylko gitem**:
`hero-ekran` + `robota/stopka-pusty-pas` (`resources/css/marka-rama.css`,
`scripts/port-projektu.mjs`), `flota/nazwa-a-relacje` + `gpt-zalegle`
(`SocialController.php`, `recipes/show.blade.php`), `flota/zdjecia-formularze`
+ `gpt-zalegle` (`app.css`, `components/photo.blade.php`).

---

## 9. Ile z listy zlecenia się nie potwierdziło

| Twierdzenie | Werdykt |
|---|---|
| D-223 zajęte w trzech miejscach | **niepełne** — kolizja to **trzy niezależne decyzje**, ale `scal-915` to ta sama decyzja co `kaskada`, a brakuje `notyfikacja-zywa` |
| `scal-786` przenumerowała na D-225/226 zakładając D-223 dla #915 | **potwierdzone**, cytat z commita `ba69bf16` |
| `martwe-kaskady` stoi na `kaskada` | **potwierdzone** |
| `straznik-r60` ⟂ `prog-postgresa`, 16 vs 18, reguła w `AGENTS.md` | **potwierdzone w całości** |
| `scal-915` i `kaskada` — ten sam strażnik, wersja z `kaskada` nowsza | **potwierdzone**, ale **niepełne**: `scal-915` niesie treść unikatową (SIGPIPE §5c, `tests/mutacje/`), więc to nie jest „albo-albo" |
| `testy-js` zapali na pięciu wymienionych gałęziach | **częściowo błędne** — 4 z 5 trafione, `gpt-skladniki` NIE zapali; brakowało `flota/scal-786` i `gpt-zalegle` |
| `jedna-regula-nazw-baz` zależy od `baza-proby-per-runtime` | **potwierdzone**, i jest szersze: to węzeł czterech gałęzi |
| `proba-odtworzenia-w-ci` jest zbędna | **potwierdzone**, ale z innego powodu (nie „bo #929" — #929 też jest puste) |

**Nieaktualnych / niepełnych: 4 z 8** (D-223, para kaskady, lista `testy-js`,
uzasadnienie zbędności). Żadne nie okazało się całkiem fałszywe.
Do tego **stary punkt 1 tego dokumentu („#929 pierwsze") jest dziś martwy.**

---
---
## WYMUSZONA KOLEJNOSC (ustalona z logow CI 20.09, godz. 23:30)

1. **#929 `naprawa/klient-pg18-w-ci`** — musi isc PIERWSZE.
   Niesie krok instalacji klienta PostgreSQL 18 oraz (commit `021264c6`)
   sonde `pg_isready` pytajaca wlasciwy port. Bez niego:
   - #914 oblewa 9 przypadkow kopii bazy kodem `51` (niezgodnosc wersji),
   - `ProbaOdtworzeniaTest` pada z `PostgreSQL nie odpowiada`.
2. **#914 `flota/dsa-odwolania`** — po #929, bez zmian w tresci, samo odswiezenie.
3. **`naprawa/ci-hybryda-runnerow`** — dopiero po niej wolno ustawic zmienne
   `CI_RUNS_ON` i `CI_RUNS_ON_PRZEGLADARKA`. Dzis `CI_RUNS_ON="ubuntu-latest"`,
   a siedem wlasnych runnerow stoi bezczynnie.
4. `naprawa/proba-odtworzenia-w-ci` — po scaleniu #929 jest **zbedna**:
   niesie te sama jedna linie.

# Kolejność scalania otwartych PR-ów

Stan na **20 września 2026, ok. 20:30Z**. Dokument analityczny — **niczego nie scalono,
nie pchnięto i nie zamknięto**. Podstawa: `gh pr list/view/checks`, dzienniki jobów
pobrane przez `gh api .../actions/jobs/<id>/logs`, listy plików z `gh pr view --json files`
oraz zapisane ostrzeżenia z `STAN_SESJI.md` i `STAN_SESJI_CZESC2–8.md`.

**Jak czytać:** idź grupami od (A) do (D). W każdej pozycji stoi numer PR-a, **warunek
wejścia** (co musi być prawdą, zanim naciśniesz merge) i **skutek złamania kolejności**.
Jeśli warunek wejścia nie jest spełniony — pomiń pozycję i wróć do niej później,
nie „popraw przy okazji”.

---

## 0. Jedna rzecz przed wszystkimi: `naprawa/klient-pg18-w-ci`

Ta gałąź **nie ma jeszcze PR-a**, a jest warunkiem wejścia dla jedenastu z czternastu pozycji.
Commit `fa06f5cd`, zmienia **dokładnie jeden plik: `.github/workflows/ci.yml`**.
W chwili pisania jest w kolejce pchania (kolejka 9, start 20:05:34Z).

Dlaczego to jest pierwsze: **jedenaście PR-ów ma identyczną czerwień, która nie pochodzi
z ich kodu.** Dowód w §5.

> **Skutek złamania:** scalisz cokolwiek innego wcześniej → nadal patrzysz na czerwone CI,
> którego nie da się odróżnić od regresji, a przy `checkSuites: true` każde czerwone CI
> w chwili scalenia kosztuje też **pominięte wdrożenie Railway, którego nic nie ponawia samo**
> (`STAN_SESJI.md` §11, w. 577–606).

**Kolizja:** `naprawa/klient-pg18-w-ci` i **PR #786** to jedyna para dotykająca
`.github/workflows/ci.yml`. #786 i tak jest w konflikcie z `main` (grupa C).

---

## 1. Tabela stanu — czternaście otwartych PR-ów

| PR | Gałąź | Zgłoszenia | Scalalność | CI: ile oblanych | Migracje | `resources/legal/` | Grupa |
|---|---|---|---|---|---|---|---|
| **#924** | `flota/komentarze` | #757, #759, #760, #761, #762 | MERGEABLE (UNSTABLE) | 3 (2 × PG18 + 1 własna) | 0 | 0 | **D** |
| **#923** | `flota/zdjecia-formularze` | #742, #743, #744, #745, #747 | MERGEABLE (UNSTABLE) | 2 (obie PG18) | 0 | 0 | **B** |
| **#922** | `flota/relacje` | #780, #791, #793, #803, #880 | MERGEABLE (UNSTABLE) | 3 (2 × PG18 + 1 własna) | 0 | 0 | **D** |
| **#921** | `robota/martwe-reguly-css` | — | MERGEABLE (UNSTABLE) | 2 (obie PG18) | 0 | 0 | **B** |
| **#920** | `robota/bazy-stanowisk` | — | MERGEABLE (UNSTABLE) | 2 (obie PG18) | 0 | 0 | **B** |
| **#919** | `flota/r73-feed` | — | MERGEABLE (UNSTABLE) | 2 (obie PG18) | 0 | 0 | **A** |
| **#918** | `codex/audyt-ux50plus` | — | MERGEABLE (UNSTABLE) | 4 (2 × PG18 + 2 własne) | 0 | 0 | **D** |
| **#917** | `flota/r47-skan` | — | MERGEABLE (UNSTABLE) | 2 (obie PG18) | 0 | 0 | **A** |
| **#916** | `flota/wyszukiwarka` | #737, #738, #753, #763 | MERGEABLE (UNSTABLE) | 2 (obie PG18) | 0 | 0 | **B** |
| **#915** | `robota/kaskada-straznik` | — | **CONFLICTING** | brak sprawdzeń w ogóle | 0 | 0 | **C** |
| **#914** | `flota/dsa-odwolania` | #796, #797, #799, #800 | MERGEABLE (UNSTABLE) | 3 (2 × PG18 + 1 własna) | 0 | 0 | **D** |
| **#913** | `flota/gotowanie` | #739, #740, #751, #755, #756, #764 | MERGEABLE (UNSTABLE) | 3 (2 × PG18 + 1 własna) | 0 | 0 | **D** |
| **#786** | `praca/izolacja-bazy-klon` | — | **CONFLICTING** | brak sprawdzeń w ogóle | 0 | 0 | **C** |
| **#725** | `codex/717-panel-kolejki` | #599 | MERGEABLE (UNSTABLE) | 1 (własna) — **PG18 na zielono** | 0 | 0 | **D** |

**Dobra wiadomość: żaden z czternastu otwartych PR-ów nie rusza migracji bazy ani plików
w `resources/legal/`.** Cała grupa „wstrzymane do decyzji właściciela ze względu na schemat
lub dokumenty prawne” jest w tej turze **pusta** — ale patrz §3, bo pułapki prawne
i migracyjne dotyczą gałęzi, które PR-ów jeszcze nie mają.

---

## 2. Kolizje plikowe między otwartymi PR-ami

Pary z częścią wspólną (wynik porównania list plików każdego PR-a z każdym):

| Para | Wspólne pliki | Ryzyko |
|---|---|---|
| **#913 ↔ #924** | `package.json`, `resources/js/app.js` | **KONFLIKT PEWNY — najgroźniejszy w tej turze.** Patrz niżej. |
| **#923 ↔ #924** | `resources/js/app.js`, `resources/views/components/field.blade.php` | **Konflikt prawdopodobny:** oba zmieniają `field.blade.php` od wiersza 115 (#923: `@@ -115,6`, #924: `@@ -115,8`). W `app.js` trafiają w różne miejsca (#924 w. 21, #923 w. 47). |
| **#921 ↔ #923** | `resources/css/app.css` | Konflikt możliwy |
| **#918 ↔ #923** | `resources/css/app.css` | Konflikt możliwy |
| **#918 ↔ #921** | `resources/css/app.css` | Konflikt możliwy |
| **#915 ↔ #921** | `resources/css/app.css`, `resources/css/marka-ekrany.css` | Konflikt możliwy (#915 i tak w konflikcie z `main`) |
| **#915 ↔ #918** | `resources/css/app.css` | jw. |
| **#915 ↔ #923** | `resources/css/app.css` | jw. |
| **#921 ↔ #922** | `resources/views/pages/profile/show.blade.php` | Konflikt możliwy |
| **#913 ↔ #923** | `resources/js/app.js` | Różne miejsca (#913 w. 21, #923 w. 47) — ryzyko niskie |
| **#786 ↔ #920** | `scripts/cleanup-test-dbs.sh`, `tests/Unit/NazwaTestowejBazyTest.php`, `tests/bootstrap.php`, `tests/skrypty/proba-odtworzenia.sh` | **Cztery pliki wspólne** — obie gałęzie robią to samo zadanie dwiema drogami |
| **#786 ↔ #915** | `docs/DECISIONS.md` | Konflikt możliwy w dzienniku decyzji |
| **#725 ↔ #923** | `resources/views/components/layout.blade.php` | Konflikt możliwy |
| **#786 ↔ `naprawa/klient-pg18-w-ci`** | `.github/workflows/ci.yml` | Patrz §0 |

### PUŁAPKA NR 1: jedna linia `package.json`, dwa PR-y, cicha strata testów

**#913 i #924 zmieniają DOKŁADNIE TĘ SAMĄ LINIĘ** — skrypt `build` w `package.json`:

- #924 dopisuje `resources/js/licznik-znakow.test.mjs`
- #913 dopisuje `resources/js/minutnik-krok.test.mjs resources/js/wake-lock-gotowania.test.mjs`

> **Skutek złamania:** git zgłosi konflikt (dobrze), ale rozwiązanie „biorę swoją wersję”
> **cicho wypisze z buildu testy drugiego PR-a**. Nic nie zaświeci na czerwono — po prostu
> od tego momentu `npm run build` przestanie uruchamiać dwa albo jeden plik testowy.
> **Poprawne rozwiązanie konfliktu to suma wszystkich trzech nazw w jednej linii, nie wybór strony.**

Ten sam PR-y kolidują też w `resources/js/app.js`: obie wstawiają `import` w hunku
`@@ -21`, więc ten konflikt zobaczysz od razu.

---

## 3. Pułapki z dokumentacji floty — z lokalizacją

Wypisane są **wszystkie** znalezione ostrzeżenia o kolejności i zależnościach, także te,
które dotyczą gałęzi bez otwartego PR-a — bo dotkną tej kolejki w następnej turze.

### 3.1 Dotyczy PR-ów otwartych DZIŚ

| # | Ostrzeżenie | Źródło | Dotyczy |
|---|---|---|---|
| P1 | „`SearchQuery.php` i `SearchController.php` **mogą wymagać ręcznego połączenia z gałęzią `wyszukiwarka`** — tamta gałąź ma zmiany `%`, `_` i typu `q` (#753/#738), które trzeba zachować. Po połączeniu uruchomić ponownie testy wyszukiwania na izolowanej bazie.” | `STAN_SESJI_CZESC7.md` w. 232–233 | **PR #916** (`flota/wyszukiwarka`) ↔ `gpt/wyszukiwanie-granice` (#885, #886, bez PR-a) |
| P2 | „Wspólny plik to `resources/views/pages/recipes/cooking.blade.php`; nasze zmiany dotyczą końca widoku i zaproszenia »Ugotowałem«, a tamte składników i minutnika.” + „**Wersja 0.68 jest lokalnym podbiciem z D-134; przy integracji wielu gałęzi koordynator musi rozstrzygnąć ewentualną kolizję numeracji.**” | `STAN_SESJI_CZESC2.md` w. 99 i 101 | **PR #913** (`flota/gotowanie`) ↔ `gpt/ugotowalem-dostep` (#902, #903, #910, bez PR-a) |
| P3 | „**PUŁAPKA SCALANIA:** cofnięcie #803 przywróci możliwość usunięcia nowszego zdjęcia starym formularzem, więc nie jest zalecanym sposobem naprawiania problemów wdrożenia.” | `STAN_SESJI_CZESC2.md` w. 167 | **PR #922** (niesie #803) |
| P4 | „**PUŁAPKA SCALANIA:** wycofanie kodu nie wymaga operacji na bazie, ale cofnięcie #880 przywraca ryzyko nadpisania nowszej decyzji — preferowana jest poprawka do przodu.” | `STAN_SESJI_CZESC2.md` w. 380 | **PR #922** (niesie #880) |
| P5 | „po przestawieniu zmiennej repozytorium `CI_RUNS_ON` na `ubuntu-latest` dwa joby zaczęły padać, ponieważ klient PostgreSQL na tym runnerze jest starszy niż 18, a `pg_restore` odmawia odczytu zdrowego archiwum (kod 51 zamiast 0). Na `self-hosted` te same joby były zielone.” | `STAN_SESJI_CZESC4.md` w. 84–104 | **11 PR-ów** — cała grupa D-PG18, patrz §5 |
| P6 | „przy `checkSuites: true` każde czerwone CI w chwili scalenia kosztuje nie tylko czerwień, ale i **pominięte wdrożenie**, którego nic nie ponawia samo.” + „**Zielone CI po fakcie NIE wskrzesza wdrożenia zamkniętego jako pominięte.**” | `STAN_SESJI.md` §11, w. 577–606 | **wszystkie** |
| P7 | „W opisie każdego PR-a stoi wprost, że **wyniki pochodzą ze stanowiska i nie były weryfikowane ponownie przy otwieraniu** — rozstrzyga CI.” | `STAN_SESJI.md` w. 813–820 | **PR #913–#921** |
| P8 | „kopia stanowiska mogła powstać na starszym `main`, więc zmiany w plikach, których dane stanowisko nigdy nie dotykało, to **nie jego praca, tylko cofnięcie cudzej** — trzeba je przywrócić, nie commitować.” | `STAN_SESJI.md` w. 1376–1379 | wszystkie gałęzie floty po awarii 20.09 ~21:00 |

### 3.2 Zależności gałęzi, które PR-ów jeszcze nie mają — przeczytaj przed następną turą

| # | Ostrzeżenie | Źródło | Dotyczy |
|---|---|---|---|
| Q1 | „**PUŁAPKA SCALANIA:** przy przenoszeniu do kolejki najpierw musi znaleźć się implementacja #871–#874; jeśli już jest na main, nie trzeba ponownie przenosić `b03621e7`.” (commit `b03621e7` to cherry-pick `6718d527`) | `STAN_SESJI_CZESC2.md` w. 244–250 | `gpt/zdjecia-limity` **po** `gpt/zdjecia-publikacja` |
| Q2 | „**Numer wersji 0.68 podnoszą co najmniej DWIE gałęzie** (`gpt/zalegle` i `gpt/zdjecia-publikacja`). Przy szeregowym scalaniu druga dostanie konflikt albo, gorzej, cicho nadpisze pierwszą.” — a z P2 wynika, że podnosi go też **trzecia**, `gpt/ugotowalem-dostep` | `STAN_SESJI.md` w. 546–560 + `CZESC2` w. 101 | `gpt/zalegle`, `gpt/zdjecia-publikacja`, `gpt/ugotowalem-dostep` |
| Q3 | „**PUŁAPKA WYCOFANIA, CIĘŻSZA NIŻ SAMA POPRAWKA:** nie wolno cofnąć klasy #888, gdy czekają zadania NOWEGO formatu. Stara klasa oczekuje modelu `User`, a nowe zadania niosą jawny adres. Wycofanie wymaga skoordynowania zatrzymania workerów, obsługi zaległości i wersji kodu.” + „**Przed wdrożeniem trzeba rozstrzygnąć, co zrobić z już oczekującymi starymi ostrzeżeniami.**” | `STAN_SESJI.md` P16, w. 907–922 | `gpt/konto-poczta` (#888, commit `c4db01c3`) |
| Q4 | „**`flota/zeszyty` JEST ZASTĄPIONA przez `robota/jedna-droga-z-zeszytu`. NIE scalać osobno.**” — inaczej na ekranie zeszytu renderują się dwa przyciski wyjścia naraz | `STAN_SESJI.md` §9, w. 406–422 | `flota/zeszyty` (wyjęta), `robota/jedna-droga-z-zeszytu` |
| Q5 | „**Migracje ODMAWIAJĄ wycofania, gdy groziłoby ono utratą danych** […] Wycofanie licznika wersji wymaga wyłączenia zapisu i unieważnienia otwartych formularzy **przed** ponownym uruchomieniem.” — trzy migracje | `STAN_SESJI.md` w. 372–385 | `gpt/kontakt-panel` (#844) |
| Q6 | „**Krok druku wymaga `pdftotext` i `pdfinfo` NA RUNNERZE.** […] Trzeba to sprawdzić PRZED scaleniem, bo inaczej pierwsze czerwone CI po scaleniu będzie wyglądało na regresję kodu, a będzie brakiem pakietu.” | `STAN_SESJI.md` w. 546–556 | `gpt/zalegle` — **ta sama klasa pułapki co PG18** |
| Q7 | „**integrator musi rozstrzygnąć, z której gałęzi bierze finalną wersję sondy, żeby nie zdublować lub nie cofnąć zmian.**” | `STAN_SESJI_CZESC7.md` w. 103–104 | `gpt/cloudflare-cache` ↔ `gpt/sonda-wdrozenia` |
| Q8 | „Przy scalaniu obu gałęzi zachować również rozdzielenie zadań i bramkę dostępu z `gpt/moderacja-ai` — nie przywracać całego starego `PrzeanalizujTresc` z tej gałęzi.” | `STAN_SESJI_CZESC7.md` w. 185 | `gpt/openai-granice` ↔ `gpt/moderacja-ai` |
| Q9 | „Kolejność wdrożenia obowiązkowa: najpierw poprawka harmonogramu na `all`, potem worker/scheduler, na końcu web — nigdy odwrotnie.” (+ 5 dalszych punktów kolejności rollbacku) | `STAN_SESJI.md` w. 1189–1209 | `gpt/rozbicie-uslug` (#595, #598, #600) |
| Q10 | „Wycofanie: najpierw wyłączyć reguły eligible, zachować końcowy BYPASS, wyczyścić istniejące wpisy cache; **dopiero potem** `git revert` aplikacji.” + „**#610 — autor jawnie pisze: NIE WŁĄCZAĆ.**” | `STAN_SESJI_CZESC7.md` w. 111, 101, 77 | `gpt/cloudflare-cache` (#597, #610) |
| Q11 | „Przed cofnięciem ochrony zdjęć wyłączyć ocenę obrazów lub wyczyścić klucz modelu, żeby nie przywrócić znanej drogi wysyłki dużego obrazu.” | `STAN_SESJI_CZESC7.md` w. 197 | `gpt/openai-granice` (#912) |
| Q12 | `resources/legal/polityka-prywatnosci.md:9` obiecuje „serwery w Unii Europejskiej”, a jurysdykcja bucketów nie jest odczytana; „samo dopisanie »`.eu.`« do endpointu nie migruje danych”. | `STAN_SESJI.md` w. 1115–1120, 1150–1154, 1231 | `gpt/r2-jurysdykcja` (#619) — **jedyna droga, którą `resources/legal/` wejdzie do kolejki** |
| Q13 | „**maksymalnie jedno zbiorcze powiadomienie push dziennie.** Nie wolno stworzyć drugiej, konkurencyjnej reguły.” | `STAN_SESJI.md` w. 1246–1247 | `gpt/zeszyt-droga` (#906) ↔ `gpt/pwa-push` (#35) |
| Q14 | „pilotów nie podpinać pod ten sam klucz [`OPENAI_MODERATION_KEY`], bo nie da się potem rozdzielić rachunku ani wyłączyć jednego bez drugiego.” | `STAN_SESJI.md` w. 1233 | `gpt/ai-piloty` ↔ `gpt/moderacja-ai` |
| Q15 | „nie skopiowano ani nie nadpisano jej poprawek […] »mają trafić własną drogą przez kolejkę integracji«” | `STAN_SESJI.md` w. 1070–1073 | `gpt/pytania-poradzcie` ↔ `gpt/pytania-widoki` |
| Q16 | „ekran obserwowanych tagów pozostaje w gestii `gpt/tagi-filtr`” | `STAN_SESJI_CZESC2.md` w. 11 | `gpt/tagi-miejsce` ↔ `gpt/tagi-filtr` |
| Q17 | „uwaga na próbę scalenia samej propozycji jako gotowej poprawki” — gałąź niesie dokument, nie poprawkę | `STAN_SESJI_CZESC3.md` w. 136–145 | `gpt/widget-wyglad` (#684) |
| Q18 | „powrót do tematu wymaga wyniku #605 (obciążenie) i osobnej decyzji” | `STAN_SESJI_CZESC7.md` w. 137 | `gpt/dr-zdjecia` (#617, #602) po #605 |
| Q19 | Cztery raporty stanowisk **nie mają commitów** (padły metadane Gita) — `gpt/offline-obietnica`, `gpt/grupy-tematyczne`, `gpt/planer-zakupy`, `gpt/redis-ha`. Niczego z nich nie ma w kolejce. | `STAN_SESJI_CZESC8.md` | cztery gałęzie dokumentacyjne |

**Czego NIE znaleziono:** nigdzie nie ma gotowej, uporządkowanej listy „kolejność scalania 1, 2, 3”.
Ten dokument jest pierwszą taką listą i **nie zastępuje decyzji właściciela** tam, gdzie
dokumentacja wprost o nią prosi.

---

## 4. GRUPY — kolejność do wykonania

### GRUPA A — gotowe i bezpieczne

Wejdą jako pierwsze, bo **nie kolidują z niczym** i nie niosą żadnej zapisanej pułapki.

| Kolejność | PR | Warunek wejścia | Skutek złamania kolejności |
|---|---|---|---|
| **A1** | **#919** `flota/r73-feed` — strażnik feedu | `naprawa/klient-pg18-w-ci` na `main` **i** ponowione CI na zielono | Zobaczysz czerwień z `ProbaOdtworzeniaTest`, która nie ma nic wspólnego z tym PR-em — a przy `checkSuites: true` zapłacisz pominiętym wdrożeniem |
| **A2** | **#917** `flota/r47-skan` — strażnik poświadczeń | jw. | jw. |

#919 zmienia **jeden plik** (`tests/Feature/FeedNieSortujePoMierzeReakcjiTest.php`),
#917 **dwa** (`docs/MAPA_REGUL_DOWODY.md`, `tests/Feature/PoswiadczeniaPozaRepozytoriumTest.php`).
Żaden nie ma części wspólnej z jakimkolwiek innym otwartym PR-em.

### GRUPA B — gotowe, ale kolejność ma znaczenie

| Kolejność | PR | Warunek wejścia | Skutek złamania kolejności |
|---|---|---|---|
| **B1** | **#920** `robota/bazy-stanowisk` — nazwa testowej bazy z katalogu, nie z nieistniejącego `.git` | PG18 na `main`, CI ponowione. **Scalać PRZED #786.** | #786 robi to samo zadanie inną drogą i dotyka **czterech tych samych plików**; scalone w odwrotnej kolejności dadzą dwa mechanizmy nazywania bazy naraz. #920 rusza też `tests/skrypty/proba-odtworzenia.sh`, czyli ten sam skrypt, który dziś oblewa CI — po jego scaleniu **obowiązkowo przeczytaj nowy log, zanim uznasz kolejną czerwień za starą** |
| **B2** | **#921** `robota/martwe-reguly-css` | PG18 na `main`. **Scalać PRZED #923 i #918**, bo usuwa martwe reguły z `resources/css/app.css`, na którym stoją tamte dwa | Scalony po nich będzie kasował reguły, których tamte PR-y właśnie zaczęły używać — konflikt albo, gorzej, ciche usunięcie żywego stylu. Uwaga też na `resources/views/pages/profile/show.blade.php` wspólny z #922 |
| **B3** | **#923** `flota/zdjecia-formularze` (#742–#747) | PG18 na `main`, **#921 już scalone**. Ma tylko czerwień PG18, żadnej własnej | Wejście przed #921 → konflikt na `app.css`. Wejście po #924 → konflikt na `field.blade.php` (oba od w. 115) |
| **B4** | **#916** `flota/wyszukiwarka` (#737, #738, #753, #763) | PG18 na `main`. Bez konfliktów z otwartymi PR-ami | **Pułapka P1:** gdy później przyjdzie `gpt/wyszukiwanie-granice` (#885, #886), trzeba **ręcznie połączyć** `SearchQuery.php` i `SearchController.php`, zachowując z tego PR-a obsługę `%`, `_` i typu `q`, a potem **ponownie uruchomić testy wyszukiwania na izolowanej bazie**. Naiwne scalenie tamtej gałęzi cofnie te trzy poprawki |

### GRUPA C — wstrzymane do decyzji, nie do scalenia dziś

| PR | Powód wstrzymania | Co trzeba rozstrzygnąć |
|---|---|---|
| **#786** `praca/izolacja-bazy-klon` | **CONFLICTING z `main`**, brak jakichkolwiek sprawdzeń CI, **41 plików**, dotyka `.github/workflows/ci.yml` (kolizja z `naprawa/klient-pg18-w-ci`) i czterech plików wspólnych z #920 | Czy #920 (mały, zielony po PG18) nie zastępuje tego PR-a — dokładnie tak, jak `robota/jedna-droga-z-zeszytu` zastąpiła `flota/zeszyty` (Q4). Jeśli nie zastępuje: rebase na `main` **po** scaleniu PG18 i #920, potem CI od zera |
| **#915** `robota/kaskada-straznik` | **CONFLICTING z `main`**, brak sprawdzeń CI, 19 plików, kolizje CSS z #921/#918/#923 i `docs/DECISIONS.md` z #786 | Rebase po #921; `docs/DECISIONS.md` rozstrzygnąć ręcznie — to dziennik decyzji, nie plik do scalenia „biorę swoją wersję” |

> **Skutek złamania:** scalenie #786 przed #920 i przed PG18 wywróci `ci.yml` w trakcie
> naprawy CI — czyli zabierze narzędzie, którym mierzysz wszystko pozostałe.

### GRUPA D — czerwone z własnego powodu

Wszystkie mają **także** czerwień PG18, ale po jej usunięciu **nadal będą czerwone**.
Przyczyny poniżej pochodzą z dzienników jobów, nie z domysłu.

| PR | Własna czerwień | Dowód z logu | Warunek wejścia |
|---|---|---|---|
| **#918** `codex/audyt-ux50plus` | 2 joby portu marki | `Error: K509_OVERFLOW /login {"width":320,"scroll":329,"rootFont":32,...}` oraz `Error: NAV638_DUZY_FONT_OVERFLOW` | Naprawić przepełnienie ekranu logowania przy 320 px i dużym foncie. **Ironia do odnotowania: PR o minimach UX 50+ oblewa własny strażnik UX 50+** |
| **#913** `flota/gotowanie` | „Port marki — rodziny ekranów” | 5 scenariuszy minutnika `FAIL`: `locator.click: Timeout 30000ms exceeded … waiting for locator('.cook-timer').first().locator('.cook-timer-start') … locator resolved to <button hidden="" …>` | Przycisk startu minutnika renderuje się z atrybutem `hidden` i nigdy nie staje się widoczny. To **własna funkcja tego PR-a**. Dodatkowo: konflikt `package.json` z #924 (pułapka nr 1) i pułapka P2 z `gpt/ugotowalem-dostep` |
| **#914** `flota/dsa-odwolania` | „Panel marki — puste i pełne widoki” | `DOMAIN_CHANGED` — asercja `requireThat(isDeepStrictEqual(before, await take()), 'DOMAIN_CHANGED')` w `scripts/panel-validation.mjs:142` | Scenariusz `appeal-no-outcome` **zmienił stan dziedziny** przy nieudanej walidacji formularza odwołania. To dokładnie obszar tego PR-a. Nie scalać: odwołanie bez wyniku nie ma prawa niczego zapisać |
| **#725** `codex/717-panel-kolejki` | „Panel marki” — **jedyna czerwień, PG18 jest zielone** | `P581_MENU_BEZ_JS_KOMPLET` | PR zmienia `resources/views/components/layout.blade.php` (menu) i oblewa strażnika kompletności menu bez JavaScriptu. Zgodne z D-053: menu ma działać bez JS. Poprawić, nie obchodzić |
| **#924** `flota/komentarze` | „Port marki — rodziny ekranów” | `page.waitForFunction: Timeout 30000ms exceeded at sprawdzBladPodWygladem (scripts/szybki-wyglad.mjs:240)` | **Stan nierozstrzygnięty — patrz niżej** |
| **#922** `flota/relacje` | „Port marki — rodziny ekranów” | **identyczny ślad** co #924, ten sam etap, po tym samym `NOTICE_OK` | **Stan nierozstrzygnięty — patrz niżej** |

#### #924 i #922 — czerwień, której NIE przypisuję jednoznacznie

Obie gałęzie oblewają **ten sam etap, tym samym komunikatem, w tym samym miejscu**
(`sprawdzBladPodWygladem`, czyli sprawdzenie, że błąd walidacji pojawia się pod polem).
Przemawia to za wspólną przyczyną albo za chwiejnym testem. **Ale** trzeci PR
uruchomiony w tej samej minucie (#923) ten sam job **zaliczył**, więc nie jest to
awaria całego runnera.

> **Nie rozstrzygam tego z logu i nie zgaduję.** Warunek wejścia dla obu: po scaleniu
> PG18 **ponowić CI i porównać, czy oba nadal padają w tym samym punkcie**. Jeśli tak —
> to jest wspólna regresja do osobnego zadania, nie do naprawy przy scalaniu.
> Oba PR-y dotykają walidacji formularzy, więc wspólna przyczyna jest prawdopodobna.

---

## 5. Czerwień PG18 kontra czerwień własna — dowód

To najważniejsze rozróżnienie w tym dokumencie. **Jedenaście PR-ów ma czerwień,
która nie pochodzi z ich kodu.**

### Sygnatura A — job „Testy (PostgreSQL 18)”

Na **wszystkich jedenastu** (#913, #914, #916, #917, #918, #919, #920, #921, #922, #923, #924)
wynik końcowy jest identyczny co do wzoru:

```
FAILED  Tests\Feature\ProbaOdtworzeniaTest > skrypty kopii i proby odtwor…
Tests:    1 failed, 4393–4419 passed (83694–83892 assertions)
```

Jedyny oblany test, z przyczyną wprost w logu:

```
Expected: PostgreSQL nie odpowiada — nie ma czego dowodzić.\n KOD=1
To contain: KOD=0
at tests/Feature/ProbaOdtworzeniaTest.php:68
```

### Sygnatura B — job „Pint (styl kodu)”

Ten job uruchamia też baterię kontroli kopii bazy. Na wszystkich jedenastu:

```
── Archiwum OBCIĘTE: na PRAWDZIWYM pliku i PRAWDZIWYM pg_restore ──
  ✓ fikstura odkodowuje się do niepustego pliku
  ✗ pełne, zdrowe archiwum PRZECHODZI weryfikację
     oczekiwano: kod=0
     otrzymano:  kod=51
  ✗ archiwum obcięte do 99% zostaje ODRZUCONE
     oczekiwano: kod=53
     otrzymano:  kod=51
  … (i tak osiem razy)
Oblane: 9, zdane: 158
```

**Kod 51 pada nawet na ZDROWYM archiwum** — to nie jest wynik o jakości kodu, tylko
o wersji klienta `pg_restore` na runnerze. Zgadza się co do kodu błędu z zapisem
w `STAN_SESJI_CZESC4.md` w. 84–104 (pułapka P5).

### Czego te dwie sygnatury NIE oznaczają

- **Nie oznaczają, że po scaleniu PG18 wszystko zaświeci na zielono.** Oznaczają, że
  te dwa joby przestaną kłamać. Sześć PR-ów z grupy D ma osobne, własne czerwienie.
- **Nie sprawdzałem, czy poprawka `fa06f5cd` faktycznie usuwa kod 51 na `ubuntu-latest`** —
  nie uruchamiałem CI z tą gałęzią. Potwierdzi to dopiero pierwsze ponowienie po jej scaleniu.
- Bloki „8 failed, 6 passed” i „1 failed (3 assertions)”, które widać w środku każdego
  logu przy `WyborZeszytuMaWalidacjeTest`, `KafelDodawaniaPrzyDuzymTekscieTest`
  i `PanelModeracjiWMenuTest`, to **zamierzone kontrole negatywne** z kroku
  `python3 scripts/kontrole-negatywne-alfa08.py`, zakończonego komunikatem
  „Pięć kontroli negatywnych wykryły regresje; źródła przywrócone.”
  **Nie liczyć ich jako porażek.**

---

## 6. Kolejność w jednej linii

```
naprawa/klient-pg18-w-ci  →  ponowić CI wszystkim  →  #919  →  #917
   →  #920  →  #921  →  #923  →  #916
   →  [decyzja: #786 vs #920]  →  [rebase #915]
   →  [naprawa własnych czerwieni: #918, #913, #914, #725, potem #924 i #922]
```

Przy każdym kroku: **scalać pojedynczo i czekać na zielone CI przed następnym**,
bo przy `checkSuites: true` czerwień w chwili scalenia kasuje wdrożenie bezpowrotnie (P6).

---

## 7. Granice tego dokumentu

- To **analiza, nie wykonanie**. Nic nie scalono, nie pchnięto, nie zamknięto,
  nie skomentowano w żadnym PR-ze.
- Stany CI są **migawką z ok. 20:30Z**. Każde ponowienie je zmienia.
- Kolizje plikowe wyliczono z **list plików**, nie z próbnego scalenia. Para z częścią
  wspólną **może** dać konflikt; para bez części wspólnej **nie da konfliktu tekstowego**,
  ale wciąż może dać konflikt semantyczny (dwa PR-y zmieniające to samo zachowanie
  w różnych plikach).
- Przyczyny czerwieni w grupie D pochodzą **z dzienników jobów**. Tam, gdzie log nie
  rozstrzyga (#924, #922), jest to napisane wprost zamiast zgadywania.
- Dokument **nie rozstrzyga** żadnej z decyzji, które dokumentacja floty rezerwuje dla
  właściciela: losu #786 wobec #920, numeracji 0.68 (Q2), obsługi zaległych zadań #888 (Q3)
  ani treści polityki prywatności (Q12).

---

## 8. Cicha kolizja `build` w PR #913 (`flota/gotowanie`) i PR #924 (`flota/komentarze`)

Obie gałęzie zmieniają **dokładnie tę samą linię `build`** w `package.json`, dopisując do niej
własne pliki `node --test`. Git zaświeci konflikt, ale odruch „biorę swoją wersję" **po cichu
wypisze z buildu testy drugiej gałęzi** — nic nie zaświeci na czerwono, bo usunięty plik testowy
po prostu przestaje się uruchamiać. Sprawdzone na `origin/main` z 2026-09-20:

- **`main`:**
  `node scripts/kontrast-marki.mjs && node --test scripts/pwa-install.test.mjs scripts/panel-komunikat.test.mjs resources/js/tagi-w-opisie.test.mjs && vite build`
- **`flota/gotowanie` dokłada:** `resources/js/minutnik-krok.test.mjs resources/js/wake-lock-gotowania.test.mjs`
- **`flota/komentarze` dokłada:** `resources/js/licznik-znakow.test.mjs`
- **Inne gałęzie:** sprawdzono wszystkie `origin/flota/*`, `origin/robota/*`, `origin/gpt/*`,
  `origin/codex/*` istniejące 2026-09-20 — żadna inna nie dotyka linii `build`. Kolizja jest
  wyłącznie między tymi dwoma PR-ami.

**Docelowa treść linii `build` (suma obu, nic nie ginie):**

```
"build": "node scripts/kontrast-marki.mjs && node --test scripts/pwa-install.test.mjs scripts/panel-komunikat.test.mjs resources/js/tagi-w-opisie.test.mjs resources/js/licznik-znakow.test.mjs resources/js/minutnik-krok.test.mjs resources/js/wake-lock-gotowania.test.mjs && vite build",
```

**Konflikt w `resources/js/app.js` (hunk `@@ -21`):** to również kolizja czysto addytywna, nie
semantyczna. `flota/gotowanie` dopisuje po `import './tagi-w-opisie.js';` dwie linie:
```
import {pozostaloSekund, formatMinutySekundy, kluczStanu, zapiszStan, odczytajStan} from './minutnik-krok.js';
import {utworzKontrolerWakeLock} from './wake-lock-gotowania.js';
```
a `flota/komentarze` w tym samym miejscu dopisuje jedną:
```
import './licznik-znakow.js';
```
Reszta zmian `flota/gotowanie` w tym pliku (przebudowa minutnika kroku, kontroler wake locka) leży
dalej w pliku i nie nachodzi na nic z `flota/komentarze`. Rozstrzygnięcie: zachować wszystkie trzy
importy, w dowolnej kolejności między sobą, zaraz po `tagi-w-opisie.js`.

**Dowód (próbne scalenie w odłączonym worktree, 2026-09-20):** `git worktree add --detach` z
`origin/flota/gotowanie`, `git merge origin/flota/komentarze`, ręczne rozstrzygnięcie dwóch
konfliktów jak wyżej, `npm run build` w przygotowanym środowisku WSL. Wynik: **33/33 testów JS
przeszło**, w tym wszystkie z obu gałęzi:
- `resources/js/minutnik-krok.test.mjs` (5 testów, `flota/gotowanie`, issue #751/#740)
- `resources/js/wake-lock-gotowania.test.mjs` (4 testy, `flota/gotowanie`, issue #739)
- `resources/js/licznik-znakow.test.mjs` (4 testy, `flota/komentarze`)
- `resources/js/tagi-w-opisie.test.mjs` (3 testy, wspólny plik z `main`)
- plus 17 testów z `panel-komunikat.test.mjs` i `pwa-install.test.mjs` (niezmienione przez żadną
  z dwóch gałęzi). `vite build` zakończył się poprawnie.

**Co się stanie, jeśli ktoś rozwiąże to odruchowo „swoją wersją":** build przejdzie zielono, ale
cicho przestaną się uruchamiać testy drugiej gałęzi (`minutnik-krok`+`wake-lock-gotowania` albo
`licznik-znakow`, zależnie który PR scalano jako drugi) — regresja w tym module nie zablokuje już
żadnego joba, dopóki ktoś ręcznie nie zauważy brakującego pliku w linii `build`.

---

## Weryfikacja PR #929 (2026-09-20) — czy kod 51 faktycznie zniknął

**Run CI:** https://github.com/woogitsu/kuking.pl/actions/runs/35535179533 (gałąź `naprawa/klient-pg18-w-ci`)

### Zadanie 1 — job "Pint (styl kodu)" (bateria kontroli kopii bazy) — PASS, 1m36s

- Krok instalacji klienta zadziałał: wykryto `pg_restore (PostgreSQL) 16.15 (Ubuntu 16.15-1.pgdg24.04+2)`,
  doinstalowano `postgresql-client-18`, wersja po instalacji: **`pg_restore (PostgreSQL) 18.6 (Ubuntu 18.6-1.pgdg24.04+2)`**.
- `✓ pełne, zdrowe archiwum PRZECHODZI weryfikację` — brak jakiegokolwiek `✗` w całym logu, brak linii
  „oczekiwano: kod=0 / otrzymano: kod=51”. **Kod 51 na zdrowym archiwum zniknął.**
- Test obcięcia znów rozróżnia przypadki: zdrowe archiwum przechodzi (✓), a obcięte do 99/95/90/80/60%,
  skrócone o jeden bajt i uszkodzone w środku — każde zostaje **ODRZUCONE** (✓ w drugą stronę). Log nie
  wypisuje surowych kodów wyjścia przy sukcesie (framework testowy pokazuje szczegóły tylko przy porażce),
  więc dosłownej wartości „53” nie da się zacytować z tego logu — ale funkcjonalnie test obcięcia znów
  **mierzy różnicę** między zdrowym a obciętym archiwum, czego wcześniej (przy kodzie 51 na obu) nie robił.

### Zadanie 2 — job "Testy (PostgreSQL 18)" — **FAIL, 8m23s**

`Tests\Feature\ProbaOdtworzeniaTest` nadal pada, i to z **dokładnie tym samym** komunikatem co przed
poprawką z #929:

```
Expected: PostgreSQL nie odpowiada — nie ma czego dowodzić.
KOD=1
To contain: KOD=0
at tests/Feature/ProbaOdtworzeniaTest.php:68
```

Wynik pełny: `Tests: 1 failed, 4393 passed (83694 assertions)`.

**To jest inna przyczyna niż kod 51** — „PostgreSQL nie odpowiada” to problem gotowości/łączności z serwerem,
nie odczytu archiwum przez `pg_restore`. Poprawka z #929 (instalacja klienta 18) na ten test nie ma wpływu
i faktycznie go nie naprawiła.

### Zadanie 3 — werdykt

1. **Czy kod 51 zniknął?** TAK — na zdrowym, pełnym archiwum żadna kontrola w jobie „Pint (styl kodu)”
   nie zwraca już kodu 51; klient `pg_restore` na runnerze to teraz 18.6 wobec serwera 18.
2. **Czy obcięte archiwa dają znowu 53 (test obcięcia znów mierzy)?** CZĘŚCIOWO POTWIERDZONE — dosłownej
   wartości kodu 53 log nie ujawnia (bo asercje przy sukcesie nie drukują kodów), ale zachowanie jest
   poprawne: zdrowe przechodzi, obcięte w każdym wariancie zostaje odrzucone — czyli test znów odróżnia
   te dwa przypadki, co było sednem obawy.
3. **Czy `ProbaOdtworzeniaTest` przeszedł?** NIE. Nadal pada z `KOD=1` / „PostgreSQL nie odpowiada — nie ma
   czego dowodzić” — to osobna, niezaadresowana przez #929 przyczyna (gotowość/łączność serwera PostgreSQL
   w CI), nie kod 51.

### Czy teza „sześć PR-ów przejdzie po samym ponowieniu” się broni?

**NIE w pełni.** Poprawka z #929 usuwa jeden z dwóch znanych źródeł czerwieni (kod 51 z `pg_restore` na
zdrowym archiwum) — to potwierdzone. Ale job „Testy (PostgreSQL 18)” w tym samym runie nadal jest czerwony,
z **innej, nieadresowanej przyczyny** (`ProbaOdtworzeniaTest`, PostgreSQL nie odpowiada, KOD=1). Jeżeli
którykolwiek z niżej wymienionych PR-ów opiera swoją czerwień na tym samym jobie/teście, samo ponowienie CI
po scaleniu #929 **nie wystarczy** — dopóki przyczyna „PostgreSQL nie odpowiada” nie zostanie osobno
zdiagnozowana i naprawiona.

PR-y wymienione w tezie: **#919, #917, #920, #921, #923, #916** — każdy z nich wymaga odrębnego sprawdzenia,
czy jego czerwień pochodzi wyłącznie z kodu 51 (wtedy retry po scaleniu #929 pomoże), czy też dotyka
`ProbaOdtworzeniaTest`/gotowości PostgreSQL (wtedy retry nic nie da bez dodatkowej poprawki). Nie da się
tego rozstrzygnąć bez sprawdzenia logów każdego z tych PR-ów z osobna — tego zadania nie wykonywano w ramach
tej weryfikacji (zakaz ponawiania CI na innych PR-ach).


---

## OSTRZEŻENIE — gałęzie cofające cudzą pracę (weryfikacja odzysku, 20 września 2026)

Pełna weryfikacja 56 gałęzi-ODZYSK (`_wspolne/WERYFIKACJA_ODZYSKU.md`) wykryła, że poniższe gałęzie
**nie powinny być scalane bez ręcznego przeglądu** — obie sprawy dotyczą decyzji **D-224**
(„Wpis wychodzi z zeszytu tam, gdzie widać, że w nim jest”, commit #789 w origin/main):

1. **`gpt-n1-powiadomienia`** i **`notyfikacja-zywa`** — obie kasują cały wpis **D-224**
   w `docs/DECISIONS.md` i wstawiają w tym samym miejscu swój własny wpis D-223. Jeśli automat scali
   którąkolwiek z nich wprost na `main`, wpis D-224 zniknie z dokumentacji decyzji. Obie gałęzie mają
   też niemal identyczny zestaw dotkniętych plików (prawdopodobnie to samo zadanie wykonane dwa razy)
   — scalić najwyżej jedną, po weryfikacji która wersja jest kompletna.

2. **`zeszyty`** — w `resources/views/components/post-card.blade.php` cofa faktyczny skutek D-224/#789:
   zamienia bezwarunkowy przycisk „Usuń z zeszytu” (widoczny wszędzie, gdzie widać „Masz to w zeszycie”)
   na wariant widoczny wyłącznie wewnątrz konkretnego zeszytu, przywracając w feedzie stan sprzed #789
   (sam odnośnik, bez drogi wyjścia). Przed scaleniem trzeba połączyć to z pracą `jedna-droga` (ten sam
   plik, zachowuje globalny przycisk i tylko dokłada wariant lokalny) — **nie scalać `zeszyty` osobno
   i przed `jedna-droga`**, bo pierwsza scalona zabierze funkcję D-224 z feedu.

Szczegóły, dowody (`git diff`, `git merge-base`) i pełna lista sprawdzonych plików współdzielonych —
patrz `_wspolne/WERYFIKACJA_ODZYSKU.md`.

---

## Przypis z 21.09, ranek — `flota/straznik-r60` jest WCHŁONIĘTA, nie alternatywna

§3 A stawiała `flota/prog-postgresa` i `flota/straznik-r60` jako wybór „albo–albo".
**Pomiar mówi, że wyboru nie ma.** `git merge-base --is-ancestor 4ac1a336 flota/prog-postgresa`
odpowiada twierdząco: `straznik-r60` (`4ac1a336`) jest **przodkiem** `prog-postgresa`.
Po scaleniu `prog-postgresa` gałąź `straznik-r60` nie wnosi ani jednego bajtu.

Dwie rzeczy do sprostowania w starym tekście:

1. **Ostrzeżenie o skasowanej sekcji było odwrócone.** `straznik-r60` wstawia
   „### R73 rozpisane" **dwa razy** (wadliwe wklejenie przy odzysku). Commit
   `d900af7b` usunął **duplikat**, nie oryginał. Sekcje „R47 rozpisane" i
   „R73 rozpisane" są w `prog-postgresa` obecne, po jednym razie, jak na `origin/main`.
   Cały wkład `straznik-r60` jest zachowany: wiersz R60 w sekcji A, liczniki
   sekcji A 11→12 i C 16→15, zdanie „R60 jest od 20.09 zamknięta".

2. **D-223 nie jest sporne dla tej rodziny.** §2 wpisuje `flota/prog-postgresa`
   do walki o D-223. Gałąź używa **D-227**. Ten wiersz §2 jest nieaktualny.

**Co z tym zrobić:** `flota/straznik-r60` **zostaje w kolejce pchania** (pchanie nic
nie kosztuje i daje kopię pracy poza maszyną), ale **wypada z kolejności scalania**.
Nie otwierać dla niej PR-a. Gdyby kiedykolwiek miała iść **przed** `prog-postgresa`
albo zostać zrebase'owana — wprowadzi z powrotem zdublowaną sekcję „R73 rozpisane".

---

## Przypis nr 2 z 21.09, przedpołudnie — cztery sprostowania i dwie pary rozstrzygnięte

### a) Rozmiar `flota/scal-786` — moja liczba była nieaktualna
Wpisałem do PR #966 „117 plików, +12 962". **Prawda: 42 pliki, +2 046 / −364.**
Zmierzyłem, gdy bazą scalenia gałęzi był jeszcze `4c811cc7`; o 07:54 autor wciągnął
do niej `cd966aae` (`ba69bf16`) i praca `main` przestała się liczyć jako praca gałęzi.
Cztery „niezwiązane testy", które wymieniłem, weszły na `main` własnymi commitami
(`bd48fce3`, #789, #916, #918).

**Skutek:** rozdzielenie gałęzi było niepotrzebne — `flota/kontrakt-nazw-baz`
(`c07d7701`) ma drzewo **identyczne** z `flota/scal-786`. Wszystkie 42 pliki to kontrakt.

**Nauka:** liczba z `git diff --stat` starzeje się, gdy ktoś wciągnie `main` do gałęzi.
Przed wpisaniem rozmiaru do PR-a **zmierzyć ponownie** i podać obok bazę scalenia.

### b) Para A — scalać TYLKO `naprawa-858`
`naprawa-858` jest nadzbiorem `tagi-filtr`: 45 z 47 wspólnych plików identycznych
haszem, a z 236 wierszy dodanych przez `tagi-filtr` brakuje **5** — dokładnie tych,
które `naprawa-858` świadomie przepisała. **Scalenie `tagi-filtr` do niej dałoby dwie
definicje `przegladaj()` i trzy przyciski przeglądania w widoku.** `gpt-tagi` domyka
rodzinę (wersja sprzed `TagFollowWindow`) — też wypada.

### c) Para B — scalać TYLKO `straznik-format`
Ścisły nadzbiór `powiadomienia`: 12 wspólnych plików bajt w bajt, plus jeden więcej
(`tests/Feature/StrefaCzasowaTest.php`). `git merge-tree` daje drzewo identyczne ze
`straznik-format`. Konflikt z `main` jest gałąź-kontra-main, nie w parze: gałąź dokłada
`celIstniejeNadal()`, `main` (#924) `urlDoKomentarza()` — metody rozłączne, „obie strony"
jest poprawne.

### d) MINA — dwie różne migracje pod jedną nazwą pliku
`notyfikacja-zywa` i `gpt-n1-powiadomienia` wnoszą
`2026_09_20_120000_usun_zamrozone_wycinki_komentarzy.php` **o RÓŻNEJ treści**.
Jedyna taka kolizja w kolejce 115 gałęzi. **Wejście obu to twarda awaria.**
Decyzja o wyborze wersji jest merytoryczna (co znika i komu), nie techniczna —
czeka na właściciela. **Do tego czasu nie scalać żadnej z tych dwóch.**

### e) Wchłonięcia potwierdzone dziś (`--is-ancestor`, w OBU kierunkach)
| wchłonięta | przez | skutek |
|---|---|---|
| `flota/straznik-r60` | `flota/prog-postgresa` | wypada ze scalania, zostaje w pchaniu |
| `flota/scal-915` | `flota/martwe-kaskady` | PR #915 zamknięty |
| `flota/kaskada` | `flota/martwe-kaskady` | wypada |
| `praca/izolacja-bazy-klon` (#786) | `flota/scal-786` | #786 zostaje otwarty na życzenie właściciela |
| `odzysk/dowody-martwe-kaskady` | `flota/martwe-kaskady` | wypada |
| `gpt-sonda-wdrozenia` | `gpt-cloudflare-cache` | wypada |

**Metoda, której trzymać się przy każdym „albo–albo" z tego planu:** `--is-ancestor`
jest **niesymetryczne**. Pytanie zadane w złą stronę daje odpowiedź prawdziwą
i bezużyteczną. Pytać w obu kierunkach, zawsze.

### f) Numery decyzji
D-223 → rodzina kaskady (17 odwołań w kodzie). Próg PostgreSQL przeniesiony na
**D-227** (`9b80b617`). D-225/D-226 wolne dla `flota/scal-786` — sprawdzone na `cd966aae`.
**D-225 chcą jeszcze trzy inne gałęzie**; decyzja właściciela: numer zostaje przy
`jedna-droga` (26 odwołań), pozostałe przenumerować — `flota/scal-786` i
`gpt-cloudflare-cache` mają zero odwołań, więc są najtańsze do przesunięcia.

**Uwaga:** `NumeryDecyzjiMajaWpisyTest` łapie duplikat dopiero **po** scaleniu drugiej
gałęzi — gdy odwołania w kodzie od dawna wskazują dwie decyzje naraz.

### g) ŻYWA KOLIZJA D-225 — `jedna-droga` kontra PR #966

`jedna-droga` wypchnięta 21.09 przedpołudniem. **Obie gałęzie zajmują D-225:**

| gałąź | numery | odwołań do D-225 |
|---|---|---|
| `jedna-droga` | D-224, **D-225** | **22** |
| `flota/scal-786` (#966) | **D-225**, D-226 | **2** |

Decyzja właściciela: numer zostaje przy `jedna-droga`. **#966 ma przenumerować
swoje D-225 na D-228** (wolne: main ma D-220…D-222 i D-224; D-223 to dziura dla
kaskady, D-227 wziął `prog-postgresa`). **D-226 z #966 jest bezsporne.**

Zapisane jako komentarz na PR #966, nie jako commit — nie robimy po cichu zmian
pod cudzym otwartym PR-em.

**Dlaczego to pilne mimo zielonego CI:** `NumeryDecyzjiMajaWpisyTest` łapie duplikat
**dopiero po scaleniu drugiej gałęzi**. Osobno obie są zielone. Czerwień wyjdzie
na `main` po fakcie, gdy odwołania w kodzie będą już wskazywać dwie decyzje pod
jednym numerem.

**To trzecia rzecz dziś, którą strażnik wykrywa dopiero po szkodzie** — obok
duplikatu migracji (`notyfikacja-zywa` / `gpt-n1-powiadomienia`) i cichego zderzenia
definicji funkcji przy scaleniu czystym. Wzór wart osobnej uwagi: **strażniki tego
repozytorium pilnują stanu `main`, a nie kolejki czekającej na scalenie.**

### h) Narzędzie: `_wspolne/kolizje-w-kolejce.sh`

Sprawdza gałęzie kolejki **przeciw sobie**, nie przeciw `main`. Powstało 21.09
po trzeciej kolizji tego samego dnia. Nic nie zmienia, nie wchodzi do CI, nie
zużywa minut — uruchamiane na żądanie:

    MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/kolizje-w-kolejce.sh

Szuka trzech rzeczy, których git **nie zgłasza** przy czystym scaleniu:
1. ten sam numer `D-xxx` **nadany** przez dwie gałęzie,
2. ta sama nazwa pliku migracji przy **różnej treści** (twarda awaria),
3. ta sama definicja klasy/funkcji dodana przez dwie gałęzie (sygnał, nie wyrok).

**Wynik pierwszego czystego przebiegu (103 gałęzie, `main` = `cd966aae`):**
D-223 sporne przez 5 gałęzi (kaskada 16 odwołań kontra para powiadomień 13/12),
D-225 przez 3 (`jedna-droga` 22, `scal-786` 2, `gpt-cloudflare-cache` 1),
mina migracyjna potwierdzona haszami `eee2804d` / `ae41ec26`.

**PUŁAPKA, którą to narzędzie samo w sobie miało i którą naprawiono —
nie cofać tej poprawki.** Pierwsza wersja liczyła **każde wystąpienie** numeru
zamiast jego **nadania** i zgłosiła D-052 jako sporne przez trzy gałęzie.
D-052 stoi na `main` od dawna; gałęzie tylko się do niego odwoływały
(`### Uzupełnienie D-052/D-055 …`). Sygnałem fałszu były **trzy identyczne
liczby odwołań (39, 39, 39)** — prawdziwy spór prawie nigdy nie jest idealnie
symetryczny. Poprawka: liczyć wyłącznie dodane **nagłówki** `## D-xxx`, plus
drugi bezpiecznik odrzucający numery już będące nagłówkami na `main`.

### i) Czwarty raz ta sama pułapka pomiaru — zapisz to raz na zawsze

`naprawa/klient-pg18-w-ci` po wypchnięciu:

```
git diff --name-only origin/main origin/naprawa/klient-pg18-w-ci | wc -l   ->  62
git merge-tree --write-tree origin/main origin/naprawa/klient-pg18-w-ci    ->  897dc6bc
git rev-parse origin/main^{tree}                                           ->  897dc6bc
```

**62 pliki różnicy, a scalenie nie zmieni ANI BAJTU.** Te 62 pliki to praca
samego `main`, o którą gałąź jest w tyle — nie jej wkład.

To jest ta sama pomyłka, która dziś wyszła:
1. przy `flota/scal-786` (podałem 117 plików zamiast 42, trafiło do PR #966),
2. przy czterech „rozbieżnych parach" (8 057 wierszy zamiast kilkunastu plików),
3. przy fałszywym D-052 w strażniku kolejki,
4. tutaj.

**Reguła, której trzymać się bez wyjątku przy ocenie, co gałąź wnosi:**

| pytanie | narzędzie |
|---|---|
| **co ta gałąź wniesie po scaleniu** | `git merge-tree --write-tree origin/main <g>` i porównać z `origin/main^{tree}` |
| w czym dwie gałęzie się nie zgadzają | pliki zmieniane przez OBIE wobec `main`, z nich te o różnym haszu |
| czy jedna zawiera drugą | `git merge-base --is-ancestor` **w obu kierunkach** |
| **do niczego z powyższych** | `git diff --stat origin/main <g>` — mierzy odległość, nie wkład |

Ostatni wiersz jest najważniejszy: `git diff --stat` odpowiada na pytanie,
którego zwykle nie zadajemy, i robi to przekonująco.

### j) DOGRYWKA — gałęzie z commitem nowszym niż to, co jest na origin

Kolejka `kolejka9` przerabia listę raz; gałąź już przez nią przepuszczona **nie
wróci**, nawet jeśli dostała nowy commit. Takie przypadki idą do
`/home/mateusz/flota/dogrywka.txt` i pchamy je **po** opróżnieniu głównej kolejki
— nigdy równolegle, bo dwie kolejki naraz dały w nocy „142 fałszywe porażki".

| gałąź | na origin | lokalnie | co wnosi nowy commit |
|---|---|---|---|
| `flota/zdjecia-formularze` | `24f7a59f` | **`6c5345ab`** | rozwiązanie konfliktu z `main` w `field.blade.php` |

Ten konflikt był **jednoplikowy i komplementarny**: `main` dokładał licznik znaków
(`#762`), gałąź strażnika tablicy w polu skalarnym (`#745`) — obie tuż po tej samej
linii `$current`. Rozwiązanie „obie strony" jest poprawne i już istnieje
(`6c5345ab`): sprawdzone, że plik ma `is_scalar` **i** `licznikZnakow`, i zero
znaczników konfliktu.

### k) ROZSTRZYGNIĘTE: schemat nazw baz testowych to `_kat_` z `#920`

`main` = **`f56f97f0`** — „Licz nazwę testowej bazy z katalogu, nie z nieistniejącego
`.git`" (#920). Decyzja właściciela z 21.09 po południu.

**Obowiązujący schemat dla kopii bez `.git`:**
```
kuking_test_kat_<katalog, do 30 znakow, male litery>_<sha256 sciezki, 8 znakow>
```

**Dwie gałęzie muszą się teraz do niego dostroić — nie odwrotnie:**

| gałąź | jej schemat | co zrobić |
|---|---|---|
| `flota/scal-786` / `flota/kontrakt-nazw-baz` (#966) | `kuking_test` + `kuking_sufiks_kopii()` | przepisać na `_kat_`; **reszta kontraktu zostaje** (bezpiecznik rodzin baz, D-225/D-226, sprzątanie) |
| `naprawa/baza-proby-per-runtime` | `kuking_test_kopia_<kat>_<sha1:8>` | przepisać na `_kat_` |

**Czego z `#920` nie wolno zgubić przy dostrajaniu:** limit **43 znaków** na sufiks
i powód, dla którego tam stoi — Postgres obcina identyfikator do **63 bajtów bez
ostrzeżenia**, więc dwie za długie nazwy schodzą się po cichu w jedną bazę, czyli
wracają dokładnie do błędu, który ta zmiana naprawia. Wcześniej stało tam 50,
co dawało 70 znaków. Żadna z dwóch pozostałych gałęzi tej pułapki nie wymienia.

**Skutek uboczny, warty sprawdzenia:** to jest naprawa przyczyny chwiejności
`ProbaOdtworzeniaTest` (wszystkie runtime'y floty widziały bazę `kuking_zrodlo_proby_glowny`,
a skrypt robi na niej `DROP ... WITH (FORCE)`). **Istniejące runtime'y to kopie —
nie dostaną poprawki, dopóki nie powstaną na nowo.**

### l) MÓJ BŁĄD przy ocenie `#920` — czytanie fragmentu zamiast funkcji

Napisałem w `PARY_ROZBIEZNE.md`, że `#920` i `naprawa/baza-proby-per-runtime`
„obie wyprowadzają nazwę z `.git`" i mają tę samą wadę. Przeczytałem **osiem wierszy**
funkcji — akurat ten fragment, gdzie stoi przypadek pierwszy (główny checkout) —
i wyciągnąłem wniosek. Dalej, w przypadku trzecim, obie obsługują drzewo bez `.git`.

Uratował mnie tytuł commita („Licz nazwę testowej bazy **z katalogu, nie z nieistniejącego
`.git`**"), który jawnie przeczył mojemu wnioskowi. **Gdyby commit nazywał się
»poprawka bootstrap«, scaliłbym to z fałszywym opisem albo wstrzymał bez powodu.**

Reguła na przyszłość: przy ocenie, co gałąź robi z regułą, **czytać całą funkcję,
nie okolice pierwszego trafienia grepa** — zwłaszcza gdy funkcja ma jawne przypadki
1./2./3., co widać po komentarzach.

### m) MINA MIGRACYJNA ROZBROJONA — była mniejsza, niż wszyscy twierdzili

Trzy niezależne źródła nazwały `2026_09_20_120000_usun_zamrozone_wycinki_komentarzy.php`
na `notyfikacja-zywa` i `gpt-n1-powiadomienia` „jedyną taką kolizją w kolejce"
i „twardą awarią przy wejściu obu". **Przeczytałem obie wersje. To nieprawda.**

`up()` jest **identyczny bajt w bajt** w obu:
```php
DB::table('notifications')
    ->whereIn('type', self::TYPY)
    ->whereRaw("jsonb_exists(data, 'excerpt')")
    ->update(['data' => DB::raw("data - 'excerpt'")]);
```
`TYPY` też: `['comment.created', 'comment.replied']`.

**Cała różnica to brzmienie stałej `WYCOFANIE_NIC_NIE_ROBI`** — tekstu, który tłumaczy
człowiekowi, czemu `down()` nic nie robi. Obie mówią to samo: wartości `excerpt`
nie ma skąd odczytać, jedynym źródłem jest kopia zapasowa sprzed migracji.
`notyfikacja-zywa` ma wersję krótszą z dopisanym docblockiem, `gpt-n1-powiadomienia`
dłuższą bez niego. **76 wierszy wobec 74.**

**Skutek:** to jest **zwykły konflikt treści**, który git zgłosi i który rozwiązuje się
wybraniem dowolnego z dwóch tekstów. Nie ma ryzyka dla danych, bo operacja kasująca
jest ta sama. Obie gałęzie **wypadają z `wstrzymane.txt`** jako mina — zostaje przy
nich tylko zwykła uwaga o konflikcie.

**Dlaczego trzy źródła się pomyliły:** wszystkie porównywały **hasze plików**
(`ae41ec26` ≠ `eee2804d`) i na tej podstawie orzekły „różna treść". Hasz odpowiada
na pytanie „czy identyczne", a nie „czy różnią się czymś, co ma znaczenie".
**Przy plikach, które coś kasują, trzeba przeczytać `up()`, a nie porównać skrót.**

### n) D-225 zostaje przy `jedna-droga` — decyzja utrzymana mimo nowego pomiaru

Pełny przegląd **141 gałęzi** pokazał, że `naprawa/ci-hybryda-runnerow` ma **28 odwołań**
do D-225 — więcej niż `jedna-droga` (22). Czyli według kryterium kosztu, które sam
zaproponowałem, numer należałby się tamtej gałęzi.

**Właściciel utrzymał decyzję** z dwóch powodów, oba mocniejsze od samej liczby:
`jedna-droga` **jest już na origin i wypchnięta**, a `naprawa/ci-hybryda-runnerow`
dotyczy hybrydy runnerów — sprawy, którą dziś zamknęło przejście na własne runnery,
więc ta gałąź może w ogóle nie wejść.

**Nauka o kryterium:** „numer zostaje tam, gdzie odwołań więcej" jest dobrym
domyślnym rozstrzygnięciem, ale **przegrywa z pytaniem, co naprawdę wejdzie do `main`**.
Liczba odwołań mierzy koszt przeniesienia, a nie wartość gałęzi.

**Nauka o moim pomiarze:** liczyłem odwołania **tylko na gałęziach z kolejki** (103),
a numery nadaje się na wszystkich (141). Przy każdym takim rachunku pytać o **wszystkie
gałęzie**, nie o te akurat pchane. Pierwszy naprawdę wolny numer to **D-229**.

### o) `#725` — czerwień zdiagnozowana, poprawka to jeden wiersz

Job „Panel marki — puste i pełne widoki" oblewał na `MENU_BEZ_JS_KOMPLET`
(`scripts/panel-marki.mjs:262`): test wymaga **9** pozycji menu bez JS, a gałąź dodaje
dziesiątą — wpis „Kolejka zadań", widoczny dla admina (`@can('diagnozujKolejke')`).
Sam plik testu jest **identyczny** z `main`; gałąź zapomniała podbić stałej po dodaniu
własnego wpisu.

Sprawdzone, że to **nie jest czerwień zamówiona** — `grep` po `MENU_BEZ_JS_KOMPLET`
w `scripts/kontrole-negatywne-alfa08.py` i skryptach `.mjs` daje zero trafień.

Poprawka: `9` → `10`, commit **`2bccebe8`**. Kontrola dodatnia: przed poprawką czerwień
identyczna z CI, po poprawce `P581 MENU PASS: 8 dodatkowych scenariuszy`.
Dopisane do dogrywki.

### p) D-223 rodzina powiadomień: `gpt-n1-powiadomienia` wygrywa, dostaje D-229

Decyzja właściciela 21.09. **`gpt-n1-powiadomienia` jest nadzbiorem `notyfikacja-zywa`,
nie jej konkurentem** — zmierzone:

- **14 z 19** wspólnych plików jest **bajt w bajt identycznych**, w tym cały `docs/DECISIONS.md`
  wraz z sekcją `## D-223`;
- obie mają identyczny wzorzec trzech commitów odzyskowych, a różnica między nimi to
  **dokładnie cztery pliki dotyczące #833**;
- notatka pomiarowa n1 (`docs/infra/POWIADOMIENIA_ZAPYTANIA_833.md`) **sama przyznaje**,
  że jej bazą jest commit „zawierający żywe wycinki z `flota/notyfikacja-zywa`".

**Co n1 dokłada:** `Notification::destinationUrls()` liczy adresy całej strony
**jednym zapytaniem zamiast pięciu na wiersz** (#833), plus `AdresyPowiadomienZbiorczoTest`.
`notyfikacja-zywa` świadomie tego nie rusza — jej własny test to dokumentuje wprost.

**Testy:** n1 **4409 przeszło**, zywa **4406**; różnica to dokładnie te trzy przypadki.
W obu jedna identyczna czerwień: `ProbaOdtworzeniaTest` — **ta sama na obu**, więc wada
środowiska, nie regresja. (Obie stoją na `main` sprzed `#920`, który tę przyczynę naprawił.)

**Numer:** D-223 zostaje przy rodzinie kaskady (16 odwołań). n1 bierze **D-229** —
pierwszy naprawdę wolny, sprawdzony niezależnie dwoma skanami (141 i 142 gałęzie).

**SPROSTOWANIE do meldunku agenta:** nazwał migrację
`2026_09_20_120000_usun_zamrozone_wycinki_komentarzy.php` „twardą awarią przy wejściu obu".
To jest **rozbrojone** — patrz przypis m): `up()` identyczny bajt w bajt, różni się wyłącznie
brzmienie stałej z komentarzem. **Prawdziwa kolizja siedzi gdzie indziej** i tę część
meldunku potwierdzam: `NotificationController.php`, `Notification.php`
i `resources/views/pages/notifications.blade.php` zderzają się wprost — drugie wejście
nadpisze pierwsze i cofnie albo optymalizację #833, albo wywali render `urlDoKomentarza()`.

**To szóste wchłonięcie dzisiaj.** Wzór jest powtarzalny: agenci odzysku scalali rodziny
gałęzi w nocy, a plan opisuje stan sprzed tego scalania. Przy każdym „albo–albo" z tego
planu pytać `--is-ancestor` **w obu kierunkach** i porównywać pliki wspólne po haszu.

### r) CI: tylko `port_funkcje` idzie na `ubuntu-latest`

Decyzja właściciela 21.09, po korekcie liczb. Gałąź `flota/ci-runnery-przegladarkowe`,
SHA **`f51504f2`**, w dogrywce.

**Dlaczego jeden job, nie pięć.** Pierwszy agent przełączył pięć i podał sumę „~39 min".
**To była pomyłka w dodawaniu** — wypisał cztery joby (18 + 13 + 8 + 27), które sumują się
do **66**. Przy 66 min to **7 przebiegów** z pozostałych ~500 minut, a nie 12.

Sam `port_funkcje` to **27 min → około 18 przebiegów**, i to **właśnie on** padł dziś na
`main` z `TimeoutError`. Jest najdłuższy w całym CI i najmocniej cierpi pod obciążeniem:
mediana 51 s, **maksimum 1638 s** na 25 przebiegach.

**Luka w strażniku, znaleziona przy okazji i naprawiona.** `DokumentyCiMowiaPrawdeORunnerzeTest`
pilnuje, że joby wybierają runnera jednym sposobem, przez `assertCount(1, array_unique(...))`.
Przy **jednym** elemencie w grupie przeglądarkowej ta asercja jest **zawsze prawdziwa** —
zbiór jednoelementowy ma jedną unikalną wartość, więc cichy powrót `port_funkcje`
na `CI_RUNS_ON` przeszedłby niezauważony. Dołożona asercja na obecność
`CI_RUNS_ON_BROWSER`; sprawdzone mutacją, że łapie ten regres. **6/6, 111 asercji.**

**Czego NIE wiemy — i to jest zapisane w `ci.yml`, nie tylko tutaj.** Krok „Przeglądarka"
woła `npx playwright install chromium` **bez `--with-deps`**, zakładając, że `libnss`,
`libatk` i `libgbm` są na maszynie. `JobDostepnosciNieWolaAptaTest` uzasadnia to słowami
o „jednym, stałym systemie" — czyli o **trwałym runnerze, nie o efemerycznym
`ubuntu-latest`**, który wstaje od zera. To założenie **nie przenosi się automatycznie**.

**Najtańszy sposób sprawdzenia:** `workflow_dispatch` obejmujący **tylko `port_funkcje`**,
nie cały przebieg. Brakującego pakietu Playwright nie połknie — powie po nazwie przy starcie.

**Pytanie otwarte:** czy pozostałe cztery joby przeglądarkowe też zaczną padać pod
obciążeniem floty. Nikt tego dla nich osobno nie zmierzył.

### s) WERSJA: cztery gałęzie podbijają na 0.68 bez wpisu — zderzą się z `flota/wersja-068`

Wersja stała na **Alfa 0.67 od 18 września**, mimo **czternastu scaleń**. Reguła
w `config/kuking.php` mówi „cyfra rośnie przy każdej zmianie, którą człowiek zobaczy",
a sam komentarz przy niej opisuje, że **to już się raz zdarzyło** przed 11 września
i regułę wtedy zaostrzono. Stanęła znowu, **bo nic jej nie pilnuje**.

Podbicie: gałąź **`flota/wersja-068`** (`dd9f6c88`, w dogrywce) — `Alfa 0.68`
w obu miejscach plus wpis w `CHANGELOG.md` obejmujący **9 z 14** scaleń.

**Zmierzone: cztery gałęzie NA ORIGIN już podbijają `etykieta` na `'Alfa 0.68'`,
zostawiając `CHANGELOG.md` na 0.67:**

| gałąź | `config/kuking.php` | `CHANGELOG.md` |
|---|---|---|
| `gpt-ugotowalem-dostep` | `Alfa 0.68` | `## Alfa 0.67 …` |
| `gpt-zalegle` | `Alfa 0.68` | `## Alfa 0.67 …` |
| `gpt-zdjecia-limity` | `Alfa 0.68` | `## Alfa 0.67 …` |
| `gpt-zdjecia-publikacja` | `Alfa 0.68` | `## Alfa 0.67 …` |

**Skutek:** każda z nich zderzy się z `dd9f6c88` na `config/kuking.php`, a gdyby
weszła **przed** nim — wstawiłaby do `main` numer wersji **bez wpisu, który go
tłumaczy**, czyli dokładnie stan, przed którym ostrzega komentarz przy regule
(„wersja bez wpisu jest numerem bez treści").

**Przed scaleniem którejkolwiek:** zdjąć z niej podbicie i pozwolić, żeby wersję
wniosła `flota/wersja-068`. Numer ma jedno źródło, nie pięć.

### Strażnik wersji — ósmy dziś, i JEDYNY, którego coś wywołuje

`naprawa/podbicie-wersji-wymaga-wpisu` (`22e97947`, jeden plik, 113 wierszy).
Zmierzone przez agenta w trzech warstwach: `ci.yml` job `test` w. 671 to **gołe
`php artisan test`**, `scripts/check.sh` w. 199 tak samo, a `install-hooks.sh`
wpina `check.sh --szybko` w `pre-push`. Bramka zakresu go nie wycina, bo za „nie kod"
uznaje wyłącznie `docs/` i `README.md`.

**Ale NIE złapałby przypadku, który go wywołał**, i mówi to wprost we własnym
docblocku: pilnuje **spójności dwóch miejsc**, nie **obowiązku podbicia**. Przez
wszystkie czternaście scaleń byłby zielony, bo obie wartości zgodnie mówiły 0.67.

Co naprawdę łapie: **dokładnie te cztery gałęzie wyżej.** To jest jego realna
wartość i powód, żeby go wpuścić — ale z zapisem, że zamyka połowę problemu.

Druga połowa („zmiana ze śladem w interfejsie musi podbić cyfrę") wymaga
rozstrzygnięcia, co maszynowo znaczy „zmiana, którą człowiek zobaczy". `ci.yml` ma
już taki filtr (`widok=true`: `resources/`, `public/`, wybrane `scripts/*.mjs`).
**Decyzja właściciela, nie agenta.**

### t) `#941` naprawione — i lekcja o teście, który kłamał nazwą

Publiczna `/tag/{slug}` wydawała gościowi **tytuł i zdjęcie główne przepisu
`followers` i `private`**. `TagController::show()` była jedyną powierzchnią bez
`zWidocznymPrzepisem()` — mają ją `TagFeed`, `FollowingFeed`, `DiscoverFeed`,
`DailyBoard`, `TagCollage`, `TagPublicStats`, `PodpowiedziTagow`.

Naprawa: `flota/tag941`, **`bd549289`**, przez **istniejący zakres**
`Recipe::scopeWidoczneDla()`, nie własny warunek w kontrolerze — żeby przyszła
poprawka widoczności naprawiała się raz, a nie w siedmiu miejscach osobno.

**Wyciek był szerszy, niż mówiło zgłoszenie.** Poza tytułem i zdjęciem przeciekała
**liczba**: `$posts->total()` szło do meta description, więc strona ogłaszała
„N wpisów z tagiem", gdzie N liczyło treści, których nie wolno pokazać. W teście
widać to jako **4 zamiast 2**. Strona, która nie pokazuje tytułu, ale podaje liczbę,
wciąż ujawnia istnienie treści.

### DLACZEGO TO ŻYŁO POD ZIELONYM TESTEM — warte zapamiętania

Istniał test `FeedTagowNiePokazujeCudzegoPrzepisuTest::test_tytul_i_zdjecie_…_na_stronie_tagu`.
Nazwa mówi „na stronie tagu". **Metoda wchodzi na `/home`, nie na `/tag/{slug}`.**

Czyli: strażnik istniał, był zielony, nazywał się dokładnie tak, jak brzmiała luka —
i jej nie pilnował. Nikt nie sprawdzał, bo nazwa brzmiała wiarygodnie.

To jest **inny wzór niż siedem martwych strażników z dzisiaj**. Tam problem brzmiał
„strażnik istnieje i nic go nie woła". Tutaj: **strażnik istnieje, jest wołany, jest
zielony — i mierzy co innego, niż mówi jego nazwa.** Drugi wzór jest gorszy, bo
pierwszy widać w `ci.yml`, a drugiego nie widać nigdzie.

Nazwa poprawiona na `…_w_strumieniu_tagow_na_stronie_glownej`.

**Reguła na przyszłość: nazwa testu jest obietnicą.** Przy audycie pokrycia nie ufać
nazwom — sprawdzać, na jaką trasę test faktycznie wchodzi.

### u) Limit nazw baz był policzony pod ZŁYM przedrostkiem — i trzymał się na nieudokumentowanym triku

`flota/sufiks-limit`, **`a5d96776`**, w dogrywce.

`#920` wprowadził limit **43 znaków** na sufiks z rachunkiem: najdłuższy przedrostek
to `kuking_zrodlo_proby` (19) + podkreślnik + 43 = 63. **Rachunek był błędny.**

Pełna lista przedrostków, zmierzona grepem:

| przedrostek | znaków |
|---|---:|
| `kuking_test` | 11 |
| `kuking_race` | 11 |
| `proba_wycofania` | 15 |
| `kuking_zrodlo_proby` | 19 |
| **`proba_odtworzenia_test`** | **22** |

Najdłuższy jest **o trzy znaki dłuższy** niż ten, pod który liczono. Rachunek nie
wywracał się **wyłącznie dzięki nieudokumentowanemu trikowi** w `proba-odtworzenia.sh`:
ręcznemu wycinaniu podkreślników z gotowego sufiksu, które przypadkiem bilansowało
się dla tego jednego przedrostka.

**Czyli limit, który wczoraj opisano jako starannie policzony, stał na przypadku.**

### Rozwiązanie i dlaczego jest lepsze od poprawienia liczby

Budżet liczy się teraz z **jawnej listy** `KUKING_PREFIKSY_RODZIN_BAZ` jako
`63 − 1 − najdłuższy_przedrostek`. Dopisanie kolejnego, dłuższego przedrostka
**samo zwęża budżet wszystkim pozostałym**, zamiast po cichu przekraczać 63 bajty.

Przy konflikcie skraca się **część czytelna**; skrót ma zawsze pierwszeństwo, bo to
on gwarantuje rozróżnialność. Strażnik `kuking_pilnuj_dlugosci_nazwy()` rzuca
wyjątkiem przy przekroczeniu 63 bajtów i jest wpięty we wszystkie funkcje budujące nazwy.

**Zapas przy najdłuższym przedrostku: zero znaków** — i to jest celowe. Margines nie
leży w liczbie, tylko w tym, że lista przelicza się sama.

### UWAGA PRZY SCALANIU — nazwy istniejących baz SIĘ ZMIENIĄ

Czytelna część skraca się z 30 do 27 znaków (budżet 44 → 40), a dla głównego checkoutu
`kuking_zrodlo_proby` i `proba_odtworzenia_test` zwracają teraz **gołe nazwy zamiast
`*_glowny`**. To znaczy: po scaleniu istniejące bazy stanowisk stają się **sierotami**
i trzeba je posprzątać. Nic nie ginie, ale miejsce zajmują.

**Dowód:** 14/14 testów jednostkowych, `proba-odtworzenia.sh` **124/124** — w tym
dokładnie ten przypadek, który dziś oblewał („odmawia odtwarzania do bazy, która NIE
jest pusta", kod 23) — pełny zestaw 4502/4502.

### w) Eksport danych: status przestał być deklaracją — `#821`, `#823`, `#824`

`flota/eksport-stan`, **`be764cfb`**, w dogrywce. Zawiera też cherry-pick siedmiu
testów rozpoznania (`b2394f27`) — **bajt w bajt, bez jednej zmiany**.

**Jedno miejsce z własnością statusu:** `ExportLifecycle.php`, pilnowane strażnikiem
`test_status_paczki_zapisuje_wylacznie_export_lifecycle` z kontrolą dodatnią. Strażnik
łapie i stałą modelu, i surowy literał; **nie łapie importu pod aliasem** — i to jest
napisane wprost w komentarzu, zamiast udawać pełną szczelność.

**`#824` — razem albo wcale.** `create()` i `dispatch()` w jednej transakcji, kolejka
`database` na tym samym połączeniu przy `after_commit => false`, więc wiersz w `jobs`
zatwierdza się razem z rekordem. **Skrzynka nadawcza bez dopisywania własnej.**
Asymetria jednostronna i świadoma: **nigdy rekordu bez zadania**; zadanie bez rekordu
jest nieszkodliwe, bo `handle()` zaczyna od `find()` i wraca.

**`#823` — nowa kolumna `retry_until`, NIE szósty stan.** To odejście od mojej sugestii
i uzasadnienie jest lepsze: `status` pełni dwie role — jest tekstem na ekranie
i kluczem inwariantu „jeden aktywny eksport". Stan „czeka na ponowienie" zamieniłby
jeden fałsz na drugi, bo **pierwsza próba naprawdę padła i człowiek ma prawo to wiedzieć**.
Więc `status` zostaje przy prawdzie o **próbie**, a `retry_until` mówi prawdę o **kolejce**.
Zobowiązanie **ma termin**, bo stan bez terminu jest blokadą konta na zawsze, gdy worker
zginie między próbami.

**`#821` — `ready` dopiero po `true`.** Plus zasada warta zapamiętania:
**odmowa bez wyjątku dostaje odpowiedź bez wyjątku.** Nie produkuje z `false` sztucznego
wyjątku ze śladem stosu, który niczego nie wskazuje.

**Migracja** przebudowuje indeks pod tą samą nazwą celowo, żeby `down()` zdejmował także
rozszerzoną ochronę zamiast zostawiać dwa indeksy. `down()` **odmawia wąsko** — tylko przy
żywym `retry_until` — z gotowym SQL-em i nazwaną ceną.

**Pełny zestaw: 4517 przeszło, 7 oblało** — wszystkie siedem to testy rozpoznania spraw,
których agent miał **nie ruszać** (`#825`, `#832`, `#953`, `#956`). Zgodne z oczekiwaniem.

### DO DECYZJI WŁAŚCICIELA — jedna wąska ścieżka została

**`processing` po twardym zabiciu workera** (SIGKILL, śmierć kontenera): hook `failed()`
się nie wykonuje, a `processing` trzyma slot **bez terminu**. W praktyce leczy się sama —
rezerwacja w `jobs` wygasa po 960 s i zadanie wraca. Zostaje na zawsze tylko wtedy, gdy
zadanie zginie **razem z kolejką**.

Agent tego nie ruszył i słusznie: to **inna przyczyna** (brak terminu przy `processing`)
niż ta, którą miał zamknąć, a naprawa zmieniałaby znaczenie `processing`.

### Zdanie do protokołu przy zamykaniu `#823`

Naprawa **nie polega** na tym, że `failed` przestało padać po pierwszej próbie, tylko na
tym, że `failed` przestało **zwalniać slot**. Kto czytał zgłoszenie jako „job ma nie pisać
failed", zobaczy w kodzie co innego, niż się spodziewa.
