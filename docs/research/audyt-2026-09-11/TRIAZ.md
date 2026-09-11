# Triaż audytu z 11.09.2026 — A01–A15 przy plikach, na `origin/main`

**Data triażu:** 11 września 2026
**Audyt zamarł na:** `8fa3cc9` (przycisk wskazywany nazwą, #325)
**Triaż zrobiony na:** `5e7c253` (autoryzacja zdjęcia jednym przejściem, #329) —
cztery commity dalej.

Ten dokument jest **pierwszą** rzeczą oddaną z tego zlecenia i świadomie
wychodzi PRZED jakąkolwiek naprawą. Właściciel ma wiedzieć, na czym stoi,
zanim ktokolwiek zacznie zmieniać kod — a odwrotna kolejność (najpierw
naprawy, triaż na końcu) daje dokument pisany pod to, co się udało zrobić.

Cztery możliwe odpowiedzi, użyte dosłownie:

| Odpowiedź | Znaczenie |
|---|---|
| **POTWIERDZONE** | usterka jest w kodzie na dzisiejszym `main`, otwarta |
| **JUŻ ZAMKNIĘTE** | naprawione po `8fa3cc9`; podany commit albo PR |
| **ŚWIADOMA DECYZJA** | tak ma być; podany numer decyzji z `docs/DECISIONS.md` |
| **NIEPOTWIERDZONE** | audyt się myli; podany dowód |

---

## Tabela triażu

| ID | Pr. | Odpowiedź | Plik i linia na `5e7c253` | Jednym zdaniem |
|---|---|---|---|---|
| A01 | P1 | **POTWIERDZONE** | `app/Domain/Recipes/Actions/PublishRecipe.php:255–258`, `app/Domain/Recipes/Actions/SnapshotRecipeVersion.php:24` | Transakcja zamyka się w linii 255, snapshot woła się w 258 — POZA nią; numer wersji to `max()+1` bez blokady |
| A02 | P1 | **POTWIERDZONE** | `app/Http/Controllers/Settings/DataSettingsController.php:130–161` | `DataExport` w stanie `QUEUED` jest zatwierdzony w linii 130–133, a `dispatch()` idzie w 161 — poza transakcją i bez odzyskiwania |
| A03 | P2 | **POTWIERDZONE** | `routes/console.php:47–311` (16 zadań), `vendor/laravel/framework/src/Illuminate/Console/Scheduling/CallbackEvent.php:120` (Laravel 13.30.1) | `return $this->result === false ? 1 : 0;` — kod `1` z `Artisan::call()` jest dla harmonogramu sukcesem. Co najmniej jedno z tych zadań NAPRAWDĘ zwraca `FAILURE`: `kuking:sprawdz-kopie` (`app/Console/Commands/SprawdzKopieBazy.php:63`), czyli czujka pilnująca, czy kopia bazy w ogóle powstała |
| A04 | P2 | **POTWIERDZONE, osiągalne z HTTP** | `app/Http/Controllers/RecipeController.php:176`, `app/Domain/Recipes/Actions/PublishRecipe.php:133–257`, `resources/views/pages/recipes/create.blade.php:425` | `action=draft` na opublikowanym przepisie zmienia treść PUBLICZNĄ i nie zapisuje ani wersji, ani wpisu w audycie |
| A05 | P2 | **POTWIERDZONE, częściowo nietrafione** | `app/Domain/Recipes/Actions/SnapshotRecipeVersion.php:31–55` | Brakuje `media_id` kroków, `hero_media_id`, `source_scan_media_id`, `source_url`, `visibility`, `no_amount` — ale `source_type/person/note/family_since_year` SĄ w snapshocie, więc „nie obejmuje pochodzenia" jest za mocne |
| A06 | P2 | **ŚWIADOMA DECYZJA — D-057** | `docs/DECISIONS.md:3853–3858`, `app/Http/Controllers/PodsumowanieTygodniaController.php:27–41` | D-057 mówi dosłownie: „Trasa działa na `GET` i to jest wybór, nie przeoczenie" |
| A07 | P2 | **POTWIERDZONE** | `app/Domain/Digest/OdnosnikWypisania.php:51–54`, `app/Http/Controllers/PodsumowanieTygodniaController.php:86–102` | `powrotDla()` to `signedRoute` bez TTL i bez nonce, a `wracam()` na `GET` bezwarunkowo WŁĄCZA zgodę |
| A08 | P2 | **POTWIERDZONE** | `public/sw.js:17`, `:37`, `:64–73` | Trzy osobne rzeczy: stała nazwa cache `kuking-v1`, `activate` kasuje KAŻDY cache originu, a manifest i ikony idą cache-first |
| A09 | P2 | **POTWIERDZONE** | `app/Http/Controllers/RecipeController.php:247–248` | `replies` i `replies.author.profile.avatar` bez `limit` — paginowane są tylko komentarze główne |
| A10 | P2 | **JUŻ ZAMKNIĘTE — #329 (`5e7c253`)** | `app/Domain/Media/DostepDoZdjecia.php:180`, `app/Http/Controllers/MediaController.php:56–62` | `rozstrzygnij()` buduje graf rodziców RAZ i odpowiada na oba pytania; 15 → 6 zapytań. Druga połowa #286 (49 unikalnych adresów obrazków na `/home`) zostaje OTWARTA i audyt jej nie dotyka |
| A11 | P2 | **POTWIERDZONE** | `app/Console/Commands/CleanUpDataExports.php:36–41`, `:77–84` | Po udanym czyszczeniu rekord zostaje `EXPIRED` z `expires_at < now()`, czyli spełnia warunek tego samego zapytania w nieskończoność |
| A12 | P2 | **ŚWIADOMA DECYZJA — D-105** | `.github/workflows/ci.yml:604–619`, `docs/DECISIONS.md:9331` | Nie przeoczenie: warunek zdjęcia flagi jest ZAPISANY. Nazwa joba i grupy w audycie jest nieprawdziwa — patrz niżej |
| A13 | P3 | **POTWIERDZONE** | `README.md:121–124`, `:31`, `:129` vs `.github/workflows/ci.yml:1–3` | README mówi „dopóki ich nie ma [Actions]", a D-010 włączyło CI; do tego „72 testy" przy ponad 2800 |
| A14 | P3 | **POTWIERDZONE** | `composer.json:7` (`"license": "MIT"`) vs `LICENSE:1` (`PROPRIETARY PLACEHOLDER`) | Dwie sprzeczne deklaracje, obie w korzeniu repozytorium |
| A15 | P3 | **POTWIERDZONE, praca trwa w otwartym PR #334** | `docs/research/audyt-2026-09-11/evidence/dostepnosc.json` → `focusNotObscured.ostrzezen = 23`, `naruszen = 0` | Na `main` liczba 23 nadal stoi, bo #334 (`claude/belka-przy-200-procent`) jest NIESCALONY. Nie duplikujemy — patrz niżej |

Podsumowanie liczbowe: **11 POTWIERDZONYCH** (z czego jedno częściowo
nietrafione w opisie i jedno obsługiwane w otwartym PR-ze), **1 JUŻ
ZAMKNIĘTE**, **2 ŚWIADOME DECYZJE**, **0 całkowicie NIEPOTWIERDZONYCH**.

**To jest dobry audyt.** Poprzedni (kolejności blokad, 10.09) zamarł
24 commity przed `main` i jego główne znalezisko było już zamknięte; ten
zamarł cztery commity wcześniej i jedno na piętnaście znalezisk zdążyło
się w tym czasie domknąć. Żadne znalezisko nie jest wyssane z palca.

---

## Trzy rzeczy, w których audyt mija się z faktem

Nie są to całe znaleziska nietrafione — są to nieprawdziwe szczegóły
w znaleziskach trafionych. Wpisuję je, bo każdy z nich prowadziłby
czytelnika do złej naprawy.

### 1. A12 nazywa job i grupę testów, które w tym repozytorium nie istnieją

Audyt pisze: „Job `race-regressions` ma `continue-on-error: true` […] Używa
PostgreSQL i grupy testów `race`".

Sprawdzone na commicie audytu, nie na dzisiejszym `main`:

```
$ git show 8fa3cc9:.github/workflows/ci.yml | grep -c "race-regressions"
0
$ git show 8fa3cc9:.github/workflows/ci.yml | grep -n "dwa-polaczenia"
494:  #  3b. Grupa `dwa-polaczenia` — testy na DWÓCH połączeniach (D-105, #314)
511:  dwa-polaczenia:
```

Job nazywa się `dwa-polaczenia` („Wyścigi na dwóch połączeniach (nie
blokuje)"), a testy leżą w `tests/Dwa/` i uruchamia je
`./scripts/testy-dwa-polaczenia.sh` — nie `--group race`. Grupa `race`
(`tests/Feature/Wyscigi/`) to coś innego: te testy chodzą w ZWYKŁYM,
blokującym przebiegu `php artisan test` i blokują scalenie. Czyli ta część
znaleziska, która brzmi „testy współbieżności nie są egzekwowane", jest
o połowę nieprawdziwa: siedem testów z `tests/Feature/Wyscigi/` jest
egzekwowanych od dawna.

### 2. A05 zarzuca brak pochodzenia przepisu, a pochodzenie w snapshocie jest

`SnapshotRecipeVersion.php:38–41` zapisuje `source_type`, `source_person`,
`source_note` i `family_since_year`. Brakuje `source_url`
i `source_scan_media_id` — czyli adresu i skanu kartki, nie „pochodzenia".
Reszta zarzutu (brak `media_id` w krokach, brak `hero_media_id`, brak
`no_amount` w składniku) jest co do joty prawdziwa.

### 3. A10 jest opisane jako otwarte, a zostało zamknięte cztery commity później

To nie jest błąd audytu — to skutek tego, że zamarł na `8fa3cc9`. Zapisuję
dla porządku: `#329` (`5e7c253`) wprowadził `rozstrzygnij()`, który buduje
graf rodziców raz na żądanie i odpowiada jednocześnie na `canView`
i `isPublic`, plus zapytanie `UNION` pytające tylko te tabele, które ten
identyfikator naprawdę mają. Liczby z opisu PR-a: 15 → 6 zapytań na jedno
zdjęcie, 450 → 180 na stronie. Macierz autoryzacji nie zmieniła się —
`Gate` jest pytany osobno dla widza i osobno dla anonima na tych samych
wierszach.

**Co po tym ZOSTAŁO otwarte i czego audyt nie widzi:** druga połowa issue
#286 — liczba ŻĄDAŃ HTTP o obrazki, nie koszt jednego żądania.
`docs/research/2026-09-10-pomiar-zapytan-zdjecia.md:299–306` podaje pomiar:
**94 wystąpienia adresu `media.show` w HTML-u `/home`, z czego 49 adresów
UNIKALNYCH**, czyli 49 żądań HTTP na zimnym cache. Poprawka #329 zbiła to
z 49 × 15 = 735 zapytań do 49 × 6 = 294 — ale nie zmniejszyła liczby żądań.
To zostaje.

---

## A12 — liczba, której nikt nie policzył

D-105 stawia warunek zdjęcia `continue-on-error`: **dwadzieścia kolejnych
przebiegów CI bez ani jednej czerwieni tego joba.** Audyt pisze, że „nie
ustalił, czy spełniono już warunek wyjścia". Nikt go nie ustalił, więc
policzyłem — przez API GitHub Actions, job po jobie, od przebiegu,
w którym ten job powstał (`34547641832`, 11.09 o 00:42 UTC):

| Wynik joba „Wyścigi na dwóch połączeniach (nie blokuje)" | Ile |
|---|---:|
| `success` | **19** |
| `failure` | **0** |
| `skipped` (brak zmian w kodzie — `needs.zakres.outputs.kod != 'true'`) | 2 |
| `cancelled` (wyparty nowszym pushem na `main`) | 3 |

**Dziewiętnaście zielonych, zero czerwonych.** Warunek D-105 wymaga
dwudziestu, czyli brakuje **jednego przebiegu**. Przebiegi `skipped`
i `cancelled` świadomie NIE liczę do dwudziestu: `docs/PULAPKI_TESTOW.md` §5
mówi wprost, że „brak wyniku to nie »w porządku«, to »nie wiemy«", a job
pominięty nie zmierzył niczego.

Pełna lista przebiegów jest w `evidence-triaz/dwa-polaczenia-przebiegi.txt`
w tym katalogu, razem z poleceniem, które ją odtwarza.

**Świadomie NIE zdejmuję flagi w tym zleceniu**, i to z dwóch osobnych
powodów:

1. Warunek jeszcze nie jest spełniony — brakuje jednego przebiegu.
   Zdjęcie flagi przy dziewiętnastu byłoby zaokrągleniem warunku, który
   właściciel zapisał właśnie po to, żeby go nie zaokrąglać.
2. Dwa `skipped` w tej serii pokazują drugą rzecz, o której D-105 nie
   mówi: job ma `if: needs.zakres.outputs.kod == 'true'`, więc PR
   dokumentacyjny go pomija. Gdy flaga spadnie, „pominięty" stanie się
   „wymagany check, którego nie ma" — a to jest dokładnie pułapka 5. To
   wymaga rozstrzygnięcia RAZEM ze zdjęciem flagi i jest wpisem do
   dziennika decyzji, nie zmianą w `ci.yml` przy okazji.

Wniosek do decyzji właściciela: **po jednym kolejnym zielonym przebiegu
tego joba warunek D-105 będzie spełniony** i zdjęcie flagi da się zrobić
z czystym sumieniem — w osobnym PR-ze, który rozstrzyga też sprawę
`skipped`.

---

## A15 — nie dotykamy, bo to już czyjaś otwarta praca

PR **#334** (`claude/belka-przy-200-procent`, „Dolna belka przy czcionce
200%: 376 px → 181 px") jest OTWARTY — sprawdzone przez API, stan `open`,
`sha` `7a580da`. Schodzi z 23 ostrzeżeń do 13, a sześć pozostałych opisuje
jako nazwany dług, bo ich zdjęcie wymaga odpięcia dolnej belki, czyli
decyzji właściciela (D-082 postawiło tę belkę na `position: fixed`
świadomie).

Dlatego w tym zleceniu `resources/css/app.css` i `scripts/dostepnosc.mjs`
zostają **nietknięte**. Dwie gałęzie poprawiające te same ostrzeżenia w tych
samych dwóch plikach to konflikt, w którym każda strona osobno wygląda
kompletnie — pułapka nr 5 z `docs/zlecenia/ZASADY_AGENTA.md`.

---

## Jedna zmiana w konfiguracji, którą wymusiło wniesienie paczki

`pint.json` dostaje `"exclude": ["docs"]`. Powód jest konkretny i nie jest
wygodą: paczka audytu niesie skrypt odtworzeniowy `repro/test-callback-exit.php`,
a `vendor/bin/pint` chce go przeformatować (`blank_line_after_opening_tag`,
`braces_position`). Tego pliku **nie wolno ruszyć** — jest objęty
`SHA256SUMS.txt` paczki, a suma jest jedynym dowodem, że dowody audytu
przyszły w stanie, w jakim je oddano:

```
$ cd docs/research/audyt-2026-09-11 && sha256sum -c SHA256SUMS.txt
audyt-kuking-2026-09-11.md: OK
findings.json: OK
evidence/dostepnosc.json: OK
evidence/repro-callback-exit.json: OK
evidence/repro-service-worker.json: OK
evidence/wydajnosc.json: OK
repro/sw-reviewed.js: OK
repro/test-callback-exit.php: OK
repro/test-service-worker.cjs: OK
```

Wszystkie dziewięć zgadza się co do bajta. `docs/` nie trzyma i nie ma
trzymać kodu aplikacji — poza tym skryptem jest tam jeszcze jeden plik PHP
(`docs/design/system-v3.1/uploads/.../motyw.blade.php`), też cudzego
autorstwa, który dotąd wymykał się pintowi tylko dlatego, że preset
`laravel` pomija `.blade.php`. `phpstan.neon` nie wymaga zmiany: jego
`paths` to `app`, `config`, `database`, `routes`, `tests`.

---

## Co z tego triażu bierzemy do naprawy w tym zleceniu

Kolejność jest kolejnością audytu (§„Kolejność napraw") przefiltrowaną
przez jedną zasadę: **lepiej trzy rzeczy zmierzone i naprawione niż
piętnaście opisanych.**

**Bierzemy:** A01 i A02. Oba są P1 i oba mają w `findings.json` podstawę
„analiza kodu; bez testu awarii w pełnej aplikacji" — a `findings.json` ma
`full_local_application_tests_run: false`. Czyli oba P1 tego audytu są
ROZUMOWANIEM, nie pomiarem, i największą wartością, jaką da się tu dołożyć,
jest zamiana jednego na drugie.

**Nie bierzemy, świadomie:**

| ID | Dlaczego nie teraz |
|---|---|
| A03 | Naprawa jest jednolinijkowa (`Artisan::call(...) === 0`), ale dotyka SZESNASTU zadań w `routes/console.php` i musi mieć własny test na każdą gałąź. Osobny PR, bo inaczej wchodzi w ten sam commit co dwa P1 i nikt tego nie przejrzy uczciwie |
| A04, A05 | Wiszą na A01 (audyt mówi to sam: „Ustalenie jest powiązane z A01 i A05"). Kontrakt „co znaczy zapis przy `publish=false` na opublikowanym przepisie" jest PYTANIEM DO WŁAŚCICIELA, nie do agenta — gotowy wpis do dziennika decyzji jest w raporcie |
| A06 | Świadoma decyzja D-057. Nie ruszamy |
| A07 | Realne, ale wymaga rozstrzygnięcia, czy ponowny zapis ma mieć TTL — a to znowu decyzja o zgodzie, nie o kodzie |
| A08 | Cache PWA, trzy osobne rzeczy w jednym pliku. `public/sw.js` nie jest w niczyim otwartym PR-ze, więc to jest najlepszy kandydat na NASTĘPNY PR |
| A09, A11 | Skalowanie. Oba potrzebują testu na dużych danych („komentarz z 10 000 odpowiedzi"), czyli osobnej roboty pomiarowej |
| A10 | Zamknięte |
| A12 | Świadoma decyzja D-105, warunek policzony wyżej. Brakuje jednego przebiegu |
| A13, A14 | P3, dokumentacja i metadane. A14 wymaga JEDNEGO słowa od właściciela: `MIT` czy `proprietary`. Nie zgaduję licencji za niego |
| A15 | Otwarty PR #334 |
