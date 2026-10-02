WSPÓLNE ZASADY (projekt Kuking, Laravel 13, PHP 8.4, PostgreSQL):
- Przeczytaj AGENTS.md (zasady). Język polski. Bez force-push, rebase, reset na wypchniętych gałęziach. Nie dotykaj Railway/produkcji. Nie omijaj haków ani odmów narzędzi — przy odmowie zatrzymaj się i zgłoś.
- Baza gałęzi: `origin/codex/integracja-poprawki-20261002-c` (git fetch origin; git checkout -b <twoja-gałąź> origin/codex/integracja-poprawki-20261002-c).
- vendor: worktree nie ma vendor, composer nie łączy się z GitHubem. Zrób `cp -a /workspace/kuking.pl/vendor ./vendor && cp /workspace/kuking.pl/.env ./ && composer dump-autoload -q` (composer.lock identyczny). NIGDY symlink — wtedy testy uruchamiają kod z innego checkoutu. Sprawdź `php artisan --version`. Lokalny PostgreSQL 16 (CI ma 18), baza kuking_test.
- Zmiana czysto organizacyjna: TREŚĆ zasad i dokumentów ma zostać zachowana (przeniesiona, nie przepisana ani skrócona merytorycznie). Udowodnij to skryptem (np. porównanie zbioru linii/akapitów przed i po) i opisz w PR.
- Testy, które czytają zmieniane pliki: dostosuj je tak, żeby nadal pilnowały tego samego (nie osłabiaj asercji). Każdy dostosowany test ma oblać przy zepsuciu (kontrola ujemna ręczna; jeśli test czyta źródła i jest w scripts/kontrole-negatywne-alfa08.py — zaktualizuj wpis i wzorzec w scripts/kontrole_oczekiwana_przyczyna.py; 3. element krotki = NAZWA testu/klasy). Uruchom StraznikTekstuMaKontroleDodatniaTest.
- Pint (czytaj pole result, nie sam kod wyjścia), php -l, `php scripts/decyzje-indeks.php`, `python3 -m unittest discover -s scripts -p 'test_*.py'`.
- Commit trailery:
Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01PkJKnfZqih8F4mpm5YPvP4
- Push `git push -u origin <gałąź>` i DRAFT PR (mcp__github__create_pull_request; jeśli brak, ToolSearch "select:mcp__github__create_pull_request") do base `codex/integracja-poprawki-20261002-c`, opis wg .github/pull_request_template.md, zakończony linią:
🤖 Generated with [Claude Code](https://claude.com/claude-code)
- NIE scalaj. NIE dopisuj wierszy do D-333 (robi to tylko agent od AGENTS.md). Raport po polsku: SHA, PR, co przeniesiono gdzie, dowód zachowania treści, testy i kontrole ujemne, ryzyka.
