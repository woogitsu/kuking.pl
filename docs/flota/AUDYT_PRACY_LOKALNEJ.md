# Audyt pracy lokalnej — 21.09.2026, popołudnie

Spisowy audyt wszystkiego, co leży lokalnie i mogłoby zniknąć razem z maszyną.
**Niczego nie skasowano, nie scommitowano, nie wypchnięto, nie ruszono stosu stashy.**
Wszystkie polecenia git w repozytorium kanonicznym (`Codex\kuking.pl`) były wyłącznie
czytające. Praca wykonawcza szła w `kuking-flota\` (259 katalogów) i przez odczyt
`/home/mateusz/flota/do-pchniecia.txt` + `dogrywka.txt` (WSL).

**Metoda i jedna ważna pułapka, na którą trafiłem:** narzędzia w WSL (`git` uruchamiany
przez `wsl.exe`) failowały na WSZYSTKICH 259 worktree'ach komunikatem
`fatal: not a git repository` — to fałszywy alarm. Plik `.git` w każdym worktree
zawiera ścieżkę windowsową (`gitdir: C:/Users/...`), której WSL-owy git nie potrafi
rozpoznać jako bezwzględnej. Przełączyłem się na naturalny Windows-owy git
(PowerShell) i dopiero wtedy dane były wiarygodne. Po drodze złapałem też błąd we
własnym pierwszym skrypcie: gołe `git log --not --remotes` (bez jawnego revision)
nie liczy nic — trzeba `git log <gałąź> --not --remotes`. Wszystkie liczby w tym
dokumencie pochodzą z drugiego, poprawionego przebiegu.

---

## DO URATOWANIA — 2 pozycje z gotowymi commitami + 6 pozycji z niescommitowaną pracą

### Gotowe commity, których nie ma w żadnej kolejce pchania

1. **Gałąź `flota/retencja-wyjatkow-audytu`** (worktree `kuking-flota\retencja-wyjatkow-audytu`)
   — 1 commit `b04e168c "Daj kategoriom dowodowym audytu termin, ale nie wybieraj go za właściciela"`.
   Sprawdzone: commit nie istnieje na ŻADNEJ gałęzi origin (`git branch -r --contains` pusty),
   nie ma go w `do-pchniecia.txt` ani w `dogrywka.txt`.
   **Ratuje:** dopisać `flota/retencja-wyjatkow-audytu` do `/home/mateusz/flota/do-pchniecia.txt`.

2. **Gałąź `zeszyty`** (worktree `kuking-flota\zeszyty-ODZYSK`)
   — 1 commit `1b62b887 "Odzyskaj prace stanowiska zeszyty po awarii repozytorium"`.
   To sam commit odzysku po awarii 20.09 — odzyskany, ale nigdy nie trafił do kolejki.
   Nie istnieje na origin, nie ma go w żadnym pliku kolejki.
   **Ratuje:** dopisać `zeszyty` do `/home/mateusz/flota/do-pchniecia.txt`.

### Niescommitowana praca (nie pomoże żadna kolejka — trzeba najpierw scommitować)

3. **`kuking-flota\proba-para-b`** (gałąź `proba/para-b`, bez origin) — **najwyższe ryzyko**.
   12 plików w stanie roboczym, w tym **nierozwiązany konflikt scalania**
   (`UU app/Models/Notification.php`) i 4 nowe testy dodane do indeksu, ale nigdy
   nieskomitowane: `AwariaPowiadomieniaNieRozdzielaZapisuDoZeszytuTest.php`,
   `CzasCzlowiekaZamiastUtcTest.php`, `PowiadomienieOObserwowaniuPodazaZaAktoremTest.php`,
   `PowiadomienieOUsunietymWykonaniuTest.php`, `PowiadomienieWlasnyAdresPrzekierowaniaTest.php`,
   `PowiadomienieZobaczOtwieraCelebracje­Test.php`. To wygląda na próbę scalenia
   powiadomień z realną pracą (nowe testy), przerwaną w połowie.
   **Ratuje:** ktoś musi wejść do tego worktree, dokończyć/porzucić rozwiązanie
   konfliktu i zrobić **commit tymczasowy** (`git add -A && git commit -m "WIP: ..."`,
   zgodnie z zasadami floty — nigdy `git stash`), zanim ktokolwiek tknie ten katalog.

4. **`kuking-flota\gpt-dziennik-wyjatkow-PLIKI`** (gałąź `gpt/dziennik-wyjatkow`, sama
   gałąź w pełni wypchnięta) — 5 plików roboczych: zmieniony
   `docs/security/DZIENNIK_WYJATKOW_828_925.md` i **skasowane** (niescommitowane)
   cztery pliki dowodowe w `docs/security/dowody-828-925/po-odtworzeniu/`.
   **Ratuje:** sprawdzić czy skasowanie plików dowodowych było zamierzone; jeśli tak —
   scommitować, jeśli nie — `git checkout -- <pliki>` żeby przywrócić.

5. **`kuking-flota\gpt-tokeny-zaproszen-PLIKI`** — 5 plików roboczych, w tym
   **`zmiany-889-bez-commita.zip`** — nazwa pliku dosłownie mówi, że to zmiany do
   #889 nigdy niescommitowane, spakowane na wszelki wypadek. Plus zmienione
   `TERMIN_RESETU_I_ZAPROSZENIA_889.md`, `KtoNieDostalListuTest.php`,
   `MartweZadaniaTest.php`. Gałąź `gpt/tokeny-zaproszen` sama jest już wypchnięta.
   **Ratuje:** rozpakować i sprawdzić zawartość zipa, scommitować to, co wartościowe.

6. **`kuking-flota\gpt-zawieszone-konto-PLIKI`** — skasowany (niescommitowany)
   `docs/product/ZAWIESZENIE_926_ODTWORZENIE.md`. Gałąź `gpt/zawieszone-konto` sama
   w pełni wypchnięta. **Ratuje:** potwierdzić czy skasowanie zamierzone, ew. scommitować.

7. **`kuking-flota\gpt-moja-wersja-kopia-20260920`** — zmieniony niescommitowany
   `docs/product/MOJA_WERSJA_PROJEKT_23.md` (gałąź `gpt/moja-wersja` wypchnięta).
   **Ratuje:** przejrzeć diff, scommitować jeśli wartościowy.

8. **`kuking-flota\gpt-ocr-import-recovery-20260920`** — zmieniony niescommitowany
   `docs/product/PROJEKT_OCR_IMPORT_28.md` (gałąź `gpt/ocr-import` wypchnięta).
   **Ratuje:** jak wyżej.

**Informacyjnie, nie do ratowania od razu, ale warto wiedzieć:**
- **`kuking-flota\dowod-922`** — odłączony HEAD (`detached`), 3 pliki robocze
  (`scripts/szybki-wyglad.mjs` zmieniony + 2 nowe skrypty instrumentacyjne).
  To najpewniej materiał śledczy do #922, regenerowalny. Commit `d2cce67a` jest
  osiągalny tylko przez ten worktree (nie jest na żadnej gałęzi/origin) —
  **chroni go wyłącznie żywa rejestracja worktree**; gdyby ktoś kiedyś odpalił
  `git worktree prune`, ten konkretny commit i te pliki robocze straciłyby ochronę.
- To samo dotyczy trzech innych worktree z odłączonym HEAD: `diagnoza-uuid`,
  `sledztwo-725`, `proba-scalenia` — to próby scalenia/śledcze (merge-testy),
  ich commity nie są na żadnej gałęzi ani origin, żyją tylko dzięki temu, że
  worktree są zarejestrowane. Zero plików roboczych brudnych w tych trzech.

---

## BEZPIECZNE — zbiorczo

### 1. Worktree pod `kuking-flota\` (obszar 1)

- **259 katalogów** na dysku. **133 mają żywą rejestrację** w
  `Codex\kuking.pl\.git\worktrees\`, **107 to sieroty** (katalog i pliki są, ale
  wpis rejestracji zniknął — objaw pasuje do `git worktree prune`, przed którym
  ostrzega `ZASADY_FLOTY.md` i pamięć floty), **9 to zwykłe foldery bez gita**
  (`_prompty`, `_scratch-gpt-cold-start`, `_tagi-dowody`, `_wspolne`, `_zapas`,
  `gpt-haslo-konto-zoom`, `gpt-obciazenie-evidence`, `gpt-tablica-evidence`,
  `gpt-zeszyt-zapisy-evidence` — poza zakresem gita, nic do ratowania na poziomie commitów).
- **Wszystkie 107 sierot zweryfikowane jako bezpieczne**: każda ma siostrzany
  katalog `*-ODZYSK`/`*-ODZYSK2` z commitem „Odzyskaj prace stanowiska X po awarii
  repozytorium", i **żaden plik w żadnej z sierot nie był modyfikowany po
  21.09 rano** (sprawdzone porównaniem najnowszego `LastWriteTime` w każdej
  sierocie — wszystkie kończą się 20.09, przed odzyskiem). To pre-awaryjne kopie,
  w pełni zastąpione przez odzysk.
- **5 worktree okazało się należeć do INNEGO repozytorium** (`Codex\.git`, nie
  kanonicznego `Codex\kuking.pl\.git`): `gpt-widmo-zamkniec`,
  `gpt-decyzja-205-i-motyw-370`, `gpt-odbior-czy-przygotowanie`,
  `gpt-pomiar-feedu-i-budzetu`, `gpt-zamkniecia-607-608-i-inwentarz-ci`.
  Zdalne referencje w `Codex\.git` są **nieświeże o 12 commitów** względem
  kanonicznego `origin/main` — dane o origin z tego repo same w sobie byłyby
  zwodnicze. Zweryfikowałem wszystkie pięć wobec świeższych referencji z
  `Codex\kuking.pl`: 4 są w pełni bezpieczne (0 commitów spoza remote), jedna
  (`gpt/widmo-zamkniec`, 1 commit) jest już w `do-pchniecia.txt`.
- Wśród żywych worktree z commitami ponad `origin/<gałąź>` (np.
  `gpt-n1-powiadomienia-ODZYSK` +14, `scal-786-izolacja` +7,
  `naprawa-baza-proby` +5, `naprawa-717-panel-marki` +1, `stan-sesji-2` +1,
  `martwe-kaskady-ODZYSK2` +1) — **wszystkie sprawdzone i obecne w
  `do-pchniecia.txt` i/lub `dogrywka.txt`**. Dojdą same.
- **`flota/kontrakt-nazw-baz`** (4 commity nie na origin) — świadomie NIE trafiła
  do żadnej kolejki. To udokumentowany, zamierzony stan z
  `_wspolne\KOLEJNOSC_SCALANIA.md` / `SPIS.md`: `#966` (ta gałąź) ma się
  **przepisać** na schemat `#920`, a jej obecne commity zostają porzucone na
  rzecz przepisanej wersji na `flota/scal-786` (sprawdziłem: drzewa różnią się
  istotnie, `kontrakt-nazw-baz` NIE jest przodkiem `scal-786` — to naprawdę
  inna, starsza wersja, nie duplikat). To decyzja właściciela, nie przeoczenie.

### 2. Repozytorium kanoniczne — 14 niescommitowanych plików

**W pełni bezpieczne, nadmiarowe.** Zweryfikowane treścią, nie nazwami: 9 zmienionych
plików (`field.blade.php`, `layout.blade.php`, `recipe-wizard.blade.php`, `app.js`,
`tokens.css`, `app.css`, `error-summary.blade.php`, `photo.blade.php`,
`WierszFormularza.php`) i 5 nowych plików testowych dają **`git diff origin/main`
= 0 linii** dla każdego z nich. Lokalny `HEAD` (`f56f97f0`) jest dokładnie
1 commit za `origin/main` (`65327e69`, PR #923 „Zdjęcia w formularzach", który
wchłonął #745) — to properyzowane, zaindeksowane wejście tego samego PR-a, które
po prostu nie przesunęło jeszcze lokalnego HEAD-a. Nic do ratowania.

### 3. Gałęzie lokalne bez odpowiednika na origin

36 gałęzi w `git branch --list` bez pary w `git branch -r`. Policzone poprawnie
(`git log <gałąź> --not --remotes`, nie goły `--not --remotes`):
- **22 mają 0 commitów spoza remote** (treść już gdzieś istnieje pod inną nazwą):
  `gpt/decyzja-205-i-motyw-370`, `gpt/zamkniecia-607-608-i-inwentarz-ci`,
  `naprawa/testy-js-wchodza-do-ci`, `proba/para-a`, `proba/para-b` (jako gałąź —
  brudne pliki opisane wyżej osobno), `robota/bazy-stanowisk-odzysk`.
- **11 gałęzi `odzysk/*`** (po 1 commicie każda, dokumenty/dowody odzyskane po
  awarii) — wszystkie w `do-pchniecia.txt`.
- **Reszta z commitami** (`codex/audyt-ux50plus`, `flota/ci-runnery-przegladarkowe`,
  `flota/gotowanie`, `flota/kaskada223`, `flota/naprawa-kontrola-ujemna-json`,
  `flota/pomiar-odciecia`, `flota/prawo-zestawienie`, `flota/rozpoznanie-eksport`,
  `flota/scal-zeszyt-775`, `flota/sonda-cdn-purge`, `flota/wersja-068`,
  `flota/zdjecia-formularze`, `naprawa/minimalne-potwierdzenie-rodo`,
  `naprawa/podbicie-wersji-wymaga-wpisu`, `naprawa/skladnik-bez-ilosci-jeden-kontrakt`)
  — **wszystkie potwierdzone w `do-pchniecia.txt` i/lub `dogrywka.txt`**.
- Wyjątki opisane w sekcji DO URATOWANIA: `flota/retencja-wyjatkow-audytu`, `zeszyty`.
  Wyjątek udokumentowany jako świadomy: `flota/kontrakt-nazw-baz`.

### 4. Stos `git stash`

**Pusty.** Sprawdzone `git stash list` w `Codex\kuking.pl` (repo kanoniczne) —
zero wpisów. Sprawdzone też w `Codex` (repo, do którego należy 5 wspomnianych
worktree) i z poziomu worktree `ci-hybryda` (żeby potwierdzić że stos jest
per-repo, nie per-worktree) — też zero. Nic do opisania, nic do ostrzeżenia.

---

## MELDUNEK

**2 pozycje DO URATOWANIA to gotowe, niescommitowane-nigdzie commity** (gałęzie
`flota/retencja-wyjatkow-audytu` i `zeszyty` — po jednym commicie, żaden w żadnej
kolejce pchania). **Do tego 6 worktree z realną niescommitowaną pracą roboczą**
(najpoważniejsze: `proba-para-b` z nierozwiązanym konfliktem scalania i czterema
nieskomitowanymi testami; `gpt-tokeny-zaproszen-PLIKI` z plikiem
`zmiany-889-bez-commita.zip` nazwanym tak, żeby nikt nie zapomniał).

Komendy ratujące (2 dopiski do kolejki):
```
echo flota/retencja-wyjatkow-audytu >> /home/mateusz/flota/do-pchniecia.txt
echo zeszyty >> /home/mateusz/flota/do-pchniecia.txt
```
Pozostałe 6 pozycji wymaga ręcznego wejścia do worktree i commitu (nie pomoże
sama kolejka pchania, bo nie ma czego pchać — nic nie jest scommitowane).

Cztery obszary zbiorczo: **worktree floty** — 259 katalogów, 133 żywe (w tym 5
z innego repozytorium niż kanoniczne), 107 sierot po `git worktree prune` (wszystkie
zweryfikowane jako bezpieczne, zastąpione przez `*-ODZYSK`), 9 zwykłych folderów
bez gita, garść gałęzi z commitami ponad origin (wszystkie w kolejce oprócz dwóch
wypisanych wyżej) i jeden świadomie porzucany przypadek (`flota/kontrakt-nazw-baz`,
udokumentowany w `KOLEJNOSC_SCALANIA.md`). **Repozytorium kanoniczne** — 14
niescommitowanych plików w 100% nadmiarowe (już w `origin/main` przez #923/#745).
**Gałęzie lokalne bez origin** — 36, wszystkie rozliczone, te same dwie luki co
w obszarze 1. **Stos stash** — pusty, nic do zgłoszenia.

**Czy coś na tej maszynie jest jedyną kopią czegokolwiek:** tak — **dwa commity
(`flota/retencja-wyjatkow-audytu`, `zeszyty`) i niescommitowana praca w sześciu
worktree wypisanych w sekcji DO URATOWANIA** istnieją wyłącznie lokalnie i nigdzie
indziej; wszystko pozostałe znalezione podczas audytu jest już wypchnięte, w
kolejce pchania, albo potwierdzone jako identyczne z czymś, co już jest na origin.
