# Odbiór #2889 — wyjście z sesji podczas zawieszenia

Data: 3 października 2026. Baza kodu: `3c109e12acf61b8590121d396e1e05519c935d8c`.
Gałąź lokalna: `codex/2889-wyjscie-zawieszone`.

## Zakres i odczyt kodu

Świeże issue #2889 jest otwarte. Istniejąca `CookingSessionPolicy::leave`
pozwala pomocnikowi wyjść, a `end` pozwala gospodarzowi zakończyć sesję także
podczas zawieszenia. Żądania DELETE zatrzymywał wcześniej
`EnsureAccountIsActive`, którego lista wyjątków nie miała tych dwóch tras.
Dodano wyłącznie `wspolne-gotowanie.leave` i `wspolne-gotowanie.destroy`
z komentarzem określającym granicę. Policy, kontroler i akcje domenowe
pozostają bez zmian; zamknięte statusy są nadal odcinane przed sprawdzeniem
wyjątków zawieszenia. Nie zmieniono schematu, retencji, CSRF ani widoków.
Zakres odpowiada rolom istniejącej sesji z D-333/#2385.

Nowy `ZawieszoneKontoOpuszczaWspolneGotowanieTest` przechodzi przez pełny
kernel HTTP, z rzeczywistym `User::suspend()` czasowo lub bezterminowo,
świeżym kontem i niezmienioną Policy. Nie wyłącza middleware, nie podmienia
autoryzacji i nie czyta źródeł. Zgodnie z AGENTS §10 nie wymaga więc wpisu
w rejestrze kontroli testów czytających tekst źródłowy. Kontrole fizyczne
wykonano istniejącym `scripts/kontrola-ujemna.sh` w własnym fixture.

## Izolacja wykonania

- Lokalny worktree: `C:\Users\matma\.codex\worktrees\2889-wyjscie-zawieszone\Portale`.
- Runtime: `/home/codex-admin/kuking-koordynacja-20261003-codex/repo_2889_http_review`,
  prawdziwy osobny worktree własnego registry `.git`.
- Przed utworzeniem bazy czysty helper `kuking_nazwa_testowej_bazy()` wskazał
  `kuking_test_repo_2889_http_review`. Potwierdzono brak tej bazy, właściciela
  `kuking_pg18_owner`, host `127.0.0.1`, port `55488` i PostgreSQL 18.6.
  Po utworzeniu potwierdzono tę samą nazwę, właściciela, host, port i wersję.
- Fizyczny, własny `vendor`; identyczny SHA-256 `composer.lock` źródła:
  `c584f51b81d36a6168ae3c89845f4241f7e62ee4b2847832eaaf9aa1bbc0f089`.
  Własne autoload, klucz aplikacji i `APP_BASE_PATH`; procesowe
  `APP_ENV=testing`, `APP_URL=http://localhost:8000`, jawne parametry DB oraz
  puste `DB_URL`. Procesy Git miały własne cwd i kopię środowiska bez `GIT_*`.
- Nie używano domyślnego `kuking_race`, baz innych zadań ani produkcji.

## Pomiary lokalne

| Próba | Wynik |
|---|---|
| Baseline 6 właściwych HTTP leave/destroy przed zmianą | 4 FAIL z własnymi markerami, 2 PASS aktywnych, 45 asercji; zero error/skip |
| Nowy HTTP po zmianie | 28 PASS / 267 asercji; zero failure/error/skip |
| Nowy HTTP + `WspolneGotowanieTest`, `WspolneGotowanieWieluPomocnikowTest`, `AccountStatusTest`, `ZawieszoneKontoPrywatneCzynnosciTest` po kontrolach | 135 PASS / 1043 asercje; zero failure/error/skip |
| Pint obu zmienianych źródeł PHP | 2 pliki PASS |
| PHPStan obu zmienianych źródeł PHP | 0 błędów |

Baseline obejmował także pełny nowy zestaw: 12 FAIL / 16 PASS, 225 asercji.
Osiem dodatkowych porażek dotyczyło zawieszonych obcych kont lub niewłaściwych
ról: stara bramka dawała 302 z odmową konta, a test oczekuje neutralnej 404
istniejącej Policy po dopuszczeniu dwóch nazwanych tras. Nie uznano tych
ośmiu porażek za właściwy marker kontroli ujemnej.

Nowa macierz obejmuje:

- 6 dodatnich HTTP: gospodarz/pomocnik × aktywne/czasowe/bezterminowe zawieszenie;
- 12 odmów: niewłaściwa rola albo obca sesja × oba endpointy × te trzy stany;
- 6 odmów zamkniętego konta: `banned`/`pending_delete`/`erased` × obie role;
- 4 zestawy zachowanych zakazów: obie role × oba rodzaje zawieszenia,
  z próbami odhaczenia, cofnięcia, resetu, utworzenia i odwołania linku.

Przed dodatnimi DELETE rzeczywisty GET ekranu daje 200, a istniejąca Policy
pozwala na wyjście/zakończenie. Wyjście zachowuje pełne odhaczenia z podpisami,
link, drugiego pomocnika, przepis i wszystkie dane drugiej sesji; usuwa tylko
własny udział i podnosi rewizję dokładnie o jeden. Zakończenie usuwa dokładnie
wskazaną sesję i jej dane potomne, pozostawiając oba przepisy i całą drugą
sesję. Odmowy porównują pełne wiersze sześciu tabel, w tym rewizje; obce konto
nie dostaje tytułu ani nazwy gospodarza.

## Dwie fizyczne kontrole ujemne

Każda kontrola usuwała dokładnie jeden wpis listy wyjątków. Istniejący
`kontrola-ujemna.sh` potwierdził rzeczywistą podmianę i `POTWIERDZONA`.
Dodatkowy launcher w izolowanym fixture zapisał osobny JUnit każdej fazy;
sprawdzono dokładnie trzy przypadki właściwej pełnej klasy i metody, jeden
aktywny i dwa zawieszone, bez pominięć i błędów wykonania. Każda porażka musi
być `<failure>` z własnym markerem; wyjątek, timeout lub SQLSTATE nie jest
akceptowany jako dowód.

| Usunięty wyjątek | Metoda | Wynik |
|---|---|---|
| `wspolne-gotowanie.leave` | `test_pomocnik_wychodzi_i_zostawia_wspolne_dane` | 3 PASS → 2 FAIL `WSPOLNE_2889_HTTP_WYJSCIE` + 1 PASS aktywnego → 3 PASS |
| `wspolne-gotowanie.destroy` | `test_gospodarz_konczy_tylko_wlasna_sesje` | 3 PASS → 2 FAIL `WSPOLNE_2889_HTTP_KONIEC` + 1 PASS aktywnego → 3 PASS |

Po obu kontrolach niezależnie porównano dokładne bajty i nanosekundowe mtime
ze stanem sprzed mutacji:

- SHA-256 middleware: `4358bc121058c7f02c98c9cc235b05a309ded23fff6b40feedc2defeabdc6898`;
- MD5: `1294cd7c90bec63287b63cd980196773`;
- mtime_ns runtime: `1791033399650803318`.

Pierwszy start launchera odrzucił zieloną kontrolę dodatnią z powodu
rozróżnienia JUnit `class` (ukośniki) i `classname` (kropki). Mutacja nie
została wtedy wykonana. Poprawiono wyłącznie odczyt tego pola w zewnętrznym
launcherze fixture; odrzucony przebieg zachowano osobno. Wynikiem są dopiero
dwa pełne cykle opisane wyżej.

Artefakty w `/home/codex-admin/kuking-koordynacja-20261003-codex/transfer/`:
`2889-baseline-exit.xml/.log`, `2889-positive.xml/.log`,
`2889-feature-final.xml/.log`, `2889-pint.log`, `2889-phpstan.log`,
`2889-control-{leave,destroy}.json/.log`, po trzy JUnit i proof JSON każdej
kontroli oraz zbiorczy `2889-controls-proof.json`. Kopia lokalna:
`C:\Users\matma\AppData\Local\Temp\kuking-koordynacja-20261003\2889-proof`.

## Granica odbioru i wycofanie

To lokalny odbiór istniejącej poprawki dostępności HTTP. Pełne CI dokładnego
heada, wydanie i odbiór produkcji pozostają do wykonania przez koordynatora.
Nie wykonano pusha, PR ani operacji na produkcji i nie zamknięto issue.
Nie zmieniano innych gałęzi ani wstrzymanego zakresu ReportContent.

Wycofanie: revert tej poprawki przywraca dwie wcześniejsze odmowy HTTP;
nie ma migracji ani danych wymagających odtwarzania. Ryzyko jest ograniczone
do dwóch nazwanych tras: członkostwo oraz właściwą rolę nadal egzekwuje
dotychczasowa Policy i akcja domenowa.
