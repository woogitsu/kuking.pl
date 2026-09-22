# Zasady floty — obowiązują KAŻDEGO agenta, bez wyjątku

Repozytorium kanoniczne: `C:\Users\matma\Documents\Codex\kuking.pl`
(NIE `Codex` — to osobny, niezwiązany klon. NIE worktree sesji prowadzącej.)

## Twarde zakazy

1. **Nie pushujesz i nie otwierasz PR-ów.** Pchanie idzie SZEREGOWO przez kolejkę
   prowadzoną przez sesję główną — dwa równoległe pchnięcia zderzają się na bazie
   i dają fałszywe porażki (142 failures, deadlocki). Commitujesz lokalnie
   i meldujesz SHA.
2. **Nigdy `git worktree prune`.** Z Windows każdy worktree spod `/home/` jest
   widziany jako `prunable`; jedno polecenie wypruwa wszystkie worktree'e WSL naraz.
   20.09 zabiło to cztery sesje jednocześnie.
3. **Nigdy `git reset --hard`**, nigdy `--no-verify`, nigdy omijania hooków.
4. **PostgreSQL wyłącznie `127.0.0.1:55439`**, nigdy współdzielony 5432.
   `migrate:fresh` / `migrate:refresh` tylko na WŁASNEJ bazie `kuking_flota_<twoje>`.
5. **Nie dotykasz cudzego stanowiska** ani cudzej gałęzi. Twoje to `flota/<twoje>`.
6. **Nie wypisujesz tokenu** w logu, raporcie ani odpowiedzi.
7. **Nie używaj gołego `git stash` ani `git stash pop`.** Stos stashy jest
   **wspólny dla wszystkich worktree** i pracuje na nim dziesięć stanowisk naraz —
   `pop` zdejmie to, co akurat jest na wierzchu, czyli może cudzą pracę. Żeby
   zobaczyć czerwień przed poprawką, zrób **tymczasowy commit** i wróć do niego,
   albo skopiuj plik na bok. Gdy naprawdę musisz: `git stash push -u -m "<twoje-stanowisko>"`,
   od razu zapisz SHA (`git stash list --format='%H %gs'`), przywracaj przez
   `git stash apply <sha>` — nigdy `pop` — i na koniec usuń swój wpis.
8. `vendor` i `node_modules` **kopiuj, nie dowiązuj symlinkiem** — symlink wywraca
   `JednoDekodowanieZdjeciaTest` po ośmiu minutach hooka.
9. Nie wysyłasz wiadomości do użytkowników ani instytucji. Brak destrukcyjnych
   operacji na produkcji.

## Zasady projektu

`AGENTS.md` w korzeniu repozytorium jest **jedynym źródłem prawdy**. Przeczytaj go
przed pierwszą zmianą. `CLAUDE.md` to tylko wskaźnik. Sprawdź `docs/ROADMAP.md`,
żeby nie budować funkcji z V2 podczas prac nad MVP.

Skrót, który i tak trzeba znać:
- Kuking = społeczność ludzi, którzy gotują. Grupa 50+, ale produkt NIE jest
  oznaczany jako „dla seniorów".
- UX 50+: tekst ≥ 18 px, przyciski ≥ 48 px, bez hover/swipe, błędy po polsku
  mówiące **co zrobić**, poprawne dane nigdy nie znikają.
- Zmiana schematu = migracja + test + `docs/DATABASE.md` + rollback.
- **Bugfix = test regresyjny.** Bez testu nie ma poprawki.
- `status` i `role` użytkownika **nigdy** w `$fillable`.
- **UUID w adresie to nie autoryzacja** — każde wejście przez Policy.
- Przed zgłoszeniem gotowości: `vendor/bin/pint` i `php artisan test`.

## Jak pracujesz

1. `cd C:\Users\matma\Documents\kuking-flota\<twoje-stanowisko>` — to Twój worktree,
   gałąź `flota/<twoje>`, odgałęziona od świeżego `main` (`534e0a51`).
2. Testy uruchamiasz **w WSL**, nie na Windows. **Przedrostek `MSYS_NO_PATHCONV=1`
   jest obowiązkowy** — bez niego Git Bash przerabia `/mnt/c/…` na
   `C:\Program Files\Git\mnt\c\…` i skrypt „nie istnieje":
   ```
   MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh <twoje>
   MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh <twoje> --filter TwojTest
   ```
   Skrypt sam zakłada `.env` i `APP_KEY` w runtime. Gdyby ich zabrakło, testy padają
   na `MissingAppKeyException` i **wyglądają jak regresja Twojej gałęzi, nią nie będąc**.
   `przygotuj-runtime.sh` przegrywa worktree do `/home/mateusz/flota/<twoje>-run`
   i dokłada `vendor`/`node_modules`. Po każdej zmianie w kodzie uruchom go ponownie.
3. Host bywa obciążony. **Porażki przy `load average` > 12 traktuj jako kontencję
   dopiero PO zobaczeniu nazw testów i odtworzeniu w izolacji**, nie zamiast.
4. Commitujesz małymi krokami, po polsku, w trybie rozkazującym
   („Nie pozwól, by…", „Przywróć…", „Dołóż…").

## Pułapki, na które ktoś już się nadział — nie powtarzaj

- **`git show 'origin/main:…'` w Git Bash na Windows cicho pada** przez konwersję
  ścieżek MSYS. Potrzeba `MSYS_NO_PATHCONV=1`.
- **Gałąź odgałęziona przed 20.09 ma stary `ci.yml`** →
  `PortMarkiMaWlasnaBramkeCiTest` daje cztery czerwienie **odtwarzalne w izolacji**,
  wyglądające jak prawdziwa regresja. Twoja gałąź jest świeża, ale runtime może się
  rozjechać — `.github` MUSI jechać do runtime.
- **SIGPIPE pod `set -o pipefail`**: `printf '%s' "$X" | grep -q "$WZ"` daje warunek
  FAŁSZYWY mimo trafienia, gdy wyjście jest duże. Pisz `grep -qE "$WZ" <<< "$X"`.
  Opisane w `docs/PULAPKI_TESTOW.md` §5c.
- **`<env>` w `phpunit.xml` bez `force`** nie nadpisze prawdziwej zmiennej
  środowiskowej, ale **nadpisze `.env`**. Stąd meldunki „Port: 5432".
- **Flagi nie przechodzą przez `artisan serve`** (whitelist `$passthroughVariables`)
  — np. `KUKING_QUESTIONS_ENABLED`. 48 „usterek ekranów" wyszło kiedyś z tego.
- **Skan, który nie znajduje żadnego pliku, przechodzi.** Każdy nowy strażnik
  potrzebuje **kontroli dodatniej** na wzór `test_skan_naprawde_czyta_modele`.
- **`.gitignore` zaczyna się od `*.log`** — dowody w `.log` wymagają `git add -f`.
- **Porażka w runtime to nie jest „istniejąca wcześniej", dopóki tego nie sprawdzisz.**
  `StandardoweWiadomosciMarkiTest` oblewał u dwóch stanowisk na kolorze tła listu
  (`#fafafa` zamiast `#F3F4F1`) i oba uznały to za cudzy, zastany problem. Nie był:
  wyklucznik `vendor/` w skrypcie runtime zjadał `resources/views/vendor/mail`.
  Po naprawie: **7 przeszło, 209 asercji**. Zanim odpiszesz „to nie moje" — odtwórz
  to na świeżym runtime; „zastane" jest wygodniejszą odpowiedzią niż prawdziwą.
- **W tym repozytorium NIE MA hooka `pre-commit`.** `scripts/install-hooks.sh`
  instaluje wyłącznie `pre-push`. Commit niczego nie sprawdza.

## Jak meldujesz

Kończąc, podaj zwięźle:
- **SHA** commitów na swojej gałęzi,
- co **zmierzyłeś sam**, a co **przejąłeś** (`[pomiar cudzy: źródło]`) —
  raport, który podaje przejęte twierdzenie tym samym tonem co własny pomiar,
  zmusza czytelnika do sprawdzania wszystkiego albo niczego,
- czego **NIE zrobiłeś** i dlaczego,
- co wymaga **decyzji właściciela** (nie domykaj tego asercją na własną rękę —
  asercja zabetonuje zachowanie, którego nikt nie wybrał).
