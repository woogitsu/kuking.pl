# Odbiór korekty izolacji fixture #2810

## Przyczyna pełnej odmowy W

Zamrożony W `6b10a31f4093df1e17ea020b242252f0fca73d1e` zakończył normalny serialny przebieg z 14 235 PASS i ośmioma błędami `MultipleRecordsFoundException(2)`. Zachowany pełny log ma SHA256 `79e15fabc8738f202c6f9b7b0d92beb9adca75a0eb87b990380de28924915aac`.

Wcześniejsze wskazanie `Recipe::sole()` było błędnym odczytaniem stacku. W niezmienionym `PelnaNazwaSkladnikaTest` linie 44 i 70 to **`Ingredient::sole()`**; odczyty przepisu są w liniach 42 i 69. Builder pobiera najwyżej dwa rekordy dla `sole()`, dlatego zgłasza 2 również przy sześciu rekordach słownika.

1. Exact W6b na świeżym schemacie: samodzielne osiem przypadków **8/114 PASS**, proces 0.
2. W jednym nowym procesie kolejność `OdzyskanieTekstuSzkicuAtomowaZamianaTest` → `PelnaNazwaSkladnikaTest`: pierwsze 15 PASS, następne **8 ERROR**, proces 2, 23 testy/153 wykonane asercje. Te ERROR są reprodukcją, nie właściwą kontrolą ujemną reguły domenowej.
3. Tymczasowy odczyt własnych danych potwierdził po każdym sprzątaniu #2810: 0 przepisów i 0 kont, lecz pięć canonical ingredients. Przed POST następnej klasy: 0 przepisów/5 składników; po POST dokładnie jeden przepis i 6 składników. Oba instrumentowane pliki przywrócono bajtowo i z mtime.

Przy `connectionsToTransact()=[]` callback Laravel nie resetuje `RefreshDatabaseState::$migrated`. Kolejna klasa korzysta z przygotowanego schematu, a FK przepisów nie usuwa wspólnego słownika. `UserFactory`, `RecipeFactory` i model nie tworzyły dodatkowego przepisu w tym pomiarze.

## Wąska poprawka

Własny WT: `C:\Users\matma\.codex\worktrees\pelna-nazwa-skladnika-diagnoza\Portale`, gałąź `codex/diag-pelna-nazwa-skladnika-20261003`, baza W6b. Rejestracja WT w aplikacji i jedna próba attachment odmówiły z powodu istniejącego limitu 100 załączników; checkout Git jest poprawny.

Fixture zapisuje zastane ID swoich pięciu znormalizowanych nazw przed tworzeniem danych. Po usunięciu własnych przepisów i kont usuwa tylko nowe ID tych składników. Asercja `ODZYSKANIE_2810_CZYSTY_SLOWNIK` pilnuje skutku DELETE. Nowy przypadek danych tworzy wcześniej osobny przepis korzystający z tej samej nazwy: sprzątanie zachowuje jego składnik, powiązanie i autora. `finally` w tearDown zawsze oddaje sterowanie sprzątaniu frameworka.

Nie zmieniono domeny, SQL aplikacji, Policy, fabryk, `TestCase`, timeoutów, konfiguracji, migracji ani `PelnaNazwaSkladnikaTest` (blob `39068ae9bc822b2da25e3782d0b89587602c130e`). Zachowane są rzeczywiste commity #2810 i asercja poziomu transakcji 0. Cztery istniejące mutacje domenowe #2810 oraz oba rejestry bazowych 580 kontroli pozostają bez zmian; późniejsza Z ma własne dodatkowe trzy wpisy #2796. Nie dodano procesu PHPUnit wywołującego siebie w teście; dwa pliki wykonano we wspólnym procesie przez zewnętrzną konfigurację dowodową.

## Izolacja i wyniki

- SSH `normalhp`; PostgreSQL **18.6**, jawne `127.0.0.1:55488`, właściciel `kuking_pg18_owner`, nowa baza `kuking_test_pelna_nazwa_peer_20261003`.
- Runtime `/home/codex-admin/kuking-koordynacja-20261003-codex/repo-pelna-nazwa-peer-20261003`; `APP_BASE_PATH` wskazywał ten katalog. Przed pierwszą migracją rzeczywisty odczyt Laravel/PDO potwierdził tę bazę, właściciela, adres/port, wersję i 0 publicznych tabel; Reflection wskazał własne źródła.
- Fizyczny vendor skopiowano z zakończonego własnego runtime przy identycznym `composer.lock`; nie użyto symlinków, instalacji ani zmian globalnej konfiguracji. Runtime nie ma `.env`; parametry i nowy klucz aplikacji ustawiono tylko w środowisku własnych procesów.
- Końcowa para: **24/265 PASS**, proces 0, 0 failures/errors/skips. Wszystkie osiem oryginalnych przypadków składnika zachowuje dokładnie **114 asercji**. JUnit terminalny: SHA256 `dc556aec3b1cab21b012a270631dda5a014cfd6c594463e9263e60b1e1d96437`.
- Końcowy katalog: recipes 0, ingredients 0, users 0. Dodatni przypadek wcześniej współdzielonego składnika miał zachowane powiązanie, zanim posprzątał własną sondę.
- Pint `--test`: 1 plik, proces 0. PHPStan: tylko zmieniony plik, proces 0. To nie pełny PHPStan ani pełny hook.

## Właściwe kontrole fizyczne

| Jedna fizyczna zmiana | Dodatni → mutant → odtworzony |
|---|---|
| Usunięcie tylko DELETE własnych ID słownika | 1/12 PASS → 1 nazwany ASSERTFAIL `ODZYSKANIE_2810_CZYSTY_SLOWNIK` → 1/12 PASS |
| Poszerzenie DELETE na cały słownik | 1/7 PASS → 1 nazwany ASSERTFAIL `ODZYSKANIE_2810_ZASTANY_SLOWNIK` o utracie FK wcześniejszego przepisu → 1/7 PASS |

Oba mutanty mają dokładnie właściwą klasę/metodę JUnit, `PHPUnit\Framework\ExpectationFailedException`, proces 1 i **zero ERROR/SKIP**; parser odmawia timeoutu, SQLSTATE, brakujących przypadków i błędnej przyczyny. Osiem wcześniejszych ERROR nie służy temu werdyktowi.

Przed i po każdej mutacji: SHA256 `5d6135e2c88cd4a5e5f171cb5cffe9610400c55d0111c00a4cb627153a2e5148`, MD5 `22841fb91913d7c7af6944b122d62f46`, 16 609 bajtów, mtime_ns **1791051252862010483**. Odtworzono oryginalne zapisane bajty i timestamp, a potem wykonano dodatni przebieg oraz terminalną całą parę. `fixture-physical-control.json`: SHA256 `14b950a5e360401b2593157e92fc8b34ba6fbb581fb5baaa88419fa74fa756fa`.

## Surowe dowody i dalsza bramka

Lokalnie: `C:\Users\matma\.codex\worktrees\pelna-nazwa-skladnika-diagnoza\evidence-diag\`. `isolated/` zachowuje pierwsze 8/114; `latest-before-fix/` zachowuje pierwszą parę, debug i jego exact restore; `final/` zawiera oba dodatnie przebiegi pary, sześć JUnit/logów kontroli, JSON werdyktu, końcowy katalog, Pint i wąski PHPStan. Helpery i `SHA256.json` leżą poza repo. Remote: `/home/codex-admin/kuking-koordynacja-20261003-codex/transfer/pelna-nazwa-peer-20261003/`.

Właściwa komenda dowodowa używa `php vendor/bin/phpunit --configuration <evidence>/pair-config.xml --log-junit <evidence>/fixed-terminal-pair.xml`; konfiguracja zawiera wyłącznie dwa nazwane pliki w tej kolejności oraz oryginalne ustawienia PHPUnit. Wersja helpera i pełne argumenty są w surowych artefaktach.

Nie powtarzano pełnej baterii, hooka ani CI i nie wykonywano pusha/PR. W i Z pozostały zamrożone. Root odbierze lokalny commit, następnie dopiero wyda nowy head i wykona wymagany normalny pełny hook oraz terminalne CI dokładnego wyniku integracji. Ta korekta fixture nie jest zamknięciem pełnego AC ani odbiorem produkcji #2810. Rollback lokalnej korekty przywraca wyciek słownika i potwierdzoną odmowę pary; nie wymaga migracji ani cofania decyzji użytkownika.
