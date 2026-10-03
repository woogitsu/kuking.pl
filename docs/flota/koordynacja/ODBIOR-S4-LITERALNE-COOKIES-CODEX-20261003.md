# Odbiór literalnych cookies S4 — #2851, #2854, #2862

## Zakres i stan wydania

Baza pracy: Y `d97250f23abd47cad12d13437b842af308be347b`, zawierająca już
uzupełnienie #2861 `14f825b98a47f98a74b62cc5e21a85fbac387d92`.
Własny worktree: `s4-literalne-cookie`, gałąź
`codex/s4-literalne-cookie-20261003`.

To uzupełnienie dowodu, bez zmian kodu aplikacji, Policy, tras, konfiguracji,
schematu, interfejsu lub zasad bezpieczeństwa. Nie cofa działającego S.
Odbiór produkcyjny i zamknięcie issues wymagają dopiero wydania Y oraz jego
pełnej bramki CI, trzech usług i produkcji. Ten dokument nie stwierdza,
że którąkolwiek z tych bramek dla Y już wykonano.

#2879 pozostaje wstrzymane po proper baseline, bez poprawki domeny.
ReportContent pozostaje wstrzymane zgodnie z wcześniejszą odmową.

## Dlaczego wcześniejszy kolejny GET A wymagał osobnego dowodu

W `tests/Dwa/bin/scenariusz.php` starsze scenariusze S4 usuwały guard i listę
sterowników menedżera sesji, ale zachowywały singleton `session.store`.
`SessionServiceProvider` tworzy ten singleton z pierwszego `manager->driver()`.
`AuthManager::createSessionDriver()` przekazuje go nowemu `SessionGuard`.
`StartSession::getSession()` po `forgetDrivers()` tworzy już inny Store,
ustawia jego ID z podanego cookie i przypina go do nowego Request.

W takim procesie guard i Request mogą czytać różne Store. Status kolejnego
GET A mógł więc wynikać z wylogowanego wcześniejszego Store, zamiast z
uwierzytelnienia odtworzonego z literalnego cookie tego GET. Nie unieważnia
to istniejących dowodów odmowy zapisu, hasła, generacji, 2FA, audytu i braku
skutków ubocznych. Niezależne odczyty B w scenariuszu `sesja-b-2fa` były
osobnymi procesami. Przy #2851/#2854 istnienie syntetycznego wiersza B nie
stanowiło jeszcze dowodu jego rzeczywistego logowania i GET.

Stare scenariusze, klasy, wzorce mutacji i uzupełnienie #2861 zachowano bez
edycji. Nowe źródła nie korzystają z ich wielożądaniowego scenariusza.

## Nowy dowód: dziewięć rzeczywistych przeplotów

`SpoznioneAkcjeLiteralnychCookiesTest` mierzy pełną macierz:

| Spóźniona akcja A | Wcześniejsza akcja B |
|---|---|
| Zmiana hasła (#2851) | reset / zmiana w ustawieniach / reset do identycznego hasła |
| Wylogowanie innych urządzeń (#2854) | reset / zmiana w ustawieniach / reset do identycznego hasła |
| Wyłączenie 2FA (#2862) | reset / zmiana w ustawieniach / reset do identycznego hasła |

Każde żądanie HTTP jest nowym procesem `literalneCookiesS4.php`, z jednym
wywołaniem rzeczywistego kernela i tras. Dotyczy to logowania, wyzwania TOTP,
zmiany/resetu, spóźnionej akcji A i każdego GET. Nie ma `actingAs`,
`Auth::login($user)`, `setUser`, ręcznego przypisania generacji ani podmiany
magazynu guarda. Każdy wynik wymaga także tożsamości
`app('session.store') === $request->session()`.

Kolejność w każdym z dziewięciu przypadków:

1. A loguje się prawdziwym formularzem; przy wyłączeniu 2FA przechodzi
   również rzeczywiste TOTP. Osobny GET z otrzymanym cookie otwiera
   ustawienia (200). GET bez cookie odmawia (302 do logowania).
2. Żądanie A przechodzi rzeczywisty poprawny `Hash::check()` i staje
   przed blokadą konta na własnej barierze doradczej. Obserwator sprawdza
   kolejkę tego procesu, zamiast zakładać kolejność po upływie czasu.
3. B kończy rzeczywisty reset albo zmianę przez HTTP. Zapisane hasło
   odpowiada B, a odpowiedź nie ma błędów formularza. B następnie loguje się
   rzeczywistym hasłem, przechodzi TOTP tam, gdzie nadal jest wymagane,
   i osobnym GET otwiera ustawienia (200).
4. Po zwolnieniu bariery A odmawia i kieruje do logowania. Nie zmienia
   hasła, generacji, remember tokenu, sekretu/potwierdzenia/kodów 2FA,
   liczby wpisów audytu ani tokenów resetu. Nie odsyła haseł przez old input
   i nie zleca listu.
5. Nowy proces GET z **identycznym ciągiem cookie B**, zachowanym sprzed
   zakończenia A, nadal otwiera ustawienia (200). Nie ma ponownego loginu B.
6. Osobne procesy GET z literalnym cookie **odpowiedzi A** oraz z cookie
   **sprzed A** oba odmawiają (302 do logowania).

Kolejne TOTP mieszczą się w istniejącym oknie ±1 i są nowsze niż ostatnio
zaakceptowane. Test nie zeruje `two_factor_last_used_at`, nie rozszerza
okna i nie zmienia zegara aplikacji. Sesja B do zmiany hasła powstaje
przed barierą A. Jeśli dwa loginy zużyły także następny slot TOTP,
przyrząd czeka na rzeczywisty zegar PRZED założeniem bariery; nie trzyma
wtedy transakcji. To zapewnia legalny trzeci login B po zmianie. Sama
kolejność zapisu i odmowy nadal jest wymuszona kolejką blokad, nie czasem.

Wstępny wspólny przebieg wykrył niestabilność wcześniejszej wersji fixture:
kod z poprzedniego slotu mógł przy granicy 30 s wypaść z okna. Naprawiono
wyłącznie przygotowanie TOTP opisane powyżej. Ten czerwony przebieg nie
stanowił dowodu awarii aplikacji ani terminalnego odbioru. Wyniki poniżej
odnoszą się do poprawionego przyrządu.

## Izolacja i kontrakt przygotowania bazy

Runtime jest rzeczywistym worktree z plikiem `.git` wskazującym na własne
`registry-s4-literalne-cookie/.git/worktrees/race_repo_s4_literalne_cookie_review`.
Przed uruchomieniem istniejącego `tests/Dwa/bin/przygotuj-baze.php` odczytano
jego kontrakt i czystą funkcją wyliczono nazwę. Ten skrypt nie przyjmuje
zamiennika nazwy przez `DB_DATABASE`; dlatego nie użyto pełnego klonu z
katalogiem `.git`, który wyliczyłby gołe `kuking_race`.

Potwierdzone przed stworzeniem bazy i po migracji:

- host `127.0.0.1`, port `55488`;
- PostgreSQL **18.6**, użytkownik i właściciel `kuking_pg18_owner`;
- baza **`kuking_race_race_repo_s4_literalne_cookie_review`**;
- runtime `/home/codex-admin/kuking-koordynacja-20261003-codex/race_repo_s4_literalne_cookie_review`;
- własny fizyczny `vendor`, identyczny `composer.lock`, własny autoload;
- `APP_BASE_PATH` tego runtime, `APP_ENV=testing`, jawne parametry DB,
  pusty `DB_URL`, nowy klucz tylko w nowej własnej `.env`.

Nie wykonywano operacji na gołym `kuking_race`, cudzych bazach ani produkcji.
Procesy Git otrzymywały osobną kopię środowiska bez `GIT_*`, z jawnym cwd;
nie zmieniano środowiska sesji ani globalnej konfiguracji Git.
Bariera, obserwator i uczestnicy mają istniejące twarde limity czasu.
Sprzątanie dotyczy wyłącznie własnych procesów oraz identyfikatorów fixture.

## Wyniki i kontrola ujemna

| Pomiar | Wynik |
|---|---|
| Nowe dziewięć przypadków Dwa | **9/649 PASS**, zero failure/error/skip |
| Stare dwie klasy S4, uzupełnienie #2861 i nowa klasa | **29/1371 PASS**, zero failure/error/skip |
| Fizyczna podmiana przekazanego cookie A na działające cookie B | **9/595 właściwe FAIL**; każdy dokładny testcase ma `COOKIE_S4_A_ODMOWA`, GET zwraca 200 zamiast 302 |
| Po dokładnym odtworzeniu bajtów i mtime | ponownie **9/649 PASS** |
| Mechanizm kontroli JUnit | **8 PASS**, z podprzypadkami odmów |
| Pint dwóch nowych źródeł | PASS |
| PHPStan dwóch nowych źródeł | 0 błędów |
| Składnia trzech zmienionych skryptów powłoki | PASS |
| Sam nowy skrypt kontroli w zakresie CI | `kod=true`, `wyscigi=true` |
| YAML CI | poprawny; nowy krok lint dokładnie raz |

Kotwica fizycznej mutacji w nowym teście:

```php
$cookieA = $wynikA['wartosc']['cookie'];
```

Zastępstwo:

```php
$cookieA = $cookieB;
```

Kontrola wymaga wszystkich dziewięciu dokładnych wariantów JUnit,
pełnych `class` i `classname`, po jednej porażce z własnym markerem w każdym
wariancie, braku skip/error i kodu wyjścia 1. Nie uznaje fatalu, obcej klasy,
duplikatu, brakującego wariantu lub innej przyczyny za dowód.

Przywrócony nowy test: SHA256
`fcbb6fc35f984addf882bf83d82872dd1b4199dea0ff066221bc0af172bfdbe8`,
mtime_ns `1791029564731580728`. Przyrząd odtwarza bajty i nanosekundowe mtime
w `finally`, także przy błędzie kontroli. Osiem starych źródeł S4/2861
porównano z HEAD oraz z zachowanymi SHA256/mtime; wszystkie pozostały zgodne.

Artefakty poza repo, w
`/home/codex-admin/kuking-koordynacja-20261003-codex/transfer/`:

- `s4-cookie-regression.xml` i `.log` — cztery klasy, 29 przypadków;
- `s4-cookie-control.log` — dodatni, dziewięć właściwych porażek, restore i dodatni;
- `s4-cookie-retained-sources.json` — osiem zachowanych źródeł i mtime.

Dziewięć nowych przypadków w rzeczywistym JUnit wspólnej regresji ma
łącznie 649 asercji. Każdy przebieg fizycznej kontroli jest osobno odczytany
z rzeczywistego JUnit przed usunięciem jego katalogu tymczasowego.

## Wpięcie i ograniczenia

Nowa klasa należy do istniejącej grupy `dwa-polaczenia`. Nowy skrypt kontroli
jest osobnym obowiązkowym wywołaniem w pełnym CI tej grupy. Test przyrządu
jest osobnym krokiem lint oraz lokalnego `check.sh`. Zakres zawiera tylko
dodany wariant `s4-cookies`; stare wzorce pozostają.

Nie wykonano pełnego `check.sh`, pełnej grupy Dwa, pełnego PHPStan, push,
PR, CI ani wdrożenia. Te bramki należą do późniejszego odbioru wspólnej Y.
Nie zmieniono issues ani nie zamknięto ich na podstawie samego tego pomiaru.
