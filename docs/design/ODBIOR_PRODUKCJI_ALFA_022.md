# Odbiór produkcji — Alfa 0.22

## Końcowy wynik — 14 września 2026, około 08:00 czasu Warszawy

**Alfa 0.22 jest wdrożona.** Produkcyjny kod:
`a3cb64df819351b18450603c1dcabe775aa748f0`.

- Railway deployment **6428309650 success**, od **05:58:05Z**.
- Właściwy workflow **Deploy34811540912 success**. Wstępne przebiegi
  34793185366 i34811476825 były skipped, nie dowodzą wdrożenia.
- Main CI34793180863, próba3: success, wszystkie10 wyników success;
  dwie przerwane próby i zakres ponowienia opisano niżej.
- Rzeczywisty GET HTTP200 oraz zalogowany Chrome: **Alfa 0.22**,
  „wydanie14września2026,07:57 · a3cb64d”.
- CSS `app-BZTD7N58.css`, HTTP200, SHA256
  `c8603ded009b0ca0061ca2688f30c313d9a9d10378650f89c90f38c9b64428fc`.
- JS `app-DXNAnudp.js`, HTTP200, SHA256
  `e94fdd3ddd8d45e79644a222e092c5a0b80d3a21f4941d8af8684425a4749d75`.
- Oba lokalne fonty Inter odpowiedziały200 jako font/woff2:
  latin-ext DO1Apj_S (85068b) i latin Dx4kXJAl (48256b).

### Rzeczywisty ogląd i jego granice

Obejrzano zrzut zalogowanej wyszukiwarki na komputerze, w jasnym motywie:
nowa sekcja „Polecane tagi”, komunikat „Nie ma jeszcze polecanych tagów”
oraz przycisk „Wszystkie tagi”. Rzeczywiste kliknięcie otworzyło /tagi;
obejrzano również jego pusty stan i tę samą metryczkę wersji.
Nie ma produkcyjnych tagów do oglądu pełnych kafli. Nie dodawano
przykładowej treści ani promocji, nie wykonywano POST i nie zmieniano
preferencji konta. Własne karty odbioru oraz Railway zamknięto.

To odbiór dwóch pustych stanów desktopowych, nie ponowny test wszystkich
szerokości, motywów ani skal na produkcji. Pełne kafle, mobile, powiększenie
i klawiatura mają oddzielny dowód lokalny oraz CI w POLECANE_TAGI_515.md.
Cała identyfikacja pozostaje **CZĘŚCIOWO** odebrana według macierzy.

## Historia kontroli i przerwanego wdrożenia

## Kod i kontrole PR

PR #521, head `1cb2ab5f485a0130a992b1cd4e5ba8db2a02b672`, scalony jako
`a3cb64df819351b18450603c1dcabe775aa748f0`.
CI PR 34792102646: 10 zadań success; PHP: 3726 testów / 75240 asercji.
Pełny lokalny obowiązkowy hook i zwykły push przeszły. Zakres lokalnych
pomiarów i rzeczywistych negatywów: POLECANE_TAGI_515.md oraz
SPROSTOWANIE_INSTRUKCJI_516.md. Pełna marka pozostaje CZĘŚCIOWO.

## Pierwsza próba CI na main — przerwana infrastruktura

CI 34793180863, próba 1, zakończył się failure: osiem sukcesów i dwa
nieudane zadania przeglądarkowe. Nie było podstaw do osłabiania testów.

- Port marki103821176446, runner kuking-wsl-DOM-NEW-03: o00:44:26Z
  log informuje „The runner has received a shutdown signal”. Dopiero
  potem pojawia się K513_HTTP i anulowanie operacji. Przyczyna wysłania
  sygnału zatrzymania nie została ustalona.
- Dostępność103821176537, runner kuking-wsl-DOM-NEW-01: adnotacja mówi
  o utracie komunikacji runnera z serwerem. Log zwracał404 BlobNotFound.
  Nie przypisujemy tego konkretnej asercji aplikacji ani przyczynie
  sprzętowej, której nie potwierdzono.
- Railway6428309650 dla a3cb64d przeszedł do inactive; wstępny
  Deploy34793185366 był skipped. To nie jest dowód działającej 0.22.
- Po odczycie wszystkich trzech runnerów jako online/idle zlecono tylko
  rerun-failed-jobs tego samego SHA. Bez zmian źródeł, progów, timeoutów
  i reguł continue-on-error.

## Historyczny stan po pierwszej próbie

Ponowienie CI i właściwe wdrożenie są w toku. Ostatni rzeczywisty odczyt
zalogowanego Chrome na /szukaj wskazywał Alfa 0.21 / ae6b519 oraz poprzedni
pusty stan wyszukiwarki. Nie deklarujemy wdrożenia 0.22 na podstawie merge.


## Druga próba i odtworzenie środowiska runnerów

Próba 2 przerwała oba zadania przed testami: port103869694405 podczas
startu kontenera PostgreSQL, dostępność103869694556 podczas checkoutu.
Oba logi zawierają sygnał shutdown o05:35:02Z. Lokalny journald potwierdza
w tej samej chwili zatrzymywanie wszystkich runnerów, Dockera i wejście
systemd w shutdown.target; nie było to zakończenie asercji aplikacji.
Od05:31Z środowisko uruchamiało się i zamykało wielokrotnie.

Dokumentacja Microsoft wyjaśnia, że same usługi systemd nie utrzymują
aktywnej instancji WSL:
https://learn.microsoft.com/en-us/windows/wsl/systemd
To jest wyjaśnienie mechanizmu zgodne z obserwacją, nie dowód konkretnego
zewnętrznego polecenia zamykającego komputer w pierwszej próbie.
Na czas ponowienia utrzymano jawną sesję WSL z ograniczonym czasem życia;
nie zmieniano konfiguracji Windows, etykiet runnerów, progów ani testów.
Próba 3 dotyczy wyłącznie ponowienia nieudanych zadań na tym samym SHA;
osiem wcześniejszych sukcesów nie jest nowym wykonaniem pełnego PHP.

Po ponownym uruchomieniu nie istniały już katalog native ani baza
z poprzedniej sesji w /tmp. Do lokalnego hooka odtworzono izolowaną kopię
z aktualnych źródeł, zależności z locków i nową bazę na porcie 55439. Nie używano
współdzielonego PostgreSQL na porcie 5432 ani produkcyjnych danych.


## Podtrzymanie WSL i ograniczenie dostępu Windows

Niezależny odczyt znalazł istniejący skrót autostartu „WSL GitHub Runners
Keepalive” z `wsl.exe -d Ubuntu --exec /usr/bin/sleep infinity`; jego proces
nie działał. Zadanie „Start WSL runners” wykonywało jedynie program `true`.
Przed próbą poprawy zapisano jego XML poza wersjonowanymi plikami.
Windows odmówił Set-ScheduledTask (0x80070005); odczyt potwierdził zachowaną
akcję uruchamiającą program `true`. Nie zmieniono uprawnień, właściciela ani wyzwalaczy.

Uruchomiono więc ukryty proces podtrzymania jako obecny użytkownik,
z takim samym poleceniem jak istniejący autostart. Proces działa niezależnie
od narzędzia wykonawczego; nie jest nową usługą ani pulą runnerów.
Długotrwały test po wylogowaniu/reboocie nie został wykonany. Trwała poprawa
chronionego zadania startowego wymaga uprawnienia, którego ta sesja nie ma.
Nie należy prezentować podtrzymania bieżącej sesji jako potwierdzenia
nieprzerwanej pracy runnerów po restarcie Windows.


## Wznowienie wydania po zielonym CI

Próba3 CI34793180863 zakończyła się success: wszystkie10 zadań mają wynik
success. Ponowiony port103870165330 (kuking-wsl-DOM-NEW-02) działał
od05:37:33Z do05:55:26Z i zaliczył również kreator. Dostępność103870165539
zakończyła się sukcesem. Poprzednich przerwań nie wykasowano z historii.

Railway nadal pokazywał pominięte wydanie PR521 z dawnym komunikatem
„CI check suite failed”. Po ponownym odczycie wszystkich10 sukcesów
uruchomiono w panelu działanie „Deploy commit” dla tego konkretnego
wydania cdb8f9ff-5d98-4b95-9fc6-44253c1fb3d0, SHA a3cb64d.
Nie zmieniono reguł Wait for CI, watch paths ani ustawień bezpieczeństwa.
Panel potwierdził „Deploy started”, a GitHub deployment6428309650 wrócił
do in_progress o05:56:57Z. Nie redeployowano starszego aktywnego PR520.
