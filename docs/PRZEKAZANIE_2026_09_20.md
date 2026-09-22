# Przekazanie — KuKing.pl, 20 września 2026

Dokument dla następnego modelu prowadzącego. Wszystko tu jest albo **sprawdzone
w tej sesji**, albo oznaczone **[pomiar cudzy: źródło]**. To rozróżnienie jest
celowe: raport, który podaje przejęte twierdzenie tak samo jak własny pomiar,
zmusza czytelnika do sprawdzania wszystkiego albo niczego.

**Wszystkie sesje i agenci są zatrzymani.** Kolejki pchające w WSL pracują dalej
— to procesy powłoki, nie modele, więc nic nie kosztują.

---

## 0. Zasady właściciela — obowiązują bez wyjątku

- Nie używać `git reset --hard`.
- Nie używać `--no-verify`, nie omijać hooków ani required CI.
- `migrate:refresh` / `migrate:fresh` **wyłącznie** przeciwko własnej bazie
  testowej przeznaczonej do usunięcia.
- PostgreSQL lokalnie **wyłącznie `127.0.0.1:55439`**; nigdy współdzielony 5432.
- Nie kopiować metadanych `.git` z runtime'ów do repozytorium kanonicznego.
- Nie wysyłać wiadomości do użytkowników ani instytucji.
- **Nigdy nie wypisywać tokenu** w logu, raporcie, PR-ze ani odpowiedzi; nie
  zapisywać go w repozytorium. Token leży w `C:\Temp\kuking-gh-token.tmp`.
- Zachować prywatność, dane, istniejące funkcje i konstytucję marki.
- Decyzje dla właściciela podawać **w formie klikalnej**.
- `vendor` i `node_modules` **kopiować, nie dowiązywać symlinkiem** — symlink
  wywraca `JednoDekodowanieZdjeciaTest` po ośmiu minutach hooka.
- Worktree zakładać pod ścieżką **Windows**, nigdy pod `/home/` — patrz §5.
- **Nie uruchamiać `git worktree prune`** — patrz §5.

Zasady projektu: `AGENTS.md` jest jedynym źródłem prawdy. `CLAUDE.md` to tylko
wskaźnik.

---

## 1. Gdzie jesteśmy

`origin/main` = **`ecf0d029`**

Scalone w tej sesji (wszystkie `clean`, zero czerwieni):

| PR | Gałąź | Co weszło |
|---|---|---|
| #785 | `fix/737-tagi-w-tresci` | Hashtag w treści wpisu jest odnośnikiem do strony tagu — zgłoszenie właściciela („klikam na tag ale nie działa") |
| #787 | `docs/audyt-decisions-20260920` | Audyt rejestru decyzji: 5 rozjazdów z kodem, 16 cicho odwróconych, 343 wstawienia / 0 usunięć |
| #788 | `bledy-ekrany` | Nagłówki bezpieczeństwa na 5 z 7 ekranów błędu + polskie ekrany 405/400/410 |

Wcześniej tego dnia: #730, #731, #781, #782, #783, #784.

---

## 2. PR-y otwarte — co z nimi zrobić

| PR | Gałąź | Stan | Decyzja |
|---|---|---|---|
| **#790** | `audyt/limity-czestosci` | czerwone `Panel marki — puste i pełne widoki` | **Nie scalać bez zrozumienia czerwieni.** Jedyny bez hipotezy, a dotyczy limitów częstości przy hasłach. |
| **#789** | `feat/l1-wyjmij-wpis-z-zeszytu` | czerwone `Port marki` | Prawdopodobnie flak z §4. Scalić po zzielenieniu albo po wejściu `76f385f9`. |
| **#786** | `praca/izolacja-bazy-klon` | `dirty` (konflikt) | Konflikt zniknie po wypchnięciu przebazowanego **`271d2665`** (force-with-lease). Na `origin` stoi stare `f1ca97b8`. |
| **#725** | `codex/717-panel-kolejki` | czerwone `Panel marki` **i** `Port marki` | `Panel marki` ma **prawdziwą** przyczynę: `scripts/panel-marki.mjs:262` wymagał dokładnie 9 pozycji menu, a #725 dodaje dziesiątą. Poprawka **`fad7bc83`** czeka (force-with-lease). |

**Kryterium czerwieni obowiązujące w tym projekcie: czerwień liczy się dopiero
po ponowieniu na identycznym SHA.**

### Co ustalono o tych czerwieniach **[pomiar cudzy: sesja #1]**

1. Wszystkie sześć czerwieni trwało **762–1181 s**, przy paśmie „zielony bez
   pomiaru" 18–22 s. Każde zadanie **wykonało pomiar i oblało na asercji** —
   to **wyklucza M-2** (restart maszyny wirtualnej), bo tam zadania giną po
   20–60 s.
2. **#725 padł na obu zadaniach także w próbie drugiej, na niezmienionym SHA.**
   Wedle reguły rejestru to **nie jest migotanie**.
3. Runnery były różne (NEW-02, NEW-03, DOM-NEW-01, DOM-NEW-03, DOM-NEW-04) —
   przemawia przeciw wspólnej przyczynie maszynowej.

Odpowiedź na „jeden mechanizm czy trzy" jest więc częściowa: **na pewno nie
jeden mechanizm maszynowy.** Czy `Port marki` na #789 i #725 to ten sam flak —
**nierozstrzygnięte**.

**Następny krok, gotowy:** pobrać dzienniki i porównać sygnatury. Job id
w `C:\Users\matma\Documents\Codex\HANDOVER-migotanie-ci.md`, §4, z komendą.
**Zacząć od `106053516444` (#790).** Czytać pierwszy `##[error]` i to, co
**nad** nim — nigdy samą adnotację. Ponowienia były w toku: przebiegi
`35501115635`, `35501116354`, `35470625027`.

---

## 3. Gałęzie do wypchnięcia

Repozytorium kanoniczne: `C:\Users\matma\Documents\Codex\kuking.pl`.
Zdalne na GitHubie: `https://github.com/woogitsu/kuking.pl.git`.

| Gałąź | SHA lokalny | Na `origin` | Siła | Co to jest |
|---|---|---|---|---|
| `praca/izolacja-bazy-klon` | `271d2665` | `f1ca97b8` | **force** | Rozłączne bazy testowe + bezpiecznik na 17 nieosłoniętych skryptów (PR #786) |
| `fix/migotanie-ci` | `9f150c97` | brak | — | Rejestr migotania + `PULAPKI_TESTOW.md` §10, 13 commitów |
| `narzedzia/kontrola-ujemna-v2` | `61622e9e` | `ad7485d2` | **force** | Naprawa SIGPIPE, §5c, mapa reguł, bity `+x` |
| `audyt/8-r1-r6-decyzje` | `6f753517` | `f18ad807` | **force** | Strażnik statusów w dokumentach decyzyjnych |
| `codex/717-panel-kolejki` | `fad7bc83` | `9a43a8e6` | **force** | Poprawka zbiorów menu zamiast liczby (PR #725) |
| `fix/732-wspolny-licznik-poczty` | `800d609d` | brak | — | Wspólny licznik poczty, D-225/D-226 |
| `fix/m4-przeplyw-pomiar` | `76f385f9` | brak | — | **Naprawa flaka `Port marki`** — patrz §4 |
| `fix/zapisane-w-feedzie` | `a544d4d0` | brak | — | Karta „Wspomnienie" dolicza stan zeszytu |
| `fix/straznik-18px` | `d8f45d35` | brak | — | Strażnik 18 px + trzy cele dotknięcia do 48 px |
| `docs/kolaz-komentarz-pomiar` | `1802c391` | brak | — | Komentarz o kolażu zgodny ze stanem rzeczywistym |
| `robota/retencja-adresow-bez-konta` | `5e2f62a0` | brak | — | Retencja adresów + zdanie w liście (decyzja właściciela) |
| `robota/kaskada-straznik` | `fe641488` | brak | — | Strażnik kaskady CSS — **niedokończony**, patrz §7 |
| `robota/hero-pierwszy-ekran` | `d3dcd009` | brak | — | **WIP**, praca przerwana — patrz §7 |
| `audyt/martwy-kod-testy` | `ce66223e` | brak | — | Oblewa hook, patrz niżej |
| `codex/csp-spojnosc` | `42c4a637` | brak | — | Zajęta przez worktree, patrz niżej |
| `fix/690-alarm-kopii` | `3ee95345` | brak | — | Zajęta przez worktree, patrz niżej |
| `docs/611-tabela-54` | `69d42ee3` | `69d42ee3` | — | **gotowe** |
| `feat/retencja-wersji-przepisu` | `7d573343` | `7d573343` | — | **gotowe** |

`straznik/r47-r60-skan` wskazuje na `ad7485d2`, czyli **nie ma własnego
commita** — agent nie zdążył nic zapisać. Nie ma czego pchać.

### Jak działa pchanie i dlaczego szeregowo

Skrypty `kolejka[3-9].sh` w katalogu scratchpad sesji:
`…\Temp\claude\C--Users-matma-Documents-Codex\2cee51a4-…\scratchpad\`

Każdy czeka na poprzedni, pobiera gałąź z repozytorium kanonicznego **w momencie
swojej tury** (więc bierze najświeższy SHA), wymeldowuje ją w stanowisku
`/home/mateusz/kuking-paneldiag-run` i pcha — a hook `pre-push` uruchamia tam
pełną baterię. Jedno pchnięcie trwa **6–7 minut**.

**Szeregowo, bo dwa równoległe przebiegi zderzają się na bazie** i dają fałszywe
porażki: 142 failures, deadlocki, `relation "contact_messages" does not exist`.
Zdarzyło się dwa razy tego dnia.

**W tym repozytorium nie ma hooka `pre-commit`** — `scripts/install-hooks.sh`
instaluje wyłącznie `pre-push`. Commit niczego nie sprawdza; pierwszym
sprawdzeniem jest pchnięcie. **[pomiar cudzy: sesja #2]**

### Prawdziwe czerwienie hooka z `kolejka3`

Kolejka trzecia wypchnęła `audyt/8-r1-r6-decyzje`, `narzedzia/kontrola-ujemna-v2`
i `feat/retencja-wersji-przepisu`. Reszta padła i **każda na czymś innym** — to
nie jest jedna wspólna przyczyna:

| Gałąź | Oblany test |
|---|---|
| `fix/migotanie-ci` (`4478acd2`) | `DokumentyMdNieMajaMartwychOdnosnikowTest` — 2 porażki |
| `rodo-paczka` (`154b84a0`) | `TekstyNiePrzypisujaPlciTest` — 2 porażki |
| `audyt/martwy-kod-testy` (`ce66223e`) | `OdnosnikiDziennikaDecyzjiIstniejaTest` |
| `feat/historia-wersji-przepisu` (`08a298a5`) | `PomiarDostepnosciWybieraPowtarzalnieTest` |

`fix/migotanie-ci` przeszła hook na **`4478acd2`**, a dziś stoi na `9f150c97`
(13 commitów) — porażki mogły zniknąć po drodze. **Sprawdzić na nowym SHA,
zanim zacznie się szukać przyczyny.**

### Dwie gałęzie zajęte przez cudzy worktree

```
fatal: 'codex/csp-spojnosc' is already used by worktree at '/home/mateusz/kuking-697-run'
fatal: 'fix/690-alarm-kopii'  is already used by worktree at '/home/mateusz/kuking-690-run'
```

Trzeba pchnąć **z tamtego worktree** (hook zadziała tam) albo odpiąć go od
gałęzi. **Nie kasować go przez `worktree prune`** — §5.

### Dwie gałęzie żyją tylko w WSL

`rodo-paczka` (`/home/mateusz/kuking-rodo-paczka`) i
`feat/historia-wersji-przepisu` (`/home/mateusz/kuking-wersje`) nie mają refu
w repozytorium kanonicznym. Wyciągnąć przez `git fetch <ścieżka> <gałąź>` —
**pełny SHA, skrócony nie działa.**

---

## 4. Flak `Port marki` — rozpoznany, poprawka czeka

**[pomiar cudzy: agent M-4, `fix/m4-przeplyw-pomiar` = `76f385f9`]**

Warunek w `scripts/szybki-wyglad.mjs` był **niespełnialny, nie powolny**.
Rozkład dwubiegunowy: pełna prędkość → asercja w **0–3 ms**; procesor zwolniony
8× → **0/10 zielonych**, 9 razy objaw z CI (`waitForFunction: Timeout 30000ms`).
**Podniesienie limitu nie uratowałoby ani jednego przebiegu.**

Trzy przyczyny, każda wystarczająca:

1. atrybut `PRZEPLYW` ustawiał już `focusin` na polu logowania — asercja
   przechodziła w 1 ms, **nie mierząc przewijania w ogóle**;
2. skok liczony z prostokąta widgetu, **który już ustąpił** → żądane −1783 px
   przy możliwych −445 px;
3. `resources/js/app.js` **400 ms po załadowaniu sam ustawia fokus** na
   `.error-summary` — pomiar ścigał się z tym timerem i pod obciążeniem
   przegrywał.

Poprawka zamienia jeden skok na **pętlę po 63 położeniach co 40 px**; zmierzone
zasłonięć 0/63, ustąpienie widgetu 4/63. **Zachowania widgetu nie zmieniano.**

Zastrzeżenie autora, bez łagodzenia: **obciążenie było emulowane**
(`Emulation.setCPUThrottlingRate` 8×), nie odtworzone na runnerze.

Po scaleniu `76f385f9` czerwienie `Port marki` **powinny** zniknąć — to jest
hipoteza, nie pomiar. Nie ogłaszać jej jako faktu.

---

## 5. Pułapki środowiska ustalone tego dnia

### 5.1 `git worktree prune` wypruwa worktree'e w WSL

Worktree ze ścieżką WSL (`/home/mateusz/…`) jest dla gitowego klienta
z **Windows** zawsze `prunable` — ta ścieżka po tej stronie nie istnieje.
**Jedno `git worktree prune` z Windows kasuje rejestrację wszystkich naraz.**

20.09 trafiło to **cztery sesje jednocześnie**; przyczyną był **jeden** prune
uruchomiony przez agenta sesji prowadzącej. Potwierdzone eksperymentem
**[pomiar cudzy: sesja #1]**: żywy worktree założony z WSL, widziany przez `ls`
w tej samej chwili, git z Windows opisuje jako `prunable`.

Objaw nie przypomina przyczyny: `fatal: not a git repository: (NULL)` przy
poprawnym pliku `.git` i nietkniętych plikach.

**Commity i gałęzie przeżywają** (obiekty i refy żyją w repozytorium
nadrzędnym). **Ginie indeks** — praca zestawiona przez `git add`
i niezacommitowana.

**Reguła: na tym repozytorium `worktree prune` nie uruchamiamy w ogóle.**
`git worktree list` **nie jest zabezpieczeniem**, bo sam kłamie o żywej pracy.
Odzysk: skopiować pliki na bok, `git worktree add <nowa ścieżka> <gałąź>`,
wkleić, `git reset` **mieszany** (nigdy `--hard`).

Cichsza część: odzysk przez `git add -A` chce przy okazji **zdjąć bity `+x`**
(Windows ich nie przechowuje) i wciągnąć cudze pliki.

Sesja #1 zapisała regułę w `AGENTS.md` (commit `942b7d84` na
`fix/migotanie-ci`) — **pojedynczy, łatwy do wyjęcia. Ostatnie słowo ma
właściciel**, bo to jedyne źródło prawdy projektu.

### 5.2 SIGPIPE pod `set -o pipefail`

**[pomiar cudzy: codex-52, `narzedzia/kontrola-ujemna-v2`]**

`printf '%s' "$X" | grep -q "$WZ"` — `grep -q` wychodzi przy pierwszym
trafieniu, `printf` dostaje SIGPIPE (141), `pipefail` bierze status z niego.
**Warunek fałszywy mimo trafienia.** Ujawnia się dopiero przy dużym wyjściu
(158 kB z PHPUnita); krótkie atrapy dawały zieleń **25 razy z rzędu**.

Kierunek zmierzony w czterech wariantach: **fałszywa `POTWIERDZONA` była
niemożliwa** — defekt dawał wyłącznie fałszywe `ZLA_PRZYCZYNA`. Dlatego
**żadnego werdyktu `POTWIERDZONA` z tego dnia nie trzeba powtarzać.**

Naprawa: `grep -qE "$WZ" <<< "$X"`. Opisane w `docs/PULAPKI_TESTOW.md` §5c.

Groźny skutek uboczny: człowiek widzi „wzorca nie ma w wyjściu", **rozluźnia
`--oczekuj`, aż trafi** — i własnoręcznie kasuje rozróżnienie, dla którego to
pole istnieje. Wtedy fałszywa zieleń pojawia się naprawdę, tylko wprowadza ją
człowiek.

**Sprawdzone w tej sesji:** bramki w `.github/workflows/ci.yml`
(`echo "$zmienione" | grep -qE …`, trzy sztuki decydujące, **czy w ogóle
uruchomić zadania przeglądarkowe**) **są bezpieczne**, bo nie deklarują
`shell: bash` i GitHub uruchamia je jako `bash -e {0}` — **bez `pipefail`**.
To jest zabezpieczenie **przez przeoczenie**: **dopisanie `shell: bash`
wprowadzi usterkę.**

`tests/skrypty/kopia-bazy.sh:238` ma wrażliwą postać, ale kierunek prowadzi do
czerwieni i wyjście jest krótkie — ryzyko uśpione, zostawione świadomie.

### 5.3 Pułapka bazy gałęzi

**[pomiar cudzy: sesja #1]** `PortMarkiMaWlasnaBramkeCiTest` czyta
`.github/workflows/ci.yml` przez `base_path()`. **Każda gałąź odgałęziona przed
20.09** ma stary `ci.yml` bez `widok:` i dostanie cztery czerwienie
**odtwarzalne w izolacji** — czyli wyglądające na prawdziwą regresję pakietu
#611. `PULAPKI_TESTOW.md` §10, wskaźnik P-3 w rejestrze migotania.

Sedno, ważniejsze od samej rady: **odtwarzalność w izolacji, która normalnie
wyklucza flaka, tutaj utwierdza w błędzie** — plik jest stale nieświeży, więc
czerwień jest stale ta sama.

Zalecenie: **kopiować całe drzewo z wykluczeniami, nie listę katalogów do
wzięcia** — lista milczy, gdy dojdzie piąty katalog.

Sprawdzenie (**w WSL**; w Git Bash na Windows `git show 'origin/main:…'` cicho
pada przez konwersję ścieżek MSYS — trzeba `MSYS_NO_PATHCONV=1`):

```
diff <(git show origin/main:.github/workflows/ci.yml) <kopia>/.github/workflows/ci.yml
```

### 5.4 Flaga, która nie przechodzi przez `artisan serve`

Ekrany `/pytania` stoją za `KUKING_QUESTIONS_ENABLED`, która **nie przechodzi
przez `artisan serve`** (whitelist `$passthroughVariables`). Pierwszy przebieg
pomiaru dostępności dał z tego **48 błędów wyglądających na usterki ekranów**.

### 5.5 `<env>` w `phpunit.xml`

`<env>` **bez `force`** nie nadpisuje prawdziwej zmiennej środowiskowej, ale
**nadpisuje `.env`**. To był powód, dla którego przebiegi meldowały
`Port: 5432` mimo poprawnej konfiguracji.

---

## 6. Decyzje właściciela

### Podjęte i wykonane albo w toku

| Decyzja | Wybór | Stan |
|---|---|---|
| Adresy osób **bez konta** (zaproszenia, zmiana e-maila) | **Retencja czasowa + zdanie w samym liście**: skąd mamy adres, jak długo trzymamy, jak poprosić o usunięcie | `5e2f62a0`, listy zmienione, testy zielone, **obie kontrole ujemne potwierdzone**, została pełna suita |
| Przycisk „Zostań kuKINGiem" niewidoczny bez przewijania | **Napraw tylko dla skali 100%**; przy 140% godzimy się na przewijanie | `d3dcd009` **WIP** — patrz §7 |
| Kolizja „Alfa 0.68" w `CHANGELOG.md` | **Późniejsza pozycja dostaje 0.69**, nic nie znika, nie scalać | **NIEZROBIONE** |
| Licznik poczty | **Wspólny dla wszystkich dróg** | `800d609d` |
| Stan zeszytu w feedzie | **Dolicz** | `a544d4d0` |
| Retencja wersji przepisu | **Czasowa, 24 miesiące** | `7d573343` |
| Stopka | **16 px — stopka to drugi plan** | w `d8f45d35` |
| Kolaż na telefonie | **Zostaw kolaż, popraw komentarz** | `1802c391` |
| `LOG_BLAD_WEBHOOK_URL` | **Na razie zostaw wyłączone** | — |
| Kopia zapasowa | **Czeka, aż będzie na produkcji** | — |
| Próg DSA | **Zostaw próg, dodaj drogę obejścia** | — |

### Otwarte — wymagają właściciela

- **Umowy powierzenia / DPA.** Nietknięte.
- **D-091**: decyzja obiecuje liczby o osobie renderowane **dwukrotnie**, kod
  renderuje raz, a test **wskazany przez sam wpis jako jego strażnik** nazywa
  się `test_liczby_wystepuja_raz_w_dokumencie` i asertuje **brak** wariantu
  szynowego. Audyt **świadomie nie rozstrzygnął**, czy decyzję odwrócono, czy
  nigdy nie wykonano — to dwie różne naprawy i dwie różne historie.
- **Tablica „kuKINGi na dziś"** nie dolicza stanu zeszytu, więc jej karty zawsze
  mówią „Zapisuję". `post-card.blade.php` opisuje to jako świadomą decyzję
  D-081; właściciel jej nie uchylił.
- **Luka 1b** — autor/moderator gotujący nieopublikowany przepis. Zapisana jako
  **NIE DO NAPRAWY bez decyzji właściciela**, nie jako zaległość: asercja
  zabetonowałaby zachowanie, którego **nikt nie wybrał**. Kto przejmie kolejkę
  bez czytania, najpewniej „domknie" to asercją. **[codex-52]**
- **Reguła o worktree w `AGENTS.md`** (commit `942b7d84`) — zostawić czy wyjąć.

---

## 7. Agenci zatrzymani w połowie — jak ich wznowić

Wszyscy zatrzymani na życzenie właściciela (limit). Żaden nie pushował.

| Gałąź | SHA | Worktree | Na czym stanął i co dalej |
|---|---|---|---|
| `robota/retencja-adresow-bez-konta` | `5e2f62a0` | `kuking-retencja-adresow` | **Najdalej.** Listy zmienione, oba testy zielone, obie kontrole ujemne potwierdzone, Pint czysty. Zostało **tylko przepuszczenie pełnej suity** — czyli w praktyce pchnięcie przez kolejkę. |
| `robota/kaskada-straznik` | `fe641488` | `kuking-kaskada` | W trakcie kontroli **dodatniej**; MD5 i mtime co do nanosekundy pobrane. Wznowić od dokończenia kontroli ujemnej. Zadanie: strażnik pytający o **wynik kaskady** (`getComputedStyle`), nie o tekst arkusza, plus pomiar kolizji `.przepis-liczby`. |
| `straznik/r47-r60-skan` | brak commita | `kuking-straznik` | Pierwsze trafienia okazały się **fałszywe** (referencje `ctx.shared.X`, proza w dokumentacji, jawne atrapy). Zawężał detektory **regułami ogólnymi, nie listą znalezisk** — utrzymać ten kierunek. |
| `robota/hero-pierwszy-ekran` | `d3dcd009` **WIP** | `kuking-hero` | Sam układ dał przy 320 px **776 → 678 px**; 360, 375 i 414 px **przechodzą przy 100%**; każda liczba przy 140% też się poprawiła. Zostało **110 px przy 320 px**, do odzyskania **wyłącznie przez skrócenie tekstu**. Zamysł autora: zostawić zdanie błogosławione przez `GLOS_MARKI.md`, usunąć opisowe. **Brak testu i kontroli ujemnej.** |
| panel moderacji wobec playbooka | — | — | Wchodził w kontrole ujemne. Nic nie zapisane. |

---

## 8. Sesje równoległe — co zostawiły

- **Sesja #1** (rejestr migotania / CI). Zamknięta. `fix/migotanie-ci`
  (`9f150c97`, 13 commitów) i `docs/611-tabela-54` czekają. Własne przekazanie:
  `C:\Users\matma\Documents\Codex\HANDOVER-migotanie-ci.md`. Otwarte pozycje
  rejestru: **M-2** (mechanizm znany — restart maszyny wirtualnej WSL widoczny
  w `Microsoft-Windows-Hyper-V-VmSwitch`; **wyzwalacz nieustalony**) oraz
  **M-5** (`BlobNotFound`, 10 wystąpień, łagodne). M-1, M-3, M-4, M-6, M-7
  zamknięte.
- **Sesja #2** (dokumenty prawne / decyzje). `audyt/8-r1-r6-decyzje` = `6f753517`.
  **Siedem ADR-ów ma dotąd tylko warstwę mechaniczną.**
- **codex-52** (kontrola ujemna / mapa reguł). Zamknięta, drzewo czyste.
  SHA: `09c38c0e`, `ad7485d2`, `a1c57129`, `6cc622ef`, `1936379b`, `61622e9e`.
  Własne przekazanie: `docs/PRZEKAZANIE_MUTACJE_2026_09_20.md`.

### Mapa reguł `AGENTS.md` — stan wiedzy **[pomiar cudzy: codex-52]**

75 reguł normatywnych, trzy stopnie:

| stopień | znaczenie | ile |
|---|---|---|
| A — dowód z mutacji | zepsuliśmy kod, test oblał | **11** |
| B — jest test, nikt go nie zepsuł | przechodzi; nie wiadomo, czy oblewa | **48** |
| C — brak strażnika | nic się do reguły nie odnosi | **16** |

Wcześniejsza liczba „31 z dowodem" była **przekłamaniem sesji prowadzącej** —
31 to zabite mutacje, z czego 13 dowodzi obietnic z `docs/legal/`, a 6 przypada
na jedną regułę. **Stopień B nie jest „prawie A"**: kampania dała dziewięć
testów **o właściwej nazwie**, które przechodziły po zepsuciu pilnowanego kodu.

**Zastrzeżenie najważniejsze: R49 nie jest dowiedziona do końca, a to reguła
o autoryzacji.** Sześć mutacji w `app/Policies/*` dowodzi, że **ciała** Policy
są pilnowane — **nie** dowodzi, że `KazdaTrasaZIdentyfikatoremPodPolicyTest`
strzeże **spisu tras**. Mutacja do zrobienia jest jednozdaniowa: usunąć trasę
ze spisu i sprawdzić, czy test oblewa. Gdyby przeszedł, R49 spada z A do B.

**Luka w sekcji C jest widoczna; fałszywy wpis w sekcji A jest niewidoczny
i cytowany jako dowód.** Dlatego zastrzeżenia do wierszy z sekcji A stoją przy
nich, nie w przypisie.

Trzy najpilniejsze z sekcji C: **R47** (`.env` w repo / hasło admina / wyłączony
CSRF — skutkiem jest wyciek, brak jakiegokolwiek skanu), **R73** (tożsamość
produktu), **R60** (nic nie oblewa na SQLite, a wtedy cała reszta mapy przestaje
znaczyć, co znaczy).

**R73 jest gotowa do napisania, nie do zaprojektowania.** Okazała się **sześcioma
zakazami o sześciu kosztach** — wiersz mapy dziedziczył koszt najdroższego
składnika i przez to wyglądał na niemierzalny. Trzy mierzalne dziś; trzy
(streaki, masowy import, sztuczne konta) **świadomie zostają w C**, bo grep po
nazwach dałby zielone o zerowej mocy — „gorsze niż brak, bo przesuwa wiersz z C
do A, nie zmieniając niczego w rzeczywistości".

Kryterium zakazu, po korekcie: **nie „agregat kontra kolumna", tylko co jest
liczone.** `MAX(published_at)` to czas — wolno. `COUNT(obserwujących)` to
popularność — nie wolno. Dwa legalne wyjątki (`DailyBoard.php:302`,
`SearchQuery.php:214` — trafność **nie jest feedem**) idą do **rejestru wyjątków
z powodem**, na wzór dwupoziomowego rejestru
z `WrazliweKolumnyPozaMasowymPrzypisaniemTest`.

Pułapka przy pisaniu: **skan, który nie znajduje żadnego pliku, przechodzi** —
potrzebna kontrola dodatnia na wzór `test_skan_naprawde_czyta_modele`.

---

## 9. Dwa wzorce, które wyszły z tego dnia

### „Wiedza zapisana przy jednym pliku nie istnieje"

Dwa wystąpienia w jeden dzień:

- `tests/skrypty/entrypoint-nadzor.sh:182` — dziesięciowierszowy komentarz
  opisujący **dokładnie** pułapkę SIGPIPE, z adnotacją, że kosztowała pół
  godziny **w trakcie awarii produkcji**. Nie uchronił przyrządu kontroli
  ujemnej napisanego później.
- `app/Domain/Feed/DailyBoard.php:299` — *„Sortujemy po tym, KIEDY ktoś ostatnio
  coś pokazał, nie po tym, ile ma obserwujących."* Czyli R73 zapisana w kodzie
  jako komentarz przy **jednym** zapytaniu, nieobejmująca następnego.

Reguła: **kiedy lekcja kosztowała awarię, jej miejscem jest dokument, a przy
pliku zostaje wskaźnik.**

Hipoteza, **niesprawdzona** — czeka na trzeci przypadek: oba komentarze są
napisane **lepiej** niż większość naszych dokumentów. Jeśli się potwierdzi,
dobre uzasadnienie **zaspokaja potrzebę**, która inaczej pchnęłaby do napisania
strażnika. Konsekwencja praktyczna **[codex-52]**: przegląd pod kątem
brakujących strażników powinien zaczynać się od **najlepiej udokumentowanych**
miejsc, nie od najgorszych — odwrotnie, niż robi się to zwykle.

### „Zmierz zachowanie na nietkniętej bazie, zanim uznasz, że coś jest zepsute"

Sonda na nietkniętym `origin/main` pokazała, że **dwie trzecie zgłoszonej
usterki feedu nie istniało** — `FollowingFeed` i `DiscoverFeed` już doliczały
stan zeszytu; zepsuta była wyłącznie karta „Wspomnienie".

Zastrzeżenie, bez którego ta reguła sama staje się zdaniem brzmiącym jak
zmierzone: **sonda rozstrzyga, czy usterka istnieje, i nie mówi nic o tym, czy
uzasadnienie pod istniejącym kodem jest prawdziwe.** Że „to kosztuje zapytania"
nigdy nie było zmierzone, wyszło z **osobnego** pomiaru: **29 zapytań przy
2 wierszach i 32 przy 12 — identycznie przed zmianą, po zmianie i przy
`dolicz()` całkiem wyciętym.**

### Konwencja raportowania

Bez adnotacji = **sprawdzone samodzielnie w tej sesji**. `[pomiar cudzy: …]` =
przejęte, źródło podane, i osobno napisane, co z tego sprawdzono.

Sesja prowadząca przekręciła tego dnia kilka cudzych wyników i **zawsze w tę
samą stronę** — korzystniejszą dla opisywanego. Operacja była zwykle ta sama:
**podniesienie cudzej liczby o jeden poziom ogólności.**

---

## 10. Pierwsze kroki po wznowieniu

1. `MSYS_NO_PATHCONV=1 git ls-remote origin` — porównać z tabelą w §3.
2. Sprawdzić, czy kolejki żyją:
   `wsl -d Ubuntu -- bash -lc "ps -eo etime,args | grep '[k]olejka'"`.
   Jeśli nie żyją, a gałęzie nie weszły — złożyć nową na wzór `kolejka7.sh`.
3. Przejrzeć czerwienie #789, #790, #725 **po ponowieniu** i scalić, co zielone.
   Zacząć od #790 — jedyny bez hipotezy.
4. **Priorytet: wypchnąć `fix/m4-przeplyw-pomiar` (`76f385f9`)** — to on
   odblokowuje dwie pozostałe czerwienie.
5. Dokończyć `robota/hero-pierwszy-ekran` (skrócenie tekstu + test + kontrola
   ujemna) i zrobić **niezrobiony CHANGELOG 0.68 → 0.69**.
6. Wyciągnąć `rodo-paczka` i `feat/historia-wersji-przepisu` z runtime'ów WSL.
7. Odpiąć `codex/csp-spojnosc` i `fix/690-alarm-kopii` od cudzych worktree
   — **bez `prune`**.
