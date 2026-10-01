## D-316 — Pakiety APT w Dockerfile-ach przypięte do migawki snapshot.debian.org, nie do wersji (audyt, issue #1868, 26 września 2026)

**Data:** 26 września 2026 · Status: **obowiązuje** · Decyzja z audytu
bezpieczeństwa · Rozszerza **#952** (obrazy bazowe przypięte do digestu)

**Problem.** Obraz bazowy każdego Dockerfile jest przypięty do digestu
(`FROM ...@sha256:...`, issue #952), ale pakiety APT instalowane W ŚRODKU
tych obrazów (`postgresql-client`, `tini` w głównym `Dockerfile`; `openssl`,
`curl`, `ca-certificates` w `docker/kopia/Dockerfile`) schodziły ze zwykłego
`deb.debian.org/debian trixie`. To jest mirror NAJNOWSZEGO PUNKTU WYDANIA,
nie archiwum — starsza wersja pakietu znika z niego, gdy tylko wyjdzie
kolejna poprawka. Ten sam commit i ten sam digest obrazu bazowego mogły więc
w poniedziałek i w piątek dać dwa różne `pg_dump`/`tini`/`openssl` w środku
obrazu, bez żadnej widocznej zmiany w repozytorium.

**Rozważona i ODRZUCONA alternatywa: literalne przypięcie wersji
(`apt-get install postgresql-client=X.Y-Z`).** To jest DOKŁADNIE pułapka,
przed którą ostrzega treść zgłoszenia: `deb.debian.org` trzyma tylko
najnowszy punkt wydania. Wersja przypięta dziś literalnie znika z niego przy
następnej łatce bezpieczeństwa Debiana — i wtedy `apt-get install
pakiet=stara-wersja` nie znajduje jej WCALE, a build, który wczoraj
przechodził, dziś pada na `Version 'X.Y-Z' for 'pakiet' was not found`.
Literalne przypięcie wersji byłoby więc MNIEJ stabilne niż stan wyjściowy,
nie bardziej.

**Decyzja: migawka `snapshot.debian.org`, nie numer wersji.** To jest pełne,
zamrożone co ~6 godzin i NIGDY nie kasowane archiwum całej historii Debiana
— usługa, którą sam projekt Debian utrzymuje właśnie do odtwarzania starych
buildów. Zamiast pinować NUMER pakietu, pinujemy CZAS: `ARG
SNAPSHOT_DEBIAN=<RRRRMMDDTHHMMSSZ>` w obu Dockerfile-ach wskazuje jedną,
zamrożoną migawkę całego archiwum, a `apt-get install postgresql-client
tini` (bez numerów wersji) bierze z niej to, co tam wtedy stało — zawsze te
same bajty, bo migawka się nie zmienia.

**Jak to jest spięte technicznie.** `-o Dir::Etc::sourcelist=…
-o Dir::Etc::sourceparts=…` każe apt-owi użyć WYŁĄCZNIE jednego, tymczasowego
pliku źródeł na czas tych dwóch komend (`update` i `install`) — domyślna
konfiguracja repozytoriów obrazu bazowego (jakikolwiek ma format) zostaje
nietknięta. `check-valid-until=no` jest konieczne, bo migawka ma w `Release`
pole `Valid-Until` z przeszłości. `signed-by=/usr/share/keyrings/debian-
archive-keyring.gpg` weryfikuje ten sam, oryginalny podpis GPG Debiana —
migawka nie generuje własnego, więc `[trusted=yes]` (wyłączenie weryfikacji)
nie jest tu potrzebne i nie jest używane.

Pierwszy build w CI ujawnił, że obraz `postgres:18` nie ma jeszcze
zaufanych certyfikatów CA, a właśnie pakiet `ca-certificates` ma pobrać
z migawki. Dlatego obraz kopii bazy pobiera migawkę przez HTTP. Bezpieczeństwo
pakietów nadal opiera się na podpisanym `InRelease` i sumach z podpisanych
metadanych, sprawdzanych przez apt względem `debian-archive-keyring.gpg`;
`trusted=yes` pozostaje zabronione. Główny obraz ma CA i używa HTTPS.
Transport HTTP nie ukrywa metadanych ani nazw pakietów przed siecią.
Łańcuch podpisanego `Release` i sum pakietów opisuje Debian w
[`apt-secure(8)`](https://manpages.debian.org/testing/apt/apt-secure.8.en.html).

**Podnoszenie wersji pakietów jest teraz ŚWIADOME, nie ciche.** Zmiana
`SNAPSHOT_DEBIAN` na nowszą datę jest jedną linijką w PR-ze, widoczną
w historii gita — dokładnie tak, jak Dependabot podbija digesty obrazów
bazowych. Sprawdzenie przed podniesieniem: migawka pod nową datą istnieje
i ma `main/binary-amd64/Packages` z potrzebnymi pakietami
(`https://snapshot.debian.org/archive/debian/<data>/dists/trixie/Release`).

**Weryfikacja builda.** Pierwszy job `docker-build` w CI pobrał pakiety
głównego obrazu z migawki, ale obraz kopii bazy zatrzymał się na TLS przed
instalacją `ca-certificates`. Kolejny przebieg CI sprawdza wariant HTTP
z niezmienioną weryfikacją podpisu. Lokalnie brak demona Dockera.

### Dowody
`tests/Unit/AptPakietyPrzypieteDoMigawkiTest.php` — kształt przypięcia
(wersja migawki, `check-valid-until=no`, `signed-by=`, brak `trusted=yes`,
`apt-get install` zawsze z `Dir::Etc::sourcelist=` i towarzyszącym `apt-get
update` z TĄ SAMĄ opcją w tym samym poleceniu). Cztery niezależne kontrole
ujemne zmierzone ręcznie przy pisaniu testu (cofnięcie każdego elementu
osobno łamie odpowiednie sprawdzenie).

### Wycofanie
Powrót do zwykłego `apt-get update && apt-get install` (bez `Dir::Etc::
sourcelist=`) cofa reprodukowalność do stanu sprzed audytu — bez zmian
schematu czy danych, to czysto build-time'owa zmiana dwóch Dockerfile-i.
