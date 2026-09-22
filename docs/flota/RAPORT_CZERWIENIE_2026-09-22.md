# Raport: dwanaście czerwonych PR-ów — sesja 04, część pierwsza (2026-09-22)

Badanie dwunastu PR-ów z czerwonym CI. Dla każdego przeczytany log padającego
joba (nie nazwa joba), przypisana dokładnie jedna kategoria.

**Wynik w jednym zdaniu: jedenaście z dwunastu PR-ów nie ma żadnej własnej wady.
Padają na czerwieni, którą dziedziczą z `main`. Wadę treści ma tylko #1253.**

---

## 1. Wspólna przyczyna — `main` jest czerwony na PIĘCIU jobach, nie na czterech

Brief wymieniał dwie odziedziczone wady (Pint, trzy joby przeglądarkowe).
Pomiar pokazuje **trzecią, dotąd nierozpoznaną**: job `Testy (PostgreSQL 18)`.

Dowód: bieg `main` 35731246462 na commicie `c30adf5` (zdarzenie `push`, więc
z sekretami). Padły w nim:

| job na `main` | krok | prawdziwy komunikat |
|---|---|---|
| Pint (styl kodu) | Pint | `docs/research/audyt-2026-09-11/repro/test-callback-exit.php` — `blank_line_after_opening_tag`, `braces_position` |
| **Testy (PostgreSQL 18)** | Testy | **`DokumentyMdNieMajaMartwychOdnosnikowTest > trasy…` — `Martwe trasy: docs/AUDYT_SKALOWANIE_2026-09.md: /zdjecia/` ×5** |
| Port marki — rodziny ekranów | Port projektu — układ i kontrola ujemna | `page.waitForURL: Timeout 15000ms exceeded` w `scripts/port-projektu.mjs:253` |
| Port marki (kompozycje) | Port projektu — układ i kontrola ujemna | jak wyżej |
| Dostępność (axe-core) | Pomiar kafla dodawania (15 wariantów) | `page.waitForURL: Timeout 15000ms exceeded` w `scripts/kafel-dodawania.mjs:464` |

### 1a. Nowa czerwień: strażnik martwych tras vs. dokument audytu

Dwa niezależnie ZIELONE scalenia dają czerwone połączenie:

- `docs/AUDYT_SKALOWANIE_2026-09.md` pisze o regule cache jako `/zdjecia/*`
  (5 wystąpień);
- strażnik `DokumentyMdNieMajaMartwychOdnosnikowTest` wszedł na `main`
  22.09 o 14:24 (scalenie `e267eeec`, PR #1182). W punkcie jego wejścia tego
  dokumentu jeszcze nie było — sprawdzone `git cat-file -e e267eeec:<ścieżka>`.

Parser tnie `*` (nie należy do dozwolonego alfabetu) i zostaje `/zdjecia/`.
Realna trasa to `/zdjecia/{media}/{wariant}`, która normalizuje się do
`zdjecia/{}/{}`. `/zdjecia/` nie pasuje do niczego → pięć martwych trafień.

**Kto ma rację: dokument czy strażnik.** Naprawa czeka w otwartym PR-ze
#1267 i czyta to tak — dokument jest **poprawny**, wadą był **parser**:
`/zdjecia/*` to wzorzec reguły cache w Cloudflare, a nie trasa Laravela.
Zgadza się to z pomiarem: #1267 podaje 884 sprawdzone trasy i 5 martwych przed
poprawką (dokładnie te z CI) oraz 860 i 0 po niej.

To czytanie jest lepsze niż moje pierwsze. Sam proponowałem dopisać dokument
do `WYKLUCZONE_Z_TRAS_PLIKI` — wpis **oślepiłby** strażnika na prawdziwie
martwy odnośnik w tym dokumencie, a takich wzorców `/coś/*` jest w `docs/`
siedem i pozostałych sześć przechodziło dotąd przypadkiem. Poprawka parsera
z #1267 zamyka całą klasę, nie jeden objaw. **Moja propozycja jest wycofana.**

**Odtworzone lokalnie** (bez PHPUnita, na treści `main`): parser strażnika
puszczony na ten dokument wraz z `php artisan route:list` daje
`[/zdjecia/] => 5` — dokładnie to, co CI. To nie jest kruchość ani flaka.

### 1b. Joby przeglądarkowe: sekret istnieje, ale nie dociera do runnera

`KUKING_DEMO_HASLO` występuje w `.github/workflows/ci.yml` na `main`
**zero razy**. Sekret jest założony w ustawieniach repozytorium, ale nigdy nie
został wpięty do `env:` żadnego joba. Skutek: `DemoSeeder` (który celowo nie ma
wartości domyślnej) losuje hasło na każdy przebieg, a skrypty przeglądarkowe
logują się hasłem wpisanym na sztywno — i zostają na `/login`, aż minie
`waitForURL`.

Dlatego wszystkie trzy joby padają teraz na **kroku logowania**, a nie na
pomiarze — mimo że krok `Przygotowanie` kończy się sukcesem. Założenie „sekret
jest już założony, więc to naprawione" jest połowiczne: brakuje wpięcia.

---

## 2. Tabela: PR po PR

Skrót „dziedziczona" = czerwień identyczna z czerwienią `main` (sekcja 1),
zgodnie z regułą właściciela zignorowana i zgłoszona, nie naprawiana na gałęzi.

| PR | gałąź | padające joby | prawdziwy komunikat | kategoria | co zrobiłem | wynik |
|---|---|---|---|---|---|---|
| #1255 | `claude/…-01-polityka-prawdziwa` | Pint, Testy, 2× Port marki, Dostępność | Testy: `1 failed, 5022 passed` — wyłącznie martwe trasy `/zdjecia/` | dziedziczona | nic; zgłoszone | bez zmian |
| #1254 | `flota/moderacja-22-09` | Pint, Testy | Testy: `1 failed, 5033 passed` — jw. | dziedziczona | nic; zgłoszone | bez zmian |
| #1253 | `flota/ekran-zeszytu-2209-rozdzielenie` | Pint, **Testy (inny krok)**, 2× Port marki, Dostępność | `RuntimeError: Kontrola nie znalazła dokładnie jednego miejsca mutacji` w `scripts/kontrole-negatywne-alfa08.py:42` | **treść gałęzi** | diagnoza + poprawka zmierzona w worktree (sekcja 3) | nie pchnięte — poprawka należy do tamtej gałęzi |
| #1252 | `naprawa/postpolicy-widocznosc-2209` | Pint, Testy | Testy: `1 failed, 5032 passed` — jw. | dziedziczona | nic; zgłoszone | bez zmian |
| #1251 | `flota/wersja-068-i-bramka` | Pint, Testy, 2× Port marki, Dostępność | Testy: `1 failed, 5023 passed` — jw. | dziedziczona | nic; zgłoszone | bez zmian |
| #1250 | `flota/stopka-pusty-pas-2209` | Pint, Testy, 2× Port marki, Dostępność | Port marki: `waitForURL: Timeout` w `port-projektu.mjs:254` (krok logowania) | dziedziczona | nic; osobna uwaga niżej | bez zmian |
| #1247 | `flota/straznik-wersji-changelog` | Pint, Testy | Testy: `1 failed, 5023 passed` — jw. | dziedziczona | nic; zgłoszone | bez zmian |
| #1242 | `flota/naprawa-941` | Pint, Testy | Testy: `1 failed, 5021 passed` — jw. | dziedziczona | nic; zgłoszone | bez zmian |
| #1240 | `zaleglosci-zgloszen` | Pint, Testy | Testy: `1 failed, 5030 passed` — jw. | dziedziczona | nic; zgłoszone | bez zmian |
| #1238 | `straznik-format` | Pint, Testy, 2× Port marki, Dostępność | Testy: `1 failed, 5056 passed` — jw. | dziedziczona | Pint odtworzony lokalnie na treści gałęzi | bez zmian |
| #1225 | `notyfikacja-zywa` | Pint, Testy, 2× Port marki, Dostępność | Testy: `1 failed, 5032 passed` — jw. | dziedziczona | nic; zgłoszone | bez zmian |
| #1224 | `narzedzia/kontrola-ujemna-v2` | Pint, Testy | Testy: `1 failed, 5021 passed` — jw. | dziedziczona | nic; zgłoszone | bez zmian |

Jedenaście gałęzi daje w jobie `Testy` **dokładnie jedną** czerwień i jest nią
strażnik martwych tras. Liczby „passed" różnią się tylko o testy, które dana
gałąź dokłada.

### Uwaga do #1250 (pytanie właściciela o wysoką stopkę)

Strażnik z tej gałęzi, `scripts/stopka-pusty-pas.mjs`, **w ogóle nie jest
wpięty do CI** — gałąź nie rusza `.github/workflows/ci.yml`. Nie ma więc czego
osłabiać ani przenosić: pomiar 15 przypadków nigdy się w CI nie uruchomił.
Czerwień tego PR-a pochodzi w całości z `main`.

Gałąź zmienia natomiast `scripts/port-projektu.mjs` — czyli skrypt padającego
kroku. To mogłoby wyglądać na jej winę, ale komunikat jest identyczny jak na
`main` (ten sam `waitForURL` na `/login`, tylko numer linii przesunięty z 253
na 254 przez dodane wiersze). Przyczyna jest ta sama: brak hasła demo.

Żeby ten strażnik zaczął cokolwiek mierzyć, trzeba najpierw wpiąć
`KUKING_DEMO_HASLO` (sekcja 1b), a potem dodać krok w `ci.yml`.

### Uwaga do #1252

Potwierdzone: gałąź zmienia **jeden plik** i jest nim plik testowy
(`tests/Feature/Visibility/ZapowiedzPrzepisuNaStronieWpisuTest.php`). Żadnej
zmiany produkcyjnej. Wszystkie jej testy przechodzą — w jobie `Testy` pada
wyłącznie strażnik martwych tras. Bramka z `PostPolicy` działa.

---

## 3. Jedyna wada treści: #1253

`scripts/kontrole-negatywne-alfa08.py` mutuje `CollectionController.php`
przez `replace_once()`, które **wymaga dokładnie jednego trafienia**.

Gałąź rozdziela ekran zeszytu i celowo dokłada drugą metodę,
`wybranyZeszytDoWyjecia()`, obok `selectedCollection()`. Obie mają identyczny
blok reguł. Skutkiem są **dwa** trafienia zamiast jednego:

```
caly plik, wystapien: 2 | 'bail', 'nullable', 'uuid',
caly plik, wystapien: 2 | Rule::exists('collections', 'id')->where('owner_id', …)
```

Padły **dwie** z pięciu zamówionych mutacji, nie jedna — skrypt przerwał na
pierwszej („Format UUID") i do drugiej („Własność zeszytu") nie doszedł.

To nie jest wada zamówionej czerwieni ani `main`. Gałąź zmieniła kod pod
strażnikiem i nie dociągnęła jego kotwic.

### Proponowany diff (NIE pchnięty — patrz sekcja 4)

Zawęzić mutację do ciała jednej metody, wzorem istniejącego w tym skrypcie
`smaller_help()`, który już tak robi. Pięć mutacji zostaje pięcioma, a
`replace_once` zachowuje swoją surowość — tylko w mniejszym zakresie:

```python
def w_metodzie(source, nazwa, old, new):
    """Mutuj tylko w ciele wskazanej metody. Po rozdzieleniu ekranu zeszytu
    ta sama reguła stoi w dwóch metodach i `replace_once` na całym pliku nie
    ma już jednego trafienia."""
    start = source.index("private function %s(" % nazwa)
    end = source.index("\n    }", start) + len("\n    }")
    blok = replace_once(source[start:end], old, new)
    return source[:start] + blok + source[end:]
```

i w `checks`:

```python
("Format UUID", CONTROLLER, COLLECTION_TEST,
 lambda s: w_metodzie(s, "selectedCollection",
     "'bail', 'nullable', 'uuid',", "'bail', 'nullable',")),
("Własność zeszytu", CONTROLLER, COLLECTION_TEST,
 lambda s: w_metodzie(s, "selectedCollection",
     "Rule::exists('collections', 'id')->where('owner_id', $request->user()->getKey())",
     "Rule::exists('collections', 'id')")),
```

### Zmierzone: poprawka działa

Kontrola uruchomiona na gałęzi w osobnym worktree, na **własnej, jednorazowej
bazie** `kuking_kontrola_1253`, z `CI=true` (skrypt ma taki warunek, bo mutuje
źródła) i `PAO_DISABLE=1` (żeby `php artisan test` dawał wyjście czytelne dla
człowieka, tak jak w CI — inaczej `laravel/pao` zwraca JSON i skrypt nie
znajduje w nim słowa `FAILED`):

- **błąd blokujący zniknął** — nie ma już „nie znalazła dokładnie jednego
  miejsca mutacji";
- **kontrola 1 „Format UUID" przechodzi w całości**: mutacja zmienia źródło,
  `WyborZeszytuMaWalidacjeTest` pada z `invalid input syntax for type uuid:
  "to-nie-jest-uuid"` (czyli test naprawdę łapie zdjętą regułę), źródło wraca
  do sumy wyjściowej;
- **kontrola 2 „Własność zeszytu" też przechodzi** — to ta druga kotwica,
  która po rozdzieleniu ekranu również była niejednoznaczna. Bez tej poprawki
  skrypt nigdy do niej nie dochodził.

### Czego ta poprawka NIE rozwiązuje (i co jest moim stanowiskiem)

Przebieg zatrzymuje się dalej, na kontroli 3 „Komunikat po powrocie":
zmutowany `layout.blade.php` nie sprawia, że test pada.

**To nie jest wina gałęzi ani tej poprawki.** Ta sama kontrola, w tym samym
worktree i na tej samej bazie, **zatrzymuje się w identycznym miejscu na
`origin/main`** (`83ac7bed`) — ten sam komunikat, ten sam test. W CI krok
„Kontrole negatywne regresji Alfa 0.8" na `main` przechodzi, więc różnica leży
po stronie mojego stanowiska (najpewniej skompilowane widoki Blade w worktree),
a nie w repozytorium. Porównanie z `main` odróżnia te dwie rzeczy i dlatego je
zrobiłem, zamiast zakładać kruchość.

**Czego zatem NIE zmierzyłem:** pełnego, zielonego przebiegu wszystkich pięciu
kontroli. Zmierzyłem to, co dotyczy #1253: obie niejednoznaczne kotwice są
naprawione i obie odpowiadające im kontrole przechodzą.

Jeśli `WyborZeszytuMaWalidacjeTest` pokrywa także ścieżkę wyjmowania,
właściwsza może być mutacja obu miejsc zamiast zawężenia do jednego.
**Decyzja należy do właściciela gałęzi.**

---

## 4. Czego nie zweryfikowałem i dlaczego

Piaskownica tej sesji odrzuca **bezpośrednie** `php artisan test`
i `vendor/bin/phpunit`, ale przez `./scripts/check.sh --szybko` testy chodzą
normalnie. **Pełna bateria przeszła do końca**: 5023 testy, 5017 zielonych,
**5 czerwonych**, 22 minuty.

Z tych pięciu **jedna jest prawdziwa i zgadza się z CI co do znaku**:

```
DokumentyMdNieMajaMartwychOdnosnikowTest::test_trasy_wspominane_w_dokumentach_naprawde_istnieja
Martwe trasy: docs/AUDYT_SKALOWANIE_2026-09.md: `/zdjecia/`   (x5)
```

Pozostałe cztery to **artefakty mojego stanowiska** — w CI te same testy są
zielone (bieg PR-a pokazuje `1 failed, 5032 passed`, czyli wyłącznie strażnik
tras). Rozpisuję je, bo każdy jest innym „założeniem o stanowisku, którego CI
nie ma", i następny agent straci na nich godzinę, jeśli ich nie rozpozna:

| test | objaw | przyczyna po stronie stanowiska |
|---|---|---|
| `SesNieUzywaPoswiadczenR2Test` (2 testy) | oczekiwane `r2-test-key`, otrzymane `proxy-injected` | sesja ma w środowisku `AWS_ACCESS_KEY_ID` i `AWS_SECRET_ACCESS_KEY` wstrzyknięte przez proxy agenta; przykrywają fixture testu |
| `RiskyTestFailsGateTest` (2 testy) | oczekiwane `1 passed` / `This test did not perform any assertions`, otrzymany JSON | testy odpalają zagnieżdżony `php artisan test`, a `vendor/laravel/pao` zamienia jego wyjście na JSON w kontekście nie-TTY/agenta; CI dostaje wyjście czytelne dla człowieka |

Dlatego:

- podstawą rozpoznania są **logi CI**, czyli to, co faktycznie świeci na
  czerwono; lokalna bateria służy jako potwierdzenie i zgadza się z CI
  w jedynym punkcie, który dotyczy repozytorium;
- `scripts/kontrole-negatywne-alfa08.py` dla #1253 **został uruchomiony** — wynik
  i jego granice opisuje sekcja 3;
- **nie pchnąłem żadnej poprawki kodu** — zgodnie z zasadą „lepiej oddać
  uczciwą diagnozę niż niesprawdzoną poprawkę".

### Czerwień, która NIE jest wadą repozytorium: klient PostgreSQL

`./scripts/check.sh --szybko` melduje u mnie lokalnie:

```
✗ Testy kopii bazy oblewają — uruchom: bash tests/skrypty/kopia-bazy.sh
```

**To artefakt mojego stanowiska, nie wada `main`.** Moje stanowisko ma klienta
`pg_dump/psql 16.13`, a repozytorium wymaga **klienta 18**: `ci.yml` ma osobny
krok „Klient PostgreSQL 18 (wymagany przez skrypty kopii)", który instaluje go
przed testami i przerywa job, gdy się nie uda — z komunikatem mówiącym wprost,
że skrypty kopii „odmówiłyby odczytu ZDROWEGO archiwum, meldując to jako
uszkodzenie".

Dokładnie to widzę: klient 16 nie czyta archiwum z serwera 18. W CI ten krok
jest w jobie `Pint`, a ponieważ Pint pada wcześniej, **kroki kopii bazy są tam
dziś pomijane** — czyli nikt ich ostatnio nie zmierzył. Po scaleniu #1267
zaczną się wykonywać; spodziewam się zieleni, bo CI instaluje klienta 18, ale
**tego nie zmierzyłem** i odnotowuję jako niewiadomą.

Co udało się zweryfikować pomiarem, nie założeniem:

- `vendor/bin/pint --test` na treści `main` → pada wyłącznie plik audytu;
- `./scripts/check.sh --szybko` powtarza to samo („Kod wymaga sformatowania")
  i potwierdza, że bramka Pint blokuje pchanie w całym repozytorium;
- PHPStan lokalnie: bez zastrzeżeń; odwracalność migracji: przechodzi;
- to samo na treści gałęzi `straznik-format` (najwięcej PHP z całej dwunastki),
  w osobnym worktree → **ten sam jeden plik**. Pint jest dziedziczony wszędzie;
- parser strażnika tras odtworzony w samodzielnym skrypcie PHP przeciwko
  `php artisan route:list` → `[/zdjecia/] => 5`, zgodnie z CI;
- operacja tekstowa proponowanej poprawki #1253;
- środowisko jest zdrowe: ext4, PHP 8.4.19, `RecursiveDirectoryIterator`
  po `tests/` widzi 778 pozycji (nie kilkadziesiąt — nie ma fałszywej zieleni).

---

## 5. Co zostaje do zrobienia

Obie wady z sekcji 1 dotykają **wszystkich dwunastu** PR-ów. Zgodnie z regułą
„nie naprawiaj czerwieni `main` na swojej gałęzi" nie ruszałem ich tutaj.

1. **Pint i martwe trasy — już w drodze, nic nie robić.** Otwarty PR #1267
   (`flota/naprawa-main-2209`) zamyka oba: wąskie wykluczenie paczki audytu
   w `pint.json`, uzasadnione obecnością `SHA256SUMS.txt` (a nie nazwą
   katalogu), oraz poprawka parsera strażnika. Nie robić własnego wykluczenia
   w `pint.json` i nie dublować tej naprawy na innej gałęzi.

2. **Hasło demo — NIEZAŁATANE, niczyje.** #1267 zmienia dwa pliki
   (`pint.json`, plik strażnika) i **nie rusza `.github/workflows/ci.yml`**.
   `KUKING_DEMO_HASLO` nadal nie występuje w tym pliku ani razu, więc po
   scaleniu #1267 trzy joby przeglądarkowe zostaną czerwone — tyle że będą
   jedyną czerwienią i wreszcie będzie widać, że chodzi o logowanie.

   Do zrobienia: wpiąć sekret do `env:` trzech jobów (`Port marki — rodziny
   ekranów`, `Port marki (kompozycje)`, `Dostępność (axe-core)`). Job
   `Panel marki` przechodzi, bo loguje się przez TOTP inną drogą — to
   kontrola dodatnia, że sama przeglądarka w CI działa.

   Dopóki to nie wejdzie, żaden pomiar przeglądarkowy w CI nie rusza — w tym
   nowy strażnik stopki z #1250, który i tak nie jest jeszcze wpięty.

Po scaleniu #1267 i wpięciu sekretu jedenaście z dwunastu PR-ów powinno
zzielenieć bez żadnej zmiany w ich treści. Zostanie #1253 (sekcja 3).
