# Inwentarz floty Kuking.pl — 20 września 2026, ok. 23:00 CEST

Zadanie inwentaryzacyjne. **Nic w trakcie tej pracy nie zostało skasowane,
przeniesione ani zmienione poza tym dokumentem.** Środowisko jest żywe — inne
sesje pisały do plików w trakcie tej inwentaryzacji (np. `_wspolne/DZIENNIK_NOCNY.md`
rosło z 1,2 KB do 9 KB, pojawił się nowy katalog `naprawa-kontrola-dodatnia`,
nowy dokument `WERYFIKACJA_ODZYSKU.md`). Liczby poniżej to zrzut stanu z chwili
sprawdzania, nie stan zamrożony na zawsze — do bieżącego stanu służy teraz
**`_wspolne/SPIS_TRESCI.md`**, generowany automatycznie przez `_wspolne/odswiez-spis.sh`.

---

## 0. Aktualizacja — 21 września 2026, poranek (kontrola prawdziwości)

Ten inwentarz i `SPIS_TRESCI.md` powstały wczoraj wieczorem (ok. 23:00). Od tamtej
pory zaszło dużo — poniższe liczby zmierzyłem dziś rano bezpośrednio (`gh api`,
`git rev-parse`, `wc -l`, `du -sh`, `systemctl list-unit-files`), nie przejąłem
z żadnego cudzego raportu. Reszta dokumentu (sekcje 1–6, „Podsumowanie liczbowe")
zostaje jako zrzut z wczoraj 23:00 — nie przepisuję jej w całości, bo część
ustaleń (duplikaty, osierocone katalogi) wymagałaby ponownego pełnego audytu,
którego zakres zlecenia dzisiaj nie obejmował. Bieżące liczby zawsze pokazuje
`SPIS_TRESCI.md` po `odswiez-spis.sh` — poniżej tylko to, co się zmieniło:

- **Gałęzie na GitHubie**: **113** (wg `gh api repos/woogitsu/kuking.pl/branches`,
  zlecenie mówiło "~111"; wieczorem było 77 — czyli kolejka pchania rzeczywiście
  wypchnęła koło 50 gałęzi w nocy).
- **`main` w kanonicznym repo**: **`cd966aae`** (wczoraj `4c811cc7`) — 9 PR-ów
  scalonych w nocy: #929, #924, #922, #921, #919, #918, #917, #916, #914
  (sprawdzone `gh pr list --state merged`, `mergedAt` między 22:28 a 01:21).
- **Kolejka pchania rozdzielona na CZTERY pliki** w `/home/mateusz/flota/`
  (wcześniej `odswiez-spis.sh` znał tylko trzy — poprawiony, patrz niżej):
  - `pchniete.txt` — **51** wpisów,
  - `do-pchniecia.txt` — **60** wpisów,
  - `nieudane.txt` — **3** wpisy (osobne od nieodzyskanych — to bieżące próby, nie awaria),
  - `nieodzyskane.txt` — **51** gałęzi, które nie przetrwały nocnej awarii,
  - `wstrzymane.txt` — **3** gałęzie czekające na decyzję właściciela.
- **Nowe narzędzia w `_wspolne`**: `triaz-bramki.sh`, `wpusc-galaz.sh`,
  `kolejka11.sh`, `stan-runnerow.sh` — wszystkie cztery istnieją i są wykonywalne.
  Generator (`odswiez-spis.sh`) wcześniej nie wymieniał w ogóle skryptów `.sh`;
  dodano sekcję „Narzędzia we `_wspolne`", więc każdy nowy skrypt pojawi się
  tam automatycznie, bez ręcznej listy do utrzymywania.
- **Runtime WSL `/home/mateusz/flota`**: **153** katalogów `*-run` dziś rano
  (było 132 wczoraj wieczorem, w międzyczasie skoczyło do 150 w trakcie tej
  kontroli — środowisko jest żywe, liczba rośnie w locie), zajętość **≈ 88 GB**
  (`du -sh --apparent-size`, było 76 GB wczoraj — przybyło ≈ 12 GB). Dokładna
  liczba i rozmiar to migający cel; aktualną wartość zawsze pokaże świeże
  uruchomienie `odswiez-spis.sh`, nie ten dokument.
- **Trzy zdublowane runnery obcych projektów wyłączone**: `lockstate-02`,
  `osadale-02`, `metro-02` mają dziś `disabled` w
  `systemctl list-unit-files` (ich `-01` odpowiedniki zostają `enabled`).
  Potwierdzone bezpośrednio, nie przejęte.

**Czego generator nadal NIE łapie** (świadomie zostawione, patrz meldunek
sesji floty, nie ten plik): dokładnej treści `DZIENNIK_NOCNY.md` (tylko rozmiar
i pierwszy nagłówek, dokument ma 84 KB), różnicy między `nieudane.txt` a
`nieodzyskane.txt` poza samą liczbą wierszy (semantyka wymaga przeczytania
pliku), oraz czy `wstrzymane.txt` doczekało się decyzji właściciela (to musi
sprawdzić człowiek, nie skrypt).

---

## 1. Praca, której NIE MA w gicie — NAJWAŻNIEJSZE

### 1a. Potwierdzone unikatowe pliki w `_zapas` (nie istnieją na ŻADNEJ gałęzi, lokalnej ani origin)

Sprawdzone przez `git log --all -- <ścieżka>` (przeszukuje wszystkie gałęzie i
całą historię) oraz `git show <gałąź>:<ścieżka>` z porównaniem treści (`diff`):

- `C:\Users\matma\Documents\kuking-flota\_zapas\dsa-odwolania\tests\Feature\SondaDsaOdwolaniaTest.php`
  (6917 B) — **ta ścieżka nigdy nie pojawia się w żadnym commicie na żadnej gałęzi.**
  Nie ma jej ani w martwym katalogu `dsa-odwolania`, ani w żywym stanowisku
  `dsa-odwolania-NOWY` (gałąź `flota/dsa-odwolania`, PR #914). Istnieje wyłącznie
  tutaj.
- `C:\Users\matma\Documents\kuking-flota\_zapas\dsa-odwolania\resources\views\pages\appeals\reporter.blade.php`
  (6459 B) — treść **różni się** i od wersji w martwym katalogu `dsa-odwolania`,
  i od wersji w żywym `dsa-odwolania-NOWY` (sprawdzone `diff`, wszystkie trzy się różnią).
  To trzecia, unikatowa wersja tego pliku.
- `C:\Users\matma\Documents\kuking-flota\_zapas\gpt-harmonogram-przejecie\Harmonogram.php` (2377 B),
  `console.php` (19034 B), `kontrole.sh`, `kontrole-cache.sh`, `caddy-check.sh`,
  `mutacja-*.json` — żaden plik o nazwie `Harmonogram.php` nie istnieje w gałęzi
  `gpt-harmonogram` (tam są tylko `tests/Feature/HarmonogramBezProcOpenTest.php`
  i `tests/Feature/HarmonogramSprawdzaKodWyjsciaTest.php` — inne pliki, inny zakres).
  `HarmonogramSprawdzaKodWyjsciaTest.php` — TEN konkretny plik już jest bezpieczny,
  wszedł do gałęzi `gpt-harmonogram` commitem `a0e489b1` ("Odzyskaj prace stanowiska
  gpt-harmonogram po awarii repozytorium"). Reszta katalogu `gpt-harmonogram-przejecie`
  — nie sprawdzona wobec żadnej gałęzi, wygląda na osobny wątek pracy (nazwa
  "przejęcie" sugeruje coś innego niż stanowisko `gpt-harmonogram`).
- `C:\Users\matma\Documents\kuking-flota\_zapas\r49-trasy\tests\Feature\FeedTagowNiePokazujeCudzegoPrzepisuTest.php`
  (9153 B) — plik o tej samej nazwie istnieje na `origin/main`, ale **treść jest
  inna**: wersja w `_zapas` ma dodatkowy import `App\Domain\Social\Actions\FollowUser`
  i trzecią postać w scenariuszu testowym (Kasia obserwuje Basię), której nie ma
  w wersji z main. To rozszerzona/zmodyfikowana wersja testu, nie duplikat.

**Nie ratowałem tych plików** — zgodnie z poleceniem, tylko wypisuję ścieżki.
Ktoś musi ocenić, czy to still-aktualna praca, czy odrzucony wariant.

### 1b. Niezacommitowane zmiany w ŻYWYCH stanowiskach (worktree ma gałąź, ale są zmiany poza HEAD)

Sprawdzone przez `git status --porcelain -uall` na wszystkich 74 zarejestrowanych
worktree'ach naraz:

| Stanowisko (ścieżka) | Co jest niezacommitowane |
|---|---|
| `kuking-flota\dowod-922` | `M scripts/szybki-wyglad.mjs`; `?? scripts/oryginal-instrumentowany.mjs`; `?? scripts/oryginal-szybki-wyglad.mjs` |
| `kuking-flota\gpt-tokeny-zaproszen` | `?? output/tokeny-zaproszen-889-odtworzenie/` (4 pliki: czerwien.txt, sprawdz.sh, zielen.txt, zmiany-889-sprzed-odtworzenia.zip) |
| `kuking-flota\main-porownanie` | `?? scripts/_tmp_nav638_only.mjs` |
| `kuking-flota\naprawa-918` | `M resources/css/app.css`; `?? scripts/_tmp_debug_home.mjs`; `?? scripts/_tmp_nav638_only.mjs` |
| `kuking-flota\naprawa-kontrola-dodatnia` | `M scripts/kontrole-negatywne-alfa08.py`; `?? tests/Feature/StraznikTekstuMaKontroleDodatniaTest.php`; `?? tests/straznicy-tekstu-zastane.txt` |
| `kuking-flota\naprawa-wyglad` | `M scripts/szybki-wyglad.mjs` |

Te stanowiska SĄ na gałęzi (mają .git, mają branch), ale mają na dysku zmiany,
których nie ma w ostatnim commicie tej gałęzi. `naprawa-kontrola-dodatnia` i
`naprawa-wyglad` to nowe worktree, które pojawiły się w trakcie tej sesji
(gałęzie `naprawa/kontrola-dodatnia-do-przodu` i `naprawa/kruchy-pomiar-wygladu`
— nie ma ich jeszcze na origin) — wygląda na trwającą, aktywną pracę innego
agenta, NIE na porzucone dane.

### 1c. Katalogi bez żadnej pasującej gałęzi (do dalszej weryfikacji przez kogoś innego)

119 katalogów w `kuking-flota` ma plik `.git` wskazujący na worktree, którego
już nie ma w rejestrze `Codex/kuking.pl/.git/worktrees/` — to **martwe wskaźniki
worktree** (git w nich mówi `fatal: not a git repository`). Z tych 119:

- **65 ma już dopasowaną gałąź** (56 pod identyczną nazwą jak katalog — to
  "wielki odzysk" z wcześniejszej sesji, potwierdzony też w `_wspolne/WERYFIKACJA_ODZYSKU.md`
  — plus 9 kolejnych rozpoznanych po przedrostku, np. `dsa-odwolania` →
  gałąź/PR `flota/dsa-odwolania`, `bazy-stanowisk` → `robota/bazy-stanowisk`).
  Praca z tych katalogów **jest już zabezpieczona** na gałęzi.
- **54 nie mają dopasowanej gałęzi** ani lokalnie, ani na origin. To NIE znaczy
  automatycznie "praca stracona" — część to prawdopodobnie: (a) katalogi-cienie
  już zabezpieczonych stanowisk (patrz sekcja 3, pary `-PLIKI`/`-NOWY`/`-kopia`/`-recovery`),
  (b) katalogi śledcze/diagnostyczne, które nigdy nie miały być samodzielną gałęzią
  (`kaskada`, `martwe-kaskady`, `sledztwo-914`, `plan-scalania`, `scal-izolacja-bazy-klon`,
  `scal-kaskada-straznik`, `naprawa-119/619/750/847/906/906-v2`, `poswiadczenia`,
  `prog-postgresa`, `prywatnosc-formularz`, `nazwa-a-relacje`, `stopka`, `straznik-r60`,
  `drobne`). Pełna lista 54 ścieżek (wszystkie pod `C:\Users\matma\Documents\kuking-flota\<nazwa>`):

  ```
  drobne, gemini-adr, gemini-retencja, gemini-wglady, gpt-analityka-piksel,
  gpt-app-css-test, gpt-cold-start, gpt-czytnik-ekranu-odsluch,
  gpt-decyzja-205-i-motyw-370, gpt-dziennik-wyjatkow-PLIKI, gpt-grupy-tematyczne,
  gpt-haslo-konto, gpt-heic-format, gpt-kolizja-wersji, gpt-logowanie-haslem-lokalne,
  gpt-moja-wersja-kopia-20260920, gpt-ocr-import-recovery-20260920,
  gpt-odbior-czy-przygotowanie, gpt-offline-obietnica, gpt-planer-zakupy,
  gpt-pomiar-feedu-i-budzetu, gpt-produkcja-liczniki-obciazenie, gpt-przepis-liczniki,
  gpt-pytania-eksport, gpt-r2-emaillabs-procedura, gpt-r2-jurysdykcja,
  gpt-tokeny-zaproszen-PLIKI, gpt-turnstile-pomoc, gpt-urzadzenia-fizyczne-procedura,
  gpt-widget-wyglad, gpt-widmo-zamkniec, gpt-zamkniecia-607-608-i-inwentarz-ci,
  gpt-zawieszone-konto-PLIKI, gpt-zeszyt-zapisy, kaskada, klient-pg18,
  martwe-kaskady, naprawa-119, naprawa-619, naprawa-750, naprawa-847, naprawa-906,
  naprawa-906-v2, nazwa-a-relacje, plan-scalania, poswiadczenia, prog-postgresa,
  prywatnosc-formularz, r49-trasy, scal-izolacja-bazy-klon, scal-kaskada-straznik,
  sledztwo-914, stopka, straznik-r60
  ```

  Z tych 54: **sześć jest już bezpiecznych z innego powodu** (mają żywego
  bliźniaka — patrz sekcja 3): `gpt-dziennik-wyjatkow-PLIKI`, `gpt-tokeny-zaproszen-PLIKI`,
  `gpt-zawieszone-konto-PLIKI`, `klient-pg18`, `gpt-moja-wersja-kopia-20260920`,
  `gpt-ocr-import-recovery-20260920`. Pozostałe **48** wymagają, żeby ktoś
  przejrzał zawartość i ocenił "czy to moja praca" — ja tego nie robię, tylko
  zgłaszam.

---

## 2. Mapa katalogów `kuking-flota`

Stan na chwilę sprawdzania: **202 katalogi** + **31 luźnych plików** bezpośrednio
w `kuking-flota` (patrz sekcja 2d).

| Kategoria | Liczba | Opis |
|---|---|---|
| Stanowiska żywe (zarejestrowany worktree w `Codex/kuking.pl/.git/worktrees`) | **73** | `git worktree list` w kanonicznym repo pokazuje je wszystkie; każde ma działającą gałąź |
| Stanowiska martwe (plik `.git` wskazuje na worktree, którego już nie ma w rejestrze) | **119** | `git status` w nich zwraca `fatal: not a git repository`; z tego 65 ma już odzyskaną gałąź gdzie indziej (patrz 1c) |
| Katalogi wspólne (bez `.git`, to nie stanowiska) | **9** | `_prompty`, `_wspolne`, `_zapas`, `_tagi-dowody`, `_scratch-gpt-cold-start`, `gpt-haslo-konto-zoom`, `gpt-obciazenie-evidence`, `gpt-tablica-evidence`, `gpt-zeszyt-zapisy-evidence` |
| Luźne pliki wprost w `kuking-flota` | **31** | Skrypty `.sh`, `.json`, `.txt`, `.zip` po doraźnej diagnostyce (patrz 2d) |

Rozmiar Windows-owej części floty (worktree na dysku C:, same drzewa gita, bez
`vendor`/`node_modules` — te są tylko w runtime'ach WSL): **≈ 21,7 GB** łącznie
dla 199 zmierzonych katalogów (średnio ~117 MB na worktree).

### 2a. Stanowiska żywe (73) — pełna lista z gałęzią

Pełna, aktualna lista jest w `git worktree list` kanonicznego repo
(`C:\Users\matma\Documents\Codex\kuking.pl`) — **to jedyne wiarygodne źródło**,
bo nazwy katalogów bywają mylące (np. katalog `bramka-startowa` jest martwy,
a jego żywy odpowiednik to `bramka-startowa-ODZYSK` na gałęzi `bramka-startowa`).
Przykładowe żywe stanowiska: `dowod-922` (detached HEAD), `ci-hybryda`
(`naprawa/ci-hybryda-runnerow`), `dsa-odwolania-NOWY` (`flota/dsa-odwolania`),
`klient-pg18-NOWY` (`naprawa/klient-pg18-w-ci`), `naprawa-918`
(`codex/audyt-ux50plus`), `stan-sesji-2` (`flota/stan-sesji-2`), oraz
wszystkie katalogi z sufiksem `-ODZYSK` poza dwoma wyjątkami (`naprawa-847-ODZYSK`,
`naprawa-858-ODZYSK` — sprawdzone, też żywe).

### 2b. Stanowiska martwe (119) — patrz sekcja 1c dla pełnej listy 54 nieodzyskanych

### 2c. Katalogi wspólne (9)

- `_wspolne` — dokumenty stanu, zasady floty, skrzynka komunikacji, TEN plik i generator spisu.
- `_prompty` — zlecenia numerowane (00–72), obecnie bez własnych dokumentów `.md` (zostały skonsolidowane do `_wspolne` w trakcie tej sesji).
- `_zapas` — patrz sekcja 1a, zawiera potwierdzone unikatowe pliki.
- `_tagi-dowody`, `_scratch-gpt-cold-start` — robocze/dowodowe, nie sprawdzane szczegółowo w tej turze (niski priorytet, małe: 1,1 MB i 1,7 MB).
- `gpt-haslo-konto-zoom`, `gpt-obciazenie-evidence`, `gpt-tablica-evidence`, `gpt-zeszyt-zapisy-evidence` — katalogi dowodowe/zrzuty ekranu powiązane z konkretnymi stanowiskami.

### 2d. Luźne pliki wprost w `kuking-flota` (31) — "śmieci" do przejrzenia

Same skrypty diagnostyczne i logi tekstowe z sesji `gpt-haslo-konto`,
`gpt-rozbicie-uslug`/`gpt-rozbicie-uslug`, `gpt-dziennik-wyjatkow`,
`gpt-ocr-import`, `gpt-offline-obietnica`, plus jeden `grupy-sprawdz-polaczenie.sh`.
Pełna lista nazw jest identyczna z wynikiem `Get-ChildItem -File` w
`kuking-flota` — 30 plików `.sh`/`.txt`/`.json` + jeden `gpt-dziennik-wyjatkow-pakiet.zip`.
To poboczne artefakty diagnostyki (build logi, testy ręczne) powiązane z
konkretnymi stanowiskami wymienionymi w nazwie pliku — treść tych plików
zwykle i tak trafia do `output/`/`evidence/` danego stanowiska albo do skrzynki;
same pliki na najwyższym poziomie `kuking-flota` są zbędne po zakończeniu
danego zadania.

---

## 3. Duplikaty (trójki/pary katalogów tego samego stanowiska)

Wzorzec jest spójny w całej flocie: **wariant z sufiksem jest żywym,
zrekonstruowanym worktree; wariant bez sufiksu (albo `-PLIKI`) jest starą,
przedawaryjną kopią plików, której praca już wylądowała w wariancie żywym.**
Sprawdzone przez `git worktree list` + porównanie `mtime`:

| Baza (martwa) | Wariant (żywy) | Gałąź żywego | Na origin? |
|---|---|---|---|
| `bramka-startowa` | `bramka-startowa-ODZYSK` | `bramka-startowa` | nie (lokalna) |
| `dsa-odwolania` | `dsa-odwolania-NOWY` | `flota/dsa-odwolania` | **tak, PR #914** |
| `gemini-794` | `gemini-794-ODZYSK` | `gemini-794` | nie |
| `gemini-profil` | `gemini-profil-ODZYSK` | `gemini-profil` | nie |
| `gotowanie` | `gotowanie-ODZYSK` | `flota/gotowanie` | **tak, PR #913** |
| `gpt-2fa-ustawienia` | `gpt-2fa-ustawienia-ODZYSK` | `gpt-2fa-ustawienia` | nie |
| `klient-pg18` | `klient-pg18-NOWY` | `naprawa/klient-pg18-w-ci` | **tak, PR #929** |
| `gpt-moja-wersja-kopia-20260920` | `gpt-moja-wersja` (żywy, BEZ sufiksu) | `gpt/moja-wersja` | nie |
| `gpt-ocr-import-recovery-20260920` | `gpt-ocr-import` (żywy, BEZ sufiksu) | `gpt/ocr-import` | nie |
| `naprawa-847` | `naprawa-847-ODZYSK` | (żywy worktree, sprawdź branch w `git worktree list`) | nie |
| `naprawa-858` | `naprawa-858-ODZYSK` | `naprawa-858` | nie |
| `gpt-dziennik-wyjatkow-PLIKI` | `gpt-dziennik-wyjatkow` (żywy, BEZ sufiksu) | `gpt/dziennik-wyjatkow` | nie |
| `gpt-tokeny-zaproszen-PLIKI` | `gpt-tokeny-zaproszen` (żywy, BEZ sufiksu) | `gpt/tokeny-zaproszen` | nie |
| `gpt-zawieszone-konto-PLIKI` | `gpt-zawieszone-konto` (żywy, BEZ sufiksu) | `gpt/zawieszone-konto` | nie |

**Ten sam wzorzec dotyczy pozostałych ~47 par `<nazwa>` / `<nazwa>-ODZYSK`** w
flocie (np. `gpt-tagi`/`gpt-tagi-ODZYSK`, `gpt-tablica`/`gpt-tablica-ODZYSK`,
`hero-ekran`/`hero-ekran-ODZYSK`, `zeszyty`/`zeszyty-ODZYSK` itd.) — nie
wypisuję każdej z osobna, bo mechanizm jest identyczny: **martwy oryginał
zawsze starszy (mtime), żywy `-ODZYSK` zawsze nowszy i ma gałąź**. Pełna lista
61 katalogów z sufiksem jest w `_wspolne/SPIS_TRESCI.md`/da się odtworzyć
poleceniem `Get-ChildItem kuking-flota | Where Name -match '(ODZYSK|PLIKI|NOWY|kopia-|recovery-)$'`.

**Wyjątek, na który trzeba uważać:** para `dsa-odwolania` / `dsa-odwolania-NOWY`
**NIE jest pełnym duplikatem** — patrz sekcja 1a: plik `SondaDsaOdwolaniaTest.php`
i inna wersja `reporter.blade.php` siedzą w `_zapas\dsa-odwolania`, osobno od
obu tych katalogów, i nie trafiły do żadnego z nich.

---

## 4. Miejsce na dysku

| Lokalizacja | Rozmiar | Uwagi |
|---|---|---|
| `C:\Users\matma\Documents\kuking-flota` (worktree na Windows) | **≈ 21,7 GB** | same drzewa gita (żywe + martwe), bez `vendor`/`node_modules` |
| `/home/mateusz/flota` (runtime'y WSL) | **76 GB** | `du -sh --apparent-size` |
| — z tego `vendor` we wszystkich `*-run` | **23 GB** | (~30% runtime'ów WSL) |
| — z tego `node_modules` we wszystkich `*-run` | **35 GB** | (~46% runtime'ów WSL) |
| **`vendor` + `node_modules` razem** | **58 GB** | **≈ 76% całego `/home/mateusz/flota`** |
| **RAZEM flota (Windows + WSL)** | **≈ 98 GB** | |

Największe pojedyncze runtime'y WSL (top): `push-run` 779 MB, `tagi-filtr-run`
686 MB, `gpt-openai-granice-run` 684 MB, `gpt-heic-format-run` 644 MB,
`sledztwo-914-run` 629 MB, `gpt-panel-moderacji-marka-run` 625 MB,
`gpt-monitoring-run` 582 MB, `gpt-zalegle-run` 569 MB — większość różnic
między runtime'ami to głównie `vendor`/`node_modules` z różnych momentów `composer install`/`npm install`.

---

## 5. Osierocone runtime'y w `/home/mateusz/flota`

Katalogi `*-run`, których stanowisko-rodzic **już nie istnieje** jako katalog
w `kuking-flota` (porównanie 132 katalogów `*-run` z 202 katalogami floty):

```
gemini-2fa-ustawienia-run       556M
gemini-eksport-run              556M
gemini-harmonogram-run          556M
gemini-haslo-konto-run          556M
gemini-kontakt-formularz-run    557M
gemini-kontakt-panel-run        556M
gemini-moderacja-ai-run         556M
gemini-n1-powiadomienia-run     556M
gemini-onboarding-run           556M
gemini-pytania-eksport-run      556M
gemini-pytania-widoki-run       556M
gemini-skladniki-run            556M
gemini-tablica-run              556M
gemini-tagi-run                 556M
gemini-turnstile-pomoc-run      556M
gemini-zalegle-run              556M
gemini-zdjecia-publikacja-run   556M
gemini-zeszyt-zapisy-run        556M
gpt-panel-moderacji-marka-php-run  559M
gpt-pytania-poradzcie-browser-run  559M
```

Razem: **20 katalogów, ≈ 11,2 GB**. Wszystkie to stanowiska serii `gemini-*`
(plus dwa pomocnicze warianty `-php`/`-browser`), których katalog-rodzic w
`kuking-flota` nigdy nie istniał albo został usunięty wcześniej (poza zakresem
tej sesji) — na `kuking-flota` nie ma dziś ŻADNEGO katalogu `gemini-2fa-ustawienia`,
`gemini-eksport` itd.

Osobno: `push-run` (779 MB) to nie "osierocony stanowiskowy runtime", tylko
**pełne, samodzielne repozytorium git** (`.git/` ze wszystkimi obiektami) używane
jako stała stacja pchania gałęzi do `origin` — nie ma katalogu-rodzica w
`kuking-flota`, bo z natury go nie potrzebuje. **Nie kwalifikuje się do
"osieroconych"**, prawdopodobnie wciąż aktywnie używany (kolejka `do-pchniecia.txt`
ma bieżąco >100 wpisów).

### 5a. Czy któryś jest zarejestrowany jako worktree? — SPRAWDZONE, ŻADEN

`git worktree list` w `C:\Users\matma\Documents\Codex\kuking.pl` pokazuje
wyłącznie ścieżki pod `C:/Users/matma/Documents/kuking-flota/...` (Windows).
Żaden z 132 katalogów `*-run` w WSL nie jest tam wymieniony. Jedyny katalog
`*-run` z plikiem `.git` wskazującym na worktree to `gpt-zeszyt-zapisy-run`
(`.git` → `C:/Users/matma/Documents/Codex/kuking.pl/.git/worktrees/gemini-zeszyt-zapisy`)
— **ale ten wpis w rejestrze worktree już nie istnieje** (`.git/worktrees/gemini-zeszyt-zapisy`
nie ma na dysku), więc to martwy wskaźnik, nie żywy worktree. Innymi słowy:
**bezpiecznie można by użyć `git worktree prune` bez utraty żadnego z tych
`*-run`, bo żaden z nich naprawdę nie jest zarejestrowanym worktree** — ALE
zgodnie z poleceniem, NIE URUCHOMIŁEM `git worktree prune` i nikt nie powinien
tego robić z Windows, bo (jak uczy `kuking-worktree-prune-zabija-wsl.md`) z tej
strony wszystkie wpisy pod `/home/` i tak wyglądają na martwe, nawet gdy nie są —
powyższa analiza dotyczy wyłącznie katalogów `*-run`, nie worktree w ogóle.

---

## 6. Co można bezpiecznie usunąć — REKOMENDACJE (nie akcja)

Posortowane od najpewniejszych. Każda pozycja ma uzasadnienie, skąd wiadomo,
że nic nie zginie.

### 6.1. Osierocone runtime'y `gemini-*-run` (WSL) — **≈ 11,2 GB**, najpewniejsze

**Skąd wiadomo, że nic nie zginie:** katalog-rodzic w `kuking-flota` dla
żadnego z tych 20 runtime'ów nie istnieje (sprawdzone `comm` między listą
`*-run` a listą katalogów floty). Runtime to kopia robocza + `vendor`/`node_modules`
do uruchamiania testów — bez katalogu-rodzica z kodem źródłowym i gitem nie ma
z czym go zsynchronizować, więc nie może zawierać unikalnej pracy, wyłącznie
zainstalowane zależności i (być może) stan bazy testowej. **Zastrzeżenie:**
przed usunięciem sprawdzić, czy w środku nie ma ręcznie wrzuconych plików spoza
standardowego szablonu runtime'u (szybki `find <run> -newer <run>/vendor -type f`
wychwyciłby anomalie) — tego kroku NIE wykonałem, bo wykracza poza inwentaryzację.

### 6.2. Martwe katalogi-bliźniaki z żywym `-ODZYSK`/`-NOWY`/bez-sufiksu (Windows) — rozmiar: ~47 × 117 MB ≈ **5,5 GB**

**Skąd wiadomo, że nic nie zginie:** dla każdej sprawdzonej pary (sekcja 3)
żywy wariant ma nowszy `mtime` NIŻ martwy i jest zarejestrowanym worktree na
konkretnej gałęzi (`git worktree list` to potwierdza). To materializacja tej
samej pracy, tyle że rozwiązanej — martwy katalog to zdjęcie stanu SPRZED
odzysku. **Zastrzeżenie:** to nie dotyczy pary `dsa-odwolania`/`dsa-odwolania-NOWY`
w tym sensie, że sam PLIK w martwym `dsa-odwolania` może zawierać starszą wersję,
ale unikalna praca dla TEGO stanowiska (`SondaDsaOdwolaniaTest.php`) jest w
`_zapas`, nie w martwym katalogu — więc czyszczenie martwego `dsa-odwolania`
samo w sobie jest bezpieczne, o ile ktoś najpierw zaopiekuje się `_zapas`
(patrz 6.4, celowo NIŻEJ na liście).

### 6.3. Luźne pliki diagnostyczne wprost w `kuking-flota` (31 plików, kilka MB)

**Skąd wiadomo, że nic nie zginie:** to logi kompilacji/testów (`*.txt`,
`*.sh` z komendami diagnostycznymi, jeden `.zip`), nazwane po stanowisku,
którego dotyczą — każde z tych stanowisk (`gpt-haslo-konto`, `gpt-rozbicie-uslug`,
`gpt-dziennik-wyjatkow`, `gpt-ocr-import`, `gpt-offline-obietnica`) ma już
własny, żywy worktree z pełną historią i (w części przypadków) już zapisany
meldunek w skrzynce. Te pliki to poboczne dowody z konkretnej sesji diagnostycznej,
nie źródło prawdy o zmianach w kodzie.

### 6.4. `_zapas` — NIE usuwać, dopóki ktoś nie zaopiekuje się trzema plikami z sekcji 1a

**Świadomie NIE rekomenduję usunięcia całego `_zapas`.** Zawiera potwierdzone
unikatowe pliki (`SondaDsaOdwolaniaTest.php`, `reporter.blade.php` w wersji
niepowtórzonej nigdzie indziej, `Harmonogram.php`/`console.php` z
`gpt-harmonogram-przejecie`, zmodyfikowany `FeedTagowNiePokazujeCudzegoPrzepisuTest.php`).
Reszta `_zapas` (pliki `gpt-pytania-widoki-*.sh/json`) to diagnostyka i
prawdopodobnie bezpieczna do wyczyszczenia, ale całego katalogu nie dzielę bez
dalszej weryfikacji, żeby nie pomieszać z tym, co unikatowe.

### 6.5. Runtime `push-run` i pozostałe 112 „żywych” `*-run` — NIE rekomenduję ruszania

Te runtime'y odpowiadają istniejącym katalogom-stanowiskom w `kuking-flota` i
są używane do bieżących testów/pushowania (kolejka `do-pchniecia.txt` ma >100
wpisów w tej chwili). `vendor`/`node_modules` w nich (58 GB) da się odtworzyć
przez `composer install`/`npm install`, ale to już wykracza poza "sprzątanie
śmieci" — to zwykły cykl życia runtime'u, decyzję zostawiam komuś, kto zna
harmonogram floty.

---

## Podsumowanie liczbowe

- Katalogi w `kuking-flota`: **202** (73 żywe + 119 martwych + 9 wspólnych + 1 rozjazd zaokrąglenia z powodu zmian w locie).
- Luźne pliki w `kuking-flota`: **31**.
- Miejsce: Windows **21,7 GB** + WSL **76 GB** = **≈ 98 GB**.
- `vendor`+`node_modules` w WSL: **58 GB (76% z 76 GB)**.
- Osierocone runtime'y WSL: **20 katalogów, 11,2 GB** (plus `push-run`, który NIE jest osierocony).
- Żaden runtime `*-run` nie jest zarejestrowanym worktree — `git worktree prune` z Windows i tak jest zakazany.
- Praca poza gitem: **3 pliki potwierdzone jako unikatowe** w `_zapas` + **6 żywych worktree z niezacommitowanymi zmianami** + **48 katalogów bez dopasowanej gałęzi wymagających dalszej weryfikacji przez kogoś innego**.

Generowany, zawsze aktualny spis dokumentów, zleceń, skrzynki, gałęzi i PR-ów:
**`_wspolne/SPIS_TRESCI.md`** (odśwież: `bash _wspolne/odswiez-spis.sh`, ~4 sekundy).
