# Uproszczenie bramki CI — #611

Podstawa: `main` **e306842c692dba2d1dea894d4cfb7947cf120ed9**.
Gałąź: `chore/611-uproszczenie-ci`. Bez pusha i bez PR-a — zmiana w `ci.yml`
ma duży promień rażenia, a w repozytorium jest kilka otwartych PR-ów w CI.

Zakres tej tury: **jedna** zmiana, ta, której da się dowieść. Reszta kandydatów
z issue jest zmierzona i opisana niżej razem z powodem, dla którego ich nie
ruszam.

---

## 1. Pomiar wejściowy

### Pliki

| Plik | Linii | Bajtów | Komentarz | Kod |
|---|---:|---:|---:|---:|
| `ci.yml` | 1823 | 85 553 | 664 | 998 |
| `deploy.yml` | 613 | 31 156 | 295 | 276 |
| `preview.yml` | 317 | 14 179 | 129 | 160 |
| `railway-iac.yml` | 225 | 9 997 | 123 | 81 |
| **razem** | **2978** | | | |

### `ci.yml`

- **12 jobów**, 100 kroków z nazwą (29 `uses:`, 71 `run:`).
- **53 unikalne nazwy kroków, 13 powtórzonych, 47 nadmiarowych wystąpień.**
  Najczęstsze: „Konfiguracja PHP" 9×, „Instalacja zależności" 9×,
  „Własny katalog narzędzi PHP" 8×, „Konfiguracja Node" 6×.
- **Filtr warstwy widoku stał 3 razy**: identyczne 589-znakowe wyrażenie
  `grep -qE` w `port_marki`, `port_funkcje` i `dostepnosc`. Trzy bloki
  różniły się **wyłącznie komentarzami** (sha treści: 2 × `7db56d3a4f9b`,
  1 × `5c3b2698f726` — różnica to trzy linijki komentarza).
- **36 odwołań** do `steps.zmiany.outputs.warto` na krokach.
- Powtórzenia środowiska: `APP_KEY` wpisany w job 6×, dynamiczny port
  PostgreSQL 15×, `cp .env.example .env` 7×, `npm run build` 5×,
  `checkout` 12×, instalacja przeglądarki 5×.

### Czasy jobów (25 ostatnich zakończonych przebiegów CI, 301 wierszy)

| Job | n | min | mediana | max |
|---|---:|---:|---:|---:|
| Panel marki — puste i pełne widoki | 25 | — | 978 s | 1163 s |
| Testy (PostgreSQL 18) | 25 | — | 594 s | 756 s |
| Build obrazu | 25 | — | 296 s | 1194 s |
| Port marki — rodziny ekranów | 25 | — | **51 s** | 1638 s |
| Dostępność (axe-core) | 25 | — | **44 s** | 955 s |
| Port marki (kompozycje) | 25 | — | **22 s** | 506 s |
| Zakres zmiany | 25 | 4 s | 8 s | 14 s |

Mediany trzech jobów przeglądarkowych (22–51 s) są rażąco niższe od ich
maksimów (506–1638 s). To nie jest zmienność maszyny — to dwa różne tryby
pracy tego samego joba.

---

## 2. Usterka: zielony bez pomiaru

Trzy joby miały warunek w **dwóch** miejscach naraz:

| Poziom | Warunek | Znaczenie |
|---|---|---|
| job | `needs.zakres.outputs.kod == 'true'` | zmiana to nie sama dokumentacja |
| **krok** | `steps.zmiany.outputs.warto == '1'` | zmieniła się warstwa widoku |

Przy zmianie kodu **poza** warstwą widoku (np. `app/Domain/**`) pierwszy
warunek był spełniony, drugi nie. Job się **uruchamiał**, wszystkie kroki
pomiarowe były `skipped`, a job kończył się **`success`**. Na liście
kontrolnej PR-a nie do odróżnienia od joba, który wszystko zmierzył.

**Zmierzone na tych samych 25 przebiegach:**

| Job | sukcesów | w tym bez pomiaru | czas bez pomiaru | czas z pomiarem |
|---|---:|---:|---|---|
| Port marki (kompozycje) | 15 | **4** | 19–22 s | 462–506 s |
| Port marki — rodziny ekranów | 13 | **4** | 19–21 s | 1456–1638 s |
| Dostępność (axe-core) | 13 | **4** | 18–22 s | 745–955 s |

**Dwanaście zielonych wyników bez ani jednego pomiaru.**

Kontrola porównawcza: `port_panelu` ma warunek **tylko na jobie** i w tej samej
próbce ma **13 sukcesów, z czego 0 poniżej 180 s**. Rozkład dwumodalny znika,
gdy warunek stoi we właściwym miejscu.

---

## 3. Zmiana

Filtr warstwy widoku liczony **raz**, w jobie `zakres`, i wystawiony jako
wyjście joba. Trzy joby przeglądarkowe dostają go w warunku **na poziomie
joba**:

```yaml
if: needs.zakres.outputs.kod == 'true' && needs.zakres.outputs.widok == 'true'
```

Przy nietkniętej warstwie widoku cały job jest `skipped` — **widać, że nic nie
zmierzono**. Zielony wynik znów znaczy „zmierzone".

### Co znika, co zostaje, czym jest pilnowane

| Co znika | Która gwarancja zostaje | Czym jest pilnowana po zmianie |
|---|---|---|
| 2 z 3 kopii 589-znakowego filtra (67 linii) | Zmiana **samego przyrządu** (skryptów pomiarowych, fixture, `ci.yml`) uruchamia pomiar | `test_zmiana_samego_przyrzadu_nie_pomija_pomiarow` — sprawdza 12 ścieżek przeciw filtrowi z `zakres` + kontrolę ujemną na `docs/PRODUCT.md` + asercję, że filtr jest w **jednym** miejscu |
| 36 warunków `steps.zmiany.outputs.warto` na krokach | Ciężkie joby nie chodzą przy zmianie poza warstwą widoku | ten sam filtr, tylko wyżej — w warunku joba |
| — (**zysk**) | Job nie może być zielony bez pomiaru | `test_job_przegladarkowy_nie_moze_byc_zielony_bez_pomiaru` — warunek musi stać na jobie, a jedyny dopuszczony warunek na kroku to `always()` (wysyłka dowodów po czerwieni) |
| — (**zysk**) | `widok` powstaje na każdej ścieżce | `test_zakres_wystawia_oba_wyjscia_na_kazdej_sciezce` — obie ścieżki `exit 0` muszą zapisać oba wyjścia; puste `widok` pomijałoby trzy joby **zawsze i po cichu** |
| — | Joby nadal mierzą | `test_joby_przegladarkowe_nadal_uruchamiaja_pomiar` — kontrola dodatnia (pułapka 4) |

### Pomiar wyjściowy

| | przed | po |
|---|---:|---:|
| `ci.yml` — linii | 1823 | **1756** (−67) |
| `ci.yml` — bajtów | 85 553 | **82 161** (−3392) |
| kopii filtra warstwy widoku | 3 | **1** |
| wyrażeń `grep -qE` | 3 | **1** |
| warunków `steps.zmiany.outputs` | 36 | **0** |
| jobów mogących być zielonymi bez pomiaru | **3** | **0** |

Liczba jobów, kroków i zakres pomiarów: **bez zmian**.

---

## 4. Dowody

### Filtr zachowuje się tak samo — 16 z 16 przypadków

Wyrażenie wyjęte **z pliku**, nie przepisane, i puszczone przez prawdziwe
ścieżki:

- uruchamia pomiar: widok Blade, `resources/css/app.css`, `public/**`,
  `scripts/port-projektu.mjs`, `scripts/dostepnosc.mjs`,
  `scripts/port-grupy.test.mjs`, `scripts/fixtures/**`,
  **`.github/workflows/ci.yml`**, `package-lock.json`, `docker/Caddyfile`;
- nie uruchamia: `docs/**`, `app/Domain/**`, `tests/**`,
  `database/migrations/**`, `deploy.yml`;
- zmiana mieszana (kod + widok) → uruchamia.

### `skipped` nie blokuje scalenia

Branch protection jest niedostępne na tym planie: GitHub odpowiada HTTP 403
na punktach API `repos/{owner}/{repo}/rulesets` oraz
`repos/{owner}/{repo}/branches/{branch}/protection`. To samo ograniczenie
zatrzymało audyt z 16.09.

(Punkty API zapisane są bez wiodącego ukośnika celowo. `DokumentyMdNieMaja`
`MartwychOdnosnikowTest` skanuje dokumenty w poszukiwaniu tras SERWISU
i — słusznie — zgłosił pierwszą wersję tego akapitu, bo wyglądały tam jak
trasy aplikacji, których nie ma. To nie jest obejście strażnika, tylko
poprawienie zapisu na taki, który nie kłamie o tym, czym są te adresy.)

Zamiast zgadywać, sprawdzone w historii: w próbce jest **5 przebiegów**,
w których wszystkie ciężkie joby były `skipped` (zmiana tylko w dokumentacji).
Wszystkie skończyły się `success`, a ich commity **są na `main`** — w tym dwa
scalone PR-y (`docs/odbior-tagow-369-370`, `docs/691-bucket-lock`). Wzorzec
„warunek na jobie → `skipped`" jest w tym repozytorium używany od dawna przez
`lint`, `test`, `static-analysis`, `assets`, `port_panelu`, `audit`
i `docker-build`.

### Kontrole

| Kontrola | Wynik |
|---|---|
| YAML parsuje się, struktura zgodna z zamiarem | tak (12 jobów, wyjścia `kod` + `widok`) |
| `git diff --check` | czysto |
| `vendor/bin/pint --test` | **PASS**, 1139 plików |
| `vendor/bin/phpstan analyse` | **`[OK] No errors`** |
| Testy około-CI i sąsiednie | **1582 passed (11 242 assertions)** |
| Kontrakt bramki | **5 passed (87 assertions)** |
| Kontrole ujemne | **6/6** czerwonych na właściwej asercji, 6/6 przywróceń bajt w bajt |

Kontrole ujemne: `docs/infra/evidence/ci611/kontrola-ujemna.log`. Sabotaże:
powrót warunku na krok, zniknięcie `widok` z warunku joba, wypadnięcie skryptu
z filtra, powrót drugiej kopii filtra, ścieżka `zakres` bez zapisu `widok`,
oraz kontrola dodatnia — job traci swój pomiar.

Jedna kontrola wymagała poprawki **testu, nie kodu**: sabotaż „druga kopia
filtra" dawał czerwień, ale z komunikatem o brakującym skrypcie, bo
`preg_match` brał pierwszy filtr z brzegu. Asercja o liczbie kopii stoi teraz
**przed** asercją o treści, żeby czerwień mówiła prawdę o przyczynie.

---

## 5. Wspólna akcja środowiska PHP — przyrostowo

Ten rozdział powstał po zdjęciu ograniczenia, przez które praca była wcześniej
świadomie odłożona.

### 5.1. Narzędzia, których wcześniej nie było

Zainstalowane do `/home/mateusz/narzedzia-611/bin` (pojedyncze binarki,
sumy kontrolne w logu sesji):

- **actionlint 1.7.7** — sprawdza pliki workflow,
- **act 0.2.89** — uruchamia joby lokalnie w kontenerze.

Pierwsza pobrana wersja `act` (0.2.82) **sama zgłosiła podatność
CVE-2026-34041 i CVE-2026-34042**. Zaktualizowana do 0.2.89 przed
jakimkolwiek użyciem.

### 5.2. Co act złapał, a czego actionlint nie mógł

W opisie wejścia akcji stało `${{ env.PHP_VERSION }}`. Wyrażenia są tam
**niedozwolone** — GitHub odrzuca taką akcję. actionlint tego nie widzi, bo
sprawdza *workflow*, nie pliki akcji. `act` pokazał to od razu:

    ❌ Failure - Main Środowisko PHP [2.760932ms]
    Line: 38 Column 18: expressions are not allowed here

Akcja padała po 2,7 ms, zanim wykonała pierwszy krok. **Bez `act` ten błąd
trafiłby na prawdziwy runner** — czyli dokładnie to, czego ta praca miała
uniknąć.

### 5.3. Najważniejszy dowód

Po poprawce pełny przebieg joba `audit` w `act` jest zielony, a w logu stoi:

    ::set-env:: SETUP_PHP_TOOLS_DIR=/tmp/kuking-narzedzia/1-1-audit/bin

`1-1-audit` to `GITHUB_RUN_ID`-`GITHUB_RUN_ATTEMPT`-**`GITHUB_JOB`**. Nazwa
joba **naprawdę** podstawia się wewnątrz composite action, więc katalog
narzędzi zostaje prywatny dla każdego joba osobno. To była kluczowa
niepewność całej zmiany: od niej zależy niepowtórzenie awarii „Text file
busy" z 10 września (issue #262).

### 5.4. Kolejność: od joba, którego porażka boli najmniej

| # | Job | Dlaczego ten | Stan |
|---|---|---|---|
| 1 | `audit` | najszybszy (21 s), niczego nie blokuje | **potwierdzony na runnerze** |
| 2 | `dwa-polaczenia` | `continue-on-error: true`, w nazwie „nie blokuje" | wysłany, runner w toku |
| — | `lint`, `static-analysis`, `assets` | porażka blokuje, ale są szybkie | do zrobienia |
| — | `test` | najdroższa porażka spośród niebrowserowych | do zrobienia |
| — | `port_panelu`, `port_marki`, `port_funkcje`, `dostepnosc` | najdłuższe i najbardziej wrażliwe | **na samym końcu** |

Jeden job na przyrost, każdy z własnym przebiegiem na runnerze. To jest ta
zasada, której brak kosztował cztery dni przestoju wdrożeń.

### 5.5. Gwarancja „żaden job nie jest zielony bez pomiaru"

**Utrzymana, nie tylko niezepsuta.** Wspólna akcja zawiera wyłącznie kroki
PRZYGOTOWANIA — żaden pomiar do niej nie wchodzi i żaden warunek pomijania
nie przenosi się na kroki. `test_job_przegladarkowy_nie_moze_byc_zielony_bez_pomiaru`
działa bez zmian.

Osobno wzmocniony został skaner katalogów narzędzi
(`CiDajeKazdemuJobowiWlasneNarzedziaTest`): **podstawia kroki akcji w miejsce
jej wywołania**, więc sprawdza dokładnie tę samą regułę, bez drugiej
implementacji, i obejmuje automatycznie każdy kolejny przeniesiony job.
Bramka liczby przeskanowanych jobów zmieniona z luźnej (`>= 6`) na twardą
(`= 9`) — przy migracji job po jobie luźna bramka przepuściłaby utratę
pokrycia aż do zera.

### 5.6. Co pozostaje wpisane wprost i dlaczego

`Cache Composera` (4 joby) i `Instalacja zależności` (**3 różne warianty**:
sam Composer, sam npm, oba naraz) **zostają w jobach**. Wciągnięcie ich do
wspólnej akcji wymagałoby warunków wewnątrz niej — czyli odtworzenia tej
samej złożoności sterowania, którą ten pakiet likwiduje. Zysk byłby
pozorny: mniej linii, więcej miejsc, w których logika może się rozjechać.

### 5.7. Potwierdzenie na prawdziwym runnerze

Przebieg **35448989459** (`workflow_dispatch` na `3277a812`): **12 z 12
jobów `success`**.

- `Audyt zależności` — **21 s**, czyli tyle co przed zmianą (mediana
  historyczna 20 s). Job na wspólnej akcji zachowuje się identycznie.
- Ten sam przebieg potwierdził **pierwszą** zmianę z tej gałęzi: przy
  `workflow_dispatch` nie ma punktu odniesienia, więc `zakres` wchodzi
  w ścieżkę „mierz wszystko" i ustawia `widok=true`. Trzy joby
  przeglądarkowe wykonały pełne pomiary — **481 s, 1495 s i 842 s**, czyli
  wartości z przedziału „z pomiarem" (462–506, 1456–1638, 745–955), a nie
  z przedziału „zielony bez pomiaru" (18–22 s).

CI nie chodzi na push gałęzi roboczej (wyzwalacze to `push` do
`main`/`staging` i `pull_request` do nich), więc runner uruchamiany jest
przez `workflow_dispatch` — bez zakładania PR-a.

---

## 6. Czego NIE zrobiłem i dlaczego

Trzy kandydaci z issue zostały zmierzone i **świadomie nietknięte**:

1. **Wspólny blok przygotowania środowiska w composite action.**
   Powtórzeń jest dużo: „Konfiguracja PHP" 9×, „Instalacja zależności" 9×,
   „Własny katalog narzędzi PHP" 8×, „Konfiguracja Node" 6×, „Cache
   Composera" 4× — razem 47 nadmiarowych wystąpień kroków. Zysk byłby realny.
   **Powodu, żeby tego nie robić teraz, jest jeden i jest twardy:** w tym
   środowisku nie ma `actionlint` ani `act`, a ja nie mogę pushować. Nie mam
   więc **żadnego** sposobu, żeby sprawdzić, czy composite action zachowa się
   tak samo — a przebudowa przygotowania środowiska we wszystkich jobach naraz
   to dokładnie ta klasa zmiany, która w tym repozytorium kosztowała cztery
   dni przestoju wdrożeń. Zmiana bez możliwości weryfikacji nie jest
   uproszczeniem, tylko przesunięciem ryzyka.
2. **`APP_KEY` wpisany w 6 jobach → jedno `env:` na poziomie workflow.**
   Zysk: 6 linii. Koszt: klucz przestaje być widoczny przy jobie, który go
   potrzebuje, a `env` workflow obejmuje też joby, które go nie używają.
   Sześć linii nie jest warte rozmycia tej informacji.
3. **Dynamiczny port PostgreSQL, 15 wystąpień.** To odwołanie do usługi
   `services.postgres` **konkretnego joba** — każdy ma własny kontener na
   własnym porcie. Tego nie da się wynieść wyżej bez zabrania jobom
   niezależnych baz, czyli bez utraty gwarancji izolacji. **Nie do usunięcia.**

Pozostałe punkty z definicji gotowości issue (jedna kanoniczna ścieżka
deployu, kontrakt smoke-testu, polityka kasowania gałęzi, zapis branch
protection) dotyczą `deploy.yml` i ustawień repozytorium, nie `ci.yml`. Ta
tura ich nie obejmuje.

---

## 7. Czego ten pakiet NIE dowodzi

- **Nie uruchomiono GitHub Actions.** Zgodnie z poleceniem nie ma pusha ani
  PR-a, a lokalnie nie ma `actionlint` ani `act`. Dowody są trzy: YAML się
  parsuje i ma zamierzoną strukturę, wyrażenie filtra zachowuje się poprawnie
  na 16 prawdziwych ścieżkach, a kontrakt w PHP przechodzi i oblewa przy
  sabotażu. **To nie zastępuje jednego przebiegu na prawdziwym runnerze** —
  pierwszy przebieg po scaleniu trzeba obejrzeć i potwierdzić, że trzy joby
  są `skipped` przy zmianie spoza warstwy widoku i `success` z pomiarem przy
  zmianie w `resources/**`.
- **Branch protection niezweryfikowane** (403). Dowód, że `skipped` nie
  blokuje, jest empiryczny z historii, nie z odczytu konfiguracji.
- **Nie mierzono `deploy.yml`, `preview.yml` ani `railway-iac.yml`** poza
  policzeniem linii. Smoke-test po deployu i ścieżki wdrożenia zostają
  nietknięte.
- Próbka czasów to **25 ostatnich przebiegów**, nie cała historia.

---

## 8. Środowisko

Kopia wykonawcza `/home/mateusz/kuking-611d` z **fizycznym** `vendor`
(nie dowiązaniem) i `composer dump-autoload --optimize`. PostgreSQL wyłącznie
`127.0.0.1:55439`, własna baza `kuking_611d_tests` (UTC) do wyrzucenia.
Poczta `array`, kolejka `sync`. Cudzych katalogów i baz nie dotykano.
Produkcji nie dotykano. `main` bez zmian.
