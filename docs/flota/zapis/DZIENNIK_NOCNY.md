# Dziennik nocny — 20/21 września 2026

Zapis pracy autonomicznej. Właściciel śpi; rano dostanie podsumowanie.
Każdy wpis: data, godzina, co się stało, z liczbami. Dopisywać na KOŃCU,
nigdy nie kasować.

## Zasady tego dziennika

- **Fakt, nie wrażenie.** Każde twierdzenie z dowodem: SHA, liczba, cytat z logu.
- **Czego NIE zrobiono** jest równie ważne jak to, co zrobiono.
- **Decyzje wymagające właściciela** zbierać w sekcji na końcu, z wariantami i kosztem.
- Cudze pomiary oznaczać `[pomiar cudzy: źródło]`.

## Stan na początek nocy (20.09, ~23:00)

- `main` = `4c811cc7`, produkcja = `4c811cc` (wydanie 19:17).
- Otwartych PR-ów: 15, w tym #929 z poprawką klienta PostgreSQL 18.
- **#929 potwierdzone**: runner miał `pg_restore 16.15`, po kroku `18.6`;
  kod 51 zniknął, job „Pint" zielony w 1:36.
- **Druga przyczyna czerwieni ZOSTAJE**: `ProbaOdtworzeniaTest` pada
  komunikatem „PostgreSQL nie odpowiada", to inna sprawa niż kod 51.
- Kolejka pchania: 116 pozycji, próg obciążenia 16, chodzi.
- Odzyskane po awarii: 56 gałęzi ze 107 martwych stanowisk, wszystkie w kolejce.
- Minuty GitHuba: ~2189 z 3000, odnowienie za 11 dni, ~65 min na przebieg PR-a.

## Wpisy

### 20.09, 23:1x — audyt przebieg 2 i trzy odzyskane gałęzie

**Z12 — plik `pchniete.txt` mówi co innego, niż znaczy.** Skrypt kolejki
dopisuje tam w CZTERECH sytuacjach: po udanym pchnięciu, po `FETCH FAIL`,
po `CHECKOUT FAIL` i po trzeciej porażce. Z 53 wpisów tylko **dwa** to
realne pchnięcie po restarcie. To nie usterka skryptu — to nazwa myląca,
i dziś wprowadziła mnie w błąd: uznałem `naprawa/847-termin`,
`naprawa-858` i `gpt/testy-50plus` za wypchnięte, a miały `FETCH FAIL`.

**Trzy gałęzie odzyskane i wstawione do kolejki:**
- `naprawa-858` — `390e7640`, **52 pliki / 9141 wstawek** (limiter tagów).
- `gpt-testy-50plus` — `ad76d56c`.
- `naprawa/847-termin` — `8de0cf60`, odzyskane przeze mnie z plików
  (commit `5b4e19b8` przepadł; kod, widok, kontroler i test przetrwały).

**Zadanie C rozstrzygnięte: dowód 429 jest z PRAWDZIWEJ odmowy.** Test nie
podstawia limitera ani nie wyłącza middleware — wysyła żądania w pętli do
400 razy i czeka, aż aplikacja naprawdę odpowie 429. Ma własną kontrolę
metody: `assertStatus(429, 'Test nie wymusił prawdziwej odmowy…')`, więc
przebieg, który nie zdążył wywołać odmowy, **oblewa zamiast przejść po cichu**.
Granica „poprawne dane nigdy nie znikają" dotrzymana i udowodniona.

**Zadanie A — luka jest systemowa i ma przyczynę, nie winnego.**
599 plików testowych, **170 to strażnicy czytający źródła, 3 objęte
kontrolą dodatnią**. Przyczyna: `kontrole-negatywne-alfa08.py` **odmawia
uruchomienia poza CI** (`if os.environ.get("CI") != "true": raise SystemExit`),
więc stanowisko nie ma jak sprawdzić własnego wpisu w chwili pisania,
a błąd w mutacji wywraca cały krok CI. Racjonalna reakcja: nie dotykać.
Uzupełnianie 167 wpisów wstecz jest niewykonalne przy 2189 minutach.

**Propozycja audytora: strażnik działający TYLKO DO PRZODU** — na plikach
testowych dodanych w diffie, wymagający wpisu w `checks` albo znacznika
`@bez-kontroli-dodatniej <powód>`. Koszt CI pomijalny. **Najważniejsza
pojedyncza zmiana: zdjąć blokadę `CI != "true"`** — bez tego punkt
instrukcji zostanie życzeniem.

**Z15 — atrybucja decyzji nie do odtworzenia.** `gpt/zawieszone-konto`
(`2c561ce0`) dopisuje do `docs/DECISIONS.md` wpis „Decyzja właściciela #926
[…] Właściciel wybrał wariant 2", a w pliku zlecenia nie ma słów „wariant",
„właściciel" ani „decyzja"; stanowisko meldowało, że pliku zlecenia jeszcze
nie ma. Wpis nie ma też numeru `D-`. Nie twierdzę, że decyzji nie było —
twierdzę, że dziennik niesie atrybucję nieodtwarzalną z repozytorium.
**DO ROZSTRZYGNIĘCIA PRZEZ WŁAŚCICIELA.**

**Z17 — granica zakresu nienazwana.** `gpt/dziennik-wyjatkow` usunął
`getMessage()` z czterech plików moderacji, ale został w trzech jobach
poza zakresem, m.in. `ProcessUploadedImage.php:240` i `:277` — a ten
przetwarza pliki od ludzi. Zakresu zgłoszeń dotrzymano; brakuje zdania
nazywającego granicę, przez co raport czyta się jak „wyjątki już nie niosą
cudzych treści".

**Trzy podejrzenia sprawdzone i NIEPOTWIERDZONE** — to najcenniejsza część
przebiegu: dopuszczenie `collections.store` przy zawieszeniu NIE otwiera
publikacji tylnymi drzwiami (polityka przepuszcza tylko `private`, jest
nazwany test, a strażnik tras został **rozszerzony**, nie osłabiony);
zmiana konstruktora `UstawienieNowegoHasla` NIE wywróci workera na starych
ładunkach (`?? null` plus test z prawdziwym `serialize`/`unserialize`);
429 nie było atrapowane.

### 20.09, 23:3x — druga przyczyna czerwieni znaleziona i naprawiona

`ProbaOdtworzeniaTest` padał komunikatem „PostgreSQL nie odpowiada".
Przyczyna w `tests/skrypty/proba-odtworzenia.sh:147`:

    if ! pg_isready -q 2>/dev/null; then

**Wywołane bez ŻADNYCH parametrów połączenia** — bez `-h`, `-p`, `-U`,
bez wyeksportowanego `PGHOST`/`PGPORT` — mimo że skrypt dwa wiersze wyżej
liczy `BAZA_HOST`/`BAZA_PORT` z `DB_HOST`/`DB_PORT` i używa ich wszędzie
indziej. Usługa `postgres:18-alpine` w CI wystawia **port dynamiczny**
(w logach 32768, 33111), a `pg_isready` bez argumentów sprawdza własny
domyślny `localhost:5432` — czyli zupełnie inny cel.

**NAJWAŻNIEJSZE — to działało na self-hosted przez PRZYPADEK.**
Na runnerach WSL stoi własny PostgreSQL 16 na porcie 5432, więc
`pg_isready` znajdował *jakiś* serwer i meldował gotowość, a reszta skryptu
i tak używała `DB_PORT`. **Fałszywa zieleń.** Na `ubuntu-latest` nic nie
nasłuchuje na 5432, więc ten sam błąd świeci uczciwie na czerwono.

To ma bezpośredni skutek dla hybrydy runnerów (D-225): po zejściu jobów
z powrotem na self-hosted ten test znów zacząłby „przechodzić" bez powodu.
Poprawka jest więc warunkiem sensowności hybrydy, nie dodatkiem.

Poprawka jednowierszowa, gałąź `naprawa/proba-odtworzenia-w-ci`,
commit **`f2c7b74c`**, wstawiona na CZOŁO kolejki:

    + if ! pg_isready -q -h "${BAZA_HOST}" -p "${BAZA_PORT}" 2>/dev/null; then

**Ten sam błąd był już raz naprawiony** — commit `8a8b61a6` na gałęzi
`praca/izolacja-bazy-klon` (PR #786, OPEN, CONFLICTING). Poprawka nigdy
nie trafiła na `main`, bo PR ma konflikt. Ten sam commit niesie też
rozłączenie baz testowych dla zwykłych klonów (P7).

**Znalezisko poboczne, ta sama rodzina:** `kuking_nazwa_testowej_bazy()`
w CI zwraca gołe `kuking_test`, bo `actions/checkout` daje `.git` jako
KATALOG, a funkcja liczy sufiks tylko z `.git` będącego PLIKIEM. Sufiks
wychodzi pusty i skrypt celuje w `kuking_zrodlo_proby_glowny`. W CI to
dziś nieszkodliwe (każdy job ma własny, efemeryczny kontener), ale to ta
sama luka nazewnicza co P7.

### 20.09, 23:4x — #918: przepełnienie WPROWADZIŁ ten PR, naprawione

Werdykt jednoznaczny: to nie było przepełnienie odsłonięte, tylko
**wprowadzone przez sam audyt UX 50+**. Mechanizm opisany dokładnie:
`.site-footer-grupa ul a` dostał w tym audycie `display: flex` i
`min-height: var(--control-height-min)`, żeby spełnić cel dotknięcia 48 px.
Skutek uboczny: odnośnik stał się elementem flex w kolumnie stopki
**bez `min-width: 0`**, więc przy czcionce przeglądarki 32 px i skali
tekstu 140% (pismo 50,4 px) najdłuższe nierozdzielne słowo — „nielegalną"
w „Zgłoś nielegalną treść" — było szersze niż kolumna `min(11rem, 100%)`
i nie mogło się złamać. Stąd 9 px poziomego przewinięcia CAŁEJ strony.

Poprawka: commit `640198cc`, wyłącznie `resources/css/app.css`, +16 linii.
Minima 18 px i 48 px **zachowane** — nie obniżono ich, żeby zmieścić się
w 320 px, co odwróciłoby sens całego PR-a.

**Kontrola z mojej strony:** agent zostawił niezacommitowaną zmianę
w `scripts/kompozycje-marki.mjs`, czyli w SKRYPCIE POMIAROWYM — dokładnie
tam, gdzie zabraniałem ruszać. Sprawdziłem diff: zmiana jest **czysto
diagnostyczna** (wypis elementów wystających przed rzuceniem tego samego
błędu), asercja `throw` nietknięta. Miernik nie został osłabiony.
Przywróciłem plik i usunąłem trzy tymczasowe skrypty `_tmp_*`.
Gałąź czysta, wstawiona do kolejki.

**Uwaga o tym agencie:** dwukrotnie zameldował zapowiedź zamiast wyniku
(„uruchomiłem w tle", „waiting for completion") przy 196 tys. tokenów
i 95 wywołaniach. Pracę wykonał dobrze, ale meldunki były bezwartościowe —
stan ustaliłem sam, oglądając gałąź.

### 20.09, 23:5x — weryfikacja odzysku znalazła TRZY ciche szkody

Odzysk 56 gałęzi był dobry, ale nie bezbłędny. Weryfikacja znalazła trzy
przypadki cofania cudzej pracy — **żaden nie dałby czerwieni w testach**.

**1 i 2. `gpt-n1-powiadomienia` i `notyfikacja-zywa` KASOWAŁY decyzję D-224.**
Obie wstawiały w jej miejsce własny wpis D-223 w `docs/DECISIONS.md`.
To nie był artefakt starej podstawy: baza scalenia obu to `origin/main`,
które D-224 już miało. Cudza decyzja znikała z rejestru bez śladu.

NAPRAWIONE przeze mnie: plik wraca do wersji z `origin/main` (D-224 jest),
własny wpis D-223 doklejony na końcu. Commity `10f867eb` i `b54a53b6`.
Obie gałęzie wracają do kolejki.

**Warto zauważyć:** żaden test nie pilnuje kompletności rejestru decyzji.
Znalazła to weryfikacja czytająca diffy, nie CI.

**3. `zeszyty` cofa skutek D-224/#789** w `post-card.blade.php`: zamienia
bezwarunkowy przycisk „Usuń z zeszytu" na wariant widoczny tylko wewnątrz
zeszytu, czyli przywraca stan sprzed #789 w feedzie. `jedna-droga`
i `gpt-zeszyt-droga` dotykają tego samego pliku i robią to POPRAWNIE.

**WYJĘTA Z KOLEJKI — DO DECYZJI WŁAŚCICIELA.** Podejrzenie: to gałąź
zastąpiona przez #789 (jest wcześniejsza notatka, że `jedna-droga`
zastąpiła `zeszyty`). Nie zgaduję w nocy. Praca zachowana lokalnie
i w katalogu `zeszyty-ODZYSK`, nic nie skasowane.

**Cztery zmiany wcześniej zgłoszone jako wątpliwe — WSZYSTKIE czyste:**
- `gpt-moderacja-ai` −50 linii: ocena zdjęć przeniesiona do nowego joba.
- `zeszyty`/`jedna-droga` `CollectionController`: `catch` na kolizję
  unikalności jest obecny dwukrotnie w każdej wersji, tyle co w `main` —
  tylko przesunięty. **Obsługa wyścigu zachowana.**
- `gpt-eksport` −31 linii: powiadomienie przeniesione do nowego joba.
- `gpt-testy-50plus`: świadoma podmiana protokołu, plik URÓSŁ 222→344.

**Blok `.flash-powrot` w `app.css` nietknięty w żadnej z 56 gałęzi.**

**Do obejrzenia rano — prawdopodobne duplikaty tej samej pracy:**
`naprawa-858` i `tagi-filtr` (niemal identyczne, #858) oraz
`gpt-n1-powiadomienia` i `notyfikacja-zywa` (niemal identyczny zestaw
plików). Możliwe, że to samo zadanie zrobione dwa razy.
`docs/DECISIONS.md` rusza **dziesięć** gałęzi — to będzie bolało przy scalaniu.

### 21.09, 00:0x — #918 domknięte pomiarem po obu stronach

Agent dokończył i podał brakujące porównanie:
- `codex/audyt-ux50plus` przed poprawką: `/login` 320 px → **scroll 329**, plus `NAV638_DUZY_FONT_OVERFLOW`.
- `origin/main` tym samym kodem pomiaru, osobny worktree: **scroll 320, overflow=false, NAV638 OK**.

Werdykt „PR wprowadził" jest więc potwierdzony pomiarem po obu stronach,
nie samym rozumowaniem. Po poprawce cała macierz czysta: 320–1440 px,
oba motywy, skale 100/140/font-200/font-200+140. Pełny `sprawdzKompozycje`
(432 warianty z mutacjami ujemnymi) → `K509_OK`.

Pełny przebieg: **4335 zaliczonych, 0 porażek**, w tym `ProbaOdtworzeniaTest`
— przeszedł, bo stanowisko ma WŁASNĄ izolowaną bazę. To zgadza się
z diagnozą `pg_isready`: na własnej bazie i własnym porcie test działa,
psuje się tam, gdzie sprawdzenie gotowości celuje w zły port.

**ZNALEZISKO ŚRODOWISKOWE, dotyczy CAŁEJ FLOTY, nie tej gałęzi:**
`przygotuj-runtime.sh` wyklucza `.git` z rsynca do runtime, więc
`git ls-files` w `zeszyty-marki.mjs` pada przy pełnym
`node scripts/port-projektu.mjs` w środowisku floty. Uderzy każdego, kto
spróbuje pełnego przebiegu portu marki w runtime. Do naprawy osobno.

To ta sama rodzina problemów co P7: skrypty zakładają obecność `.git`,
którego rsync runtime'u nie kopiuje.

---

## 23:10 — Bramka pchania nie istniała od 21:14 (najważniejsze ustalenie nocy)

`.git/hooks/` w repozytorium kanonicznym zawierało **wyłącznie pliki `.sample`**,
z datą 21:14 — czyli z godziny odbudowy repozytorium po awarii. Hook `pre-push`,
na którym opierała się cała kolejka szeregowa, przepadł razem z katalogiem `.git`
i **nikt tego nie zauważył**, bo jego brak nie daje żadnego komunikatu: push po
prostu jest szybki.

Objaw, który to zdradził: `gpt/moja-wersja` padła na baterii o 20:59 (hook żył),
a późniejsze pchnięcia szły w sekundy.

Pchnięte bez bramki (z `.git/logs/refs/remotes/origin`, po 21:14):
- `naprawa/klient-pg18-w-ci` — 22:16
- `flota/dsa-odwolania` — 22:25
- `gpt/dziennik-wyjatkow` — 22:44

Hook przywrócony o 23:08 przez `scripts/install-hooks.sh`; `.git/hooks/pre-push`
istnieje (224 B). Te trzy gałęzie wymagają nadrobienia baterii — nie zakładam,
że są dobre tylko dlatego, że wyszły.

## 23:05 — Lista „do pchnięcia" była fałszywa i prawie na niej zbudowałem kolejkę

Pierwszy przebieg dał 79 pozycji, z czego 77 jako „PRZED:?" — czyli wyprzedzają
`origin`. **Nieprawda.** `git rev-parse origin/nieistniejaca-galaz` wypisuje
nazwę refa **na stdout** i dopiero `fatal:` na stderr. Podstawienie `$(...)`
łapało tę nazwę jako poprawne SHA, więc gałąź nieistniejąca na zdalnym wyglądała
na istniejącą i wyprzedzoną, a `rev-list` padał dając znak zapytania zamiast
liczby.

Poprawnie: `git rev-parse --verify --quiet refs/remotes/origin/<b>`.

Po poprawce: **77 gałęzi NOWYCH** (nie ma ich na GitHubie w ogóle) + 2 wyprzedzone
o 1 commit (`codex/audyt-ux50plus`, `flota/gotowanie`). Pominięto **107 martwych
stanowisk** — ich `.git` wskazuje na `worktrees/<nazwa>`, który nie przetrwał
odbudowy. To nie są kandydaci do pchania, tylko katalogi z plikami.

Zgodność z GitHubem: zdalnych gałęzi 72, z czego `origin/gpt/*` tylko **jedna** —
co potwierdza, że te 77 naprawdę nigdy tam nie było.

## 23:07 — Budżet minut: push jest darmowy, PR nie

Odczytane z bloków `on:` czterema plikami workflow:

| workflow | wyzwalacz |
|---|---|
| `ci.yml` | `push` **tylko do `main`/`staging`**, `pull_request` do `main`/`staging` |
| `preview.yml` | `pull_request` (opened, synchronize, reopened) |
| `railway-iac.yml` | `pull_request`, ale tylko przy zmianie `.railway/**` |
| `deploy.yml` | `deployment_status` + ręcznie |

Wniosek: **pchnięcie 77 gałęzi roboczych kosztuje zero minut Actions.**
Minuty zjada dopiero otwarcie PR-a (~65 min na przebieg). Przy ~2189 minutach
zapasu to sufit rzędu **30 PR-ów**, i to bez rezerwy na scalenia do `main`
(każde scalenie to kolejny przebieg `ci.yml` z wyzwalacza `push`).

Plan wynikający z tych liczb, nie z chęci:
1. Pchnąć wszystko — darmowe, zdejmuje pracę z dysku, gdzie tylko czeka na
   kolejną awarię.
2. PR-y otwierać partiami, w kolejności z `KOLEJNOSC_SCALANIA.md`, licząc minuty.
3. Gałęzie czysto dokumentacyjne trzymać na koniec — ich wartość nie zależy od CI.

## 23:02 — #23: strażnik martwych odnośników miał rację

`gpt/moja-wersja` cyklicznie padała na `DokumentyMdNieMajaMartwychOdnosnikowTest`.
Dokument projektowy #23 zapisywał dwa ekrany V2 — `przepisy/{fork}/oryginal`
i `przepisy/{oryginal}/moja-wersja` — w backtickach, notacją nieodróżnialną od
tras, które naprawdę są w `routes/web.php`. To obietnica bez pokrycia: czytelnik
dostaje adres, pod który nikt nie wejdzie.

Poprawiony dokument, nie strażnik. Commit `65ca5b19`.
Czerwień przed: 1 failed, obie trasy wymienione. Zieleń po: 3 passed, 49 asercji.
Kontrola dodatnia jest z pomiaru — czerwień widziałem, zanim tknąłem plik.

## 23:20 — Płacimy minuty, mając siedem własnych runnerów na biegu jałowym

`gh api .../actions/variables`: **`CI_RUNS_ON="ubuntu-latest"`** — zmienna JEST
ustawiona i kieruje wszystkie dziewięć jobów na płatną pulę GitHuba.

Jednocześnie `gh api .../actions/runners` pokazuje **siedem runnerów online**
(`kuking-wsl-DOM-NEW-01..03`, `kuking-wsl-NEW-01..03`, jeden offline),
wszystkie `busy=false`. W WSL widać działający `Runner.Worker` i `headless_shell`
na 335% CPU, więc maszyna umie ciągnąć także joby przeglądarkowe.

Czego NIE zrobiłem i dlaczego: nie przestawiłem `CI_RUNS_ON` na własną pulę.
Plan z `KOLEJNOSC_SCALANIA.md` mówi wprost, że zmienne ustawiamy **po** scaleniu
rozdziału hybrydowego, bo dopiero on wprowadza osobną `CI_RUNS_ON_PRZEGLADARKA`
dla czterech jobów przeglądarkowych. Przestawienie teraz wysłałoby na własne
runnery także je — a gdyby tam czegoś brakowało, zapaliłoby na czerwono PR-y,
które są zdrowe. To dokładnie ta klasa fałszywego sygnału, którą tropimy.

Kolejność została więc odwrócona pod ten cel: `naprawa/ci-hybryda-runnerow`
stoi jako **pozycja pierwsza** w kolejce pchania.

Do decyzji właściciela rano: po scaleniu hybrydy ustawić
`CI_RUNS_ON=["self-hosted","kuking-linux"]` (składnia z cudzysłowami wynika
z `fromJSON(vars.CI_RUNS_ON || '"ubuntu-latest"')` w `ci.yml`).

## 23:16 — #929 odblokowane; przyczyna potwierdzona z logu, nie z domysłu

Job „Testy (PostgreSQL 18)" PR-a #929:
`Expected: PostgreSQL nie odpowiada — nie ma czego dowodzić.` `KOD=1`,
przy 4393 pozostałych testach zielonych.

To ta sama sonda `pg_isready -q` bez argumentów: pyta localhost:5432. Lokalnie
przechodzi **przypadkiem**, bo na 5432 stoi cudzy klaster; w CI baza słucha na
`DB_PORT`, więc sonda kłamie i cały skrypt kopii wychodzi jedynką.

Poprawka przeniesiona na gałąź pg18 jako commit `021264c6` (sonda pyta
`BAZA_HOST`/`BAZA_PORT` z linii 71-72). Commitu `f2c7b74c` nie dało się
cherry-pickować: siostrzane stanowisko wisi na **innym repozytorium**
(`Codex/.git`, remote nazwany `gh707`), więc obiekty nie są wspólne.

Skutek uboczny: gałąź `naprawa/proba-odtworzenia-w-ci` niesie teraz tę samą
jedną linię co `naprawa/klient-pg18-w-ci`. Przy scalaniu wystarczy jedna z nich.

## 23:14 — Kolejka trafiła w pułapkę dwóch repozytoriów

8 z 74 stanowisk wisi na `Codex/.git`, którego remote nazywa się **`gh707`**,
a nie `origin`. `git push origin` u nich po prostu nie istnieje. Kolejka
zaraportuje to uczciwie jako PADŁO (porównuje SHA, nie kod wyjścia), a drugie
przejście obsłuży je pod właściwą nazwą remote'a. Sprawdzone: wszystkie osiem
jest naprawdę nowych także wobec `gh707`.

## 23:30 — #914 nie jest zepsute; czeka na #929

Job „Pint (styl kodu)" PR-a #914 (`flota/dsa-odwolania`) oblewa **9 z 167**
przypadków w kroku „Testy skryptu kopii bazy". Wszystkie dziewięć ma ten sam
podpis:

```
✗ pełne, zdrowe archiwum PRZECHODZI weryfikację
   oczekiwano: kod=0      otrzymano: kod=51
✗ archiwum obcięte do 99% zostaje ODRZUCONE
   oczekiwano: kod=53     otrzymano: kod=51
```

`kod=51` to niezgodność wersji `pg_restore`, nie uszkodzenie archiwum — zdrowe
archiwum i archiwum obcięte dostają **ten sam** kod, więc test nie odróżnia już
dobrego od złego. Fałszywa czerwień: gałąź jest niewinna.

Lekarstwem jest krok doinstalowania klienta PostgreSQL 18, który niesie
`naprawa/klient-pg18-w-ci` (#929). **Kolejność scalania jest więc wymuszona:
#929 przed #914.** Po scaleniu #914 wystarczy odświeżyć — bez zmian w treści.

Oszczędność: nie tknąłem #914. Gdybym „naprawiał" ją u niej, zamaskowałbym
przyczynę i zostawił test, który nie odróżnia archiwum zdrowego od obciętego.

## 23:22 — Moja własna kolejka dała FAŁSZYWE „OK". Opisuję to, bo to najważniejsza lekcja tej nocy

Napisałem kolejkę tak, żeby „nie mogła skłamać": wpis do `pchniete` miał
powstawać wyłącznie po porównaniu SHA lokalnego ze zdalnym, nigdy z kodu
wyjścia. I mimo to skłamała.

W klonie `push-run` remote o nazwie **`origin` to lokalne repozytorium
kanoniczne**, a nie GitHub (GitHub siedzi pod nazwami `github9`/`github10`).
Kolejka pchała do `origin`, push padał — i weryfikacja porównywała SHA
z `refs/remotes/origin/<gałąź>`, czyli z **lokalnym klonem**, który tę gałąź
miał po świeżym fetchu. Zgadzało się co do bajtu. Log powiedział:

```
error: failed to push some refs to '/mnt/c/.../Codex/kuking.pl'
23:21:30 OK naprawa/klient-pg18-w-ci -> 021264c6…
```

Porażka i „OK" w dwóch kolejnych wierszach.

Nauka nie brzmi „porównuj SHA" tylko **„sprawdź, czy porównujesz z tym samym
miejscem, do którego pchasz"**. Poprawka: pchanie i weryfikacja jawnie przez
`$ZDALNY=github9`.

## 23:19 — Hook pchał baterię w katalogu bez `vendor/`

Pierwsza wersja kolejki pchała wprost ze stanowisk windowsowych. Hook odpala
`./scripts/check.sh`, a tam nie ma `vendor/`:

```
Failed opening required 'C:\...\ci-hybryda/vendor/autoload.php'
✗ Migracja nie ma działającego down() — nie da się jej wycofać podczas awarii
Problemów do naprawienia: 7
```

Siedem „problemów" było artefaktem braku katalogu, nie oceną kodu. To gorsze
niż brak bramki, bo wygląda na werdykt. Właściwa droga (odtworzona z
`nowe-stanowisko-pchania.sh`): pchać z klonu w WSL `/home/mateusz/flota/push-run`,
gdzie `vendor` i `node_modules` są skopiowane, a hook ma czym pracować.

Druga pułapka w tym samym miejscu: `.env` klonu niesie `DB_PORT=5432`, czyli
port współdzielony, którego zasady zabraniają. Bateria wywalała się na
`password authentication failed for user "kuking"`. Kolejka nadpisuje teraz
środowisko na własny klaster `127.0.0.1:55439` i własną bazę
`kuking_flota_push`, tak samo jak `testuj.sh`.

## 23:18 — Uszkodzony `multi-pack-index` blokował każdy fetch

```
fatal: bad pack-int-id: 70102040 (2 total packs)
```

`git fsck --connectivity-only` pokazał wyłącznie wiszące obiekty — historia
cała. Uszkodzony był sam plik `multi-pack-index` (pamięć podręczna, odtwarzalna),
najpewniej niedokończony zapis przy odbudowie repozytorium. Usunięty (kopia
w `C:\Temp`), fetch działa. Repozytorium nietknięte poza tym plikiem.

## 23:35 — Cała flota PR-ów zaczerwieniła się naraz około 17:30

14 otwartych PR-ów, **ani jednego zielonego**. 10 z 11 sprawdzonych pada
w dokładnie tych samych dwóch jobach: „Pint (styl kodu)" i „Testy (PostgreSQL 18)".
Dwie niezależne przyczyny, obie wspólne, żadna nie dotyczy treści gałęzi:

1. `kod=51` w testach kopii bazy — niezgodność wersji `pg_restore`. Zdrowe
   archiwum i obcięte dostają ten sam kod, więc test przestał odróżniać dobre
   od złego. Lekarstwo: #929.
2. `WyborZeszytuMaWalidacjeTest`: `invalid input syntax for type uuid:
   "to-nie-jest-uuid"` → HTTP 500 zamiast polskiego komunikatu; w jednym
   przypadku 404.

Przy czym `ci.yml` na `main` (4c811cc7) jest **zielony**, a kontroler ma regułę
`'bail', 'nullable', 'uuid'` przed `exists` od #483 — więc kod na `main` jest
poprawny. Żadna z trzech sprawdzonych gałęzi (`flota/wyszukiwarka`,
`robota/bazy-stanowisk`, `flota/dsa-odwolania`) **nie zawiera 4c811cc7**.

Robocza hipoteza, jeszcze NIE potwierdzona pomiarem: gałęzie są przestarzałe
wobec `main`, a przebiegi z 17:39-17:58 liczono na starszym scaleniu. Rozstrzyga
to jedno uruchomienie `WyborZeszytuMaWalidacjeTest` na aktualnym `main` —
i dopóki go nie zrobię, nie ogłaszam werdyktu. Pisownia „prawdopodobnie" jest
tu celowa: dwa razy dziś myliłem wzór z przyczyną.

## 23:39 — Hipoteza „przestarzałe gałęzie" OBALONA (moja, sprawdzona i odrzucona)

Pobrałem rzeczywiste scalenia, na których liczono czerwone przebiegi
(`refs/pull/<n>/merge`), i sprawdziłem je wprost:

| PR | scalenie | reguła `bail`+`uuid` | zawiera 4c811cc7 |
|---|---|---|---|
| #916 | `f550c6b7` | **jest** | tak |
| #920 | `2444c068` | **jest** | tak |

Czyli kod w scaleniu jest poprawny i zawiera aktualny `main`. Moja hipoteza
z 23:35 była błędna i tu ją odwołuję.

Ustalone dalej: `4c811cc7` ma datę **17:11 CEST = 15:11 UTC**, a zielony przebieg
`ci.yml` o `15:11:17Z` to dokładnie przebieg z tego scalenia. Więc ten sam test
jest **zielony na `main`** i **czerwony na scaleniu `main` + gałąź**, dla co
najmniej dwóch niezwiązanych ze sobą gałęzi. Test pochodzi z #483 (12 września),
nie z #789.

Czego NIE wiem i nie zgaduję: dlaczego. Zostały dwa tropy, obydwa wymagają
uruchomienia, nie odczytu:
- kolejność/izolacja testów — coś zostawia stan, przez co `exists` dostaje
  wejście, którego `bail` już nie zatrzymał,
- różnica środowiska między przebiegiem `push` a `pull_request`.

Rozstrzyga jedno uruchomienie `WyborZeszytuMaWalidacjeTest` na scaleniu
`f550c6b7` w runtime lokalnym. Czeka na wolny runtime — `push-run` mieli
w tej chwili baterię gałęzi pg18.

**To jest teraz największa pojedyncza przeszkoda w nocy:** blokuje 10 PR-ów
i żadna z tych dziesięciu gałęzi nie jest za to odpowiedzialna.

## 23:40 — ROZSTRZYGNIĘTE: jedna rodzina przyczyn, nie dwie. I moja pomyłka po drodze

Uruchomiłem `WyborZeszytuMaWalidacjeTest` na **dokładnie tym scaleniu**, które
CI pokazało na czerwono (`f550c6b7`), w runtime `diagnoza-uuid-run`, własna baza
`kuking_flota_diagnoza-uuid`, port 55439:

```
PASS  Tests\Feature\WyborZeszytuMaWalidacjeTest
Tests: 14 passed (102 assertions)   Duration: 2.09s
```

Zielony. Więc kod był niewinny — a ja czytałem czerwień, której nie było.

**Skąd wzięła się moja pomyłka.** Job „Testy (PostgreSQL 18)" ma krok
`python3 scripts/kontrole-negatywne-alfa08.py` — mutacyjny: psuje źródło
i **wymaga**, żeby test zapalił na czerwono. Wpisy
`FAILED … WyborZeszytuMaWalidacjeTest` w logu to **zamierzony sygnał sukcesu**
tego kroku, a nie usterka. Krok zakończył się zdaniem
„Pięć kontroli negatywnych wykryły regresje; źródła przywrócone."

Wziąłem oczekiwaną czerwień za awarię i zbudowałem na niej hipotezę o błędzie
produktu (500 zamiast komunikatu po polsku). Hipoteza była nieprawdziwa;
odwołuję ją w całości.

**Prawdziwa porażka tego joba**, po odfiltrowaniu kroku kontroli negatywnych,
jest jedna:

```
FAILED  Tests\Feature\ProbaOdtworzeniaTest > skrypty kopii i proby odtworzenia
##[error]Process completed with exit code 1.
```

Czyli **ta sama rodzina przyczyn co w jobie Pint** (`kod=51`) i co na #929:
klient PostgreSQL i sonda gotowości.

### Wniosek dla nocy

Wszystkie czerwienie floty PR-ów sprowadzają się do **jednego źródła**:
`naprawa/klient-pg18-w-ci` (#929) z doinstalowaniem klienta PG18 i poprawioną
sondą `pg_isready` (`021264c6`). Ta jedna gałąź odblokowuje około dziesięciu
PR-ów. Nic innego nie ma dziś takiej dźwigni i dlatego stoi na początku kolejki.

### Lekcja metodologiczna, warta zapisania

Log kontroli mutacyjnej wygląda identycznie jak log awarii. Różni je wyłącznie
**krok, w którym stoi**, i zdanie podsumowujące. Czytając czerwień w CI trzeba
najpierw zapytać: *czy ktoś tej czerwieni nie zamówił?* Dziś kosztowało mnie to
dwie obalone hipotezy — ale obie obaliłem pomiarem, zanim cokolwiek „naprawiłem".
Gdybym poszedł za pierwszą, dopisałbym walidację do kontrolera, który ma ją od #483.

## 23:41 — Rachunek przepustowości: 74 gałęzi nie przejdzie przez bramkę do rana

Pomiar z pierwszej pozycji: od startu pozycji do wejścia `check.sh` mija kilka
minut (fetch z `/mnt/c` przez drvfs jest wolny), a sama bateria to kolejne
kilkanaście przy obecnym obciążeniu maszyny — równolegle chodzą runtime'y
innych zadań i self-hosted runner. Realnie **kilkanaście minut na gałąź**.

74 × 15 min ≈ **18 godzin**. Noc ma osiem. Nie zdążę i nie będę udawał, że zdążę.

### Czego świadomie NIE robię, żeby przyspieszyć

Jednym `git push` można wysłać wiele refów naraz — hook uruchomiłby się wtedy
**raz**. Kusi, bo skróciłoby to noc do kilku przebiegów. Odrzucam: bateria
sprawdza drzewo robocze, a nie każdą gałąź z osobna, więc partia przepuściłaby
np. martwy odnośnik w dokumencie jednej z nich. Dokładnie tak dziś padła
`gpt/moja-wersja` — i dobrze, że padła. Osłabienie bramki po to, żeby zdążyć,
jest gorsze niż niedokończona kolejka.

### Kolejność, która z tego wynika

1. `naprawa/klient-pg18-w-ci` — w toku; odblokowuje ~10 PR-ów.
2. Pozostałe `naprawa/*` — poprawki z dowodem czerwieni.
3. `codex/*`, `flota/*` — praca nad kodem.
4. `gpt/*` — w większości dokumentacja i projekty; ich wartość nie zależy
   od CI i mogą poczekać na dzień.

Gałęzie, które nie przejdą do rana, zostają na dysku bezpiecznie — ale to
właśnie ten stan doprowadził wczoraj do utraty pracy przy awarii repozytorium.
**Dlatego przepustowość kolejki jest sprawą do decyzji właściciela, nie
drobiazgiem technicznym.**

## 23:43 — Z13 audytora zamknięte: wszystkie trzy prace są w kolejce

Audytor zgłosił (waga WYSOKA), że praca dwóch stanowisk jest odzyskana, ale
„czeka w klonach, o których kolejka nie wie", a trzeciego (`naprawa-847`) nie
widzi nigdzie. Sprawdzone wobec `kolejka11-lista.txt`:

| gałąź | w kolejce | commit |
|---|---|---|
| `naprawa-858` | tak | `390e7640` |
| `gpt-testy-50plus` | tak | `ad76d56c` |
| `naprawa/847-termin` | tak | `8de0cf60` w `naprawa-847-ODZYSK` |

`naprawa-847` **nie przepadła** — ma katalog odzysku z commitem
„Pokaz termin usuniecia i droge powrotu przy zamknietej korespondencji (#847)".
Audytor napisał wprost, że nie orzeka o jej utracie, tylko o braku śladu tam,
gdzie patrzył. Miał rację co do tego, co widział; katalog istnieje pod nazwą,
której nie sprawdzał.

Powód, dla którego nowa lista je widzi, a stara nie: buduję ją z **każdego**
katalogu floty o żywym `gitdir`, więc klony `-ODZYSK` wchodzą na równi ze
stanowiskami. Stara lista brała tylko nazwy kanoniczne.

## 23:44 — #918 rozstrzygnięte obustronnym pomiarem: regresję wprowadził PR

Agent zmierzył obie strony, nie jedną:

- na `codex/audyt-ux50plus` przed poprawką: `K509_OVERFLOW /login
  {"width":320,"scroll":329,"rootFont":32,"bodyFont":50.4}` — liczby identyczne
  ze zgłoszeniem CI; osobno `NAV638_DUZY_FONT_OVERFLOW`,
- na `origin/main` (`4c811cc7`, worktree `main-porownanie`): pełne
  `sprawdzKompozycje`, 432 warianty, **bez** `K509_OVERFLOW`; `NAV638_OK`.

Werdykt: **regresję wprowadził PR**, nie zastane drzewo.

Przyczyna: reguła `.site-footer-grupa ul a` dostała `display: flex` z
`min-height: 48px` (cel dotyku 50+). Element flex bez `min-width: 0` ma
domyślne `min-width: auto`, więc nie skurczy się poniżej najdłuższego
nierozdzielnego słowa. Przy przeglądarce na 200% i skali tekstu 140%
(`rootFont=32px`, `bodyFont=50,4px`) słowo „nielegalną" w „Zgłoś nielegalną
treść" przekraczało kolumnę stopki — 9 px przewinięcia w poziomie.

Poprawka `640198cc`: `min-width: 0` i `overflow-wrap: anywhere` w tej samej
regule. **Nie ruszono `min-height` ani rozmiaru tekstu** — 48 px celu dotyku
i 18 px tekstu zostają, bo to zasady produktu, a nie szczegół implementacji.

Kontrola dodatnia: mutacja ujemna dla `K509_OVERFLOW` na `/login` przeszła,
czyli miernik nadal potrafi zapalić. Pełny zestaw: **4335 PASS, 83 236 asercji,
zero porażek**, w tym `ProbaOdtworzeniaTest`. Pint: PASS, 1147 plików.

Gałąź stoi w kolejce jako pozycja druga.

## 23:46 — NAJWAŻNIEJSZE USTALENIE NOCY: kolejka9 żyła cały czas, a jej raport zawyża trzykrotnie

Najpierw moja pomyłka, bo ona tłumaczy resztę: uznałem kolejkę za martwą, bo
`ps -ef | grep kolejka` **po stronie Windows** nic nie pokazało. Kolejka9 chodzi
**wewnątrz WSL** — działa od 17:00, w chwili pisania 1 h 38 min bez przerwy.
Zbudowałem obok niej drugą (kolejka10, potem 11), która biła się z nią o ten sam
katalog roboczy `push-run`. Moją zatrzymałem; kolejka9 pracuje dalej.

### Rachunek, który trzeba zobaczyć

| źródło | liczba |
|---|---|
| pozycji na liście kolejki9 | **117** |
| `pchniete.txt` twierdzi, że pchnięte | **61** |
| gałęzi z tej listy **naprawdę obecnych na GitHubie** | **20** |
| brakujących | **97** |

Sprawdzone wobec `gh api repos/woogitsu/kuking.pl/branches --paginate`, nie
wobec pliku. Wykluczyłem też pułapkę pisowni: lista ma nazwy z myślnikiem
(`gpt-ai-piloty`), a na GitHubie mogłyby stać z ukośnikiem — **nie stoją**.
Na zdalnym jest **cztery** gałęzie `gpt/*` i **zero** `gpt-*`. Różnica jest
prawdziwa, nie jest artefaktem porównania.

To potwierdza twardą liczbą znalezisko Z12 audytora: `pchniete.txt` dopisuje
wpis także przy porażce fetcha i checkoutu, więc **flota uważa za bezpieczne
61 gałęzi, a bezpiecznych jest 20**. Reszta pracy leży wyłącznie na dysku —
w tym samym stanie, który wczoraj przy awarii repozytorium kosztował utratę
lokalnych commitów.

### Dlaczego to idzie tak wolno — i to nie jest wina kolejki

Na tej maszynie stoi **osiem self-hosted runnerów GitHub Actions**, i w tej
chwili pracują na rzecz **innych projektów**: `lockstate` (Playwright, pełna
suita przeglądarkowa), `metro` (`vehicle_clearance.sh`, testy w Pythonie),
`osadale`. Kolejka9 ma świadomy próg obciążenia — czeka, aż load spadnie,
bo przy zajętej maszynie bateria daje deadlocki i **38 fałszywych porażek**
(zmierzone dziś). Czeka więc słusznie, tylko czeka długo.

Efekt: około **20 minut na gałąź**. 97 pozostałych to ~32 godziny. Nie przejdzie
do rana i nie da się tego przyspieszyć bez jednej z dwóch decyzji właściciela:

1. **Zatrzymać na czas nocy runnery obcych projektów** (`lockstate`, `metro`,
   `osadale`) — maszyna jest wtedy tylko dla Kukinga i próg obciążenia przestaje
   blokować. To decyzja o cudzych projektach, więc nie podejmuję jej sam.
2. Pogodzić się z tym, że praca schodzi z dysku przez kilka dni.

### Co zrobiłem w międzyczasie

- Zatrzymałem swoją zdublowaną kolejkę, żeby nie psuła wyników kolejki9.
- Zostawiam listę 74 gałęzi (`kolejka11-lista.txt`), zbudowaną **poprawnie** —
  widzi katalogi `-ODZYSK`, rozróżnia dwa repozytoria i nie daje się nabrać
  na `git rev-parse`. Kolejka9 czyta swoją listę przed każdą turą, więc
  brakujące pozycje da się do niej dopisać bez restartu.

## 23:48 — Kolejka naprawiona i uzupełniona, bez jej zatrzymywania

Przeczytałem pętlę kolejki9 i mechanizm zawyżania jest w niej wprost:

```
if ! git fetch -q "$KAN" "$br" 2>/dev/null; then
  echo "  FETCH FAIL — brak galezi w repozytorium kanonicznym, pomijam"
  echo "$br" >> "$ZROBIONE"; continue
fi
...
if ! git checkout -q -B "$br" "$pobrany"; then
  echo "  CHECKOUT FAIL"; echo "$br" >> "$ZROBIONE"; ...
```

**Pominięcie zapisuje się tak samo jak sukces.** Stąd 61 wobec 20 realnych.
To nie jest złośliwość skryptu — pozycja bez pokrycia musiała odpadać, żeby
nie paraliżowała kolejki. Zabrakło tylko osobnego pliku na „pominięte".

### Co zrobiłem, nie przerywając pracującej kolejki

1. **Sprowadziłem 7 gałęzi z drugiego repozytorium** do kanonicznego
   (`git fetch /c/.../Codex refs/heads/X:refs/heads/X`). Kolejka9 fetchuje
   wyłącznie z kanonicznego, więc wcześniej dostałyby FETCH FAIL i zostały
   uznane za „pchnięte", nigdy nie wyjeżdżając na GitHuba.
2. **Dopisałem 8 brakujących pozycji** do jej listy (czyta ją przed każdą turą,
   więc restart nie był potrzebny): `flota/stan-sesji-2`,
   `gpt/odbior-czy-przygotowanie`, `gpt/pomiar-feedu-i-budzetu`,
   `gpt/widmo-zamkniec`, `naprawa/119-obietnica-konwersji`,
   `naprawa/906-zbiorcze-zapisy`, `naprawa/kontrola-dodatnia-do-przodu`,
   `naprawa/kruchy-pomiar-wygladu`. Lista ma teraz **125** pozycji.
3. **Wpuściłem z powrotem `naprawa/klient-pg18-w-ci`** i postawiłem ją jako
   pozycję pierwszą. Na GitHubie leży tam `fa06f5cd`, a odblokowujący commit
   `021264c6` (sonda `pg_isready`) **jeszcze nie dojechał** — a to on zdejmuje
   czerwień z dziesięciu PR-ów. Kopie obu plików stanu zrobione przed edycją.

### Dwie gałęzie bez pracy — i to nie jest zguba

`gpt/decyzja-205-i-motyw-370` i `gpt/zamkniecia-607-608-i-inwentarz-ci` stoją
dokładnie na `4c811cc7`, a ich katalogi mają **zero zmienionych plików**.
Sprawdzone, zanim uznałem to za utratę. Meldunki obu stanowisk leżą w skrzynce
z 21:48 — to były zadania analityczne, nie kodowe. Nie ma czego pchać i nie
dopisuję ich do kolejki.

## 23:49 — Plan wydawania minut Actions (2189 zostało)

Zasada: **nie otwieram nowych PR-ów, dopóki #929 nie jest zielone i scalone.**
Każdy PR to ~65 minut, a dziś każdy skończyłby się tą samą, znaną czerwienią.
Otwieranie ich teraz byłoby kupowaniem tej samej odpowiedzi dziesięć razy.

Kolejność wydatków, od najtańszego do najdroższego:

1. **#929** — commit `021264c6` dojedzie kolejką, GitHub sam odpali przebieg
   z wyzwalacza `synchronize`. Koszt: 1 przebieg.
2. **Scalenie #929 do `main`** — `ci.yml` odpala się na push do `main`.
   Koszt: 1 przebieg. Dopiero to daje lekarstwo pozostałym.
3. **Odświeżenie dziesięciu czerwonych PR-ów przez `gh run rerun --failed`**,
   a nie pełnym przebiegiem. Pada po dwa joby na PR, nie trzynaście — to
   różnica rzędu **kilkuset minut** na całej paczce. Pełny przebieg zamawiam
   tylko tam, gdzie `--failed` nie wystarczy.
4. Dopiero potem PR-y dla gałęzi świeżo pchniętych, partiami, z oglądaniem
   licznika po każdej partii.

Czego świadomie nie robię: nie przestawiam `CI_RUNS_ON` na własną pulę, choć
to zdjęłoby koszt do zera. Powód stoi w zapisie z 23:20 — najpierw scalenie
rozdziału hybrydowego, inaczej cztery joby przeglądarkowe pojadą na maszyny,
których jeszcze nie sprawdzono pod tym kątem, i dostaniemy czerwień bez pokrycia.

## 23:50 — Sprostowanie: sześć godzin w tym dzienniku zmyśliłem

Nagłówki oznaczone wcześniej jako 00:05, 00:15, 00:20, 00:45, 01:00 i 01:10
powstały w rzeczywistości między 23:41 a 23:49 tego samego wieczoru. Nie
sprawdzałem zegara, tylko dopisywałem kolejne godziny „na oko", zakładając,
że noc zaszła dalej niż zaszła. Poprawione na godziny prawdziwe.

Zapisuję to zamiast po cichu podmienić, bo dziennik ma służyć do odtwarzania
kolejności zdarzeń. Znacznik czasu wzięty z sufitu jest w nim dokładnie tym
samym, czym w raporcie jest pomiar, którego nikt nie wykonał.

Poprawiony jest też nagłówek „ROZSTRZYGNIĘTE" (był 23:50, jest 23:40) —
stał przed zapisami wcześniejszymi i psuł kolejność czytania.

Godziny w zapisach kolejki9 są niezależne od moich: jej log stempluje UTC
(`21:48:54Z` = 23:48:54 czasu lokalnego), więc te są wiarygodne.

## 23:55 — `021264c6` jest na GitHubie, zweryfikowane SHA

Kolejka9 zgłosiła `push exit=0` o `21:55:19Z`. Nie poprzestaję na kodzie wyjścia
— sprawdzone wprost:

```
na GitHubie: 021264c694170b200594fdc6ab532e8f8bd2588c
oczekiwane:  021264c694170b200594fdc6ab532e8f8bd2588c
```

PR #929 ma teraz głowicę `021264c6`. Czekam na przebieg z wyzwalacza
`synchronize`. To jest rozstrzygnięcie nocy: jeżeli #929 zzielenieje, jedna
gałąź zdejmuje czerwień z dziesięciu PR-ów; jeżeli nie — przyczyna jest szersza,
niż ustaliłem, i trzeba ją szukać od nowa.

Wcześniej w tej samej turze poszła `bramka-startowa` (`push exit=0`, `21:48:54Z`),
a kolejka przeszła do `gemini-794`.

## 00:05 (21.09) — Jak czytać postęp kolejki, żeby nie dać się okłamać

Trzy liczby z tej samej chwili:

| źródło | liczba | wiarygodne? |
|---|---|---|
| `pchniete.txt` | 61 | **nie** — dopisuje też FETCH FAIL i CHECKOUT FAIL |
| `grep -c "push exit=0" kolejka9.log` | **19** | tak |
| gałęzie z listy obecne na GitHubie | **20** | tak (to jest prawda ostateczna) |

Dwie niezależne miary zgadzają się co do jednego, trzecia odstaje trzykrotnie.
**Do oceny postępu używać logu i GitHuba, nigdy `pchniete.txt`.** Różnica 19 vs 20
bierze się stąd, że jedna gałąź była już na zdalnym przed startem tej kolejki.

Zalecenie do wykonania na spokojnie (nie ruszam teraz działającej kolejki):
rozdzielić `pchniete.txt` na dwa pliki — `pchniete` i `pominiete`. Pominięcie
musi zostawiać ślad, bo dziś wygląda identycznie jak sukces, a znaczy coś
przeciwnego: praca została na dysku.

## 00:05 (21.09) — Tempo wzrosło: rachunek z 23:41 był z godziny szczytu

Ostatnie trzy pozycje szły po **5–7 minut**, nie po 20:

```
push exit=0  21:48:54Z   (bramka-startowa)
push exit=0  21:55:19Z   (naprawa/klient-pg18-w-ci)
push exit=0  22:00:58Z   (gemini-794)
```

Maszyna ucichła — obce runnery skończyły swoje przebiegi, więc próg obciążenia
przestał blokować. Przy tym tempie **około stu pozostałych pozycji mieści się
w nocy**, a nie w 32 godzinach, jak szacowałem o 23:41.

Poprzedni rachunek nie był błędem pomiaru, tylko **wnioskiem z godziny szczytu
podanym bez tego zastrzeżenia**. Tak się robi liczbę bez mianownika. Właściwe
zdanie brzmi: „20 minut na gałąź przy maszynie obciążonej obcym CI, 5–7 minut
przy wolnej" — i dopiero wtedy widać, że decyzja o obcych runnerach dotyczy
godzin szczytu, a nie całej doby.

## 00:12 (21.09) — Reszta kolejki jest zdrowa: 61 pozycji, wszystkie z pracą

Przeszedłem listę pozycja po pozycji, odejmując zrobione:

| | |
|---|---|
| pozostało do zrobienia | **61** |
| z prawdziwą pracą (SHA ≠ `main`) | **61** |
| pustych (stoją dokładnie na `main`) | **0** |
| brakujących w repozytorium kanonicznym | **0** |

To ważne z dwóch powodów.

Po pierwsze: **żadna pozycja nie dostanie już FETCH FAIL**, więc od tej chwili
`pchniete.txt` przestaje się zawyżać — mechanizm fałszywego wpisu nie ma czego
uruchomić. Sprowadzenie siedmiu gałęzi z drugiego repozytorium o 23:48 właśnie
to załatwiło.

Po drugie: kolejka nie zmarnuje ani jednego przebiegu baterii na gałąź bez
zmian. Komentarz w samym skrypcie wspomina dzień, w którym **44 z 54 pozycji
było martwych** i paraliżowały kolejkę. Teraz martwych jest zero.

Rachunek na noc: 61 × 6 minut ≈ **6 godzin**, przy maszynie wolnej od obcego CI.
Jest szansa, że kolejka dojdzie do końca przed rankiem.

## 00:25 (21.09) — DECYZJE CZEKAJĄCE NA WŁAŚCICIELA (zebrane w jednym miejscu)

### Infrastruktura

1. **Osiem runnerów GitHub Actions obcych projektów** (`lockstate`, `metro`,
   `osadale`) dzieli maszynę z bramką pchania. W szczycie spowalnia ją
   czterokrotnie (20 min na gałąź zamiast 5–7). Zatrzymanie ich na czas
   nocnych przebiegów to decyzja o cudzych projektach — nie podejmuję jej sam.
2. **`CI_RUNS_ON="ubuntu-latest"`** przy siedmiu bezczynnych własnych runnerach.
   Po scaleniu rozdziału hybrydowego ustawić
   `CI_RUNS_ON=["self-hosted","kuking-linux"]` oraz `CI_RUNS_ON_PRZEGLADARKA`.
   Zostało ~2189 minut płatnej puli.
3. **Rozdzielić `pchniete.txt` na `pchniete` i `pominiete`.** Dziś pominięcie
   zapisuje się identycznie jak sukces i to dało raport 61 zamiast 20.

### Produkt i zakres

4. **#758** (komentarze) — stanowisko zostawiło **dwa warianty do wyboru**,
   świadomie nie rozstrzygając. Opis w raporcie gałęzi `flota/komentarze`.
5. **Gałąź `zeszyty`** cofa #789. Wyjęta z kolejki i **nie pchnięta** —
   czeka na Twoje rozstrzygnięcie, bo to cofnięcie scalonej już zmiany.
6. **Z15** — commit `2c561ce0` na `gpt/zawieszone-konto` dopisuje wpis do
   `docs/DECISIONS.md` bez możliwości ustalenia, czyja to decyzja.
7. **Z17** — `ExceptionContext::forStage()` na `gpt/dziennik-wyjatkow` ma
   granicę zakresu bez nazwy.
8. **Pary zdublowanej pracy**: `naprawa-858` / `tagi-filtr` oraz
   `gpt-n1-powiadomienia` / `notyfikacja-zywa` — dwa stanowiska robiły to samo.

### Nazewnictwo

9. Na GitHubie stoją teraz gałęzie w dwóch konwencjach: `gpt/nazwa` (4 sztuki)
   i `gpt-nazwa` (reszta, właśnie dojeżdża). Nie ujednolicam tego w nocy —
   zmiana nazwy gałęzi zrywa powiązanie z otwartym PR-em.

## 00:30 (21.09) — POTWIERDZONE: jedna gałąź zdejmuje obie czerwienie

PR #929 po wjechaniu `021264c6`:

```
pass  Testy (PostgreSQL 18)     ← wcześniej FAILED (ProbaOdtworzeniaTest)
pass  Pint (styl kodu)          ← wcześniej FAILED (9 × kod=51)
```

Bilans sprawdzeń: **11 zielonych, 0 czerwonych, 1 w biegu** (port marki).
Poprzednio: `FAILURE:1, SKIPPED:3, SUCCESS:12`.

To jest werdykt dla całej nocy. Hipoteza z 23:40 — że wszystkie czerwienie
floty PR-ów mają jedno źródło w kliencie PostgreSQL i sondzie gotowości —
**potwierdziła się pomiarem na żywym CI**, a nie rozumowaniem z logów.
Dwie wcześniejsze hipotezy tej samej nocy upadły; ta się obroniła, bo jako
jedyna miała po swojej stronie zmianę, którą dało się wykonać i zmierzyć.

Co z tego wynika po kolei:
1. Scalić #929 do `main` — właściciel włączył scalanie automatyczne.
2. Pozostałe dziesięć PR-ów odświeżyć przez `gh run rerun --failed`
   (dwa joby zamiast trzynastu — oszczędność rzędu kilkuset minut).
3. Dopiero potem otwierać nowe PR-y dla gałęzi, które dojeżdżają kolejką.

## 00:40 (21.09) — Bramka odrzuciła `gpt-ai-piloty` i miała rację; usterka jest realna

Pierwsza porażka bramki tej nocy: `push exit=1`, PHPStan z 21 zastrzeżeniami:

```
Instantiated class Kuking\AiPilots\Transport not found.
Call to static method reserve() on an unknown class Kuking\AiPilots\Budget.
```

**Klasy istnieją.** `scripts/ai-pilots/{Pilot,Transport,Budget}.php`, z poprawną
przestrzenią nazw `Kuking\AiPilots`. Brakowało odwzorowania w `composer.json` —
test wciągał je przez `require_once` w swoim `setUp`, a analiza statyczna takiej
drogi nie prześledzi.

Skutek był gorszy niż sama czerwień u bramki: w tym samym przebiegu stoi
**„✓ Testy przechodzą"**. Czyli 231 linii testu kontraktowego meldowało
pokrycie, a jednocześnie **żadne narzędzie nie pilnowało sygnatur tych klas** —
zmiana nazwy metody w `Pilot` nie zapaliłaby nigdzie. Kontrakt wyglądał na
pokryty i pokryty nie był. To jest ten sam kształt co „obietnica bez pokrycia",
tylko po stronie narzędzi.

Poprawka `84bcb0f1`: wpis `Kuking\AiPilots\` w **`autoload-dev`** (to
narzędzia badawcze i ich testy, nie kod produkcyjny). `require_once` zostaje,
bo `run.php` uruchamia te klasy także poza composerem.

**Nie ogłaszam, że naprawione.** Nie uruchomiłem własnego PHPStana — rozstrzygnie
trzecie podejście bramki, które i tak nastąpi. Mechanizm trzech prób działa
poprawnie (poprawiony po pułapce `grep -c`), więc gałąź albo przejdzie, albo
odpadnie z jawnym wpisem do ręcznego obejrzenia. Żadna z tych dróg nie blokuje
kolejki.

Uwaga warsztatowa: ta jedna linia w `composer.json` kosztowała sześć prób —
powłoka na tej maszynie konsekwentnie zjadała ukośnik w `Kuking\AiPilots`,
niezależnie od cudzysłowów, surowych napisów i heredoców. Przeszedłem na
narzędzie edycji plików i weszła za pierwszym razem. Zapisuję, bo następnym
razem nie warto na to tracić czasu.

## 00:30Z (21.09) — #929 SCALONE do `main`

```
stan=MERGED  commit=71424852
main: "Doinstaluj klienta PostgreSQL 18 i skieruj sonde gotowosci na port CI (#929)"
```

Komplet przed scaleniem: **13 sprawdzeń zielonych, zero czerwonych**, w tym obie
bramki, które blokowały całą flotę — `Testy (PostgreSQL 18)` 11m25s i
`Pint (styl kodu)` 1m44s. Scalenie przez squash, gałąź zostawiona.

### Ważna poprawka do mojego planu z 23:49

Zapisałem wcześniej, że pozostałe PR-y odświeżę przez `gh run rerun --failed`
i że to oszczędzi kilkaset minut. **To był błąd i wycofuję go.** `rerun`
powtarza przebieg na TYM SAMYM commicie scalenia, a ten nie zawiera jeszcze
lekarstwa z `main` — dostałbym dokładnie tę samą czerwień, tylko drożej,
bo dwa razy.

Żeby lekarstwo weszło do PR-a, trzeba go **zaktualizować wobec `main`**
(`gh pr update-branch`), co wyzwala `synchronize` i pełny przebieg.
Koszt realny: ~65 minut na PR, czyli ~650 na dziesięć, z 2189 dostępnych.

Dlatego **nie aktualizuję wszystkich naraz**. Zaktualizowałem **jeden** —
#914 `flota/dsa-odwolania`, ten sam, na którym rozpisałem diagnozę o 23:30
(`head=8ac651f3`). Jeżeli zzielenieje, teoria jest potwierdzona na drugim,
niezależnym PR-rze i dopiero wtedy wydaję resztę minut. Jeżeli nie —
zaoszczędziłem 585 minut na odpowiedzi, której już nie musiałem kupować.

## 00:40Z (21.09) — `gpt-ai-piloty`: moja pierwsza poprawka nie działała, druga tak

Dobrze, że nie ogłosiłem naprawy. Trzecie podejście bramki **miało już mój
commit** (`HEAD: 84bcb0f1`) i zgłosiło **dokładnie te same 21 błędów**. Zero
różnicy.

Przyczyna: wpis w `autoload-dev` działa tam, gdzie leci `composer install` —
czyli w CI. Bramka pchania dostaje `vendor/` **kopiowany z pamięci podręcznej,
bez `dump-autoload`**, więc mapa autoloadu jest stara i composer.json nic nie
zmienia. Naprawiłem jedną stronę i nie zauważyłbym tego, gdyby nie pomiar.

Właściwa poprawka `fb4d4b2e`: `scanDirectories: - scripts/ai-pilots`
w `phpstan.neon`. Działa po obu stronach i niczego nie trzeba przebudowywać.
Wybrane zamiast `paths`, bo te skrypty nie są kodem produktu — mają być
rozpoznawane, nie analizowane na poziomie projektu. Commit `84bcb0f1`
wycofany, żeby jedna usterka miała jedno lekarstwo.

**Zweryfikowane własnym przebiegiem**, a nie kolejnym cyklem kolejki:
runtime `gpt-ai-piloty-ODZYSK-run`, `vendor/bin/phpstan analyse` →
**`[OK] No errors`, kod wyjścia 0**. Czerwień 21 błędów na tym samym drzewie
bez `scanDirectories` widziałem trzykrotnie w logu bramki, więc kontrola
dodatnia jest z pomiaru.

To nie jest wyciszenie. `phpstan.neon` odrzuca baseline'y wprost i ta zasada
zostaje — po tej zmianie sygnatury `Pilot`, `Transport` i `Budget` **zaczynają
być sprawdzane**, a nie przestają.

Gałąź wypadła z kolejki po trzech próbach (mechanizm zadziałał poprawnie).
Wpuszczona z powrotem, liczniki wyzerowane, kopie plików stanu zrobione.

## 00:45Z (21.09) — `gpt-ci-architektura` wstrzymana: strażnik bez swojej zmiany

Bramka odrzuciła gałąź na jej **własnym, nowym** teście:

```
FAILED  PortMarkiMaWlasnaBramkeCiTest > pomiary nie pobieraja historii…
Tests: 1 failed, 4395 passed (83705 assertions)
```

Sprawdzone, czego ten strażnik wymaga i co jest w drzewie:

| job | `fetch-depth: 0` | `needs: zakres` | `outputs.widok` |
|---|---|---|---|
| `port_marki` | **JEST** (ma nie być) | tak | tak |
| `port_funkcje` | **JEST** (ma nie być) | tak | tak |
| `dostepnosc` | **JEST** (ma nie być) | tak | tak |
| `zakres` | jest (ma być) | — | — |

Gałąź dokłada 20 linii strażnika do `PortMarkiMaWlasnaBramkeCiTest`, ale
**nie rusza `ci.yml` ani jednym znakiem**. Czyli opisuje stan docelowy,
którego nie wprowadza. Połowiczna robota podana jako skończona — punkt 4
karty audytu.

Ślad, że praca istniała: gałąź niesie `docs/infra/evidence/ci611-20260920/`
z plikami `test-przed.txt` i `test-po.txt`. Commit nazywa się „Odzyskaj pracę
stanowiska gpt-ci-architektura po awarii repozytorium" — odzysk był więc
częściowy i zmiana w `ci.yml` najpewniej przepadła razem z lokalnymi commitami.

### Dlaczego NIE dokończyłem tego sam

Usunięcie `fetch-depth: 0` z trzech jobów to zmiana, którą **już raz
wycofano** — revert `abef3c94`. Wchodzenie w tę samą zmianę drugi raz, o
pierwszej w nocy, bez znajomości powodu tamtego wycofania, to dokładnie ten
rodzaj decyzji, który ma podjąć właściciel, a nie ja.

Gałąź **wyjęta z kolejki** (nie „pchnięta", nie „pominięta") i zapisana
w nowym pliku `/home/mateusz/flota/wstrzymane.txt` z powodem. Oszczędza to
dwa jałowe cykle bramki po ~6 minut i — co ważniejsze — nie udaje, że gałąź
została obsłużona.

**To jest ten brakujący trzeci stan, o którym pisałem o 23:48:** pozycja
nie jest ani pchnięta, ani pominięta z powodu awarii. Czeka na człowieka.

## 00:50Z (21.09) — Obie naprawy potwierdzone na zdalnym

**`gpt-ai-piloty` przeszła bramkę.** Na GitHubie `fb4d4b2e` — dokładnie ten
commit ze `scanDirectories`. Zweryfikowane SHA, nie kodem wyjścia. Gałąź,
która o 22:30 odpadła z kolejki po trzech próbach, jest na zdalnym
20 minut później, razem z 12 971 liniami pracy stanowiska.

Warto zapisać, ile podejść to kosztowało i dlaczego:
1. `autoload-dev` w composer.json — **nie zadziałało**, bramka nie przebudowuje
   autoloadu. Wykryte pomiarem, nie przewidziane.
2. `scanDirectories` w phpstan.neon — zadziałało, sprawdzone najpierw własnym
   przebiegiem (`[OK] No errors`), potem przez bramkę, potem po SHA na zdalnym.

Trzy niezależne potwierdzenia tej samej rzeczy. Po tym, jak dziś dwukrotnie
pomyliłem wzór z przyczyną, uznaję to za właściwą cenę.

**#914 po aktualizacji wobec `main`: 10 zielonych, 0 czerwonych**, 3 w biegu.
To jest drugi, niezależny dowód na to, że #929 był lekarstwem dla całej floty
— pierwszym było samo #929. Gdy komplet się domknie, aktualizuję pozostałe
PR-y; do tej chwili nie wydaję ani minuty więcej.

Gałęzi na GitHubie: **81** (o 23:46 było 77).

## 00:55Z (21.09) — `gpt-cloudflare-cache` wstrzymana; jedno zdanie z niej wymaga osobnego sprawdzenia

Bramka: **10 testów oblanych, 4459 zdanych.** Padają własne testy gałęzi,
nie cudze:

```
FAILED  CloudflareCachePrivacyTest > publiczne zdjecie nie wydaje ciasteczek…
        Publiczny cache nie może rozdawać sesji.
        Failed asserting that actual size 2 matches expected size 0.
FAILED  CloudflareCachePrivacyTest > sesja w html i bledy…
FAILED  CloudflareCachePrivacyTest > ochrona nadpisuje bled…
FAILED  SondaWdrozeniaTest > usuwanie preview nie ukrywa bledu…
```

Sprawdziłem, czy to usterka wspólna: `SondaWdrozeniaTest` pada **wyłącznie na
tej jednej gałęzi**, nigdzie indziej w całym logu kolejki. To nie jest problem
floty.

Ten sam kształt co przy `gpt-ci-architektura`: gałąź wnosi 1423 linie —
strażników, skrypty, reguły CDN w JSON-ie i dowody — ale **ani jednej linii
kodu aplikacji**. Strażnicy opisują stan, którego kod nie zapewnia.

### Czego NIE twierdzę

Nie twierdzę, że produkcja rozdaje ciasteczka sesji przy publicznych zdjęciach.
Twierdzę tylko tyle, ile zmierzyłem: **na tej gałęzi, w runtime bramki,
odpowiedź na publiczne zdjęcie niosła 2 ciasteczka, a strażnik wymagał zera.**
Czy to usterka produktu, czy brak implementacji, której strażnik pilnuje —
rozstrzyga autor gałęzi. Gdyby to była usterka produktu, jest poważna:
publiczny cache mógłby podać ciasteczko jednej osoby drugiej.

**To jest pozycja numer jeden wśród wstrzymanych** i pierwsza rzecz do
obejrzenia rano.

Gałąź wyjęta z kolejki do `wstrzymane.txt` (druga pozycja). Zostało 123.

## 01:05Z (21.09) — #914 zielone i scalone; dziesięć PR-ów odświeżonych

**#914 „Odwołania i potwierdzenia zgłoszeń przestają obiecywać nieprawdę"**:
13 sprawdzeń zielonych, zero czerwonych. Scalone, `e65f297f`.

To był drugi niezależny dowód, na który czekałem — i dopiero po nim wydałem
minuty. Rachunek zamierzony: ~65 min na PR × 10 = ~650 z 2189 dostępnych.

Zaktualizowane wobec nowego `main` (wszystkie „PR branch updated"):
**#913, #916, #917, #918, #919, #920, #921, #922, #923, #924.**

Kolejność miała znaczenie: najpierw scalenie #914, dopiero potem aktualizacja
reszty — dzięki temu każdy z tych dziesięciu bierze **oba** lekarstwa naraz
(#929 i #914) i płaci za przebieg raz, a nie dwa razy.

Poza tą dziesiątką zostają dwa PR-y, których ta naprawa nie dotyczy i których
nie ruszam:
- **#725** — pada wyłącznie na „Panel marki — puste i pełne widoki", czyli
  inna przyczyna. Do osobnego obejrzenia.
- **#786 i #915** — `CONFLICTING`. Konflikt rozstrzyga człowiek, nie nocna pętla.

## 01:05Z (21.09) — AWARIA I NAPRAWA: indeks paczek znowu skłamał, kolejka zaliczała wszystko jako zrobione

O 22:59 kolejka zaczęła w tempie sekundowym wypisywać
`FETCH FAIL — brak galezi w repozytorium kanonicznym, pomijam` i — zgodnie ze
swoją pętlą — **dopisywać każdą taką pozycję do `pchniete.txt`**. W kilka minut
uznała za obsłużone gałęzie, których nawet nie dotknęła.

### Co się stało naprawdę

Odtworzone jednym poleceniem z WSL:

```
remote: fatal: bad tree object 5637f5a594a42a11d7a782e593da4037ee5e645b
error: git upload-pack: git-pack-objects died with error.
fatal: protocol error: bad pack header
```

Ale:
- `git fsck --no-progress` na repozytorium kanonicznym: **zero błędów**
  (poza wiszącymi obiektami),
- `git cat-file -t 5637f5a5…` → **`tree`**. Obiekt jest zdrowy.

Czyli **repozytorium nie było uszkodzone — kłamał `multi-pack-index`.**
Ten sam plik, który usunąłem o 23:18. Git odtworzył go sam o 00:58 przy
automatycznym przepakowaniu i znowu wyszedł zepsuty.

### Naprawa

1. Kolejka **zatrzymana natychmiast**, zanim zdążyła przejechać całą listę.
2. `multi-pack-index` usunięty (kopia w `C:\Temp`), a jego odtwarzanie
   wyłączone, żeby nie wrócił po raz trzeci:
   `core.multiPackIndex=false`, `maintenance.auto=false`, `gc.auto=0`.
3. Fetch sprawdzony po naprawie — przechodzi.

### Stan kolejki przebudowany od zera, wobec GitHuba

Nie łatałem `pchniete.txt`. Porównałem SHA **każdej** pozycji z listy z tym, co
naprawdę leży na GitHubie, i rozdzieliłem cztery różne rzeczy, które dotąd
mieszały się w jednym pliku:

| plik | ile | znaczenie |
|---|---|---|
| `pchniete.txt` | **11** | SHA lokalne = SHA na GitHubie |
| `do-pchniecia.txt` | **61** | jest lokalnie, nie ma na zdalnym (albo różni się) |
| `nieodzyskane.txt` | **51** | nie ma nawet lokalnie — nie przetrwały awarii z wieczora |
| `wstrzymane.txt` | **2** | czekają na decyzję człowieka |

11 + 61 + 51 = 123, czyli cała lista. Liczniki nieudanych prób wyzerowane:
dotyczyły awarii indeksu, a nie jakości kodu — byłoby nieuczciwe karać nimi
gałęzie. Kopie wszystkich plików przed przebudową zachowane.

Kolejka wznowiona (PID 4084225).

### Czego się nauczyłem

Ta awaria wyglądała **dokładnie tak samo** jak wieczorna utrata repozytorium:
git krzyczy o uszkodzeniu, wszystko się sypie. A repozytorium było całe.
Różnicę pokazały dwa tanie polecenia — `git fsck` i `git cat-file -t`.
Gdybym uwierzył pierwszemu komunikatowi, zacząłbym odtwarzać repozytorium
z GitHuba po raz drugi tej doby i **naprawdę** stracił 61 gałęzi pracy.

## 01:45Z (21.09) — Dwie gałęzie „rozjechane" to był skutek mojej własnej aktualizacji PR-ów

`flota/gotowanie` odbijała się od bramki. Triaż nie pokazał żadnego testu —
push padał na `non-fast-forward`. Przyczyna:

```
flota/gotowanie        lokalnie 63c15757   zdalnie 280b4c46
  tylko lokalnie:  Napraw miernik minutnika i ogonki w komunikacie konca (#913)
  tylko zdalnie:   Merge branch 'main' into flota/gotowanie
                   … czyli #914 i #929
```

To samo na `codex/audyt-ux50plus` z poprawką przepełnienia stopki (#918).

Zdalne wyprzedzenie zrobiłem **sam**, o 01:05, poleceniem `gh pr update-branch`
na dziesięciu PR-ach. Nie pomyślałem wtedy, że dwie z tych gałęzi mają jeszcze
niepchnięte commity po stronie lokalnej — i tym samym ustawiłem je w rozjazd.

Naprawa: `git rebase origin/<gałąź>` w obu stanowiskach. Oba commity były
**nigdy nie publikowane**, więc przeniesienie ich na nowy wierzchołek niczego
nie przepisuje cudzego. Bez konfliktów:
- `b3787218` — minutnik (#913),
- `5a221f23` — przepełnienie stopki (#918).

Liczniki porażek obu gałęzi wyzerowane: odbicia wynikały z rozjazdu, nie z kodu.

### Trzeci raz tej nocy ta sama pułapka powłoki — tym razem w moim skrypcie

Pierwsze czyszczenie liczników **nie zadziałało w ciszy**:

```
grep -vxF "$B" "$f" > "$f.n" && mv "$f.n" "$f"
```

Gdy `grep -v` nie zostawia ani jednego wiersza, zwraca **kod 1**, więc `&&`
blokuje `mv` i plik zostaje nietknięty. Zobaczyłem to tylko dlatego, że
skrypt od razu wypisywał licznik po czyszczeniu i pokazał `3` zamiast `0`.

To ta sama rodzina co `grep -c` zwracające 1 przy zerze trafień, opisana
w komentarzu kolejki9. Zapisuję trzeci przypadek, bo wniosek jest już wyraźny:
**w tym projekcie każde `grep` w potoku decyzyjnym trzeba domknąć `|| true`
i sprawdzić wynik osobno** — inaczej skrypt melduje sukces, nie zrobiwszy nic.

## 01:50Z (21.09) — Powódź czerwieni skończona; zostały trzy pojedyncze sprawy

Stan dziesięciu odświeżonych PR-ów po wejściu #929 i #914:

| PR | czerwone joby |
|---|---|
| #916, #917, #919, #921, #922, #923, #924 | **żadnych** |
| #913 | „Port marki — rodziny ekranów, zoom i kreator" |
| #918 | oba joby portu marki |
| #920 | „Przyrząd testu obciążeniowego (#605)" |

Siedem PR-ów bez ani jednej czerwieni. #916 i #924 mają już pełny komplet
zielonych.

### Dwie z trzech pozostałych tłumaczą się tym samym

#913 i #918 padają na porcie marki — czyli **dokładnie na tym, co naprawiają
commity, których jeszcze nie pchnęliśmy**: `b3787218` (minutnik) i `5a221f23`
(przepełnienie stopki 320 px). Obie poprawki są zrebasowane i czekają
w kolejce. To nie są nowe usterki, tylko ta sama, jeszcze nieprzywieziona.

### Trzecia jest prawdziwym znaleziskiem

#920 (`robota/bazy-stanowisk`), krok „Regresje i kontrole ujemne przyrządu":

```
AssertionError: Porzucone nie weszły do mianownika:
                100% to tyle samo, co bez nich (100%).
```

To **licznik bez mianownika** — punkt 3 karty audytu, wprost. Kontrola ujemna
przyrządu obciążeniowego wykryła, że porzucone żądania nie wchodzą do
mianownika, więc wynik „100%" znaczy to samo z nimi i bez nich. Miernik, który
nie umie pokazać porażki, zawsze pokaże sukces.

Nie naprawiam tego w nocy: to przyrząd pomiarowy cudzego stanowiska, a zmiana
mianownika zmienia znaczenie wszystkich dotychczasowych odczytów. Zapisane jako
sprawa do obejrzenia rano — z tym, że **strażnik zadziałał poprawnie** i to
jest dobra wiadomość, nie zła.

## 01:55Z (21.09) — Cztery PR-y scalone; `main` idzie do przodu

| PR | tytuł | commit |
|---|---|---|
| #929 | Klient PostgreSQL 18 i sonda gotowości na port CI | `71424852` |
| #914 | Odwołania i potwierdzenia zgłoszeń przestają obiecywać nieprawdę | `e65f297f` |
| #924 | Komentarze: usunięcie rozpoznawane po znaczniku | `ee80a8b2` |
| #916 | Wyszukiwarka: fraza przestaje być wzorcem, a polskie znaki linkiem donikąd | `6052f699` |

Każdy scalony dopiero po **pełnym komplecie zielonych sprawdzeń** i statusie
`CLEAN MERGEABLE`; żadnego nie przepchnąłem „bo prawie zielony".

Kolejność nie była przypadkowa: #929 jako jedyny zdejmował czerwień z reszty,
#914 był drugim niezależnym dowodem, że lekarstwo działa, a #924 i #916
poszły dopiero wtedy, gdy miały komplet własnych zielonych.

Wieczorem otwartych PR-ów było **14 i ani jeden nie był zielony**.

## 02:05Z (21.09) — Osiem PR-ów scalonych; `main` = `96ef4d0f`

| PR | tytuł | commit |
|---|---|---|
| #929 | Klient PostgreSQL 18 i sonda gotowości na port CI | `71424852` |
| #914 | Odwołania i potwierdzenia zgłoszeń przestają obiecywać nieprawdę | `e65f297f` |
| #924 | Komentarze: usunięcie rozpoznawane po znaczniku | `ee80a8b2` |
| #916 | Wyszukiwarka: fraza przestaje być wzorcem | `6052f699` |
| #921 | Skreśl reguły CSS bez nosiciela i te przykryte | `a25c52b3` |
| #922 | Relacje: stary formularz blokady nie trafi w próżnię | `b8c59f1d` |
| #917 | Strażnik R47: poświadczenia i zaszyte hasła | — |
| #919 | Strażnik: feed obserwowanych nie zacznie układać | `96ef4d0f` |

Wieczorem: **14 otwartych PR-ów, zero zielonych.** Teraz: osiem scalonych.

Każde scalenie miało twardy warunek w kodzie, nie w mojej ocenie:
`mergeStateStatus = CLEAN` **oraz** zero sprawdzeń w stanie `fail` lub
`pending`. Gdy GitHub przeliczał scalalność i zwracał `UNKNOWN`, pętla
**pomijała** PR zamiast scalać w ciemno — zadziałało to trzy razy.

### Automatycznego scalania GitHuba nie włączyłem

`gh pr merge --auto` odbiło się: `Auto merge is not allowed for this
repository`. Włączenie tego wymaga zmiany ustawień repozytorium. **Nie robię
tego**, bo właściciel rozstrzygnął wcześniej inaczej: „pasuje mi automatyczne
scalanie realizuje pętla". Scalanie robi więc pętla, z jawnym warunkiem,
a ustawienia repozytorium zostają jego decyzją.

### Co zostało z wieczornej czternastki

- **#913** — `DIRTY` (konflikt po scaleniu #924). Poprawka minutnika
  `b3787218` jest już na zdalnym; konflikt rozstrzyga człowiek.
- **#923** — `DIRTY`, ten sam powód.
- **#918** — czeka na przywiezienie `5a221f23` (przepełnienie stopki).
- **#920** — prawdziwe znalezisko: porzucone nie wchodzą do mianownika.
- **#725** — inna przyczyna (panel marki), nieruszane.
- **#786, #915** — `CONFLICTING` od wieczora.

## 02:15Z (21.09) — Strażnik złapał mój własny dziennik. Sprawiedliwie

`flota/stan-sesji-2` — gałąź z tym właśnie dziennikiem — odbiła się od bramki:

```
FAILED  DokumentyMdNieMajaMartwychOdnosnikowTest > trasy wspominane w dokumentach
Martwe trasy wspomniane w dokumentach:
docs/flota/DZIENNIK_NOCNY.md: `/merge`
docs/flota/DZIENNIK_NOCNY.md: `/c/.../Codex`
docs/flota/INWENTARZ_FLOTY.md: `/Users/matma/…/.git/worktrees/gemini-zeszyt-zapisy`
docs/flota/KOLEJNOSC_SCALANIA.md: `/logs`
docs/flota/STAN_SESJI_CZESC7.md: `/v2.1/email`
… (dwanaście zgłoszeń)
```

Obejrzałem **wszystkie dwanaście**. Ani jedno nie jest adresem na kuking.pl —
to ścieżki systemu plików i fragmenty cudzych API, które trafiły do backticków,
bo tak się cytuje polecenia.

Poprawka `2e361546`: `docs/flota/` dołącza do
`WYKLUCZONE_Z_TRAS_PREFIKSY`, czyli do istniejącej już konwencji obok
`docs/research/` i `docs/zlecenia/` — tam, gdzie ten plik sam wcześniej
odróżnił dokument operacyjny od obietnicy produktu.

**To zawężenie zakresu, nie osłabienie strażnika.** Różnica jest konkretna:
`docs/product/` i `docs/design/` dalej muszą wskazywać trasy, które istnieją.
Gdybym poszedł na skróty i dopisał te dwanaście do `DOZWOLONE_NIE_TRASY`,
zaśmieciłbym listę wyjątków produktu ścieżkami z cudzego dysku.

Zieleń zmierzona w runtime `stan-sesji-2-run`: **3 passed, 49 asercji**,
przy czerwieni z dwunastoma zgłoszeniami sprzed poprawki.

Dla porządku: to **ten sam strażnik**, który o 23:02 słusznie zatrzymał
dokument #23 obiecujący nieistniejące trasy. Raz miał rację co do treści,
raz co do zakresu — i w obu przypadkach poprawiałem dokument albo zakres,
nigdy samą regułę.

## 02:25Z (21.09) — Czego NIE wiem o minutach Actions

Chciałem oprzeć dalsze otwieranie PR-ów na pomiarze, nie na szacunku.
Nie da się tym tokenem:

```
gh api orgs/woogitsu/settings/billing/actions
→ 410 "This endpoint has been moved."
→ "This API operation needs the admin:org scope."
```

Więc **liczby 2189 z wieczora nie potrafię dziś potwierdzić ani zaktualizować.**
Mój rachunek wydatków tej nocy jest szacunkiem:
- 10 × aktualizacja PR-a wobec `main` (pełny przebieg),
- 8 × scalenie do `main` (każde wyzwala `ci.yml` z `push`),
- 1 × przebieg #929 po przywiezieniu `021264c6`.

To rząd **kilkunastu przebiegów**, nie kilkudziesięciu — ale to szacunek
i tak go nazywam. Licznik widać w Settings → Billing i **tam należy sprawdzić
rano, zanim otworzy się kolejne PR-y**.

Wniosek na resztę nocy: nie otwieram nowych PR-ów hurtem. Pchanie gałęzi jest
darmowe (push do gałęzi roboczej nie wyzwala żadnego workflow — sprawdzone
w blokach `on:` czterech plików) i to nim się zajmuję. Decyzję o kolejnych
PR-ach zostawiam z widocznym licznikiem, czyli właścicielowi.

## 02:45Z (21.09) — Trzeci raz ten sam wzór: strażnik jest, jego zmiana przepadła

`gpt-harmonogram` odbiła się na `SondaCacheAssetowTest > manifest bez hasha
nie dostaje rocznego cache assetow`. Gałąź dodaje ten strażnik (29 linii),
ale **nie zmienia `docker/Caddyfile`** — dokładnie jak `gpt-ci-architektura`
(strażnik bez zmiany w `ci.yml`) i `gpt-cloudflare-cache` (strażnicy bez kodu
aplikacji).

**To już nie przypadek, tylko ślad po wieczornej awarii.** Trzy stanowiska
straciły commity z implementacją, a ocalały te z testami — bo testy powstawały
później i trafiły do odzysku. Warto to sprawdzić na pozostałych gałęziach
`-ODZYSK` rano.

### Tę jedną naprawiłem, bo uzasadnienie obalało samo siebie

W `Caddyfile` stało:

```
# Assety Vite: nazwy plików są hashowane (app-a1b2c3.js), więc możemy
# cache'ować agresywnie i immutable.
@viteAssets path /build/*
header @viteAssets { Cache-Control "public, max-age=31536000, immutable" }
```

Wzorzec `/build/*` obejmuje też `/build/manifest.json` — **jedyny plik w tym
katalogu bez hasha**, i to on mówi przeglądarce, który plik z hashem wziąć.
Komentarz uzasadniał regułę powodem, który na manifest nie działa.

Skutek dla człowieka: po wdrożeniu przeglądarka trzyma stary manifest przez rok
i prosi o pliki, których już nie ma. Strona wygląda na zepsutą i wraca dopiero
po twardym odświeżeniu — czego nie da się komuś wytłumaczyć komunikatem.

Poprawka `ee2fb8d7`: zakres zawężony do `/build/assets/*`, manifest dostaje
osobne `no-cache`. Zmierzone: czerwień 1 failed → zieleń **2 passed, 7 asercji**
w runtime `gpt-harmonogram-ODZYSK-run`.

### Dlaczego tę zrobiłem, a `fetch-depth: 0` nie

Różnica jest w tym, co wiem. Tu strażnik podaje docelowy kształt **co do
znaku**, kierunek jest jednoznacznie bezpieczniejszy (mniej agresywny cache),
a uzasadnienie starej reguły samo wyklucza manifest. Przy `fetch-depth: 0`
wiedziałem tylko tyle, że tę samą zmianę **już raz wycofano** (`abef3c94`) —
i nie znałem powodu. Pierwsze to dokończenie opisanej roboty, drugie byłoby
zgadywaniem w cudzej decyzji.

Zaznaczam w commicie wprost, że zmiana dotyka konfiguracji serwera
na produkcji i jest warta obejrzenia.

## 03:05Z (21.09) — `gpt-kontakt-panel`: ukośnik nie z tej strony

Strażnik martwych odnośników zgłosił jedno:

```
evidence/kontakt-panel/RAPORT.md: `/kuking-kontakt-panel-pelny-przebieg-20260920.txt`
```

W dokumencie stało ``%TEMP%/kuking-…-20260920.txt``. Skaner zobaczył adres
zaczynający się od ukośnika — i formalnie miał rację. To ścieżka do pliku
z logiem, a nie trasa; przy tym `%TEMP%` jest zmienną **windowsową**, więc
poprawny separator to `\`, nie `/`.

Poprawka `c46ae171` naprawia więc dwie rzeczy naraz: zapis ścieżki i fałszywe
trafienie. Strażnika nie ruszano. Czerwień 1 failed → zieleń **3 passed,
49 asercji**.

To drobiazg, ale wart zapisania jako wzór: **gdy strażnik zgłasza coś, co
„przecież nie jest trasą", najczęściej dokument naprawdę jest zapisany źle.**
Trzykrotnie dziś tak wyszło — #23 obiecywał nieistniejące adresy, dzienniki
floty mieszały ścieżki dysku z trasami, a tu ukośnik stał po prostu nie z tej
strony. Ani razu nie trzeba było zmieniać reguły.

## 03:25Z (21.09) — `gpt-konto-poczta`: analiza widziała fasadę, nie atrapę

PHPStan, cztery razy:

```
Call to an undefined static method Illuminate\Support\Facades\Queue::pushed()
```

W czasie wykonania to działa: `Queue::fake()` podmienia obiekt za fasadą na
`QueueFake`, a ten `pushed()` ma. Analiza statyczna widzi samą fasadę.

**Ten projekt rozstrzygnął już ten sam przypadek** — przy
`Log::shouldHaveReceived()`, i zapisał uzasadnienie w `phpstan.neon`:
„fasada przekazuje wywołanie do obiektu Mockery, ale analiza widzi samą
fasadę. Szpieg trafił do zmiennej." Zastosowałem ten sam wzorzec:

```php
$kolejka = Queue::fake();
… $kolejka->pushed(SendQueuedNotifications::class) …
```

Nic nie jest wyciszane, nie dochodzi żaden wyjątek w konfiguracji. Zmienia się
tylko sposób sięgnięcia po atrapę — kod mówi teraz analizie to samo, co robi
w czasie wykonania.

Zmierzone: PHPStan **4 błędy → `[OK] No errors`**, oba testy po zmianie
**11 passed, 53 asercje**. Commit `443100cf`.

Warto odnotować, że rozwiązanie nie było moim pomysłem — leżało w komentarzu
konfiguracji, napisane przy okazji poprzedniego takiego błędu. Gdyby ten
komentarz wtedy nie powstał, dziś zgadywałbym.

## 03:40Z (21.09) — #918 scalone; dwa konflikty zostają dla człowieka, jeden z nich to znana pułapka

**#918 „Napraw przepełnienie 320 px" — MERGED `cd966aae`.** Poprawka stopki
(`5a221f23`) dojechała kolejką i PR od razu pokazał **13 zielonych, zero
czerwonych**. To dziewiąte scalenie tej nocy.

### Dwa PR-y naprawdę konfliktują — sprawdziłem, co dokładnie

`gh pr update-branch` odmówił obu: „Cannot update PR branch due to conflicts".
Zrobiłem próbne scalenie lokalnie, żeby wiedzieć, o co idzie:

| PR | gałąź | pliki w konflikcie |
|---|---|---|
| #923 | `flota/zdjecia-formularze` | `resources/views/components/field.blade.php` |
| #913 | `flota/gotowanie` | **`package.json`** i `resources/js/app.js` |

### #913 to pułapka opisana w karcie audytu, punkt 7

`package.json` — to jest **dokładnie** ten przypadek, przed którym ostrzega
`KOLEJNOSC_SCALANIA.md` i punkt 7 karty audytu: konflikt na wspólnej linii
`build` da się „rozwiązać", biorąc swoją wersję, i **nic nie zaświeci na
czerwono** — a cicho wypadnie krok budowania drugiej strony.

Dlatego nie tknąłem ani jednego z tych dwóch. Rozstrzygnięcie wymaga
sprawdzenia, czego potrzebują OBIE strony, a nie wybrania jednej.
Oba PR-y mają poza tym komplet zielonych sprawdzeń — konflikt jest jedyną
przeszkodą.

### Stan otwartych PR-ów nad ranem

| PR | stan |
|---|---|
| #913, #923 | zielone, ale **konflikt** — do rozstrzygnięcia ręcznie |
| #920 | „Przyrząd testu obciążeniowego": porzucone nie wchodzą do mianownika |
| #725 | „Panel marki — puste i pełne widoki", przyczyna nierozpoznana |
| #786, #915 | `CONFLICTING` od wieczora, nieruszane |

## 03:55Z (21.09) — `gpt-monitoring`: raport odsyłał do dowodów, które przepadły

Strażnik odnośników markdown zgłosił cztery:

```
docs/infra/MONITORING_ODBIOR_2026_09_20.md:
  odnosnik do output/monitoring/baseline.json    -> brak pliku
  odnosnik do output/monitoring/received.json    -> brak pliku
  odnosnik do output/monitoring/alert-cases.json -> brak pliku
  odnosnik do output/monitoring/negative.json    -> brak pliku
```

Sprawdziłem najpierw, czy to nie znana pułapka `.gitignore` (w tym projekcie
`*.log` bywa połykany bez ostrzeżenia). **Nie jest**: `git check-ignore` nic
nie zwraca, katalog `output/` nie jest ignorowany. Plików nie ma także na
dysku stanowiska — nie zdążyły trafić do commita przed awarią z 20 września.

Poprawka `effab631`: odnośniki zamienione na same nazwy plików, a na końcu
dokumentu stoi uwaga mówiąca wprost, że liczby w tabelach pochodzą z tamtych
przebiegów i **nie da się ich dziś niezależnie sprawdzić**, dopóki dowody nie
zostaną odtworzone — razem z instrukcją odtworzenia.

Świadomie **nie usunąłem liczb**. Usunięcie byłoby wygodne (dokument
przestaje mówić cokolwiek trudnego), ale skasowałoby ślad po prawdziwej
pracy. Zostawienie odnośników byłoby gorsze: raport obiecywałby dowód jednym
kliknięciem, nie mając czym go pokryć. Trzecia droga — powiedzieć, co jest
zmierzone, i co z tego dziś nie jest sprawdzalne.

Czerwień 1 failed → zieleń **3 passed, 49 asercji**.

To już **czwarta gałąź tej nocy, na której awaria zabrała część pracy,
a zostawiła opis tej pracy.** Wzór jest stały: zostają testy i raporty,
znika to, co opisują.

## 04:10Z (21.09) — `gpt-n1-powiadomienia`: uzasadnienie było, tylko nie tam, gdzie patrzy strażnik

`KazdaMigracjaMaWycofanieTest` odrzucił migrację
`2026_09_20_120000_usun_zamrozone_wycinki_komentarzy.php` — pusty `down()`.

Strażnik nie poprzestaje na zgłoszeniu, tylko mówi **co zrobić**:

> napisz wycofanie, a jeśli naprawdę nie ma czego cofać — zadeklaruj to
> w klasie migracji stałą `WYCOFANIE_NIC_NIE_ROBI` z uzasadnieniem po polsku
> (co najmniej 60 znaków), tak jak robi to
> `0001_01_01_000000_enable_postgres_extensions`.

I miał rację co do sedna: pusty `down()` wygląda **identycznie**, gdy ktoś
o wycofaniu zapomniał, i gdy świadomie uznał, że nie ma czego cofać.

Ciekawe jest to, że autor migracji uzasadnił tę pustkę wyczerpująco — cały
akapit w docbloku, łącznie ze zdaniem „Migracja w drugą stronę, która milczy,
twierdziłaby, że wycofanie jest pełne — a nie jest". Tyle że napisał to tam,
gdzie czyta człowiek, a nie tam, gdzie patrzy maszyna.

Poprawka `4da45693`: to samo uzasadnienie przeniesione do stałej
`WYCOFANIE_NIC_NIE_ROBI`. **Nie podjąłem żadnej nowej decyzji** — nie tknąłem
ani zakresu migracji, ani `up()`. Przełożyłem cudze zdanie z prozy do miejsca,
w którym strażnik umie je znaleźć.

Czerwień 1 failed → zieleń **2 passed, 172 asercje**.

Warto to zapamiętać przy pisaniu strażników: ten podał w komunikacie nazwę
stałej, minimalną długość uzasadnienia i **plik z przykładem**. Naprawa zajęła
minutę, bo nie trzeba było zgadywać, czego strażnik chce.

## 04:30Z (21.09) — Strażnik odnośników złapał mnie na cytacie

Gałąź z dziennikiem odbiła się jeszcze raz, tym razem na odnośnikach markdown.
Powód jest zabawny i wart zapisania: **zacytowałem w dzienniku tamte cztery
martwe odnośniki dosłownie, razem ze składnią linku** — więc skaner poszedł
za nimi po raz drugi, teraz w moim własnym tekście.

To pokazuje różnicę między dwoma testami w tym samym pliku strażnika:
- test **tras** pomija bloki kodu i katalog `docs/flota/` (wykluczenie dodane
  o 02:15),
- test **odnośników** sprawdza wszystko — i słusznie, bo martwy odnośnik jest
  martwy niezależnie od tego, w jakim dokumencie stoi.

Nie rozszerzam wykluczenia na odnośniki. Zamiast tego przepisałem cytat tak,
żeby był cytatem, a nie linkiem. Opis zdarzenia nie musi odtwarzać składni,
której dotyczy — wystarczy, że mówi, co było nie tak.

## 05:00Z (21.09) — Osiem napraw bramki. Wszystkie tego samego rodzaju

Osiem gałęzi odbiło się od bramki i osiem wróciło poprawionych. Zestawienie,
bo dopiero razem widać wzór:

| gałąź | co padło | naprawa | czerwień → zieleń |
|---|---|---|---|
| `gpt-ai-piloty` | PHPStan, 21 × unknown class | `scanDirectories` w phpstan.neon | 21 błędów → `[OK] No errors` |
| `gpt-harmonogram` | manifest Vite z rocznym cache'em | `/build/assets/*` + `no-cache` na manifest | 1 → 2 passed |
| `gpt-konto-poczta` | PHPStan, `Queue::pushed()` | atrapa do zmiennej | 4 błędy → `[OK]`, 11 passed |
| `gpt-n1-powiadomienia` | pusty `down()` migracji | stała `WYCOFANIE_NIC_NIE_ROBI` | 1 → 2 passed, 172 asercje |
| `gpt-kontakt-panel` | `%TEMP%/…` wzięte za trasę | ukośnik windowsowy | 1 → 3 passed |
| `gpt-monitoring` | odnośniki do przepadłych dowodów | nazwy plików + uwaga o utracie | 1 → 3 passed |
| `gpt-obciazenie` | `/bezwzgledna/sciezka/…` | `<bezwzględna ścieżka do…>` | 1 → 3 passed |
| `gpt-odbior-wdrozen` | `/zdjecia/UUID/feed` | pełny adres, jak trzy wiersze niżej | 1 → 3 passed |
| `flota/stan-sesji-2` | mój cytat martwych odnośników | cytat bez składni odnośnika | 1 → 3 passed |

**W żadnym z tych dziewięciu przypadków nie zmieniłem reguły strażnika.**
Sześć razy poprawiłem dokument, dwa razy kod, raz zakres skanowania — i ten
jeden raz jest opisany osobno, z uzasadnieniem, dlaczego zawężenie o katalog
dzienników nie jest osłabieniem.

Dwie gałęzie wstrzymałem, bo naprawa wymagałaby decyzji, której nie mam prawa
podjąć: `gpt-ci-architektura` (zmiana raz już wycofana) i `gpt-cloudflare-cache`
(pytanie o prywatność publicznego cache'u).

### Koszt, który akceptuję świadomie

Każda naprawa kosztuje jeden dodatkowy cykl bramki (~6 minut), bo kolejka
pobiera gałąź w tej samej chwili, w której ją poprawiam, i najpierw jeszcze
raz przemiela stary kod. Wstrzymywanie kolejki na czas naprawy kosztowałoby
więcej niż te sześć minut, więc zostawiam jak jest — ale każdą taką porażkę
trzeba wtedy odróżnić od prawdziwej i wyzerować licznik prób. Stąd
`wpusc-galaz.sh`.

## 06:10 — `gpt-sonda-wdrozenia` wstrzymana: to spór dwóch decyzji, nie luka

Bramka: `SondaWdrozeniaTest > usuwanie preview nie ukrywa bledu cli`,
trzy przypadki, wszystkie z tym samym objawem:

```
Usuwam środowisko: pr-999999
Gotowe.
ATRAPA_ODMOWA
Failed asserting that 0 is identical to 73.
```

Czyli: polecenie usuwające środowisko **padło kodem 73**, a krok wypisał
„Gotowe." i wyszedł zerem. Klasyczna fałszywa zieleń — dokładnie to, czego
ten strażnik pilnuje.

Winowajca stoi w `.github/workflows/preview.yml`:

```yaml
railway environment delete "$env_name" --yes || true
echo "Gotowe."
```

`|| true` połyka **każdy** błąd. Komentarz obok uzasadnia go jednak sensownie:
„jeśli środowisko już nie istnieje, to jest stan pożądany, a nie błąd".

### Dlaczego NIE dokończyłem tego sam

Test wymaga propagacji **każdego** niezerowego kodu — łącznie z przypadkiem
nazwanym wprost „805 brak środowiska zgłoszony błędem" => kod 1. Czyli autor
gałęzi świadomie rozstrzyga, że **usuwanie nieistniejącego środowiska ma
zapalać joba na czerwono**, a autor obecnego kroku rozstrzygnął odwrotnie
i zapisał to w komentarzu.

To nie jest zapomniana implementacja (jak przy manifeście Vite, gdzie
uzasadnienie starej reguły samo wykluczało manifest). To **dwie rozsądne
decyzje operacyjne, które się wykluczają**: czy sprzątanie po zamkniętym PR-rze
ma świecić na czerwono, gdy nie ma czego sprzątać.

Rozstrzygnięcie kosztuje czyjś spokój przy każdym zamkniętym PR-rze albo
ukrywa prawdziwe awarie usuwania. Wybór należy do właściciela.

Gałąź wyjęta z kolejki do `wstrzymane.txt` — trzecia pozycja.

### Uwaga na marginesie, warta sprawdzenia rano

Ten sam test padał też na wstrzymanej `gpt-cloudflare-cache`. Dwie gałęzie
ruszają tę samą sondę wdrożenia — to może być kolejna para zdublowanej pracy,
obok `naprawa-858`/`tagi-filtr` i `gpt-n1-powiadomienia`/`notyfikacja-zywa`.

## 07:25 — `gpt-zalegle`: „Spróbuj ponownie", które nie ponawiało

Szósty raz ten sam wzór — gałąź zaostrzyła asercję, a zmiany, której ta
asercja pilnuje, nie wniosła. Tym razem usterka dotyka człowieka wprost.

W `public/offline.html` stało:

```html
<p><a href="/home">Spróbuj ponownie</a></p>
```

Napis obiecuje ponowienie, a odnośnik prowadzi na stronę główną. To nie jest
drobiazg formalny: ktoś czytający przepis przy garnku, komu na chwilę padło
Wi-Fi, po kliknięciu „Spróbuj ponownie" **traci przepis** i ląduje na
`/home`. Ekran offline jest ostatnim miejscem, w którym wolno komuś zgubić
to, co robił.

Pusty `href` przeładowuje ten adres, pod którym człowiek stoi — czyli robi
dokładnie to, co mówi napis. Commit `06e8b7f8`, z powodem zapisanym
komentarzem w samym pliku, żeby następna osoba nie „poprawiła" tego z powrotem.

Czerwień 1 failed → zieleń **3 passed, 59 asercji**.

To już szósta gałąź, na której awaria zostawiła opis pracy, a zabrała pracę.
Przy trzech z nich brakującą połowę dało się odtworzyć z samego strażnika,
bo strażnik podawał stan docelowy co do znaku. Przy trzech pozostałych nie —
i te stoją wstrzymane.

## 08:00 (21.09) — SPROSTOWANIE: powód wstrzymania `gpt-ci-architektura` był mój, i był błędny

W zapisie z 00:45Z napisałem, że nie dokańczam tej gałęzi, bo usunięcie
`fetch-depth: 0` „już raz wycofano — revert `abef3c94`".

**To nieprawda.** `abef3c94` jest commitem, który tę zmianę ZROBIŁ. Zdanie
źródłowe stoi w `docs/infra/MAPA_CI_611.md:192` w sekcji „Sprawdzenie
i wycofanie":

> Rollback: przywrócić trzy opcje pełnego checkoutu wraz ze zmianą testu
> regresyjnego (revert abef3c94…).

To **plan wycofania na przyszłość**, a ja przeczytałem go jako zapis zdarzenia
z przeszłości. Potwierdzenie negatywne: `gh api .../commits/abef3c94` → 422
„No commit found for SHA"; `git cat-file -t` w obu klonach → „Not a valid
object name". Commit przepadł w awarii razem ze zmianą w `ci.yml`.

Wstrzymałem gałąź na podstawie nieporozumienia i tu to odwołuję.

### Co jest naprawdę do rozstrzygnięcia

ZA: żaden z trzech jobów (`port_marki`, `port_funkcje`, `dostepnosc`) nie woła
gita w ogóle — sprawdzone w ich zakresach. Porównanie z bazą przeniósł do joba
`zakres` commit `6586a870`; komentarz w `ci.yml` uzasadniający pełną historię
opisuje stan sprzed tamtej zmiany. Koszt udokumentowany w #611:
`fetch --unshallow` raz **10 min 16 s** przy limicie joba 20 min (job padł na
przekroczeniu), raz **16 min 20 s**.

PRZECIW: issue #611 ostrzega wprost „nie zmieniać tego w ciemno na
`fetch-depth: 1`" — i ma powód. Pomiar: historia jednej gałęzi to **~6 MB,
czyli 8% `.git` i ~3% checkoutu**; samo drzewo robocze waży ~124 MB, z czego
`docs/` 106 MB. Tamte dziesięciominutowe fetche **nie brały się z objętości
historii**; #611 samo odnotowuje retransmisje i niezmienne liczniki bajtów
i mówi, że przyczyny nie ustalono. `fetch-depth: 1` ścina 3%, nie 10 minut.

Skutku na runnerze **nikt nigdy nie zmierzył** — gałąź nie trafiła na GitHuba.
`docs/DECISIONS.md` (15 050 linii) nie zawiera ani jednej wzmianki o
`fetch-depth`, więc nie ma decyzji projektowej ani do złamania, ani do obrony.

### Lekcja

Przeczytałem „revert `abef3c94`" i zbudowałem na tym decyzję, nie sprawdzając,
czy ten commit w ogóle istnieje. Jedno `git cat-file -t` wystarczyło, żeby
to obalić — i było mnie na nie stać. To trzeci raz tej doby, kiedy wziąłem
zdanie z dokumentu za pomiar.

## 07:55 (21.09) — Omal nie uruchomiłem drugiej kolejki. Opis, bo to była moja pomyłka

`ps -eo args | grep -c "[k]olejka9.sh"` zwróciło **0**, więc uznałem kolejkę za
martwą i wznowiłem ją przez `setsid`. Po starcie okazało się, że stara **żyje
od 6 h 50 min** (PID 4084225) — mój odczyt był fałszywy, nie jej stan.

Przez chwilę działały dwie. To dokładnie ta kolizja, przed którą ostrzega
komentarz w samym skrypcie:

> Dwa rownolegle przebiegi zderzaja sie na bazie i daja falszywe porazki
> (142 failures, deadlocki) — stad szeregowo.

Zatrzymałem tę, którą dodałem. Została jedna, a licznik prób gałęzi
`gpt/odbior-czy-przygotowanie` wyzerowałem — jej bateria padła dlatego, że
po drodze zabiłem „osieroconą" instancję `check.sh`, która wcale osierocona
nie była.

### Czego się nauczyłem

Sprawdziłem **jeden** wskaźnik (obecność procesu) i na jego podstawie podjąłem
działanie naprawcze na żywym systemie. Wystarczyło spojrzeć na drugi: ostatni
wpis w logu miał **minutę**, czyli coś oczywiście pracowało. Sprzeczność
między dwoma odczytami była widoczna od razu — i to ona, a nie jeden z nich,
była właściwą informacją.

To ta sama pomyłka co przy `abef3c94`: wziąłem pojedynczy sygnał za rozstrzygnięcie.

## 08:10 (21.09) — SPROSTOWANIE #920: mianownik był dobry, kłamał dowód

W nocy zapisałem, że „porzucone nie wchodzą do mianownika". **Nieprawda.**
Mianownik jest poprawny — `mianownik = wszystkieN + porzucone`
(`scripts/generator-obciazenia-605.mjs:820`, commit `34b6ceb3`, już na `main`).
Gałąź #920 przyrządu w ogóle nie tyka.

Padał **sposób dowodzenia**. Stary dowód opierał tezę na nierówności odsetków
`blad_procent > bezPorzuconych`, a odsetek **nasyca się na 100%**: gdy na
obciążonym runnerze twardy limit ścina wszystko, 100% wychodzi zarówno
z porzuconymi w mianowniku, jak i bez nich. Przyrząd zapalał wtedy czerwień
o brakującym mianowniku — przy poprawnym mianowniku.

### Rzecz, dla której warto było to zlecić

**Własna kontrola ujemna degenerowała się identycznie.** Porównywała wyłącznie
`blad_procent`, więc przy zerze poprawnych odpowiedzi zepsuty i poprawny
generator dają te same 100% — kontrola nie potrafiłaby zapalić. Miernik gubił
zdolność pokazania porażki **dokładnie w warunkach, w których jest potrzebny**.
To nie jest usterka odczytu, tylko strażnik, który ślepnie pod obciążeniem.

Poprawka `9ce50b79` przenosi dowód na liczniki, które nie nasycają się nigdy:
różnica między księgowaniem z porzuconymi i bez nich to zawsze dokładnie
`porzucone`. Dwie bezwarunkowe asercje plus ta sama para w kontroli ujemnej.

Dowód trzema biegami: test sprzed poprawki + poprawny generator = czerwień
z komunikatem identycznym jak w CI; test po poprawce + poprawny generator =
21/21 zielonych; test po poprawce + **zepsuty** generator = czerwień
„jest 10, a z porzuconymi powinno być 180". Trzeci bieg jest sednem — stary
dowód nie odróżniał zepsutego od poprawnego.

Kontrola dodatnia narzędziem repozytorium (`kontrola-ujemna.sh`): werdykt
POTWIERDZONA, pełny cykl PASS → FAIL z właściwego powodu → PASS.

### Porównywalność wcześniejszych odczytów

Bez zmian — mianownik się nie zmienił, więc poprzednie serie zostają
porównywalne. Sprawdzone dodatkowo: w obu zapisanych seriach
`porzuconych_przez_limit = 0`, a i tak są oznaczone jako „stopień skażony,
NIE cytować".

### Sprawa dla kolejki

Worktree `bazy-stanowisk` stracił metadane gita, więc poprawka powstała na
gałęzi `robota/bazy-stanowisk-odzysk`. PR #920 stoi jednak na
`robota/bazy-stanowisk` — pchnięcie innej nazwy nie odświeżyłoby PR-a.
Sprawdziłem, że `9ce50b79` jest potomkiem `origin/robota/bazy-stanowisk`
(pchnięcie będzie fast-forward), wskazałem lokalną gałąź o właściwej nazwie
na ten commit i wpuściłem ją do kolejki.

## 08:20 (21.09) — Przegląd całej floty pod kątem „strażnik bez swojej zmiany"

90 katalogów z żywym `.git` (ze 197), po odjęciu duplikatów **85 odrębnych
gałęzi**. Każdy wymóg sprawdzany na DWÓCH rewizjach — bazie `4c811cc7`
i aktualnym `origin/main` `cd966aae`.

### Cztery luki, w tym jedna nowa

| gałąź | strażnik | pilnowany plik | spełnione na main? |
|---|---|---|---|
| **`hero-ekran`** | `PierwszyEkranMiesciPrzyciskTest` | `.github/workflows/ci.yml` | **NIE** — nowa luka |
| `gpt-ci-architektura` | `PortMarkiMaWlasnaBramkeCiTest` | `.github/workflows/ci.yml` | NIE |
| `gpt-sonda-wdrozenia` | `SondaWdrozeniaTest` | `.github/workflows/preview.yml` | NIE |
| `gpt-cloudflare-cache` | **ten sam** `SondaWdrozeniaTest` | `.github/workflows/preview.yml` | NIE |

**Dwie ostatnie to ta sama luka w dwóch egzemplarzach — jedna poprawka
`preview.yml` odblokowuje obie gałęzie.** To podnosi wagę decyzji o usuwaniu
środowisk preview: nie wisi na niej jedna gałąź, tylko dwie.

`hero-ekran` wymaga decyzji człowieka: strażnik pilnuje tylko OBECNOŚCI napisu
`hero-nad-zgieciem` w `ci.yml`, nie mówiąc, w którym z ~1630 wierszy i w którym
jobie krok ma stanąć. Domknięcie tego asercją na własną rękę zabetonowałoby
wiązanie, którego nikt nie wybrał.

### Trzy moje nocne poprawki potwierdzone jako zamknięte

`gpt-harmonogram` (Caddyfile w diffie, strażnik spełniony), `gpt-zalegle`
(`offline.html` w diffie), `gpt-monitoring` (odnośniki to już tylko higiena
dokumentacji, nie luka testowa).

### Osiem gałęzi podejrzanych z automatu, odrzuconych po przeczytaniu

M.in. `gpt-eksport` i `gpt-wspomnienia-prywatnosc` — ich migracje są już na
`main` z wymaganym indeksem i odmową `down()`. `gotowanie`, `naprawa-923`,
`sledztwo-725` — pliki, które wyglądały na „niezmienione", są **kotwicami
skanerów**, a nie rzeczami do zmiany.

### Pułapka, którą agent sam na sobie złapał

`git show 'origin/main:.github/…'` **cicho padło** przez konwersję ścieżek
MSYS i pierwszy odczyt `ci.yml` dał **fałszywe zero trafień**. Powtórzył
wszystko z `MSYS_NO_PATHCONV=1`. Bez tego zgłosiłby luki tam, gdzie ich nie ma.

### Granica tego przeglądu

**107 katalogów z martwym `.git` nie było sprawdzanych.** Jeżeli któraś gałąź
żyje tylko tam, wypadła z przeglądu. Wnioski są też z ODCZYTU, nie z przebiegu
— cztery luki to przewidywane porażki, nie zmierzone.

## 08:35 (21.09) — SPROSTOWANIE: żadna z 51 „nieodzyskanych" gałęzi nie przepadła

W nocy przebudowałem stan kolejki i zapisałem kategorię **`nieodzyskane.txt`
— 51 gałęzi, które „nie przetrwały awarii"**. To był mój błąd metody:
porównywałem nazwy **dosłownie**, a flota masowo przemianowała gałęzie,
ucinając prefiks (`flota/hero-ekran` → `hero-ekran`).

Rzeczywisty rozkład tych 51:

| kategoria | ile | stan |
|---|---|---|
| są na GitHubie pod TĄ SAMĄ nazwą | 6 | mój błąd odczytu |
| są na GitHubie pod nazwą przemianowaną | 15 | bezpieczne |
| **są lokalnie, ale NIE na GitHubie** | **8** | **jedyne realne ryzyko** |
| tylko katalog na dysku, praca potwierdzona treścią | 22 | do odzyskania |

### Osiem z realnym ryzykiem — wpuszczone do kolejki od razu

`hero-ekran`, `notyfikacja-zywa`, `odwolanie-link`, `powiadomienia`,
`zaleglosci-zgloszen`, `naprawa-858`, `jedna-droga`, `straznik-format`.
Razem **~17 000 wstawek** pracy, żadnej na zdalnym. Sprawdziłem każdą
z osobna (`git diff --shortstat origin/main...`), zanim je dopisałem.

Wśród nich `naprawa-858` z 9141 wstawkami — ta sama, którą audytor zgłaszał
jako Z13 i o której w nocy napisałem, że jest w kolejce. Była — ale pod nazwą,
której kolejka nie kojarzyła z listą.

### Dwadzieścia dwa katalogi bez gita — trzy agenty w robocie

Praca potwierdzona **treścią**, nie samą obecnością plików: każdy ma realny
diff wobec `origin/main` pasujący tematycznie do nazwy. Odzysk jest delikatny,
bo `main` przesunął się o dziewięć scaleń — skopiowanie starszego drzewa
**cicho cofnęłoby** cudze scalone zmiany. Agenci mają to sprawdzać dla każdego
pliku z osobna, zgodnie z `_prompty/00-NAPRAWA-STANOWISKA.txt`.

## 08:30 (21.09) — Uprzedzone dwa odbicia od bramki

Przeczesano wszystkie 20 gałęzi czekających w kolejce. Dwie realnie by odbiły:
`gpt/widmo-zamkniec` (odnośnik do `MAPA_CI_611.md`, pliku, który nigdy nie
powstał) i `stan-sesji` (cztery fałszywe „trasy": ścieżka MSYS, placeholder
korpusu, cudzy endpoint EmailLabs i `/Program` wyłuskane z `C:/Program Files/`).
Obie poprawione i zweryfikowane osobnym przebiegiem. Pozostałe 18 sprawdzono
pomiarem — przechodziły już wcześniej. **Żadnej listy wykluczeń nie tknięto.**

## 09:00 (21.09) — Audyt przedkolejkowy: wzoru z mojego zlecenia NIE MA, jest odwrotny

Audytor sprawdził 53 gałęzie pchnięte tej nocy i **nie znalazł ani jednego
„strażnika bez zmiany, której pilnuje"** — czyli wzoru, którego kazałem szukać.
Znalazł za to **wzór odwrotny, i też sześć razy: strażnika, którego NIC NIE
URUCHAMIA.**

Jedyną drogą, którą CI odpala `node --test`, jest zamknięta lista w
`package.json` → `"build"`. Poza `flota/gotowanie` nikt jej nie rusza, więc
sześć gałęzi wnosi testy JS, które nigdy nie pobiegną. **Dwie z nich twierdzą
w swoim raporcie, że test „jest w CI"** (`gpt-skladniki`, `gpt-ustawienia-profilu`)
— to jest obietnica bez pokrycia po stronie raportu, nie kodu.

### Pięć znalezisk, które trzeba obejrzeć przed scalaniem

1. **`gpt-zalegle` wpisuje port klastra floty `55439` na sztywno do wspólnego
   `scripts/check.sh`** — lokalnej bramki reklamowanej w trzech workflow.
   Po scaleniu **każdy poza tym stanowiskiem dostanie `exit 1` zamiast kontroli.**
2. `gpt-rozbicie-uslug` — test manifestu Railway odwołuje się do stałych,
   których w drzewie nie ma. **Nie ma prawa przejść**, i nikt tego nie zobaczy,
   bo nic go nie uruchamia.
3. `gpt-ugotowalem-dostep` zostawia w feedzie **martwy przycisk „Ugotowałem"**
   bez `@can('cook')` — ten sam objaw, który #902 miało usunąć.
4. `gpt-kontakt-panel` uczynił „Wyślij odpowiedź" **domyślnym** przyciskiem
   formularza, więc Enter wysyła nieodwracalny list.
5. `gpt-n1-powiadomienia` ma test migracji, który **migracji nie uruchamia**
   (kopiuje zapytanie), a docblock twierdzi coś przeciwnego.

### Sprzeczność produktowa, która scali się bez konfliktu i bez czerwieni

`flota/gotowanie` (#764) **dodaje** dopisek „— do smaku" w trybie gotowania,
a `gpt-skladniki` (#878) **usuwa dokładnie ten blok** ze strony przepisu,
na jawne polecenie właściciela. Różne pliki, więc git nie zgłosi konfliktu,
a testy nie zapalą. **To jest dokładnie punkt 7 karty audytu.**

Do tego cztery gałęzie podbijają wersję na `Alfa 0.68` i **żadna nie pisze
w `CHANGELOG.md`**, choć reguła „podbicie = wpis" stoi trzy linie wyżej
w tym samym pliku i nie pilnuje jej żaden test.

### Korekta liczb

Na GitHubie jest **114** gałęzi, nie 111. Tej nocy pchnięto **53**.
`pchniete.txt` tym razem **nie zawyża, ale ZANIŻA o cztery** — brakuje
`flota/zdjecia-formularze`, `naprawa/kontrola-dodatnia-do-przodu`,
`naprawa/proba-odtworzenia-w-ci`, `robota/bazy-stanowisk`. Kto planuje
kolejność z tego pliku, pominie trzy gałęzie naprawcze.

Audytor zaznaczył wprost: minut Actions **nie zmierzył** (API odmawia
tokenowi), a „~65 minut na PR" przejął z mojego zlecenia.

## 09:05 (21.09) — Siedem stanowisk odzyskanych; nowa pułapka w procedurze

Grupa C: `gpt/turnstile-pomoc`, `gpt/zeszyt-zapisy`,
`naprawa/619-polityka-prawdziwa`, `naprawa/750-porcje`, `robota/plan-scalania`,
`robota/poswiadczenia-decyzje`, `robota/stopka-pusty-pas` — wszystkie
odtworzone od zera na `cd966aae` i wpuszczone do kolejki.

Metoda odsiewu warta zapamiętania: agent porównywał `git hash-object` każdego
pliku roboczego z jego wersją w **40 kolejnych commitach wstecz**. Ponad 40
plików w KAŻDYM katalogu trafiało bajt w bajt w `4c811cc7` albo `534e0a51` —
czyli było treścią `main` sprzed nocnych scaleń. Skopiowanie ich **cofnęłoby
dziewięć scalonych PR-ów bez jednej czerwieni**.

W `naprawa-750` jeden plik był mieszany: `RecipeController.php` niósł obok
prawdziwej roboty o porcjach hunk cofający scalony #924. Agent rozciął patch
i wpuścił tylko właściwy fragment.

### Pułapka dopisana do `00-NAPRAWA-STANOWISKA.txt`

`robocopy /XD .git` **nie chroni worktree**, bo tam `.git` jest PLIKIEM, nie
katalogiem. Przy pierwszym podejściu skopiowało to stare wskaźniki `gitdir:`
na siedem nowych katalogów i git odpowiadał `fatal: not a git repository` —
**objaw identyczny z awarią, którą właśnie naprawiamy.** Instrukcja podawała
dokładnie tę komendę, więc każde kolejne stanowisko weszłoby w to samo.
Poprawione: `.git` stoi teraz w OBU wykluczeniach, z wyjaśnieniem dlaczego.

## 09:40 (21.09) — Instrukcja odzysku potrafiła zniszczyć efekt odzysku

Przegląd 73 plików `_prompty` znalazł rzecz, która zamykała się w pętlę.

**40 plików wskazywało nazwy gałęzi, których już nie ma.** Flota po awarii nie
tylko ucięła prefiksy (`flota/hero-ekran` → `hero-ekran`), ale też **zamieniła
ukośnik na myślnik** w gałęziach `gpt/*`: `gpt/tablica` → `gpt-tablica`.

Sama nieaktualna nazwa byłaby drobiazgiem. Groźne jest połączenie z krokiem 3
`00-NAPRAWA-STANOWISKA.txt`:

```
git branch -r --list "origin/TWOJA/GALAZ"
```

Dla starej nazwy zwraca **pustkę**. Agent idzie wtedy ścieżką „gałęzi nie ma"
i zakłada **pustą gałąź od `main`** — osierocając prawdziwie odzyskaną pracę,
która wisi na origin pod nową nazwą. Instrukcja naprawy niszczyłaby efekt
naprawy, nie zgłaszając niczego.

Poprawione we wszystkich 40 plikach. Przy okazji martwe SHA baz (`534e0a51`
zamiast `4c811cc7`), każde zweryfikowane osobno przez `git merge-base`, oraz
literówka `naprawa/750-porcze` i martwe SHA `46dc3d3f`, którego nie ma w repo.

Dopisana pułapka, której nie znałem: **`git worktree add -b <gałąź> origin/main`
ustawia `origin/main` jako upstream** nowej gałęzi, więc goły `git push`
poszedłby w `main`. Teraz instrukcja każe zaraz potem `git branch --unset-upstream`.

Mianownik przeglądu: **73 pliki**, poprawionych 46, oznaczonych jako całościowo
nieaktualne — zero. Agent zaznaczył wprost, czego nie sprawdził: 193 numery
zgłoszeń rozproszone po plikach, bo `gh` nie było w jego środowisku.

## 08:50 (21.09) — Minut zostało 558, nie 2189. Przełączenie na własne runnery

Właściciel pokazał licznik z GitHub Billing: **2442 / 3000 minut zużyte**,
reset za 10 dni, billable $0 (zniżki pokrywają). Zostało **558**.

### Mój szacunek był zaniżony o jedną trzecią

Wieczorem było 811/3000. Zakładałem, że noc kosztowała ~1170 minut
(10 aktualizacji PR-ów + 8 scaleń + przebieg #929). Kosztowała **~1631**.
Planowałem wydatki na szacunku, mając możliwość zobaczenia licznika —
API rozliczeń odmawia tokenowi, ale przeglądarka nie.

### Ostrzeżenie, które dał właściciel, jest cenniejsze niż sama liczba

> „Jak ci zacznie świecić na czerwono za jakiś czas, to może być to, że się
> skończyły minuty na GitHubie."

Czerwień z wyczerpanego pakietu wygląda **identycznie** jak czerwień z kodu.
Agent, który tego nie sprawdzi, będzie szukał usterki w gałęzi — albo „naprawi"
coś, co nie było zepsute. Dopisane na górę `CZYTAJ-TO-NAJPIERW.md` jako
pierwsza rzecz do sprawdzenia przy każdej niewyjaśnionej czerwieni.

### Przełączenie — decyzja właściciela, wykonana i zmierzona

`CI_RUNS_ON` z `"ubuntu-latest"` na `["self-hosted","Linux","X64","wsl2"]`.
Etykiety dobrane pomiarem, nie z pamięci: `kuking-linux` ma tylko **trzy**
runnery, a te cztery etykiety mają **wszystkie sześć**.

Właściciel wybrał przełączenie **bez czekania na rozdział hybrydowy** — czyli
joby przeglądarkowe też idą na własną pulę. To świadome ryzyko: gdyby czegoś
im tam brakowało, zapaliłyby czerwień bez pokrycia. Dlatego zamiast od razu
otwierać osiem PR-ów, puściłem **jeden darmowy przebieg ręczny** jako kontrolę.

Zmierzone — wszystkie sześć runnerów zajętych, joby rozeszły się po maszynach:

```
Zakres zmiany              kuking-wsl-NEW-03      completed
Testy (PostgreSQL 18)      kuking-wsl-NEW-03
Build assetów (Vite)       kuking-wsl-DOM-NEW-02
Dostępność (axe) i wydajność  kuking-wsl-DOM-NEW-03
Port marki — rodziny ekranów  kuking-wsl-NEW-01
Panel marki                kuking-wsl-NEW-02
Audyt zależności           kuking-wsl-DOM-NEW-01
```

Ten przebieg nie rusza pakietu. Czekam na jego komplet, zanim wydam minuty
na PR-y — jeśli coś padnie środowiskowo, lepiej dowiedzieć się na jednym
darmowym przebiegu niż na ośmiu PR-ach naraz.

**Skutek uboczny do zapamiętania:** sześć runnerów obsługuje jeden przebieg,
więc kolejne czekają w kolejce. PR-y pójdą szeregowo — wolniej niż na
GitHubie, ale za darmo.

---

## 21.09.2026, ranek — stan po nocy i trzy decyzje właściciela

### Decyzje wdrażane
1. **Próg PostgreSQL w górę na 18 razem ze zmianą reguły** — zrobione.
2. **Strażnik kaskady wpięty w CI** — w toku.
3. **Obie pary duplikatów scalane, git rozstrzyga** — w toku, z jawnym szukaniem cichych zderzeń.

### Co się okazało przy wdrażaniu (pomiary, nie założenia)

**`flota/straznik-r60` jest PRZODKIEM `flota/prog-postgresa`.** Plan scalania §1 poz. 6
stawiał je jako wybór „albo–albo". Pomiar (`git merge-base --is-ancestor`) mówi,
że wyboru nie ma: po scaleniu `prog-postgresa` gałąź `straznik-r60` nie wnosi ani
jednego bajtu. **Wykreślić z kolejki** z adnotacją „wchłonięta".

**Ostrzeżenie o skasowanej sekcji było odwrócone.** Commit `d900af7b` usunął
DUPLIKAT sekcji „R73 rozpisane" (wklejonej dwa razy przy odzysku), a nie oryginał.
Sekcje „R47" i „R73" są obecne, po jednym razie.

**D-223 nie jest sporne dla `prog-postgresa`** — ta gałąź używa D-227. Wiersz §2
planu scalania jest w tym punkcie nieaktualny; wysłałem na jego podstawie korektę,
która była niepotrzebna.

**Ciche zderzenie domknięte testem.** `MINIMALNY_MAJOR = 18` cytował regułę tylko
w komentarzu, a komentarz nie oblewa. Dołożony test czyta wiersz stacku z `AGENTS.md`
i wymaga tej samej liczby. Kontrola dodatnia dwustronna, obie `POTWIERDZONA`.

**`#915` niesie skrypt kaskady, ale nie dotyka `ci.yml`.** Luka potwierdzona
odczytem listy plików PR-a. `flota/martwe-kaskady` ma wersję nowszą (37 408 B,
8× `--tylko`) niż rodzina `scal-915` (35 098 B, 2×).

### Kolejka — nie stanęła, choć wyglądała na martwą
`kolejka11` padła o 23:22 i jej pliki wyglądają na świeże. Żywa jest `kolejka9`
(PID 4084225), log pisany 5 minut temu. **Drugi wskaźnik znów rozstrzygnął** —
tak jak w nocy. Stan: **63 gałęzie wypchnięte, 56 zostało**, ~6 min na pozycję.

### Znaleziony mnożnik kosztu kolejki
Gałęzie idą po **dwa–trzy podejścia**, bo bramka oblewa na tych samych testach.
Suita trwa **321 s**, więc każde zbędne podejście to ponad 5 minut. Zliczone FAILED:

| ile | test |
|---|---|
| 15 | `DokumentyMdNieMajaMartwychOdnosnikowTest` |
| 14 | `CloudflareCachePrivacyTest` |
| 8 | `SondaWdrozeniaTest` |
| 4 | `ProbaOdtworzeniaTest` |

**Pozycja nr 1 zdiagnozowana:** `docs/infra/PLAN_TECHNICZNY_614.md` linkuje do
`docs/infra/MAPA_CI_611.md`. Dokument wnosi **wyłącznie `origin/gpt/widmo-zamkniec`**
(już wypchnięta), a `MAPA_CI_611.md` **nie istnieje na żadnej ze 118 gałęzi zdalnych**.
Po scaleniu `widmo-zamkniec` ten test zapali na `main` i będzie oblewał każdą
następną gałąź. Agent naprawia u źródła — bez wyłączania strażnika.

### Własne runnery — działają
Wszystkie 17 jobów `ci.yml` czyta `CI_RUNS_ON`, zmienna = `["self-hosted","Linux","X64","wsl2"]`.
Przebieg 35570122027: **9 zielonych, 0 czerwonych**, reszta w toku, zero minut z pakietu.
Pełny przebieg ≈ 14 min, najdłuższy job „Testy (PostgreSQL 18)" = 13 min 54 s.

**Do decyzji: `kuking-wsl-DOM-NEW-04` jest offline** — 1 z 7 runnerów nie pracuje.

### Osiem PR-ów priorytetowych — zablokowane, ale nie przeze mnie
Dziewięć z dziesięciu gałęzi z czołówki planu **nie jest jeszcze na origin**.
Nie da się otworzyć PR-a dla gałęzi, której nie ma. Czekają w kolejce; przy
obecnym tempie i 56 pozycjach to ~5,5 h — chyba że naprawa podatku bramki skróci
to o połowę.

### Minimalne potwierdzenie RODO — projekt gotowy, `9fc7cfc3`

Gałąź `naprawa/minimalne-potwierdzenie-rodo`, jeden commit na `cd966aae`. Tabela
`potwierdzenia_zadan_rodo`: numer sprawy u człowieka (`RODO-XXXX-XXXX-XXXX`, ~59 bitów),
`konto_id` dozwolone **tylko dopóki konto żyje** — przy `wynik = 'wykonane'` zabrania
go CHECK w bazie, nie reguła w PHP. Nie ma e-maila (ani skrótu), nie ma `metadata jsonb`
— tej kolumny pilnuje skan `information_schema` z kontrolą dodatnią.

Odrzucono HMAC e-maila z powodem, który warto zapamiętać: przestrzeń adresów nie jest
losowa, więc rejestr stałby się **wyrocznią „czy ta osoba usunęła konto"**.

Testy: `MinimalnePotwierdzenieRodoTest` 17 przeszło / 61 asercji; pełny zestaw
**4491 przeszło, 1 oblał**. Agent sam zgłosił lukę: po jednolinijkowej poprawce
w pliku `.md` nie przepuścił pełnego zestawu ponownie.

**Oblał `DokumentyMdNieMajaMartwychOdnosnikowTest`** — ten sam test, który jest
podatkiem kolejki, ale **z innej przyczyny** (trasa `/ustawienia/dane` zamiast
`/ustawienia/twoje-dane`). Wniosek dla naprawy podatku: ten strażnik oblewa
z wielu różnych przyczyn naraz, więc jedna naprawa nie zdejmie wszystkich piętnastu.

Nic nie pisze do tabeli — brak modelu, ścieżki zapisu, backfillu i automatu retencji.
To jest świadome: właściciel ma najpierw zatwierdzić kompromis.

**DO DECYZJI WŁAŚCICIELA (§3.3 pkt 3):** czy godzimy się, że **bez numeru sprawy
nie odpowiemy regulatorowi o konkretną osobę**. To jest cena za rejestr, który nie
jest wyrocznią. Tego nie da się domknąć asercją — to wybór, nie usterka.

### Decyzje właściciela z rana
- Kolejka: **najpierw zdjąć podatek bramki** (agent pracuje).
- Dysk: **najpierw pełny przegląd wszystkich sierot**, kasowanie świadome i po jednej
  pozycji. Spis powstaje jako `_wspolne/SIEROTY_DYSKU.md`.

### Przełączenie na własne runnery — POTWIERDZONE POMIAREM, `main` jest zielony

Przebieg **35570122027**, `main` = `cd966aae`, 06:49–07:14Z (25,5 min):

- **13 jobów na 13 zielonych, zero czerwonych.**
- **Każdy job poszedł na własny runner**: `kuking-wsl-DOM-NEW-01` (5 jobów),
  `DOM-NEW-02` (3), `DOM-NEW-03` (1), `NEW-01` (1), `NEW-02` (1), `NEW-03` (2).
  **Ani jeden na runnerze GitHuba.**
- **`billable = {}`** — przebieg nie zużył ani jednej minuty z pakietu.
  To jest twardy dowód, nie oszacowanie: GitHub sam raportuje puste rozliczenie.

**To rozstrzyga dwie rzeczy naraz.** Po pierwsze: PR-y i scalenia nie palą już
minut, więc z 558 pozostałych nie ubędzie. Po drugie: `main` **nie jest czerwony**.
Ostatni czerwony przebieg (35550606725, 01:20, ten sam `cd966aae`) oblał na
„Testy (PostgreSQL 18)" krok **„Klient PostgreSQL 18 (wymagany przez skrypty kopii)"**
oraz na „Port marki". Na własnym runnerze oba są zielone. Czerwień brała się
ze **środowiska runnera GitHuba**, nie z kodu — przełączenie ją zdjęło.

Wniosek do zapamiętania: to była czerwień, której nie dało się naprawić w repozytorium,
bo w repozytorium nie było usterki.

### Drugie wchłonięcie: `flota/scal-915` znika z kolejności scalania

Plan §1 stawiał `flota/scal-915` jako pozycję nr 2, z ręcznym rozwiązaniem
konfliktu („skrypty z poz. 1, dokumentacja i `tests/mutacje/` z poz. 2").
**Ten konflikt jest już rozwiązany** — commit `f658afe5` na `flota/martwe-kaskady`
nazywa się wprost „Wchłoń przyrządy z `flota/scal-915` i zostaw NOWSZEGO strażnika".

Pomiar:
- `git merge-base --is-ancestor origin/flota/scal-915 origin/flota/martwe-kaskady` → **tak**
- ponad `scal-915`: 9 plików, +758 wierszy
- strażnik: `scal-915` → **35 098 B**, `martwe-kaskady` → **37 408 B** (nowszy wygrał)

**Uwaga metodologiczna do zapamiętania:** sprawdziłem najpierw ODWROTNY kierunek
(`is-ancestor martwe-kaskady scal-915`), dostałem „nie" i o mało nie ogłosiłem
gałęzi rozłącznymi. `--is-ancestor` jest niesymetryczne — pytanie zadane
w złą stronę daje odpowiedź prawdziwą i bezużyteczną.

To **drugie dziś wchłonięcie** po `straznik-r60`. Wzór jest powtarzalny: agenci
odzysku scalali rodziny gałęzi w nocy, a plan opisuje stan sprzed tego scalania.
**Przed każdym „albo–albo" z planu sprawdzić `--is-ancestor` w OBU kierunkach.**

### PR #960 otwarty jako SZKIC
`flota/martwe-kaskady` → `main`, pozycja nr 1. Szkic celowo: **nic nie wywołuje
jeszcze tego strażnika**, więc scalenie teraz dołożyłoby kolejnego strażnika,
który istnieje i milczy. Zdjąć szkic dopiero po wpięciu w `ci.yml`.

### Pary duplikatów — rozstrzygnięte pomiarem (agent od par, 21.09)

**Para A:** `naprawa-858` jest nadzbiorem `tagi-filtr`. 45 z 47 wspólnych plików
identycznych haszem; z 236 wierszy dodanych przez `tagi-filtr` brakuje **5**,
i wszystkie pięć to wiersze, które `naprawa-858` świadomie przepisała.
**Scalać tylko `naprawa-858`.** Scalenie `tagi-filtr` do niej dałoby dwie
definicje `przegladaj()` i **trzy przyciski przeglądania w widoku** — dokładnie
to ciche zderzenie, o które pytałem. `gpt-tagi` domyka rodzinę, też do wycofania.

**Para B:** `straznik-format` jest **ścisłym nadzbiorem** `powiadomienia` —
12 wspólnych plików bajt w bajt (hasze wypisane w meldunku), plus jeden więcej.
**Scalać tylko `straznik-format`.** Konflikt z `main` jest gałąź-kontra-main,
nie w parze: gałąź dokłada `celIstniejeNadal()`, `main` (#924) `urlDoKomentarza()`
— metody rozłączne.

**Cichych zderzeń szukał i nie ma** — przeskanował drzewa scalenia czterech
gałęzi pod kątem zdublowanych definicji, zmiennych CSS i wpisów pod jednym `D-xxx`.
Dwa trafienia okazały się fałszywe i sprawdził je ręcznie.

### MINA: dwie różne migracje pod jedną nazwą pliku

`notyfikacja-zywa` i `gpt-n1-powiadomienia` wnoszą **tę samą migrację**
`2026_09_20_120000_usun_zamrozone_wycinki_komentarzy.php` **o RÓŻNEJ treści**.
To jedyna taka kolizja w całej kolejce 115 gałęzi. **Wejście obu to twarda awaria.**
Wybór wersji jest decyzją merytoryczną o zachowaniu powiadomień, nie techniczną.

### D-223 rozstrzygnięte, D-225 nie
Próg PostgreSQL przeniesiony na **D-227** (`9b80b617`); D-223 zostaje przy
strażniku kaskady, który ma **17 odwołań w kodzie** wobec 2 u progu. Kryterium:
koszt przeniesienia.

**D-225 chcą cztery gałęzie:** `jedna-droga` (26 odwołań), `naprawa/ci-hybryda-runnerow`
(25), `flota/scal-786` (0), `gpt-cloudflare-cache` (0). Nierozstrzygnięte.

**Uwaga o strażnikach numerów:** `NumeryDecyzjiMajaWpisyTest` łapie duplikat
dopiero **po** scaleniu drugiej gałęzi — gdy odwołania w kodzie od dawna
wskazują dwie decyzje naraz.

### MÓJ BŁĄD: znowu dwaj agenci na jednym worktree
Agent od par zgłosił, że w `martwe-kaskady-ODZYSK2` ktoś edytował `ci.yml`,
`check.sh` i `PortMarkiMaWlasnaBramkeCiTest.php` **w trakcie jego pomiarów**
(09:00 i 09:08). To był mój agent od wpięcia kaskady w CI. **Drugi raz tego
samego dnia zaplanowałem dwóch agentów na zachodzące na siebie drzewa.**
Commit się nie połknął (sprawdził listę plików), ale trzeci przebieg pełnej
suity mógł mieć cudze zmiany w runtime — czyli jeden z jego pomiarów jest
podejrzany. Wniosek na przyszłość: **przed wysłaniem agenta sprawdzić, czy
katalog roboczy nie jest już zajęty** — `pgrep -af` po nazwie stanowiska.

### Czerwień na #966: ten sam kod, różny wynik — i realna usterka pod spodem

`WyborZeszytuMaWalidacjeTest` przechodzi na `main` (job „Testy (PostgreSQL 18)",
06:49–07:03, zielony) i **pada na `flota/scal-786`** o 07:36. Zmierzone:

- `tests/Feature/WyborZeszytuMaWalidacjeTest.php` — hasz **`b7748f008617` na OBU**
- `app/Http/Controllers/CollectionController.php` — **identyczny na obu**
- gałąź **nie rusza** kontrolera (z rzeczy „zeszytowych" tylko `scripts/wyjecie-z-zeszytu.mjs`)

Czyli to nie jest regresja produktu. Różnica siedzi w **środowisku testów**, a
`scal-786` przebudowuje właśnie izolację baz (`tests/bootstrap.php`,
`tests/nazwa-bazy.php`, `cleanup-test-dbs.sh`).

**HIPOTEZA, nie ustalenie** — mechanizmu nie zmierzyłem: nowa izolacja zmienia stan
startowy testu i **odsłania usterkę, którą stara reguła maskowała**.

**Usterka jest realna i warta naprawy niezależnie od losu gałęzi:**
```
PDOException: SQLSTATE[22P02]: invalid input syntax for type uuid: "to-nie-jest-uuid"
```
Wartość z formularza idzie **prosto do zapytania** przeciw kolumnie `uuid`, zamiast
zostać odrzucona przez walidację. Człowiek dostaje błąd bazy zamiast zdania po polsku
mówiącego, co zrobić — wprost przeciw regule projektu. I przeciw `AGENTS.md`:
**UUID w adresie to nie autoryzacja**, a tu UUID z formularza nie jest sprawdzany
nawet pod kątem tego, czy jest UUID-em.

**Czego NIE twierdzę:** czy jest osiągalna na `main` w produkcji (tam test przechodzi)
ani czym dokładnie nowa izolacja zmienia stan startowy.

### SPROSTOWANIE: usterka z uuid NIE ISTNIEJE — to była czerwień zamówiona

Wpis wyżej („Czerwień na #966: ten sam kod, różny wynik") twierdzi, że
`WyborZeszytuMaWalidacjeTest` odsłania realną usterkę: wartość z formularza idzie
prosto do zapytania przeciw kolumnie `uuid`. **To nieprawda.**

`CollectionController::selectedCollection()` ma regułę `'bail', 'nullable', 'uuid'`
**przed** `Rule::exists(...)`, więc `"to-nie-jest-uuid"` odpada na walidacji formatu
i nigdy nie dociera do zapytania. Kontroler ma to od #483.

Skąd zatem `PDOException 22P02` w logu joba? Z **kontroli mutacyjnej**:
`scripts/kontrole-negatywne-alfa08.py` w. 61 **celowo wycina `'uuid',`** z tego
kontrolera i **wymaga**, żeby test zapalił na czerwono:
```python
("Format UUID", CONTROLLER, COLLECTION_TEST,
 lambda s: replace_once(s, "'bail', 'nullable', 'uuid',", "'bail', 'nullable',")),
```
Wpis `FAILED … WyborZeszytuMaWalidacjeTest` z `22P02` jest więc **zamówionym
sygnałem sukcesu kroku**, nie awarią.

Pomiar: `WyborZeszytuMaWalidacjeTest` w izolacji — **14 przeszło, 102 asercje**.

**To jest dokładnie ta pułapka, która jest opisana w `CZYTAJ-TO-NAJPIERW.md`
i w tym dzienniku (23:40), i którą sam sobie wypisałem rano jako ostrzeżenie dla
agentów.** Wszedłem w nią mimo to, bo znalazłem wyjaśnienie („ten sam kod, różny
wynik") na tyle ciekawe, że przestałem sprawdzać prostsze. Kosztowałoby to
„naprawę" walidacji, która od #483 działa.

**Wniosek proceduralny:** zanim uznam czerwień za usterkę, sprawdzić
`scripts/kontrole-negatywne-alfa08.py` i skrypty `.mjs` kontroli mutacyjnych —
**nazwa oblanego testu jest tam wypisana wprost**, więc to jest pytanie na jeden grep,
a nie dochodzenie.

Nadal **nie wiadomo**, co naprawdę oblało w jobie „Testy (PostgreSQL 18)" na
przebiegu 35573220727 — wiadomo tylko, że nie ten wiersz.
