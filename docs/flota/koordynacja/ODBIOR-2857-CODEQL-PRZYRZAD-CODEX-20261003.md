# #2857 — odłączony parser dokumentu przyrządu przeglądarkowego

Data: 3 października 2026. Baza zmiany:
`30be87d7d6c140275cce12d05f8a253b29452951`.

## Powód i zakres

Zagregowany check CodeQL `111215204806` odmówił dla tego heada, mimo
powodzenia trzech zadań Analyze w runie `37127298926`. Dwa nowe alerty
dotyczyły filtra w `scripts/przegladarka/dopisek-2857-laravel.test.mjs:55`:

- [alert 17, js/bad-tag-filter](https://github.com/woogitsu/kuking.pl/security/code-scanning/17):
  pominięcie zamknięcia `</script >`;
- [alert 18, js/incomplete-multi-character-sanitization](https://github.com/woogitsu/kuking.pl/security/code-scanning/18):
  jednokrotne usunięcie może złożyć nowy element `script`.

To filtr lokalnego fixture, a nie filtr treści produkcyjnych. Zmieniono
istniejący test, dodano jego mały moduł parsera oraz ten odbiór. Kod aplikacji,
widoki, pliki zależności i konfiguracja CI pozostają takie jak w bazie.

`DOMParser` pracuje na pustej stronie Chromium i tworzy odłączony dokument.
Moduł usuwa wszystkie rzeczywiste elementy `script`, dodaje odsyłacz do CSS
metodami DOM i serializuje dokument. Nie przenosi jego węzłów do bieżącej
strony. Poboczne skrypty fixture nie są uruchamiane. Jest to przygotowanie
znanego fixture tego testu, nie ogólny sanitizer HTML użytkownika.

Zachowano wszystkie dotychczasowe asercje formularza: rzeczywisty widok
Laravela, pola, potwierdzenie zastąpienia, FileList i bajty w żądaniu multipart,
320 px oraz 200%. POST jest przechwycony przez przyrząd: wynik nie potwierdza
zapisu wykonania przez serwer. Proces potomny `artisan test` nadal ma jawne
`APP_ENV=testing` i `APP_URL=http://localhost`.

Dwie nowe próby wywołują rzeczywisty moduł w Chromium. Sprawdzają oba stare
obejścia, brak wykonania skryptu i dołączenia formularza do strony, zachowanie
pól oraz dodanie CSS. Zwykły element `script` jest przed próbką obejścia,
żeby nie dostarczał staremu filtrowi kolejnego pasującego zamknięcia.
Istniejące wywołanie tego samego pliku testowego wykonuje teraz trzy przypadki;
nie dodano drugiego wywołania CI. Wszystkie korzystają z jednego procesu
Chromium i osobnych, zamykanych kontekstów.

## Izolacja i narzędzia

Własny worktree: `2857-codeql-przyrzad`, gałąź `codex/2857-codeql-przyrzad`.
Wykonawcza kopia jest rzeczywistym odrębnym worktree z fizycznymi kopiami
`vendor` i `node_modules`:
`/home/codex-admin/kuking-koordynacja-20261003-codex/repo_2857_codeql_review`.
Każdy proces Git ma jawne cwd i kopię środowiska bez odziedziczonych `GIT_*`;
nie zmieniano globalnej konfiguracji ani środowiska sesji.

Przed utworzeniem potwierdzono nieistnienie docelowej bazy oraz parametry
klastra. Rzeczywisty odczyt po przygotowaniu: baza
`kuking_test_2857_codeql_20261003`, host `127.0.0.1`, port `55488`, PostgreSQL
18.6, użytkownik i właściciel `kuking_pg18_owner`. Nie używano portu 5432 ani
domyślnego `kuking_race`, cudzych baz lub produkcji. Fixture działały kolejno.
Proces rodzica ma `APP_ENV=local`; wyłącznie potomny test ma `testing`.

PHP 8.4.26, Node 22.23.2, Playwright 1.63.0. Użyto istniejącego Chromium
153.0.8010.12, zgodnego z revision 1243 z zastanej biblioteki:
`/opt/woogitsu/playwright-browsers/chromium-1243/chrome-linux64/chrome`.
Nie instalowano przeglądarki ani biblioteki. Własny Vite build zakończył się
kodem 0. Oba zmienione pliki `.mjs` przeszły `node --check`.

## Wynik i fizyczna kontrola ujemna

Kontrolę wykonał zastany `scripts/kontrola-ujemna.sh`, mutując ciało
`przygotujDokument` do wcześniejszego jednokrotnego filtra regex i podmiany
`</head>`. Dokładne łańcuchy są w `mutation.from.txt` i `mutation.to.txt`.
W każdej fazie uruchomiono pełny plik testu, włącznie z prawdziwym formularzem.

| Faza | Rzeczywisty formularz | Oba obejścia | JUnit |
|---|---|---|---|
| Przed mutacją | PASS | 2 PASS | 3 PASS, 0 failure/error/skip |
| Fizyczny stary filtr | PASS | 2 właściwe FAIL | 3 przypadki, 2 failure, 0 error/skip |
| Po dokładnym przywróceniu | PASS | 2 PASS | 3 PASS, 0 failure/error/skip |

Ścisły czytnik surowego JUnit wymagał dokładnie tych trzech pełnych nazw,
klasy `test`, braku skip/error, kodu procesu 1 wyłącznie przy mutacji oraz
dwóch `AssertionError/ERR_ASSERTION` z markerami:
`PRZYRZAD_2857_DOM_KONIEC_SCRIPT` i `PRZYRZAD_2857_DOM_PONOWNE_SCRIPT`.
Obie porażki mają przyczynę `1 !== 0` w liczbie rzeczywistych elementów
`script`. Formularz ma PASS także przy mutacji. Timeout, SQLSTATE, obca
porażka, brak/uszkodzenie JUnit lub brak jednej wymaganej porażki oznaczały
odmowę dowodu; czytnik wtedy nie wypisywał markerów oczekiwanej przyczyny.

Werdykt terminalny: `POTWIERDZONA`, kod 0. Jedna fizyczna podmiana:
MD5 `7f855e445037e6e10e382cb31fb44cd6` →
`d228f5cc807f14eee4dc479b038fd053`. Po przywróceniu ponownie pierwsza suma,
identyczne bajty i mtime `1791036446617161884` ns. SHA-256 modułu:
`9eb5d4bb668d24d0a0d82f853cea149b9449d0ff0cbeff44aed78a2d42d44956`.
SHA-256 finalnego pliku testowego:
`42105778822f3a680c39ee3a1c3368541b884fad76cc9a1c367f2d57a89a83fb`.

Pierwsza próba kontroli została uczciwie odrzucona: zwykły skrypt był za
próbką `</script >`, więc stary regex pochłaniał oba fragmenty i powstała
tylko jedna właściwa porażka. Czytnik zwrócił 99, przyrząd
`ZLA_PRZYCZYNA`/4, exact restore był poprawny. Te wyniki są zachowane w
`rejected-attempt-1` i nie są zaliczone jako kontrola. Po korekcie kolejności
próbki wykonano kompletny terminalny przebieg z tabeli.

## Surowe dowody i ograniczenia

Katalog dowodów na normalhp:
`/home/codex-admin/kuking-koordynacja-20261003-codex/transfer/2857-codeql-proof`.
Lokalna kopia:
`C:\Users\matma\AppData\Local\Temp\kuking-koordynacja-20261003\2857-codeql-proof`.

Zawiera `before.junit.xml`, `mutant.junit.xml`, `after.junit.xml`, osobne
kody i stderr faz, `physical-control.json/log/exit`, ścisły `run-strict.py`,
parametry w `runtime.json`, oba wykonane źródła, snapshoty źródła przed,
podczas i po mutacji oraz `artifact-sha256.json` z sumami wszystkich dowodów.
Przywrócenie potwierdzają niezależnie snapshoty i trap zastanego przyrządu.

Nie uruchamiano pełnego `check.sh`, produkcji ani nowych zadań GitHub.
Nie dismissowano alertów, nie wykonano push/PR/merge. Ten odbiór dowodzi
lokalnej poprawki i regresji przyrządu; zamknięcie blokady wydania wymaga
późniejszego rzeczywistego CodeQL dla zintegrowanego heada.
