## D-141 · Wyścig o binarkę Composera usuwa własny katalog narzędzi per job, a nie kolejkowanie

**Data:** 11 września 2026 · Issue #262 · Status: **obowiązuje**

### Objaw

Job „Testy (PostgreSQL 18)" oblewał z kodem **126** bez ani jednego oblanego
testu: `…/setup-php/tools/composer: /usr/bin/env: bad interpreter: Text file busy`.
Ten sam commit lokalnie — komplet testów zielony.

### Diagnoza z samego issue była BŁĘDNA i warto to zapisać

Issue mówiło: „gdy dwa joby z RÓŻNYCH gałęzi startują w tej samej sekundzie".
Logi mówią co innego. `kuking-wsl-DOM-NEW-01`…`-03` to **trzy rejestracje na
jednej maszynie** (jedno `/home/mateusz`, jedno `/usr/local/bin`), a siedem jobów
tego workflow startuje równolegle. W przebiegu, na którym to złapano, pisał job
„Dostępność" na runnerze `-02`, a wykonywał job „Testy" na `-03` — **ten sam
przebieg i ta sama gałąź**.

To zmienia rozwiązanie: skoro biją się joby JEDNEGO przebiegu, żadna grupa
`concurrency` po `github.ref` ich nie rozdziela. Grupa wspólna dla wszystkich
przebiegów też nie — a przy tym trzyma jeden bieg działający i jeden oczekujący,
więc trzeci ANULUJE oczekującego. Zamieniłoby to losową czerwień na anulowane
joby i dłuższą kolejkę.

### Decyzja

Każdy job dostaje **własny** katalog na binarki narzędzi
(`$RUNNER_TEMP` + numer przebiegu + numer próby + nazwa joba). Nie ma już pliku,
do którego jeden job pisze, a drugi go wykonuje. **Wyścig znika konstrukcyjnie,
nie statystycznie.**

Katalog zakładamy sami, a nie zostawiamy tego akcji: akcja robi `sudo mkdir -p`,
więc katalog byłby rootowy, a rootowy katalog w `_temp` blokuje potem sprzątanie
katalogu roboczego przez runnera.

Koszt: Composer (~3 MB) pobiera się raz na job. Sekundy — i po nich CI przestaje
zależeć od tego, czy sąsiedni job właśnie nie podmienia binarki.

### Czego świadomie NIE zrobiono

**Ponowienia kroku.** Retry ukrywa wyścig, nie usuwa go — i uczy, że czerwone CI
się powtarza, a nie czyta. To jest ta sama zasada, którą trzymamy przy testach.

### Node tego nie potrzebuje i to jest ZMIERZONE, nie założone

`actions/setup-node` trzyma Node w `_work/_tool` **każdego runnera osobno**
(„Found in cache @ …/actions-runner-kuking-03/_work/_tool/node/22.23.2/x64"
w logu joba dostępności z 10 września), a jeden runner wykonuje jeden job naraz.
Wspólnej ścieżki dla Node'a tu nie ma.
