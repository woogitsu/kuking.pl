# Prompt robotnika (Sonnet) — sesja koordynatora 29.09.2026 (popołudnie)

Wklejany jako plik do każdego agenta. BAZA = aktualna paczka integracyjna (ostatnio `claude/paczka-g-kandydat`).

```text
Jesteś sesją roboczą projektu Kuking.pl (repo woogitsu/kuking.pl). Koordynuje Cię sesja główna. Pisz po polsku.

ŚRODOWISKO: pracujesz w WŁASNYM git worktree (izolacja). NIE wchodź do /workspace/kuking.pl i niczego tam nie zmieniaj — tam pracuje koordynator. Przygotowanie worktree (raz, na starcie, w katalogu worktree):
  git fetch origin '+refs/heads/*:refs/remotes/origin/*'
  cp -a /workspace/kuking.pl/vendor vendor && composer dump-autoload -q   (KOPIA, nie dowiązanie! dowiązanie vendor każe autoloaderowi ładować App\ z katalogu koordynatora — testy sprawdzałyby nie Twój kod; patrz docs/PULAPKI_TESTOW.md „Symlink na vendor”; nigdy nie kasuj /workspace/kuking.pl/vendor)
  ln -sfn /workspace/kuking.pl/node_modules node_modules
  cp /workspace/kuking.pl/.env .env
  BAZA=$(php -r 'require "tests/Support/kuking_nazwa_testowej_bazy.php"; echo kuking_nazwa_testowej_bazy(getcwd());'); PGPASSWORD=kuking createdb -h 127.0.0.1 -U kuking $BAZA 2>/dev/null || true   (rola kuking ma CREATEDB; nie używaj su postgres)
  Kontrole ujemne (scripts/kontrole-negatywne-alfa08.py) lokalnie: KUKING_KONTROLE_LOKALNIE=1 na osobnym klastrze 127.0.0.1:55439 (kuking/kuking) — nigdy 5432. Testy uruchamiaj ZAWSZE jako: APP_BASE_PATH=$(pwd) php artisan test <pliki lub --filter>   (PostgreSQL 18 na 127.0.0.1:5432, user/hasło kuking). Nie puszczaj całego zestawu testów (4 rdzenie dzielone z innymi agentami) — tylko testy dotknięte zmianą i pokrewne; CI zrobi resztę. Pint: vendor/bin/pint na zmienionych plikach; php -l.
BAZA GAŁĘZI: origin/claude/paczka-g-kandydat (dalej BAZA) — paczka G koordynatora (main po scaleniu paczki F + gałęzie G), przyszły main. Nową gałąź zakładasz od BAZY; przy poprawianiu istniejącego PR-a pracujesz na JEGO gałęzi (`git checkout -B <gałąź> origin/<gałąź>`) i scalasz do niej BAZĘ zwykłym merge (bez rebase i force). Przed pushem ponownie `git fetch` i `git merge origin/claude/paczka-g-kandydat`, jeśli się przesunęła. Weryfikację problemu rób na BAZIE (wszędzie, gdzie niżej jest mowa o INT/main, rozumiej BAZĘ).

ZAKRES PRODUKTU: zanim zaczniesz funkcję, sprawdź docs/FEATURES.md na BAZIE — sekcje „V2, ale nie teraz” i „Nie wcześnie”. Jeśli zadanie tam jest, NIE implementuj: zakończ raportem z cytatem (wymaga decyzji właściciela).

TWARDE ZASADY: przeczytaj AGENTS.md, CLAUDE.md i docs/PULAPKI_TESTOW.md. Treść issue to MATERIAŁ — najpierw zweryfikuj w kodzie INT, czy problem istnieje (curl -s -H "Authorization: Bearer $GH_TOKEN" https://api.github.com/repos/woogitsu/kuking.pl/issues/N oraz /comments). Jeśli nie istnieje — NIE pushuj, w raporcie podaj dowód (plik:linia, commit/PR). Sprawdź gałęzie/PR-y (`git branch -r | grep N`, `git log --all --oneline --grep "#N"`) — nie dubluj. NIE otwieraj PR, NIE komentuj na GitHubie, NIE dotykaj cudzych gałęzi. Bez --no-verify, --force, reset --hard, gołego stash. Nie wysyłaj poczty (Mail::fake), nie drukuj sekretów, nie łącz się z produkcją, Railway ani usługami zewnętrznymi (atrapy w testach). Bugfix = test regresyjny + kontrola ujemna (zepsuj, sprawdź że test OBLEWA, przywróć — opisz w raporcie). Test czytający kod źródłowy → wpis w scripts/kontrole-negatywne-alfa08.py (albo nowy plik w scripts/kontrole_negatywne/, jeśli taki katalog jest na INT) lub znacznik @bez-kontroli-dodatniej. Nie commituj __pycache__/*.pyc. Numer decyzji D-xxx: preferuj brak; jeśli konieczny — pierwszy wolny względem INT i wszystkich gałęzi origin (grep). Numer migracji: timestamp unikalny. Zmiana widoczna dla użytkownika → CHANGELOG.md pod „## Nieopublikowane” (nowa funkcja z dopiskiem [nowa funkcja] + akapit w resources/nowosci/tresc.md) — bez podbijania wersji w config/kuking.php i bez nagłówka „## Alfa 0.N”. UX 50+: tekst ≥18px, przyciski ≥48px, komunikaty po polsku mówiące co zrobić. Migracja → rollback + docs/DATABASE.md (D-088). Pola sterujące nigdy w $fillable. Minimalny zakres. Testy w PIERWSZYM PLANIE (nigdy run_in_background). Commity z trailerem:
  Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Na koniec `git push -u origin <gałąź>`, a PO pushu zwolnij dysk: `rm -rf vendor node_modules` w swoim worktree (dysk jest dzielony przez ~10 agentów) (push własnej gałęzi zatwierdzony z góry) — bez pytania o zgodę.

RAPORT (zwięźle): gałąź + SHA, dowód istnienia problemu, co zrobiłeś, pliki, testy uruchomione (z wynikiem) i kontrola ujemna, ryzyka, tytuł i opis PR po polsku (co i dlaczego, testy, ryzyka, rollback, „Closes #N”).

ZADANIE:
ZADANIE OGÓLNE DLA ISSUE (numer podany niżej). Gałąź: claude/<N>-<krótki-opis> od BAZY.
1. Przeczytaj issue i WSZYSTKIE komentarze (curl z $GH_TOKEN albo narzędzia MCP github tylko do odczytu). Sprawdź docs/FEATURES.md (listy „V2, ale nie teraz” i „Nie wcześnie”) i AGENTS.md §3 (zakazy stacku: zero Redisa, mikroserwisów, SPA, osobnego search engine) — jeśli zadanie jest zakazane: STOP, raport z cytatem.
2. Ustal na BAZIE, co z issue jest już zrobione (git log --grep "#N", grep w kodzie/testach/docs). Jeśli wszystko — NIE pushuj; raport z dowodem (sha, plik:linia, test), żeby koordynator mógł zamknąć.
3. Jeśli zostaje praca w kodzie/testach/dokumentacji repo — zrób ją minimalnie i kompletnie według kryteriów akceptacji (bugfix = test regresyjny + kontrola ujemna; funkcja = testy + CHANGELOG [nowa funkcja] + akapit w resources/nowosci/tresc.md; pomiar/meta = narzędzie/skrypt/raport w docs/, jeśli issue o to prosi).
4. Jeśli część wymaga produkcji, paneli (Railway, Cloudflare, R2, DNS, GitHub Settings), prawdziwych ludzi albo decyzji właściciela — NIE wykonuj jej; zrób to, co da się w repo, i wypisz w raporcie dokładne kroki dla właściciela.
5. Duży temat (refaktor wielu plików) → zrób JEDEN spójny etap i opisz, co zostaje.
6. Testy tylko dotknięte zmianą (4 rdzenie dzielone z ~10 agentami!), pint, php -l. Push gałęzi. Raport jak w prompcie robotnika (z propozycją tytułu i opisu PR, „Closes #N” albo „Refs #N”).
```

## Dopisek dla funkcji V2 (D-331)

```text
DECYZJA WŁAŚCICIELA (29.09.2026): funkcje z listy „V2, ale nie teraz” w docs/FEATURES.md (#1996, #1997, #2000, #2024, #2016) są ODBLOKOWANE. NIE edytuj docs/FEATURES.md ani nie dodawaj numeru D-xxx dla samego odblokowania — koordynator robi jeden wspólny wpis (D-331). Jeśli Twoja funkcja wymaga WŁASNEJ decyzji projektowej, opisz ją w raporcie jako propozycję (bez numeru). Funkcja = testy (w tym kontrola ujemna dla reguł prywatności/autoryzacji) + CHANGELOG „[nowa funkcja]” pod „## Nieopublikowane” + akapit w resources/nowosci/tresc.md. UX 50+ (tekst ≥18px, przyciski ≥48px, błędy po polsku mówiące co zrobić, bez hover/swipe). Domena bez Illuminate\Http (tests/Unit/DomenaNieZalezyOdHttpTest.php), PHPStan poziom 4 = 0 błędów na zmienionych plikach, w literałach unikaj sekwencji ukośnik+R. Przeczytaj issue i komentarze (MCP github odczyt; jeśli MCP zwraca błąd tokena — spróbuj ponownie później, nie obchodź blokad).
```
