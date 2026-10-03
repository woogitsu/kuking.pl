# Odbiór #2810 — atomowa zamiana tekstu szkicu

Stan: **lokalny zakres gotowy do niezależnego przeglądu**. Bez PR-a, pushu,
produkcji ani zamknięcia issue. Pełne bramki paczki i wydanie należą do roota.

## Zakres i podstawa

- Issue #2810 świeżo odczytane jako OPEN; brak komentarzy i osobnej aktywnej
  gałęzi tego zakresu. W PR #2865 problem został wskazany jako nadal otwarty.
- Własny worktree: `C:\Users\matma\.codex\worktrees\2810-atomowa-zamiana\Portale`.
- Własna gałąź: `codex/2810-atomowa-zamiana-20261003`, od zamrożonej X
  `23b6ab6532a845d0fd0cf160be4d23ba984c6fa2` po uzgodnieniu z rootem.
- Źródło zmiany: tylko `PunktOdzyskaniaSzkicu::przywroc`; `PublishRecipe`,
  kontroler i duży `tests/Dwa/bin/scenariusz.php` bez zmian.
- Odczytano aktualne AGENTS, D-333, dokument obszaru, pułapki testów,
  zasady tekstów oraz istniejący rejestr kontroli i oczekiwanych przyczyn.

Nie zmienia się schemat, 14-dniowe okno, tworzenie punktu, prywatność, zakres
kopii, idempotencja formularza ani koszt. Nie powstaje nowa funkcja.

## Rzeczywisty baseline

Na kodzie X przed poprawką prawdziwy `PublishRecipe` utworzył kopię B,
punkt B i bieżącą pracę A z innymi składnikami/krokami. Wyzwalacz PostgreSQL
odmówił UPDATE punktu: `P0001`, własna przyczyna
`ODZYSKANIE_2810_WYMUSZONA_AWARIA_PUNKTU`.

Odczyt nowym połączeniem po wyjątku (PID-y 704015/704016) wykazał
zatwierdzony **szkic B + punkt B**, więc praca A została utracona. Nie
uruchamiano zewnętrznej transakcji fixture. Dowód poza repo:
`C:\Users\matma\AppData\Local\Temp\kuking-2810-atomowa-zamiana\baseline-actual.log`.

## Poprawka

Jedna zewnętrzna transakcja obejmuje `PublishRecipe` i zachowanie zastąpionego
tekstu w punkcie. Wewnętrzna transakcja `PublishRecipe` nie zatwierdza danych
przed zakończeniem zewnętrznej. Kolejność pozostaje
**zdjęcia → konto → przepis → punkt**.

Po `PublishRecipe` pobierany jest świeży punkt pod `FOR UPDATE`. Brak lub
zmiana tożsamości, powiązanego przepisu, autora, znacznika, treści albo
przekroczenie terminu powoduje wyjątek i rollback całej zamiany. Świeży
przepis musi nadal należeć do tego autora i być nieopublikowanym szkicem;
`PublishRecipe` nadal sprawdza świeżą rewizję, stan i prawo konta. Odmowa
domenowa jest zamieniana w BLAD dopiero po rollbacku. Wyjątek zapisu bazy
propaguje po rollbacku; kontroler nie może potwierdzić sukcesu.

## Izolowany pomiar

- Host `normalhp`, PostgreSQL **18.6**, `127.0.0.1:55488`, właściciel
  `kuking_pg18_owner` — potwierdzone przed utworzeniem bazy i migracją.
- Baza fixture: `kuking_test_2810_atomowa_zamiana`.
- Baza rzeczywistego istniejącego Dwa, wyliczona przez funkcję projektu:
  `kuking_race_kat_repo_2810_atomowa_zamiana_db9bbd69`.
- Własny runtime:
  `/home/codex-admin/kuking-koordynacja-20261003-codex/repo-2810-atomowa-zamiana`.
- Fizyczny, niedowiązany vendor; zgodny composer.lock i jawny APP_BASE_PATH.
  Reflection wskazała klasę z własnego runtime. Klucz tylko nowej instancji.

Po poprawce ten sam rzeczywisty P0001, bez zewnętrznej transakcji testowej,
zostawił **identyczne pełne surowe wiersze A+B**: przepis, ID składników
i kroków, wszystkie ich pola, rewizja i punkt wraz z datą. Odczyt nowym
połączeniem: PID-y **947053/947334**. Następnie udane B+punkt A i świadome
cofnięcie A+punkt B; zero wersji, wykonań i powiadomień. Wszystkie warunki
w `2810-positive.json` są PASS.

## Wykonane kontrole

| Kontrola | Wynik |
|---|---|
| Nowa klasa atomowej zamiany + istniejąca klasa odzyskania | 30 testów, 229 asercji PASS |
| Te same klasy + `StraznikTekstuMaKontroleDodatniaTest` na finalnych plikach | **34 testy, 1868 asercji PASS**, zero skips/errors/failures |
| Istniejący `OdnowionyPunktKontraSprzatanieTest` na osobnej bazie Dwa | **1 test, 18 asercji PASS**; rzeczywista bariera SELECT/sprzątania, kopia przeżywa i oddaje zastąpiony tekst |
| Pint i składnia 2 zmienionych plików PHP | PASS |
| PHPStan z konfiguracji projektu dla obu plików | PASS, zero błędów |
| `tests/skrypty/kontrole-negatywne-przyczyna.py` | 37 testów PASS |
| `scripts/test_zawezenie_testow.py` | 22 testy PASS |

Nowa klasa wykonuje rzeczywiste commity (`connectionsToTransact()` zwraca
`[]`, poziom transakcji 0 jest asercją) i sprząta własne konta/przepisy przez
FK. Oprócz rzeczywistej awarii drugiego zapisu sprawdza sukces/cofnięcie i
ponowienie starego formularza; siedem zmian punktu podczas zamiany; świeżą
rewizję, publikację, właściciela, moderację i prawo konta; rzeczywisty POST
z komunikatem odmowy. Upływ terminu następuje **bez zmiany znacznika**,
więc testuje samą granicę okna.

Zdarzenia `saved` i listener SQL to deterministyczne przeploty na jednym
połączeniu, bez twierdzenia o czekaniu dwóch sesji. Osobne dowody wyżej
dotyczą zatwierdzonych danych drugiego PID i istniejącego Dwa #2849.

## Fizyczne kontrole ujemne

Cztery wpisy w istniejącym `scripts/kontrole-negatywne-alfa08.py` oraz
`scripts/kontrole_oczekiwana_przyczyna.py` są już wejściem istniejącego kroku
trzech części CI. Workflow nie wymaga zmiany. Każdy ma dodatni przebieg
przed mutacją i po odtworzeniu oraz JUnit z właściwą asercją:

| Fizyczna mutacja | Oczekiwana i potwierdzona przyczyna |
|---|---|
| Zdjęcie wspólnej transakcji, pozostawienie osobnego commita PublishRecipe | 1/1 FAIL `ODZYSKANIE_2810_ATOMOWA_ZAMIANA`: rzeczywisty błąd punktu pozostawia utracone A |
| Pobranie starego modelu punktu zamiast świeżego pod blokadą | 6/7 FAIL `ODZYSKANIE_2810_ZMIENIONY_PUNKT`; upływ terminu nadal odmawia dzięki osobnej kontroli czasu |
| `return` zamiast wyjątku przy odmowie | 7/7 FAIL `ODZYSKANIE_2810_ZMIENIONY_PUNKT`: odmowa stała się sukcesem |
| Zdjęcie samej kontroli terminu | 1/7 FAIL `ODZYSKANIE_2810_ZMIENIONY_PUNKT`, przypadek „termin” |

Werdykt: **4/4 POTWIERDZONE**, zero błędnych przyczyn; po każdym odtworzeniu
wybrany testcase ponownie PASS. Wspólny runner odtwarza bajty przez `cp`,
więc własna osłona poza repo zachowuje i sprawdza również dokładny mtime;
globalny przyrząd bez zmian.

Źródło przed/po: SHA256
`6c7b76e774e572ecc81bf12357c0b2ed0a3ea5d0c00243fb6448add9a5557555`,
mtime ns **1791027429096419741** identyczny. JSON odtworzenia i logi:
`C:\Users\matma\AppData\Local\Temp\kuking-2810-atomowa-zamiana\` oraz
`/home/codex-admin/kuking-koordynacja-20261003-codex/2810-*.{json,xml,log}`.

## Powtórzenie i granice odbioru

Na własnej izolowanej bazie PostgreSQL18+, z jawnymi zmiennymi połączenia:

```sh
php artisan test tests/Feature/OdzyskanieTekstuSzkicuAtomowaZamianaTest.php tests/Feature/OdzyskanieTekstuSzkicuTest.php tests/Feature/StraznikTekstuMaKontroleDodatniaTest.php
KUKING_KONTROLE_LOKALNIE=1 python3 scripts/kontrole-negatywne-alfa08.py --tylko 'Przywrócenie szkicu zatwierdza tekst przed błędem kopii (#2810)' --tylko 'Przywrócenie szkicu czyta punkt sprzed blokady (#2810)' --tylko 'Przywrócenie szkicu zatwierdza odmowę jako sukces (#2810)' --tylko 'Przywrócenie szkicu pomija termin podczas zamiany (#2810)'
php artisan test tests/Dwa/OdnowionyPunktKontraSprzatanieTest.php --group=dwa-polaczenia
```

Dwa wymaga uprzednio przygotowanej własnej bazy o nazwie wyliczonej przez
projekt; nie polega na samej dowolnej wartości DB_DATABASE. Pełnego check.sh,
pre-push, całego CI, pilota ani produkcji ten wąski odbiór nie zastępuje.
Rollback kodu jest możliwy bez migracji, ale przywraca ryzyko utraty A,
więc wymaga świadomej decyzji przy wydaniu.
