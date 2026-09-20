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
