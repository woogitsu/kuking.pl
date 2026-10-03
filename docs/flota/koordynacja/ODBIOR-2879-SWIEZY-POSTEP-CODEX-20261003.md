# Odbiór #2879 — świeże konto przy zapisie wspólnego postępu

Data: 3 października 2026. Issue: **P2**, istniejący zakres wspólnego gotowania
#2385/D-333. Baza gałęzi: `23b6ab6532a845d0fd0cf160be4d23ba984c6fa2`.
Własny worktree: `2879-swiezy-postep`, gałąź
`codex/2879-swiezy-postep-20261003`. Bez pusha, PR, zmian issues i produkcji.

## Zakres domeny

`PostepWspolnegoGotowania::zablokuj()` odczytuje teraz `User` pod istniejącym
`FOR KEY SHARE` i sprawdza jego `isActive()`, zamiast sprawdzać obiekt sprzed
czekania. Odmowa zachowuje dotychczasowy komunikat. Kolejność **konto → sesja**,
siła obu blokad, wstępna Policy, publiczne sygnatury oraz callers pozostają.
Jedno zapytanie konta zastępuje poprzednie jedno zapytanie po identyfikator.

Nie zmieniono middleware, Policy, kontrolera, tras, widoków, schematu, opcji,
limitu osób, żądań ani retencji. Nie dodano funkcji. Stary wspólny scenariusz
Dwa nie jest edytowany; nowe przeploty mają własną klasę i własny plik bin.

## Rzeczywista blokada zawieszenia

`User::suspend()` wywołuje `OstatniAdministrator::odbierzAktywnosc()`, który
odczytuje konto `FOR UPDATE` w transakcji; dalsza nazwana zmiana także korzysta
z istniejącego zamka konta. **Nie** opieramy dowodu na zwykłym `UPDATE status`:
PostgreSQL `NO KEY UPDATE` jest zgodne z `KEY SHARE`. Oba procesy wołają
rzeczywiste publiczne akcje, a fixture mierzy faktyczny SQL `users … FOR UPDATE`.

## Izolowane stanowisko

- Normalhp, PostgreSQL **18.6**, PHP **8.4.26**.
- Host `127.0.0.1`, port `55488`, użytkownik/właściciel `kuking_pg18_owner`.
- Runtime: `/home/codex-admin/kuking-koordynacja-20261003-codex/race_repo_2879_swiezy_review`.
- Własny rzeczywisty Git worktree; nazwy ustalone czystą funkcją
  `kuking_nazwa_testowej_bazy()` **przed** tworzeniem i migracją.
- Dwa: `kuking_race_race_repo_2879_swiezy_review`, ta sama baza co baseline.
- Feature: `kuking_test_race_repo_2879_swiezy_review`; przed `createdb` potwierdzono
  nazwę, nieistnienie, host/port, właściciela i major 18.
- Fizyczny własny `vendor`, `APP_BASE_PATH` wskazuje własny runtime,
  `APP_ENV=testing`, jawne parametry DB, puste `DB_URL`.
- Bez operacji na gołym `kuking_race`, bazach innych zadań i produkcji.
  Procesy Git mają jawne cwd i kopię środowiska bez `GIT_*`; środowisko sesji
  i istniejące zabezpieczenia fixture #2871 pozostają niezmienione.

## Macierz i pomiary

Pięć operacji: gospodarz odhacza/cofa, pomocnik odhacza/cofa, gospodarz czyści.
Każda ma dwie rzeczywiste kolejności A/B:

1. A przechodzi prawdziwą Policy i stoi na barierze przed zamkiem konta.
   `pg_blocking_pids(A)` wskazuje PID bariery. B woła `User::suspend()` i
   zatwierdza zawieszenie. Dopiero wtedy zwalniamy A: odmowa `BladDlaCzlowieka`,
   `sqlstate=null`, dokładny komunikat, wszystkie wiersze kroków i rewizja
   identyczne jak przed próbą.
2. A ma już prawdziwy `KEY SHARE` konta i stoi na barierze (jej PID również
   potwierdza `pg_blocking_pids`). B wywołuje `suspend()`;
   `pg_blocking_pids(B)` wskazuje A przy prawdziwym `users … FOR UPDATE`.
   Zwalniamy A: rzeczywisty zapis i jedna zmiana rewizji, potem B kończy
   zawieszenie. Nie podstawiamy SQL zawieszenia ani wyniku akcji.

| Dowód | Wynik |
|---|---|
| Baseline domeny przed poprawką | **5 właściwych FAIL + 5 PASS / 205 asercji**, zero error/skip |
| Finalne nowe Dwa obu kolejności | **10 PASS / 235 asercji** |
| Nowe Dwa + istniejące `WspolneGotowanieNaDwochPolaczeniachTest` | **18 PASS / 337 asercji** |
| Nowe Feature + całe istniejące `WspolneGotowanieTest` | **59 PASS / 443 asercje** |
| Przyrząd ścisłego JUnit #2879 | **9 PASS** |
| Istniejący mechanizm podziału centralnych kontroli | **6 PASS** |
| Pint: domena i trzy nowe źródła PHP | **4 pliki PASS** |
| PHPStan: te same cztery źródła | **0 błędów**, konfiguracja repo, bez wyciszeń |
| Składnia check / Dwa / scope | **3 PASS** |
| Scope zmiany tylko nowego przyrządu #2879 | `kod=true`, `wyscigi=true` |

Nowe Feature także mierzy dodatni zapis aktywnego konta, odmowę świeżego
zawieszonego konta, rzeczywisty GET sesji 200 i bezpośrednie publiczne akcje
odejścia/zakończenia dla zawieszonego konta. **Domenowe zakończenie i odejście
nie są dowodem skutecznego DELETE przez HTTP** — osobne ustalenie poniżej.

## Fizyczne kontrole ujemne i przywrócenie

Mutant domeny zmienia wyłącznie sprawdzenie świeżego konta na stare:

```php
if ($swiezaOsoba === null || ! $swiezaOsoba->isActive()) {
// mutant:
if (! $osoba->isActive()) {
```

- Własny przyrząd Dwa: **10 PASS → 5 właściwych FAIL + 5 PASS → dokładne
  bytes/mtime restore → 10 PASS**. Każda porażka ma marker
  `WSPOLNE_2879_SWIEZE_KONTO_ODMOWA` na właściwej klasie/metodzie/zbiorze danych.
  JUnit wymaga dokładnie dziesięciu różnych własnych przypadków. Skip, error,
  obca klasa, brak przypadków, duplikat, niewłaściwa liczba porażek, SQLSTATE,
  timeout, czerwona odwrotna kolejność i niewłaściwy kod wyjścia są odrzucane.
- Centralne dwa rejestry: filtr `StareKontoNieZapisujeWspolnegoPostepuTest`
  wybiera te same **5** przypadków przed i po zawężeniu. **5 PASS → 5 z 5
  właściwych FAIL, POTWIERDZONA → 5 PASS** po odtworzeniu.
- Obydwa finalne przebiegi odtwarzają domenę o SHA256
  `c19837b3fb1112c19e1ef8634222a468c8a2dc64ddbb59e8e71267785f8a2df7`
  i dokładnym `mtime_ns=1791030867050855504`. Dwa robi to w `finally` we własnym
  przyrządzie; centralny przebieg był dodatkowo objęty własną kopią bajtów/stat
  i kontrolą odtworzenia mtime (zastany runner przywraca bajty przez `cp`).

Nowy przyrząd jest wpięty do obowiązkowego końca Dwa w CI. Jego mechanizm
jest jeden raz w lint i jeden raz w check, ze zwykłym `if/else/fi`.
Zachowano wszystkie stare wzorce filtrów i wpisy obu rejestrów.

## Osobny zastany problem HTTP — poza poprawką #2879

Pomiar własnym tymczasowym fixture, poza commitem, potwierdził cztery sceny:
gospodarz `destroy` i pomocnik `leave`, każda z legalnym publicznym zawieszeniem
czasowym i bezterminowym. GET przed i po zawieszeniu ma **200**, Policy akcji
jest dodatnia, ale DELETE ma **302 na ekran sesji**, błąd sesji `konto` i
dotychczasowy komunikat zawieszenia. Sesja, członkostwo, kroki oraz rewizja
pozostają identyczne. **4 PASS / 48 asercji oznacza odtworzenie tej odmowy,
nie skuteczne odejście/zakończenie przez HTTP.**

Przyczyną jest brak `wspolne-gotowanie.leave` oraz `wspolne-gotowanie.destroy`
w `EnsureAccountIsActive::DOZWOLONE_MIMO_ZAWIESZENIA`. Źródła tej ścieżki
(middleware, trasy, Policy, sesja, kontroler, User i OstatniAdministrator)
mają dokładnie te same bajty co baza `23b6ab6`; poprawka postępu ich nie dotyka.

Świeże wyszukiwanie issues/PR wszystkich stanów po obu dokładnych trasach oraz
odejściu/zakończeniu gotowania nie znalazło odpowiednika. Przeczytano najbliższe
**otwarte #2791** (odebranie udostępnienia przepisu) i **#2796** (wspólny zeszyt)
oraz zamknięte #1364/#926: dotyczą innych zasobów i tras. Wspólna rodzina błędu
middleware nie zastępuje osobnego kontraktu sesji gotowania. Root otrzymuje
oddzielny raport, fixture, JUnit, źródłowe SHA i świeże wyniki deduplikacji;
sam decyduje o nowym issue. Nie otwarto ani nie zmieniono issue i nie naprawiono
tego problemu przy okazji.

## Artefakty i granice odbioru

W `/home/codex-admin/kuking-koordynacja-20261003-codex/transfer/`:

- `2879-baseline.xml/.log`, `2879-dwa-final.xml/.log`, `2879-feature-final.xml/.log`;
- `2879-control-final.log`, `2879-registered-control-final.log`,
  `2879-registered-restore-final.json`, `2879-phpstan-final.log`, `2879-pint-final.log`;
- oddzielne `2879-http-suspend-probe.php`, `2879-http-suspend.xml/.log`,
  `2879-http-suspend-proof.jsonl`, `2879-http-suspend-sources.json`.

Oddzielny HTTP i deduplikacja mają również lokalną kopię w
`%TEMP%/kuking-koordynacja-20261003/2879-http-suspend/`.

To odbiór lokalny wąskiego zakresu, bez szerokiego Feature, pełnego PHPStan,
GitHub CI ani produkcji. Zamknięcie #2879 dopiero po bramce integracji i wydaniu.
Nie ma migracji. Ewentualny revert tej poprawki przywraca także znaną możliwość
zapisu przez stary aktywny model; nie zmienia danych ani retencji.
