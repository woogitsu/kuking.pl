## D-121 · Runnera wybiera zmienna repozytorium `CI_RUNS_ON`, a dziś wskazuje starą pulę WSL

**Data:** 11 września 2026 · Status: **obowiązuje** · Ciąg dalszy **D-028** · issue #342

> **Adnotacja z 20 września 2026 (audyt rejestru).** Reguła obowiązuje —
> `runs-on` czyta `vars.CI_RUNS_ON` z fallbackiem `ubuntu-latest`. Liczba nie:
> zdanie „wszystkie **dziewięć jobów** w `ci.yml`" opisuje stan sprzed
> rozrostu workflow. Dziś jobów jest TRZYNAŚCIE (`zakres`, `lint`,
> `przyrzad_605`, `static-analysis`, `test`, `dwa-polaczenia`, `assets`,
> `port_panelu`, `port_marki`, `port_funkcje`, `dostepnosc`, `audit`,
> `docker-build`) i każdy ma tę samą linię `runs-on`. Liczba „dziewięć"
> została przepisana także do komentarza strażnika
> (`tests/Feature/DokumentyCiMowiaPrawdeORunnerzeTest.php:18`), więc rozjazd
> stoi w dwóch miejscach naraz. Drugi człon tytułu — „a dziś wskazuje starą
> pulę WSL" — opiera się na jednym pomiarze (CI nr 661) i dotyczy zmiennej
> żyjącej w ustawieniach repozytorium, więc z kodu nie da się go ani
> potwierdzić, ani obalić. Zgodnie z wnioskiem z D-118 słowo „dziś" w tytule
> powinno nosić datę pomiaru.

Wszystkie dziewięć jobów w `ci.yml` ma
`runs-on: ${{ fromJSON(vars.CI_RUNS_ON || '"ubuntu-latest"') }}` — czyli **dokładnie**
zmienną repozytorium, z zapasem w runnerach GitHuba. Nagłówek twierdził odwrotnie:
że joby tej zmiennej nie biorą i zapasu nie mają.

Zmierzone na żywym przebiegu (CI nr 661): `labels: ["self-hosted"]`,
`runner_name: kuking-wsl-DOM-NEW-02`. Zmienna jest ustawiona na **samo**
`self-hosted`, więc joby lądują na starej puli WSL-owej — tej, którą komplet sześciu
etykiet miał wykluczać. Ten sam pomiar stał już w `SELF_HOSTED_RUNNER.md`, 160 linii
niżej niż zdanie, któremu przeczy.

**Zmienna żyje w ustawieniach repozytorium i żaden agent jej nie zmieni.** Wybór
(komplet etykiet / usunięcie zmiennej / świadome zostawienie) należy do właściciela.
