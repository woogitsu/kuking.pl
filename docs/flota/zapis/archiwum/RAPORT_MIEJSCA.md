# Raport o miejscu na dysku — flota Kuking.pl

Sporządzony: 21 września 2026, ok. 08:10 CEST. **Nic nie zostało skasowane** —
to jest materiał do decyzji właściciela, zgodnie ze zleceniem.

Środowisko jest żywe: w trakcie tego audytu w `/home/mateusz/flota` pojawił się
nowy runtime (`naprawa-795-run`), którego nie było jeszcze w zrzucie `du`, a
mimo to okazał się **aktywnie używany** (test w toku). Liczby poniżej to zrzut
z chwili sprawdzania — traktuj je jako dolną granicę, nie stan zamrożony.

---

## 0. Streszczenie

| Co | Wartość |
|---|---|
| `/home/mateusz/flota` łącznie | **≈ 97 GB** (`du -sh --apparent-size`; wczoraj wieczorem 88 GB — przybyło ~9 GB w noc/poranek) |
| Katalogów `*-run` | **169** w zrzucie `du` (+ co najmniej 1 powstały już w trakcie audytu, patrz §4) |
| `vendor` we wszystkich `*-run` | **29 GB** |
| `node_modules` we wszystkich `*-run` | **45 GB** |
| `vendor` + `node_modules` razem | **74 GB ≈ 76,3%** całości `/home/mateusz/flota` (audyt z 20.09 mówił 76% — **potwierdzone, bez zmian**) |
| Runtime'y-sieroty (brak żywego odpowiednika w `kuking-flota`, **nieużywane teraz**) | **23 katalogi, ≈ 12 GB** |
| Bazy `kuking_flota_*` w PostgreSQL | **175**, razem **5,32 GB** |
| Bazy-sieroty (brak `*-run` i brak katalogu w `kuking-flota`) | **4 bazy, ≈ 55 MB** |
| Procesy używające runtime'ów TERAZ | `push-run`, `gpt-cloudflare-cache-ODZYSK-run`, `naprawa-795-run`, `gpt-ci-architektura-ODZYSK-run` — **żadnego z nich nie ruszać** |

**Najbezpieczniejszy, natychmiastowy zysk: ~12 GB** (sieroty runtime) **+ ~55 MB**
(sieroty bazodanowe) **= ~12,05 GB**, zero ryzyka dla żywej pracy — pod warunkiem
ponownego sprawdzenia `ps` tuż przed kasowaniem (patrz §6).

Największy potencjalny zysk to `vendor`/`node_modules` (74 GB), ale to wymaga
polityki, nie jednorazowego sprzątania — patrz §5 i §6.

---

## 1. Metoda

1. `wsl -d Ubuntu -- bash -c "du -sh --apparent-size /home/mateusz/flota/*-run"` — rozmiary.
2. Dla każdego `<nazwa>-run` sprawdzone, czy w `C:\Users\matma\Documents\kuking-flota\`
   istnieje katalog `<nazwa>` (dokładne dopasowanie nazwy, bez sufiksu `-run`) —
   źródło: `Get-ChildItem`/`ls` na `kuking-flota` (259 pozycji, w tym pliki luźne
   i katalogi wspólne — patrz `INWENTARZ_FLOTY.md` §2 dla ich klasyfikacji).
   **Uwaga metodologiczna**: to sprawdza tylko *istnienie katalogu o pasującej
   nazwie*, nie czy ten katalog ma działający `.git` — zgodnie z poprzednim
   audytem (`INWENTARZ_FLOTY.md`) część katalogów windowsowych to martwe
   wskaźniki worktree. Dla samej klasyfikacji sierota/ma-odpowiednik to nie
   zmienia wyniku (runtime bez ŻADNEGO katalogu-imiennika jest sierotą tym
   bardziej), ale znaczy, że część „ma odpowiednik" może mieć odpowiednik
   martwy po stronie Windows — to osobny problem, opisany w `INWENTARZ_FLOTY.md`,
   nie w tym raporcie.
3. `ps -eo args | grep <nazwa>` dla każdego kandydata do sieroctwa oraz ogólnie
   `ps -eo args | grep flota` — żeby złapać procesy używające runtime'u teraz.
4. `vendor`/`node_modules`: `du -shc --apparent-size` po wszystkich `*-run/vendor`
   i `*-run/node_modules` osobno.
5. PostgreSQL `127.0.0.1:55439`, baza `postgres`, użytkownik `kuking` (dane
   logowania z `.env` runtime'u — domyślny `psql -l` bez `-U` pada, bo brak
   roli `postgres`). `select datname, pg_size_pretty(pg_database_size(datname))
   from pg_database where datname like 'kuking_flota_%'`.

**`git worktree prune` NIE zostało uruchomione** — ani na Windows, ani w WSL.
Nic nie zostało skasowane, przeniesione, ani zmienione.

---

## 2. Tabela runtime'ów `/home/mateusz/flota/*-run` (169, malejąco wg rozmiaru)

Legenda: **W UŻYCIU TERAZ** = żywy proces w `ps` w chwili audytu, nie ruszać
pod żadnym pozorem. **ma odpowiednik** = istnieje katalog o tej nazwie w
`kuking-flota`. **SIEROTA** = brak takiego katalogu ORAZ brak procesu — kandydat
do sprzątania.

| Runtime | Rozmiar | Ocena |
|---|---|---|
| push | 844M | W UŻYCIU TERAZ |
| tagi-filtr | 686M | ma odpowiednik |
| gpt-openai-granice | 684M | ma odpowiednik |
| gpt-konto-poczta-ODZYSK | 682M | ma odpowiednik |
| gpt-ai-piloty-ODZYSK | 682M | ma odpowiednik |
| gpt-heic-format | 644M | ma odpowiednik |
| sledztwo-914 | 629M | ma odpowiednik |
| gpt-panel-moderacji-marka | 625M | ma odpowiednik |
| naprawa-918 | 586M | ma odpowiednik |
| gpt-monitoring | 582M | ma odpowiednik |
| gpt-zalegle | 569M | ma odpowiednik |
| kaskada | 564M | ma odpowiednik |
| hero-ekran | 564M | ma odpowiednik |
| naprawa-906 | 562M | ma odpowiednik |
| komentarze | 562M | ma odpowiednik |
| gpt-zeszyt-droga | 562M | ma odpowiednik |
| gpt-pytania-poradzcie | 562M | ma odpowiednik |
| gpt-kreator-przepisu | 562M | ma odpowiednik |
| gpt-2fa-ustawienia | 562M | ma odpowiednik |
| dsa-odwolania | 562M | ma odpowiednik |
| zaleglosci-zgloszen | 561M | ma odpowiednik |
| r49-trasy | 561M | ma odpowiednik |
| gpt-wyszukiwanie-granice | 561M | ma odpowiednik |
| gpt-konto-poczta | 561M | ma odpowiednik |
| gotowanie-ODZYSK | 561M | ma odpowiednik |
| **BAZA-main** | **561M** | **SIEROTA** |
| scal-915-kaskada | 560M | ma odpowiednik |
| scal-786-izolacja | 560M | ma odpowiednik |
| odwolanie-link | 560M | ma odpowiednik |
| naprawa-923 | 560M | ma odpowiednik |
| naprawa-847 | 560M | ma odpowiednik |
| martwe-kaskady | 560M | ma odpowiednik |
| gpt-wspomnienia-prywatnosc | 560M | ma odpowiednik |
| gpt-onboarding | 560M | ma odpowiednik |
| gpt-haslo-konto | 560M | ma odpowiednik |
| gpt-ai-piloty | 560M | ma odpowiednik |
| straznik-r60 | 559M | ma odpowiednik |
| naprawa-750 | 559M | ma odpowiednik |
| jedna-droga | 559M | ma odpowiednik |
| gpt-zalegle-ODZYSK | 559M | ma odpowiednik |
| gpt-wersje-przepisu | 559M | ma odpowiednik |
| gpt-ustawienia-profilu | 559M | ma odpowiednik |
| gpt-ugotowalem-dostep | 559M | ma odpowiednik |
| **gpt-pytania-poradzcie-browser** | **559M** | **SIEROTA** |
| **gpt-panel-moderacji-marka-php** | **559M** | **SIEROTA** |
| gpt-odbior-wdrozen | 559M | ma odpowiednik |
| gpt-kontakt-formularz | 559M | ma odpowiednik |
| gpt-eksport | 559M | ma odpowiednik |
| gpt-dlug-weryfikacyjny | 559M | ma odpowiednik |
| gpt-cold-start | 559M | ma odpowiednik |
| ci-hybryda | 559M | ma odpowiednik |
| bramka-startowa | 559M | ma odpowiednik |
| nazwa-a-relacje | 558M | ma odpowiednik |
| naprawa-858 | 558M | ma odpowiednik |
| main-porownanie | 558M | ma odpowiednik |
| gpt-zdjecia-publikacja | 558M | ma odpowiednik |
| gpt-zdjecia-limity | 558M | ma odpowiednik |
| gpt-zamkniecia-607-608-i-inwentarz-ci | 558M | ma odpowiednik |
| gpt-turnstile-pomoc | 558M | ma odpowiednik |
| gpt-tokeny-zaproszen | 558M | ma odpowiednik |
| gpt-tablica | 558M | ma odpowiednik |
| gpt-skladniki | 558M | ma odpowiednik |
| gpt-redis-ha | 558M | ma odpowiednik |
| gpt-pytania-widoki | 558M | ma odpowiednik |
| gpt-przepis-liczniki | 558M | ma odpowiednik |
| gpt-n1-powiadomienia | 558M | ma odpowiednik |
| gpt-moderacja-ai | 558M | ma odpowiednik |
| gpt-kontakt-panel | 558M | ma odpowiednik |
| gpt-cloudflare-cache-ODZYSK | 558M | **W UŻYCIU TERAZ** |
| zeszyty | 557M | ma odpowiednik |
| zdjecia-formularze | 557M | ma odpowiednik |
| tagi-filtr-ODZYSK | 557M | ma odpowiednik |
| stan-sesji-ODZYSK | 557M | ma odpowiednik |
| stan-sesji-2 | 557M | ma odpowiednik |
| scal-kaskada-straznik | 557M | ma odpowiednik |
| prywatnosc-formularz | 557M | ma odpowiednik |
| powiadomienia | 557M | ma odpowiednik |
| notyfikacja-zywa | 557M | ma odpowiednik |
| naprawa-kontrola-dodatnia | 557M | ma odpowiednik |
| naprawa-858-ODZYSK | 557M | ma odpowiednik |
| naprawa-619 | 557M | ma odpowiednik |
| gpt-zeszyt-zapisy | 557M | ma odpowiednik |
| gpt-zawieszone-konto | 557M | ma odpowiednik |
| gpt-widget-wyglad | 557M | ma odpowiednik |
| gpt-tagi | 557M | ma odpowiednik |
| gpt-tagi-miejsce | 557M | ma odpowiednik |
| gpt-rozbicie-uslug | 557M | ma odpowiednik |
| gpt-pwa-push | 557M | ma odpowiednik |
| gpt-planer-zakupy | 557M | ma odpowiednik |
| gpt-odbior-czy-przygotowanie | 557M | ma odpowiednik |
| gpt-ocr-import | 557M | ma odpowiednik |
| gpt-obciazenie | 557M | ma odpowiednik |
| gpt-obciazenie-ODZYSK | 557M | ma odpowiednik |
| gpt-kontakt-panel-ODZYSK | 557M | ma odpowiednik |
| gpt-harmonogram | 557M | ma odpowiednik |
| gpt-dziennik-wyjatkow | 557M | ma odpowiednik |
| gpt-dr-zdjecia | 557M | ma odpowiednik |
| gpt-dr-baza | 557M | ma odpowiednik |
| gpt-cloudflare-cache | 557M | ma odpowiednik |
| gpt-ci-architektura | 557M | ma odpowiednik |
| gpt-analityka-piksel | 557M | ma odpowiednik |
| **gemini-kontakt-formularz** | **557M** | **SIEROTA** |
| dowod-922 | 557M | ma odpowiednik |
| zaleglosci-zgloszen-ODZYSK | 556M | ma odpowiednik |
| wyszukiwarka | 556M | ma odpowiednik |
| straznik-format | 556M | ma odpowiednik |
| straznik-format-ODZYSK | 556M | ma odpowiednik |
| sledztwo-725 | 556M | ma odpowiednik |
| scal-izolacja-bazy-klon | 556M | ma odpowiednik |
| relacje | 556M | ma odpowiednik |
| r73-feed | 556M | ma odpowiednik |
| r47-skan | 556M | ma odpowiednik |
| prog-postgresa | 556M | ma odpowiednik |
| proba-scalenia | 556M | ma odpowiednik |
| powiadomienia-ODZYSK | 556M | ma odpowiednik |
| poswiadczenia | 556M | ma odpowiednik |
| odwolanie-link-ODZYSK | 556M | ma odpowiednik |
| notyfikacja-zywa-ODZYSK | 556M | ma odpowiednik |
| naprawa-wyglad | 556M | ma odpowiednik |
| naprawa-906-v2 | 556M | ma odpowiednik |
| naprawa-847-ODZYSK | 556M | ma odpowiednik |
| naprawa-119 | 556M | ma odpowiednik |
| **kontrola-main-cache** | **556M** | **SIEROTA** |
| **kontrola-923-main** | **556M** | **SIEROTA** |
| klient-pg18-NOWY | 556M | ma odpowiednik |
| jedna-droga-ODZYSK | 556M | ma odpowiednik |
| hero-ekran-ODZYSK | 556M | ma odpowiednik |
| gpt-zeszyt-droga-ODZYSK | 556M | ma odpowiednik |
| gpt-widmo-zamkniec | 556M | ma odpowiednik |
| gpt-testy-50plus | 556M | ma odpowiednik |
| gpt-tag-tygodnia | 556M | ma odpowiednik |
| gpt-sonda-wdrozenia | 556M | ma odpowiednik |
| gpt-sonda-wdrozenia-ODZYSK | 556M | ma odpowiednik |
| gpt-r2-jurysdykcja | 556M | ma odpowiednik |
| gpt-pomiar-feedu-i-budzetu | 556M | ma odpowiednik |
| gpt-offline-obietnica | 556M | ma odpowiednik |
| gpt-odbior-wdrozen-ODZYSK | 556M | ma odpowiednik |
| gpt-n1-powiadomienia-ODZYSK | 556M | ma odpowiednik |
| gpt-monitoring-ODZYSK | 556M | ma odpowiednik |
| gpt-moja-wersja | 556M | ma odpowiednik |
| gpt-harmonogram-ODZYSK | 556M | ma odpowiednik |
| gpt-grupy-tematyczne | 556M | ma odpowiednik |
| gpt-ci-architektura-ODZYSK | 556M | **W UŻYCIU TERAZ** |
| gotowanie | 556M | ma odpowiednik |
| **gemini-zeszyt-zapisy** | **556M** | **SIEROTA** |
| **gemini-zdjecia-publikacja** | **556M** | **SIEROTA** |
| **gemini-zalegle** | **556M** | **SIEROTA** |
| **gemini-wglady** — *ma odpowiednik* (katalog `gemini-wglady` istnieje) | 556M | ma odpowiednik |
| **gemini-turnstile-pomoc** | **556M** | **SIEROTA** |
| **gemini-tagi** | **556M** | **SIEROTA** |
| **gemini-tablica** | **556M** | **SIEROTA** |
| **gemini-skladniki** | **556M** | **SIEROTA** |
| gemini-retencja — *ma odpowiednik* (katalog `gemini-retencja` istnieje) | 556M | ma odpowiednik |
| **gemini-pytania-widoki** | **556M** | **SIEROTA** |
| **gemini-pytania-eksport** | **556M** | **SIEROTA** |
| gemini-profil — *ma odpowiednik* (katalog `gemini-profil` istnieje) | 556M | ma odpowiednik |
| **gemini-onboarding** | **556M** | **SIEROTA** |
| **gemini-n1-powiadomienia** | **556M** | **SIEROTA** |
| **gemini-moderacja-ai** | **556M** | **SIEROTA** |
| **gemini-kontakt-panel** | **556M** | **SIEROTA** |
| **gemini-haslo-konto** | **556M** | **SIEROTA** |
| **gemini-harmonogram** | **556M** | **SIEROTA** |
| **gemini-eksport** | **556M** | **SIEROTA** |
| gemini-adr — *ma odpowiednik* (katalog `gemini-adr` istnieje) | 556M | ma odpowiednik |
| **gemini-794** — *ma odpowiednik* (katalog `gemini-794` istnieje) | 556M | ma odpowiednik |
| **gemini-2fa-ustawienia** | **556M** | **SIEROTA** |
| drobne | 556M | ma odpowiednik |
| diagnoza-uuid | 556M | ma odpowiednik |
| bazy-stanowisk | 556M | ma odpowiednik |

**Poza tym zrzutem**: `naprawa-795-run` — powstał w trakcie tego audytu (nie
było go w `du`, ale w `ps` widać aktywny test przeciw niemu). Ma odpowiednik
(`naprawa-795`, żywy worktree, gałąź `naprawa/795-powrot-ze-zgloszenia`) i jest
**w użyciu teraz** — nie kandydat do niczego.

### 2a. Podsumowanie sierot

**23 runtime'y-sieroty, razem ≈ 12 GB** (`du -shc`), żaden bez aktywnego procesu:

```
BAZA-main, gemini-2fa-ustawienia, gemini-eksport, gemini-harmonogram,
gemini-haslo-konto, gemini-kontakt-formularz, gemini-kontakt-panel,
gemini-moderacja-ai, gemini-n1-powiadomienia, gemini-onboarding,
gemini-pytania-eksport, gemini-pytania-widoki, gemini-skladniki,
gemini-tablica, gemini-tagi, gemini-turnstile-pomoc, gemini-zalegle,
gemini-zdjecia-publikacja, gemini-zeszyt-zapisy, gpt-panel-moderacji-marka-php,
gpt-pytania-poradzcie-browser, kontrola-923-main, kontrola-main-cache
```

Wzorzec: 17 z 23 to seria `gemini-*` po ~556 MB każdy — wygląda na jedną falę
runtime'ów stanowiska `gemini`, których windowsowe worktree już nie istnieją
(albo nigdy nie istniały pod tą nazwą — część `gemini-*` ma swój odpowiednik,
np. `gemini-adr`, `gemini-794`, `gemini-profil`, `gemini-retencja`,
`gemini-wglady`, więc to nie jest "cała rodzina gemini", tylko jej podzbiór).

---

## 3. `vendor` i `node_modules`

| Składnik | Rozmiar | % `/home/mateusz/flota` |
|---|---|---|
| `vendor` (suma po wszystkich `*-run`) | 29 GB | ~30% |
| `node_modules` (suma po wszystkich `*-run`) | 45 GB | ~46% |
| **Razem** | **74 GB** | **~76,3%** |

Zgodne z poprzednim audytem (`INWENTARZ_FLOTY.md`: „58 GB ≈ 76% z 76 GB") —
proporcja **nie zmieniła się**, mimo że całość urosła z 76 GB do 97 GB. To
oznacza, że przyrost jest głównie w kolejnych pełnych runtime'ach
(`composer install` + `npm install` za każdym razem), nie w czymś innym.

**Ważne dla `przygotuj-runtime.sh`** (zasada floty #8): `vendor`/`node_modules`
są kopiowane, nie linkowane symlinkiem — więc każdy z 169 runtime'ów ma **własną,
pełną kopię**. To jest bezpieczne dla testów, ale kosztuje 74 GB. Skrypt
odbudowuje je automatycznie przy każdym uruchomieniu, więc dla runtime'ów, do
których nikt nie wraca, `vendor`/`node_modules` da się skasować bez utraty pracy
(kod źródłowy w `-run` to kopia worktree, nie jedyne miejsce, gdzie on istnieje).

---

## 4. Bazy PostgreSQL `kuking_flota_*` (`127.0.0.1:55439`)

**175 baz, razem 5,32 GB.** Rozstrzał rozmiarów duży — od 7,6 kB do 2,6 GB:

- **`kuking_flota_gpt_dr_baza_source` — 2,62 GB**, zdecydowanie największa,
  ponad połowa całej sumy 175 baz. Nazwa („source") sugeruje bazę-źródło do
  klonowania w teście `gpt-dr-baza` (runtime `gpt-dr-baza` istnieje, ma
  odpowiednik) — **nie oceniam jej jako sierotę**, ale jej rozmiar wart jest
  osobnego sprawdzenia przez właściciela: 2,6 GB to nietypowo dużo na bazę
  testową.
- Reszta mieści się w przedziale kilku–kilkudziesięciu MB — typowe dla baz
  testowych z migracjami i garścią danych.

### 4a. Bazy-sieroty (brak `*-run` I brak katalogu w `kuking-flota`)

| Baza | Rozmiar | Uwaga |
|---|---|---|
| `kuking_flota_format` | 17 MB | brak runtime `format-run`, brak katalogu `format` w `kuking-flota` |
| `kuking_flota_notyfikacja` | 15 MB | odrębna od `notyfikacja-zywa` (ta ma i runtime, i katalog — nie mylić) |
| `kuking_flota_gpttagi_kontrola` | 13 MB | brak runtime i katalogu pod żadnym wariantem pisowni (`gpttagi-kontrola` / `gpttagi_kontrola`) |
| `kuking_flota_794` | 11 MB | brak runtime/katalogu `794` (nie mylić z `gemini-794`, który ma i jedno, i drugie) |

**Razem: 4 bazy, ≈ 55 MB.** Małe kwoty, ale zero ryzyka — żaden proces, żaden
katalog źródłowy się do nich nie odwołuje pod tą nazwą.

**`kuking_flota_naprawa-795` NIE jest sierotą**, mimo że w chwili sprawdzania
`*-run` nie miała jeszcze runtime'u w zrzucie `du` — w trakcie audytu okazało
się, że test właśnie w niej pracuje (`ps`: `postgres: kuking kuking_flota_naprawa-795
… idle in transaction`, plus `phpunit --filter Ekran` przeciwko
`naprawa-795-run/phpunit.xml`). To jest dokładnie przykład ryzyka, przed którym
ostrzegają zasady floty: obraz z jednego momentu może mylnie wyglądać jak
sierota.

### 4b. Bazy, które WYGLĄDAJĄ na sieroty, ale nimi nie są (warianty testowe)

Te mają nazwy z dopiskiem `_browser`/`_a11y`/`_ui`/`-browser`/`-a11y`/`-ui`, ale
należą do runtime'u bez tego dopisku (osobna baza na test przeglądarkowy/a11y
tego samego stanowiska) — **nie liczę ich jako sieroty**:

```
gpt-cold-start_browser → gpt-cold-start-run
gpt-dr-baza-source → gpt-dr-baza-run (ta sama uwaga co w 4: rozmiar 2,6 GB)
gpt-panel-moderacji-marka-browser-20260920 → gpt-panel-moderacji-marka-run
gpt-tablica_ui → gpt-tablica-run
gpt-tagi_a11y, gpt_tagi_miejsce_a11y → gpt-tagi-run / gpt-tagi-miejsce-run
gpt-zdjecia-publikacja_browser → gpt-zdjecia-publikacja-run
gpt-zeszyt-droga_ui → gpt-zeszyt-droga-run
tagi_filtr_a11y → tagi-filtr-run
baza_main → BAZA-main (który sam jest runtime-sierotą — patrz §2a; jeśli
  BAZA-main-run zostanie skasowany, warto wtedy ocenić też tę bazę)
```

---

## 5. Katalogi windowsowe (`kuking-flota`, ~22 GB) — poza głównym zakresem tego zlecenia

Zlecenie dotyczyło runtime'ów WSL i baz PostgreSQL. Katalogi windowsowe
(`C:\Users\matma\Documents\kuking-flota`, ~21,7 GB wg poprzedniego audytu z
20.09) to osobny, większy problem: 119 martwych wskaźników worktree, z czego
54 bez jednoznacznie odzyskanej gałęzi — opisane szczegółowo w
`INWENTARZ_FLOTY.md` §1c i §3. **Nie powtarzam tu tej analizy** — wymaga
przeczytania zawartości katalogów, nie samego porównania rozmiarów, i
`INWENTARZ_FLOTY.md` wyraźnie mówi, że tego nie zrobiono jeszcze do końca.
Wspominam to tylko, żeby właściciel miał pełny obraz: **nawet 100% odzysku
z tego raportu (12 GB) nie rozwiązuje problemu miejsca w pojedynkę** — drugie
tyle leży po stronie Windows.

---

## 6. Proponowana kolejność sprzątania — od najbezpieczniejszego

1. **Bazy-sieroty (§4a), ~55 MB.** Zero zależności, zero procesu, zero
   katalogu źródłowego. `DROP DATABASE` na 4 bazach. Zysk symboliczny, ale
   dobry test procedury przed większym krokiem.
2. **Runtime'y-sieroty (§2a), ~12 GB, 23 katalogi.** Przed kasowaniem
   KAŻDEGO z nich: **odśwież `ps -eo args | grep <nazwa>` bezpośrednio przed
   `rm -rf`**, nie ufaj temu raportowi — środowisko rośnie w locie (patrz
   przykład `naprawa-795` w §4a, który zmienił status w trakcie tego samego
   audytu). Kasować pojedynczo, nie wsadowo, żeby jeden nietrafiony `rm -rf`
   nie poszedł w hurcie. `BAZA-main-run` (561 MB) i para
   `gpt-panel-moderacji-marka-php-run`/`gpt-pytania-poradzcie-browser-run`
   (559 MB każdy) wyglądają na warianty stanowisk, które mają żywy odpowiednik
   pod inną nazwą — przed kasowaniem sprawdzić, czy nazwa bazowa (`BAZA-main`
   bez `-php`, `gpt-panel-moderacji-marka` bez `-php`, `gpt-pytania-poradzcie`
   bez `-browser`) nie jest tym samym stanowiskiem co odpowiadający katalog
   windowsowy pod inną, powiązaną nazwą — a nie osobnym bytem.
3. **`vendor`/`node_modules` w runtime'ach BEZ aktywnego procesu i BEZ
   niedawnej modyfikacji** (np. `mtime` starszy niż 48h — do ustalenia progu
   przez właściciela), ~74 GB potencjału łącznie, ale realistycznie tylko
   część na raz. To największy zysk, ale i największe ryzyko: `przygotuj-runtime.sh`
   odbuduje je automatycznie przy następnym uruchomieniu, więc kasowanie samego
   `vendor`/`node_modules` (zostawiając resztę runtime'u) jest odwracalne bez
   utraty kodu — ale **wymaga polityki wieku/aktywności**, nie jednorazowej
   akcji, bo runtime'y są tworzone bez przerwy. Rekomendacja: zdecydować progu
   (np. „skasuj `vendor`+`node_modules` w runtime'ach nietykanych >48h i bez
   procesu") i zautomatyzować jako osobne zadanie, nie ręczne sprzątanie.
4. **`kuking_flota_gpt_dr_baza_source` (2,62 GB)** — nie sierota, ale
   nietypowo duża jak na bazę testową. Wymaga decyzji właściciela: czy to
   zamierzony rozmiar źródła do klonowania, czy przypadkowy narost (np.
   nieposprzątane dane testowe).
5. **Katalogi windowsowe (~22 GB, poza zakresem tego zlecenia)** — osobne
   zadanie, wymaga przeczytania zawartości 54 niejednoznacznych katalogów
   (`INWENTARZ_FLOTY.md` §1c), nie samego porównania nazw i rozmiarów.

**Czego NIE robić**: `git worktree prune` (zasada floty, killuje żywe worktree
WSL widziane z Windows jako "prunable"); kasowanie czegokolwiek z listy
„W UŻYCIU TERAZ" (§2); kasowanie `kuking_flota_naprawa-795` (wygląda na
sierotę w danych z `du`, ale jest aktywna — patrz §4a).
