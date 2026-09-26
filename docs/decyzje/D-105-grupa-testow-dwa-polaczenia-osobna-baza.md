## D-105 · Grupa testów `dwa-polaczenia`: osobna baza, osobne procesy, osobny przebieg — i wyłączona ze zwykłego `php artisan test`

**Data:** 11 września 2026 · Audyt kolejności blokad, rozdział 7
(`docs/research/2026-09-10-kolejnosc-blokad.md`) · issue #314 · Status: **obowiązuje**

### Co było złamane

**Żaden z 2854 testów tego repozytorium nie chodził na dwóch połączeniach do
PostgreSQL.** `RefreshDatabase` opakowuje każdy test w transakcję, której nigdy
nie zatwierdza, więc drugie połączenie nie widzi ani jednego wiersza
pierwszego. Skutek jest strukturalny, a nie drobny: **zakleszczenia
i odwrócone kolejności blokad są dla całego zestawu niewidzialne**
(`docs/PULAPKI_TESTOW.md` §6).

Nie jest to problem teoretyczny. 10.09.2026 naprawiono dwa zakleszczenia z tej
rodziny — Z-2 (kasowanie konta, D-093) i Z-3 (zużycie tokenu linku do
logowania) — i **obaj agenci napisali wprost, że ich testy tego nie dowodzą**:

> Nie pilnuje braku `40P01` przy prawdziwej równoległości. Że z (1) i (2) cyklu
> nie da się zbudować, to rozumowanie, nie pomiar.

Oba zakleszczenia zmierzono **ręcznie, poza zestawem testów**. Przy każdym
następnym mechanizmie blokowania trzeba by to powtarzać; dziś takich
mechanizmów jest siedem.

### Decyzja

Powstaje osobna grupa testów `dwa-polaczenia` — katalog `tests/Dwa/`, klasa
bazowa `Tests\Dwa\TestDwochPolaczen`, trzy testy startowe i jedno polecenie
`./scripts/testy-dwa-polaczenia.sh`.

**Sześć zasad, bez których to nie mierzy niczego** (każda jest osobną
pułapką, każda ma odpowiadający jej bezpiecznik w kodzie):

1. **Bez `RefreshDatabase`** — dane są zatwierdzane naprawdę.
2. **Własna baza na przebieg**: `kuking_race_<worktree>`, liczona
   `kuking_nazwa_bazy_wyscigow()` w `tests/bootstrap.php` — TĄ SAMĄ metodą, co
   nazwa bazy testowej, żeby w repozytorium nie było dwóch reguł nazywania
   baz. Klasa bazowa **odmawia startu**, gdy połączenie nie wskazuje na
   `kuking_race*`; ten sam bezpiecznik jest osobno w procesie potomnym, bo to
   on wykonuje `EraseAccountData`.
3. **Sprzątanie po identyfikatorach z testu**, nie `truncate` całych tabel.
4. **Twardy `lock_timeout`, `statement_timeout`
   i `idle_in_transaction_session_timeout` na KAŻDYM połączeniu**, łącznie
   z połączeniami procesów potomnych.
5. **Naprawdę osobne połączenia**: osobne instancje `PDO`
   (bez `ATTR_PERSISTENT`) i osobne PROCESY uczestników wyścigu.
6. **Kontrola pozytywna w każdym teście**: `setUp()` sprawdza, że dwa
   połączenia to dwa różne backendy (`pg_backend_pid()`) I że blokada
   z jednego jest widziana z drugiego; każdy test dokłada własną asercję na
   to, że mierzona operacja naprawdę się wykonała.

**Grupa jest wyłączona ze zwykłego przebiegu** (`<groups><exclude>`
w `phpunit.xml`). `php artisan test` ma po tej zmianie **2854 testy, tyle samo
co przed** — zmierzone przed i po. Uruchamia się ją osobno, osobnym zadaniem
w CI, i jej czerwień **nie blokuje** pracy nad niepowiązaną zmianą.

### Zestaw startowy i to, że każdy z trzech BYŁ czerwony

Rozdział 7 zakładał, że `KasowanieKontaNieZakleszczaSieTest` będzie czerwony
od razu (Z-2 nienaprawione). Po D-093 wszystkie trzy są zielone — a **zielony
test, który nigdy nie był czerwony, nie jest dowodem niczego**. Dlatego każdy
został pokazany czerwonym przeciwko kodowi bez zabezpieczenia:

| Test | Sabotaż | Wynik |
|---|---|---|
| `KasowanieKontaNieZakleszczaSieTest` | `EraseAccountData`: powrót do dwóch hurtowych `detach()` w kolejności ról (stan sprzed D-093) | **czerwony**: `SQLSTATE[40P01] deadlock detected … while deleting tuple (0,7) in relation "follows"` |
| `ZablokujIObserwujNieZakleszczajaSieTest` | `BlockUser`: powrót do `DB::transaction()` zamiast `ZamekPary` (stan sprzed D-090) | **czerwony**: `SQLSTATE[40P01] deadlock detected … while locking tuple (0,70) in relation "users"`, w `SELECT … FOR KEY SHARE` z klucza obcego przy `insert into blocks` |
| `WyzwalaczFollowsWidziZatwierdzonaBlokadeTest` | `DROP TRIGGER follows_blokada_ma_pierwszenstwo_trg`, osobno każda gałąź `OR` w funkcji wyzwalacza | **czerwony** na obu gałęziach: „Bariera przepuściła obserwowanie mimo ZATWIERDZONEJ blokady" |

Trzeci sabotaż **nie daje zakleszczenia i nie ma dawać**: ten test mierzy
widoczność zatwierdzonego stanu między dwoma backendami, a nie kolejność
blokad.

### Dlaczego uczestnikami są PROCESY, a nie drugie połączenie w tym samym PHP

Bo w jednym procesie PHP nie da się zatrzymać `EraseAccountData::handle()`
w połowie i puścić w tym czasie drugiej egzekucji — kod jest synchroniczny.
Zostawałoby przepisanie jego zapytań do testu i odegranie ich ręcznie, czyli
test mierzący SQL napisany w teście: zielony także po zmianie kodu, którego
pilnuje. To jest definicja atrapy z `AGENTS.md` §10.

Zatrzymaniem uczestnika w wybranym miejscu zajmuje się **bariera** — wiersz
trzymany pod `FOR UPDATE` na osobnym połączeniu. Tym, że uczestnik NAPRAWDĘ
już stoi w kolejce, zajmuje się `czekajNaZablokowane()`, pytające
`pg_stat_activity` — **nie `sleep`**. Powtarzalność bierze się stąd, że kolejka
po blokadę w PostgreSQL jest obsługiwana w kolejności zgłoszeń: test ustawia
uczestników w znanej kolejności i dopiero potem zwalnia barierę.

### Cena — uczciwie, łącznie z utrzymaniem

- **Szkielet:** pół dnia (zgodnie z wyceną rozdziału 7). Klasa bazowa,
  uruchamianie procesów, bariery, limity czasu, sprzątanie.
- **Każdy kolejny test:** pół godziny do godziny, głównie na ustawienie
  przeplotu.
- **Czas przebiegu:** 4 testy w ~1,7 s, plus jednorazowe migracje bazy wyścigów.
- **Utrzymanie — koszt najwyższy i nazwany wprost:** testy na prawdziwej
  równoległości bywają niestabilne, **a niestabilny test w tym repozytorium
  jest gorszy niż jego brak, bo uczy ludzi ignorować czerwone**. Zmierzone przy
  zakładaniu grupy: **20 przebiegów pod rząd, 20 zielonych** (drugi pomiar,
  przy obciążonym kontenerze: patrz opis PR). To jest warunek wejścia, nie
  ciekawostka.
- **Koszt zaniechania:** każdy następny mechanizm blokowania trzeba mierzyć
  ręcznie, poza zestawem, i wynik zostaje w podsumowaniu sesji, a nie w CI.

### Gdy grupa zacznie migać

Kolejność działań jest ustalona z góry, żeby nikt nie musiał jej wymyślać pod
presją czerwonego CI:

1. **Nie „powtórzyć i jechać dalej".** Zapisz, KTÓRY test i z jakim
   komunikatem — `40P01` (zakleszczenie, czyli usterka kodu) to coś zupełnie
   innego niż `55P03`/`lock_timeout` albo „przeplot się nie ustawił"
   (usterka testu).
2. **Usterka testu → wyłącz TEN test**, zostaw resztę grupy, otwórz issue
   z komunikatem. Nie wyłączaj całej grupy i nie zwiększaj `SEKUNDY_NA_KOLEJKE`
   „żeby przestało migać" — to jest zamiana pomiaru na czekanie.
3. **Grupa miga jako całość → nie scalać jej do CI jako zadania blokującego**
   i powiedzieć to wprost, zamiast zostawiać migający alarm. Rozdział 7 mówi
   to samo i ta decyzja to powtarza: **wolimy nie mieć tego narzędzia, niż mieć
   je migające.**

### Czego ta decyzja NIE rozstrzyga

- **Nie dowodzi, że kod jest wolny od zakleszczeń w ogóle.** Każdy test
  odtwarza JEDEN przeplot — ten, który był zmierzoną usterką. Inny przeplot
  wymaga innego testu i to jest napisane w docblocku każdego z nich.
- **Nie zamyka Z-3** (zużycie tokenu linku do logowania kontra
  `wymienToken()`). Rozdział 7 wymienia go jako zadanie dodatkowe „jeśli
  szkielet stanie" — szkielet stanął, test nie powstał, zostaje w issue #314.
- **Nie zmienia niczego w kodzie produkcyjnym.** Żadnej migracji, żadnej
  zmiany schematu, żadnej zmiany w `app/`. Sabotaże z tabeli wyżej były
  tymczasowe i zostały cofnięte (`git diff` pusty).
- **Nie rusza `tests/bootstrap.php` poza dopisaniem jednej funkcji.** Ten plik
  liczy nazwę bazy dla WSZYSTKICH przebiegów w repozytorium; nowa funkcja
  niczego w środowisku nie ustawia i nie dotyka istniejącej ścieżki.

### Jak to wycofać

Skasować `tests/Dwa/`, `scripts/testy-dwa-polaczenia.sh`, zadanie
`dwa-polaczenia` z `.github/workflows/ci.yml`, wpis `<testsuite name="Dwa">`
i `<groups>` z `phpunit.xml` oraz `kuking_nazwa_bazy_wyscigow()`
z `tests/bootstrap.php`. Nic więcej — grupa nie ma zależności w kodzie
produkcyjnym, a bazy `kuking_race_*` usuwa się `dropdb`. Ceną wycofania jest
powrót do stanu, w którym „brak zakleszczenia" jest w tym repozytorium
rozumowaniem, a nie pomiarem.

**Zmiana wymaga:** zmierzonej niestabilności (patrz „Gdy grupa zacznie migać")
albo znalezienia sposobu na ten sam pomiar wewnątrz zwykłego przebiegu — czyli
bez `RefreshDatabase`, ale i bez osobnej bazy. Dziś taki sposób nie jest znany.

📄 `tests/Dwa/TestDwochPolaczen.php` ·
`tests/Dwa/ProcesRownolegly.php` ·
`tests/Dwa/bin/scenariusz.php` ·
`tests/Dwa/KasowanieKontaNieZakleszczaSieTest.php` ·
`tests/Dwa/ZablokujIObserwujNieZakleszczajaSieTest.php` ·
`tests/Dwa/WyzwalaczFollowsWidziZatwierdzonaBlokadeTest.php` ·
`scripts/testy-dwa-polaczenia.sh` · `phpunit.xml` · `tests/bootstrap.php` ·
`docs/PULAPKI_TESTOW.md` (pułapka 7) ·
`docs/research/2026-09-10-kolejnosc-blokad.md` §7 ·
issue #314, #66, D-079, D-080, D-090, D-093
