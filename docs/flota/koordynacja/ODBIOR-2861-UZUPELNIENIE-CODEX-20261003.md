# #2861 — literalne ciasteczko moderatora i okno potwierdzenia 2FA

Data pomiaru: 3 października 2026. Baza kodu:
`09f8af1c738789c4498d35158f940ac237c30eee` (wydanie S, PR #2886).

**Korekta odbioru przyrządu:** wyniki poniżej opisują pierwotny pomiar
z `14f825b98`. Niezależny przegląd wykrył, że porażka okna mogła również
pochodzić z błędu B. Odtworzenie tej luki i nowy dowód po poprawce są
w końcowej sekcji „Korekta: awaria procesu B nie potwierdza mutacji”.

## Zakres

Uzupełnienie dwóch wykonawczych kryteriów #2861. Kod aplikacji, Policy,
middleware, konfiguracja produktu i sześć dotychczasowych scen wyścigu
#2861/#2862 pozostają bez zmian. Nowe pliki to klasa Dwa, jej osobny
uczestnik HTTP, kontrola ujemna i test wyniku tej kontroli. Wpięcia dodają
wyłącznie nowe wywołania oraz ścieżkę nowej kontroli do zakresu wyścigów.

Nie powstał drugi produktowy sposób włączania 2FA. Test wywołuje rzeczywiste
trasy przez HTTP kernel. To pomiar lokalny w trybie `testing`, z normalnym
testowym kontraktem Laravela dla CSRF; nie zmienia ochron aplikacji.
Nie jest oglądem przeglądarki ani produkcji.

## Izolacja

Własny runtime jest rzeczywistym worktree, z plikiem `.git` wskazującym na
`registry-2861-literalny-odbior/.git/worktrees/race_repo_2861_literalny_review`.
Przed uruchomieniem `tests/Dwa/bin/przygotuj-baze.php` odczytano jego kontrakt
i wyliczono nazwę czystą funkcją `kuking_nazwa_testowej_bazy()`:

| Parametr | Potwierdzony stan |
|---|---|
| Runtime | `/home/codex-admin/kuking-koordynacja-20261003-codex/race_repo_2861_literalny_review` |
| Baza Dwa | `kuking_race_race_repo_2861_literalny_review` — przed przygotowaniem nie istniała |
| Host / port | `127.0.0.1:55488` |
| Silnik | PostgreSQL 18.6 |
| Rola i właściciel bazy | `kuking_pg18_owner` |
| Vendor | fizyczna kopia; identyczny `composer.lock`, własny `dump-autoload` |
| Aplikacja | jawne `APP_BASE_PATH` i `APP_ENV=testing`, własny klucz |

Nie używano domyślnego `kuking_race`. Procesy Git miały jawną lokalizację
i własną kopię środowiska bez `GIT_*`; środowisko sesji i globalna
konfiguracja Git nie zostały zmienione. Zastane zabezpieczenia fixture
#2871 pozostają bez zmian.

Przyrząd porównuje cel z nazwą wyliczoną dla aktualnego checkoutu. Gołe
`kuking_race` obsługuje wyłącznie w zastanym kontrakcie izolowanego CI
(`CI=true`); lokalnie odmawia tego celu. Nie zmienia sposobu nazywania baz.

## Co wykonują testy

### Literalne ciasteczko A i panel moderatora — trzy warianty

`Potwierdzenie2faPodWspolnaBlokadaTest::test_spoznione_potwierdzenie_nie_otwiera_panelu_literalnym_cookie`
mierzy reset, zmianę hasła i reset do identycznego hasła.

1. Moderator loguje się prawdziwym POST-em hasła i POST-em TOTP.
   GET `admin.reports` z otrzymanym ciasteczkiem daje **200**.
2. Ta przeglądarka legalnie wyłącza 2FA POST-em z hasłem i przygotowuje
   nowy składnik przez rzeczywisty GET ustawień. Ustawienia nadal dają 200.
   Kontrola panelu następuje przed tym świadomym wyłączeniem 2FA: panel
   z niepotwierdzonym składnikiem nie mógłby być poprawną kontrolą dodatnią.
3. A wysyła POST potwierdzenia nowego składnika. Bariera zatrzymuje A
   po sprawdzeniu hasła i ważnego kodu, przed blokadą finalnego zapisu.
4. B kończy właściwą zmianę/reset; następnie bariera zwalnia A.
5. Osobny proces wykonuje GET ustawień oraz GET panelu z **dosłownym
   ciasteczkiem odpowiedzi A**. Oba żądania odsyłają do logowania.
   Tak samo sprawdzane jest dosłowne ciasteczko sprzed A. Odczyt nie loguje
   ponownie i nie podkłada użytkownika, generacji ani dowodu 2FA.
6. Hasło, generacja, `remember_token`, sekret i audyt pozostają stanem B;
   2FA nie jest potwierdzone, kody zapasowe nie powstają, listów jest zero,
   `old` nie zawiera wpisanych poświadczeń.

Między żądaniami przyrząd usuwa guardy, sterowniki sesji i singleton
`session.store`. Bez usunięcia singletona kontrola w jednym procesie
mogłaby czytać poprzednią sesję zamiast dosłownego ciasteczka. Przeniesienie
ciasteczka do kolejnego procesu rzeczywiście wykryło ten błąd przyrządu
podczas przygotowania, zanim zaczęto zbierać dowód domenowy.

### Okno `confirmTwoFactor()` → `invalidateSessions()` — dwa warianty

`Potwierdzenie2faPodWspolnaBlokadaTest::test_potwierdzenie_i_odwolanie_sesji_serializuja_reset`
mierzy reset do nowego oraz identycznego hasła.

Bariera `beforeExecuting` staje bezpośrednio przed pierwszym SQL-em
`invalidateSessions()`, po wykonanym przez akcję `confirmTwoFactor()`.
Na połączeniu A potwierdzenie istnieje i poziom transakcji wynosi 1.
Obserwator nadal widzi niepotwierdzone 2FA. Prawdziwy proces B resetu
czeka na `FOR UPDATE` konta: `pg_blocking_pids(B)` zawiera PID A.
To potwierdza blokadę konkretnego procesu, a nie samą obecność dowolnego
oczekującego zapytania. Synchronizacja opiera się na kolejce blokad,
nie na założeniu, że uczestnik zdążył po upływie czasu.

Po zwolnieniu bariery A kończy jedną transakcję, a B kończy reset.
Końcowe hasło należy do B, generacja wzrasta o dwa, 2FA jest potwierdzone,
a literalne ciasteczka sprzed A i z odpowiedzi A nie otwierają ustawień
ani panelu moderatora.

## Wyniki i kontrole ujemne

| Pomiar | Wynik |
|---|---|
| Nowa klasa Dwa | **5/219 PASS**, zero failure/error/skip |
| Stare dwie klasy S4 oraz nowa klasa | **20/722 PASS**, zero failure/error/skip |
| Usunięcie świeżego sprawdzenia sesji | **3 właściwe FAIL + 2 PASS**; marker `2FA_2861_COOKIE_A_PANEL_ODMOWA`, panel zwracał 200 |
| Wyniesienie `invalidateSessions()` poza blokadę/transakcję | **2 właściwe FAIL + 3 PASS**; marker `2FA_2861_OKNO_WSPOLNA_BLOKADA`, reset nie czekał na A |
| Po każdej fizycznej mutacji | dokładne odtworzenie bajtów i mtime, ponownie **5/219 PASS** |
| Przyrząd JUnit | **8 PASS**; odmawia braku/uszkodzenia raportu, obcej klasy/metody, duplikatu, braku przypadku, skip/error, złego markera, dwóch porażek, złej rodziny i kodu fatalu |
| Pint dwóch nowych źródeł | PASS |
| PHPStan dwóch nowych źródeł | 0 błędów |
| Składnia trzech zmienionych skryptów powłoki | PASS |
| Zakres dla samego nowego skryptu kontroli | `kod=true`, `wyscigi=true` |
| YAML CI | poprawny; nowe wywołanie przyrządu w lint dokładnie raz |

Kontrola ujemna wymaga wszystkich pięciu dokładnych przypadków JUnit,
właściwych pełnych `class`/`classname`, osobnej przyczyny każdej porażki
i kodu wyjścia 1 mutanta. Zielony przypadek z drugiej rodziny także musi
pozostać zielony. Przyrząd zachowuje surowe bajty i nanosekundowe mtime
w `finally`, również przy nieudanym przebiegu.

Odtworzone źródło `app/Domain/Security/Actions/WlaczDwuetapowa.php`:

- SHA256: `d2ed765f07e0333d32bd8c040fbc377b6d371f5740f29c76a24cadcbc74a7c1f`;
- mtime_ns własnego runtime: `1791026108811417807`;
- bajty są identyczne z bazowym commitem przed i po obu kontrolach.

Zastane `SpoznioneWlaczenieIWylaczenie2faTest.php`, `bin/scenariusz.php`
i `kontrola-negatywna-2861-2862.py` mają identyczne bajty z bazowym commitem.
Stare sześć scen, ich wzorce i trzy dodatnie przypadki wykonano ponownie
w przebiegu 20/722.

Artefakty pomiaru na tym samym stanowisku, w katalogu
`/home/codex-admin/kuking-koordynacja-20261003-codex/transfer/`:

- `2861-uzupelnienie-positive.xml` — 5 przypadków dodatnich;
- `2861-uzupelnienie-regression.xml` — 20 przypadków wspólnych;
- `2861-uzupelnienie-mutants.log` — dwie fizyczne mutacje, własne porażki,
  dokładne restore i zielone przebiegi po odtworzeniu.

## Granica odbioru

To uzupełnienie dowodu, bez poprawki działającego kodu aplikacji. Nie
uruchomiono pełnego `check.sh`, całej grupy Dwa, całego PHPStan ani nowego CI.
Pełny odbiór i wydanie paczki Y należą do sesji głównej. Lokalny dowód tych
dwóch scen nie zamyka issue i nie zastępuje bramki produkcyjnej właściwego
wydania. Powrót polega na cofnięciu samych testów i nowych wywołań.

## Korekta: awaria procesu B nie potwierdza mutacji

3 października 2026, nowa gałąź `codex/2861-blad-procesu-b-20261003`
od Y `ae28fadca67abe6981a7627ddc62bf5b2dd41d01`. Własny lokalny WT:
`C:\Users\matma\.codex\worktrees\2861-blad-procesu-b\Portale`.
Zakres to istniejąca nowa klasa Dwa, jej własny helper, kontrola i test
werdyktu oraz ten receipt. Domain/Policy, stare S4 i duży `bin/scenariusz.php`
nie zostały zmienione. Nie dubluje to osobnej macierzy dziewięciu cookies S4.

### Wykryta luka i rzeczywisty baseline

Stare `assertResetCzekaNaWlaczenie()` po zakończeniu B lub przekroczeniu
czasu zgłaszało `2FA_2861_OKNO_WSPOLNA_BLOKADA` bez odczytu wyniku B.
`sprawdz_junit()` odrzucał JUnit ERROR, lecz wynik ten miał postać FAILURE
z oczekiwanym markerem. Poprzedni dowód nie wykluczał więc błędu
infrastruktury jako przyczyny czerwieni okna.

W nowym runtime najpierw wykonano niezmienioną klasę: **5/219 PASS**.
Następnie fizycznie zastąpiono wyłącznie start B własnym etapem `blad-b`,
wykonującym zapytanie do nieistniejącej tabeli fixture. Proces B zwrócił
rzeczywisty JSON `ok=false`, `sqlstate=42P01`,
`wyjatek=Illuminate\Database\QueryException`. Stara klasa dała **2 FAILURE
+ 3 PASS**, kod 1, a stary werdykt **przyjął to jako mutację okna**.
Po dokładnym przywróceniu bajtów i mtime klasy ponownie **5/219 PASS**.
To błąd przyrządu; sam dodatni wynik nie usuwał tej luki.

### Poprawiony warunek dowodu

- Kolejka musi należeć do rzeczywistego B (`PGAPPNAME=uzupelnienie2861-reset-b`),
  blokowanego przez A z `application_name=uzupelnienie2861-wlacz` na własnej
  bazie; nadal mierzymy `pg_blocking_pids`, Lock i prawdziwy SELECT FOR UPDATE.
- Marker `2FA_2861_OKNO_WSPOLNA_BLOKADA` powstaje wyłącznie po zakończeniu B,
  poprawnym JSON (`ok=true`, brak SQLSTATE/wyjątku, `bledy=[]`) i sprawdzeniu
  zatwierdzonego hasha oraz wzrostu generacji sesji o jeden w świeżym wierszu.
  Dopiero ten dowód pozwala nazwać zakończony reset ominięciem blokady A.
- Błąd B, niepoprawny/brakujący wynik i odmowa resetu dają RuntimeException
  `2FA_2861_PROCES_B_BLAD`; niepotwierdzona kolejka z nadal działającym B daje
  `2FA_2861_PROCES_B_TIMEOUT`. Żaden z nich nie niesie markera mutacji.
  Diagnostyka bariery A ma osobny marker `2FA_2861_BARIERA_POTWIERDZENIA`.
- Werdykt odmawia ERROR/SKIP oraz awarii B/SQLSTATE również podszytej pod
  FAILURE. Osobna kontrola awarii wymaga dokładnie trzech zielonych cookies,
  dwóch ERROR z własnym `42P01`, pełnej tożsamości pięciu przypadków i kodu 2;
  następnie sprawdza, że zwykły werdykt mutacji odmówił.
- Fizyczna kontrola zachowuje i sprawdza bajty oraz nanosekundowe mtime
  w `finally`, także gdy sam werdykt odmówi. Źródło oraz nowa klasa są
  po każdej próbie odtworzone i ponownie wykonywane dodatnio.

### Izolacja i wyniki poprawki

Runtime jest osobnym Git worktree
`/home/codex-admin/kuking-koordynacja-20261003-codex/race_repo_2861_b_guard`
z własnym fizycznym `vendor` zgodnym z `composer.lock`. Rejestr:
`registry-2861-b-guard/.git/worktrees/race_repo_2861_b_guard`.
Przed migracją ustalono i sprawdzono **PostgreSQL 18.6**, host `127.0.0.1`,
port `55488`, właściciela `kuking_pg18_owner` i bazę
`kuking_race_race_repo_2861_b_guard`; ta baza wcześniej nie istniała.
Klucz powstał tylko w nowej instancji. Nazwę Dwa potwierdziła funkcja
`kuking_nazwa_bazy_wyscigow()` tego worktree.

| Pomiar | Wynik |
|---|---|
| Poprawiona klasa i każde odtworzenie | **5/217 PASS**, bez failure/error/skip |
| Stare dwie klasy S4 plus poprawiona nowa klasa | **20/720 PASS**, bez failure/error/skip |
| Usunięcie świeżego sprawdzenia sesji (fizyczny FIX z 14f) | **3 właściwe FAILURE + 2 PASS**, `2FA_2861_COOKIE_A_PANEL_ODMOWA` |
| Wyniesienie odwołania sesji poza wspólną transakcję (fizyczny FIX z 14f) | **2 właściwe FAILURE + 3 PASS**, poprawny reset B zatwierdzony przed zwolnieniem A |
| Fizyczne zastąpienie B procesem z błędem SQL | **2 ERROR + 3 PASS**, własny `2FA_2861_PROCES_B_BLAD`, `42P01`, kod 2; odrzucone jako mutacja |
| Testy parsowania/werdyktu i odtworzenia po odmowie | **13 PASS** na Windows i Linux |
| Pint, składnia PHP, PHPStan obu źródeł PHP | PASS, 0 błędów PHPStan |

Dwa ubytki asercji wynikają z usunięcia asercji `B->trwa()` z markerem
mutacji; tożsamość, liczba i zakres pięciu przypadków pozostają takie same.
Rejestr strażników źródeł przeczytano przed zmianą: nowe sprawdzenia mierzą
wykonanie procesu i stan bazy, nie tekst źródła. Nie powstał nowy strażnik
czytający pliki.

Wpięcia są już obecne i zachowane: `scripts/check.sh` i lint CI wykonują
ten sam test werdyktu, a `scripts/testy-dwa-polaczenia.sh` uruchamia ten sam
przyrząd po dodatniej grupie. `scripts/ci/zakres.sh` obejmuje `tests/Dwa/`
i istniejącą kontrolę 2861-uzupelnienie. Nie usunięto żadnego innego wywołania.

### Artefakty korekty i granica odbioru

Katalog `/home/codex-admin/kuking-koordynacja-20261003-codex/` zawiera:

- `2861-b-guard-baseline.json`, `2861-b-guard-b-error.json` oraz baseline
  JUnit/logi, w tym `2861-b-guard-baseline-b-error.xml` i przywrócenie;
- `2861-b-guard-final-control.log`, `2861-b-guard-final.json` i katalog
  `2861-b-guard-final-proof/` z siedmioma dokładnymi JUnit i logami:
  dodatni, cookie, restore, okno, restore, awaria B, restore;
- `2861-b-guard-regression.xml/.log`, `2861-b-guard-python.log`,
  `2861-b-guard-phpstan.log` oraz `2861-b-guard-pint.log`.

Kopię dowodów zapisano poza repo w
`%TEMP%\kuking-2861-b-guard\`. Pliki JSON i log przyrządu zapisują SHA256
i nanosekundowe mtime odtworzonej domeny oraz nowej klasy:

| Odtworzony plik | SHA256 | mtime_ns runtime |
|---|---|---|
| `WlaczDwuetapowa.php` | `d2ed765f07e0333d32bd8c040fbc377b6d371f5740f29c76a24cadcbc74a7c1f` | `1791030159795698958` |
| `Potwierdzenie2faPodWspolnaBlokadaTest.php` | `71aa2844b14a77ad73180abcd8aefdb6d4b700ab8006fd9444210a1a837d80e0` | `1791030714416151623` |

SHA256 czterech finalnych źródeł przyrządu porównano między runtime
i lokalnym WT: identyczne. Domenę i stare trzy źródła S4 oraz starą kontrolę
porównano bajt po bajcie z bazą Y: identyczne.

Pełny check/CI,
odbiór Y i wydanie pozostają zadaniem sesji głównej; nie wykonano push,
PR ani operacji produkcyjnych. Ten odbiór nie zamyka zbiorczego #2861.
